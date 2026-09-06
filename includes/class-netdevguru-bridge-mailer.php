<?php
/**
 * Routes wp_mail() through the AutomateFlow transactional API.
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces WordPress's own delivery for site email.
 *
 * Hooks `pre_wp_mail` rather than swapping PHPMailer via `phpmailer_init`. The filter is a
 * clean short-circuit — return non-null and WordPress skips its own send entirely and uses
 * the returned boolean as wp_mail()'s result — where the PHPMailer route means mutating a
 * configured object and hoping nothing downstream reconfigures it.
 *
 * ## The fallback decision
 *
 * If the API call fails, this hands the message back to WordPress (`return null`) instead of
 * reporting failure. That is a deliberate trade against a small double-send risk: the only
 * way both paths deliver is a request that the API accepted but whose response never arrived,
 * whereas *not* falling back means a failed API call silently swallows password resets and
 * order confirmations. A duplicate receipt is an annoyance; an undeliverable password reset
 * locks people out of the site.
 *
 * Sites that would rather fail loudly can flip it:
 *
 *     add_filter( 'netdevguru_bridge_mail_fallback', '__return_false' );
 *
 * ## Rate limiting
 *
 * The transactional endpoint accepts one recipient per call, so a message addressed to N
 * people costs N requests against a key limited per minute. Bulk notification plugins can
 * exhaust that budget; those sends fall back to WordPress rather than being dropped.
 */
class Netdevguru_Bridge_Mailer {

	/**
	 * API client.
	 *
	 * @var Netdevguru_Bridge_Client
	 */
	private $client;

	/**
	 * Settings repository.
	 *
	 * @var Netdevguru_Bridge_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Netdevguru_Bridge_Client   $client   API client.
	 * @param Netdevguru_Bridge_Settings $settings Settings repository.
	 */
	public function __construct( Netdevguru_Bridge_Client $client, Netdevguru_Bridge_Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Hook registration.
	 */
	public function register() {
		if ( ! $this->settings->is_enabled( 'mailer' ) ) {
			return;
		}

		add_filter( 'pre_wp_mail', array( $this, 'send' ), 10, 2 );
	}

	/**
	 * Short-circuit wp_mail().
	 *
	 * @param null|bool            $short_circuit Whatever an earlier filter decided.
	 * @param array<string, mixed> $atts          to, subject, message, headers, attachments.
	 * @return null|bool Null to let WordPress send; boolean to report the outcome.
	 */
	public function send( $short_circuit, $atts ) {
		// Another plugin already claimed this message. Two mailers both short-circuiting is
		// how a site ends up sending everything twice.
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		$recipients = $this->addresses( isset( $atts['to'] ) ? $atts['to'] : array() );

		if ( empty( $recipients ) ) {
			return null;
		}

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$body    = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		$is_html = 'text/html' === $headers['content_type'];

		$message = array(
			'to'         => '',
			'subject'    => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'from_email' => '' !== $headers['from_email'] ? $headers['from_email'] : $this->settings->mail_from(),
			'from_name'  => '' !== $headers['from_name'] ? $headers['from_name'] : $this->settings->mail_from_name(),
		);

		if ( $is_html ) {
			$message['html'] = $body;
		} else {
			$message['text'] = $body;
		}

		if ( '' !== $headers['reply_to'] ) {
			$message['reply_to'] = $headers['reply_to'];
		}

		$attachments = $this->encode_attachments( isset( $atts['attachments'] ) ? $atts['attachments'] : array() );

		if ( ! empty( $attachments ) ) {
			$message['attachments'] = $attachments;
		}

		// Cc/Bcc have no representation in the transactional contract, so they are expanded
		// into ordinary recipients. That loses the *appearance* of a copy but not the copy —
		// silently dropping them would be the worse failure, and a Bcc becoming a separate
		// message is closer to the intent than no message at all.
		$all = array_unique( array_merge( $recipients, $headers['cc'], $headers['bcc'] ) );

		$sent_any = false;

		foreach ( $all as $recipient ) {
			$message['to'] = $recipient;
			$result        = $this->client->send_transactional( $message );

			if ( is_wp_error( $result ) ) {
				Netdevguru_Bridge_Logger::warning(
					__( 'Transactional send failed; handing the message back to WordPress.', 'netdevguru-bridge-for-automateflow' ),
					array(
						'reason'  => $result->get_error_code(),
						'subject' => $message['subject'],
					)
				);

				/**
				 * Filter whether a failed API send falls back to WordPress's own mailer.
				 *
				 * @param bool $fallback Default true.
				 */
				if ( apply_filters( 'netdevguru_bridge_mail_fallback', true ) ) {
					// Returning null re-runs the *whole* message through WordPress, including
					// any recipient already sent above. Only reachable when at least one
					// recipient failed, and duplicate-vs-dropped is the trade documented in
					// the class docblock.
					return null;
				}

				return false;
			}

			$sent_any = true;
		}

		return $sent_any;
	}

	/**
	 * Normalise a to/cc value into a list of bare addresses.
	 *
	 * @param string|string[] $value Raw header or address list.
	 * @return string[]
	 */
	private function addresses( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		$out = array();

		foreach ( (array) $value as $entry ) {
			$address = $this->bare_address( (string) $entry );

			if ( '' !== $address ) {
				$out[] = $address;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Extract the address from `Name <a@b.c>` or a bare address.
	 *
	 * @param string $value Raw value.
	 * @return string Empty when not a valid address.
	 */
	private function bare_address( $value ) {
		$value = trim( $value );

		if ( preg_match( '/<([^>]+)>/', $value, $matches ) ) {
			$value = $matches[1];
		}

		$value = sanitize_email( $value );

		return is_email( $value ) ? $value : '';
	}

	/**
	 * Pull the parts of the header block the API can represent.
	 *
	 * @param string|string[] $headers Raw headers.
	 * @return array{content_type:string,from_email:string,from_name:string,reply_to:string,cc:string[],bcc:string[]}
	 */
	private function parse_headers( $headers ) {
		$parsed = array(
			'content_type' => 'text/plain',
			'from_email'   => '',
			'from_name'    => '',
			'reply_to'     => '',
			'cc'           => array(),
			'bcc'          => array(),
		);

		if ( is_string( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		foreach ( (array) $headers as $header ) {
			if ( false === strpos( (string) $header, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', (string) $header, 2 );

			$name  = strtolower( trim( $name ) );
			$value = trim( $value );

			switch ( $name ) {
				case 'content-type':
					if ( false !== stripos( $value, 'text/html' ) ) {
						$parsed['content_type'] = 'text/html';
					}
					break;

				case 'from':
					$parsed['from_email'] = $this->bare_address( $value );

					if ( preg_match( '/^\s*"?([^"<]*)"?\s*</', $value, $matches ) ) {
						$parsed['from_name'] = sanitize_text_field( trim( $matches[1] ) );
					}
					break;

				case 'reply-to':
					$parsed['reply_to'] = $this->bare_address( $value );
					break;

				case 'cc':
					$parsed['cc'] = $this->addresses( $value );
					break;

				case 'bcc':
					$parsed['bcc'] = $this->addresses( $value );
					break;
			}
		}

		return $parsed;
	}

	/**
	 * Read attachment files and base64 them for the API.
	 *
	 * @param string|string[] $attachments Absolute paths, as wp_mail supplies them.
	 * @return array<int, array{filename:string,content:string}>
	 */
	private function encode_attachments( $attachments ) {
		if ( is_string( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		}

		$out   = array();
		$total = 0;

		foreach ( (array) $attachments as $path ) {
			$path = trim( (string) $path );

			if ( '' === $path || ! is_readable( $path ) ) {
				continue;
			}

			$size = (int) filesize( $path );

			// Base64 inflates by roughly a third and the whole message is one JSON body, so a
			// large attachment is a memory problem here and a rejected request there. Skip
			// past a conservative ceiling and let the message go without it.
			if ( $size <= 0 || $total + $size > 5 * MB_IN_BYTES ) {
				Netdevguru_Bridge_Logger::warning(
					__( 'Attachment skipped: the message exceeds the size the transactional API accepts.', 'netdevguru-bridge-for-automateflow' ),
					array( 'file' => basename( $path ) )
				);

				continue;
			}

			// Direct read rather than WP_Filesystem: these are local paths wp_mail() is about
			// to read itself, and WP_Filesystem may not be initialised on a mail call.
			$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $contents ) {
				continue;
			}

			$total += $size;
			$out[]  = array(
				'filename' => basename( $path ),
				'content'  => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API contract specifies base64 attachment content.
			);
		}

		return $out;
	}
}

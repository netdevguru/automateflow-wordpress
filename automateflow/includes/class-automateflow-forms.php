<?php
/**
 * Renders and submits AutomateFlow subscription forms.
 *
 * @package AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode and block that embed a workspace form.
 *
 * ## The API key never reaches the browser
 *
 * The obvious embed posts straight from the visitor's browser to the AutomateFlow API. That
 * cannot work here: the credential is a workspace-scoped key with write access, and putting
 * it in page source hands every visitor the ability to write to the workspace. So the form
 * posts to WordPress — `admin-post.php`, which is available to logged-out visitors through
 * the `nopriv` action — and the server relays it with the key attached.
 *
 * That indirection buys two more things worth having: a nonce on a form that would otherwise
 * be trivially scriptable, and a definition cache so a popular page does not spend an API
 * request per view.
 */
class AutomateFlow_Forms {

	const ACTION        = 'automateflow_submit_form';
	const CACHE_PREFIX  = 'automateflow_form_';
	const CACHE_SECONDS = 900;

	/**
	 * API client.
	 *
	 * @var AutomateFlow_Client
	 */
	private $client;

	/**
	 * Settings repository.
	 *
	 * @var AutomateFlow_Settings
	 */
	private $settings;

	/**
	 * Status message captured from the cookie at init.
	 *
	 * Static because the shortcode, the block and a widget can each construct an instance
	 * within one request, and the message belongs to the request rather than to any of them.
	 *
	 * @var array{type:string,message:string}|null
	 */
	private static $status = null;

	/**
	 * Constructor.
	 *
	 * @param AutomateFlow_Client   $client   API client.
	 * @param AutomateFlow_Settings $settings Settings repository.
	 */
	public function __construct( AutomateFlow_Client $client, AutomateFlow_Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Hook registration.
	 */
	public function register() {
		if ( ! $this->settings->is_enabled( 'forms' ) ) {
			return;
		}

		add_shortcode( 'automateflow_form', array( $this, 'render_shortcode' ) );

		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submit' ) );

		add_action( 'init', array( $this, 'register_block' ) );

		// The status cookie has to be read *and expired* before any output, because
		// setcookie() sends a header. render() runs inside the_content, by which point the
		// headers are long gone and clearing it there would emit a warning on every page
		// that embeds a form.
		add_action( 'init', array( $this, 'capture_status' ) );
	}

	/**
	 * One-shot read of the status cookie, before output starts.
	 */
	public function capture_status() {
		if ( empty( $_COOKIE['automateflow_status'] ) ) {
			return;
		}

		$decoded = json_decode( sanitize_text_field( wp_unslash( $_COOKIE['automateflow_status'] ) ), true );

		$this->expire_status_cookie();

		if ( ! is_array( $decoded ) || ! isset( $decoded['type'], $decoded['message'] ) ) {
			return;
		}

		self::$status = array(
			'type'    => 'success' === $decoded['type'] ? 'success' : 'error',
			'message' => sanitize_text_field( (string) $decoded['message'] ),
		);
	}

	/**
	 * Register the editor block.
	 *
	 * Server-rendered so there is no compiled bundle in the plugin — the editor script is
	 * plain ES5 against `wp.element.createElement`, which keeps the plugin buildless and
	 * therefore reviewable as shipped, a wordpress.org expectation.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'automateflow-block',
			AUTOMATEFLOW_PLUGIN_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			AUTOMATEFLOW_VERSION,
			true
		);

		register_block_type(
			'automateflow/form',
			array(
				'api_version'     => 2,
				'editor_script'   => 'automateflow-block',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'uuid'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'title' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		return $this->render(
			isset( $attributes['uuid'] ) ? (string) $attributes['uuid'] : '',
			isset( $attributes['title'] ) ? (string) $attributes['title'] : ''
		);
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'uuid'  => '',
				'title' => '',
			),
			$atts,
			'automateflow_form'
		);

		return $this->render( $atts['uuid'], $atts['title'] );
	}

	/**
	 * Build the form markup.
	 *
	 * @param string $uuid  Form uuid.
	 * @param string $title Optional heading override.
	 * @return string
	 */
	private function render( $uuid, $title ) {
		$uuid = sanitize_text_field( $uuid );

		if ( '' === $uuid ) {
			return $this->notice( __( 'No AutomateFlow form was specified.', 'automateflow' ) );
		}

		$definition = $this->definition( $uuid );

		if ( is_wp_error( $definition ) ) {
			// Deliberately generic for visitors: the underlying message can name the
			// workspace or the reason a key was rejected, which is not public information.
			// The specific error is in the plugin log.
			return $this->notice( __( 'This form is temporarily unavailable.', 'automateflow' ) );
		}

		$fields  = isset( $definition['fields'] ) && is_array( $definition['fields'] ) ? $definition['fields'] : array();
		$heading = '' !== $title ? $title : (string) ( isset( $definition['name'] ) ? $definition['name'] : '' );

		$status = $this->consume_status();

		ob_start();
		?>
		<div class="automateflow-form-wrapper">
			<?php if ( '' !== $heading ) : ?>
				<h3 class="automateflow-form-title"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>

			<?php if ( null !== $status ) : ?>
				<p class="automateflow-form-status automateflow-form-status--<?php echo esc_attr( $status['type'] ); ?>">
					<?php echo esc_html( $status['message'] ); ?>
				</p>
			<?php endif; ?>

			<form class="automateflow-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="form_uuid" value="<?php echo esc_attr( $uuid ); ?>" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->current_url() ); ?>" />
				<?php wp_nonce_field( self::ACTION . '_' . $uuid, '_automateflow_nonce' ); ?>

				<?php
				// A honeypot: a field a person never sees and a bot fills in. Cheap, and it
				// keeps the plugin free of a third-party captcha dependency.
				?>
				<div class="automateflow-hp" aria-hidden="true" style="position:absolute;left:-9999px;">
					<label for="automateflow-website-<?php echo esc_attr( $uuid ); ?>"><?php esc_html_e( 'Leave this field empty', 'automateflow' ); ?></label>
					<input type="text" name="automateflow_website" id="automateflow-website-<?php echo esc_attr( $uuid ); ?>" tabindex="-1" autocomplete="off" />
				</div>

				<p class="automateflow-field">
					<label for="automateflow-email-<?php echo esc_attr( $uuid ); ?>"><?php esc_html_e( 'Email', 'automateflow' ); ?> <span aria-hidden="true">*</span></label>
					<input type="email" name="email" id="automateflow-email-<?php echo esc_attr( $uuid ); ?>" required />
				</p>

				<?php foreach ( $fields as $field ) : ?>
					<?php
					$name     = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : '';
					$label    = isset( $field['label'] ) ? (string) $field['label'] : $name;
					$required = ! empty( $field['required'] );

					// `email` is rendered above unconditionally, so a definition that also
					// declares it must not produce a second input with the same name.
					if ( '' === $name || 'email' === $name ) {
						continue;
					}
					?>
					<p class="automateflow-field">
						<label for="automateflow-<?php echo esc_attr( $name . '-' . $uuid ); ?>">
							<?php echo esc_html( $label ); ?>
							<?php if ( $required ) : ?>
								<span aria-hidden="true">*</span>
							<?php endif; ?>
						</label>
						<input
							type="text"
							name="fields[<?php echo esc_attr( $name ); ?>]"
							id="automateflow-<?php echo esc_attr( $name . '-' . $uuid ); ?>"
							<?php echo $required ? 'required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attribute name, no variable content. ?>
						/>
					</p>
				<?php endforeach; ?>

				<p class="automateflow-submit">
					<button type="submit"><?php esc_html_e( 'Subscribe', 'automateflow' ); ?></button>
				</p>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Handle a posted form.
	 */
	public function handle_submit() {
		$uuid = isset( $_POST['form_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['form_uuid'] ) ) : '';

		if ( '' === $uuid || ! isset( $_POST['_automateflow_nonce'] ) ) {
			wp_safe_redirect( $this->redirect_target() );
			exit;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_automateflow_nonce'] ) ), self::ACTION . '_' . $uuid ) ) {
			$this->set_status( 'error', __( 'That form has expired. Please try again.', 'automateflow' ) );
			wp_safe_redirect( $this->redirect_target() );
			exit;
		}

		// Honeypot filled means a bot. Redirect as though it worked: telling an automated
		// submitter which signal caught it just invites a second attempt without it.
		if ( ! empty( $_POST['automateflow_website'] ) ) {
			$this->set_status( 'success', __( 'Thanks — please check your inbox to confirm.', 'automateflow' ) );
			wp_safe_redirect( $this->redirect_target() );
			exit;
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			$this->set_status( 'error', __( 'Please enter a valid email address.', 'automateflow' ) );
			wp_safe_redirect( $this->redirect_target() );
			exit;
		}

		$payload = array( 'email' => $email );

		if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
			// Unslashed then sanitized key and value at a time; the shape is a flat map of
			// text inputs, so anything nested is dropped rather than passed through.
			$raw = wp_unslash( $_POST['fields'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per entry immediately below.

			foreach ( (array) $raw as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$payload[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
				}
			}
		}

		$result = $this->client->submit_form( $uuid, $payload );

		if ( is_wp_error( $result ) ) {
			$this->set_status( 'error', __( 'We could not complete your subscription. Please try again shortly.', 'automateflow' ) );
		} else {
			$this->set_status( 'success', __( 'Thanks — please check your inbox to confirm.', 'automateflow' ) );
		}

		wp_safe_redirect( $this->redirect_target() );
		exit;
	}

	/**
	 * Fetch a form definition, cached.
	 *
	 * @param string $uuid Form uuid.
	 * @return array<string, mixed>|WP_Error
	 */
	private function definition( $uuid ) {
		$key    = self::CACHE_PREFIX . md5( $uuid );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->client->get_form( $uuid );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$definition = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;

		set_transient( $key, $definition, self::CACHE_SECONDS );

		return $definition;
	}

	/**
	 * Drop every cached definition.
	 *
	 * Exposed so the settings screen can offer it — a form edited in AutomateFlow otherwise
	 * takes up to the cache lifetime to appear changed on the site.
	 */
	public static function flush_cache() {
		global $wpdb;

		// Transients have no prefix query API. This is a direct read of the options table
		// followed by the proper delete_transient() call for each hit, so object-cache
		// installs (where the rows do not exist) simply find nothing and no-op.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
			)
		);

		foreach ( (array) $names as $name ) {
			delete_transient( str_replace( '_transient_', '', $name ) );
		}
	}

	/**
	 * Where to send the visitor after a submission.
	 *
	 * @return string
	 */
	private function redirect_target() {
		$target = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked by the caller; this only picks a same-host redirect.

		// wp_safe_redirect() confines this to the site's own host regardless, so a tampered
		// value can at worst pick a different local page.
		return '' !== $target ? $target : home_url( '/' );
	}

	/**
	 * Current front-end URL, for the round trip back.
	 *
	 * @return string
	 */
	private function current_url() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $path );
	}

	/**
	 * Stash a one-shot status message for the visitor.
	 *
	 * Cookie rather than a session or a query argument: WordPress has no session layer, and a
	 * query argument would survive sharing the URL and re-announce a stranger's signup.
	 *
	 * @param string $type    success|error.
	 * @param string $message Display text.
	 */
	private function set_status( $type, $message ) {
		setcookie(
			'automateflow_status',
			wp_json_encode(
				array(
					'type'    => $type,
					'message' => $message,
				)
			),
			array(
				'expires'  => time() + 60,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * The status captured at init, if any.
	 *
	 * Read once and cleared, so two form blocks on one page do not both announce the same
	 * submission.
	 *
	 * @return array{type:string,message:string}|null
	 */
	private function consume_status() {
		$status       = self::$status;
		self::$status = null;

		return $status;
	}

	/**
	 * Expire the status cookie.
	 */
	private function expire_status_cookie() {
		setcookie(
			'automateflow_status',
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Small inline notice.
	 *
	 * @param string $message Text.
	 * @return string
	 */
	private function notice( $message ) {
		return '<p class="automateflow-form-notice">' . esc_html( $message ) . '</p>';
	}
}

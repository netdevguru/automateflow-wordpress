<?php
/**
 * HTTP client for the AutomateFlow public API.
 *
 * @package AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only place in the plugin that talks to the network.
 *
 * Mirrors the app's own convention of a single HTTP call-site: every request goes through
 * request(), so the API key header, the JSON envelope, the timeout and — most importantly —
 * the error mapping are decided once. A module that called wp_remote_post() directly would
 * get none of that and would report "something went wrong" for a problem the API described
 * precisely.
 *
 * ## Error envelopes this maps
 *
 * The API answers failures in four recognisable shapes, and each needs a different response
 * from the caller, so they are translated into WP_Error codes rather than a single blob:
 *
 * | Status | Body                                        | WP_Error code           |
 * |--------|---------------------------------------------|-------------------------|
 * | 401    | `{message}`                                 | `automateflow_auth`     |
 * | 403    | `{feature_limit_exceeded, feature}`         | `automateflow_feature`  |
 * | 422    | `{message, errors:{field:[...]}}`           | `automateflow_invalid`  |
 * | 429    | `{rate_limit_exceeded, window}`             | `automateflow_throttled`|
 *
 * `automateflow_throttled` is the one callers must handle rather than surface: the key is
 * limited to a fixed number of requests a minute, so a bulk sync will meet it routinely and
 * the right answer is to requeue, not to drop the record or show the user an error.
 */
class AutomateFlow_Client {

	/**
	 * Settings repository.
	 *
	 * @var AutomateFlow_Settings
	 */
	private $settings;

	/**
	 * Seconds before a request is abandoned.
	 *
	 * Kept well under PHP's default max_execution_time because several of these can run in
	 * one page load (an order can sync a customer and fire a trigger), and a hanging API must
	 * not turn into a hanging checkout.
	 */
	const TIMEOUT = 15;

	/**
	 * Constructor.
	 *
	 * @param AutomateFlow_Settings $settings Settings repository.
	 */
	public function __construct( AutomateFlow_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Perform a request against /api/v1.
	 *
	 * @param string                    $method HTTP verb.
	 * @param string                    $path   Path below /api/v1, with a leading slash.
	 * @param array<string, mixed>|null $body   Payload for write methods.
	 * @param array<string, mixed>      $query  Query-string arguments.
	 * @return array<string, mixed>|WP_Error Decoded response body, or an error.
	 */
	public function request( $method, $path, $body = null, array $query = array() ) {
		if ( ! $this->settings->is_configured() ) {
			return new WP_Error(
				'automateflow_unconfigured',
				__( 'AutomateFlow is not connected. Add your site URL and API key on the settings screen.', 'automateflow' )
			);
		}

		$url = $this->settings->base_url() . '/api/v1' . $path;

		if ( ! empty( $query ) ) {
			// add_query_arg() url-encodes values itself; encoding them first would double it
			// and turn a page number's neighbours into literal %25 sequences.
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'X-API-Key' => $this->settings->api_key(),
				'Accept'    => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// A transport failure (DNS, TLS, timeout) is not the API saying no — it is the
			// request never having arrived. Callers that queue work need to tell those apart.
			$this->settings->record_error( $response->get_error_message() );

			return new WP_Error(
				'automateflow_transport',
				sprintf(
					/* translators: %s: underlying transport error. */
					__( 'Could not reach AutomateFlow: %s', 'automateflow' ),
					$response->get_error_message()
				)
			);
		}

		return $this->interpret( $response, $method, $path );
	}

	/**
	 * Turn a raw response into decoded data or a typed WP_Error.
	 *
	 * @param array<string, mixed> $response wp_remote_request result.
	 * @param string               $method   Verb, for the log line.
	 * @param string               $path     Path, for the log line.
	 * @return array<string, mixed>|WP_Error
	 */
	private function interpret( $response, $method, $path ) {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $status >= 200 && $status < 300 ) {
			$this->settings->clear_error();

			return $data;
		}

		$error = $this->error_for( $status, $data );

		// Logged with the path but never the body: a failing request's payload is exactly the
		// place a subscriber's email address or an order's contents would be.
		AutomateFlow_Logger::error(
			$error->get_error_message(),
			array(
				'endpoint' => $method . ' ' . $path,
				'status'   => $status,
			)
		);

		$this->settings->record_error( $error->get_error_message() );

		return $error;
	}

	/**
	 * Map a failure status and body onto a typed error.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $data   Decoded body.
	 * @return WP_Error
	 */
	private function error_for( $status, array $data ) {
		if ( 429 === $status || ! empty( $data['rate_limit_exceeded'] ) ) {
			$window = isset( $data['window'] ) ? (string) $data['window'] : '';

			return new WP_Error(
				'automateflow_throttled',
				'' !== $window
					? sprintf(
						/* translators: %s: rate-limit window name, e.g. "per_minute". */
						__( 'AutomateFlow rate limit reached (%s). The request will be retried.', 'automateflow' ),
						$window
					)
					: __( 'AutomateFlow rate limit reached. The request will be retried.', 'automateflow' ),
				array( 'status' => $status )
			);
		}

		if ( ! empty( $data['feature_limit_exceeded'] ) ) {
			return new WP_Error(
				'automateflow_feature',
				sprintf(
					/* translators: %s: feature key, e.g. "api_access_enabled". */
					__( 'Your AutomateFlow plan does not include this capability (%s).', 'automateflow' ),
					isset( $data['feature'] ) ? (string) $data['feature'] : 'unknown'
				),
				array( 'status' => $status )
			);
		}

		if ( 401 === $status ) {
			return new WP_Error(
				'automateflow_auth',
				isset( $data['message'] )
					? sanitize_text_field( (string) $data['message'] )
					: __( 'AutomateFlow rejected the API key.', 'automateflow' ),
				array( 'status' => $status )
			);
		}

		if ( 422 === $status ) {
			return new WP_Error( 'automateflow_invalid', $this->validation_message( $data ), array( 'status' => $status ) );
		}

		return new WP_Error(
			'automateflow_http_' . $status,
			isset( $data['message'] )
				? sanitize_text_field( (string) $data['message'] )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'AutomateFlow returned an unexpected response (HTTP %d).', 'automateflow' ),
					$status
				),
			array( 'status' => $status )
		);
	}

	/**
	 * Flatten a Laravel validation envelope into one readable sentence.
	 *
	 * @param array<string, mixed> $data Decoded body.
	 * @return string
	 */
	private function validation_message( array $data ) {
		$parts = array();

		if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
			foreach ( $data['errors'] as $field => $messages ) {
				$messages = (array) $messages;
				$parts[]  = sanitize_text_field( $field . ': ' . implode( ' ', array_map( 'strval', $messages ) ) );
			}
		}

		if ( empty( $parts ) ) {
			return isset( $data['message'] )
				? sanitize_text_field( (string) $data['message'] )
				: __( 'AutomateFlow rejected the data as invalid.', 'automateflow' );
		}

		return implode( ' | ', $parts );
	}

	/* --------------------------------------------------------------------- *
	 * Endpoint wrappers
	 * --------------------------------------------------------------------- */

	/**
	 * Create or update a contact.
	 *
	 * The API upserts on (workspace, email), so this is safe to call repeatedly for the same
	 * person — which is what makes user-profile sync a plain "send the current state" rather
	 * than a create-or-update decision the plugin has to make.
	 *
	 * @param string               $email         Contact address.
	 * @param array<string, mixed> $fields        first_name, last_name, custom_fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function upsert_contact( $email, array $fields = array() ) {
		$payload = array_filter(
			array(
				'email'         => sanitize_email( $email ),
				'first_name'    => isset( $fields['first_name'] ) ? sanitize_text_field( $fields['first_name'] ) : null,
				'last_name'     => isset( $fields['last_name'] ) ? sanitize_text_field( $fields['last_name'] ) : null,
				'custom_fields' => isset( $fields['custom_fields'] ) ? (array) $fields['custom_fields'] : null,
			),
			static function ( $value ) {
				return null !== $value;
			}
		);

		return $this->request( 'POST', '/contacts', $payload );
	}

	/**
	 * Fetch a page of contacts.
	 *
	 * @param int $page     1-indexed page.
	 * @param int $per_page Page size; the API clamps this at 100.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_contacts( $page = 1, $per_page = 25 ) {
		return $this->request( 'GET', '/contacts', null, array( 'page' => (string) absint( $page ), 'per_page' => (string) absint( $per_page ) ) );
	}

	/**
	 * Unsubscribe a contact by id.
	 *
	 * @param int $contact_id Contact id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function unsubscribe_contact( $contact_id ) {
		return $this->request( 'POST', '/contacts/' . absint( $contact_id ) . '/unsubscribe' );
	}

	/**
	 * All lists in the workspace.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_lists() {
		return $this->request( 'GET', '/lists', null, array( 'per_page' => '100' ) );
	}

	/**
	 * Add a contact to a list.
	 *
	 * @param int $list_id    List id.
	 * @param int $contact_id Contact id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function add_contact_to_list( $list_id, $contact_id ) {
		return $this->request(
			'POST',
			'/lists/' . absint( $list_id ) . '/contacts',
			array( 'contact_id' => absint( $contact_id ) )
		);
	}

	/**
	 * A page of campaigns.
	 *
	 * @param int $page     1-indexed page.
	 * @param int $per_page Page size.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_campaigns( $page = 1, $per_page = 20 ) {
		return $this->request( 'GET', '/campaigns', null, array( 'page' => (string) absint( $page ), 'per_page' => (string) absint( $per_page ) ) );
	}

	/**
	 * Delivery and engagement counters for one campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_campaign_stats( $campaign_id ) {
		return $this->request( 'GET', '/campaigns/' . absint( $campaign_id ) . '/stats' );
	}

	/**
	 * Start sending a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_campaign( $campaign_id ) {
		return $this->request( 'POST', '/campaigns/' . absint( $campaign_id ) . '/send' );
	}

	/**
	 * Fire an automation trigger for a contact.
	 *
	 * @param string               $event_key Trigger key configured on the automation.
	 * @param string               $email     Contact address.
	 * @param array<string, mixed> $data      Context merged into the enrollment.
	 * @return array<string, mixed>|WP_Error
	 */
	public function trigger_automation( $event_key, $email, array $data = array() ) {
		return $this->request(
			'POST',
			'/automations/trigger',
			array(
				'event_key'     => sanitize_text_field( $event_key ),
				'contact_email' => sanitize_email( $email ),
				'data'          => $data,
			)
		);
	}

	/**
	 * A public form's definition.
	 *
	 * @param string $uuid Form uuid.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_form( $uuid ) {
		return $this->request( 'GET', '/forms/' . rawurlencode( $uuid ) );
	}

	/**
	 * Submit a public form.
	 *
	 * @param string               $uuid   Form uuid.
	 * @param array<string, mixed> $fields Submitted values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function submit_form( $uuid, array $fields ) {
		return $this->request( 'POST', '/forms/' . rawurlencode( $uuid ) . '/submit', $fields );
	}

	/**
	 * Send one transactional message.
	 *
	 * @param array<string, mixed> $message to, subject, from_email, html, text, attachments.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_transactional( array $message ) {
		return $this->request( 'POST', '/transactional/send', $message );
	}

	/**
	 * Cheapest authenticated call, used by the settings screen's connection test.
	 *
	 * Lists rather than contacts: the response is small on any workspace, where a contacts
	 * page is proportional to the account.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function test_connection() {
		return $this->request( 'GET', '/lists', null, array( 'per_page' => '1' ) );
	}
}

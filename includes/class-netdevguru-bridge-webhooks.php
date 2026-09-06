<?php
/**
 * Receives AutomateFlow's outbound webhooks.
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * A REST endpoint AutomateFlow can post events to.
 *
 * Register the printed URL as a webhook endpoint in the workspace, paste the generated secret
 * into both sides, and delivery events arrive here as WordPress actions.
 *
 * ## Verifying the signature
 *
 * `DeliverWebhookJob` signs the exact JSON body with the endpoint's secret:
 *
 *     hash_hmac( 'sha256', $body, $secret )
 *
 * and sends the result in `X-Webhook-Signature`. Two details matter and are easy to get
 * wrong. First, the header carries the **bare hex digest** — there is no `sha256=` prefix,
 * whatever the general convention elsewhere. This class tolerates one if a future version
 * adds it, but does not require it. Second, the signature covers the body *byte for byte*, so
 * it has to be verified against the raw payload; re-encoding the decoded array reorders keys
 * and changes escaping, and the comparison then fails for every legitimate request.
 *
 * The comparison uses hash_equals() so a wrong secret cannot be recovered one byte at a time
 * by timing the responses.
 */
class Netdevguru_Bridge_Webhooks {

	const NAMESPACE_V1 = 'netdevguru-bridge/v1';
	const ROUTE        = '/webhook';

	/**
	 * Events the API is known to emit.
	 *
	 * Listed so the admin screen can describe what to subscribe to. An unlisted event is
	 * still dispatched — the generic action fires for anything — but is not advertised.
	 *
	 * @var string[]
	 */
	const KNOWN_EVENTS = array(
		'contact.bounced',
		'contact.complained',
		'campaign.completed',
		'automation.completed',
		'automation.failed',
		'form.submitted',
		'list.contact_added',
	);

	/**
	 * Settings repository.
	 *
	 * @var Netdevguru_Bridge_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Netdevguru_Bridge_Settings $settings Settings repository.
	 */
	public function __construct( Netdevguru_Bridge_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );

		// Built-in reaction to the two events that mean "stop mailing this person": mirror the
		// state onto the WordPress user so the site's own view agrees with the platform's.
		add_action( 'netdevguru_bridge_webhook_contact_bounced', array( $this, 'flag_undeliverable' ) );
		add_action( 'netdevguru_bridge_webhook_contact_complained', array( $this, 'flag_undeliverable' ) );
	}

	/**
	 * Declare the route.
	 */
	public function register_route() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Authentication is the HMAC, checked inside the callback against the raw
				// body. Returning true here means "no WordPress capability required", which
				// is correct: the caller is a server, not a logged-in user.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Endpoint URL, for display on the settings screen.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Verify and dispatch one delivery.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$secret = $this->settings->webhook_secret();

		if ( '' === $secret ) {
			// Fails closed, matching the platform's own posture on an unset webhook secret:
			// an endpoint that accepts unauthenticated events is worse than one that is off.
			Netdevguru_Bridge_Logger::error( __( 'Webhook rejected: no shared secret is configured.', 'netdevguru-bridge-for-automateflow' ) );

			return new WP_REST_Response( array( 'message' => 'Webhook secret not configured.' ), 503 );
		}

		$body      = $request->get_body();
		$signature = (string) $request->get_header( 'x_webhook_signature' );
		$event     = sanitize_text_field( (string) $request->get_header( 'x_webhook_event' ) );

		if ( '' === $signature || ! $this->signature_matches( $body, $signature, $secret ) ) {
			Netdevguru_Bridge_Logger::error(
				__( 'Webhook rejected: signature mismatch.', 'netdevguru-bridge-for-automateflow' ),
				array( 'event' => '' !== $event ? $event : 'unknown' )
			);

			return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 401 );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'message' => 'Malformed payload.' ), 400 );
		}

		if ( '' === $event ) {
			// Older deliveries put the type in the body rather than a header.
			$event = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '';
		}

		if ( '' === $event ) {
			return new WP_REST_Response( array( 'message' => 'Missing event type.' ), 400 );
		}

		Netdevguru_Bridge_Logger::info(
			__( 'Webhook received.', 'netdevguru-bridge-for-automateflow' ),
			array( 'event' => $event )
		);

		/**
		 * Fires for every verified webhook.
		 *
		 * @param string               $event   Event type, e.g. "campaign.completed".
		 * @param array<string, mixed> $payload Decoded body.
		 */
		do_action( 'netdevguru_bridge_webhook', $event, $payload );

		/**
		 * Fires for one specific event, with dots replaced by underscores.
		 *
		 * `netdevguru_bridge_webhook_campaign_completed`, and so on.
		 *
		 * @param array<string, mixed> $payload Decoded body.
		 */
		do_action( 'netdevguru_bridge_webhook_' . str_replace( '.', '_', $event ), $payload );

		// 200 promptly: non-2xx puts the delivery into the platform's retry schedule and,
		// after enough attempts, its dead-letter path. Handlers hooked above run inline, so a
		// slow one costs the sender its ten-second timeout — heavy work belongs on wp-cron.
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Constant-time signature comparison.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Header value.
	 * @param string $secret    Shared secret.
	 * @return bool
	 */
	private function signature_matches( $body, $signature, $secret ) {
		// Tolerated but not required — see the class docblock.
		if ( 0 === stripos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		return hash_equals( hash_hmac( 'sha256', $body, $secret ), trim( $signature ) );
	}

	/**
	 * Mark the matching WordPress user as undeliverable.
	 *
	 * Recorded as user meta rather than acted on: what a site should *do* about a bounce is a
	 * policy decision — block checkout, hide from a directory, nothing at all — and belongs to
	 * the site, not to this plugin. The meta and the action below are the hooks for it.
	 *
	 * @param array<string, mixed> $payload Webhook body.
	 */
	public function flag_undeliverable( $payload ) {
		$email = '';

		foreach ( array( 'email', 'contact_email' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				$email = sanitize_email( (string) $payload[ $key ] );
				break;
			}
		}

		if ( '' === $email && ! empty( $payload['contact']['email'] ) ) {
			$email = sanitize_email( (string) $payload['contact']['email'] );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return;
		}

		update_user_meta( $user->ID, '_netdevguru_bridge_undeliverable', time() );

		/**
		 * Fires when a synced user's address bounced or drew a complaint.
		 *
		 * @param WP_User              $user    Affected user.
		 * @param array<string, mixed> $payload Webhook body.
		 */
		do_action( 'netdevguru_bridge_user_undeliverable', $user, $payload );
	}
}

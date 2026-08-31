<?php
/**
 * WordPress users → AutomateFlow contacts.
 *
 * @package AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the contact record for each eligible WordPress user current.
 *
 * ## Why every write is queued rather than sent inline
 *
 * The obvious implementation calls the API from `user_register`. That puts a third-party HTTP
 * request inside the request that is registering the user, so a slow or unreachable API makes
 * signup slow or, on a timeout, appears to fail — for a side effect the user did not ask for
 * and cannot see. Worse, the API is rate limited per key, so a burst of registrations (an
 * import, a migration, a spam wave) would start losing records with no trace.
 *
 * Queueing decouples the two: the hook records an intent and returns, and a cron batch drains
 * it against whatever budget the API currently has. A 429 stops the batch with the remainder
 * still queued, so throttling costs latency instead of data.
 */
class AutomateFlow_Contacts {

	const QUEUE_HOOK = 'automateflow_process_sync_queue';
	const BATCH_SIZE = 20;
	const META_KEY   = '_automateflow_contact_id';
	const OPT_IN_KEY = 'automateflow_opt_in';

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
		// The queue drains even when the feature is switched off mid-flight, so records that
		// were already accepted are not silently stranded.
		add_action( self::QUEUE_HOOK, array( $this, 'process_queue' ) );

		if ( ! $this->settings->is_enabled( 'contacts' ) ) {
			return;
		}

		add_action( 'user_register', array( $this, 'enqueue_user' ) );
		add_action( 'profile_update', array( $this, 'enqueue_user' ) );
		add_action( 'delete_user', array( $this, 'handle_user_deleted' ) );

		// Comment opt-in: a checkbox under the comment form, honoured only when the commenter
		// ticks it. Nothing about leaving a comment implies consent to be mailed.
		add_action( 'comment_form_after_fields', array( $this, 'render_comment_opt_in' ) );
		add_action( 'comment_post', array( $this, 'handle_comment_opt_in' ), 10, 2 );
	}

	/**
	 * Queue a user for sync.
	 *
	 * @param int $user_id User id.
	 */
	public function enqueue_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $this->is_eligible( $user_id ) ) {
			return;
		}

		$queue = $this->queue();

		// Keyed by user id so repeated profile saves collapse into one pending sync — the
		// payload is built at drain time from current state, so an older entry has no value.
		$queue[ 'user:' . $user_id ] = array(
			'type' => 'user',
			'id'   => $user_id,
		);

		$this->save_queue( $queue );
	}

	/**
	 * Queue a bare email address (comment opt-in, WooCommerce guest checkout).
	 *
	 * @param string               $email  Address.
	 * @param array<string, mixed> $fields first_name/last_name/custom_fields.
	 */
	public function enqueue_email( $email, array $fields = array() ) {
		$email = sanitize_email( $email );

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$queue = $this->queue();

		$queue[ 'email:' . strtolower( $email ) ] = array(
			'type'   => 'email',
			'email'  => $email,
			'fields' => $fields,
		);

		$this->save_queue( $queue );
	}

	/**
	 * Drain a bounded slice of the queue.
	 *
	 * Stops early and leaves the remainder in place on a throttle or a transport failure —
	 * continuing would burn the rest of the batch on requests certain to fail the same way.
	 */
	public function process_queue() {
		$queue = $this->queue();

		if ( empty( $queue ) ) {
			return;
		}

		$batch     = array_slice( $queue, 0, self::BATCH_SIZE, true );
		$processed = 0;

		foreach ( $batch as $key => $item ) {
			$result = $this->sync_item( $item );

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();

				if ( 'automateflow_throttled' === $code || 'automateflow_transport' === $code ) {
					// Retriable: keep this item and everything after it.
					break;
				}

				// A permanent rejection (bad address, revoked key, plan limit) would loop for
				// ever if it stayed queued. Drop it — it is already in the log.
				unset( $queue[ $key ] );
				++$processed;

				continue;
			}

			unset( $queue[ $key ] );
			++$processed;
		}

		$this->save_queue( $queue );

		if ( $processed > 0 ) {
			AutomateFlow_Logger::info(
				sprintf(
					/* translators: 1: number synced, 2: number still queued. */
					__( 'Synced %1$d contact(s); %2$d still queued.', 'automateflow' ),
					$processed,
					count( $queue )
				)
			);
		}
	}

	/**
	 * Push one queue entry to the API.
	 *
	 * @param array<string, mixed> $item Queue entry.
	 * @return array<string, mixed>|WP_Error
	 */
	private function sync_item( array $item ) {
		if ( 'email' === $item['type'] ) {
			return $this->push_contact( $item['email'], isset( $item['fields'] ) ? (array) $item['fields'] : array(), 0 );
		}

		$user = get_userdata( absint( $item['id'] ) );

		if ( ! $user ) {
			// Deleted between enqueue and drain. Not an error; nothing to send.
			return array();
		}

		return $this->push_contact( $user->user_email, $this->fields_for_user( $user ), $user->ID );
	}

	/**
	 * Upsert a contact and file it on the configured list.
	 *
	 * @param string               $email   Address.
	 * @param array<string, mixed> $fields  Contact fields.
	 * @param int                  $user_id Originating WP user, or 0.
	 * @return array<string, mixed>|WP_Error
	 */
	private function push_contact( $email, array $fields, $user_id ) {
		$response = $this->client->upsert_contact( $email, $fields );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$contact_id = isset( $response['data']['id'] ) ? absint( $response['data']['id'] ) : 0;

		if ( $contact_id > 0 && $user_id > 0 ) {
			// Stored so later operations (unsubscribe on user delete) address the contact by
			// id rather than re-resolving it by email.
			update_user_meta( $user_id, self::META_KEY, $contact_id );
		}

		$list_id = $this->settings->default_list_id();

		if ( $contact_id > 0 && $list_id > 0 ) {
			$added = $this->client->add_contact_to_list( $list_id, $contact_id );

			// A list membership failure is worth logging but must not fail the whole sync:
			// the contact itself is saved, and re-queueing would re-upsert it pointlessly.
			if ( is_wp_error( $added ) ) {
				AutomateFlow_Logger::warning(
					__( 'Contact saved but could not be added to the default list.', 'automateflow' ),
					array( 'list_id' => $list_id )
				);
			}
		}

		return $response;
	}

	/**
	 * Build the API payload for a user.
	 *
	 * @param WP_User $user User.
	 * @return array<string, mixed>
	 */
	private function fields_for_user( WP_User $user ) {
		$custom = array(
			'wp_user_id'  => $user->ID,
			'wp_username' => $user->user_login,
			'wp_roles'    => implode( ',', (array) $user->roles ),
		);

		foreach ( $this->settings->field_map() as $meta_key => $field_name ) {
			$meta_key   = sanitize_key( $meta_key );
			$field_name = sanitize_key( $field_name );

			if ( '' === $meta_key || '' === $field_name ) {
				continue;
			}

			$value = get_user_meta( $user->ID, $meta_key, true );

			// Only scalars: the custom_fields column is JSON, but a serialized object round
			// -tripped through it is unreadable in the AutomateFlow UI and unusable in a segment.
			if ( is_scalar( $value ) && '' !== $value ) {
				$custom[ $field_name ] = sanitize_text_field( (string) $value );
			}
		}

		/**
		 * Filter the contact payload for a user.
		 *
		 * @param array<string, mixed> $fields Payload.
		 * @param WP_User              $user   Source user.
		 */
		return apply_filters(
			'automateflow_user_contact_fields',
			array(
				'first_name'    => $user->first_name,
				'last_name'     => $user->last_name,
				'custom_fields' => $custom,
			),
			$user
		);
	}

	/**
	 * Should this user be synced at all?
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function is_eligible( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$allowed = $this->settings->sync_roles();

		if ( ! empty( $allowed ) && empty( array_intersect( $allowed, (array) $user->roles ) ) ) {
			return false;
		}

		/**
		 * Filter whether a user is synced.
		 *
		 * @param bool    $eligible Current decision.
		 * @param WP_User $user     User under consideration.
		 */
		return (bool) apply_filters( 'automateflow_should_sync_user', true, $user );
	}

	/**
	 * Unsubscribe the matching contact when a user is deleted.
	 *
	 * Unsubscribe rather than delete, deliberately. The contact may predate the WordPress
	 * account and may belong to lists this site knows nothing about; removing it would
	 * destroy engagement history and, if they later re-register, lose the record that they
	 * had opted out. Unsubscribing stops the mail, which is the part that matters.
	 *
	 * @param int $user_id User id.
	 */
	public function handle_user_deleted( $user_id ) {
		$contact_id = absint( get_user_meta( absint( $user_id ), self::META_KEY, true ) );

		if ( $contact_id <= 0 ) {
			return;
		}

		$result = $this->client->unsubscribe_contact( $contact_id );

		if ( is_wp_error( $result ) ) {
			AutomateFlow_Logger::warning(
				__( 'Could not unsubscribe the contact for a deleted user.', 'automateflow' ),
				array( 'contact_id' => $contact_id )
			);
		}
	}

	/**
	 * Opt-in checkbox under the comment form.
	 */
	public function render_comment_opt_in() {
		printf(
			'<p class="comment-form-automateflow"><label for="%1$s"><input type="checkbox" name="%1$s" id="%1$s" value="1" /> %2$s</label></p>',
			esc_attr( self::OPT_IN_KEY ),
			esc_html__( 'Subscribe me to the newsletter', 'automateflow' )
		);
	}

	/**
	 * Queue a commenter who ticked the box.
	 *
	 * @param int        $comment_id Comment id.
	 * @param int|string $approved   Approval state.
	 */
	public function handle_comment_opt_in( $comment_id, $approved ) {
		// Spam and held comments are not consent. A comment that is later approved simply
		// does not subscribe anyone, which is the safe direction to be wrong in.
		if ( 1 !== (int) $approved ) {
			return;
		}

		// The checkbox is a plain form field on a public, nonce-less form (WordPress does not
		// nonce the comment form); the value is only ever read as a boolean and the address
		// comes from the stored comment, not from the request.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST[ self::OPT_IN_KEY ] ) ) {
			return;
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment || ! is_email( $comment->comment_author_email ) ) {
			return;
		}

		$this->enqueue_email(
			$comment->comment_author_email,
			array(
				'first_name'    => $comment->comment_author,
				'custom_fields' => array( 'source' => 'wordpress_comment' ),
			)
		);
	}

	/**
	 * Queue every eligible existing user.
	 *
	 * Used by the "sync all users" button. Returns the number queued rather than syncing
	 * inline — on a site with thousands of users the request would time out long before the
	 * rate limiter let it finish.
	 *
	 * @return int
	 */
	public function enqueue_all_users() {
		$user_ids = get_users(
			array(
				'fields' => 'ID',
				'number' => -1,
			)
		);

		// Built in memory and written once. Calling enqueue_user() in the loop would be one
		// update_option() — a real database write — per user, so a 5,000-user site would
		// issue 5,000 writes to record a single intent.
		$queue  = $this->queue();
		$queued = 0;

		foreach ( $user_ids as $user_id ) {
			$user_id = absint( $user_id );

			if ( ! $this->is_eligible( $user_id ) ) {
				continue;
			}

			$key = 'user:' . $user_id;

			if ( isset( $queue[ $key ] ) ) {
				continue;
			}

			$queue[ $key ] = array(
				'type' => 'user',
				'id'   => $user_id,
			);

			++$queued;
		}

		$this->save_queue( $queue );

		return $queued;
	}

	/**
	 * Pending queue.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function queue() {
		$queue = get_option( AutomateFlow_Settings::OPT_SYNC_QUEUE, array() );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Persist the queue, non-autoloaded.
	 *
	 * @param array<string, array<string, mixed>> $queue Queue.
	 */
	private function save_queue( array $queue ) {
		update_option( AutomateFlow_Settings::OPT_SYNC_QUEUE, $queue, false );
	}
}

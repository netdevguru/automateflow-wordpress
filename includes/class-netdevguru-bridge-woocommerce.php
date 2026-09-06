<?php
/**
 * WooCommerce customer sync and order-driven automation triggers.
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns store activity into contacts and automation enrollments.
 *
 * ## Consent is separate from the contact record
 *
 * Two different things happen when an order is placed, and conflating them is how a store
 * ends up marketing to people who never agreed to it:
 *
 * - The **contact** is upserted so order automations (receipts, review requests, win-back)
 *   have someone to run against. This is the transactional side of an existing customer
 *   relationship.
 * - **List membership** — the marketing side — happens only when the customer ticked the
 *   opt-in box at checkout.
 *
 * With "require consent" left on (the default), neither happens without the tick, which is
 * the conservative reading and the right default for a store that has not thought about it.
 * Turning it off enables order automations for every customer while still gating the list.
 *
 * ## Order state, not order events
 *
 * Triggers fire from `woocommerce_order_status_*` rather than from checkout hooks, because a
 * payment gateway can complete an order minutes later, off-session, and an order created in
 * wp-admin never passes through checkout at all. Status transitions catch every route in.
 */
class Netdevguru_Bridge_WooCommerce {

	const OPT_IN_FIELD      = 'netdevguru_bridge_marketing_opt_in';
	const CONSENT_META      = '_netdevguru_bridge_marketing_consent';
	const TRIGGER_PLACED    = 'woocommerce_order_placed';
	const TRIGGER_COMPLETED = 'woocommerce_order_completed';
	const TRIGGER_REFUNDED  = 'woocommerce_order_refunded';
	const TRIGGER_CANCELLED = 'woocommerce_order_cancelled';

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
		if ( ! $this->settings->is_enabled( 'woocommerce' ) ) {
			return;
		}

		// Classic checkout opt-in. The block checkout collects marketing consent through its
		// own field, which surfaces here as order meta, so both paths land in record_consent().
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_opt_in' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'record_consent' ), 10, 2 );

		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_placed' ) );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'handle_placed' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_completed' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_refunded' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_cancelled' ) );
	}

	/**
	 * Marketing opt-in checkbox on the classic checkout.
	 */
	public function render_opt_in() {
		woocommerce_form_field(
			self::OPT_IN_FIELD,
			array(
				'type'  => 'checkbox',
				'class' => array( 'form-row', 'netdevguru-bridge-opt-in' ),
				'label' => __( 'Email me news and offers', 'netdevguru-bridge-for-automateflow' ),
			),
			// Never pre-ticked: a pre-checked marketing box is not consent under GDPR, and
			// several jurisdictions treat it as an outright violation.
			false
		);
	}

	/**
	 * Persist the customer's choice onto the order.
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 */
	public function record_consent( $order, $data ) {
		unset( $data );

		// WooCommerce has already verified the checkout nonce by this point; this only reads
		// a boolean out of the same validated submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$opted_in = ! empty( $_POST[ self::OPT_IN_FIELD ] );

		$order->update_meta_data( self::CONSENT_META, $opted_in ? 'yes' : 'no' );
	}

	/**
	 * Order reached a paid or held state.
	 *
	 * @param int $order_id Order id.
	 */
	public function handle_placed( $order_id ) {
		$this->process( $order_id, self::TRIGGER_PLACED, true );
	}

	/**
	 * Order completed.
	 *
	 * @param int $order_id Order id.
	 */
	public function handle_completed( $order_id ) {
		$this->process( $order_id, self::TRIGGER_COMPLETED, true );
	}

	/**
	 * Order refunded.
	 *
	 * @param int $order_id Order id.
	 */
	public function handle_refunded( $order_id ) {
		$this->process( $order_id, self::TRIGGER_REFUNDED, false );
	}

	/**
	 * Order cancelled.
	 *
	 * @param int $order_id Order id.
	 */
	public function handle_cancelled( $order_id ) {
		$this->process( $order_id, self::TRIGGER_CANCELLED, false );
	}

	/**
	 * Sync the customer and fire the trigger.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $event_key Automation trigger key.
	 * @param bool   $may_list  Whether this event may add the customer to the marketing list.
	 */
	private function process( $order_id, $event_key, $may_list ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// A status can be set more than once — a gateway callback racing an admin edit, a
		// plugin re-saving the order — and each pass would re-enroll the customer in the same
		// automation. One marker per order per event makes the handler idempotent.
		$marker = '_netdevguru_bridge_fired_' . $event_key;

		if ( 'yes' === $order->get_meta( $marker ) ) {
			return;
		}

		$email = sanitize_email( $order->get_billing_email() );

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$consented = 'yes' === $order->get_meta( self::CONSENT_META );

		if ( $this->requires_consent() && ! $consented ) {
			// Marked as handled so a later status change on the same order does not retry a
			// decision that will not change.
			$order->update_meta_data( $marker, 'yes' );
			$order->save();

			return;
		}

		$contact = $this->client->upsert_contact( $email, $this->contact_fields( $order ) );

		if ( is_wp_error( $contact ) ) {
			Netdevguru_Bridge_Logger::warning(
				__( 'Could not sync a WooCommerce customer.', 'netdevguru-bridge-for-automateflow' ),
				array(
					'order'  => $order->get_order_number(),
					'reason' => $contact->get_error_code(),
				)
			);

			// Left unmarked on purpose: a transport or throttle failure should be retried by
			// the next status transition rather than swallowed.
			return;
		}

		$contact_id = isset( $contact['data']['id'] ) ? absint( $contact['data']['id'] ) : 0;
		$list_id    = $this->settings->woo_list_id();

		if ( $may_list && $consented && $contact_id > 0 && $list_id > 0 ) {
			$added = $this->client->add_contact_to_list( $list_id, $contact_id );

			if ( is_wp_error( $added ) ) {
				Netdevguru_Bridge_Logger::warning(
					__( 'Customer synced but not added to the store list.', 'netdevguru-bridge-for-automateflow' ),
					array( 'list_id' => $list_id )
				);
			}
		}

		$triggered = $this->client->trigger_automation( $event_key, $email, $this->order_context( $order ) );

		if ( is_wp_error( $triggered ) ) {
			Netdevguru_Bridge_Logger::warning(
				__( 'Order automation trigger failed.', 'netdevguru-bridge-for-automateflow' ),
				array(
					'order' => $order->get_order_number(),
					'event' => $event_key,
				)
			);

			return;
		}

		$order->update_meta_data( $marker, 'yes' );
		$order->save();

		Netdevguru_Bridge_Logger::info(
			__( 'Order automation triggered.', 'netdevguru-bridge-for-automateflow' ),
			array(
				'order' => $order->get_order_number(),
				'event' => $event_key,
			)
		);
	}

	/**
	 * Contact payload derived from the order and the customer's history.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private function contact_fields( $order ) {
		$custom = array(
			'source'          => 'woocommerce',
			'wc_last_order'   => $order->get_order_number(),
			'wc_order_total'  => (string) $order->get_total(),
			'wc_currency'     => $order->get_currency(),
			'wc_billing_city' => $order->get_billing_city(),
			'wc_country'      => $order->get_billing_country(),
		);

		$customer_id = $order->get_customer_id();

		if ( $customer_id > 0 ) {
			$custom['wp_user_id'] = $customer_id;

			// Lifetime figures make the difference between "a customer" and "a good customer"
			// segmentable on the platform side without the store exporting anything else.
			$custom['wc_order_count'] = (string) wc_get_customer_order_count( $customer_id );
			$custom['wc_total_spent'] = (string) wc_get_customer_total_spent( $customer_id );
		}

		/**
		 * Filter the contact payload built from an order.
		 *
		 * @param array<string, mixed> $fields Payload.
		 * @param WC_Order             $order  Source order.
		 */
		return apply_filters(
			'netdevguru_bridge_woocommerce_contact_fields',
			array(
				'first_name'    => $order->get_billing_first_name(),
				'last_name'     => $order->get_billing_last_name(),
				'custom_fields' => $custom,
			),
			$order
		);
	}

	/**
	 * Automation context for the order.
	 *
	 * Item names and quantities only. The temptation is to send the whole order object, but
	 * everything here is resolvable as `{{trigger.field}}` inside an automation and lands in
	 * an enrollment context that is stored per enrollment — so a fat payload is paid for on
	 * every row, for data an email template will never reference.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private function order_context( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (string) $item->get_total(),
			);
		}

		/**
		 * Filter the automation context for an order.
		 *
		 * @param array<string, mixed> $context Context.
		 * @param WC_Order             $order   Source order.
		 */
		return apply_filters(
			'netdevguru_bridge_woocommerce_order_context',
			array(
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'status'       => $order->get_status(),
				'total'        => (string) $order->get_total(),
				'currency'     => $order->get_currency(),
				'first_name'   => $order->get_billing_first_name(),
				'items'        => $items,
				'order_url'    => $order->get_view_order_url(),
			),
			$order
		);
	}

	/**
	 * Whether an explicit opt-in is required before anything is sent.
	 *
	 * @return bool
	 */
	private function requires_consent() {
		return $this->settings->woo_requires_consent();
	}
}

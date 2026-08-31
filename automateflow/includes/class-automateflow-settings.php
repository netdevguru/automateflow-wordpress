<?php
/**
 * Typed access to every stored option.
 *
 * @package AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * One place that knows option names, defaults and coercion.
 *
 * Every other class asks this rather than calling get_option() directly, so a renamed key or
 * a changed default is a single edit and no module can disagree with another about what
 * "enabled" means.
 */
class AutomateFlow_Settings {

	const OPT_BASE_URL       = 'automateflow_base_url';
	const OPT_API_KEY        = 'automateflow_api_key';
	const OPT_FEATURES       = 'automateflow_features';
	const OPT_DEFAULT_LIST   = 'automateflow_default_list_id';
	const OPT_SYNC_ROLES     = 'automateflow_sync_roles';
	const OPT_FIELD_MAP      = 'automateflow_field_map';
	const OPT_WEBHOOK_SECRET = 'automateflow_webhook_secret';
	const OPT_MAIL_FROM      = 'automateflow_mail_from';
	const OPT_MAIL_FROM_NAME = 'automateflow_mail_from_name';
	const OPT_WOO_LIST       = 'automateflow_woo_list_id';
	const OPT_WOO_CONSENT    = 'automateflow_woo_require_consent';
	const OPT_SYNC_QUEUE     = 'automateflow_sync_queue';
	const OPT_LAST_ERROR     = 'automateflow_last_error';

	/**
	 * Feature switches, all default-off.
	 *
	 * Off by default is deliberate for every one of these: activating the plugin must not
	 * start mailing through a third party, rewriting wp_mail(), or shipping the user table
	 * anywhere until someone has entered a key and chosen to.
	 *
	 * @var array<string, string>
	 */
	const FEATURES = array(
		'contacts'    => 'Sync WordPress users as contacts',
		'mailer'      => 'Route site email through AutomateFlow',
		'forms'       => 'Embed AutomateFlow forms',
		'campaigns'   => 'Campaign browser in wp-admin',
		'woocommerce' => 'WooCommerce customer sync and order triggers',
	);

	/**
	 * Base URL of the AutomateFlow install, without a trailing slash.
	 *
	 * @return string
	 */
	public function base_url() {
		return untrailingslashit( (string) get_option( self::OPT_BASE_URL, '' ) );
	}

	/**
	 * The API key, or an empty string.
	 *
	 * Never echo this. `AutomateFlow_Admin` renders a masked placeholder and only writes a
	 * new value when the submitted field is non-empty, so the real key is never sent to the
	 * browser and cannot leak through a page cache or a browser autofill store.
	 *
	 * @return string
	 */
	public function api_key() {
		return (string) get_option( self::OPT_API_KEY, '' );
	}

	/**
	 * Whether both halves of the credential are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->base_url() && '' !== $this->api_key();
	}

	/**
	 * Is a named feature switched on?
	 *
	 * A feature can never be on while the plugin is unconfigured — that would let a hook fire
	 * into a client with nowhere to send the request, once per page load.
	 *
	 * @param string $feature Key from self::FEATURES.
	 * @return bool
	 */
	public function is_enabled( $feature ) {
		if ( ! $this->is_configured() ) {
			return false;
		}

		$features = (array) get_option( self::OPT_FEATURES, array() );

		return ! empty( $features[ $feature ] );
	}

	/**
	 * All feature switches as a key => bool map.
	 *
	 * @return array<string, bool>
	 */
	public function features() {
		$stored = (array) get_option( self::OPT_FEATURES, array() );
		$out    = array();

		foreach ( array_keys( self::FEATURES ) as $key ) {
			$out[ $key ] = ! empty( $stored[ $key ] );
		}

		return $out;
	}

	/**
	 * List new contacts are added to, or 0 for none.
	 *
	 * @return int
	 */
	public function default_list_id() {
		return absint( get_option( self::OPT_DEFAULT_LIST, 0 ) );
	}

	/**
	 * List WooCommerce customers are added to. Falls back to the general default.
	 *
	 * @return int
	 */
	public function woo_list_id() {
		$id = absint( get_option( self::OPT_WOO_LIST, 0 ) );

		return $id > 0 ? $id : $this->default_list_id();
	}

	/**
	 * Must a WooCommerce customer opt in before anything is sent to the platform?
	 *
	 * Defaults to true, and the default is the point: a store owner who enables the
	 * integration without reading the screen gets the conservative behaviour, not a silent
	 * export of their customer list. `'0'` is checked explicitly because an unset option and
	 * a deliberately-disabled one must not look the same.
	 *
	 * @return bool
	 */
	public function woo_requires_consent() {
		return '0' !== (string) get_option( self::OPT_WOO_CONSENT, '1' );
	}

	/**
	 * WordPress roles eligible for contact sync.
	 *
	 * Empty means every role. Administrators are not special-cased — a site owner who wants
	 * their own address in the list should be able to have it.
	 *
	 * @return string[]
	 */
	public function sync_roles() {
		$roles = get_option( self::OPT_SYNC_ROLES, array() );

		return is_array( $roles ) ? array_values( array_filter( array_map( 'sanitize_key', $roles ) ) ) : array();
	}

	/**
	 * WP user meta key => AutomateFlow custom field name.
	 *
	 * @return array<string, string>
	 */
	public function field_map() {
		$map = get_option( self::OPT_FIELD_MAP, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Shared secret used to verify inbound webhooks.
	 *
	 * @return string
	 */
	public function webhook_secret() {
		return (string) get_option( self::OPT_WEBHOOK_SECRET, '' );
	}

	/**
	 * Envelope sender for transactional mail, defaulting to the site admin address.
	 *
	 * @return string
	 */
	public function mail_from() {
		$configured = sanitize_email( (string) get_option( self::OPT_MAIL_FROM, '' ) );

		return '' !== $configured ? $configured : sanitize_email( (string) get_option( 'admin_email' ) );
	}

	/**
	 * Display name for transactional mail, defaulting to the site title.
	 *
	 * @return string
	 */
	public function mail_from_name() {
		$configured = trim( (string) get_option( self::OPT_MAIL_FROM_NAME, '' ) );

		return '' !== $configured ? $configured : wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * Record the most recent API failure for the settings screen.
	 *
	 * Stored rather than logged because the person who needs to see a bad key is looking at
	 * wp-admin, not at debug.log.
	 *
	 * @param string $message Human-readable failure.
	 */
	public function record_error( $message ) {
		update_option(
			self::OPT_LAST_ERROR,
			array(
				'message' => sanitize_text_field( $message ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Clear the stored failure after a success.
	 */
	public function clear_error() {
		delete_option( self::OPT_LAST_ERROR );
	}

	/**
	 * Most recent API failure, or null.
	 *
	 * @return array{message:string,time:int}|null
	 */
	public function last_error() {
		$error = get_option( self::OPT_LAST_ERROR, null );

		return is_array( $error ) && isset( $error['message'], $error['time'] ) ? $error : null;
	}
}

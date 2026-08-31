<?php
/**
 * wp-admin screens.
 *
 * @package AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu, settings form and the campaign browser.
 *
 * Every write goes through admin-post.php with a nonce and a `manage_options` check, rather
 * than through the Settings API. The screen mixes stored options with actions that are not
 * settings at all — test the connection, queue a full user sync, drop the form cache — and
 * splitting those across two mechanisms would mean two permission models to keep in step.
 */
class AutomateFlow_Admin {

	const CAPABILITY = 'manage_options';
	const MENU_SLUG  = 'automateflow';
	const SAVE       = 'automateflow_save_settings';
	const TEST       = 'automateflow_test_connection';
	const SYNC_ALL   = 'automateflow_sync_all_users';
	const FLUSH      = 'automateflow_flush_cache';
	const CLEAR_LOG  = 'automateflow_clear_log';
	const SEND       = 'automateflow_send_campaign';

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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_' . self::SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::TEST, array( $this, 'handle_test' ) );
		add_action( 'admin_post_' . self::SYNC_ALL, array( $this, 'handle_sync_all' ) );
		add_action( 'admin_post_' . self::FLUSH, array( $this, 'handle_flush' ) );
		add_action( 'admin_post_' . self::CLEAR_LOG, array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_' . self::SEND, array( $this, 'handle_send_campaign' ) );

		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Menu entries.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'AutomateFlow', 'automateflow' ),
			__( 'AutomateFlow', 'automateflow' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-email-alt',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'automateflow' ),
			__( 'Settings', 'automateflow' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( $this->settings->is_enabled( 'campaigns' ) ) {
			add_submenu_page(
				self::MENU_SLUG,
				__( 'Campaigns', 'automateflow' ),
				__( 'Campaigns', 'automateflow' ),
				self::CAPABILITY,
				self::MENU_SLUG . '-campaigns',
				array( $this, 'render_campaigns_page' )
			);
		}

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Activity Log', 'automateflow' ),
			__( 'Activity Log', 'automateflow' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-log',
			array( $this, 'render_log_page' )
		);
	}

	/**
	 * Admin stylesheet, only on this plugin's screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'automateflow-admin',
			AUTOMATEFLOW_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AUTOMATEFLOW_VERSION
		);
	}

	/* --------------------------------------------------------------------- *
	 * Screens
	 * --------------------------------------------------------------------- */

	/**
	 * Settings screen.
	 */
	public function render_settings_page() {
		$this->guard();

		$settings = $this->settings;
		$lists    = $this->available_lists();
		$roles    = wp_roles()->get_names();

		require AUTOMATEFLOW_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Campaign browser.
	 */
	public function render_campaigns_page() {
		$this->guard();

		// Read-only screen; the page argument only selects which page of the API's own
		// pagination to show, so a nonce would be noise.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

		$response  = $this->client->get_campaigns( $page );
		$error     = is_wp_error( $response ) ? $response : null;
		$campaigns = array();
		$last_page = 1;

		if ( ! $error ) {
			$campaigns = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
			$last_page = isset( $response['last_page'] ) ? absint( $response['last_page'] ) : 1;
		}

		// One extra call, and only when a row's Stats link was followed — see the note at the
		// top of the view about why this is not a column.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stats_for = isset( $_GET['campaign'] ) ? absint( wp_unslash( $_GET['campaign'] ) ) : 0;
		$stats     = null;

		if ( $stats_for > 0 ) {
			$stats_response = $this->client->get_campaign_stats( $stats_for );

			if ( ! is_wp_error( $stats_response ) && isset( $stats_response['data'] ) && is_array( $stats_response['data'] ) ) {
				$stats = $stats_response['data'];
			}
		}

		require AUTOMATEFLOW_PLUGIN_DIR . 'admin/views/campaigns.php';
	}

	/**
	 * Activity log.
	 */
	public function render_log_page() {
		$this->guard();

		$entries = AutomateFlow_Logger::entries();

		require AUTOMATEFLOW_PLUGIN_DIR . 'admin/views/log.php';
	}

	/* --------------------------------------------------------------------- *
	 * Actions
	 * --------------------------------------------------------------------- */

	/**
	 * Persist the settings form.
	 */
	public function handle_save() {
		$this->guard();
		check_admin_referer( self::SAVE );

		$base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
		update_option( AutomateFlow_Settings::OPT_BASE_URL, untrailingslashit( $base_url ) );

		// Only overwrite the key when something was typed. The field renders empty with a
		// placeholder, so an admin who saves the form without touching it keeps the stored
		// key instead of blanking it — and the real value never travels to the browser.
		$api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';

		if ( '' !== $api_key ) {
			update_option( AutomateFlow_Settings::OPT_API_KEY, $api_key );
		}

		$features = array();

		foreach ( array_keys( AutomateFlow_Settings::FEATURES ) as $feature ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
			$features[ $feature ] = ! empty( $_POST['features'][ $feature ] );
		}

		update_option( AutomateFlow_Settings::OPT_FEATURES, $features );

		update_option(
			AutomateFlow_Settings::OPT_DEFAULT_LIST,
			isset( $_POST['default_list_id'] ) ? absint( wp_unslash( $_POST['default_list_id'] ) ) : 0
		);

		update_option(
			AutomateFlow_Settings::OPT_WOO_LIST,
			isset( $_POST['woo_list_id'] ) ? absint( wp_unslash( $_POST['woo_list_id'] ) ) : 0
		);

		update_option(
			AutomateFlow_Settings::OPT_WOO_CONSENT,
			empty( $_POST['woo_require_consent'] ) ? '0' : '1'
		);

		$roles = array();

		if ( isset( $_POST['sync_roles'] ) && is_array( $_POST['sync_roles'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key applied per entry.
			foreach ( wp_unslash( $_POST['sync_roles'] ) as $role ) {
				$roles[] = sanitize_key( $role );
			}
		}

		update_option( AutomateFlow_Settings::OPT_SYNC_ROLES, array_values( array_filter( $roles ) ) );

		update_option(
			AutomateFlow_Settings::OPT_WEBHOOK_SECRET,
			isset( $_POST['webhook_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) ) : ''
		);

		update_option(
			AutomateFlow_Settings::OPT_MAIL_FROM,
			isset( $_POST['mail_from'] ) ? sanitize_email( wp_unslash( $_POST['mail_from'] ) ) : ''
		);

		update_option(
			AutomateFlow_Settings::OPT_MAIL_FROM_NAME,
			isset( $_POST['mail_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mail_from_name'] ) ) : ''
		);

		update_option( AutomateFlow_Settings::OPT_FIELD_MAP, $this->parse_field_map() );

		$this->redirect_back( 'saved' );
	}

	/**
	 * Read the repeatable field-map rows.
	 *
	 * @return array<string, string>
	 */
	private function parse_field_map() {
		$map = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer runs in the caller.
		$keys = isset( $_POST['map_meta_key'] ) && is_array( $_POST['map_meta_key'] ) ? wp_unslash( $_POST['map_meta_key'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$names = isset( $_POST['map_field_name'] ) && is_array( $_POST['map_field_name'] ) ? wp_unslash( $_POST['map_field_name'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		foreach ( $keys as $index => $meta_key ) {
			$meta_key = sanitize_key( $meta_key );
			$name     = isset( $names[ $index ] ) ? sanitize_key( $names[ $index ] ) : '';

			if ( '' !== $meta_key && '' !== $name ) {
				$map[ $meta_key ] = $name;
			}
		}

		return $map;
	}

	/**
	 * Probe the API with the stored credentials.
	 */
	public function handle_test() {
		$this->guard();
		check_admin_referer( self::TEST );

		$result = $this->client->test_connection();

		if ( is_wp_error( $result ) ) {
			$this->redirect_back( 'test_failed', $result->get_error_message() );
		}

		$this->redirect_back( 'test_ok' );
	}

	/**
	 * Queue every eligible user.
	 */
	public function handle_sync_all() {
		$this->guard();
		check_admin_referer( self::SYNC_ALL );

		$contacts = new AutomateFlow_Contacts( $this->client, $this->settings );
		$queued   = $contacts->enqueue_all_users();

		$this->redirect_back( 'queued', (string) $queued );
	}

	/**
	 * Drop cached form definitions.
	 */
	public function handle_flush() {
		$this->guard();
		check_admin_referer( self::FLUSH );

		AutomateFlow_Forms::flush_cache();

		$this->redirect_back( 'flushed' );
	}

	/**
	 * Empty the activity log.
	 */
	public function handle_clear_log() {
		$this->guard();
		check_admin_referer( self::CLEAR_LOG );

		AutomateFlow_Logger::clear();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-log' ) );
		exit;
	}

	/**
	 * Start a campaign send.
	 */
	public function handle_send_campaign() {
		$this->guard();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0;

		// Nonce is per-campaign, so a token minted for one row cannot be replayed to send a
		// different campaign — the one action here that is both irreversible and expensive.
		check_admin_referer( self::SEND . '_' . $campaign_id );

		if ( $campaign_id <= 0 ) {
			$this->redirect_campaigns( 'send_failed', __( 'No campaign was selected.', 'automateflow' ) );
		}

		$result = $this->client->send_campaign( $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_campaigns( 'send_failed', $result->get_error_message() );
		}

		AutomateFlow_Logger::info(
			__( 'Campaign send started from WordPress.', 'automateflow' ),
			array( 'campaign_id' => $campaign_id )
		);

		$this->redirect_campaigns( 'sending' );
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Capability gate.
	 */
	private function guard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage AutomateFlow.', 'automateflow' ) );
		}
	}

	/**
	 * Lists for the two select boxes, or an empty array when unreachable.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function available_lists() {
		if ( ! $this->settings->is_configured() ) {
			return array();
		}

		$response = $this->client->get_lists();

		if ( is_wp_error( $response ) ) {
			return array();
		}

		return isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
	}

	/**
	 * Back to the settings screen with a status flag.
	 *
	 * @param string $status Status key.
	 * @param string $detail Optional detail.
	 */
	private function redirect_back( $status, $detail = '' ) {
		$args = array(
			'page'                 => self::MENU_SLUG,
			'automateflow_status'  => $status,
		);

		if ( '' !== $detail ) {
			$args['automateflow_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Back to the campaigns screen with a status flag.
	 *
	 * @param string $status Status key.
	 * @param string $detail Optional detail.
	 */
	private function redirect_campaigns( $status, $detail = '' ) {
		$args = array(
			'page'                => self::MENU_SLUG . '-campaigns',
			'automateflow_status' => $status,
		);

		if ( '' !== $detail ) {
			$args['automateflow_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the result of the last action.
	 */
	public function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect flag; no state changes here.
		if ( ! isset( $_GET['automateflow_status'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['automateflow_status'] ) );
		$detail = isset( $_GET['automateflow_detail'] )
			? sanitize_text_field( rawurldecode( wp_unslash( $_GET['automateflow_detail'] ) ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'saved'    => array( 'success', __( 'Settings saved.', 'automateflow' ) ),
			'test_ok'  => array( 'success', __( 'Connected to AutomateFlow successfully.', 'automateflow' ) ),
			'flushed'  => array( 'success', __( 'Cached form definitions cleared.', 'automateflow' ) ),
			'sending'  => array( 'success', __( 'Campaign send started.', 'automateflow' ) ),
		);

		if ( isset( $messages[ $status ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $status ][0] ),
				esc_html( $messages[ $status ][1] )
			);

			return;
		}

		if ( 'queued' === $status ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of users queued. */
						_n(
							'%d user queued for sync. They will be sent in the background.',
							'%d users queued for sync. They will be sent in the background.',
							(int) $detail,
							'automateflow'
						),
						(int) $detail
					)
				)
			);

			return;
		}

		if ( in_array( $status, array( 'test_failed', 'send_failed' ), true ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					'' !== $detail
						? $detail
						: __( 'The request to AutomateFlow failed.', 'automateflow' )
				)
			);
		}
	}
}

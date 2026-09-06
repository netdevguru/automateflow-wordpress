<?php
/**
 * Plugin Name:       netdevguru Bridge for AutomateFlow
 * Plugin URI:        https://github.com/netdevguru/automateflow-wordpress
 * Description:       Connects WordPress and WooCommerce to an AutomateFlow workspace — sync contacts, route site email through the transactional API, embed subscription forms, trigger automations, and review campaign performance without leaving wp-admin. Not affiliated with any other product of a similar name.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            netdevguru
 * Author URI:        https://profiles.wordpress.org/netdevguru/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       netdevguru-bridge-for-automateflow
 * Domain Path:       /languages
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

define( 'NETDEVGURU_BRIDGE_VERSION', '1.0.0' );
define( 'NETDEVGURU_BRIDGE_PLUGIN_FILE', __FILE__ );
define( 'NETDEVGURU_BRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NETDEVGURU_BRIDGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimum PHP guard.
 *
 * The `Requires PHP` header stops WordPress 5.1+ from *activating* the plugin on an older
 * runtime, but it does not stop an already-active install from being carried onto one by a
 * host downgrade. Bailing here keeps that case a notice rather than a parse error on every
 * request, which would take the whole site down including wp-admin.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: current PHP version. */
						__( 'netdevguru Bridge for AutomateFlow requires PHP 7.4 or newer. This site runs PHP %s, so the plugin has not loaded.', 'netdevguru-bridge-for-automateflow' ),
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'includes/class-netdevguru-bridge-logger.php';
require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'includes/class-netdevguru-bridge-settings.php';
require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'includes/class-netdevguru-bridge-client.php';
require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'includes/class-netdevguru-bridge-contacts.php';
require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'includes/class-netdevguru-bridge-mailer.php';
require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'includes/class-netdevguru-bridge-forms.php';
require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'includes/class-netdevguru-bridge-webhooks.php';
require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'includes/class-netdevguru-bridge-woocommerce.php';
require_once NETDEVGURU_BRIDGE_PLUGIN_DIR . 'admin/class-netdevguru-bridge-admin.php';

/**
 * Shared plugin container.
 *
 * Deliberately a lazy singleton rather than instantiating everything at file scope: the
 * client reads options, and options are not reliably available until `plugins_loaded`.
 */
final class Netdevguru_Bridge_Plugin {

	/**
	 * Sole instance.
	 *
	 * @var Netdevguru_Bridge_Plugin|null
	 */
	private static $instance = null;

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
	 * Wire the object graph. Private — use instance().
	 */
	private function __construct() {
		$this->settings = new Netdevguru_Bridge_Settings();
		$this->client   = new Netdevguru_Bridge_Client( $this->settings );
	}

	/**
	 * Accessor.
	 *
	 * @return Netdevguru_Bridge_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * API client.
	 *
	 * @return Netdevguru_Bridge_Client
	 */
	public function client() {
		return $this->client;
	}

	/**
	 * Settings repository.
	 *
	 * @return Netdevguru_Bridge_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Register every feature module's hooks.
	 *
	 * Each module decides for itself whether its feature is switched on, so this stays a
	 * flat list and a disabled feature costs one option read rather than a conditional here.
	 */
	public function boot() {
		( new Netdevguru_Bridge_Admin( $this->client, $this->settings ) )->register();
		( new Netdevguru_Bridge_Contacts( $this->client, $this->settings ) )->register();
		( new Netdevguru_Bridge_Mailer( $this->client, $this->settings ) )->register();
		( new Netdevguru_Bridge_Forms( $this->client, $this->settings ) )->register();
		( new Netdevguru_Bridge_Webhooks( $this->settings ) )->register();

		// Guarded on the class, not on a settings flag: the integration's hooks do not exist
		// to be registered when WooCommerce is absent.
		if ( class_exists( 'WooCommerce' ) ) {
			( new Netdevguru_Bridge_WooCommerce( $this->client, $this->settings ) )->register();
		}
	}
}

/**
 * Convenience accessor for the container.
 *
 * @return Netdevguru_Bridge_Plugin
 */
function netdevguru_bridge() {
	return Netdevguru_Bridge_Plugin::instance();
}

/**
 * Declare High-Performance Order Storage compatibility.
 *
 * Registered at file scope rather than inside boot(): `before_woocommerce_init` fires during
 * WooCommerce's own load, and a plugin that has not declared either way by then is listed as
 * incompatible in WooCommerce's admin screen. The integration only reads orders through the
 * CRUD API (`wc_get_order()`, `$order->get_*()`), which is storage-agnostic.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NETDEVGURU_BRIDGE_PLUGIN_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		netdevguru_bridge()->boot();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		// Backfill and retry queues run on WP-Cron; the schedules are added by the modules
		// themselves, but the first tick has to be planted here or nothing ever starts.
		if ( ! wp_next_scheduled( 'netdevguru_bridge_process_sync_queue' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'netdevguru_bridge_five_minutes', 'netdevguru_bridge_process_sync_queue' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'netdevguru_bridge_process_sync_queue' );
	}
);

/**
 * Custom cron cadence for the sync queue.
 *
 * Five minutes rather than every minute: the API is rate limited per key (60 req/min by
 * default) and the queue drains in bounded batches, so a tighter schedule would spend its
 * budget on empty wake-ups.
 */
add_filter(
	'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Five minutes is well above the 10-minute warning threshold's intent; see docblock.
	static function ( $schedules ) {
		/*
		 * `display` is translated only once `init` has fired.
		 *
		 * `wp_get_schedules()` is routinely called before `init` — WooCommerce's own
		 * WC_Install::cron_schedules does it, and so does any plugin checking its schedules on
		 * `plugins_loaded`. Calling __() there forces WordPress to load this text domain
		 * just-in-time, which since 6.7 emits a "translation loading was triggered too early"
		 * notice naming this plugin.
		 *
		 * The filter runs afresh on every wp_get_schedules() call and nothing caches the
		 * result, so every context that shows this label to a human — Tools screens, cron
		 * inspectors, WP-Cron's own listings — runs long after `init` and gets the translated
		 * string. Only the pre-init machine callers see the English fallback, and they are
		 * reading `interval`, not `display`.
		 */
		$schedules['netdevguru_bridge_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => did_action( 'init' )
				? __( 'Every five minutes (netdevguru Bridge)', 'netdevguru-bridge-for-automateflow' )
				: 'Every five minutes (netdevguru Bridge)',
		);

		return $schedules;
	}
);

<?php
/**
 * Removes every trace of the plugin when it is deleted.
 *
 * Runs on delete, never on deactivate — a site that deactivates to troubleshoot must get its
 * settings back when it reactivates.
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
 */

// Loaded directly by WordPress with this constant defined. Without the guard the file is a
// script anyone can request.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$netdevguru_bridge_options = array(
	'netdevguru_bridge_base_url',
	'netdevguru_bridge_api_key',
	'netdevguru_bridge_features',
	'netdevguru_bridge_default_list_id',
	'netdevguru_bridge_sync_roles',
	'netdevguru_bridge_field_map',
	'netdevguru_bridge_webhook_secret',
	'netdevguru_bridge_mail_from',
	'netdevguru_bridge_mail_from_name',
	'netdevguru_bridge_woo_list_id',
	'netdevguru_bridge_woo_require_consent',
	'netdevguru_bridge_sync_queue',
	'netdevguru_bridge_last_error',
	'netdevguru_bridge_log',
);

foreach ( $netdevguru_bridge_options as $netdevguru_bridge_option ) {
	delete_option( $netdevguru_bridge_option );

	// Multisite stores network-activated settings separately; delete_option() alone would
	// leave those behind on every site in the network.
	delete_site_option( $netdevguru_bridge_option );
}

wp_clear_scheduled_hook( 'netdevguru_bridge_process_sync_queue' );

/*
 * User meta is deliberately left in place.
 *
 * `_netdevguru_bridge_contact_id` and `_netdevguru_bridge_undeliverable` describe the *platform's*
 * state, not this plugin's. Deleting the plugin does not delete the contacts, so discarding
 * the local record of which contact belongs to which user would mean a reinstall re-creating
 * mappings it already had — and, in the undeliverable case, forgetting that someone's address
 * bounced. Neither is the plugin's to throw away.
 */

<?php
/**
 * Removes every trace of the plugin when it is deleted.
 *
 * Runs on delete, never on deactivate — a site that deactivates to troubleshoot must get its
 * settings back when it reactivates.
 *
 * @package AutomateFlow
 */

// Loaded directly by WordPress with this constant defined. Without the guard the file is a
// script anyone can request.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$automateflow_options = array(
	'automateflow_base_url',
	'automateflow_api_key',
	'automateflow_features',
	'automateflow_default_list_id',
	'automateflow_sync_roles',
	'automateflow_field_map',
	'automateflow_webhook_secret',
	'automateflow_mail_from',
	'automateflow_mail_from_name',
	'automateflow_woo_list_id',
	'automateflow_woo_require_consent',
	'automateflow_sync_queue',
	'automateflow_last_error',
	'automateflow_log',
);

foreach ( $automateflow_options as $automateflow_option ) {
	delete_option( $automateflow_option );

	// Multisite stores network-activated settings separately; delete_option() alone would
	// leave those behind on every site in the network.
	delete_site_option( $automateflow_option );
}

wp_clear_scheduled_hook( 'automateflow_process_sync_queue' );

/*
 * User meta is deliberately left in place.
 *
 * `_automateflow_contact_id` and `_automateflow_undeliverable` describe the *platform's*
 * state, not this plugin's. Deleting the plugin does not delete the contacts, so discarding
 * the local record of which contact belongs to which user would mean a reinstall re-creating
 * mappings it already had — and, in the undeliverable case, forgetting that someone's address
 * bounced. Neither is the plugin's to throw away.
 */

<?php
/**
 * Settings screen.
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
 *
 * @var Netdevguru_Bridge_Settings           $settings Settings repository.
 * @var array<int, array<string,mixed>> $lists    Lists from the API, possibly empty.
 * @var array<string, string>           $roles    Role slug => display name.
 */

defined( 'ABSPATH' ) || exit;

$netdevguru_bridge_features   = $settings->features();
$netdevguru_bridge_field_map  = $settings->field_map();
$netdevguru_bridge_sync_roles = $settings->sync_roles();
$netdevguru_bridge_last_error = $settings->last_error();
?>
<div class="wrap netdevguru-bridge-settings">
	<h1><?php esc_html_e( 'netdevguru Bridge for AutomateFlow', 'netdevguru-bridge-for-automateflow' ); ?></h1>

	<?php if ( ! $settings->is_configured() ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'Enter your AutomateFlow site URL and an API key to switch features on. Create a key under API Keys in your AutomateFlow workspace — it needs both read and write scope.', 'netdevguru-bridge-for-automateflow' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $netdevguru_bridge_last_error ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Last API error:', 'netdevguru-bridge-for-automateflow' ); ?></strong>
				<?php echo esc_html( $netdevguru_bridge_last_error['message'] ); ?>
				<em>(<?php echo esc_html( human_time_diff( $netdevguru_bridge_last_error['time'] ) ); ?> <?php esc_html_e( 'ago', 'netdevguru-bridge-for-automateflow' ); ?>)</em>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Netdevguru_Bridge_Admin::SAVE ); ?>" />
		<?php wp_nonce_field( Netdevguru_Bridge_Admin::SAVE ); ?>

		<h2 class="title"><?php esc_html_e( 'Connection', 'netdevguru-bridge-for-automateflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="af-base-url"><?php esc_html_e( 'AutomateFlow URL', 'netdevguru-bridge-for-automateflow' ); ?></label></th>
				<td>
					<input type="url" class="regular-text code" id="af-base-url" name="base_url"
						value="<?php echo esc_attr( $settings->base_url() ); ?>"
						placeholder="https://app.example.com" />
					<p class="description"><?php esc_html_e( 'The root URL of your AutomateFlow install, with no trailing slash. Requests go to /api/v1 beneath it.', 'netdevguru-bridge-for-automateflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="af-api-key"><?php esc_html_e( 'API key', 'netdevguru-bridge-for-automateflow' ); ?></label></th>
				<td>
					<input type="password" class="regular-text code" id="af-api-key" name="api_key"
						autocomplete="off"
						placeholder="<?php echo esc_attr( '' !== $settings->api_key() ? __( 'Saved — leave blank to keep it', 'netdevguru-bridge-for-automateflow' ) : __( 'Paste your key', 'netdevguru-bridge-for-automateflow' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Shown once by AutomateFlow when the key is created. Leave this blank to keep the stored key; the saved value is never sent back to your browser.', 'netdevguru-bridge-for-automateflow' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Features', 'netdevguru-bridge-for-automateflow' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Everything is off until you switch it on. Nothing leaves this site while a feature is disabled.', 'netdevguru-bridge-for-automateflow' ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( Netdevguru_Bridge_Settings::FEATURES as $netdevguru_bridge_key => $netdevguru_bridge_label ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $netdevguru_bridge_label ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="features[<?php echo esc_attr( $netdevguru_bridge_key ); ?>]" value="1"
								<?php checked( ! empty( $netdevguru_bridge_features[ $netdevguru_bridge_key ] ) ); ?> />
							<?php esc_html_e( 'Enabled', 'netdevguru-bridge-for-automateflow' ); ?>
						</label>
						<?php if ( 'woocommerce' === $netdevguru_bridge_key && ! class_exists( 'WooCommerce' ) ) : ?>
							<p class="description"><?php esc_html_e( 'WooCommerce is not active on this site, so this has no effect.', 'netdevguru-bridge-for-automateflow' ); ?></p>
						<?php elseif ( 'mailer' === $netdevguru_bridge_key ) : ?>
							<p class="description"><?php esc_html_e( 'Replaces the site\'s outgoing mail transport. If a send fails, the message falls back to the WordPress default rather than being dropped.', 'netdevguru-bridge-for-automateflow' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2 class="title"><?php esc_html_e( 'Contact sync', 'netdevguru-bridge-for-automateflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="af-default-list"><?php esc_html_e( 'Default list', 'netdevguru-bridge-for-automateflow' ); ?></label></th>
				<td>
					<?php if ( empty( $lists ) ) : ?>
						<p class="description"><?php esc_html_e( 'Connect to AutomateFlow to choose a list.', 'netdevguru-bridge-for-automateflow' ); ?></p>
						<input type="hidden" name="default_list_id" value="<?php echo esc_attr( (string) $settings->default_list_id() ); ?>" />
					<?php else : ?>
						<select id="af-default-list" name="default_list_id">
							<option value="0"><?php esc_html_e( '— None —', 'netdevguru-bridge-for-automateflow' ); ?></option>
							<?php foreach ( $lists as $netdevguru_bridge_list ) : ?>
								<option value="<?php echo esc_attr( (string) $netdevguru_bridge_list['id'] ); ?>" <?php selected( $settings->default_list_id(), (int) $netdevguru_bridge_list['id'] ); ?>>
									<?php echo esc_html( (string) $netdevguru_bridge_list['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Roles to sync', 'netdevguru-bridge-for-automateflow' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Roles to sync', 'netdevguru-bridge-for-automateflow' ); ?></legend>
						<?php foreach ( $roles as $netdevguru_bridge_role_slug => $netdevguru_bridge_role_name ) : ?>
							<label style="display:inline-block;min-width:12em;">
								<input type="checkbox" name="sync_roles[]" value="<?php echo esc_attr( $netdevguru_bridge_role_slug ); ?>"
									<?php checked( in_array( $netdevguru_bridge_role_slug, $netdevguru_bridge_sync_roles, true ) ); ?> />
								<?php echo esc_html( $netdevguru_bridge_role_name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Leave every box unticked to sync all roles.', 'netdevguru-bridge-for-automateflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Custom field mapping', 'netdevguru-bridge-for-automateflow' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Copy WordPress user meta into AutomateFlow custom fields. Three blank rows are always available.', 'netdevguru-bridge-for-automateflow' ); ?></p>
					<table class="widefat striped netdevguru-bridge-map">
						<thead>
							<tr>
								<th><?php esc_html_e( 'WordPress user meta key', 'netdevguru-bridge-for-automateflow' ); ?></th>
								<th><?php esc_html_e( 'AutomateFlow custom field', 'netdevguru-bridge-for-automateflow' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $netdevguru_bridge_field_map as $netdevguru_bridge_meta => $netdevguru_bridge_field ) : ?>
								<tr>
									<td><input type="text" class="code" name="map_meta_key[]" value="<?php echo esc_attr( $netdevguru_bridge_meta ); ?>" /></td>
									<td><input type="text" class="code" name="map_field_name[]" value="<?php echo esc_attr( $netdevguru_bridge_field ); ?>" /></td>
								</tr>
							<?php endforeach; ?>
							<?php for ( $netdevguru_bridge_i = 0; $netdevguru_bridge_i < 3; $netdevguru_bridge_i++ ) : ?>
								<tr>
									<td><input type="text" class="code" name="map_meta_key[]" value="" placeholder="billing_phone" /></td>
									<td><input type="text" class="code" name="map_field_name[]" value="" placeholder="phone" /></td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Outgoing mail', 'netdevguru-bridge-for-automateflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="af-mail-from"><?php esc_html_e( 'From address', 'netdevguru-bridge-for-automateflow' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="af-mail-from" name="mail_from" value="<?php echo esc_attr( (string) get_option( Netdevguru_Bridge_Settings::OPT_MAIL_FROM, '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->mail_from() ); ?>" />
					<p class="description"><?php esc_html_e( 'Must be a verified sender in AutomateFlow, or sends will be rejected. Defaults to the site admin address.', 'netdevguru-bridge-for-automateflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="af-mail-from-name"><?php esc_html_e( 'From name', 'netdevguru-bridge-for-automateflow' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="af-mail-from-name" name="mail_from_name" value="<?php echo esc_attr( (string) get_option( Netdevguru_Bridge_Settings::OPT_MAIL_FROM_NAME, '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->mail_from_name() ); ?>" />
				</td>
			</tr>
		</table>

		<?php if ( class_exists( 'WooCommerce' ) ) : ?>
			<?php
			/*
			 * Marks this section as present in the submission. The whole WooCommerce block is
			 * conditional on WooCommerce being active, so without this flag the handler cannot
			 * tell "the admin unticked Require opt-in" from "the controls were never on the
			 * page" — and an unticked checkbox and an absent one look identical in $_POST.
			 * Saving from a site with no WooCommerce would then silently clear the opt-in
			 * requirement.
			 */
			?>
			<input type="hidden" name="woo_settings_rendered" value="1" />
			<h2 class="title"><?php esc_html_e( 'WooCommerce', 'netdevguru-bridge-for-automateflow' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af-woo-list"><?php esc_html_e( 'Customer list', 'netdevguru-bridge-for-automateflow' ); ?></label></th>
					<td>
						<?php if ( empty( $lists ) ) : ?>
							<p class="description"><?php esc_html_e( 'Connect to AutomateFlow to choose a list.', 'netdevguru-bridge-for-automateflow' ); ?></p>
							<input type="hidden" name="woo_list_id" value="<?php echo esc_attr( (string) get_option( Netdevguru_Bridge_Settings::OPT_WOO_LIST, 0 ) ); ?>" />
						<?php else : ?>
							<select id="af-woo-list" name="woo_list_id">
								<option value="0"><?php esc_html_e( '— Use the default list —', 'netdevguru-bridge-for-automateflow' ); ?></option>
								<?php foreach ( $lists as $netdevguru_bridge_list ) : ?>
									<option value="<?php echo esc_attr( (string) $netdevguru_bridge_list['id'] ); ?>" <?php selected( (int) get_option( Netdevguru_Bridge_Settings::OPT_WOO_LIST, 0 ), (int) $netdevguru_bridge_list['id'] ); ?>>
										<?php echo esc_html( (string) $netdevguru_bridge_list['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Require opt-in', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="woo_require_consent" value="1" <?php checked( $settings->woo_requires_consent() ); ?> />
							<?php esc_html_e( 'Only send customer data to AutomateFlow when the customer ticks the marketing box at checkout', 'netdevguru-bridge-for-automateflow' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Turning this off syncs every customer and fires order automations for all of them. List membership still requires the tick either way. Check your obligations before changing it.', 'netdevguru-bridge-for-automateflow' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Trigger keys', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<td>
						<p class="description"><?php esc_html_e( 'Create automations in AutomateFlow with an API-event trigger using these keys:', 'netdevguru-bridge-for-automateflow' ); ?></p>
						<ul class="netdevguru-bridge-keys">
							<li><code><?php echo esc_html( Netdevguru_Bridge_WooCommerce::TRIGGER_PLACED ); ?></code></li>
							<li><code><?php echo esc_html( Netdevguru_Bridge_WooCommerce::TRIGGER_COMPLETED ); ?></code></li>
							<li><code><?php echo esc_html( Netdevguru_Bridge_WooCommerce::TRIGGER_REFUNDED ); ?></code></li>
							<li><code><?php echo esc_html( Netdevguru_Bridge_WooCommerce::TRIGGER_CANCELLED ); ?></code></li>
						</ul>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<h2 class="title"><?php esc_html_e( 'Incoming webhooks', 'netdevguru-bridge-for-automateflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoint URL', 'netdevguru-bridge-for-automateflow' ); ?></th>
				<td>
					<code class="netdevguru-bridge-endpoint"><?php echo esc_html( Netdevguru_Bridge_Webhooks::endpoint_url() ); ?></code>
					<p class="description"><?php esc_html_e( 'Add this as a webhook endpoint in AutomateFlow, then paste the secret it generates below.', 'netdevguru-bridge-for-automateflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="af-webhook-secret"><?php esc_html_e( 'Shared secret', 'netdevguru-bridge-for-automateflow' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="af-webhook-secret" name="webhook_secret"
						value="<?php echo esc_attr( $settings->webhook_secret() ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Until this is set, incoming webhooks are refused rather than trusted.', 'netdevguru-bridge-for-automateflow' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<h2 class="title"><?php esc_html_e( 'Maintenance', 'netdevguru-bridge-for-automateflow' ); ?></h2>
	<div class="netdevguru-bridge-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Netdevguru_Bridge_Admin::TEST ); ?>" />
			<?php wp_nonce_field( Netdevguru_Bridge_Admin::TEST ); ?>
			<?php submit_button( __( 'Test connection', 'netdevguru-bridge-for-automateflow' ), 'secondary', 'submit', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Netdevguru_Bridge_Admin::SYNC_ALL ); ?>" />
			<?php wp_nonce_field( Netdevguru_Bridge_Admin::SYNC_ALL ); ?>
			<?php submit_button( __( 'Queue all users for sync', 'netdevguru-bridge-for-automateflow' ), 'secondary', 'submit', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Netdevguru_Bridge_Admin::FLUSH ); ?>" />
			<?php wp_nonce_field( Netdevguru_Bridge_Admin::FLUSH ); ?>
			<?php submit_button( __( 'Clear cached forms', 'netdevguru-bridge-for-automateflow' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
</div>

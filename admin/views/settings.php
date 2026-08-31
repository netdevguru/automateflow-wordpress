<?php
/**
 * Settings screen.
 *
 * @package AutomateFlow
 *
 * @var AutomateFlow_Settings           $settings Settings repository.
 * @var array<int, array<string,mixed>> $lists    Lists from the API, possibly empty.
 * @var array<string, string>           $roles    Role slug => display name.
 */

defined( 'ABSPATH' ) || exit;

$automateflow_features   = $settings->features();
$automateflow_field_map  = $settings->field_map();
$automateflow_sync_roles = $settings->sync_roles();
$automateflow_last_error = $settings->last_error();
?>
<div class="wrap automateflow-settings">
	<h1><?php esc_html_e( 'AutomateFlow', 'automateflow' ); ?></h1>

	<?php if ( ! $settings->is_configured() ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'Enter your AutomateFlow site URL and an API key to switch features on. Create a key under API Keys in your AutomateFlow workspace — it needs both read and write scope.', 'automateflow' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $automateflow_last_error ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Last API error:', 'automateflow' ); ?></strong>
				<?php echo esc_html( $automateflow_last_error['message'] ); ?>
				<em>(<?php echo esc_html( human_time_diff( $automateflow_last_error['time'] ) ); ?> <?php esc_html_e( 'ago', 'automateflow' ); ?>)</em>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( AutomateFlow_Admin::SAVE ); ?>" />
		<?php wp_nonce_field( AutomateFlow_Admin::SAVE ); ?>

		<h2 class="title"><?php esc_html_e( 'Connection', 'automateflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="af-base-url"><?php esc_html_e( 'AutomateFlow URL', 'automateflow' ); ?></label></th>
				<td>
					<input type="url" class="regular-text code" id="af-base-url" name="base_url"
						value="<?php echo esc_attr( $settings->base_url() ); ?>"
						placeholder="https://app.example.com" />
					<p class="description"><?php esc_html_e( 'The root URL of your AutomateFlow install, with no trailing slash. Requests go to /api/v1 beneath it.', 'automateflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="af-api-key"><?php esc_html_e( 'API key', 'automateflow' ); ?></label></th>
				<td>
					<input type="password" class="regular-text code" id="af-api-key" name="api_key"
						autocomplete="off"
						placeholder="<?php echo esc_attr( '' !== $settings->api_key() ? __( 'Saved — leave blank to keep it', 'automateflow' ) : __( 'Paste your key', 'automateflow' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Shown once by AutomateFlow when the key is created. Leave this blank to keep the stored key; the saved value is never sent back to your browser.', 'automateflow' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Features', 'automateflow' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Everything is off until you switch it on. Nothing leaves this site while a feature is disabled.', 'automateflow' ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( AutomateFlow_Settings::FEATURES as $automateflow_key => $automateflow_label ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $automateflow_label ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="features[<?php echo esc_attr( $automateflow_key ); ?>]" value="1"
								<?php checked( ! empty( $automateflow_features[ $automateflow_key ] ) ); ?> />
							<?php esc_html_e( 'Enabled', 'automateflow' ); ?>
						</label>
						<?php if ( 'woocommerce' === $automateflow_key && ! class_exists( 'WooCommerce' ) ) : ?>
							<p class="description"><?php esc_html_e( 'WooCommerce is not active on this site, so this has no effect.', 'automateflow' ); ?></p>
						<?php elseif ( 'mailer' === $automateflow_key ) : ?>
							<p class="description"><?php esc_html_e( 'Replaces the site\'s outgoing mail transport. If a send fails, the message falls back to the WordPress default rather than being dropped.', 'automateflow' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2 class="title"><?php esc_html_e( 'Contact sync', 'automateflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="af-default-list"><?php esc_html_e( 'Default list', 'automateflow' ); ?></label></th>
				<td>
					<?php if ( empty( $lists ) ) : ?>
						<p class="description"><?php esc_html_e( 'Connect to AutomateFlow to choose a list.', 'automateflow' ); ?></p>
						<input type="hidden" name="default_list_id" value="<?php echo esc_attr( (string) $settings->default_list_id() ); ?>" />
					<?php else : ?>
						<select id="af-default-list" name="default_list_id">
							<option value="0"><?php esc_html_e( '— None —', 'automateflow' ); ?></option>
							<?php foreach ( $lists as $automateflow_list ) : ?>
								<option value="<?php echo esc_attr( (string) $automateflow_list['id'] ); ?>" <?php selected( $settings->default_list_id(), (int) $automateflow_list['id'] ); ?>>
									<?php echo esc_html( (string) $automateflow_list['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Roles to sync', 'automateflow' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Roles to sync', 'automateflow' ); ?></legend>
						<?php foreach ( $roles as $automateflow_role_slug => $automateflow_role_name ) : ?>
							<label style="display:inline-block;min-width:12em;">
								<input type="checkbox" name="sync_roles[]" value="<?php echo esc_attr( $automateflow_role_slug ); ?>"
									<?php checked( in_array( $automateflow_role_slug, $automateflow_sync_roles, true ) ); ?> />
								<?php echo esc_html( $automateflow_role_name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Leave every box unticked to sync all roles.', 'automateflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Custom field mapping', 'automateflow' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Copy WordPress user meta into AutomateFlow custom fields. Three blank rows are always available.', 'automateflow' ); ?></p>
					<table class="widefat striped automateflow-map">
						<thead>
							<tr>
								<th><?php esc_html_e( 'WordPress user meta key', 'automateflow' ); ?></th>
								<th><?php esc_html_e( 'AutomateFlow custom field', 'automateflow' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $automateflow_field_map as $automateflow_meta => $automateflow_field ) : ?>
								<tr>
									<td><input type="text" class="code" name="map_meta_key[]" value="<?php echo esc_attr( $automateflow_meta ); ?>" /></td>
									<td><input type="text" class="code" name="map_field_name[]" value="<?php echo esc_attr( $automateflow_field ); ?>" /></td>
								</tr>
							<?php endforeach; ?>
							<?php for ( $automateflow_i = 0; $automateflow_i < 3; $automateflow_i++ ) : ?>
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

		<h2 class="title"><?php esc_html_e( 'Outgoing mail', 'automateflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="af-mail-from"><?php esc_html_e( 'From address', 'automateflow' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="af-mail-from" name="mail_from" value="<?php echo esc_attr( (string) get_option( AutomateFlow_Settings::OPT_MAIL_FROM, '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->mail_from() ); ?>" />
					<p class="description"><?php esc_html_e( 'Must be a verified sender in AutomateFlow, or sends will be rejected. Defaults to the site admin address.', 'automateflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="af-mail-from-name"><?php esc_html_e( 'From name', 'automateflow' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="af-mail-from-name" name="mail_from_name" value="<?php echo esc_attr( (string) get_option( AutomateFlow_Settings::OPT_MAIL_FROM_NAME, '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->mail_from_name() ); ?>" />
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
			<h2 class="title"><?php esc_html_e( 'WooCommerce', 'automateflow' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af-woo-list"><?php esc_html_e( 'Customer list', 'automateflow' ); ?></label></th>
					<td>
						<?php if ( empty( $lists ) ) : ?>
							<p class="description"><?php esc_html_e( 'Connect to AutomateFlow to choose a list.', 'automateflow' ); ?></p>
							<input type="hidden" name="woo_list_id" value="<?php echo esc_attr( (string) get_option( AutomateFlow_Settings::OPT_WOO_LIST, 0 ) ); ?>" />
						<?php else : ?>
							<select id="af-woo-list" name="woo_list_id">
								<option value="0"><?php esc_html_e( '— Use the default list —', 'automateflow' ); ?></option>
								<?php foreach ( $lists as $automateflow_list ) : ?>
									<option value="<?php echo esc_attr( (string) $automateflow_list['id'] ); ?>" <?php selected( (int) get_option( AutomateFlow_Settings::OPT_WOO_LIST, 0 ), (int) $automateflow_list['id'] ); ?>>
										<?php echo esc_html( (string) $automateflow_list['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Require opt-in', 'automateflow' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="woo_require_consent" value="1" <?php checked( $settings->woo_requires_consent() ); ?> />
							<?php esc_html_e( 'Only send customer data to AutomateFlow when the customer ticks the marketing box at checkout', 'automateflow' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Turning this off syncs every customer and fires order automations for all of them. List membership still requires the tick either way. Check your obligations before changing it.', 'automateflow' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Trigger keys', 'automateflow' ); ?></th>
					<td>
						<p class="description"><?php esc_html_e( 'Create automations in AutomateFlow with an API-event trigger using these keys:', 'automateflow' ); ?></p>
						<ul class="automateflow-keys">
							<li><code><?php echo esc_html( AutomateFlow_WooCommerce::TRIGGER_PLACED ); ?></code></li>
							<li><code><?php echo esc_html( AutomateFlow_WooCommerce::TRIGGER_COMPLETED ); ?></code></li>
							<li><code><?php echo esc_html( AutomateFlow_WooCommerce::TRIGGER_REFUNDED ); ?></code></li>
							<li><code><?php echo esc_html( AutomateFlow_WooCommerce::TRIGGER_CANCELLED ); ?></code></li>
						</ul>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<h2 class="title"><?php esc_html_e( 'Incoming webhooks', 'automateflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoint URL', 'automateflow' ); ?></th>
				<td>
					<code class="automateflow-endpoint"><?php echo esc_html( AutomateFlow_Webhooks::endpoint_url() ); ?></code>
					<p class="description"><?php esc_html_e( 'Add this as a webhook endpoint in AutomateFlow, then paste the secret it generates below.', 'automateflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="af-webhook-secret"><?php esc_html_e( 'Shared secret', 'automateflow' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="af-webhook-secret" name="webhook_secret"
						value="<?php echo esc_attr( $settings->webhook_secret() ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Until this is set, incoming webhooks are refused rather than trusted.', 'automateflow' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<h2 class="title"><?php esc_html_e( 'Maintenance', 'automateflow' ); ?></h2>
	<div class="automateflow-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( AutomateFlow_Admin::TEST ); ?>" />
			<?php wp_nonce_field( AutomateFlow_Admin::TEST ); ?>
			<?php submit_button( __( 'Test connection', 'automateflow' ), 'secondary', 'submit', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( AutomateFlow_Admin::SYNC_ALL ); ?>" />
			<?php wp_nonce_field( AutomateFlow_Admin::SYNC_ALL ); ?>
			<?php submit_button( __( 'Queue all users for sync', 'automateflow' ), 'secondary', 'submit', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( AutomateFlow_Admin::FLUSH ); ?>" />
			<?php wp_nonce_field( AutomateFlow_Admin::FLUSH ); ?>
			<?php submit_button( __( 'Clear cached forms', 'automateflow' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
</div>

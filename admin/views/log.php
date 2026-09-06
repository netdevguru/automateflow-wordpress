<?php
/**
 * Activity log.
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
 *
 * @var array<int, array<string, mixed>> $entries Newest first.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap netdevguru-bridge-log">
	<h1><?php esc_html_e( 'Activity log', 'netdevguru-bridge-for-automateflow' ); ?></h1>

	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: maximum number of retained entries. */
				__( 'The most recent %d events. Payloads are never recorded here — only outcomes.', 'netdevguru-bridge-for-automateflow' ),
				Netdevguru_Bridge_Logger::MAX_ROWS
			)
		);
		?>
	</p>

	<?php if ( empty( $entries ) ) : ?>
		<p><?php esc_html_e( 'Nothing logged yet.', 'netdevguru-bridge-for-automateflow' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="netdevguru-bridge-clear-log">
			<input type="hidden" name="action" value="<?php echo esc_attr( Netdevguru_Bridge_Admin::CLEAR_LOG ); ?>" />
			<?php wp_nonce_field( Netdevguru_Bridge_Admin::CLEAR_LOG ); ?>
			<?php submit_button( __( 'Clear log', 'netdevguru-bridge-for-automateflow' ), 'secondary', 'submit', false ); ?>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:8em;"><?php esc_html_e( 'When', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<th scope="col" style="width:6em;"><?php esc_html_e( 'Level', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<th scope="col" style="width:20em;"><?php esc_html_e( 'Detail', 'netdevguru-bridge-for-automateflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $netdevguru_bridge_entry ) : ?>
					<tr>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human-readable interval, e.g. "5 mins". */
									__( '%s ago', 'netdevguru-bridge-for-automateflow' ),
									human_time_diff( (int) $netdevguru_bridge_entry['time'] )
								)
							);
							?>
						</td>
						<td>
							<span class="netdevguru-bridge-level netdevguru-bridge-level--<?php echo esc_attr( sanitize_html_class( (string) $netdevguru_bridge_entry['level'] ) ); ?>">
								<?php echo esc_html( (string) $netdevguru_bridge_entry['level'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (string) $netdevguru_bridge_entry['message'] ); ?></td>
						<td>
							<?php if ( ! empty( $netdevguru_bridge_entry['context'] ) && is_array( $netdevguru_bridge_entry['context'] ) ) : ?>
								<?php
								$netdevguru_bridge_pairs = array();

								foreach ( $netdevguru_bridge_entry['context'] as $netdevguru_bridge_key => $netdevguru_bridge_value ) {
									$netdevguru_bridge_pairs[] = $netdevguru_bridge_key . '=' . $netdevguru_bridge_value;
								}

								?>
								<code><?php echo esc_html( implode( ' ', $netdevguru_bridge_pairs ) ); ?></code>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

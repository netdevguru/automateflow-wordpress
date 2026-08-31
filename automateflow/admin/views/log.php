<?php
/**
 * Activity log.
 *
 * @package AutomateFlow
 *
 * @var array<int, array<string, mixed>> $entries Newest first.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap automateflow-log">
	<h1><?php esc_html_e( 'AutomateFlow Activity', 'automateflow' ); ?></h1>

	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: maximum number of retained entries. */
				__( 'The most recent %d events. Payloads are never recorded here — only outcomes.', 'automateflow' ),
				AutomateFlow_Logger::MAX_ROWS
			)
		);
		?>
	</p>

	<?php if ( empty( $entries ) ) : ?>
		<p><?php esc_html_e( 'Nothing logged yet.', 'automateflow' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="automateflow-clear-log">
			<input type="hidden" name="action" value="<?php echo esc_attr( AutomateFlow_Admin::CLEAR_LOG ); ?>" />
			<?php wp_nonce_field( AutomateFlow_Admin::CLEAR_LOG ); ?>
			<?php submit_button( __( 'Clear log', 'automateflow' ), 'secondary', 'submit', false ); ?>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:8em;"><?php esc_html_e( 'When', 'automateflow' ); ?></th>
					<th scope="col" style="width:6em;"><?php esc_html_e( 'Level', 'automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'automateflow' ); ?></th>
					<th scope="col" style="width:20em;"><?php esc_html_e( 'Detail', 'automateflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $af_entry ) : ?>
					<tr>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human-readable interval, e.g. "5 mins". */
									__( '%s ago', 'automateflow' ),
									human_time_diff( (int) $af_entry['time'] )
								)
							);
							?>
						</td>
						<td>
							<span class="automateflow-level automateflow-level--<?php echo esc_attr( sanitize_html_class( (string) $af_entry['level'] ) ); ?>">
								<?php echo esc_html( (string) $af_entry['level'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (string) $af_entry['message'] ); ?></td>
						<td>
							<?php if ( ! empty( $af_entry['context'] ) && is_array( $af_entry['context'] ) ) : ?>
								<code><?php
								$af_pairs = array();

								foreach ( $af_entry['context'] as $af_key => $af_value ) {
									$af_pairs[] = $af_key . '=' . $af_value;
								}

								echo esc_html( implode( ' ', $af_pairs ) );
								?></code>
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

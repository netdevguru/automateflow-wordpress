<?php
/**
 * Campaign browser.
 *
 * Stats are fetched for one campaign at a time, on request, rather than for every row.
 * The list endpoint does not carry them, so a stats column would mean one extra API call
 * per row against a key limited to roughly a request a second — a 25-row page would spend
 * half a minute's budget rendering a table.
 *
 * @package AutomateFlow
 *
 * @var array<int, array<string,mixed>> $campaigns Current page of campaigns.
 * @var WP_Error|null                   $error     Failure from the list call.
 * @var int                             $page      Current page.
 * @var int                             $last_page Total pages.
 * @var array<string, mixed>|null       $stats     Stats for the expanded campaign, if any.
 * @var int                             $stats_for Campaign id the stats belong to.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Only these two states can be sent — the API rejects anything else with a 422.
 * Rendering the button for a campaign that cannot accept it would be an error the
 * admin only discovers by clicking.
 */
$automateflow_sendable = array( 'draft', 'scheduled' );
?>
<div class="wrap automateflow-campaigns">
	<h1><?php esc_html_e( 'AutomateFlow Campaigns', 'automateflow' ); ?></h1>

	<?php if ( $error ) : ?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $error->get_error_message() ); ?></p>
		</div>
	<?php elseif ( empty( $campaigns ) ) : ?>
		<p><?php esc_html_e( 'No campaigns found in this workspace.', 'automateflow' ); ?></p>
	<?php else : ?>

		<?php if ( null !== $stats ) : ?>
			<div class="automateflow-stats-card">
				<h2><?php esc_html_e( 'Campaign statistics', 'automateflow' ); ?></h2>
				<ul>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['sent'] ) ); ?></strong> <?php esc_html_e( 'sent', 'automateflow' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['delivered'] ) ); ?></strong> <?php esc_html_e( 'delivered', 'automateflow' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['opened'] ) ); ?></strong> <?php esc_html_e( 'opened', 'automateflow' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['clicked'] ) ); ?></strong> <?php esc_html_e( 'clicked', 'automateflow' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['bounced'] ) ); ?></strong> <?php esc_html_e( 'bounced', 'automateflow' ); ?></li>
				</ul>
				<p class="description">
					<?php esc_html_e( 'Open rates count loaded tracking pixels, which include some machine fetches. Treat them as directional.', 'automateflow' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recipients', 'automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'automateflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $campaigns as $automateflow_campaign ) : ?>
					<?php
					$automateflow_id        = isset( $automateflow_campaign['id'] ) ? absint( $automateflow_campaign['id'] ) : 0;
					$automateflow_status    = isset( $automateflow_campaign['status'] ) ? (string) $automateflow_campaign['status'] : '';
					$automateflow_stats_url = add_query_arg(
						array(
							'page'     => 'automateflow-campaigns',
							'campaign' => $automateflow_id,
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr class="<?php echo esc_attr( $automateflow_id === $stats_for ? 'automateflow-row--active' : '' ); ?>">
						<td><strong><?php echo esc_html( isset( $automateflow_campaign['name'] ) ? (string) $automateflow_campaign['name'] : '—' ); ?></strong></td>
						<td><?php echo esc_html( isset( $automateflow_campaign['subject'] ) && '' !== $automateflow_campaign['subject'] ? (string) $automateflow_campaign['subject'] : '—' ); ?></td>
						<td>
							<span class="automateflow-status automateflow-status--<?php echo esc_attr( sanitize_html_class( $automateflow_status ) ); ?>">
								<?php echo esc_html( $automateflow_status ); ?>
							</span>
						</td>
						<td><?php echo esc_html( number_format_i18n( isset( $automateflow_campaign['sends_count'] ) ? (int) $automateflow_campaign['sends_count'] : 0 ) ); ?></td>
						<td class="automateflow-row-actions">
							<a href="<?php echo esc_url( $automateflow_stats_url ); ?>">
								<?php esc_html_e( 'Stats', 'automateflow' ); ?>
							</a>

							<?php if ( in_array( $automateflow_status, $automateflow_sendable, true ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="automateflow-inline-form">
									<input type="hidden" name="action" value="<?php echo esc_attr( AutomateFlow_Admin::SEND ); ?>" />
									<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $automateflow_id ); ?>" />
									<?php wp_nonce_field( AutomateFlow_Admin::SEND . '_' . $automateflow_id ); ?>
									<button type="submit" class="button button-small"
										onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Start sending this campaign now? This cannot be undone.', 'automateflow' ) ) ); ?>);">
										<?php esc_html_e( 'Send now', 'automateflow' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $last_page > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $page,
								'total'     => $last_page,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

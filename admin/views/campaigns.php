<?php
/**
 * Campaign browser.
 *
 * Stats are fetched for one campaign at a time, on request, rather than for every row.
 * The list endpoint does not carry them, so a stats column would mean one extra API call
 * per row against a key limited to roughly a request a second — a 25-row page would spend
 * half a minute's budget rendering a table.
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
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
$netdevguru_bridge_sendable = array( 'draft', 'scheduled' );
?>
<div class="wrap netdevguru-bridge-campaigns">
	<h1><?php esc_html_e( 'AutomateFlow Campaigns', 'netdevguru-bridge-for-automateflow' ); ?></h1>

	<?php if ( $error ) : ?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $error->get_error_message() ); ?></p>
		</div>
	<?php elseif ( empty( $campaigns ) ) : ?>
		<p><?php esc_html_e( 'No campaigns found in this workspace.', 'netdevguru-bridge-for-automateflow' ); ?></p>
	<?php else : ?>

		<?php if ( null !== $stats ) : ?>
			<div class="netdevguru-bridge-stats-card">
				<h2><?php esc_html_e( 'Campaign statistics', 'netdevguru-bridge-for-automateflow' ); ?></h2>
				<ul>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['sent'] ) ); ?></strong> <?php esc_html_e( 'sent', 'netdevguru-bridge-for-automateflow' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['delivered'] ) ); ?></strong> <?php esc_html_e( 'delivered', 'netdevguru-bridge-for-automateflow' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['opened'] ) ); ?></strong> <?php esc_html_e( 'opened', 'netdevguru-bridge-for-automateflow' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['clicked'] ) ); ?></strong> <?php esc_html_e( 'clicked', 'netdevguru-bridge-for-automateflow' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $stats['bounced'] ) ); ?></strong> <?php esc_html_e( 'bounced', 'netdevguru-bridge-for-automateflow' ); ?></li>
				</ul>
				<p class="description">
					<?php esc_html_e( 'Open rates count loaded tracking pixels, which include some machine fetches. Treat them as directional.', 'netdevguru-bridge-for-automateflow' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recipients', 'netdevguru-bridge-for-automateflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'netdevguru-bridge-for-automateflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $campaigns as $netdevguru_bridge_campaign ) : ?>
					<?php
					$netdevguru_bridge_id        = isset( $netdevguru_bridge_campaign['id'] ) ? absint( $netdevguru_bridge_campaign['id'] ) : 0;
					$netdevguru_bridge_status    = isset( $netdevguru_bridge_campaign['status'] ) ? (string) $netdevguru_bridge_campaign['status'] : '';
					$netdevguru_bridge_stats_url = add_query_arg(
						array(
							// Derived from the constant rather than repeated as a literal: the
							// admin class builds this same page slug from MENU_SLUG, and a
							// hardcoded copy silently breaks this link whenever the slug changes.
							'page'     => Netdevguru_Bridge_Admin::MENU_SLUG . '-campaigns',
							'campaign' => $netdevguru_bridge_id,
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr class="<?php echo esc_attr( $netdevguru_bridge_id === $stats_for ? 'netdevguru-bridge-row--active' : '' ); ?>">
						<td><strong><?php echo esc_html( isset( $netdevguru_bridge_campaign['name'] ) ? (string) $netdevguru_bridge_campaign['name'] : '—' ); ?></strong></td>
						<td><?php echo esc_html( isset( $netdevguru_bridge_campaign['subject'] ) && '' !== $netdevguru_bridge_campaign['subject'] ? (string) $netdevguru_bridge_campaign['subject'] : '—' ); ?></td>
						<td>
							<span class="netdevguru-bridge-status netdevguru-bridge-status--<?php echo esc_attr( sanitize_html_class( $netdevguru_bridge_status ) ); ?>">
								<?php echo esc_html( $netdevguru_bridge_status ); ?>
							</span>
						</td>
						<td><?php echo esc_html( number_format_i18n( isset( $netdevguru_bridge_campaign['sends_count'] ) ? (int) $netdevguru_bridge_campaign['sends_count'] : 0 ) ); ?></td>
						<td class="netdevguru-bridge-row-actions">
							<a href="<?php echo esc_url( $netdevguru_bridge_stats_url ); ?>">
								<?php esc_html_e( 'Stats', 'netdevguru-bridge-for-automateflow' ); ?>
							</a>

							<?php if ( in_array( $netdevguru_bridge_status, $netdevguru_bridge_sendable, true ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="netdevguru-bridge-inline-form">
									<input type="hidden" name="action" value="<?php echo esc_attr( Netdevguru_Bridge_Admin::SEND ); ?>" />
									<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $netdevguru_bridge_id ); ?>" />
									<?php wp_nonce_field( Netdevguru_Bridge_Admin::SEND . '_' . $netdevguru_bridge_id ); ?>
									<button type="submit" class="button button-small"
										onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Start sending this campaign now? This cannot be undone.', 'netdevguru-bridge-for-automateflow' ) ) ); ?>);">
										<?php esc_html_e( 'Send now', 'netdevguru-bridge-for-automateflow' ); ?>
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

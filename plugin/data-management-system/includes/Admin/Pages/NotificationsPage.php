<?php
/**
 * Notifications: delivery monitoring, manual retry and email templates
 * (ARCH §76.4, MP §28, §37). Monitoring needs notifications.view, retry needs
 * notifications.retry, and editing templates needs settings.edit. Message
 * bodies are never listed (they can contain personal data).
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Admin\AdminMenu;
use DMS\Notifications\EmailTemplates;
use DMS\Notifications\NotificationService;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class NotificationsPage {

	public const SLUG = 'dms-notifications';

	public const DRAFT_TRANSIENT = 'dms_template_draft_';

	public function __construct( private Plugin $plugin ) {
	}

	public static function visible(): bool {
		return current_user_can( 'notifications.view' ) || current_user_can( 'settings.view' );
	}

	public function render(): void {
		if ( ! self::visible() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = sanitize_key( (string) wp_unslash( $_GET['tab'] ?? '' ) );
		if ( '' === $tab ) {
			$tab = current_user_can( 'notifications.view' ) ? 'log' : 'templates';
		}
		?>
		<div class="wrap dms-wrap">
			<div class="dms-page-head">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'System', 'dms' ); ?></p>
					<h1><?php esc_html_e( 'Notifications', 'dms' ); ?></h1>
					<p><?php esc_html_e( 'Email delivery log, retries and email templates.', 'dms' ); ?></p>
				</div>
			</div>
			<hr class="wp-header-end">
			<nav class="nav-tab-wrapper">
				<?php if ( current_user_can( 'notifications.view' ) ) : ?>
					<a class="nav-tab<?php echo 'log' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=log' ) ); ?>"><?php esc_html_e( 'Delivery log', 'dms' ); ?></a>
				<?php endif; ?>
				<?php if ( current_user_can( 'settings.view' ) ) : ?>
					<a class="nav-tab<?php echo 'templates' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=templates' ) ); ?>"><?php esc_html_e( 'Email templates', 'dms' ); ?></a>
				<?php endif; ?>
			</nav>
			<?php
			if ( 'templates' === $tab && current_user_can( 'settings.view' ) ) {
				$this->render_templates();
			} elseif ( current_user_can( 'notifications.view' ) ) {
				$this->render_log();
			} else {
				wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
			}
			?>
		</div>
		<?php
	}

	private function render_log(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters validated by NotificationService::search().
		$filters = array(
			'status' => sanitize_key( (string) wp_unslash( $_GET['status'] ?? '' ) ),
			'event'  => sanitize_key( (string) wp_unslash( $_GET['event'] ?? '' ) ),
			'page'   => absint( $_GET['paged'] ?? 1 ),
		);
		// phpcs:enable
		$service   = $this->plugin->notifications();
		$stats     = $service->stats();
		$result    = $service->search( $filters );
		$labels    = array(
			NotificationService::STATUS_QUEUED    => __( 'Waiting', 'dms' ),
			NotificationService::STATUS_SENT      => __( 'Sent', 'dms' ),
			NotificationService::STATUS_FAILED    => __( 'Failed', 'dms' ),
			NotificationService::STATUS_CANCELLED => __( 'Cancelled', 'dms' ),
		);
		$can_retry = current_user_can( 'notifications.retry' );
		?>
		<div class="dms-tiles">
			<?php foreach ( $labels as $status => $label ) : ?>
				<a class="dms-tile<?php echo NotificationService::STATUS_FAILED === $status && $stats[ $status ] > 0 ? ' dms-tile--alert' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=log&status=' . strtolower( $status ) ) ); ?>">
					<span class="dms-tile__label"><?php echo esc_html( $label ); ?></span>
					<span class="dms-tile__value"><?php echo esc_html( number_format_i18n( $stats[ $status ] ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<p class="description"><?php esc_html_e( 'Failed emails are retried automatically every 15 minutes with increasing waits, up to the configured number of attempts. Emails for permanently deleted registrations are cancelled.', 'dms' ); ?></p>

		<?php if ( $can_retry && $stats[ NotificationService::STATUS_FAILED ] > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dms-confirm="<?php esc_attr_e( 'Retry every failed email now?', 'dms' ); ?>">
				<?php echo AdminActions::fields( 'notification_retry' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="all_failed" value="1">
				<button type="submit" class="button"><?php esc_html_e( 'Retry all failed now', 'dms' ); ?></button>
			</form>
		<?php endif; ?>

		<form method="get" class="dms-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"><input type="hidden" name="tab" value="log">
			<label for="dms-n-status"><?php esc_html_e( 'Status', 'dms' ); ?></label>
			<select id="dms-n-status" name="status"><option value=""><?php esc_html_e( 'All', 'dms' ); ?></option>
				<?php foreach ( $labels as $status => $label ) : ?>
					<option value="<?php echo esc_attr( strtolower( $status ) ); ?>" <?php selected( strtoupper( $filters['status'] ), $status ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="dms-n-event"><?php esc_html_e( 'Email', 'dms' ); ?></label>
			<select id="dms-n-event" name="event"><option value=""><?php esc_html_e( 'All', 'dms' ); ?></option>
				<?php foreach ( EmailTemplates::defaults() as $event => $def ) : ?>
					<option value="<?php echo esc_attr( strtolower( $event ) ); ?>" <?php selected( strtoupper( $filters['event'] ), $event ); ?>><?php echo esc_html( $def['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'dms' ), '', '', false ); ?>
		</form>

		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Created', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Email', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Registration', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Recipient', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Attempts', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Last error / next try', 'dms' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'dms' ); ?></span></th></tr></thead>
			<tbody>
			<?php if ( array() === $result['items'] ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No emails match.', 'dms' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['items'] as $n ) : ?>
				<tr>
					<td><?php echo esc_html( \DMS\Admin\View::short_date( $n->created_at ) ); ?></td>
					<td><?php echo esc_html( EmailTemplates::defaults()[ $n->event ]['label'] ?? $n->event ); ?></td>
					<td>
						<?php if ( $n->registration_number ) : ?>
							<a href="<?php echo esc_url( AdminMenu::registration_url( (int) $n->registration_id ) ); ?>"><?php echo esc_html( $n->registration_number ); ?></a>
						<?php endif; ?>
					</td>
					<td><?php echo null === $n->recipient_email ? '<em>' . esc_html__( 'removed', 'dms' ) . '</em>' : esc_html( $n->recipient_email ); ?></td>
					<td><span class="dms-pill dms-pill--
					<?php
					echo esc_attr(
						array(
							'SENT'   => 'green',
							'FAILED' => 'red',
							'QUEUED' => 'amber',
						)[ $n->status ] ?? 'gray'
					);
					?>
														"><?php echo esc_html( $labels[ $n->status ] ?? $n->status ); ?></span></td>
					<td><?php echo esc_html( $n->attempts . ' / ' . $n->max_attempts ); ?></td>
					<td>
						<?php
						echo esc_html( (string) $n->last_error );
						if ( NotificationService::STATUS_FAILED === $n->status ) {
							echo '<br><span class="description">' . esc_html( $n->next_attempt_at ? sprintf( /* translators: %s: date */ __( 'Next automatic try: %s', 'dms' ), get_date_from_gmt( $n->next_attempt_at, get_option( 'date_format' ) . ' H:i' ) ) : __( 'No more automatic tries.', 'dms' ) ) . '</span>';
						}
						?>
					</td>
					<td>
						<?php if ( $can_retry && NotificationService::STATUS_FAILED === $n->status && null !== $n->recipient_email ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php echo AdminActions::fields( 'notification_retry' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<input type="hidden" name="notification_id" value="<?php echo esc_attr( (string) $n->id ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Retry now', 'dms' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $result['total'] / $result['per_page'] );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $result['page'],
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
	}

	private function render_templates(): void {
		$templates = $this->plugin->email_templates();
		$can_edit  = current_user_can( 'settings.edit' );
		$off       = $can_edit ? '' : ' disabled';
		?>
		<p class="description"><?php esc_html_e( 'Emails are sent as plain text. Leave both boxes empty to use the default wording. Only the placeholders listed for each email are replaced. There is no disapproval email by design.', 'dms' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php echo AdminActions::fields( 'email_templates_save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			$draft = get_transient( self::DRAFT_TRANSIENT . get_current_user_id() );
			delete_transient( self::DRAFT_TRANSIENT . get_current_user_id() );
			?>
			<?php foreach ( EmailTemplates::defaults() as $event => $def ) : ?>
				<?php
				$custom  = $templates->is_customised( $event );
				$current = $custom ? $templates->get( $event ) : array(
					'subject' => '',
					'body'    => '',
				);
				if ( is_array( $draft ) && is_array( $draft[ $event ] ?? null ) ) {
					// A refused save: show what was typed (UI-03).
					$current = array(
						'subject' => (string) ( $draft[ $event ]['subject'] ?? '' ),
						'body'    => (string) ( $draft[ $event ]['body'] ?? '' ),
					);
				}
				$key = strtolower( $event );
				?>
				<div class="dms-card">
					<h2><?php echo esc_html( $def['label'] ); ?><?php echo $custom ? ' <span class="dms-badge">' . esc_html__( 'Customised', 'dms' ) . '</span>' : ''; ?></h2>
					<p class="description">
					<?php
					/* translators: %s: placeholder list */
					echo esc_html( sprintf( __( 'Placeholders: %s', 'dms' ), '{' . implode( '}, {', $def['placeholders'] ) . '}' ) );
					?>
					</p>
					<p><label for="dms-t-<?php echo esc_attr( $key ); ?>-s"><?php esc_html_e( 'Subject', 'dms' ); ?></label><br>
						<input type="text" class="large-text" id="dms-t-<?php echo esc_attr( $key ); ?>-s" name="templates[<?php echo esc_attr( $event ); ?>][subject]" value="<?php echo esc_attr( $current['subject'] ); ?>" placeholder="<?php echo esc_attr( $def['subject'] ); ?>"<?php echo esc_attr( $off ); ?>></p>
					<p><label for="dms-t-<?php echo esc_attr( $key ); ?>-b"><?php esc_html_e( 'Message', 'dms' ); ?></label><br>
						<textarea class="large-text" rows="6" id="dms-t-<?php echo esc_attr( $key ); ?>-b" name="templates[<?php echo esc_attr( $event ); ?>][body]" placeholder="<?php echo esc_attr( $def['body'] ); ?>"<?php echo esc_attr( $off ); ?>><?php echo esc_textarea( $current['body'] ); ?></textarea></p>
				</div>
			<?php endforeach; ?>
			<?php
			if ( $can_edit ) {
				submit_button( __( 'Save templates', 'dms' ) );
			}
			?>
		</form>
		<?php
	}
}

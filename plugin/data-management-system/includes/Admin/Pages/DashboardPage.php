<?php
/**
 * Dashboard (ARCH §24): live counts within the user's own scope, their work
 * queue, and operational alerts. Analytics on approved data come in Phase 9.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminMenu;
use DMS\Database\Tables;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class DashboardPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'dashboard.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		$user  = get_current_user_id();
		$lists = $this->plugin->lists();
		$areas = $lists->visible_areas( $user );
		$names = array(
			'holding'      => __( 'Holding Area', 'dms' ),
			'assigned'     => __( 'Assigned', 'dms' ),
			'under_review' => __( 'Under Review', 'dms' ),
			'approved'     => __( 'Approved', 'dms' ),
			'bin'          => __( 'Bin', 'dms' ),
		);
		?>
		<div class="wrap dms-wrap">
			<h1><?php esc_html_e( 'Data Management Dashboard', 'dms' ); ?></h1>
			<div class="dms-tiles">
				<?php foreach ( $areas as $area ) : ?>
					<?php $total = $lists->search( $user, $area, array( 'per_page' => 1 ) )['total']; ?>
					<a class="dms-tile" href="<?php echo esc_url( AdminMenu::area_url( $area ) ); ?>">
						<span class="dms-tile__label"><?php echo esc_html( $names[ $area ] ); ?></span>
						<span class="dms-tile__value"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					</a>
				<?php endforeach; ?>
				<?php if ( current_user_can( 'assignment.view' ) ) : ?>
					<a class="dms-tile<?php echo $this->open_exceptions() ? ' dms-tile--alert' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dms-assignments' ) ); ?>">
						<span class="dms-tile__label"><?php esc_html_e( 'Unassigned: no officer available', 'dms' ); ?></span>
						<span class="dms-tile__value"><?php echo esc_html( number_format_i18n( $this->open_exceptions() ) ); ?></span>
					</a>
				<?php endif; ?>
				<?php if ( current_user_can( 'notifications.view' ) ) : ?>
					<div class="dms-tile<?php echo $this->failed_notifications() ? ' dms-tile--alert' : ''; ?>">
						<span class="dms-tile__label"><?php esc_html_e( 'Failed email notifications', 'dms' ); ?></span>
						<span class="dms-tile__value"><?php echo esc_html( number_format_i18n( $this->failed_notifications() ) ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( array() === $areas ) : ?>
				<p><?php esc_html_e( 'You do not have access to any registration lists yet. Ask an administrator to give you a role.', 'dms' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function open_exceptions(): int {
		global $wpdb;
		$t = Tables::name( Tables::ASSIGNMENT_EXCEPTIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", 'OPEN' ) );
	}

	private function failed_notifications(): int {
		global $wpdb;
		$t = Tables::name( Tables::NOTIFICATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", 'FAILED' ) );
	}
}

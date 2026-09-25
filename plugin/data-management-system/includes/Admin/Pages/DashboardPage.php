<?php
/**
 * Dashboard (ARCH §24): live counts within the user's own scope, their work
 * queue, and operational alerts. Analytics on approved data come in Phase 9.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminMenu;
use DMS\Admin\View;
use DMS\Database\Tables;
use DMS\Plugin;
use DMS\Workflow\Status;

defined( 'ABSPATH' ) || exit;

class DashboardPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'dashboard.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		$user     = get_current_user_id();
		$lists    = $this->plugin->lists();
		$areas    = $lists->visible_areas( $user );
		$names    = array(
			'holding'      => __( 'Holding Area', 'dms' ),
			'assigned'     => __( 'Assigned', 'dms' ),
			'under_review' => __( 'Under Review', 'dms' ),
			'approved'     => __( 'Approved', 'dms' ),
			'bin'          => __( 'Bin', 'dms' ),
		);
		$icons    = array(
			'holding'      => 'clipboard',
			'assigned'     => 'id',
			'under_review' => 'visibility',
			'approved'     => 'yes-alt',
			'bin'          => 'trash',
		);
		$hour     = (int) current_time( 'G' );
		$greeting = $hour < 12 ? __( 'Good morning', 'dms' ) : ( $hour < 18 ? __( 'Good afternoon', 'dms' ) : __( 'Good evening', 'dms' ) );
		$first    = (string) wp_get_current_user()->first_name;
		?>
		<div class="wrap dms-wrap dms-dashboard">
			<?php
			View::page_head(
				__( 'Overview', 'dms' ),
				'' !== $first ? sprintf( '%1$s, %2$s', $greeting, $first ) : __( 'Data Management Dashboard', 'dms' ),
				__( 'What is happening across your registration workflow.', 'dms' )
			);
			?>
			<div class="dms-kpis">
				<?php foreach ( $areas as $area ) : ?>
					<?php View::kpi( $names[ $area ], (int) $lists->search( $user, $area, array( 'per_page' => 1 ) )['total'], $icons[ $area ], '', AdminMenu::area_url( $area ) ); ?>
				<?php endforeach; ?>
				<?php if ( current_user_can( 'assignment.view' ) ) : ?>
					<?php $open = $this->open_exceptions(); ?>
					<?php View::kpi( __( 'Unassigned: no officer available', 'dms' ), $open, 'warning', $open ? __( 'Needs an officer for the region', 'dms' ) : '', admin_url( 'admin.php?page=dms-assignments' ), $open > 0 ); ?>
				<?php endif; ?>
				<?php if ( current_user_can( 'notifications.view' ) ) : ?>
					<?php $failed = $this->failed_notifications(); ?>
					<?php View::kpi( __( 'Failed email notifications', 'dms' ), $failed, 'email-alt', $failed ? __( 'Retried automatically', 'dms' ) : '', admin_url( 'admin.php?page=' . NotificationsPage::SLUG ), $failed > 0 ); ?>
				<?php endif; ?>
			</div>
			<?php if ( array() === $areas ) : ?>
				<div class="dms-card dms-empty"><?php esc_html_e( 'You do not have access to any registration lists yet. Ask an administrator to give you a role.', 'dms' ); ?></div>
			<?php endif; ?>

			<?php
			$recent   = in_array( 'holding', $areas, true );
			$workload = current_user_can( 'assignment.view' );
			?>
			<?php if ( $recent || $workload ) : ?>
				<div class="dms-grid-2<?php echo $recent && $workload ? '' : ' dms-grid-2--single'; ?>">
					<?php if ( $recent ) : ?>
						<?php $this->recent( $user ); ?>
					<?php endif; ?>
					<?php if ( $workload ) : ?>
						<section class="dms-card" aria-labelledby="dms-workload-title">
							<div class="dms-card__head">
								<h2 id="dms-workload-title"><?php esc_html_e( 'Officer workload', 'dms' ); ?></h2>
								<span class="dms-muted"><?php esc_html_e( 'Assigned + under review', 'dms' ); ?></span>
							</div>
							<?php View::workload_list( View::officer_workload( $this->plugin ) ); ?>
						</section>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** The newest Holding Area records in the user's own scope. */
	private function recent( int $user ): void {
		$result = $this->plugin->lists()->search(
			$user,
			'holding',
			array(
				'per_page' => 6,
				'orderby'  => 'submitted_at',
				'order'    => 'desc',
			)
		);
		?>
		<section class="dms-card dms-card--flush" aria-labelledby="dms-recent-title">
			<div class="dms-card__head dms-card__head--padded">
				<h2 id="dms-recent-title"><?php esc_html_e( 'Recent registrations', 'dms' ); ?></h2>
				<a href="<?php echo esc_url( AdminMenu::area_url( 'holding' ) ); ?>"><?php esc_html_e( 'View Holding Area', 'dms' ); ?> <span aria-hidden="true">&rarr;</span></a>
			</div>
			<?php if ( array() === $result['items'] ) : ?>
				<p class="dms-empty"><?php esc_html_e( 'No registrations are waiting. New submissions appear here.', 'dms' ); ?></p>
			<?php else : ?>
				<table class="widefat dms-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Registration', 'dms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Applicant', 'dms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Region', 'dms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'dms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Submitted', 'dms' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $result['items'] as $item ) : ?>
						<?php
						$name   = trim( $item->first_name . ' ' . $item->last_name );
						$status = Status::from( $item->status );
						?>
						<tr>
							<td class="column-registration_number"><strong><a href="<?php echo esc_url( AdminMenu::registration_url( (int) $item->id, 'holding' ) ); ?>"><?php echo esc_html( $item->registration_number ); ?></a></strong></td>
							<td><span class="dms-person"><?php echo View::avatar( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $name ); ?></span></span></td>
							<td><?php echo esc_html( (string) $item->region_name ); ?></td>
							<td><span class="dms-status dms-status--<?php echo esc_attr( strtolower( $status->value ) ); ?>"><?php echo esc_html( $status->label() ); ?></span></td>
							<td><?php echo esc_html( View::date( $item->submitted_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
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

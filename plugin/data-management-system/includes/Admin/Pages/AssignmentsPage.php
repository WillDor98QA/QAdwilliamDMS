<?php
/**
 * Assignments (ARCH §6, §72.4, §72.5): registrations waiting for an officer,
 * officer workloads per region, and a "try again now" action.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Admin\AdminMenu;
use DMS\Electoral\ElectoralLevel;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class AssignmentsPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'assignment.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		$exceptions = $this->plugin->assignment_exceptions()->open_list( 200 );
		?>
		<div class="wrap dms-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Assignments', 'dms' ); ?></h1>
			<?php if ( current_user_can( 'assignment.assign' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dms-inline-form">
					<?php echo AdminActions::fields( 'assign_pending' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<button type="submit" class="page-title-action"><?php esc_html_e( 'Try automatic assignment now', 'dms' ); ?></button>
				</form>
			<?php endif; ?>
			<hr class="wp-header-end">

			<h2><?php esc_html_e( 'Waiting for an officer', 'dms' ); ?></h2>
			<?php if ( array() === $exceptions ) : ?>
				<p><?php esc_html_e( 'Every registration has an officer. Nothing is waiting.', 'dms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Registration', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Region', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Reason', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Since', 'dms' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $exceptions as $e ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( AdminMenu::registration_url( (int) $e->registration_id, 'holding' ) ); ?>"><?php echo esc_html( $e->registration_number ); ?></a></td>
							<td><?php echo esc_html( $e->region_name ); ?></td>
							<td><?php esc_html_e( 'No active officer is configured for this region.', 'dms' ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $e->created_at, get_option( 'date_format' ) . ' H:i' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Officer workload by region', 'dms' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Region', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Officer', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Active workload', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Last new assignment', 'dms' ); ?></th></tr></thead>
				<tbody>
				<?php
				$any = false;
				foreach ( $this->plugin->electoral()->options( ElectoralLevel::REGION ) as $region ) {
					foreach ( $this->plugin->assignments()->ranked_candidates( $region['id'] ) as $c ) {
						$any  = true;
						$user = get_user_by( 'id', $c['user_id'] );
						printf(
							'<tr><td>%1$s</td><td>%2$s</td><td>%3$d</td><td>%4$s</td></tr>',
							esc_html( $region['name'] ),
							esc_html( $user ? $user->display_name : '#' . $c['user_id'] ),
							(int) $c['workload'],
							esc_html( $c['last_assigned_at'] ? get_date_from_gmt( $c['last_assigned_at'], get_option( 'date_format' ) . ' H:i' ) : __( 'Never', 'dms' ) )
						);
					}
				}
				if ( ! $any ) {
					echo '<tr><td colspan="4">' . esc_html__( 'No active officers are configured. Create users with an officer role and at least one region.', 'dms' ) . '</td></tr>';
				}
				?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

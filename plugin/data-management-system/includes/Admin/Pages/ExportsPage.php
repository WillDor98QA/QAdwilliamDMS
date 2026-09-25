<?php
/**
 * My Exports: background export jobs of the current user (ARCH §75).
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Export\ExportService;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class ExportsPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'registrations.export' ) && ! current_user_can( 'approved.export' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		$jobs = $this->plugin->exports()->jobs_for( get_current_user_id() );
		?>
		<div class="wrap dms-wrap">
			<div class="dms-page-head">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'Data delivery', 'dms' ); ?></p>
					<h1><?php esc_html_e( 'My Exports', 'dms' ); ?></h1>
				</div>
			</div>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'Large exports are prepared in the background. Files can be downloaded for 24 hours, and only by you.', 'dms' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Requested', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'List', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Format', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Rows', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dms' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Download', 'dms' ); ?></span></th></tr></thead>
				<tbody>
				<?php if ( array() === $jobs ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'You have no recent background exports.', 'dms' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $jobs as $job ) : ?>
					<tr>
						<td><?php echo esc_html( \DMS\Admin\View::short_date( $job->created_at ) ); ?></td>
						<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $job->area ) ) ); ?></td>
						<td><?php echo esc_html( strtoupper( $job->format ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $job->total_rows ) ); ?></td>
						<td><?php echo esc_html( ExportService::STATUS_FAILED === $job->status ? (string) $job->error : ucfirst( strtolower( $job->status ) ) ); ?></td>
						<td>
							<?php if ( ExportService::STATUS_DONE === $job->status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php echo AdminActions::fields( 'export_download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<input type="hidden" name="job_key" value="<?php echo esc_attr( $job->job_key ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Download', 'dms' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

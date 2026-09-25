<?php
/**
 * Reports (ARCH §8, §9): predefined reports with preview (reports.view) and
 * CSV/Excel download (reports.export).
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\View;
use DMS\Analytics\ReportService;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class ReportsPage {

	public const SLUG = 'dms-reports';

	public const PER_PAGE = 50;

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'reports.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		$raw = AnalyticsPage::request_filters();
		$f   = $this->plugin->analytics()->filters( $raw );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection.
		$current = sanitize_key( (string) wp_unslash( $_GET['report'] ?? 'approved_by_location' ) );
		$catalog = ReportService::catalog();
		if ( ! isset( $catalog[ $current ] ) ) {
			$current = array_key_first( $catalog );
		}
		$report = $this->plugin->reports()->build( $current, $raw );
		$total  = count( $report['rows'] );
		$paged  = min( View::page_param(), max( 1, (int) ceil( $total / self::PER_PAGE ) ) );
		?>
		<div class="wrap dms-wrap">
			<div class="dms-page-head">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'Insights', 'dms' ); ?></p>
					<h1><?php esc_html_e( 'Reports', 'dms' ); ?></h1>
					<p><?php esc_html_e( 'Summary tables of approved registrations.', 'dms' ); ?></p>
				</div>
				<div class="dms-page-head__actions">
			<?php if ( current_user_can( 'reports.export' ) ) : ?>
				<?php
				AnalyticsPage::export_forms(
					'report_export',
					array(
						'report'  => $current,
						'filters' => (string) wp_json_encode(
							array(
								'from'      => $f['from'],
								'to'        => $f['to'],
								'region_id' => $f['region_id'],
							)
						),
					)
				);
				?>
			<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end">
			<nav class="nav-tab-wrapper">
				<?php foreach ( $catalog as $key => $info ) : ?>
					<a class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?> href="
					<?php
					echo esc_url(
						add_query_arg(
							array_merge(
								$raw,
								array(
									'page'   => self::SLUG,
									'report' => $key,
								)
							),
							admin_url( 'admin.php' )
						)
					);
					?>
										"><?php echo esc_html( $info['title'] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<p class="description"><?php echo esc_html( $catalog[ $current ]['description'] ); ?></p>
			<?php AnalyticsPage::filter_form( self::SLUG, $f, $this->plugin, array( 'report' => $current ) ); ?>
			<table class="widefat striped">
				<thead><tr>
					<?php foreach ( $report['headers'] as $h ) : ?>
						<th scope="col"><?php echo esc_html( $h ); ?></th>
					<?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php if ( array() === $report['rows'] ) : ?>
					<tr><td colspan="<?php echo esc_attr( (string) count( $report['headers'] ) ); ?>"><?php esc_html_e( 'No data for these filters.', 'dms' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( array_slice( $report['rows'], ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $row ) : ?>
					<tr>
						<?php foreach ( $row as $cell ) : ?>
							<td><?php echo esc_html( is_int( $cell ) ? number_format_i18n( $cell ) : (string) $cell ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php View::pagination( $total, self::PER_PAGE, $paged ); ?>
		</div>
		<?php
	}
}

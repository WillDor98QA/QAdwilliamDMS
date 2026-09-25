<?php
/**
 * Analytics (ARCH §9). Shows only the figures ARCH §9 lists. Charts are
 * accessible HTML bars: every value is printed, identity is never colour-alone
 * (legend + labels), and each chart has a table view.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Electoral\ElectoralLevel;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class AnalyticsPage {

	public const SLUG = 'dms-analytics';

	public function __construct( private Plugin $plugin ) {
	}

	/** @return array<string,string> Raw filters from the query string. */
	public static function request_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters, validated by AnalyticsService::filters().
		return array(
			'from'      => sanitize_text_field( (string) wp_unslash( $_GET['from'] ?? '' ) ),
			'to'        => sanitize_text_field( (string) wp_unslash( $_GET['to'] ?? '' ) ),
			'region_id' => (string) absint( $_GET['region_id'] ?? 0 ),
		);
		// phpcs:enable
	}

	public function render(): void {
		if ( ! current_user_can( 'analytics.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		$o = $this->plugin->analytics()->overview( self::request_filters() );
		$f = $o['filters'];
		?>
		<div class="wrap dms-wrap dms-analytics">
			<div class="dms-page-head">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'Insights', 'dms' ); ?></p>
					<h1><?php esc_html_e( 'Analytics', 'dms' ); ?></h1>
					<p><?php esc_html_e( 'Approved registrations and decisions over time.', 'dms' ); ?></p>
				</div>
				<div class="dms-page-head__actions">
			<?php if ( current_user_can( 'analytics.export' ) ) : ?>
				<?php
				self::export_forms(
					'analytics_export',
					array(
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
			<?php self::filter_form( self::SLUG, $f, $this->plugin ); ?>

			<h2 class="screen-reader-text"><?php esc_html_e( 'Approved records', 'dms' ); ?></h2>
			<div class="dms-tiles">
				<?php
				self::tile( __( 'Total approved', 'dms' ), $o['totals']['approved_total'] );
				self::tile( __( 'Approved this month', 'dms' ), $o['totals']['approved_this_month'] );
				self::tile( __( 'Approved in selected period', 'dms' ), $o['totals']['approved_in_period'] );
				self::tile( __( 'Approval rate in period', 'dms' ), null === $o['decisions']['approval_rate'] ? '—' : number_format_i18n( $o['decisions']['approval_rate'], 1 ) . '%' );
				?>
			</div>

			<div class="dms-chart-grid">
				<section class="dms-card" aria-labelledby="dms-chart-location">
					<h2 id="dms-chart-location"><?php echo 'REGION' === $o['locations']['level'] ? esc_html__( 'Approved records by region', 'dms' ) : esc_html__( 'Approved records by constituency', 'dms' ); ?></h2>
					<?php self::bar_list( array_map( static fn( array $r ): array => array( $r['label'], $r['count'] ), $o['locations']['rows'] ), __( 'Approved', 'dms' ) ); ?>
				</section>
				<section class="dms-card" aria-labelledby="dms-chart-gender">
					<h2 id="dms-chart-gender"><?php esc_html_e( 'Approved records by gender', 'dms' ); ?></h2>
					<?php self::bar_list( array_map( static fn( array $g ): array => array( $g['label'], $g['count'] ), $o['gender'] ), __( 'Approved', 'dms' ) ); ?>
				</section>
			</div>

			<section class="dms-card" aria-labelledby="dms-chart-trend">
				<h2 id="dms-chart-trend"><?php esc_html_e( 'Decisions by month', 'dms' ); ?></h2>
				<?php self::trend_chart( $o['trend'] ); ?>
			</section>

			<h2><?php esc_html_e( 'Workload', 'dms' ); ?></h2>
			<div class="dms-tiles">
				<?php
				self::tile( __( 'Waiting for an officer', 'dms' ), $o['pending']['unassigned'], $o['pending']['open_exceptions'] > 0 );
				self::tile( __( 'Assigned', 'dms' ), $o['pending']['assigned'] );
				self::tile( __( 'Under review', 'dms' ), $o['pending']['under_review'] );
				self::tile( __( 'Oldest undecided (days)', 'dms' ), null === $o['pending']['oldest_waiting_days'] ? '—' : $o['pending']['oldest_waiting_days'] );
				?>
			</div>
			<section class="dms-card" aria-labelledby="dms-officers">
				<h2 id="dms-officers"><?php esc_html_e( 'Officer workload', 'dms' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Officer', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Open work now', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Approved in period', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Disapproved in period', 'dms' ); ?></th></tr></thead>
					<tbody>
					<?php if ( array() === $o['officers'] ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No officer activity for these filters.', 'dms' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $o['officers'] as $row ) : ?>
						<tr><td><?php echo esc_html( $row['name'] ); ?></td><td><?php echo esc_html( number_format_i18n( $row['active'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['approved'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['disapproved'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
			<p class="description">
			<?php
			/* translators: %s: time */
			echo esc_html( sprintf( __( 'Figures updated %s (refreshed at most every 5 minutes).', 'dms' ), get_date_from_gmt( $o['generated'], get_option( 'time_format' ) ) ) );
			?>
			</p>
		</div>
		<?php
	}

	/** Filter row (one row, above the charts). */
	public static function filter_form( string $slug, array $f, Plugin $plugin, array $extra = array() ): void {
		?>
		<form method="get" class="dms-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>">
			<?php foreach ( $extra as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<label for="dms-a-from"><?php esc_html_e( 'From', 'dms' ); ?></label>
			<input type="date" id="dms-a-from" name="from" value="<?php echo esc_attr( $f['from'] ); ?>">
			<label for="dms-a-to"><?php esc_html_e( 'To', 'dms' ); ?></label>
			<input type="date" id="dms-a-to" name="to" value="<?php echo esc_attr( $f['to'] ); ?>">
			<label for="dms-a-region"><?php esc_html_e( 'Region', 'dms' ); ?></label>
			<select id="dms-a-region" name="region_id"><option value="0"><?php esc_html_e( 'All regions', 'dms' ); ?></option>
				<?php foreach ( $plugin->electoral()->options( ElectoralLevel::REGION ) as $r ) : ?>
					<option value="<?php echo esc_attr( (string) $r['id'] ); ?>" <?php selected( (int) $f['region_id'], $r['id'] ); ?>><?php echo esc_html( $r['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Apply', 'dms' ), '', '', false ); ?>
		</form>
		<?php
	}

	/** @param array<string,string> $fields */
	public static function export_forms( string $action, array $fields ): void {
		foreach ( array(
			'csv'  => __( 'Export CSV', 'dms' ),
			'xlsx' => __( 'Export Excel', 'dms' ),
		) as $format => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dms-inline-form">';
			echo AdminActions::fields( $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf( '<input type="hidden" name="format" value="%s">', esc_attr( $format ) );
			foreach ( $fields as $name => $value ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( $value ) );
			}
			printf( '<button type="submit" class="page-title-action">%s</button></form>', esc_html( $label ) );
		}
	}

	private static function tile( string $label, int|string $value, bool $alert = false ): void {
		printf(
			'<div class="dms-tile%1$s"><span class="dms-tile__label">%2$s</span><span class="dms-tile__value">%3$s</span></div>',
			$alert ? ' dms-tile--alert' : '',
			esc_html( $label ),
			esc_html( is_int( $value ) ? number_format_i18n( $value ) : $value )
		);
	}

	/**
	 * Horizontal single-series bars: magnitude by category. Value printed on every
	 * row (small category counts), hover title, and a table view.
	 *
	 * @param list<array{0:string,1:int}> $rows
	 */
	private static function bar_list( array $rows, string $measure ): void {
		if ( array() === $rows || 0 === array_sum( array_column( $rows, 1 ) ) ) {
			echo '<p class="description">' . esc_html__( 'No approved records for these filters yet.', 'dms' ) . '</p>';
			return;
		}
		$max = max( array_column( $rows, 1 ) );
		echo '<ul class="dms-bars" aria-hidden="true">';
		foreach ( $rows as [ $label, $count ] ) {
			$pct = $max > 0 ? max( 0.5, 100 * $count / $max ) : 0;
			printf(
				'<li class="dms-bars__row" title="%1$s: %2$s"><span class="dms-bars__label">%1$s</span><span class="dms-bars__track">%3$s</span><span class="dms-bars__value">%2$s</span></li>',
				esc_attr( $label ),
				esc_html( number_format_i18n( $count ) ),
				// A zero gets no mark at all, so it can't be read as "a little".
				$count > 0 ? sprintf( '<span class="dms-bars__bar" style="width:%.2f%%"></span>', (float) $pct ) : ''
			);
		}
		echo '</ul>';
		self::table_view( array( __( 'Category', 'dms' ), $measure ), $rows );
	}

	/**
	 * Monthly approvals vs disapprovals: grouped vertical bars on one shared axis,
	 * with a legend and a table view.
	 *
	 * @param list<array{month:string,approved:int,disapproved:int}> $trend
	 */
	private static function trend_chart( array $trend ): void {
		$max = 0;
		foreach ( $trend as $m ) {
			$max = max( $max, $m['approved'], $m['disapproved'] );
		}
		if ( 0 === $max ) {
			echo '<p class="description">' . esc_html__( 'No decisions in the selected period.', 'dms' ) . '</p>';
			return;
		}
		echo '<ul class="dms-legend"><li><span class="dms-swatch dms-swatch--approved"></span>' . esc_html__( 'Approved', 'dms' ) . '</li><li><span class="dms-swatch dms-swatch--disapproved"></span>' . esc_html__( 'Disapproved', 'dms' ) . '</li></ul>';
		echo '<div class="dms-columns" aria-hidden="true">';
		foreach ( $trend as $i => $m ) {
			$time = strtotime( $m['month'] . '-01 12:00:00 UTC' );
			// The year is shown on the first month and on January so a period crossing years reads correctly.
			$axis = ( 0 === $i || '01' === substr( $m['month'], 5 ) ) ? wp_date( 'M Y', $time ) : wp_date( 'M', $time );
			printf(
				'<div class="dms-columns__group" title="%1$s — %2$s: %3$s, %4$s: %5$s"><div class="dms-columns__bars">%6$s%7$s</div><span class="dms-columns__label">%8$s</span></div>',
				esc_attr( wp_date( 'M Y', $time ) ),
				esc_attr__( 'Approved', 'dms' ),
				esc_attr( number_format_i18n( $m['approved'] ) ),
				esc_attr__( 'Disapproved', 'dms' ),
				esc_attr( number_format_i18n( $m['disapproved'] ) ),
				self::column( 'approved', $m['approved'], $max ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				self::column( 'disapproved', $m['disapproved'], $max ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $axis )
			);
		}
		echo '</div>';
		self::table_view(
			array( __( 'Month', 'dms' ), __( 'Approved', 'dms' ), __( 'Disapproved', 'dms' ) ),
			array_map( static fn( array $m ): array => array( wp_date( 'F Y', strtotime( $m['month'] . '-01 12:00:00 UTC' ) ), $m['approved'], $m['disapproved'] ), $trend )
		);
	}

	/** One column with its value on top; a zero keeps its slot (so pairs stay aligned) but draws nothing. */
	private static function column( string $series, int $value, int $max ): string {
		if ( 0 === $value ) {
			return '<span class="dms-columns__bar dms-columns__bar--empty"></span>';
		}
		return sprintf(
			'<span class="dms-columns__bar dms-columns__bar--%1$s" style="height:%2$.2f%%"><span class="dms-columns__value">%3$s</span></span>',
			esc_attr( $series ),
			(float) ( 100 * $value / $max ),
			esc_html( number_format_i18n( $value ) )
		);
	}

	/** Always available table view (screen readers get this instead of the drawing). */
	private static function table_view( array $headers, array $rows ): void {
		echo '<details class="dms-table-view"><summary>' . esc_html__( 'View as table', 'dms' ) . '</summary><table class="widefat striped"><thead><tr>';
		foreach ( $headers as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $i => $cell ) {
				echo 0 === $i ? '<th scope="row">' . esc_html( (string) $cell ) . '</th>' : '<td>' . esc_html( is_int( $cell ) ? number_format_i18n( $cell ) : (string) $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></details>';
	}
}

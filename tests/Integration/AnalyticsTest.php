<?php
/**
 * Analytics & reports (ARCH §8, §9; MP §41 Phase 9, §27).
 */

namespace DMS\Tests\Integration;

use DMS\Analytics\AnalyticsService;
use DMS\Analytics\ReportService;
use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Plugin;

final class AnalyticsTest extends \WP_UnitTestCase {

	use Fixtures;

	/** @var array{region:int,constituency:int,station:int} */
	private array $a;
	/** @var array{region:int,constituency:int,station:int} */
	private array $b;
	private int $officer_a;
	private int $officer_b;

	public function set_up(): void {
		parent::set_up();
		delete_option( \DMS\Support\Settings::OPTION );
		update_option( 'timezone_string', 'Africa/Accra' );
		$this->a         = $this->make_hierarchy( 'ANA' );
		$this->b         = $this->make_hierarchy( 'ANB' );
		$this->officer_a = $this->make_officer( array( $this->a['region'] ) );
		$this->officer_b = $this->make_officer( array( $this->b['region'] ) );
		AnalyticsService::invalidate();
	}

	public function tear_down(): void {
		Plugin::instance()->clock()->freeze( null );
		parent::tear_down();
	}

	private function at_time( string $when ): void {
		Plugin::instance()->clock()->freeze( new \DateTimeImmutable( $when, new \DateTimeZone( 'UTC' ) ) );
	}

	/** Creates a registration and decides it through the real workflow at a given time. */
	private function decided( array $h, int $officer, string $decision, string $when, array $overrides = array() ): int {
		$this->at_time( $when );
		$id = $this->make_registration( $h, $overrides );
		Plugin::instance()->assignments()->auto_assign( $id );
		wp_set_current_user( $officer );
		Plugin::instance()->workflow()->start_review( $id );
		if ( 'approve' === $decision ) {
			Plugin::instance()->workflow()->approve( $id );
		} elseif ( 'disapprove' === $decision ) {
			Plugin::instance()->workflow()->disapprove( $id, 'Details could not be confirmed' );
		}
		return $id;
	}

	private function seed(): void {
		$this->decided( $this->a, $this->officer_a, 'approve', '2026-07-10 10:00:00', array( 'gender' => 'Female' ) );
		$this->decided( $this->a, $this->officer_a, 'approve', '2026-09-05 10:00:00', array( 'gender' => 'Male' ) );
		$this->decided( $this->a, $this->officer_a, 'approve', '2026-09-20 10:00:00', array( 'gender' => 'Female' ) );
		$this->decided( $this->b, $this->officer_b, 'approve', '2026-09-21 10:00:00', array( 'gender' => null ) );
		$this->decided( $this->a, $this->officer_a, 'disapprove', '2026-08-15 10:00:00' );
		$this->decided( $this->b, $this->officer_b, 'review', '2026-09-22 10:00:00' ); // Still under review.
		$this->at_time( '2026-09-23 10:00:00' );
		$this->make_registration( $this->b ); // Pending, not yet assigned.
		$this->at_time( '2026-09-25 12:00:00' );
		AnalyticsService::invalidate();
	}

	private function overview( array $filters = array() ): array {
		return Plugin::instance()->analytics()->overview( $filters + array( 'from' => '2026-06-01', 'to' => '2026-09-30' ) );
	}

	// -------------------------------------------------------------- analytics

	public function test_approved_totals_this_month_and_period(): void {
		$this->seed();
		$t = $this->overview()['totals'];
		$this->assertSame( 4, $t['approved_total'] );
		$this->assertSame( 3, $t['approved_this_month'], 'September 2026' );
		$this->assertSame( 4, $t['approved_in_period'] );
		$this->assertSame( 1, $this->overview( array( 'from' => '2026-07-01', 'to' => '2026-07-31' ) )['totals']['approved_in_period'] );
	}

	public function test_region_filter_applies_everywhere(): void {
		$this->seed();
		$o = $this->overview( array( 'region_id' => $this->b['region'] ) );
		$this->assertSame( 1, $o['totals']['approved_total'] );
		$this->assertSame( 'CONSTITUENCY', $o['locations']['level'], 'Drill-down to constituencies' );
		$this->assertSame( 1, $o['pending']['unassigned'] );
		$this->assertSame( 1, $o['pending']['under_review'] );
		$this->assertSame( array( $this->officer_b ), array_column( $o['officers'], 'user_id' ) );
	}

	public function test_gender_breakdown_includes_not_stated(): void {
		$this->seed();
		$g = array_column( $this->overview()['gender'], 'count', 'label' );
		$this->assertSame( array( 'Female' => 2, 'Male' => 1, 'Not stated' => 1 ), $g );
	}

	public function test_approved_by_region_lists_every_region_including_zero(): void {
		$this->seed();
		$empty = $this->make_hierarchy( 'ANZ' );
		AnalyticsService::invalidate();
		$rows = array_column( $this->overview()['locations']['rows'], 'count', 'id' );
		$this->assertSame( 3, $rows[ $this->a['region'] ] );
		$this->assertSame( 1, $rows[ $this->b['region'] ] );
		$this->assertSame( 0, $rows[ $empty['region'] ] );
	}

	public function test_monthly_decisions_from_audit_include_empty_months(): void {
		$this->seed();
		$trend = array_column( $this->overview()['trend'], null, 'month' );
		$this->assertSame( array( '2026-06', '2026-07', '2026-08', '2026-09' ), array_keys( $trend ) );
		$this->assertSame( 0, $trend['2026-06']['approved'] );
		$this->assertSame( 1, $trend['2026-07']['approved'] );
		$this->assertSame( 1, $trend['2026-08']['disapproved'] );
		$this->assertSame( 3, $trend['2026-09']['approved'] );
		$d = $this->overview()['decisions'];
		$this->assertSame( array( 'approved' => 4, 'disapproved' => 1, 'approval_rate' => 80.0 ), $d );
	}

	public function test_decision_still_counts_after_restore(): void {
		$this->seed();
		global $wpdb;
		$bin = (int) $wpdb->get_var( 'SELECT id FROM ' . Tables::name( Tables::REGISTRATIONS ) . " WHERE status = 'DISAPPROVED'" );
		$this->act_as( array( 'bin.restore', 'bin.view' ) );
		Plugin::instance()->workflow()->restore( $bin );
		AnalyticsService::invalidate();
		$this->assertSame( 1, $this->overview()['decisions']['disapproved'], 'The August decision happened, even though the record was restored' );
	}

	public function test_pending_and_officer_workload(): void {
		$this->seed();
		$o = $this->overview();
		$this->assertSame( 1, $o['pending']['unassigned'] );
		$this->assertSame( 1, $o['pending']['under_review'] );
		$this->assertSame( 3, $o['pending']['oldest_waiting_days'], 'Under review since 22 Sep' );
		$by = array_column( $o['officers'], null, 'user_id' );
		$this->assertSame( array( 'active' => 0, 'approved' => 3, 'disapproved' => 1 ), array_intersect_key( $by[ $this->officer_a ], array_flip( array( 'active', 'approved', 'disapproved' ) ) ) );
		$this->assertSame( 1, $by[ $this->officer_b ]['active'] );
	}

	public function test_results_are_cached_until_invalidated(): void {
		$this->seed();
		$this->assertSame( 4, $this->overview()['totals']['approved_total'] );
		$this->decided( $this->a, $this->officer_a, 'approve', '2026-09-25 13:00:00' );
		$this->assertSame( 4, $this->overview()['totals']['approved_total'], 'Cached' );
		AnalyticsService::invalidate();
		$this->assertSame( 5, $this->overview()['totals']['approved_total'] );
	}

	public function test_invalid_filters_fall_back_safely(): void {
		$this->at_time( '2026-09-25 12:00:00' );
		$f = Plugin::instance()->analytics()->filters( array( 'from' => "2026-01-01' OR 1=1", 'to' => '2026-02-30', 'region_id' => '-4' ) );
		$this->assertSame( '2025-10-01', $f['from'], 'Default: last 12 months' );
		$this->assertSame( '2026-09-25', $f['to'], 'Impossible date falls back to today' );
		$this->assertNull( $f['region_id'], 'A negative id is not region 4' );
		$this->assertNull( Plugin::instance()->analytics()->filters( array( 'region_id' => '3 OR 1=1' ) )['region_id'] );
		$swapped = Plugin::instance()->analytics()->filters( array( 'from' => '2026-09-30', 'to' => '2026-09-01' ) );
		$this->assertSame( '2026-09-01', $swapped['from'] );
	}

	// ---------------------------------------------------------------- reports

	public function test_every_report_builds_with_headers(): void {
		$this->seed();
		$this->act_as( array( 'reports.view' ) );
		foreach ( array_keys( ReportService::catalog() ) as $report ) {
			$data = Plugin::instance()->reports()->build( $report, array( 'from' => '2026-06-01', 'to' => '2026-09-30' ) );
			$this->assertNotEmpty( $data['headers'], $report );
			$this->assertNotEmpty( $data['rows'], $report );
		}
	}

	public function test_report_contents(): void {
		$this->seed();
		$this->act_as( array( 'reports.view' ) );
		$filters  = array( 'from' => '2026-06-01', 'to' => '2026-09-30' );
		$location = Plugin::instance()->reports()->build( 'approved_by_location', $filters )['rows'];
		$this->assertSame( array( array( 'Region ANA', 'Constituency ANA', 'ANAS', 'Station ANA', 3 ), array( 'Region ANB', 'Constituency ANB', 'ANBS', 'Station ANB', 1 ) ), $location );

		$gender = Plugin::instance()->reports()->build( 'approved_by_gender', $filters );
		$this->assertSame( array( 'Region', 'Female', 'Male', 'Not stated', 'Total' ), $gender['headers'] );
		$this->assertSame( array( 'Region ANA', 2, 1, 0, 3 ), $gender['rows'][0] );

		$months = array_column( Plugin::instance()->reports()->build( 'decisions_by_month', $filters )['rows'], null, 0 );
		$this->assertSame( array( '2026-08', 0, 1, 0.0 ), $months['2026-08'] );

		$pending = Plugin::instance()->reports()->build( 'pending_by_region', $filters )['rows'];
		$this->assertSame( array( 'Region ANB', 1, 0, 1, 2 ), $pending[0] );
	}

	public function test_report_access_and_export_are_permission_checked_and_audited(): void {
		$this->seed();
		$this->act_as( array( 'analytics.view' ) );
		try {
			Plugin::instance()->reports()->build( 'decisions_by_month', array() );
			$this->fail( 'reports.view required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}
		$this->act_as( array( 'reports.view' ) );
		try {
			Plugin::instance()->reports()->export( 'decisions_by_month', array(), 'csv' );
			$this->fail( 'reports.export required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}

		$user = $this->act_as( array( 'reports.view', 'reports.export' ) );
		$file = Plugin::instance()->reports()->export( 'approved_by_location', array( 'from' => '2026-06-01', 'to' => '2026-09-30' ), 'csv' );
		$csv  = (string) file_get_contents( $file['path'] );
		wp_delete_file( $file['path'] );
		$this->assertStringContainsString( 'Region ANA', $csv );
		$this->assertStringNotContainsString( 'Mensah', $csv, 'Reports are aggregates: no personal data' );
		$this->assertSame( 'approved-by-location-2026-06-01-to-2026-09-30.csv', $file['filename'] );

		global $wpdb;
		$audit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::AUDIT_LOG ) . " WHERE action = %s AND object_type = 'report' ORDER BY id DESC LIMIT 1", AuditAction::EXPORTED ) );
		$this->assertSame( (string) $user, (string) $audit->user_id );
		$this->assertSame( 'approved_by_location', json_decode( $audit->metadata, true )['report'] );
	}

	public function test_analytics_export_needs_analytics_export(): void {
		$this->seed();
		$this->act_as( array( 'analytics.view' ) );
		try {
			Plugin::instance()->reports()->export_analytics( array(), 'xlsx' );
			$this->fail( 'analytics.export required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}
		$this->act_as( array( 'analytics.view', 'analytics.export' ) );
		$file = Plugin::instance()->reports()->export_analytics( array( 'from' => '2026-06-01', 'to' => '2026-09-30' ), 'xlsx' );
		$zip  = new \ZipArchive();
		$this->assertTrue( $zip->open( $file['path'] ) );
		$this->assertStringContainsString( 'Total approved', (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ) );
		$zip->close();
		wp_delete_file( $file['path'] );
	}

	public function test_reserved_report_and_dashboard_export_permissions(): void {
		$registry = Plugin::instance()->permissions();
		$this->assertTrue( $registry->get( 'reports.create' )->reserved );
		$this->assertTrue( $registry->get( 'dashboard.export' )->reserved );
	}

	// ----------------------------------------------------------------- pages

	public function test_pages_refuse_without_permission_and_render_with_it(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		$this->seed();
		$render = static function ( callable $cb ): string {
			ob_start();
			try {
				$cb();
			} finally {
				$html = (string) ob_get_clean();
			}
			return $html;
		};
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( Plugin::instance()->page_analytics(), Plugin::instance()->page_reports() ) as $page ) {
			try {
				$render( array( $page, 'render' ) );
				$this->fail( get_class( $page ) . ' must refuse' );
			} catch ( \WPDieException $e ) {
				unset( $e );
			}
		}

		$this->act_as( array( 'analytics.view', 'reports.view' ) );
		$_GET = array( 'from' => '2026-06-01', 'to' => '2026-09-30' );
		$html = $render( array( Plugin::instance()->page_analytics(), 'render' ) );
		$this->assertStringContainsString( 'Approved records by region', $html );
		$this->assertStringContainsString( 'View as table', $html );
		$this->assertStringContainsString( 'Region ANA', $html );
		$this->assertStringNotContainsString( 'Export CSV', $html, 'No export without analytics.export' );
		$this->assertStringContainsString( 'Decisions by month', $render( array( Plugin::instance()->page_reports(), 'render' ) ) );
		$_GET = array();
	}

	public function test_region_names_are_escaped_in_charts(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		$el  = Plugin::instance()->electoral();
		$r   = $el->insert( \DMS\Electoral\ElectoralLevel::REGION, 'XSS', '<img src=x onerror=alert(1)>' );
		$c   = $el->insert( \DMS\Electoral\ElectoralLevel::CONSTITUENCY, 'XSSC', 'C', $r );
		$h   = array( 'region' => $r, 'constituency' => $c, 'station' => $el->insert( \DMS\Electoral\ElectoralLevel::POLLING_STATION, 'XSSS', 'S', $c ) );
		$off = $this->make_officer( array( $r ) );
		$this->decided( $h, $off, 'approve', '2026-09-20 10:00:00', array( 'gender' => '<script>x</script>' ) );
		$this->at_time( '2026-09-25 12:00:00' );
		AnalyticsService::invalidate();
		$this->act_as( array( 'analytics.view' ) );
		ob_start();
		Plugin::instance()->page_analytics()->render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x', $html, 'The name is shown, escaped' );
		$this->assertStringNotContainsString( '<script>x', $html );
	}
}

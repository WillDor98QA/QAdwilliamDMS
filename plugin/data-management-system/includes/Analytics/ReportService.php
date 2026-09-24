<?php
/**
 * Predefined, exportable reports (ARCH §8 "generate reports", §9 "exportable
 * reports"; MP §27). A fixed set: there is no custom report builder in V1
 * (reports.create is reserved, ID-51).
 *
 * reports.view lets a user preview reports; reports.export lets them download
 * CSV/Excel. Every download is audited (user, report, filters, format, rows).
 * Reports contain aggregate counts only — no personal data.
 *
 * @package DMS
 */

namespace DMS\Analytics;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Tables;
use DMS\Errors\NotFoundException;
use DMS\Errors\ValidationException;
use DMS\Export\CsvWriter;
use DMS\Export\XlsxWriter;
use DMS\Support\Authorizer;
use DMS\Workflow\Status;

defined( 'ABSPATH' ) || exit;

class ReportService {

	public function __construct(
		private \wpdb $db,
		private AnalyticsService $analytics,
		private AuditService $audit,
		private Authorizer $authorizer,
	) {
	}

	/** @return array<string,array{title:string,description:string}> */
	public static function catalog(): array {
		return array(
			'approved_by_location' => array(
				'title'       => __( 'Approved registrations by location', 'dms' ),
				'description' => __( 'Approved records per Region, Constituency and Polling Station (only places with at least one approval).', 'dms' ),
			),
			'approved_by_gender'   => array(
				'title'       => __( 'Approved registrations by region and gender', 'dms' ),
				'description' => __( 'Approved records per Region, broken down by gender.', 'dms' ),
			),
			'decisions_by_month'   => array(
				'title'       => __( 'Decisions by month', 'dms' ),
				'description' => __( 'Approvals and disapprovals made each month in the selected period, with the approval rate.', 'dms' ),
			),
			'officer_workload'     => array(
				'title'       => __( 'Officer workload and decisions', 'dms' ),
				'description' => __( 'Each officer\'s current open work and the decisions they made in the selected period.', 'dms' ),
			),
			'pending_by_region'    => array(
				'title'       => __( 'Pending workload by region', 'dms' ),
				'description' => __( 'Registrations not yet decided, per Region: waiting for an officer, assigned, and under review.', 'dms' ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $filters Raw filters (from, to, region_id).
	 * @return array{headers:list<string>,rows:list<list<string|int|float>>}
	 * @throws NotFoundException
	 */
	public function build( string $report, array $filters ): array {
		$this->authorizer->require( 'reports.view' );
		if ( ! isset( self::catalog()[ $report ] ) ) {
			throw new NotFoundException();
		}
		$f = $this->analytics->filters( $filters );
		return match ( $report ) {
			'approved_by_location' => $this->approved_by_location( $f ),
			'approved_by_gender'   => $this->approved_by_gender( $f ),
			'decisions_by_month'   => $this->decisions_by_month( $f ),
			'officer_workload'     => $this->officer_workload( $f ),
			'pending_by_region'    => $this->pending_by_region( $f ),
		};
	}

	/**
	 * Writes a report to a temporary file for download (reports.export). Audited.
	 *
	 * @return array{path:string,filename:string,rows:int}
	 */
	public function export( string $report, array $filters, string $format ): array {
		$this->authorizer->require( 'reports.view', 'reports.export' );
		return $this->write( $report, $this->build( $report, $filters ), $filters, $format, 'report' );
	}

	/**
	 * Exports the Analytics screen figures (analytics.export). Audited.
	 *
	 * @return array{path:string,filename:string,rows:int}
	 */
	public function export_analytics( array $filters, string $format ): array {
		$this->authorizer->require( 'analytics.view', 'analytics.export' );
		$o    = $this->analytics->overview( $filters );
		$rows = array(
			array( __( 'Total approved', 'dms' ), '', $o['totals']['approved_total'] ),
			array( __( 'Approved this month', 'dms' ), '', $o['totals']['approved_this_month'] ),
			array( __( 'Approved in period', 'dms' ), $o['filters']['from'] . ' – ' . $o['filters']['to'], $o['totals']['approved_in_period'] ),
			array( __( 'Approvals in period', 'dms' ), '', $o['decisions']['approved'] ),
			array( __( 'Disapprovals in period', 'dms' ), '', $o['decisions']['disapproved'] ),
			array( __( 'Pending (waiting for an officer)', 'dms' ), '', $o['pending']['unassigned'] ),
			array( __( 'Assigned', 'dms' ), '', $o['pending']['assigned'] ),
			array( __( 'Under review', 'dms' ), '', $o['pending']['under_review'] ),
		);
		foreach ( $o['gender'] as $g ) {
			$rows[] = array( __( 'Approved by gender', 'dms' ), $g['label'], $g['count'] );
		}
		foreach ( $o['locations']['rows'] as $l ) {
			$rows[] = array( __( 'Approved by location', 'dms' ), $l['label'], $l['count'] );
		}
		foreach ( $o['trend'] as $m ) {
			$rows[] = array( __( 'Approvals by month', 'dms' ), $m['month'], $m['approved'] );
			$rows[] = array( __( 'Disapprovals by month', 'dms' ), $m['month'], $m['disapproved'] );
		}
		$data = array(
			'headers' => array( __( 'Measure', 'dms' ), __( 'Breakdown', 'dms' ), __( 'Value', 'dms' ) ),
			'rows'    => $rows,
		);
		return $this->write( 'analytics', $data, $filters, $format, 'analytics' );
	}

	/** @param array{headers:list<string>,rows:list<list<mixed>>} $data */
	private function write( string $name, array $data, array $filters, string $format, string $object_type ): array {
		if ( ! in_array( $format, array( 'csv', 'xlsx' ), true ) ) {
			throw new ValidationException( array( 'format' => __( 'Choose CSV or Excel.', 'dms' ) ) );
		}
		$temp = wp_tempnam( 'dms-report' );
		$path = $temp . '.' . $format;
		wp_delete_file( $temp );
		$writer = 'xlsx' === $format ? new XlsxWriter( $path ) : new CsvWriter( $path );
		$writer->add_sheet( 'Report', $data['headers'], array( 'width' => 28 ) );
		foreach ( $data['rows'] as $row ) {
			$writer->add_row( $row );
		}
		$writer->finish();
		$f = $this->analytics->filters( $filters );
		$this->audit->record(
			AuditAction::EXPORTED,
			array(
				'object_type' => $object_type,
				'metadata'    => array(
					'report'  => $name,
					'format'  => $format,
					'filters' => array(
						'from'      => $f['from'],
						'to'        => $f['to'],
						'region_id' => $f['region_id'],
					),
					'rows'    => count( $data['rows'] ),
				),
			)
		);
		return array(
			'path'     => $path,
			'filename' => sprintf( '%s-%s-to-%s.%s', str_replace( '_', '-', $name ), $f['from'], $f['to'], $format ),
			'rows'     => count( $data['rows'] ),
		);
	}

	private function approved_by_location( array $f ): array {
		$r      = Tables::name( Tables::REGISTRATIONS );
		$g      = Tables::name( Tables::REGIONS );
		$c      = Tables::name( Tables::CONSTITUENCIES );
		$p      = Tables::name( Tables::POLLING_STATIONS );
		$region = null === $f['region_id'] ? '' : $this->db->prepare( ' AND r.region_id = %d', $f['region_id'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from Tables; $region is prepared.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT g.name AS region, c.name AS constituency, p.code AS station_code, p.name AS station, COUNT(*) AS n
				FROM {$r} r INNER JOIN {$g} g ON g.id = r.region_id INNER JOIN {$c} c ON c.id = r.constituency_id INNER JOIN {$p} p ON p.id = r.polling_station_id
				WHERE r.status = %s{$region} GROUP BY g.name, c.name, p.code, p.name ORDER BY g.name, c.name, p.name",
				Status::APPROVED->value
			)
		);
		return array(
			'headers' => array( __( 'Region', 'dms' ), __( 'Constituency', 'dms' ), __( 'Polling Station Code', 'dms' ), __( 'Polling Station', 'dms' ), __( 'Approved', 'dms' ) ),
			'rows'    => array_map( static fn( object $x ): array => array( $x->region, $x->constituency, $x->station_code, $x->station, (int) $x->n ), $rows ),
		);
	}

	private function approved_by_gender( array $f ): array {
		$r      = Tables::name( Tables::REGISTRATIONS );
		$g      = Tables::name( Tables::REGIONS );
		$region = null === $f['region_id'] ? '' : $this->db->prepare( ' AND r.region_id = %d', $f['region_id'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows    = $this->db->get_results( $this->db->prepare( "SELECT g.name AS region, COALESCE(NULLIF(r.gender, ''), '') AS gender, COUNT(*) AS n FROM {$r} r INNER JOIN {$g} g ON g.id = r.region_id WHERE r.status = %s{$region} GROUP BY g.name, gender ORDER BY g.name", Status::APPROVED->value ) );
		$genders = array();
		$table   = array();
		foreach ( $rows as $x ) {
			$label                         = '' === $x->gender ? __( 'Not stated', 'dms' ) : $x->gender;
			$genders[ $label ]             = true;
			$table[ $x->region ][ $label ] = (int) $x->n;
		}
		$genders = array_keys( $genders );
		sort( $genders );
		$out = array();
		foreach ( $table as $region_name => $counts ) {
			$line = array( $region_name );
			foreach ( $genders as $label ) {
				$line[] = $counts[ $label ] ?? 0;
			}
			$line[] = array_sum( $counts );
			$out[]  = $line;
		}
		return array(
			'headers' => array_merge( array( __( 'Region', 'dms' ) ), $genders, array( __( 'Total', 'dms' ) ) ),
			'rows'    => $out,
		);
	}

	private function decisions_by_month( array $f ): array {
		$rows = array();
		foreach ( $this->analytics->monthly_decisions( $f ) as $m ) {
			$total  = $m['approved'] + $m['disapproved'];
			$rows[] = array( $m['month'], $m['approved'], $m['disapproved'], $total > 0 ? round( 100 * $m['approved'] / $total, 1 ) : '' );
		}
		return array(
			'headers' => array( __( 'Month', 'dms' ), __( 'Approved', 'dms' ), __( 'Disapproved', 'dms' ), __( 'Approval rate (%)', 'dms' ) ),
			'rows'    => $rows,
		);
	}

	private function officer_workload( array $f ): array {
		return array(
			'headers' => array( __( 'Officer', 'dms' ), __( 'Open work now', 'dms' ), __( 'Approved in period', 'dms' ), __( 'Disapproved in period', 'dms' ) ),
			'rows'    => array_map( static fn( array $o ): array => array( $o['name'], $o['active'], $o['approved'], $o['disapproved'] ), $this->analytics->officer_workload( $f ) ),
		);
	}

	private function pending_by_region( array $f ): array {
		$r      = Tables::name( Tables::REGISTRATIONS );
		$g      = Tables::name( Tables::REGIONS );
		$region = null === $f['region_id'] ? '' : $this->db->prepare( ' AND r.region_id = %d', $f['region_id'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT g.name AS region, SUM(r.status = %s) AS waiting, SUM(r.status = %s) AS assigned, SUM(r.status = %s) AS review, COUNT(*) AS total
				FROM {$r} r INNER JOIN {$g} g ON g.id = r.region_id WHERE r.status IN (%s, %s, %s){$region} GROUP BY g.name ORDER BY total DESC, g.name",
				Status::PENDING->value,
				Status::ASSIGNED->value,
				Status::UNDER_REVIEW->value,
				Status::PENDING->value,
				Status::ASSIGNED->value,
				Status::UNDER_REVIEW->value
			)
		);
		return array(
			'headers' => array( __( 'Region', 'dms' ), __( 'Waiting for an officer', 'dms' ), __( 'Assigned', 'dms' ), __( 'Under review', 'dms' ), __( 'Total', 'dms' ) ),
			'rows'    => array_map( static fn( object $x ): array => array( $x->region, (int) $x->waiting, (int) $x->assigned, (int) $x->review, (int) $x->total ), $rows ),
		);
	}
}

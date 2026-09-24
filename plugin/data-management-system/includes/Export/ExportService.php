<?php
/**
 * Registration exports (ARCH §75, MP §27, Decisions §25, §33; §78.18, §78.19).
 *
 * - Permission for the export itself: approved.export for the Approved area,
 *   registrations.export for every other area (implementation decision ID-34).
 * - Rows come from RegistrationListService, so the export contains exactly
 *   what the user may see with the filters they applied, optionally narrowed
 *   to selected IDs (bulk export).
 * - Small exports are built immediately. Above `export_sync_max_rows` a
 *   background job is queued and processed by WP-Cron, so large exports
 *   never hit PHP or browser timeouts.
 * - Files are written to a private folder (web access denied), named with a
 *   random key, downloadable only by the requesting user while they still hold
 *   the permission, and deleted after 24 hours.
 * - Every export is audited: user, area, filters, format, row count.
 *
 * @package DMS
 */

namespace DMS\Export;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\NotFoundException;
use DMS\Errors\ValidationException;
use DMS\Forms\FormDefinition;
use DMS\Registrations\RegistrationFilter;
use DMS\Registrations\RegistrationListService;
use DMS\Registrations\RegistrationRepository;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class ExportService {

	public const FORMATS        = array( 'csv', 'xlsx' );
	public const CRON_HOOK      = 'dms_process_export';
	public const STATUS_QUEUED  = 'QUEUED';
	public const STATUS_RUNNING = 'RUNNING';
	public const STATUS_DONE    = 'DONE';
	public const STATUS_FAILED  = 'FAILED';

	private const PAGE_SIZE = 500;
	private const TTL       = DAY_IN_SECONDS;

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private Settings $settings,
		private RegistrationListService $lists,
		private RegistrationRepository $registrations,
		private FormDefinition $form,
		private AuditService $audit,
		private Logger $logger,
	) {
	}

	public static function permission_for( string $area ): string {
		return 'approved' === $area ? 'approved.export' : 'registrations.export';
	}

	/**
	 * @param array<string,mixed> $filters Raw list filters (as on the list screen).
	 * @param list<int>           $ids     Optional selection (bulk export).
	 * @return array{mode:string,path?:string,filename?:string,rows?:int,job_key?:string}
	 *         mode "file": stream `path` then delete it. mode "queued": background job.
	 */
	public function request( int $user_id, string $area, string $format, array $filters, array $ids = array() ): array {
		$this->assert_allowed( $user_id, $area );
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			throw new ValidationException( array( 'format' => __( 'Choose CSV or Excel.', 'dms' ) ) );
		}
		$filters = $this->normalise_filters( $filters, $ids );
		$total   = $this->count( $user_id, $area, $filters );

		if ( $total <= $this->settings->int( 'export_sync_max_rows' ) ) {
			$path = wp_tempnam( 'dms-export' );
			$rows = $this->write( $user_id, $area, $format, $filters, $path );
			$this->audit_export( $user_id, $area, $format, $filters, $rows, 'immediate' );
			return array(
				'mode'     => 'file',
				'path'     => $path,
				'filename' => $this->filename( $area, $format ),
				'rows'     => $rows,
			);
		}

		$key = bin2hex( random_bytes( 16 ) );
		$ok  = $this->db->insert(
			$this->table(),
			array(
				'job_key'    => $key,
				'user_id'    => $user_id,
				'area'       => $area,
				'format'     => $format,
				'filters'    => wp_json_encode( $filters ),
				'status'     => self::STATUS_QUEUED,
				'total_rows' => $total,
				'created_at' => $this->clock->now_mysql(),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not queue export: ' . $this->db->last_error );
		}
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $key ) );
		return array(
			'mode'    => 'queued',
			'job_key' => $key,
			'rows'    => $total,
		);
	}

	/** Cron worker: builds the file for one queued job. Never throws. */
	public function process( string $job_key ): void {
		$job = $this->find( $job_key );
		if ( null === $job || self::STATUS_QUEUED !== $job->status ) {
			return;
		}
		$this->db->update(
			$this->table(),
			array( 'status' => self::STATUS_RUNNING ),
			array(
				'id'     => $job->id,
				'status' => self::STATUS_QUEUED,
			)
		);
		try {
			// The requester may have lost the permission since queuing.
			$this->assert_allowed( (int) $job->user_id, (string) $job->area );
			$filters = json_decode( (string) $job->filters, true );
			$path    = $this->storage_dir() . '/' . $job->job_key . '.' . $job->format;
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- background job.
			}
			$rows = $this->write( (int) $job->user_id, (string) $job->area, (string) $job->format, is_array( $filters ) ? $filters : array(), $path );
			$now  = $this->clock->now_mysql();
			$this->db->update(
				$this->table(),
				array(
					'status'       => self::STATUS_DONE,
					'total_rows'   => $rows,
					'file_path'    => $path,
					'completed_at' => $now,
					'expires_at'   => gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + self::TTL ),
				),
				array( 'id' => $job->id )
			);
			$this->audit_export( (int) $job->user_id, (string) $job->area, (string) $job->format, is_array( $filters ) ? $filters : array(), $rows, 'background' );
		} catch ( \Throwable $e ) {
			$reference = $this->logger->error(
				'Background export failed',
				array(
					'job'   => $job->job_key,
					'error' => $e->getMessage(),
				)
			);
			$this->db->update(
				$this->table(),
				array(
					'status'       => self::STATUS_FAILED,
					/* translators: %s: error reference */
					'error'        => sprintf( __( 'Export failed (reference %s).', 'dms' ), $reference ),
					'completed_at' => $this->clock->now_mysql(),
				),
				array( 'id' => $job->id )
			);
		}
	}

	/**
	 * Resolves a finished job for download by its owner.
	 *
	 * @return array{path:string,filename:string}
	 * @throws NotFoundException|AuthorizationException
	 */
	public function download( int $user_id, string $job_key ): array {
		$job = $this->find( $job_key );
		if ( null === $job || (int) $job->user_id !== $user_id || self::STATUS_DONE !== $job->status || ! is_readable( (string) $job->file_path ) || $job->expires_at <= $this->clock->now_mysql() ) {
			throw new NotFoundException( __( 'This export is not available. It may have expired.', 'dms' ) );
		}
		$this->assert_allowed( $user_id, (string) $job->area );
		return array(
			'path'     => (string) $job->file_path,
			'filename' => $this->filename( (string) $job->area, (string) $job->format ),
		);
	}

	/** @return list<object> The user's recent jobs, newest first. */
	public function jobs_for( int $user_id ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return $this->db->get_results( $this->db->prepare( "SELECT job_key, area, format, status, total_rows, error, created_at, completed_at, expires_at FROM {$this->table()} WHERE user_id = %d ORDER BY id DESC LIMIT 20", $user_id ) );
	}

	/** Deletes expired files and job rows (daily maintenance). */
	public function purge_expired(): int {
		$now = $this->clock->now_mysql();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old = $this->db->get_results( $this->db->prepare( "SELECT id, file_path FROM {$this->table()} WHERE (expires_at IS NOT NULL AND expires_at <= %s) OR (status IN (%s, %s) AND created_at <= %s)", $now, self::STATUS_FAILED, self::STATUS_QUEUED, gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() - self::TTL ) ) );
		// phpcs:enable
		foreach ( $old as $job ) {
			if ( $job->file_path && is_file( $job->file_path ) ) {
				wp_delete_file( $job->file_path );
			}
			$this->db->delete( $this->table(), array( 'id' => $job->id ) );
		}
		return count( $old );
	}

	/** @return list<string> Column headers in export order. */
	public function headers(): array {
		$headers = array(
			__( 'Registration Number', 'dms' ),
			__( 'Status', 'dms' ),
			__( 'First Name', 'dms' ),
			__( 'Middle Name', 'dms' ),
			__( 'Last Name', 'dms' ),
			__( 'Date of Birth', 'dms' ),
			__( 'Gender', 'dms' ),
			__( 'Phone', 'dms' ),
			__( 'Phone Verified', 'dms' ),
			__( 'Email', 'dms' ),
			__( 'Residential Address', 'dms' ),
			__( 'Organization', 'dms' ),
			__( 'Region', 'dms' ),
			__( 'Constituency', 'dms' ),
			__( 'Polling Station', 'dms' ),
			__( 'Assigned Officer', 'dms' ),
			__( 'Submitted (UTC)', 'dms' ),
			__( 'Review Started (UTC)', 'dms' ),
			__( 'Approved (UTC)', 'dms' ),
			__( 'Disapproved (UTC)', 'dms' ),
			__( 'Disapproval Reason', 'dms' ),
		);
		foreach ( $this->custom_fields() as $field ) {
			$headers[] = (string) $field['label'];
		}
		return $headers;
	}

	private function assert_allowed( int $user_id, string $area ): void {
		if ( ! isset( RegistrationFilter::AREAS[ $area ] ) || ! user_can( $user_id, self::permission_for( $area ) ) ) {
			throw new AuthorizationException();
		}
	}

	/** @return array<string,mixed> */
	private function normalise_filters( array $filters, array $ids ): array {
		$filters = array_intersect_key( $filters, array_flip( array( 'status', 'search', 'region_id', 'constituency_id', 'polling_station_id', 'officer_id', 'unassigned', 'submitted_from', 'submitted_to', 'reviewed_from', 'reviewed_to', 'approved_from', 'approved_to', 'disapproved_from', 'disapproved_to', 'orderby', 'order' ) ) );
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() !== $ids ) {
			$filters['ids'] = $ids;
		}
		return $filters;
	}

	private function count( int $user_id, string $area, array $filters ): int {
		$filter = $this->filter( $user_id, $area, $filters, 1 );
		return (int) $this->registrations->search( $filter )['total'];
	}

	private function filter( int $user_id, string $area, array $filters, int $page ): RegistrationFilter {
		$filter           = $this->lists->filter_for( $user_id, $area, $filters + array( 'per_page' => RegistrationFilter::MAX_PER_PAGE ) );
		$filter->per_page = self::PAGE_SIZE;
		$filter->page     = $page;
		$filter->ids      = array_map( 'intval', (array) ( $filters['ids'] ?? array() ) );
		return $filter;
	}

	/** Streams every matching row to $path, page by page. @return int rows written */
	private function write( int $user_id, string $area, string $format, array $filters, string $path ): int {
		$writer = 'xlsx' === $format ? new XlsxWriter( $path ) : new CsvWriter( $path );
		$writer->add_sheet( __( 'Registrations', 'dms' ), $this->headers() );
		$custom = $this->custom_fields();
		$rows   = 0;
		for ( $page = 1; ; $page++ ) {
			$result = $this->registrations->search( $this->filter( $user_id, $area, $filters, $page ) );
			foreach ( $result['items'] as $r ) {
				$writer->add_row( $this->row( $r, $custom ) );
				++$rows;
			}
			if ( count( $result['items'] ) < self::PAGE_SIZE ) {
				break;
			}
		}
		$writer->finish();
		return $rows;
	}

	/** @param list<array<string,mixed>> $custom */
	private function row( object $r, array $custom ): array {
		$extra = json_decode( (string) $r->extra_fields, true );
		$row   = array(
			$r->registration_number,
			$r->status,
			$r->first_name,
			$r->middle_name,
			$r->last_name,
			$r->date_of_birth,
			$r->gender,
			$r->phone_normalized,
			(int) $r->phone_verified ? __( 'Yes', 'dms' ) : __( 'No', 'dms' ),
			$r->email,
			$r->address,
			$r->organization,
			$r->region_name,
			$r->constituency_name,
			$r->polling_station_name,
			$r->officer_name,
			$r->submitted_at,
			$r->reviewed_at,
			$r->approved_at,
			$r->disapproved_at,
			$r->disapproval_reason,
		);
		foreach ( $custom as $field ) {
			$row[] = is_array( $extra ) ? ( $extra[ substr( (string) $field['key'], 7 ) ] ?? '' ) : '';
		}
		return $row;
	}

	/** @return list<array<string,mixed>> */
	private function custom_fields(): array {
		return array_values( array_filter( $this->form->fields(), static fn( array $f ): bool => $f['custom'] ) );
	}

	private function audit_export( int $user_id, string $area, string $format, array $filters, int $rows, string $mode ): void {
		$this->audit->record(
			AuditAction::EXPORTED,
			array(
				'object_type' => 'export',
				'user_id'     => $user_id,
				'metadata'    => array(
					'area'    => $area,
					'format'  => $format,
					'filters' => $filters,
					'rows'    => $rows,
					'mode'    => $mode,
				),
			)
		);
	}

	private function filename( string $area, string $format ): string {
		return sprintf( 'registrations-%s-%s.%s', $area, gmdate( 'Ymd-His' ), $format );
	}

	private function find( string $job_key ): ?object {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $job_key ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE job_key = %s", $job_key ) );
	}

	private function storage_dir(): string {
		return \DMS\Support\PrivateStorage::dir( 'exports' );
	}

	private function table(): string {
		return Tables::name( Tables::EXPORT_JOBS );
	}
}

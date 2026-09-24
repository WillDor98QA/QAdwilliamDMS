<?php
/**
 * Controlled electoral master-data import and dependency-aware rollback
 * (ARCH §39–§41, §48–§70; MP §15–§19; Decisions §27–§32).
 *
 *   Download template → Upload → Validate → Preview → Confirm → Import → Summary
 *
 * - upload(): checks type, size and zip structure, stores the file privately
 *   under a random name, records its SHA-256, creates a batch (UPLOADED), then
 *   validates it (VALIDATED, or FAILED with a row-by-row error report).
 * - confirm(): re-validates the stored file against the *current* database,
 *   then applies every change in ONE transaction in dependency order,
 *   recording before/after values for each created or updated record
 *   (COMPLETED). Any error rolls everything back (FAILED). Nothing is ever
 *   partially imported.
 * - rollback_impact() / rollback(): batch-specific and dependency-aware
 *   (ARCH §59–§61). Created records are removed and updated records restored,
 *   unless something depends on them. In that case the rollback is blocked
 *   and every blocker is explained (ROLLBACK_BLOCKED).
 * - Every step is audited (ARCH §65). A MySQL named lock prevents two imports
 *   or rollbacks running at once.
 *
 * @package DMS
 */

namespace DMS\Imports;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Tables;
use DMS\Database\Transaction;
use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;
use DMS\Errors\ConflictException;
use DMS\Errors\ValidationException;
use DMS\Export\XlsxWriter;
use DMS\Support\Authorizer;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\PrivateStorage;
use DMS\Support\RequestContext;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class ImportService {

	private const LOCK = 'dms_electoral_import';

	/** MIME types a genuine .xlsx may be reported as. */
	private const ALLOWED_MIME = array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' );

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private Settings $settings,
		private ElectoralRepository $electoral,
		private ImportBatchRepository $batches,
		private ImportValidator $validator,
		private AuditService $audit,
		private Authorizer $authorizer,
		private Logger $logger,
		private RequestContext $context,
	) {
	}

	// ---------------------------------------------------------------- template

	/**
	 * Writes the official template (ARCH §49), or with $with_data the current
	 * electoral data in the same format for edit-and-re-import (ARCH §41).
	 *
	 * @return string Path to a temporary .xlsx file (caller streams then deletes).
	 */
	public function write_template( bool $with_data = false ): string {
		$this->authorizer->require( 'imports.view' );
		$temp = wp_tempnam( 'dms-template' );
		$path = $temp . '.xlsx';
		wp_delete_file( $temp );
		$writer = new XlsxWriter( $path );

		$writer->add_sheet( 'Instructions', array( __( 'How to complete this workbook', 'dms' ) ), array( 'width' => 110 ) );
		foreach ( $this->instructions() as $line ) {
			$writer->add_row( array( $line ) );
		}
		foreach ( WorkbookSpec::sheets() as $level_value => $spec ) {
			$level = ElectoralLevel::from( $level_value );
			$writer->add_sheet(
				$spec['sheet'],
				WorkbookSpec::columns( $level ),
				array(
					'width'        => 32,
					'text_columns' => null === $spec['parent'] ? array( 0 ) : array( 0, 2 ),
				)
			);
			if ( $with_data ) {
				foreach ( $this->current_rows( $level ) as $row ) {
					$writer->add_row( $row );
				}
			}
		}
		$writer->finish();
		return $path;
	}

	/** @return list<string> */
	private function instructions(): array {
		return array(
			__( '1. Fill in the Regions, Constituencies and Polling Stations sheets. Do not rename the sheets or the column headings in row 1.', 'dms' ),
			__( '2. Codes are the permanent identity of each record (for example GAR, GAR-001, PS-001). Never reuse a code for a different place. Letters, digits and . _ - / are allowed.', 'dms' ),
			__( '3. region_code on the Constituencies sheet must be a Region code from this file or one already in the system. constituency_code on the Polling Stations sheet works the same way.', 'dms' ),
			__( '4. To correct a name, keep the same code and type the new name. Re-importing updates the existing record; it never creates a duplicate.', 'dms' ),
			__( '5. Records that are not in the file are left unchanged. The import never deletes anything.', 'dms' ),
			__( '6. Keep the code columns formatted as Text so codes such as 001 keep their leading zeros.', 'dms' ),
			__( '7. You will see a preview before anything is saved. If any row has an error, nothing is imported until the file is corrected.', 'dms' ),
			'',
			__( 'Example — Regions: GAR | Greater Accra', 'dms' ),
			__( 'Example — Constituencies: GAR-001 | Ablekuma Central | GAR', 'dms' ),
			__( 'Example — Polling Stations: PS-001 | Station 001 | GAR-001', 'dms' ),
		);
	}

	/** @return \Generator<list<string>> */
	private function current_rows( ElectoralLevel $level ): \Generator {
		$table  = $level->table();
		$parent = $level->parent();
		$last   = 0;
		do {
			if ( null === $parent ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from ElectoralLevel.
				$rows = $this->db->get_results( $this->db->prepare( "SELECT id, code, name FROM {$table} WHERE id > %d ORDER BY id LIMIT 1000", $last ) );
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $this->db->get_results( $this->db->prepare( "SELECT c.id, c.code, c.name, p.code AS parent_code FROM {$table} c INNER JOIN {$parent->table()} p ON p.id = c.{$level->parent_column()} WHERE c.id > %d ORDER BY c.id LIMIT 1000", $last ) );
			}
			foreach ( $rows as $row ) {
				$last = (int) $row->id;
				yield null === $parent ? array( $row->code, $row->name ) : array( $row->code, $row->name, $row->parent_code );
			}
			$fetched = count( $rows );
		} while ( 1000 === $fetched );
	}

	// ------------------------------------------------------ upload / validate

	/**
	 * @return int Batch ID (status VALIDATED or FAILED).
	 * @throws ValidationException When the upload itself is unacceptable (no batch is created).
	 */
	public function upload( string $tmp_path, string $original_name, int $size, int $upload_error = UPLOAD_ERR_OK ): int {
		$this->authorizer->require( 'imports.upload', 'imports.validate' );

		$max = $this->settings->int( 'import_max_file_bytes' );
		if ( UPLOAD_ERR_OK !== $upload_error || ! is_readable( $tmp_path ) ) {
			throw new ValidationException( array( 'file' => __( 'The file could not be uploaded. Please try again.', 'dms' ) ) );
		}
		if ( $size <= 0 || $size > $max || filesize( $tmp_path ) > $max ) {
			/* translators: %s: maximum size */
			throw new ValidationException( array( 'file' => sprintf( __( 'The file is empty or larger than the %s limit.', 'dms' ), size_format( $max ) ) ) );
		}
		if ( 'xlsx' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			throw new ValidationException( array( 'file' => __( 'Upload the official Excel workbook (.xlsx).', 'dms' ) ) );
		}
		$mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $tmp_path ) : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 4-byte signature check of a local temp file.
		$magic = (string) file_get_contents( $tmp_path, false, null, 0, 4 );
		if ( "PK\x03\x04" !== $magic || ( '' !== $mime && ! in_array( $mime, self::ALLOWED_MIME, true ) ) ) {
			throw new ValidationException( array( 'file' => __( 'The file is not a valid Excel (.xlsx) workbook.', 'dms' ) ) );
		}

		$stored = PrivateStorage::dir( 'imports' ) . '/' . bin2hex( random_bytes( 16 ) ) . '.xlsx';
		if ( ! copy( $tmp_path, $stored ) ) {
			throw new \RuntimeException( 'Could not store the uploaded workbook.' );
		}
		$hash     = (string) hash_file( 'sha256', $stored );
		$filename = sanitize_file_name( $original_name );
		$batch_id = $this->batches->create( $filename, $hash, $this->context->user_id(), ImportStatus::SCOPE_ELECTORAL_WORKBOOK, $stored );
		$this->audit_batch(
			AuditAction::IMPORT_STARTED,
			$batch_id,
			array(
				'filename'  => $filename,
				'file_hash' => $hash,
				'bytes'     => $size,
			)
		);

		$this->validate( $batch_id );
		return $batch_id;
	}

	/** Validates the stored file and saves the preview (VALIDATED / FAILED). */
	public function validate( int $batch_id ): ImportPlan {
		$this->authorizer->require( 'imports.validate' );
		$batch = $this->batches->get( $batch_id );
		$this->batches->update_status( $batch_id, ImportStatus::VALIDATING );
		try {
			$plan = $this->validator->plan( new XlsxReader( (string) $batch->stored_file ) );
		} catch ( ImportFileException $e ) {
			$this->batches->update_status( $batch_id, ImportStatus::FAILED, array( 'error_count' => 1 ), array( 'file_error' => $e->getMessage() ) );
			$this->audit_batch(
				AuditAction::IMPORT_FAILED,
				$batch_id,
				array(
					'stage'  => 'validation',
					'reason' => $e->getMessage(),
				)
			);
			throw $e;
		}

		$summary                  = $plan->summary();
		$summary['earlier_batch'] = $this->earlier_batch_with_hash( (string) $batch->file_hash, $batch_id );
		$status                   = $plan->has_errors() ? ImportStatus::FAILED : ImportStatus::VALIDATED;
		$this->batches->update_status( $batch_id, $status, $this->counts( $plan ), $summary );
		$this->audit_batch( $plan->has_errors() ? AuditAction::IMPORT_FAILED : AuditAction::IMPORT_VALIDATED, $batch_id, array( 'totals' => $summary['totals'] ) );
		return $plan;
	}

	/**
	 * Deletes uploaded workbooks of finished imports older than the retention
	 * setting (R-07). History, item changes and rollback do not need the file.
	 * Runs from the daily cron; no permission check (system job).
	 *
	 * @return int Files removed.
	 */
	public function purge_old_files(): int {
		$days = $this->settings->int( 'import_file_retention_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff = $this->clock->now()->modify( "-{$days} days" )->format( 'Y-m-d H:i:s' );
		$table  = Tables::name( Tables::IMPORT_BATCHES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows    = $this->db->get_results( $this->db->prepare( "SELECT id, stored_file FROM {$table} WHERE stored_file IS NOT NULL AND status IN (%s, %s, %s, %s) AND COALESCE(rolled_back_at, completed_at, created_at) < %s LIMIT 500", ImportStatus::FAILED, ImportStatus::COMPLETED, ImportStatus::ROLLED_BACK, ImportStatus::ROLLBACK_BLOCKED, $cutoff ) );
		$removed = 0;
		$base    = realpath( PrivateStorage::dir( 'imports' ) );
		foreach ( $rows as $row ) {
			$path = realpath( (string) $row->stored_file );
			// Only ever delete inside the private imports folder.
			if ( false !== $path && false !== $base && str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) ) {
				wp_delete_file( $path );
				++$removed;
			}
			$this->db->update( $table, array( 'stored_file' => null ), array( 'id' => (int) $row->id ) );
		}
		if ( $removed > 0 ) {
			$this->logger->info( 'Old import files removed', array( 'files' => $removed ) );
		}
		return $removed;
	}

	// ----------------------------------------------------------------- confirm

	/**
	 * Applies a validated batch in one transaction (ARCH §64).
	 *
	 * @throws ConflictException  Not in VALIDATED state, or another import/rollback is running.
	 * @throws ValidationException When the data changed since the preview and the file no longer validates.
	 */
	public function confirm( int $batch_id ): void {
		$this->authorizer->require( 'imports.confirm' );
		$this->with_lock(
			function () use ( $batch_id ): void {
				$batch = $this->batches->get( $batch_id );
				if ( ImportStatus::VALIDATED !== $batch->status ) {
					throw new ConflictException( __( 'Only a validated import that has not been applied yet can be confirmed.', 'dms' ), 'import_state' );
				}
				$this->audit_batch( AuditAction::IMPORT_CONFIRMED, $batch_id );

				// The database may have changed since the preview: plan again from the stored file.
				$plan = $this->validator->plan( new XlsxReader( (string) $batch->stored_file ) );
				if ( $plan->has_errors() ) {
					$this->batches->update_status( $batch_id, ImportStatus::FAILED, $this->counts( $plan ), $plan->summary() );
					$this->audit_batch(
						AuditAction::IMPORT_FAILED,
						$batch_id,
						array(
							'stage'  => 'confirm',
							'totals' => $plan->summary()['totals'],
						)
					);
					throw new ValidationException( array( 'file' => __( 'The data changed since the preview and the file no longer validates. Review the errors and upload a corrected file.', 'dms' ) ) );
				}

				$started = microtime( true );
				if ( function_exists( 'set_time_limit' ) ) {
					set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- large imports.
				}
				try {
					Transaction::run( $this->db, fn() => $this->apply( $batch_id, $plan ) );
				} catch ( \Throwable $e ) {
					$reference = $this->logger->error(
						'Import failed during commit',
						array(
							'batch' => $batch_id,
							'error' => $e->getMessage(),
						)
					);
					$this->batches->update_status( $batch_id, ImportStatus::FAILED, array(), array_merge( $plan->summary(), array( 'commit_error_reference' => $reference ) ) );
					$this->audit_batch(
						AuditAction::IMPORT_FAILED,
						$batch_id,
						array(
							'stage'     => 'commit',
							'reference' => $reference,
						)
					);
					/* translators: %s: error reference */
					throw new ConflictException( sprintf( __( 'The import could not be completed and nothing was changed. Reference: %s', 'dms' ), $reference ), 'import_failed' );
				}
				$this->electoral->invalidate_cache();

				$summary                     = $plan->summary();
				$summary['duration_seconds'] = round( microtime( true ) - $started, 2 );
				$summary['earlier_batch']    = $this->earlier_batch_with_hash( (string) $batch->file_hash, $batch_id );
				$this->batches->update_status( $batch_id, ImportStatus::COMPLETED, $this->counts( $plan ), $summary );
				$this->audit_batch(
					AuditAction::IMPORT_COMPLETED,
					$batch_id,
					array(
						'totals'           => $summary['totals'],
						'duration_seconds' => $summary['duration_seconds'],
					)
				);
			}
		);
	}

	private function apply( int $batch_id, ImportPlan $plan ): void {
		$ids   = array(); // level => code => id, for codes created in this batch.
		$items = array();
		foreach ( array_keys( WorkbookSpec::sheets() ) as $level_value ) {
			$level      = ElectoralLevel::from( $level_value );
			$parent     = $level->parent();
			$codes      = array_column( array_filter( $plan->changes, static fn( array $c ): bool => $c['level'] === $level_value && null !== $level->parent() ), 'parent' );
			$db_parents = null !== $parent ? $this->electoral->find_by_codes( $parent, array_values( array_unique( $codes ) ) ) : array();

			foreach ( $plan->changes as $change ) {
				if ( $change['level'] !== $level_value || ImportStatus::ACTION_UNCHANGED === $change['action'] ) {
					continue;
				}
				$parent_id = null;
				if ( null !== $parent ) {
					$parent_id = $ids[ $parent->value ][ $change['parent'] ] ?? ( isset( $db_parents[ $change['parent'] ] ) ? (int) $db_parents[ $change['parent'] ]->id : null );
					if ( null === $parent_id ) {
						throw new \RuntimeException( "Unresolved parent {$change['parent']} for {$change['code']}" );
					}
				}

				if ( ImportStatus::ACTION_CREATE === $change['action'] ) {
					$id                                     = $this->electoral->insert( $level, $change['code'], $change['name'], $parent_id, false );
					$ids[ $level_value ][ $change['code'] ] = $id;
					$items[]                                = array(
						'entity_type' => $level_value,
						'record_code' => $change['code'],
						'record_id'   => $id,
						'source_row'  => $change['row'],
						'action'      => ImportStatus::ACTION_CREATE,
						'new_values'  => $this->values( $level, $change['name'], $parent_id, 'ACTIVE' ),
					);
					continue;
				}

				$current  = $change['current'];
				$previous = $this->values( $level, (string) $current->name, null !== $parent ? (int) $current->{$level->parent_column()} : null, (string) $current->status );
				$new      = $this->values( $level, $change['name'], $parent_id, 'ACTIVE' );
				$this->electoral->update( $level, (int) $current->id, $new, false );
				$ids[ $level_value ][ $change['code'] ] = (int) $current->id;
				$items[]                                = array(
					'entity_type'     => $level_value,
					'record_code'     => $change['code'],
					'record_id'       => (int) $current->id,
					'source_row'      => $change['row'],
					'action'          => ImportStatus::ACTION_UPDATE,
					'previous_values' => $previous,
					'new_values'      => $new,
				);
			}
		}
		$this->batches->add_items( $batch_id, $items );
	}

	/** @return array<string,mixed> Columns ElectoralRepository::update() accepts. */
	private function values( ElectoralLevel $level, string $name, ?int $parent_id, string $status ): array {
		$values = array(
			'name'   => $name,
			'status' => $status,
		);
		if ( null !== $level->parent_column() ) {
			$values[ $level->parent_column() ] = $parent_id;
		}
		return $values;
	}

	// ---------------------------------------------------------------- rollback

	/**
	 * What a rollback would do, and whether anything prevents it (ARCH §60 step 1–3).
	 *
	 * @return array{safe:bool,remove:int,restore:int,blockers:list<array{level:string,code:string,reason:string}>}
	 */
	public function rollback_impact( int $batch_id ): array {
		$this->authorizer->require( 'imports.rollback' );
		$batch = $this->batches->get( $batch_id );
		if ( ! in_array( $batch->status, array( ImportStatus::COMPLETED, ImportStatus::ROLLBACK_BLOCKED ), true ) ) {
			throw new ConflictException( __( 'Only a completed import can be rolled back.', 'dms' ), 'import_state' );
		}

		$items    = $this->batches->items( $batch_id, array( ImportStatus::ACTION_CREATE, ImportStatus::ACTION_UPDATE ) );
		$created  = array();
		$blockers = array();
		foreach ( $items as $item ) {
			if ( ImportStatus::ACTION_CREATE === $item->action ) {
				$created[ $item->entity_type ][ (int) $item->record_id ] = true;
			}
		}
		$later = $this->later_touches( $batch_id );

		foreach ( $items as $item ) {
			$level  = ElectoralLevel::from( $item->entity_type );
			$id     = (int) $item->record_id;
			$record = $this->electoral->find( $level, $id );
			$block  = static function ( string $reason ) use ( &$blockers, $level, $item ): void {
				$blockers[] = array(
					'level'  => $level->value,
					'code'   => (string) $item->record_code,
					'reason' => $reason,
				);
			};

			if ( isset( $later[ $level->value ][ $id ] ) ) {
				/* translators: %s: later import reference */
				$block( sprintf( __( 'Changed again by the later import %s. Roll that import back first.', 'dms' ), $later[ $level->value ][ $id ] ) );
				continue;
			}
			if ( null === $record ) {
				$block( __( 'The record no longer exists.', 'dms' ) );
				continue;
			}
			if ( ! $this->matches( $record, (array) $item->new_values ) ) {
				$block( __( 'The record was changed after this import.', 'dms' ) );
				continue;
			}

			$registrations = $this->count_where( Tables::name( Tables::REGISTRATIONS ), $level->registration_column(), $id );
			if ( ImportStatus::ACTION_CREATE === $item->action ) {
				if ( $registrations > 0 ) {
					/* translators: %d: number of registrations */
					$block( sprintf( _n( 'Used by %d registration.', 'Used by %d registrations.', $registrations, 'dms' ), $registrations ) );
				}
				$child = $level->child();
				if ( null !== $child ) {
					$foreign = $this->children_not_created_by( $child, $id, $created[ $child->value ] ?? array() );
					if ( $foreign > 0 ) {
						/* translators: 1: number, 2: child label */
						$block( sprintf( __( 'Has %1$d %2$s record(s) that were not created by this import.', 'dms' ), $foreign, strtolower( $child->label() ) ) );
					}
				}
				if ( ElectoralLevel::REGION === $level ) {
					$officers = $this->count_where( Tables::name( Tables::OFFICER_REGIONS ), 'region_id', $id );
					if ( $officers > 0 ) {
						/* translators: %d: number of officers */
						$block( sprintf( _n( 'Linked to %d officer.', 'Linked to %d officers.', $officers, 'dms' ), $officers ) );
					}
				}
				continue;
			}

			// UPDATE: restoring a different parent would break registrations' chain.
			$previous   = (array) $item->previous_values;
			$parent_col = $level->parent_column();
			if ( null !== $parent_col && (int) ( $previous[ $parent_col ] ?? 0 ) !== (int) $record->{$parent_col} && $registrations > 0 ) {
				/* translators: %d: number of registrations */
				$block( sprintf( _n( 'Restoring the previous parent would break %d registration.', 'Restoring the previous parent would break %d registrations.', $registrations, 'dms' ), $registrations ) );
			}
			if ( null !== $parent_col && null === $this->electoral->find( $level->parent(), (int) ( $previous[ $parent_col ] ?? 0 ) ) ) {
				$block( __( 'The previous parent record no longer exists.', 'dms' ) );
			}
		}

		$remove = count( array_filter( $items, static fn( object $i ): bool => ImportStatus::ACTION_CREATE === $i->action ) );
		return array(
			'safe'     => array() === $blockers,
			'remove'   => $remove,
			'restore'  => count( $items ) - $remove,
			'blockers' => $blockers,
		);
	}

	/**
	 * Rolls back one completed batch (ARCH §60 step 4). Blocked rollbacks
	 * change nothing and are recorded.
	 *
	 * @return array{safe:bool,remove:int,restore:int,blockers:list<array{level:string,code:string,reason:string}>}
	 */
	public function rollback( int $batch_id, bool $confirmed ): array {
		$this->authorizer->require( 'imports.rollback' );
		if ( ! $confirmed ) {
			throw new ValidationException( array( 'confirm' => __( 'Please confirm the rollback.', 'dms' ) ) );
		}
		return $this->with_lock(
			function () use ( $batch_id ): array {
				$this->audit_batch( AuditAction::IMPORT_ROLLBACK_REQUESTED, $batch_id );
				$impact = $this->rollback_impact( $batch_id );
				if ( ! $impact['safe'] ) {
					$this->batches->update_status( $batch_id, ImportStatus::ROLLBACK_BLOCKED );
					$this->audit_batch( AuditAction::IMPORT_ROLLBACK_BLOCKED, $batch_id, array( 'blockers' => array_slice( $impact['blockers'], 0, 100 ) ) );
					return $impact;
				}

				Transaction::run(
					$this->db,
					function () use ( $batch_id ): void {
						$items = $this->batches->items( $batch_id, array( ImportStatus::ACTION_CREATE, ImportStatus::ACTION_UPDATE ) );
						// Children before parents; restores before deletions of their new parents.
						foreach ( array_reverse( array_keys( WorkbookSpec::sheets() ) ) as $level_value ) {
							$level = ElectoralLevel::from( $level_value );
							foreach ( $items as $item ) {
								if ( $item->entity_type === $level_value && ImportStatus::ACTION_UPDATE === $item->action ) {
									$this->electoral->update( $level, (int) $item->record_id, (array) $item->previous_values, false );
								}
							}
							foreach ( $items as $item ) {
								if ( $item->entity_type === $level_value && ImportStatus::ACTION_CREATE === $item->action ) {
									$this->electoral->delete( $level, (int) $item->record_id );
								}
							}
						}
						$this->batches->mark_rolled_back( $batch_id, $this->context->user_id() );
					}
				);
				$this->electoral->invalidate_cache();
				$this->audit_batch(
					AuditAction::IMPORT_ROLLED_BACK,
					$batch_id,
					array(
						'removed'  => $impact['remove'],
						'restored' => $impact['restore'],
					)
				);
				return $impact;
			}
		);
	}

	// ----------------------------------------------------------------- helpers

	/** @param array<string,mixed> $values */
	private function matches( object $record, array $values ): bool {
		foreach ( $values as $column => $value ) {
			if ( (string) $record->{$column} !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Records changed by COMPLETED batches newer than this one.
	 *
	 * @return array<string,array<int,string>> level => record_id => import reference
	 */
	private function later_touches( int $batch_id ): array {
		$items   = Tables::name( Tables::IMPORT_ITEMS );
		$batches = Tables::name( Tables::IMPORT_BATCHES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Tables.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT i.entity_type, i.record_id, b.import_reference FROM {$items} i INNER JOIN {$batches} b ON b.id = i.import_batch_id
				WHERE i.import_batch_id > %d AND b.status IN (%s, %s) AND i.action IN (%s, %s)",
				$batch_id,
				ImportStatus::COMPLETED,
				ImportStatus::ROLLBACK_BLOCKED,
				ImportStatus::ACTION_CREATE,
				ImportStatus::ACTION_UPDATE
			)
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row->entity_type ][ (int) $row->record_id ] = $row->import_reference;
		}
		return $out;
	}

	/** @param array<int,true> $created_ids */
	private function children_not_created_by( ElectoralLevel $child, int $parent_id, array $created_ids ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT id FROM {$child->table()} WHERE {$child->parent_column()} = %d", $parent_id ) ) );
		return count( array_filter( $ids, static fn( int $id ): bool => ! isset( $created_ids[ $id ] ) ) );
	}

	private function count_where( string $table, string $column, int $id ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers are internal constants.
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %d", $id ) );
	}

	private function earlier_batch_with_hash( string $hash, int $batch_id ): ?string {
		$table = Tables::name( Tables::IMPORT_BATCHES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ref = $this->db->get_var( $this->db->prepare( "SELECT import_reference FROM {$table} WHERE file_hash = %s AND id <> %d AND status = %s ORDER BY id DESC LIMIT 1", $hash, $batch_id, ImportStatus::COMPLETED ) );
		return $ref ? (string) $ref : null;
	}

	/** @return array<string,int> */
	private function counts( ImportPlan $plan ): array {
		$totals = $plan->summary()['totals'];
		return array(
			'total_rows'      => $totals['rows'],
			'created_count'   => $totals['create'],
			'updated_count'   => $totals['update'],
			'unchanged_count' => $totals['unchanged'],
			'error_count'     => $totals['errors'],
		);
	}

	/** @param array<string,mixed> $metadata */
	private function audit_batch( string $action, int $batch_id, array $metadata = array() ): void {
		$batch = $this->batches->find( $batch_id );
		$this->audit->record(
			$action,
			array(
				'object_type' => 'import_batch',
				'object_id'   => $batch_id,
				'metadata'    => array_merge( array( 'import_reference' => $batch ? $batch->import_reference : null ), $metadata ),
			)
		);
	}

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	private function with_lock( callable $work ): mixed {
		$name = self::LOCK . '_' . $this->db->prefix;
		if ( 1 !== (int) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
			throw new ConflictException( __( 'Another import or rollback is running. Please wait for it to finish.', 'dms' ), 'import_busy' );
		}
		try {
			return $work();
		} finally {
			$this->db->query( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}
}

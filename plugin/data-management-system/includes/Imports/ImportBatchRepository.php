<?php
/**
 * Import batches and their record-level items (ARCH §56, §57, Decisions §31).
 *
 * Items are inserted with multi-row INSERTs in bounded chunks, so a 33,000-row
 * workbook does not issue 33,000 queries (MP §32).
 *
 * @package DMS
 */

namespace DMS\Imports;

use DMS\Database\SequenceGenerator;
use DMS\Database\Tables;
use DMS\Errors\NotFoundException;
use DMS\Support\Clock;

defined( 'ABSPATH' ) || exit;

class ImportBatchRepository {

	private const ITEM_CHUNK = 250;

	private const COUNT_COLUMNS = array( 'total_rows', 'created_count', 'updated_count', 'unchanged_count', 'error_count' );

	public function __construct( private \wpdb $db, private Clock $clock, private SequenceGenerator $sequences ) {
	}

	private function batches_table(): string {
		return Tables::name( Tables::IMPORT_BATCHES );
	}

	private function items_table(): string {
		return Tables::name( Tables::IMPORT_ITEMS );
	}

	/** @return int New batch ID, status UPLOADED, with a unique IMPORT-YYYY-NNNNN reference. */
	public function create( string $filename, string $file_hash, int $uploaded_by, string $scope = ImportStatus::SCOPE_ELECTORAL_WORKBOOK, ?string $stored_file = null ): int {
		$ok = $this->db->insert(
			$this->batches_table(),
			array(
				'import_reference' => $this->sequences->next_import_reference(),
				'entity_type'      => $scope,
				'filename'         => $filename,
				'file_hash'        => $file_hash,
				'stored_file'      => $stored_file,
				'status'           => ImportStatus::UPLOADED,
				'uploaded_by'      => $uploaded_by,
				'created_at'       => $this->clock->now_mysql(),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not create import batch: ' . $this->db->last_error );
		}
		return (int) $this->db->insert_id;
	}

	public function find( int $id ): ?object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->batches_table()} WHERE id = %d", $id ) );
	}

	/** @throws NotFoundException */
	public function get( int $id ): object {
		$batch = $this->find( $id );
		if ( null === $batch ) {
			throw new NotFoundException();
		}
		return $batch;
	}

	/**
	 * @param array<string,int>  $counts  Any of total_rows, created_count, updated_count, unchanged_count, error_count.
	 * @param array<mixed>|null  $summary Preview / error report, stored as JSON.
	 */
	public function update_status( int $id, string $status, array $counts = array(), ?array $summary = null ): void {
		if ( ! in_array( $status, ImportStatus::ALL, true ) ) {
			throw new \InvalidArgumentException( "Unknown import status {$status}" );
		}
		$data = array_map( 'intval', array_intersect_key( $counts, array_flip( self::COUNT_COLUMNS ) ) );

		$data['status'] = $status;
		if ( null !== $summary ) {
			$data['summary'] = wp_json_encode( $summary );
		}
		if ( in_array( $status, array( ImportStatus::COMPLETED, ImportStatus::FAILED ), true ) ) {
			$data['completed_at'] = $this->clock->now_mysql();
		}
		if ( false === $this->db->update( $this->batches_table(), $data, array( 'id' => $id ) ) ) {
			throw new \RuntimeException( 'Could not update import batch: ' . $this->db->last_error );
		}
	}

	public function mark_rolled_back( int $id, int $user_id ): void {
		$ok = $this->db->update(
			$this->batches_table(),
			array(
				'status'         => ImportStatus::ROLLED_BACK,
				'rolled_back_at' => $this->clock->now_mysql(),
				'rolled_back_by' => $user_id,
			),
			array( 'id' => $id )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not update import batch: ' . $this->db->last_error );
		}
	}

	/**
	 * @param list<array{entity_type:string,record_code:string,action:string,record_id?:int|null,source_row?:int|null,previous_values?:array<mixed>|null,new_values?:array<mixed>|null,message?:string|null}> $items
	 */
	public function add_items( int $batch_id, array $items ): void {
		$now = $this->clock->now_mysql();
		foreach ( array_chunk( $items, self::ITEM_CHUNK ) as $chunk ) {
			$rows   = array();
			$values = array();
			foreach ( $chunk as $item ) {
				if ( ! in_array( $item['action'], ImportStatus::ACTIONS, true ) ) {
					throw new \InvalidArgumentException( "Unknown import item action {$item['action']}" );
				}
				$rows[] = '(%d, %s, ' . ( isset( $item['record_id'] ) ? '%d' : 'NULL' ) . ', %s, ' . ( isset( $item['source_row'] ) ? '%d' : 'NULL' ) . ', %s, %s, %s, %s, %s)';
				array_push( $values, $batch_id, $item['entity_type'] );
				if ( isset( $item['record_id'] ) ) {
					$values[] = $item['record_id'];
				}
				$values[] = $item['record_code'];
				if ( isset( $item['source_row'] ) ) {
					$values[] = $item['source_row'];
				}
				array_push(
					$values,
					$item['action'],
					isset( $item['previous_values'] ) ? (string) wp_json_encode( $item['previous_values'] ) : '',
					isset( $item['new_values'] ) ? (string) wp_json_encode( $item['new_values'] ) : '',
					(string) ( $item['message'] ?? '' ),
					$now
				);
			}
			$sql = "INSERT INTO {$this->items_table()} (import_batch_id, entity_type, record_id, record_code, source_row, action, previous_values, new_values, message, created_at) VALUES " . implode( ', ', $rows );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments; values are placeholders.
			if ( false === $this->db->query( $this->db->prepare( $sql, ...$values ) ) ) {
				throw new \RuntimeException( 'Could not record import items: ' . $this->db->last_error );
			}
		}
	}

	/**
	 * @param list<string> $actions Empty for all.
	 * @return list<object> Items with previous/new values decoded.
	 */
	public function items( int $batch_id, array $actions = array() ): array {
		$sql    = "SELECT * FROM {$this->items_table()} WHERE import_batch_id = %d";
		$params = array( $batch_id );
		if ( array() !== $actions ) {
			$sql   .= ' AND action IN (' . implode( ',', array_fill( 0, count( $actions ), '%s' ) ) . ')';
			$params = array_merge( $params, $actions );
		}
		$sql .= ' ORDER BY id ASC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results( $this->db->prepare( $sql, ...$params ) );
		foreach ( $rows as $row ) {
			$row->previous_values = '' === (string) $row->previous_values ? null : json_decode( $row->previous_values, true );
			$row->new_values      = '' === (string) $row->new_values ? null : json_decode( $row->new_values, true );
		}
		return $rows;
	}

	/** @return array{items:list<object>,total:int} Newest first. */
	public function history( int $page = 1, int $per_page = 20 ): array {
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $this->db->get_results( $this->db->prepare( "SELECT id, import_reference, entity_type, filename, total_rows, created_count, updated_count, unchanged_count, error_count, status, uploaded_by, created_at, completed_at, rolled_back_at FROM {$this->batches_table()} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		$total = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->batches_table()}" );
		// phpcs:enable
		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}

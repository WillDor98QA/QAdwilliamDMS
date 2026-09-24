<?php
/**
 * REQ-IMPORT-010..012 batch history and record-level before/after tracking (ARCH §56, §57).
 */

namespace DMS\Tests\Integration;

use DMS\Imports\ImportStatus;
use DMS\Plugin;

final class ImportBatchRepositoryTest extends \WP_UnitTestCase {

	public function test_batch_lifecycle_and_counts(): void {
		$repo = Plugin::instance()->import_batches();
		$id   = $repo->create( 'Ghana Electoral Data.xlsx', str_repeat( 'a', 64 ), 1 );

		$batch = $repo->get( $id );
		$this->assertMatchesRegularExpression( '/^IMPORT-\d{4}-\d{5}$/', $batch->import_reference );
		$this->assertSame( ImportStatus::UPLOADED, $batch->status );

		$repo->update_status( $id, ImportStatus::COMPLETED, array( 'total_rows' => 5, 'created_count' => 3, 'updated_count' => 1, 'unchanged_count' => 1, 'bogus' => 9 ), array( 'note' => 'ok' ) );
		$batch = $repo->get( $id );
		$this->assertSame( '3', (string) $batch->created_count );
		$this->assertNotNull( $batch->completed_at );
		$this->assertSame( array( 'note' => 'ok' ), json_decode( $batch->summary, true ) );
	}

	public function test_unknown_status_rejected(): void {
		$repo = Plugin::instance()->import_batches();
		$id   = $repo->create( 'f.xlsx', str_repeat( 'b', 64 ), 1 );
		$this->expectException( \InvalidArgumentException::class );
		$repo->update_status( $id, 'DONE-ISH' );
	}

	public function test_items_keep_previous_and_new_values_for_rollback(): void {
		$repo = Plugin::instance()->import_batches();
		$id   = $repo->create( 'f.xlsx', str_repeat( 'c', 64 ), 1 );
		$items = array(
			array( 'entity_type' => 'CONSTITUENCY', 'record_code' => 'GAR-001', 'record_id' => 42, 'source_row' => 2, 'action' => ImportStatus::ACTION_UPDATE, 'previous_values' => array( 'name' => 'Ablekuma Centre' ), 'new_values' => array( 'name' => 'Ablekuma Central' ) ),
			array( 'entity_type' => 'REGION', 'record_code' => 'GAR', 'action' => ImportStatus::ACTION_UNCHANGED ),
		);
		for ( $i = 0; $i < 600; $i++ ) {
			$items[] = array( 'entity_type' => 'POLLING_STATION', 'record_code' => "PS-{$i}", 'record_id' => 1000 + $i, 'action' => ImportStatus::ACTION_CREATE, 'new_values' => array( 'name' => "Station {$i}" ) );
		}
		$repo->add_items( $id, $items );

		$updates = $repo->items( $id, array( ImportStatus::ACTION_UPDATE ) );
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'name' => 'Ablekuma Centre' ), $updates[0]->previous_values );
		$this->assertSame( array( 'name' => 'Ablekuma Central' ), $updates[0]->new_values );
		$this->assertNull( $repo->items( $id, array( ImportStatus::ACTION_UNCHANGED ) )[0]->record_id );
		$this->assertCount( 602, $repo->items( $id ), 'Chunked insert keeps every item' );
	}

	public function test_history_is_newest_first(): void {
		$repo  = Plugin::instance()->import_batches();
		$first = $repo->create( 'one.xlsx', str_repeat( 'd', 64 ), 1 );
		$second = $repo->create( 'two.xlsx', str_repeat( 'e', 64 ), 1 );
		$history = $repo->history( 1, 2 );
		$this->assertSame( (string) $second, (string) $history['items'][0]->id );
		$this->assertSame( (string) $first, (string) $history['items'][1]->id );
	}
}

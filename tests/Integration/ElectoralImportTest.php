<?php
/**
 * Electoral data import and rollback (ARCH §39–§41, §48–§70; MP §15–§19, §39 "Import";
 * Decisions §27–§32).
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Electoral\ElectoralLevel;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ConflictException;
use DMS\Errors\ValidationException;
use DMS\Imports\ImportFileException;
use DMS\Imports\ImportStatus;
use DMS\Imports\XlsxReader;
use DMS\Plugin;
use DMS\Tests\Support\Workbooks;

final class ElectoralImportTest extends \WP_UnitTestCase {

	use Fixtures;

	public function set_up(): void {
		parent::set_up();
		delete_option( \DMS\Support\Settings::OPTION );
		$this->act_as_admin();
	}

	public function tear_down(): void {
		Workbooks::cleanup();
		parent::tear_down();
	}

	private function upload( string $path, string $name = 'Ghana Electoral Data.xlsx' ): object {
		$id = Plugin::instance()->imports()->upload( $path, $name, (int) filesize( $path ) );
		return Plugin::instance()->import_batches()->get( $id );
	}

	/** Uploads and confirms; returns the batch. */
	private function import( string $path ): object {
		$batch = $this->upload( $path );
		$this->assertSame( ImportStatus::VALIDATED, $batch->status, (string) $batch->summary );
		Plugin::instance()->imports()->confirm( (int) $batch->id );
		return Plugin::instance()->import_batches()->get( (int) $batch->id );
	}

	private function find( ElectoralLevel $level, string $code ): ?object {
		return Plugin::instance()->electoral()->find_by_code( $level, $code );
	}

	private function errors( object $batch ): array {
		return array_column( json_decode( (string) $batch->summary, true )['errors'] ?? array(), 'message' );
	}

	private function audit_actions( int $batch_id ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . Tables::name( Tables::AUDIT_LOG ) . " WHERE object_type = 'import_batch' AND object_id = %d ORDER BY id", $batch_id ) );
	}

	private function standard(): string {
		return Workbooks::electoral(
			array( array( 'IMP', 'Import Region' ), array( 'IMQ', 'Other Region' ) ),
			array( array( 'IMP-001', 'Central', 'IMP' ), array( 'IMP-002', 'North', 'IMP' ), array( 'IMQ-001', 'East', 'IMQ' ) ),
			array( array( 'IPS-001', 'Station 001', 'IMP-001' ), array( 'IPS-002', 'Station 002', 'IMP-001' ), array( 'IPS-003', 'Station 003', 'IMQ-001' ) )
		);
	}

	// --------------------------------------------------------------- happy path

	public function test_valid_workbook_previews_without_writing(): void {
		$batch = $this->upload( $this->standard() );
		$this->assertSame( ImportStatus::VALIDATED, $batch->status );
		$this->assertMatchesRegularExpression( '/^IMPORT-\d{4}-\d{5}$/', $batch->import_reference );
		$this->assertSame( 64, strlen( $batch->file_hash ) );
		$totals = json_decode( $batch->summary, true )['totals'];
		$this->assertSame( array( 'rows' => 8, 'create' => 8, 'update' => 0, 'unchanged' => 0, 'errors' => 0 ), $totals );
		$this->assertNull( $this->find( ElectoralLevel::REGION, 'IMP' ), 'Nothing is written before confirmation' );
		$this->assertSame( array( AuditAction::IMPORT_STARTED, AuditAction::IMPORT_VALIDATED ), $this->audit_actions( (int) $batch->id ) );
	}

	public function test_confirm_creates_relational_hierarchy_from_codes(): void {
		$batch = $this->import( $this->standard() );

		$region = $this->find( ElectoralLevel::REGION, 'IMP' );
		$const  = $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' );
		$ps     = $this->find( ElectoralLevel::POLLING_STATION, 'IPS-002' );
		$this->assertSame( (string) $region->id, (string) $const->region_id, 'Codes resolved to internal IDs' );
		$this->assertSame( (string) $const->id, (string) $ps->constituency_id );
		$this->assertSame( ImportStatus::COMPLETED, $batch->status );
		$this->assertSame( '8', (string) $batch->created_count );
		$this->assertNotNull( $batch->completed_at );
		$this->assertCount( 8, Plugin::instance()->import_batches()->items( (int) $batch->id, array( ImportStatus::ACTION_CREATE ) ) );
		$this->assertSame( array( AuditAction::IMPORT_STARTED, AuditAction::IMPORT_VALIDATED, AuditAction::IMPORT_CONFIRMED, AuditAction::IMPORT_COMPLETED ), $this->audit_actions( (int) $batch->id ) );
		$this->assertCount( 2, Plugin::instance()->electoral()->options( ElectoralLevel::CONSTITUENCY, (int) $region->id ), 'Dropdown cache refreshed' );
	}

	public function test_reimport_same_file_is_unchanged_and_creates_no_duplicates(): void {
		$file = $this->standard();
		$this->import( $file );
		$again = $this->import( $file );

		$this->assertSame( '0', (string) $again->created_count );
		$this->assertSame( '8', (string) $again->unchanged_count );
		$summary = json_decode( $again->summary, true );
		$this->assertNotNull( $summary['earlier_batch'], 'Same file detected by hash' );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT COUNT(*) FROM " . Tables::name( Tables::REGIONS ) . " WHERE code = 'IMP'" ) );
		$this->assertSame( array(), Plugin::instance()->import_batches()->items( (int) $again->id ), 'Unchanged rows are counted, not itemised' );
	}

	public function test_corrected_reimport_updates_in_place_and_keeps_registrations(): void {
		$this->import( $this->standard() );
		$region = $this->find( ElectoralLevel::REGION, 'IMP' );
		$const  = $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' );
		$ps     = $this->find( ElectoralLevel::POLLING_STATION, 'IPS-001' );
		$reg    = $this->make_registration( array( 'region' => (int) $region->id, 'constituency' => (int) $const->id, 'station' => (int) $ps->id ) );

		$batch = $this->import( Workbooks::electoral( array( array( 'IMP', 'Import Region' ) ), array( array( 'IMP-001', 'Central (corrected)', 'IMP' ) ) ) );

		$this->assertSame( '1', (string) $batch->updated_count );
		$this->assertSame( 'Central (corrected)', $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' )->name );
		$this->assertSame( (string) $const->id, (string) $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' )->id, 'Same record, same ID' );
		$this->assertSame( (string) $const->id, (string) Plugin::instance()->registrations()->get( $reg )->constituency_id );
		$update = Plugin::instance()->import_batches()->items( (int) $batch->id, array( ImportStatus::ACTION_UPDATE ) )[0];
		$this->assertSame( 'Central', $update->previous_values['name'] );
		$this->assertSame( 'Central (corrected)', $update->new_values['name'] );
	}

	public function test_codes_are_case_insensitive_and_trimmed(): void {
		$this->import( $this->standard() );
		$batch = $this->upload( Workbooks::electoral( array( array( '  imp ', 'Import Region' ) ) ) );
		$this->assertSame( '1', (string) $batch->unchanged_count );
	}

	public function test_parent_may_already_exist_in_database(): void {
		$this->import( Workbooks::electoral( array( array( 'IMP', 'Import Region' ) ) ) );
		$batch = $this->import( Workbooks::electoral( array(), array( array( 'IMP-009', 'Later', 'IMP' ) ) ) );
		$this->assertSame( '1', (string) $batch->created_count );
		$this->assertSame( (string) $this->find( ElectoralLevel::REGION, 'IMP' )->id, (string) $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-009' )->region_id );
	}

	public function test_blank_rows_skipped_and_columns_in_any_order(): void {
		$path  = Workbooks::make(
			array(
				'Regions'          => array( array( 'region_name', 'region_code' ), array( 'Reordered', 'ORD' ), array( '', '' ), array( 'Second', 'ORD2' ) ),
				'Constituencies'   => array( array( 'constituency_code', 'constituency_name', 'region_code' ) ),
				'Polling Stations' => array( array( 'polling_station_code', 'polling_station_name', 'constituency_code' ) ),
			)
		);
		$batch = $this->upload( $path );
		$this->assertSame( ImportStatus::VALIDATED, $batch->status );
		$this->assertSame( '2', (string) $batch->created_count );
	}

	// ------------------------------------------------------------- validation

	public function test_missing_sheet_fails_with_clear_message(): void {
		$path = Workbooks::make( array( 'Regions' => array( array( 'region_code', 'region_name' ) ) ) );
		try {
			$this->upload( $path );
			$this->fail( 'Expected ImportFileException' );
		} catch ( ImportFileException $e ) {
			$this->assertStringContainsString( 'missing the "Constituencies" sheet', $e->getMessage() );
		}
		$batch = Plugin::instance()->import_batches()->history( 1, 1 )['items'][0];
		$this->assertSame( ImportStatus::FAILED, $batch->status );
		$this->assertContains( AuditAction::IMPORT_FAILED, $this->audit_actions( (int) $batch->id ) );
	}

	public function test_missing_columns_fail_with_column_names(): void {
		$path = Workbooks::make(
			array(
				'Regions'          => array( array( 'region_code', 'region_name' ) ),
				'Constituencies'   => array( array( 'constituency_code', 'name' ) ),
				'Polling Stations' => array( array( 'polling_station_code', 'polling_station_name', 'constituency_code' ) ),
			)
		);
		$this->expectException( ImportFileException::class );
		$this->expectExceptionMessageMatches( '/constituency_name, region_code/' );
		$this->upload( $path );
	}

	public function test_duplicate_codes_in_file_block_the_whole_import(): void {
		$batch = $this->upload( Workbooks::electoral( array( array( 'DUP', 'First' ), array( 'DUP', 'Second' ), array( 'OK1', 'Fine' ) ) ) );
		$this->assertSame( ImportStatus::FAILED, $batch->status );
		$this->assertStringContainsString( 'Duplicate code: already used on row 2', implode( ' ', $this->errors( $batch ) ) );
		$this->assertSame( '1', (string) $batch->error_count );
		$this->expectException( ConflictException::class );
		Plugin::instance()->imports()->confirm( (int) $batch->id );
	}

	public function test_invalid_parent_reference_is_reported_per_row(): void {
		$batch  = $this->upload( Workbooks::electoral( array( array( 'GOOD', 'Good' ) ), array( array( 'GOOD-001', 'Fine', 'GOOD' ), array( 'BAD-001', 'Broken', 'NOPE-999' ) ), array( array( 'P-1', 'Orphan', 'BAD-001' ) ) ) );
		$errors = $this->errors( $batch );
		$this->assertSame( ImportStatus::FAILED, $batch->status );
		$this->assertContains( 'Region code NOPE-999 does not exist.', $errors );
		$this->assertContains( 'Constituency code BAD-001 has errors in this file.', $errors );
		$this->assertNull( $this->find( ElectoralLevel::REGION, 'GOOD' ), 'Valid rows are not partially imported' );
	}

	public function test_required_values_and_code_format(): void {
		$batch  = $this->upload( Workbooks::electoral( array( array( 'BAD CODE!', 'x' ), array( 'OKAY', '' ), array( '', 'No code' ) ) ) );
		$errors = implode( ' | ', $this->errors( $batch ) );
		$this->assertStringContainsString( 'Code may use only letters', $errors );
		$this->assertStringContainsString( 'Name is required.', $errors );
		$this->assertStringContainsString( 'Code is required.', $errors );
	}

	public function test_moving_a_record_used_by_registrations_is_a_conflict(): void {
		$this->import( $this->standard() );
		$h = array(
			'region'       => (int) $this->find( ElectoralLevel::REGION, 'IMP' )->id,
			'constituency' => (int) $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' )->id,
			'station'      => (int) $this->find( ElectoralLevel::POLLING_STATION, 'IPS-001' )->id,
		);
		$this->make_registration( $h );

		$batch = $this->upload( Workbooks::electoral( array(), array( array( 'IMP-001', 'Central', 'IMQ' ), array( 'IMP-002', 'North', 'IMQ' ) ) ) );
		$this->assertSame( ImportStatus::FAILED, $batch->status );
		$this->assertStringContainsString( 'Conflicts with existing data', implode( ' ', $this->errors( $batch ) ) );
		$this->assertSame( '1', (string) $batch->error_count, 'IMP-002 has no registrations and may move' );
	}

	public function test_confirm_revalidates_against_current_data(): void {
		$this->import( $this->standard() );
		$batch = $this->upload( Workbooks::electoral( array(), array( array( 'IMP-001', 'Central', 'IMQ' ) ) ) );
		$this->assertSame( ImportStatus::VALIDATED, $batch->status, 'No registrations yet: the move is allowed' );

		$this->make_registration(
			array(
				'region'       => (int) $this->find( ElectoralLevel::REGION, 'IMP' )->id,
				'constituency' => (int) $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' )->id,
				'station'      => (int) $this->find( ElectoralLevel::POLLING_STATION, 'IPS-001' )->id,
			)
		);
		try {
			Plugin::instance()->imports()->confirm( (int) $batch->id );
			$this->fail( 'Must re-validate at confirm time' );
		} catch ( ValidationException $e ) {
			unset( $e );
		}
		$this->assertSame( (string) $this->find( ElectoralLevel::REGION, 'IMP' )->id, (string) $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' )->region_id );
		$this->assertSame( ImportStatus::FAILED, Plugin::instance()->import_batches()->get( (int) $batch->id )->status );
	}

	public function test_database_error_mid_import_rolls_back_everything(): void {
		$breaker = static function ( string $sql ): string {
			return str_contains( $sql, "'IPS-003'" ) ? 'THIS IS NOT SQL' : $sql;
		};
		add_filter( 'query', $breaker );
		$batch = $this->upload( $this->standard() );
		try {
			Plugin::instance()->imports()->confirm( (int) $batch->id );
			$this->fail( 'Expected ConflictException' );
		} catch ( ConflictException $e ) {
			$this->assertStringContainsString( 'nothing was changed', $e->getMessage() );
		} finally {
			remove_filter( 'query', $breaker );
		}
		$this->assertNull( $this->find( ElectoralLevel::REGION, 'IMP' ), 'Regions created earlier in the same import were rolled back' );
		$this->assertNull( $this->find( ElectoralLevel::POLLING_STATION, 'IPS-001' ) );
		$this->assertSame( ImportStatus::FAILED, Plugin::instance()->import_batches()->get( (int) $batch->id )->status );
	}

	public function test_batch_cannot_be_confirmed_twice(): void {
		$batch = $this->import( $this->standard() );
		$this->expectException( ConflictException::class );
		Plugin::instance()->imports()->confirm( (int) $batch->id );
	}

	// --------------------------------------------------------------- rollback

	public function test_safe_rollback_removes_created_and_restores_updated(): void {
		$this->import( Workbooks::electoral( array( array( 'IMP', 'Import Region' ) ), array( array( 'IMP-001', 'Central', 'IMP' ) ) ) );
		$batch = $this->import( $this->standard() ); // Updates nothing, creates the rest …
		$fix   = $this->import( Workbooks::electoral( array( array( 'IMP', 'Renamed Region' ) ) ) ); // … then an update.

		$impact = Plugin::instance()->imports()->rollback_impact( (int) $fix->id );
		$this->assertTrue( $impact['safe'] );
		$this->assertSame( 1, $impact['restore'] );
		Plugin::instance()->imports()->rollback( (int) $fix->id, true );
		$this->assertSame( 'Import Region', $this->find( ElectoralLevel::REGION, 'IMP' )->name );

		$result = Plugin::instance()->imports()->rollback( (int) $batch->id, true );
		$this->assertTrue( $result['safe'] );
		$this->assertNull( $this->find( ElectoralLevel::POLLING_STATION, 'IPS-001' ) );
		$this->assertNull( $this->find( ElectoralLevel::REGION, 'IMQ' ) );
		$this->assertNotNull( $this->find( ElectoralLevel::REGION, 'IMP' ), 'Records from the first import are untouched' );
		$this->assertNotNull( $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' ) );
		$rolled = Plugin::instance()->import_batches()->get( (int) $batch->id );
		$this->assertSame( ImportStatus::ROLLED_BACK, $rolled->status );
		$this->assertNotNull( $rolled->rolled_back_by );
		$this->assertContains( AuditAction::IMPORT_ROLLED_BACK, $this->audit_actions( (int) $batch->id ) );
	}

	public function test_rollback_blocked_by_registrations(): void {
		$batch = $this->import( $this->standard() );
		$this->make_registration(
			array(
				'region'       => (int) $this->find( ElectoralLevel::REGION, 'IMP' )->id,
				'constituency' => (int) $this->find( ElectoralLevel::CONSTITUENCY, 'IMP-001' )->id,
				'station'      => (int) $this->find( ElectoralLevel::POLLING_STATION, 'IPS-001' )->id,
			)
		);
		$result = Plugin::instance()->imports()->rollback( (int) $batch->id, true );

		$this->assertFalse( $result['safe'] );
		$by_code = array_column( $result['blockers'], 'reason', 'code' );
		$this->assertSame( 'Used by 1 registration.', $by_code['IPS-001'] );
		$this->assertNotNull( $this->find( ElectoralLevel::POLLING_STATION, 'IPS-002' ), 'Blocked rollback changes nothing' );
		$this->assertSame( ImportStatus::ROLLBACK_BLOCKED, Plugin::instance()->import_batches()->get( (int) $batch->id )->status );
		$this->assertContains( AuditAction::IMPORT_ROLLBACK_BLOCKED, $this->audit_actions( (int) $batch->id ) );
	}

	public function test_rollback_blocked_by_officer_region_link(): void {
		$batch = $this->import( $this->standard() );
		$this->make_officer( array( (int) $this->find( ElectoralLevel::REGION, 'IMQ' )->id ) );
		$impact = Plugin::instance()->imports()->rollback_impact( (int) $batch->id );
		$this->assertFalse( $impact['safe'] );
		$this->assertContains( 'Linked to 1 officer.', array_column( $impact['blockers'], 'reason' ) );
	}

	public function test_rollback_blocked_by_child_created_outside_the_batch(): void {
		$batch = $this->import( $this->standard() );
		$this->import( Workbooks::electoral( array(), array(), array( array( 'IPS-900', 'Added later', 'IMP-002' ) ) ) );
		$impact = Plugin::instance()->imports()->rollback_impact( (int) $batch->id );
		$this->assertFalse( $impact['safe'] );
		$reasons = implode( ' | ', array_column( $impact['blockers'], 'reason' ) );
		$this->assertStringContainsString( 'polling station record(s) that were not created by this import', $reasons );
	}

	public function test_rollback_order_later_import_first(): void {
		$first  = $this->import( $this->standard() );
		$second = $this->import( Workbooks::electoral( array( array( 'IMP', 'Changed Again' ) ) ) );
		$impact = Plugin::instance()->imports()->rollback_impact( (int) $first->id );
		$this->assertFalse( $impact['safe'] );
		$this->assertStringContainsString( $second->import_reference, implode( ' ', array_column( $impact['blockers'], 'reason' ) ) );

		Plugin::instance()->imports()->rollback( (int) $second->id, true );
		$this->assertTrue( Plugin::instance()->imports()->rollback( (int) $first->id, true )['safe'] );
		$this->assertNull( $this->find( ElectoralLevel::REGION, 'IMP' ) );
	}

	public function test_rollback_needs_confirmation_and_completed_state(): void {
		$batch = $this->upload( $this->standard() );
		try {
			Plugin::instance()->imports()->rollback( (int) $batch->id, true );
			$this->fail( 'Only completed imports can be rolled back' );
		} catch ( ConflictException $e ) {
			unset( $e );
		}
		Plugin::instance()->imports()->confirm( (int) $batch->id );
		$this->expectException( ValidationException::class );
		Plugin::instance()->imports()->rollback( (int) $batch->id, false );
	}

	// ------------------------------------------------------------ permissions

	public function test_each_import_step_needs_its_permission(): void {
		$file = $this->standard();
		$this->act_as( array( 'imports.view' ) );
		try {
			$this->upload( $file );
			$this->fail( 'imports.upload required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}

		$this->act_as( array( 'imports.upload', 'imports.validate' ) );
		$batch = $this->upload( $file );
		try {
			Plugin::instance()->imports()->confirm( (int) $batch->id );
			$this->fail( 'imports.confirm required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}

		$this->act_as( array( 'imports.confirm' ) );
		Plugin::instance()->imports()->confirm( (int) $batch->id );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->imports()->rollback( (int) $batch->id, true );
	}

	// ------------------------------------------------------ file safety & format

	public function test_upload_rejects_wrong_type_size_and_errors(): void {
		$cases = array(
			'not xlsx extension' => array( $this->standard(), 'data.csv', null ),
			'not a zip'          => array( $this->text_file( "code,name\nGAR,Accra" ), 'fake.xlsx', null ),
			'too large'          => array( $this->standard(), 'big.xlsx', 1 ),
		);
		foreach ( $cases as $label => [ $path, $name, $limit_mb ] ) {
			if ( null !== $limit_mb ) {
				update_option( \DMS\Support\Settings::OPTION, array( 'import_max_file_bytes' => 10 ) );
			}
			try {
				Plugin::instance()->imports()->upload( $path, $name, (int) filesize( $path ) );
				$this->fail( "{$label} must be rejected" );
			} catch ( ValidationException $e ) {
				$this->assertArrayHasKey( 'file', $e->errors, $label );
			}
			delete_option( \DMS\Support\Settings::OPTION );
		}
		$this->expectException( ValidationException::class );
		Plugin::instance()->imports()->upload( $this->standard(), 'x.xlsx', 100, UPLOAD_ERR_PARTIAL );
	}

	public function test_xml_entity_attack_is_rejected(): void {
		$sheet = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&e;</t></is></c></row></sheetData></worksheet>';
		$path  = Workbooks::raw( Workbooks::skeleton( 'Regions', $sheet ) );
		$this->expectException( ImportFileException::class );
		iterator_to_array( ( new XlsxReader( $path ) )->rows( 'Regions' ) );
	}

	public function test_zip_bomb_is_rejected_before_decompression(): void {
		$parts                             = Workbooks::skeleton( 'Regions', '<worksheet/>' );
		$parts['xl/media/padding.bin']     = str_repeat( "\0", 30 * 1024 * 1024 );
		$path                              = Workbooks::raw( $parts );
		$this->expectException( ImportFileException::class );
		new XlsxReader( $path );
	}

	public function test_excel_style_shared_strings_and_numbers_are_read(): void {
		$shared = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3" uniqueCount="3"><si><t>region_code</t></si><si><t>region_name</t></si><si><r><t>Greater </t></r><r><t>Accra</t></r></si></sst>';
		$sheet  = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="3"><c r="A3"><v>12.0</v></c><c r="B3" t="s"><v>2</v></c></row></sheetData></worksheet>';
		$rows   = iterator_to_array( ( new XlsxReader( Workbooks::raw( Workbooks::skeleton( 'Regions', $sheet, $shared ) ) ) )->rows( 'Regions' ) );
		$this->assertSame( array( 'region_code', 'region_name' ), $rows[1] );
		$this->assertSame( array( '12', 'Greater Accra' ), $rows[3], 'Rich text joined, 12.0 read as 12' );
	}

	// ------------------------------------------------------------ template

	public function test_template_has_official_sheets_and_headers(): void {
		$path   = Plugin::instance()->imports()->write_template();
		$reader = new XlsxReader( $path );
		$this->assertSame( array( 'Instructions', 'Regions', 'Constituencies', 'Polling Stations' ), $reader->sheet_names() );
		$this->assertSame( array( 'constituency_code', 'constituency_name', 'region_code' ), iterator_to_array( $reader->rows( 'Constituencies' ) )[1] );
		$zip = new \ZipArchive();
		$zip->open( $path );
		$this->assertStringContainsString( 'numFmtId="49"', $zip->getFromName( 'xl/styles.xml' ), 'Code columns formatted as Text' );
		$zip->close();
		unset( $reader );
		wp_delete_file( $path );
	}

	public function test_exported_current_data_reimports_as_unchanged(): void {
		$this->import( $this->standard() );
		$path  = Plugin::instance()->imports()->write_template( true );
		$batch = $this->upload( $path );
		$this->assertSame( ImportStatus::VALIDATED, $batch->status );
		$this->assertSame( '0', (string) $batch->created_count );
		$this->assertSame( '0', (string) $batch->updated_count );
		$this->assertGreaterThanOrEqual( 8, (int) $batch->unchanged_count );
		wp_delete_file( $path );
	}

	// ------------------------------------------------- file retention (R-07)

	private function after_days( int $days ): void {
		Plugin::instance()->clock()->freeze( new \DateTimeImmutable( "+{$days} days", new \DateTimeZone( 'UTC' ) ) );
	}

	public function test_old_import_files_are_deleted_but_history_and_rollback_remain(): void {
		$done    = $this->import( $this->standard() );
		$pending = $this->upload(
			Workbooks::electoral( array( array( 'IMR', 'Waiting Region' ) ) )
		);
		$this->assertFileExists( $done->stored_file );

		$this->after_days( 89 );
		$this->assertSame( 0, Plugin::instance()->imports()->purge_old_files(), 'Kept for 90 days' );
		$this->after_days( 91 );
		$this->assertSame( 1, Plugin::instance()->imports()->purge_old_files() );
		Plugin::instance()->clock()->freeze( null );

		$this->assertFileDoesNotExist( $done->stored_file );
		$after = Plugin::instance()->import_batches()->get( (int) $done->id );
		$this->assertNull( $after->stored_file );
		$this->assertSame( $done->file_hash, $after->file_hash, 'The fingerprint stays for "already imported" checks' );
		$this->assertFileExists( $pending->stored_file, 'A validated import awaiting confirmation keeps its file' );

		$result = Plugin::instance()->imports()->rollback( (int) $done->id, true );
		$this->assertTrue( $result['safe'], 'Rollback works from the stored item history, not the file' );
		$this->assertNull( $this->find( ElectoralLevel::REGION, 'IMP' ) );
	}

	public function test_zero_retention_keeps_files_forever(): void {
		Plugin::instance()->settings()->update( array( 'import_file_retention_days' => 0 ) );
		$done = $this->import( $this->standard() );
		$this->after_days( 5000 );
		$this->assertSame( 0, Plugin::instance()->imports()->purge_old_files() );
		Plugin::instance()->clock()->freeze( null );
		$this->assertFileExists( $done->stored_file );
	}

	public function test_purge_never_deletes_outside_the_private_imports_folder(): void {
		$done    = $this->import( $this->standard() );
		$outside = (string) wp_tempnam( 'not-ours' );
		global $wpdb;
		$wpdb->update( Tables::name( Tables::IMPORT_BATCHES ), array( 'stored_file' => $outside ), array( 'id' => $done->id ) );
		$this->after_days( 91 );
		Plugin::instance()->imports()->purge_old_files();
		Plugin::instance()->clock()->freeze( null );
		$this->assertFileExists( $outside );
		wp_delete_file( $outside );
		wp_delete_file( $done->stored_file );
	}

	// ---------------------------------------------------------- performance

	public function test_five_thousand_polling_stations_import_in_one_batch(): void {
		$stations = array();
		for ( $i = 1; $i <= 5000; $i++ ) {
			$stations[] = array( sprintf( 'PERF-%05d', $i ), "Station {$i}", 'PERF-C' );
		}
		$path    = Workbooks::electoral( array( array( 'PERF', 'Perf Region' ) ), array( array( 'PERF-C', 'Perf Constituency', 'PERF' ) ), $stations );
		$started = microtime( true );
		$batch   = $this->import( $path );
		$elapsed = microtime( true ) - $started;
		$this->assertSame( '5002', (string) $batch->created_count );
		$this->assertLessThan( 60, $elapsed, "5,000-row import took {$elapsed}s" );
		fwrite( STDERR, sprintf( "\n[perf] 5,002-row import (upload+validate+confirm): %.1fs\n", $elapsed ) );
	}

	private function text_file( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'dms-txt' ) . '.xlsx';
		file_put_contents( $path, $content );
		return $path;
	}
}

<?php
/**
 * Phase 6 services: scoped lists (§78.14), bulk actions (§78.15–§78.17, MP §26),
 * exports (§78.18–§78.19, MP §27), Form Builder (ARCH §42), audit log query.
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\NotFoundException;
use DMS\Errors\ValidationException;
use DMS\Export\ExportService;
use DMS\Plugin;
use DMS\Workflow\Status;

final class AdminServicesTest extends \WP_UnitTestCase {

	use Fixtures;

	/** @var array{region:int,constituency:int,station:int} */
	private array $h;

	public function set_up(): void {
		parent::set_up();
		delete_option( \DMS\Support\Settings::OPTION );
		delete_option( \DMS\Forms\FormDefinition::OPTION );
		$this->h = $this->make_hierarchy();
	}

	private function in_status( Status $status, array $overrides = array(), ?int $officer = null ): int {
		$repo = Plugin::instance()->registrations();
		$id   = $this->make_registration( $this->h, $overrides );
		if ( Status::PENDING === $status ) {
			return $id;
		}
		$repo->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => $officer ?? 999 ) );
		if ( Status::ASSIGNED === $status ) {
			return $id;
		}
		$repo->transition( $id, Status::ASSIGNED, Status::UNDER_REVIEW );
		if ( Status::UNDER_REVIEW === $status ) {
			return $id;
		}
		$repo->transition( $id, Status::UNDER_REVIEW, $status, Status::DISAPPROVED === $status ? array( 'disapproval_reason' => 'Invalid', 'disapproved_at' => '2026-09-20 10:00:00' ) : array( 'approved_at' => '2026-09-20 10:00:00' ) );
		return $id;
	}

	private function ids( array $result ): array {
		return array_map( static fn( $r ) => (int) $r->id, $result['items'] );
	}

	// ------------------------------------------------------------ list scope

	public function test_officer_sees_only_own_assignments_in_every_area(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$mine    = $this->in_status( Status::ASSIGNED, array(), $officer );
		$theirs  = $this->in_status( Status::ASSIGNED );
		$this->in_status( Status::PENDING );

		$lists = Plugin::instance()->lists();
		$this->assertSame( array( $mine ), $this->ids( $lists->search( $officer, 'holding', array() ) ) );
		$this->assertSame( array( $mine ), $this->ids( $lists->search( $officer, 'assigned', array() ) ) );
		$this->assertNotContains( $theirs, $this->ids( $lists->search( $officer, 'holding', array() ) ) );
	}

	public function test_auditor_sees_bin_only(): void {
		$auditor = self::factory()->user->create();
		Plugin::instance()->roles()->assign_user( $auditor, $this->role_with( array( 'bin.view' ) ) );
		$bin = $this->in_status( Status::DISAPPROVED );
		$this->in_status( Status::APPROVED );

		$lists = Plugin::instance()->lists();
		$this->assertSame( array( 'bin' ), $lists->visible_areas( $auditor ) );
		$this->assertSame( array( $bin ), $this->ids( $lists->search( $auditor, 'bin', array() ) ) );
		$this->expectException( NotFoundException::class );
		$lists->search( $auditor, 'approved', array() );
	}

	public function test_user_without_view_permissions_sees_no_area(): void {
		$nobody = self::factory()->user->create();
		$this->assertSame( array(), Plugin::instance()->lists()->visible_areas( $nobody ) );
	}

	public function test_area_status_filter_cannot_escape_the_area(): void {
		$admin = $this->act_as_admin();
		$this->in_status( Status::APPROVED );
		$pending = $this->in_status( Status::PENDING );
		$result  = Plugin::instance()->lists()->search( $admin, 'holding', array( 'status' => array( 'APPROVED' ) ) );
		$this->assertContains( $pending, $this->ids( $result ), 'APPROVED is outside the Holding Area and is ignored' );
		foreach ( $result['items'] as $row ) {
			$this->assertContains( $row->status, array( 'PENDING', 'ASSIGNED', 'UNDER_REVIEW' ) );
		}
	}

	// ---------------------------------------------------------------- bulk

	public function test_bulk_requires_confirmation_and_action_permission(): void {
		$id = $this->in_status( Status::DISAPPROVED );
		$this->act_as( array( 'bin.view' ) );
		try {
			Plugin::instance()->bulk()->execute( 'restore', array( $id ), array(), true );
			$this->fail( 'Permission for the action is required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}
		$this->act_as( array( 'bin.view', 'bin.restore' ) );
		try {
			Plugin::instance()->bulk()->execute( 'restore', array( $id ), array(), false );
			$this->fail( 'Unconfirmed bulk action must not run' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'confirm', $e->errors );
		}
		$this->assertSame( 'DISAPPROVED', Plugin::instance()->registrations()->get( $id )->status, 'Cancel = nothing changed' );
	}

	public function test_bulk_restore_reports_partial_failure_and_is_audited(): void {
		$bin_a   = $this->in_status( Status::DISAPPROVED );
		$bin_b   = $this->in_status( Status::DISAPPROVED );
		$pending = $this->in_status( Status::PENDING );
		$this->act_as( array( 'bin.view', 'bin.restore' ) );

		$result = Plugin::instance()->bulk()->execute( 'restore', array( $bin_a, $bin_b, $pending, 999999 ), array(), true );

		$this->assertEqualsCanonicalizing( array( $bin_a, $bin_b ), $result['processed'] );
		$this->assertEqualsCanonicalizing( array( $pending, 999999 ), array_keys( $result['failed'] ) );
		$this->assertSame( 'PENDING', Plugin::instance()->registrations()->get( $bin_a )->status );

		global $wpdb;
		$meta = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT metadata FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE action = %s ORDER BY id DESC LIMIT 1', AuditAction::BULK_ACTION ) ), true );
		$this->assertSame( 'restore', $meta['action'] );
		$this->assertSame( 4, $meta['requested'] );
		$this->assertCount( 2, $meta['failed'] );
		$this->assertContains( AuditAction::RESTORED, array_map( static fn( $e ) => $e->action, Plugin::instance()->audit()->for_registration( $bin_a ) ), 'Per-record audit too' );
	}

	public function test_bulk_assign_needs_officer_and_eligibility(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$a       = $this->in_status( Status::PENDING );
		$this->act_as( array( 'assignment.assign' ) );
		try {
			Plugin::instance()->bulk()->execute( 'assign', array( $a ), array(), true );
			$this->fail( 'Officer required' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'officer_id', $e->errors );
		}
		$result = Plugin::instance()->bulk()->execute( 'assign', array( $a ), array( 'officer_id' => $officer ), true );
		$this->assertSame( array( $a ), $result['processed'] );
	}

	public function test_bulk_delete_requires_destructive_permission(): void {
		$id = $this->in_status( Status::DISAPPROVED );
		$this->act_as( array( 'bin.view', 'bin.restore' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->bulk()->execute( 'delete', array( $id ), array(), true );
	}

	public function test_bulk_rejects_unknown_action_and_oversized_selection(): void {
		$this->act_as_admin();
		try {
			Plugin::instance()->bulk()->execute( 'approve_all', array( 1 ), array(), true );
			$this->fail( 'Unknown action' );
		} catch ( ValidationException $e ) {
			unset( $e );
		}
		$this->expectException( ValidationException::class );
		Plugin::instance()->bulk()->execute( 'restore', range( 1, 201 ), array(), true );
	}

	// -------------------------------------------------------------- export

	private function read_csv( string $path ): array {
		$lines    = file( $path, FILE_IGNORE_NEW_LINES );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $lines[0], 'UTF-8 BOM for Excel' );
		$lines[0] = substr( $lines[0], 3 );
		return array_map( static fn( string $l ): array => str_getcsv( $l, ',', '"', '' ), $lines );
	}

	public function test_export_requires_the_area_export_permission(): void {
		$user = $this->act_as( array( 'registrations.view' ) );
		try {
			Plugin::instance()->exports()->request( $user, 'holding', 'csv', array() );
			$this->fail( 'registrations.export required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}
		$user = $this->act_as( array( 'registrations.view', 'registrations.export' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->exports()->request( $user, 'approved', 'csv', array() ); // needs approved.export
	}

	public function test_csv_export_contains_only_authorized_filtered_rows_and_is_audited(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		Plugin::instance()->roles()->assign_user( $officer, $this->role_with( array( 'registrations.export' ) ) );
		$mine   = $this->in_status( Status::ASSIGNED, array( 'last_name' => 'MineOnly', 'organization' => 'Keep Org' ), $officer );
		$this->in_status( Status::ASSIGNED, array( 'last_name' => 'NotMine', 'organization' => 'Keep Org' ) );
		$this->in_status( Status::ASSIGNED, array( 'last_name' => 'MineFilteredOut', 'organization' => 'Other' ), $officer );

		$out  = Plugin::instance()->exports()->request( $officer, 'holding', 'csv', array( 'search' => 'Keep Org' ) );
		$rows = $this->read_csv( $out['path'] );
		wp_delete_file( $out['path'] );

		$this->assertSame( 'file', $out['mode'] );
		$this->assertSame( 1, $out['rows'] );
		$this->assertSame( 'Registration Number', $rows[0][0] );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'MineOnly', $rows[1][4] );
		$this->assertSame( Plugin::instance()->registrations()->get( $mine )->registration_number, $rows[1][0] );

		global $wpdb;
		$audit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE action = %s ORDER BY id DESC LIMIT 1', AuditAction::EXPORTED ) );
		$meta  = json_decode( $audit->metadata, true );
		$this->assertSame( (string) $officer, (string) $audit->user_id );
		$this->assertSame( 'csv', $meta['format'] );
		$this->assertSame( 1, $meta['rows'] );
		$this->assertSame( 'Keep Org', $meta['filters']['search'] );
	}

	public function test_csv_export_neutralises_formula_injection(): void {
		$admin = $this->act_as_admin();
		$this->in_status( Status::PENDING, array( 'first_name' => '=HYPERLINK("http://evil")' ) );
		$out  = Plugin::instance()->exports()->request( $admin, 'holding', 'csv', array() );
		$rows = $this->read_csv( $out['path'] );
		wp_delete_file( $out['path'] );
		$this->assertSame( "'=HYPERLINK(\"http://evil\")", $rows[1][2] );
	}

	public function test_excel_export_is_a_valid_workbook(): void {
		$admin = $this->act_as_admin();
		$this->in_status( Status::APPROVED, array( 'last_name' => 'Excel & <Sons>' ) );
		$out = Plugin::instance()->exports()->request( $admin, 'approved', 'xlsx', array() );

		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $out['path'] ) );
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$this->assertNotFalse( $zip->getFromName( 'xl/workbook.xml' ) );
		$zip->close();
		wp_delete_file( $out['path'] );

		$xml = simplexml_load_string( $sheet );
		$this->assertNotFalse( $xml, 'Well-formed XML' );
		$this->assertStringContainsString( 'Excel &amp; &lt;Sons&gt;', $sheet );
		$this->assertStringContainsString( 'Registration Number', $sheet );
	}

	public function test_export_of_selected_ids_only(): void {
		$admin = $this->act_as_admin();
		$a     = $this->in_status( Status::PENDING );
		$this->in_status( Status::PENDING );
		$out = Plugin::instance()->exports()->request( $admin, 'holding', 'csv', array(), array( $a ) );
		wp_delete_file( $out['path'] );
		$this->assertSame( 1, $out['rows'] );
	}

	public function test_large_export_is_queued_processed_and_downloadable_only_by_owner(): void {
		update_option( \DMS\Support\Settings::OPTION, array( 'export_sync_max_rows' => 100 ) );
		for ( $i = 0; $i < 101; $i++ ) {
			$this->in_status( Status::PENDING );
		}
		$admin = $this->act_as_admin();
		$out   = Plugin::instance()->exports()->request( $admin, 'holding', 'csv', array() );
		$this->assertSame( 'queued', $out['mode'] );
		$this->assertNotFalse( wp_next_scheduled( ExportService::CRON_HOOK, array( $out['job_key'] ) ) );

		Plugin::instance()->exports()->process( $out['job_key'] );
		$download = Plugin::instance()->exports()->download( $admin, $out['job_key'] );
		$this->assertCount( 102, file( $download['path'] ) );
		$this->assertStringContainsString( 'dms-private', $download['path'] );
		$this->assertFileExists( dirname( $download['path'], 2 ) . '/.htaccess' );

		$other = $this->act_as_admin();
		try {
			Plugin::instance()->exports()->download( $other, $out['job_key'] );
			$this->fail( 'Only the requester may download' );
		} catch ( NotFoundException $e ) {
			unset( $e );
		}
		wp_delete_file( $download['path'] );
	}

	public function test_expired_exports_are_purged(): void {
		update_option( \DMS\Support\Settings::OPTION, array( 'export_sync_max_rows' => 100 ) );
		for ( $i = 0; $i < 101; $i++ ) {
			$this->in_status( Status::PENDING );
		}
		$admin = $this->act_as_admin();
		$out   = Plugin::instance()->exports()->request( $admin, 'holding', 'csv', array() );
		Plugin::instance()->exports()->process( $out['job_key'] );
		$path = Plugin::instance()->exports()->download( $admin, $out['job_key'] )['path'];

		Plugin::instance()->clock()->freeze( new \DateTimeImmutable( '+2 days', new \DateTimeZone( 'UTC' ) ) );
		try {
			$this->assertSame( 1, Plugin::instance()->exports()->purge_expired() );
		} finally {
			Plugin::instance()->clock()->freeze( null );
		}
		$this->assertFileDoesNotExist( $path );
	}

	// ---------------------------------------------------------- form builder

	public function test_form_builder_saves_valid_config_and_audits(): void {
		$this->act_as_admin();
		Plugin::instance()->form_config()->save(
			array(
				'fields' => array(
					'first_name'   => array( 'label' => 'Given Name', 'order' => 5 ),
					'organization' => array( 'required' => false, 'enabled' => true ),
					'gender'       => array( 'options' => "Male\nFemale\nPrefer not to say" ),
				),
				'custom' => array( array( 'key' => 'voter_id', 'label' => 'Voter ID', 'type' => 'text', 'required' => true ) ),
			)
		);
		$fields = array_column( Plugin::instance()->form_definition()->fields(), null, 'key' );
		$this->assertSame( 'Given Name', $fields['first_name']['label'] );
		$this->assertFalse( $fields['organization']['required'] );
		$this->assertSame( array( 'Male', 'Female', 'Prefer not to say' ), $fields['gender']['options'] );
		$this->assertTrue( $fields['custom_voter_id']['required'] );

		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE action = %s', AuditAction::FORM_CHANGED ) ) );
	}

	public function test_form_builder_cannot_disable_system_fields(): void {
		$this->act_as_admin();
		Plugin::instance()->form_config()->save( array( 'fields' => array( 'phone' => array( 'enabled' => false, 'required' => false ), 'region_id' => array( 'enabled' => false ) ) ) );
		$fields = array_column( Plugin::instance()->form_definition()->fields(), null, 'key' );
		$this->assertTrue( $fields['phone']['enabled'] && $fields['phone']['required'] );
		$this->assertTrue( $fields['region_id']['enabled'] );
	}

	public function test_form_builder_rejects_invalid_input(): void {
		$this->act_as_admin();
		try {
			Plugin::instance()->form_config()->save(
				array(
					'fields' => array( 'nonexistent' => array( 'label' => 'x' ) ),
					'custom' => array(
						array( 'key' => '1bad', 'label' => '', 'type' => 'script' ),
						array( 'key' => 'first_name', 'label' => 'Clash', 'type' => 'text' ),
						array( 'key' => 'colour', 'label' => 'Colour', 'type' => 'select', 'options' => '' ),
					),
				)
			);
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			foreach ( array( 'fields.nonexistent', 'custom.0.key', 'custom.0.label', 'custom.0.type', 'custom.1.key', 'custom.2.options' ) as $key ) {
				$this->assertArrayHasKey( $key, $e->errors, $key );
			}
		}
	}

	public function test_form_builder_requires_settings_edit(): void {
		$this->act_as( array( 'settings.view' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->form_config()->save( array() );
	}

	// ----------------------------------------------------------- audit query

	public function test_audit_log_filters_and_paginates(): void {
		$id    = $this->in_status( Status::PENDING );
		$audit = Plugin::instance()->audit();
		$user  = self::factory()->user->create( array( 'display_name' => 'Auditor Tester' ) );
		wp_set_current_user( $user );
		for ( $i = 0; $i < 3; $i++ ) {
			$audit->record( AuditAction::VIEWED, array( 'registration_id' => $id ) );
		}
		$audit->record( AuditAction::ROLE_CREATED, array( 'object_type' => 'role', 'object_id' => 5 ) );
		$number = Plugin::instance()->registrations()->get( $id )->registration_number;

		$q = Plugin::instance()->audit_log();
		$viewed = $q->search( array( 'action' => 'viewed', 'registration_number' => $number, 'per_page' => 2 ) );
		$this->assertSame( 3, $viewed['total'] );
		$this->assertCount( 2, $viewed['items'] );
		$this->assertSame( 'Auditor Tester', $viewed['items'][0]->actor_name );
		$this->assertSame( $number, $viewed['items'][0]->registration_number );
		$this->assertSame( 1, $q->search( array( 'object_type' => 'role', 'user_id' => $user ) )['total'] );
		$this->assertContains( 'ROLE_CREATED', $q->actions() );
	}
}

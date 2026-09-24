<?php
/**
 * Electoral Data admin screen and handlers (ARCH §53–§56, §60; MP §34, §39 "Permissions").
 */

namespace DMS\Tests\Integration;

use DMS\Admin\Pages\ElectoralDataPage;
use DMS\Electoral\ElectoralLevel;
use DMS\Imports\ImportStatus;
use DMS\Plugin;
use DMS\Tests\Support\Workbooks;

final class ElectoralAdminTest extends \WP_UnitTestCase {

	use Fixtures;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		delete_option( \DMS\Support\Settings::OPTION );
	}

	public function tear_down(): void {
		$_GET = array();
		Workbooks::cleanup();
		delete_transient( 'dms_admin_notices_' . get_current_user_id() );
		parent::tear_down();
	}

	private function render( array $get = array() ): string {
		$_GET = $get + array( 'page' => ElectoralDataPage::SLUG );
		ob_start();
		try {
			Plugin::instance()->page_electoral()->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function upload_via_handler( string $path ): int {
		Plugin::instance()->admin_actions()->handle(
			'import_upload',
			array( '_files' => array( 'workbook' => array( 'tmp_name' => $path, 'name' => 'data.xlsx', 'size' => filesize( $path ), 'error' => UPLOAD_ERR_OK ) ) )
		);
		return (int) Plugin::instance()->import_batches()->history( 1, 1 )['items'][0]->id;
	}

	public function test_page_refuses_users_without_import_permissions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );
		$this->render();
	}

	public function test_history_only_user_sees_history_but_no_upload_or_counts(): void {
		$this->act_as( array( 'imports.view_history' ) );
		$html = $this->render();
		$this->assertStringContainsString( 'Import History', $html );
		$this->assertStringNotContainsString( 'name="workbook"', $html );
		$this->assertStringNotContainsString( 'Download Template', $html );
	}

	public function test_full_flow_through_admin_handlers(): void {
		$this->act_as_admin();
		$this->assertStringContainsString( 'name="workbook"', $this->render() );

		$path     = Workbooks::electoral( array( array( 'UIR', 'UI Region' ) ), array( array( 'UIR-1', 'UI Const', 'UIR' ) ), array( array( 'UIP-1', 'UI Station', 'UIR-1' ) ) );
		$batch_id = $this->upload_via_handler( $path );

		$preview = $this->render( array( 'batch' => $batch_id ) );
		$this->assertStringContainsString( 'Import Preview', $preview );
		$this->assertStringContainsString( 'The import is ready to proceed.', $preview );
		$this->assertStringContainsString( 'Confirm Import', $preview );

		Plugin::instance()->admin_actions()->handle( 'import_confirm', array( 'batch_id' => $batch_id ) );
		$this->assertNotNull( Plugin::instance()->electoral()->find_by_code( ElectoralLevel::POLLING_STATION, 'UIP-1' ) );
		$done = $this->render( array( 'batch' => $batch_id ) );
		$this->assertStringContainsString( 'Import Summary', $done );
		$this->assertStringContainsString( 'Changes made by this import', $done );

		$impact = $this->render( array( 'batch' => $batch_id, 'check_rollback' => 1 ) );
		$this->assertStringContainsString( 'Rollback is safe', $impact );

		Plugin::instance()->admin_actions()->handle( 'import_rollback', array( 'batch_id' => $batch_id, 'confirmed' => '1' ) );
		$this->assertNull( Plugin::instance()->electoral()->find_by_code( ElectoralLevel::REGION, 'UIR' ) );
		$this->assertSame( ImportStatus::ROLLED_BACK, Plugin::instance()->import_batches()->get( $batch_id )->status );
	}

	public function test_errors_are_listed_with_sheet_and_row(): void {
		$this->act_as_admin();
		$batch_id = $this->upload_via_handler( Workbooks::electoral( array(), array( array( 'X-1', 'Orphan', 'NOPE' ) ) ) );
		$html     = $this->render( array( 'batch' => $batch_id ) );
		$this->assertStringContainsString( 'Constituencies, row 2 (X-1): Region code NOPE does not exist.', $html );
		$this->assertStringContainsString( 'Download Error Report', $html );
		$this->assertStringNotContainsString( 'Confirm Import', $html );
	}

	public function test_blocked_rollback_is_explained(): void {
		$this->act_as_admin();
		$batch_id = $this->upload_via_handler( Workbooks::electoral( array( array( 'BLK', 'Blocked Region' ) ) ) );
		Plugin::instance()->imports()->confirm( $batch_id );
		$this->make_officer( array( (int) Plugin::instance()->electoral()->find_by_code( ElectoralLevel::REGION, 'BLK' )->id ) );
		$html = $this->render( array( 'batch' => $batch_id, 'check_rollback' => 1 ) );
		$this->assertStringContainsString( 'Rollback blocked', $html );
		$this->assertStringContainsString( 'Linked to 1 officer.', $html );
		$this->assertStringNotContainsString( 'value="1">', substr( $html, (int) strpos( $html, 'Rollback blocked' ) ), 'No rollback form when blocked' );
	}

	public function test_current_data_view_searches(): void {
		$this->act_as_admin();
		$this->make_hierarchy( 'SRCH' );
		$html = $this->render( array( 'view' => 'data', 'level' => 'CONSTITUENCY', 's' => 'SRCH' ) );
		$this->assertStringContainsString( 'SRCHC', $html );
		$this->assertStringContainsString( 'Region SRCH', $html, 'Parent shown by name, not ID' );
	}

	public function test_upload_without_file_is_explained(): void {
		$this->act_as_admin();
		$_FILES = array();
		Plugin::instance()->admin_actions()->handle( 'import_upload', array() );
		$notices = get_transient( 'dms_admin_notices_' . get_current_user_id() );
		$this->assertSame( 'error', $notices[0]['type'] );
	}

	public function test_menu_entry_follows_permission(): void {
		global $submenu, $menu, $_registered_pages;
		$this->act_as( array( 'imports.view' ) );
		$submenu = array();
		$menu    = array();
		$_registered_pages = array();
		Plugin::instance()->admin_menu()->menu();
		$slugs = array();
		foreach ( $submenu as $items ) {
			foreach ( $items as $item ) {
				$slugs[] = $item[2];
			}
		}
		$this->assertContains( ElectoralDataPage::SLUG, $slugs );
	}
}

<?php
/**
 * Admin screens and actions (MP §39 "Permissions: attempt direct access to every
 * protected page/action — not only through visible UI"; ARCH §25, §74; MP §26, §33–§35).
 */

namespace DMS\Tests\Integration;

use DMS\Admin\AdminMenu;
use DMS\Admin\Pages\RegistrationsPage;
use DMS\Plugin;
use DMS\Workflow\Status;

final class AdminUiTest extends \WP_UnitTestCase {

	use Fixtures;

	/** @var array{region:int,constituency:int,station:int} */
	private array $h;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		delete_option( \DMS\Support\Settings::OPTION );
		$this->h = $this->make_hierarchy();
	}

	public function tear_down(): void {
		$_GET = array();
		delete_transient( 'dms_admin_notices_' . get_current_user_id() );
		parent::tear_down();
	}

	/** Renders a page callback and returns its HTML. */
	private function render( callable $callback, array $get = array() ): string {
		$_GET = $get;
		ob_start();
		try {
			$callback();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/** Asserts the callback stops with wp_die() (403/404) and outputs nothing useful. */
	private function assert_denied( callable $callback, array $get = array() ): void {
		try {
			$this->render( $callback, $get );
			$this->fail( 'Access must be denied' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
	}

	private function submenu_slugs(): array {
		global $submenu, $menu, $_registered_pages;
		$submenu           = array();
		$menu              = array();
		$_registered_pages = array();
		Plugin::instance()->admin_menu()->menu();
		$slugs = array();
		foreach ( $submenu as $items ) {
			foreach ( $items as $item ) {
				$slugs[] = $item[2];
			}
		}
		return $slugs;
	}

	private function notices(): array {
		$notices = get_transient( 'dms_admin_notices_' . get_current_user_id() );
		return is_array( $notices ) ? $notices : array();
	}

	/** @return array<string,callable> Every protected page renderer. */
	private function pages(): array {
		$p = Plugin::instance();
		return array(
			'dashboard'    => array( $p->page_dashboard(), 'render' ),
			'assignments'  => array( $p->page_assignments(), 'render' ),
			'exports'      => array( $p->page_exports(), 'render' ),
			'users'        => array( $p->page_users(), 'render' ),
			'roles'        => array( $p->page_roles(), 'render' ),
			'audit'        => array( $p->page_audit(), 'render' ),
			'form_builder' => array( $p->page_form_builder(), 'render' ),
			'settings'     => array( $p->settings_page(), 'render' ),
		);
	}

	// ------------------------------------------------------------ menus

	public function test_menu_shows_only_permitted_pages(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		wp_set_current_user( $officer );
		$slugs = $this->submenu_slugs();
		$this->assertContains( 'dms-assigned', $slugs );
		foreach ( array( 'dms-dashboard', 'dms-users', 'dms-roles', 'dms-audit', 'dms-settings', 'dms-form-builder', 'dms-assignments' ) as $hidden ) {
			$this->assertNotContains( $hidden, $slugs, $hidden );
		}

		$this->act_as_admin();
		$slugs = $this->submenu_slugs();
		foreach ( array( 'dms-dashboard', 'dms-holding', 'dms-bin', 'dms-users', 'dms-roles', 'dms-audit', 'dms-settings', 'dms-form-builder', 'dms-assignments', 'dms-exports' ) as $shown ) {
			$this->assertContains( $shown, $slugs, $shown );
		}
	}

	public function test_user_without_permissions_gets_no_menu(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array(), $this->submenu_slugs() );
	}

	// ----------------------------------------------- direct page access

	public function test_every_page_refuses_direct_access_without_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $this->pages() as $name => $callback ) {
			try {
				$this->render( $callback );
				$this->fail( "{$name} must refuse access" );
			} catch ( \WPDieException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
		foreach ( AdminMenu::AREA_PAGES as $slug ) {
			$this->assert_denied( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => $slug ) );
		}
	}

	public function test_area_page_refused_when_area_not_in_scope(): void {
		$this->act_as( array( 'bin.view' ) ); // Auditor.
		$this->assertStringContainsString( 'Bin', $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-bin' ) ) );
		$this->assert_denied( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-approved' ) );
	}

	public function test_record_outside_scope_is_not_found(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$other   = $this->make_registration( $this->h );
		wp_set_current_user( $officer );
		$this->assert_denied( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-holding', 'registration' => $other ) );
	}

	// --------------------------------------------------------- rendering

	public function test_every_page_renders_for_administrator(): void {
		$this->act_as_admin();
		$this->make_registration( $this->h );
		foreach ( $this->pages() as $name => $callback ) {
			$this->assertStringContainsString( 'class="wrap', $this->render( $callback ), $name );
		}
		foreach ( AdminMenu::AREA_PAGES as $slug ) {
			$this->assertStringContainsString( 'wp-list-table', $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => $slug ) ), $slug );
		}
	}

	public function test_list_and_record_escape_user_input(): void {
		$this->act_as_admin();
		$id    = $this->make_registration( $this->h, array( 'first_name' => '<script>alert(1)</script>', 'organization' => '"><img src=x onerror=alert(2)>' ) );
		$list  = $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-holding' ) );
		$record = $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-holding', 'registration' => $id ) );
		foreach ( array( $list, $record ) as $html ) {
			$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
			$this->assertStringNotContainsString( '<img src=x', $html );
			$this->assertStringContainsString( '&lt;script&gt;', $html );
		}
	}

	public function test_review_screen_offers_decisions_only_to_the_assigned_officer(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->make_registration( $this->h );
		Plugin::instance()->assignments()->auto_assign( $id );
		wp_set_current_user( $officer );
		Plugin::instance()->workflow()->start_review( $id );

		$mine = $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-under-review', 'registration' => $id ) );
		$this->assertStringContainsString( 'value="approve"', $mine );
		$this->assertStringContainsString( 'value="disapprove"', $mine );
		$this->assertStringContainsString( 'Do not include personal details', $mine, 'OD-23 guidance on the disapprove form' );

		$this->act_as_admin();
		$admin_view = $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-under-review', 'registration' => $id ) );
		$this->assertStringNotContainsString( 'value="approve"', $admin_view );
		$this->assertStringContainsString( 'value="reassign"', $admin_view );
	}

	public function test_approved_record_shows_locked_and_no_edit_form(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->make_registration( $this->h );
		Plugin::instance()->assignments()->auto_assign( $id );
		wp_set_current_user( $officer );
		Plugin::instance()->workflow()->start_review( $id );
		Plugin::instance()->workflow()->approve( $id );
		$this->act_as_admin();
		$html = $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-approved', 'registration' => $id ) );
		$this->assertStringContainsString( 'Approved records are locked', $html );
		$this->assertStringNotContainsString( 'value="edit"', $html );
	}

	public function test_edit_form_changes_polling_station_within_chain(): void {
		$this->act_as_admin();
		$id     = $this->make_registration( $this->h );
		$html   = $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-holding', 'registration' => $id ) );
		$this->assertStringContainsString( 'name="changes[polling_station_id]"', $html );
		$second = Plugin::instance()->electoral()->insert( \DMS\Electoral\ElectoralLevel::POLLING_STATION, 'EDIT-PS2', 'Second Station', $this->h['constituency'] );

		Plugin::instance()->admin_actions()->handle(
			'registration',
			array(
				'op'              => 'edit',
				'registration_id' => $id,
				'changes'         => array( 'region_id' => $this->h['region'], 'constituency_id' => $this->h['constituency'], 'polling_station_id' => $second ),
			)
		);
		$this->assertSame( (string) $second, (string) Plugin::instance()->registrations()->get( $id )->polling_station_id );
	}

	public function test_bulk_confirmation_page_without_javascript(): void {
		$this->act_as_admin();
		$id   = $this->make_registration( $this->h );
		$html = $this->render( array( Plugin::instance()->page_registrations(), 'render' ), array( 'page' => 'dms-holding', 'action' => 'assign', 'ids' => array( $id ) ) );
		$this->assertStringContainsString( 'Are you sure you want to perform this action?', $html );
		$this->assertStringContainsString( 'name="confirmed" value="1"', $html );
		$this->assertStringContainsString( 'Cancel', $html );
	}

	// ----------------------------------------------------------- actions

	public function test_action_without_nonce_is_rejected(): void {
		$this->act_as_admin();
		$_POST    = array();
		$_REQUEST = array();
		$this->expectException( \WPDieException::class );
		Plugin::instance()->admin_actions()->dispatch( 'role_delete' );
	}

	public function test_forbidden_action_changes_nothing_and_explains(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->make_registration( $this->h );
		Plugin::instance()->assignments()->auto_assign( $id );
		wp_set_current_user( $officer );
		Plugin::instance()->workflow()->start_review( $id );

		$this->act_as( array( 'review.approve', 'registrations.view' ) ); // Not the assignee.
		Plugin::instance()->admin_actions()->handle( 'registration', array( 'op' => 'approve', 'registration_id' => $id ) );

		$this->assertSame( 'UNDER_REVIEW', Plugin::instance()->registrations()->get( $id )->status );
		$this->assertSame( 'error', $this->notices()[0]['type'] );
	}

	public function test_officer_reviews_and_approves_through_admin_actions(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->make_registration( $this->h );
		Plugin::instance()->assignments()->auto_assign( $id );
		wp_set_current_user( $officer );
		$actions = Plugin::instance()->admin_actions();

		$actions->handle( 'registration', array( 'op' => 'start_review', 'registration_id' => $id ) );
		$this->assertSame( 'UNDER_REVIEW', Plugin::instance()->registrations()->get( $id )->status );
		$url = $actions->handle( 'registration', array( 'op' => 'approve', 'registration_id' => $id ) );
		$this->assertSame( 'APPROVED', Plugin::instance()->registrations()->get( $id )->status );
		$this->assertStringContainsString( 'page=dms-approved', $url );
		$notices = $this->notices();
		$this->assertSame( 'success', end( $notices )['type'] );
	}

	public function test_bulk_handler_refuses_unconfirmed_request(): void {
		$this->act_as_admin();
		$id = $this->make_registration( $this->h );
		Plugin::instance()->registrations()->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => 1 ) );
		Plugin::instance()->registrations()->transition( $id, Status::ASSIGNED, Status::UNDER_REVIEW );
		Plugin::instance()->registrations()->transition( $id, Status::UNDER_REVIEW, Status::DISAPPROVED, array( 'disapproval_reason' => 'Invalid' ) );

		Plugin::instance()->admin_actions()->handle( 'bulk', array( 'bulk_action' => 'delete', 'ids' => array( $id ), 'area' => 'bin' ) );
		$this->assertSame( 'DISAPPROVED', Plugin::instance()->registrations()->get( $id )->status );
		$this->assertSame( 'error', $this->notices()[0]['type'] );

		Plugin::instance()->admin_actions()->handle( 'bulk', array( 'bulk_action' => 'delete', 'ids' => array( $id ), 'area' => 'bin', 'confirmed' => '1' ) );
		$this->assertSame( 'DELETED', Plugin::instance()->registrations()->get( $id )->status );
	}

	public function test_single_delete_requires_confirmation_flag(): void {
		$this->act_as_admin();
		$id = $this->make_registration( $this->h );
		Plugin::instance()->registrations()->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => 1 ) );
		Plugin::instance()->registrations()->transition( $id, Status::ASSIGNED, Status::UNDER_REVIEW );
		Plugin::instance()->registrations()->transition( $id, Status::UNDER_REVIEW, Status::DISAPPROVED, array( 'disapproval_reason' => 'Invalid' ) );
		Plugin::instance()->admin_actions()->handle( 'registration', array( 'op' => 'delete', 'registration_id' => $id, 'confirmed' => '0' ) );
		$this->assertSame( 'DISAPPROVED', Plugin::instance()->registrations()->get( $id )->status );
	}

	public function test_user_and_role_admin_actions(): void {
		$this->act_as_admin();
		$url = Plugin::instance()->admin_actions()->handle(
			'role_save',
			array( 'role_id' => 0, 'name' => 'Data Analyst', 'description' => 'Reads analytics', 'permissions' => array( 'analytics.view' ) )
		);
		$role = Plugin::instance()->roles()->find_by_slug( 'data-analyst' );
		$this->assertNotNull( $role );
		$this->assertStringContainsString( 'role=' . $role->id, $url );

		Plugin::instance()->admin_actions()->handle(
			'user_save',
			array( 'user_id' => 0, 'first_name' => 'Abena', 'last_name' => 'Ofori', 'email' => 'abena@example.org', 'username' => 'abena', 'password' => 'correct-horse-battery', 'role_ids' => array( $role->id ) )
		);
		$user = get_user_by( 'login', 'abena' );
		$this->assertTrue( user_can( $user, 'analytics.view' ) );
	}

	public function test_form_builder_action_skips_blank_and_removed_rows(): void {
		$this->act_as_admin();
		Plugin::instance()->admin_actions()->handle(
			'form_save',
			array(
				'fields' => array( 'first_name' => array( 'label' => 'First Name', 'required' => '1', 'enabled' => '1' ) ),
				'custom' => array(
					array( 'key' => 'voter_id', 'label' => 'Voter ID', 'type' => 'text' ),
					array( 'key' => 'old_field', 'label' => 'Old', 'type' => 'text', 'remove' => '1' ),
					array( 'key' => '', 'label' => '', 'type' => 'text' ),
				),
			)
		);
		$this->assertSame( array( 'voter_id' ), array_column( Plugin::instance()->form_config()->current()['custom'], 'key' ) );
	}
}

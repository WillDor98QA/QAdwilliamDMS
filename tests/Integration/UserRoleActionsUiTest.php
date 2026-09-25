<?php
/**
 * Row actions on the Users and Roles lists (Edit / Disable-Enable / Remove,
 * Edit / Delete), shown only with the matching permission, and the
 * "Removed" users tab (user decision 2026-09-25: remove = out of DMS, account kept).
 */

namespace DMS\Tests\Integration;

use DMS\Plugin;

final class UserRoleActionsUiTest extends \WP_UnitTestCase {

	use Fixtures;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

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

	private function users_page( array $get = array() ): string {
		return $this->render( array( Plugin::instance()->page_users(), 'render' ), array_merge( array( 'page' => 'dms-users' ), $get ) );
	}

	public function test_users_list_offers_edit_disable_and_remove_but_not_on_own_row(): void {
		$admin   = $this->act_as_admin();
		$officer = $this->make_officer( array( $this->make_hierarchy()['region'] ) );
		$html    = $this->users_page();

		$this->assertStringContainsString( 'page=dms-users&#038;user=' . $officer, $html );
		$this->assertStringContainsString( 'value="dms_user_status"', $html );
		$this->assertStringContainsString( 'value="dms_user_remove"', $html );
		$this->assertMatchesRegularExpression( '#name="user_id" value="' . $officer . '"><button type="submit" class="button button-small dms-button-danger">Remove</button>#', $html );
		$this->assertDoesNotMatchRegularExpression( '#name="user_id" value="' . $admin . '"><(input type="hidden" name="status"|button)#', $html, 'No disable/remove on your own row' );
	}

	public function test_users_list_hides_actions_without_permission(): void {
		$this->act_as_admin();
		$this->make_officer( array( $this->make_hierarchy()['region'] ) );
		$this->act_as( array( 'users.view' ) );
		$html = $this->users_page();
		$this->assertStringNotContainsString( 'dms_user_remove', $html );
		$this->assertStringNotContainsString( 'dms_user_status', $html );
		$this->assertStringNotContainsString( '>Edit</a>', $html );
	}

	public function test_removed_tab_lists_removed_users_with_add_back(): void {
		$this->act_as_admin();
		$officer = $this->make_officer( array( $this->make_hierarchy()['region'] ) );
		Plugin::instance()->user_service()->remove( $officer, 'Moved away' );

		$current = $this->users_page();
		$this->assertStringNotContainsString( 'value="' . $officer . '"', $current, 'Gone from the main list' );

		$removed = $this->users_page( array( 'view' => 'removed' ) );
		$this->assertStringContainsString( get_user_by( 'id', $officer )->display_name, $removed );
		$this->assertStringContainsString( 'Moved away', $removed );
		$this->assertStringContainsString( '>Add back</a>', $removed );

		$form = $this->users_page( array( 'user' => (string) $officer ) );
		$this->assertStringContainsString( 'This account is not in Data Management', $form );
		$this->assertStringNotContainsString( 'value="dms_user_remove"', $form, 'Nothing to remove any more' );
	}

	public function test_roles_list_offers_edit_and_delete_and_explains_when_delete_is_not_possible(): void {
		$this->act_as_admin();
		$free = $this->role_with( array( 'analytics.view' ), 'unused-role' );
		$this->make_officer( array( $this->make_hierarchy()['region'] ) ); // Verification Officer now has a user.
		$html = $this->render( array( Plugin::instance()->page_roles(), 'render' ), array( 'page' => 'dms-roles' ) );

		$this->assertMatchesRegularExpression( '#name="role_id" value="' . $free . '"><button type="submit" class="button button-small dms-button-danger">Delete</button>#', $html );
		$this->assertStringContainsString( 'Protected role', $html, 'Administrator cannot be deleted' );
		$this->assertMatchesRegularExpression( '#disabled>Delete</button><span class="dms-row-actions__why">Assigned to \d+ users?</span>#', $html, 'Assigned roles explain why' );
		$this->assertStringContainsString( '>Edit</a>', $html );
	}
}

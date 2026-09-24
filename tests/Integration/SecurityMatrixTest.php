<?php
/**
 * Phase 10 security pass: every state-changing admin action is refused —
 * with nothing changed and nothing downloaded — for signed-in users who lack
 * the permission, and every action requires its own nonce (MP §33, ARCH §25:
 * "Direct URL/API access → denied"; "Menu hidden ≠ Permission denied").
 *
 * The case list is checked against AdminActions::HANDLERS, so an action added
 * later without a case here fails this test.
 */

namespace DMS\Tests\Integration;

use DMS\Admin\AdminActions;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\NotFoundException;
use DMS\Plugin;

final class SecurityMatrixTest extends \WP_UnitTestCase {

	use Fixtures;

	/** @var array{region:int,constituency:int,station:int} */
	private array $h;
	private int $officer;
	private int $pending;
	private int $assigned;
	private int $binned;
	private int $role;
	private int $notification;
	private string $upload;
	private string $job_key;
	private string $job_file;

	public function set_up(): void {
		parent::set_up();
		$this->h       = $this->make_hierarchy( 'SEC' );
		$this->officer = $this->make_officer( array( $this->h['region'] ) );
		$other         = $this->make_officer( array( $this->h['region'] ) );

		$this->pending  = $this->make_registration( $this->h );
		$this->assigned = $this->make_registration( $this->h );
		$this->binned   = $this->make_registration( $this->h );
		Plugin::instance()->assignments()->auto_assign( $this->assigned );
		Plugin::instance()->assignments()->auto_assign( $this->binned );
		$bin_officer = (int) Plugin::instance()->registrations()->get( $this->binned )->assigned_officer_id;
		wp_set_current_user( $bin_officer );
		Plugin::instance()->workflow()->start_review( $this->binned );
		Plugin::instance()->workflow()->disapprove( $this->binned, 'Could not be confirmed' );
		unset( $other );

		$this->role = $this->role_with( array( 'registrations.view' ), 'sec-custom' );
		global $wpdb;
		$this->notification = (int) $wpdb->get_var( 'SELECT id FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' ORDER BY id LIMIT 1' );
		$this->upload       = (string) wp_tempnam( 'sec-upload' );
		file_put_contents( $this->upload, 'PK' . str_repeat( 'x', 100 ) );
		// A finished export that belongs to an administrator.
		$this->job_key  = bin2hex( random_bytes( 16 ) );
		$this->job_file = (string) wp_tempnam( 'sec-export' );
		file_put_contents( $this->job_file, "Name\nSecret Person\n" );
		$wpdb->insert(
			Tables::name( Tables::EXPORT_JOBS ),
			array(
				'job_key'    => $this->job_key,
				'user_id'    => $this->act_as_admin(),
				'area'       => 'holding',
				'format'     => 'csv',
				'status'     => 'DONE',
				'total_rows' => 1,
				'file_path'  => $this->job_file,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_delete_file( $this->upload );
		wp_delete_file( $this->job_file );
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * One realistic request per action (and per registration/bulk operation) —
	 * the payload an administrator would send.
	 *
	 * @return list<array{0:string,1:array<string,mixed>}>
	 */
	private function cases(): array {
		$reg = fn( string $op, int $id, array $extra = array() ): array => array( 'registration', array( 'op' => $op, 'registration_id' => $id ) + $extra );
		return array(
			$reg( 'start_review', $this->assigned ),
			$reg( 'edit', $this->pending, array( 'changes' => array( 'first_name' => 'Changed' ), 'reason' => 'Correction' ) ),
			$reg( 'note', $this->assigned, array( 'note' => 'A note' ) ),
			$reg( 'approve', $this->assigned ),
			$reg( 'disapprove', $this->assigned, array( 'reason' => 'No' ) ),
			$reg( 'assign', $this->pending, array( 'officer_id' => $this->officer ) ),
			$reg( 'reassign', $this->assigned, array( 'officer_id' => $this->officer, 'reason' => 'Balance' ) ),
			$reg( 'restore', $this->binned ),
			$reg( 'delete', $this->binned, array( 'confirmed' => 1 ) ),
			array( 'bulk', array( 'bulk_action' => 'assign', 'ids' => array( $this->pending ), 'officer_id' => $this->officer, 'area' => 'holding' ) ),
			array( 'bulk', array( 'bulk_action' => 'restore', 'ids' => array( $this->binned ), 'area' => 'bin', 'confirmed' => 1 ) ),
			array( 'bulk', array( 'bulk_action' => 'delete', 'ids' => array( $this->binned ), 'area' => 'bin', 'confirmed' => 1 ) ),
			array( 'bulk', array( 'bulk_action' => 'export_csv', 'ids' => array( $this->pending ), 'area' => 'holding' ) ),
			array( 'export', array( 'area' => 'holding', 'format' => 'csv', 'filters' => '{}' ) ),
			array( 'export', array( 'area' => 'bin', 'format' => 'xlsx', 'filters' => '{}' ) ),
			array( 'export_download', array( 'job_key' => $this->job_key ) ), // Someone else's finished export.
			array( 'assign_pending', array() ),
			array( 'user_save', array( 'username' => 'intruder', 'password' => 'Str0ng-Passw0rd!x', 'email' => 'intruder@example.org', 'first_name' => 'I', 'last_name' => 'N', 'role_ids' => array( $this->role ) ) ),
			array( 'user_save', array( 'user_id' => $this->officer, 'email' => 'changed@example.org', 'first_name' => 'X', 'last_name' => 'Y', 'role_ids' => array( $this->role ) ) ),
			array( 'user_status', array( 'user_id' => $this->officer, 'status' => 'disable', 'reason' => 'Left' ) ),
			array( 'role_save', array( 'name' => 'Intruders', 'permissions' => array( 'registrations.view', 'users.create' ) ) ),
			array( 'role_save', array( 'role_id' => $this->role, 'name' => 'Renamed', 'permissions' => array( 'users.create' ) ) ),
			array( 'role_delete', array( 'role_id' => $this->role ) ),
			array( 'form_save', array( 'fields' => array(), 'custom' => array( array( 'key' => 'evil', 'label' => 'Evil', 'type' => 'text' ) ) ) ),
			array( 'audit_export', array( 'filters' => '{}' ) ),
			array( 'import_upload', array( '_files' => array( 'workbook' => array( 'tmp_name' => $this->upload, 'name' => 'data.xlsx', 'size' => 102, 'error' => 0 ) ) ) ),
			array( 'import_confirm', array( 'batch_id' => 1 ) ),
			array( 'import_rollback', array( 'batch_id' => 1, 'confirmed' => 1 ) ),
			array( 'import_template', array( 'with_data' => 1 ) ),
			array( 'import_template', array() ),
			array( 'import_error_report', array( 'batch_id' => 1 ) ),
			array( 'notification_retry', array( 'notification_id' => $this->notification ) ),
			array( 'notification_retry', array( 'all_failed' => 1 ) ),
			array( 'email_templates_save', array( 'templates' => array( 'registration_approved' => array( 'subject' => 'Hi', 'body' => 'Body' ) ) ) ),
			array( 'analytics_export', array( 'format' => 'csv', 'filters' => '{}' ) ),
			array( 'report_export', array( 'report' => 'approved_by_location', 'format' => 'xlsx', 'filters' => '{}' ) ),
		);
	}

	/** Fingerprint of every plugin table, plugin options, users and user meta. */
	private function snapshot(): array {
		global $wpdb;
		$out = array();
		foreach ( Tables::all() as $table ) {
			$name = Tables::name( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $table ] = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM {$name} ORDER BY 1", ARRAY_N ) ) );
		}
		$out['options']  = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'dms\\_%' ORDER BY option_name", ARRAY_N ) ) );
		$out['users']    = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT ID, user_login, user_email, user_pass FROM {$wpdb->users} ORDER BY ID", ARRAY_N ) ) );
		$out['usermeta'] = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key NOT LIKE '%session%' ORDER BY umeta_id", ARRAY_N ) ) );
		return $out;
	}

	private function last_notice(): array {
		$notices = get_transient( 'dms_admin_notices_' . get_current_user_id() );
		return is_array( $notices ) ? (array) end( $notices ) : array();
	}

	/** Runs every case as the current user and returns the ones that were NOT refused. */
	private function unrefused(): array {
		// Someone else's export gets the same answer as a missing one, so job keys cannot be probed.
		$refusals = array( ( new AuthorizationException() )->getMessage(), ( new NotFoundException() )->getMessage(), __( 'This export is not available. It may have expired.', 'dms' ) );
		$problems = array();
		foreach ( $this->cases() as $i => [ $action, $payload ] ) {
			$label  = $action . ( isset( $payload['op'] ) ? ':' . $payload['op'] : '' ) . ( isset( $payload['bulk_action'] ) ? ':' . $payload['bulk_action'] : '' ) . " #{$i}";
			$before = $this->snapshot();
			delete_transient( 'dms_admin_notices_' . get_current_user_id() );
			ob_start();
			try {
				Plugin::instance()->admin_actions()->handle( $action, $payload );
			} catch ( \WPDieException $e ) {
				unset( $e ); // wp_die counts as a refusal only if nothing else happened (checked below).
			}
			$output = (string) ob_get_clean();
			$after  = $this->snapshot();
			$notice = $this->last_notice();
			if ( '' !== $output ) {
				$problems[] = "{$label}: sent output (" . strlen( $output ) . ' bytes)';
			}
			$changed = array_keys( array_diff_assoc( $after, $before ) );
			if ( array() !== $changed ) {
				$problems[] = "{$label}: changed " . implode( ', ', $changed );
			}
			if ( ! in_array( $notice['message'] ?? '', $refusals, true ) ) {
				$problems[] = "{$label}: not refused for lack of permission (notice: " . ( $notice['message'] ?? 'none' ) . ')';
			}
		}
		return $problems;
	}

	public function test_control_the_owner_can_download_the_export(): void {
		global $wpdb;
		$owner = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . Tables::name( Tables::EXPORT_JOBS ) . ' WHERE job_key = %s', $this->job_key ) );
		$file  = Plugin::instance()->exports()->download( $owner, $this->job_key );
		$this->assertSame( $this->job_file, $file['path'], 'Proves the refusal below is about ownership, not a broken job' );
	}

	public function test_every_admin_action_has_a_case(): void {
		$handlers = ( new \ReflectionClass( AdminActions::class ) )->getConstant( 'HANDLERS' );
		$covered  = array_unique( array_column( $this->cases(), 0 ) );
		$this->assertSame( array(), array_values( array_diff( array_keys( $handlers ), $covered ) ), 'Add a case for every new admin action' );
	}

	public function test_user_with_no_permissions_is_refused_everything(): void {
		$this->act_as( array() );
		$this->assertSame( array(), $this->unrefused() );
	}

	public function test_wordpress_administrator_without_a_dms_role_is_refused(): void {
		// WordPress's own "administrator" role is not a DMS role: plugin access comes only from DMS roles.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( array(), $this->unrefused() );
	}

	public function test_read_only_viewer_cannot_change_anything(): void {
		$this->act_as( array( 'registrations.view', 'bin.view', 'audit.view', 'imports.view_history', 'notifications.view', 'users.view', 'roles.view', 'analytics.view', 'reports.view' ) );
		$problems = $this->unrefused();
		// Downloading the blank official template needs imports.view, which this user does not hold; every other case must be refused.
		$this->assertSame( array(), $problems );
	}

	// ----------------------------------------------------------------- CSRF

	public function test_every_action_requires_its_own_nonce(): void {
		$this->act_as_admin();
		$handlers = ( new \ReflectionClass( AdminActions::class ) )->getConstant( 'HANDLERS' );
		foreach ( array_keys( $handlers ) as $action ) {
			foreach ( array(
				'missing'        => array(),
				'forged'         => array( '_dms_nonce' => 'abc123' ),
				'another action' => array( '_dms_nonce' => wp_create_nonce( AdminActions::PREFIX . ( 'bulk' === $action ? 'registration' : 'bulk' ) ) ),
			) as $kind => $post ) {
				$_POST    = $post;
				$_REQUEST = $post;
				try {
					Plugin::instance()->admin_actions()->dispatch( $action );
					$this->fail( "{$action} ran with a {$kind} nonce" );
				} catch ( \WPDieException $e ) {
					$this->assertStringNotContainsString( 'Location:', $e->getMessage() );
				}
			}
		}
	}

	public function test_settings_save_needs_permission_and_nonce(): void {
		$page = Plugin::instance()->settings_page();
		$this->act_as( array( 'settings.view' ) );
		$_POST = array( '_wpnonce' => wp_create_nonce( \DMS\Admin\SettingsPage::ACTION ), 'settings' => array( 'otp_enabled' => '1' ) );
		$_REQUEST = $_POST;
		try {
			$page->save();
			$this->fail( 'settings.edit required' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		}
		$this->act_as_admin();
		$_POST    = array( 'settings' => array( 'otp_enabled' => '1' ) );
		$_REQUEST = $_POST;
		$this->expectException( \WPDieException::class );
		$page->save();
	}
}

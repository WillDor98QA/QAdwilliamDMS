<?php
/**
 * REQ-AUDIT-001..004 — central audit service (ARCH §11, Decisions §20, §21).
 */

namespace DMS\Tests\Integration;

use DMS\Audit\ActorType;
use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Tables;
use DMS\Plugin;
use DMS\Support\Redactor;

final class AuditServiceTest extends \WP_UnitTestCase {

	public function test_records_actor_status_change_reason_and_correlation(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$id    = Plugin::instance()->audit()->record(
			AuditAction::DISAPPROVED,
			array( 'registration_id' => 42, 'old_status' => 'UNDER_REVIEW', 'new_status' => 'DISAPPROVED', 'reason' => 'Invalid documentation' )
		);
		$entry = Plugin::instance()->audit()->for_registration( 42 )[0];

		$this->assertSame( (string) $id, (string) $entry->id );
		$this->assertSame( (string) $user, (string) $entry->user_id );
		$this->assertSame( ActorType::USER, $entry->actor_type );
		$this->assertSame( 'registration', $entry->object_type );
		$this->assertSame( 'UNDER_REVIEW', $entry->old_status );
		$this->assertSame( 'DISAPPROVED', $entry->new_status );
		$this->assertSame( 'Invalid documentation', $entry->reason );
		$this->assertNotEmpty( $entry->correlation_id );
	}

	public function test_system_actor_when_no_user(): void {
		wp_set_current_user( 0 );
		Plugin::instance()->audit()->record( AuditAction::ASSIGNED, array( 'registration_id' => 7 ) );
		$this->assertSame( ActorType::SYSTEM, Plugin::instance()->audit()->for_registration( 7 )[0]->actor_type );
	}

	public function test_non_registration_event_has_null_registration_id(): void {
		global $wpdb;
		$id  = Plugin::instance()->audit()->record( AuditAction::ROLE_CREATED, array( 'object_type' => 'role', 'object_id' => 3 ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE id = %d', $id ) );
		$this->assertNull( $row->registration_id );
		$this->assertSame( 'role', $row->object_type );
	}

	public function test_otp_never_stored_in_metadata(): void {
		Plugin::instance()->audit()->record( AuditAction::OTP_VERIFIED, array( 'registration_id' => 9, 'metadata' => array( 'otp' => '123456' ) ) );
		$entry = Plugin::instance()->audit()->for_registration( 9 )[0];
		$this->assertStringNotContainsString( '123456', $entry->metadata );
		$this->assertStringContainsString( Redactor::MASK, $entry->metadata );
	}

	public function test_scrub_removes_pii_values_keeps_history(): void {
		$audit = Plugin::instance()->audit();
		$audit->record( AuditAction::EDITED, array( 'registration_id' => 11, 'metadata' => array( 'changes' => array( 'last_name' => array( 'old' => 'Mensah', 'new' => 'Mensa' ) ) ) ) );
		$audit->record( AuditAction::APPROVED, array( 'registration_id' => 11 ) );

		$this->assertSame( 1, $audit->scrub_registration_pii( 11, array( 'last_name' ) ) );
		$this->assertSame( 0, $audit->scrub_registration_pii( 11, array( 'last_name' ) ), 'Idempotent' );

		$entries = $audit->for_registration( 11 );
		$this->assertCount( 2, $entries, 'History retained' );
		$this->assertStringNotContainsString( 'Mensah', $entries[0]->metadata );
		$this->assertStringContainsString( 'last_name', $entries[0]->metadata );
		$this->assertStringContainsString( AuditService::SCRUBBED, $entries[0]->metadata );
		$this->assertNotNull( $entries[0]->pii_scrubbed_at );
	}

	public function test_audit_service_exposes_no_update_or_delete(): void {
		$methods = get_class_methods( AuditService::class );
		foreach ( $methods as $method ) {
			$this->assertDoesNotMatchRegularExpression( '/^(update|delete|remove|edit)/', $method );
		}
	}
}

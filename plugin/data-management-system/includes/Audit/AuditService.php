<?php
/**
 * Centralized, append-oriented audit trail (ARCH §11, MP §21, Decisions §20).
 *
 * - The service exposes no update or delete. The single controlled mutation is
 *   scrub_registration_pii(), used by the retention job (Decisions §21).
 * - Secrets are redacted from metadata before storage (never OTPs/passwords).
 * - A failed write throws, so callers running inside a Transaction roll the
 *   business change back instead of completing it unaudited.
 *
 * @package DMS
 */

namespace DMS\Audit;

use DMS\Database\Tables;
use DMS\Support\Clock;
use DMS\Support\Redactor;
use DMS\Support\RequestContext;

defined( 'ABSPATH' ) || exit;

class AuditService {

	public const SCRUBBED = '[SCRUBBED]';

	public const OBJECT_REGISTRATION = 'registration';

	public function __construct( private \wpdb $db, private Clock $clock, private RequestContext $context ) {
	}

	/**
	 * @param array{
	 *   registration_id?:int|null,
	 *   object_type?:string,
	 *   object_id?:int|null,
	 *   old_status?:string|null,
	 *   new_status?:string|null,
	 *   reason?:string|null,
	 *   metadata?:array<mixed>,
	 *   user_id?:int|null,
	 *   actor_type?:string
	 * } $args
	 * @return int Audit entry ID.
	 * @throws AuditWriteException
	 */
	public function record( string $action, array $args = array() ): int {
		$registration_id = $args['registration_id'] ?? null;
		$user_id         = array_key_exists( 'user_id', $args ) ? $args['user_id'] : $this->context->user_id();
		$user_id         = $user_id > 0 ? (int) $user_id : null;
		$actor_type      = $args['actor_type'] ?? ( $user_id ? ActorType::USER : ActorType::SYSTEM );
		$object_type     = $args['object_type'] ?? ( $registration_id ? self::OBJECT_REGISTRATION : 'system' );
		$object_id       = $args['object_id'] ?? $registration_id;
		$metadata        = isset( $args['metadata'] ) ? Redactor::redact( $args['metadata'] ) : null;

		$ok = $this->db->insert(
			Tables::name( Tables::AUDIT_LOG ),
			array(
				'registration_id' => $registration_id,
				'object_type'     => $object_type,
				'object_id'       => $object_id,
				'action'          => $action,
				'old_status'      => $args['old_status'] ?? null,
				'new_status'      => $args['new_status'] ?? null,
				'reason'          => $args['reason'] ?? null,
				'metadata'        => null === $metadata ? null : wp_json_encode( $metadata ),
				'user_id'         => $user_id,
				'actor_type'      => $actor_type,
				'ip_hash'         => $this->context->ip_hash(),
				'correlation_id'  => $this->context->correlation_id(),
				'created_at'      => $this->clock->now_mysql(),
			)
		);
		if ( false === $ok ) {
			throw new AuditWriteException( 'Audit entry could not be written: ' . $this->db->last_error );
		}
		return (int) $this->db->insert_id;
	}

	/** @return list<object> Entries for one registration, oldest first. */
	public function for_registration( int $registration_id ): array {
		$table = Tables::name( Tables::AUDIT_LOG );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE registration_id = %d ORDER BY id ASC", $registration_id ) );
	}

	/**
	 * Replaces personal values in a registration's audit metadata with a marker,
	 * keeping field names, actions, actors and timestamps (Decisions §21).
	 * Idempotent.
	 *
	 * @param list<string> $pii_fields Registration field keys considered personal data.
	 * @return int Number of entries changed.
	 */
	public function scrub_registration_pii( int $registration_id, array $pii_fields ): int {
		$table   = Tables::name( Tables::AUDIT_LOG );
		$changed = 0;
		foreach ( $this->for_registration( $registration_id ) as $entry ) {
			if ( null === $entry->metadata || '' === $entry->metadata ) {
				continue;
			}
			$metadata = json_decode( $entry->metadata, true );
			if ( ! is_array( $metadata ) ) {
				continue;
			}
			$scrubbed = self::scrub( $metadata, $pii_fields );
			if ( $scrubbed === $metadata ) {
				continue;
			}
			$ok = $this->db->update(
				$table,
				array(
					'metadata'        => wp_json_encode( $scrubbed ),
					'pii_scrubbed_at' => $this->clock->now_mysql(),
				),
				array( 'id' => $entry->id )
			);
			if ( false === $ok ) {
				throw new AuditWriteException( 'Audit PII scrub failed: ' . $this->db->last_error );
			}
			++$changed;
		}
		return $changed;
	}

	/**
	 * @param array<mixed> $data
	 * @param list<string> $pii_fields
	 * @return array<mixed>
	 */
	public static function scrub( array $data, array $pii_fields ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, $pii_fields, true ) ) {
				$data[ $key ] = self::SCRUBBED;
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::scrub( $value, $pii_fields );
			}
		}
		return $data;
	}
}

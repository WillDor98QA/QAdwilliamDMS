<?php
/**
 * Bulk actions on registrations (ARCH §74, MP §26, Decisions §24).
 *
 * - Nothing runs without an explicit confirmation flag from the confirmation
 *   dialog. The server refuses unconfirmed requests (defence in depth).
 * - Permission for the ACTION is checked first; then every record goes
 *   through the normal single-record service, which re-checks object access,
 *   state and business rules. UI selection state is never trusted.
 * - Partial failure is safe: each record is its own transaction, and the
 *   result lists what succeeded and what failed and why.
 * - One BULK_ACTION audit entry summarises the run. Each record also gets its
 *   own normal audit entry from the underlying service.
 *
 * @package DMS
 */

namespace DMS\Bulk;

use DMS\Assignments\AssignmentService;
use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Errors\DmsException;
use DMS\Errors\ValidationException;
use DMS\Support\Authorizer;
use DMS\Support\Logger;
use DMS\Workflow\RegistrationWorkflow;

defined( 'ABSPATH' ) || exit;

class BulkActionService {

	public const MAX_RECORDS = 200;

	/** Action => required permission. */
	public const ACTIONS = array(
		'assign'   => 'assignment.assign',
		'reassign' => 'assignment.reassign',
		'restore'  => 'bin.restore',
		'delete'   => 'bin.delete',
	);

	/** Actions needing the stronger confirmation (MP §26, ARCH §74). */
	public const DESTRUCTIVE = array( 'delete' );

	public function __construct(
		private RegistrationWorkflow $workflow,
		private AssignmentService $assignments,
		private AuditService $audit,
		private Authorizer $authorizer,
		private Logger $logger,
	) {
	}

	/**
	 * @param list<mixed>          $ids
	 * @param array<string,mixed>  $params e.g. officer_id, reason.
	 * @return array{action:string,processed:list<int>,failed:array<int,string>}
	 */
	public function execute( string $action, array $ids, array $params, bool $confirmed ): array {
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			throw new ValidationException( array( 'action' => __( 'Choose a valid bulk action.', 'dms' ) ) );
		}
		$this->authorizer->require( self::ACTIONS[ $action ] );
		if ( ! $confirmed ) {
			throw new ValidationException( array( 'confirm' => __( 'Please confirm the bulk action.', 'dms' ) ) );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $ids ) {
			throw new ValidationException( array( 'ids' => __( 'Select at least one registration.', 'dms' ) ) );
		}
		if ( count( $ids ) > self::MAX_RECORDS ) {
			/* translators: %d: maximum records */
			throw new ValidationException( array( 'ids' => sprintf( __( 'Select at most %d registrations at a time.', 'dms' ), self::MAX_RECORDS ) ) );
		}
		$officer_id = absint( $params['officer_id'] ?? 0 );
		$reason     = (string) ( $params['reason'] ?? '' );
		if ( in_array( $action, array( 'assign', 'reassign' ), true ) && 0 === $officer_id ) {
			throw new ValidationException( array( 'officer_id' => __( 'Choose an officer.', 'dms' ) ) );
		}

		$processed = array();
		$failed    = array();
		foreach ( $ids as $id ) {
			try {
				match ( $action ) {
					'assign'   => $this->assignments->assign( $id, $officer_id, $reason ),
					'reassign' => $this->assignments->reassign( $id, $officer_id, $reason ),
					'restore'  => $this->workflow->restore( $id ),
					'delete'   => $this->workflow->delete_permanently( $id ),
				};
				$processed[] = $id;
			} catch ( DmsException $e ) {
				$failed[ $id ] = $e->getMessage();
			} catch ( \Throwable $e ) {
				$reference = $this->logger->error(
					'Bulk action item failed',
					array(
						'action'          => $action,
						'registration_id' => $id,
						'error'           => $e->getMessage(),
					)
				);
				/* translators: %s: error reference */
				$failed[ $id ] = sprintf( __( 'Unexpected error (reference %s).', 'dms' ), $reference );
			}
		}

		$this->audit->record(
			AuditAction::BULK_ACTION,
			array(
				'object_type' => 'bulk',
				'reason'      => '' !== $reason ? sanitize_textarea_field( $reason ) : null,
				'metadata'    => array(
					'action'     => $action,
					'requested'  => count( $ids ),
					'processed'  => $processed,
					'failed'     => array_keys( $failed ),
					'officer_id' => $officer_id > 0 ? $officer_id : null,
				),
			)
		);

		return array(
			'action'    => $action,
			'processed' => $processed,
			'failed'    => $failed,
		);
	}
}

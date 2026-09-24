<?php
/**
 * Centralized email notifications (ARCH §76, MP §28, §37; Decisions §26).
 *
 * Reliability model (ARCH §76.4):
 *   1. queue_*() inserts rows into dms_notifications INSIDE the business
 *      transaction, so a notification exists exactly when its event does.
 *   2. dispatch() runs AFTER commit and sends with wp_mail(). A failure is
 *      recorded (status FAILED, attempts, error, next_attempt_at), logged and
 *      audited. It never touches the registration.
 *   3. process_due() (every 15 minutes) retries failed emails with
 *      exponential backoff (5, 10, 20, 40 … minutes) until max_attempts, and
 *      picks up emails left QUEUED by an interrupted request.
 *   4. A UNIQUE dedupe_key guarantees each event produces its email once.
 *   5. When a registration is permanently deleted, its unsent emails are
 *      CANCELLED and personal data is removed from the log.
 *
 * Disapproval deliberately has no public notification (ARCH §76.3).
 * Wording comes from EmailTemplates (MP §37).
 *
 * @package DMS
 */

namespace DMS\Notifications;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Tables;
use DMS\Errors\ConflictException;
use DMS\Errors\NotFoundException;
use DMS\Support\Authorizer;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class NotificationService {

	public const EVENT_REGISTRATION_RECEIVED  = 'REGISTRATION_RECEIVED';
	public const EVENT_ADMIN_NEW_REGISTRATION = 'ADMIN_NEW_REGISTRATION';
	public const EVENT_OFFICER_ASSIGNED       = 'OFFICER_ASSIGNED';
	public const EVENT_REGISTRATION_APPROVED  = 'REGISTRATION_APPROVED';
	public const EVENT_ASSIGNMENT_EXCEPTION   = 'ASSIGNMENT_EXCEPTION';

	public const STATUS_QUEUED    = 'QUEUED';
	public const STATUS_SENT      = 'SENT';
	public const STATUS_FAILED    = 'FAILED';
	public const STATUS_CANCELLED = 'CANCELLED';

	/** A QUEUED email older than this was left behind by an interrupted request. */
	public const STUCK_AFTER_SECONDS = 600;

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private Settings $settings,
		private AuditService $audit,
		private Logger $logger,
		private EmailTemplates $templates,
		private Authorizer $authorizer,
	) {
	}

	private function table(): string {
		return Tables::name( Tables::NOTIFICATIONS );
	}

	/** Public-user confirmation (ARCH §76.1). No-op when the registrant gave no email. @return list<int> */
	public function queue_registration_received( object $registration ): array {
		if ( empty( $registration->email ) ) {
			return array();
		}
		return $this->queue_event( self::EVENT_REGISTRATION_RECEIVED, $registration, (string) $registration->email, null, $this->applicant_values( $registration ) );
	}

	/** New-registration notice to the configured administrator recipients (ARCH §76.1). @return list<int> */
	public function queue_admin_new_registration( object $registration ): array {
		$ids = array();
		foreach ( $this->admin_recipients() as $email ) {
			$ids = array_merge( $ids, $this->queue_event( self::EVENT_ADMIN_NEW_REGISTRATION, $registration, $email, null, $this->base_values( $registration ) ) );
		}
		return $ids;
	}

	/**
	 * "Registration pending approval" to the assigned officer (ARCH §76.1).
	 * The dedupe key includes the assignment time, so a later reassignment to the same
	 * officer produces a new notification while retries of this one do not.
	 *
	 * @return list<int>
	 */
	public function queue_officer_assignment( object $registration, int $officer_id ): array {
		$officer = get_user_by( 'id', $officer_id );
		if ( ! $officer || ! is_email( $officer->user_email ) ) {
			return array();
		}
		$values = $this->base_values( $registration ) + array( 'officer_name' => (string) $officer->display_name );
		return $this->queue_event( self::EVENT_OFFICER_ASSIGNED, $registration, $officer->user_email, $officer_id, $values, (string) $registration->assigned_at );
	}

	/** "Your registration has been approved" to the public user (ARCH §76.2). @return list<int> */
	public function queue_registration_approved( object $registration ): array {
		if ( empty( $registration->email ) ) {
			return array();
		}
		return $this->queue_event( self::EVENT_REGISTRATION_APPROVED, $registration, (string) $registration->email, null, $this->applicant_values( $registration ) );
	}

	/** Alerts administrators that a registration could not be assigned (ARCH §72.4). @return list<int> */
	public function queue_assignment_exception( object $registration, string $region_name ): array {
		$ids = array();
		foreach ( $this->admin_recipients() as $email ) {
			$ids = array_merge( $ids, $this->queue_event( self::EVENT_ASSIGNMENT_EXCEPTION, $registration, $email, null, $this->base_values( $registration ) + array( 'region_name' => $region_name ) ) );
		}
		return $ids;
	}

	/**
	 * Removes personal data from a registration's notification log and cancels
	 * anything not yet sent, so a deleted person is never emailed (Decisions §21).
	 */
	public function scrub_registration( int $registration_id ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		$n = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table()} SET body = NULL, subject = NULL, recipient_email = NULL, next_attempt_at = NULL,
					status = CASE WHEN status IN (%s, %s) THEN %s ELSE status END, updated_at = %s
				WHERE registration_id = %d AND (body IS NOT NULL OR recipient_email IS NOT NULL OR status IN (%s, %s))",
				self::STATUS_QUEUED,
				self::STATUS_FAILED,
				self::STATUS_CANCELLED,
				$this->clock->now_mysql(),
				$registration_id,
				self::STATUS_QUEUED,
				self::STATUS_FAILED
			)
		);
		if ( false === $n ) {
			throw new \RuntimeException( 'Could not scrub notifications: ' . $this->db->last_error );
		}
		return (int) $n;
	}

	/** @param array<string,string> $values @return list<int> */
	private function queue_event( string $event, object $registration, string $email, ?int $user_id, array $values, string $dedupe_suffix = '' ): array {
		$message = $this->templates->render( $event, $values );
		$id      = $this->queue( $event, (int) $registration->id, $email, $user_id, $message['subject'], $message['body'], $dedupe_suffix );
		return null === $id ? array() : array( $id );
	}

	/** @return array<string,string> */
	private function base_values( object $registration ): array {
		return array(
			'site_name'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'registration_number' => (string) $registration->registration_number,
			'admin_url'           => admin_url( 'admin.php?page=dms-holding&registration=' . (int) $registration->id ),
		);
	}

	/** @return array<string,string> */
	private function applicant_values( object $registration ): array {
		return $this->base_values( $registration ) + array(
			'first_name' => (string) $registration->first_name,
			'last_name'  => (string) $registration->last_name,
		);
	}

	/** @return list<string> */
	private function admin_recipients(): array {
		return array_values( array_filter( (array) $this->settings->get( 'admin_notification_emails' ), 'is_email' ) );
	}

	/** @return int|null Notification ID, or null if this exact notification was already queued. */
	public function queue( string $event, ?int $registration_id, string $email, ?int $user_id, string $subject, string $body, string $dedupe_suffix = '' ): ?int {
		$now = $this->clock->now_mysql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		$ok = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$this->table()} (event, registration_id, recipient_email, recipient_user_id, channel, subject, body, status, attempts, max_attempts, dedupe_key, created_at, updated_at)
				VALUES (%s, %d, %s, %d, 'email', %s, %s, %s, 0, %d, %s, %s, %s)",
				$event,
				(int) $registration_id,
				$email,
				(int) $user_id,
				$subject,
				$body,
				self::STATUS_QUEUED,
				$this->settings->int( 'notification_max_attempts' ),
				substr( $event . ':' . (int) $registration_id . ':' . strtolower( $email ) . ( '' !== $dedupe_suffix ? ':' . $dedupe_suffix : '' ), 0, 191 ),
				$now,
				$now
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not queue notification: ' . $this->db->last_error );
		}
		return 1 === $ok ? (int) $this->db->insert_id : null;
	}

	/**
	 * Sends queued notifications. Never throws: every failure is recorded.
	 *
	 * @param list<int> $ids
	 * @return array{sent:int,failed:int}
	 */
	public function dispatch( array $ids ): array {
		$sent   = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE id = %d AND status IN (%s, %s) AND recipient_email IS NOT NULL", $id, self::STATUS_QUEUED, self::STATUS_FAILED ) );
			if ( null === $row ) {
				continue;
			}
			if ( $this->send( $row ) ) {
				++$sent;
			} else {
				++$failed;
			}
		}
		return array(
			'sent'   => $sent,
			'failed' => $failed,
		);
	}

	/**
	 * Retry job: failed emails whose backoff has elapsed, and emails stuck in
	 * QUEUED. Bounded per run. Never throws.
	 *
	 * @return array{sent:int,failed:int}
	 */
	public function process_due( int $limit = 50 ): array {
		$now   = $this->clock->now_mysql();
		$stuck = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() - self::STUCK_AFTER_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		$ids = array_map(
			'intval',
			$this->db->get_col(
				$this->db->prepare(
					"SELECT id FROM {$this->table()}
					WHERE recipient_email IS NOT NULL AND (
						( status = %s AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s AND attempts < max_attempts )
						OR ( status = %s AND created_at <= %s )
					)
					ORDER BY id ASC LIMIT %d",
					self::STATUS_FAILED,
					$now,
					self::STATUS_QUEUED,
					$stuck,
					$limit
				)
			)
		);
		return $this->dispatch( $ids );
	}

	/**
	 * Manual retry from the monitoring screen (notifications.retry). Works even
	 * after automatic retries are exhausted. Audited with the outcome.
	 *
	 * @throws NotFoundException|ConflictException
	 */
	public function retry( int $id ): bool {
		$this->authorizer->require( 'notifications.retry' );
		$row = $this->find( $id );
		if ( null === $row ) {
			throw new NotFoundException();
		}
		if ( self::STATUS_FAILED !== $row->status || null === $row->recipient_email ) {
			throw new ConflictException( __( 'Only failed emails that have not been cancelled can be retried.', 'dms' ), 'notification_state' );
		}
		$ok = $this->send( $row );
		$this->audit->record(
			AuditAction::NOTIFICATION_RETRIED,
			array(
				'registration_id' => $row->registration_id ? (int) $row->registration_id : null,
				'object_type'     => 'notification',
				'object_id'       => $id,
				'metadata'        => array(
					'event'  => $row->event,
					'result' => $ok ? 'sent' : 'failed',
				),
			)
		);
		return $ok;
	}

	public function find( int $id ): ?object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
	}

	/** @return array<string,int> status => count */
	public function stats(): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status" );
		$out  = array_fill_keys( array( self::STATUS_QUEUED, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_CANCELLED ), 0 );
		foreach ( $rows as $row ) {
			$out[ $row->status ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Monitoring list. The message body is never returned (it can contain personal data).
	 *
	 * @param array<string,mixed> $filters status, event, page, per_page
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	public function search( array $filters ): array {
		$where  = array( '1 = 1' );
		$params = array();
		$status = strtoupper( sanitize_key( (string) ( $filters['status'] ?? '' ) ) );
		if ( in_array( $status, array( self::STATUS_QUEUED, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_CANCELLED ), true ) ) {
			$where[]  = 'n.status = %s';
			$params[] = $status;
		}
		$event = strtoupper( sanitize_key( (string) ( $filters['event'] ?? '' ) ) );
		if ( isset( EmailTemplates::defaults()[ $event ] ) ) {
			$where[]  = 'n.event = %s';
			$params[] = $event;
		}
		$per_page = min( 100, max( 1, absint( $filters['per_page'] ?? 50 ) ) );
		$page     = max( 1, absint( $filters['page'] ?? 1 ) );
		$regs     = Tables::name( Tables::REGISTRATIONS );
		$clause   = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- fixed fragments; values are placeholders.
		$items = $this->db->get_results(
			$this->db->prepare(
				"SELECT n.id, n.event, n.registration_id, r.registration_number, n.recipient_email, n.subject, n.status, n.attempts, n.max_attempts, n.last_error, n.next_attempt_at, n.sent_at, n.created_at
				FROM {$this->table()} n LEFT JOIN {$regs} r ON r.id = n.registration_id WHERE {$clause} ORDER BY n.id DESC LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		$count = "SELECT COUNT(*) FROM {$this->table()} n WHERE {$clause}";
		$total = (int) ( array() === $params ? $this->db->get_var( $count ) : $this->db->get_var( $this->db->prepare( $count, ...$params ) ) );
		// phpcs:enable
		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	private function send( object $row ): bool {
		$error = null;
		$catch = static function ( \WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );
		try {
			$ok = wp_mail( (string) $row->recipient_email, (string) $row->subject, (string) $row->body );
		} catch ( \Throwable $e ) {
			$ok    = false;
			$error = $e->getMessage();
		} finally {
			remove_action( 'wp_mail_failed', $catch );
		}

		$now      = $this->clock->now_mysql();
		$attempts = (int) $row->attempts + 1;
		if ( $ok ) {
			$this->db->update(
				$this->table(),
				array(
					'status'          => self::STATUS_SENT,
					'attempts'        => $attempts,
					'sent_at'         => $now,
					'last_error'      => null,
					'next_attempt_at' => null,
					'updated_at'      => $now,
				),
				array( 'id' => $row->id )
			);
			return true;
		}

		$error = $error ?? 'wp_mail() returned false';
		$next  = $attempts < (int) $row->max_attempts ? gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + ( 300 * ( 2 ** ( $attempts - 1 ) ) ) ) : null;
		$this->db->update(
			$this->table(),
			array(
				'status'          => self::STATUS_FAILED,
				'attempts'        => $attempts,
				'last_error'      => substr( $error, 0, 1000 ),
				'next_attempt_at' => $next,
				'updated_at'      => $now,
			),
			array( 'id' => $row->id )
		);
		$reference = $this->logger->error(
			'Notification delivery failed',
			array(
				'notification_id' => (int) $row->id,
				'event'           => $row->event,
				'attempt'         => $attempts,
				'error'           => $error,
			)
		);
		try {
			$this->audit->record(
				AuditAction::NOTIFICATION_FAILED,
				array(
					'registration_id' => $row->registration_id ? (int) $row->registration_id : null,
					'object_type'     => 'notification',
					'object_id'       => (int) $row->id,
					'user_id'         => null,
					'metadata'        => array(
						'event'     => $row->event,
						'attempt'   => $attempts,
						'reference' => $reference,
					),
				)
			);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Could not audit notification failure', array( 'notification_id' => (int) $row->id ) );
		}
		return false;
	}
}

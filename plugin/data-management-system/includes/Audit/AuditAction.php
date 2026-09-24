<?php
/**
 * Audit action vocabulary (ARCH §11, §65; MP §21; Decisions §20).
 *
 * @package DMS
 */

namespace DMS\Audit;

defined( 'ABSPATH' ) || exit;

final class AuditAction {

	// Registration lifecycle (ARCH §11).
	public const SUBMITTED      = 'SUBMITTED';
	public const ASSIGNED       = 'ASSIGNED';
	public const REASSIGNED     = 'REASSIGNED';
	public const VIEWED         = 'VIEWED';
	public const REVIEW_STARTED = 'REVIEW_STARTED';
	public const EDITED         = 'EDITED';
	public const NOTE_ADDED     = 'NOTE_ADDED';
	public const APPROVED       = 'APPROVED';
	public const DISAPPROVED    = 'DISAPPROVED';
	public const RESTORED       = 'RESTORED';
	public const DELETED        = 'DELETED';
	public const EXPORTED       = 'EXPORTED';
	public const EDIT_REJECTED  = 'EDIT_REJECTED';

	// Consent & phone verification (ARCH §71.1, §71.3).
	public const CONSENT_RECORDED = 'CONSENT_RECORDED';
	public const OTP_REQUESTED    = 'OTP_REQUESTED';
	public const OTP_VERIFIED     = 'OTP_VERIFIED';
	public const OTP_FAILED       = 'OTP_FAILED';
	public const OTP_LOCKED       = 'OTP_LOCKED';
	public const RATE_LIMITED     = 'RATE_LIMITED';
	public const OTP_SEND_FAILED  = 'OTP_SEND_FAILED';

	// Assignment engine (ARCH §72).
	public const ASSIGNMENT_EXCEPTION          = 'ASSIGNMENT_EXCEPTION';
	public const ASSIGNMENT_EXCEPTION_RESOLVED = 'ASSIGNMENT_EXCEPTION_RESOLVED';

	// Bulk actions (ARCH §74).
	public const BULK_ACTION = 'BULK_ACTION';

	// Retention (ARCH §71.4).
	public const RETENTION_PURGED = 'RETENTION_PURGED';

	// Imports (ARCH §65).
	public const IMPORT_STARTED            = 'IMPORT_STARTED';
	public const IMPORT_VALIDATED          = 'IMPORT_VALIDATED';
	public const IMPORT_CONFIRMED          = 'IMPORT_CONFIRMED';
	public const IMPORT_COMPLETED          = 'IMPORT_COMPLETED';
	public const IMPORT_FAILED             = 'IMPORT_FAILED';
	public const IMPORT_ROLLBACK_REQUESTED = 'IMPORT_ROLLBACK_REQUESTED';
	public const IMPORT_ROLLED_BACK        = 'IMPORT_ROLLED_BACK';
	public const IMPORT_ROLLBACK_BLOCKED   = 'IMPORT_ROLLBACK_BLOCKED';

	// Users, roles, permissions, configuration (MP §21).
	public const USER_CREATED         = 'USER_CREATED';
	public const USER_UPDATED         = 'USER_UPDATED';
	public const USER_DISABLED        = 'USER_DISABLED';
	public const USER_ENABLED         = 'USER_ENABLED';
	public const USER_ROLES_CHANGED   = 'USER_ROLES_CHANGED';
	public const USER_REGIONS_CHANGED = 'USER_REGIONS_CHANGED';
	public const ROLE_CREATED         = 'ROLE_CREATED';
	public const ROLE_UPDATED         = 'ROLE_UPDATED';
	public const ROLE_DELETED         = 'ROLE_DELETED';
	public const PERMISSIONS_CHANGED  = 'PERMISSIONS_CHANGED';
	public const SETTINGS_CHANGED     = 'SETTINGS_CHANGED';
	public const FORM_CHANGED         = 'FORM_CHANGED';

	// Notifications (Decisions §20, "where relevant").
	public const NOTIFICATION_FAILED  = 'NOTIFICATION_FAILED';
	public const NOTIFICATION_RETRIED = 'NOTIFICATION_RETRIED';
}

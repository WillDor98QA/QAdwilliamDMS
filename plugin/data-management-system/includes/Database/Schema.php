<?php
/**
 * Target schema for every plugin table, applied with dbDelta().
 *
 * This is the single description of the current table shape. dbDelta() only
 * creates missing tables and adds missing columns/indexes — it never drops
 * anything — so applying it repeatedly is safe (Master Prompt §7).
 *
 * dbDelta() formatting rules are load-bearing: one column per line, two
 * spaces after PRIMARY KEY, and KEY rather than INDEX. Foreign keys are not
 * supported by dbDelta() and are added by migration M002.
 *
 * All IDs are BIGINT UNSIGNED so foreign-key column types match.
 * All datetimes are UTC.
 *
 * @package DMS
 */

namespace DMS\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** @return array<string,string> Short table name => CREATE TABLE statement. */
	public static function statements(): array {
		global $wpdb;
		$c = $wpdb->get_charset_collate() . ' ENGINE=InnoDB';
		$t = static fn( string $name ): string => Tables::name( $name );

		return array(
			// ARCH §37 — electoral hierarchy. Codes are the stable import identity (ARCH §50, §58).
			Tables::REGIONS               => "CREATE TABLE {$t(Tables::REGIONS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code varchar(50) NOT NULL,
				name varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'ACTIVE',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY status_name (status,name)
			) $c;",

			Tables::CONSTITUENCIES        => "CREATE TABLE {$t(Tables::CONSTITUENCIES)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				region_id bigint(20) unsigned NOT NULL,
				code varchar(50) NOT NULL,
				name varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'ACTIVE',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY region_status_name (region_id,status,name)
			) $c;",

			Tables::POLLING_STATIONS      => "CREATE TABLE {$t(Tables::POLLING_STATIONS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				constituency_id bigint(20) unsigned NOT NULL,
				code varchar(50) NOT NULL,
				name varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'ACTIVE',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY constituency_status_name (constituency_id,status,name)
			) $c;",

			// ARCH §37 + Decisions §8, §13, §15, §21, §37. Personal columns are nullable so a
			// permanently deleted record can become a tombstone (Decisions §21).
			Tables::REGISTRATIONS         => "CREATE TABLE {$t(Tables::REGISTRATIONS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_number varchar(20) NOT NULL,
				first_name varchar(100) NULL,
				middle_name varchar(100) NULL,
				last_name varchar(100) NULL,
				date_of_birth date NULL,
				gender varchar(30) NULL,
				phone varchar(30) NULL,
				phone_normalized varchar(20) NULL,
				phone_verified tinyint(1) NOT NULL DEFAULT 0,
				phone_verified_at datetime NULL,
				email varchar(191) NULL,
				address text NULL,
				organization varchar(191) NULL,
				extra_fields longtext NULL,
				region_id bigint(20) unsigned NOT NULL,
				constituency_id bigint(20) unsigned NOT NULL,
				polling_station_id bigint(20) unsigned NOT NULL,
				consent_given tinyint(1) NOT NULL DEFAULT 0,
				consent_given_at datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'PENDING',
				assigned_officer_id bigint(20) unsigned NULL,
				assigned_at datetime NULL,
				assigned_by bigint(20) unsigned NULL,
				assignment_method varchar(20) NULL,
				reviewed_at datetime NULL,
				approved_at datetime NULL,
				approved_by bigint(20) unsigned NULL,
				disapproved_at datetime NULL,
				disapproved_by bigint(20) unsigned NULL,
				disapproval_reason text NULL,
				deleted_at datetime NULL,
				deleted_by bigint(20) unsigned NULL,
				submitted_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY registration_number (registration_number),
				UNIQUE KEY phone_normalized (phone_normalized),
				KEY status_submitted (status,submitted_at),
				KEY region_status (region_id,status),
				KEY constituency_id (constituency_id),
				KEY polling_station_id (polling_station_id),
				KEY officer_status (assigned_officer_id,status),
				KEY status_disapproved (status,disapproved_at),
				KEY reviewed_at (reviewed_at),
				KEY approved_at (approved_at),
				KEY last_name (last_name),
				KEY first_name (first_name),
				KEY email (email),
				KEY organization (organization)
			) $c;",

			// Year-scoped counters for REG-YYYY-NNNNNN and IMPORT-YYYY-NNNNN (Decisions §7, ARCH §56).
			Tables::SEQUENCES             => "CREATE TABLE {$t(Tables::SEQUENCES)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(50) NOT NULL,
				scope varchar(20) NOT NULL,
				value bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY name_scope (name,scope)
			) $c;",

			// General, append-oriented audit trail (ARCH §11, Decisions §20).
			Tables::AUDIT_LOG             => "CREATE TABLE {$t(Tables::AUDIT_LOG)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_id bigint(20) unsigned NULL,
				object_type varchar(50) NOT NULL,
				object_id bigint(20) unsigned NULL,
				action varchar(64) NOT NULL,
				old_status varchar(20) NULL,
				new_status varchar(20) NULL,
				reason text NULL,
				metadata longtext NULL,
				user_id bigint(20) unsigned NULL,
				actor_type varchar(20) NOT NULL,
				ip_hash varchar(64) NULL,
				correlation_id varchar(36) NULL,
				pii_scrubbed_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY registration_id (registration_id),
				KEY object (object_type,object_id),
				KEY action_created (action,created_at),
				KEY user_created (user_id,created_at),
				KEY created_at (created_at)
			) $c;",

			// ARCH §22 application roles. is_system marks the protected bootstrap Administrator role.
			Tables::ROLES                 => "CREATE TABLE {$t(Tables::ROLES)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(100) NOT NULL,
				slug varchar(100) NOT NULL,
				description text NULL,
				status varchar(20) NOT NULL DEFAULT 'ACTIVE',
				is_system tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) $c;",

			Tables::ROLE_PERMISSIONS      => "CREATE TABLE {$t(Tables::ROLE_PERMISSIONS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				role_id bigint(20) unsigned NOT NULL,
				permission varchar(100) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY role_permission (role_id,permission),
				KEY permission (permission)
			) $c;",

			Tables::USER_ROLES            => "CREATE TABLE {$t(Tables::USER_ROLES)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				role_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_role (user_id,role_id),
				KEY role_id (role_id)
			) $c;",

			// Application account state for native WP users: enable/disable and the
			// assignment tie-breaker timestamp (Decisions §4).
			Tables::USER_PROFILES         => "CREATE TABLE {$t(Tables::USER_PROFILES)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'ACTIVE',
				last_assigned_at datetime(6) NULL,
				disabled_at datetime NULL,
				disabled_by bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				KEY status (status)
			) $c;",

			// Decisions §37 — officers may cover one or more Regions.
			Tables::OFFICER_REGIONS       => "CREATE TABLE {$t(Tables::OFFICER_REGIONS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				region_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'ACTIVE',
				created_by bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_region (user_id,region_id),
				KEY region_status (region_id,status)
			) $c;",

			// ARCH §72.4 / Decisions §4 — no eligible officer.
			Tables::ASSIGNMENT_EXCEPTIONS => "CREATE TABLE {$t(Tables::ASSIGNMENT_EXCEPTIONS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_id bigint(20) unsigned NOT NULL,
				region_id bigint(20) unsigned NOT NULL,
				reason_code varchar(50) NOT NULL,
				details text NULL,
				status varchar(20) NOT NULL DEFAULT 'OPEN',
				created_at datetime NOT NULL,
				resolved_at datetime NULL,
				resolved_by bigint(20) unsigned NULL,
				PRIMARY KEY  (id),
				KEY status_created (status,created_at),
				KEY registration_id (registration_id),
				KEY region_status (region_id,status)
			) $c;",

			// Decisions §9–§11, §37. Only a hash of the OTP is stored. The registration payload
			// is not stored here: it is re-submitted and re-validated with the verification call.
			Tables::OTP_REQUESTS          => "CREATE TABLE {$t(Tables::OTP_REQUESTS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_id varchar(64) NOT NULL,
				phone_normalized varchar(20) NOT NULL,
				otp_hash varchar(255) NULL,
				status varchar(20) NOT NULL,
				is_duplicate tinyint(1) NOT NULL DEFAULT 0,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				send_count smallint(5) unsigned NOT NULL DEFAULT 0,
				last_sent_at datetime NULL,
				expires_at datetime NOT NULL,
				ip_hash varchar(64) NULL,
				verified_at datetime NULL,
				consumed_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_id (request_id),
				KEY phone_created (phone_normalized,created_at),
				KEY status_expires (status,expires_at)
			) $c;",

			// Fixed-window counters for OTP, submission and lookup rate limits (Decisions §39).
			Tables::RATE_LIMITS           => "CREATE TABLE {$t(Tables::RATE_LIMITS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				bucket varchar(191) NOT NULL,
				window_start datetime NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY bucket_window (bucket,window_start),
				KEY expires_at (expires_at)
			) $c;",

			// ARCH §76.4, Decisions §26, §37 — queue + delivery log. dedupe_key prevents duplicate sends.
			Tables::NOTIFICATIONS         => "CREATE TABLE {$t(Tables::NOTIFICATIONS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event varchar(64) NOT NULL,
				registration_id bigint(20) unsigned NULL,
				recipient_email varchar(191) NULL,
				recipient_user_id bigint(20) unsigned NULL,
				channel varchar(20) NOT NULL DEFAULT 'email',
				subject varchar(255) NULL,
				body longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'QUEUED',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
				last_error text NULL,
				next_attempt_at datetime NULL,
				sent_at datetime NULL,
				dedupe_key varchar(191) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY status_next (status,next_attempt_at),
				KEY registration_id (registration_id)
			) $c;",

			// ARCH §56.
			Tables::IMPORT_BATCHES        => "CREATE TABLE {$t(Tables::IMPORT_BATCHES)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				import_reference varchar(30) NOT NULL,
				entity_type varchar(30) NOT NULL,
				filename varchar(255) NOT NULL,
				file_hash char(64) NOT NULL,
				stored_file varchar(255) NULL,
				mode varchar(20) NOT NULL DEFAULT 'UPSERT',
				total_rows int(10) unsigned NOT NULL DEFAULT 0,
				created_count int(10) unsigned NOT NULL DEFAULT 0,
				updated_count int(10) unsigned NOT NULL DEFAULT 0,
				unchanged_count int(10) unsigned NOT NULL DEFAULT 0,
				error_count int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL,
				summary longtext NULL,
				uploaded_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				completed_at datetime NULL,
				rolled_back_at datetime NULL,
				rolled_back_by bigint(20) unsigned NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY import_reference (import_reference),
				KEY status_created (status,created_at)
			) $c;",

			// Background exports (ARCH §75 "large exports … asynchronous", MP §27). Files live in a
			// protected uploads folder and expire after 24 hours.
			Tables::EXPORT_JOBS           => "CREATE TABLE {$t(Tables::EXPORT_JOBS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_key char(32) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				area varchar(20) NOT NULL,
				format varchar(10) NOT NULL,
				filters longtext NULL,
				status varchar(20) NOT NULL,
				total_rows int(10) unsigned NOT NULL DEFAULT 0,
				file_path varchar(255) NULL,
				error text NULL,
				created_at datetime NOT NULL,
				completed_at datetime NULL,
				expires_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY job_key (job_key),
				KEY user_status (user_id,status),
				KEY expires_at (expires_at)
			) $c;",

			// ARCH §57 — before/after values for rollback. source_row avoids the reserved word ROW_NUMBER.
			Tables::IMPORT_ITEMS          => "CREATE TABLE {$t(Tables::IMPORT_ITEMS)} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				import_batch_id bigint(20) unsigned NOT NULL,
				entity_type varchar(30) NOT NULL,
				record_id bigint(20) unsigned NULL,
				record_code varchar(50) NOT NULL,
				source_row int(10) unsigned NULL,
				action varchar(20) NOT NULL,
				previous_values longtext NULL,
				new_values longtext NULL,
				message text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY batch_action (import_batch_id,action),
				KEY entity_record (entity_type,record_id)
			) $c;",
		);
	}

	/**
	 * Foreign keys added by M002. Parents of registrations use RESTRICT so the
	 * database itself refuses to orphan a registration (ARCH §61, MP §19).
	 * The audit log intentionally has no FK: it must outlive what it describes.
	 *
	 * @return list<array{table:string,name:string,column:string,ref_table:string,ref_column:string,on_delete:string}>
	 */
	public static function foreign_keys(): array {
		$fk = static fn( string $table, string $column, string $ref, string $on_delete ): array => array(
			'table'      => $table,
			'name'       => 'dms_fk_' . $table . '_' . $column,
			'column'     => $column,
			'ref_table'  => $ref,
			'ref_column' => 'id',
			'on_delete'  => $on_delete,
		);

		return array(
			$fk( Tables::CONSTITUENCIES, 'region_id', Tables::REGIONS, 'RESTRICT' ),
			$fk( Tables::POLLING_STATIONS, 'constituency_id', Tables::CONSTITUENCIES, 'RESTRICT' ),
			$fk( Tables::REGISTRATIONS, 'region_id', Tables::REGIONS, 'RESTRICT' ),
			$fk( Tables::REGISTRATIONS, 'constituency_id', Tables::CONSTITUENCIES, 'RESTRICT' ),
			$fk( Tables::REGISTRATIONS, 'polling_station_id', Tables::POLLING_STATIONS, 'RESTRICT' ),
			$fk( Tables::ROLE_PERMISSIONS, 'role_id', Tables::ROLES, 'CASCADE' ),
			$fk( Tables::USER_ROLES, 'role_id', Tables::ROLES, 'RESTRICT' ),
			$fk( Tables::OFFICER_REGIONS, 'region_id', Tables::REGIONS, 'RESTRICT' ),
			$fk( Tables::ASSIGNMENT_EXCEPTIONS, 'registration_id', Tables::REGISTRATIONS, 'RESTRICT' ),
			$fk( Tables::IMPORT_ITEMS, 'import_batch_id', Tables::IMPORT_BATCHES, 'RESTRICT' ),
		);
	}
}

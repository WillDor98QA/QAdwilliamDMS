<?php
/**
 * Which registration columns are personal data and which are editable.
 *
 * @package DMS
 */

namespace DMS\Registrations;

defined( 'ABSPATH' ) || exit;

final class RegistrationFields {

	/**
	 * Personal data: nulled on tombstoning and scrubbed from audit metadata (Decisions §21).
	 * Electoral IDs, status, timestamps and actor IDs are operational, not personal, and are kept.
	 */
	public const PII = array(
		'first_name',
		'middle_name',
		'last_name',
		'date_of_birth',
		'gender',
		'phone',
		'phone_normalized',
		'email',
		'address',
		'organization',
		'extra_fields',
	);

	/**
	 * Columns an authorized user may edit before approval (ARCH §71.2).
	 * Phone is excluded: it was proven by OTP and is the duplicate key, so
	 * changing it would bypass verification (OD-20, approved).
	 * Status and assignment columns change only through workflow transitions.
	 */
	public const EDITABLE = array(
		'first_name',
		'middle_name',
		'last_name',
		'date_of_birth',
		'gender',
		'email',
		'address',
		'organization',
		'extra_fields',
		'region_id',
		'constituency_id',
		'polling_station_id',
	);

	public const ELECTORAL = array( 'region_id', 'constituency_id', 'polling_station_id' );
}

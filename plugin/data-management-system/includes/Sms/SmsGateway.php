<?php
/**
 * Provider-agnostic SMS sending (Decisions §12).
 *
 * A production provider is added as one class implementing this interface,
 * registered through the `dms_sms_gateways` filter. Nothing else in the
 * plugin knows which provider is in use.
 *
 * @package DMS
 */

namespace DMS\Sms;

defined( 'ABSPATH' ) || exit;

interface SmsGateway {

	/** Stable identifier stored in the `sms_gateway` setting. */
	public function id(): string;

	public function label(): string;

	/**
	 * Credential and option fields shown on the SMS settings screen.
	 *
	 * @return list<array{key:string,label:string,secret:bool,required:bool}>
	 */
	public function fields(): array;

	/**
	 * Admin-safe descriptions of what is missing or invalid. Empty = ready.
	 * Must never include credential values.
	 *
	 * @return list<string>
	 */
	public function configuration_problems(): array;

	/** Sends one message to an E.164 number. Must not throw for provider errors. */
	public function send( string $to, string $message ): SmsResult;
}

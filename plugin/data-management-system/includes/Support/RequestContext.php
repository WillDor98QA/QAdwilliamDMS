<?php
/**
 * Per-request facts shared by audit, logging and rate limiting.
 *
 * The client IP is REMOTE_ADDR, unless the request came through a proxy listed
 * in DMS_TRUSTED_PROXIES (see ClientIp). IPs are stored only as keyed hashes.
 *
 * @package DMS
 */

namespace DMS\Support;

defined( 'ABSPATH' ) || exit;

class RequestContext {

	private string $correlation_id;

	public function __construct() {
		$this->correlation_id = wp_generate_uuid4();
	}

	public function correlation_id(): string {
		return $this->correlation_id;
	}

	public function user_id(): int {
		return get_current_user_id();
	}

	public function ip(): string {
		$remote    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		return ClientIp::resolve( $remote, $forwarded, self::trusted_proxies() );
	}

	/**
	 * Proxies allowed to report the client IP: the DMS_TRUSTED_PROXIES constant
	 * (array, or comma-separated string of IPs/CIDRs). Empty by default.
	 *
	 * @return list<string>
	 */
	public static function trusted_proxies(): array {
		$configured = defined( 'DMS_TRUSTED_PROXIES' ) ? constant( 'DMS_TRUSTED_PROXIES' ) : array();
		$list       = is_array( $configured ) ? $configured : explode( ',', (string) $configured );
		$list       = (array) apply_filters( 'dms_trusted_proxies', $list );
		return array_values( array_filter( array_map( static fn( $v ): string => trim( (string) $v ), $list ) ) );
	}

	public function ip_hash(): ?string {
		$ip = $this->ip();
		return '' === $ip ? null : self::hash( $ip );
	}

	/** Keyed hash so stored values cannot be reversed by brute-forcing the IPv4 space. */
	public static function hash( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}
}

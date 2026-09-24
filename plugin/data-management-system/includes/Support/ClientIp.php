<?php
/**
 * Client IP resolution behind trusted reverse proxies (Phase 10, SEC-02).
 *
 * By default only REMOTE_ADDR is used, which cannot be spoofed. When the site
 * sits behind a load balancer or CDN, every visitor arrives from the proxy's
 * address, so per-IP rate limits would be shared by everyone. Listing the
 * proxies in DMS_TRUSTED_PROXIES (wp-config.php) lets the real client be read
 * from X-Forwarded-For — but only when the request really came from one of
 * them, and only up to the first address that is not a trusted proxy, so a
 * client cannot forge its IP by sending its own header.
 *
 * @package DMS
 */

namespace DMS\Support;

defined( 'ABSPATH' ) || exit;

final class ClientIp {

	/**
	 * @param string       $remote_addr     The TCP peer (REMOTE_ADDR).
	 * @param string       $forwarded_for   The X-Forwarded-For header, or ''.
	 * @param list<string> $trusted_proxies IPs or CIDR ranges (IPv4/IPv6).
	 * @return string A valid IP, or '' when none can be determined.
	 */
	public static function resolve( string $remote_addr, string $forwarded_for, array $trusted_proxies ): string {
		$remote = trim( $remote_addr );
		if ( false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( array() === $trusted_proxies || '' === trim( $forwarded_for ) || ! self::in_any( $remote, $trusted_proxies ) ) {
			return $remote;
		}
		// Walk right to left: each hop was appended by the proxy in front of it.
		$hops = array_reverse( array_map( 'trim', explode( ',', $forwarded_for ) ) );
		$last = $remote;
		foreach ( $hops as $hop ) {
			if ( false === filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				return $last; // Garbage in the chain: stop at the last address we can vouch for.
			}
			if ( ! self::in_any( $hop, $trusted_proxies ) ) {
				return $hop;
			}
			$last = $hop;
		}
		return $last;
	}

	/** @param list<string> $ranges */
	public static function in_any( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::in_range( $ip, trim( (string) $range ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function in_range( string $ip, string $range ): bool {
		[ $subnet, $bits ] = array_pad( explode( '/', $range, 2 ), 2, null );
		$ip_bin            = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		$net_bin           = @inet_pton( (string) $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$max  = 8 * strlen( $ip_bin );
		$bits = null === $bits ? $max : ( ctype_digit( $bits ) ? (int) $bits : -1 );
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask );
	}
}

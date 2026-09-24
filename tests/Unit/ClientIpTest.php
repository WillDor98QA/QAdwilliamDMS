<?php
/**
 * REQ-SEC-033 client IP behind trusted proxies (Phase 10, SEC-02).
 */

namespace DMS\Tests\Unit;

use DMS\Support\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase {

	private const LB  = array( '10.0.0.0/8' );
	private const CDN = array( '10.0.0.0/8', '173.245.48.0/20', '2400:cb00::/32' );

	public function test_without_trusted_proxies_the_header_is_ignored(): void {
		$this->assertSame( '203.0.113.9', ClientIp::resolve( '203.0.113.9', '1.2.3.4', array() ) );
	}

	public function test_header_from_an_untrusted_peer_is_ignored(): void {
		// A visitor connecting directly cannot choose their IP by sending the header.
		$this->assertSame( '203.0.113.9', ClientIp::resolve( '203.0.113.9', '1.2.3.4', self::LB ) );
	}

	public function test_client_behind_a_trusted_load_balancer(): void {
		$this->assertSame( '198.51.100.7', ClientIp::resolve( '10.1.2.3', '198.51.100.7', self::LB ) );
	}

	public function test_forged_left_entries_are_skipped(): void {
		// The client sent "X-Forwarded-For: 1.1.1.1"; the load balancer appended the real peer.
		$this->assertSame( '198.51.100.7', ClientIp::resolve( '10.1.2.3', '1.1.1.1, 198.51.100.7', self::LB ) );
	}

	public function test_chain_of_trusted_proxies(): void {
		$this->assertSame( '198.51.100.7', ClientIp::resolve( '10.1.2.3', '198.51.100.7, 173.245.49.1', self::CDN ) );
		$this->assertSame( '2001:db8::5', ClientIp::resolve( '10.1.2.3', '2001:db8::5, 2400:cb00:1::1', self::CDN ) );
	}

	public function test_garbage_in_the_chain_stops_at_the_last_trusted_hop(): void {
		$this->assertSame( '10.1.2.3', ClientIp::resolve( '10.1.2.3', "<script>, 'OR 1=1", self::LB ) );
		$this->assertSame( '173.245.49.1', ClientIp::resolve( '10.1.2.3', 'nonsense, 173.245.49.1', self::CDN ) );
	}

	public function test_all_hops_trusted_returns_the_leftmost(): void {
		$this->assertSame( '10.9.9.9', ClientIp::resolve( '10.1.2.3', '10.9.9.9', self::LB ) );
	}

	public function test_invalid_remote_addr_gives_no_ip(): void {
		$this->assertSame( '', ClientIp::resolve( 'not-an-ip', '198.51.100.7', self::LB ) );
		$this->assertSame( '', ClientIp::resolve( '', '', array() ) );
	}

	/** @return iterable<string,array{string,string,bool}> */
	public static function ranges(): iterable {
		yield 'exact ipv4'          => array( '192.0.2.1', '192.0.2.1', true );
		yield 'ipv4 /24 in'         => array( '192.0.2.200', '192.0.2.0/24', true );
		yield 'ipv4 /24 out'        => array( '192.0.3.1', '192.0.2.0/24', false );
		yield 'ipv4 /20 edge in'    => array( '173.245.63.255', '173.245.48.0/20', true );
		yield 'ipv4 /20 edge out'   => array( '173.245.64.0', '173.245.48.0/20', false );
		yield 'ipv4 /0'             => array( '8.8.8.8', '0.0.0.0/0', true );
		yield 'ipv6 in'             => array( '2400:cb00:abcd::1', '2400:cb00::/32', true );
		yield 'ipv6 out'            => array( '2400:cb01::1', '2400:cb00::/32', false );
		yield 'family mismatch'     => array( '192.0.2.1', '2400:cb00::/32', false );
		yield 'bad prefix'          => array( '192.0.2.1', '192.0.2.0/33', false );
		yield 'non-numeric prefix'  => array( '192.0.2.1', '192.0.2.0/abc', false );
		yield 'garbage range'       => array( '192.0.2.1', 'example.com', false );
	}

	/** @dataProvider ranges */
	public function test_range_matching( string $ip, string $range, bool $expected ): void {
		$this->assertSame( $expected, ClientIp::in_range( $ip, $range ) );
	}
}

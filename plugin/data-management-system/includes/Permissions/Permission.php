<?php
/**
 * A single registered permission.
 *
 * Reserved permissions are declared (so role screens show them consistently
 * with ARCH §17) but grant nothing in V1; CapabilityManager never grants them.
 *
 * @package DMS
 */

namespace DMS\Permissions;

defined( 'ABSPATH' ) || exit;

final class Permission {

	public function __construct(
		public readonly string $key,
		public readonly string $group,
		public readonly string $label,
		public readonly string $description = '',
		public readonly bool $reserved = false,
	) {
	}

	/** Keys are "<group>.<action>" in lowercase with underscores. */
	public static function is_valid_key( string $key ): bool {
		return (bool) preg_match( '/^[a-z][a-z_]*\.[a-z][a-z_]*$/', $key );
	}
}

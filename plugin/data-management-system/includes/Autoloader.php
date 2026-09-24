<?php
/**
 * PSR-4 autoloader for the DMS namespace.
 *
 * The plugin must run without Composer at runtime, so it ships its own loader.
 *
 * @package DMS
 */

namespace DMS;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX = 'DMS\\';

	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}

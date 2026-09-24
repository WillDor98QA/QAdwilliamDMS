<?php
/**
 * Private file folder (exports, uploaded import workbooks).
 *
 * Default: uploads/dms-private, with web access denied by .htaccess (Apache
 * 2.2 and 2.4) and random file names. nginx and other servers ignore
 * .htaccess, so production should either add a deny rule (R-05) or — better —
 * define DMS_PRIVATE_DIR in wp-config.php as an absolute path outside the web
 * root (SEC-04).
 *
 * @package DMS
 */

namespace DMS\Support;

defined( 'ABSPATH' ) || exit;

final class PrivateStorage {

	/** Works on Apache 2.4 (mod_authz_core) and 2.2 (mod_access_compat) without a 500 on either. */
	public const HTACCESS = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	/** Base folder: DMS_PRIVATE_DIR when defined, else uploads/dms-private. */
	public static function base(): string {
		if ( defined( 'DMS_PRIVATE_DIR' ) && '' !== trim( (string) constant( 'DMS_PRIVATE_DIR' ) ) ) {
			return untrailingslashit( (string) constant( 'DMS_PRIVATE_DIR' ) );
		}
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'dms-private';
	}

	public static function dir( string $sub ): string {
		$base = self::base();
		$dir  = $base . '/' . sanitize_key( $sub );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Also upgrades the older single-line rule written before Phase 10.
		if ( ! file_exists( $base . '/.htaccess' ) || self::HTACCESS !== file_get_contents( $base . '/.htaccess' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			file_put_contents( $base . '/.htaccess', self::HTACCESS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}
}

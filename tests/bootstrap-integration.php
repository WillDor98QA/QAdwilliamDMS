<?php
/**
 * Integration-test bootstrap: installs WordPress into the test database,
 * loads the plugin, then runs the plugin migrations ONCE before any test
 * transaction starts (WP_UnitTestCase rewrites CREATE TABLE to temporary
 * tables during tests, and temporary tables cannot hold foreign keys).
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Keep plugin log lines out of test output; inspect .dev/test-php-errors.log when debugging.
@mkdir( dirname( __DIR__ ) . '/.dev', 0700, true );
ini_set( 'error_log', dirname( __DIR__ ) . '/.dev/test-php-errors.log' );

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$wp_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/plugin/data-management-system/data-management-system.php';
	}
);

require $wp_tests_dir . '/includes/bootstrap.php';

// The OTP timing floor (R-01) would add 1.5 s to every OTP test; OtpTimingTest turns it back on.
add_filter( 'dms_otp_min_delivery_ms', '__return_zero' );

// Fresh schema for every run: the suite reinstalls WordPress, so reset our version marker.
global $wpdb;
foreach ( \DMS\Database\Tables::all() as $table ) {
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . \DMS\Database\Tables::name( $table ) );
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
}
delete_option( \DMS\Database\Migrator::VERSION_OPTION );
\DMS\Plugin::reset();
\DMS\Plugin::instance()->migrator()->migrate();

// WP_UnitTestCase owns the transaction; our Transaction helper must nest inside it.
\DMS\Database\Transaction::$externally_managed = true;

<?php
/**
 * wp-phpunit configuration. Credentials come from tests/.env (git-ignored)
 * or the environment — never from this file.
 */

$env_file = __DIR__ . '/.env';
if ( is_readable( $env_file ) ) {
	foreach ( file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		if ( str_starts_with( trim( $line ), '#' ) || ! str_contains( $line, '=' ) ) {
			continue;
		}
		[ $key, $value ] = array_map( 'trim', explode( '=', $line, 2 ) );
		if ( false === getenv( $key ) ) {
			putenv( "{$key}={$value}" );
		}
	}
}

$required = static function ( string $key ): string {
	$value = getenv( $key );
	if ( false === $value ) {
		fwrite( STDERR, "Missing {$key}. Copy tests/.env.example to tests/.env and fill it in.\n" );
		exit( 1 );
	}
	return $value;
};

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', $required( 'DMS_TEST_DB_NAME' ) );
define( 'DB_USER', $required( 'DMS_TEST_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'DMS_TEST_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'DMS_TEST_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'DMS Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// Test-only salts; not used anywhere else.
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $salt ) {
	define( $salt, 'dms-tests-' . $salt );
}

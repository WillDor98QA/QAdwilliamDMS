<?php
// Router for `php -S`: redirect directory requests without a trailing slash
// (e.g. /wp-admin -> /wp-admin/) like Apache/nginx do, so relative admin
// links such as "admin.php?page=..." resolve under /wp-admin/.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ( '/' !== substr( $path, -1 ) && is_dir( $file ) ) {
	$query = isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
	header( 'Location: ' . $path . '/' . $query, true, 301 );
	return true;
}
if ( is_file( $file ) || is_file( rtrim( $file, '/' ) . '/index.php' ) ) {
	return false;
}
chdir( $_SERVER['DOCUMENT_ROOT'] );
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';

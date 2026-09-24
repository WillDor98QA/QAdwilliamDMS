<?php
/**
 * Installs WordPress in the dev site, activates the plugin and seeds demo data.
 * Usage: php seed.php <site-dir> <credentials-file>
 */

[ , $site, $credentials_file ] = $argv;

define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$admin_pass   = wp_generate_password( 20, false );
$officer_pass = wp_generate_password( 16, false );
wp_install( 'DMS Dev', 'admin', 'admin@example.test', false, '', $admin_pass );
switch_theme( 'dms-dev' );
update_option( 'permalink_structure', '' );

wp_set_current_user( 1 );
$result = activate_plugin( 'data-management-system/data-management-system.php' );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->get_error_message() . "\n" );
	exit( 1 );
}
\DMS\Plugin::reset();
$plugin = \DMS\Plugin::instance();
$plugin->capabilities()->register();

use DMS\Electoral\ElectoralLevel;

$el    = $plugin->electoral();
$tree  = array(
	'GAR' => array( 'Greater Accra', array( 'GAR-001' => array( 'Ablekuma Central', array( 'PS-001' => 'Station 001', 'PS-002' => 'Station 002' ) ), 'GAR-002' => array( 'Ablekuma North', array( 'PS-003' => 'Station 003' ) ) ) ),
	'ASH' => array( 'Ashanti', array( 'ASH-001' => array( 'Asokwa', array( 'PS-101' => 'Asokwa Station A' ) ) ) ),
);
$regions = array();
foreach ( $tree as $rcode => [ $rname, $consts ] ) {
	$regions[ $rcode ] = $el->insert( ElectoralLevel::REGION, $rcode, $rname );
	foreach ( $consts as $ccode => [ $cname, $stations ] ) {
		$cid = $el->insert( ElectoralLevel::CONSTITUENCY, $ccode, $cname, $regions[ $rcode ] );
		foreach ( $stations as $scode => $sname ) {
			$el->insert( ElectoralLevel::POLLING_STATION, $scode, $sname, $cid );
		}
	}
}

$officer_role = (int) $plugin->roles()->find_by_slug( 'verification-officer' )->id;
$users        = $plugin->user_service();
foreach ( array( 'william' => 'William', 'kwame' => 'Kwame' ) as $login => $first ) {
	$users->create(
		array(
			'first_name' => $first,
			'last_name'  => 'Officer',
			'email'      => $login . '@example.test',
			'username'   => $login,
			'password'   => $officer_pass,
			'role_ids'   => array( $officer_role ),
			'region_ids' => array( $regions['GAR'] ),
		)
	);
}

$plugin->settings()->update( array( 'admin_notification_emails' => array( 'admin@example.test' ) ) );
$page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Register',
		'post_content' => '[dms_registration_form]',
	)
);

file_put_contents(
	$credentials_file,
	"Site: " . home_url( '/' ) . "\nForm: " . home_url( '/?page_id=' . $page ) . "\nAdmin: admin / {$admin_pass}\nOfficers (Greater Accra): william, kwame / {$officer_pass}\n"
);
chmod( $credentials_file, 0600 );
echo "Seeded.\n";

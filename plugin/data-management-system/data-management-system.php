<?php
/**
 * Plugin Name:       Data Management System
 * Description:       Public registration, approval workflow, electoral master data, audit trail and reporting.
 * Version:           0.2.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            QAdwilliam
 * License:           Proprietary
 * Text Domain:       dms
 *
 * @package DMS
 */

defined( 'ABSPATH' ) || exit;

define( 'DMS_VERSION', '0.2.2' );
define( 'DMS_PLUGIN_FILE', __FILE__ );
define( 'DMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once DMS_PLUGIN_DIR . 'includes/Autoloader.php';
\DMS\Autoloader::register( DMS_PLUGIN_DIR . 'includes/' );

register_activation_hook( __FILE__, array( \DMS\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \DMS\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \DMS\Plugin::class, 'boot' ) );

<?php
/**
 * Plugin Name: Pixel Solution
 * Plugin URI:  https://mindcloudsiedlce.pl
 * Description: Meta Pixel (browser) + Conversions API (server) z panelem admina.
 * Version:     1.0.0
 * Author:      Tomasz Kalinowski — MCS Mind Cloud Siedlce
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCS_PIXEL_VERSION', '1.0.0' );
define( 'MCS_PIXEL_DIR', plugin_dir_path( __FILE__ ) );

require_once MCS_PIXEL_DIR . 'includes/class-pixel.php';
require_once MCS_PIXEL_DIR . 'includes/class-capi.php';
require_once MCS_PIXEL_DIR . 'includes/class-events.php';

register_activation_hook( __FILE__, 'mcs_pixel_activate' );
function mcs_pixel_activate() {
	add_option( 'mcs_pixel_id', '' );
	add_option( 'mcs_capi_token', '' );
	add_option( 'mcs_test_event_code', '' );
}

register_deactivation_hook( __FILE__, 'mcs_pixel_deactivate' );
function mcs_pixel_deactivate() {
	// Opcje pozostają po dezaktywacji; usuwa je uninstall.php.
}

add_action( 'admin_menu', 'mcs_pixel_register_menu' );
function mcs_pixel_register_menu() {
	require_once MCS_PIXEL_DIR . 'admin/settings-page.php';
	add_options_page(
		'MCS Meta Pixel',
		'MCS Meta Pixel',
		'manage_options',
		'mcs-meta-pixel',
		'mcs_pixel_render_settings_page'
	);
}

$mcs_pixel  = new MCS_Pixel();
$mcs_capi   = new MCS_CAPI();
$mcs_events = new MCS_Events( $mcs_pixel, $mcs_capi );
$mcs_events->register_hooks();

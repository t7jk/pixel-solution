<?php
/**
 * Plugin Name: Pixel Solution
 * Plugin URI:  https://x.com/tomas3man
 * Description: Sends events to Meta via browser Pixel and server-side Conversions API (CAPI). Supports PageView, ViewContent and Lead with automatic Contact Form 7 integration and event deduplication.
 * Version:     1.1.0
 * Author:      Tomasz Kalinowski
 * Author URI:  https://x.com/tomas3man
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIXEL_SOLUTION_VERSION', '1.1.0' );
define( 'PIXEL_SOLUTION_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXEL_SOLUTION_SLUG', 'pixel-solution' );

require_once PIXEL_SOLUTION_DIR . 'includes/class-log.php';
require_once PIXEL_SOLUTION_DIR . 'includes/class-pixel.php';
require_once PIXEL_SOLUTION_DIR . 'includes/class-capi.php';
require_once PIXEL_SOLUTION_DIR . 'includes/class-events.php';

register_activation_hook( __FILE__, 'pixel_solution_activate' );
function pixel_solution_activate() {
	add_option( 'mcs_pixel_id', '' );
	add_option( 'mcs_capi_token', '' );
	add_option( 'mcs_test_event_code', '' );
}

register_deactivation_hook( __FILE__, 'pixel_solution_deactivate' );
function pixel_solution_deactivate() {
	// Options persist after deactivation; removed by uninstall.php.
}

add_action( 'admin_menu', 'pixel_solution_register_menu' );
function pixel_solution_register_menu() {
	require_once PIXEL_SOLUTION_DIR . 'admin/settings-page.php';
	add_options_page(
		'Pixel Solution',
		'PixelSolution',
		'manage_options',
		PIXEL_SOLUTION_SLUG,
		'pixel_solution_render_settings_page'
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pixel_solution_action_links' );
function pixel_solution_action_links( $links ) {
	$settings_link = '<a href="' . admin_url( 'options-general.php?page=' . PIXEL_SOLUTION_SLUG ) . '">Settings</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

$pixel_solution_pixel  = new MCS_Pixel();
$pixel_solution_capi   = new MCS_CAPI();
$pixel_solution_events = new MCS_Events( $pixel_solution_pixel, $pixel_solution_capi );
$pixel_solution_events->register_hooks();

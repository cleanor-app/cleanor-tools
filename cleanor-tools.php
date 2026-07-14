<?php
/**
 * Plugin Name:       Cleanor: Image Compressor & Converter
 * Description:        Automatically compress and convert Media Library images to WebP or AVIF via Cleanor Labs. Faster pages, better Core Web Vitals, less storage. Bulk-optimize existing images in one click.
 * Version:           0.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cleanor Labs
 * Author URI:        https://cleanor.app/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cleanor-tools
 * Domain Path:       /languages
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'CLEANOR_TOOLS_VERSION', '0.6.0' );
define( 'CLEANOR_TOOLS_FILE', __FILE__ );
define( 'CLEANOR_TOOLS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLEANOR_TOOLS_URL', plugin_dir_url( __FILE__ ) );

/** Default REST endpoint. Overridable in Settings (or via the filter below). */
define( 'CLEANOR_TOOLS_DEFAULT_ENDPOINT', 'https://mcp.cleanor.app' );

require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-settings.php';
require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-api.php';
require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-local.php';
require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-optimizer.php';
require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-bulk.php';
require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-restore.php';
require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-serve.php';
require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-cleanup.php';
require_once CLEANOR_TOOLS_DIR . 'includes/class-cleanor-admin.php';

/**
 * Boot the plugin once all plugins are loaded.
 */
function cleanor_tools_init() {
	$settings = new Cleanor_Settings();
	$settings->hooks();

	$api = new Cleanor_API( $settings );
	$api->hooks();

	$local     = new Cleanor_Local();
	$optimizer = new Cleanor_Optimizer( $settings, $api, $local );
	$optimizer->hooks();

	$bulk = new Cleanor_Bulk( $settings, $optimizer );
	$bulk->hooks();

	$restore = new Cleanor_Restore( $settings );
	$restore->hooks();

	// Front-end <picture> delivery for non-destructive ("keep") mode.
	$serve = new Cleanor_Serve( $settings );
	$serve->hooks();

	// CleanUp: tidy sibling files on delete + reclaim-space tools.
	$cleanup = new Cleanor_Cleanup( $settings );
	$cleanup->hooks();

	// Branded cabinet: owns the top-level menu + shared assets for all screens.
	$admin = new Cleanor_Admin( $settings, $bulk, $restore, $cleanup );
	$admin->hooks();
}
add_action( 'plugins_loaded', 'cleanor_tools_init' );

/**
 * On activation, seed default options.
 */
function cleanor_tools_activate() {
	Cleanor_Settings::seed_defaults();
}
register_activation_hook( __FILE__, 'cleanor_tools_activate' );

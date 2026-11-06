<?php
/**
 * Plugin Name:       AJ Core HOA
 * Description:       Homeowners association extension for AJ Core. Requires the AJ Core plugin. Currently a roadmap only: no features are active yet.
 * Version:           0.1.1
 * Requires PHP:      7.4
 * Author:            IT Spector LLC
 * Author URI:        https://itspector.com
 * Update URI:        false
 * License:           GPL-2.0+
 * Text Domain:       ajcore-hoa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'AJCORE_HOA_VERSION', '0.1.1' );
// Lowest AJCore extension API this plugin works with (see AJCORE_EXTENSION_API in AJCore).
define( 'AJCORE_HOA_MIN_EXTENSION_API', 24 );
define( 'AJCORE_HOA_BASENAME', plugin_basename( __FILE__ ) );

require_once plugin_dir_path( __FILE__ ) . 'includes/admin-roadmap.php';

/**
 * Plugins load alphabetically by path ("ajcore-hoa/" sorts before "ajcore/"), so AJCore isn't loaded yet
 * when this file runs. Check at plugins_loaded instead.
 */
function ajcore_hoa_boot() {
	if ( ! class_exists( 'AJCore_Extensions' ) || AJCORE_HOA_MIN_EXTENSION_API > (int) ( defined( 'AJCORE_EXTENSION_API' ) ? AJCORE_EXTENSION_API : 0 ) ) {
		add_action( 'admin_notices', 'ajcore_hoa_missing_core_notice' );
		return;
	}

	AJCore_Extensions::register(
		'ajcore-hoa',
		array(
			'name'        => 'AJCore HOA',
			'version'     => AJCORE_HOA_VERSION,
			'diagnostics' => 'ajcore_hoa_diagnostics',
		)
	);
}
add_action( 'plugins_loaded', 'ajcore_hoa_boot' );

function ajcore_hoa_missing_core_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html(
		sprintf(
			/* translators: %d: minimum AJCore extension API version */
			__( 'AJ Core HOA needs the AJ Core plugin (extension API %d or newer). It is not active until AJ Core is installed and up to date.', 'ajcore-hoa' ),
			AJCORE_HOA_MIN_EXTENSION_API
		)
	) . '</p></div>';
}

/** Shown on AJ Core, Extensions. */
function ajcore_hoa_diagnostics() {
	return array(
		'Extension API' => ( defined( 'AJCORE_EXTENSION_API' ) ? AJCORE_EXTENSION_API : '?' ) . ' (min ' . AJCORE_HOA_MIN_EXTENSION_API . ')',
		'Status'        => 'Roadmap only, no features active',
	);
}

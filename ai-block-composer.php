<?php
/**
 * Plugin Name:       AI Block Composer
 * Description:        Experimental. Generate WordPress native (core) block sections and pages from AI prompts, right inside the editor.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            You
 * License:           GPL-2.0-or-later
 * Text Domain:       ai-block-composer
 *
 * @package AI_Block_Composer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABC_VERSION', '0.2.0' );
define( 'ABC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABC_URL', plugin_dir_url( __FILE__ ) );

require_once ABC_DIR . 'includes/class-serializer.php';
require_once ABC_DIR . 'includes/class-theme-context.php';
require_once ABC_DIR . 'includes/class-anthropic-provider.php';
require_once ABC_DIR . 'includes/class-openai-compatible-provider.php';
require_once ABC_DIR . 'includes/class-rest-controller.php';
require_once ABC_DIR . 'includes/class-settings.php';

/**
 * Boot the plugin.
 */
function abc_boot() {
	( new ABC_Settings() )->register();
	( new ABC_REST_Controller() )->register();
	add_action( 'enqueue_block_editor_assets', 'abc_enqueue_editor_assets' );
}
add_action( 'plugins_loaded', 'abc_boot' );

/**
 * Load the sidebar into the block editor.
 */
function abc_enqueue_editor_assets() {
	wp_enqueue_script(
		'ai-block-composer-editor',
		ABC_URL . 'assets/editor.js',
		array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-api-fetch', 'wp-i18n' ),
		ABC_VERSION,
		true
	);
}

/**
 * Nudge the user to add a key if it's missing.
 */
function abc_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_option( 'abc_api_key', '' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && 'settings_page_ai-block-composer' === $screen->id ) {
		return;
	}
	echo '<div class="notice notice-info is-dismissible"><p><strong>AI Block Composer:</strong> pick a provider and add an API key under <a href="' . esc_url( admin_url( 'options-general.php?page=ai-block-composer' ) ) . '">Settings &rarr; AI Block Composer</a> to start generating. No Anthropic credits? Groq/Cerebras run Llama 3.3 70B free.</p></div>';
}
add_action( 'admin_notices', 'abc_admin_notice' );

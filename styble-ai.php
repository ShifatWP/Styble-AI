<?php
/**
 * Plugin Name:       Styble AI
 * Description:        Experimental. Generate Styble block sections and pages from AI prompts, right inside the editor. Forked from AI Block Composer; still emits core blocks until the Styble applier lands.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            You
 * License:           GPL-2.0-or-later
 * Text Domain:       styble-ai
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STYBLE_AI_VERSION', '0.1.0' );
define( 'STYBLE_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'STYBLE_AI_URL', plugin_dir_url( __FILE__ ) );

require_once STYBLE_AI_DIR . 'includes/class-serializer.php';
require_once STYBLE_AI_DIR . 'includes/class-theme-context.php';
// The Styble pipeline: catalog -> prompt/tool schema -> (validator) -> applier.
// Loaded but not yet wired into the REST route; that swap happens with the applier.
require_once STYBLE_AI_DIR . 'includes/class-catalog.php';
require_once STYBLE_AI_DIR . 'includes/class-brand-context.php';
require_once STYBLE_AI_DIR . 'includes/class-prompt.php';
require_once STYBLE_AI_DIR . 'includes/class-validation-result.php';
require_once STYBLE_AI_DIR . 'includes/class-validator.php';
require_once STYBLE_AI_DIR . 'includes/class-anthropic-provider.php';
require_once STYBLE_AI_DIR . 'includes/class-openai-compatible-provider.php';
require_once STYBLE_AI_DIR . 'includes/class-rest-controller.php';
require_once STYBLE_AI_DIR . 'includes/class-settings.php';

/**
 * Boot the plugin.
 */
function styble_ai_boot() {
	( new Styble_AI_Settings() )->register();
	( new Styble_AI_REST_Controller() )->register();
	add_action( 'enqueue_block_editor_assets', 'styble_ai_enqueue_editor_assets' );
}
add_action( 'plugins_loaded', 'styble_ai_boot' );

/**
 * Load the sidebar into the block editor.
 */
function styble_ai_enqueue_editor_assets() {
	wp_enqueue_script(
		'styble-ai-editor',
		STYBLE_AI_URL . 'assets/editor.js',
		array( 'wp-plugins', 'wp-edit-post', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-data', 'wp-blocks', 'wp-api-fetch', 'wp-i18n' ),
		STYBLE_AI_VERSION,
		true
	);
}

/**
 * Nudge the user to add a key if it's missing.
 */
function styble_ai_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_option( 'styble_ai_api_key', '' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && 'settings_page_styble-ai' === $screen->id ) {
		return;
	}
	echo '<div class="notice notice-info is-dismissible"><p><strong>Styble AI:</strong> pick a provider and add an API key under <a href="' . esc_url( admin_url( 'options-general.php?page=styble-ai' ) ) . '">Settings &rarr; Styble AI</a> to start generating. No Anthropic credits? Groq/Cerebras run Llama 3.3 70B free.</p></div>';
}
add_action( 'admin_notices', 'styble_ai_admin_notice' );

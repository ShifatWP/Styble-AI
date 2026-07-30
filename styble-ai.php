<?php
/**
 * Plugin Name:       Styble AI
 * Description:        Experimental. Generate Styble block sections from AI prompts, right inside the editor. The model emits a validated JSON tree; the applier builds real Styble blocks.
 * Requires Plugins:  styble-pro
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

// The pipeline: catalog -> prompt/tool schema -> provider -> validator ->
// corrective retry -> tree -> applier. Two appliers, one contract:
//   in the editor  assets/applier.js   createBlock() -> insertBlocks
//   headless       class-page-applier  tree -> block markup -> a draft page
// Neither writes block HTML from the model; the tree is validated first.
require_once STYBLE_AI_DIR . 'includes/class-catalog.php';
require_once STYBLE_AI_DIR . 'includes/class-brand-context.php';
require_once STYBLE_AI_DIR . 'includes/class-prompt.php';
require_once STYBLE_AI_DIR . 'includes/class-validation-result.php';
require_once STYBLE_AI_DIR . 'includes/class-validator.php';
// Before the providers: both record every response through it.
require_once STYBLE_AI_DIR . 'includes/class-usage-tracker.php';
require_once STYBLE_AI_DIR . 'includes/class-anthropic-provider.php';
require_once STYBLE_AI_DIR . 'includes/class-openai-compatible-provider.php';
require_once STYBLE_AI_DIR . 'includes/class-provider-factory.php';
require_once STYBLE_AI_DIR . 'includes/class-generator.php';
require_once STYBLE_AI_DIR . 'includes/class-media.php';
require_once STYBLE_AI_DIR . 'includes/class-page-applier.php';
require_once STYBLE_AI_DIR . 'includes/class-page-planner.php';
require_once STYBLE_AI_DIR . 'includes/class-page-store.php';
require_once STYBLE_AI_DIR . 'includes/class-page-model.php';
require_once STYBLE_AI_DIR . 'includes/class-rest-controller.php';
require_once STYBLE_AI_DIR . 'includes/class-chat-controller.php';
require_once STYBLE_AI_DIR . 'includes/class-chat-page.php';
require_once STYBLE_AI_DIR . 'includes/class-usage-page.php';
require_once STYBLE_AI_DIR . 'includes/class-settings.php';

/**
 * Boot the plugin.
 */
function styble_ai_boot() {
	( new Styble_AI_Chat_Page() )->register();
	( new Styble_AI_Usage_Page() )->register();
	( new Styble_AI_Settings() )->register();
	( new Styble_AI_REST_Controller() )->register();
	( new Styble_AI_Chat_Controller() )->register();
	add_action( 'enqueue_block_editor_assets', 'styble_ai_enqueue_editor_assets' );
}
add_action( 'plugins_loaded', 'styble_ai_boot' );

/**
 * Load the applier and the sidebar into the block editor.
 */
function styble_ai_enqueue_editor_assets() {
	wp_enqueue_script(
		'styble-ai-applier',
		STYBLE_AI_URL . 'assets/applier.js',
		array( 'wp-blocks' ),
		STYBLE_AI_VERSION,
		true
	);

	// Only the layout table reaches the browser, not the whole catalog: the
	// applier needs nothing else, because the tree is validated server-side.
	// A missing catalog is left to the REST route to report — it can explain
	// itself, whereas a broken editor script cannot.
	$layouts = array();
	try {
		$layouts = Styble_AI_Catalog::from_file()->layouts();
	} catch ( RuntimeException $e ) {
		$layouts = array();
	}
	wp_add_inline_script(
		'styble-ai-applier',
		'window.stybleAI = window.stybleAI || {}; window.stybleAI.layouts = '
			. wp_json_encode( $layouts ) . ';',
		'before'
	);

	wp_enqueue_script(
		'styble-ai-editor',
		STYBLE_AI_URL . 'assets/editor.js',
		array( 'styble-ai-applier', 'wp-plugins', 'wp-edit-post', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-data', 'wp-blocks', 'wp-api-fetch', 'wp-i18n' ),
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
	if ( $screen && Styble_AI_Settings::hook_suffix() === $screen->id ) {
		return;
	}
	echo '<div class="notice notice-info is-dismissible"><p><strong>Styble AI:</strong> pick a provider and add an API key under <a href="' . esc_url( admin_url( 'admin.php?page=' . Styble_AI_Settings::SLUG ) ) . '">Styble AI &rarr; Settings</a> to start generating. No Anthropic credits? Gemini 2.0 Flash is free and handles the nested layout schema.</p></div>';
}
add_action( 'admin_notices', 'styble_ai_admin_notice' );

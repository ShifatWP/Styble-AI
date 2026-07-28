<?php
/**
 * The AI Chat admin screen: top-level "Styble AI" menu, chat on the left, a live
 * preview of the generated page on the right.
 *
 * This screen deliberately does NOT load the block editor. Building the page
 * headlessly (Styble_AI_Page_Applier) and previewing the real frontend render is
 * both simpler and more honest than mounting a hidden editor here — what the
 * user sees in the iframe is the page a visitor would get.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Chat_Page {

	/**
	 * Menu slug — also the plugin's top-level menu.
	 */
	const SLUG = 'styble-ai';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'Styble AI', 'styble-ai' ),
			__( 'Styble AI', 'styble-ai' ),
			'edit_pages',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-superhero-alt',
			58
		);

		// Without this the auto-created first submenu item repeats the menu title.
		add_submenu_page(
			self::SLUG,
			__( 'Styble AI Chat', 'styble-ai' ),
			__( 'AI Chat', 'styble-ai' ),
			'edit_pages',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @return string The screen id this page renders under.
	 */
	public static function hook_suffix() {
		return 'toplevel_page_' . self::SLUG;
	}

	/**
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function assets( $hook ) {
		if ( self::hook_suffix() !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'styble-ai-chat',
			STYBLE_AI_URL . 'assets/chat.css',
			array( 'wp-components' ),
			STYBLE_AI_VERSION
		);

		wp_enqueue_script(
			'styble-ai-chat',
			STYBLE_AI_URL . 'assets/chat.js',
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ),
			STYBLE_AI_VERSION,
			true
		);

		wp_add_inline_script(
			'styble-ai-chat',
			'window.stybleAIChat = ' . wp_json_encode(
				array(
					'hasKey'      => Styble_AI_Provider_Factory::has_key(),
					'settingsUrl' => admin_url( 'admin.php?page=' . Styble_AI_Settings::SLUG ),
					'pagesUrl'    => admin_url( 'edit.php?post_type=page' ),
					'examples'    => array(
						__( 'Create a pricing page for a WordPress plugin with three plans.', 'styble-ai' ),
						__( 'Build an about page for a two-person design studio in Lisbon.', 'styble-ai' ),
						__( 'A services page for a local accounting firm, with an FAQ.', 'styble-ai' ),
						__( 'A landing page for a productivity app launching next month.', 'styble-ai' ),
					),
				)
			) . ';',
			'before'
		);
	}

	public function render() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		?>
		<div class="wrap styble-ai-chat-wrap">
			<div id="styble-ai-chat-root">
				<p><?php esc_html_e( 'Loading Styble AI…', 'styble-ai' ); ?></p>
			</div>
		</div>
		<?php
	}
}

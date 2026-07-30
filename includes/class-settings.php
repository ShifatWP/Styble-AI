<?php
/**
 * Settings page (top-level admin menu -> Styble AI).
 * Experimental: the API key is stored in wp_options in plaintext. Fine for a
 * local/dev experiment; for production move to the proxy/credits model instead
 * of storing user keys.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Settings {

	/**
	 * Submenu slug, under the Styble AI top-level menu owned by the chat screen.
	 */
	const SLUG = 'styble-ai-settings';

	public function register() {
		// Priority 20: the chat screen registers the top-level menu at the
		// default 10, and a submenu cannot be attached before its parent exists.
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_init', array( $this, 'fields' ) );
	}

	/**
	 * @return string The screen id this page renders under.
	 */
	public static function hook_suffix() {
		return 'styble-ai_page_' . self::SLUG;
	}

	public function menu() {
		add_submenu_page(
			Styble_AI_Chat_Page::SLUG,
			__( 'Styble AI Settings', 'styble-ai' ),
			__( 'Settings', 'styble-ai' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function fields() {
		register_setting(
			'styble_ai_settings',
			'styble_ai_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'anthropic',
			)
		);
		// Per-provider key + model, one array. Replaced the single shared
		// `styble_ai_api_key` / `styble_ai_model` pair (roadmap blocker B3):
		// switching provider used to overwrite the only key field, so a target
		// model and a floor model could never be held at once. The legacy options
		// are no longer registered — Styble_AI_Provider_Factory reads them once and
		// migrates, so nothing needs re-pasting.
		register_setting(
			'styble_ai_settings',
			Styble_AI_Provider_Factory::CREDENTIALS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Styble_AI_Provider_Factory', 'sanitize_credentials' ),
				'default'           => array(),
			)
		);
		register_setting(
			'styble_ai_settings',
			'styble_ai_base_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
		register_setting(
			'styble_ai_settings',
			'styble_ai_media_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			)
		);
		register_setting(
			'styble_ai_settings',
			'styble_ai_media_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$provider   = Styble_AI_Provider_Factory::active_provider();
		$creds      = Styble_AI_Provider_Factory::credentials();
		$with_keys  = Styble_AI_Provider_Factory::providers_with_keys();
		$base_url   = get_option( 'styble_ai_base_url', '' );
		$media_prov = get_option( 'styble_ai_media_provider', '' );
		$media_key  = get_option( 'styble_ai_media_key', '' );

		// Provider dropdown: Anthropic (native) + every OpenAI-compatible preset.
		$presets   = Styble_AI_OpenAI_Compatible_Provider::presets();
		// Keep 'model' in step with Styble_AI_REST_Controller::make_provider() —
		// it is shown as the field placeholder, so a stale value here tells the
		// user they will get one model when they would actually get another.
		$providers = array( 'anthropic' => array( 'label' => 'Anthropic — Claude (native)', 'model' => 'claude-opus-5', 'signup' => 'https://console.anthropic.com/settings/keys' ) );
		foreach ( $presets as $id => $p ) {
			$providers[ $id ] = $p;
		}
		// Default-model hints, exposed to the model text field's placeholder via data-*.
		?>
		<div class="wrap">
			<h1>Styble AI</h1>
			<p>Experimental build. Pick a provider, paste that provider's API key, then either generate sections from the editor sidebar or build whole pages from <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Styble_AI_Chat_Page::SLUG ) ); ?>">AI Chat</a>. Requires Styble Pro.</p>
			<p><strong>Model choice matters here.</strong> A section is a nested block tree, and smaller models emit malformed JSON for it — Llama 3.3 70B was measured producing an unparseable tool call. <strong>Claude</strong> is the most reliable; <strong>Gemini 2.0 Flash</strong> is the best free option and also handles image uploads.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'styble_ai_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="styble_ai_provider">Provider</label></th>
						<td>
							<select name="styble_ai_provider" id="styble_ai_provider">
								<?php foreach ( $providers as $id => $p ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"
										data-model="<?php echo esc_attr( $p['model'] ); ?>"
										data-signup="<?php echo esc_attr( $p['signup'] ); ?>"
										<?php selected( $provider, $id ); ?>>
										<?php echo esc_html( $p['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">All non-Anthropic options use the OpenAI-compatible chat API. The model must support function/tool calling.</p>
						</td>
					</tr>
					<?php
					// One key + model row PER PROVIDER, all posted together, only the
					// selected one visible. Every provider's credentials therefore
					// survive a provider switch — which is the entire point of B3.
					// Rendering all of them (rather than renaming one field as the
					// dropdown changes) also means there is no save-ordering problem:
					// the array arrives complete and sanitize_credentials merges it.
					$cred_option = Styble_AI_Provider_Factory::CREDENTIALS_OPTION;
					foreach ( $providers as $id => $p ) :
						$slot     = isset( $creds[ $id ] ) ? $creds[ $id ] : array( 'key' => '', 'model' => '' );
						$is_shown = ( $id === $provider );
						?>
						<tr class="styble-ai-cred-row" data-provider="<?php echo esc_attr( $id ); ?>"<?php echo $is_shown ? '' : ' style="display:none"'; ?>>
							<th scope="row"><label for="styble_ai_key_<?php echo esc_attr( $id ); ?>">API key</label></th>
							<td>
								<input name="<?php echo esc_attr( $cred_option ); ?>[<?php echo esc_attr( $id ); ?>][key]"
									id="styble_ai_key_<?php echo esc_attr( $id ); ?>" type="password" autocomplete="off"
									value="<?php echo esc_attr( $slot['key'] ); ?>" class="regular-text" placeholder="paste key" />
								<p class="description">
									Key for <strong><?php echo esc_html( $p['label'] ); ?></strong>. Stored in your database (plaintext — fine for local/dev).
									<a href="<?php echo esc_url( $p['signup'] ); ?>" target="_blank" rel="noopener">Get a key &rarr;</a>
								</p>
							</td>
						</tr>
						<tr class="styble-ai-cred-row" data-provider="<?php echo esc_attr( $id ); ?>"<?php echo $is_shown ? '' : ' style="display:none"'; ?>>
							<th scope="row"><label for="styble_ai_model_<?php echo esc_attr( $id ); ?>">Model</label></th>
							<td>
								<input name="<?php echo esc_attr( $cred_option ); ?>[<?php echo esc_attr( $id ); ?>][model]"
									id="styble_ai_model_<?php echo esc_attr( $id ); ?>" type="text"
									value="<?php echo esc_attr( $slot['model'] ); ?>" class="regular-text"
									placeholder="<?php echo esc_attr( $p['model'] ); ?>" />
								<p class="description">Leave blank to use this provider's default (shown as the placeholder).<br /><strong>Image uploads need a vision model:</strong> <code>meta-llama/llama-4-scout-17b-16e-instruct</code> (Groq, free), <code>gemini-2.0-flash</code> (Gemini, free), <code>claude-opus-5</code>, or <code>gpt-4o</code>. Text-only models (e.g. <code>llama-3.3-70b-versatile</code>) reject images.</p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row">Keys on file</th>
						<td>
							<?php if ( $with_keys ) : ?>
								<p>
									<?php foreach ( $with_keys as $id ) : ?>
										<code><?php echo esc_html( $id ); ?></code><?php echo $id === end( $with_keys ) ? '' : ' '; ?>
									<?php endforeach; ?>
								</p>
								<p class="description">Each provider keeps its own key and model, so switching the dropdown above no longer discards the other one. That is what makes a target-versus-floor eval comparison possible — see <code>docs/ROADMAP.md</code> blocker B3.</p>
							<?php else : ?>
								<p class="description">No keys stored yet. Paste one for the selected provider above.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr id="styble_ai_base_url_row">
						<th scope="row"><label for="styble_ai_base_url">Custom base URL</label></th>
						<td>
							<input name="styble_ai_base_url" id="styble_ai_base_url" type="url"
								value="<?php echo esc_attr( $base_url ); ?>" class="regular-text"
								placeholder="https://host/v1/chat/completions" />
							<p class="description">Only used when Provider = Custom. Full chat/completions endpoint URL.</p>
						</td>
					</tr>
				</table>

				<h2>Stock photos</h2>
				<p>Optional. Leave the provider on “None” and generated images stay blank placeholders you fill in yourself — the AI still writes the alt text describing what the picture should show.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="styble_ai_media_provider">Image source</label></th>
						<td>
							<select name="styble_ai_media_provider" id="styble_ai_media_provider">
								<option value="" <?php selected( $media_prov, '' ); ?>>None — leave placeholders</option>
								<?php foreach ( Styble_AI_Media::providers() as $id => $p ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"
										data-signup="<?php echo esc_attr( $p['signup'] ); ?>"
										<?php selected( $media_prov, $id ); ?>>
										<?php echo esc_html( $p['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Photos are downloaded into your Media Library and the block gets a real attachment, so they behave like any other upload — crop, replace, reuse. The photographer is credited in the attachment caption.</p>
						</td>
					</tr>
					<tr id="styble_ai_media_key_row">
						<th scope="row"><label for="styble_ai_media_key">Image API key</label></th>
						<td>
							<input name="styble_ai_media_key" id="styble_ai_media_key" type="password" autocomplete="off"
								value="<?php echo esc_attr( $media_key ); ?>" class="regular-text" placeholder="paste key" />
							<p class="description">Free from the provider. <a id="styble_ai_media_signup" href="https://www.pexels.com/api/new/" target="_blank" rel="noopener">Get an image API key &rarr;</a><br />Both have free tiers with hourly limits; results are cached per search phrase, so the same description reuses the photo already in your library instead of downloading it twice.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<script>
			( function () {
				var sel  = document.getElementById( 'styble_ai_provider' );
				var row  = document.getElementById( 'styble_ai_base_url_row' );
				// Show only the selected provider's key + model rows. Every row is
				// in the DOM and every one posts, so the OTHER providers' stored
				// credentials round-trip untouched — hiding is presentation only.
				// A hidden input still submits, which is exactly what is wanted:
				// nothing is silently cleared by looking at a different provider.
				var credRows = document.querySelectorAll( '.styble-ai-cred-row' );
				function sync() {
					var i;
					for ( i = 0; i < credRows.length; i++ ) {
						credRows[ i ].style.display =
							( credRows[ i ].getAttribute( 'data-provider' ) === sel.value ) ? '' : 'none';
					}
					row.style.display = ( sel.value === 'custom' ) ? '' : 'none';
				}
				sel.addEventListener( 'change', sync );
				sync();

				var media = document.getElementById( 'styble_ai_media_provider' );
				var mediaKeyRow = document.getElementById( 'styble_ai_media_key_row' );
				var mediaSignup = document.getElementById( 'styble_ai_media_signup' );
				function syncMedia() {
					var opt = media.options[ media.selectedIndex ];
					mediaKeyRow.style.display = media.value ? '' : 'none';
					if ( opt.getAttribute( 'data-signup' ) ) {
						mediaSignup.href = opt.getAttribute( 'data-signup' );
					}
				}
				media.addEventListener( 'change', syncMedia );
				syncMedia();
			} )();
			</script>
			<hr />
			<h2>How to use</h2>
			<p><strong>A whole page — Styble AI &rarr; AI Chat.</strong> Ask for a page (“a pricing page with three plans”). It plans the sections, builds them one by one into a new draft page, and previews the result as you go. Follow-up messages revise that same page.</p>
			<p><strong>One section — the editor sidebar.</strong> Edit any page or post, open the <strong>Styble AI</strong> panel from the top-right plugin menu (star icon), describe a section, and click <strong>Generate</strong>. Selecting a block and using ✦ <strong>Edit with AI</strong> in its toolbar rewrites just that block.</p>
		</div>
		<?php
	}
}

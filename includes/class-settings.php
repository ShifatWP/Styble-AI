<?php
/**
 * Settings page (Settings -> AI Block Composer).
 * Experimental: the API key is stored in wp_options in plaintext. Fine for a
 * local/dev experiment; for production move to the proxy/credits model instead
 * of storing user keys.
 *
 * @package AI_Block_Composer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABC_Settings {

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'fields' ) );
	}

	public function menu() {
		add_options_page(
			'AI Block Composer',
			'AI Block Composer',
			'manage_options',
			'ai-block-composer',
			array( $this, 'render' )
		);
	}

	public function fields() {
		register_setting(
			'abc_settings',
			'abc_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'anthropic',
			)
		);
		register_setting(
			'abc_settings',
			'abc_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'abc_settings',
			'abc_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'abc_settings',
			'abc_base_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$provider = get_option( 'abc_provider', 'anthropic' );
		$key      = get_option( 'abc_api_key', '' );
		$model    = get_option( 'abc_model', '' );
		$base_url = get_option( 'abc_base_url', '' );

		// Provider dropdown: Anthropic (native) + every OpenAI-compatible preset.
		$presets   = ABC_OpenAI_Compatible_Provider::presets();
		$providers = array( 'anthropic' => array( 'label' => 'Anthropic — Claude (native)', 'model' => 'claude-sonnet-5', 'signup' => 'https://console.anthropic.com/settings/keys' ) );
		foreach ( $presets as $id => $p ) {
			$providers[ $id ] = $p;
		}
		// Default-model hints, exposed to the model text field's placeholder via data-*.
		?>
		<div class="wrap">
			<h1>AI Block Composer</h1>
			<p>Experimental build. Pick a provider, paste that provider's API key, and generate WordPress core-block sections from a prompt inside the editor. Out of Anthropic credits? <strong>Groq</strong> and <strong>Cerebras</strong> run Llama 3.3 70B free and support the function-calling this plugin needs.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'abc_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abc_provider">Provider</label></th>
						<td>
							<select name="abc_provider" id="abc_provider">
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
					<tr>
						<th scope="row"><label for="abc_api_key">API key</label></th>
						<td>
							<input name="abc_api_key" id="abc_api_key" type="password" autocomplete="off"
								value="<?php echo esc_attr( $key ); ?>" class="regular-text" placeholder="paste key" />
							<p class="description">Stored in your database (plaintext — fine for local/dev). <a id="abc_signup" href="<?php echo esc_url( $providers[ $provider ]['signup'] ); ?>" target="_blank" rel="noopener">Get a key for the selected provider &rarr;</a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abc_model">Model</label></th>
						<td>
							<input name="abc_model" id="abc_model" type="text"
								value="<?php echo esc_attr( $model ); ?>" class="regular-text"
								placeholder="<?php echo esc_attr( $providers[ $provider ]['model'] ); ?>" />
							<p class="description">Leave blank to use the provider's default (shown as the placeholder). Examples: <code>llama-3.3-70b-versatile</code> (Groq), <code>llama-3.3-70b</code> (Cerebras), <code>deepseek-chat</code>, <code>claude-sonnet-5</code>.</p>
						</td>
					</tr>
					<tr id="abc_base_url_row">
						<th scope="row"><label for="abc_base_url">Custom base URL</label></th>
						<td>
							<input name="abc_base_url" id="abc_base_url" type="url"
								value="<?php echo esc_attr( $base_url ); ?>" class="regular-text"
								placeholder="https://host/v1/chat/completions" />
							<p class="description">Only used when Provider = Custom. Full chat/completions endpoint URL.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<script>
			( function () {
				var sel  = document.getElementById( 'abc_provider' );
				var model = document.getElementById( 'abc_model' );
				var signup = document.getElementById( 'abc_signup' );
				var row  = document.getElementById( 'abc_base_url_row' );
				function sync() {
					var opt = sel.options[ sel.selectedIndex ];
					model.placeholder = opt.getAttribute( 'data-model' ) || '';
					signup.href = opt.getAttribute( 'data-signup' ) || '#';
					row.style.display = ( sel.value === 'custom' ) ? '' : 'none';
				}
				sel.addEventListener( 'change', sync );
				sync();
			} )();
			</script>
			<hr />
			<h2>How to use</h2>
			<ol>
				<li>Pick a provider, paste that provider's key, save.</li>
				<li>Edit any page or post.</li>
				<li>Open the <strong>AI Block Composer</strong> panel from the top-right plugin menu (star icon).</li>
				<li>Describe a section (e.g. “a hero for a coffee roaster with a headline, one line of copy, and two buttons”) and click <strong>Generate</strong>.</li>
			</ol>
		</div>
		<?php
	}
}

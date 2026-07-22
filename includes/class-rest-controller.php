<?php
/**
 * REST endpoint: POST /wp-json/ai-block-composer/v1/generate
 *
 * Flow: prompt -> theme context -> provider (structured JSON) -> serializer
 * -> validate markup -> return markup to the editor for insertion.
 *
 * @package AI_Block_Composer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABC_REST_Controller {

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'ai-block-composer/v1',
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'prompt' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	public function generate( WP_REST_Request $request ) {
		$prompt = trim( (string) $request->get_param( 'prompt' ) );

		if ( '' === $prompt ) {
			return new WP_Error( 'abc_empty', 'Please describe what to build.', array( 'status' => 400 ) );
		}

		$provider = $this->make_provider();
		$context  = ( new ABC_Theme_Context() )->summary();

		$ir = $provider->generate( $prompt, $context );
		if ( is_wp_error( $ir ) ) {
			return new WP_Error( $ir->get_error_code(), $ir->get_error_message(), array( 'status' => 502 ) );
		}

		$markup = ( new ABC_Serializer() )->serialize( $ir );

		// Validation pass: re-parse and confirm we produced real blocks and no
		// classic-editor fallback (core/freeform), which signals invalid markup.
		$parsed  = parse_blocks( $markup );
		$has_real = false;
		foreach ( $parsed as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$has_real = true;
			}
			if ( 'core/freeform' === $b['blockName'] ) {
				return new WP_Error( 'abc_invalid_markup', 'Generated markup did not validate. Please try again.', array( 'status' => 500 ) );
			}
		}

		if ( ! $has_real ) {
			return new WP_Error( 'abc_empty_result', 'The model returned no usable blocks. Try a more specific prompt.', array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'markup' => $markup,
				'ir'     => $ir, // handy while experimenting; drop in production.
			)
		);
	}

	/**
	 * Build the configured provider. "anthropic" uses the native Messages API;
	 * every other id is an OpenAI-compatible chat/completions endpoint (Groq,
	 * Cerebras, OpenRouter, DeepSeek, Mistral, Together, or a custom base URL).
	 */
	private function make_provider() {
		$provider = get_option( 'abc_provider', 'anthropic' );
		$api_key  = get_option( 'abc_api_key', '' );
		$model    = get_option( 'abc_model', '' );

		if ( 'anthropic' === $provider ) {
			return new ABC_Anthropic_Provider( $api_key, $model ? $model : 'claude-sonnet-5' );
		}

		$presets  = ABC_OpenAI_Compatible_Provider::presets();
		$endpoint = '';
		if ( 'custom' === $provider ) {
			$endpoint = get_option( 'abc_base_url', '' );
		} elseif ( isset( $presets[ $provider ] ) ) {
			$endpoint = $presets[ $provider ]['endpoint'];
			if ( '' === $model && ! empty( $presets[ $provider ]['model'] ) ) {
				$model = $presets[ $provider ]['model'];
			}
		}

		return new ABC_OpenAI_Compatible_Provider( $api_key, $model, $endpoint );
	}
}

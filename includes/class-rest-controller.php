<?php
/**
 * REST endpoint: POST /wp-json/styble-ai/v1/generate
 *
 * Flow: prompt -> catalog-generated prompt + tool schema -> provider (forced
 * tool call) -> validator -> one corrective retry -> validated emit_layout tree.
 *
 * The route returns a TREE, not markup. Serialization is the editor's job now:
 * every Styble block is dynamic, so the applier builds real blocks with
 * createBlock() and each block fills its own defaults and mints its own
 * uniqueId. Nothing here writes block HTML.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_REST_Controller {

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'styble-ai/v1',
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'prompt'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'selection' => array(
						'required'          => false,
						'type'              => 'string',
						// Existing block markup sent as edit context. It is NEVER
						// output to the page (only fed to the model), and block
						// markup lives in HTML comments that kses would strip — so
						// keep it raw, just bound the size.
						'sanitize_callback' => array( $this, 'sanitize_selection' ),
					),
					'image'     => array(
						'required'          => false,
						'type'              => 'string',
						// A data:image/*;base64 URL used as a design reference.
						// Kept as a light string passthrough; real validation (type
						// + size) happens in generate() so we can return an explicit
						// error instead of silently dropping it.
						'sanitize_callback' => function ( $v ) {
							return trim( (string) $v );
						},
					),
				),
			)
		);
	}

	public function generate( WP_REST_Request $request ) {
		$prompt    = trim( (string) $request->get_param( 'prompt' ) );
		$selection = trim( (string) $request->get_param( 'selection' ) );
		$image     = trim( (string) $request->get_param( 'image' ) );

		if ( '' === $prompt ) {
			return new WP_Error( 'styble_ai_empty', 'Please describe what to build.', array( 'status' => 400 ) );
		}

		// Validate an attached design image up front — never silently drop it.
		if ( '' !== $image ) {
			if ( 0 !== strpos( $image, 'data:image/' ) ) {
				return new WP_Error( 'styble_ai_bad_image', 'Attached file is not a valid image.', array( 'status' => 400 ) );
			}
			// ~8 MB cap on the raw data URL (base64 is ~33% larger than the file).
			if ( strlen( $image ) > 8 * 1024 * 1024 ) {
				return new WP_Error( 'styble_ai_image_too_large', 'Image is too large. Please use one under 8 MB.', array( 'status' => 413 ) );
			}
		}

		try {
			$catalog = Styble_AI_Catalog::from_file();
		} catch ( RuntimeException $e ) {
			// A missing catalog is a broken install, not a bad prompt. Say which.
			return new WP_Error(
				'styble_ai_no_catalog',
				'Styble AI cannot read its block catalog. ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}

		$generator = new Styble_AI_Generator( $catalog, $this->make_provider() );

		$result = ( '' !== $selection )
			? $generator->edit( $prompt, $selection )
			: $generator->generate( $prompt, $image );

		if ( is_wp_error( $result ) ) {
			return $this->as_response_error( $result );
		}

		return rest_ensure_response(
			array(
				'tree'            => $result['tree'],
				'attempts'        => $result['attempts'],
				'contractVersion' => $catalog->contract_version(),
			)
		);
	}

	/**
	 * Give every failure an HTTP status, and carry the validator's per-error
	 * list through to the editor so it can list the reasons instead of showing a
	 * blank failure.
	 *
	 * @param WP_Error $error Error from the pipeline.
	 *
	 * @return WP_Error
	 */
	private function as_response_error( WP_Error $error ) {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 502;

		$data['status'] = $status;

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * Bound the selection markup we forward to the model. Not escaped (it is
	 * context only, never rendered), just length-capped to keep the payload sane.
	 */
	public function sanitize_selection( $value ) {
		$value = (string) $value;
		$max   = 20000;
		if ( strlen( $value ) > $max ) {
			$value = substr( $value, 0, $max );
		}
		return $value;
	}

	/**
	 * Build the configured provider. "anthropic" uses the native Messages API;
	 * every other id is an OpenAI-compatible chat/completions endpoint (Groq,
	 * Cerebras, OpenRouter, DeepSeek, Mistral, Together, Gemini, or a custom
	 * base URL).
	 */
	private function make_provider() {
		$provider = get_option( 'styble_ai_provider', 'anthropic' );
		$api_key  = get_option( 'styble_ai_api_key', '' );
		$model    = get_option( 'styble_ai_model', '' );

		if ( 'anthropic' === $provider ) {
			return new Styble_AI_Anthropic_Provider( $api_key, $model ? $model : 'claude-opus-5' );
		}

		$presets  = Styble_AI_OpenAI_Compatible_Provider::presets();
		$endpoint = '';
		if ( 'custom' === $provider ) {
			$endpoint = get_option( 'styble_ai_base_url', '' );
		} elseif ( isset( $presets[ $provider ] ) ) {
			$endpoint = $presets[ $provider ]['endpoint'];
			if ( '' === $model && ! empty( $presets[ $provider ]['model'] ) ) {
				$model = $presets[ $provider ]['model'];
			}
		}

		return new Styble_AI_OpenAI_Compatible_Provider( $api_key, $model, $endpoint );
	}
}

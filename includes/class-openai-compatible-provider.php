<?php
/**
 * LLM provider — OpenAI-compatible Chat Completions API.
 *
 * Works with any provider that speaks the OpenAI /chat/completions contract
 * and supports function calling: Groq, OpenRouter, Cerebras, DeepSeek, Mistral,
 * Together, or a self-hosted endpoint. Lets you run the experiment on a free
 * (or near-free) key instead of Anthropic.
 *
 * Same contract as ABC_Anthropic_Provider: generate($prompt, $context) returns
 * the decoded IR array ( ['sections' => [...]] ) or a WP_Error. Structured
 * output is forced the same way — a single "function" whose parameters ARE our
 * IR schema, with tool_choice pinned to it. The serializer still owns
 * correctness; this layer only fills the schema.
 *
 * Key differences from the Anthropic layer:
 * - Auth header is `Authorization: Bearer <key>`.
 * - Tools are `{type:"function", function:{name,description,parameters}}`.
 * - Forced call is `tool_choice:{type:"function",function:{name:"build_layout"}}`.
 * - The system prompt is a message with role "system".
 * - The result lives in choices[0].message.tool_calls[0].function.arguments,
 *   which is a JSON *string* we must decode (some providers hand back an object).
 *
 * @package AI_Block_Composer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABC_OpenAI_Compatible_Provider {

	private $api_key;
	private $model;
	private $endpoint;

	/**
	 * @param string $api_key  Provider API key.
	 * @param string $model    Model id (provider-specific, e.g. "llama-3.3-70b-versatile").
	 * @param string $endpoint Full chat/completions URL for the chosen provider.
	 */
	public function __construct( $api_key, $model, $endpoint ) {
		$this->api_key  = $api_key;
		$this->model    = $model;
		$this->endpoint = $endpoint;
	}

	/**
	 * Preset providers: id => [label, endpoint, default model, signup URL].
	 * "custom" carries no endpoint — the user supplies a base URL in settings.
	 * All are OpenAI-compatible and support function calling on the listed model.
	 */
	public static function presets() {
		return array(
			'groq'       => array(
				'label'    => 'Groq (free — Llama 3.3 70B, recommended)',
				'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
				'model'    => 'llama-3.3-70b-versatile',
				'signup'   => 'https://console.groq.com/keys',
			),
			'cerebras'   => array(
				'label'    => 'Cerebras (free — Llama 3.3 70B, very fast)',
				'endpoint' => 'https://api.cerebras.ai/v1/chat/completions',
				'model'    => 'llama-3.3-70b',
				'signup'   => 'https://cloud.cerebras.ai',
			),
			'openrouter' => array(
				'label'    => 'OpenRouter (free tier — pick a model that supports tools)',
				'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
				'model'    => 'meta-llama/llama-3.3-70b-instruct',
				'signup'   => 'https://openrouter.ai/keys',
			),
			'deepseek'   => array(
				'label'    => 'DeepSeek (cheap — deepseek-chat)',
				'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
				'model'    => 'deepseek-chat',
				'signup'   => 'https://platform.deepseek.com/api_keys',
			),
			'mistral'    => array(
				'label'    => 'Mistral (free tier — mistral-large-latest)',
				'endpoint' => 'https://api.mistral.ai/v1/chat/completions',
				'model'    => 'mistral-large-latest',
				'signup'   => 'https://console.mistral.ai/api-keys',
			),
			'together'   => array(
				'label'    => 'Together AI (Llama 3.3 70B Turbo)',
				'endpoint' => 'https://api.together.xyz/v1/chat/completions',
				'model'    => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
				'signup'   => 'https://api.together.xyz/settings/api-keys',
			),
			'custom'     => array(
				'label'    => 'Custom (enter your own base URL)',
				'endpoint' => '',
				'model'    => '',
				'signup'   => '',
			),
		);
	}

	/**
	 * @param string $prompt  User's description of the section(s) to build.
	 * @param string $context Theme summary from ABC_Theme_Context.
	 * @return array|WP_Error Decoded IR ( ['sections' => [...]] ) or error.
	 */
	public function generate( $prompt, $context ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'abc_no_key', 'No API key configured. Add one under Settings → AI Block Composer.' );
		}
		if ( empty( $this->endpoint ) ) {
			return new WP_Error( 'abc_no_endpoint', 'No API endpoint configured. Pick a provider or set a custom base URL under Settings → AI Block Composer.' );
		}
		if ( empty( $this->model ) ) {
			return new WP_Error( 'abc_no_model', 'No model configured. Set a model id under Settings → AI Block Composer.' );
		}

		$body = array(
			'model'       => $this->model,
			'max_tokens'  => 4096,
			'temperature' => 0.7,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $this->system_prompt( $context ),
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'tools'       => array( $this->tool_definition() ),
			'tool_choice' => array(
				'type'     => 'function',
				'function' => array( 'name' => 'build_layout' ),
			),
		);

		$headers = array(
			'content-type'  => 'application/json',
			'authorization' => 'Bearer ' . $this->api_key,
		);
		// OpenRouter asks for attribution headers; harmless elsewhere.
		if ( false !== strpos( $this->endpoint, 'openrouter.ai' ) ) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = 'AI Block Composer';
		}

		$response = wp_remote_post(
			$this->endpoint,
			array(
				'timeout' => 60,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = 'HTTP ' . $code;
			if ( isset( $data['error']['message'] ) ) {
				$msg = $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$msg = $data['error'];
			} elseif ( isset( $data['message'] ) ) {
				$msg = $data['message'];
			}
			return new WP_Error( 'abc_api_error', 'AI request failed: ' . $msg );
		}

		// Pull the forced function call arguments (our IR).
		$args = null;
		if ( isset( $data['choices'][0]['message']['tool_calls'][0]['function']['arguments'] ) ) {
			$args = $data['choices'][0]['message']['tool_calls'][0]['function']['arguments'];
		}

		if ( null === $args ) {
			return new WP_Error( 'abc_no_tool_use', 'The model did not return structured layout data. The chosen model may not support function calling — try Groq/Cerebras with Llama 3.3 70B.' );
		}

		// arguments is normally a JSON string; some providers hand back an object.
		$ir = is_array( $args ) ? $args : json_decode( $args, true );

		if ( ! is_array( $ir ) ) {
			return new WP_Error( 'abc_bad_json', 'The model returned malformed layout JSON.' );
		}

		return $ir;
	}

	/**
	 * System prompt: teaches the model our block vocabulary and design rules.
	 * (Mirrors the Anthropic provider so output quality is comparable.)
	 */
	private function system_prompt( $context ) {
		return implode(
			"\n",
			array(
				'You are a WordPress layout designer. Turn the user request into clean, well-structured page sections using ONLY the schema of the build_layout function. You MUST call build_layout with valid arguments.',
				'',
				'Rules:',
				'- Compose one or more "sections". A hero is usually one section; features, testimonials, CTA are separate sections.',
				'- Write real, specific, publishable copy — never lorem ipsum or placeholders like "Your text here".',
				'- Prefer 2 or 3 columns for feature/benefit grids. Keep each column focused.',
				'- Use "tone" to create visual rhythm: alternate default/light, use dark or accent for hero or CTA.',
				'- Use "full" width for hero and CTA bands; "default" for text-heavy content.',
				'- Images are placeholders (no URL); give a clear alt describing the intended photo.',
				'- Keep headings concise. Do not stuff a section with too many blocks.',
				'',
				'Site context (use it to match tone and wording): ' . $context,
			)
		);
	}

	/**
	 * The function whose parameters ARE our intermediate representation.
	 * Same JSON Schema as the Anthropic tool, wrapped in the OpenAI shape.
	 */
	private function tool_definition() {
		$block_schema = array(
			'type'       => 'object',
			'properties' => array(
				'type'     => array(
					'type'        => 'string',
					'enum'        => array( 'heading', 'paragraph', 'buttons', 'list', 'quote', 'image', 'spacer', 'columns' ),
					'description' => 'The block kind.',
				),
				'level'    => array(
					'type'        => 'integer',
					'description' => 'For heading: 1-4.',
				),
				'text'     => array(
					'type'        => 'string',
					'description' => 'For heading, paragraph, quote: the text. May contain <strong>, <em>, <a href>.',
				),
				'citation' => array(
					'type'        => 'string',
					'description' => 'For quote: who said it.',
				),
				'ordered'  => array(
					'type'        => 'boolean',
					'description' => 'For list: true = numbered.',
				),
				'items'    => array(
					'type'        => 'array',
					'description' => 'For list: array of strings. For buttons: array of {label,url,style}.',
					'items'       => array( 'type' => array( 'string', 'object' ) ),
				),
				'alt'      => array(
					'type'        => 'string',
					'description' => 'For image: describe the intended photo.',
				),
				'caption'  => array(
					'type'        => 'string',
					'description' => 'For image: optional caption.',
				),
				'height'   => array(
					'type'        => 'integer',
					'description' => 'For spacer: height in pixels (8-400).',
				),
				'columns'  => array(
					'type'        => 'array',
					'description' => 'For columns: array of { "blocks": [ leaf blocks, no further columns ] }.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'blocks' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
				),
			),
			'required'   => array( 'type' ),
		);

		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'build_layout',
				'description' => 'Produce the page layout as a list of sections built from core WordPress blocks.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'sections' => array(
							'type'        => 'array',
							'description' => 'Ordered list of page sections.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'label'  => array(
										'type'        => 'string',
										'description' => 'Short internal name, e.g. "Hero", "Features", "CTA".',
									),
									'tone'   => array(
										'type' => 'string',
										'enum' => array( 'default', 'light', 'dark', 'accent' ),
									),
									'width'  => array(
										'type' => 'string',
										'enum' => array( 'full', 'wide', 'default' ),
									),
									'blocks' => array(
										'type'  => 'array',
										'items' => $block_schema,
									),
								),
								'required'   => array( 'blocks' ),
							),
						),
					),
					'required'   => array( 'sections' ),
				),
			),
		);
	}
}

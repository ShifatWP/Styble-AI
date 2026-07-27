<?php
/**
 * LLM provider — OpenAI-compatible Chat Completions API.
 *
 * Works with any provider that speaks the OpenAI /chat/completions contract
 * and supports function calling: Groq, OpenRouter, Cerebras, DeepSeek, Mistral,
 * Together, or a self-hosted endpoint. Lets you run the experiment on a free
 * (or near-free) key instead of Anthropic.
 *
 * Same contract as Styble_AI_Anthropic_Provider — both take the identical spec
 * and return the tool input:
 *
 *   complete( array $spec ) -> array | WP_Error
 *
 * Structured output is forced the same way: a single "function" whose
 * parameters are the emit_layout schema, with tool_choice pinned to it. The
 * provider owns transport only; the system prompt and schema arrive in the spec,
 * generated from the catalog, and Styble_AI_Validator owns correctness.
 *
 * Key differences from the Anthropic layer:
 * - Auth header is `Authorization: Bearer <key>`.
 * - Tools are `{type:"function", function:{name,description,parameters}}`.
 * - Forced call is `tool_choice:{type:"function",function:{name:…}}`.
 * - The system prompt is a message with role "system".
 * - Images are `image_url` parts rather than base64 `image` blocks.
 * - The result lives in choices[0].message.tool_calls[0].function.arguments,
 *   which is a JSON *string* we must decode (some providers hand back an object).
 * - `temperature` is still accepted here; on current Claude models it 400s.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_OpenAI_Compatible_Provider {

	/**
	 * Default output budget.
	 *
	 * Deliberately NOT the 16000 the Anthropic path uses. That figure exists
	 * because thinking shares Claude's budget; nothing here does. Worse,
	 * max_tokens is counted as *reserved* tokens against per-minute limits, so a
	 * large value fails before a single token is generated: Groq's free tier
	 * allows 12000 TPM, and 16000 + the ~2.5k prompt is rejected outright with
	 * "Request too large". Groq's free tier is a realistic target, so the
	 * default has to fit inside it.
	 *
	 * One section's tree is well under this — the hero fixture is ~500 tokens.
	 */
	const MAX_TOKENS = 4096;

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
				'label'    => 'Groq (free — Llama 3.3 70B; struggles with this schema)',
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
			'gemini'     => array(
				'label'    => 'Google Gemini (free — vision + tools; best free option)',
				'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
				'model'    => 'gemini-2.0-flash',
				'signup'   => 'https://aistudio.google.com/app/apikey',
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
	 * Run one forced-tool turn.
	 *
	 * Takes the identical spec as Styble_AI_Anthropic_Provider::complete() and
	 * maps it onto the chat/completions shape.
	 *
	 * @param array $spec {
	 *     @type string $system      System prompt.
	 *     @type array  $tool        name, description, input_schema.
	 *     @type array  $messages    List of [ role, text, image ] (image optional data URL).
	 *     @type int    $max_tokens  Optional output budget.
	 *     @type float  $temperature Optional sampling temperature.
	 * }
	 *
	 * @return array|WP_Error Tool arguments, or an error.
	 */
	public function complete( array $spec ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'styble_ai_no_key', 'No API key configured. Add one under Settings → Styble AI.' );
		}
		if ( empty( $this->endpoint ) ) {
			return new WP_Error( 'styble_ai_no_endpoint', 'No API endpoint configured. Pick a provider or set a custom base URL under Settings → Styble AI.' );
		}
		if ( empty( $this->model ) ) {
			return new WP_Error( 'styble_ai_no_model', 'No model configured. Set a model id under Settings → Styble AI.' );
		}

		$tool = isset( $spec['tool'] ) ? $spec['tool'] : array();
		if ( empty( $tool['name'] ) || empty( $tool['input_schema'] ) ) {
			return new WP_Error( 'styble_ai_bad_spec', 'Internal error: the request had no tool definition.' );
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => isset( $spec['system'] ) ? $spec['system'] : '',
			),
		);
		foreach ( $this->render_messages( isset( $spec['messages'] ) ? $spec['messages'] : array() ) as $message ) {
			$messages[] = $message;
		}

		$body = array(
			'model'       => $this->model,
			'max_tokens'  => isset( $spec['max_tokens'] ) ? (int) $spec['max_tokens'] : self::MAX_TOKENS,
			// Still valid here, unlike the Anthropic path.
			'temperature' => isset( $spec['temperature'] ) ? (float) $spec['temperature'] : 0.7,
			'messages'    => $messages,
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => $tool['name'],
						'description' => isset( $tool['description'] ) ? $tool['description'] : '',
						'parameters'  => $tool['input_schema'],
					),
				),
			),
			// No `strict`: the tree is recursive and structured output cannot
			// express that. Styble_AI_Validator is the enforcement layer.
			'tool_choice' => array(
				'type'     => 'function',
				'function' => array( 'name' => $tool['name'] ),
			),
		);

		return $this->send( $body, $tool['name'] );
	}

	/**
	 * Turn provider-neutral messages into chat/completions content parts.
	 *
	 * @param array $messages Neutral messages.
	 *
	 * @return array
	 */
	private function render_messages( array $messages ) {
		$out = array();

		foreach ( $messages as $message ) {
			$role  = isset( $message['role'] ) ? $message['role'] : 'user';
			$text  = isset( $message['text'] ) ? (string) $message['text'] : '';
			$image = isset( $message['image'] ) ? (string) $message['image'] : '';

			if ( $image ) {
				$out[] = array(
					'role'    => $role,
					'content' => array(
						array(
							'type' => 'text',
							'text' => $text,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array( 'url' => $image ),
						),
					),
				);
				continue;
			}

			$out[] = array(
				'role'    => $role,
				'content' => $text,
			);
		}

		return $out;
	}

	/**
	 * Host name of the configured endpoint, for error messages.
	 *
	 * @return string
	 */
	private function provider_label() {
		$host = wp_parse_url( $this->endpoint, PHP_URL_HOST );
		return $host ? $host : 'the provider';
	}

	/**
	 * POST the request and pull the forced function-call arguments.
	 *
	 * @param array  $body      Request body.
	 * @param string $tool_name Expected function name.
	 *
	 * @return array|WP_Error
	 */
	private function send( array $body, $tool_name ) {
		$headers = array(
			'content-type'  => 'application/json',
			'authorization' => 'Bearer ' . $this->api_key,
		);
		// OpenRouter asks for attribution headers; harmless elsewhere.
		if ( false !== strpos( $this->endpoint, 'openrouter.ai' ) ) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = 'Styble AI';
		}

		$response = wp_remote_post(
			$this->endpoint,
			array(
				'timeout' => 120,
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

			// The model tried to call the tool and produced something the
			// provider could not parse — in practice, malformed JSON (a missing
			// bracket is the common one on smaller models). The provider rejects
			// it at the API boundary, so it never reaches our validator and the
			// corrective retry cannot help. The raw attempt comes back in
			// failed_generation, and the default message points at a field the
			// user cannot see, so say what actually happened instead.
			if ( isset( $data['error']['failed_generation'] ) ) {
				return new WP_Error(
					'styble_ai_malformed_tool_call',
					sprintf(
						'%s produced a malformed layout that %s rejected before it could be checked. '
							. 'This usually means the model is not strong enough for a nested tool schema — '
							. 'try Claude, Gemini, or another larger model.',
						$this->model,
						$this->provider_label()
					),
					array( 'failed_generation' => $data['error']['failed_generation'] )
				);
			}

			return new WP_Error( 'styble_ai_api_error', 'AI request failed: ' . $msg );
		}

		$args = null;
		if ( isset( $data['choices'][0]['message']['tool_calls'][0]['function']['arguments'] ) ) {
			$args = $data['choices'][0]['message']['tool_calls'][0]['function']['arguments'];
		}

		if ( null === $args ) {
			return new WP_Error(
				'styble_ai_no_tool_use',
				'The model did not call ' . $tool_name . '. The chosen model may not support function calling — try Gemini or Claude.'
			);
		}

		// arguments is normally a JSON string; some providers hand back an object.
		$tree = is_array( $args ) ? $args : json_decode( $args, true );

		if ( ! is_array( $tree ) ) {
			return new WP_Error( 'styble_ai_bad_json', 'The model returned malformed layout JSON.' );
		}

		return $tree;
	}
}

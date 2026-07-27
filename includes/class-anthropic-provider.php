<?php
/**
 * LLM provider — Anthropic (Claude) Messages API.
 *
 * Structured output is forced by defining a tool whose input_schema is the
 * emit_layout envelope and pinning tool_choice to it. The model must answer with
 * a tool_use block whose `input` is the block tree.
 *
 * The provider owns transport only. It does not know what a Styble block is:
 * the system prompt, the tool and its schema all arrive in the spec, generated
 * from the catalog by Styble_AI_Prompt. That is what makes the provider
 * swappable — Styble_AI_OpenAI_Compatible_Provider accepts the identical spec.
 *
 *   complete( array $spec ) -> array (the tool input) | WP_Error
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Anthropic_Provider {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const VERSION  = '2023-06-01';

	/**
	 * Default output budget.
	 *
	 * Thinking is on by default on current Claude models and shares this budget
	 * with the response, so a full section tree truncates at the 4096 the earlier
	 * core-block pipeline used.
	 *
	 * Thinking is deliberately left ON. Disabling it to reclaim budget has a
	 * documented failure mode on Claude Opus 5: the model writes the tool call
	 * into its visible text instead of emitting a tool_use block, so the turn
	 * succeeds and the call silently never happens. For a forced-tool pipeline
	 * that is the worst possible failure — it looks like a model that answered.
	 */
	const MAX_TOKENS = 16000;

	private $api_key;
	private $model;

	public function __construct( $api_key, $model ) {
		$this->api_key = $api_key;
		$this->model   = $model ? $model : 'claude-opus-5';
	}

	/**
	 * Run one forced-tool turn.
	 *
	 * @param array $spec {
	 *     @type string $system     System prompt.
	 *     @type array  $tool       name, description, input_schema.
	 *     @type array  $messages   List of [ role, text, image ] (image optional data URL).
	 *     @type int    $max_tokens Optional output budget.
	 * }
	 *
	 * @return array|WP_Error Tool input, or an error.
	 */
	public function complete( array $spec ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'styble_ai_no_key', 'No API key configured. Add one under Settings → Styble AI.' );
		}

		$tool = isset( $spec['tool'] ) ? $spec['tool'] : array();
		if ( empty( $tool['name'] ) || empty( $tool['input_schema'] ) ) {
			return new WP_Error( 'styble_ai_bad_spec', 'Internal error: the request had no tool definition.' );
		}

		$body = array(
			'model'      => $this->model,
			'max_tokens' => isset( $spec['max_tokens'] ) ? (int) $spec['max_tokens'] : self::MAX_TOKENS,
			'system'     => isset( $spec['system'] ) ? $spec['system'] : '',
			'tools'      => array(
				array(
					'name'         => $tool['name'],
					'description'  => isset( $tool['description'] ) ? $tool['description'] : '',
					'input_schema' => $tool['input_schema'],
				),
			),
			// No `strict`: the tree is recursive and structured output cannot
			// express that. Styble_AI_Validator is the enforcement layer.
			'tool_choice' => array(
				'type' => 'tool',
				'name' => $tool['name'],
			),
			'messages'    => $this->render_messages( isset( $spec['messages'] ) ? $spec['messages'] : array() ),
		);

		// No `temperature`: sampling parameters were removed on Claude Opus 4.7
		// and later and now return a 400. The OpenAI-compatible path still sends
		// one, where it remains valid.

		return $this->send( $body, $tool['name'] );
	}

	/**
	 * Turn provider-neutral messages into Anthropic content blocks.
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

			$block = $image ? $this->image_block( $image ) : null;

			if ( $block ) {
				$out[] = array(
					'role'    => $role,
					'content' => array(
						array(
							'type' => 'text',
							'text' => $text,
						),
						$block,
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
	 * Parse a data URL into an Anthropic image content block. Returns null if the
	 * string is not a base64 image data URL.
	 *
	 * @param string $data_url Data URL.
	 *
	 * @return array|null
	 */
	private function image_block( $data_url ) {
		if ( ! preg_match( '#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', (string) $data_url, $m ) ) {
			return null;
		}
		return array(
			'type'   => 'image',
			'source' => array(
				'type'       => 'base64',
				'media_type' => $m[1],
				'data'       => $m[2],
			),
		);
	}

	/**
	 * POST the request and pull the forced tool_use input.
	 *
	 * @param array  $body      Request body.
	 * @param string $tool_name Expected tool name.
	 *
	 * @return array|WP_Error
	 */
	private function send( array $body, $tool_name ) {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 120,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::VERSION,
				),
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
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'styble_ai_api_error', 'AI request failed: ' . $msg );
		}

		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'tool_use' === $block['type'] && isset( $block['input'] ) ) {
					return is_array( $block['input'] ) ? $block['input'] : array();
				}
			}
		}

		// A stop_reason of max_tokens here means the tree was cut off mid-emit;
		// say so rather than reporting a generic "no structured data".
		if ( isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'] ) {
			return new WP_Error(
				'styble_ai_truncated',
				'The model ran out of output budget before finishing the layout. Try a smaller section.'
			);
		}

		return new WP_Error(
			'styble_ai_no_tool_use',
			'The model did not call ' . $tool_name . '. No layout was returned.'
		);
	}
}

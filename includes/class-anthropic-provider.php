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

	/**
	 * Token usage from the most recent call, or an empty array.
	 *
	 * Static because the interesting numbers are cache_creation_input_tokens and
	 * cache_read_input_tokens, and the only way to know whether the prefix cache
	 * is actually working is to read them: a zero cache_read across repeated
	 * calls means something in the prefix is not byte-identical, and that failure
	 * is otherwise completely silent.
	 *
	 * This is the provider's OWN shape, kept for the one caller that wants the
	 * raw block. Anything comparing the two providers goes through
	 * Styble_AI_Usage_Tracker, which reconciles them — the two shapes count
	 * cached tokens differently and adding them alike is wrong.
	 *
	 * @var array
	 */
	private static $last_usage = array();

	/**
	 * @return array Usage from the last complete() call.
	 */
	public static function last_usage() {
		return self::$last_usage;
	}

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
	 *     @type string $operation   Optional label for token accounting: plan|section|edit.
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
			'system'     => $this->system_blocks( isset( $spec['system'] ) ? (string) $spec['system'] : '' ),
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

		return $this->send( $body, $tool['name'], isset( $spec['operation'] ) ? (string) $spec['operation'] : 'section' );
	}

	/**
	 * The system prompt as content blocks, with a cache breakpoint on the last one.
	 *
	 * Caching is a prefix match, and the request renders in the order
	 * tools -> system -> messages. So a single breakpoint on the last system
	 * block covers BOTH the tool schema and the system prompt — which together
	 * are the whole ~21KB the catalog generates, byte-identical on every call and
	 * on the corrective retry. Only the user's own sentence sits after it.
	 *
	 * One breakpoint, not two: four are allowed, but a second one on the tool
	 * would only create a redundant entry for a prefix this one already covers.
	 *
	 * Default 5-minute TTL rather than 1h. A write costs 1.25x base input at 5
	 * minutes versus 2x at an hour, and every caller here issues its requests in
	 * a burst — a page is 5-7 back-to-back sections, an eval run paces cases
	 * seconds apart. Two requests inside the window already pay the write back;
	 * the hour would need three, for a gap nothing in this plugin leaves.
	 *
	 * This is deliberately NOT mirrored in the OpenAI-compatible provider.
	 * `cache_control` is Anthropic's parameter, the nine endpoints behind that
	 * class do not accept it, and the strict ones reject unknown fields outright
	 * — a caching optimisation that breaks generation on eight providers is not
	 * an optimisation. OpenAI-shaped endpoints that cache do it server-side with
	 * no parameter to send.
	 *
	 * @param string $system Rendered system prompt.
	 *
	 * @return array|string Blocks, or the raw string when there is nothing to cache.
	 */
	private function system_blocks( $system ) {
		// An empty text block is rejected, and there would be no prefix to cache
		// anyway. Fall back to the plain string form.
		if ( '' === trim( $system ) ) {
			return $system;
		}

		return array(
			array(
				'type'          => 'text',
				'text'          => $system,
				'cache_control' => array( 'type' => 'ephemeral' ),
			),
		);
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
	 * @param string $operation Label for token accounting.
	 *
	 * @return array|WP_Error
	 */
	private function send( array $body, $tool_name, $operation = 'section' ) {
		$started  = microtime( true );
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

		// Recorded before the error branches: a truncated or tool-less response
		// still reports what the prefix cost, which is exactly when you want to
		// know whether the cache was read — and it is still billed.
		self::$last_usage = ( is_array( $data ) && isset( $data['usage'] ) && is_array( $data['usage'] ) )
			? $data['usage']
			: array();

		if ( self::$last_usage ) {
			Styble_AI_Usage_Tracker::record(
				'anthropic',
				$this->model,
				$operation,
				self::$last_usage,
				(int) round( ( microtime( true ) - $started ) * 1000 ),
				(int) $code
			);
		}

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

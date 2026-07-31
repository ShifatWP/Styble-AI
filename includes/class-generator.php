<?php
/**
 * Generation pipeline: prompt -> provider -> validate -> corrective retry.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a user request into a validated emit_layout tree, or into the reasons it
 * could not.
 *
 * This is where the contract is actually enforced end to end. The model is asked
 * once; if the validator rejects the result, the model is asked a second time
 * with its own rejected tree and the validator's own error list. A second
 * rejection is reported, never patched — a tree is applied exactly as validated
 * or not at all.
 */
class Styble_AI_Generator {

	/**
	 * How many corrective attempts follow the first one.
	 *
	 * One. If a model cannot satisfy a schema it was handed, plus a list of its
	 * own mistakes, a third try mostly buys latency and tokens.
	 */
	const MAX_RETRIES = 1;

	/**
	 * Provider error codes where a second attempt has a real chance of
	 * succeeding: the model produced SOMETHING, but it never reached the
	 * validator — no tool call was made, the output was cut off, or the
	 * provider's own JSON parser rejected the call before we ever saw a tree.
	 * `styble_ai_malformed_tool_call`'s own message already said as much: "the
	 * corrective retry cannot help" — true only because nothing routed a retry
	 * to this branch. This is that routing.
	 *
	 * Deliberately narrow. Two categories are excluded on purpose:
	 *
	 * - Config errors (`styble_ai_no_key`, `styble_ai_no_endpoint`,
	 *   `styble_ai_no_model`, `styble_ai_bad_spec`) — a second call with the
	 *   same missing setting fails identically. Retrying spends a real request
	 *   to learn nothing.
	 * - `styble_ai_api_error` — bundles rate limits and transient HTTP failures.
	 *   The roadmap already decided against the plugin retrying those itself:
	 *   production should surface a 429 to the user fast, and only the eval
	 *   harness is meant to be patient (`docs/ROADMAP.md`, "deliberately not
	 *   doing yet"). Auto-retrying here would quietly reverse that.
	 *
	 * So this list is exactly "the model's fault, not the account's, not the
	 * network's" — the same class of failure the validator retry already
	 * exists to correct, just caught one step earlier.
	 */
	const RETRYABLE_PROVIDER_CODES = array(
		'styble_ai_no_tool_use',
		'styble_ai_truncated',
		'styble_ai_bad_json',
		'styble_ai_malformed_tool_call',
	);

	/**
	 * @var Styble_AI_Catalog
	 */
	private $catalog;

	/**
	 * @var Styble_AI_Prompt
	 */
	private $prompt;

	/**
	 * @var Styble_AI_Validator
	 */
	private $validator;

	/**
	 * Provider exposing complete( array $spec ).
	 *
	 * @var object
	 */
	private $provider;

	/**
	 * Corrective attempts allowed after the first.
	 *
	 * @var int
	 */
	private $max_retries;

	/**
	 * @param Styble_AI_Catalog $catalog     Block catalog.
	 * @param object            $provider    Provider with a complete() method.
	 * @param int|null          $max_retries Corrective attempts after the first;
	 *                                       null for the default. Passing 0 is
	 *                                       how the eval harness measures
	 *                                       first-try quality — with the retry on,
	 *                                       a model that never gets it right first
	 *                                       time scores the same as one that
	 *                                       always does, which is the number we
	 *                                       actually want to move.
	 */
	public function __construct( Styble_AI_Catalog $catalog, $provider, $max_retries = null ) {
		$this->catalog     = $catalog;
		$this->provider    = $provider;
		$this->prompt      = new Styble_AI_Prompt( $catalog );
		$this->validator   = new Styble_AI_Validator( $catalog );
		$this->max_retries = ( null === $max_retries ) ? self::MAX_RETRIES : max( 0, (int) $max_retries );
	}

	/**
	 * Build a new section from a description, optionally with a design image.
	 *
	 * @param string $request User's description.
	 * @param string $image   Optional data:image/*;base64 design reference.
	 *
	 * @return array|WP_Error { tree, attempts } or an error.
	 */
	public function generate( $request, $image = '' ) {
		return $this->run( $request, $image, 'section' );
	}

	/**
	 * Rebuild a selection according to an edit instruction.
	 *
	 * The selection is sent as context and the model returns a whole replacement
	 * tree, which the editor swaps in for the selected blocks. This is the simple
	 * form of contextual editing: it reuses the generation path unchanged. The
	 * granular version — per-block tools that mutate attributes in place — is a
	 * later phase.
	 *
	 * @param string $instruction The edit ("make this punchier").
	 * @param string $selection   Serialized markup of the current selection.
	 *
	 * @return array|WP_Error { tree, attempts } or an error.
	 */
	public function edit( $instruction, $selection ) {
		$request = "The user has selected these existing blocks:\n\n"
			. "```\n" . $selection . "\n```\n\n"
			. "Apply this edit and emit the FULL replacement for that selection: " . $instruction . "\n\n"
			. 'Keep everything the user did not ask you to change, including the existing copy.';

		return $this->run( $request, '', 'edit' );
	}

	/**
	 * Ask, validate, and ask once more with the errors if needed.
	 *
	 * @param string $request   User-facing request text.
	 * @param string $image     Optional data URL.
	 * @param string $operation 'section' or 'edit', for usage accounting.
	 *
	 * @return array|WP_Error
	 */
	private function run( $request, $image, $operation = 'section' ) {
		$spec = array(
			// Labels the call for token accounting; providers ignore it otherwise.
			'operation' => $operation,
			'system' => $this->prompt->system_prompt(),
			'tool'   => array(
				'name'         => $this->prompt->tool_name(),
				'description'  => $this->prompt->tool_description(),
				'input_schema' => $this->prompt->tool_schema(),
			),
		);

		$attempt        = 0;
		$last_tree      = null;
		$last_errs      = array();
		$provider_issue = '';

		while ( $attempt <= $this->max_retries ) {
			$attempt++;

			$spec['messages'] = array(
				array(
					'role'  => 'user',
					'text'  => $this->message_for( $attempt, $request, $last_tree, $last_errs, $provider_issue ),
					'image' => $image,
				),
			);

			$tree = $this->provider->complete( $spec );
			if ( is_wp_error( $tree ) ) {
				$retryable    = in_array( $tree->get_error_code(), self::RETRYABLE_PROVIDER_CODES, true );
				$attempts_left = $attempt <= $this->max_retries;

				if ( ! $retryable || ! $attempts_left ) {
					return $tree;
				}

				// A provider-level failure has no tree to quote, so the validator
				// state from any PRIOR attempt is cleared rather than carried
				// alongside it — mixing "your last tree was invalid" with "your
				// call before that never reached the validator" describes two
				// different failures as one and the model cannot act on both at
				// once.
				$last_tree      = null;
				$last_errs      = array();
				$provider_issue = self::provider_issue_message( $tree );
				continue;
			}

			$provider_issue = '';
			$result         = $this->validator->validate( $tree );
			if ( $result->is_valid() ) {
				return array(
					'tree'     => $tree,
					'attempts' => $attempt,
				);
			}

			$last_tree = $tree;
			$last_errs = $result->errors();
		}

		// Out of attempts. Surface the validator's reasons rather than a shrug:
		// an explicit list is what makes a bad prompt or a too-narrow allowlist
		// diagnosable instead of just "it didn't work".
		return new WP_Error(
			'styble_ai_invalid_tree',
			$this->failure_message( $last_errs ),
			array(
				'status'   => 422,
				'errors'   => $last_errs,
				'attempts' => $attempt,
			)
		);
	}

	/**
	 * The user turn for a given attempt.
	 *
	 * The retry does NOT replay the first turn's tool_use and a tool_result. It
	 * restates the request with the rejected tree quoted inside it, which avoids
	 * the tool_use/tool_result pairing rules entirely and behaves identically on
	 * both provider shapes.
	 *
	 * @param int    $attempt        1-based attempt number.
	 * @param string $request        Original request.
	 * @param array  $last_tree      Previously rejected tree, or null.
	 * @param array  $last_errs      Validator errors for it.
	 * @param string $provider_issue A provider-level failure to correct instead
	 *                                of a validator one — see provider_issue_message().
	 *
	 * @return string
	 */
	private function message_for( $attempt, $request, $last_tree, $last_errs, $provider_issue = '' ) {
		if ( 1 === $attempt ) {
			return $request;
		}

		if ( '' !== $provider_issue ) {
			return $request . "\n\n" . $provider_issue;
		}

		if ( null === $last_tree ) {
			return $request;
		}

		return $request . "\n\n"
			. "Your previous attempt was rejected:\n\n"
			. "```json\n" . wp_json_encode( $last_tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n```\n\n"
			. Styble_AI_Prompt::correction_message( $last_errs );
	}

	/**
	 * A corrective message for a retryable provider-level failure — the sibling
	 * of Styble_AI_Prompt::correction_message() for the case where nothing
	 * reached the validator at all.
	 *
	 * `styble_ai_malformed_tool_call` carries the model's own unparsed output
	 * in `failed_generation`; quoting it back is the same pattern the validator
	 * retry uses for a rejected tree — show the model exactly what it wrote,
	 * not a paraphrase of what went wrong.
	 *
	 * @param WP_Error $error Retryable provider error.
	 *
	 * @return string
	 */
	private static function provider_issue_message( WP_Error $error ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();

		if ( 'styble_ai_malformed_tool_call' === $code && ! empty( $data['failed_generation'] ) ) {
			return 'Your previous attempt could not be parsed as valid JSON, so it never reached the validator:'
				. "\n\n```\n" . $data['failed_generation'] . "\n```\n\n"
				. 'Call ' . Styble_AI_Prompt::TOOL_NAME . ' again with the same content as valid, well-formed JSON — check every bracket and quote closes.';
		}

		if ( 'styble_ai_truncated' === $code ) {
			return 'Your previous attempt ran out of output budget before finishing the layout. '
				. 'Call ' . Styble_AI_Prompt::TOOL_NAME . ' again with a SMALLER section — fewer blocks or shorter copy — so it finishes within budget.';
		}

		if ( 'styble_ai_bad_json' === $code ) {
			return 'Your previous attempt returned malformed JSON that could not be parsed. '
				. 'Call ' . Styble_AI_Prompt::TOOL_NAME . ' again with valid JSON — check every bracket and quote closes.';
		}

		// styble_ai_no_tool_use, or any other retryable code without a more
		// specific message: the model replied without calling the tool at all.
		return 'Your previous response did not call ' . Styble_AI_Prompt::TOOL_NAME . '. '
			. 'You must call that tool with the section tree — never reply with prose or markup.';
	}

	/**
	 * A short, readable summary of why the tree was refused.
	 *
	 * @param array $errors Validator errors.
	 *
	 * @return string
	 */
	private function failure_message( array $errors ) {
		if ( ! $errors ) {
			return 'The model did not return a usable layout.';
		}

		$shown = array_slice( $errors, 0, 3 );
		$parts = array();
		foreach ( $shown as $error ) {
			$parts[] = $error['path'] . ' — ' . $error['message'];
		}

		// "twice" only when a corrective attempt actually happened. The eval
		// harness runs with the retry off, where claiming two attempts is simply
		// untrue.
		$message = $this->max_retries > 0
			? 'The generated layout did not satisfy the Styble block contract, twice. '
			: 'The generated layout did not satisfy the Styble block contract. ';
		$message .= implode( ' ', $parts );

		$extra = count( $errors ) - count( $shown );
		if ( $extra > 0 ) {
			$message .= sprintf( ' (%d more problem%s.)', $extra, 1 === $extra ? '' : 's' );
		}

		return $message;
	}
}

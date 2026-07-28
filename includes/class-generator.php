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
		return $this->run( $request, $image );
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

		return $this->run( $request, '' );
	}

	/**
	 * Ask, validate, and ask once more with the errors if needed.
	 *
	 * @param string $request User-facing request text.
	 * @param string $image   Optional data URL.
	 *
	 * @return array|WP_Error
	 */
	private function run( $request, $image ) {
		$spec = array(
			'system' => $this->prompt->system_prompt(),
			'tool'   => array(
				'name'         => $this->prompt->tool_name(),
				'description'  => $this->prompt->tool_description(),
				'input_schema' => $this->prompt->tool_schema(),
			),
		);

		$attempt   = 0;
		$last_tree = null;
		$last_errs = array();

		while ( $attempt <= $this->max_retries ) {
			$attempt++;

			$spec['messages'] = array(
				array(
					'role'  => 'user',
					'text'  => $this->message_for( $attempt, $request, $last_tree, $last_errs ),
					'image' => $image,
				),
			);

			$tree = $this->provider->complete( $spec );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}

			$result = $this->validator->validate( $tree );
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
	 * @param int    $attempt   1-based attempt number.
	 * @param string $request   Original request.
	 * @param array  $last_tree Previously rejected tree.
	 * @param array  $last_errs Validator errors for it.
	 *
	 * @return string
	 */
	private function message_for( $attempt, $request, $last_tree, $last_errs ) {
		if ( 1 === $attempt || null === $last_tree ) {
			return $request;
		}

		return $request . "\n\n"
			. "Your previous attempt was rejected:\n\n"
			. "```json\n" . wp_json_encode( $last_tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n```\n\n"
			. Styble_AI_Prompt::correction_message( $last_errs );
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

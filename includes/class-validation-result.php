<?php
/**
 * Outcome of validating a block tree.
 *
 * @package Styble_AI
 */

// Pure, WP-free class: also loaded by the CLI scripts, which define STYBLE_AI_CLI.
if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

/**
 * A collected list of validation errors.
 *
 * Errors are accumulated rather than thrown on the first failure: the corrective
 * retry feeds the whole list back to the model, and one bad tree usually has more
 * than one problem. Each error carries a machine-readable code, a JSON path to
 * the offending node, and a message meant to be read by both a developer and an
 * LLM.
 */
class Styble_AI_Validation_Result {

	/**
	 * Stop collecting past this many errors; the tree is hopeless by then.
	 */
	const MAX_ERRORS = 50;

	/**
	 * Collected errors.
	 *
	 * @var array<int, array{code:string, path:string, message:string}>
	 */
	private $errors = array();

	/**
	 * Set once MAX_ERRORS is hit.
	 *
	 * @var bool
	 */
	private $truncated = false;

	/**
	 * Record an error.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $path    JSON path, e.g. root.children[0].attrs.layout.
	 * @param string $message Human/LLM-readable explanation.
	 *
	 * @return void
	 */
	public function add( $code, $path, $message ) {
		if ( count( $this->errors ) >= self::MAX_ERRORS ) {
			$this->truncated = true;
			return;
		}
		$this->errors[] = array(
			'code'    => $code,
			'path'    => $path,
			'message' => $message,
		);
	}

	/**
	 * @return bool
	 */
	public function is_valid() {
		return ! $this->errors;
	}

	/**
	 * @return bool
	 */
	public function is_truncated() {
		return $this->truncated;
	}

	/**
	 * @return array<int, array{code:string, path:string, message:string}>
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * @return array{code:string, path:string, message:string}|null
	 */
	public function first_error() {
		return $this->errors ? $this->errors[0] : null;
	}

	/**
	 * Every distinct error code, in first-seen order.
	 *
	 * @return string[]
	 */
	public function codes() {
		return array_values( array_unique( array_column( $this->errors, 'code' ) ) );
	}

	/**
	 * Did the tree fail with this specific code?
	 *
	 * @param string $code Error code.
	 *
	 * @return bool
	 */
	public function has_code( $code ) {
		return in_array( $code, array_column( $this->errors, 'code' ), true );
	}

	/**
	 * Serializable form, suitable for a REST response.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'valid'     => $this->is_valid(),
			'errors'    => $this->errors,
			'truncated' => $this->truncated,
		);
	}

	/**
	 * One error per line, for the CLI.
	 *
	 * @return string
	 */
	public function to_string() {
		if ( $this->is_valid() ) {
			return 'valid';
		}
		$lines = array();
		foreach ( $this->errors as $error ) {
			$lines[] = sprintf( '[%s] %s — %s', $error['code'], $error['path'], $error['message'] );
		}
		if ( $this->truncated ) {
			$lines[] = sprintf( '… stopped after %d errors.', self::MAX_ERRORS );
		}
		return implode( "\n", $lines );
	}
}

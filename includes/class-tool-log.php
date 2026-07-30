<?php
/**
 * A rolling log of tool calls.
 *
 * ZIP AI's `Event_Logger` equivalent. Deliberately small: this is for answering
 * "what did the model actually do on that turn", which is the question you have
 * when a page comes out wrong and the chat transcript says everything went fine.
 *
 * Arguments are recorded, results are recorded as a verdict plus a short reason —
 * not in full. A tool that returns a whole block tree would otherwise blow the
 * option up within a handful of turns, and the tree is already in
 * `_styble_ai_page` where it can be read properly.
 *
 * Separate from `class-usage-tracker.php` on purpose: that one answers "what was
 * billed" and must record every response before parsing (invariant 9). This one
 * answers "what was attempted" and is allowed to be lossy.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Tool_Log {

	const OPTION = 'styble_ai_tool_log';

	/**
	 * Entries kept. Matches the usage log's 200 so the two can be read side by
	 * side over the same window.
	 */
	const MAX = 200;

	/**
	 * Longest recorded argument blob, per call.
	 */
	const MAX_ARGS = 600;

	/**
	 * Record one call.
	 *
	 * Never throws and never blocks the tool: a logging failure must not turn a
	 * successful edit into a failed one.
	 *
	 * @param string $id     Tool id.
	 * @param array  $args   Raw arguments.
	 * @param array  $result Tool result.
	 *
	 * @return void
	 */
	public static function record( $id, array $args, array $result ) {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		try {
			$log = get_option( self::OPTION, array() );
			if ( ! is_array( $log ) ) {
				$log = array();
			}

			$log[] = array(
				'at'   => time(),
				'tool' => (string) $id,
				'ok'   => ! isset( $result['ok'] ) || false !== $result['ok'],
				'code' => isset( $result['code'] ) ? (string) $result['code'] : '',
				'why'  => self::reason( $result ),
				'args' => self::clip( $args ),
				'took' => isset( $result['took'] ) ? $result['took'] : null,
			);

			// Oldest go, not newest — the recent window is the useful one.
			if ( count( $log ) > self::MAX ) {
				$log = array_slice( $log, -self::MAX );
			}

			update_option( self::OPTION, $log, false );
		} catch ( Exception $e ) {
			return;
		} catch ( Error $e ) {
			return;
		}
	}

	/**
	 * The one-line "what happened" for an entry.
	 *
	 * Prefers the error, then anything the tool chose to summarise, then the
	 * counts a mutation reports. A successful call with nothing to say records ''
	 * rather than a fabricated summary.
	 *
	 * @param array $result Tool result.
	 *
	 * @return string
	 */
	private static function reason( array $result ) {
		if ( ! empty( $result['error'] ) ) {
			return self::trim_to( (string) $result['error'], 240 );
		}
		if ( ! empty( $result['message'] ) ) {
			return self::trim_to( (string) $result['message'], 240 );
		}

		$bits = array();
		foreach ( array( 'changed', 'applied', 'failed', 'unknown_attrs' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) && $result[ $key ] ) {
				$bits[] = $key . ':' . count( $result[ $key ] );
			}
		}
		if ( ! empty( $result['uid'] ) ) {
			array_unshift( $bits, (string) $result['uid'] );
		}

		return implode( ' ', $bits );
	}

	/**
	 * Arguments as a clipped JSON string.
	 *
	 * @param array $args Arguments.
	 *
	 * @return string
	 */
	private static function clip( array $args ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $args ) : json_encode( $args );
		return self::trim_to( (string) $json, self::MAX_ARGS );
	}

	/**
	 * @param string $str Input.
	 * @param int    $len Max length.
	 *
	 * @return string
	 */
	private static function trim_to( $str, $len ) {
		return strlen( $str ) > $len ? substr( $str, 0, $len - 1 ) . '…' : $str;
	}

	/**
	 * The log, newest last.
	 *
	 * @return array
	 */
	public static function all() {
		$log = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $log ) ? $log : array();
	}

	/**
	 * @return void
	 */
	public static function reset() {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION );
		}
	}
}

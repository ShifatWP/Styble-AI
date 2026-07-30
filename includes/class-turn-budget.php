<?php
/**
 * A hard token ceiling for one turn.
 *
 * ZIP AI does not have this and does not need it: credits are metered centrally on
 * their SaaS, so an over-long loop costs the account rather than surprising the
 * user mid-request. We spend the user's own key, so an agent loop that decides to
 * take forty steps is a real bill with no ceiling.
 *
 * `MAX_STEPS` alone is not the same guard. Twelve cheap reads are fine; three
 * whole-section generations are not, and a step count cannot tell them apart. So
 * the budget counts tokens and the step cap counts steps, and a turn stops on
 * whichever it hits first.
 *
 * Deliberately advisory-at-the-boundary rather than mid-call: a tool that has
 * already started is allowed to finish, because killing it halfway is exactly the
 * unconfirmed-mutation state B-1 exists to avoid. `allows()` is checked BEFORE a
 * call, never during.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Turn_Budget {

	/**
	 * Default ceiling for one conversational turn, in tokens.
	 *
	 * Sized against the measured prompt surface: the system prompt plus the tool
	 * schemas is roughly 7k tokens per call, so this is about six full calls. A
	 * scoped edit should take two or three.
	 */
	const DEFAULT_TOKENS = 45000;

	/**
	 * Default step ceiling. ZIP AI's editor loop settles in two or three tool
	 * calls for an ordinary edit; eight leaves room for a read, a correction and a
	 * verify without letting a confused model spiral.
	 */
	const DEFAULT_STEPS = 8;

	/**
	 * @var int
	 */
	private $token_cap;

	/**
	 * @var int
	 */
	private $step_cap;

	/**
	 * @var int
	 */
	private $tokens = 0;

	/**
	 * @var int
	 */
	private $steps = 0;

	/**
	 * Per-tool token attribution. I8: the open question is whether N granular
	 * calls cost more than one big call, and it cannot be answered by a total.
	 *
	 * @var array<string,int>
	 */
	private $by_tool = array();

	/**
	 * @param int|null $token_cap Tokens; default when null.
	 * @param int|null $step_cap  Steps; default when null.
	 */
	public function __construct( $token_cap = null, $step_cap = null ) {
		$tokens = null === $token_cap ? self::DEFAULT_TOKENS : (int) $token_cap;
		$steps  = null === $step_cap ? self::DEFAULT_STEPS : (int) $step_cap;

		/**
		 * Filter the per-turn token ceiling.
		 *
		 * @param int $tokens Token cap.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$tokens = (int) apply_filters( 'styble_ai_turn_token_budget', $tokens );
			$steps  = (int) apply_filters( 'styble_ai_turn_step_budget', $steps );
		}

		$this->token_cap = max( 1, $tokens );
		$this->step_cap  = max( 1, $steps );
	}

	/**
	 * May another call start?
	 *
	 * @return bool
	 */
	public function allows() {
		return $this->tokens < $this->token_cap && $this->steps < $this->step_cap;
	}

	/**
	 * Why the turn stopped, for the message handed back to the user. Empty while
	 * the budget still allows a call.
	 *
	 * @return string
	 */
	public function exhausted_because() {
		if ( $this->steps >= $this->step_cap ) {
			return sprintf( 'step limit reached (%d)', $this->step_cap );
		}
		if ( $this->tokens >= $this->token_cap ) {
			return sprintf( 'token budget reached (%d)', $this->token_cap );
		}
		return '';
	}

	/**
	 * Record one model call's cost.
	 *
	 * @param int    $tokens Total tokens for the call.
	 * @param string $tool   Tool the call resulted in, when known.
	 *
	 * @return void
	 */
	public function spend( $tokens, $tool = '' ) {
		$tokens        = max( 0, (int) $tokens );
		$this->tokens += $tokens;
		$this->steps++;

		if ( '' !== $tool ) {
			if ( ! isset( $this->by_tool[ $tool ] ) ) {
				$this->by_tool[ $tool ] = 0;
			}
			$this->by_tool[ $tool ] += $tokens;
		}
	}

	public function tokens() {
		return $this->tokens;
	}

	public function steps() {
		return $this->steps;
	}

	public function token_cap() {
		return $this->token_cap;
	}

	public function step_cap() {
		return $this->step_cap;
	}

	/**
	 * Per-tool token totals for this turn. I8 — recorded alongside the turn on the
	 * Token Usage screen so an expensive tool is identifiable rather than averaged
	 * into the total.
	 *
	 * @return array<string,int>
	 */
	public function by_tool() {
		arsort( $this->by_tool );
		return $this->by_tool;
	}

	/**
	 * A compact summary for logging and for the turn's closing message.
	 *
	 * @return array
	 */
	public function summary() {
		return array(
			'tokens'    => $this->tokens,
			'token_cap' => $this->token_cap,
			'steps'     => $this->steps,
			'step_cap'  => $this->step_cap,
			'by_tool'   => $this->by_tool(),
		);
	}
}

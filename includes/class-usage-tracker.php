<?php
/**
 * Token accounting — what every generation actually cost.
 *
 * Until now the only token number this plugin kept was
 * Styble_AI_Anthropic_Provider::last_usage(): the most recent call, on one
 * provider, readable only from a CLI eval run. That is enough to answer "is the
 * prefix cache working" and nothing else. A page is 5-7 model calls plus a
 * planner call, each with a corrective retry available, so the question a user
 * actually has — "what am I spending, and on what" — was unanswerable.
 *
 * This class answers it. Providers call record() once per HTTP response; the
 * numbers are normalised into one shape, added to a rolling total, and appended
 * to a capped log of recent calls. Styble_AI_Usage_Page renders both.
 *
 * Three decisions worth naming:
 *
 *  - **The two providers report different shapes, and the difference is a trap.**
 *    Anthropic's `input_tokens` EXCLUDES anything served from or written to the
 *    cache, so the prompt size is the sum of three fields. An OpenAI-compatible
 *    `prompt_tokens` INCLUDES the cached portion, which is reported separately in
 *    `prompt_tokens_details.cached_tokens`. Adding them the same way would
 *    double-count every cached token on one path and undercount on the other.
 *    normalise() is the only place that knows this.
 *
 *  - **Cost is computed at record time and stored, never recomputed.** Rates
 *    change; a historical total that silently re-prices itself when they do is
 *    worse than no total. A model with no known rate records a null cost and
 *    increments `unpriced` instead of quietly counting as free.
 *
 *  - **Usage is recorded before the response is parsed**, so a request that
 *    fails validation, truncates, or 4xxs after the model has already generated
 *    is still counted. Those are exactly the calls you are paying for and cannot
 *    see. The HTTP status rides along so the page can show them.
 *
 * Storage is two non-autoloaded options rather than a table: no activation hook,
 * no dbDelta, and the log is capped at LOG_LIMIT rows. The known cost of that
 * choice is that update_option() is read-modify-write, so two generations
 * running in genuinely parallel requests can lose one record. Every caller here
 * issues its requests one at a time (the chat screen builds a page section by
 * section), so this is a rounding error rather than a design flaw — but it is a
 * real one, and it is why this is an accounting aid and not a billing ledger.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Usage_Tracker {

	/**
	 * Rolling aggregates. Small and bounded — safe to keep in one option.
	 */
	const TOTALS_OPTION = 'styble_ai_usage_totals';

	/**
	 * Recent calls, newest last.
	 */
	const LOG_OPTION = 'styble_ai_usage_log';

	/**
	 * How many recent calls to keep. ~200 rows is a few days of real use and
	 * well under a megabyte serialized; the option is not autoloaded.
	 */
	const LOG_LIMIT = 200;

	/**
	 * How many daily buckets to keep. Two months is enough to see a trend and
	 * keeps the totals option from growing without bound.
	 */
	const DAY_LIMIT = 60;

	/**
	 * Anthropic prices a cache WRITE at 1.25x base input on the default 5-minute
	 * TTL (2x on the hour, which this plugin deliberately does not use — see
	 * Styble_AI_Anthropic_Provider::system_blocks()) and a cache READ at 0.1x.
	 *
	 * Applied to the input rate only. The OpenAI-compatible path reports cached
	 * tokens but never writes a cache itself, and the discount there is
	 * provider-specific — see rate_for().
	 */
	const CACHE_WRITE_MULTIPLIER = 1.25;
	const CACHE_READ_MULTIPLIER  = 0.1;

	/**
	 * Normalised usage from the most recent recorded call, in THIS request.
	 *
	 * Static rather than stored because the interesting use is comparative: the
	 * eval runner reads it after each case to see whether the prefix cache was
	 * read, and a zero read across cases means something in the prompt is not
	 * byte-identical. Persisting it would tell you nothing extra.
	 *
	 * @var array
	 */
	private static $last = array();

	/**
	 * @return array Normalised usage from the last record() in this request, or empty.
	 */
	public static function last() {
		return self::$last;
	}

	/**
	 * Account for one provider response.
	 *
	 * @param string $provider  Provider label ("anthropic", or the endpoint host).
	 * @param string $model     Model id as sent.
	 * @param string $operation What the call was for: plan|section|edit.
	 * @param array  $raw       The provider's own usage block, either shape.
	 * @param int    $ms        Round-trip time in milliseconds.
	 * @param int    $http      HTTP status, so failed-but-billed calls are visible.
	 *
	 * @return array The normalised usage that was recorded.
	 */
	public static function record( $provider, $model, $operation, array $raw, $ms = 0, $http = 200 ) {
		$usage = self::normalise( $raw );

		self::$last = $usage;

		$model     = (string) $model;
		$operation = (string) $operation;
		$cost      = self::cost( $usage, $model );

		self::add_to_totals( $provider, $model, $operation, $usage, $cost, (int) $http );
		self::append_to_log( $provider, $model, $operation, $usage, $cost, (int) $ms, (int) $http );

		return $usage;
	}

	/**
	 * Turn either provider's usage block into one shape.
	 *
	 * Returned keys, all integers:
	 *   in          input tokens charged at full price (cache neither read nor written)
	 *   cache_write input tokens written to the cache this call
	 *   cache_read  input tokens served from the cache this call
	 *   out         generated tokens
	 *   prompt      in + cache_write + cache_read, i.e. the whole prompt
	 *   total       prompt + out
	 *
	 * @param array $raw Provider usage block.
	 *
	 * @return array
	 */
	public static function normalise( array $raw ) {
		$usage = array(
			'in'          => 0,
			'cache_write' => 0,
			'cache_read'  => 0,
			'out'         => 0,
		);

		if ( isset( $raw['input_tokens'] ) || isset( $raw['output_tokens'] ) ) {
			// Anthropic. input_tokens is the UNCACHED remainder: the three input
			// figures are disjoint and the prompt is their sum.
			$usage['in']          = isset( $raw['input_tokens'] ) ? (int) $raw['input_tokens'] : 0;
			$usage['out']         = isset( $raw['output_tokens'] ) ? (int) $raw['output_tokens'] : 0;
			$usage['cache_read']  = isset( $raw['cache_read_input_tokens'] ) ? (int) $raw['cache_read_input_tokens'] : 0;
			$usage['cache_write'] = self::cache_creation( $raw );
		} else {
			// OpenAI-compatible. prompt_tokens INCLUDES whatever was cached, so
			// the cached portion has to be subtracted back out or it is counted
			// twice — once at full price and once at the cache rate.
			$prompt = isset( $raw['prompt_tokens'] ) ? (int) $raw['prompt_tokens'] : 0;
			$cached = 0;
			if ( isset( $raw['prompt_tokens_details']['cached_tokens'] ) ) {
				$cached = (int) $raw['prompt_tokens_details']['cached_tokens'];
			} elseif ( isset( $raw['cached_tokens'] ) ) {
				$cached = (int) $raw['cached_tokens'];
			}

			$usage['cache_read'] = max( 0, min( $cached, $prompt ) );
			$usage['in']         = max( 0, $prompt - $usage['cache_read'] );
			$usage['out']        = isset( $raw['completion_tokens'] ) ? (int) $raw['completion_tokens'] : 0;
			// No cache_write: these endpoints cache server-side with no parameter
			// to send and no write to report. Reasoning tokens, where a provider
			// reports them, are already inside completion_tokens.
		}

		$usage['prompt'] = $usage['in'] + $usage['cache_write'] + $usage['cache_read'];
		$usage['total']  = $usage['prompt'] + $usage['out'];

		return $usage;
	}

	/**
	 * Cache-creation tokens, whichever way the field arrives.
	 *
	 * Anthropic reports a flat cache_creation_input_tokens, and alongside it a
	 * cache_creation object broken down by TTL. Prefer the flat number; fall back
	 * to summing the breakdown so a response that only carries the object is not
	 * silently recorded as an uncached call.
	 *
	 * @param array $raw Anthropic usage block.
	 *
	 * @return int
	 */
	private static function cache_creation( array $raw ) {
		if ( isset( $raw['cache_creation_input_tokens'] ) ) {
			return (int) $raw['cache_creation_input_tokens'];
		}

		if ( isset( $raw['cache_creation'] ) && is_array( $raw['cache_creation'] ) ) {
			$sum = 0;
			foreach ( $raw['cache_creation'] as $tokens ) {
				if ( is_numeric( $tokens ) ) {
					$sum += (int) $tokens;
				}
			}
			return $sum;
		}

		return 0;
	}

	/**
	 * Price one call in US dollars.
	 *
	 * @param array  $usage Normalised usage.
	 * @param string $model Model id.
	 *
	 * @return float|null Dollars, or null when no rate is known for the model.
	 */
	public static function cost( array $usage, $model ) {
		$rate = self::rate_for( $model );
		if ( ! $rate ) {
			return null;
		}

		list( $in_rate, $out_rate ) = $rate;

		$dollars = ( $usage['in'] * $in_rate )
			+ ( $usage['cache_write'] * $in_rate * self::CACHE_WRITE_MULTIPLIER )
			+ ( $usage['cache_read'] * $in_rate * self::CACHE_READ_MULTIPLIER )
			+ ( $usage['out'] * $out_rate );

		return $dollars / 1000000;
	}

	/**
	 * Known rates, US dollars per million tokens: model => [ input, output ].
	 *
	 * Claude models only. The nine OpenAI-compatible presets span free tiers and
	 * paid ones and change independently of this plugin, so a wrong number there
	 * would be worse than an honest blank — those calls record a null cost and
	 * count as `unpriced`. Add your own with the filter:
	 *
	 *     add_filter( 'styble_ai_token_rates', function ( $rates ) {
	 *         $rates['deepseek-chat'] = array( 0.28, 0.42 );
	 *         return $rates;
	 *     } );
	 *
	 * These are list prices, and a cost column built from a hardcoded table is an
	 * ESTIMATE — read your provider's dashboard for the bill. Two known ways this
	 * table drifts: Claude Sonnet 5 has an introductory $2/$10 running to
	 * 2026-08-31, so its estimate reads high until then; and fast mode on Opus 5
	 * bills at $10/$50, which this plugin never requests.
	 *
	 * @return array
	 */
	public static function rates() {
		$rates = array(
			'claude-fable-5'    => array( 10.0, 50.0 ),
			'claude-opus-5'     => array( 5.0, 25.0 ),
			'claude-opus-4-8'   => array( 5.0, 25.0 ),
			'claude-opus-4-7'   => array( 5.0, 25.0 ),
			'claude-opus-4-6'   => array( 5.0, 25.0 ),
			'claude-sonnet-5'   => array( 3.0, 15.0 ),
			'claude-sonnet-4-6' => array( 3.0, 15.0 ),
			'claude-haiku-4-5'  => array( 1.0, 5.0 ),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$rates = apply_filters( 'styble_ai_token_rates', $rates );
		}

		return is_array( $rates ) ? $rates : array();
	}

	/**
	 * The rate pair for a model id: exact match, else the longest key the id
	 * starts with, so a dated or suffixed variant still prices.
	 *
	 * @param string $model Model id.
	 *
	 * @return array|null [ input, output ] per million tokens, or null.
	 */
	private static function rate_for( $model ) {
		$rates = self::rates();
		$model = (string) $model;

		if ( isset( $rates[ $model ] ) && is_array( $rates[ $model ] ) ) {
			return array_values( $rates[ $model ] );
		}

		$best = null;
		$len  = 0;
		foreach ( $rates as $key => $pair ) {
			if ( ! is_array( $pair ) || '' === (string) $key ) {
				continue;
			}
			if ( 0 === strpos( $model, (string) $key ) && strlen( (string) $key ) > $len ) {
				$best = array_values( $pair );
				$len  = strlen( (string) $key );
			}
		}

		return $best;
	}

	/**
	 * @return array Aggregates, with every key present even on a fresh install.
	 */
	public static function totals() {
		$stored = get_option( self::TOTALS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::empty_totals(), $stored );
	}

	/**
	 * @return array Recent calls, newest first.
	 */
	public static function log() {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}

		return array_reverse( $log );
	}

	/**
	 * Forget everything. Deliberately total — a partial reset would make the
	 * per-model and per-day breakdowns disagree with the headline figures.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::TOTALS_OPTION );
		delete_option( self::LOG_OPTION );
		self::$last = array();
	}

	/**
	 * @return array The zero state, which is also the shape of the option.
	 */
	private static function empty_totals() {
		return array(
			'calls'        => 0,
			'errors'       => 0,
			'unpriced'     => 0,
			'in'           => 0,
			'cache_write'  => 0,
			'cache_read'   => 0,
			'out'          => 0,
			'cost'         => 0.0,
			'by_model'     => array(),
			'by_operation' => array(),
			'by_day'       => array(),
			'first'        => 0,
			'last'         => 0,
		);
	}

	/**
	 * @return array A per-bucket row, for by_model / by_operation / by_day.
	 */
	private static function empty_bucket() {
		return array(
			'calls'       => 0,
			'in'          => 0,
			'cache_write' => 0,
			'cache_read'  => 0,
			'out'         => 0,
			'cost'        => 0.0,
		);
	}

	/**
	 * @param string     $provider  Provider label.
	 * @param string     $model     Model id.
	 * @param string     $operation Operation label.
	 * @param array      $usage     Normalised usage.
	 * @param float|null $cost      Dollars, or null when unpriced.
	 * @param int        $http      HTTP status.
	 *
	 * @return void
	 */
	private static function add_to_totals( $provider, $model, $operation, array $usage, $cost, $http ) {
		$totals = self::totals();
		$now    = time();

		$totals['calls']++;
		if ( $http < 200 || $http >= 300 ) {
			$totals['errors']++;
		}
		if ( null === $cost ) {
			$totals['unpriced']++;
		}

		foreach ( array( 'in', 'cache_write', 'cache_read', 'out' ) as $key ) {
			$totals[ $key ] += $usage[ $key ];
		}
		$totals['cost'] += (float) $cost;

		if ( ! $totals['first'] ) {
			$totals['first'] = $now;
		}
		$totals['last'] = $now;

		// Model and provider together, because the same model id behind two
		// endpoints is two different bills.
		$model_key = $provider ? $model . ' · ' . $provider : $model;

		$totals['by_model']     = self::bump( $totals['by_model'], $model_key, $usage, $cost );
		$totals['by_operation'] = self::bump( $totals['by_operation'], $operation ? $operation : 'unknown', $usage, $cost );
		$totals['by_day']       = self::bump( $totals['by_day'], self::today(), $usage, $cost );

		// Newest days last, then keep the tail.
		ksort( $totals['by_day'] );
		if ( count( $totals['by_day'] ) > self::DAY_LIMIT ) {
			$totals['by_day'] = array_slice( $totals['by_day'], -self::DAY_LIMIT, null, true );
		}

		update_option( self::TOTALS_OPTION, $totals, false );
	}

	/**
	 * Add one call into a named bucket.
	 *
	 * @param array      $buckets Existing buckets.
	 * @param string     $key     Bucket key.
	 * @param array      $usage   Normalised usage.
	 * @param float|null $cost    Dollars, or null.
	 *
	 * @return array
	 */
	private static function bump( array $buckets, $key, array $usage, $cost ) {
		if ( ! isset( $buckets[ $key ] ) || ! is_array( $buckets[ $key ] ) ) {
			$buckets[ $key ] = self::empty_bucket();
		}

		$bucket = array_merge( self::empty_bucket(), $buckets[ $key ] );
		$bucket['calls']++;
		foreach ( array( 'in', 'cache_write', 'cache_read', 'out' ) as $field ) {
			$bucket[ $field ] += $usage[ $field ];
		}
		$bucket['cost'] += (float) $cost;

		$buckets[ $key ] = $bucket;

		return $buckets;
	}

	/**
	 * @param string     $provider  Provider label.
	 * @param string     $model     Model id.
	 * @param string     $operation Operation label.
	 * @param array      $usage     Normalised usage.
	 * @param float|null $cost      Dollars, or null.
	 * @param int        $ms        Round-trip milliseconds.
	 * @param int        $http      HTTP status.
	 *
	 * @return void
	 */
	private static function append_to_log( $provider, $model, $operation, array $usage, $cost, $ms, $http ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'at'          => time(),
			'provider'    => (string) $provider,
			'model'       => (string) $model,
			'operation'   => (string) $operation,
			'user'        => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'in'          => $usage['in'],
			'cache_write' => $usage['cache_write'],
			'cache_read'  => $usage['cache_read'],
			'out'         => $usage['out'],
			'cost'        => $cost,
			'ms'          => $ms,
			'http'        => $http,
		);

		if ( count( $log ) > self::LOG_LIMIT ) {
			$log = array_slice( $log, -self::LOG_LIMIT );
		}

		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Today in the site's timezone, so a day bucket matches the day the user had.
	 *
	 * @return string Y-m-d
	 */
	private static function today() {
		if ( function_exists( 'wp_date' ) ) {
			$day = wp_date( 'Y-m-d' );
			if ( $day ) {
				return $day;
			}
		}

		return gmdate( 'Y-m-d' );
	}
}

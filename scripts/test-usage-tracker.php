<?php
/**
 * Styble AI — the token accounting.
 *
 * The defect this file exists to catch is a silent one: two providers report
 * usage in shapes that look interchangeable and are not. Anthropic's
 * `input_tokens` is the UNCACHED remainder, so the prompt is the sum of three
 * fields; an OpenAI-compatible `prompt_tokens` is the WHOLE prompt with the
 * cached part reported separately inside it. Add them the same way and every
 * cached token is either counted twice or lost — with no error, and with numbers
 * that still look plausible on the page.
 *
 * So the assertions below are mostly arithmetic identities:
 *
 *  - on both shapes, in + cache_write + cache_read is the real prompt size;
 *  - a cached OpenAI call and the equivalent Anthropic call agree on the prompt;
 *  - a cache read is priced at 0.1x input and a write at 1.25x, so a cached call
 *    costs less than the same call uncached (the entire point of the caching in
 *    Styble_AI_Anthropic_Provider);
 *  - a model with no rate records a null cost rather than a free one;
 *  - the rolling log is capped, and the day buckets are capped.
 *
 * No WordPress and no network: the options are an in-memory array.
 *
 * Run:  php scripts/test-usage-tracker.php
 * Exit: 0 when every invariant holds.
 *
 * @package Styble_AI
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

define( 'ABSPATH', __DIR__ );

/**
 * The whole options table, for this process.
 *
 * @var array
 */
$GLOBALS['styble_ai_options'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['styble_ai_options'] )
		? $GLOBALS['styble_ai_options'][ $key ]
		: $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['styble_ai_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['styble_ai_options'][ $key ] );
	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_current_user_id() {
	return 7;
}

require_once dirname( __DIR__ ) . '/includes/class-usage-tracker.php';

$failures = 0;
$checks   = 0;

/**
 * @param bool   $ok    Assertion result.
 * @param string $label What was being checked.
 * @param string $note  Optional detail, shown either way.
 */
function check( $ok, $label, $note = '' ) {
	global $failures, $checks;
	$checks++;
	printf( "  %-4s %-58s %s\n", $ok ? 'ok' : 'FAIL', $label, $note );
	if ( ! $ok ) {
		$failures++;
	}
}

/* ------------------------------------------------------------------ */
echo "\nANTHROPIC SHAPE\n";

$anthropic = Styble_AI_Usage_Tracker::normalise(
	array(
		'input_tokens'                => 41,
		'output_tokens'               => 900,
		'cache_creation_input_tokens' => 0,
		'cache_read_input_tokens'     => 5610,
	)
);

check( 41 === $anthropic['in'], 'in is the uncached remainder', 'input_tokens verbatim' );
check( 5610 === $anthropic['cache_read'], 'cache_read is carried over' );
check( 900 === $anthropic['out'], 'out is output_tokens' );
check(
	5651 === $anthropic['prompt'],
	'prompt sums the three input fields',
	'41 + 0 + 5610 — they are disjoint here'
);
check( 6551 === $anthropic['total'], 'total is prompt + out' );

// The TTL breakdown object, with no flat field. A response that only carried
// this would otherwise be recorded as an uncached call.
$breakdown = Styble_AI_Usage_Tracker::normalise(
	array(
		'input_tokens'   => 10,
		'output_tokens'  => 20,
		'cache_creation' => array(
			'ephemeral_5m_input_tokens' => 4000,
			'ephemeral_1h_input_tokens' => 200,
		),
	)
);
check( 4200 === $breakdown['cache_write'], 'cache_creation object is summed', 'no flat field present' );

/* ------------------------------------------------------------------ */
echo "\nOPENAI-COMPATIBLE SHAPE\n";

$openai = Styble_AI_Usage_Tracker::normalise(
	array(
		'prompt_tokens'         => 5651,
		'completion_tokens'     => 900,
		'total_tokens'          => 6551,
		'prompt_tokens_details' => array( 'cached_tokens' => 5610 ),
	)
);

check(
	41 === $openai['in'],
	'the cached part is subtracted out of prompt_tokens',
	'5651 - 5610; not counted twice'
);
check( 5610 === $openai['cache_read'], 'cache_read is the reported cached_tokens' );
check( 0 === $openai['cache_write'], 'no cache_write on this path', 'nothing to send, nothing reported' );
check(
	5651 === $openai['prompt'],
	'prompt matches prompt_tokens',
	'the field already IS the whole prompt'
);

check(
	$openai['prompt'] === $anthropic['prompt'] && $openai['out'] === $anthropic['out'],
	'both shapes agree on an identical call',
	'the reconciliation this class exists for'
);

// No details block at all — the common case on a provider that does not cache.
$plain = Styble_AI_Usage_Tracker::normalise(
	array(
		'prompt_tokens'     => 2500,
		'completion_tokens' => 400,
	)
);
check( 2500 === $plain['in'] && 0 === $plain['cache_read'], 'an uncached call reports all input as in' );

// Garbage in: a provider that reports a cached figure larger than the prompt
// must not produce a negative input count.
$absurd = Styble_AI_Usage_Tracker::normalise(
	array(
		'prompt_tokens'         => 100,
		'completion_tokens'     => 10,
		'prompt_tokens_details' => array( 'cached_tokens' => 999 ),
	)
);
check( 0 === $absurd['in'] && 100 === $absurd['cache_read'], 'a nonsense cached count is clamped, never negative' );

check(
	array() === array_diff_key( Styble_AI_Usage_Tracker::normalise( array() ), $plain ),
	'an empty usage block still returns the full shape'
);

/* ------------------------------------------------------------------ */
echo "\nPRICING\n";

$cost = Styble_AI_Usage_Tracker::cost( $anthropic, 'claude-opus-5' );
// 41 in + 5610 read + 900 out, at $5/$25 per Mtok with reads at 0.1x:
// (41*5 + 5610*5*0.1 + 900*25) / 1e6
$want = ( ( 41 * 5.0 ) + ( 5610 * 5.0 * 0.1 ) + ( 900 * 25.0 ) ) / 1000000;
check( abs( $cost - $want ) < 1e-12, 'opus-5 priced at 5/25 with reads at 0.1x', sprintf( '$%.6f', $cost ) );

$uncached = Styble_AI_Usage_Tracker::cost(
	array(
		'in'          => 5651,
		'cache_write' => 0,
		'cache_read'  => 0,
		'out'         => 900,
	),
	'claude-opus-5'
);
check(
	$cost < $uncached,
	'a cache READ is cheaper than paying full price',
	sprintf( '$%.4f vs $%.4f', $cost, $uncached )
);

$written = Styble_AI_Usage_Tracker::cost(
	array(
		'in'          => 41,
		'cache_write' => 5610,
		'cache_read'  => 0,
		'out'         => 900,
	),
	'claude-opus-5'
);
check(
	$written > $uncached,
	'a cache WRITE costs more than not caching, once',
	'1.25x — it pays back on the second call'
);

check(
	null === Styble_AI_Usage_Tracker::cost( $anthropic, 'llama-3.3-70b-versatile' ),
	'an unknown model prices as null, not as free'
);

check(
	null !== Styble_AI_Usage_Tracker::cost( $anthropic, 'claude-opus-5-some-future-suffix' ),
	'a suffixed model id still matches by prefix'
);

/* ------------------------------------------------------------------ */
echo "\nRECORDING\n";

Styble_AI_Usage_Tracker::reset();

Styble_AI_Usage_Tracker::record(
	'anthropic',
	'claude-opus-5',
	'plan',
	array(
		'input_tokens'                => 200,
		'output_tokens'               => 400,
		'cache_creation_input_tokens'  => 5000,
		'cache_read_input_tokens'      => 0,
	),
	1200
);

for ( $i = 0; $i < 5; $i++ ) {
	Styble_AI_Usage_Tracker::record(
		'anthropic',
		'claude-opus-5',
		'section',
		array(
			'input_tokens'                => 60,
			'output_tokens'               => 800,
			'cache_creation_input_tokens'  => 0,
			'cache_read_input_tokens'      => 5000,
		),
		9000
	);
}

// A rate-limited call: generated nothing usable, still reported usage, still billed.
Styble_AI_Usage_Tracker::record(
	'anthropic',
	'claude-opus-5',
	'section',
	array(
		'input_tokens'            => 60,
		'output_tokens'           => 16000,
		'cache_read_input_tokens' => 5000,
	),
	120000,
	429
);

$totals = Styble_AI_Usage_Tracker::totals();

check( 7 === $totals['calls'], 'every call is counted', '1 plan + 6 sections' );
check( 1 === $totals['errors'], 'the failed call is counted as an error' );
check( 0 === $totals['unpriced'], 'nothing unpriced on a known model' );
check( 5000 === $totals['cache_write'], 'the prefix was written exactly once' );
check( 30000 === $totals['cache_read'], 'and read on all six later calls' );
check( $totals['cost'] > 0, 'cost accumulated', sprintf( '$%.4f', $totals['cost'] ) );

check(
	isset( $totals['by_operation']['plan'], $totals['by_operation']['section'] ),
	'operations are bucketed separately'
);
check(
	1 === $totals['by_operation']['plan']['calls'] && 6 === $totals['by_operation']['section']['calls'],
	'per-operation call counts are right'
);
check(
	isset( $totals['by_model']['claude-opus-5 · anthropic'] ),
	'the model bucket is keyed by model AND provider',
	'one id behind two endpoints is two bills'
);

$sum_of_buckets = 0;
foreach ( $totals['by_operation'] as $bucket ) {
	$sum_of_buckets += $bucket['out'];
}
check(
	$sum_of_buckets === $totals['out'],
	'the breakdowns add up to the headline figure'
);

check( 1 === count( $totals['by_day'] ), 'one day bucket for one day of calls' );

$log = Styble_AI_Usage_Tracker::log();
check( 7 === count( $log ), 'every call is logged' );
check( 429 === $log[0]['http'], 'the log is newest first', 'the 429 was last recorded' );
check( 7 === $log[0]['user'], 'the acting user is recorded' );
check( 120000 === $log[0]['ms'], 'round-trip time is recorded' );

$unpriced = Styble_AI_Usage_Tracker::record(
	'api.groq.com',
	'llama-3.3-70b-versatile',
	'section',
	array(
		'prompt_tokens'     => 2500,
		'completion_tokens' => 300,
	)
);
$totals = Styble_AI_Usage_Tracker::totals();
check( 1 === $totals['unpriced'], 'an unpriced call is flagged, and still counted' );
check( 2500 === $totals['by_model']['llama-3.3-70b-versatile · api.groq.com']['in'], 'its tokens are still recorded' );
check(
	null === Styble_AI_Usage_Tracker::log()[0]['cost'],
	'its logged cost is null, not zero',
	'zero would read as free'
);

/* ------------------------------------------------------------------ */
echo "\nBOUNDS\n";

Styble_AI_Usage_Tracker::reset();
check( 0 === Styble_AI_Usage_Tracker::totals()['calls'], 'reset clears the totals' );
check( array() === Styble_AI_Usage_Tracker::log(), 'reset clears the log' );

$over = Styble_AI_Usage_Tracker::LOG_LIMIT + 25;
for ( $i = 0; $i < $over; $i++ ) {
	Styble_AI_Usage_Tracker::record(
		'anthropic',
		'claude-opus-5',
		'section',
		array(
			'input_tokens'  => 1,
			'output_tokens' => 1,
		)
	);
}

$log    = Styble_AI_Usage_Tracker::log();
$totals = Styble_AI_Usage_Tracker::totals();
check(
	Styble_AI_Usage_Tracker::LOG_LIMIT === count( $log ),
	'the log is capped',
	sprintf( '%d recorded, %d kept', $over, count( $log ) )
);
check(
	$over === $totals['calls'],
	'the totals are NOT capped',
	'trimming the log must not lose the aggregate'
);

// Day buckets: forced directly, because the recorder can only ever write today.
$totals['by_day'] = array();
for ( $i = 0; $i < Styble_AI_Usage_Tracker::DAY_LIMIT + 10; $i++ ) {
	$totals['by_day'][ gmdate( 'Y-m-d', 1700000000 + ( $i * 86400 ) ) ] = array(
		'calls'       => 1,
		'in'          => 1,
		'cache_write' => 0,
		'cache_read'  => 0,
		'out'         => 1,
		'cost'        => 0.0,
	);
}
update_option( Styble_AI_Usage_Tracker::TOTALS_OPTION, $totals, false );
Styble_AI_Usage_Tracker::record(
	'anthropic',
	'claude-opus-5',
	'section',
	array(
		'input_tokens'  => 1,
		'output_tokens' => 1,
	)
);
$days = Styble_AI_Usage_Tracker::totals()['by_day'];
check(
	count( $days ) <= Styble_AI_Usage_Tracker::DAY_LIMIT,
	'day buckets are capped',
	sprintf( '%d kept', count( $days ) )
);
check(
	array_key_exists( gmdate( 'Y-m-d' ), $days ) || array_key_exists( date( 'Y-m-d' ), $days ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	'and today survived the trim',
	'the oldest go, not the newest'
);

/* ------------------------------------------------------------------ */
echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );

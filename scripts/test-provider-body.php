<?php
/**
 * Styble AI — the exact request body each provider sends.
 *
 * Prompt caching is a prefix match: the cache key is the bytes of the rendered
 * request up to the breakpoint, so a single byte that differs between two calls
 * silently costs full price. There is no error, no warning, and no symptom other
 * than a bill — which is precisely the class of defect this repo has been bad at
 * catching, and the reason the eval runner now prints cache accounting.
 *
 * So this file asserts the shape of the body rather than trusting it:
 *
 *  - the breakpoint sits on the LAST system block, because the render order is
 *    tools -> system -> messages and one breakpoint there covers both;
 *  - tools and system are byte-identical between a first attempt and the
 *    corrective retry, which is where the doubling would otherwise happen;
 *  - nothing in the prefix is non-deterministic (the classic invalidators are a
 *    timestamp or a uuid, and neither would ever announce itself);
 *  - the OpenAI-compatible path carries NO cache_control, deliberately — it is
 *    Anthropic's parameter and strict endpoints reject unknown fields.
 *
 * It also records which models the prefix is long enough to cache on at all.
 * The minimum is model-specific and NOT monotonic across generations, so a
 * prefix that caches on one Opus can be silently uncacheable on an older one.
 *
 * No network, no API key, no WordPress — wp_remote_post is stubbed and the body
 * it would have sent is captured instead.
 *
 * Run:  php scripts/test-provider-body.php
 * Exit: 0 when every invariant holds.
 *
 * @package Styble_AI
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

define( 'STYBLE_AI_CLI', true );
// The provider classes are WP-guarded; satisfy the guard, then stub what they call.
define( 'ABSPATH', __DIR__ );

/**
 * Minimal WP_Error stand-in with the same surface the providers use.
 */
class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
	public function get_error_data() {
		return null; }
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

function get_option( $key, $default = '' ) {
	return $default;
}

function __( $text, $domain = '' ) {
	return $text;
}

// Both providers now hand every usage block to Styble_AI_Usage_Tracker. Its own
// invariants are asserted by scripts/test-usage-tracker.php; here the stubs exist
// so the recording path is exercised rather than mocked away — if a provider ever
// calls record() with the wrong shape, this file fails too.
function update_option( $key, $value, $autoload = null ) {
	return true;
}

function delete_option( $key ) {
	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}

/**
 * Bodies captured from wp_remote_post, newest last.
 *
 * @var array
 */
$GLOBALS['styble_ai_sent'] = array();

function wp_remote_post( $url, $args ) {
	$GLOBALS['styble_ai_sent'][] = json_decode( $args['body'], true );

	// A minimal valid forced-tool response, with the usage block the provider
	// records so the cache-accounting path is exercised too.
	return array(
		'code' => 200,
		'body' => json_encode(
			array(
				'content' => array(
					array(
						'type'  => 'tool_use',
						'input' => array(
							'version' => '0.1.0',
							'root'    => array( 'block' => 'styble/container' ),
						),
					),
				),
				'usage'   => array(
					'input_tokens'                => 41,
					'cache_creation_input_tokens'  => 5610,
					'cache_read_input_tokens'      => 0,
				),
			)
		),
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

$root = dirname( __DIR__ );
require_once $root . '/includes/class-catalog.php';
require_once $root . '/includes/class-brand-context.php';
require_once $root . '/includes/class-prompt.php';
require_once $root . '/includes/class-usage-tracker.php';
require_once $root . '/includes/class-anthropic-provider.php';
require_once $root . '/includes/class-openai-compatible-provider.php';

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
	printf( "  %-4s %-56s %s\n", $ok ? 'ok' : 'FAIL', $label, $note );
	if ( ! $ok ) {
		$failures++;
	}
}

$catalog = Styble_AI_Catalog::from_file( $root . '/catalog/catalog.json' );
$prompt  = new Styble_AI_Prompt( $catalog );

$spec = array(
	'system'   => $prompt->system_prompt(),
	'tool'     => array(
		'name'         => $prompt->tool_name(),
		'description'  => $prompt->tool_description(),
		'input_schema' => $prompt->tool_schema(),
	),
	'messages' => array(
		array(
			'role'  => 'user',
			'text'  => 'A hero section for a coffee roaster.',
			'image' => '',
		),
	),
);

$anthropic = new Styble_AI_Anthropic_Provider( 'test-key', 'claude-opus-5' );

// Attempt 1, then the corrective retry: the same system and tools with a longer
// user turn, exactly as Styble_AI_Generator::message_for() builds it.
$anthropic->complete( $spec );
$retry_spec = $spec;
$retry_spec['messages'][0]['text'] .= "\n\nYour previous attempt was rejected:\n\n```json\n{}\n```";
$anthropic->complete( $retry_spec );

$first = $GLOBALS['styble_ai_sent'][0];
$again = $GLOBALS['styble_ai_sent'][1];

/* ------------------------------------------------------------------ */
echo "\nCACHE BREAKPOINT PLACEMENT\n";

check(
	is_array( $first['system'] ) && isset( $first['system'][0]['type'] ) && 'text' === $first['system'][0]['type'],
	'system is sent as an array of text blocks',
	count( (array) $first['system'] ) . ' block'
);

$last_block = is_array( $first['system'] ) ? $first['system'][ count( $first['system'] ) - 1 ] : array();
check(
	isset( $last_block['cache_control']['type'] ) && 'ephemeral' === $last_block['cache_control']['type'],
	'ephemeral breakpoint on the LAST system block',
	'covers tools + system'
);

check(
	! isset( $last_block['cache_control']['ttl'] ),
	'no ttl, i.e. the 5-minute default',
	'a 1h write costs 2x base vs 1.25x'
);

check(
	1 === substr_count( wp_json_encode( $first ), '"cache_control"' ),
	'exactly one breakpoint in the whole body',
	'four are allowed; more here would be redundant'
);

check(
	! isset( $first['tools'][0]['cache_control'] ),
	'the tool carries no breakpoint of its own',
	'tools render before system'
);

/* ------------------------------------------------------------------ */
echo "\nPREFIX STABILITY — attempt 1 vs corrective retry\n";

check(
	wp_json_encode( $first['tools'] ) === wp_json_encode( $again['tools'] ),
	'tools byte-identical',
	number_format( strlen( wp_json_encode( $first['tools'] ) ) ) . ' bytes'
);

check(
	wp_json_encode( $first['system'] ) === wp_json_encode( $again['system'] ),
	'system byte-identical',
	number_format( strlen( wp_json_encode( $first['system'] ) ) ) . ' bytes'
);

check(
	$first['model'] === $again['model'],
	'model byte-identical',
	'caches are model-scoped'
);

check(
	wp_json_encode( $first['messages'] ) !== wp_json_encode( $again['messages'] ),
	'only the user message differs',
	'the retry restates rather than replaying tool_use'
);

/* ------------------------------------------------------------------ */
echo "\nNO SILENT INVALIDATORS IN THE PREFIX\n";

$prefix = wp_json_encode( $first['tools'] ) . wp_json_encode( $first['system'] );

check(
	! preg_match( '/\d{4}-\d{2}-\d{2}T\d{2}:/', $prefix ),
	'no ISO timestamp in the prefix'
);

check(
	! preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-/i', $prefix ),
	'no uuid in the prefix'
);

$rebuilt = new Styble_AI_Prompt( Styble_AI_Catalog::from_file( $root . '/catalog/catalog.json' ) );
check(
	$rebuilt->system_prompt() === $prompt->system_prompt(),
	'system prompt is deterministic when rebuilt'
);
check(
	wp_json_encode( $rebuilt->tool_schema() ) === wp_json_encode( $prompt->tool_schema() ),
	'tool schema is deterministic when rebuilt'
);

/* ------------------------------------------------------------------ */
echo "\nPREFIX LENGTH vs THE PER-MODEL MINIMUM\n";

// Rough, and deliberately so: an exact count needs the count_tokens endpoint,
// which needs a key. 3.7 chars/token is close enough to tell 5,800 from 500.
$est = (int) round( strlen( $prefix ) / 3.7 );
printf( "       section prefix ~%s tokens (%s bytes, estimated)\n", number_format( $est ), number_format( strlen( $prefix ) ) );

// Not monotonic across generations — an older Opus needs a LONGER prefix.
$minimums = array(
	'claude-opus-5'    => 512,
	'claude-opus-4-8'  => 1024,
	'claude-sonnet-5'  => 1024,
	'claude-opus-4-7'  => 2048,
	'claude-opus-4-6'  => 4096,
	'claude-haiku-4-5' => 4096,
);
foreach ( $minimums as $model => $min ) {
	printf( "       %-18s min %5d  %s\n", $model, $min, $est >= $min ? 'caches' : 'BELOW MINIMUM' );
}
check(
	$est >= max( $minimums ),
	'section prefix clears every listed model minimum',
	'so switching model cannot silently stop caching'
);

/* ------------------------------------------------------------------ */
echo "\nDOCUMENTED REQUEST DECISIONS STILL HOLD\n";

check(
	isset( $first['tool_choice']['type'] ) && 'tool' === $first['tool_choice']['type']
		&& Styble_AI_Prompt::TOOL_NAME === $first['tool_choice']['name'],
	'tool_choice still forces ' . Styble_AI_Prompt::TOOL_NAME
);
check(
	! array_key_exists( 'temperature', $first ),
	'no temperature',
	'sampling params 400 on Opus 4.7+'
);
check(
	! array_key_exists( 'thinking', $first ),
	'no thinking key',
	'off = the tool call silently becomes text'
);

/* ------------------------------------------------------------------ */
echo "\nUSAGE CAPTURE\n";

$usage = Styble_AI_Anthropic_Provider::last_usage();
check( ! empty( $usage ), 'last_usage() returns the usage block' );
check( isset( $usage['cache_creation_input_tokens'] ), 'cache_creation_input_tokens is readable' );
check( isset( $usage['cache_read_input_tokens'] ), 'cache_read_input_tokens is readable' );

$tracked = Styble_AI_Usage_Tracker::last();
check( ! empty( $tracked ), 'the provider reached the usage tracker' );
check(
	41 === $tracked['in'] && 5610 === $tracked['cache_write'] && 0 === $tracked['cache_read'],
	'tracker read the Anthropic shape correctly',
	'in:41 write:5610 read:0'
);
check(
	5651 === $tracked['prompt'],
	'prompt = in + write + read',
	'Anthropic input_tokens EXCLUDES the cached part'
);

/* ------------------------------------------------------------------ */
echo "\nEMPTY SYSTEM PROMPT\n";

$empty_spec           = $spec;
$empty_spec['system'] = '';
$anthropic->complete( $empty_spec );
$empty = $GLOBALS['styble_ai_sent'][ count( $GLOBALS['styble_ai_sent'] ) - 1 ];
check(
	is_string( $empty['system'] ),
	'falls back to a plain string',
	'an empty text block is rejected by the API'
);

/* ------------------------------------------------------------------ */
echo "\nOPENAI-COMPATIBLE PATH IS DELIBERATELY UNCACHED\n";

$openai = new Styble_AI_OpenAI_Compatible_Provider( 'test-key', 'gemini-2.0-flash', 'https://example.invalid/v1/chat/completions' );
$openai->complete( $spec );
$oa = $GLOBALS['styble_ai_sent'][ count( $GLOBALS['styble_ai_sent'] ) - 1 ];

check(
	false === strpos( wp_json_encode( $oa ), 'cache_control' ),
	'no cache_control anywhere in the body',
	'strict endpoints reject unknown fields'
);
check(
	isset( $oa['messages'][0]['role'] ) && 'system' === $oa['messages'][0]['role']
		&& is_string( $oa['messages'][0]['content'] ),
	'system stays a plain string in messages[0]'
);
check(
	array_key_exists( 'temperature', $oa ),
	'temperature still sent here',
	'still valid on this path'
);

/* ------------------------------------------------------------------ */
echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );

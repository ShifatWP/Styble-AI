<?php
/**
 * Styble AI — exercise the generation pipeline against a stub provider.
 *
 * Covers the part that cannot be seen from the fixture suite: that a rejected
 * tree is actually sent back to the model with the validator's own errors, that
 * a second rejection fails loudly instead of applying something plausible, and
 * that the retry prompt really carries the error list.
 *
 * No network, no API key, no WordPress — the handful of WP functions the
 * pipeline touches are stubbed below.
 *
 * Run:  php scripts/test-generator.php
 * Exit: 0 when every case passes.
 *
 * @package Styble_AI
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

define( 'STYBLE_AI_CLI', true );
// The pipeline classes are WP-guarded; satisfy the guard, then stub what they call.
define( 'ABSPATH', __DIR__ );

/**
 * Minimal WP_Error stand-in with the same surface the pipeline uses.
 */
class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
	public function get_error_data() {
		return $this->data; }
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

$root = dirname( __DIR__ );
require_once $root . '/includes/class-catalog.php';
require_once $root . '/includes/class-brand-context.php';
require_once $root . '/includes/class-prompt.php';
require_once $root . '/includes/class-validation-result.php';
require_once $root . '/includes/class-validator.php';
require_once $root . '/includes/class-generator.php';

/**
 * Provider stub: replays a scripted list of responses and records what it was
 * asked, so the retry prompt itself can be asserted on.
 */
class Stub_Provider {

	public $calls = array();
	private $queue;

	public function __construct( array $queue ) {
		$this->queue = $queue;
	}

	public function complete( array $spec ) {
		$this->calls[] = $spec;
		if ( ! $this->queue ) {
			return new WP_Error( 'stub_exhausted', 'Stub provider ran out of responses.' );
		}
		return array_shift( $this->queue );
	}
}

$catalog = Styble_AI_Catalog::from_file( $root . '/catalog/catalog.json' );

$fixtures = $root . '/tests/fixtures/';
$valid    = json_decode( file_get_contents( $fixtures . 'valid.hero.json' ), true );
$bad      = json_decode( file_get_contents( $fixtures . 'invalid.attr_unknown.json' ), true );

$failures = 0;

/**
 * @param bool   $ok    Did the case pass?
 * @param string $label Case name.
 * @param string $note  Detail shown either way.
 */
function check( $ok, $label, $note = '' ) {
	global $failures;
	if ( $ok ) {
		fwrite( STDOUT, sprintf( "  ok    %-42s %s\n", $label, $note ) );
		return;
	}
	$failures++;
	fwrite( STDOUT, sprintf( "  FAIL  %-42s %s\n", $label, $note ) );
}

// 1. A valid tree on the first try is returned as-is, with no second call.
$provider = new Stub_Provider( array( $valid ) );
$result   = ( new Styble_AI_Generator( $catalog, $provider ) )->generate( 'A hero section' );
check(
	! is_wp_error( $result ) && 1 === $result['attempts'] && 1 === count( $provider->calls ),
	'valid first try',
	is_wp_error( $result ) ? $result->get_error_message() : 'attempts=' . $result['attempts'] . ', calls=' . count( $provider->calls )
);

// 2. Rejected then corrected: two calls, and the tree comes back.
$provider = new Stub_Provider( array( $bad, $valid ) );
$result   = ( new Styble_AI_Generator( $catalog, $provider ) )->generate( 'A hero section' );
check(
	! is_wp_error( $result ) && 2 === $result['attempts'] && 2 === count( $provider->calls ),
	'rejected then corrected',
	is_wp_error( $result ) ? $result->get_error_message() : 'attempts=' . $result['attempts'] . ', calls=' . count( $provider->calls )
);

// 3. The retry actually carries the validator's errors, not just a nudge.
$retry_text = isset( $provider->calls[1]['messages'][0]['text'] ) ? $provider->calls[1]['messages'][0]['text'] : '';
check(
	false !== strpos( $retry_text, 'attr_unknown' )
		&& false !== strpos( $retry_text, 'rejected' )
		&& false !== strpos( $retry_text, 'A hero section' ),
	'retry prompt carries code + original request',
	strlen( $retry_text ) . ' chars'
);

// 4. Rejected twice: an explicit error carrying the error list, never a tree.
$provider = new Stub_Provider( array( $bad, $bad ) );
$result   = ( new Styble_AI_Generator( $catalog, $provider ) )->generate( 'A hero section' );
$data     = is_wp_error( $result ) ? $result->get_error_data() : array();
check(
	is_wp_error( $result )
		&& 'styble_ai_invalid_tree' === $result->get_error_code()
		&& 2 === count( $provider->calls )
		&& ! empty( $data['errors'] )
		&& 422 === $data['status'],
	'rejected twice fails loudly',
	is_wp_error( $result ) ? count( $data['errors'] ) . ' error(s), status ' . $data['status'] : 'returned a tree!'
);

// 5. A provider-level failure is passed straight through, not retried.
$provider = new Stub_Provider( array( new WP_Error( 'styble_ai_api_error', 'AI request failed: boom' ) ) );
$result   = ( new Styble_AI_Generator( $catalog, $provider ) )->generate( 'A hero section' );
check(
	is_wp_error( $result ) && 'styble_ai_api_error' === $result->get_error_code() && 1 === count( $provider->calls ),
	'provider error is not retried',
	is_wp_error( $result ) ? $result->get_error_code() : 'no error'
);

// 6. The spec handed to the provider is the generated one.
$provider = new Stub_Provider( array( $valid ) );
( new Styble_AI_Generator( $catalog, $provider ) )->generate( 'A hero section' );
$spec = $provider->calls[0];
check(
	'emit_layout' === $spec['tool']['name']
		&& ! empty( $spec['tool']['input_schema']['properties']['root'] )
		&& false !== strpos( $spec['system'], 'styble/container' ),
	'spec carries the generated prompt + schema',
	'tool=' . $spec['tool']['name'] . ', system=' . strlen( $spec['system'] ) . ' chars'
);

// 7. Edit mode sends the selection as context and still emits a tree.
$provider = new Stub_Provider( array( $valid ) );
$result   = ( new Styble_AI_Generator( $catalog, $provider ) )->edit( 'make it dark', '<!-- wp:styble/container --><!-- /wp:styble/container -->' );
$sent     = $provider->calls[0]['messages'][0]['text'];
check(
	! is_wp_error( $result )
		&& false !== strpos( $sent, 'styble/container' )
		&& false !== strpos( $sent, 'make it dark' ),
	'edit sends the selection as context',
	is_wp_error( $result ) ? $result->get_error_message() : strlen( $sent ) . ' chars'
);

fwrite( STDOUT, sprintf( "\n7 case(s): %d passed, %d failed\n", 7 - $failures, $failures ) );
exit( $failures > 0 ? 1 : 0 );

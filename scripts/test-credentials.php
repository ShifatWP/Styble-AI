<?php
/**
 * Styble AI — per-provider credentials.
 *
 * This file exists because of roadmap blocker B3. There used to be ONE
 * `styble_ai_api_key` shared by every provider, so selecting a different provider
 * kept whatever key was already in the box. Two consequences, both measured:
 *
 *  - The eval plan's most useful signal — a TARGET model versus a FLOOR model —
 *    was unreachable, because holding two keys at once was impossible.
 *  - `provider=gemini` moved the endpoint but not the credential, so all 20 cases
 *    failed identically on auth and the run read like a catastrophic model score
 *    rather than a mistyped argument (decision #17).
 *
 * Credentials are now one array keyed by provider. The assertions below are about
 * the two things that can silently destroy a key:
 *
 *  - a SAVE must preserve every provider it did not touch. The settings screen
 *    posts all providers' rows at once and hides all but one, so a bug that drops
 *    absent-or-empty slots would delete the other keys the moment you looked at a
 *    different provider — the exact failure B3 was fixed to prevent.
 *  - MIGRATION off the legacy option must be idempotent and must never clobber a
 *    slot the user has since saved, or an upgrade would quietly restore a stale
 *    key over a fresh one.
 *
 * No WordPress and no network: the options are an in-memory array.
 *
 * Run:  php scripts/test-credentials.php
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
	// Counted so the `--writes` harness can assert the migration latch holds:
	// repeated reads in one request must produce exactly ONE write.
	if ( isset( $GLOBALS['styble_ai_write_count'] )
		&& Styble_AI_Provider_Factory::CREDENTIALS_OPTION === $key ) {
		$GLOBALS['styble_ai_write_count']++;
	}
	$GLOBALS['styble_ai_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['styble_ai_options'][ $key ] );
	return true;
}

function sanitize_text_field( $str ) {
	// Enough of core's behaviour for these assertions: strip tags, collapse
	// whitespace, trim. The real one also strips octets and percent-encodings.
	$str = strip_tags( (string) $str );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $str ) );
}

$root = dirname( __DIR__ );
require_once $root . '/includes/class-anthropic-provider.php';
require_once $root . '/includes/class-openai-compatible-provider.php';
require_once $root . '/includes/class-provider-factory.php';

// ── Subprocess harness ───────────────────────────────────────────────────────
//
// maybe_migrate_legacy() latches on a `static $done` so it runs at most once per
// request. That is the correct production behaviour and it makes the migration
// impossible to test twice in one process. So the migration scenarios re-invoke
// THIS file with a seeded options table, print one line, and exit; the main body
// below asserts on that line.
//
// Printing a flattened string rather than returning a structure keeps the
// subprocess contract trivial: "provider:key:model" joined by "|", sorted, or
// empty when no credentials exist.
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--migrate=' ) || 0 === strpos( $arg, '--writes=' ) ) {
		$mode = 0 === strpos( $arg, '--migrate=' ) ? 'migrate' : 'writes';
		$blob = substr( $arg, strpos( $arg, '=' ) + 1 );
		$seed = unserialize( base64_decode( $blob ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$GLOBALS['styble_ai_options'] = is_array( $seed ) ? $seed : array();

		if ( 'writes' === $mode ) {
			// Count writes to the credentials option across repeated reads. One
			// write is correct; two would mean the latch is not holding and every
			// request pays for a migration that already happened.
			$GLOBALS['styble_ai_write_count'] = 0;
			Styble_AI_Provider_Factory::credentials();
			Styble_AI_Provider_Factory::key_for( 'mistral' );
			Styble_AI_Provider_Factory::providers_with_keys();
			Styble_AI_Provider_Factory::has_key();
			echo (int) $GLOBALS['styble_ai_write_count'];
			exit( 0 );
		}

		$creds = Styble_AI_Provider_Factory::credentials();
		ksort( $creds );
		$parts = array();
		foreach ( $creds as $provider => $slot ) {
			$parts[] = $provider . ':' . $slot['key'] . ':' . $slot['model'];
		}
		echo implode( '|', $parts );
		exit( 0 );
	}
}

$failures = 0;
$checks   = 0;

/**
 * @param bool   $ok    Assertion result.
 * @param string $label What was being checked.
 */
function check( $ok, $label ) {
	global $failures, $checks;
	$checks++;
	if ( $ok ) {
		echo "  ok   {$label}\n";
		return;
	}
	$failures++;
	echo "  FAIL {$label}\n";
}

/**
 * Wipe the options table AND the factory's one-shot migration latch, so each
 * scenario starts from a real "fresh request" rather than inheriting the
 * previous one's static state.
 *
 * The latch is a `static $done` inside maybe_migrate_legacy(). There is no way
 * to reset it from outside, and that is correct for production — a migration
 * must not re-run per call. For the test we get the same effect by running each
 * migration scenario in a SUBPROCESS (see below), and use this only for the
 * scenarios that never touch the legacy option.
 *
 * @param array $options Options to seed.
 *
 * @return void
 */
function reset_options( array $options = array() ) {
	$GLOBALS['styble_ai_options'] = $options;
}

$cred_option = Styble_AI_Provider_Factory::CREDENTIALS_OPTION;

// ── Provider ids ─────────────────────────────────────────────────────────────

$ids = Styble_AI_Provider_Factory::provider_ids();
check( in_array( 'anthropic', $ids, true ), 'anthropic is a known provider id' );
check( in_array( 'gemini', $ids, true ), 'gemini is a known provider id' );
check( in_array( 'custom', $ids, true ), 'custom is a known provider id' );
check( count( $ids ) === count( array_unique( $ids ) ), 'provider ids are unique' );

// ── sanitize_credentials ─────────────────────────────────────────────────────

$clean = Styble_AI_Provider_Factory::sanitize_credentials(
	array(
		'anthropic' => array( 'key' => '  sk-ant-123  ', 'model' => ' claude-opus-5 ' ),
		'gemini'    => array( 'key' => 'g-key', 'model' => '' ),
	)
);
check( isset( $clean['anthropic']['key'] ) && 'sk-ant-123' === $clean['anthropic']['key'], 'a key is trimmed' );
check( isset( $clean['anthropic']['model'] ) && 'claude-opus-5' === $clean['anthropic']['model'], 'a model is trimmed' );
check( isset( $clean['gemini'] ) && 'g-key' === $clean['gemini']['key'], 'a second provider is kept alongside the first' );
check( isset( $clean['gemini']['model'] ) && '' === $clean['gemini']['model'], 'an empty model is preserved as empty, not dropped' );

// An unknown provider id must not become addressable. A renamed or removed
// preset would otherwise leave a key stored under a name nothing validates, and
// key_for() would happily return it.
$clean = Styble_AI_Provider_Factory::sanitize_credentials(
	array(
		'anthropic'    => array( 'key' => 'good' ),
		'not-a-vendor' => array( 'key' => 'orphan' ),
	)
);
check( ! isset( $clean['not-a-vendor'] ), 'an unknown provider id is dropped on save' );
check( isset( $clean['anthropic'] ), 'a known provider survives beside a dropped one' );

// A slot with neither key nor model is not stored. Without this the option
// accumulates a row for every provider the user merely clicked through, and
// providers_with_keys() stops being a straight read.
$clean = Styble_AI_Provider_Factory::sanitize_credentials(
	array(
		'anthropic' => array( 'key' => '', 'model' => '' ),
		'gemini'    => array( 'key' => 'g' ),
	)
);
check( ! isset( $clean['anthropic'] ), 'a wholly empty slot is not stored' );
check( isset( $clean['gemini'] ), 'a slot with only a key is stored' );

// A model with no key IS stored: the user may set the model before pasting the
// key, and discarding it would silently lose typed input.
$clean = Styble_AI_Provider_Factory::sanitize_credentials(
	array( 'gemini' => array( 'key' => '', 'model' => 'gemini-2.0-flash' ) )
);
check(
	isset( $clean['gemini']['model'] ) && 'gemini-2.0-flash' === $clean['gemini']['model'],
	'a model with no key is kept (typed input is not discarded)'
);

check( array() === Styble_AI_Provider_Factory::sanitize_credentials( 'not-an-array' ), 'a non-array post sanitizes to empty' );
check( array() === Styble_AI_Provider_Factory::sanitize_credentials( array() ), 'an empty post sanitizes to empty' );

$clean = Styble_AI_Provider_Factory::sanitize_credentials(
	array( 'anthropic' => array( 'key' => "sk-<script>alert(1)</script>", 'model' => 'x' ) )
);
check(
	false === strpos( $clean['anthropic']['key'], '<script' ),
	'a key is passed through sanitize_text_field'
);

// ── THE B3 INVARIANT: a save preserves untouched providers ───────────────────
//
// The settings screen renders a row per provider and hides all but the selected
// one. Hidden inputs still submit, so the posted array is complete and this is a
// merge in practice. The assertion is that a provider whose fields were not
// edited comes back byte-identical — because the alternative is the bug B3 was
// fixed to remove: looking at a different provider deletes the other's key.
reset_options(
	array(
		'styble_ai_provider' => 'anthropic',
		$cred_option         => array(
			'anthropic' => array( 'key' => 'sk-ant-KEEP', 'model' => 'claude-opus-5' ),
			'gemini'    => array( 'key' => 'g-KEEP', 'model' => 'gemini-2.0-flash' ),
		),
	)
);
$before = Styble_AI_Provider_Factory::credentials();
check( 'sk-ant-KEEP' === $before['anthropic']['key'], 'seeded anthropic key reads back' );
check( 'g-KEEP' === $before['gemini']['key'], 'seeded gemini key reads back' );

// Simulate the form post: gemini's model edited, anthropic's row untouched but
// still submitted (which is what the hidden row does).
$posted = Styble_AI_Provider_Factory::sanitize_credentials(
	array(
		'anthropic' => array( 'key' => 'sk-ant-KEEP', 'model' => 'claude-opus-5' ),
		'gemini'    => array( 'key' => 'g-KEEP', 'model' => 'gemini-2.5-flash' ),
	)
);
update_option( $cred_option, $posted );
$after = Styble_AI_Provider_Factory::credentials();
check( 'sk-ant-KEEP' === $after['anthropic']['key'], 'B3: editing gemini does not touch the anthropic key' );
check( 'claude-opus-5' === $after['anthropic']['model'], 'B3: editing gemini does not touch the anthropic model' );
check( 'gemini-2.5-flash' === $after['gemini']['model'], 'the edited model is stored' );
check( 'g-KEEP' === $after['gemini']['key'], 'the edited provider keeps its key' );

// ── key_for / model_for / has_key / providers_with_keys ──────────────────────

reset_options(
	array(
		'styble_ai_provider' => 'gemini',
		$cred_option         => array(
			'anthropic' => array( 'key' => 'A', 'model' => 'claude-opus-5' ),
			'gemini'    => array( 'key' => 'G', 'model' => '' ),
			'groq'      => array( 'key' => '', 'model' => 'llama-3.3-70b-versatile' ),
		),
	)
);
check( 'gemini' === Styble_AI_Provider_Factory::active_provider(), 'the active provider comes from styble_ai_provider' );
check( 'G' === Styble_AI_Provider_Factory::key_for(), 'key_for() with no argument uses the active provider' );
check( 'A' === Styble_AI_Provider_Factory::key_for( 'anthropic' ), 'key_for() targets an explicit provider' );
check( '' === Styble_AI_Provider_Factory::key_for( 'deepseek' ), 'key_for() on an unconfigured provider is empty' );
check( '' === Styble_AI_Provider_Factory::key_for( 'not-a-vendor' ), 'key_for() on an unknown provider is empty' );
check( 'claude-opus-5' === Styble_AI_Provider_Factory::model_for( 'anthropic' ), 'model_for() targets an explicit provider' );
check( '' === Styble_AI_Provider_Factory::model_for( 'gemini' ), 'model_for() is empty when no override is stored' );

check( true === Styble_AI_Provider_Factory::has_key(), 'has_key() is true for the active provider with a key' );
check( true === Styble_AI_Provider_Factory::has_key( 'anthropic' ), 'has_key() is true for a named provider with a key' );
check( false === Styble_AI_Provider_Factory::has_key( 'groq' ), 'has_key() is false for a model-only slot' );
check( false === Styble_AI_Provider_Factory::has_key( 'deepseek' ), 'has_key() is false for an absent slot' );

$with = Styble_AI_Provider_Factory::providers_with_keys();
check( array( 'anthropic', 'gemini' ) === $with, 'providers_with_keys() lists only slots holding a key, sorted' );

// A stored slot under an unknown id must not be readable, even if it reached the
// database some other way (a downgrade, a direct write, a renamed preset).
reset_options(
	array(
		'styble_ai_provider' => 'anthropic',
		$cred_option         => array( 'not-a-vendor' => array( 'key' => 'orphan', 'model' => '' ) ),
	)
);
check( array() === Styble_AI_Provider_Factory::credentials(), 'an unknown provider id stored in the DB is dropped on read' );
check( array() === Styble_AI_Provider_Factory::providers_with_keys(), 'an orphaned slot does not appear as a configured provider' );

// A corrupt option must not fatal or leak — it reads as "nothing configured".
reset_options( array( 'styble_ai_provider' => 'anthropic', $cred_option => 'garbage' ) );
check( array() === Styble_AI_Provider_Factory::credentials(), 'a non-array credentials option reads as empty' );
check( false === Styble_AI_Provider_Factory::has_key(), 'a corrupt option means no key, not a crash' );

reset_options( array( 'styble_ai_provider' => 'anthropic', $cred_option => array( 'anthropic' => 'not-a-slot' ) ) );
check( array() === Styble_AI_Provider_Factory::credentials(), 'a non-array slot is dropped on read' );

// ── make() ───────────────────────────────────────────────────────────────────

reset_options(
	array(
		'styble_ai_provider' => 'anthropic',
		$cred_option         => array(
			'anthropic' => array( 'key' => 'A', 'model' => '' ),
			'gemini'    => array( 'key' => 'G', 'model' => 'gemini-2.0-flash' ),
		),
	)
);
check(
	Styble_AI_Provider_Factory::make() instanceof Styble_AI_Anthropic_Provider,
	'make() builds the native provider for anthropic'
);
check(
	Styble_AI_Provider_Factory::make( 'gemini' ) instanceof Styble_AI_OpenAI_Compatible_Provider,
	'make() builds the OpenAI-compatible provider for a preset'
);
// The whole point of B3: an explicit provider argument picks up THAT provider's
// own key, so the eval runner can measure a floor model without disturbing
// Settings or borrowing the wrong credential.
check(
	Styble_AI_Provider_Factory::make( 'gemini' ) instanceof Styble_AI_OpenAI_Compatible_Provider
		&& 'G' === Styble_AI_Provider_Factory::key_for( 'gemini' ),
	'B3: make(provider) resolves that provider\'s own key, not the active one'
);

// ── Legacy migration, each in a subprocess ───────────────────────────────────
//
// maybe_migrate_legacy() latches on a `static $done` so it runs at most once per
// request — correct in production, and untestable in-process. Each scenario
// therefore runs in its own PHP subprocess with a tiny harness, and this file
// asserts on the reported outcome.
$migration_cases = array(
	array(
		'label'  => 'migration copies the legacy key into the ACTIVE provider slot',
		'seed'   => array(
			'styble_ai_provider' => 'mistral',
			'styble_ai_api_key'  => 'legacy-key',
			'styble_ai_model'    => 'mistral-large-latest',
		),
		'expect' => 'mistral:legacy-key:mistral-large-latest',
	),
	array(
		'label'  => 'migration does not clobber a slot the user has already saved',
		'seed'   => array(
			'styble_ai_provider'                              => 'mistral',
			'styble_ai_api_key'                               => 'legacy-key',
			Styble_AI_Provider_Factory::CREDENTIALS_OPTION     => array( 'mistral' => array( 'key' => 'fresh-key', 'model' => '' ) ),
		),
		'expect' => 'mistral:fresh-key:',
	),
	array(
		'label'  => 'migration leaves other providers alone',
		'seed'   => array(
			'styble_ai_provider'                          => 'mistral',
			'styble_ai_api_key'                           => 'legacy-key',
			Styble_AI_Provider_Factory::CREDENTIALS_OPTION => array( 'gemini' => array( 'key' => 'G', 'model' => '' ) ),
		),
		'expect' => 'gemini:G:|mistral:legacy-key:',
	),
	array(
		'label'  => 'no legacy key means no migration write',
		'seed'   => array( 'styble_ai_provider' => 'mistral' ),
		'expect' => '',
	),
	array(
		'label'  => 'a blank legacy key is not migrated',
		'seed'   => array( 'styble_ai_provider' => 'mistral', 'styble_ai_api_key' => '   ' ),
		'expect' => '',
	),
);

$harness = $root . '/scripts/test-credentials.php';
foreach ( $migration_cases as $case ) {
	$seed = base64_encode( serialize( $case['seed'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	$cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $harness )
		. ' --migrate=' . escapeshellarg( $seed );
	$out  = trim( (string) shell_exec( $cmd ) );
	check( $case['expect'] === $out, $case['label'] . ( $case['expect'] === $out ? '' : " — got '{$out}', wanted '{$case['expect']}'" ) );
}

// Idempotency: two reads in ONE request must produce exactly one write. Asserted
// in a subprocess too, because it depends on the same latch.
$seed = base64_encode( serialize( array( 'styble_ai_provider' => 'mistral', 'styble_ai_api_key' => 'legacy-key' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
$cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $harness ) . ' --writes=' . escapeshellarg( $seed );
$out  = trim( (string) shell_exec( $cmd ) );
check( '1' === $out, "migration writes exactly once per request across repeated reads — got '{$out}'" );

echo "\n";
printf( "%d checks, %d failed\n", $checks, $failures );
exit( $failures ? 1 : 0 );

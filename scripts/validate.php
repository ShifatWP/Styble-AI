<?php
/**
 * Styble AI — CLI tree validator and fixture test.
 *
 * Checks emit_layout trees against catalog/catalog.json and prints the verdict.
 * Use it to sanity-check a hand-written fixture or a tree an LLM produced.
 *
 * Run with no arguments it becomes the fixture suite, and asserts rather than
 * just reporting. Fixture names are the expectation:
 *
 *   valid.<name>.json      must validate clean
 *   invalid.<code>.json    must be rejected, and must produce <code> specifically
 *
 * Asserting the code matters: a fixture named for one rule can easily be
 * rejected by a different one — a tree written to trip `attr_type` that also has
 * a stale block name fails for the wrong reason and silently stops testing what
 * it was written for.
 *
 * Run:  php scripts/validate.php [file.json ...]
 *       php scripts/validate.php                # the fixture suite
 *
 * Exit: 0 when every expectation held; 1 when a fixture failed, or an input was
 *       missing or not JSON. For explicitly named files an INVALID verdict is a
 *       result, not a failure.
 *
 * @package Styble_AI
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

// Lets the WP-guarded classes load outside WordPress.
define( 'STYBLE_AI_CLI', true );

$root = dirname( __DIR__ );
require_once $root . '/includes/class-catalog.php';
require_once $root . '/includes/class-validation-result.php';
require_once $root . '/includes/class-validator.php';

try {
	$catalog = Styble_AI_Catalog::from_file( $root . '/catalog/catalog.json' );
} catch ( RuntimeException $e ) {
	fwrite( STDERR, 'ERROR: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
$validator = new Styble_AI_Validator( $catalog );

$files    = array_slice( $argv, 1 );
$is_suite = ! $files;

if ( $is_suite ) {
	$files = glob( $root . '/tests/fixtures/*.json' );
	sort( $files );
}
if ( ! $files ) {
	fwrite( STDERR, "No input files. Pass a path or add fixtures to tests/fixtures/.\n" );
	exit( 1 );
}

/**
 * What a fixture's filename claims should happen.
 *
 * @param string $label Basename, e.g. invalid.attr_type.json.
 *
 * @return array{expect:string, code:string|null} expect is valid|invalid|any.
 */
function expectation_from_name( $label ) {
	if ( 0 === strpos( $label, 'valid.' ) ) {
		return array(
			'expect' => 'valid',
			'code'   => null,
		);
	}
	if ( 0 === strpos( $label, 'invalid.' ) ) {
		$code = substr( $label, strlen( 'invalid.' ) );
		$code = preg_replace( '/\.json$/', '', $code );
		// invalid.cap_columns.2.json -> cap_columns, so a rule can have several cases.
		$code = preg_replace( '/\.\d+$/', '', $code );
		return array(
			'expect' => 'invalid',
			'code'   => $code,
		);
	}
	return array(
		'expect' => 'any',
		'code'   => null,
	);
}

$failed     = 0;
$unreadable = 0;
$invalid    = 0;

foreach ( $files as $file ) {
	$label  = basename( $file );
	$expect = expectation_from_name( $label );

	if ( ! is_readable( $file ) ) {
		fwrite( STDERR, sprintf( "%-42s UNREADABLE\n", $label ) );
		$unreadable++;
		continue;
	}

	$tree = json_decode( file_get_contents( $file ), true );
	if ( null === $tree && JSON_ERROR_NONE !== json_last_error() ) {
		fwrite( STDERR, sprintf( "%-42s BAD JSON — %s\n", $label, json_last_error_msg() ) );
		$unreadable++;
		continue;
	}

	$result = $validator->validate( $tree );
	$codes  = $result->codes();

	if ( ! $result->is_valid() ) {
		$invalid++;
	}

	// Explicitly named file: report, do not judge.
	if ( ! $is_suite ) {
		if ( $result->is_valid() ) {
			fwrite( STDOUT, sprintf( "%-42s VALID\n", $label ) );
			continue;
		}
		fwrite( STDOUT, sprintf( "%-42s INVALID (%d)\n", $label, count( $result->errors() ) ) );
		foreach ( $result->errors() as $error ) {
			fwrite( STDOUT, sprintf( "    [%s] %s\n        %s\n", $error['code'], $error['path'], $error['message'] ) );
		}
		if ( $result->is_truncated() ) {
			fwrite( STDOUT, "    … more errors suppressed.\n" );
		}
		continue;
	}

	// Suite mode: the filename is the assertion.
	if ( 'valid' === $expect['expect'] ) {
		if ( $result->is_valid() ) {
			fwrite( STDOUT, sprintf( "  ok    %-36s valid\n", $label ) );
			continue;
		}
		$failed++;
		fwrite( STDOUT, sprintf( "  FAIL  %-36s expected valid, got %d error(s)\n", $label, count( $result->errors() ) ) );
		foreach ( $result->errors() as $error ) {
			fwrite( STDOUT, sprintf( "          [%s] %s — %s\n", $error['code'], $error['path'], $error['message'] ) );
		}
		continue;
	}

	if ( 'invalid' === $expect['expect'] ) {
		if ( $result->is_valid() ) {
			$failed++;
			fwrite( STDOUT, sprintf( "  FAIL  %-36s expected %s, but the tree validated\n", $label, $expect['code'] ) );
			continue;
		}
		if ( ! $result->has_code( $expect['code'] ) ) {
			$failed++;
			fwrite(
				STDOUT,
				sprintf(
					"  FAIL  %-36s expected %s, got %s\n",
					$label,
					$expect['code'],
					implode( ', ', $codes )
				)
			);
			foreach ( $result->errors() as $error ) {
				fwrite( STDOUT, sprintf( "          [%s] %s — %s\n", $error['code'], $error['path'], $error['message'] ) );
			}
			continue;
		}
		$extra = array_values( array_diff( $codes, array( $expect['code'] ) ) );
		fwrite(
			STDOUT,
			sprintf(
				"  ok    %-36s %s%s\n",
				$label,
				$expect['code'],
				$extra ? ' (also: ' . implode( ', ', $extra ) . ')' : ''
			)
		);
		continue;
	}

	fwrite( STDOUT, sprintf( "  skip  %-36s no expectation in the name\n", $label ) );
}

if ( ! $is_suite ) {
	fwrite(
		STDOUT,
		sprintf( "\n%d file(s): %d valid, %d invalid, %d unreadable\n", count( $files ), count( $files ) - $invalid - $unreadable, $invalid, $unreadable )
	);
	exit( $unreadable > 0 ? 1 : 0 );
}

fwrite(
	STDOUT,
	sprintf(
		"\n%d fixture(s): %d passed, %d failed, %d unreadable\n",
		count( $files ),
		count( $files ) - $failed - $unreadable,
		$failed,
		$unreadable
	)
);

// Every error code the contract documents should have a fixture behind it.
$covered = array();
foreach ( $files as $file ) {
	$expect = expectation_from_name( basename( $file ) );
	if ( 'invalid' === $expect['expect'] ) {
		$covered[ $expect['code'] ] = true;
	}
}
$documented = array(
	'envelope_not_object',
	'envelope_missing_key',
	'envelope_unknown_key',
	'version_mismatch',
	'node_not_object',
	'node_unknown_key',
	'block_missing',
	'block_not_string',
	'block_unknown',
	'block_not_allowlisted',
	'root_requires_parent',
	'parent_not_allowed',
	'child_not_allowed',
	'block_is_leaf',
	'children_not_array',
	'attrs_not_object',
	'attr_unknown',
	'attr_type',
	'attr_shape',
	'image_not_placeholder',
	'layout_unknown',
	'layout_columns_mismatch',
	'layout_children_mismatch',
	'layout_selected_conflict',
	'cap_containers',
	'cap_columns',
	'cap_column_blocks',
	'cap_depth',
	'cap_nodes',
);
$uncovered = array_values( array_diff( $documented, array_keys( $covered ) ) );
if ( $uncovered ) {
	fwrite(
		STDOUT,
		sprintf(
			"coverage: %d/%d error codes have a fixture; missing: %s\n",
			count( $documented ) - count( $uncovered ),
			count( $documented ),
			implode( ', ', $uncovered )
		)
	);
} else {
	fwrite( STDOUT, sprintf( "coverage: all %d error codes have a fixture\n", count( $documented ) ) );
}

exit( ( $failed > 0 || $unreadable > 0 ) ? 1 : 0 );

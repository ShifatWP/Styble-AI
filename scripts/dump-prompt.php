<?php
/**
 * Styble AI — print the generated system prompt and tool schema.
 *
 * Both are derived from catalog/catalog.json, so this is how you see exactly what
 * the model will be told without spending an API call. Brand context is absent
 * here: it comes from Styble Pro's global settings, which only exist inside
 * WordPress.
 *
 * Run:  php scripts/dump-prompt.php [--prompt|--schema] [path-to-catalog.json]
 *
 * @package Styble_AI
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

define( 'STYBLE_AI_CLI', true );

require_once __DIR__ . '/../includes/class-catalog.php';
require_once __DIR__ . '/../includes/class-brand-context.php';
require_once __DIR__ . '/../includes/class-prompt.php';

$args = array_slice( $argv, 1 );
$want = 'both';
$path = null;

foreach ( $args as $arg ) {
	if ( '--prompt' === $arg ) {
		$want = 'prompt';
	} elseif ( '--schema' === $arg ) {
		$want = 'schema';
	} elseif ( '--help' === $arg || '-h' === $arg ) {
		fwrite( STDOUT, "Usage: php scripts/dump-prompt.php [--prompt|--schema] [path-to-catalog.json]\n" );
		exit( 0 );
	} else {
		$path = $arg;
	}
}

try {
	$catalog = Styble_AI_Catalog::from_file( $path );
} catch ( RuntimeException $e ) {
	fwrite( STDERR, 'ERROR: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

$prompt = new Styble_AI_Prompt( $catalog );

if ( 'schema' !== $want ) {
	fwrite( STDOUT, "===== SYSTEM PROMPT =====\n\n" );
	fwrite( STDOUT, $prompt->system_prompt() . "\n" );
}

if ( 'prompt' !== $want ) {
	fwrite( STDOUT, "\n===== TOOL: " . $prompt->tool_name() . " =====\n\n" );
	fwrite( STDOUT, $prompt->tool_description() . "\n\n" );
	fwrite(
		STDOUT,
		json_encode( $prompt->tool_schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
	);
}

if ( 'both' === $want ) {
	$system = $prompt->system_prompt();
	$schema = json_encode( $prompt->tool_schema() );
	fwrite(
		STDERR,
		sprintf(
			"\n[contract %s] prompt %d chars, schema %d chars, %d allowlisted blocks, %d layouts, node depth %d\n",
			$catalog->contract_version(),
			strlen( $system ),
			strlen( $schema ),
			count( $catalog->allowlisted_names() ),
			count( $catalog->layouts() ),
			Styble_AI_Prompt::SCHEMA_DEPTH
		)
	);
}

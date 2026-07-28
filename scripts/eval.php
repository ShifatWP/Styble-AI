<?php
/**
 * Styble AI — the measuring stick.
 *
 * Every other check in this repo asks whether the code did what the code says.
 * This one asks whether the MODEL did what the user wanted, which is the only
 * question that has ever caught a real defect here — the zero-padding bug and
 * the invented-enum bug were both found by looking at a page, because nothing
 * we owned could fail on them.
 *
 * Design notes, all of which are load-bearing:
 *
 *  - **The retry is off by default.** With one corrective retry on, a model that
 *    never gets it right first time scores the same as one that always does.
 *    First-try validity is the number we are trying to move; pass --retries=1 to
 *    measure the shipped behaviour instead.
 *
 *  - **Every model response is cached** on disk, keyed by the exact bytes sent.
 *    Scorers change far more often than prompts do, and re-scoring must cost
 *    nothing or it will not be done. A cached run is also byte-reproducible,
 *    which is the difference between a measurement and an anecdote.
 *
 *  - **Rate limits are handled, not reported.** Free tiers are the floor model's
 *    whole point, and Groq's 12k TPM cannot fit two of our requests in a minute.
 *    The runner reads the delay the provider asks for and waits it out.
 *
 * Runs under WP-CLI because it needs the real provider classes, the real
 * settings and real HTTP — unlike the other scripts here, which are WP-free
 * because they can be.
 *
 * WP-CLI rejects unknown `--flags`, so arguments are positional:
 *
 *   wp eval-file scripts/eval.php suite=section              # cache only, free
 *   wp eval-file scripts/eval.php suite=section live=1       # calls the provider
 *   wp eval-file scripts/eval.php suite=section live=1 provider=anthropic model=claude-opus-5
 *   wp eval-file scripts/eval.php compare=section-a with=section-b
 *
 * `live=1` is required to touch the network at all. Everything else defaults to
 * reading the cache, so a typo cannot spend money.
 *
 * @package Styble_AI
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI: wp eval-file scripts/eval.php -- --suite=section\n" );
	return;
}

// ---------------------------------------------------------------- arguments

$opts = styble_ai_eval_args( isset( $args ) && is_array( $args ) ? $args : array() );

if ( '' !== $opts['compare'] ) {
	styble_ai_eval_compare( $opts['compare'], $opts['with'] );
	return;
}

$suite_file = STYBLE_AI_DIR . 'evals/' . $opts['suite'] . '/cases.json';
if ( ! is_readable( $suite_file ) ) {
	WP_CLI::error( "No such suite: {$suite_file}" );
}
$suite = json_decode( file_get_contents( $suite_file ), true );
if ( ! is_array( $suite ) || empty( $suite['cases'] ) ) {
	WP_CLI::error( "Suite is not valid JSON or has no cases: {$suite_file}" );
}

$catalog  = Styble_AI_Catalog::from_file();
$provider = styble_ai_eval_provider( $opts );
$model    = $opts['model'] ? $opts['model'] : styble_ai_eval_default_model( $opts['provider'] );

WP_CLI::log( sprintf(
	'suite=%s  provider=%s  model=%s  retries=%d  %s',
	$opts['suite'],
	$opts['provider'],
	$model,
	$opts['retries'],
	$opts['cached'] ? 'CACHED ONLY (no network)' : 'live'
) );
WP_CLI::log( str_repeat( '-', 78 ) );

// ---------------------------------------------------------------- run

$results = array();
$n_cases = count( $suite['cases'] );

foreach ( $suite['cases'] as $i => $case ) {
	$outcome = styble_ai_eval_run_case( $case, $catalog, $provider, $model, $opts );
	$results[] = $outcome;

	WP_CLI::log( sprintf(
		'  %-20s %s%s  %s',
		$case['id'],
		$outcome['pass'] ? 'PASS' : 'FAIL',
		$outcome['cached'] ? ' (cached)' : '',
		$outcome['pass'] ? '' : implode( '; ', array_slice( $outcome['failures'], 0, 3 ) )
	) );

	// Pace live calls. A free tier that allows one request per minute is still a
	// usable eval target if the runner is willing to wait.
	if ( ! $outcome['cached'] && $i < $n_cases - 1 && $opts['delay'] > 0 ) {
		sleep( $opts['delay'] );
	}
}

// ---------------------------------------------------------------- report

$record = styble_ai_eval_summarise( $suite, $results, $model, $opts );
$path   = styble_ai_eval_write_run( $record, $opts );

WP_CLI::log( str_repeat( '-', 78 ) );
WP_CLI::log( sprintf(
	'%d/%d passed  (%.0f%%)',
	$record['summary']['passed'],
	$record['summary']['n'],
	100 * $record['summary']['rate']
) );
foreach ( $record['summary']['checks'] as $check => $stat ) {
	WP_CLI::log( sprintf( '  %-18s %d/%d', $check, $stat['passed'], $stat['n'] ) );
}
if ( $record['summary']['errorCodes'] ) {
	WP_CLI::log( '  validator codes seen: ' . implode( ', ', array_keys( $record['summary']['errorCodes'] ) ) );
}
WP_CLI::log( 'run: ' . str_replace( STYBLE_AI_DIR, '', $path ) );

/* ==================================================================== */
/* Runner                                                               */
/* ==================================================================== */

/**
 * Generate one case and score it.
 *
 * @param array             $case     Case definition.
 * @param Styble_AI_Catalog $catalog  Block catalog.
 * @param object            $provider Provider.
 * @param string            $model    Model id, for the cache key.
 * @param array             $opts     Options.
 *
 * @return array
 */
function styble_ai_eval_run_case( array $case, $catalog, $provider, $model, array $opts ) {
	$cache_key = styble_ai_eval_cache_key( $model, $opts['retries'], $case['prompt'], $catalog );
	$cached    = styble_ai_eval_cache_read( $cache_key );

	if ( null !== $cached ) {
		$raw = $cached;
	} elseif ( $opts['cached'] ) {
		return array(
			'id'         => $case['id'],
			'pass'       => false,
			'cached'     => true,
			'failures'   => array( 'no cached response — run live first' ),
			'checks'     => array(),
			'errorCodes' => array(),
			'tree'       => null,
		);
	} else {
		$generator = new Styble_AI_Generator( $catalog, $provider, $opts['retries'] );
		$result    = $generator->generate( $case['prompt'] );

		$raw = is_wp_error( $result )
			? array(
				'error'    => $result->get_error_message(),
				'code'     => $result->get_error_code(),
				'errors'   => (array) ( is_array( $result->get_error_data() ) && isset( $result->get_error_data()['errors'] ) ? $result->get_error_data()['errors'] : array() ),
				'tree'     => null,
			)
			: array(
				'error'  => null,
				'code'   => '',
				'errors' => array(),
				'tree'   => $result['tree'],
			);

		styble_ai_eval_cache_write( $cache_key, $raw );
	}

	$scored           = styble_ai_eval_score( $case, $raw, $catalog );
	$scored['id']     = $case['id'];
	$scored['cached'] = ( null !== $cached );

	return $scored;
}

/**
 * Build the configured provider, wrapped so rate limits are waited out rather
 * than reported as a failure.
 *
 * @param array $opts Options.
 *
 * @return object
 */
function styble_ai_eval_provider( array $opts ) {
	$key = get_option( 'styble_ai_api_key', '' );

	if ( 'anthropic' === $opts['provider'] ) {
		$inner = new Styble_AI_Anthropic_Provider( $key, $opts['model'] ? $opts['model'] : 'claude-opus-5' );
	} else {
		$presets  = Styble_AI_OpenAI_Compatible_Provider::presets();
		$endpoint = isset( $presets[ $opts['provider'] ] ) ? $presets[ $opts['provider'] ]['endpoint'] : get_option( 'styble_ai_base_url', '' );
		$model    = $opts['model'] ? $opts['model'] : styble_ai_eval_default_model( $opts['provider'] );
		$inner    = new Styble_AI_OpenAI_Compatible_Provider( $key, $model, $endpoint );
	}

	return new Styble_AI_Eval_Patient_Provider( $inner );
}

/**
 * Retries through rate limits, using the delay the provider asks for.
 *
 * Not in the plugin proper: production should surface a 429 to the user quickly,
 * where an eval run should simply take longer. Handling it here keeps free-tier
 * models usable as the floor without changing shipped behaviour.
 */
class Styble_AI_Eval_Patient_Provider {

	/** @var object */
	private $inner;

	/** @var int */
	private $max_waits = 4;

	/**
	 * @param object $inner Real provider.
	 */
	public function __construct( $inner ) {
		$this->inner = $inner;
	}

	/**
	 * @param array $spec Provider spec.
	 *
	 * @return array|WP_Error
	 */
	public function complete( array $spec ) {
		for ( $attempt = 0; $attempt <= $this->max_waits; $attempt++ ) {
			$result = $this->inner->complete( $spec );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$wait = self::retry_delay( $result->get_error_message() );
			if ( null === $wait || $attempt === $this->max_waits ) {
				return $result;
			}

			WP_CLI::log( sprintf( '      rate limited — waiting %ds', $wait ) );
			sleep( $wait );
		}

		return $result;
	}

	/**
	 * Pull a wait out of a rate-limit message. Providers word these differently
	 * but all of them say the number.
	 *
	 * @param string $message Error message.
	 *
	 * @return int|null Seconds to wait, or null when this is not a rate limit.
	 */
	private static function retry_delay( $message ) {
		if ( ! preg_match( '/rate limit|429|too many requests/i', $message ) ) {
			return null;
		}
		// "Please try again in 39.655s" / "try again in 1m2.5s" / "retry after 30"
		if ( preg_match( '/try again in (?:(\d+)m)?([\d.]+)s/i', $message, $m ) ) {
			return (int) ceil( ( (int) $m[1] ) * 60 + (float) $m[2] ) + 1;
		}
		if ( preg_match( '/retry[- ]after[:\s]+(\d+)/i', $message, $m ) ) {
			return (int) $m[1] + 1;
		}
		return 30;
	}
}

/* ==================================================================== */
/* Scoring — deterministic, so a cached re-run is byte-identical         */
/* ==================================================================== */

/**
 * @param array             $case    Case definition.
 * @param array             $raw     Cached/live provider outcome.
 * @param Styble_AI_Catalog $catalog Block catalog.
 *
 * @return array
 */
function styble_ai_eval_score( array $case, array $raw, $catalog ) {
	$expect   = isset( $case['expect'] ) ? $case['expect'] : array();
	$checks   = array();
	$failures = array();
	$tree     = isset( $raw['tree'] ) ? $raw['tree'] : null;

	// 1. Did it produce a valid tree at all?
	$valid = is_array( $tree );
	if ( ! empty( $expect['valid'] ) ) {
		$checks['valid'] = $valid;
		if ( ! $valid ) {
			$codes      = array();
			foreach ( $raw['errors'] as $e ) {
				$codes[] = isset( $e['code'] ) ? $e['code'] : '?';
			}
			$failures[] = 'invalid: ' . ( $codes ? implode( ',', array_unique( $codes ) ) : $raw['code'] );
		}
	}

	if ( ! $valid ) {
		return array(
			'pass'       => false,
			'checks'     => $checks,
			'failures'   => $failures,
			'errorCodes' => styble_ai_eval_codes( $raw ),
			'tree'       => null,
		);
	}

	$flat = styble_ai_eval_flatten( $tree['root'] );
	$counts = array();
	foreach ( $flat as $node ) {
		$counts[ $node['block'] ] = ( isset( $counts[ $node['block'] ] ) ? $counts[ $node['block'] ] : 0 ) + 1;
	}

	// 2. Exact block counts.
	if ( ! empty( $expect['counts'] ) ) {
		$ok = true;
		foreach ( $expect['counts'] as $block => $want ) {
			$got = isset( $counts[ $block ] ) ? $counts[ $block ] : 0;
			if ( $got !== (int) $want ) {
				$ok         = false;
				$failures[] = sprintf( '%s: want %d got %d', $block, $want, $got );
			}
		}
		$checks['counts'] = $ok;
	}

	// 3. Presence, absence and bounds.
	foreach ( array( 'mustContain' => true, 'mustNotContain' => false ) as $key => $want_present ) {
		if ( empty( $expect[ $key ] ) ) {
			continue;
		}
		$ok = true;
		foreach ( $expect[ $key ] as $block ) {
			$present = isset( $counts[ $block ] );
			if ( $present !== $want_present ) {
				$ok         = false;
				$failures[] = $want_present ? "missing {$block}" : "should not use {$block}";
			}
		}
		$checks[ $key ] = $ok;
	}

	foreach ( array( 'minOf' => 'min', 'maxOf' => 'max' ) as $key => $kind ) {
		if ( empty( $expect[ $key ] ) ) {
			continue;
		}
		$ok = true;
		foreach ( $expect[ $key ] as $block => $bound ) {
			$got  = isset( $counts[ $block ] ) ? $counts[ $block ] : 0;
			$bad  = ( 'min' === $kind ) ? ( $got < (int) $bound ) : ( $got > (int) $bound );
			if ( $bad ) {
				$ok         = false;
				$failures[] = sprintf( '%s: %s %d, got %d', $block, $kind, $bound, $got );
			}
		}
		$checks[ $key ] = $ok;
	}

	// 4. Layout id, where the brief implies a specific split.
	if ( ! empty( $expect['layoutIn'] ) ) {
		$layout            = isset( $tree['root']['attrs']['layout'] ) ? $tree['root']['attrs']['layout'] : '';
		$checks['layout']  = in_array( $layout, $expect['layoutIn'], true );
		if ( ! $checks['layout'] ) {
			$failures[] = sprintf( 'layout: want one of %s, got "%s"', implode( '|', $expect['layoutIn'] ), $layout );
		}
	}

	// 5. Heading tags actually used, so "a headline" does not come back as a <p>.
	if ( ! empty( $expect['headingTags'] ) ) {
		$tags = array();
		foreach ( $flat as $node ) {
			if ( 'styble/advanced-text' === $node['block'] && ! empty( $node['attrs']['textHTMLTag'] ) ) {
				$tags[] = $node['attrs']['textHTMLTag'];
			}
		}
		$checks['headingTags'] = (bool) array_intersect( $tags, $expect['headingTags'] );
		if ( ! $checks['headingTags'] ) {
			$failures[] = 'no heading in ' . implode( '|', $expect['headingTags'] ) . ' (saw ' . ( $tags ? implode( ',', array_unique( $tags ) ) : 'none' ) . ')';
		}
	}

	// 6. Specifics from the brief that must survive into the copy.
	$copy = styble_ai_eval_copy( $flat );
	if ( ! empty( $expect['copyMustMention'] ) ) {
		$ok = true;
		foreach ( $expect['copyMustMention'] as $needle ) {
			if ( false === stripos( $copy, (string) $needle ) ) {
				$ok         = false;
				$failures[] = "copy never mentions \"{$needle}\"";
			}
		}
		$checks['copyMustMention'] = $ok;
	}

	// 7. An exact attribute value, for the traps the prompt calls out.
	if ( ! empty( $expect['attrEquals'] ) ) {
		$ok = true;
		foreach ( $expect['attrEquals'] as $rule ) {
			$found = false;
			foreach ( $flat as $node ) {
				if ( $node['block'] !== $rule['block'] ) {
					continue;
				}
				if ( array_key_exists( $rule['attr'], $node['attrs'] ) && $node['attrs'][ $rule['attr'] ] === $rule['value'] ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$ok         = false;
				$failures[] = sprintf( '%s.%s != %s', $rule['block'], $rule['attr'], wp_json_encode( $rule['value'] ) );
			}
		}
		$checks['attrEquals'] = $ok;
	}

	// 8. Universal checks, applied to every case whether it asks or not.
	$checks['noPlaceholder'] = ! preg_match( '/lorem ipsum|your text here|placeholder text|dolor sit amet/i', $copy );
	if ( ! $checks['noPlaceholder'] ) {
		$failures[] = 'placeholder copy';
	}

	$checks['padding'] = isset( $tree['root']['attrs']['sectionPadding'] );
	if ( ! $checks['padding'] ) {
		// Not a hard failure — the applier backstops it — but we want the number,
		// because it says whether the prompt instruction is landing.
		$failures[] = 'no sectionPadding (applier will backstop)';
	}

	// preferContain is advisory: recorded, never fails the case. This is where
	// block-choice quality shows up before Stage 1 exists to measure it properly.
	if ( ! empty( $expect['preferContain'] ) ) {
		$hit = 0;
		foreach ( $expect['preferContain'] as $block ) {
			$hit += isset( $counts[ $block ] ) ? 1 : 0;
		}
		$checks['preferContain'] = ( $hit === count( $expect['preferContain'] ) );
	}

	// The case passes on the hard checks only.
	$soft = array( 'padding', 'preferContain' );
	$pass = true;
	foreach ( $checks as $name => $ok ) {
		if ( ! $ok && ! in_array( $name, $soft, true ) ) {
			$pass = false;
		}
	}

	return array(
		'pass'       => $pass,
		'checks'     => $checks,
		'failures'   => $failures,
		'errorCodes' => styble_ai_eval_codes( $raw ),
		'tree'       => $tree,
	);
}

/**
 * @param array $node Tree node.
 *
 * @return array Flat list of [block, attrs].
 */
function styble_ai_eval_flatten( $node ) {
	if ( ! is_array( $node ) || empty( $node['block'] ) ) {
		return array();
	}
	$out = array(
		array(
			'block' => $node['block'],
			'attrs' => isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array(),
		),
	);
	foreach ( ( isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array() ) as $child ) {
		$out = array_merge( $out, styble_ai_eval_flatten( $child ) );
	}
	return $out;
}

/**
 * Every string attribute value, concatenated — the section's visible copy plus
 * a little noise, which is fine for substring checks.
 *
 * @param array $flat Flattened nodes.
 *
 * @return string
 */
function styble_ai_eval_copy( array $flat ) {
	$parts = array();
	foreach ( $flat as $node ) {
		foreach ( $node['attrs'] as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$parts[] = $value;
			}
		}
	}
	return implode( ' ', $parts );
}

/**
 * @param array $raw Provider outcome.
 *
 * @return array Validator error codes seen, as a set.
 */
function styble_ai_eval_codes( array $raw ) {
	$codes = array();
	foreach ( ( isset( $raw['errors'] ) ? $raw['errors'] : array() ) as $e ) {
		if ( isset( $e['code'] ) ) {
			$codes[ $e['code'] ] = true;
		}
	}
	return $codes;
}

/* ==================================================================== */
/* Cache, run records, comparison                                        */
/* ==================================================================== */

/**
 * Key on everything that can change the answer: model, retry budget, the brief,
 * and the contract the model is working against. A catalog regeneration or a
 * prompt edit must miss the cache, or the number is a lie.
 *
 * @param string            $model   Model id.
 * @param int               $retries Retry budget.
 * @param string            $prompt  Case prompt.
 * @param Styble_AI_Catalog $catalog Block catalog.
 *
 * @return string
 */
function styble_ai_eval_cache_key( $model, $retries, $prompt, $catalog ) {
	$p = new Styble_AI_Prompt( $catalog );
	return substr(
		sha1( $model . '|' . $retries . '|' . $prompt . '|' . $p->system_prompt() . '|' . wp_json_encode( $p->tool_schema() ) ),
		0,
		16
	);
}

/**
 * @param string $key Cache key.
 *
 * @return array|null
 */
function styble_ai_eval_cache_read( $key ) {
	$file = STYBLE_AI_DIR . 'evals/cache/' . $key . '.json';
	if ( ! is_readable( $file ) ) {
		return null;
	}
	$data = json_decode( file_get_contents( $file ), true );
	return is_array( $data ) ? $data : null;
}

/**
 * @param string $key  Cache key.
 * @param array  $data Provider outcome.
 *
 * @return void
 */
function styble_ai_eval_cache_write( $key, array $data ) {
	$dir = STYBLE_AI_DIR . 'evals/cache';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents( $dir . '/' . $key . '.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/**
 * @param array  $suite   Suite definition.
 * @param array  $results Per-case outcomes.
 * @param string $model   Model id.
 * @param array  $opts    Options.
 *
 * @return array
 */
function styble_ai_eval_summarise( array $suite, array $results, $model, array $opts ) {
	$passed = 0;
	$checks = array();
	$codes  = array();
	$cases  = array();

	foreach ( $results as $r ) {
		$passed += $r['pass'] ? 1 : 0;

		foreach ( $r['checks'] as $name => $ok ) {
			if ( ! isset( $checks[ $name ] ) ) {
				$checks[ $name ] = array( 'passed' => 0, 'n' => 0 );
			}
			$checks[ $name ]['n']++;
			$checks[ $name ]['passed'] += $ok ? 1 : 0;
		}
		foreach ( array_keys( $r['errorCodes'] ) as $code ) {
			$codes[ $code ] = ( isset( $codes[ $code ] ) ? $codes[ $code ] : 0 ) + 1;
		}

		$cases[] = array(
			'id'       => $r['id'],
			'pass'     => $r['pass'],
			'checks'   => $r['checks'],
			'failures' => $r['failures'],
		);
	}

	ksort( $checks );
	ksort( $codes );

	return array(
		'suite'    => $suite['suite'],
		'provider' => $opts['provider'],
		'model'    => $model,
		'retries'  => $opts['retries'],
		'cases'    => $cases,
		'summary'  => array(
			'n'          => count( $results ),
			'passed'     => $passed,
			'rate'       => count( $results ) ? round( $passed / count( $results ), 4 ) : 0,
			'checks'     => $checks,
			'errorCodes' => $codes,
		),
	);
}

/**
 * Run records carry no timestamp inside them: two runs of the same cases on the
 * same model must be byte-identical, and a clock would break that.
 *
 * @param array $record Run record.
 * @param array $opts   Options.
 *
 * @return string Path written.
 */
function styble_ai_eval_write_run( array $record, array $opts ) {
	$dir = STYBLE_AI_DIR . 'evals/runs';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	$name = $opts['name'] ? $opts['name'] : sprintf( '%s-%s', $record['suite'], preg_replace( '/[^a-z0-9.-]/i', '-', $record['model'] ) );
	$path = $dir . '/' . $name . '.json';
	file_put_contents( $path, wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	return $path;
}

/**
 * Per-case regressions between two run records.
 *
 * @param string $a First run name.
 * @param string $b Second run name.
 *
 * @return void
 */
function styble_ai_eval_compare( $a, $b ) {
	$ra = styble_ai_eval_read_run( $a );
	$rb = styble_ai_eval_read_run( $b );

	$by_id = array();
	foreach ( $ra['cases'] as $c ) {
		$by_id[ $c['id'] ]['a'] = $c;
	}
	foreach ( $rb['cases'] as $c ) {
		$by_id[ $c['id'] ]['b'] = $c;
	}

	WP_CLI::log( sprintf( '%s (%.0f%%)  ->  %s (%.0f%%)', $a, 100 * $ra['summary']['rate'], $b, 100 * $rb['summary']['rate'] ) );
	WP_CLI::log( str_repeat( '-', 78 ) );

	$fixed = 0;
	$broke = 0;
	foreach ( $by_id as $id => $pair ) {
		$pa = isset( $pair['a'] ) ? $pair['a']['pass'] : null;
		$pb = isset( $pair['b'] ) ? $pair['b']['pass'] : null;
		if ( $pa === $pb ) {
			continue;
		}
		if ( true === $pb ) {
			$fixed++;
			WP_CLI::log( "  FIXED    {$id}" );
		} else {
			$broke++;
			WP_CLI::log( "  REGRESS  {$id}  " . implode( '; ', array_slice( $pair['b']['failures'], 0, 2 ) ) );
		}
	}

	WP_CLI::log( str_repeat( '-', 78 ) );
	WP_CLI::log( sprintf( '%d fixed, %d regressed', $fixed, $broke ) );
}

/**
 * @param string $name Run name or path.
 *
 * @return array
 */
function styble_ai_eval_read_run( $name ) {
	$path = ( 0 === strpos( $name, '/' ) ) ? $name : STYBLE_AI_DIR . 'evals/runs/' . $name . '.json';
	if ( ! is_readable( $path ) ) {
		WP_CLI::error( "No such run: {$path}" );
	}
	return json_decode( file_get_contents( $path ), true );
}

/* ==================================================================== */
/* Options                                                              */
/* ==================================================================== */

/**
 * @param array $argv Raw args after `--`.
 *
 * @return array
 */
function styble_ai_eval_args( array $argv ) {
	$opts = array(
		'suite'    => 'section',
		'provider' => get_option( 'styble_ai_provider', 'anthropic' ),
		'model'    => '',
		'retries'  => 0,
		'delay'    => 2,
		// Network access is opt-in. A run that only reads the cache is free and
		// harmless; a run that calls a provider spends real money, so it may not
		// be something a mistyped flag can switch on by accident.
		'live'     => 0,
		'name'     => '',
		'compare'  => '',
		'with'     => '',
	);

	// WP-CLI eval-file rejects unknown `--flags` before the script ever runs, so
	// arguments arrive positionally as `suite=section`. Both spellings are
	// accepted because the `--` form is what everyone types first.
	foreach ( $argv as $arg ) {
		$arg = preg_replace( '/^--/', '', (string) $arg );
		if ( ! preg_match( '/^([a-z]+)(?:=(.*))?$/', $arg, $m ) || ! array_key_exists( $m[1], $opts ) ) {
			continue;
		}
		$value           = isset( $m[2] ) ? $m[2] : '1';
		$opts[ $m[1] ]   = is_numeric( $value ) ? (int) $value : $value;
	}

	$opts['cached'] = ! $opts['live'];

	return $opts;
}

/**
 * @param string $provider Provider id.
 *
 * @return string
 */
function styble_ai_eval_default_model( $provider ) {
	if ( 'anthropic' === $provider ) {
		return 'claude-opus-5';
	}
	$presets = Styble_AI_OpenAI_Compatible_Provider::presets();
	return isset( $presets[ $provider ]['model'] ) ? $presets[ $provider ]['model'] : '';
}

<?php
/**
 * Base class for every tool the model can call.
 *
 * Ported from ZIP AI's `Abstract_Ability` (see docs/SPECTRA_AI_IMPLEMENTATION.md
 * §5a-b), minus the parts that belong to a site-management product. The shape is
 * worth taking for one reason: every cross-cutting concern — validation, rate
 * limiting, dry-run, metrics, logging — lives in `handle()`, so no tool author can
 * forget one. A subclass writes `configure()`, `input_schema()` and `run()`, and
 * gets the rest whether it wants it or not.
 *
 * Two deliberate divergences from theirs:
 *
 *  - **`tool_type()` is fail-safe.** Their docblock records the bug: the default was
 *    READ, so a mutating ability that forgot to override it was classified read-only
 *    and skipped the approval gate entirely. A destructive tool that says nothing is
 *    an ACTION here.
 *  - **A per-turn token budget.** They meter credits centrally and do not need one;
 *    we spend the user's own key, so an unbounded loop is a real cost. The budget is
 *    checked before the tool runs, not after.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

abstract class Styble_AI_Tool {

	/**
	 * Tool type vocabulary. Drives the approval gate: WRITE, DELETE and ACTION
	 * require confirmation or a dry run, READ and friends do not.
	 */
	const READ   = 'read';
	const WRITE  = 'write';
	const LIST   = 'list';
	const SEARCH = 'search';
	const ACTION = 'action';
	const DELETE = 'delete';

	/**
	 * Canonical verbs a tool id may use. ZIP AI enforces the same thing with a
	 * regex and a WP_DEBUG `trigger_error`, and the payoff is that a model can
	 * GUESS a tool name correctly — `page/update-block` is derivable from
	 * "update the block" without reading the catalog.
	 *
	 * Trimmed to what a page builder needs; theirs has 24 for a site manager.
	 *
	 * @var array<int,string>
	 */
	const VERBS = array(
		'get',
		'list',
		'search',
		'insert',
		'update',
		'move',
		'delete',
		'duplicate',
		'replace',
		'fill',
		'apply',
		'generate',
	);

	/**
	 * Requests per minute, per user, per tool.
	 *
	 * @var int
	 */
	const RATE_LIMIT = 100;

	/**
	 * Tool id, `namespace/verb-resource`. e.g. `page/update-block`.
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Short human label.
	 *
	 * @var string
	 */
	protected $label = '';

	/**
	 * What the model reads to decide whether to call this. The single most
	 * load-bearing string on the class.
	 *
	 * @var string
	 */
	protected $description = '';

	/**
	 * Capability required. Page tools edit pages.
	 *
	 * @var string
	 */
	protected $capability = 'edit_pages';

	/**
	 * Does this change state? Drives the auto-injected `dry_run`, the fail-safe
	 * tool type, and the approval gate.
	 *
	 * @var bool
	 */
	protected $is_destructive = false;

	/**
	 * Sub-actions that are safe reads on an otherwise-destructive tool.
	 *
	 * Ours are single-purpose so this stays empty, but the field is kept because
	 * `pattern/fill-slots` will want a preview mode and ZIP AI's reason for having
	 * it is sound: a tool routing `create|list|get` through one enum is destructive
	 * overall and read-only for `list`, and without an allowlist the gate blocks
	 * the read.
	 *
	 * @var array<int,string>
	 */
	protected $read_only_actions = array();

	/**
	 * Resource this tool acts on: `page`, `block`, `pattern`, `media`.
	 *
	 * Tools sharing a resource are read/write pairs. E.2 uses this to run a dry
	 * pass automatically when a destructive call arrives with no prior read of the
	 * same resource this turn — the read-first-write pairing ZIP AI declared and
	 * never wired up.
	 *
	 * @var string
	 */
	protected $resource = '';

	/**
	 * Bumped when behaviour or schema changes.
	 *
	 * @var string
	 */
	protected $version = '1.0.0';

	/**
	 * Set the id, label, description and flags.
	 *
	 * @return void
	 */
	abstract protected function configure();

	/**
	 * JSON Schema for this tool's arguments.
	 *
	 * Constraints inherited from the emit_layout contract and its decision log:
	 * no `$defs` / `$ref` (one of nine OpenAI-compatible endpoints failing to
	 * resolve a `$ref` breaks generation outright), and keep it shallow — schema
	 * text costs ~700 chars per attribute per depth level against ~29 in the
	 * prompt.
	 *
	 * @return array
	 */
	abstract public function input_schema();

	/**
	 * Do the work. Called with arguments already validated against the schema.
	 *
	 * @param array $args Validated arguments.
	 *
	 * @return array Result array; `ok` false plus `error` on failure.
	 */
	abstract protected function run( array $args );

	public function __construct() {
		$this->configure();
	}

	/**
	 * Declared response contract, or [] for none.
	 *
	 * Worth overriding: forwarded as `outputSchema` over MCP in Phase F, and the
	 * model learns the reply shape from a schema rather than from prose.
	 *
	 * @return array
	 */
	public function output_schema() {
		return array();
	}

	/**
	 * The schema actually shown to the model, with `dry_run` injected for
	 * destructive tools so every one of them has a free preview.
	 *
	 * @return array
	 */
	public function final_input_schema() {
		$schema = $this->input_schema();

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			$schema['properties'] = array();
		}

		if ( $this->is_destructive && ! isset( $schema['properties']['dry_run'] ) ) {
			$schema['properties']['dry_run'] = array(
				'type'        => 'boolean',
				'description' => 'Preview only: report what would change without changing it.',
				'default'     => false,
			);
		}

		return $schema;
	}

	/**
	 * Fail-SAFE classification.
	 *
	 * A destructive tool that does not override this is a mutating ACTION, never a
	 * READ. ZIP AI's own comment records why: their default was READ, so a new
	 * mutating ability whose author forgot to override it was treated as read-only
	 * and bypassed the approval gate by omission.
	 *
	 * @return string
	 */
	public function tool_type() {
		return $this->is_destructive ? self::ACTION : self::READ;
	}

	/**
	 * Does this type need confirmation before it runs?
	 *
	 * @param string $type Tool type.
	 *
	 * @return bool
	 */
	public static function type_requires_approval( $type ) {
		return in_array( $type, array( self::WRITE, self::DELETE, self::ACTION ), true );
	}

	/**
	 * Run the tool with every cross-cutting concern applied.
	 *
	 * Order matters and mirrors ZIP AI's: budget, rate limit, validate, dry run,
	 * execute, measure, log. `Error` is caught separately from `Exception` — a
	 * TypeError inside a tool must be reported to the model as a failed call, not
	 * escape and kill the whole turn.
	 *
	 * @param array                        $args    Raw arguments from the model.
	 * @param Styble_AI_Turn_Budget|null   $budget  Turn budget, when in a loop.
	 *
	 * @return array
	 */
	public function handle( array $args, $budget = null ) {
		$started = microtime( true );
		$mem     = memory_get_usage();

		// ONE exit point. An earlier draft returned early from each guard, which
		// meant a refused call — bad arguments, an exhausted budget, a rate limit —
		// was neither stamped nor logged. Those are precisely the calls worth
		// having in the log: a turn that "did nothing" is diagnosed by seeing what
		// the model tried and why it was refused, and a rejection that leaves no
		// trace looks identical to a call that was never made.
		try {
			$result = $this->attempt( $args, $budget );
		} catch ( Exception $e ) {
			$result = $this->fail( 'exception', $e->getMessage() );
		} catch ( Error $e ) {
			// A TypeError or a call to a missing method is a bug in the tool, not a
			// reason to lose the turn. The model sees a failed call and can proceed.
			$result = $this->fail( 'error', $e->getMessage() );
		}

		$result = $this->stamp( $result, $started, $mem );
		Styble_AI_Tool_Log::record( $this->id, $args, $result );
		return $result;
	}

	/**
	 * The guards and the call itself. Every path returns a result array; nothing
	 * here logs or stamps — `handle()` owns both.
	 *
	 * @param array                      $args   Raw arguments.
	 * @param Styble_AI_Turn_Budget|null $budget Turn budget.
	 *
	 * @return array
	 */
	private function attempt( array $args, $budget ) {
		if ( $budget && ! $budget->allows() ) {
			return $this->fail(
				'budget_exhausted',
				sprintf(
					'This turn has stopped: %s. Report what has been done so far and stop calling tools.',
					$budget->exhausted_because()
				)
			);
		}

		if ( ! $this->user_can() ) {
			return $this->fail(
				'forbidden',
				sprintf( 'The current user lacks the %s capability.', $this->capability )
			);
		}

		$limited = $this->rate_limited();
		if ( '' !== $limited ) {
			return $this->fail( 'rate_limited', $limited );
		}

		$validated = $this->validate_args( $args );
		if ( isset( $validated['__error'] ) ) {
			return $this->fail( 'invalid_args', $validated['__error'] );
		}

		if ( $this->is_destructive && ! empty( $validated['dry_run'] ) ) {
			return $this->dry_run( $validated );
		}

		return $this->run( $validated );
	}

	/**
	 * Default preview: says nothing changed. Destructive tools should override to
	 * report the diff they WOULD apply — that is what makes E.2's automatic dry
	 * pass useful rather than a speed bump.
	 *
	 * @param array $args Validated arguments.
	 *
	 * @return array
	 */
	protected function dry_run( array $args ) {
		return array(
			'ok'      => true,
			'dry_run' => true,
			'message' => 'Preview only. Nothing was changed.',
		);
	}

	/**
	 * Validate and coerce arguments against the final schema.
	 *
	 * Deliberately shallow: types, enums, required keys. It is NOT the block
	 * validator, and must never become a second one — `class-validator.php` owns
	 * tree legality and it never coerces. This only checks that the CALL is
	 * well-formed before a tool touches anything.
	 *
	 * @param array $args Raw arguments.
	 *
	 * @return array Validated arguments, or [ '__error' => string ].
	 */
	protected function validate_args( array $args ) {
		$schema = $this->final_input_schema();
		$props  = isset( $schema['properties'] ) ? $schema['properties'] : array();
		$req    = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$out    = array();

		foreach ( $req as $key ) {
			if ( ! array_key_exists( $key, $args ) || '' === $args[ $key ] || null === $args[ $key ] ) {
				return array( '__error' => sprintf( 'Missing required argument `%s`.', $key ) );
			}
		}

		foreach ( $args as $key => $value ) {
			if ( ! isset( $props[ $key ] ) ) {
				// Unknown argument. Named rather than ignored, for the same reason
				// update-block reports unknown_attrs: a silently dropped argument
				// teaches the model that the call worked.
				return array(
					'__error' => sprintf(
						'Unknown argument `%s`. This tool accepts: %s.',
						$key,
						implode( ', ', array_keys( $props ) )
					),
				);
			}

			$spec = $props[ $key ];
			$type = isset( $spec['type'] ) ? $spec['type'] : 'string';

			if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
				return array(
					'__error' => sprintf(
						'`%s` must be one of: %s. Got %s.',
						$key,
						implode( ', ', array_map( 'strval', $spec['enum'] ) ),
						self::describe( $value )
					),
				);
			}

			$typed = self::coerce( $value, $type );
			if ( isset( $typed['__error'] ) ) {
				return array( '__error' => sprintf( '`%s` %s', $key, $typed['__error'] ) );
			}

			$out[ $key ] = $typed['value'];
		}

		// Apply declared defaults for anything absent, so `run()` never has to
		// second-guess whether a key was omitted or explicitly falsy.
		foreach ( $props as $key => $spec ) {
			if ( ! array_key_exists( $key, $out ) && array_key_exists( 'default', $spec ) ) {
				$out[ $key ] = $spec['default'];
			}
		}

		return $out;
	}

	/**
	 * Type check with the narrow coercions that are always safe.
	 *
	 * A JSON boolean arriving as the STRING "true" is the one exception worth
	 * accepting rather than rejecting — decision-log #3 records four `attr_type`
	 * failures on exactly that, and unlike a block attribute a tool argument has
	 * no downstream renderer to confuse. Numbers likewise.
	 *
	 * @param mixed  $value Value.
	 * @param string $type  JSON Schema type.
	 *
	 * @return array [ 'value' => mixed ] or [ '__error' => string ]
	 */
	private static function coerce( $value, $type ) {
		switch ( $type ) {
			case 'boolean':
				if ( is_bool( $value ) ) {
					return array( 'value' => $value );
				}
				if ( 'true' === $value || 'false' === $value ) {
					return array( 'value' => 'true' === $value );
				}
				return array( '__error' => 'must be true or false, got ' . self::describe( $value ) . '.' );

			case 'integer':
				if ( is_int( $value ) ) {
					return array( 'value' => $value );
				}
				if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
					return array( 'value' => (int) $value );
				}
				return array( '__error' => 'must be a whole number, got ' . self::describe( $value ) . '.' );

			case 'number':
				if ( is_int( $value ) || is_float( $value ) ) {
					return array( 'value' => $value );
				}
				if ( is_string( $value ) && is_numeric( $value ) ) {
					return array( 'value' => 0 + $value );
				}
				return array( '__error' => 'must be a number, got ' . self::describe( $value ) . '.' );

			case 'array':
				if ( is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
					return array( 'value' => $value );
				}
				return array( '__error' => 'must be an array, got ' . self::describe( $value ) . '.' );

			case 'object':
				if ( is_array( $value ) ) {
					return array( 'value' => $value );
				}
				return array( '__error' => 'must be an object, got ' . self::describe( $value ) . '. Never send a quoted JSON string.' );

			case 'string':
			default:
				if ( is_string( $value ) ) {
					return array( 'value' => $value );
				}
				if ( is_int( $value ) || is_float( $value ) ) {
					return array( 'value' => (string) $value );
				}
				return array( '__error' => 'must be a string, got ' . self::describe( $value ) . '.' );
		}
	}

	/**
	 * A short, model-readable description of a bad value. Whole point is that the
	 * error names what arrived, so the model can see its own mistake.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string
	 */
	private static function describe( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value );
			return strlen( (string) $json ) > 80 ? substr( (string) $json, 0, 77 ) . '…' : (string) $json;
		}
		if ( is_string( $value ) ) {
			return strlen( $value ) > 80 ? '"' . substr( $value, 0, 77 ) . '…"' : '"' . $value . '"';
		}
		return (string) $value;
	}

	/**
	 * Transient-backed per-user, per-tool rate limit.
	 *
	 * @return string Message when limited, '' when allowed.
	 */
	protected function rate_limited() {
		if ( defined( 'STYBLE_AI_CLI' ) || ! function_exists( 'get_transient' ) ) {
			return '';
		}

		$key   = 'styble_ai_rate_' . get_current_user_id() . '_' . md5( $this->id );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return sprintf( 'Rate limit reached for %s. Try again in a minute.', $this->id );
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return '';
	}

	/**
	 * Capability check. Skipped under CLI so scripts can drive tools directly.
	 *
	 * @return bool
	 */
	protected function user_can() {
		if ( defined( 'STYBLE_AI_CLI' ) || ! function_exists( 'current_user_can' ) ) {
			return true;
		}
		return current_user_can( $this->capability );
	}

	/**
	 * A failed call, in the shape the loop folds back to the model.
	 *
	 * `ok: false` with a `code` and a message written FOR THE MODEL — ZIP AI's
	 * habit worth copying: an error that says what to do instead is worth more
	 * than one that only says what went wrong.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human/model-readable message.
	 *
	 * @return array
	 */
	protected function fail( $code, $message ) {
		return array(
			'ok'    => false,
			'code'  => $code,
			'error' => $message,
		);
	}

	/**
	 * Attach timing to a result.
	 *
	 * @param array $result  Tool result.
	 * @param float $started microtime at entry.
	 * @param int   $mem     memory_get_usage at entry.
	 *
	 * @return array
	 */
	private function stamp( array $result, $started, $mem ) {
		$result['tool'] = $this->id;
		$result['took'] = round( ( microtime( true ) - $started ) * 1000, 2 );
		$result['mem']  = round( ( memory_get_peak_usage() - $mem ) / 1024, 2 );
		return $result;
	}

	/**
	 * Is this id well-formed? `namespace/verb-resource`, verb from VERBS.
	 *
	 * @param string $id Tool id.
	 *
	 * @return bool
	 */
	public static function id_is_valid( $id ) {
		return (bool) preg_match(
			'#^[a-z0-9-]+/(' . implode( '|', self::VERBS ) . ')-[a-z0-9-]+$#',
			(string) $id
		);
	}

	public function id() {
		return $this->id;
	}

	public function label() {
		return $this->label;
	}

	public function description() {
		return $this->description;
	}

	public function is_destructive() {
		return $this->is_destructive;
	}

	public function read_only_actions() {
		return array_values( $this->read_only_actions );
	}

	public function resource() {
		return $this->resource;
	}

	public function version() {
		return $this->version;
	}

	public function capability() {
		return $this->capability;
	}
}

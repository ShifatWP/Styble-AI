<?php
/**
 * Discovers tools and hands the model their schemas.
 *
 * Ported from ZIP AI's `Ability_Loader` + `Tool_Registry` (docs/
 * SPECTRA_AI_IMPLEMENTATION.md §5d, §5f), merged because we do not need their
 * split: theirs exists to bridge the WordPress Abilities API and a third-party
 * registration hook, and ours only has to find files and describe them.
 *
 * The property worth keeping: **adding a tool is dropping one file in
 * includes/tools/.** No registry to edit, nothing to remember. A directory walk
 * finds it, the id regex rejects it if it is misnamed, and it appears in the next
 * turn's tool list.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Tool_Registry {

	/**
	 * id => Styble_AI_Tool
	 *
	 * @var array<string,Styble_AI_Tool>
	 */
	private $tools = array();

	/**
	 * Ids rejected at load, with the reason. Surfaced by `problems()` so a
	 * misnamed tool is visible rather than merely absent — the failure mode of a
	 * silent skip is a tool the author believes shipped.
	 *
	 * @var array<string,string>
	 */
	private $rejected = array();

	/**
	 * @param string $dir Directory to scan; defaults to includes/tools.
	 */
	public function __construct( $dir = '' ) {
		$dir = $dir ? $dir : self::default_dir();
		$this->discover( $dir );

		/**
		 * Register additional tools.
		 *
		 * @param Styble_AI_Tool_Registry $registry This registry.
		 */
		if ( function_exists( 'do_action' ) ) {
			do_action( 'styble_ai_register_tools', $this );
		}
	}

	/**
	 * @return string
	 */
	private static function default_dir() {
		if ( defined( 'STYBLE_AI_DIR' ) ) {
			return STYBLE_AI_DIR . 'includes/tools';
		}
		return dirname( __DIR__ ) . '/includes/tools';
	}

	/**
	 * Recursively require every PHP file under $dir and register the
	 * Styble_AI_Tool subclasses it defines.
	 *
	 * Class names are derived from the file, not guessed from the path: the file
	 * declares its own class and we diff the class list before and after the
	 * require. That avoids ZIP AI's PSR-4-from-path convention, which silently
	 * skips a file whose namespace does not match its directory.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	private function discover( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		$files = array();
		foreach ( $iterator as $file ) {
			if ( $file->isDir() || 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( 'index' === $file->getBasename( '.php' ) ) {
				continue;
			}
			$files[] = $file->getPathname();
		}

		// Sorted so discovery order is deterministic, and therefore so is the
		// order tools appear in the schema list. An unstable order would move the
		// cached prompt prefix on every request (invariant 10).
		sort( $files );

		foreach ( $files as $path ) {
			$before = get_declared_classes();
			require_once $path;
			$after = get_declared_classes();

			foreach ( array_diff( $after, $before ) as $class ) {
				if ( ! is_subclass_of( $class, 'Styble_AI_Tool' ) ) {
					continue;
				}
				$reflection = new ReflectionClass( $class );
				if ( $reflection->isAbstract() ) {
					continue;
				}
				$this->add( new $class() );
			}
		}
	}

	/**
	 * Register one tool, rejecting a malformed id.
	 *
	 * The id gate is ZIP AI's, and its value is that a model can derive
	 * `page/update-block` from "update the block" without consulting a list. A
	 * tool called `page/blockUpdate` breaks that quietly for every future call, so
	 * it is refused here rather than shipped.
	 *
	 * @param Styble_AI_Tool $tool Tool.
	 *
	 * @return bool
	 */
	public function add( Styble_AI_Tool $tool ) {
		$id = $tool->id();

		if ( ! Styble_AI_Tool::id_is_valid( $id ) ) {
			$this->rejected[ $id ? $id : get_class( $tool ) ] = sprintf(
				'Malformed id. Expected {namespace}/{verb}-{resource} with verb one of: %s.',
				implode( ', ', Styble_AI_Tool::VERBS )
			);
			return false;
		}

		if ( '' === $tool->description() ) {
			$this->rejected[ $id ] = 'No description. The model has nothing to decide on.';
			return false;
		}

		if ( isset( $this->tools[ $id ] ) ) {
			$this->rejected[ $id ] = 'Duplicate id; the first registration wins.';
			return false;
		}

		$this->tools[ $id ] = $tool;
		return true;
	}

	/**
	 * @param string $id Tool id.
	 *
	 * @return Styble_AI_Tool|null
	 */
	public function get( $id ) {
		return isset( $this->tools[ $id ] ) ? $this->tools[ $id ] : null;
	}

	/**
	 * @return array<string,Styble_AI_Tool>
	 */
	public function all() {
		return $this->tools;
	}

	/**
	 * @return array<int,string>
	 */
	public function ids() {
		return array_keys( $this->tools );
	}

	/**
	 * Tools rejected at load, id => reason.
	 *
	 * @return array<string,string>
	 */
	public function problems() {
		return $this->rejected;
	}

	/**
	 * Tools acting on one resource. Backs the read-first-write pairing in E.2.
	 *
	 * @param string $resource Resource name.
	 *
	 * @return array<string,Styble_AI_Tool>
	 */
	public function for_resource( $resource ) {
		$out = array();
		foreach ( $this->tools as $id => $tool ) {
			if ( $tool->resource() === $resource ) {
				$out[ $id ] = $tool;
			}
		}
		return $out;
	}

	/**
	 * The tool list in Anthropic's shape.
	 *
	 * @param array<int,string> $only Restrict to these ids; all when empty.
	 *
	 * @return array
	 */
	public function anthropic_schemas( array $only = array() ) {
		$out = array();
		foreach ( $this->selected( $only ) as $tool ) {
			$out[] = array(
				'name'         => self::wire_name( $tool->id() ),
				'description'  => $tool->description(),
				'input_schema' => $tool->final_input_schema(),
			);
		}
		return $out;
	}

	/**
	 * The tool list in OpenAI's shape.
	 *
	 * @param array<int,string> $only Restrict to these ids; all when empty.
	 *
	 * @return array
	 */
	public function openai_schemas( array $only = array() ) {
		$out = array();
		foreach ( $this->selected( $only ) as $tool ) {
			$out[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => self::wire_name( $tool->id() ),
					'description' => $tool->description(),
					'parameters'  => $tool->final_input_schema(),
				),
			);
		}
		return $out;
	}

	/**
	 * @param array<int,string> $only Ids.
	 *
	 * @return array<int,Styble_AI_Tool>
	 */
	private function selected( array $only ) {
		if ( ! $only ) {
			return array_values( $this->tools );
		}
		$out = array();
		foreach ( $only as $id ) {
			if ( isset( $this->tools[ $id ] ) ) {
				$out[] = $this->tools[ $id ];
			}
		}
		return $out;
	}

	/**
	 * `page/update-block` -> `page__update_block`.
	 *
	 * Providers restrict tool names to `[A-Za-z0-9_-]`, so the slash and the
	 * hyphens have to go. ZIP AI hit a real defect on exactly this boundary: their
	 * brain knows tools as `namespace__name` while one of their own meta-tools
	 * resolved by `namespace/name`, so an argument never matched and the call
	 * returned a misleading "invalid permissions" that dead-ended recovery. Hence
	 * one pair of functions here, used on both sides, and nothing else converting.
	 *
	 * @param string $id Tool id.
	 *
	 * @return string
	 */
	public static function wire_name( $id ) {
		return str_replace( array( '/', '-' ), array( '__', '_' ), $id );
	}

	/**
	 * Inverse of `wire_name()`, resolved against the registry rather than by
	 * string surgery — `page__update_block` is ambiguous on its own, since both
	 * `/` and `-` became `_`.
	 *
	 * @param string $wire Wire name.
	 *
	 * @return string Tool id, or '' when unknown.
	 */
	public function id_from_wire( $wire ) {
		foreach ( array_keys( $this->tools ) as $id ) {
			if ( self::wire_name( $id ) === $wire ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Run a tool by wire name, resolving it through the registry.
	 *
	 * An unknown name is a failed call, not an exception: the model gets the list
	 * of what it could have called instead, which is the difference between a
	 * recoverable turn and a dead one.
	 *
	 * @param string                     $wire   Wire name from the provider.
	 * @param array                      $args   Arguments.
	 * @param Styble_AI_Turn_Budget|null $budget Turn budget.
	 *
	 * @return array
	 */
	public function execute( $wire, array $args, $budget = null ) {
		$id = $this->id_from_wire( $wire );

		if ( '' === $id ) {
			// Logged, not just returned. A hallucinated tool name is one of the
			// commonest model mistakes and it never reaches Styble_AI_Tool::handle(),
			// so without this the log shows a turn where nothing happened and gives
			// no hint that the model was reaching for a tool that does not exist.
			$result = array(
				'ok'    => false,
				'code'  => 'unknown_tool',
				'error' => sprintf(
					'No tool named `%s`. Available: %s.',
					$wire,
					implode( ', ', array_map( array( __CLASS__, 'wire_name' ), $this->ids() ) )
				),
			);
			Styble_AI_Tool_Log::record( '(unknown) ' . $wire, $args, $result );
			return $result;
		}

		return $this->tools[ $id ]->handle( $args, $budget );
	}
}

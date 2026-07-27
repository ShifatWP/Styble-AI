<?php
/**
 * Read-only accessor over the generated block catalog.
 *
 * @package Styble_AI
 */

// Pure, WP-free class: also loaded by the CLI scripts, which define STYBLE_AI_CLI.
if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

/**
 * Wraps catalog/catalog.json.
 *
 * The catalog is the single source of truth for what the AI may emit: which
 * blocks exist, which are allowlisted, what each one's editable attributes are
 * (with types and defaults), how blocks may nest, and the container layouts.
 * The prompt and the validator read nothing else, so neither can drift from the
 * blocks — regenerating the catalog regenerates both.
 */
class Styble_AI_Catalog {

	/**
	 * Decoded catalog.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Block entries keyed by full block name (styble/foo), not slug.
	 *
	 * @var array
	 */
	private $by_name = array();

	/**
	 * Layout entries keyed by layout id.
	 *
	 * @var array
	 */
	private $layouts_by_id = array();

	/**
	 * @param array $data Decoded catalog.
	 *
	 * @throws RuntimeException When the catalog is missing required sections.
	 */
	public function __construct( array $data ) {
		if ( ! isset( $data['contractVersion'], $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
			throw new RuntimeException( 'Catalog is malformed: contractVersion and blocks are required.' );
		}
		$this->data = $data;

		foreach ( $data['blocks'] as $entry ) {
			if ( isset( $entry['name'] ) ) {
				$this->by_name[ $entry['name'] ] = $entry;
			}
		}
		foreach ( ( isset( $data['layouts'] ) ? $data['layouts'] : array() ) as $layout ) {
			if ( isset( $layout['id'] ) ) {
				$this->layouts_by_id[ $layout['id'] ] = $layout;
			}
		}
	}

	/**
	 * Default catalog location.
	 *
	 * @return string
	 */
	public static function default_path() {
		if ( defined( 'STYBLE_AI_DIR' ) ) {
			return STYBLE_AI_DIR . 'catalog/catalog.json';
		}
		return dirname( __DIR__ ) . '/catalog/catalog.json';
	}

	/**
	 * Load and decode a catalog file.
	 *
	 * @param string|null $path Catalog path, or null for the default.
	 *
	 * @return Styble_AI_Catalog
	 * @throws RuntimeException When the file is missing or not valid JSON.
	 */
	public static function from_file( $path = null ) {
		$path = ( null === $path ) ? self::default_path() : $path;

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException(
				"Catalog not found or unreadable: {$path}. Run: php scripts/generate-catalog.php"
			);
		}

		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( "Catalog is not valid JSON: {$path}" );
		}

		return new self( $data );
	}

	/**
	 * Contract version the catalog was generated for.
	 *
	 * @return string
	 */
	public function contract_version() {
		return (string) $this->data['contractVersion'];
	}

	/**
	 * @param string $name Full block name.
	 *
	 * @return bool
	 */
	public function has_block( $name ) {
		return isset( $this->by_name[ $name ] );
	}

	/**
	 * @param string $name Full block name.
	 *
	 * @return array Empty array when unknown.
	 */
	public function block( $name ) {
		return isset( $this->by_name[ $name ] ) ? $this->by_name[ $name ] : array();
	}

	/**
	 * May the AI emit this block?
	 *
	 * @param string $name Full block name.
	 *
	 * @return bool
	 */
	public function is_allowlisted( $name ) {
		$block = $this->block( $name );
		return ! empty( $block['aiAllowlist'] );
	}

	/**
	 * Every allowlisted block name.
	 *
	 * @return string[]
	 */
	public function allowlisted_names() {
		$out = array();
		foreach ( $this->by_name as $name => $entry ) {
			if ( ! empty( $entry['aiAllowlist'] ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Does this block render InnerBlocks?
	 *
	 * @param string $name Full block name.
	 *
	 * @return bool
	 */
	public function accepts_children( $name ) {
		$block = $this->block( $name );
		return ! empty( $block['acceptsChildren'] );
	}

	/**
	 * Parent-side allowedBlocks. An empty list on a block that accepts children
	 * means "no parent-side restriction" — not "nothing allowed".
	 *
	 * @param string $name Full block name.
	 *
	 * @return string[]
	 */
	public function allowed_children( $name ) {
		$block = $this->block( $name );
		return isset( $block['allowedChildren'] ) ? $block['allowedChildren'] : array();
	}

	/**
	 * Child-side `parent:` restriction. Non-empty means the block is ONLY legal
	 * inside one of these.
	 *
	 * @param string $name Full block name.
	 *
	 * @return string[]
	 */
	public function parents_of( $name ) {
		$block = $this->block( $name );
		return isset( $block['parents'] ) ? $block['parents'] : array();
	}

	/**
	 * Editable attribute definitions (type + default) keyed by attribute name.
	 *
	 * @param string $name Full block name.
	 *
	 * @return array
	 */
	public function editable_attrs( $name ) {
		$block = $this->block( $name );
		return isset( $block['editable'] ) ? $block['editable'] : array();
	}

	/**
	 * The block's useUniqueId() prefix, or null when it never calls the hook and
	 * so must not be given a uniqueId at all.
	 *
	 * @param string $name Full block name.
	 *
	 * @return string|null
	 */
	public function unique_id_prefix( $name ) {
		$block = $this->block( $name );
		return isset( $block['uniqueIdPrefix'] ) ? $block['uniqueIdPrefix'] : null;
	}

	/**
	 * @param string $id Layout id.
	 *
	 * @return array Empty array when unknown.
	 */
	public function layout( $id ) {
		return isset( $this->layouts_by_id[ $id ] ) ? $this->layouts_by_id[ $id ] : array();
	}

	/**
	 * @return string[]
	 */
	public function layout_ids() {
		return array_keys( $this->layouts_by_id );
	}

	/**
	 * @return array
	 */
	public function layouts() {
		return isset( $this->data['layouts'] ) ? $this->data['layouts'] : array();
	}

	/**
	 * Brand colour slugs and type-scale slugs (static reference values; the live
	 * ones come from Styble Pro at prompt time).
	 *
	 * @return array
	 */
	public function brand_defaults() {
		return isset( $this->data['brandDefaults'] ) ? $this->data['brandDefaults'] : array();
	}

	/**
	 * Raw decoded catalog.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->data;
	}
}

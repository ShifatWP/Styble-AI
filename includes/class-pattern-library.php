<?php
/**
 * The pattern library: human-designed sections the model can place and fill.
 *
 * This is Spectra's whole first-generation AI, done properly — see
 * docs/SPECTRA_AI_IMPLEMENTATION.md §14a. Theirs is "human-designed templates plus
 * AI-written copy": onboarding collects business details, a remote endpoint writes
 * copy per template category, and the importer swaps placeholders block by block on
 * the way in. The layout is never the model's problem.
 *
 * The structural advantage over freeform generation is the one that pays: **a
 * pattern is validated ONCE, at ingest, by a human.** A pattern that fails is a
 * library bug fixed once; a freeform section that fails is a per-request model
 * failure, and the measured first-try validity of freeform is 65–75%. So the model
 * stops being responsible for legality and becomes responsible only for choosing
 * and for writing copy.
 *
 * ── Patterns are TREES, not markup ───────────────────────────────────────────
 *
 * A pattern file holds an `emit_layout` envelope: exactly what the model would have
 * emitted, and exactly what `Styble_AI_Page_Store::set_section_tree()` accepts. Not
 * block markup. Three reasons, and they are all load-bearing:
 *
 *  - Invariant 12 — `post_content` is DERIVED from `_styble_ai_page`. Read the
 *    trees, never parse the markup back. A markup-shaped pattern would force a
 *    parse on the way in and put a second, lossy source of truth in the middle.
 *  - Invariant 2 — the validator never coerces, and it validates trees. A tree
 *    pattern goes through the same gate as a generated one, with the same 31 codes.
 *  - The appliers already own serialization (invariant 5). A tree pattern reaches
 *    the page through `set_section_tree()` → `rebuild()`, which is the identical
 *    path a generated section takes. No new write path (invariant 14).
 *
 * `styble-patterns-provider` serves raw block markup (`POST /single-pattern`), so
 * it is NOT wired here yet: consuming it needs a one-time markup → tree conversion
 * that has to be a supervised ingest step, not a runtime parse. Recorded rather
 * than smuggled in — see `docs/ZIPAI_SELECTIVE_PORT.md` §9.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Pattern_Library {

	/**
	 * @var Styble_AI_Catalog
	 */
	private $catalog;

	/**
	 * @var Styble_AI_Validator
	 */
	private $validator;

	/**
	 * @var string
	 */
	private $dir;

	/**
	 * slug => pattern record, only the ones that PASSED ingest validation.
	 *
	 * @var array<string,array>|null
	 */
	private $patterns = null;

	/**
	 * slug => rejection reason, for the ones that did not.
	 *
	 * A rejected pattern is a library defect and must be visible. Dropping it
	 * silently is how a pattern nobody can insert stays in the folder for months
	 * looking installed.
	 *
	 * @var array<string,string>
	 */
	private $rejected = array();

	/**
	 * @param Styble_AI_Catalog $catalog Block catalog.
	 * @param string            $dir     Pattern directory; defaults to patterns/.
	 */
	public function __construct( Styble_AI_Catalog $catalog, $dir = '' ) {
		$this->catalog   = $catalog;
		$this->validator = new Styble_AI_Validator( $catalog );
		$this->dir       = $dir ? $dir : self::default_dir();
	}

	/**
	 * @return string
	 */
	private static function default_dir() {
		if ( defined( 'STYBLE_AI_DIR' ) ) {
			return STYBLE_AI_DIR . 'patterns';
		}
		return dirname( __DIR__ ) . '/patterns';
	}

	/**
	 * Load and validate every pattern file, once per request.
	 *
	 * @return array<string,array>
	 */
	public function all() {
		if ( null !== $this->patterns ) {
			return $this->patterns;
		}

		$this->patterns = array();
		$this->rejected = array();

		// Not trailingslashit() — this class has to be loadable under STYBLE_AI_CLI
		// for the ingest check, where no WordPress functions exist.
		$files = glob( rtrim( $this->dir, '/\\' ) . '/*.json' );
		if ( ! $files ) {
			return $this->patterns;
		}

		sort( $files );

		foreach ( $files as $path ) {
			$slug = basename( $path, '.json' );
			$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = json_decode( (string) $raw, true );

			if ( ! is_array( $data ) ) {
				$this->rejected[ $slug ] = 'Not valid JSON.';
				continue;
			}

			$record = $this->ingest( $slug, $data );
			if ( isset( $record['__error'] ) ) {
				$this->rejected[ $slug ] = $record['__error'];
				continue;
			}

			$this->patterns[ $slug ] = $record;
		}

		return $this->patterns;
	}

	/**
	 * Validate one pattern and build its record.
	 *
	 * THE gate. Every pattern goes through `Styble_AI_Validator` — the same
	 * instance, the same 31 codes, the same never-coerce rule that generated
	 * sections face. A pattern that cannot pass is not servable, and finding that
	 * out here rather than at insert time is the entire economic argument for the
	 * library.
	 *
	 * @param string $slug Pattern slug.
	 * @param array  $data Decoded file.
	 *
	 * @return array Record, or [ '__error' => string ].
	 */
	private function ingest( $slug, array $data ) {
		foreach ( array( 'name', 'intent', 'tree' ) as $key ) {
			if ( empty( $data[ $key ] ) ) {
				return array( '__error' => sprintf( 'Missing `%s`.', $key ) );
			}
		}

		if ( ! is_array( $data['tree'] ) ) {
			return array( '__error' => '`tree` must be an emit_layout envelope object.' );
		}

		$result = $this->validator->validate( $data['tree'] );
		if ( ! $result->is_valid() ) {
			return array(
				'__error' => 'Fails the validator: ' . $result->to_string(),
			);
		}

		// Slots are derived from the tree, never declared in the file. A declared
		// list would drift the moment someone edited the tree, and the drift would
		// be invisible until fill-slots wrote to a path that no longer exists.
		$slots = $this->slots_of( $data['tree'] );

		return array(
			'slug'      => $slug,
			'name'      => (string) $data['name'],
			'intent'    => (string) $data['intent'],
			'tags'      => isset( $data['tags'] ) && is_array( $data['tags'] )
				? array_values( array_map( 'strval', $data['tags'] ) )
				: array(),
			'blocks'    => $this->block_names( $data['tree'] ),
			'slots'     => $slots,
			'slotCount' => count( $slots ),
			'tree'      => $data['tree'],
		);
	}

	/**
	 * Every fillable copy slot in a pattern, as dot paths into the tree.
	 *
	 * A slot is a content attribute — the validator's own CONTENT_ATTRS map, so
	 * "what can be filled" is defined by the same table that decides what may not
	 * be empty. That keeps fill-slots from inventing a writable surface: anything
	 * it can write is something a section is required to have.
	 *
	 * Path shape is `root.children.0.children.1`, addressing the NODE; the
	 * attribute name rides alongside so the writer never has to re-derive it.
	 *
	 * @param array $tree Envelope.
	 *
	 * @return array<int,array{path:string,block:string,attr:string,text:string}>
	 */
	public function slots_of( array $tree ) {
		$out = array();
		if ( ! isset( $tree['root'] ) || ! is_array( $tree['root'] ) ) {
			return $out;
		}
		$this->walk_slots( $tree['root'], 'root', $out );
		return $out;
	}

	/**
	 * @param array  $node Node.
	 * @param string $path Dot path to this node.
	 * @param array  $out  Accumulator.
	 *
	 * @return void
	 */
	private function walk_slots( array $node, $path, array &$out ) {
		$block = isset( $node['block'] ) ? (string) $node['block'] : '';

		if ( isset( Styble_AI_Validator::CONTENT_ATTRS[ $block ] ) ) {
			$attr = Styble_AI_Validator::CONTENT_ATTRS[ $block ]['attr'];
			$out[] = array(
				'path'  => $path,
				'block' => $block,
				'attr'  => $attr,
				'text'  => isset( $node['attrs'][ $attr ] ) ? (string) $node['attrs'][ $attr ] : '',
			);
		}

		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$this->walk_slots( $child, $path . '.children.' . $i, $out );
				}
			}
		}
	}

	/**
	 * Distinct block names used by a pattern, for search and for the record.
	 *
	 * @param array $tree Envelope.
	 *
	 * @return array<int,string>
	 */
	private function block_names( array $tree ) {
		$names = array();
		$stack = isset( $tree['root'] ) && is_array( $tree['root'] ) ? array( $tree['root'] ) : array();

		while ( $stack ) {
			$node = array_pop( $stack );
			if ( ! empty( $node['block'] ) ) {
				$names[ (string) $node['block'] ] = true;
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				foreach ( $node['children'] as $child ) {
					if ( is_array( $child ) ) {
						$stack[] = $child;
					}
				}
			}
		}

		$out = array_keys( $names );
		sort( $out );
		return $out;
	}

	/**
	 * One pattern by slug, or null.
	 *
	 * @param string $slug Slug.
	 *
	 * @return array|null
	 */
	public function get( $slug ) {
		$all = $this->all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Patterns that failed ingest, slug => reason.
	 *
	 * @return array<string,string>
	 */
	public function problems() {
		$this->all();
		return $this->rejected;
	}

	/**
	 * Rank patterns against a plain-language need.
	 *
	 * Deliberately a keyword score and not an embedding: the library is small
	 * enough that the model reads the candidate list and chooses, so search only
	 * has to put plausible options in front of it. Scoring is transparent, which
	 * matters more here than precision — an inexplicable ranking is impossible to
	 * tune against.
	 *
	 * @param string $need  What the user asked for.
	 * @param int    $limit Max results.
	 *
	 * @return array<int,array> Records without their trees, plus a score.
	 */
	public function search( $need, $limit = 8 ) {
		$terms = self::terms( $need );
		$out   = array();

		foreach ( $this->all() as $slug => $pattern ) {
			$haystack = self::terms(
				$pattern['name'] . ' ' . $pattern['intent'] . ' ' . implode( ' ', $pattern['tags'] ) . ' ' . $slug
			);

			$score = 0;
			foreach ( $terms as $term ) {
				if ( in_array( $term, $haystack, true ) ) {
					// An exact tag or name hit is worth more than a partial.
					$score += 3;
					continue;
				}
				foreach ( $haystack as $word ) {
					if ( strlen( $term ) >= 4 && false !== strpos( $word, $term ) ) {
						$score += 1;
						break;
					}
				}
			}

			$out[] = array(
				'slug'      => $slug,
				'name'      => $pattern['name'],
				'intent'    => $pattern['intent'],
				'tags'      => $pattern['tags'],
				'blocks'    => $pattern['blocks'],
				'slotCount' => $pattern['slotCount'],
				'score'     => $score,
			);
		}

		// Score desc, then slug asc so an unscored search is still deterministic
		// rather than filesystem-ordered.
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return strcmp( $a['slug'], $b['slug'] );
				}
				return $b['score'] - $a['score'];
			}
		);

		// Every pattern is returned when nothing matched — a small library with a
		// bad query is better browsed than refused.
		$hits = array_values( array_filter( $out, function ( $r ) { return $r['score'] > 0; } ) );
		$list = $hits ? $hits : $out;

		return array_slice( $list, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Lowercased word list, stop-words dropped.
	 *
	 * @param string $text Text.
	 *
	 * @return array<int,string>
	 */
	private static function terms( $text ) {
		$text  = strtolower( (string) $text );
		$words = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return array();
		}
		$stop = array( 'a', 'an', 'the', 'with', 'and', 'or', 'for', 'of', 'to', 'in', 'on', 'my', 'our', 'i', 'we', 'want', 'need', 'add', 'make', 'section', 'that', 'this' );
		return array_values( array_unique( array_diff( $words, $stop ) ) );
	}

	/**
	 * Write new copy into a pattern's slots and re-validate.
	 *
	 * Returns a fresh tree; the stored pattern is never mutated. Re-validation is
	 * not belt-and-braces: filling `advancedTextContent` with '' would trip
	 * `content_empty`, and a caller that skipped the check would write a section
	 * rendering grey placeholders — the failure mode invariant 14 exists to stop,
	 * and the one that looks like the feature worked.
	 *
	 * @param array                 $tree Pattern tree.
	 * @param array<string,string>  $copy path => new text.
	 *
	 * @return array{ok:bool,tree?:array,filled?:array,unknown?:array,error?:string}
	 */
	public function fill( array $tree, array $copy ) {
		$slots = array();
		foreach ( $this->slots_of( $tree ) as $slot ) {
			$slots[ $slot['path'] ] = $slot;
		}

		$filled  = array();
		$unknown = array();

		foreach ( $copy as $path => $text ) {
			if ( ! isset( $slots[ $path ] ) ) {
				$unknown[] = (string) $path;
				continue;
			}
			$tree = self::write_at( $tree, $path, $slots[ $path ]['attr'], (string) $text );
			$filled[] = (string) $path;
		}

		$result = $this->validator->validate( $tree );
		if ( ! $result->is_valid() ) {
			return array(
				'ok'    => false,
				'error' => 'The filled pattern no longer validates: ' . $result->to_string(),
			);
		}

		return array(
			'ok'      => true,
			'tree'    => $tree,
			'filled'  => $filled,
			'unknown' => $unknown,
		);
	}

	/**
	 * Set one attribute at a dot path, returning a modified copy.
	 *
	 * @param array  $tree Envelope.
	 * @param string $path Dot path to the node.
	 * @param string $attr Attribute name.
	 * @param string $text New value.
	 *
	 * @return array
	 */
	private static function write_at( array $tree, $path, $attr, $text ) {
		$keys = explode( '.', $path );
		$ref  = &$tree;

		foreach ( $keys as $key ) {
			if ( ! is_array( $ref ) || ! array_key_exists( $key, $ref ) ) {
				return $tree;
			}
			$ref = &$ref[ $key ];
		}

		if ( is_array( $ref ) ) {
			if ( ! isset( $ref['attrs'] ) || ! is_array( $ref['attrs'] ) ) {
				$ref['attrs'] = array();
			}
			$ref['attrs'][ $attr ] = $text;
		}

		unset( $ref );
		return $tree;
	}
}

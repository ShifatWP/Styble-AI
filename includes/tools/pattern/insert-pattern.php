<?php
/**
 * pattern/insert-pattern — place a designer-built section on a page.
 *
 * Writes the pattern's tree through `Styble_AI_Page_Store::set_section_tree()` —
 * the identical path a generated section takes, so there is no second write path
 * (invariant 14) and `post_content` stays derived (invariant 12).
 *
 * The reply carries the pattern's copy SLOTS with their placeholder text, so the
 * model's next move is obvious: call `pattern/fill-slots` with real words. Placing
 * a pattern and leaving the stock copy is the one outcome worse than not placing it
 * — it renders as a finished section saying the wrong thing.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Tool_Insert_Pattern extends Styble_AI_Tool {

	/**
	 * @var Styble_AI_Pattern_Library|null
	 */
	private $library = null;

	/**
	 * @var Styble_AI_Page_Store|null
	 */
	private $store = null;

	protected function configure() {
		$this->id             = 'pattern/insert-pattern';
		$this->label          = 'Insert pattern';
		$this->description    = 'Place a ready-made section from the pattern library onto a page, by slug. '
			. 'The layout arrives already correct — it was built by a designer and validated when the library loaded, '
			. 'so this cannot produce a broken section. Returns the section id and the list of copy slots. '
			. 'You MUST then call pattern/fill-slots to replace the placeholder words, or the page will read as someone else\'s.';
		$this->resource       = 'pattern';
		$this->capability     = 'edit_pages';
		$this->is_destructive = true;
	}

	public function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array(
					'type'        => 'integer',
					'description' => 'The page to insert into.',
				),
				'slug'     => array(
					'type'        => 'string',
					'description' => 'Pattern slug from pattern/search-patterns.',
				),
				'position' => array(
					'type'        => 'integer',
					'description' => 'Zero-based position among the page\'s sections. Omit to append.',
				),
			),
			'required'   => array( 'post_id', 'slug' ),
		);
	}

	/**
	 * @param Styble_AI_Pattern_Library $library Library.
	 * @param Styble_AI_Page_Store      $store   Page store.
	 *
	 * @return void
	 */
	public function set_deps( Styble_AI_Pattern_Library $library, Styble_AI_Page_Store $store ) {
		$this->library = $library;
		$this->store   = $store;
	}

	/**
	 * @return Styble_AI_Pattern_Library
	 */
	private function library() {
		if ( null === $this->library ) {
			$catalog       = Styble_AI_Catalog::from_file( STYBLE_AI_DIR . 'catalog/catalog.json' );
			$this->library = new Styble_AI_Pattern_Library( $catalog );
		}
		return $this->library;
	}

	/**
	 * @return Styble_AI_Page_Store
	 */
	private function store() {
		if ( null === $this->store ) {
			$catalog     = Styble_AI_Catalog::from_file( STYBLE_AI_DIR . 'catalog/catalog.json' );
			$this->store = new Styble_AI_Page_Store( new Styble_AI_Page_Applier( $catalog ) );
		}
		return $this->store;
	}

	/**
	 * Report what would be placed, without placing it.
	 *
	 * @param array $args Validated arguments.
	 *
	 * @return array
	 */
	protected function dry_run( array $args ) {
		$pattern = $this->library()->get( $args['slug'] );
		if ( ! $pattern ) {
			return $this->unknown_slug( $args['slug'] );
		}

		return array(
			'ok'      => true,
			'dry_run' => true,
			'slug'    => $pattern['slug'],
			'name'    => $pattern['name'],
			'slots'   => $this->slot_view( $pattern['slots'] ),
			'message' => sprintf(
				'Would place "%s" (%d copy slots) on page %d. Nothing was changed.',
				$pattern['name'],
				$pattern['slotCount'],
				$args['post_id']
			),
		);
	}

	protected function run( array $args ) {
		$pattern = $this->library()->get( $args['slug'] );
		if ( ! $pattern ) {
			return $this->unknown_slug( $args['slug'] );
		}

		$store   = $this->store();
		$post_id = (int) $args['post_id'];

		if ( ! $store->owns( $post_id ) ) {
			return $this->fail(
				'not_a_styble_page',
				sprintf(
					'Page %d was not created by Styble AI, so it has no section plan to insert into.',
					$post_id
				)
			);
		}

		$state    = $store->get( $post_id );
		$sections = isset( $state['sections'] ) && is_array( $state['sections'] ) ? $state['sections'] : array();

		// Section ids must be unique and stable: `uniqueId` is derived from
		// `sectionId | nodePath` (invariant 11), so a duplicate id would collide two
		// sections' scoped CSS and a reused one would renumber on rebuild.
		$section_id = $this->mint_section_id( $sections, $pattern['slug'] );

		$section = array(
			'id'      => $section_id,
			'brief'   => $pattern['name'],
			'pattern' => $pattern['slug'],
			'tree'    => $pattern['tree'],
		);

		$position = isset( $args['position'] ) ? max( 0, (int) $args['position'] ) : count( $sections );
		$position = min( $position, count( $sections ) );
		array_splice( $sections, $position, 0, array( $section ) );

		$title  = isset( $state['title'] ) ? $state['title'] : '';
		$store->set_plan( $post_id, $title, $sections );

		return array(
			'ok'         => true,
			'section_id' => $section_id,
			'slug'       => $pattern['slug'],
			'name'       => $pattern['name'],
			'position'   => $position,
			'slots'      => $this->slot_view( $pattern['slots'] ),
			'message'    => sprintf(
				'Placed "%s" at position %d. Now call pattern/fill-slots with section_id "%s" and real copy for its %d slots — the placeholder words are still in place.',
				$pattern['name'],
				$position,
				$section_id,
				$pattern['slotCount']
			),
		);
	}

	/**
	 * The slot list as the model needs it: path, what block it is, and the text
	 * currently sitting there so it can see what each slot is FOR.
	 *
	 * @param array $slots Slots.
	 *
	 * @return array
	 */
	private function slot_view( array $slots ) {
		$out = array();
		foreach ( $slots as $slot ) {
			$out[] = array(
				'path'    => $slot['path'],
				'block'   => $slot['block'],
				'current' => $slot['text'],
			);
		}
		return $out;
	}

	/**
	 * A section id that does not collide with one already on the page.
	 *
	 * @param array  $sections Existing sections.
	 * @param string $slug     Pattern slug.
	 *
	 * @return string
	 */
	private function mint_section_id( array $sections, $slug ) {
		$taken = array();
		foreach ( $sections as $section ) {
			if ( ! empty( $section['id'] ) ) {
				$taken[ (string) $section['id'] ] = true;
			}
		}

		$base = $slug;
		$id   = $base;
		$n    = 2;
		while ( isset( $taken[ $id ] ) ) {
			$id = $base . '-' . $n;
			$n++;
		}
		return $id;
	}

	/**
	 * @param string $slug Requested slug.
	 *
	 * @return array
	 */
	private function unknown_slug( $slug ) {
		$known = array_keys( $this->library()->all() );
		return $this->fail(
			'unknown_pattern',
			sprintf(
				'No pattern with slug `%s`. Available: %s. Call pattern/search-patterns to choose one.',
				$slug,
				$known ? implode( ', ', $known ) : 'none — the library is empty'
			)
		);
	}
}

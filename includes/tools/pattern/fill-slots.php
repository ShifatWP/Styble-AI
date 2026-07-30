<?php
/**
 * pattern/fill-slots — write real copy into a placed pattern's slots.
 *
 * The other half of the pattern bet: a designer owns the layout, the model owns the
 * words. Spectra's equivalent generates copy per template CATEGORY, caches it, and
 * swaps placeholders on import (see docs/SPECTRA_AI_IMPLEMENTATION.md §14a) — so
 * every site in a category gets the same sentences. This fills one specific placed
 * section, live, from the site's own brand context.
 *
 * Copy-only by construction. A slot is a content attribute from the validator's own
 * CONTENT_ATTRS table, so this tool cannot reach layout, colour or spacing even if
 * the model asks it to — the write surface IS the required-text surface. Structural
 * change is the agent loop's job.
 *
 * The filled tree is re-validated before it is stored. Not belt-and-braces: filling
 * a slot with '' trips `content_empty`, and a section of empty content attributes
 * renders grey placeholders — the failure that looks like the feature ran.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Tool_Fill_Slots extends Styble_AI_Tool {

	/**
	 * @var Styble_AI_Pattern_Library|null
	 */
	private $library = null;

	/**
	 * @var Styble_AI_Page_Store|null
	 */
	private $store = null;

	protected function configure() {
		$this->id             = 'pattern/fill-slots';
		$this->label          = 'Fill pattern copy';
		$this->description    = 'Replace the placeholder words in a placed pattern with real copy. '
			. 'Send one entry per slot, keyed by the slot path returned by pattern/insert-pattern. '
			. 'Write specific, publishable copy — actual names, actual numbers, actual claims. Never lorem ipsum, never "Your text here". '
			. 'You may fill some slots and leave others; anything you omit keeps its current text. '
			. 'This changes words only — it cannot alter layout, colour or spacing.';
		$this->resource       = 'pattern';
		$this->capability     = 'edit_pages';
		$this->is_destructive = true;
		// `preview` is a genuine read on an otherwise-mutating tool, which is
		// exactly what ZIP AI's read_only_actions allowlist is for: without it the
		// approval gate would stop a call that changes nothing.
		$this->read_only_actions = array( 'preview' );
	}

	public function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'    => array(
					'type'        => 'integer',
					'description' => 'The page holding the section.',
				),
				'section_id' => array(
					'type'        => 'string',
					'description' => 'Section id returned by pattern/insert-pattern.',
				),
				'copy'       => array(
					'type'        => 'object',
					'description' => 'Slot path => new text. e.g. {"root.children.0.children.0": "Coffee roasted on Tuesdays"}. '
						. 'A real JSON object, never a quoted string.',
				),
			),
			'required'   => array( 'post_id', 'section_id', 'copy' ),
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

	protected function dry_run( array $args ) {
		$prepared = $this->prepare( $args );
		if ( isset( $prepared['__fail'] ) ) {
			return $prepared['__fail'];
		}

		return array(
			'ok'      => true,
			'dry_run' => true,
			'filled'  => $prepared['filled'],
			'unknown' => $prepared['unknown'],
			'message' => sprintf(
				'Would fill %d slot(s) on section "%s". Nothing was changed.',
				count( $prepared['filled'] ),
				$args['section_id']
			),
		);
	}

	protected function run( array $args ) {
		$prepared = $this->prepare( $args );
		if ( isset( $prepared['__fail'] ) ) {
			return $prepared['__fail'];
		}

		$stored = $this->store()->set_section_tree(
			(int) $args['post_id'],
			(string) $args['section_id'],
			$prepared['tree']
		);

		if ( is_wp_error( $stored ) ) {
			return $this->fail( 'store_failed', $stored->get_error_message() );
		}

		$result = array(
			'ok'      => true,
			'filled'  => $prepared['filled'],
			'unknown' => $prepared['unknown'],
			'message' => sprintf( 'Filled %d slot(s) on "%s".', count( $prepared['filled'] ), $args['section_id'] ),
		);

		// An unknown path is reported, never ignored — I1's reasoning applied here:
		// a silently dropped write teaches the model the call succeeded, and it will
		// not try again.
		if ( $prepared['unknown'] ) {
			$result['message'] .= sprintf(
				' %d path(s) matched no slot and were skipped: %s. Re-read the slot list before retrying.',
				count( $prepared['unknown'] ),
				implode( ', ', $prepared['unknown'] )
			);
		}

		return $result;
	}

	/**
	 * Resolve the section, apply the copy, re-validate. Shared by run and dry_run
	 * so a preview cannot diverge from what the write would do.
	 *
	 * @param array $args Validated arguments.
	 *
	 * @return array{tree?:array,filled?:array,unknown?:array,__fail?:array}
	 */
	private function prepare( array $args ) {
		$store   = $this->store();
		$post_id = (int) $args['post_id'];

		if ( ! $store->owns( $post_id ) ) {
			return array(
				'__fail' => $this->fail(
					'not_a_styble_page',
					sprintf( 'Page %d was not created by Styble AI.', $post_id )
				),
			);
		}

		$state    = $store->get( $post_id );
		$sections = isset( $state['sections'] ) && is_array( $state['sections'] ) ? $state['sections'] : array();
		$target   = null;
		$ids      = array();

		foreach ( $sections as $section ) {
			$ids[] = isset( $section['id'] ) ? (string) $section['id'] : '';
			if ( isset( $section['id'] ) && (string) $section['id'] === (string) $args['section_id'] ) {
				$target = $section;
			}
		}

		if ( null === $target ) {
			return array(
				'__fail' => $this->fail(
					'unknown_section',
					sprintf(
						'No section "%s" on page %d. Sections here: %s.',
						$args['section_id'],
						$post_id,
						$ids ? implode( ', ', array_filter( $ids ) ) : 'none'
					)
				),
			);
		}

		if ( empty( $target['tree'] ) || ! is_array( $target['tree'] ) ) {
			return array(
				'__fail' => $this->fail(
					'section_not_built',
					sprintf( 'Section "%s" has no tree yet — insert a pattern or generate it first.', $args['section_id'] )
				),
			);
		}

		if ( empty( $args['copy'] ) || ! is_array( $args['copy'] ) ) {
			return array(
				'__fail' => $this->fail( 'no_copy', '`copy` must be a non-empty object of slot path => text.' ),
			);
		}

		$filled = $this->library()->fill( $target['tree'], $args['copy'] );

		if ( empty( $filled['ok'] ) ) {
			// The validator's own message, unedited. It names the code and the path,
			// which is what the model needs to fix its own copy — usually an empty
			// string where content is required.
			return array(
				'__fail' => $this->fail( 'invalid_after_fill', $filled['error'] ),
			);
		}

		return array(
			'tree'    => $filled['tree'],
			'filled'  => $filled['filled'],
			'unknown' => $filled['unknown'],
		);
	}
}

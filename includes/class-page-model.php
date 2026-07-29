<?php
/**
 * The canonical page model: every block on a generated page, addressable by uid.
 *
 * This is the deck's Phase 1 centrepiece and the thing the fork skipped — except
 * that most of it turned out to be present already. Styble_AI_Page_Applier mints
 * each block's `uniqueId` from `md5(sectionId|nodePath)` so that regenerating one
 * section cannot renumber another's scoped CSS. That derivation is deterministic,
 * which means it is also *reversible* by recomputation: walk the stored trees,
 * recompute each node's uid, and you have a uid -> node index. No new data
 * structure, no migration, no second source of truth.
 *
 * So the model is a projection of `_styble_ai_page`, not a replacement for it.
 * The trees in post meta stay authoritative; this class only addresses into them
 * and writes back through Styble_AI_Page_Store, which re-derives post_content.
 *
 * What it is for: granular editing. Today "Edit with AI" regenerates a whole
 * selection, which the deck calls the naive approach and which costs a full
 * section's tokens to change one colour. With an addressable model, an edit
 * becomes update_block(uid, attrs) — the model sends a handful of attributes and
 * gets back a diff.
 *
 * Two rules it does not bend:
 *
 *  - **Never coerces.** A merge that would not validate is refused whole, with
 *    the validator's own errors. Same contract as generation: a tree is applied
 *    exactly as validated or not at all.
 *  - **Never returns markup.** Callers get nodes, attributes and diffs. The
 *    appliers own serialization.
 *
 * Blocks whose catalog entry has no `uniqueIdPrefix` never call useUniqueId() in
 * Styble Pro, so they carry no uid and are not addressable. That is a property of
 * those blocks, not a gap here — nothing can address them in the editor either.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Page_Model {

	/**
	 * @var Styble_AI_Catalog
	 */
	private $catalog;

	/**
	 * @var Styble_AI_Page_Store
	 */
	private $store;

	/**
	 * @var Styble_AI_Validator
	 */
	private $validator;

	/**
	 * @param Styble_AI_Catalog    $catalog Block catalog.
	 * @param Styble_AI_Page_Store $store   Page store.
	 */
	public function __construct( Styble_AI_Catalog $catalog, Styble_AI_Page_Store $store ) {
		$this->catalog   = $catalog;
		$this->store     = $store;
		$this->validator = new Styble_AI_Validator( $catalog );
	}

	/**
	 * Every addressable block on the page, in document order.
	 *
	 * Cheap enough to build per request: a page is at most 8 sections of at most
	 * 200 nodes, and the walk is one md5 per node.
	 *
	 * @param int $post_id Page id.
	 *
	 * @return array uid => { uid, block, section, path, depth, attrs }
	 */
	public function index( $post_id ) {
		$state = $this->store->get( $post_id );
		$index = array();

		foreach ( $state['sections'] as $section ) {
			if ( empty( $section['tree']['root'] ) || ! is_array( $section['tree']['root'] ) ) {
				continue; // Planned but not built yet.
			}
			$this->walk(
				$section['tree']['root'],
				(string) $section['id'],
				'r',
				0,
				$index
			);
		}

		return $index;
	}

	/**
	 * One block, by uid.
	 *
	 * @param int    $post_id Page id.
	 * @param string $uid     Block uniqueId.
	 *
	 * @return array|WP_Error { uid, block, section, path, attrs, children } — children
	 *                        as uids, not nested nodes, so a caller cannot mistake
	 *                        this for something it can serialize.
	 */
	public function get_block( $post_id, $uid ) {
		$located = $this->locate( $post_id, $uid );
		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$node = $located['node'];

		$child_uids = array();
		foreach ( ( isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array() ) as $i => $child ) {
			$child      = (array) $child;
			$child_uid  = $this->uid_for( $child, $located['section'], $located['path'] . '.' . $i );
			if ( '' !== $child_uid ) {
				$child_uids[] = $child_uid;
			}
		}

		return array(
			'uid'      => $uid,
			'block'    => $node['block'],
			'section'  => $located['section'],
			'path'     => $located['path'],
			'attrs'    => isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array(),
			'children' => $child_uids,
			'editable' => array_keys( $this->catalog->editable_attrs( $node['block'] ) ),
		);
	}

	/**
	 * Merge attributes into one block, validate the whole section, and save.
	 *
	 * The validator runs on the section's complete envelope rather than the single
	 * node, because most of the interesting rules are cross-field: a container's
	 * layout has to agree with its column count, an image background needs its
	 * description and its fallback colour. Validating a node in isolation would
	 * pass edits that break the section around it.
	 *
	 * Nothing is written unless the merged tree validates. On rejection the stored
	 * page is exactly as it was.
	 *
	 * @param int    $post_id Page id.
	 * @param string $uid     Block uniqueId.
	 * @param array  $attrs   Attributes to merge. A null value removes the
	 *                        attribute, which is how a caller reverts one to the
	 *                        block's own default.
	 *
	 * @return array|WP_Error { ok, uid, block, changed[], warnings[] }
	 */
	public function update_block( $post_id, $uid, array $attrs ) {
		if ( ! $attrs ) {
			return new WP_Error(
				'styble_ai_no_attrs',
				'update_block was given no attributes to change.',
				array( 'status' => 400 )
			);
		}

		$located = $this->locate( $post_id, $uid );
		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$before  = isset( $located['node']['attrs'] ) && is_array( $located['node']['attrs'] ) ? $located['node']['attrs'] : array();
		$after   = $before;
		$changed = array();

		foreach ( $attrs as $key => $value ) {
			$had = array_key_exists( $key, $before );

			if ( null === $value ) {
				if ( $had ) {
					unset( $after[ $key ] );
					$changed[] = array( 'attr' => $key, 'from' => $before[ $key ], 'to' => null );
				}
				continue;
			}

			// Only report a change when something actually changed. A caller that
			// re-sends an identical value has not edited anything, and reporting it
			// as an edit would make a collateral count meaningless.
			if ( $had && $before[ $key ] === $value ) {
				continue;
			}

			$after[ $key ] = $value;
			$changed[]     = array( 'attr' => $key, 'from' => $had ? $before[ $key ] : null, 'to' => $value );
		}

		if ( ! $changed ) {
			return array(
				'ok'       => true,
				'uid'      => $uid,
				'block'    => $located['node']['block'],
				'changed'  => array(),
				'warnings' => array( 'Nothing changed; the block already had those values.' ),
			);
		}

		// Rebuild the section tree with the merged attributes in place.
		$tree = $this->with_attrs_at( $located['tree'], $located['path'], $after );

		$result = $this->validator->validate( $tree );
		if ( ! $result->is_valid() ) {
			return new WP_Error(
				'styble_ai_invalid_edit',
				$this->rejection_message( $located['node']['block'], $result->errors() ),
				array(
					'status' => 422,
					'uid'    => $uid,
					'errors' => $result->errors(),
				)
			);
		}

		$saved = $this->store->set_section_tree( $post_id, $located['section'], $tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'ok'       => true,
			'uid'      => $uid,
			'block'    => $located['node']['block'],
			'changed'  => $changed,
			'warnings' => array(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Resolve a uid to its section, path, node and section tree.
	 *
	 * @param int    $post_id Page id.
	 * @param string $uid     Block uniqueId.
	 *
	 * @return array|WP_Error { section, path, node, tree }
	 */
	private function locate( $post_id, $uid ) {
		$uid   = (string) $uid;
		$state = $this->store->get( $post_id );

		foreach ( $state['sections'] as $section ) {
			if ( empty( $section['tree']['root'] ) || ! is_array( $section['tree']['root'] ) ) {
				continue;
			}

			$found = array();
			$this->walk( $section['tree']['root'], (string) $section['id'], 'r', 0, $found );

			if ( ! isset( $found[ $uid ] ) ) {
				continue;
			}

			return array(
				'section' => (string) $section['id'],
				'path'    => $found[ $uid ]['path'],
				'node'    => $this->node_at( $section['tree'], $found[ $uid ]['path'] ),
				'tree'    => $section['tree'],
			);
		}

		return new WP_Error(
			'styble_ai_no_block',
			sprintf( 'No block with uniqueId "%s" on this page.', $uid ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Index a subtree, recomputing each node's uid as the applier would.
	 *
	 * @param array  $node    Tree node.
	 * @param string $section Section id.
	 * @param string $path    Node path.
	 * @param int    $depth   Depth, root is 0.
	 * @param array  $index   Accumulator, by reference.
	 *
	 * @return void
	 */
	private function walk( array $node, $section, $path, $depth, array &$index ) {
		if ( empty( $node['block'] ) || ! is_string( $node['block'] ) ) {
			return;
		}

		$uid = $this->uid_for( $node, $section, $path );
		if ( '' !== $uid ) {
			$index[ $uid ] = array(
				'uid'     => $uid,
				'block'   => $node['block'],
				'section' => $section,
				'path'    => $path,
				'depth'   => $depth,
				'attrs'   => isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array(),
			);
		}

		foreach ( ( isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array() ) as $i => $child ) {
			if ( is_array( $child ) ) {
				$this->walk( $child, $section, $path . '.' . $i, $depth + 1, $index );
			}
		}
	}

	/**
	 * The uid a node would be given, or '' when its block carries none.
	 *
	 * @param array  $node    Tree node.
	 * @param string $section Section id.
	 * @param string $path    Node path.
	 *
	 * @return string
	 */
	private function uid_for( array $node, $section, $path ) {
		if ( empty( $node['block'] ) || ! is_string( $node['block'] ) ) {
			return '';
		}

		$prefix = $this->catalog->unique_id_prefix( $node['block'] );
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			return '';
		}

		return Styble_AI_Page_Applier::unique_id( $prefix, $section, $path );
	}

	/**
	 * Read the node at a path.
	 *
	 * @param array  $tree Section envelope.
	 * @param string $path Node path ("r", "r.0", "r.0.1").
	 *
	 * @return array
	 */
	private function node_at( array $tree, $path ) {
		$node = $tree['root'];
		foreach ( self::steps( $path ) as $i ) {
			$node = (array) $node['children'][ $i ];
		}
		return $node;
	}

	/**
	 * A copy of the tree with one node's attributes replaced.
	 *
	 * @param array  $tree  Section envelope.
	 * @param string $path  Node path.
	 * @param array  $attrs Replacement attributes.
	 *
	 * @return array
	 */
	private function with_attrs_at( array $tree, $path, array $attrs ) {
		$steps = self::steps( $path );

		// Walk down collecting references, then assign. PHP's reference semantics
		// on nested arrays make the recursive version easy to get subtly wrong, so
		// this is deliberately iterative and explicit.
		$cursor = &$tree['root'];
		foreach ( $steps as $i ) {
			$cursor = &$cursor['children'][ $i ];
		}
		$cursor['attrs'] = $attrs;
		unset( $cursor );

		return $tree;
	}

	/**
	 * Child indexes from a path. "r" is the root, so it contributes nothing.
	 *
	 * @param string $path Node path.
	 *
	 * @return int[]
	 */
	private static function steps( $path ) {
		$parts = explode( '.', (string) $path );
		array_shift( $parts ); // "r"

		return array_map( 'intval', $parts );
	}

	/**
	 * Why an edit was refused, in the same voice as a rejected generation.
	 *
	 * @param string $block  Block name.
	 * @param array  $errors Validator errors.
	 *
	 * @return string
	 */
	private function rejection_message( $block, array $errors ) {
		$shown = array_slice( $errors, 0, 3 );
		$parts = array();
		foreach ( $shown as $error ) {
			$parts[] = $error['path'] . ' — ' . $error['message'];
		}

		$message = sprintf( 'That edit would make the section invalid, so nothing was changed on %s. ', $block )
			. implode( ' ', $parts );

		$extra = count( $errors ) - count( $shown );
		if ( $extra > 0 ) {
			$message .= sprintf( ' (%d more problem%s.)', $extra, 1 === $extra ? '' : 's' );
		}

		return $message;
	}
}

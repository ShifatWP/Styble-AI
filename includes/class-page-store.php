<?php
/**
 * The chat's page state: which sections a generated page has, and the validated
 * tree behind each one.
 *
 * post_content is the deliverable, but it is not a good source of truth to plan
 * against — reading a brief back out of serialized blocks is guesswork. So the
 * plan and the trees live in post meta, and post_content is derived from them
 * every time a section lands. Editing the page in Gutenberg therefore wins on
 * the page itself, and the chat's next revision starts from the plan it wrote,
 * not from a re-reading of the user's edits.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Page_Store {

	/**
	 * Meta key holding the JSON page state.
	 */
	const META_KEY = '_styble_ai_page';

	/**
	 * @var Styble_AI_Page_Applier
	 */
	private $applier;

	/**
	 * @param Styble_AI_Page_Applier $applier Headless applier.
	 */
	public function __construct( Styble_AI_Page_Applier $applier ) {
		$this->applier = $applier;
	}

	/**
	 * Create the draft page a plan will be built into.
	 *
	 * The page exists before its first section does, so the chat has something
	 * to preview and link to while the build is still running.
	 *
	 * @param string $title Page title.
	 *
	 * @return int|WP_Error Post id.
	 */
	public function create( $title ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => wp_slash( $title ),
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->write_state(
			$post_id,
			array(
				'title'    => $title,
				'sections' => array(),
			)
		);

		return (int) $post_id;
	}

	/**
	 * Store the page state.
	 *
	 * wp_slash() is not decoration: update_post_meta() unslashes what it is
	 * given, and this JSON is full of backslashes — every `\"` inside a copy
	 * string and every `\/` in a URL. Storing it raw silently corrupts the state
	 * the moment a brief contains a quote.
	 *
	 * @param int   $post_id Post id.
	 * @param array $state   { title, sections }.
	 *
	 * @return void
	 */
	private function write_state( $post_id, array $state ) {
		update_post_meta( $post_id, self::META_KEY, wp_slash( wp_json_encode( $state ) ) );
	}

	/**
	 * Was this page made by Styble AI? Guards every mutation, so the chat can
	 * never be pointed at an unrelated post id.
	 *
	 * @param int $post_id Post id.
	 *
	 * @return bool
	 */
	public function owns( $post_id ) {
		return '' !== (string) get_post_meta( $post_id, self::META_KEY, true )
			&& 'page' === get_post_type( $post_id );
	}

	/**
	 * @param int $post_id Post id.
	 *
	 * @return array { title, sections } — empty arrays when there is no state.
	 */
	public function get( $post_id ) {
		$raw   = (string) get_post_meta( $post_id, self::META_KEY, true );
		$state = $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'title'    => isset( $state['title'] ) ? (string) $state['title'] : '',
			'sections' => isset( $state['sections'] ) && is_array( $state['sections'] ) ? $state['sections'] : array(),
		);
	}

	/**
	 * Replace the plan, keeping any trees the planner marked for reuse.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $title    Page title.
	 * @param array  $sections Sections from the planner.
	 *
	 * @return void
	 */
	public function set_plan( $post_id, $title, array $sections ) {
		$this->write_state(
			$post_id,
			array(
				'title'    => $title,
				'sections' => $sections,
			)
		);

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => wp_slash( $title ),
			)
		);

		$this->rebuild( $post_id );
	}

	/**
	 * Attach a generated tree to one section and re-derive post_content.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $section_id Section id.
	 * @param array  $tree       Validated emit_layout envelope.
	 *
	 * @return true|WP_Error
	 */
	public function set_section_tree( $post_id, $section_id, array $tree ) {
		$state = $this->get( $post_id );
		$found = false;

		foreach ( $state['sections'] as $i => $section ) {
			if ( $section['id'] === $section_id ) {
				$state['sections'][ $i ]['tree'] = $tree;
				$found                           = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error(
				'styble_ai_no_section',
				'That section is no longer part of the page.',
				array( 'status' => 404 )
			);
		}

		$this->write_state( $post_id, $state );

		return $this->rebuild( $post_id );
	}

	/**
	 * Serialize every built section into post_content.
	 *
	 * @param int $post_id Post id.
	 *
	 * @return true|WP_Error
	 */
	public function rebuild( $post_id ) {
		$state = $this->get( $post_id );

		try {
			$markup = $this->applier->to_markup( $state['sections'] );
		} catch ( RuntimeException $e ) {
			return new WP_Error(
				'styble_ai_apply_failed',
				'The generated layout could not be turned into blocks. ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}

		$invalid = $this->markup_problem( $markup );
		if ( $invalid ) {
			return new WP_Error( 'styble_ai_invalid_markup', $invalid, array( 'status' => 500 ) );
		}

		// Two things this write has to get right:
		//
		// wp_slash() — wp_insert_post() unslashes what it is given, and this
		// markup is JSON inside HTML comments, so every `\"` and `\/` in it
		// would be eaten and every block would parse as invalid.
		//
		// kses — for a user without unfiltered_html the content_save_pre filter
		// strips HTML comments, which is the entire block markup. The content is
		// built here from a validated tree, never from user HTML, so the filters
		// come off for this write. kses_init() restores them exactly as they
		// were, honouring the current user's capability; kses_init_filters()
		// would add them for users who never had them.
		kses_remove_filters();
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $markup ),
			),
			true
		);
		kses_init();

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Re-parse what we just serialized and refuse anything WordPress would treat
	 * as classic content — the same guardrail the editor path has always had.
	 *
	 * @param string $markup Serialized blocks.
	 *
	 * @return string Problem description, or '' when the markup is sound.
	 */
	private function markup_problem( $markup ) {
		if ( '' === trim( $markup ) ) {
			return '';
		}

		foreach ( parse_blocks( $markup ) as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

			if ( 'core/freeform' === $name ) {
				return 'The generated markup fell back to the classic editor, which means it is not valid block markup.';
			}
			if ( null === $name && '' !== trim( (string) $block['innerHTML'] ) ) {
				return 'The generated markup contained content outside any block.';
			}
		}

		return '';
	}
}

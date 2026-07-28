<?php
/**
 * REST routes behind the AI Chat screen.
 *
 *   POST /styble-ai/v1/chat/plan      message -> draft page + section briefs
 *   POST /styble-ai/v1/chat/section   build one planned section
 *
 * Two routes rather than one because a page is 5–7 model calls. Doing them in a
 * single request means a two-minute POST that PHP or the browser may cut in
 * half, with no way to show what was finished. One call per request keeps every
 * request short, lets the chat report progress section by section, and means a
 * failure costs one section instead of the page.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Chat_Controller {

	/**
	 * Built once per request: constructing it reads and decodes the catalog, and
	 * a single route touches it three times.
	 *
	 * @var Styble_AI_Page_Store|WP_Error|null
	 */
	private $store = null;

	/**
	 * @var Styble_AI_Catalog|WP_Error|null
	 */
	private $catalog = null;

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'styble-ai/v1',
			'/chat/plan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'plan' ),
				'permission_callback' => array( $this, 'can_edit_pages' ),
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'pageId'  => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					),
				),
			)
		);

		register_rest_route(
			'styble-ai/v1',
			'/chat/section',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'section' ),
				'permission_callback' => array( $this, 'can_edit_pages' ),
				'args'                => array(
					'pageId'    => array(
						'required' => true,
						'type'     => 'integer',
					),
					'sectionId' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function can_edit_pages() {
		return current_user_can( 'edit_pages' );
	}

	/**
	 * Plan a page, and create or re-plan its draft.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function plan( WP_REST_Request $request ) {
		$message = trim( (string) $request->get_param( 'message' ) );
		$page_id = (int) $request->get_param( 'pageId' );

		if ( '' === $message ) {
			return new WP_Error( 'styble_ai_empty', 'Tell me what page to build.', array( 'status' => 400 ) );
		}

		$store = $this->store();
		if ( is_wp_error( $store ) ) {
			return $store;
		}

		$existing = array();
		if ( $page_id ) {
			$guard = $this->guard_page( $page_id );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$existing = $store->get( $page_id );
		}

		$planner = new Styble_AI_Page_Planner( Styble_AI_Provider_Factory::make() );
		$plan    = $planner->plan( $message, $existing );
		if ( is_wp_error( $plan ) ) {
			return $this->with_status( $plan );
		}

		if ( ! $page_id ) {
			$page_id = $store->create( $plan['title'] );
			if ( is_wp_error( $page_id ) ) {
				return $this->with_status( $page_id );
			}
		}

		$store->set_plan( $page_id, $plan['title'], $plan['sections'] );

		return rest_ensure_response(
			array_merge(
				$this->page_payload( $page_id, $store ),
				array( 'reply' => $plan['reply'] )
			)
		);
	}

	/**
	 * Generate one planned section and fold it into the page.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function section( WP_REST_Request $request ) {
		$page_id    = (int) $request->get_param( 'pageId' );
		$section_id = (string) $request->get_param( 'sectionId' );

		$guard = $this->guard_page( $page_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$store = $this->store();
		if ( is_wp_error( $store ) ) {
			return $store;
		}

		$catalog = $this->catalog();
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$state   = $store->get( $page_id );
		$section = null;
		foreach ( $state['sections'] as $candidate ) {
			if ( $candidate['id'] === $section_id ) {
				$section = $candidate;
				break;
			}
		}

		if ( ! $section ) {
			return new WP_Error(
				'styble_ai_no_section',
				'That section is no longer part of the page.',
				array( 'status' => 404 )
			);
		}

		$generator = new Styble_AI_Generator( $catalog, Styble_AI_Provider_Factory::make() );
		$result    = $generator->generate( $this->section_request( $state, $section ) );
		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		$saved = $store->set_section_tree( $page_id, $section_id, $result['tree'] );
		if ( is_wp_error( $saved ) ) {
			return $this->with_status( $saved );
		}

		return rest_ensure_response(
			array_merge(
				$this->page_payload( $page_id, $store ),
				array(
					'sectionId' => $section_id,
					'attempts'  => $result['attempts'],
				)
			)
		);
	}

	/**
	 * The request handed to the section generator.
	 *
	 * The whole outline goes in, not just this section's brief: without it the
	 * model writes an intro paragraph into every section, because each call
	 * looks like the only one.
	 *
	 * @param array $state   Page state.
	 * @param array $section The section to build.
	 *
	 * @return string
	 */
	private function section_request( array $state, array $section ) {
		$outline = array();
		foreach ( $state['sections'] as $s ) {
			$outline[] = ( $s['id'] === $section['id'] )
				? '- ' . $s['heading'] . '  <- the section you are building now'
				: '- ' . $s['heading'];
		}

		return implode(
			"\n",
			array(
				'This section belongs to a page titled "' . $state['title'] . '".',
				'',
				'The page reads, top to bottom:',
				implode( "\n", $outline ),
				'',
				'Build ONLY that one section: ' . $section['heading'],
				'',
				$section['brief'],
				'',
				'Do not repeat what the other sections cover, and do not add a page heading unless this section is the one at the top.',
			)
		);
	}

	/**
	 * What the chat needs to render the page's current state.
	 *
	 * @param int                  $page_id Post id.
	 * @param Styble_AI_Page_Store $store   Page store.
	 *
	 * @return array
	 */
	private function page_payload( $page_id, Styble_AI_Page_Store $store ) {
		$state    = $store->get( $page_id );
		$sections = array();

		foreach ( $state['sections'] as $section ) {
			$sections[] = array(
				'id'      => $section['id'],
				'heading' => $section['heading'],
				'built'   => ! empty( $section['tree'] ),
			);
		}

		$post = get_post( $page_id );

		return array(
			'pageId'     => $page_id,
			'title'      => $state['title'],
			'sections'   => $sections,
			'previewUrl' => $post ? get_preview_post_link( $post ) : '',
			'editUrl'    => get_edit_post_link( $page_id, 'raw' ),
			'status'     => $post ? $post->post_status : '',
		);
	}

	/**
	 * The page must exist, be ours, and be editable by this user.
	 *
	 * @param int $page_id Post id.
	 *
	 * @return true|WP_Error
	 */
	private function guard_page( $page_id ) {
		if ( ! $page_id || ! get_post( $page_id ) ) {
			return new WP_Error( 'styble_ai_no_page', 'That page no longer exists.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			return new WP_Error( 'styble_ai_forbidden', 'You cannot edit that page.', array( 'status' => 403 ) );
		}

		$store = $this->store();
		if ( is_wp_error( $store ) ) {
			return $store;
		}
		if ( ! $store->owns( $page_id ) ) {
			return new WP_Error(
				'styble_ai_not_ours',
				'That page was not created by Styble AI, so the chat will not overwrite it.',
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * @return Styble_AI_Page_Store|WP_Error
	 */
	private function store() {
		if ( null !== $this->store ) {
			return $this->store;
		}

		$catalog = $this->catalog();

		$this->store = is_wp_error( $catalog )
			? $catalog
			: new Styble_AI_Page_Store( new Styble_AI_Page_Applier( $catalog ) );

		return $this->store;
	}

	/**
	 * @return Styble_AI_Catalog|WP_Error
	 */
	private function catalog() {
		if ( null !== $this->catalog ) {
			return $this->catalog;
		}

		try {
			$this->catalog = Styble_AI_Catalog::from_file();
		} catch ( RuntimeException $e ) {
			$this->catalog = $this->no_catalog( $e );
		}

		return $this->catalog;
	}

	/**
	 * @param RuntimeException $e Catalog failure.
	 *
	 * @return WP_Error
	 */
	private function no_catalog( RuntimeException $e ) {
		return new WP_Error(
			'styble_ai_no_catalog',
			'Styble AI cannot read its block catalog. ' . $e->getMessage(),
			array( 'status' => 500 )
		);
	}

	/**
	 * Give every failure an HTTP status and keep the validator's per-error list,
	 * so the chat can list reasons instead of showing a blank failure.
	 *
	 * @param WP_Error $error Error from the pipeline.
	 *
	 * @return WP_Error
	 */
	private function with_status( WP_Error $error ) {
		$data           = is_array( $error->get_error_data() ) ? $error->get_error_data() : array();
		$data['status'] = isset( $data['status'] ) ? (int) $data['status'] : 502;

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
}

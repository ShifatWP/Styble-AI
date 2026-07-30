<?php
/**
 * Page planner: a chat message in, an ordered list of section briefs out.
 *
 * Generating a whole page in one tool call does not work — the tree is deep, the
 * output is long, and a truncated or malformed call costs the entire page. So a
 * page is planned first and built one section at a time, which is also what
 * makes progress visible in the chat and keeps every LLM call inside a normal
 * request timeout.
 *
 * The planner writes no blocks and no attributes. It only decides what sections
 * the page has and what each one is for; Styble_AI_Generator turns each brief
 * into a validated tree with the contract the rest of the plugin already
 * enforces.
 *
 * Follow-up turns pass the current outline back in. The planner then returns the
 * FULL new outline with `reuse: true` on the sections it wants left alone, so
 * "add a FAQ" costs one section call rather than a whole page.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Page_Planner {

	const TOOL_NAME = 'plan_page';

	/**
	 * Guardrail from the migration plan: at most 8 sections per page.
	 */
	const MAX_SECTIONS = 8;

	/**
	 * @var object Provider exposing complete( array $spec ).
	 */
	private $provider;

	/**
	 * @param object $provider Provider with a complete() method.
	 */
	public function __construct( $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Plan a page, or revise an existing plan.
	 *
	 * @param string $message  What the user typed.
	 * @param array  $existing Current page state, or [] for a new page.
	 *
	 * @return array|WP_Error { reply, title, sections[] } or an error.
	 */
	public function plan( $message, array $existing = array() ) {
		$spec = array(
			'operation' => 'plan',
			'system'   => $this->system_prompt(),
			'tool'     => array(
				'name'         => self::TOOL_NAME,
				'description'  => 'Plan a Styble page as an ordered list of section briefs. You never write blocks or HTML.',
				'input_schema' => $this->tool_schema(),
			),
			'messages' => array(
				array(
					'role'  => 'user',
					'text'  => $this->user_message( $message, $existing ),
					'image' => '',
				),
			),
		);

		$plan = $this->provider->complete( $spec );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		return $this->normalize( $plan, $existing );
	}

	/**
	 * @return array JSON Schema for the plan_page tool input.
	 */
	private function tool_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'reply'    => array(
					'type'        => 'string',
					'description' => 'One or two sentences addressed to the user, describing the page you are about to build. No markdown, no lists.',
				),
				'title'    => array(
					'type'        => 'string',
					'description' => 'The page title, e.g. "Pricing".',
				),
				'sections' => array(
					'type'        => 'array',
					'description' => 'The page, top to bottom. At most ' . self::MAX_SECTIONS . ' sections.',
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'      => array(
								'type'        => 'string',
								'description' => 'Stable kebab-case id, e.g. "hero" or "plans". Reuse the existing id when revising a section that is already on the page.',
							),
							'heading' => array(
								'type'        => 'string',
								'description' => 'Short human label for this section, shown as build progress.',
							),
							'brief'   => array(
								'type'        => 'string',
								'description' => 'What this section must contain: its purpose, how many columns, which elements, and the gist of the copy. One paragraph. This is handed verbatim to the section generator.',
							),
							'reuse'   => array(
								'type'        => 'boolean',
								'description' => 'True only when this section already exists on the page and must be left exactly as it is. Omit or set false to (re)generate it.',
							),
						),
						'required'             => array( 'id', 'heading', 'brief' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'reply', 'title', 'sections' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * @return string
	 */
	private function system_prompt() {
		$lines = array(
			'You plan pages for Styble, a WordPress block builder. The user asks for a page in plain words; you return the plan by calling the ' . self::TOOL_NAME . ' tool. Always call the tool; never reply with prose or markup.',
			'',
			'# What a section is',
			'',
			'Each section becomes ONE full-width Styble container with columns inside it — a hero, a features row, a pricing table, a testimonial band, an FAQ, a call to action. A separate generator turns each brief into real blocks, so you describe intent and content, never blocks, attributes, HTML or CSS.',
			'',
			'# Rules',
			'',
			'- Plan ' . self::MAX_SECTIONS . ' sections at most. Most pages need 4 to 6. A page that says everything twice is worse than a short one.',
			'- Order them the way the page reads, top to bottom.',
			'- Every brief must be specific to THIS business or topic: name the actual plans, the actual features, the actual claims. A brief that would fit any company produces a section that says nothing.',
			'- Say how many columns a section wants and what goes in each one.',
			'- Never plan a site header, navigation menu or footer — the theme provides those.',
			'- Ids are kebab-case and stable. When revising a page, keep the id of any section that stays.',
			'- Images are placeholders the user fills in later, so describe the intended picture rather than asking for a specific file.',
			'',
			'# Revising an existing page',
			'',
			'- Return the FULL section list every time, in final order, including sections that do not change.',
			'- Set reuse=true on every section that must stay exactly as it is. Only sections with reuse=false (or absent) are rebuilt, so marking an untouched section as changed throws away work the user already accepted.',
			'- Removing a section means leaving it out of the list.',
		);

		$brand = Styble_AI_Brand_Context::summary();
		if ( '' !== $brand ) {
			$lines[] = '';
			$lines[] = '# Brand context';
			$lines[] = '';
			$lines[] = $brand;
		}

		return implode( "\n", $lines );
	}

	/**
	 * The user turn, with the current outline attached on follow-ups.
	 *
	 * @param string $message  User message.
	 * @param array  $existing Current page state.
	 *
	 * @return string
	 */
	private function user_message( $message, array $existing ) {
		if ( empty( $existing['sections'] ) ) {
			return $message;
		}

		$lines = array(
			'The page "' . ( isset( $existing['title'] ) ? $existing['title'] : '' ) . '" currently has these sections, in order:',
			'',
		);
		foreach ( $existing['sections'] as $section ) {
			$built   = empty( $section['tree'] ) ? ' (not built yet)' : '';
			$lines[] = sprintf(
				'- %s — %s: %s%s',
				$section['id'],
				$section['heading'],
				$section['brief'],
				$built
			);
		}
		$lines[] = '';
		$lines[] = 'The user now says:';
		$lines[] = '';
		$lines[] = $message;

		return implode( "\n", $lines );
	}

	/**
	 * Check and clean what the model sent.
	 *
	 * A plan is cheap to re-ask for but expensive to half-apply, so anything
	 * structurally wrong is rejected outright rather than patched.
	 *
	 * @param mixed $plan     Raw tool input.
	 * @param array $existing Current page state.
	 *
	 * @return array|WP_Error
	 */
	private function normalize( $plan, array $existing ) {
		if ( ! is_array( $plan ) || empty( $plan['sections'] ) || ! is_array( $plan['sections'] ) ) {
			return new WP_Error(
				'styble_ai_bad_plan',
				'The model did not return a usable page plan.',
				array( 'status' => 422 )
			);
		}

		$known = array();
		foreach ( ( isset( $existing['sections'] ) ? $existing['sections'] : array() ) as $section ) {
			$known[ $section['id'] ] = $section;
		}

		$sections = array();
		$seen     = array();

		foreach ( array_slice( $plan['sections'], 0, self::MAX_SECTIONS ) as $i => $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['brief'] ) ) {
				continue;
			}

			$id = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = 'section-' . ( $i + 1 );
			}
			$seen[ $id ] = true;

			// A section may only be reused if it exists AND was actually built —
			// otherwise "reuse" would silently leave a hole in the page.
			$reuse = ! empty( $raw['reuse'] ) && ! empty( $known[ $id ]['tree'] );

			$sections[] = array(
				'id'      => $id,
				'heading' => isset( $raw['heading'] ) ? sanitize_text_field( $raw['heading'] ) : $id,
				'brief'   => sanitize_textarea_field( $raw['brief'] ),
				'tree'    => $reuse ? $known[ $id ]['tree'] : null,
			);
		}

		if ( ! $sections ) {
			return new WP_Error(
				'styble_ai_bad_plan',
				'The page plan contained no usable sections.',
				array( 'status' => 422 )
			);
		}

		$title = isset( $plan['title'] ) ? sanitize_text_field( $plan['title'] ) : '';
		if ( '' === $title ) {
			$title = isset( $existing['title'] ) ? $existing['title'] : __( 'Untitled page', 'styble-ai' );
		}

		return array(
			'reply'    => isset( $plan['reply'] ) ? sanitize_textarea_field( $plan['reply'] ) : '',
			'title'    => $title,
			'sections' => $sections,
		);
	}
}

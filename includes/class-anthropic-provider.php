<?php
/**
 * LLM provider — Anthropic (Claude) Messages API.
 *
 * We force structured output by defining a "tool" whose input_schema is our IR
 * and setting tool_choice to that tool. The model must return valid JSON in the
 * tool_use block's `input` field. (Do NOT enable extended thinking together with
 * forced tool_choice — the API rejects that combination.)
 *
 * This is the swappable layer. Two entry points share the same contract:
 *   generate($prompt, $context)              -> IR for new sections.
 *   edit($prompt, $context, $selection)      -> revised IR for selected blocks.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Anthropic_Provider {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const VERSION  = '2023-06-01';

	private $api_key;
	private $model;

	public function __construct( $api_key, $model ) {
		$this->api_key = $api_key;
		$this->model   = $model ? $model : 'claude-sonnet-5';
	}

	/**
	 * Generate brand-new sections from a description.
	 *
	 * @param string $prompt  User's description of the section(s) to build.
	 * @param string $context Theme summary from Styble_AI_Theme_Context.
	 * @param string $image   Optional data:image/*;base64 URL of a design reference.
	 * @return array|WP_Error Decoded IR ( ['sections' => [...]] ) or error.
	 */
	public function generate( $prompt, $context, $image = '' ) {
		// With an image, send multimodal content (needs a vision-capable model).
		$content = $prompt;
		if ( $image ) {
			$img = $this->image_block( $image );
			if ( $img ) {
				$content = array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
					$img,
				);
			}
		}

		$body = array(
			'model'       => $this->model,
			'max_tokens'  => 4096,
			'temperature' => 0.7,
			'system'      => $this->system_prompt( $context ),
			'tools'       => array( $this->tool_definition( false ) ),
			'tool_choice' => array(
				'type' => 'tool',
				'name' => 'build_layout',
			),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
		);

		return $this->send( $body );
	}

	/**
	 * Parse a data URL into an Anthropic image content block. Returns null if the
	 * string is not a base64 image data URL.
	 */
	private function image_block( $data_url ) {
		if ( ! preg_match( '#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', (string) $data_url, $m ) ) {
			return null;
		}
		return array(
			'type'   => 'image',
			'source' => array(
				'type'       => 'base64',
				'media_type' => $m[1],
				'data'       => $m[2],
			),
		);
	}

	/**
	 * Revise the currently selected block(s) per an edit instruction.
	 *
	 * @param string $prompt    The edit instruction ("make this punchier", ...).
	 * @param string $context   Theme summary.
	 * @param string $selection Block markup of the current selection (context).
	 * @return array|WP_Error Decoded IR ( ['blocks'=>[...]] or ['sections'=>[...]] ).
	 */
	public function edit( $prompt, $context, $selection ) {
		$user = "The user has selected these existing block(s):\n\n"
			. "```\n" . $selection . "\n```\n\n"
			. 'Apply this edit and return the full revised replacement for that selection: ' . $prompt;

		$body = array(
			'model'       => $this->model,
			'max_tokens'  => 4096,
			'temperature' => 0.5,
			'system'      => $this->edit_system_prompt( $context ),
			'tools'       => array( $this->tool_definition( true ) ),
			'tool_choice' => array(
				'type' => 'tool',
				'name' => 'build_layout',
			),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);

		return $this->send( $body );
	}

	/**
	 * POST the request and pull the forced tool_use input (our IR).
	 */
	private function send( array $body ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'styble_ai_no_key', 'No API key configured. Add one under Settings → Styble AI.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'styble_ai_api_error', 'AI request failed: ' . $msg );
		}

		// Find the tool_use content block and read its input (our IR).
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'tool_use' === $block['type'] && isset( $block['input'] ) ) {
					return is_array( $block['input'] ) ? $block['input'] : array();
				}
			}
		}

		return new WP_Error( 'styble_ai_no_tool_use', 'The model did not return structured layout data.' );
	}

	/**
	 * System prompt for new generation: teaches the block vocabulary + design rules.
	 */
	private function system_prompt( $context ) {
		return implode(
			"\n",
			array(
				'You are a WordPress layout designer. Turn the user request into clean, well-structured page sections using ONLY the schema of the build_layout tool. Always call build_layout.',
				'',
				'Rules:',
				'- Compose one or more "sections". A hero is usually one section; features, testimonials, CTA are separate sections.',
				'- Write real, specific, publishable copy — never lorem ipsum or placeholders like "Your text here".',
				'- Prefer 2 or 3 columns for feature/benefit grids. Keep each column focused.',
				'- Use "tone" to create visual rhythm: alternate default/light, use dark or accent for hero or CTA.',
				'- Use "full" width for hero and CTA bands; "default" for text-heavy content.',
				'- Images are placeholders (no URL); give a clear alt describing the intended photo.',
				'- Keep headings concise. Do not stuff a section with too many blocks.',
				'',
				'Site context (use it to match tone and wording): ' . $context,
			)
		);
	}

	/**
	 * System prompt for contextual editing of an existing selection.
	 */
	private function edit_system_prompt( $context ) {
		return implode(
			"\n",
			array(
				'You are a WordPress layout editor. The user has selected one or more existing blocks and wants to revise them. Return the revised replacement by calling build_layout.',
				'',
				'Rules:',
				'- Return ONLY the replacement for the selection — do not add unrelated sections.',
				'- If the edit stays within content (rewriting copy, adding a list item, adding a column, changing a heading), return a flat "blocks" array so structure is preserved.',
				'- If the edit changes a whole section\'s tone/width or replaces an entire section, return "sections" instead.',
				'- Preserve the parts of the existing content the user did not ask to change; keep real, specific copy (no lorem ipsum).',
				'- Match the number and kind of blocks to the request; do not drop content unless asked.',
				'- Images stay as placeholders (no URL); keep or improve the alt text.',
				'',
				'Site context (use it to match tone and wording): ' . $context,
			)
		);
	}

	/**
	 * The tool whose schema IS our IR. In edit mode it accepts either a flat
	 * "blocks" list or "sections"; in generate mode only "sections".
	 */
	private function tool_definition( $edit ) {
		$block_schema = $this->block_schema();

		$properties = array(
			'sections' => $this->sections_schema( $block_schema ),
		);
		$required = array( 'sections' );

		if ( $edit ) {
			$properties['blocks'] = array(
				'type'        => 'array',
				'description' => 'Flat replacement blocks (preferred for in-place edits). Use this OR "sections", not both.',
				'items'       => $block_schema,
			);
			// Edit mode: model picks blocks or sections, so neither is required.
			$required = array();
		}

		return array(
			'name'         => 'build_layout',
			'description'  => $edit
				? 'Return the revised replacement for the selected blocks as either a flat "blocks" list or "sections".'
				: 'Produce the page layout as a list of sections built from core WordPress blocks.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => $properties,
				'required'   => $required,
			),
		);
	}

	/**
	 * The "sections" array schema (shared by generate + edit).
	 */
	private function sections_schema( $block_schema ) {
		return array(
			'type'        => 'array',
			'description' => 'Ordered list of page sections.',
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'label'  => array(
						'type'        => 'string',
						'description' => 'Short internal name, e.g. "Hero", "Features", "CTA".',
					),
					'tone'   => array(
						'type' => 'string',
						'enum' => array( 'default', 'light', 'dark', 'accent' ),
					),
					'width'  => array(
						'type' => 'string',
						'enum' => array( 'full', 'wide', 'default' ),
					),
					'blocks' => array(
						'type'  => 'array',
						'items' => $block_schema,
					),
				),
				'required'   => array( 'blocks' ),
			),
		);
	}

	/**
	 * Schema for a single leaf/columns block node.
	 */
	private function block_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'     => array(
					'type'        => 'string',
					'enum'        => array( 'heading', 'paragraph', 'buttons', 'list', 'quote', 'image', 'spacer', 'columns' ),
					'description' => 'The block kind.',
				),
				'level'    => array(
					'type'        => 'integer',
					'description' => 'For heading: 1-4.',
				),
				'text'     => array(
					'type'        => 'string',
					'description' => 'For heading, paragraph, quote: the text. May contain <strong>, <em>, <a href>.',
				),
				'citation' => array(
					'type'        => 'string',
					'description' => 'For quote: who said it.',
				),
				'ordered'  => array(
					'type'        => 'boolean',
					'description' => 'For list: true = numbered.',
				),
				'items'    => array(
					'type'        => 'array',
					'description' => 'For list: array of strings. For buttons: array of {label,url,style}.',
					'items'       => array( 'type' => array( 'string', 'object' ) ),
				),
				'alt'      => array(
					'type'        => 'string',
					'description' => 'For image: describe the intended photo.',
				),
				'caption'  => array(
					'type'        => 'string',
					'description' => 'For image: optional caption.',
				),
				'height'   => array(
					'type'        => 'integer',
					'description' => 'For spacer: height in pixels (8-400).',
				),
				'columns'  => array(
					'type'        => 'array',
					'description' => 'For columns: array of { "blocks": [ leaf blocks, no further columns ] }.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'blocks' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
				),
			),
			'required'   => array( 'type' ),
		);
	}
}

<?php
/**
 * LLM provider — Anthropic (Claude) Messages API.
 *
 * We force structured output by defining a "tool" whose input_schema is our IR
 * and setting tool_choice to that tool. The model must return valid JSON in the
 * tool_use block's `input` field. (Do NOT enable extended thinking together with
 * forced tool_choice — the API rejects that combination.)
 *
 * This is the swappable layer. To add OpenAI/Gemini later, implement the same
 * generate($prompt, $context) contract and return the decoded IR array.
 *
 * @package AI_Block_Composer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABC_Anthropic_Provider {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const VERSION  = '2023-06-01';

	private $api_key;
	private $model;

	public function __construct( $api_key, $model ) {
		$this->api_key = $api_key;
		$this->model   = $model ? $model : 'claude-sonnet-5';
	}

	/**
	 * @param string $prompt  User's description of the section(s) to build.
	 * @param string $context Theme summary from ABC_Theme_Context.
	 * @return array|WP_Error Decoded IR ( ['sections' => [...]] ) or error.
	 */
	public function generate( $prompt, $context ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'abc_no_key', 'No API key configured. Add one under Settings → AI Block Composer.' );
		}

		$body = array(
			'model'       => $this->model,
			'max_tokens'  => 4096,
			'temperature' => 0.7,
			'system'      => $this->system_prompt( $context ),
			'tools'       => array( $this->tool_definition() ),
			'tool_choice' => array(
				'type' => 'tool',
				'name' => 'build_layout',
			),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

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
			return new WP_Error( 'abc_api_error', 'AI request failed: ' . $msg );
		}

		// Find the tool_use content block and read its input (our IR).
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'tool_use' === $block['type'] && isset( $block['input'] ) ) {
					return is_array( $block['input'] ) ? $block['input'] : array();
				}
			}
		}

		return new WP_Error( 'abc_no_tool_use', 'The model did not return structured layout data.' );
	}

	/**
	 * System prompt: teaches the model our block vocabulary and design rules.
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
	 * The tool whose input_schema IS our intermediate representation.
	 * Descriptions tell the model which fields belong to which block type.
	 */
	private function tool_definition() {
		$block_schema = array(
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

		return array(
			'name'         => 'build_layout',
			'description'  => 'Produce the page layout as a list of sections built from core WordPress blocks.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'sections' => array(
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
					),
				),
				'required'   => array( 'sections' ),
			),
		);
	}
}

<?php
/**
 * pattern/search-patterns — find a human-designed section for a stated need.
 *
 * Returns names, intents and slot counts. **Never trees.** A tree is thousands of
 * tokens and the model does not need one to choose; it needs enough to pick a slug
 * and hand it to `pattern/insert-pattern`. ZIP AI's page outline makes the same
 * trade — a cheap summary, with detail pulled on demand.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Tool_Search_Patterns extends Styble_AI_Tool {

	/**
	 * @var Styble_AI_Pattern_Library|null
	 */
	private $library = null;

	protected function configure() {
		$this->id          = 'pattern/search-patterns';
		$this->label       = 'Search patterns';
		$this->description = 'Find a ready-made, designer-built section for a stated need '
			. '(e.g. "three pricing tiers", "an FAQ", "a hero with a photo"). '
			. 'Returns candidate patterns with their slug, what each is for, and how many copy slots each has. '
			. 'ALWAYS prefer a pattern over writing a section from scratch: patterns are pre-validated, so they cannot fail, '
			. 'and you only have to write the words. Follow up with pattern/insert-pattern.';
		$this->resource    = 'pattern';
		$this->capability  = 'edit_pages';
	}

	public function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'need'  => array(
					'type'        => 'string',
					'description' => 'What the section has to do, in plain words. "three services as cards", "a closing call to action".',
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Maximum candidates to return.',
					'default'     => 6,
				),
			),
			'required'   => array( 'need' ),
		);
	}

	public function output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'patterns' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'      => array( 'type' => 'string' ),
							'name'      => array( 'type' => 'string' ),
							'intent'    => array( 'type' => 'string' ),
							'slotCount' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
	}

	/**
	 * @param Styble_AI_Pattern_Library $library Library.
	 *
	 * @return void
	 */
	public function set_library( Styble_AI_Pattern_Library $library ) {
		$this->library = $library;
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

	protected function run( array $args ) {
		$library = $this->library();
		$hits    = $library->search( $args['need'], isset( $args['limit'] ) ? $args['limit'] : 6 );

		if ( ! $hits ) {
			return array(
				'ok'       => true,
				'patterns' => array(),
				'message'  => 'The pattern library is empty. Generate the section from scratch instead.',
			);
		}

		// Scores are dropped from the reply: they are a ranking device, not
		// information the model should reason about, and exposing them invites it
		// to argue with the ordering instead of reading the intents.
		$out = array();
		foreach ( $hits as $hit ) {
			$out[] = array(
				'slug'      => $hit['slug'],
				'name'      => $hit['name'],
				'intent'    => $hit['intent'],
				'slotCount' => $hit['slotCount'],
			);
		}

		return array(
			'ok'       => true,
			'patterns' => $out,
			'message'  => 'Pick a slug and call pattern/insert-pattern. Its copy slots come back for you to fill.',
		);
	}
}

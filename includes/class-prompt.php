<?php
/**
 * System prompt and tool schema, both generated from the catalog.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

/**
 * Teaches a model the emit_layout contract.
 *
 * Everything here is derived from catalog/catalog.json, so the prompt and the
 * tool schema cannot drift from the blocks. That is what pays for dropping AI
 * Block Composer's hand-written semantic IR: a new block or attribute is one
 * regeneration away, not a new IR verb plus a new mapper branch.
 *
 * The split matters: the JSON schema describes the tree's *shape*, the system
 * prompt supplies the per-block *vocabulary*, and the validator is what actually
 * enforces correctness. Nothing the model returns is trusted.
 *
 * Why the schema stays generic where it does:
 *
 * - The tree is recursive, and provider-side strict/structured output does not
 *   support recursive schemas — so `strict: true` is off and the node schema is
 *   expanded to a bounded depth instead.
 * - Attributes differ per block, so expressing them in the schema would need a
 *   oneOf branch per block. `attrs` is left free-form and the legal keys are
 *   listed per block in the prompt, then enforced by the validator.
 */
class Styble_AI_Prompt {

	const TOOL_NAME = 'emit_layout';

	/**
	 * How deep the generated node schema nests. Deep enough for the deepest
	 * legal Styble tree (container > column > info-box > advanced-text >
	 * icon-picker) with room to spare.
	 */
	const SCHEMA_DEPTH = 6;

	/**
	 * Attributes the applier computes, per block, and therefore never advertises.
	 *
	 * These stay in the catalog — the validator still needs their types, and the
	 * layout rules need to check `columns` when a model sends it anyway — but the
	 * prompt must not invite the model to set them. Listing an attribute as legal
	 * and then telling the model not to touch it is a contradiction it will
	 * sometimes resolve the wrong way.
	 *
	 * Keyed per block because the same name is not always applier-owned:
	 * `direction` on styble/container follows from the layout, while `direction`
	 * on styble/advanced-buttons is a genuine design choice.
	 */
	const APPLIER_OWNED = array(
		'styble/container' => array( 'columns', 'direction', 'flexWrap', 'layoutSelected' ),
		'styble/column'    => array( 'columnWidth', 'columnFlex' ),
	);

	/**
	 * @var Styble_AI_Catalog
	 */
	private $catalog;

	/**
	 * @param Styble_AI_Catalog $catalog Block catalog.
	 */
	public function __construct( Styble_AI_Catalog $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Tool name the provider must force.
	 *
	 * @return string
	 */
	public function tool_name() {
		return self::TOOL_NAME;
	}

	/**
	 * Tool description shown to the model.
	 *
	 * @return string
	 */
	public function tool_description() {
		return 'Emit one Styble section as a validated JSON block tree. '
			. 'You never write HTML or block markup — only this tree.';
	}

	/**
	 * JSON Schema for the tool input: the emit_layout envelope.
	 *
	 * @return array
	 */
	public function tool_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'version' => array(
					'type'        => 'string',
					'description' => 'Contract version. Always "' . $this->catalog->contract_version() . '".',
				),
				'root'    => $this->node_schema( self::SCHEMA_DEPTH ),
			),
			'required'             => array( 'version', 'root' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * One node, with children nested to the given remaining depth.
	 *
	 * @param int $depth Remaining depth; 1 means no children.
	 *
	 * @return array
	 */
	private function node_schema( $depth ) {
		$properties = array(
			'block' => array(
				'type'        => 'string',
				'enum'        => array_values( $this->catalog->allowlisted_names() ),
				'description' => 'Styble block name.',
			),
			'attrs' => array(
				'type'        => 'object',
				'description' => 'Sparse attributes. Only the keys listed for this block in the system prompt are legal; omit anything you do not mean to change.',
			),
		);

		if ( $depth > 1 ) {
			$properties['children'] = array(
				'type'        => 'array',
				'description' => 'Child blocks, only where the nesting rules allow them.',
				'items'       => $this->node_schema( $depth - 1 ),
			);
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'block' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * The system prompt: contract rules, block vocabulary, layouts, brand context.
	 *
	 * @return string
	 */
	public function system_prompt() {
		$sections = array(
			$this->rules(),
			$this->block_reference(),
			$this->value_shapes(),
			$this->layout_reference(),
		);

		$brand = Styble_AI_Brand_Context::summary();
		if ( '' !== $brand ) {
			$sections[] = "# Brand context\n\n" . $brand;
		}

		return implode( "\n\n", $sections );
	}

	/**
	 * @return string
	 */
	private function rules() {
		return implode(
			"\n",
			array(
				'You design page sections for Styble, a WordPress block builder. Turn the request into ONE section and return it by calling the ' . self::TOOL_NAME . ' tool. Always call the tool; never reply with prose or markup.',
				'',
				'# Rules',
				'',
				'- Emit a single root block. A hero, a features row, a CTA — one section per call.',
				'- Set attributes sparsely. Omit anything you are not deliberately changing; every block fills its own defaults.',
				'- Only the attribute keys listed for a block below are legal. Any other key is rejected.',
				'- Attributes tagged (responsive), (icon) or (image) are objects with an EXACT shape, given under "Attribute value shapes". A plain number or string is rejected. If you do not specifically need to change one, omit it — the block\'s own default is already sensible.',
				'- Write real, specific, publishable copy. Never lorem ipsum, never "Your text here".',
				'- Images are placeholders: set imgAltText describing the intended photo, and never invent a URL or attachment id.',
				'- A styble/container holds only styble/column children (or nested containers). Content goes inside the columns.',
				'- Pick the container\'s `layout` and give it that many columns. Everything else about the grid — column widths, column count, direction, wrapping — is computed for you, and uniqueId is never yours to set.',
				'- Keep it proportionate: at most 6 columns per container and 8 blocks per column.',
			)
		);
	}

	/**
	 * Per-block vocabulary, generated so it can never drift from the catalog.
	 *
	 * @return string
	 */
	private function block_reference() {
		$lines = array( '# Blocks you may use', '' );

		foreach ( $this->catalog->allowlisted_names() as $name ) {
			$line = '- `' . $name . '`';

			$owned = isset( self::APPLIER_OWNED[ $name ] ) ? self::APPLIER_OWNED[ $name ] : array();
			$defs  = $this->catalog->editable_attrs( $name );

			$attrs = array();
			foreach ( $defs as $attr => $def ) {
				if ( in_array( $attr, $owned, true ) ) {
					continue;
				}
				$tag     = self::attr_tag( $attr, $def );
				$attrs[] = $tag ? $attr . ' (' . $tag . ')' : $attr;
			}
			$line .= $attrs ? ' — attrs: ' . implode( ', ', $attrs ) : ' — no attrs to set';

			$children = $this->catalog->allowed_children( $name );
			if ( $children ) {
				$line .= '; children: ' . implode( ', ', $children );
			} elseif ( ! $this->catalog->accepts_children( $name ) ) {
				$line .= '; no children';
			}

			$parents = $this->catalog->parents_of( $name );
			if ( $parents ) {
				$line .= '; only inside ' . implode( ' or ', $parents );
			}

			$lines[] = $line;
		}

		$lines[] = '';
		$lines[] = 'Content goes in these attributes: advanced-text uses advancedTextContent with textHTMLTag (h1..h4 for headings, p for body); advanced-button uses labelText and addLink; advanced-image uses imgAltText; info-box uses layoutType (layout1..layout4) and badgeText; icon-list-item uses listText; separator uses separatorText, and set separatorLabelEnable to false for a plain rule with no caption.';

		return implode( "\n", $lines );
	}

	/**
	 * Compact JSON for a prompt example.
	 *
	 * Not wp_json_encode(): this class also renders from the CLI via
	 * scripts/dump-prompt.php, outside WordPress.
	 *
	 * @param mixed $value Value to encode.
	 *
	 * @return string
	 */
	private static function json( $value ) {
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Classify an attribute so the model knows what kind of value it takes.
	 *
	 * Derived from the catalog's recorded default, never from the name — the
	 * names are actively misleading. "gapBetween" sounds like it takes a number
	 * or a {top,bottom} box; it is a full responsive object, and a model that
	 * guesses gets three validator errors.
	 *
	 * Plain strings get no tag: they are the common case and tagging them all
	 * would bury the ones that matter.
	 *
	 * @param string $attr Attribute name.
	 * @param array  $def  Catalog definition (type + default).
	 *
	 * @return string Tag, or '' when no tag is warranted.
	 */
	private static function attr_tag( $attr, $def ) {
		$type    = isset( $def['type'] ) ? $def['type'] : 'mixed';
		$default = isset( $def['default'] ) ? $def['default'] : null;

		// block.json declares this a number, but the contract requires it stay
		// blank so the user picks the attachment. Tagging it "number" would be an
		// invitation to invent an id.
		if ( 'selectImageId' === $attr ) {
			return 'always ""';
		}

		if ( 'boolean' === $type ) {
			return 'true/false';
		}
		if ( 'number' === $type ) {
			return 'number';
		}
		if ( 'array' === $type ) {
			return 'array';
		}
		if ( 'object' !== $type ) {
			return '';
		}

		if ( is_array( $default ) && isset( $default['device'] ) && array_key_exists( 'unit', $default ) ) {
			return 'responsive';
		}
		if ( is_array( $default ) && array_key_exists( 'iconName', $default ) ) {
			return 'icon';
		}
		if ( 'selectImage' === $attr ) {
			return 'image';
		}
		return 'object';
	}

	/**
	 * Literal examples of every object shape in play, taken from the catalog's
	 * own defaults so they cannot be wrong.
	 *
	 * Object-valued attributes are 17 of the 60 in the v1 allowlist, and without
	 * this the prompt names them but never says what they hold — which is how a
	 * model ends up sending {"top":16,"bottom":16} for a responsive value.
	 *
	 * @return string
	 */
	private function value_shapes() {
		$responsive = null;
		$icon       = null;

		foreach ( $this->catalog->allowlisted_names() as $name ) {
			foreach ( $this->catalog->editable_attrs( $name ) as $attr => $def ) {
				$tag = self::attr_tag( $attr, $def );
				if ( 'responsive' === $tag && null === $responsive ) {
					$responsive = $def['default'];
				}
				if ( 'icon' === $tag && null === $icon ) {
					$icon = $def['default'];
				}
			}
		}

		$lines = array( '# Attribute value shapes', '' );

		if ( null !== $responsive ) {
			$lines[] = '`(responsive)` — an object of per-device values. Desktop is required; Tablet and Mobile may be "" to inherit it. The ONLY top-level keys are `device` and `unit`. Never `top`/`bottom`, never a bare number.';
			$lines[] = '';
			$lines[] = '```json';
			$lines[] = self::json( $responsive );
			$lines[] = '```';
			$lines[] = '';
		}

		if ( null !== $icon ) {
			$lines[] = '`(icon)` — an object, not an icon name. Put the name in `iconName`:';
			$lines[] = '';
			$lines[] = '```json';
			$lines[] = self::json( $icon );
			$lines[] = '```';
			$lines[] = '';
		}

		$lines[] = '`(image)` — always the empty object `{}`, with `selectImageId` left as `""`. You describe the picture in `imgAltText`; the user picks the file.';

		return implode( "\n", $lines );
	}

	/**
	 * @return string
	 */
	private function layout_reference() {
		$lines = array( '# Container layouts', '' );

		foreach ( $this->catalog->layouts() as $layout ) {
			$lines[] = sprintf(
				'- `%s` — %s (%d column%s)',
				$layout['id'],
				$layout['label'],
				$layout['columns'],
				1 === (int) $layout['columns'] ? '' : 's'
			);
		}

		$lines[] = '';
		$lines[] = 'Set the container\'s `layout` to one of these ids and give it exactly that many styble/column children.';

		return implode( "\n", $lines );
	}

	/**
	 * Follow-up message asking the model to fix a rejected tree.
	 *
	 * The validator's own errors are fed back verbatim: the model gets the exact
	 * code, path and message a developer would read, which is why the messages
	 * are written for both audiences.
	 *
	 * @param array $errors Validation errors.
	 *
	 * @return string
	 */
	public static function correction_message( array $errors ) {
		$lines = array(
			'That tree was rejected by the Styble block validator. Fix every problem below and call ' . self::TOOL_NAME . ' again with a corrected tree.',
			'',
		);
		foreach ( $errors as $error ) {
			$lines[] = sprintf( '- [%s] %s — %s', $error['code'], $error['path'], $error['message'] );
		}
		return implode( "\n", $lines );
	}
}

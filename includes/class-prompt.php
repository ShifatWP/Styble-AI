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
	 *
	 * The node schema is expanded, not referenced, so everything in it repeats at
	 * every level. Keeping `attrs` free-form is therefore worth six times what it
	 * looks like: the one experiment that put the attribute vocabulary in here
	 * cost ~2.7k tokens AND caused the failure documented on `attrs` below.
	 * `$defs`/`$ref` would collapse the duplication, and is deliberately not
	 * used: this plugin talks to nine different OpenAI-compatible endpoints, and
	 * a `$ref` one of them will not resolve breaks generation outright.
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
			// Deliberately free-form, after trying the alternative and measuring it.
			//
			// Listing the attribute vocabulary here as `properties` looks like an
			// obvious improvement and is actively harmful: `attrs` is one object
			// shared by every block in the tree, so the only list that fits is the
			// UNION across all of them — which tells the model that textHTMLTag
			// and subHeading are legal keys on a container. Gemini promptly put
			// all four content attributes on the root container and every section
			// of a page failed. The schema cannot express "this block's
			// attributes" without a oneOf branch per block at every one of the six
			// nesting levels, which is both enormous and the shape providers are
			// worst at.
			//
			// So the per-block vocabulary lives where it can be stated exactly —
			// the system prompt — and the validator enforces it.
			'attrs' => array(
				'type'        => 'object',
				'description' => 'Sparse attributes for THIS block. Only the keys listed under this exact block name in the system prompt are legal — an attribute of a different block is rejected. Booleans are JSON true/false, never the strings "true"/"false". Omit anything you do not mean to change.',
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
				'- **Attributes belong to their own block.** Each block below lists its own keys, and only those are legal ON THAT BLOCK. `textHTMLTag` and `subHeading` belong to styble/advanced-text; putting them on a styble/container is rejected, because a container holds columns and has no text of its own. If you want a heading, add a styble/advanced-text block — do not describe it with an attribute.',
				'- An attribute written `name (a|b|c)` takes exactly one of those values. Anything else is rejected.',
				'- An attribute written `name (true/false)` takes a JSON boolean: `true`, not `"true"`. A quoted string is rejected.',
				'- Object-valued attributes are real JSON objects, not strings. `{"device":{"Desktop":16}}` is correct; `"{\\"device\\":{\\"Desktop\\":16}}"` is rejected. Never quote a `{`.',
				'- **Every block must carry its content.** advanced-text needs advancedTextContent, advanced-image needs imgAltText, advanced-button needs labelText, icon-list-item needs listText. An empty or omitted one is rejected, because the block would render a grey "Enter your text...." placeholder instead.',
				'- Attributes tagged (responsive), (responsive box), (icon) or (image) are objects with an EXACT shape, given under "Attribute value shapes". A plain number or string is rejected. If you do not specifically need to change one, omit it — the block\'s own default is already sensible.',
				'- Write real, specific, publishable copy. Never lorem ipsum, never "Your text here".',
				'- Never invent an image URL or attachment id. Describe the photograph you want in `imgAltText` instead — that description is used verbatim to search a stock photo library, so write it as a subject, not a caption: "barista pouring latte art into a white cup", not "Our coffee". Two to eight concrete words, no brand names, no text-in-image, no people by name.',
				'- A styble/container holds only styble/column children (or nested containers). Content goes inside the columns.',
				'',
				'## Choosing the layout',
				'',
				'- **Prefer to omit `layout`.** Give the container the styble/column children you want and the right equal-width layout is applied for you. Only set `layout` when you specifically want an UNEQUAL split, e.g. `l-2-60-40` for text beside an image.',
				'- If you do set `layout`, the number of styble/column children must equal that layout\'s column count EXACTLY, or the whole section is rejected. Check the count in the layout list below — `l-mr-3x2` is six columns, not three.',
				'- Column widths, column count, direction and wrapping are computed for you. Never set them, and never set uniqueId.',
				'',
				'## Spacing (this is what makes a section look finished)',
				'',
				'- **Always set `sectionPadding` on the section\'s outermost container.** It defaults to zero on all four sides, so a section without it has its text jammed against the edge of the screen and against the section above. A normal band is 80px top and bottom, 24px left and right on Desktop, and 48/16 on Mobile.',
				'- Use `horizontalGap` / `verticalGap` for the space BETWEEN columns, not padding.',
				'',
				'## Size',
				'',
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
				// A verified value list beats a type tag: "left|center|right"
				// tells the model everything, where "(string)" told it nothing
				// and let it answer "centre".
				if ( ! empty( $def['values'] ) ) {
					$attrs[] = $attr . ' (' . implode( '|', $def['values'] ) . ')';
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
		$lines[] = '**A card with a photo cannot be an info-box.** styble/info-box does not accept styble/advanced-image as a child — it is for icon + heading + text + button cards only. For a card with a photograph (a team member, a case study, a product), use a styble/column and stack styble/advanced-image and styble/advanced-text inside it.';
		$lines[] = '';
		$lines[] = 'Content goes in these attributes: advanced-text uses advancedTextContent, with textHTMLTag set to h1 for a page title, h2 or h3 for a section heading, and p for body copy; advanced-button uses labelText and addLink; advanced-image uses imgAltText; info-box needs layoutType set explicitly (it defaults to blank, which renders nothing) plus badgeText when showBadge is true; icon-list-item uses listText; separator uses separatorText, and set separatorLabelEnable to false for a plain rule with no caption.';

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
			// Two different responsive shapes share these keys. In one, a device
			// bucket is a scalar (gap: 16); in the other it is a box of four
			// sides (padding). Telling the model "responsive" for both is how it
			// learns to send 16 where {top,right,bottom,left} is required.
			$desktop = isset( $default['device']['Desktop'] ) ? $default['device']['Desktop'] : null;
			return is_array( $desktop ) ? 'responsive box' : 'responsive';
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
		$box        = null;
		$icon       = null;

		foreach ( $this->catalog->allowlisted_names() as $name ) {
			foreach ( $this->catalog->editable_attrs( $name ) as $attr => $def ) {
				$tag = self::attr_tag( $attr, $def );
				if ( 'responsive' === $tag && null === $responsive ) {
					$responsive = $def['default'];
				}
				if ( 'responsive box' === $tag && null === $box ) {
					$box = $def['default'];
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

		if ( null !== $box ) {
			$lines[] = '`(responsive box)` — the same `device`/`unit` wrapper, but each device holds FOUR SIDES, not a number. This is what `sectionPadding` takes. Tablet and Mobile may be omitted entirely to inherit Desktop.';
			$lines[] = '';
			$lines[] = '```json';
			$lines[] = self::json(
				array(
					'device' => array(
						'Desktop' => array(
							'top'    => 80,
							'right'  => 24,
							'bottom' => 80,
							'left'   => 24,
						),
						'Mobile'  => array(
							'top'    => 48,
							'right'  => 16,
							'bottom' => 48,
							'left'   => 16,
						),
					),
					'unit'   => array(
						'Desktop' => 'px',
						'Tablet'  => 'px',
						'Mobile'  => 'px',
					),
				)
			);
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

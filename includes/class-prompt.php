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
			// Content attributes only. All three options were measured against
			// gemini-3.1-flash-lite, and all three fail on it:
			//
			//   Full union (25 keys)  -> content attributes on the root container.
			//   No properties at all  -> EVERY attrs empty, a perfectly nested
			//                            tree of grey placeholders. `block` has an
			//                            enum so it was always right; `attrs` had
			//                            nothing to pattern-match so it was {}.
			//   Content only (5 keys) -> labelText and separatorText on the root
			//                            container.
			//
			// The dilemma is structural: `attrs` is ONE object shared by every
			// block in the tree, so any declaration is a union, and a weak model
			// cannot map "which block am I on" to "which keys are legal". Only a
			// oneOf branch per block at all six nesting levels expresses it
			// properly, which is enormous and the shape providers handle worst.
			//
			// These five stay because they are the mandatory ones (CONTENT_ATTRS
			// in the validator), they are what a section is actually made of, and
			// a misplaced content attribute is a cheaper failure than no content
			// anywhere — the validator names both precisely, so neither reaches
			// the page. Styling stays out: optional, and where the union did its
			// damage.
			//
			// NOT verified on a capable model. Both remaining failure modes are
			// rejections rather than silent damage, so the choice between them is
			// about which a strong model handles better, and that is untested.
			'attrs' => array(
				'type'                 => 'object',
				'description'          => 'Attributes for THIS block. The content attribute below is REQUIRED on any block that carries text or an image — an empty attrs on such a block is rejected. Other keys are legal only if listed under this exact block name in the system prompt. Booleans are JSON true/false, never "true". Styling attributes may be omitted.',
				'properties'           => array(
					'advancedTextContent' => array(
						'type'        => 'string',
						'description' => 'REQUIRED on styble/advanced-text. The heading or body copy itself.',
					),
					'imgAltText'          => array(
						'type'        => 'string',
						'description' => 'REQUIRED on styble/advanced-image. A concrete description of the wanted photograph, used verbatim as a stock photo search: "barista pouring latte art into a white cup".',
					),
					'labelText'           => array(
						'type'        => 'string',
						'description' => 'REQUIRED on styble/advanced-button. The button label.',
					),
					'listText'            => array(
						'type'        => 'string',
						'description' => 'REQUIRED on styble/icon-list-item. The list item text.',
					),
					'accordionTitle'      => array(
						'type'        => 'string',
						'description' => 'REQUIRED on styble/accordion-item. The question or row title. The answer goes in a styble/advanced-text CHILD of the item, not here.',
					),
					'separatorText'       => array(
						'type'        => 'string',
						'description' => 'The separator caption. Set separatorLabelEnable to false instead for a plain rule.',
					),
				),
				'additionalProperties' => true,
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
				'# Content is mandatory. Styling is optional.',
				'',
				'Read this before anything else — it is the distinction that matters most.',
				'',
				'- **Write the content attribute of every block you emit.** advanced-text needs `advancedTextContent`. advanced-image needs `imgAltText`. advanced-button needs `labelText`. icon-list-item needs `listText`. accordion-item needs `accordionTitle`. A block missing its content is REJECTED, because it would render a grey "Enter your text...." placeholder — worse than nothing.',
				'- `"attrs": {}` on a block that carries text or an image is not a minimal answer, it is an empty page. A tree of correctly-nested empty blocks is a FAILURE, not a clean skeleton.',
				'- Write real, specific, publishable copy: actual names, actual numbers, actual claims. Never lorem ipsum, never "Your text here".',
				'- **Styling** attributes are the optional ones — spacing, alignment, colour, gaps, icon sizes. Omit those unless you mean to change them; every block has sensible defaults for them.',
				'',
				'# Rules',
				'',
				'- Emit a single root block. A hero, a features row, a CTA — one section per call.',
				'- **Attributes belong to their own block.** Each block below lists its own keys, and only those are legal ON THAT BLOCK. `textHTMLTag` and `subHeading` belong to styble/advanced-text; putting them on a styble/container is rejected, because a container holds columns and has no text of its own. If you want a heading, add a styble/advanced-text block — do not describe it with an attribute.',
				'- An attribute written `name (a|b|c)` takes exactly one of those values. Anything else is rejected.',
				'- An attribute written `name (true/false)` takes a JSON boolean: `true`, not `"true"`. A quoted string is rejected.',
				'- Object-valued attributes are real JSON objects, not strings. `{"device":{"Desktop":16}}` is correct; `"{\\"device\\":{\\"Desktop\\":16}}"` is rejected. Never quote a `{`.',
				'- Attributes tagged (responsive), (responsive box), (icon) or (image) are objects with an EXACT shape, given under "Attribute value shapes". A plain number or string is rejected. If you do not specifically need to change one, omit it — the block\'s own default is already sensible.',
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
				'## Background (this is what makes a section read as a band)',
				'',
				'- **Give a section a background when the design calls for one** — a dark hero, a tinted testimonial strip, a CTA that has to stand out. Set `sectionBg` on the section\'s outermost container. Without it every section sits on the same page background and the page reads as one undifferentiated column.',
				'- **Prefer a brand variable to a hex.** Every brand colour slug listed under "Brand context" is also a CSS variable: slug `primary` is `var(--styble-primary)`, `light-neutral` is `var(--styble-light-neutral)`, and so on. A variable tracks the site\'s palette if the owner changes it; a hard-coded hex does not.',
				'- **A hero can have a photographic background.** Set `sectionBg` style `image` and describe the photo in `sectionBgImg.alt` — see "(background)" under "Attribute value shapes" for the exact shape, including the fallback colour and the overlay that keeps the copy readable. Use one where the brief asks for a photo or an atmosphere; a flat colour is the better default for everything else.',
				'- **Alternate, do not repeat.** Consecutive sections with the same background are the same as no background at all. A light band between two plain sections does the work; five tinted bands in a row do not.',
				'- **Recolour EVERY piece of text on a dark background, not just the heading.** Contrast belongs to the whole section, and a block you skip keeps its default dark text on your dark band. Each block has its own attribute: `styble/advanced-text` uses `textFillBg` for the heading and `subHeadingBg` for the sub-heading — both take the `(background)` shape, because Styble paints text by clipping a background to it. `styble/icon-list-item` uses `listTextColor`, `styble/accordion` uses `titleTextColor` and `contentTextColor`, `styble/separator` uses `separatorLabelColor`, `styble/icon-picker` uses `iconColor`, and `styble/icon-list` uses `iconListOrderedColor` for the numbers on an ordered list.',
				'- **Set `listTextColor` on every `styble/icon-list-item` individually.** There is no list-level colour that reaches the page; one set on the list itself paints nothing.',
				'- **Leave `btnTextColor` alone unless you are deliberately inverting the button.** A button already arrives filled with the brand colour and light label text from the site\'s global button style, so it reads correctly on a dark band without your help. Setting a dark label on it is the one change that reliably makes a call to action worse.',
				'- On a light or tinted background, leave all of these alone — the defaults are the brand\'s own text colours and are already right.',
				'- **A card is a styble/column with its own `sectionBg`.** That is how you build a white panel on a tinted band, or a pricing tier that reads as its own object. Give the card `sectionPadding` too — a column\'s padding is 0 by default, so a background without it puts the copy against the edge of the colour. `sectionBorderRadius` rounds the corners.',
				'- Contrast the card against the band, not against the page: a tinted section with white cards, or a white section with tinted cards. Do not give the container and its columns the same background — that is the same as giving neither one.',
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
		$lines[] = '**An FAQ is a styble/accordion.** Give it one styble/accordion-item per question. The QUESTION goes in the item\'s `accordionTitle` attribute; the ANSWER goes in a styble/advanced-text block nested INSIDE that item, with textHTMLTag "p". Never flatten questions and answers into a run of loose advanced-text blocks, and never put the answer in accordionTitle.';
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
		// Tagged separately from the catch-all "object": a background is the one
		// object attribute whose useful keys are a level down, so "(object)" would
		// send the model looking for a flat one.
		if ( is_array( $default ) && isset( $default['color']['style'] ) ) {
			return 'background';
		}
		// Non-responsive spacing: {value:{top,right,bottom,left}, unit, allChange}.
		// Left as "(object)" the model reliably guesses a flat {top,right,…} or a
		// bare number, and the validator rejects both — a tag it can act on is
		// cheaper than a rejection it has to be corrected out of.
		if ( is_array( $default ) && isset( $default['value'] ) && is_array( $default['value'] )
			&& array_key_exists( 'unit', $default ) ) {
			return 'box';
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
		$background = null;
		$flat_box   = null;

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
				if ( 'background' === $tag && null === $background ) {
					$background = $def['default'];
				}
				if ( 'box' === $tag && null === $flat_box ) {
					$flat_box = $def['default'];
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

		if ( null !== $flat_box ) {
			$lines[] = '`(box)` — four sides with ONE shared unit, not per-device. Note the sides sit under `value`, and there is no `device` wrapper:';
			$lines[] = '';
			$lines[] = '```json';
			$lines[] = self::json(
				array(
					'value'     => array(
						'top'    => 12,
						'right'  => 12,
						'bottom' => 12,
						'left'   => 12,
					),
					'unit'      => 'px',
					'allChange' => true,
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

		if ( null !== $background ) {
			// Curated rather than dumped, like the (responsive box) example above:
			// the catalog default carries an empty solidColor and a stock gradient,
			// which shows the key set but not a usable value. The keys here are the
			// recorded ones, so the shape still cannot drift from the block.
			$lines[] = '`(background)` — an object whose useful keys are one level down, under `color`. Set `style` to `bgColor` and put the colour in `solidColor`:';
			$lines[] = '';
			$lines[] = '```json';
			$lines[] = self::json(
				array(
					'color' => array(
						'style'      => 'bgColor',
						'solidColor' => 'var(--styble-light-neutral)',
					),
				)
			);
			$lines[] = '```';
			$lines[] = '';
			$lines[] = 'Or `style` `gradient` with a CSS gradient in `gradient`:';
			$lines[] = '';
			$lines[] = '```json';
			$lines[] = self::json(
				array(
					'color' => array(
						'style'    => 'gradient',
						'gradient' => 'linear-gradient(135deg, var(--styble-primary) 0%, var(--styble-accent) 100%)',
					),
				)
			);
			$lines[] = '```';
			$lines[] = '';
			$lines[] = 'Or `style` `image` for a photographic background. You do not choose the file — describe the photograph in `sectionBgImg.alt` and it is searched for and downloaded after your answer, exactly as `imgAltText` works. Three things go together and all three are required:';
			$lines[] = '';
			$lines[] = '```json';
			$lines[] = self::json(
				array(
					'sectionBg'                  => array(
						'color' => array(
							'style'      => 'image',
							'solidColor' => 'var(--styble-dark-neutral)',
						),
					),
					'sectionBgImg'               => array( 'alt' => 'sunlit coffee shop interior with wooden tables' ),
					'sectionBgImgOverlay'        => array(
						'style' => 'solid-overlay',
						'solid' => 'rgba(0,0,0,0.55)',
					),
					'sectionBgImgOverlayOpacity' => array(
						'value' => 0.55,
						'unit'  => 'px',
					),
				)
			);
			$lines[] = '```';
			$lines[] = '';
			$lines[] = '- `solidColor` is the **fallback** and is not optional. Photo search can be switched off or find nothing, and without a colour behind it the section would render no background at all.';
			$lines[] = '- The **overlay is what makes the copy readable.** Light text over an unknown photograph is a coin flip; a dark scrim at 0.5–0.6 makes it safe. `sectionBgImgOverlay.style` is `solid-overlay` or `transparent`, and the colour key is `solid` — not `solidColor`.';
			$lines[] = '- Put **nothing but `alt`** in `sectionBgImg`. No url, no id. Write the description as a subject, two to eight concrete words, the same way you would for an advanced-image.';
			$lines[] = '';
			$lines[] = 'Legal `style` values are `bgColor`, `gradient`, `image` and `transparent`. `video` is rejected — there is no stock video library behind it, so it would render nothing. Omit `hover` entirely; a generated section has no hover state to design.';
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

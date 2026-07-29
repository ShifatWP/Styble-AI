<?php
/**
 * Block-tree validator.
 *
 * @package Styble_AI
 */

// Pure, WP-free class: also loaded by the CLI scripts, which define STYBLE_AI_CLI.
if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

/**
 * Checks an emit_layout block tree against the catalog.
 *
 * The one rule: the LLM never emits Styble markup, only a JSON tree of sparse
 * attributes. This class is the gate between that tree and the applier — if it
 * passes, the applier may call createBlock() on every node without further
 * thought. It rejects with an explicit reason and never coerces a bad value into
 * a plausible one.
 *
 * It is also the reason `strict: true` is off on the tool call: a block tree is
 * recursive, provider-side structured output cannot express that, so enforcement
 * lives here instead of in the schema.
 *
 * See docs/CONTRACT.md for the tree shape and the full error-code table.
 */
class Styble_AI_Validator {

	/**
	 * Caps (see docs/CONTRACT.md §Caps). Sections are containers.
	 */
	const MAX_DEPTH                 = 8;
	const MAX_NODES                 = 200;
	const MAX_CONTAINERS            = 8;
	const MAX_COLUMNS_PER_CONTAINER = 6;
	const MAX_BLOCKS_PER_COLUMN     = 8;

	/**
	 * The only keys allowed at each level.
	 */
	const ENVELOPE_KEYS = array( 'version', 'root' );
	const NODE_KEYS     = array( 'block', 'attrs', 'children' );

	/**
	 * Responsive attribute vocabulary (Att_Utils shapes).
	 */
	const DEVICES = array( 'Desktop', 'Tablet', 'Mobile' );
	const SIDES   = array( 'top', 'right', 'bottom', 'left' );

	/**
	 * Background styles the AI may choose.
	 *
	 * `image` is here, but conditionally: it is only legal alongside a description
	 * in sectionBgImg.alt and a fallback colour, because the photo itself is filled
	 * in after validation. See check_background_image().
	 */
	const BACKGROUND_STYLES = array( 'bgColor', 'gradient', 'transparent', 'image' );

	/**
	 * Styles that need media the AI cannot ask for.
	 *
	 * `video` has no stock-video pipeline behind it the way `image` has
	 * Styble_AI_Media, so a video background would resolve to `none` and paint
	 * nothing. Split out so the error can say why rather than only what.
	 */
	const BACKGROUND_MEDIA_STYLES = array( 'video' );

	/**
	 * The only key the AI may put in a background image object.
	 *
	 * Everything else — url, id, sizes, focalPoint, scale — is written by
	 * Styble_AI_Media after validation, from the attachment it created.
	 */
	const BACKGROUND_IMAGE_KEY = 'alt';

	/**
	 * The background attribute that may carry a photograph, and its companion.
	 *
	 * An image style is only meaningful where BOTH exist on the same block: the
	 * style says "paint a photo" and the companion says which one. Anywhere else
	 * the style resolves to the literal `none`.
	 */
	const BACKGROUND_ATTR       = 'sectionBg';
	const BACKGROUND_IMAGE_ATTR = 'sectionBgImg';

	/**
	 * Styles legal on a background that has no image companion.
	 */
	const BACKGROUND_STYLES_NO_IMAGE = array( 'bgColor', 'gradient', 'transparent' );

	/**
	 * Overlay styles that behave correctly in Styble Pro.
	 *
	 * The inspector offers no-overlay, solid-overlay and gradient-overlay, and
	 * Css_Helpers::color_controls() has a case for none of them — so it falls to
	 * its default branch and returns the SOLID colour for all three. That makes
	 * `gradient-overlay` paint a solid, and `no-overlay` paint one too, because
	 * has_active_section_bg_overlay() treats any style except blank or
	 * `transparent` as active. Only these two do what their name says, so the AI
	 * gets only these two — the control's own list is deliberately not used, and
	 * the catalog's default check already rejected it (default `transparent` is
	 * not a member of it).
	 */
	const OVERLAY_STYLES = array( 'transparent', 'solid-overlay' );

	const CONTAINER = 'styble/container';
	const COLUMN    = 'styble/column';
	const IMAGE     = 'styble/advanced-image';

	/**
	 * The attribute that carries each block's actual content, and the toggle (if
	 * any) that makes it optional.
	 *
	 * Without this a structurally perfect section of empty blocks validates
	 * clean: every rule passes, the tree applies, and the editor shows a column
	 * of "Enter your text...." placeholders. That is a worse outcome than a
	 * rejection, because it looks like the feature ran. Sparse attributes make it
	 * easy to hit — an omitted key is indistinguishable from an empty one, and
	 * both fall back to a placeholder default.
	 *
	 * The `unless` entry is where emptiness is legitimate: an icon-only button
	 * has no label, and a plain rule has no caption.
	 */
	const CONTENT_ATTRS = array(
		'styble/advanced-text'   => array( 'attr' => 'advancedTextContent' ),
		'styble/advanced-image'  => array( 'attr' => 'imgAltText' ),
		'styble/icon-list-item'  => array( 'attr' => 'listText' ),
		'styble/accordion-item'  => array( 'attr' => 'accordionTitle' ),
		'styble/advanced-button' => array(
			'attr'   => 'labelText',
			'unless' => 'showLabel',
		),
		'styble/separator'       => array(
			'attr'   => 'separatorText',
			'unless' => 'separatorLabelEnable',
		),
	);

	/**
	 * @var Styble_AI_Catalog
	 */
	private $catalog;

	/**
	 * Accumulator for the run in progress.
	 *
	 * @var Styble_AI_Validation_Result
	 */
	private $result;

	/**
	 * Nodes seen so far this run.
	 *
	 * @var int
	 */
	private $node_count = 0;

	/**
	 * Containers seen so far this run.
	 *
	 * @var int
	 */
	private $container_count = 0;

	/**
	 * @param Styble_AI_Catalog $catalog Generated block catalog.
	 */
	public function __construct( Styble_AI_Catalog $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Convenience constructor using the default catalog path.
	 *
	 * @return Styble_AI_Validator
	 * @throws RuntimeException When the catalog cannot be loaded.
	 */
	public static function create() {
		return new self( Styble_AI_Catalog::from_file() );
	}

	/**
	 * Validate a decoded tree.
	 *
	 * @param mixed $tree Decoded emit_layout envelope.
	 *
	 * @return Styble_AI_Validation_Result
	 */
	public function validate( $tree ) {
		$this->result          = new Styble_AI_Validation_Result();
		$this->node_count      = 0;
		$this->container_count = 0;

		if ( ! self::is_map( $tree ) ) {
			$this->result->add(
				'envelope_not_object',
				'$',
				'Tree must be a JSON object with "version" and "root" keys, got ' . self::describe( $tree ) . '.'
			);
			return $this->result;
		}

		foreach ( self::ENVELOPE_KEYS as $key ) {
			if ( ! array_key_exists( $key, $tree ) ) {
				$this->result->add( 'envelope_missing_key', '$', "Missing required top-level key \"{$key}\"." );
			}
		}
		foreach ( array_keys( $tree ) as $key ) {
			if ( ! in_array( $key, self::ENVELOPE_KEYS, true ) ) {
				$this->result->add(
					'envelope_unknown_key',
					'$.' . $key,
					"Unknown top-level key \"{$key}\". Allowed: " . implode( ', ', self::ENVELOPE_KEYS ) . '.'
				);
			}
		}

		if ( array_key_exists( 'version', $tree ) ) {
			$expected = $this->catalog->contract_version();
			if ( ! is_string( $tree['version'] ) || $tree['version'] !== $expected ) {
				$this->result->add(
					'version_mismatch',
					'$.version',
					'Contract version must be "' . $expected . '", got ' . self::describe( $tree['version'] ) . '.'
				);
			}
		}

		if ( array_key_exists( 'root', $tree ) ) {
			$this->validate_node( $tree['root'], 'root', 0, null );
		}

		return $this->result;
	}

	/**
	 * Validate one node and recurse into its children.
	 *
	 * @param mixed       $node        Node to check.
	 * @param string      $path        JSON path for error reporting.
	 * @param int         $depth       Current depth, root is 0.
	 * @param string|null $parent_name Parent block name, null at the root.
	 *
	 * @return void
	 */
	private function validate_node( $node, $path, $depth, $parent_name ) {
		$this->node_count++;
		if ( $this->node_count > self::MAX_NODES ) {
			if ( self::MAX_NODES + 1 === $this->node_count ) {
				$this->result->add(
					'cap_nodes',
					$path,
					'Tree exceeds the ' . self::MAX_NODES . '-node cap; remaining nodes were not checked.'
				);
			}
			return;
		}

		if ( $depth > self::MAX_DEPTH ) {
			$this->result->add(
				'cap_depth',
				$path,
				'Nesting deeper than ' . self::MAX_DEPTH . ' levels; this subtree was not checked.'
			);
			return;
		}

		if ( ! self::is_map( $node ) ) {
			$this->result->add( 'node_not_object', $path, 'Node must be an object, got ' . self::describe( $node ) . '.' );
			return;
		}

		foreach ( array_keys( $node ) as $key ) {
			if ( ! in_array( $key, self::NODE_KEYS, true ) ) {
				$this->result->add(
					'node_unknown_key',
					$path . '.' . $key,
					"Unknown node key \"{$key}\". Allowed: " . implode( ', ', self::NODE_KEYS ) . '.'
				);
			}
		}

		if ( ! array_key_exists( 'block', $node ) ) {
			$this->result->add( 'block_missing', $path, 'Node is missing the required "block" key.' );
			return;
		}
		if ( ! is_string( $node['block'] ) ) {
			$this->result->add(
				'block_not_string',
				$path . '.block',
				'"block" must be a string like "styble/container", got ' . self::describe( $node['block'] ) . '.'
			);
			return;
		}

		$name = $node['block'];

		if ( ! $this->catalog->has_block( $name ) ) {
			$this->result->add(
				'block_unknown',
				$path . '.block',
				"\"{$name}\" is not a Styble block."
			);
			return;
		}
		if ( ! $this->catalog->is_allowlisted( $name ) ) {
			$this->result->add(
				'block_not_allowlisted',
				$path . '.block',
				"\"{$name}\" exists but is not in the v1 AI allowlist. Allowed: "
					. implode( ', ', $this->catalog->allowlisted_names() ) . '.'
			);
			return;
		}

		$this->check_nesting( $name, $path, $parent_name );

		if ( self::CONTAINER === $name ) {
			$this->container_count++;
			if ( self::MAX_CONTAINERS + 1 === $this->container_count ) {
				$this->result->add(
					'cap_containers',
					$path,
					'More than ' . self::MAX_CONTAINERS . ' containers (sections) in one tree.'
				);
			}
		}

		$attrs = $this->read_attrs( $node, $path );
		$this->validate_attrs( $name, $attrs, $path . '.attrs' );
		// Cross-attribute, so it cannot live in validate_attrs(): an image
		// background is only legal in combination with the description that will
		// fill it and the colour that covers for it if filling fails.
		$this->check_background_image( $name, $attrs, $path . '.attrs' );
		// Outside validate_attrs() on purpose: that returns early on an empty
		// attribute bag, and a content block with NO attributes at all is the
		// exact failure this catches.
		$this->check_content_present( $name, $attrs, $path );

		$children = $this->read_children( $node, $name, $path );

		if ( self::CONTAINER === $name ) {
			$this->validate_container( $attrs, $children, $path );
		}
		if ( self::COLUMN === $name && count( $children ) > self::MAX_BLOCKS_PER_COLUMN ) {
			$this->result->add(
				'cap_column_blocks',
				$path . '.children',
				'A column may hold at most ' . self::MAX_BLOCKS_PER_COLUMN . ' blocks, got ' . count( $children ) . '.'
			);
		}

		foreach ( $children as $i => $child ) {
			$this->validate_node( $child, $path . '.children[' . $i . ']', $depth + 1, $name );
		}
	}

	/**
	 * Enforce both directions of the nesting map.
	 *
	 * @param string      $name        Block name.
	 * @param string      $path        JSON path.
	 * @param string|null $parent_name Parent block name, null at the root.
	 *
	 * @return void
	 */
	private function check_nesting( $name, $path, $parent_name ) {
		$parents = $this->catalog->parents_of( $name );

		if ( null === $parent_name ) {
			if ( $parents ) {
				$this->result->add(
					'root_requires_parent',
					$path . '.block',
					"\"{$name}\" cannot be a root block; it is only legal inside " . implode( ' or ', $parents ) . '.'
				);
			}
			return;
		}

		if ( $parents && ! in_array( $parent_name, $parents, true ) ) {
			$this->result->add(
				'parent_not_allowed',
				$path . '.block',
				"\"{$name}\" is only legal inside " . implode( ' or ', $parents ) . ", not inside \"{$parent_name}\"."
			);
		}

		// An empty allowedChildren on a block that accepts children means no
		// parent-side restriction (styble/column), not "nothing allowed".
		$allowed = $this->catalog->allowed_children( $parent_name );
		if ( $allowed && ! in_array( $name, $allowed, true ) ) {
			$this->result->add(
				'child_not_allowed',
				$path . '.block',
				"\"{$parent_name}\" does not accept \"{$name}\" as a child. Allowed: " . implode( ', ', $allowed ) . '.'
			);
		}
	}

	/**
	 * Read and shallow-check the attrs bag.
	 *
	 * @param array  $node Node.
	 * @param string $path JSON path of the node.
	 *
	 * @return array Attributes, or empty when absent/invalid.
	 */
	private function read_attrs( $node, $path ) {
		if ( ! array_key_exists( 'attrs', $node ) ) {
			return array();
		}
		if ( ! self::is_map( $node['attrs'] ) ) {
			$this->result->add(
				'attrs_not_object',
				$path . '.attrs',
				'"attrs" must be an object, got ' . self::describe( $node['attrs'] ) . '.'
			);
			return array();
		}
		return $node['attrs'];
	}

	/**
	 * Read and shallow-check the children list.
	 *
	 * @param array  $node Node.
	 * @param string $name Block name.
	 * @param string $path JSON path of the node.
	 *
	 * @return array Children, or empty when absent/invalid/illegal.
	 */
	private function read_children( $node, $name, $path ) {
		if ( ! array_key_exists( 'children', $node ) ) {
			return array();
		}
		if ( ! self::is_list( $node['children'] ) ) {
			$this->result->add(
				'children_not_array',
				$path . '.children',
				'"children" must be an array, got ' . self::describe( $node['children'] ) . '.'
			);
			return array();
		}

		$children = $node['children'];
		if ( $children && ! $this->catalog->accepts_children( $name ) ) {
			$this->result->add(
				'block_is_leaf',
				$path . '.children',
				"\"{$name}\" renders no InnerBlocks and cannot have children."
			);
			return array();
		}
		return $children;
	}

	/**
	 * Every attribute key must be editable for this block, and every value must
	 * match the type and shape recorded in the catalog.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Attributes.
	 * @param string $path  JSON path of the attrs bag.
	 *
	 * @return void
	 */
	/**
	 * A content-bearing block must actually carry content.
	 *
	 * The blocks in CONTENT_ATTRS all ship a placeholder default — "Enter your
	 * text....", "List Item Text", the literal word "Separator" — so a tree that
	 * omits the content attribute passes every structural rule and then renders a
	 * section of grey placeholders. Rejecting it is the only way the retry gets a
	 * chance to write the copy, and the only way a failure looks like a failure.
	 *
	 * @param string $name  Block name.
	 * @param mixed  $attrs Attributes (may be empty or absent).
	 * @param string $path  JSON path of the node.
	 *
	 * @return void
	 */
	private function check_content_present( $name, $attrs, $path ) {
		if ( ! isset( self::CONTENT_ATTRS[ $name ] ) ) {
			return;
		}

		$rule  = self::CONTENT_ATTRS[ $name ];
		$attrs = is_array( $attrs ) ? $attrs : array();

		// Explicitly switched off: an icon-only button has no label, and a plain
		// rule has no caption.
		if ( isset( $rule['unless'] ) && array_key_exists( $rule['unless'], $attrs ) && false === $attrs[ $rule['unless'] ] ) {
			return;
		}

		// strip_tags(), not wp_strip_all_tags(): this class also runs from the
		// WP-free fixture suite. "<br>" and "&nbsp;" are empty content.
		$value = isset( $attrs[ $rule['attr'] ] ) ? $attrs[ $rule['attr'] ] : '';
		if ( is_string( $value ) ) {
			$text = trim( str_replace( array( '&nbsp;', "\xc2\xa0" ), ' ', strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			if ( '' !== $text ) {
				return;
			}
		}

		$hint = isset( $rule['unless'] )
			? sprintf( ' Set it, or set "%s" to false if this block is meant to have none.', $rule['unless'] )
			: '';

		$this->result->add(
			'content_empty',
			$path . '.attrs.' . $rule['attr'],
			sprintf(
				'"%s" carries the content of "%s" and is empty, so the block would render its placeholder.%s',
				$rule['attr'],
				$name,
				$hint
			)
		);
	}

	private function validate_attrs( $name, $attrs, $path ) {
		if ( ! $attrs ) {
			return;
		}

		$defs = $this->catalog->editable_attrs( $name );

		foreach ( $attrs as $attr => $value ) {
			if ( ! isset( $defs[ $attr ] ) ) {
				$allowed = $defs ? implode( ', ', array_keys( $defs ) ) : 'none in v1';
				$this->result->add(
					'attr_unknown',
					$path . '.' . $attr,
					"\"{$attr}\" is not an AI-editable attribute of \"{$name}\". Allowed: {$allowed}."
				);
				continue;
			}
			$this->validate_attr_value( $attr, $value, $defs[ $attr ], $path . '.' . $attr );
		}

		if ( self::IMAGE === $name ) {
			$this->check_image_placeholder( $attrs, $path );
		}
	}

	/**
	 * Type then shape check for one attribute value.
	 *
	 * @param string $attr  Attribute name.
	 * @param mixed  $value Supplied value.
	 * @param array  $def   Catalog definition (type + default).
	 * @param string $path  JSON path of the attribute.
	 *
	 * @return void
	 */
	private function validate_attr_value( $attr, $value, $def, $path ) {
		$type    = isset( $def['type'] ) ? $def['type'] : 'mixed';
		$default = array_key_exists( 'default', $def ) ? $def['default'] : null;

		// Some block.json entries declare a type their own default contradicts
		// (advanced-image.selectImageId is "number" with default ""). Emitting
		// the default verbatim is always legal.
		if ( $value === $default ) {
			return;
		}

		switch ( $type ) {
			case 'string':
				if ( ! is_string( $value ) ) {
					$this->add_type_error( $path, $attr, 'a string', $value );
					return;
				}
				$this->check_allowed_value( $attr, $value, $def, $path );
				return;

			case 'boolean':
				if ( ! is_bool( $value ) ) {
					$this->add_type_error( $path, $attr, 'true or false', $value );
				}
				return;

			case 'number':
				if ( ! is_int( $value ) && ! is_float( $value ) ) {
					$this->add_type_error( $path, $attr, 'a number', $value );
				}
				return;

			case 'array':
				if ( ! self::is_list( $value ) ) {
					$this->add_type_error( $path, $attr, 'an array', $value );
				}
				return;

			case 'object':
				if ( ! is_array( $value ) ) {
					$this->add_type_error( $path, $attr, 'an object', $value );
					return;
				}
				$this->check_shape( $value, $default, $path, $attr );
				return;

			default:
				// 'mixed' — no constraint recorded.
		}
	}

	/**
	 * An attribute the editor exposes as a fixed set of choices must hold one of
	 * them.
	 *
	 * Type alone is not enough for these, and the gap is invisible: a model that
	 * answers "contained" for containerWidth, "centre" for textAliment or "grid"
	 * for layoutType has produced a perfectly good string, so nothing rejects it
	 * and the block quietly falls back to its default. The page renders — just
	 * not the page that was asked for. Only attributes whose value list was read
	 * from Styble Pro AND verified against their own default carry `values`, so
	 * this check never fires on a guess.
	 *
	 * @param string $attr  Attribute name.
	 * @param string $value Supplied value.
	 * @param array  $def   Catalog definition.
	 * @param string $path  JSON path.
	 *
	 * @return void
	 */
	private function check_allowed_value( $attr, $value, $def, $path ) {
		if ( empty( $def['values'] ) || ! is_array( $def['values'] ) ) {
			return;
		}
		// Blank always means "leave it to the block".
		if ( '' === $value || in_array( $value, $def['values'], true ) ) {
			return;
		}

		$this->result->add(
			'attr_value',
			$path,
			"\"{$attr}\" must be one of: " . implode( ', ', $def['values'] ) . "; got \"{$value}\"."
		);
	}

	/**
	 * Compare an object value against the shape of its catalog default.
	 *
	 * @param array  $value   Supplied value.
	 * @param mixed  $default Catalog default.
	 * @param string $path    JSON path.
	 * @param string $attr    Attribute name.
	 *
	 * @return void
	 */
	private function check_shape( $value, $default, $path, $attr ) {
		if ( ! is_array( $default ) || ! $default ) {
			return; // No shape recorded to compare against.
		}

		// Responsive shapes: {device:{Desktop,…}, unit:{Desktop,…}}.
		if ( isset( $default['device'] ) && array_key_exists( 'unit', $default ) ) {
			$this->check_responsive_shape( $value, $default, $path, $attr );
			return;
		}

		// Background shapes: {color:{style,…}, hover:{style,…}}. Checked before the
		// generic key comparison below because that only looks at the TOP level,
		// and everything that matters about a background is one level down.
		if ( self::is_background_default( $default ) ) {
			$this->check_background_shape( $value, $default, $path, $attr );
			return;
		}

		// Everything else (icon, colors, non-responsive spacing) is a fixed key set.
		foreach ( array_keys( $value ) as $key ) {
			if ( ! array_key_exists( $key, $default ) ) {
				$this->result->add(
					'attr_shape',
					$path . '.' . $key,
					"\"{$attr}\" has no key \"{$key}\". Expected keys: " . implode( ', ', array_keys( $default ) ) . '.'
				);
			}
		}

		// Non-responsive spacing: {value:{top,right,bottom,left}, unit, allChange}.
		if ( isset( $default['value'] ) && is_array( $default['value'] ) && isset( $value['value'] ) ) {
			$this->check_sides( $value['value'], $path . '.value', $attr );
		}
	}

	/**
	 * Does this catalog default describe a background?
	 *
	 * Recognised by structure rather than by attribute name, because Styble Pro
	 * builds every one of them from the same Att_Utils::background() factory —
	 * so any future background attribute is covered without a list to maintain.
	 *
	 * @param mixed $default Catalog default.
	 *
	 * @return bool
	 */
	private static function is_background_default( $default ) {
		return is_array( $default )
			&& isset( $default['color'] ) && is_array( $default['color'] )
			&& array_key_exists( 'style', $default['color'] )
			&& array_key_exists( 'solidColor', $default['color'] )
			&& array_key_exists( 'gradient', $default['color'] );
	}

	/**
	 * Validate a {color:{style,…}, hover:{…}} background value.
	 *
	 * The generic key comparison in check_shape() would pass anything at all here:
	 * `color` and `hover` are the only top-level keys and both are legal, so a
	 * tree could set style to any string and never be questioned. Everything that
	 * decides whether a background actually paints lives one level down.
	 *
	 * `image` and `video` are refused rather than merely discouraged. Both read
	 * their media out of a SEPARATE attribute (sectionBgImg), which is not on the
	 * AI allowlist because the model may never invent a media reference. Styble
	 * Pro's Css_Helpers::color_controls() maps a style of "image" with no URL to
	 * the literal `none`, so the section would render with no background and no
	 * error anywhere — the same invisible-failure class as a placeholder-filled
	 * tree, and refused for the same reason.
	 *
	 * @param array  $value   Supplied value.
	 * @param array  $default Catalog default.
	 * @param string $path    JSON path.
	 * @param string $attr    Attribute name.
	 *
	 * @return void
	 */
	private function check_background_shape( $value, $default, $path, $attr ) {
		foreach ( $value as $state => $inner ) {
			if ( ! array_key_exists( $state, $default ) ) {
				$this->result->add(
					'attr_shape',
					$path . '.' . $state,
					"\"{$attr}\" has no key \"{$state}\". Expected keys: " . implode( ', ', array_keys( $default ) ) . '.'
				);
				continue;
			}

			if ( ! self::is_map( $inner ) ) {
				$this->result->add(
					'attr_shape',
					$path . '.' . $state,
					"\"{$attr}.{$state}\" must be an object with a \"style\", got " . self::describe( $inner ) . '.'
				);
				continue;
			}

			foreach ( array_keys( $inner ) as $key ) {
				if ( ! array_key_exists( $key, $default[ $state ] ) ) {
					$this->result->add(
						'attr_shape',
						$path . '.' . $state . '.' . $key,
						"\"{$attr}.{$state}\" has no key \"{$key}\". Expected keys: "
							. implode( ', ', array_keys( $default[ $state ] ) ) . '.'
					);
				}
			}

			if ( ! array_key_exists( 'style', $inner ) ) {
				continue;
			}

			$style = $inner['style'];
			if ( ! is_string( $style ) ) {
				$this->add_type_error( $path . '.' . $state . '.style', $attr . '.' . $state . '.style', 'string', $style );
				continue;
			}
			// Blank means "leave it to the block", as everywhere else.
			if ( '' === $style || in_array( $style, self::BACKGROUND_STYLES, true ) ) {
				continue;
			}

			$reason = in_array( $style, self::BACKGROUND_MEDIA_STYLES, true )
				? " A \"{$style}\" background needs a media reference the AI may not set, and would render nothing."
				: '';

			$this->result->add(
				'attr_value',
				$path . '.' . $state . '.style',
				"\"{$attr}.{$state}.style\" must be one of: " . implode( ', ', self::BACKGROUND_STYLES )
					. "; got \"{$style}\"." . $reason
			);
		}
	}

	/**
	 * Validate a {device, unit} responsive value.
	 *
	 * @param array  $value   Supplied value.
	 * @param array  $default Catalog default.
	 * @param string $path    JSON path.
	 * @param string $attr    Attribute name.
	 *
	 * @return void
	 */
	private function check_responsive_shape( $value, $default, $path, $attr ) {
		foreach ( array_keys( $value ) as $key ) {
			if ( 'device' !== $key && 'unit' !== $key ) {
				$this->result->add(
					'attr_shape',
					$path . '.' . $key,
					"\"{$attr}\" is a responsive value; allowed keys are device, unit — got \"{$key}\"."
				);
			}
		}

		if ( ! isset( $value['device'] ) ) {
			$this->result->add(
				'attr_shape',
				$path,
				"\"{$attr}\" is a responsive value and requires a \"device\" object, e.g. "
					. '{"device":{"Desktop":16},"unit":{"Desktop":"px"}}.'
			);
			return;
		}

		// Per-device buckets on both device and unit.
		foreach ( array( 'device', 'unit' ) as $bucket ) {
			if ( ! array_key_exists( $bucket, $value ) ) {
				continue;
			}
			if ( ! self::is_map( $value[ $bucket ] ) ) {
				$this->result->add(
					'attr_shape',
					$path . '.' . $bucket,
					"\"{$attr}\".{$bucket} must be an object keyed by device, got " . self::describe( $value[ $bucket ] ) . '.'
				);
				continue;
			}
			foreach ( array_keys( $value[ $bucket ] ) as $device ) {
				if ( ! in_array( $device, self::DEVICES, true ) ) {
					$this->result->add(
						'attr_shape',
						$path . '.' . $bucket . '.' . $device,
						"Unknown device \"{$device}\". Allowed: " . implode( ', ', self::DEVICES ) . '.'
					);
				}
			}
		}

		if ( self::is_map( $value['device'] ) && ! array_key_exists( 'Desktop', $value['device'] ) ) {
			$this->result->add(
				'attr_shape',
				$path . '.device',
				"\"{$attr}\" must set at least the Desktop device; Tablet and Mobile inherit from it."
			);
		}

		// responsive_spacing: each device bucket is itself {top,right,bottom,left}.
		$sample = isset( $default['device']['Desktop'] ) ? $default['device']['Desktop'] : null;
		if ( is_array( $sample ) && self::is_map( $value['device'] ) ) {
			foreach ( $value['device'] as $device => $per_device ) {
				$this->check_sides( $per_device, $path . '.device.' . $device, $attr );
			}
		}
	}

	/**
	 * A spacing bucket must be an object of top/right/bottom/left.
	 *
	 * @param mixed  $sides Supplied bucket.
	 * @param string $path  JSON path.
	 * @param string $attr  Attribute name.
	 *
	 * @return void
	 */
	private function check_sides( $sides, $path, $attr ) {
		if ( '' === $sides || null === $sides ) {
			return; // Empty device bucket means "inherit".
		}
		if ( ! self::is_map( $sides ) ) {
			$this->result->add(
				'attr_shape',
				$path,
				"\"{$attr}\" is a spacing value; expected an object of "
					. implode( '/', self::SIDES ) . ', got ' . self::describe( $sides ) . '.'
			);
			return;
		}
		foreach ( array_keys( $sides ) as $side ) {
			if ( ! in_array( $side, self::SIDES, true ) ) {
				$this->result->add(
					'attr_shape',
					$path . '.' . $side,
					"Unknown spacing side \"{$side}\". Allowed: " . implode( ', ', self::SIDES ) . '.'
				);
			}
		}
	}

	/**
	 * Images are placeholders: the AI writes alt text, the user picks the media.
	 *
	 * @param array  $attrs Attributes of an advanced-image node.
	 * @param string $path  JSON path of the attrs bag.
	 *
	 * @return void
	 */
	private function check_image_placeholder( $attrs, $path ) {
		if ( array_key_exists( 'selectImage', $attrs ) && ! self::is_blank( $attrs['selectImage'] ) ) {
			$this->result->add(
				'image_not_placeholder',
				$path . '.selectImage',
				'AI output must leave selectImage empty ({}) and never invent a media URL. Set imgAltText instead.'
			);
		}
		if ( array_key_exists( 'selectImageId', $attrs ) && ! self::is_blank( $attrs['selectImageId'] ) && 0 !== $attrs['selectImageId'] ) {
			$this->result->add(
				'image_not_placeholder',
				$path . '.selectImageId',
				'AI output must leave selectImageId empty; the user picks the attachment.'
			);
		}
	}

	/**
	 * A background image must be a fillable placeholder with something behind it.
	 *
	 * The contract's media rule does not bend for backgrounds: the model writes a
	 * description and never a URL or an id. What makes `image` usable rather than
	 * merely legal is the pipeline behind it — Styble_AI_Media reads the
	 * description, downloads a photo, and writes the url and attachment id AFTER
	 * validation, exactly as it does for advanced-image.
	 *
	 * Three things are therefore required together, and none of them is optional:
	 *
	 *  1. A description in sectionBgImg.alt, or there is nothing to search for.
	 *  2. Nothing else in sectionBgImg, or the model has invented media.
	 *  3. A fallback solidColor. Image filling is off unless a stock-photo
	 *     provider is configured, and every failure is non-fatal by design — so
	 *     without a fallback the section would resolve to `background: none` and
	 *     paint nothing at all. With one, a failed download degrades to a plain
	 *     coloured band, which is a section that still works.
	 *
	 * @param array  $attrs Node attributes.
	 * @param string $path  JSON path of the attrs bag.
	 *
	 * @return void
	 */
	private function check_background_image( $name, $attrs, $path ) {
		// Every background-shaped attribute on this block, not just sectionBg.
		// styble/advanced-text paints its heading through textFillBg and its
		// sub-heading through subHeadingBg, both the same shape — and neither has an
		// image companion, so an image style on either is refused below rather than
		// silently producing `background: none` clipped to the text, which renders
		// the heading INVISIBLE.
		foreach ( $this->catalog->editable_attrs( $name ) as $attr => $def ) {
			if ( ! array_key_exists( $attr, $attrs ) || ! self::is_map( $attrs[ $attr ] ) ) {
				continue;
			}
			if ( ! self::is_background_default( isset( $def['default'] ) ? $def['default'] : null ) ) {
				continue;
			}
			$style = isset( $attrs[ $attr ]['color']['style'] ) ? $attrs[ $attr ]['color']['style'] : '';
			if ( 'image' !== $style ) {
				continue;
			}
			// An image background needs a companion image attribute on the SAME
			// block. Only the section container has one, so this is what stops the
			// model choosing a photo for a heading or a card and being sent round a
			// loop between "needs sectionBgImg" and "sectionBgImg is not editable".
			if ( self::BACKGROUND_ATTR !== $attr || ! array_key_exists( self::BACKGROUND_IMAGE_ATTR, $this->catalog->editable_attrs( $name ) ) ) {
				$this->result->add(
					'attr_value',
					$path . '.' . $attr . '.color.style',
					"\"{$attr}.color.style\" cannot be \"image\" on \"{$name}\" — only a section container can carry a photograph. "
						. 'Use ' . implode( ', ', self::BACKGROUND_STYLES_NO_IMAGE ) . ' here, and put the photograph on the section instead.'
				);
				// Stop here for this node. The checks below would otherwise demand a
				// description and a fallback colour for an image this block can never
				// have, and the corrective retry would chase an attribute that is not
				// editable on it — two errors pointing in opposite directions.
				return;
			}
		}

		$overlay = isset( $attrs['sectionBgImgOverlay'] ) ? $attrs['sectionBgImgOverlay'] : null;
		if ( self::is_map( $overlay ) && isset( $overlay['style'] ) && is_string( $overlay['style'] )
			&& '' !== $overlay['style'] && ! in_array( $overlay['style'], self::OVERLAY_STYLES, true ) ) {
			$this->result->add(
				'attr_value',
				$path . '.sectionBgImgOverlay.style',
				'"sectionBgImgOverlay.style" must be one of: ' . implode( ', ', self::OVERLAY_STYLES )
					. '; got "' . $overlay['style'] . '". The other values the block offers all render a solid colour regardless of their name.'
			);
		}

		$style = isset( $attrs['sectionBg']['color']['style'] ) ? $attrs['sectionBg']['color']['style'] : '';
		$img   = isset( $attrs['sectionBgImg'] ) ? $attrs['sectionBgImg'] : null;

		if ( 'image' !== $style ) {
			// A description with no image style is a wasted download, not an error —
			// but media the model invented is still media the model invented.
			if ( self::is_map( $img ) ) {
				$this->check_background_image_keys( $img, $path );
			}
			return;
		}

		if ( ! self::is_map( $img ) || '' === trim( (string) ( isset( $img['alt'] ) ? $img['alt'] : '' ) ) ) {
			$this->result->add(
				'content_empty',
				$path . '.sectionBgImg',
				'A background with style "image" needs sectionBgImg.alt describing the photograph you want, e.g. '
					. '{"alt": "sunlit coffee shop interior"}. That description is used verbatim to search a stock photo library.'
			);
		}

		// Unconditionally, not in an else: a missing description and an invented URL
		// are two separate mistakes, and reporting only the first would let the
		// second through on the corrective retry.
		if ( self::is_map( $img ) ) {
			$this->check_background_image_keys( $img, $path );
		}

		$fallback = isset( $attrs['sectionBg']['color']['solidColor'] ) ? trim( (string) $attrs['sectionBg']['color']['solidColor'] ) : '';
		if ( '' === $fallback ) {
			$this->result->add(
				'content_empty',
				$path . '.sectionBg.color.solidColor',
				'A background with style "image" also needs solidColor set as a fallback. Stock photo filling can be '
					. 'switched off or fail, and without a colour behind it the section would render no background at all.'
			);
		}
	}

	/**
	 * The AI may put only a description in a background image object.
	 *
	 * @param array  $img  sectionBgImg value.
	 * @param string $path JSON path of the attrs bag.
	 *
	 * @return void
	 */
	private function check_background_image_keys( array $img, $path ) {
		foreach ( array_keys( $img ) as $key ) {
			if ( self::BACKGROUND_IMAGE_KEY === $key ) {
				continue;
			}
			$this->result->add(
				'image_not_placeholder',
				$path . '.sectionBgImg.' . $key,
				'AI output must put only "' . self::BACKGROUND_IMAGE_KEY . '" in sectionBgImg and never invent a media '
					. 'URL or id; the photo is filled in afterwards. Remove "' . $key . '".'
			);
		}
	}

	/**
	 * Container layout must agree with its column children.
	 *
	 * @param array  $attrs    Container attributes.
	 * @param array  $children Container children.
	 * @param string $path     JSON path of the container node.
	 *
	 * @return void
	 */
	private function validate_container( $attrs, $children, $path ) {
		$column_children = 0;
		foreach ( $children as $child ) {
			if ( self::is_map( $child ) && isset( $child['block'] ) && self::COLUMN === $child['block'] ) {
				$column_children++;
			}
		}

		if ( $column_children > self::MAX_COLUMNS_PER_CONTAINER ) {
			$this->result->add(
				'cap_columns',
				$path . '.children',
				'A container may hold at most ' . self::MAX_COLUMNS_PER_CONTAINER . ' columns, got ' . $column_children . '.'
			);
		}

		if ( ! isset( $attrs['layout'] ) || ! is_string( $attrs['layout'] ) || '' === $attrs['layout'] ) {
			return; // Layout is optional; the applier defaults it from the column count.
		}

		$layout_id = $attrs['layout'];
		$layout    = $this->catalog->layout( $layout_id );
		if ( ! $layout ) {
			$this->result->add(
				'layout_unknown',
				$path . '.attrs.layout',
				"\"{$layout_id}\" is not a Styble layout id. Allowed: " . implode( ', ', $this->catalog->layout_ids() ) . '.'
			);
			return;
		}

		$expected = (int) $layout['columns'];

		if ( array_key_exists( 'columns', $attrs ) && is_numeric( $attrs['columns'] ) && (int) $attrs['columns'] !== $expected ) {
			$this->result->add(
				'layout_columns_mismatch',
				$path . '.attrs.columns',
				"Layout \"{$layout_id}\" has {$expected} columns but columns is set to " . self::describe( $attrs['columns'] ) . '.'
			);
		}

		if ( $column_children > 0 && $column_children !== $expected ) {
			$this->result->add(
				'layout_children_mismatch',
				$path . '.children',
				"Layout \"{$layout_id}\" needs exactly {$expected} styble/column children, got {$column_children}."
			);
		}

		if ( array_key_exists( 'layoutSelected', $attrs ) && false === $attrs['layoutSelected'] ) {
			$this->result->add(
				'layout_selected_conflict',
				$path . '.attrs.layoutSelected',
				"Layout \"{$layout_id}\" is set, so layoutSelected must be true (or omitted)."
			);
		}
	}

	/**
	 * Record a wrong-type error.
	 *
	 * @param string $path     JSON path.
	 * @param string $attr     Attribute name.
	 * @param string $expected Human description of the expected type.
	 * @param mixed  $value    Supplied value.
	 *
	 * @return void
	 */
	private function add_type_error( $path, $attr, $expected, $value ) {
		$this->result->add(
			'attr_type',
			$path,
			"\"{$attr}\" must be {$expected}, got " . self::describe( $value ) . '.'
		);
	}

	/**
	 * Is this an empty-ish value (unset media, inherited device bucket)?
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	private static function is_blank( $value ) {
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * JSON object (or empty array, which decodes from {}).
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	private static function is_map( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		return ! $value || array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * JSON array.
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	private static function is_list( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		return ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Short, safe rendering of a value for an error message.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string
	 */
	private static function describe( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		// Not wp_json_encode(): this class also runs from the CLI, outside WordPress.
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( false === $json ) {
			return gettype( $value );
		}
		return strlen( $json ) > 60 ? substr( $json, 0, 57 ) . '…' : $json;
	}
}

<?php
/**
 * Headless applier: validated emit_layout trees -> Styble block markup.
 *
 * The editor path (assets/applier.js) hands trees to createBlock() and lets the
 * blocks mount. Nothing mounts here, so this applier has to do by hand the two
 * things mounting did for free:
 *
 *  1. `uniqueId`. Every Styble block assigns its own in a mount effect, and the
 *     PHP that renders the frontend scopes its generated CSS to that class. A
 *     headless page without uniqueIds parses fine, opens fine, and renders
 *     completely unstyled — so this file mints them, in the blocks' own format
 *     (`<uniqueIdPrefix><12 hex>`, see catalog.uniqueIdRule). They are derived
 *     from section id + node path, so regenerating one section leaves every
 *     other block's id — and therefore its CSS — untouched.
 *
 *  2. Defaults are NOT written. The tree stays sparse: block.json fills the rest
 *     at render (WP_Block merges defaults) and at parse (the editor does the
 *     same). This is the one intentional difference from the editor's own
 *     output, which writes every attribute it holds in memory.
 *
 * Everything else — layout, columns, per-column columnWidth, direction,
 * flexWrap — is applier-owned and mirrors assets/applier.js line for line. The
 * two must agree: a section built here and the same section built in the editor
 * should differ only in which defaults are spelled out. Keep them in step.
 *
 * @package Styble_AI
 */

// Pure enough to run from the CLI test harness, which defines STYBLE_AI_CLI.
if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Page_Applier {

	const COLUMN_BLOCK    = 'styble/column';
	const CONTAINER_BLOCK = 'styble/container';

	/**
	 * Padding applied to a section's outermost container when the model set
	 * none.
	 *
	 * `sectionPadding` is zero on all four sides in block.json, so leaving it
	 * unset is not a neutral choice: the copy sits against the viewport edge and
	 * the section sits flush against the one above it. The prompt asks for it
	 * explicitly; this is the backstop for when the model does not comply, and
	 * it is applier-owned in the same sense the column geometry is.
	 *
	 * Kept in step with SECTION_PADDING in assets/applier.js.
	 */
	const DEFAULT_SECTION_PADDING = array(
		'device' => array(
			'Desktop' => array(
				'top'    => 80,
				'right'  => 24,
				'bottom' => 80,
				'left'   => 24,
			),
			'Tablet'  => array(
				'top'    => 64,
				'right'  => 20,
				'bottom' => 64,
				'left'   => 20,
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
	);

	/**
	 * Padding given to a column that has a background but set no padding.
	 *
	 * Tighter and even, unlike the section band above: a card is padded inward on
	 * all four sides, not given a vertical rhythm. See with_card_padding().
	 *
	 * Kept in step with CARD_PADDING in assets/applier.js.
	 */
	const DEFAULT_CARD_PADDING = array(
		'device' => array(
			'Desktop' => array(
				'top'    => 32,
				'right'  => 32,
				'bottom' => 32,
				'left'   => 32,
			),
			'Tablet'  => array(
				'top'    => 28,
				'right'  => 28,
				'bottom' => 28,
				'left'   => 28,
			),
			'Mobile'  => array(
				'top'    => 24,
				'right'  => 24,
				'bottom' => 24,
				'left'   => 24,
			),
		),
		'unit'   => array(
			'Desktop' => 'px',
			'Tablet'  => 'px',
			'Mobile'  => 'px',
		),
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
	 * Serialize a whole page.
	 *
	 * @param array $sections List of [ 'id' => string, 'tree' => envelope ].
	 *                        Sections with no tree yet are skipped, so a page
	 *                        can be re-serialized while it is still building.
	 *
	 * @return string Block markup for post_content.
	 */
	public function to_markup( array $sections ) {
		return $this->serialize_all( $this->to_blocks( $sections ) );
	}

	/**
	 * Build the page's blocks in parse_blocks() shape.
	 *
	 * @param array $sections List of [ 'id' => string, 'tree' => envelope ].
	 *
	 * @return array List of block arrays.
	 */
	public function to_blocks( array $sections ) {
		$blocks = array();

		foreach ( $sections as $section ) {
			$tree = isset( $section['tree'] ) ? $section['tree'] : null;
			if ( ! is_array( $tree ) || ! isset( $tree['root'] ) || ! is_array( $tree['root'] ) ) {
				continue;
			}
			$scope    = isset( $section['id'] ) ? (string) $section['id'] : (string) count( $blocks );
			$blocks[] = $this->build_node( self::with_section_padding( $tree['root'] ), $scope, 'r' );
		}

		return $blocks;
	}

	/* ------------------------------------------------------------------ */
	/* Tree -> blocks                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Give a section's outermost container breathing room if the model did not.
	 *
	 * sectionPadding is zero on all four sides by default, so an unset one is
	 * not a neutral choice — it is copy hard against the viewport edge and
	 * against the section above. Only the root is touched: an 80px band on a
	 * nested container would be wrong.
	 *
	 * @param array $root Root node of a section.
	 *
	 * @return array
	 */
	private static function with_section_padding( array $root ) {
		if ( ! isset( $root['block'] ) || self::CONTAINER_BLOCK !== $root['block'] ) {
			return $root;
		}

		$attrs = isset( $root['attrs'] ) && is_array( $root['attrs'] ) ? $root['attrs'] : array();
		if ( isset( $attrs['sectionPadding'] ) ) {
			return $root;
		}

		$attrs['sectionPadding'] = self::DEFAULT_SECTION_PADDING;
		$root['attrs']           = $attrs;

		return $root;
	}

	/**
	 * Give a column that has a background the padding that makes it a card.
	 *
	 * sectionPadding is zero on all four sides for a column too, so a tinted or
	 * white column with no padding renders its copy flush against the edge of the
	 * tint. That is not a styling preference the model might reasonably have
	 * chosen — it is a card that looks broken, and it looks broken in the same
	 * silent way an unpadded section did.
	 *
	 * Applied only when there is a background AND no padding of its own. A column
	 * with no background wants no padding (the gap between columns does that work),
	 * and a model-supplied value is never overwritten.
	 *
	 * Kept in step with CARD_PADDING in assets/applier.js.
	 *
	 * @param array $node Tree node.
	 *
	 * @return array
	 */
	private static function with_card_padding( array $node ) {
		if ( ! isset( $node['block'] ) || self::COLUMN_BLOCK !== $node['block'] ) {
			return $node;
		}

		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		if ( ! self::has_background( $attrs ) || isset( $attrs['sectionPadding'] ) ) {
			return $node;
		}

		$attrs['sectionPadding'] = self::DEFAULT_CARD_PADDING;
		$node['attrs']          = $attrs;

		return $node;
	}

	/**
	 * Does this attribute bag carry a background that will actually paint?
	 *
	 * A style of bgColor with an empty solidColor is what the block ships by
	 * default and paints nothing, so it must not trigger the padding backstop.
	 *
	 * @param array $attrs Node attributes.
	 *
	 * @return bool
	 */
	private static function has_background( array $attrs ) {
		if ( ! isset( $attrs['sectionBg']['color'] ) || ! is_array( $attrs['sectionBg']['color'] ) ) {
			return false;
		}

		$color = $attrs['sectionBg']['color'];
		$style = isset( $color['style'] ) ? $color['style'] : '';

		if ( 'gradient' === $style ) {
			return '' !== trim( (string) ( isset( $color['gradient'] ) ? $color['gradient'] : '' ) );
		}
		if ( 'bgColor' === $style ) {
			return '' !== trim( (string) ( isset( $color['solidColor'] ) ? $color['solidColor'] : '' ) );
		}

		return false;
	}

	/**
	 * Build one node and its subtree.
	 *
	 * @param array  $node  Tree node.
	 * @param string $scope Section id, namespacing this subtree's uniqueIds.
	 * @param string $path  Node path within the section ("r", "r.0", "r.0.1").
	 *
	 * @return array Block array.
	 * @throws RuntimeException When a node has no block name.
	 */
	private function build_node( array $node, $scope, $path ) {
		if ( ! isset( $node['block'] ) || ! is_string( $node['block'] ) ) {
			throw new RuntimeException( 'Styble AI: tree node has no block name.' );
		}

		$node = self::with_card_padding( $node );

		if ( self::CONTAINER_BLOCK === $node['block'] ) {
			return $this->build_container( $node, $scope, $path );
		}

		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		$inner    = array();
		foreach ( $children as $i => $child ) {
			$inner[] = $this->build_node( (array) $child, $scope, $path . '.' . $i );
		}

		return $this->block(
			$node['block'],
			$this->attrs_with_unique_id( $node, $scope, $path ),
			$inner
		);
	}

	/**
	 * Build a container and its columns.
	 *
	 * Mirrors assets/applier.js buildContainer(): the layout decides the column
	 * count and the per-column widths, and anything the container's own
	 * onSelectPreset would compute is written here rather than trusted from the
	 * model.
	 *
	 * @param array  $node  Container node.
	 * @param string $scope Section id.
	 * @param string $path  Node path.
	 *
	 * @return array Block array.
	 */
	private function build_container( array $node, $scope, $path ) {
		$attrs    = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();

		$layout_id = ( isset( $attrs['layout'] ) && is_string( $attrs['layout'] ) && '' !== $attrs['layout'] )
			? $attrs['layout']
			: $this->default_layout_for_count(
				$this->count_columns( $children ) ? $this->count_columns( $children ) : ( count( $children ) ? count( $children ) : 1 )
			);

		$layout = $this->catalog->layout( $layout_id );

		// No usable layout: build the subtree as-is rather than inventing geometry.
		if ( ! $layout ) {
			$inner = array();
			foreach ( $children as $i => $child ) {
				$inner[] = $this->build_node( (array) $child, $scope, $path . '.' . $i );
			}
			return $this->block(
				self::CONTAINER_BLOCK,
				$this->attrs_with_unique_id( $node, $scope, $path ),
				$inner
			);
		}

		$widths = array();
		foreach ( ( isset( $layout['widths'] ) ? $layout['widths'] : array() ) as $w ) {
			$widths[] = self::round_pct( $w );
		}

		// An empty container gets the layout's columns seeded, exactly as picking
		// the layout in the editor would. A container that already has children
		// keeps them — the validator has already checked the counts agree.
		$source = $children;
		if ( ! $source ) {
			foreach ( $widths as $unused ) {
				$source[] = array( 'block' => self::COLUMN_BLOCK );
			}
		}

		$column_index = 0;
		$inner        = array();
		foreach ( $source as $i => $child ) {
			$child = (array) $child;
			if ( ! isset( $child['block'] ) || self::COLUMN_BLOCK !== $child['block'] ) {
				$inner[] = $this->build_node( $child, $scope, $path . '.' . $i );
				continue;
			}

			$width = isset( $widths[ $column_index ] ) ? $widths[ $column_index ] : null;
			$column_index++;

			$child_attrs = isset( $child['attrs'] ) && is_array( $child['attrs'] ) ? $child['attrs'] : array();
			// Applier-owned: the layout decides column widths.
			$child_attrs['columnWidth'] = self::layout_column_width( $width, count( $widths ) );
			$child['attrs']             = $child_attrs;

			$inner[] = $this->build_node( $child, $scope, $path . '.' . $i );
		}

		$attrs['layout']         = $layout['id'];
		$attrs['layoutSelected'] = true;
		$attrs['columns']        = count( $widths );
		$attrs['direction']      = self::override_desktop( isset( $attrs['direction'] ) ? $attrs['direction'] : null, 'row' );
		$attrs['flexWrap']       = self::override_desktop(
			isset( $attrs['flexWrap'] ) ? $attrs['flexWrap'] : null,
			self::is_multi_row( $layout ) ? 'wrap' : 'nowrap'
		);

		$node['attrs'] = $attrs;

		return $this->block(
			self::CONTAINER_BLOCK,
			$this->attrs_with_unique_id( $node, $scope, $path ),
			$inner
		);
	}

	/**
	 * The node's attributes plus the uniqueId the mount effect would have set.
	 *
	 * Blocks with no uniqueIdPrefix in the catalog never call useUniqueId(), so
	 * they get no uniqueId — writing one would put an attribute on a block that
	 * has no such attribute.
	 *
	 * @param array  $node  Tree node.
	 * @param string $scope Section id.
	 * @param string $path  Node path.
	 *
	 * @return array
	 */
	private function attrs_with_unique_id( array $node, $scope, $path ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();

		$prefix = $this->catalog->unique_id_prefix( $node['block'] );
		if ( is_string( $prefix ) && '' !== $prefix ) {
			$attrs['uniqueId'] = self::unique_id( $prefix, $scope, $path );
		}

		return $attrs;
	}

	/**
	 * The uniqueId a block at this position gets.
	 *
	 * Derived, not random: stable across rebuilds, so regenerating one section
	 * cannot renumber another section's scoped CSS. 12 hex characters matches the
	 * length of the clientId segment the editor's own useUniqueId() uses.
	 *
	 * Public and static because it is the addressing scheme for the whole page,
	 * not an implementation detail of serialization — Styble_AI_Page_Model walks
	 * the stored trees and recomputes these to resolve a uid back to a node. Two
	 * copies of this arithmetic would mean a uid that resolves to the wrong block,
	 * so there is one.
	 *
	 * @param string $prefix Block's uniqueIdPrefix from the catalog.
	 * @param string $scope  Section id.
	 * @param string $path   Node path within the section ("r", "r.0", "r.0.1").
	 *
	 * @return string
	 */
	public static function unique_id( $prefix, $scope, $path ) {
		return $prefix . substr( md5( $scope . '|' . $path ), 0, 12 );
	}

	/* ------------------------------------------------------------------ */
	/* Layout maths — mirrors assets/applier.js                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Round a percent to at most 2 decimals, keeping whole numbers integral so
	 * the JSON matches what the JS applier writes (50, not 50.0).
	 *
	 * @param mixed $n Raw percent.
	 *
	 * @return int|float|mixed
	 */
	private static function round_pct( $n ) {
		if ( ! is_numeric( $n ) ) {
			return $n;
		}
		$r = round( (float) $n, 2 );
		return ( (float) (int) $r === $r ) ? (int) $r : $r;
	}

	/**
	 * @param mixed $desktop Desktop percent.
	 *
	 * @return array single_responsive width value.
	 */
	private static function responsive_width( $desktop ) {
		return array(
			'device' => array(
				'Desktop' => self::round_pct( $desktop ),
				'Tablet'  => self::round_pct( $desktop ),
				'Mobile'  => 100,
			),
			'unit'   => array(
				'Desktop' => '%',
				'Tablet'  => '%',
				'Mobile'  => '%',
			),
		);
	}

	/**
	 * @return array Empty responsive width — flex decides.
	 */
	private static function empty_responsive_width() {
		return array(
			'device' => array(
				'Desktop' => '',
				'Tablet'  => '',
				'Mobile'  => '',
			),
			'unit'   => array(
				'Desktop' => '%',
				'Tablet'  => '%',
				'Mobile'  => '%',
			),
		);
	}

	/**
	 * Single-column layouts keep width empty (flex default); multi-column use
	 * the preset percentage.
	 *
	 * @param mixed $pct   Layout width percent.
	 * @param int   $count Number of columns in the layout.
	 *
	 * @return array
	 */
	private static function layout_column_width( $pct, $count ) {
		return ( 1 === $count ) ? self::empty_responsive_width() : self::responsive_width( $pct );
	}

	/**
	 * Set the Desktop value of a responsive attribute, preserving other devices.
	 *
	 * @param mixed $current Existing responsive value, may be null.
	 * @param mixed $desktop New Desktop value.
	 *
	 * @return array
	 */
	private static function override_desktop( $current, $desktop ) {
		$base = is_array( $current ) ? $current : array();
		$unit = ( isset( $base['unit'] ) && is_array( $base['unit'] ) ) ? $base['unit'] : array();

		$base['device']            = ( isset( $base['device'] ) && is_array( $base['device'] ) ) ? $base['device'] : array();
		$base['device']['Desktop'] = $desktop;

		$unit['Desktop'] = isset( $unit['Desktop'] ) ? $unit['Desktop'] : '';
		$base['unit']    = $unit;

		return $base;
	}

	/**
	 * @param array $layout Layout entry.
	 *
	 * @return bool True for layouts that wrap onto more than one row.
	 */
	private static function is_multi_row( array $layout ) {
		return isset( $layout['rows'] ) && is_array( $layout['rows'] ) && count( $layout['rows'] ) > 1;
	}

	/**
	 * The layout a container falls back to for a given column count.
	 *
	 * @param int $count Column count.
	 *
	 * @return string Layout id, or '' when nothing fits.
	 */
	private function default_layout_for_count( $count ) {
		$equal_id = ( 1 === $count ) ? 'l-1' : 'l-' . $count . '-equal';
		if ( $this->catalog->layout( $equal_id ) ) {
			return $equal_id;
		}
		foreach ( $this->catalog->layouts() as $layout ) {
			if ( isset( $layout['columns'] ) && (int) $layout['columns'] === (int) $count ) {
				return $layout['id'];
			}
		}
		return '';
	}

	/**
	 * @param array $children Child nodes.
	 *
	 * @return int How many direct children are columns.
	 */
	private function count_columns( array $children ) {
		$n = 0;
		foreach ( $children as $child ) {
			$child = (array) $child;
			if ( isset( $child['block'] ) && self::COLUMN_BLOCK === $child['block'] ) {
				$n++;
			}
		}
		return $n;
	}

	/* ------------------------------------------------------------------ */
	/* Serialization                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * A block in parse_blocks() shape.
	 *
	 * Every Styble block is dynamic — save() returns InnerBlocks.Content or null
	 * — so the markup is a comment delimiter and the children, nothing else.
	 * The newline chunks reproduce what the editor's own serializer emits, so
	 * markup from this applier reads the same as markup the editor saved.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Attributes.
	 * @param array  $inner Inner blocks.
	 *
	 * @return array
	 */
	private function block( $name, array $attrs, array $inner ) {
		$content = array();
		$html    = '';

		if ( $inner ) {
			$content[] = "\n";
			$html     .= "\n";
			foreach ( $inner as $i => $unused ) {
				if ( $i > 0 ) {
					$content[] = "\n\n";
					$html     .= "\n\n";
				}
				$content[] = null;
			}
			$content[] = "\n";
			$html     .= "\n";
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => $content,
		);
	}

	/**
	 * @param array $blocks Block arrays.
	 *
	 * @return string
	 */
	private function serialize_all( array $blocks ) {
		if ( function_exists( 'serialize_blocks' ) ) {
			return serialize_blocks( $blocks );
		}

		$out = '';
		foreach ( $blocks as $block ) {
			$out .= $this->serialize_one( $block );
		}
		return $out;
	}

	/**
	 * Fallback serializer for the CLI harness, where WordPress is absent.
	 * In WordPress, core's serialize_blocks() is used instead.
	 *
	 * @param array $block Block array.
	 *
	 * @return string
	 */
	private function serialize_one( array $block ) {
		$inner_index = 0;
		$content     = '';

		foreach ( $block['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) ) {
				$content .= $chunk;
				continue;
			}
			$content .= $this->serialize_one( $block['innerBlocks'][ $inner_index ] );
			$inner_index++;
		}

		$attrs = $block['attrs']
			? self::encode_attrs( $block['attrs'] ) . ' '
			: '';

		if ( '' === $content ) {
			return sprintf( '<!-- wp:%s %s/-->', $block['blockName'], $attrs );
		}

		return sprintf(
			'<!-- wp:%s %s-->%s<!-- /wp:%s -->',
			$block['blockName'],
			$attrs,
			$content,
			$block['blockName']
		);
	}

	/**
	 * @param array $attrs Attributes.
	 *
	 * @return string
	 */
	private static function encode_attrs( array $attrs ) {
		if ( function_exists( 'serialize_block_attributes' ) ) {
			return serialize_block_attributes( $attrs );
		}
		return (string) json_encode( $attrs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

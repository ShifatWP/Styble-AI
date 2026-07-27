<?php
/**
 * Styble AI — catalog generator (Phase 0).
 *
 * Projects a small, LLM-friendly catalog of Styble blocks from three sources that
 * live in Styble Pro:
 *   1. blocks/Types/<Studly>/block.json  — attribute names, types, defaults.
 *   2. src/blocks/<slug>/index.js         — child `parent:` nesting declarations.
 *   3. src/blocks/<slug>/edit.jsx         — the useUniqueId() prefix.
 * Plus a curated parent-side allowedBlocks map and the container layout IDs from
 * src/blocks/container/shared/layouts.js.
 *
 * block.json carries the FULL attribute set (100–180+ attrs, mostly common) and
 * NO nesting info — neither is usable by an LLM as-is. This script emits only a
 * curated editable subset per allowlisted block, with real types/defaults, plus
 * a complete nesting map for validation.
 *
 * Run:  php scripts/generate-catalog.php [path-to-styble-pro]
 * Out:  catalog/catalog.json
 *
 * Explicit errors, no silent fallback: a missing plugin aborts; a missing
 * allowlisted attribute is reported and exits non-zero (catalog still written).
 *
 * @package Styble-AI
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

const CONTRACT_VERSION = '0.1.0';

/**
 * All 23 Styble Pro blocks (slug order).
 */
$all_blocks = array(
	'accordion', 'accordion-item', 'advanced-button', 'advanced-buttons',
	'advanced-image', 'advanced-tab-pane', 'advanced-tabs', 'advanced-text',
	'advanced-video', 'animated-heading', 'column', 'container', 'icon-list',
	'icon-list-item', 'icon-picker', 'image-gallery', 'image-gallery-item',
	'info-box', 'pagination', 'popup-builder', 'post-grid', 'separator',
	'styble-parent',
);

/**
 * v1 editable attribute allowlist per block. Names verified against block.json.
 * Empty array = block allowed for nesting but no AI-editable attrs yet.
 *
 * A content-bearing block MUST expose the attribute that carries its copy, or
 * the model can place it but not fill it and the block ships its placeholder
 * default: separator renders the literal word "Separator" (separatorText, with
 * separatorLabelEnable defaulting to true), and every icon-list-item renders
 * "List Item Text" (listText).
 */
$editable = array(
	'container'       => array( 'layout', 'layoutSelected', 'columns', 'direction', 'flexWrap', 'horizontalGap', 'verticalGap', 'containerWidth', 'containerCustomWidth' ),
	'column'          => array( 'columnWidth', 'columnFlex' ),
	'advanced-text'   => array( 'advancedTextContent', 'textHTMLTag', 'subHeading', 'subHeadingContent', 'subHeadingHTMLTag', 'textAliment', 'advancedTextColor' ),
	'advanced-buttons'=> array( 'direction', 'justify', 'gap', 'wrap', 'fullWidth' ),
	'advanced-button' => array( 'labelText', 'showLabel', 'addLink', 'buttonIcon', 'iconPosition', 'iconSize' ),
	'advanced-image'  => array( 'selectImage', 'selectImageId', 'imgAltText', 'imgAspectRatio', 'imgResolution', 'addLink' ),
	'info-box'        => array( 'layoutType', 'id', 'infoBoxPosition', 'contentAlign', 'showBadge', 'badgeText', 'badgePosition', 'gapBetween' ),
	'icon-picker'     => array( 'featuredIcon', 'iconColor', 'iconSize' ),
	'separator'       => array( 'separatorType', 'separatorLabelEnable', 'separatorText', 'separatorIconEnable', 'separatorIcon' ),
	'icon-list'       => array( 'layoutType', 'iconType', 'iconPosition', 'listGap', 'iconOrderedStyle' ),
	'icon-list-item'  => array( 'listText', 'listTextTag', 'listIcon', 'addLink' ),
);

/**
 * Parent-side allowedBlocks (declared on the parent in edit.jsx/templates.js,
 * NOT as a child `parent:`). Merged with the inverted child-parent map below.
 */
$parent_side = array(
	'container'      => array( 'column', 'container' ),
	'info-box'       => array( 'column', 'icon-picker', 'advanced-text', 'advanced-button', 'advanced-buttons' ),
	'advanced-text'  => array( 'icon-picker' ),
	'advanced-buttons' => array( 'advanced-button' ),
	'accordion'      => array( 'accordion-item' ),
	'advanced-tabs'  => array( 'advanced-tab-pane' ),
	'icon-list'      => array( 'icon-list-item' ),
	'image-gallery'  => array( 'image-gallery-item' ),
	'popup-builder'  => array( 'advanced-text', 'advanced-buttons', 'icon-picker', 'advanced-image', 'info-box', 'separator', 'container', 'column' ),
);

// --- Locate Styble Pro --------------------------------------------------------

$styble_pro = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__, 2 ) . '/styble-pro';
if ( ! is_dir( $styble_pro . '/blocks/Types' ) ) {
	fwrite( STDERR, "ERROR: Styble Pro not found at: {$styble_pro}\n       Pass its path as arg 1.\n" );
	exit( 1 );
}

// --- Helpers ------------------------------------------------------------------

/**
 * slug 'advanced-tab-pane' -> Studly 'Advanced_Tab_Pane'.
 */
function studly( $slug ) {
	return implode( '_', array_map( 'ucfirst', explode( '-', $slug ) ) );
}

/**
 * Extract child `parent:` array from a block's index.js. Returns [] if none.
 */
function read_parents( $styble_pro, $slug ) {
	$file = "{$styble_pro}/src/blocks/{$slug}/index.js";
	if ( ! is_file( $file ) ) {
		return array();
	}
	$src = file_get_contents( $file );
	if ( ! preg_match( '/parent:\s*\[([^\]]*)\]/', $src, $m ) ) {
		return array();
	}
	preg_match_all( "/'styble\/([^']+)'/", $m[1], $mm );
	return $mm[1];
}

/**
 * Can this block hold children at all? True when its edit component renders
 * InnerBlocks. The validator needs this to tell "no children declared yet"
 * (column: open, confined by its child's own parent: rule) apart from "leaf"
 * (advanced-button: children are always illegal).
 */
function read_accepts_children( $styble_pro, $slug ) {
	foreach ( array( 'edit.jsx', 'edit.js' ) as $name ) {
		$file = "{$styble_pro}/src/blocks/{$slug}/{$name}";
		if ( is_file( $file ) ) {
			return (bool) preg_match( '/\bInnerBlocks\b|\buseInnerBlocksProps\b/', file_get_contents( $file ) );
		}
	}
	return false;
}

/**
 * Extract the block's useUniqueId() prefix from its edit component.
 *
 * The prefix is NOT derivable from the slug — animated-heading uses
 * 'sp-styble-heading-' and image-gallery-item uses 'sp-styble-gallery-item-'.
 * Two blocks (pagination, styble-parent) omit the argument entirely and take the
 * hook's default. Headless PHP serialization has no mount effect to run, so it
 * must write uniqueId itself, which means it needs this string per block.
 *
 * Returns null when the block never calls the hook (advanced-tab-pane): that
 * block carries no uniqueId and the applier must not invent one.
 */
function read_unique_id_prefix( $styble_pro, $slug, $default_prefix ) {
	foreach ( array( 'edit.jsx', 'edit.js' ) as $name ) {
		$file = "{$styble_pro}/src/blocks/{$slug}/{$name}";
		if ( ! is_file( $file ) ) {
			continue;
		}
		$src = file_get_contents( $file );
		// 4th argument present: useUniqueId(clientId, uniqueId, setAttributes, 'prefix-').
		if ( preg_match( '/useUniqueId\(\s*[^)]*?,\s*[^,)]+,\s*[^,)]+,\s*[\'"]([^\'"]+)[\'"]/s', $src, $m ) ) {
			return $m[1];
		}
		// Hook called with no prefix: falls back to the hook's own default.
		if ( preg_match( '/useUniqueId\(/', $src ) ) {
			return $default_prefix;
		}
	}
	return null;
}

/**
 * Read the useUniqueId hook's default prefix so it is never hardcoded here.
 */
function read_unique_id_default( $styble_pro ) {
	$file = "{$styble_pro}/src/hooks/useUniqueId.js";
	if ( is_file( $file ) && preg_match( '/prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', file_get_contents( $file ), $m ) ) {
		return $m[1];
	}
	return null;
}

/**
 * Return the balanced `[...]` literal starting at $pos, or '' if $pos is not '['.
 */
function balanced_brackets( $src, $pos ) {
	if ( ! isset( $src[ $pos ] ) || '[' !== $src[ $pos ] ) {
		return '';
	}
	$depth = 0;
	$len   = strlen( $src );
	for ( $i = $pos; $i < $len; $i++ ) {
		if ( '[' === $src[ $i ] ) {
			$depth++;
		} elseif ( ']' === $src[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $src, $pos, $i - $pos + 1 );
			}
		}
	}
	return '';
}

/**
 * Parse layouts from container/shared/layouts.js.
 *
 * Each entry carries `rows` (array of rows of percentage widths). The flat list
 * maps 1:1 onto column children, so `columns` = count(flat) is the number of
 * styble/column children a container using this layout must have, and `widths`
 * is what the applier writes into each column's columnWidth.
 */
function read_layouts( $styble_pro ) {
	$file = "{$styble_pro}/src/blocks/container/shared/layouts.js";
	if ( ! is_file( $file ) ) {
		return array();
	}
	$src = file_get_contents( $file );

	// Scope to the LAYOUTS literal so later id lists cannot leak in.
	$start = strpos( $src, 'const LAYOUTS = [' );
	if ( false === $start ) {
		return array();
	}
	$src = substr( $src, $start );

	$out    = array();
	$offset = 0;
	while ( preg_match( "/id:\s*'([^']+)',\s*label:\s*'([^']+)',\s*rows:\s*/", $src, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$value_at = $m[0][1] + strlen( $m[0][0] );
		$literal  = balanced_brackets( $src, $value_at );
		$offset   = $value_at + max( 1, strlen( $literal ) );
		if ( '' === $literal ) {
			continue;
		}
		// Numeric literals only — JSON-decodable once trailing commas are gone.
		$rows = json_decode( preg_replace( '/,\s*([\]\}])/', '$1', $literal ), true );
		if ( ! is_array( $rows ) || ! $rows ) {
			continue;
		}
		$widths = array_merge( ...$rows );
		$out[]  = array(
			'id'      => $m[1][0],
			'label'   => $m[2][0],
			'rows'    => $rows,
			'columns' => count( $widths ),
			'widths'  => $widths,
		);
	}
	return $out;
}

// --- Build nesting map (invert child-parents ∪ curated parent-side) -----------

$children_of = $parent_side;
$parents_of  = array();
foreach ( $all_blocks as $slug ) {
	$parents = read_parents( $styble_pro, $slug );
	if ( $parents ) {
		$parents_of[ $slug ] = $parents;
		foreach ( $parents as $p ) {
			$children_of[ $p ][] = $slug;
		}
	}
}
foreach ( $children_of as $p => $list ) {
	$children_of[ $p ] = array_values( array_unique( $list ) );
}

// --- Build per-block catalog --------------------------------------------------

$missing = array();
$blocks  = array();

$uid_default = read_unique_id_default( $styble_pro );
if ( null === $uid_default ) {
	fwrite( STDERR, "ERROR: could not read the default prefix from src/hooks/useUniqueId.js.\n" );
	exit( 1 );
}

foreach ( $all_blocks as $slug ) {
	$name       = 'styble/' . $slug;
	$json_path  = "{$styble_pro}/blocks/Types/" . studly( $slug ) . '/block.json';
	$has_json   = is_file( $json_path );
	$defs       = array();
	$title      = ucwords( str_replace( '-', ' ', $slug ) );
	$desc       = '';

	if ( $has_json ) {
		$json  = json_decode( file_get_contents( $json_path ), true );
		$title = isset( $json['title'] ) ? $json['title'] : $title;
		$desc  = isset( $json['description'] ) ? $json['description'] : '';
		$attrs = isset( $json['attributes'] ) ? $json['attributes'] : array();

		$wanted = isset( $editable[ $slug ] ) ? $editable[ $slug ] : array();

		// Iterate block.json, not the allowlist: the editor's serializer walks
		// blockType.attributes in declaration order, so the PHP applier has to
		// emit them in that same order to produce byte-identical markup.
		foreach ( $attrs as $attr => $schema ) {
			if ( ! in_array( $attr, $wanted, true ) ) {
				continue;
			}
			$def = array(
				'type'    => isset( $schema['type'] ) ? $schema['type'] : 'mixed',
				'default' => isset( $schema['default'] ) ? $schema['default'] : null,
			);
			// Sourced and role:local attributes never reach the block comment.
			if ( isset( $schema['source'] ) ) {
				$def['source'] = $schema['source'];
			}
			if ( isset( $schema['role'] ) ) {
				$def['role'] = $schema['role'];
			}
			$defs[ $attr ] = $def;
		}

		foreach ( $wanted as $attr ) {
			if ( ! isset( $attrs[ $attr ] ) ) {
				$missing[] = "{$slug}.{$attr}";
			}
		}
	}

	$blocks[ $slug ] = array(
		'name'            => $name,
		'title'           => $title,
		'description'     => $desc,
		'aiAllowlist'     => array_key_exists( $slug, $editable ),
		'hasBlockJson'    => $has_json,
		'acceptsChildren' => read_accepts_children( $styble_pro, $slug ),
		'uniqueIdPrefix'  => read_unique_id_prefix( $styble_pro, $slug, $uid_default ),
		'editable'        => $defs,
		'allowedChildren' => isset( $children_of[ $slug ] ) ? array_map( fn( $s ) => 'styble/' . $s, $children_of[ $slug ] ) : array(),
		'parents'         => isset( $parents_of[ $slug ] ) ? array_map( fn( $s ) => 'styble/' . $s, $parents_of[ $slug ] ) : array(),
	);
}

// --- Assemble + write ---------------------------------------------------------

$catalog = array(
	'contractVersion' => CONTRACT_VERSION,
	'source'          => array(
		'styblePro'    => basename( $styble_pro ),
		'attributes'   => 'blocks/Types/<Studly>/block.json',
		'nesting'      => 'src/blocks/<slug>/index.js parent: + curated parent-side allowedBlocks',
		'uniqueId'     => 'src/blocks/<slug>/edit.jsx useUniqueId() 4th argument',
		'note'         => 'Generated by scripts/generate-catalog.php. Do not hand-edit.',
	),
	// How the editor mints uniqueId, so headless PHP can reproduce it. The editor
	// runs this in a mount effect and dedupes across the whole block tree; PHP has
	// neither, so the applier must guarantee uniqueness itself.
	'uniqueIdRule'    => array(
		'format'        => '<uniqueIdPrefix><last dash-segment of clientId>',
		'defaultPrefix' => $uid_default,
		'source'        => 'src/hooks/useUniqueId.js',
		'note'          => 'Scoped CSS keys off uniqueId — a block that loses it loses its styling.',
	),
	'layouts'         => read_layouts( $styble_pro ),
	// Static reference; live values come from styble_global_settings at prompt time.
	'brandDefaults'   => array(
		'colorSlugs' => array( 'primary', 'secondary', 'accent', 'text-color', 'heading-color', 'border-color', 'light-neutral', 'dark-neutral', 'white' ),
		'typeScale'  => array( 'heading-1', 'heading-2', 'heading-3', 'heading-4', 'heading-5', 'heading-6', 'body-1', 'body-2', 'body-3', 'body-4' ),
	),
	'blocks'          => $blocks,
);

$out_dir = dirname( __DIR__ ) . '/catalog';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0755, true );
}
$out_file = $out_dir . '/catalog.json';
file_put_contents( $out_file, json_encode( $catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

// --- Report -------------------------------------------------------------------

$allow_count = count( array_filter( $blocks, fn( $b ) => $b['aiAllowlist'] ) );
fwrite( STDOUT, "Catalog written: {$out_file}\n" );
fwrite( STDOUT, sprintf( "  blocks: %d total, %d in AI allowlist\n", count( $blocks ), $allow_count ) );
fwrite( STDOUT, sprintf( "  layouts: %d\n", count( $catalog['layouts'] ) ) );

if ( $missing ) {
	fwrite( STDERR, "\nWARNING: editable attrs not found in block.json (fix the allowlist):\n" );
	foreach ( $missing as $m ) {
		fwrite( STDERR, "  - {$m}\n" );
	}
	exit( 2 );
}

<?php
/**
 * Styble AI — invariants of the generated prompt and tool schema.
 *
 * These are the things that are cheap to get wrong, expensive to notice, and
 * impossible for the fixture suite to catch: the fixtures check trees the model
 * already produced, and nothing checks what the model was ASKED for.
 *
 * The first assertion here exists because of a real regression. Declaring the
 * attribute vocabulary as `properties` on `attrs` looked like an obvious
 * improvement — the tool schema should describe the data, after all. But `attrs`
 * is one object shared by every block in the tree, so the only list that fits is
 * the union across all of them, and a union tells the model that `textHTMLTag`
 * is a legal key on a container. It promptly put four content attributes on a
 * root container and every section of a page failed.
 *
 * No network, no API key, no WordPress.
 *
 * Run:  php scripts/test-prompt.php
 * Exit: 0 when every invariant holds.
 *
 * @package Styble_AI
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

define( 'STYBLE_AI_CLI', true );

$root = dirname( __DIR__ );
require_once $root . '/includes/class-catalog.php';
require_once $root . '/includes/class-brand-context.php';
require_once $root . '/includes/class-prompt.php';

$catalog = Styble_AI_Catalog::from_file( $root . '/catalog/catalog.json' );
$prompt  = new Styble_AI_Prompt( $catalog );
$schema  = $prompt->tool_schema();
$text    = $prompt->system_prompt();

$failures = 0;
$checks   = 0;

/**
 * @param bool   $ok    Assertion result.
 * @param string $label What was being checked.
 */
function check( $ok, $label ) {
	global $failures, $checks;
	$checks++;
	if ( $ok ) {
		echo "  ok   {$label}\n";
		return;
	}
	$failures++;
	echo "  FAIL {$label}\n";
}

/**
 * Walk to the node schema at a given nesting depth.
 *
 * @param array $schema Tool schema.
 * @param int   $depth  How many `children` hops to take.
 *
 * @return array|null
 */
function node_at_depth( array $schema, $depth ) {
	$node = $schema['properties']['root'];
	for ( $i = 0; $i < $depth; $i++ ) {
		if ( ! isset( $node['properties']['children']['items'] ) ) {
			return null;
		}
		$node = $node['properties']['children']['items'];
	}
	return $node;
}

echo "Tool schema\n";

check( 'object' === $schema['type'], 'the envelope is an object' );
check( array( 'version', 'root' ) === $schema['required'], 'version and root are both required' );
check( false === $schema['additionalProperties'], 'no extra envelope keys — model chatter is rejected' );

$root_node = $schema['properties']['root'];
check( array( 'block' ) === $root_node['required'], 'a node requires only `block`; attrs and children are optional' );
check( false === $root_node['additionalProperties'], 'a node takes no keys beyond block/attrs/children' );

$enum = $root_node['properties']['block']['enum'];
sort( $enum );
$allow = $catalog->allowlisted_names();
sort( $allow );
check( $enum === $allow, 'the block enum is exactly the catalog allowlist' );

// THE REGRESSION GUARD. See the file header.
$attrs = $root_node['properties']['attrs'];
check( 'object' === $attrs['type'], 'attrs is an object' );
// Both extremes were measured and both fail: the full union puts textHTMLTag on
// a container, and no properties at all makes a weak model return every attrs
// empty. Only the content attributes belong here.
$declared = array_keys( isset( $attrs['properties'] ) ? $attrs['properties'] : array() );
sort( $declared );
check(
	array( 'accordionTitle', 'advancedTextContent', 'imgAltText', 'labelText', 'listText', 'separatorText' ) === $declared,
	'attrs declares exactly the content attributes, no styling: ' . implode( ', ', $declared )
);
$styling_leak = array_intersect( $declared, array( 'textHTMLTag', 'textAliment', 'containerWidth', 'layout', 'layoutType', 'sectionPadding' ) );
check( ! $styling_leak, 'no styling attribute leaked into the schema' . ( $styling_leak ? ' — ' . implode( ', ', $styling_leak ) : '' ) );
check(
	! isset( $attrs['additionalProperties'] ) || true === $attrs['additionalProperties'],
	'attrs does not close itself off — the validator reports unknown keys with a better message than the provider can'
);
check(
	false !== stripos( $attrs['description'], 'this block' ),
	'the attrs description says the keys belong to THIS block'
);
check(
	false !== stripos( $attrs['description'], 'true/false' ) || false !== stripos( $attrs['description'], '"true"' ),
	'the attrs description warns that booleans are not quoted strings'
);

// Depth has to actually reach the deepest legal tree:
// container > column > info-box > advanced-text > icon-picker.
check( null !== node_at_depth( $schema, 4 ), 'the node schema nests at least 5 levels deep' );
check( null === node_at_depth( $schema, Styble_AI_Prompt::SCHEMA_DEPTH ), 'nesting stops at SCHEMA_DEPTH' );

echo "\nSystem prompt\n";

foreach ( $catalog->allowlisted_names() as $name ) {
	check( false !== strpos( $text, '`' . $name . '`' ), "{$name} appears in the block reference" );
}

// Every verified value list must reach the model. The catalog knowing them is
// no use if the prompt does not print them — that was the state that let
// "centre" and "contained" through for months.
$missing_values = array();
foreach ( $catalog->allowlisted_names() as $name ) {
	foreach ( $catalog->editable_attrs( $name ) as $attr => $def ) {
		if ( empty( $def['values'] ) ) {
			continue;
		}
		$owned = isset( Styble_AI_Prompt::APPLIER_OWNED[ $name ] ) ? Styble_AI_Prompt::APPLIER_OWNED[ $name ] : array();
		if ( in_array( $attr, $owned, true ) ) {
			continue;
		}
		if ( false === strpos( $text, $attr . ' (' . implode( '|', $def['values'] ) . ')' ) ) {
			$missing_values[] = "{$name}.{$attr}";
		}
	}
}
check( ! $missing_values, 'every verified value list is printed in the prompt' . ( $missing_values ? ' — missing: ' . implode( ', ', $missing_values ) : '' ) );

// Applier-owned attributes must never be advertised: listing one and then
// telling the model not to touch it is a contradiction it resolves wrongly.
$advertised = array();
foreach ( Styble_AI_Prompt::APPLIER_OWNED as $block => $attrs_owned ) {
	$slug = str_replace( 'styble/', '', $block );
	if ( ! preg_match( '/^- `' . preg_quote( $block, '/' ) . '` — attrs: ([^;]*)/m', $text, $m ) ) {
		continue;
	}
	foreach ( $attrs_owned as $owned ) {
		if ( preg_match( '/\b' . preg_quote( $owned, '/' ) . '\b/', $m[1] ) ) {
			$advertised[] = "{$slug}.{$owned}";
		}
	}
}
check( ! $advertised, 'no applier-owned attribute is advertised' . ( $advertised ? ' — leaked: ' . implode( ', ', $advertised ) : '' ) );

check( false !== stripos( $text, 'sectionPadding' ), 'the prompt asks for sectionPadding' );
check( false !== stripos( $text, 'omit `layout`' ), 'the prompt tells the model it may omit layout' );
check( false !== stripos( $text, 'belong to their own block' ), 'the prompt states attributes belong to their own block' );

foreach ( $catalog->layout_ids() as $id ) {
	if ( false === strpos( $text, '`' . $id . '`' ) ) {
		check( false, "layout {$id} is listed in the prompt" );
		break;
	}
}
check( true, 'every layout id is listed in the prompt' );

// ── Colour shape disambiguation ───────────────────────────────────────────────
//
// The 2026-07-29 baseline lost 22 of its 26 validator errors to ONE ambiguity:
// `textFillBg` and `subHeadingBg` take the `(background)` OBJECT and the prompt
// documents that shape prominently, while `listTextColor`, `titleTextColor`,
// `iconColor` and four others are plain strings that carried NO tag — so the
// model generalised the object shape to all of them.
//
// These checks are structural, not textual: they walk the catalog and assert
// every colour-named string attribute is tagged wherever it is advertised. A new
// colour attribute added to the allowlist is therefore covered the moment it
// appears, with no test edit — which is the property the old hand-written rule
// sentence did not have.
$colour_strings = array();
$object_colours = array();
foreach ( $catalog->allowlisted_names() as $name ) {
	foreach ( $catalog->editable_attrs( $name ) as $attr => $def ) {
		$type = isset( $def['type'] ) ? $def['type'] : 'mixed';
		if ( ! preg_match( '/colou?r/i', $attr ) ) {
			continue;
		}
		if ( 'string' === $type ) {
			$colour_strings[ $attr ] = true;
		} elseif ( 'object' === $type ) {
			$object_colours[ $attr ] = true;
		}
	}
}
$colour_strings = array_keys( $colour_strings );

check( ! empty( $colour_strings ), 'the catalog has colour-named string attributes to disambiguate' );

// The premise the tag rule rests on: no colour-NAMED attribute is object-typed,
// so "name matches /colour/ and type is string" can never mis-tag a background.
// If a future block breaks this, the rule in attr_tag() needs a real allowlist
// and this check is where that becomes visible.
check(
	empty( $object_colours ),
	'no colour-named attribute is object-typed (the tag rule stays unambiguous)'
		. ( $object_colours ? ' — found: ' . implode( ', ', array_keys( $object_colours ) ) : '' )
);

$untagged = array();
foreach ( $colour_strings as $attr ) {
	// Every advertised occurrence must carry the tag — not just the first. An
	// attribute listed on two blocks with the tag on only one is exactly the
	// inconsistency that taught the model to guess.
	if ( preg_match_all( '/\b' . preg_quote( $attr, '/' ) . '\b(?! \(colour string\))/', $text, $m ) ) {
		// Occurrences outside a block's attr list (prose mentions) are fine, so
		// only count the ones inside an "— attrs:" line.
		foreach ( explode( "\n", $text ) as $line ) {
			if ( false === strpos( $line, '— attrs:' ) ) {
				continue;
			}
			if ( preg_match( '/\b' . preg_quote( $attr, '/' ) . '\b(?! \(colour string\))/', $line ) ) {
				$untagged[] = $attr;
				break;
			}
		}
	}
}
check(
	! $untagged,
	'every colour string attribute is tagged (colour string) in every block list'
		. ( $untagged ? ' — untagged: ' . implode( ', ', array_unique( $untagged ) ) : '' )
);

// The shapes section must define the tag, and must name the full set — the model
// matches an attribute name against that list rather than inferring from a
// neighbour.
check( false !== strpos( $text, '`(colour string)`' ), 'the shapes section defines (colour string)' );
$missing_from_shapes = array();
foreach ( $colour_strings as $attr ) {
	if ( false === strpos( $text, '`' . $attr . '`' ) ) {
		$missing_from_shapes[] = $attr;
	}
}
check(
	! $missing_from_shapes,
	'every colour string attribute is named in the shapes section'
		. ( $missing_from_shapes ? ' — missing: ' . implode( ', ', $missing_from_shapes ) : '' )
);

// The two shapes must be stated as mutually exclusive somewhere the model reads.
// Without this the tags exist but nothing says the object is wrong on a string.
check(
	false !== stripos( $text, 'PLAIN STRING, never an object' ),
	'the prompt states (colour string) is never an object'
);
check(
	false !== stripos( $text, 'Go by the tag' ),
	'the prompt tells the model to go by the tag, not by "it is a colour"'
);

// The background pair must still be tagged (background) — the fix must not have
// flattened both shapes into one.
foreach ( array( 'textFillBg', 'subHeadingBg' ) as $bg_attr ) {
	check(
		false !== strpos( $text, $bg_attr . ' (background)' ),
		"{$bg_attr} is still tagged (background)"
	);
}

echo "\n";
printf( "%d checks, %d failed\n", $checks, $failures );
exit( $failures ? 1 : 0 );

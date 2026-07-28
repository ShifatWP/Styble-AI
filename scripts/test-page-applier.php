<?php
/**
 * Styble AI — exercise the headless applier.
 *
 * The headless applier is the one place that has to reproduce, without a
 * browser, two things the editor did for free: the container's layout maths and
 * the `uniqueId` every Styble block assigns itself on mount. Both fail silently
 * — a page with the wrong column widths still saves, and a page with no
 * uniqueIds still parses; it just renders unstyled. So they are asserted here.
 *
 * No network, no API key, no WordPress. Serialization therefore uses the
 * applier's own fallback rather than core's serialize_blocks(); the block ARRAYS
 * are what this suite actually checks, and those are what core serializes.
 *
 * Run:  php scripts/test-page-applier.php
 * Exit: 0 when every case passes.
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
require_once $root . '/includes/class-page-applier.php';

$catalog = Styble_AI_Catalog::from_file( $root . '/catalog/catalog.json' );
$applier = new Styble_AI_Page_Applier( $catalog );

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
 * Collect every uniqueId in a block tree.
 *
 * @param array $blocks Block arrays.
 *
 * @return string[]
 */
function unique_ids( array $blocks ) {
	$out = array();
	foreach ( $blocks as $block ) {
		if ( isset( $block['attrs']['uniqueId'] ) ) {
			$out[] = $block['attrs']['uniqueId'];
		}
		$out = array_merge( $out, unique_ids( $block['innerBlocks'] ) );
	}
	return $out;
}

/**
 * A two-column section: container > 2 columns, each with a heading.
 *
 * @param string $layout Layout id.
 *
 * @return array emit_layout envelope.
 */
function two_column_tree( $layout = 'l-2-60-40' ) {
	return array(
		'version' => '0.1.0',
		'root'    => array(
			'block'    => 'styble/container',
			'attrs'    => array( 'layout' => $layout ),
			'children' => array(
				array(
					'block'    => 'styble/column',
					'children' => array(
						array(
							'block' => 'styble/advanced-text',
							'attrs' => array(
								'advancedTextContent' => 'Pricing that scales with you',
								'textHTMLTag'         => 'h1',
							),
						),
					),
				),
				array(
					'block'    => 'styble/column',
					'children' => array(
						array(
							'block' => 'styble/advanced-image',
							'attrs' => array( 'imgAltText' => 'A team reviewing a dashboard' ),
						),
					),
				),
			),
		),
	);
}

echo "Layout maths\n";

$blocks    = $applier->to_blocks( array( array( 'id' => 'hero', 'tree' => two_column_tree() ) ) );
$container = $blocks[0];

check( 1 === count( $blocks ), 'one section produces one root block' );
check( 'styble/container' === $container['blockName'], 'the root is a container' );
check( 'l-2-60-40' === $container['attrs']['layout'], 'the model layout is kept' );
check( true === $container['attrs']['layoutSelected'], 'layoutSelected is applier-owned and set' );
check( 2 === $container['attrs']['columns'], 'columns comes from the layout, not the model' );
check( 'row' === $container['attrs']['direction']['device']['Desktop'], 'direction Desktop is row' );
check( 'nowrap' === $container['attrs']['flexWrap']['device']['Desktop'], 'single-row layout does not wrap' );

$widths = array(
	$container['innerBlocks'][0]['attrs']['columnWidth']['device']['Desktop'],
	$container['innerBlocks'][1]['attrs']['columnWidth']['device']['Desktop'],
);
check( array( 60, 40 ) === $widths, 'column widths come from the layout preset (60/40)' );
check( 100 === $container['innerBlocks'][0]['attrs']['columnWidth']['device']['Mobile'], 'columns are full width on mobile' );
check( '%' === $container['innerBlocks'][0]['attrs']['columnWidth']['unit']['Desktop'], 'column widths carry a unit' );

// A one-column layout leaves the width empty so flex decides — same rule as
// assets/applier.js layoutColumnWidth().
$single = $applier->to_blocks(
	array(
		array(
			'id'   => 'cta',
			'tree' => array(
				'version' => '0.1.0',
				'root'    => array(
					'block' => 'styble/container',
					'attrs' => array( 'layout' => 'l-1' ),
				),
			),
		),
	)
);
check( 1 === count( $single[0]['innerBlocks'] ), 'an empty container is seeded with the layout\'s columns' );
check( 'styble/column' === $single[0]['innerBlocks'][0]['blockName'], 'the seeded child is a column' );
check( '' === $single[0]['innerBlocks'][0]['attrs']['columnWidth']['device']['Desktop'], 'a single column keeps its width empty' );

echo "\nuniqueId\n";

// container + 2 columns + heading + image.
$ids = unique_ids( $blocks );
check( 5 === count( $ids ), 'every block that calls useUniqueId() got one' );
check( count( $ids ) === count( array_unique( $ids ) ), 'uniqueIds are unique within a section' );
check( 0 === strpos( $container['attrs']['uniqueId'], 'sp-styble-container-' ), 'the container uses its own catalog prefix' );
check( 0 === strpos( $container['innerBlocks'][0]['attrs']['uniqueId'], 'sp-styble-column-' ), 'a column uses the column prefix' );
check( 12 === strlen( substr( $container['attrs']['uniqueId'], strlen( 'sp-styble-container-' ) ) ), 'the suffix is 12 characters, like the editor\'s clientId segment' );

// Stability is what makes regenerating one section safe: scoped CSS keys off
// uniqueId, so a rebuild that renumbered other sections would restyle the page.
$again = $applier->to_blocks( array( array( 'id' => 'hero', 'tree' => two_column_tree() ) ) );
check( unique_ids( $again ) === $ids, 'the same section id yields the same uniqueIds on rebuild' );

$two = $applier->to_blocks(
	array(
		array( 'id' => 'hero', 'tree' => two_column_tree() ),
		array( 'id' => 'plans', 'tree' => two_column_tree() ),
	)
);
$all = unique_ids( $two );
check( count( $all ) === count( array_unique( $all ) ), 'identical trees in different sections do not collide' );

echo "\nSection padding\n";

// sectionPadding is 0 on all four sides by default, so an unset one is not a
// neutral choice — it is why generated pages rendered flush and cramped.
$pad = $container['attrs']['sectionPadding'];
check( is_array( $pad ), 'the root container always gets sectionPadding' );
check( 80 === $pad['device']['Desktop']['top'], 'desktop top padding is the section band' );
check( 48 === $pad['device']['Mobile']['top'], 'mobile padding is tighter' );
check( 'px' === $pad['unit']['Desktop'], 'padding carries a unit' );

$inner_column = $container['innerBlocks'][0];
check( ! isset( $inner_column['attrs']['sectionPadding'] ), 'only the root is padded, not its children' );

// A model that DID set padding must keep it — this is a backstop, not a policy.
$explicit = two_column_tree();
$explicit['root']['attrs']['sectionPadding'] = array(
	'device' => array( 'Desktop' => array( 'top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10 ) ),
	'unit'   => array( 'Desktop' => 'px' ),
);
$kept = $applier->to_blocks( array( array( 'id' => 'hero', 'tree' => $explicit ) ) );
check( 10 === $kept[0]['attrs']['sectionPadding']['device']['Desktop']['top'], 'model-supplied padding is not overwritten' );

// A section whose root is not a container is left alone.
$leaf = $applier->to_blocks(
	array(
		array(
			'id'   => 'rule',
			'tree' => array(
				'version' => '0.1.0',
				'root'    => array( 'block' => 'styble/separator', 'attrs' => array( 'separatorText' => 'Or' ) ),
			),
		),
	)
);
check( ! isset( $leaf[0]['attrs']['sectionPadding'] ), 'a non-container root is not given container padding' );

echo "\nSparse attributes\n";

$text = $container['innerBlocks'][0]['innerBlocks'][0];
check( 'styble/advanced-text' === $text['blockName'], 'the heading survived the walk' );
check(
	array( 'advancedTextContent', 'textHTMLTag', 'uniqueId' ) === array_keys( $text['attrs'] ),
	'only what the model set, plus uniqueId, is written — defaults stay in block.json'
);

echo "\nSerialization\n";

$markup = $applier->to_markup( array( array( 'id' => 'hero', 'tree' => two_column_tree() ) ) );

check( 0 === strpos( $markup, '<!-- wp:styble/container ' ), 'markup opens with the container delimiter' );
check( substr_count( $markup, '<!-- wp:' ) === substr_count( $markup, '<!-- /wp:' ) + substr_count( $markup, '/-->' ), 'every open delimiter is closed or self-closing' );
check( false === strpos( $markup, '<!-- wp:core/' ), 'no core blocks leaked in' );
check( false !== strpos( $markup, '"uniqueId":"sp-styble-advanced-image-' ), 'leaf blocks serialize their uniqueId' );
check( false !== strpos( $markup, '/-->' ), 'leaf blocks are self-closing, as the editor writes them' );

// Every inline JSON payload must parse — a broken one is an invalid block.
preg_match_all( '/<!-- wp:[a-z0-9\/-]+ (\{.*?\}) ?\/?-->/', $markup, $matches );
$bad = 0;
foreach ( $matches[1] as $json ) {
	if ( null === json_decode( $json, true ) ) {
		$bad++;
	}
}
check( 0 === $bad, 'every inline attribute payload is valid JSON' );

// A section with no tree yet must not produce anything: the page is serialized
// after every section, while the rest are still building.
$partial = $applier->to_markup(
	array(
		array( 'id' => 'hero', 'tree' => two_column_tree() ),
		array( 'id' => 'plans', 'tree' => null ),
	)
);
check( 1 === substr_count( $partial, '<!-- wp:styble/container ' ), 'an unbuilt section serializes to nothing' );

echo "\n";
printf( "%d checks, %d failed\n", $checks, $failures );
exit( $failures ? 1 : 0 );

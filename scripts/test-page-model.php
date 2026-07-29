<?php
/**
 * Styble AI — the canonical page model: uid addressing and granular edits.
 *
 * The whole model rests on one claim: that a block's uniqueId, which the applier
 * derives from md5(sectionId|nodePath), can be recomputed later to find that same
 * block again. If that reverse walk is ever off by one child index, update_block
 * silently edits the WRONG block — a page that still validates, still renders, and
 * is quietly wrong. So the resolution is asserted here node by node, and against
 * the applier's own output rather than against a second copy of the arithmetic.
 *
 * The store is doubled in memory rather than stubbed, so this needs no database:
 * Styble_AI_Page_Model only ever calls get() and set_section_tree() on it.
 *
 * Run:  php scripts/test-page-model.php
 * Exit: 0 when every case passes.
 *
 * @package Styble_AI
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

define( 'STYBLE_AI_CLI', true );
// The pipeline classes are WP-guarded; satisfy the guard, then stub what they call.
define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
	public function get_error_data() {
		return $this->data; }
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

$root = dirname( __DIR__ );
require_once $root . '/includes/class-catalog.php';
require_once $root . '/includes/class-validation-result.php';
require_once $root . '/includes/class-validator.php';
require_once $root . '/includes/class-page-applier.php';
require_once $root . '/includes/class-page-store.php';
require_once $root . '/includes/class-page-model.php';

/**
 * In-memory stand-in for the page store.
 *
 * Subclassed rather than reimplemented so that a change to the real store's
 * get()/set_section_tree() signatures breaks this file loudly.
 */
class Fake_Page_Store extends Styble_AI_Page_Store {

	/** @var array */
	public $state = array( 'title' => 'Pricing', 'sections' => array() );

	/** @var int */
	public $writes = 0;

	public function get( $post_id ) {
		return $this->state;
	}

	public function set_section_tree( $post_id, $section_id, array $tree ) {
		foreach ( $this->state['sections'] as $i => $section ) {
			if ( $section['id'] === $section_id ) {
				$this->state['sections'][ $i ]['tree'] = $tree;
				$this->writes++;
				return true;
			}
		}
		return new WP_Error( 'styble_ai_no_section', 'That section is no longer part of the page.' );
	}
}

$failures = 0;
$checks   = 0;

/**
 * @param bool   $ok    Assertion result.
 * @param string $label What was being checked.
 * @param string $note  Optional detail.
 */
function check( $ok, $label, $note = '' ) {
	global $failures, $checks;
	$checks++;
	printf( "  %-4s %-58s %s\n", $ok ? 'ok' : 'FAIL', $label, $note );
	if ( ! $ok ) {
		$failures++;
	}
}

/**
 * A two-column hero: container > 2 columns, one with a heading, one with a button.
 *
 * @return array emit_layout envelope.
 */
function hero_tree() {
	return array(
		'version' => '0.1.0',
		'root'    => array(
			'block'    => 'styble/container',
			'attrs'    => array( 'layout' => 'l-2-equal' ),
			'children' => array(
				array(
					'block'    => 'styble/column',
					'children' => array(
						array(
							'block' => 'styble/advanced-text',
							'attrs' => array( 'advancedTextContent' => 'Ship faster', 'textHTMLTag' => 'h1' ),
						),
						array(
							'block' => 'styble/advanced-text',
							'attrs' => array( 'advancedTextContent' => 'Deploy on every commit.', 'textHTMLTag' => 'p' ),
						),
					),
				),
				array(
					'block'    => 'styble/column',
					'children' => array(
						array(
							'block'    => 'styble/advanced-buttons',
							'children' => array(
								array(
									'block' => 'styble/advanced-button',
									'attrs' => array( 'labelText' => 'Start free' ),
								),
							),
						),
					),
				),
			),
		),
	);
}

$catalog = Styble_AI_Catalog::from_file( $root . '/catalog/catalog.json' );
$applier = new Styble_AI_Page_Applier( $catalog );

$store                     = new Fake_Page_Store( $applier );
$store->state['sections']  = array(
	array( 'id' => 'hero', 'heading' => 'Hero', 'brief' => 'x', 'tree' => hero_tree() ),
	array( 'id' => 'plans', 'heading' => 'Plans', 'brief' => 'x', 'tree' => hero_tree() ),
	array( 'id' => 'faq', 'heading' => 'FAQ', 'brief' => 'x', 'tree' => null ),
);

$model = new Styble_AI_Page_Model( $catalog, $store );

/* ------------------------------------------------------------------ */
echo "\nINDEX\n";

$index = $model->index( 1 );

// container + 2 columns + 2 text + buttons + button = 7 per section, two built.
check( 14 === count( $index ), 'every addressable block on the page is indexed', count( $index ) . ' uids' );
check( count( $index ) === count( array_unique( array_keys( $index ) ) ), 'uids are unique across sections' );
check( ! array_filter( $index, function ( $e ) { return 'faq' === $e['section']; } ), 'an unbuilt section contributes nothing' );

$blocks = array_column( $index, 'block' );
check( 2 === count( array_filter( $blocks, function ( $b ) { return 'styble/container' === $b; } ) ), 'both containers are addressable' );
check( 2 === count( array_filter( $blocks, function ( $b ) { return 'styble/advanced-button' === $b; } ) ), 'a nested button is addressable' );

/* ------------------------------------------------------------------ */
echo "\nUIDS MATCH THE APPLIER'S OWN OUTPUT\n";

// The claim under test: the model recomputes exactly what the applier writes.
// Compared against the applier rather than against a second md5() here, so the
// two can never drift apart silently.
$applied = $applier->to_blocks( $store->state['sections'] );

/**
 * @param array $blocks Block arrays.
 * @return string[]
 */
function applied_uids( array $blocks ) {
	$out = array();
	foreach ( $blocks as $block ) {
		if ( isset( $block['attrs']['uniqueId'] ) ) {
			$out[] = $block['attrs']['uniqueId'];
		}
		$out = array_merge( $out, applied_uids( $block['innerBlocks'] ) );
	}
	return $out;
}

$from_applier = applied_uids( $applied );
sort( $from_applier );
$from_model = array_keys( $index );
sort( $from_model );

check( $from_applier === $from_model, 'the model resolves exactly the uids the applier writes', count( $from_applier ) . ' compared' );

/* ------------------------------------------------------------------ */
echo "\nGET_BLOCK\n";

// Find the h1 by content so the test does not depend on a hardcoded hash.
$h1_uid = '';
foreach ( $index as $uid => $entry ) {
	if ( 'styble/advanced-text' === $entry['block'] && 'hero' === $entry['section']
		&& 'Ship faster' === ( $entry['attrs']['advancedTextContent'] ?? '' ) ) {
		$h1_uid = $uid;
		break;
	}
}
check( '' !== $h1_uid, 'the hero h1 is findable in the index' );

$got = $model->get_block( 1, $h1_uid );
check( ! is_wp_error( $got ), 'get_block resolves it' );
check( 'styble/advanced-text' === $got['block'], 'it reports the right block' );
check( 'hero' === $got['section'], 'it reports the right section' );
check( 'Ship faster' === $got['attrs']['advancedTextContent'], 'it returns the block\'s own attributes' );
check( in_array( 'textFillBg', $got['editable'], true ), 'it lists what may be edited', count( $got['editable'] ) . ' attrs' );
check( ! isset( $got['markup'] ) && ! isset( $got['html'] ), 'it never returns markup' );

$missing = $model->get_block( 1, 'sp-styble-container-deadbeefdead' );
check( is_wp_error( $missing ) && 'styble_ai_no_block' === $missing->get_error_code(), 'an unknown uid is a clean 404' );

/* ------------------------------------------------------------------ */
echo "\nUPDATE_BLOCK\n";

$before_writes = $store->writes;
$res           = $model->update_block( 1, $h1_uid, array( 'textAliment' => 'center' ) );

check( ! is_wp_error( $res ) && $res['ok'], 'a legal edit succeeds' );
check( 1 === count( $res['changed'] ), 'exactly one attribute is reported changed' );
check( 'textAliment' === $res['changed'][0]['attr'], 'the diff names the attribute' );
check( null === $res['changed'][0]['from'] && 'center' === $res['changed'][0]['to'], 'the diff carries from and to' );
check( $store->writes === $before_writes + 1, 'the section was written once' );
check( 'center' === $model->get_block( 1, $h1_uid )['attrs']['textAliment'], 'the change is readable back' );

// Collateral is the number Stage 2 gates on, so prove nothing else moved.
$others_before = $index;
$after_index   = $model->index( 1 );
$collateral    = 0;
foreach ( $after_index as $uid => $entry ) {
	if ( $uid === $h1_uid ) {
		continue;
	}
	if ( ! isset( $others_before[ $uid ] ) || $others_before[ $uid ]['attrs'] !== $entry['attrs'] ) {
		$collateral++;
	}
}
check( 0 === $collateral, 'no other block on the page changed', 'collateral is a hard zero' );

// Re-sending the same value is not an edit.
$noop = $model->update_block( 1, $h1_uid, array( 'textAliment' => 'center' ) );
check( ! is_wp_error( $noop ) && ! $noop['changed'], 'an identical value reports no change' );

// null removes an attribute, reverting it to the block's own default.
$revert = $model->update_block( 1, $h1_uid, array( 'textAliment' => null ) );
check( ! is_wp_error( $revert ) && 1 === count( $revert['changed'] ), 'null removes the attribute' );
check( ! isset( $model->get_block( 1, $h1_uid )['attrs']['textAliment'] ), 'and it is gone from the tree' );

/* ------------------------------------------------------------------ */
echo "\nA REJECTED EDIT CHANGES NOTHING\n";

$writes_before = $store->writes;
$state_before  = $store->state;

// textHTMLTag has a verified value list, so "heading-one" is not legal.
$bad = $model->update_block( 1, $h1_uid, array( 'textHTMLTag' => 'heading-one' ) );
check( is_wp_error( $bad ), 'an illegal value is refused' );
check( 'styble_ai_invalid_edit' === $bad->get_error_code(), 'with its own error code' );
check( ! empty( $bad->get_error_data()['errors'] ), 'and the validator\'s reasons' );
check( $store->writes === $writes_before, 'nothing was written' );
check( $store->state === $state_before, 'the stored page is byte-identical' );

// Emptying a content attribute is caught by the same validator, not a special case.
$emptied = $model->update_block( 1, $h1_uid, array( 'advancedTextContent' => '' ) );
check( is_wp_error( $emptied ), 'emptying the copy is refused too' );
check( 'Ship faster' === $model->get_block( 1, $h1_uid )['attrs']['advancedTextContent'], 'the copy survived' );

// An attribute that is not editable on this block.
$unknown = $model->update_block( 1, $h1_uid, array( 'sectionBgImg' => array( 'alt' => 'x' ) ) );
check( is_wp_error( $unknown ), 'an attribute foreign to the block is refused' );

$nothing = $model->update_block( 1, $h1_uid, array() );
check( is_wp_error( $nothing ) && 'styble_ai_no_attrs' === $nothing->get_error_code(), 'an empty edit is a clean error, not a write' );

/* ------------------------------------------------------------------ */
echo "\nCROSS-FIELD RULES STILL APPLY TO AN EDIT\n";

// The container's layout must agree with its column count. Editing the layout
// alone breaks that agreement, and a node-only validation would have missed it.
$container_uid = '';
foreach ( $index as $uid => $entry ) {
	if ( 'styble/container' === $entry['block'] && 'hero' === $entry['section'] ) {
		$container_uid = $uid;
		break;
	}
}
$layout = $model->update_block( 1, $container_uid, array( 'layout' => 'l-3-equal' ) );
check( is_wp_error( $layout ), 'a layout that disagrees with the column count is refused' );
$codes = array();
foreach ( ( is_wp_error( $layout ) ? $layout->get_error_data()['errors'] : array() ) as $e ) {
	$codes[] = $e['code'];
}
check( in_array( 'layout_children_mismatch', $codes, true ), 'and names the cross-field rule', implode( ',', array_unique( $codes ) ) );

/* ------------------------------------------------------------------ */
echo "\nSAME TREE IN TWO SECTIONS IS TWO DIFFERENT BLOCKS\n";

$plans_h1 = '';
foreach ( $index as $uid => $entry ) {
	if ( 'plans' === $entry['section'] && 'styble/advanced-text' === $entry['block']
		&& 'Ship faster' === ( $entry['attrs']['advancedTextContent'] ?? '' ) ) {
		$plans_h1 = $uid;
		break;
	}
}
check( '' !== $plans_h1 && $plans_h1 !== $h1_uid, 'identical trees in two sections get different uids' );

$model->update_block( 1, $plans_h1, array( 'advancedTextContent' => 'Plans that scale' ) );
check( 'Plans that scale' === $model->get_block( 1, $plans_h1 )['attrs']['advancedTextContent'], 'editing one of them works' );
check( 'Ship faster' === $model->get_block( 1, $h1_uid )['attrs']['advancedTextContent'], 'and leaves its twin alone' );

/* ------------------------------------------------------------------ */
echo "\n";
printf( "%d checks, %d failed\n", $checks, $failures );
exit( $failures ? 1 : 0 );

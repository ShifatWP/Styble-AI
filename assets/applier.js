/**
 * Styble AI applier — validated JSON tree to real Styble blocks.
 *
 * Every Styble block is dynamic: save() returns InnerBlocks.Content or null and
 * PHP renders the frontend from attributes. So handing a tree to createBlock()
 * carries no serialization risk — block.json fills the defaults the model left
 * out, and each block's own editor effect assigns its uniqueId and scoped CSS.
 * That is why there is no serializer here and no block markup anywhere: the one
 * thing that used to be fragile is now the editor's job.
 *
 * The applier owns correctness. The model supplies content and intent; anything
 * the container's own layout logic would compute — layout, layoutSelected,
 * columns, per-column columnWidth, direction, flexWrap — is written here, and
 * model-supplied values for those are overwritten, not trusted.
 *
 * Never sets uniqueId. The blocks assign their own on mount, and scoped CSS keys
 * off it, so writing one here would fight the editor for it.
 *
 * Build-free by design (see CLAUDE.md): globals only, no imports, no JSX.
 * Layout geometry is NOT duplicated — rows, columns and widths come from
 * catalog.json, which the generator reads out of Styble Pro's layouts.js. The
 * arithmetic below mirrors the container's own onSelectPreset, so an applied
 * section is indistinguishable from one built by picking the layout in the UI.
 * Keep it in step when Styble Pro changes.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.blocks.createBlock ) {
		return;
	}

	var createBlock = wp.blocks.createBlock;

	var COLUMN_BLOCK = 'styble/column';
	var CONTAINER_BLOCK = 'styble/container';

	/**
	 * Padding applied to a section's outermost container when the model set none.
	 *
	 * sectionPadding is zero on all four sides in block.json, so leaving it unset
	 * is not a neutral choice: the copy sits against the viewport edge and the
	 * section sits flush against the one above it. The prompt asks for it; this
	 * is the backstop for when the model does not comply.
	 *
	 * Kept in step with DEFAULT_SECTION_PADDING in includes/class-page-applier.php.
	 */
	var SECTION_PADDING = {
		device: {
			Desktop: { top: 80, right: 24, bottom: 80, left: 24 },
			Tablet: { top: 64, right: 20, bottom: 64, left: 20 },
			Mobile: { top: 48, right: 16, bottom: 48, left: 16 },
		},
		unit: { Desktop: 'px', Tablet: 'px', Mobile: 'px' },
	};

	/**
	 * Layouts injected by PHP (wp_add_inline_script). Only the layout table is
	 * shipped to the browser, not the whole catalog — it is all the applier needs,
	 * since the tree was already validated server-side.
	 */
	function layouts() {
		return ( window.stybleAI && window.stybleAI.layouts ) || [];
	}

	/**
	 * Round a percent width to at most 2 decimal places.
	 *
	 * @param {number|string} n Raw percent value.
	 * @return {number|string} Rounded number, or the original when not finite.
	 */
	function roundPct( n ) {
		var num = Number( n );
		if ( ! isFinite( num ) ) {
			return n;
		}
		return Math.round( num * 100 ) / 100;
	}

	/**
	 * @param {number} desktop Desktop percent.
	 * @param {number} tablet  Tablet percent, defaults to the desktop value.
	 * @param {number} mobile  Mobile percent, defaults to full width.
	 * @return {Object} single_responsive width value.
	 */
	function responsiveWidth( desktop, tablet, mobile ) {
		return {
			device: {
				Desktop: roundPct( desktop ),
				Tablet: roundPct( undefined === tablet ? desktop : tablet ),
				Mobile: roundPct( undefined === mobile ? 100 : mobile ),
			},
			unit: { Desktop: '%', Tablet: '%', Mobile: '%' },
		};
	}

	/**
	 * @return {Object} Empty responsive width — flex decides.
	 */
	function emptyResponsiveWidth() {
		return {
			device: { Desktop: '', Tablet: '', Mobile: '' },
			unit: { Desktop: '%', Tablet: '%', Mobile: '%' },
		};
	}

	/**
	 * Single-column layouts keep width empty (flex default); multi-column use the
	 * preset percentage.
	 *
	 * @param {number} pct         Layout width percent.
	 * @param {number} columnCount Number of columns in the layout.
	 * @return {Object} Responsive width value.
	 */
	function layoutColumnWidth( pct, columnCount ) {
		return 1 === columnCount ? emptyResponsiveWidth() : responsiveWidth( pct );
	}

	/**
	 * Set the Desktop value of a responsive attribute, preserving other devices.
	 *
	 * @param {Object} current Existing responsive value, may be undefined.
	 * @param {*}      desktop New Desktop value.
	 * @return {Object} Updated responsive value.
	 */
	function overrideDesktop( current, desktop ) {
		var base = current || {};
		var unit = base.unit || {};
		return Object.assign( {}, base, {
			device: Object.assign( {}, base.device || {}, { Desktop: desktop } ),
			unit: Object.assign( {}, unit, {
				Desktop: undefined === unit.Desktop ? '' : unit.Desktop,
			} ),
		} );
	}

	/**
	 * Look a layout up in the catalog table.
	 *
	 * @param {string} id Layout id.
	 * @return {Object|null} Layout entry with rows/columns/widths, or null.
	 */
	function getLayout( id ) {
		var all = layouts();
		for ( var i = 0; i < all.length; i++ ) {
			if ( all[ i ].id === id ) {
				return all[ i ];
			}
		}
		return null;
	}

	/**
	 * Does this layout wrap onto more than one row?
	 *
	 * @param {Object} layout Layout entry.
	 * @return {boolean} True for multi-row layouts.
	 */
	function isMultiRow( layout ) {
		return !! layout && !! layout.rows && layout.rows.length > 1;
	}

	/**
	 * The layout a container falls back to for a given column count.
	 *
	 * @param {number} count Column count.
	 * @return {string} Layout id, or '' when nothing fits.
	 */
	function defaultLayoutForCount( count ) {
		var equalId = 1 === count ? 'l-1' : 'l-' + count + '-equal';
		if ( getLayout( equalId ) ) {
			return equalId;
		}
		var all = layouts();
		for ( var i = 0; i < all.length; i++ ) {
			if ( all[ i ].columns === count ) {
				return all[ i ].id;
			}
		}
		return '';
	}

	/**
	 * How many direct children of this node are columns?
	 *
	 * @param {Array} children Child nodes.
	 * @return {number} Column count.
	 */
	function countColumns( children ) {
		var n = 0;
		for ( var i = 0; i < children.length; i++ ) {
			if ( children[ i ] && children[ i ].block === COLUMN_BLOCK ) {
				n++;
			}
		}
		return n;
	}

	/**
	 * Build a container and its columns.
	 *
	 * @param {Object} node Container node.
	 * @return {Object} Block object.
	 */
	function buildContainer( node ) {
		var attrs = Object.assign( {}, node.attrs || {} );
		var children = node.children || [];

		var layoutId =
			'string' === typeof attrs.layout && '' !== attrs.layout
				? attrs.layout
				: defaultLayoutForCount(
						countColumns( children ) || children.length || 1
				  );

		var layout = getLayout( layoutId );

		// No usable layout: build the subtree as-is rather than inventing geometry.
		if ( ! layout ) {
			return createBlock(
				CONTAINER_BLOCK,
				attrs,
				children.map( buildNode )
			);
		}

		var widths = ( layout.widths || [] ).map( roundPct );

		// An empty container gets the layout's columns seeded, exactly as picking
		// the layout in the editor would. A container that already has children
		// keeps them — the validator has already checked the counts agree.
		var sourceChildren = children.length
			? children
			: widths.map( function () {
					return { block: COLUMN_BLOCK };
			  } );

		var columnIndex = 0;
		var inner = sourceChildren.map( function ( child ) {
			if ( ! child || child.block !== COLUMN_BLOCK ) {
				return buildNode( child );
			}
			var width = widths[ columnIndex ];
			columnIndex++;
			return buildNode(
				Object.assign( {}, child, {
					attrs: Object.assign( {}, child.attrs || {}, {
						// Applier-owned: the layout decides column widths.
						columnWidth: layoutColumnWidth( width, widths.length ),
					} ),
				} )
			);
		} );

		return createBlock(
			CONTAINER_BLOCK,
			Object.assign( {}, attrs, {
				layout: layout.id,
				layoutSelected: true,
				columns: widths.length,
				direction: overrideDesktop( attrs.direction, 'row' ),
				flexWrap: overrideDesktop(
					attrs.flexWrap,
					isMultiRow( layout ) ? 'wrap' : 'nowrap'
				),
			} ),
			inner
		);
	}

	/**
	 * Build one node and its subtree.
	 *
	 * @param {Object} node Tree node.
	 * @return {Object} Block object.
	 */
	function buildNode( node ) {
		if ( ! node || 'string' !== typeof node.block ) {
			throw new Error( 'Styble AI: tree node has no block name.' );
		}
		if ( node.block === CONTAINER_BLOCK ) {
			return buildContainer( node );
		}
		return createBlock(
			node.block,
			Object.assign( {}, node.attrs || {} ),
			( node.children || [] ).map( buildNode )
		);
	}

	/**
	 * Build the blocks for a validated emit_layout envelope.
	 *
	 * The tree arrives already validated by the REST route, so this does not
	 * re-run the contract — it only refuses a shape it cannot walk at all.
	 *
	 * @param {Object} tree emit_layout envelope.
	 * @return {Array} Blocks ready for insertBlocks/replaceBlocks.
	 */
	function buildBlocks( tree ) {
		if ( ! tree || ! tree.root ) {
			throw new Error( 'Styble AI: the response contained no block tree.' );
		}
		return [ buildNode( withSectionPadding( tree.root ) ) ];
	}

	/**
	 * Give a section's outermost container breathing room if the model did not.
	 * Only the root: an 80px band on a nested container would be wrong.
	 *
	 * @param {Object} root Root node of a section.
	 * @return {Object} The root, with sectionPadding guaranteed.
	 */
	function withSectionPadding( root ) {
		if ( ! root || root.block !== CONTAINER_BLOCK ) {
			return root;
		}
		var attrs = root.attrs || {};
		if ( attrs.sectionPadding ) {
			return root;
		}
		return Object.assign( {}, root, {
			attrs: Object.assign( {}, attrs, { sectionPadding: SECTION_PADDING } ),
		} );
	}

	window.stybleAI = window.stybleAI || {};
	window.stybleAI.buildBlocks = buildBlocks;
	window.stybleAI.buildNode = buildNode;
} )( window.wp );

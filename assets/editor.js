/**
 * AI Block Composer — editor sidebar + per-block AI edit.
 *
 * Build-free: uses the global `wp` object and wp.element.createElement instead
 * of JSX, so the plugin runs by just enqueuing this file (no npm/webpack).
 *
 * Two features:
 *  1) Sidebar "Compose with AI" — describe a section/layout, insert as native blocks.
 *  2) Per-block toolbar "Edit with AI" — select any block, revise it in place via
 *     a popover prompt. Both hit the same REST endpoint; the server owns the
 *     fragile serialization.
 */
( function ( wp ) {
	if ( ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var registerPlugin = wp.plugins.registerPlugin;

	var editorPkg = wp.editPost || wp.editor || {};
	var PluginSidebar = editorPkg.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem;

	var blockEditor = wp.blockEditor || wp.editor || {};
	var BlockControls = blockEditor.BlockControls;

	var C = wp.components;
	var apiFetch = wp.apiFetch;
	var MAXLEN = 1000;

	// Inline line-icons (no icon font, no build step). Each entry is a list of
	// SVG path `d` strings drawn with the current text color.
	var ICONS = {
		wand:        [ 'M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9z', 'M18 14l.7 2 2.3.7-2.3.7L18 20l-.7-2-2.3-.7 2.3-.7z' ],
		features:    [ 'M4 5h16v14H4z', 'M9.33 5v14', 'M14.66 5v14' ],
		testimonial: [ 'M20 4H4v12h4v4l5-4h7z' ],
		cta:         [ 'M13 2L4 14h6l-1 8 9-12h-6z' ],
		pricing:     [ 'M12 5v14', 'M15.5 8.5c0-1.6-1.6-2.5-3.5-2.5s-3.5 1-3.5 2.4 1.4 2.1 3.5 2.6 3.5 1.1 3.5 2.6-1.6 2.4-3.5 2.4-3.5-1-3.5-2.5' ],
		star:        [ 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.9 6.6 20.9l1-6.1L3.2 9.5l6.1-.9z' ],
		shield:      [ 'M12 3l7 2.5v5.5c0 4.5-3.1 7.4-7 8.5-3.9-1.1-7-4-7-8.5V5.5z', 'M9 12l2 2 4-4' ],
		arrow:       [ 'M7 17L17 7', 'M8.5 7H17v8.5' ],
		edit:        [ 'M4 20h4L18.5 9.5a2 2 0 0 0-2.83-2.83L5 17v3z', 'M13.5 6.5l4 4' ]
	};

	function icon( name, size, color ) {
		var filled = name === 'star';
		return el( 'svg', {
			width: size || 18,
			height: size || 18,
			viewBox: '0 0 24 24',
			fill: filled ? 'currentColor' : 'none',
			stroke: filled ? 'none' : 'currentColor',
			strokeWidth: 2,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			style: color ? { color: color } : undefined,
			'aria-hidden': true
		}, ( ICONS[ name ] || [] ).map( function ( d, i ) {
			return el( 'path', { key: i, d: d } );
		} ) );
	}

	var TONES = [
		{ id: 'auto', label: __( 'Auto', 'ai-block-composer' ), instr: '' },
		{ id: 'friendly', label: __( 'Friendly', 'ai-block-composer' ), instr: 'Write the copy in a warm, friendly, conversational tone.' },
		{ id: 'professional', label: __( 'Professional', 'ai-block-composer' ), instr: 'Write the copy in a polished, professional, businesslike tone.' },
		{ id: 'bold', label: __( 'Bold', 'ai-block-composer' ), instr: 'Write the copy in a bold, punchy, high-energy tone.' }
	];

	var EXAMPLES = [
		{ icon: 'features', title: __( 'Features', 'ai-block-composer' ), text: 'A features section with 3 columns: fast, secure, affordable — each with a heading and a sentence.' },
		{ icon: 'testimonial', title: __( 'Testimonial', 'ai-block-composer' ), text: 'A testimonial section, light tone, with a quote and the customer name.' },
		{ icon: 'cta', title: __( 'Call to action', 'ai-block-composer' ), text: 'A call-to-action band, accent tone, full width, headline + one button.' },
		{ icon: 'pricing', title: __( 'Pricing', 'ai-block-composer' ), text: 'A pricing section with three plans and a highlighted recommended tier.' }
	];

	// One-tap edit instructions for the block popover.
	var EDIT_QUICK = [
		'Make the copy punchier',
		'More concise',
		'Change tone to dark',
		'Add another column',
		'Improve the wording'
	];

	var STYLE =
		'.abc-body{padding:16px}' +
		'.abc-title{text-transform:uppercase;letter-spacing:.5px;font-weight:700;font-size:12px;color:#1e1e1e;margin:0 0 12px;padding:0}' +
		'.abc-brand{display:flex;align-items:center;gap:12px;padding:16px 16px 14px;border-bottom:1px solid #e9eaec}' +
		'.abc-brand-ico{flex:0 0 auto;width:38px;height:38px;border-radius:10px;background:linear-gradient(180deg,#4aa576,#3d986a);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 2px rgba(31,120,80,.35)}' +
		'.abc-brand-title{font-weight:700;font-size:15px;line-height:1.2;color:#1e1e1e}' +
		'.abc-brand-sub{font-size:11.5px;color:#7a8085;margin-top:2px}' +
		'.abc-intro{color:#50575e;font-size:13px;line-height:1.6;margin:0 0 14px}' +
		'.abc-label-row{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px}' +
		'.abc-label{font-weight:600;font-size:12px;color:#1e1e1e}' +
		'.abc-count{font-size:11px;color:#9aa0a6;font-variant-numeric:tabular-nums}' +
		'.abc-ta{width:100%;box-sizing:border-box;border:1px solid #d5d7db;border-radius:8px;padding:12px;font-size:13px;line-height:1.55;color:#1e1e1e;resize:vertical;min-height:96px;font-family:inherit;transition:border-color .12s,box-shadow .12s}' +
		'.abc-ta::placeholder{color:#9297a0}' +
		'.abc-ta:focus{outline:none;border-color:#3d986a;box-shadow:0 0 0 3px rgba(63,154,107,.18)}' +
		'.abc-ta:disabled{background:#f6f7f7;color:#8a8f94}' +
		'.abc-tone{margin-top:12px;padding:12px;border:1px solid #e6e7e9;border-radius:8px;background:#fafbfb}' +
		'.abc-tone-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}' +
		'.abc-tone-label{font-size:10.5px;font-weight:700;letter-spacing:.6px;color:#8a8f94;margin-right:2px}' +
		'.abc-chip{padding:5px 13px;border-radius:999px;border:1px solid #d5d7db;background:#fff;color:#3c434a;font-size:12px;font-weight:500;cursor:pointer;transition:all .12s}' +
		'.abc-chip:hover{border-color:#3d986a;color:#1c7c4a}' +
		'.abc-chip[aria-pressed="true"]{background:#1c8250;border-color:#1c8250;color:#fff}' +
		'.abc-actions{display:flex;align-items:center;gap:12px}' +
		'.abc-generate{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:8px;padding:12px 16px;font-size:13.5px;font-weight:600;color:#fff;cursor:pointer;background:linear-gradient(180deg,#4aa576,#3d986a);box-shadow:0 1px 2px rgba(31,120,80,.35);transition:filter .12s,transform .04s}' +
		'.abc-generate:hover:not(:disabled){filter:brightness(1.05)}' +
		'.abc-generate:active:not(:disabled){transform:translateY(1px)}' +
		'.abc-generate:disabled{background:#a9d3bd;box-shadow:none;cursor:not-allowed}' +
		'.abc-clear{border:0;background:none;color:#7a8085;font-size:13px;cursor:pointer;padding:6px 4px}' +
		'.abc-clear:hover:not(:disabled){color:#1e1e1e;text-decoration:underline}' +
		'.abc-clear:disabled{color:#c3c7cb;cursor:default}' +
		'.abc-notice{margin-top:14px}' +
		'.abc-try{margin-top:22px;border-top:1px solid #e9eaec;padding-top:16px}' +
		'.abc-try-head{display:flex;align-items:center;gap:7px;font-weight:700;font-size:11px;letter-spacing:.5px;text-transform:uppercase;color:#1e1e1e;margin:0 0 10px}' +
		'.abc-try-head .abc-star{color:#eab308}' +
		'.abc-card{position:relative;display:flex;gap:12px;width:100%;text-align:left;border:1px solid #e2e4e7;background:#fff;border-radius:10px;padding:13px 14px;margin-bottom:10px;cursor:pointer;transition:border-color .12s,background .12s,box-shadow .12s}' +
		'.abc-card:hover{border-color:#3d986a;background:#f4faf6;box-shadow:0 2px 8px rgba(31,120,80,.1)}' +
		'.abc-card:focus-visible{outline:2px solid #3d986a;outline-offset:1px}' +
		'.abc-card:disabled{opacity:.55;cursor:not-allowed}' +
		'.abc-card-ico{flex:0 0 auto;width:34px;height:34px;border-radius:8px;background:#e7f4ec;color:#1c8250;display:flex;align-items:center;justify-content:center}' +
		'.abc-card-title{font-weight:700;font-size:11.5px;letter-spacing:.4px;text-transform:uppercase;color:#1c8250;margin-bottom:3px;display:block}' +
		'.abc-card-text{font-size:12px;line-height:1.5;color:#50575e}' +
		'.abc-card-arrow{position:absolute;top:12px;right:12px;color:#b3bcc2}' +
		'.abc-card:hover .abc-card-arrow{color:#3d986a}' +
		'.abc-foot{position:sticky;bottom:0;display:flex;align-items:center;gap:7px;margin:18px -16px -16px;padding:12px 16px;background:#fbfcfb;border-top:1px solid #e9eaec;color:#7a8085;font-size:11.5px}' +
		'.abc-foot svg{flex:0 0 auto;color:#3d986a}' +
		// Per-block toolbar popover.
		'.abc-pop{width:300px;max-width:88vw;padding:14px}' +
		'.abc-pop-head{display:flex;align-items:center;gap:7px;font-weight:700;font-size:12px;color:#1e1e1e;margin:0 0 10px}' +
		'.abc-pop .abc-ta{min-height:68px}' +
		'.abc-pop-err{color:#b32d2e;font-size:12px;line-height:1.4;margin:8px 0 0}' +
		'.abc-pop-actions{margin-top:10px}' +
		'.abc-quick-row{display:flex;flex-wrap:wrap;gap:7px;margin:10px 0}' +
		'.abc-quick{padding:5px 11px;border-radius:999px;border:1px solid #d5d7db;background:#fff;color:#3c434a;font-size:11.5px;cursor:pointer;transition:all .12s}' +
		'.abc-quick:hover:not(:disabled){border-color:#3d986a;color:#1c7c4a;background:#f4faf6}' +
		'.abc-quick:disabled{opacity:.55;cursor:not-allowed}';

	// Inject styles once, globally — the toolbar popover renders even when the
	// sidebar panel is closed, so styles cannot live only inside the panel.
	function injectStyles() {
		if ( typeof document === 'undefined' || document.getElementById( 'abc-inline-styles' ) ) {
			return;
		}
		var s = document.createElement( 'style' );
		s.id = 'abc-inline-styles';
		s.textContent = STYLE;
		( document.head || document.documentElement ).appendChild( s );
	}

	/* ------------------------------------------------------------------ */
	/* Feature 2: per-block "Edit with AI" toolbar button + popover.       */
	/* ------------------------------------------------------------------ */

	// The popover form. Serializes the one block, sends it as edit context,
	// replaces it with the revised blocks the server returns.
	function AIEditForm( props ) {
		var valState = useState( '' );
		var val = valState[ 0 ];
		var setVal = valState[ 1 ];

		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var errState = useState( null );
		var err = errState[ 0 ];
		var setErr = errState[ 1 ];

		function apply() {
			if ( ! val.trim() ) {
				setErr( __( 'Describe the change first.', 'ai-block-composer' ) );
				return;
			}
			var be = wp.data.select( 'core/block-editor' );
			var block = be.getBlock( props.clientId );
			if ( ! block ) {
				setErr( __( 'Could not read this block.', 'ai-block-composer' ) );
				return;
			}

			var selectionMarkup = wp.blocks.serialize( [ block ] );
			setBusy( true );
			setErr( null );

			apiFetch( {
				path: '/ai-block-composer/v1/generate',
				method: 'POST',
				data: { prompt: val.trim(), selection: selectionMarkup }
			} )
				.then( function ( res ) {
					setBusy( false );
					if ( ! res || ! res.markup ) {
						setErr( __( 'Empty response from server.', 'ai-block-composer' ) );
						return;
					}
					var newBlocks = wp.blocks.parse( res.markup );
					if ( ! newBlocks || ! newBlocks.length ) {
						setErr( __( 'The edit returned nothing usable.', 'ai-block-composer' ) );
						return;
					}
					wp.data.dispatch( 'core/block-editor' ).replaceBlocks( props.clientId, newBlocks );
					if ( props.onClose ) {
						props.onClose();
					}
				} )
				.catch( function ( e ) {
					setBusy( false );
					setErr( ( e && e.message ) ? e.message : __( 'Request failed.', 'ai-block-composer' ) );
				} );
		}

		return el( 'div', { className: 'abc-pop' },
			el( 'p', { className: 'abc-pop-head' }, icon( 'wand', 15, '#1c8250' ), __( 'Edit this block with AI', 'ai-block-composer' ) ),
			el( 'textarea', {
				className: 'abc-ta',
				rows: 3,
				autoFocus: true,
				maxLength: MAXLEN,
				value: val,
				disabled: busy,
				placeholder: __( 'e.g. Make it punchier and add a short subheading.', 'ai-block-composer' ),
				onChange: function ( e ) { setVal( e.target.value ); }
			} ),
			el( 'div', { className: 'abc-quick-row' },
				EDIT_QUICK.map( function ( q, i ) {
					return el( 'button', {
						key: i,
						type: 'button',
						className: 'abc-quick',
						disabled: busy,
						onClick: function () { setVal( q ); }
					}, q );
				} )
			),
			err ? el( 'p', { className: 'abc-pop-err' }, err ) : null,
			el( 'div', { className: 'abc-pop-actions' },
				el( 'button', {
					type: 'button',
					className: 'abc-generate',
					style: { width: '100%' },
					disabled: ! val.trim() || busy,
					onClick: apply
				}, busy
					? el( Fragment, {}, el( C.Spinner, {} ), __( 'Applying…', 'ai-block-composer' ) )
					: el( Fragment, {}, icon( 'wand', 16 ), __( 'Apply', 'ai-block-composer' ) )
				)
			)
		);
	}

	// Register the toolbar button on every block, only when the requisite
	// editor APIs exist (older WP or non-block contexts degrade gracefully).
	function registerBlockToolbar() {
		if ( ! wp.hooks || ! wp.compose || ! BlockControls || ! C.Dropdown || ! C.ToolbarGroup || ! C.ToolbarButton ) {
			return;
		}

		var withAIEdit = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
			return function ( props ) {
				if ( ! props.isSelected ) {
					return el( BlockEdit, props );
				}
				return el( Fragment, {},
					el( BlockEdit, props ),
					el( BlockControls, { group: 'other' },
						el( C.ToolbarGroup, {},
							el( C.Dropdown, {
								popoverProps: { placement: 'bottom-start' },
								renderToggle: function ( o ) {
									return el( C.ToolbarButton, {
										icon: icon( 'wand', 24, '#1c8250' ),
										label: __( 'Edit with AI', 'ai-block-composer' ),
										isPressed: o.isOpen,
										'aria-expanded': o.isOpen,
										onClick: o.onToggle
									} );
								},
								renderContent: function ( o ) {
									return el( AIEditForm, { clientId: props.clientId, onClose: o.onClose } );
								}
							} )
						)
					)
				);
			};
		}, 'withAIEdit' );

		wp.hooks.addFilter( 'editor.BlockEdit', 'ai-block-composer/with-ai-edit', withAIEdit );
	}

	/* ------------------------------------------------------------------ */
	/* Feature 1: sidebar "Compose with AI".                               */
	/* ------------------------------------------------------------------ */

	function Panel() {
		var promptState = useState( '' );
		var prompt = promptState[ 0 ];
		var setPrompt = promptState[ 1 ];

		var toneState = useState( 'auto' );
		var tone = toneState[ 0 ];
		var setTone = toneState[ 1 ];

		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var noticeState = useState( null );
		var notice = noticeState[ 0 ];
		var setNotice = noticeState[ 1 ];

		function insertMarkup( markup ) {
			var blocks = wp.blocks.parse( markup );
			if ( ! blocks || ! blocks.length ) {
				setNotice( { type: 'error', text: __( 'Nothing to insert.', 'ai-block-composer' ) } );
				return;
			}
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks );
			setNotice( { type: 'success', text: blocks.length + ' ' + __( 'section block(s) inserted.', 'ai-block-composer' ) } );
		}

		function onGenerate() {
			if ( ! prompt.trim() ) {
				setNotice( { type: 'error', text: __( 'Describe what to build first.', 'ai-block-composer' ) } );
				return;
			}
			setBusy( true );
			setNotice( null );

			// Fold the selected tone into the prompt (backend takes only `prompt`).
			var effective = prompt.trim();
			var picked = TONES.filter( function ( t ) { return t.id === tone; } )[ 0 ];
			if ( picked && picked.instr ) {
				effective = picked.instr + '\n\n' + effective;
			}

			apiFetch( { path: '/ai-block-composer/v1/generate', method: 'POST', data: { prompt: effective } } )
				.then( function ( res ) {
					setBusy( false );
					if ( res && res.markup ) {
						insertMarkup( res.markup );
					} else {
						setNotice( { type: 'error', text: __( 'Empty response from server.', 'ai-block-composer' ) } );
					}
				} )
				.catch( function ( err ) {
					setBusy( false );
					var msg = ( err && err.message ) ? err.message : __( 'Request failed.', 'ai-block-composer' );
					setNotice( { type: 'error', text: msg } );
				} );
		}

		var canGenerate = !! prompt.trim() && ! busy;
		var hasText = prompt.length > 0;
		var children = [];

		children.push(
			el( 'h2', { key: 'title', className: 'abc-title' }, __( 'Compose with AI', 'ai-block-composer' ) )
		);

		children.push(
			el( 'p', { key: 'help', className: 'abc-intro' },
				__( 'Describe a section or a whole layout in plain words. We build it as native, fully-editable WordPress blocks.', 'ai-block-composer' )
			)
		);

		// Prompt label + live character counter.
		children.push(
			el( 'div', { key: 'lrow', className: 'abc-label-row' },
				el( 'label', { className: 'abc-label', htmlFor: 'abc-prompt' }, __( 'Prompt', 'ai-block-composer' ) ),
				el( 'span', { className: 'abc-count' }, prompt.length + '/' + MAXLEN )
			)
		);

		children.push(
			el( 'textarea', {
				key: 'ta',
				id: 'abc-prompt',
				className: 'abc-ta',
				value: prompt,
				rows: 5,
				maxLength: MAXLEN,
				disabled: busy,
				placeholder: __( 'e.g. A features section with 3 columns: fast, secure, affordable — each with a heading and a sentence.', 'ai-block-composer' ),
				onChange: function ( e ) { setPrompt( e.target.value ); }
			} )
		);

		// Tone chips.
		children.push(
			el( 'div', { key: 'tone', className: 'abc-tone' },
				el( 'div', { className: 'abc-tone-row' },
					[ el( 'span', { key: 'tl', className: 'abc-tone-label' }, __( 'TONE', 'ai-block-composer' ) ) ].concat(
						TONES.map( function ( t ) {
							return el( 'button', {
								key: t.id,
								type: 'button',
								className: 'abc-chip',
								'aria-pressed': tone === t.id,
								disabled: busy,
								onClick: function () { setTone( t.id ); }
							}, t.label );
						} )
					)
				)
			)
		);

		children.push(
			el( 'div', { key: 'actions', className: 'abc-actions', style: { marginTop: '16px' } },
				el( 'button', {
					type: 'button',
					className: 'abc-generate',
					disabled: ! canGenerate,
					onClick: onGenerate
				}, busy
					? el( Fragment, {}, el( C.Spinner, {} ), __( 'Generating…', 'ai-block-composer' ) )
					: el( Fragment, {}, icon( 'wand', 16 ), __( 'Generate & insert', 'ai-block-composer' ) )
				),
				el( 'button', {
					type: 'button',
					className: 'abc-clear',
					disabled: ! hasText || busy,
					onClick: function () { setPrompt( '' ); setNotice( null ); }
				}, __( 'Clear', 'ai-block-composer' ) )
			)
		);

		if ( notice ) {
			children.push(
				el( 'div', { key: 'notice', className: 'abc-notice' },
					el( C.Notice, {
						status: notice.type,
						isDismissible: true,
						onRemove: function () { setNotice( null ); }
					}, notice.text )
				)
			);
		}

		// Hint that per-block editing lives in the block toolbar now.
		children.push(
			el( 'div', { key: 'edit', className: 'abc-try' },
				el( 'p', { className: 'abc-try-head' },
					icon( 'edit', 14 ),
					__( 'Edit existing blocks', 'ai-block-composer' )
				),
				el( 'p', { className: 'abc-intro', style: { margin: 0 } },
					__( 'Select any block in the editor and click the ✦ "Edit with AI" button in its toolbar to rewrite it in place.', 'ai-block-composer' )
				)
			)
		);

		// Example cards.
		children.push(
			el( 'div', { key: 'try', className: 'abc-try' },
				el( 'p', { className: 'abc-try-head' },
					el( 'span', { className: 'abc-star' }, icon( 'star', 14 ) ),
					__( 'Try one of these', 'ai-block-composer' )
				),
				EXAMPLES.map( function ( x, i ) {
					return el( 'button', {
						key: i,
						type: 'button',
						className: 'abc-card',
						disabled: busy,
						onClick: function () { setPrompt( x.text ); }
					},
						el( 'span', { className: 'abc-card-ico' }, icon( x.icon, 18 ) ),
						el( 'span', { className: 'abc-card-body' },
							el( 'span', { className: 'abc-card-title' }, x.title ),
							el( 'span', { className: 'abc-card-text' }, x.text )
						),
						el( 'span', { className: 'abc-card-arrow' }, icon( 'arrow', 15 ) )
					);
				} )
			)
		);

		children.push(
			el( 'div', { key: 'foot', className: 'abc-foot' },
				icon( 'shield', 15 ),
				el( 'span', {}, __( 'Nothing publishes automatically — you review every block.', 'ai-block-composer' ) )
			)
		);

		return el( 'div', { className: 'abc-body' }, children );
	}

	// Branded header shown at the top of the sidebar content.
	function Brand() {
		return el( 'div', { className: 'abc-brand' },
			el( 'div', { className: 'abc-brand-ico' }, icon( 'wand', 20 ) ),
			el( 'div', {},
				el( 'div', { className: 'abc-brand-title' }, __( 'AI Block Composer', 'ai-block-composer' ) ),
				el( 'div', { className: 'abc-brand-sub' }, __( 'Beta · outputs native blocks', 'ai-block-composer' ) )
			)
		);
	}

	function Sidebar() {
		return el( Fragment, {},
			el( PluginSidebarMoreMenuItem, { target: 'ai-block-composer-sidebar', icon: icon( 'wand', 20, '#1c8250' ) },
				__( 'AI Block Composer', 'ai-block-composer' )
			),
			el( PluginSidebar, {
				name: 'ai-block-composer-sidebar',
				title: __( 'AI Block Composer', 'ai-block-composer' ),
				className: 'abc-sidebar',
				icon: icon( 'wand', 20, '#1c8250' )
			}, el( Brand, {} ), el( Panel, {} ) )
		);
	}

	injectStyles();
	registerBlockToolbar();

	if ( PluginSidebar ) {
		registerPlugin( 'ai-block-composer', { render: Sidebar } );
	}
} )( window.wp );

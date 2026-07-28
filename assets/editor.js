/**
 * Styble AI — editor sidebar + per-block AI edit.
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
	var useRef = wp.element.useRef;
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
	var MAX_IMG_BYTES = 8 * 1024 * 1024; // 8 MB, matches the server cap.
	var MAX_IMG_DIM = 1024;              // Downscale longest side to this.

	// The route returns a validated emit_layout tree, not markup — every Styble
	// block is dynamic, so the applier builds real blocks with createBlock() and
	// each block fills its own defaults and mints its own uniqueId.
	// Throws with a readable message; callers turn that into a notice.
	function buildBlocksFrom( res ) {
		if ( ! res || ! res.tree ) {
			throw new Error( __( 'Empty response from server.', 'styble-ai' ) );
		}
		if ( ! window.stybleAI || ! window.stybleAI.buildBlocks ) {
			throw new Error( __( 'The Styble AI applier did not load. Try reloading the editor.', 'styble-ai' ) );
		}
		var blocks = window.stybleAI.buildBlocks( res.tree );
		if ( ! blocks || ! blocks.length ) {
			throw new Error( __( 'The layout contained no blocks.', 'styble-ai' ) );
		}
		return blocks;
	}

	// Stock photo filling is best-effort and never fails a generation, which is
	// exactly why it has to be reported: a section that quietly came back with
	// blank images looks identical to one where the feature is switched off.
	// Returns { count, warnings } — count 0 with no warnings means image filling
	// is not configured, and says nothing.
	function imageReport( res ) {
		return {
			count: ( res && res.imagesFilled ) || 0,
			warnings: ( res && res.imageWarnings ) || []
		};
	}

	function photoText( count ) {
		return 1 === count
			? __( '1 photo added from your stock library.', 'styble-ai' )
			: count + ' ' + __( 'photos added from your stock library.', 'styble-ai' );
	}

	// Never fail silently: when the tree was rejected, the server sends the
	// validator's per-error path + message, and those are the only thing that
	// makes a bad prompt or a too-narrow allowlist diagnosable.
	function errorText( err ) {
		if ( ! err ) {
			return __( 'Request failed.', 'styble-ai' );
		}
		var msg = err.message || __( 'Request failed.', 'styble-ai' );
		var errors = ( err.data && err.data.errors ) || err.errors;
		if ( errors && errors.length ) {
			var shown = errors.slice( 0, 5 ).map( function ( e ) {
				return '• ' + e.path + ' — ' + e.message;
			} );
			if ( errors.length > shown.length ) {
				shown.push( '• ' + ( errors.length - shown.length ) + ' ' + __( 'more…', 'styble-ai' ) );
			}
			msg += '\n' + shown.join( '\n' );
		}
		return msg;
	}

	// Read an image file, downscale to <= MAX_IMG_DIM, hand back a JPEG data URL.
	// cb(null) on any failure. Keeps payload/token cost low before upload.
	function loadDownscaled( file, cb ) {
		if ( typeof FileReader === 'undefined' ) { cb( null ); return; }
		var reader = new FileReader();
		reader.onload = function ( e ) {
			var img = new window.Image();
			img.onload = function () {
				var w = img.width, h = img.height;
				if ( w > MAX_IMG_DIM || h > MAX_IMG_DIM ) {
					var r = Math.min( MAX_IMG_DIM / w, MAX_IMG_DIM / h );
					w = Math.round( w * r );
					h = Math.round( h * r );
				}
				var canvas = document.createElement( 'canvas' );
				canvas.width = w;
				canvas.height = h;
				var ctx = canvas.getContext( '2d' );
				if ( ! ctx ) { cb( null ); return; }
				ctx.drawImage( img, 0, 0, w, h );
				try {
					cb( canvas.toDataURL( 'image/jpeg', 0.85 ) );
				} catch ( err ) {
					cb( null );
				}
			};
			img.onerror = function () { cb( null ); };
			img.src = e.target.result;
		};
		reader.onerror = function () { cb( null ); };
		reader.readAsDataURL( file );
	}

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
		edit:        [ 'M4 20h4L18.5 9.5a2 2 0 0 0-2.83-2.83L5 17v3z', 'M13.5 6.5l4 4' ],
		image:       [ 'M4 5h16v14H4z', 'M4 16l4.5-4.5 3 3 4-4L20 14', 'M9 9.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0' ]
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
		{ id: 'auto', label: __( 'Auto', 'styble-ai' ), instr: '' },
		{ id: 'friendly', label: __( 'Friendly', 'styble-ai' ), instr: 'Write the copy in a warm, friendly, conversational tone.' },
		{ id: 'professional', label: __( 'Professional', 'styble-ai' ), instr: 'Write the copy in a polished, professional, businesslike tone.' },
		{ id: 'bold', label: __( 'Bold', 'styble-ai' ), instr: 'Write the copy in a bold, punchy, high-energy tone.' }
	];

	var EXAMPLES = [
		{ icon: 'features', title: __( 'Features', 'styble-ai' ), text: 'A features section with 3 columns: fast, secure, affordable — each with a heading and a sentence.' },
		{ icon: 'testimonial', title: __( 'Testimonial', 'styble-ai' ), text: 'A testimonial section, light tone, with a quote and the customer name.' },
		{ icon: 'cta', title: __( 'Call to action', 'styble-ai' ), text: 'A call-to-action band, accent tone, full width, headline + one button.' },
		{ icon: 'pricing', title: __( 'Pricing', 'styble-ai' ), text: 'A pricing section with three plans and a highlighted recommended tier.' }
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
		'.sai-body{padding:16px}' +
		'.sai-title{text-transform:uppercase;letter-spacing:.5px;font-weight:700;font-size:12px;color:#1e1e1e;margin:0 0 12px;padding:0}' +
		'.sai-brand{display:flex;align-items:center;gap:12px;padding:16px 16px 14px;border-bottom:1px solid #e9eaec}' +
		'.sai-brand-ico{flex:0 0 auto;width:38px;height:38px;border-radius:10px;background:linear-gradient(180deg,#4aa576,#3d986a);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 2px rgba(31,120,80,.35)}' +
		'.sai-brand-title{font-weight:700;font-size:15px;line-height:1.2;color:#1e1e1e}' +
		'.sai-brand-sub{font-size:11.5px;color:#7a8085;margin-top:2px}' +
		'.sai-intro{color:#50575e;font-size:13px;line-height:1.6;margin:0 0 14px}' +
		'.sai-label-row{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px}' +
		'.sai-label{font-weight:600;font-size:12px;color:#1e1e1e}' +
		'.sai-count{font-size:11px;color:#9aa0a6;font-variant-numeric:tabular-nums}' +
		'.sai-ta{width:100%;box-sizing:border-box;border:1px solid #d5d7db;border-radius:8px;padding:12px;font-size:13px;line-height:1.55;color:#1e1e1e;resize:vertical;min-height:96px;font-family:inherit;transition:border-color .12s,box-shadow .12s}' +
		'.sai-ta::placeholder{color:#9297a0}' +
		'.sai-ta:focus{outline:none;border-color:#3d986a;box-shadow:0 0 0 3px rgba(63,154,107,.18)}' +
		'.sai-ta:disabled{background:#f6f7f7;color:#8a8f94}' +
		'.sai-tone{margin-top:12px;padding:12px;border:1px solid #e6e7e9;border-radius:8px;background:#fafbfb}' +
		'.sai-tone-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}' +
		'.sai-tone-label{font-size:10.5px;font-weight:700;letter-spacing:.6px;color:#8a8f94;margin-right:2px}' +
		'.sai-chip{padding:5px 13px;border-radius:999px;border:1px solid #d5d7db;background:#fff;color:#3c434a;font-size:12px;font-weight:500;cursor:pointer;transition:all .12s}' +
		'.sai-chip:hover{border-color:#3d986a;color:#1c7c4a}' +
		'.sai-chip[aria-pressed="true"]{background:#1c8250;border-color:#1c8250;color:#fff}' +
		'.sai-actions{display:flex;align-items:center;gap:12px}' +
		'.sai-generate{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:8px;padding:12px 16px;font-size:13.5px;font-weight:600;color:#fff;cursor:pointer;background:linear-gradient(180deg,#4aa576,#3d986a);box-shadow:0 1px 2px rgba(31,120,80,.35);transition:filter .12s,transform .04s}' +
		'.sai-generate:hover:not(:disabled){filter:brightness(1.05)}' +
		'.sai-generate:active:not(:disabled){transform:translateY(1px)}' +
		'.sai-generate:disabled{background:#a9d3bd;box-shadow:none;cursor:not-allowed}' +
		'.sai-clear{border:0;background:none;color:#7a8085;font-size:13px;cursor:pointer;padding:6px 4px}' +
		'.sai-clear:hover:not(:disabled){color:#1e1e1e;text-decoration:underline}' +
		'.sai-clear:disabled{color:#c3c7cb;cursor:default}' +
		'.sai-notice{margin-top:14px}' +
		'.sai-try{margin-top:22px;border-top:1px solid #e9eaec;padding-top:16px}' +
		'.sai-try-head{display:flex;align-items:center;gap:7px;font-weight:700;font-size:11px;letter-spacing:.5px;text-transform:uppercase;color:#1e1e1e;margin:0 0 10px}' +
		'.sai-try-head .sai-star{color:#eab308}' +
		'.sai-card{position:relative;display:flex;gap:12px;width:100%;text-align:left;border:1px solid #e2e4e7;background:#fff;border-radius:10px;padding:13px 14px;margin-bottom:10px;cursor:pointer;transition:border-color .12s,background .12s,box-shadow .12s}' +
		'.sai-card:hover{border-color:#3d986a;background:#f4faf6;box-shadow:0 2px 8px rgba(31,120,80,.1)}' +
		'.sai-card:focus-visible{outline:2px solid #3d986a;outline-offset:1px}' +
		'.sai-card:disabled{opacity:.55;cursor:not-allowed}' +
		'.sai-card-ico{flex:0 0 auto;width:34px;height:34px;border-radius:8px;background:#e7f4ec;color:#1c8250;display:flex;align-items:center;justify-content:center}' +
		'.sai-card-title{font-weight:700;font-size:11.5px;letter-spacing:.4px;text-transform:uppercase;color:#1c8250;margin-bottom:3px;display:block}' +
		'.sai-card-text{font-size:12px;line-height:1.5;color:#50575e}' +
		'.sai-card-arrow{position:absolute;top:12px;right:12px;color:#b3bcc2}' +
		'.sai-card:hover .sai-card-arrow{color:#3d986a}' +
		'.sai-foot{position:sticky;bottom:0;display:flex;align-items:center;gap:7px;margin:18px -16px -16px;padding:12px 16px;background:#fbfcfb;border-top:1px solid #e9eaec;color:#7a8085;font-size:11.5px}' +
		'.sai-foot svg{flex:0 0 auto;color:#3d986a}' +
		// Per-block toolbar popover.
		'.sai-pop{width:300px;max-width:88vw;padding:14px}' +
		'.sai-pop-head{display:flex;align-items:center;gap:7px;font-weight:700;font-size:12px;color:#1e1e1e;margin:0 0 10px}' +
		'.sai-pop .sai-ta{min-height:68px}' +
		'.sai-pop-err{color:#b32d2e;font-size:12px;line-height:1.4;margin:8px 0 0}' +
		'.sai-pop-warn{margin:9px 0 0;padding:8px 10px;border:1px solid #f0d48a;background:#fdf6e3;border-radius:6px;font-size:11.5px;line-height:1.5;color:#7a5c00}' +
		'.sai-pop-warn ul{margin:4px 0 0;padding-left:16px;list-style:disc}' +
		'.sai-notice-list{margin:6px 0 0;padding-left:16px;list-style:disc;font-size:11.5px;line-height:1.5}' +
		'.sai-pop-actions{margin-top:10px}' +
		'.sai-quick-row{display:flex;flex-wrap:wrap;gap:7px;margin:10px 0}' +
		'.sai-quick{padding:5px 11px;border-radius:999px;border:1px solid #d5d7db;background:#fff;color:#3c434a;font-size:11.5px;cursor:pointer;transition:all .12s}' +
		'.sai-quick:hover:not(:disabled){border-color:#3d986a;color:#1c7c4a;background:#f4faf6}' +
		'.sai-quick:disabled{opacity:.55;cursor:not-allowed}' +
		// Design-image attach (sidebar generate).
		'.sai-attach{margin-top:10px}' +
		'.sai-attach-btn{display:inline-flex;align-items:center;gap:7px;width:100%;justify-content:center;border:1px dashed #cdd0d4;background:#fff;color:#3c434a;border-radius:8px;padding:10px 12px;font-size:12px;cursor:pointer;transition:all .12s}' +
		'.sai-attach-btn:hover:not(:disabled){border-color:#3d986a;color:#1c7c4a;background:#f4faf6}' +
		'.sai-attach-btn:disabled{opacity:.55;cursor:not-allowed}' +
		'.sai-attach-hint{font-size:11px;color:#7a8085;line-height:1.5;margin:7px 0 0}' +
		'.sai-thumb{position:relative;display:flex;align-items:center;gap:10px;padding:8px;border:1px solid #e2e4e7;border-radius:8px;background:#fafbfb}' +
		'.sai-thumb img{width:52px;height:52px;object-fit:cover;border-radius:6px;flex:0 0 auto;background:#fff}' +
		'.sai-thumb-meta{flex:1;min-width:0;font-size:11.5px;color:#50575e;line-height:1.4}' +
		'.sai-thumb-name{font-weight:600;color:#1e1e1e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
		'.sai-thumb-x{flex:0 0 auto;width:26px;height:26px;border:1px solid #d5d7db;background:#fff;border-radius:6px;color:#7a8085;font-size:15px;line-height:1;cursor:pointer}' +
		'.sai-thumb-x:hover{border-color:#b32d2e;color:#b32d2e}';

	// Inject styles once, globally — the toolbar popover renders even when the
	// sidebar panel is closed, so styles cannot live only inside the panel.
	function injectStyles() {
		if ( typeof document === 'undefined' || document.getElementById( 'sai-inline-styles' ) ) {
			return;
		}
		var s = document.createElement( 'style' );
		s.id = 'sai-inline-styles';
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

		var warnState = useState( null );
		var warn = warnState[ 0 ];
		var setWarn = warnState[ 1 ];

		function apply() {
			if ( ! val.trim() ) {
				setErr( __( 'Describe the change first.', 'styble-ai' ) );
				return;
			}
			var be = wp.data.select( 'core/block-editor' );
			var block = be.getBlock( props.clientId );
			if ( ! block ) {
				setErr( __( 'Could not read this block.', 'styble-ai' ) );
				return;
			}

			var selectionMarkup = wp.blocks.serialize( [ block ] );
			setBusy( true );
			setErr( null );
			setWarn( null );

			apiFetch( {
				path: '/styble-ai/v1/generate',
				method: 'POST',
				data: { prompt: val.trim(), selection: selectionMarkup }
			} )
				.then( function ( res ) {
					setBusy( false );
					var newBlocks;
					try {
						newBlocks = buildBlocksFrom( res );
					} catch ( e ) {
						setErr( e.message );
						return;
					}
					wp.data.dispatch( 'core/block-editor' ).replaceBlocks( props.clientId, newBlocks );

					// The block is already replaced either way. Stay open when a
					// stock photo could not be fetched, because closing is the one
					// thing that would make that silent.
					var img = imageReport( res );
					if ( img.warnings.length ) {
						setWarn( img.warnings );
						return;
					}
					if ( props.onClose ) {
						props.onClose();
					}
				} )
				.catch( function ( e ) {
					setBusy( false );
					setErr( errorText( e ) );
				} );
		}

		return el( 'div', { className: 'sai-pop' },
			el( 'p', { className: 'sai-pop-head' }, icon( 'wand', 15, '#1c8250' ), __( 'Edit this block with AI', 'styble-ai' ) ),
			el( 'textarea', {
				className: 'sai-ta',
				rows: 3,
				autoFocus: true,
				maxLength: MAXLEN,
				value: val,
				disabled: busy,
				placeholder: __( 'e.g. Make it punchier and add a short subheading.', 'styble-ai' ),
				onChange: function ( e ) { setVal( e.target.value ); }
			} ),
			el( 'div', { className: 'sai-quick-row' },
				EDIT_QUICK.map( function ( q, i ) {
					return el( 'button', {
						key: i,
						type: 'button',
						className: 'sai-quick',
						disabled: busy,
						onClick: function () { setVal( q ); }
					}, q );
				} )
			),
			err ? el( 'p', { className: 'sai-pop-err' }, err ) : null,
			warn
				? el( 'div', { className: 'sai-pop-warn' },
					el( 'strong', {}, __( 'Applied, but the images are still blank:', 'styble-ai' ) ),
					el( 'ul', {}, warn.map( function ( w, i ) {
						return el( 'li', { key: i }, w );
					} ) )
				)
				: null,
			el( 'div', { className: 'sai-pop-actions' },
				el( 'button', {
					type: 'button',
					className: 'sai-generate',
					style: { width: '100%' },
					disabled: ! val.trim() || busy,
					onClick: apply
				}, busy
					? el( Fragment, {}, el( C.Spinner, {} ), __( 'Applying…', 'styble-ai' ) )
					: el( Fragment, {}, icon( 'wand', 16 ), __( 'Apply', 'styble-ai' ) )
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
										label: __( 'Edit with AI', 'styble-ai' ),
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

		wp.hooks.addFilter( 'editor.BlockEdit', 'styble-ai/with-ai-edit', withAIEdit );
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

		var imageState = useState( null );
		var image = imageState[ 0 ];
		var setImage = imageState[ 1 ];

		var imageNameState = useState( '' );
		var imageName = imageNameState[ 0 ];
		var setImageName = imageNameState[ 1 ];

		var fileRef = useRef ? useRef( null ) : { current: null };

		// Accept an image File: reject oversized up front, else downscale + attach.
		function acceptImage( file, fallbackName ) {
			if ( ! file ) { return; }
			if ( file.size > MAX_IMG_BYTES ) {
				setNotice( { type: 'error', text: __( 'Image too large — max 8 MB.', 'styble-ai' ) } );
				return;
			}
			loadDownscaled( file, function ( url ) {
				if ( url ) {
					setImage( url );
					setImageName( file.name || fallbackName || __( 'Design image', 'styble-ai' ) );
					setNotice( null );
				} else {
					setNotice( { type: 'error', text: __( 'Could not read that image.', 'styble-ai' ) } );
				}
			} );
		}

		function onFilePick( e ) {
			var f = e.target.files && e.target.files[ 0 ];
			acceptImage( f );
			e.target.value = ''; // allow re-picking the same file
		}

		function onPastePrompt( e ) {
			var items = e.clipboardData && e.clipboardData.items;
			if ( ! items ) { return; }
			for ( var i = 0; i < items.length; i++ ) {
				if ( items[ i ].type && items[ i ].type.indexOf( 'image' ) === 0 ) {
					var f = items[ i ].getAsFile();
					if ( f ) {
						e.preventDefault();
						acceptImage( f, __( 'Pasted image', 'styble-ai' ) );
					}
					break;
				}
			}
		}

		function clearImage() {
			setImage( null );
			setImageName( '' );
		}

		function insertTree( res ) {
			var blocks = buildBlocksFrom( res );
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks );

			var text = ( res.attempts > 1 )
				? __( 'Section inserted (the first attempt was rejected and regenerated).', 'styble-ai' )
				: __( 'Section inserted.', 'styble-ai' );

			var img = imageReport( res );
			if ( img.count ) {
				text += ' ' + photoText( img.count );
			}

			// A warning is not a failure — the section is already in the editor.
			// It is a "your images are blank and here is why".
			setNotice( {
				type: img.warnings.length ? 'warning' : 'success',
				text: text,
				details: img.warnings
			} );
		}

		function onGenerate() {
			if ( ! prompt.trim() ) {
				setNotice( { type: 'error', text: __( 'Describe what to build first.', 'styble-ai' ) } );
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

			var data = { prompt: effective };
			if ( image ) {
				data.image = image;
			}
			var hadImage = !! image;

			apiFetch( { path: '/styble-ai/v1/generate', method: 'POST', data: data } )
				.then( function ( res ) {
					setBusy( false );
					try {
						insertTree( res );
					} catch ( e ) {
						setNotice( { type: 'error', text: e.message } );
					}
				} )
				.catch( function ( err ) {
					setBusy( false );
					var msg = errorText( err );
					if ( hadImage ) {
						msg += ' ' + __( '— the selected model may not support images; switch to a vision model.', 'styble-ai' );
					}
					setNotice( { type: 'error', text: msg } );
				} );
		}

		var canGenerate = !! prompt.trim() && ! busy;
		var hasText = prompt.length > 0;
		var children = [];

		children.push(
			el( 'h2', { key: 'title', className: 'sai-title' }, __( 'Compose with AI', 'styble-ai' ) )
		);

		children.push(
			el( 'p', { key: 'help', className: 'sai-intro' },
				__( 'Describe a section or a whole layout in plain words. We build it as native, fully-editable WordPress blocks.', 'styble-ai' )
			)
		);

		// Prompt label + live character counter.
		children.push(
			el( 'div', { key: 'lrow', className: 'sai-label-row' },
				el( 'label', { className: 'sai-label', htmlFor: 'sai-prompt' }, __( 'Prompt', 'styble-ai' ) ),
				el( 'span', { className: 'sai-count' }, prompt.length + '/' + MAXLEN )
			)
		);

		children.push(
			el( 'textarea', {
				key: 'ta',
				id: 'sai-prompt',
				className: 'sai-ta',
				value: prompt,
				rows: 5,
				maxLength: MAXLEN,
				disabled: busy,
				placeholder: __( 'e.g. A features section with 3 columns: fast, secure, affordable — each with a heading and a sentence.', 'styble-ai' ),
				onChange: function ( e ) { setPrompt( e.target.value ); },
				onPaste: onPastePrompt
			} )
		);

		// Design-image attach: build the layout from a screenshot/mockup.
		children.push(
			el( 'div', { key: 'attach', className: 'sai-attach' },
				el( 'input', {
					ref: fileRef,
					type: 'file',
					accept: 'image/*',
					style: { display: 'none' },
					onChange: onFilePick
				} ),
				image
					? el( 'div', { className: 'sai-thumb' },
						el( 'img', { src: image, alt: '' } ),
						el( 'div', { className: 'sai-thumb-meta' },
							el( 'div', { className: 'sai-thumb-name' }, imageName ),
							el( 'div', {}, __( 'AI will build from this image.', 'styble-ai' ) )
						),
						el( 'button', {
							type: 'button',
							className: 'sai-thumb-x',
							'aria-label': __( 'Remove image', 'styble-ai' ),
							title: __( 'Remove image', 'styble-ai' ),
							disabled: busy,
							onClick: clearImage
						}, '×' )
					)
					: el( 'button', {
						type: 'button',
						className: 'sai-attach-btn',
						disabled: busy,
						onClick: function () { if ( fileRef.current ) { fileRef.current.click(); } }
					}, icon( 'image', 15 ), __( 'Add a design image', 'styble-ai' ) ),
				el( 'p', { className: 'sai-attach-hint' },
					__( 'Optional. Or paste an image into the prompt. Needs a vision model (Groq llama-4-scout, Gemini, Claude, GPT-4o).', 'styble-ai' )
				)
			)
		);

		// Tone chips.
		children.push(
			el( 'div', { key: 'tone', className: 'sai-tone' },
				el( 'div', { className: 'sai-tone-row' },
					[ el( 'span', { key: 'tl', className: 'sai-tone-label' }, __( 'TONE', 'styble-ai' ) ) ].concat(
						TONES.map( function ( t ) {
							return el( 'button', {
								key: t.id,
								type: 'button',
								className: 'sai-chip',
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
			el( 'div', { key: 'actions', className: 'sai-actions', style: { marginTop: '16px' } },
				el( 'button', {
					type: 'button',
					className: 'sai-generate',
					disabled: ! canGenerate,
					onClick: onGenerate
				}, busy
					? el( Fragment, {}, el( C.Spinner, {} ), __( 'Generating…', 'styble-ai' ) )
					: el( Fragment, {}, icon( 'wand', 16 ), __( 'Generate & insert', 'styble-ai' ) )
				),
				el( 'button', {
					type: 'button',
					className: 'sai-clear',
					disabled: ( ! hasText && ! image ) || busy,
					onClick: function () { setPrompt( '' ); setNotice( null ); clearImage(); }
				}, __( 'Clear', 'styble-ai' ) )
			)
		);

		if ( notice ) {
			children.push(
				el( 'div', { key: 'notice', className: 'sai-notice' },
					el( C.Notice, {
						status: notice.type,
						isDismissible: true,
						onRemove: function () { setNotice( null ); }
					},
						el( 'span', {}, notice.text ),
						notice.details && notice.details.length
							? el( 'ul', { className: 'sai-notice-list' },
								notice.details.map( function ( d, i ) {
									return el( 'li', { key: i }, d );
								} )
							)
							: null
					)
				)
			);
		}

		// Hint that per-block editing lives in the block toolbar now.
		children.push(
			el( 'div', { key: 'edit', className: 'sai-try' },
				el( 'p', { className: 'sai-try-head' },
					icon( 'edit', 14 ),
					__( 'Edit existing blocks', 'styble-ai' )
				),
				el( 'p', { className: 'sai-intro', style: { margin: 0 } },
					__( 'Select any block in the editor and click the ✦ "Edit with AI" button in its toolbar to rewrite it in place.', 'styble-ai' )
				)
			)
		);

		// Example cards.
		children.push(
			el( 'div', { key: 'try', className: 'sai-try' },
				el( 'p', { className: 'sai-try-head' },
					el( 'span', { className: 'sai-star' }, icon( 'star', 14 ) ),
					__( 'Try one of these', 'styble-ai' )
				),
				EXAMPLES.map( function ( x, i ) {
					return el( 'button', {
						key: i,
						type: 'button',
						className: 'sai-card',
						disabled: busy,
						onClick: function () { setPrompt( x.text ); }
					},
						el( 'span', { className: 'sai-card-ico' }, icon( x.icon, 18 ) ),
						el( 'span', { className: 'sai-card-body' },
							el( 'span', { className: 'sai-card-title' }, x.title ),
							el( 'span', { className: 'sai-card-text' }, x.text )
						),
						el( 'span', { className: 'sai-card-arrow' }, icon( 'arrow', 15 ) )
					);
				} )
			)
		);

		children.push(
			el( 'div', { key: 'foot', className: 'sai-foot' },
				icon( 'shield', 15 ),
				el( 'span', {}, __( 'Nothing publishes automatically — you review every block.', 'styble-ai' ) )
			)
		);

		return el( 'div', { className: 'sai-body' }, children );
	}

	// Branded header shown at the top of the sidebar content.
	function Brand() {
		return el( 'div', { className: 'sai-brand' },
			el( 'div', { className: 'sai-brand-ico' }, icon( 'wand', 20 ) ),
			el( 'div', {},
				el( 'div', { className: 'sai-brand-title' }, __( 'Styble AI', 'styble-ai' ) ),
				el( 'div', { className: 'sai-brand-sub' }, __( 'Beta · outputs Styble blocks', 'styble-ai' ) )
			)
		);
	}

	function Sidebar() {
		return el( Fragment, {},
			el( PluginSidebarMoreMenuItem, { target: 'styble-ai-sidebar', icon: icon( 'wand', 20, '#1c8250' ) },
				__( 'Styble AI', 'styble-ai' )
			),
			el( PluginSidebar, {
				name: 'styble-ai-sidebar',
				title: __( 'Styble AI', 'styble-ai' ),
				className: 'sai-sidebar',
				icon: icon( 'wand', 20, '#1c8250' )
			}, el( Brand, {} ), el( Panel, {} ) )
		);
	}

	injectStyles();
	registerBlockToolbar();

	if ( PluginSidebar ) {
		registerPlugin( 'styble-ai', { render: Sidebar } );
	}
} )( window.wp );

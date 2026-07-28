/**
 * Styble AI — the AI Chat admin screen.
 *
 * Ask for a page; get a draft page and a live preview of it.
 *
 * Build-free by design (see CLAUDE.md): globals only, no imports, no JSX.
 *
 * The build is deliberately two-phase and visible. `/chat/plan` returns the
 * outline in one quick call, then each section is fetched by its own request so
 * the checklist ticks over, the preview refreshes as the page grows, and a model
 * that fails on one section costs one section rather than the whole page.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var render = wp.element.render;
	var C = wp.components;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var domReady = wp.domReady;

	var CFG = window.stybleAIChat || {};
	var MAXLEN = 2000;

	var DEVICES = [
		{ id: 'desktop', label: __( 'Desktop', 'styble-ai' ), width: null },
		{ id: 'tablet', label: __( 'Tablet', 'styble-ai' ), width: 834 },
		{ id: 'mobile', label: __( 'Mobile', 'styble-ai' ), width: 390 }
	];

	var ICONS = {
		wand: [ 'M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9z', 'M18 14l.7 2 2.3.7-2.3.7L18 20l-.7-2-2.3-.7 2.3-.7z' ],
		check: [ 'M4 12.5l5 5L20 6.5' ],
		alert: [ 'M12 4l9 16H3z', 'M12 10v4', 'M12 17.2v.1' ],
		page: [ 'M6 3h8l4 4v14H6z', 'M14 3v4h4' ],
		send: [ 'M4 12l16-8-6 16-2.5-6.5z' ],
		reload: [ 'M20 12a8 8 0 1 1-2.6-5.9', 'M20 4v5h-5' ],
		open: [ 'M14 4h6v6', 'M20 4l-8 8', 'M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5' ]
	};

	function icon( name, size ) {
		return el( 'svg', {
			width: size || 16,
			height: size || 16,
			viewBox: '0 0 24 24',
			fill: 'none',
			stroke: 'currentColor',
			strokeWidth: 2,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			'aria-hidden': true
		}, ( ICONS[ name ] || [] ).map( function ( d, i ) {
			return el( 'path', { key: i, d: d } );
		} ) );
	}

	/**
	 * Never fail silently: when a tree was rejected, the server sends the
	 * validator's per-error path + message, and those are the only thing that
	 * makes a bad brief or a too-narrow allowlist diagnosable.
	 *
	 * @param {Object} err Error from apiFetch.
	 * @return {string} Readable message.
	 */
	function errorText( err ) {
		if ( ! err ) {
			return __( 'Request failed.', 'styble-ai' );
		}
		var msg = err.message || __( 'Request failed.', 'styble-ai' );
		var errors = ( err.data && err.data.errors ) || err.errors;
		if ( errors && errors.length ) {
			var shown = errors.slice( 0, 4 ).map( function ( e ) {
				return '• ' + e.path + ' — ' + e.message;
			} );
			if ( errors.length > shown.length ) {
				shown.push( '• ' + ( errors.length - shown.length ) + ' ' + __( 'more…', 'styble-ai' ) );
			}
			msg += '\n' + shown.join( '\n' );
		}
		return msg;
	}

	/* ------------------------------------------------------------------ */
	/* Preview pane                                                        */
	/* ------------------------------------------------------------------ */

	function Preview( props ) {
		var page = props.page;
		var deviceState = useState( 'desktop' );
		var device = deviceState[ 0 ];
		var setDevice = deviceState[ 1 ];

		if ( ! page ) {
			return el( 'div', { className: 'sac-preview sac-preview--empty' },
				el( 'div', { className: 'sac-empty' },
					el( 'span', { className: 'sac-empty-ico' }, icon( 'page', 26 ) ),
					el( 'h2', {}, __( 'No page yet', 'styble-ai' ) ),
					el( 'p', {}, __( 'Describe the page you want on the left. Styble AI plans the sections, builds them one at a time, and previews the draft here.', 'styble-ai' ) )
				)
			);
		}

		var picked = DEVICES.filter( function ( d ) { return d.id === device; } )[ 0 ] || DEVICES[ 0 ];
		var frameStyle = picked.width ? { width: picked.width + 'px' } : {};

		return el( 'div', { className: 'sac-preview' },
			el( 'div', { className: 'sac-preview-bar' },
				el( 'div', { className: 'sac-preview-title' },
					icon( 'page', 15 ),
					el( 'span', {}, page.title ),
					el( 'span', { className: 'sac-badge' }, page.status || 'draft' )
				),
				el( 'div', { className: 'sac-preview-tools' },
					el( 'div', { className: 'sac-devices', role: 'group', 'aria-label': __( 'Preview width', 'styble-ai' ) },
						DEVICES.map( function ( d ) {
							return el( 'button', {
								key: d.id,
								type: 'button',
								className: 'sac-device',
								'aria-pressed': device === d.id,
								onClick: function () { setDevice( d.id ); }
							}, d.label );
						} )
					),
					el( 'button', {
						type: 'button',
						className: 'sac-tool',
						title: __( 'Refresh preview', 'styble-ai' ),
						onClick: props.onRefresh
					}, icon( 'reload', 15 ) ),
					el( 'a', {
						className: 'sac-tool',
						href: page.editUrl,
						target: '_blank',
						rel: 'noopener',
						title: __( 'Open in the block editor', 'styble-ai' )
					}, icon( 'open', 15 ) )
				)
			),
			el( 'div', { className: 'sac-frame-wrap' },
				el( 'iframe', {
					key: page.pageId,
					className: 'sac-frame',
					style: frameStyle,
					src: props.src,
					title: __( 'Page preview', 'styble-ai' )
				} )
			),
			props.busy ? el( 'div', { className: 'sac-frame-busy' }, el( C.Spinner, {} ) ) : null
		);
	}

	/* ------------------------------------------------------------------ */
	/* Chat                                                                */
	/* ------------------------------------------------------------------ */

	function SectionList( props ) {
		return el( 'ul', { className: 'sac-sections' },
			props.sections.map( function ( s ) {
				var state = s.failed ? 'failed' : ( s.built ? 'built' : ( s.id === props.active ? 'active' : 'queued' ) );
				return el( 'li', { key: s.id, className: 'sac-section sac-section--' + state },
					el( 'span', { className: 'sac-section-mark' },
						'built' === state ? icon( 'check', 13 ) : null,
						'failed' === state ? icon( 'alert', 13 ) : null,
						'active' === state ? el( C.Spinner, {} ) : null
					),
					el( 'span', { className: 'sac-section-name' }, s.heading ),
					'failed' === state
						? el( 'button', {
							type: 'button',
							className: 'sac-retry',
							disabled: props.busy,
							onClick: function () { props.onRetry( s.id ); }
						}, __( 'Retry', 'styble-ai' ) )
						: null
				);
			} )
		);
	}

	function Message( props ) {
		var m = props.message;
		return el( 'div', { className: 'sac-msg sac-msg--' + m.role + ( m.error ? ' sac-msg--error' : '' ) },
			'assistant' === m.role
				? el( 'span', { className: 'sac-avatar' }, icon( 'wand', 15 ) )
				: null,
			el( 'div', { className: 'sac-bubble' },
				m.text ? el( 'p', { className: 'sac-text' }, m.text ) : null,
				m.sections
					? el( SectionList, {
						sections: m.sections,
						active: props.active,
						busy: props.busy,
						onRetry: props.onRetry
					} )
					: null,
				m.footer ? el( 'p', { className: 'sac-foot-note' }, m.footer ) : null
			)
		);
	}

	function App() {
		var messagesState = useState( [] );
		var messages = messagesState[ 0 ];
		var setMessages = messagesState[ 1 ];

		var pageState = useState( null );
		var page = pageState[ 0 ];
		var setPage = pageState[ 1 ];

		var inputState = useState( '' );
		var input = inputState[ 0 ];
		var setInput = inputState[ 1 ];

		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var activeState = useState( null );
		var active = activeState[ 0 ];
		var setActive = activeState[ 1 ];

		var bustState = useState( 0 );
		var bust = bustState[ 0 ];
		var setBust = bustState[ 1 ];

		var scrollRef = useRef( null );

		useEffect( function () {
			if ( scrollRef.current ) {
				scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
			}
		}, [ messages, active ] );

		function push( message ) {
			setMessages( function ( prev ) { return prev.concat( [ message ] ); } );
		}

		// The plan checklist lives on its own message, so progress is patched
		// into that message rather than appended as a stream of new ones.
		function patchPlan( planId, mutate ) {
			setMessages( function ( prev ) {
				return prev.map( function ( m ) {
					if ( m.planId !== planId ) {
						return m;
					}
					var copy = Object.assign( {}, m );
					mutate( copy );
					return copy;
				} );
			} );
		}

		function refresh() {
			setBust( function ( n ) { return n + 1; } );
		}

		function previewSrc() {
			if ( ! page || ! page.previewUrl ) {
				return 'about:blank';
			}
			return page.previewUrl + ( page.previewUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'sai=' + bust;
		}

		function buildSection( pageId, sectionId, planId ) {
			setActive( sectionId );

			return apiFetch( {
				path: '/styble-ai/v1/chat/section',
				method: 'POST',
				data: { pageId: pageId, sectionId: sectionId }
			} )
				.then( function ( res ) {
					setPage( res );
					patchPlan( planId, function ( m ) {
						m.sections = m.sections.map( function ( s ) {
							return s.id === sectionId
								? Object.assign( {}, s, { built: true, failed: false } )
								: s;
						} );
					} );
					refresh();
					return true;
				} )
				.catch( function ( err ) {
					patchPlan( planId, function ( m ) {
						m.sections = m.sections.map( function ( s ) {
							return s.id === sectionId
								? Object.assign( {}, s, { failed: true, error: errorText( err ) } )
								: s;
						} );
					} );
					return false;
				} );
		}

		// Sequential on purpose: the providers rate-limit, and a half-built page
		// with three sections out of order is worse than a slower honest one.
		function buildAll( pageId, ids, planId ) {
			var failures = 0;

			return ids.reduce( function ( chain, id ) {
				return chain.then( function () {
					return buildSection( pageId, id, planId ).then( function ( ok ) {
						if ( ! ok ) {
							failures++;
						}
					} );
				} );
			}, Promise.resolve() ).then( function () {
				setActive( null );
				return failures;
			} );
		}

		function retry( sectionId ) {
			if ( ! page || busy ) {
				return;
			}
			var planId = lastPlanId();
			setBusy( true );
			buildSection( page.pageId, sectionId, planId )
				.then( function () {
					setActive( null );
					setBusy( false );
				} );
		}

		function lastPlanId() {
			for ( var i = messages.length - 1; i >= 0; i-- ) {
				if ( messages[ i ].planId ) {
					return messages[ i ].planId;
				}
			}
			return null;
		}

		function submit() {
			var text = input.trim();
			if ( ! text || busy ) {
				return;
			}

			push( { role: 'user', text: text } );
			setInput( '' );
			setBusy( true );

			var planId = 'plan-' + Date.now();

			apiFetch( {
				path: '/styble-ai/v1/chat/plan',
				method: 'POST',
				data: { message: text, pageId: page ? page.pageId : 0 }
			} )
				.then( function ( res ) {
					setPage( res );
					refresh();

					push( {
						role: 'assistant',
						planId: planId,
						text: res.reply || __( 'Here is the plan.', 'styble-ai' ),
						sections: res.sections.map( function ( s ) {
							return { id: s.id, heading: s.heading, built: !! s.built, failed: false };
						} )
					} );

					var pending = res.sections
						.filter( function ( s ) { return ! s.built; } )
						.map( function ( s ) { return s.id; } );

					if ( ! pending.length ) {
						setBusy( false );
						push( { role: 'assistant', text: __( 'Nothing to rebuild — the page already matches that plan.', 'styble-ai' ) } );
						return;
					}

					return buildAll( res.pageId, pending, planId ).then( function ( failures ) {
						setBusy( false );
						push( {
							role: 'assistant',
							text: failures
								? sprintfLike( __( 'Built the page, but %d section(s) failed. Retry them above, or tell me to rework them.', 'styble-ai' ), failures )
								: __( 'Done — the draft is on the right. Tell me what to change, or open it in the editor.', 'styble-ai' ),
							footer: __( 'The page is a draft. Nothing is published until you publish it.', 'styble-ai' )
						} );
					} );
				} )
				.catch( function ( err ) {
					setBusy( false );
					setActive( null );
					push( { role: 'assistant', error: true, text: errorText( err ) } );
				} );
		}

		function startOver() {
			setPage( null );
			setMessages( [] );
			setActive( null );
		}

		function onKeyDown( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				submit();
			}
		}

		var disabled = ! CFG.hasKey;

		return el( 'div', { className: 'sac' },
			el( 'div', { className: 'sac-chat' },
				el( 'div', { className: 'sac-head' },
					el( 'div', { className: 'sac-head-ico' }, icon( 'wand', 19 ) ),
					el( 'div', { className: 'sac-head-text' },
						el( 'div', { className: 'sac-head-title' }, __( 'Styble AI', 'styble-ai' ) ),
						el( 'div', { className: 'sac-head-sub' }, __( 'Describe a page — it builds one as a draft', 'styble-ai' ) )
					),
					page
						? el( 'button', { type: 'button', className: 'sac-newpage', onClick: startOver, disabled: busy },
							__( 'New page', 'styble-ai' ) )
						: null
				),

				disabled
					? el( 'div', { className: 'sac-keynotice' },
						el( C.Notice, { status: 'warning', isDismissible: false },
							el( Fragment, {},
								__( 'No API key yet. ', 'styble-ai' ),
								el( 'a', { href: CFG.settingsUrl }, __( 'Add one in Settings', 'styble-ai' ) ),
								__( ' to start building pages.', 'styble-ai' )
							)
						)
					)
					: null,

				el( 'div', { className: 'sac-scroll', ref: scrollRef },
					messages.length
						? messages.map( function ( m, i ) {
							return el( Message, {
								key: i,
								message: m,
								active: active,
								busy: busy,
								onRetry: retry
							} );
						} )
						: el( 'div', { className: 'sac-intro' },
							el( 'p', {}, __( 'Ask for a page and Styble AI plans it, builds every section as real Styble blocks, and saves it as a draft you can edit.', 'styble-ai' ) ),
							el( 'p', { className: 'sac-intro-label' }, __( 'Try one of these', 'styble-ai' ) ),
							( CFG.examples || [] ).map( function ( x, i ) {
								return el( 'button', {
									key: i,
									type: 'button',
									className: 'sac-example',
									disabled: disabled,
									onClick: function () { setInput( x ); }
								}, x );
							} )
						)
				),

				el( 'div', { className: 'sac-composer' },
					el( 'textarea', {
						className: 'sac-input',
						value: input,
						rows: 3,
						maxLength: MAXLEN,
						disabled: busy || disabled,
						placeholder: page
							? __( 'What should change? e.g. “make the hero shorter and add an FAQ”', 'styble-ai' )
							: __( 'e.g. Create a pricing page for a WordPress plugin with three plans.', 'styble-ai' ),
						onChange: function ( e ) { setInput( e.target.value ); },
						onKeyDown: onKeyDown
					} ),
					el( 'div', { className: 'sac-composer-row' },
						el( 'span', { className: 'sac-hint' },
							busy
								? __( 'Building… this takes a model call per section.', 'styble-ai' )
								: __( 'Enter to send · Shift+Enter for a new line', 'styble-ai' )
						),
						el( 'button', {
							type: 'button',
							className: 'sac-send',
							disabled: ! input.trim() || busy || disabled,
							onClick: submit
						}, busy
							? el( Fragment, {}, el( C.Spinner, {} ), __( 'Working…', 'styble-ai' ) )
							: el( Fragment, {}, icon( 'send', 15 ), page ? __( 'Revise', 'styble-ai' ) : __( 'Build page', 'styble-ai' ) )
						)
					)
				)
			),

			el( Preview, {
				page: page,
				src: previewSrc(),
				busy: busy,
				onRefresh: refresh
			} )
		);
	}

	/**
	 * @param {string} template Format string with one %d.
	 * @param {number} n        Replacement.
	 * @return {string} Formatted string.
	 */
	function sprintfLike( template, n ) {
		return template.replace( '%d', String( n ) );
	}

	domReady( function () {
		var node = document.getElementById( 'styble-ai-chat-root' );
		if ( ! node ) {
			return;
		}
		// createRoot on WP 6.2+, render on anything older.
		if ( wp.element.createRoot ) {
			wp.element.createRoot( node ).render( el( App, {} ) );
			return;
		}
		render( el( App, {} ), node );
	} );
} )( window.wp );

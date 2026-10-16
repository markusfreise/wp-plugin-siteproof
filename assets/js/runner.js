/**
 * Siteproof runner: drives an audit step by step via the REST API and measures every page
 * in a same-origin iframe (390px mobile: images, lazy loading, timing; 1440px desktop: axe-core).
 */
( function () {
	'use strict';

	var cfg = window.siteproofRunner;
	var root = document.getElementById( 'sp-runner' );
	if ( ! cfg || ! root ) {
		return;
	}
	var t = cfg.i18n;
	var form = root.querySelector( '.sp-start__form' );
	var progress = root.querySelector( '.sp-progress' );
	var stepsEl = root.querySelector( '.sp-steps' );
	var currentEl = root.querySelector( '.sp-progress__current' );
	var logEl = root.querySelector( '.sp-log ol' );
	var frame = root.querySelector( '.sp-preview iframe' );
	var dialog = root.querySelector( '.sp-dialog' );
	var stopped = false;
	var run = null;
	var axeLocale = null;

	// ------------------------------------------------------------------ Helpers

	function api( path, method, body ) {
		return fetch( cfg.rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( j.message || 'HTTP ' + r.status );
				}
				return j;
			} );
		} );
	}

	function sleep( ms ) {
		return new Promise( function ( res ) {
			setTimeout( res, ms );
		} );
	}

	function log( msg, isError ) {
		var li = document.createElement( 'li' );
		li.textContent = msg;
		if ( isError ) {
			li.className = 'sp-log__error';
			root.querySelector( '.sp-log' ).open = true;
		}
		logEl.appendChild( li );
	}

	var steps = {};
	function addStep( key, label ) {
		var li = document.createElement( 'li' );
		li.className = 'sp-steps__item';
		li.innerHTML = '<span class="sp-steps__label"></span> <span class="sp-steps__count"></span><span class="sp-steps__bar"><span></span></span>';
		li.querySelector( '.sp-steps__label' ).textContent = label;
		stepsEl.appendChild( li );
		steps[ key ] = li;
	}
	function setStep( key, done, total ) {
		var li = steps[ key ];
		if ( ! li ) {
			return;
		}
		li.querySelector( '.sp-steps__count' ).textContent = total ? done + ' / ' + total : '';
		li.querySelector( '.sp-steps__bar span' ).style.width = ( total ? Math.round( ( done / total ) * 100 ) : 100 ) + '%';
		li.classList.toggle( 'is-done', ! total || done >= total );
	}

	// Run tasks with limited concurrency; stops scheduling when the audit is stopped.
	function pool( items, limit, fn ) {
		var i = 0;
		function next() {
			if ( stopped || i >= items.length ) {
				return Promise.resolve();
			}
			var item = items[ i++ ];
			return fn( item ).then( next, function ( e ) {
				log( t.error + ': ' + e.message, true );
				return next();
			} );
		}
		var workers = [];
		for ( var w = 0; w < Math.min( limit, items.length ); w++ ) {
			workers.push( next() );
		}
		return Promise.all( workers );
	}

	// ------------------------------------------------------------------ Start / resume / stop

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var ids = ( form.ids.value || '' ).split( /[\s,]+/ ).filter( Boolean ).map( Number );
		// Selective re-check: levels per category; pages outside keep their last results.
		var only = {};
		form.querySelectorAll( '.sp-only input:checked' ).forEach( function ( el ) {
			( only[ el.dataset.cat ] = only[ el.dataset.cat ] || [] ).push( el.value );
		} );
		form.querySelector( '[type=submit]' ).disabled = true;
		api( '/runs', 'POST', {
			browser: form.browser.checked,
			psi: form.psi.checked,
			ai: form.ai.checked,
			ids: ids,
			only: only,
		} ).then( start, function ( err ) {
			form.querySelector( '[type=submit]' ).disabled = false;
			window.alert( err.message );
		} );
	} );

	if ( root.dataset.resume ) {
		api( '/runs/' + root.dataset.resume + '/status', 'POST', { status: 'running' } )
			.then( function () {
				return api( '/runs/' + root.dataset.resume );
			} )
			.then( start, function ( err ) {
				window.alert( err.message );
			} );
	}

	root.querySelector( '.sp-stop' ).addEventListener( 'click', function () {
		if ( ! window.confirm( t.confirm ) ) {
			return;
		}
		stopped = true;
		api( '/runs/' + run.id + '/status', 'POST', { status: 'paused' } ).catch( function () {} );
		currentEl.textContent = t.paused;
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( run && ! stopped && 'done' !== run.status ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	// ------------------------------------------------------------------ Flow

	function start( state ) {
		run = state;
		form.hidden = true;
		progress.hidden = false;
		addStep( 'server', t.server );
		addStep( 'links', t.links );
		if ( state.settings.browser ) {
			addStep( 'browser', t.browser );
		}
		if ( state.settings.psi ) {
			addStep( 'psi', t.psi );
		}
		if ( state.settings.ai ) {
			addStep( 'review', t.review );
		}
		addStep( 'finish', t.finish );
		flow().catch( function ( e ) {
			log( t.error + ': ' + e.message, true );
			currentEl.textContent = t.error + ': ' + e.message;
		} );
	}

	function countDone( key ) {
		return run.pages.filter( function ( p ) {
			return p[ key ];
		} ).length;
	}

	async function flow() {
		var pages = run.pages;
		var total = pages.length;

		// 1. Server collector in batches of three pages.
		var todo = pages.filter( function ( p ) {
			return ! p.server;
		} );
		setStep( 'server', total - todo.length, total );
		for ( var i = 0; i < todo.length && ! stopped; i += 3 ) {
			var batch = todo.slice( i, i + 3 );
			currentEl.textContent = t.server + ': ' + batch.map( function ( p ) {
				return p.title;
			} ).join( ', ' );
			var res = await api( '/runs/' + run.id + '/server', 'POST', { posts: batch.map( function ( p ) {
				return p.post;
			} ) } );
			res.done.forEach( function ( d ) {
				if ( d.error ) {
					log( d.post + ': ' + d.error, true );
				}
			} );
			batch.forEach( function ( p ) {
				p.server = true;
			} );
			setStep( 'server', countDone( 'server' ), total );
		}
		if ( stopped ) {
			return;
		}

		// 2. Links.
		var links = run.links;
		while ( ! stopped && ( ! links || links.done < links.total ) ) {
			currentEl.textContent = t.links;
			links = await api( '/runs/' + run.id + '/links', 'POST', {} );
			setStep( 'links', links.done, links.total );
		}
		if ( links ) {
			setStep( 'links', links.done, links.total );
		}
		if ( stopped ) {
			return;
		}

		// 3. Browser measurement and PageSpeed Insights in parallel.
		var jobs = [];
		if ( run.settings.browser ) {
			var bTodo = pages.filter( function ( p ) {
				return ! p.browser;
			} );
			setStep( 'browser', total - bTodo.length, total );
			jobs.push( pool( bTodo, 1, function ( p ) {
				currentEl.textContent = t.browser + ': ' + p.title;
				return measure( p ).then( function ( data ) {
					return api( '/runs/' + run.id + '/browser', 'POST', { post: p.post, data: data } );
				} ).catch( function ( e ) {
					log( p.title + ': ' + e.message, true );
					return api( '/runs/' + run.id + '/browser', 'POST', { post: p.post, data: { error: e.message } } );
				} ).then( function () {
					p.browser = true;
					setStep( 'browser', countDone( 'browser' ), total );
				} );
			} ) );
		}
		if ( run.settings.psi ) {
			var pTodo = pages.filter( function ( p ) {
				return ! p.psi;
			} );
			setStep( 'psi', total - pTodo.length, total );
			jobs.push( pool( pTodo, 2, function ( p ) {
				return api( '/runs/' + run.id + '/psi', 'POST', { post: p.post } ).then( function ( r ) {
					if ( r.error && ! r.local ) {
						log( 'PageSpeed ' + p.title + ': ' + r.error, true );
					}
					p.psi = true;
					setStep( 'psi', countDone( 'psi' ), total );
				} );
			} ) );
		}
		await Promise.all( jobs );
		if ( stopped ) {
			return;
		}
		frame.removeAttribute( 'src' );

		// 4. AI proofreading after a cost confirmation.
		if ( run.settings.ai ) {
			var rTodo = pages.filter( function ( p ) {
				return ! p.review;
			} );
			setStep( 'review', total - rTodo.length, total );
			if ( rTodo.length ) {
				var est = await api( '/runs/' + run.id + '/estimate' );
				if ( await confirmCosts( est.providers ) ) {
					await pool( rTodo, 2, function ( p ) {
						currentEl.textContent = t.review + ': ' + p.title;
						return api( '/runs/' + run.id + '/review', 'POST', { post: p.post } ).then( function ( r ) {
							if ( r.error ) {
								log( p.title + ': ' + r.error, true );
							}
							p.review = true;
							setStep( 'review', countDone( 'review' ), total );
						} );
					} );
				} else {
					setStep( 'review', 0, 0 );
				}
			}
		}
		if ( stopped ) {
			return;
		}

		// 5. Report.
		currentEl.textContent = t.finish;
		var fin = await api( '/runs/' + run.id + '/finish', 'POST', {} );
		setStep( 'finish', 1, 1 );
		run.status = 'done';
		currentEl.textContent = t.done;
		window.location.href = fin.reportUrl;
	}

	function confirmCosts( providers ) {
		var body = dialog.querySelector( '.sp-dialog__body' );
		var table = document.createElement( 'table' );
		table.className = 'widefat striped';
		providers.forEach( function ( p ) {
			var tr = document.createElement( 'tr' );
			var cost = ! p.configured ? t.notSet : null === p.cost ? t.noPrice : '~' + p.cost.toFixed( 2 ) + ' USD';
			[ p.label + ( p.selected ? ' (' + t.selected + ')' : '' ), p.model || '–', p.in.toLocaleString() + ' / ' + p.out.toLocaleString() + ' ' + t.tokens, cost ].forEach( function ( v, i ) {
				var td = document.createElement( i ? 'td' : 'th' );
				td.textContent = v;
				tr.appendChild( td );
			} );
			if ( p.selected ) {
				tr.className = 'is-selected';
			}
			table.appendChild( tr );
		} );
		body.innerHTML = '';
		body.appendChild( table );
		return new Promise( function ( resolve ) {
			dialog.addEventListener( 'close', function onClose() {
				dialog.removeEventListener( 'close', onClose );
				resolve( 'yes' === dialog.returnValue );
			} );
			dialog.showModal();
		} );
	}

	// ------------------------------------------------------------------ Measurement in the iframe

	function frameUrl( url ) {
		return url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'siteproof_frame=1';
	}

	function setSize( w, h ) {
		frame.style.width = w + 'px';
		frame.style.height = h + 'px';
		frame.parentNode.style.setProperty( '--sp-frame-w', w );
		frame.parentNode.style.setProperty( '--sp-frame-h', h );
	}

	function load( url ) {
		return new Promise( function ( resolve, reject ) {
			var timer = setTimeout( function () {
				reject( new Error( 'Timeout ' + url ) );
			}, 60000 );
			frame.onload = function () {
				clearTimeout( timer );
				try {
					if ( ! frame.contentDocument || ! frame.contentDocument.body ) {
						throw new Error( 'empty' );
					}
				} catch ( e ) {
					reject( new Error( 'The page cannot be read in the frame (different origin or X-Frame-Options).' ) );
					return;
				}
				resolve();
			};
			frame.src = url;
		} );
	}

	async function autoScroll( win ) {
		var doc = win.document;
		for ( var y = 0; y < doc.body.scrollHeight; y += win.innerHeight * 0.7 ) {
			win.scrollTo( 0, y );
			await sleep( 150 );
		}
		win.scrollTo( 0, 0 );
	}

	function observe( win, type ) {
		var entries = [];
		try {
			new win.PerformanceObserver( function ( list ) {
				entries.push.apply( entries, list.getEntries() );
			} ).observe( { type: type, buffered: true } );
		} catch ( e ) {}
		return entries;
	}

	function resourceType( e ) {
		var path = e.name.split( '?' )[ 0 ].toLowerCase();
		if ( /\.(js|mjs)$/.test( path ) || e.initiatorType === 'script' ) {
			return 'script';
		}
		if ( /\.css$/.test( path ) || ( e.initiatorType === 'link' && /css/.test( path ) ) ) {
			return 'stylesheet';
		}
		if ( /\.(woff2?|ttf|otf|eot)$/.test( path ) ) {
			return 'font';
		}
		if ( /\.(png|jpe?g|gif|webp|avif|svg|ico)$/.test( path ) || e.initiatorType === 'img' ) {
			return 'image';
		}
		if ( /\.(mp4|webm|mp3|ogg|m4a|mov)$/.test( path ) || e.initiatorType === 'video' || e.initiatorType === 'audio' ) {
			return 'media';
		}
		return 'other';
	}

	async function measure( page ) {
		var url = frameUrl( page.url );

		// Mobile (390px): timing, images, lazy loading.
		setSize( 390, 844 );
		await load( url );
		var win = frame.contentWindow;
		var lcp = observe( win, 'largest-contentful-paint' );
		var shifts = observe( win, 'layout-shift' );
		await sleep( 1500 );
		var initial = new Set( win.performance.getEntriesByType( 'resource' ).map( function ( e ) {
			return e.name;
		} ) );
		await autoScroll( win );
		await sleep( 1500 );

		var nav = win.performance.getEntriesByType( 'navigation' )[ 0 ] || {};
		var fcp = win.performance.getEntriesByName( 'first-contentful-paint' )[ 0 ];
		var bytes = { total: 0, document: nav.encodedBodySize || nav.transferSize || 0, script: 0, stylesheet: 0, font: 0, image: 0, media: 0, other: 0, thirdParty: 0 };
		bytes.total = bytes.document;
		var bySrc = {};
		var resources = win.performance.getEntriesByType( 'resource' );
		resources.forEach( function ( e ) {
			var size = e.encodedBodySize || e.transferSize || 0;
			// Page weight counts only what loads before scrolling; images are summed separately.
			if ( initial.has( e.name ) ) {
				bytes.total += size;
				bytes[ resourceType( e ) ] += size;
				try {
					if ( new URL( e.name ).host !== win.location.host ) {
						bytes.thirdParty += size;
					}
				} catch ( err ) {}
			}
			bySrc[ e.name ] = { bytes: size };
		} );
		var cls = shifts.reduce( function ( a, e ) {
			return e.hadRecentInput ? a : a + e.value;
		}, 0 );
		var timing = {
			source: 'local',
			url: page.url,
			ttfb: nav.responseStart || null,
			domContentLoaded: nav.domContentLoadedEventEnd || null,
			load: nav.loadEventEnd || null,
			fcp: fcp ? fcp.startTime : null,
			lcp: lcp.length ? lcp[ lcp.length - 1 ].startTime : null,
			cls: Math.round( cls * 1000 ) / 1000,
			bytes: bytes,
			requests: initial.size + 1,
		};
		var collected = collectImages( win, run.blockClass || {} );
		collected.images.forEach( function ( img ) {
			var r = bySrc[ img.src ];
			img.bytes = r && r.bytes ? r.bytes : null;
			img.loadedInitially = initial.has( img.src );
		} );
		// Resource Timing reports the size of a cached copy, which may be an outdated file under the
		// same URL. Ask the server for the current size of same-origin images, bypassing the cache.
		var current = await currentSizes( collected.images, win );
		collected.images.forEach( function ( img ) {
			if ( current[ img.src ] ) {
				img.bytes = current[ img.src ];
			}
		} );

		// Desktop (1440px): heading visibility, axe-core.
		setSize( 1440, 900 );
		await load( url );
		win = frame.contentWindow;
		await sleep( 800 );
		var doc = win.document;
		var headingsVisible = Array.prototype.filter.call( doc.querySelectorAll( 'h1,h2,h3,h4,h5,h6' ), function ( h ) {
			return ( h.textContent || '' ).replace( /\s+/g, ' ' ).trim() !== '';
		} ).map( function ( h ) {
			return !! ( h.offsetWidth || h.offsetHeight || h.getClientRects().length );
		} );
		var ids = Array.prototype.map.call( doc.querySelectorAll( '[id], a[name]' ), function ( el ) {
			return el.id || el.getAttribute( 'name' );
		} ).slice( 0, 1500 );
		var axe = await runAxe( win );

		return {
			mobile: { timing: timing, images: collected.images, media: collected.media },
			desktop: { headingsVisible: headingsVisible, ids: ids, axe: axe },
		};
	}

	// Current byte size per same-origin image URL (HEAD, no cache, image Accept header as a browser sends it).
	async function currentSizes( images, win ) {
		var urls = [];
		images.forEach( function ( img ) {
			try {
				if ( img.src && new URL( img.src ).origin === win.location.origin && urls.indexOf( img.src ) === -1 ) {
					urls.push( img.src );
				}
			} catch ( e ) {}
		} );
		var sizes = {};
		await pool( urls, 6, function ( url ) {
			return fetch( url, {
				method: 'HEAD',
				cache: 'no-store',
				credentials: 'same-origin',
				headers: { Accept: 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8' },
			} ).then( function ( r ) {
				var len = +r.headers.get( 'content-length' );
				if ( r.ok && len > 0 ) {
					sizes[ url ] = len;
				}
			} ).catch( function () {} );
		} );
		return sizes;
	}

	async function runAxe( win ) {
		var doc = win.document;
		if ( ! win.axe ) {
			await new Promise( function ( resolve, reject ) {
				var s = doc.createElement( 'script' );
				s.src = cfg.axe;
				s.onload = resolve;
				s.onerror = function () {
					reject( new Error( 'axe-core could not be loaded.' ) );
				};
				doc.head.appendChild( s );
			} );
		}
		if ( cfg.axeLocale && null === axeLocale ) {
			axeLocale = await fetch( cfg.axeLocale ).then( function ( r ) {
				return r.json();
			} ).catch( function () {
				return false;
			} );
		}
		if ( axeLocale ) {
			win.axe.configure( { locale: axeLocale } );
		}
		var res = await win.axe.run( doc, {
			runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice' ] },
			resultTypes: [ 'violations' ],
		} );
		return res.violations.map( function ( v ) {
			return {
				id: v.id,
				impact: v.impact,
				help: v.help,
				helpUrl: v.helpUrl,
				tags: v.tags.filter( function ( x ) {
					return /^wcag\d/.test( x );
				} ),
				count: v.nodes.length,
				nodes: v.nodes.slice( 0, 4 ).map( function ( n ) {
					return {
						target: n.target.join( ' ' ),
						html: n.html.slice( 0, 160 ),
						summary: ( n.failureSummary || '' ).split( '\n' ).slice( 1, 3 ).join( ' ' ).slice( 0, 220 ),
					};
				} ),
			};
		} );
	}

	// With srcset w-descriptors, naturalWidth is density-corrected (e.g. 390 for a 768px file).
	// The real pixel width is the w descriptor of the candidate that was loaded.
	function realSize( img, win ) {
		var w = img.naturalWidth;
		var h = img.naturalHeight;
		var srcset = img.getAttribute( 'srcset' );
		if ( ! srcset || ! w || ! img.currentSrc ) {
			return [ w, h ];
		}
		var found = null;
		srcset.split( /,\s+/ ).forEach( function ( part ) {
			var m = part.trim().match( /^(\S+)\s+(\d+)w$/ );
			if ( m && ! found ) {
				try {
					if ( new URL( m[ 1 ], win.location.href ).href === img.currentSrc ) {
						found = +m[ 2 ];
					}
				} catch ( e ) {}
			}
		} );
		return found ? [ found, Math.round( ( found * h ) / w ) ] : [ w, h ];
	}

	// Same logic as the server collector: nearest meaningful block, region, background images.
	function collectImages( win, blockClass ) {
		var doc = win.document;
		var region = function ( el ) {
			if ( el.closest( 'main, [role=main]' ) ) {
				return 'content';
			}
			if ( el.closest( 'header, #header, [role=banner]' ) ) {
				return 'header';
			}
			if ( el.closest( 'footer, [role=contentinfo]' ) ) {
				return 'footer';
			}
			return 'template';
		};
		var hasMap = Object.keys( blockClass ).length > 0;
		var counters = {};
		var index = new WeakMap();
		var name = function ( n ) {
			for ( var i = 0; i < n.classList.length; i++ ) {
				var c = n.classList[ i ];
				if ( blockClass[ c ] ) {
					return blockClass[ c ];
				}
				if ( ! hasMap && c.indexOf( 'wp-block-' ) === 0 ) {
					return c.slice( 9 );
				}
			}
			return null;
		};
		var label = function ( nm, n ) {
			if ( ! index.has( n ) ) {
				counters[ nm ] = ( counters[ nm ] || 0 ) + 1;
				index.set( n, counters[ nm ] );
			}
			return nm + ' #' + index.get( n );
		};
		doc.querySelectorAll( '[class*="wp-block-"]' ).forEach( function ( n ) {
			var nm = name( n );
			if ( nm ) {
				label( nm, n );
			}
		} );
		var skip = [ 'core/image', 'core/column', 'core/columns' ];
		var blockOf = function ( el ) {
			var first = null;
			for ( var n = el; n && n !== doc.body; n = n.parentElement ) {
				var nm = name( n );
				if ( nm ) {
					if ( ! first ) {
						first = [ nm, n ];
					}
					if ( skip.indexOf( nm ) === -1 ) {
						return label( nm, n );
					}
				}
			}
			if ( first ) {
				return label( first[ 0 ], first[ 1 ] );
			}
			var sec = el.closest( 'section[id], section[class]' );
			if ( sec ) {
				return sec.id ? '#' + sec.id : 'section.' + sec.classList[ 0 ];
			}
			return region( el );
		};
		var vh = win.innerHeight;
		var imgs = Array.prototype.filter.call( doc.images, function ( img ) {
			return ! img.closest( '.swiper-slide-duplicate' );
		} ).map( function ( img, i ) {
			var r = img.getBoundingClientRect();
			var idClass = Array.prototype.find.call( img.classList, function ( c ) {
				return /^wp-image-\d+$/.test( c );
			} );
			var real = realSize( img, win );
			return {
				order: i + 1,
				src: img.currentSrc || img.src,
				alt: img.getAttribute( 'alt' ),
				decorative: img.getAttribute( 'role' ) === 'presentation' || !! img.closest( '[aria-hidden="true"]' ),
				attachmentId: idClass ? +idClass.slice( 9 ) : ( +img.dataset.id || null ),
				naturalWidth: real[ 0 ],
				naturalHeight: real[ 1 ],
				renderedWidth: Math.round( r.width ),
				renderedHeight: Math.round( r.height ),
				objectFit: win.getComputedStyle( img ).objectFit,
				loading: img.getAttribute( 'loading' ),
				srcset: img.hasAttribute( 'srcset' ),
				sizes: img.getAttribute( 'sizes' ),
				hasDims: img.hasAttribute( 'width' ) && img.hasAttribute( 'height' ),
				aboveFold: r.top + win.scrollY < vh,
				block: blockOf( img ),
				region: region( img ),
				background: false,
			};
		} ).filter( function ( img ) {
			return img.src && img.src.indexOf( 'data:' ) !== 0;
		} );
		var bgs = [];
		doc.querySelectorAll( 'main *, [role=main] *' ).forEach( function ( el ) {
			var m = win.getComputedStyle( el ).backgroundImage.match( /url\(["']?(.*?)["']?\)/ );
			if ( m && m[ 1 ].indexOf( 'data:' ) !== 0 ) {
				var r = el.getBoundingClientRect();
				bgs.push( {
					src: new URL( m[ 1 ], win.location.href ).href,
					alt: null,
					background: true,
					renderedWidth: Math.round( r.width ),
					renderedHeight: Math.round( r.height ),
					aboveFold: r.top + win.scrollY < vh,
					block: blockOf( el ),
					region: 'content',
					loading: null,
				} );
			}
		} );
		bgs.forEach( function ( b, i ) {
			b.order = imgs.length + i + 1;
		} );
		var media = Array.prototype.map.call( doc.querySelectorAll( 'iframe, video, audio' ), function ( m ) {
			var source = m.querySelector( 'source' );
			return {
				tag: m.tagName.toLowerCase(),
				src: m.currentSrc || m.src || ( source && source.src ) || null,
				loading: m.getAttribute( 'loading' ),
				preload: m.getAttribute( 'preload' ),
				aboveFold: m.getBoundingClientRect().top + win.scrollY < vh,
				title: m.getAttribute( 'title' ),
				block: blockOf( m ),
				region: region( m ),
			};
		} );
		return { images: imgs.concat( bgs ), media: media };
	}
}() );

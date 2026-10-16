/**
 * Siteproof report: filter (remembered), expand/collapse, jump links, sticky page headers,
 * inline fixes (alt text, proofreading) via the REST API. Also used in the HTML export,
 * where the fix forms are not present.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.sp-report' );
	if ( ! root ) {
		return;
	}
	var cfg = window.siteproofReport;

	// Filter.
	function setFilter( f ) {
		root.dataset.filter = f;
		root.querySelectorAll( '[data-sp-filter]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', String( b.dataset.spFilter === f ) );
		} );
		try {
			localStorage.setItem( 'siteproof-filter', f );
		} catch ( e ) {}
	}
	var saved = 'ry';
	try {
		saved = localStorage.getItem( 'siteproof-filter' ) || 'ry';
	} catch ( e ) {}
	setFilter( saved );

	function openTo( id ) {
		var el = document.getElementById( id );
		if ( ! el ) {
			return;
		}
		for ( var n = el; n; n = n.parentElement ) {
			if ( n.tagName === 'DETAILS' ) {
				n.open = true;
			}
		}
		setTimeout( function () {
			el.scrollIntoView( { block: 'start' } );
		}, 0 );
	}

	root.addEventListener( 'click', function ( e ) {
		var f = e.target.closest( '[data-sp-filter]' );
		if ( f ) {
			setFilter( f.dataset.spFilter );
			return;
		}
		var j = e.target.closest( 'a[href^="#"]' );
		if ( j ) {
			openTo( j.getAttribute( 'href' ).slice( 1 ) );
			return;
		}
		var x = e.target.closest( '[data-sp-toggle]' );
		if ( x ) {
			var open = x.dataset.spToggle === 'open';
			root.querySelectorAll( 'details.sp-page, details.sp-sec' ).forEach( function ( d ) {
				d.open = open;
			} );
		}
	} );
	if ( location.hash ) {
		openTo( location.hash.slice( 1 ) );
	}

	// Sticky page headers sit below the sticky filter bar: keep its height in a custom property.
	var bar = root.querySelector( '.sp-bar' );
	if ( bar ) {
		var setBar = function () {
			root.style.setProperty( '--sp-bar-h', bar.offsetHeight + 'px' );
		};
		setBar();
		if ( window.ResizeObserver ) {
			new ResizeObserver( setBar ).observe( bar );
		}
	}

	// Inline fixes.
	if ( ! cfg ) {
		return;
	}
	root.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( 'form.sp-fix' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var input = form.querySelector( 'input' );
		var msg = form.querySelector( '.sp-fix__msg' );
		var button = form.querySelector( 'button' );
		var value = input.value.trim();
		if ( ! value ) {
			input.focus();
			return;
		}
		var body = form.dataset.fix === 'alt'
			? { attachment: +form.dataset.attachment, post: +form.dataset.post, alt: value }
			: { post: +form.dataset.post, index: +form.dataset.index, suggestion: value };
		button.disabled = true;
		msg.className = 'sp-fix__msg';
		msg.textContent = cfg.i18n.saving;
		fetch( cfg.rest + '/runs/' + root.dataset.run + '/fix/' + form.dataset.fix, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) {
					var err = new Error( j.message || cfg.i18n.failed );
					err.edit = j.data && j.data.edit;
					throw err;
				}
				return j;
			} );
		} ).then( function ( j ) {
			msg.textContent = j.message;
			msg.classList.add( 'is-ok' );
			input.readOnly = true;
			var row = form.closest( 'tr' );
			if ( row ) {
				row.dataset.level = 'green';
				var dot = row.querySelector( '.sp-dot' );
				if ( dot ) {
					dot.className = 'sp-dot sp-dot--green';
				}
			}
		} ).catch( function ( err ) {
			button.disabled = false;
			msg.classList.add( 'is-error' );
			msg.textContent = err.message + ' ';
			if ( err.edit ) {
				var a = document.createElement( 'a' );
				a.href = err.edit;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = cfg.i18n.edit;
				msg.appendChild( a );
			}
		} );
	} );
}() );

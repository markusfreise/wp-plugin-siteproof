/**
 * Siteproof settings: model lists per provider (loaded from the provider's API) and connection tests.
 */
( function () {
	'use strict';

	var cfg = window.siteproofSettings;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;

	function api( path, method, body ) {
		return fetch( cfg.rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( j.message || t.failed );
				}
				return j;
			} );
		} );
	}

	function price( v ) {
		return null === v || undefined === v ? '' : Number( v ).toFixed( 2 );
	}

	function loadModels( select, refresh ) {
		var provider = select.dataset.provider;
		var current = cfg.models[ provider ] || '';
		select.disabled = true;
		api( '/models?provider=' + provider + ( refresh ? '&refresh=1' : '' ) ).then( function ( res ) {
			select.innerHTML = '';
			var def = document.createElement( 'option' );
			def.value = '';
			def.textContent = t.default + ' (' + res.default + ')';
			select.appendChild( def );
			res.models.forEach( function ( m ) {
				var o = document.createElement( 'option' );
				o.value = m.id;
				o.textContent = m.label === m.id ? m.id : m.label + ' – ' + m.id;
				if ( null !== m.in && undefined !== m.in ) {
					o.textContent += ' (' + price( m.in ) + ' / ' + price( m.out ) + ' USD)';
				}
				select.appendChild( o );
			} );
			if ( current && ! res.models.some( function ( m ) {
				return m.id === current;
			} ) ) {
				var keep = document.createElement( 'option' );
				keep.value = current;
				keep.textContent = current;
				select.appendChild( keep );
			}
			select.value = current;
		} ).catch( function () {
			// No key or provider unreachable: keep the stored value.
		} ).then( function () {
			select.disabled = false;
		} );
	}

	document.querySelectorAll( '.sp-model select[data-has-key="1"]' ).forEach( function ( s ) {
		loadModels( s, false );
	} );

	document.addEventListener( 'click', function ( e ) {
		var refresh = e.target.closest( '.sp-refresh' );
		if ( refresh ) {
			var select = document.querySelector( '.sp-model select[data-provider="' + refresh.dataset.provider + '"]' );
			if ( select ) {
				loadModels( select, true );
			}
			return;
		}
		var test = e.target.closest( '.sp-test' );
		if ( ! test ) {
			return;
		}
		var out = test.parentNode.querySelector( '.sp-test__result' );
		out.className = 'sp-test__result';
		out.textContent = t.testing;
		test.disabled = true;
		api( '/test', 'POST', { provider: test.dataset.provider } ).then( function ( r ) {
			out.textContent = r.message;
			out.classList.add( r.ok ? 'is-ok' : 'is-error' );
		} ).catch( function ( err ) {
			out.textContent = err.message;
			out.classList.add( 'is-error' );
		} ).then( function () {
			test.disabled = false;
		} );
	} );
}() );

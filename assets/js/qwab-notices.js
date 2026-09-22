/**
 * Qaiyo Admin Booster — Admin notices tray.
 *
 * A wp-admin tetején halmozódó GLOBÁLIS admin-értesítőket (plugin-promók,
 * nag-ek, frissítési figyelmeztetések) egy összecsukható „tálcába" gyűjti
 * egy harang-számláló gomb alá, így nem tolják lejjebb a tartalmat. Az
 * értesítéseket NEM törli — kinyitva minden eredeti tartalmuk és gombjuk
 * működik.
 *
 * Két fontos óvintézkedés:
 *  1. A WordPress core (common.js) maga is áthelyezi a globális notice-okat
 *     a `.wp-header-end` mögé, ugyanúgy DOM-ready-kor. Ezért egy tickkel
 *     KÉSŐBB futunk (setTimeout 0 + window.load), hogy ne versengjünk vele,
 *     és a már áthelyezett notice-okat is megtaláljuk.
 *  2. Csak a top-level (a #wpbody-content és a .wrap KÖZVETLEN gyermek)
 *     értesítőket gyűjtjük, és kihagyjuk a kontextuális visszajelzéseket
 *     (pl. „Beállítások mentve", „Bővítmény bekapcsolva") és az üres,
 *     placeholder notice-okat — különben üres tálca jelenne meg.
 */
( function () {
	'use strict';

	var L = ( window.qwabNotices && window.qwabNotices.i18n ) || {};

	var SELECTOR =
		'.notice, .updated, .error, .update-nag, .notice-warning, .notice-error, .notice-success, .notice-info';

	function label( count ) {
		if ( count === 1 ) {
			return L.one || '1 notice';
		}
		return ( L.count || '%d notices' ).replace( '%d', count );
	}

	/**
	 * Üres-e a notice? Az elvető gombon kívül nincs benne látható szöveg
	 * vagy vizuális elem → placeholder, nem gyűjtjük be.
	 */
	function hasContent( node ) {
		var clone   = node.cloneNode( true );
		var dismiss = clone.querySelector( '.notice-dismiss' );
		if ( dismiss && dismiss.parentNode ) {
			dismiss.parentNode.removeChild( dismiss );
		}
		if ( ( clone.textContent || '' ).replace( /\s+/g, '' ).length ) {
			return true;
		}
		return !! clone.querySelector( 'img, svg, input, select, button, a' );
	}

	/**
	 * Kontextuális visszajelzés (pl. „Beállítások mentve", „Bővítmény
	 * bekapcsolva") — a helyén hagyjuk, nem tálcázzuk.
	 */
	function isContextual( node ) {
		if ( node.classList.contains( 'inline' ) || node.classList.contains( 'below-h2' ) ) {
			return true;
		}
		if ( node.classList.contains( 'updated' ) || node.classList.contains( 'notice-success' ) ) {
			return true;
		}
		var id = node.id || '';
		return ( id === 'message' || id === 'moved' || id.indexOf( 'setting-error' ) === 0 );
	}

	/**
	 * Top-level globális notice-ok: a #wpbody-content és a .wrap közvetlen
	 * gyermekei (a core ide helyezi át őket), kontextuális/üres szűréssel.
	 */
	function collect() {
		var body = document.getElementById( 'wpbody-content' );
		if ( ! body ) {
			return [];
		}
		var roots = [ body ];
		var wrap  = body.querySelector( '.wrap' );
		if ( wrap ) {
			roots.push( wrap );
		}

		var out = [];
		roots.forEach( function ( root ) {
			Array.prototype.forEach.call( root.children, function ( node ) {
				if ( ! node.matches || ! node.matches( SELECTOR ) ) {
					return;
				}
				if ( node.classList.contains( 'hidden' ) || node.classList.contains( 'qwab-skip' ) ) {
					return;
				}
				if ( isContextual( node ) || ! hasContent( node ) ) {
					return;
				}
				if ( out.indexOf( node ) === -1 ) {
					out.push( node );
				}
			} );
		} );
		return out;
	}

	var tray, bar, panel, text;

	function ensureTray() {
		if ( tray ) {
			return;
		}
		tray           = document.createElement( 'div' );
		tray.className = 'qwab-notice-tray';

		bar      = document.createElement( 'button' );
		bar.type = 'button';
		bar.className = 'qwab-notice-bar';
		bar.setAttribute( 'aria-expanded', 'false' );
		bar.setAttribute( 'aria-label', L.label || 'Admin notices' );
		bar.innerHTML =
			'<span class="qwab-notice-bell dashicons dashicons-bell" aria-hidden="true"></span>' +
			'<span class="qwab-notice-text"></span>' +
			'<span class="qwab-notice-caret dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
		text = bar.querySelector( '.qwab-notice-text' );

		panel           = document.createElement( 'div' );
		panel.className = 'qwab-notice-panel';
		panel.hidden    = true;

		bar.addEventListener( 'click', function () {
			var open = bar.getAttribute( 'aria-expanded' ) === 'true';
			bar.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
			panel.hidden = open;
			tray.classList.toggle( 'is-open', ! open );
		} );

		tray.appendChild( bar );
		tray.appendChild( panel );

		// A H1 (.wp-header-end) mögé, ahová a natív notice-ok is kerülnek;
		// ha nincs, a #wpbody-content tetejére.
		var body   = document.getElementById( 'wpbody-content' );
		var anchor = body ? body.querySelector( '.wrap .wp-header-end' ) : null;
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( tray, anchor.nextSibling );
		} else if ( body ) {
			body.insertBefore( tray, body.firstChild );
		}
	}

	function run() {
		var found = collect();
		if ( ! found.length && ! tray ) {
			return;
		}

		ensureTray();
		found.forEach( function ( node ) {
			panel.appendChild( node );
		} );

		var total = panel.children.length;
		if ( total < 1 ) {
			if ( tray && tray.parentNode ) {
				tray.parentNode.removeChild( tray );
			}
			tray = null;
			return;
		}
		text.textContent = label( total );
	}

	// Egy tickkel a core notice-áthelyezés UTÁN futunk, majd a load-nál még
	// egyszer (késői AJAX-os notice-okhoz). A run() idempotens.
	function boot() {
		window.setTimeout( run, 0 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
	window.addEventListener( 'load', function () {
		window.setTimeout( run, 0 );
	} );
} )();

/**
 * Qaiyo Admin Booster — Menühöz adás a szerkesztőből.
 *
 * A panel törzsét a szerver rendereli, és minden művelet után frissen
 * visszaküldi, ezért az eseményeket a külső konténerre delegáljuk — így az
 * innerHTML-csere után sem kell újrakötni semmit.
 *
 * A meta boxok a szerkesztő iframe-jén KÍVÜL renderelnek, ezért itt a sima
 * `document` a helyes hivatkozás (a WP 7.1-es iframe-változás nem érinti).
 */
( function () {
	'use strict';

	var cfg = window.qwabMenuManager || {};
	var i18n = cfg.i18n || {};

	/**
	 * A szülő-legördülő újraépítése a kiválasztott menü elemeiből.
	 */
	function syncParents( root ) {
		var add = root.querySelector( '.qwab-mm-add' );
		if ( ! add ) {
			return;
		}
		var menuSel = add.querySelector( '.qwab-mm-menu' );
		var parentSel = add.querySelector( '.qwab-mm-parent' );
		if ( ! menuSel || ! parentSel ) {
			return;
		}

		var map = {};
		try {
			map = JSON.parse( add.getAttribute( 'data-parents' ) || '{}' ) || {};
		} catch ( e ) {
			map = {};
		}

		var current = {};
		try {
			current = JSON.parse( add.getAttribute( 'data-current' ) || '{}' ) || {};
		} catch ( e ) {
			current = {};
		}

		var items = map[ menuSel.value ] || [];
		parentSel.innerHTML = '';

		var top = document.createElement( 'option' );
		top.value = '0';
		top.textContent = i18n.topLevel || '— Top level —';
		parentSel.appendChild( top );

		items.forEach( function ( item ) {
			var opt = document.createElement( 'option' );
			opt.value = String( item.id );
			opt.textContent = item.title;
			parentSel.appendChild( opt );
		} );

		// Nincs mibe ágyazni → a szint-választó felesleges.
		parentSel.disabled = ! items.length;

		// Ha a tartalom már benne van ebben a menüben, mutassuk a tényleges
		// jelenlegi szintjét, és a gomb „hozzáadás” helyett „szint frissítése”
		// legyen — így egyértelmű, hogy a művelet áthelyez, nem duplikál.
		var inMenu = Object.prototype.hasOwnProperty.call( current, menuSel.value );
		if ( inMenu ) {
			parentSel.value = String( current[ menuSel.value ] );
		}

		var btn = root.querySelector( '.qwab-mm-submit[data-action="add"]' );
		if ( btn ) {
			var label = inMenu
				? btn.getAttribute( 'data-label-move' )
				: btn.getAttribute( 'data-label-add' );
			if ( label ) {
				btn.textContent = label;
			}
		}
	}

	function setStatus( root, text, state ) {
		var el = root.querySelector( '.qwab-mm-status' );
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.className = 'qwab-mm-status' + ( state ? ' is-' + state : '' );
	}

	function setBusy( root, busy ) {
		var buttons = root.querySelectorAll( 'button' );
		Array.prototype.forEach.call( buttons, function ( b ) {
			b.disabled = !! busy;
		} );
	}

	/**
	 * Művelet küldése és a panel frissítése a válasz HTML-jével.
	 */
	function send( root, payload ) {
		var body = new URLSearchParams();
		body.append( 'action', 'qwab_menu_action' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'post_id', root.getAttribute( 'data-post' ) || '0' );
		Object.keys( payload ).forEach( function ( k ) {
			body.append( k, payload[ k ] );
		} );

		setBusy( root, true );
		setStatus( root, i18n.working || 'Working…' );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					root.innerHTML = res.data.html;
					syncParents( root );
					setStatus( root, res.data.message || '', 'ok' );
				} else {
					setBusy( root, false );
					setStatus( root, ( res && res.data && res.data.message ) || i18n.error || 'Error', 'error' );
				}
			} )
			.catch( function () {
				setBusy( root, false );
				setStatus( root, i18n.error || 'Error', 'error' );
			} );
	}

	function init() {
		var root = document.querySelector( '.qwab-mm' );
		if ( ! root ) {
			return;
		}

		syncParents( root );

		root.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'qwab-mm-menu' ) ) {
				syncParents( root );
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			var target = e.target;
			if ( ! target || ! target.classList ) {
				return;
			}

			// Új menü űrlap ki/be.
			if ( target.classList.contains( 'qwab-mm-toggle-create' ) ) {
				e.preventDefault();
				var form = root.querySelector( '.qwab-mm-create__form' );
				if ( form ) {
					form.hidden = ! form.hidden;
				}
				return;
			}

			// Eltávolítás a menüből.
			if ( target.classList.contains( 'qwab-mm-remove' ) ) {
				e.preventDefault();
				if ( ! window.confirm( i18n.confirmDel || 'Remove this item from the menu?' ) ) {
					return;
				}
				send( root, { what: 'remove', item_id: target.getAttribute( 'data-item' ) || '0' } );
				return;
			}

			// Hozzáadás / létrehozás.
			if ( target.classList.contains( 'qwab-mm-submit' ) ) {
				e.preventDefault();
				var what = target.getAttribute( 'data-action' );

				if ( 'create' === what ) {
					var nameEl = root.querySelector( '.qwab-mm-name' );
					var locEl = root.querySelector( '.qwab-mm-location' );
					send( root, {
						what: 'create',
						menu_name: nameEl ? nameEl.value : '',
						location: locEl ? locEl.value : ''
					} );
					return;
				}

				var menuEl = root.querySelector( '.qwab-mm-menu' );
				var parentEl = root.querySelector( '.qwab-mm-parent' );
				send( root, {
					what: 'add',
					menu_id: menuEl ? menuEl.value : '0',
					parent_id: ( parentEl && ! parentEl.disabled ) ? parentEl.value : '0'
				} );
			}
		} );
	}

	/**
	 * A blokk-szerkesztő a meta boxokat a saját konténerébe helyezi át, ezért
	 * a panel nem feltétlenül van a DOM-ban az első futáskor. A csomópontot a
	 * mozgatás nem semmisíti meg, így a delegált eseménykezelők utána is
	 * élnek — csak meg kell várnunk, amíg megjelenik.
	 */
	function boot( tries ) {
		if ( document.querySelector( '.qwab-mm' ) ) {
			init();
			return;
		}
		if ( tries < 20 ) {
			window.setTimeout( function () {
				boot( tries + 1 );
			}, 150 );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			boot( 0 );
		} );
	} else {
		boot( 0 );
	}
} )();

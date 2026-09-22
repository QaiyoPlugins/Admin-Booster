/**
 * Qaiyo Admin Booster — admin oldal scriptje.
 *
 * Kollekció-kezelő: a felhasználói kollekciók szerkesztése és AJAX mentése.
 */
( function () {
	'use strict';

	var cfg = window.qwabAdmin || {};
	var i18n = cfg.i18n || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		// Modulok: „Összes be / ki" gombok.
		var bulkBtns = document.querySelectorAll( '.qwab-modules-all' );
		bulkBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var on = btn.getAttribute( 'data-check' ) === '1';
				var boxes = document.querySelectorAll( '.qwab-modules-grid input[type="checkbox"]' );
				boxes.forEach( function ( box ) {
					box.checked = on;
				} );
			} );
		} );

		// Tabok (AM-stílusú pill sáv, kliensoldali váltás — az egész űrlap egyben
		// marad, így a Mentés minden tab beállítását elküldi).
		( function () {
			var tabs = document.querySelectorAll( '.qwab-tab' );
			if ( ! tabs.length ) {
				return;
			}
			var submitBar = document.querySelector( '.qwab-submit-bar' );

			function activate( name ) {
				document.querySelectorAll( '.qwab-tab' ).forEach( function ( t ) {
					var on = t.getAttribute( 'data-tab' ) === name;
					t.classList.toggle( 'is-active', on );
					t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				} );
				var activePanel = null;
				document.querySelectorAll( '.qwab-tab-panel' ).forEach( function ( p ) {
					var on = p.getAttribute( 'data-panel' ) === name;
					p.hidden = ! on;
					if ( on ) {
						activePanel = p;
					}
				} );
				// A Free „Beállítások mentése" sáv csak akkor látszik, ha az aktív
				// panel a Free űrlapon belül van (a Pro/teaser paneleknek saját
				// mentőgombjuk van, vagy nincs mentenivalójuk).
				if ( submitBar ) {
					var inFreeForm = activePanel && activePanel.closest( '.qwab-settings-form' );
					submitBar.style.display = inFreeForm ? '' : 'none';
				}
				try {
					window.sessionStorage.setItem( 'qwabActiveTab', name );
				} catch ( e ) {}
			}

			tabs.forEach( function ( t ) {
				t.addEventListener( 'click', function () {
					activate( t.getAttribute( 'data-tab' ) );
				} );
			} );

			// Az aktív tab visszaállítása mentés (oldal-újratöltés) után.
			var stored = '';
			try {
				stored = window.sessionStorage.getItem( 'qwabActiveTab' ) || '';
			} catch ( e ) {}
			// Egy szerveroldali átirányítás (?qwab_tab=…) felülírja a tárolt fület.
			var match = window.location.search.match( /[?&]qwab_tab=([a-z0-9-]+)/ );
			if ( match ) {
				stored = match[ 1 ];
			}
			if ( stored && document.querySelector( '.qwab-tab[data-tab="' + stored + '"]' ) ) {
				activate( stored );
			}
		} )();

		var list = document.getElementById( 'qwab-collections-list' );
		var addBtn = document.getElementById( 'qwab-add-collection' );
		var saveBtn = document.getElementById( 'qwab-save-collections' );
		var status = document.getElementById( 'qwab-collections-status' );

		if ( ! list ) {
			return;
		}

		var icons = cfg.iconChoices || [ 'dashicons-category' ];

		function rowTemplate( c ) {
			c = c || { id: '', name: '', color: '#6c5ce7', icon: 'dashicons-category' };
			var row = document.createElement( 'div' );
			row.className = 'qwab-collection-row';
			row.setAttribute( 'data-id', c.id || '' );

			var iconOptions = icons
				.map( function ( ic ) {
					var sel = ic === c.icon ? ' selected' : '';
					return '<option value="' + ic + '"' + sel + '>' + ic.replace( 'dashicons-', '' ) + '</option>';
				} )
				.join( '' );

			row.innerHTML =
				'<span class="qwab-collection-row__preview dashicons ' + escAttr( c.icon ) + '" style="color:' + escAttr( c.color ) + '"></span>' +
				'<input type="text" class="qwab-c-name regular-text" value="' + escAttr( c.name ) + '" placeholder="' + escAttr( i18n.namePlace || 'Name' ) + '" />' +
				'<label class="qwab-c-color-wrap" title="' + escAttr( i18n.hexLabel || 'Color' ) + '"><input type="color" class="qwab-c-color" value="' + escAttr( c.color ) + '" /></label>' +
				'<select class="qwab-c-icon" title="' + escAttr( i18n.iconLabel || 'Icon' ) + '">' + iconOptions + '</select>' +
				'<button type="button" class="button-link qwab-c-remove" aria-label="' + escAttr( i18n.remove || 'Remove' ) + '"><span class="dashicons dashicons-trash"></span></button>';

			// Élő előnézet.
			var preview = row.querySelector( '.qwab-collection-row__preview' );
			var colorIn = row.querySelector( '.qwab-c-color' );
			var iconIn = row.querySelector( '.qwab-c-icon' );

			colorIn.addEventListener( 'input', function () {
				preview.style.color = colorIn.value;
			} );
			iconIn.addEventListener( 'change', function () {
				preview.className = 'qwab-collection-row__preview dashicons ' + iconIn.value;
			} );
			row.querySelector( '.qwab-c-remove' ).addEventListener( 'click', function () {
				if ( window.confirm( i18n.confirmDel || 'Remove?' ) ) {
					row.parentNode.removeChild( row );
				}
			} );

			return row;
		}

		function escAttr( s ) {
			return String( s == null ? '' : s )
				.replace( /&/g, '&amp;' )
				.replace( /"/g, '&quot;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' );
		}

		function render( collections ) {
			list.innerHTML = '';
			( collections || [] ).forEach( function ( c ) {
				list.appendChild( rowTemplate( c ) );
			} );
		}

		function collect() {
			var rows = list.querySelectorAll( '.qwab-collection-row' );
			var out = [];
			rows.forEach( function ( row ) {
				var name = row.querySelector( '.qwab-c-name' ).value.trim();
				if ( ! name ) {
					return;
				}
				out.push( {
					id: row.getAttribute( 'data-id' ) || '',
					name: name,
					color: row.querySelector( '.qwab-c-color' ).value,
					icon: row.querySelector( '.qwab-c-icon' ).value,
				} );
			} );
			return out;
		}

		function setStatus( msg, isError ) {
			if ( ! status ) {
				return;
			}
			status.textContent = msg;
			status.className = 'qwab-collections-status' + ( isError ? ' is-error' : ' is-ok' );
			if ( ! isError ) {
				window.setTimeout( function () {
					status.textContent = '';
					status.className = 'qwab-collections-status';
				}, 3000 );
			}
		}

		render( cfg.collections || [] );

		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				list.appendChild( rowTemplate() );
			} );
		}

		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', function () {
				saveBtn.disabled = true;
				var body = new URLSearchParams();
				body.append( 'action', 'qwab_save_collections' );
				body.append( 'nonce', cfg.nonce || '' );
				body.append( 'collections', JSON.stringify( collect() ) );

				fetch( cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString(),
				} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						saveBtn.disabled = false;
						if ( res && res.success ) {
							render( res.data.collections || [] );
							setStatus( i18n.saved || 'Saved.', false );
						} else {
							setStatus( ( res && res.data && res.data.message ) || i18n.saveError || 'Error.', true );
						}
					} )
					.catch( function () {
						saveBtn.disabled = false;
						setStatus( i18n.saveError || 'Error.', true );
					} );
			} );
		}
	} );
} )();

/**
 * „Teszt e-mail küldése" gomb a Frissítési értesítések kártyán.
 *
 * A wp_mail() csendben is elhalhat (nincs SMTP, a hosting tiltja a mail()-t),
 * ezért azonnali visszajelzést adunk — ne a következő digestből derüljön ki.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var cfg = window.qwabAdmin || {};
		var i18n = cfg.i18n || {};
		var btn = document.getElementById( 'qwab-test-mail' );
		var out = document.querySelector( '.qwab-test-mail-result' );

		if ( ! btn || ! out ) {
			return;
		}

		function say( message, isError ) {
			out.textContent = message;
			out.classList.toggle( 'is-error', !! isError );
		}

		btn.addEventListener( 'click', function () {
			var body = new URLSearchParams();

			btn.disabled = true;
			say( i18n.mailSending || 'Sending…', false );

			body.append( 'action', 'qwab_test_update_mail' );
			body.append( '_ajax_nonce', cfg.mailNonce || '' );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					btn.disabled = false;
					if ( res && res.success ) {
						say( ( res.data && res.data.message ) || '', false );
					} else {
						say( ( res && res.data && res.data.message ) || i18n.mailError || 'Error.', true );
					}
				} )
				.catch( function () {
					btn.disabled = false;
					say( i18n.mailError || 'Error.', true );
				} );
		} );
	} );
} )();

/**
 * Safe Mode link másolása.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn = document.getElementById( 'qwab-safe-mode-copy' );
		var input = document.getElementById( 'qwab_safe_mode_url' );

		if ( ! btn || ! input ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var label = btn.textContent;

			function done() {
				btn.textContent = btn.getAttribute( 'data-copied' ) || label;
				window.setTimeout( function () {
					btn.textContent = label;
				}, 2000 );
			}

			input.select();
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( input.value ).then( done, function () {
					document.execCommand( 'copy' );
					done();
				} );
			} else {
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );
} )();

/**
 * Qaiyo Admin Booster — Gutenberg UX.
 *
 * A blokk-szerkesztő betöltésekor kinyitja a bal oldali blokkillesztőt és
 * opcionálisan kikapcsolja a teljes képernyős módot. Egyszer fut le, hogy
 * a felhasználó döntését (ha kézzel becsukja) ne írja felül folyamatosan.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.data || ! wp.domReady ) {
		return;
	}

	var cfg = window.qwabGutenberg || {};

	wp.domReady( function () {
		// Teljes képernyős mód kikapcsolása.
		if ( cfg.disableFullscreen ) {
			try {
				var prefs = wp.data.select( 'core/preferences' );
				var isFs = prefs && prefs.get
					? prefs.get( 'core', 'fullscreenMode' )
					: ( wp.data.select( 'core/edit-post' ) &&
						wp.data.select( 'core/edit-post' ).isFeatureActive &&
						wp.data.select( 'core/edit-post' ).isFeatureActive( 'fullscreenMode' ) );

				if ( isFs ) {
					if ( wp.data.dispatch( 'core/preferences' ) && wp.data.dispatch( 'core/preferences' ).toggle ) {
						wp.data.dispatch( 'core/preferences' ).toggle( 'core', 'fullscreenMode' );
					} else if ( wp.data.dispatch( 'core/edit-post' ) && wp.data.dispatch( 'core/edit-post' ).toggleFeature ) {
						wp.data.dispatch( 'core/edit-post' ).toggleFeature( 'fullscreenMode' );
					}
				}
			} catch ( e ) {}
		}

		// Blokkillesztő (inserter) kinyitása — kis késleltetéssel, hogy a
		// szerkesztő store-ja már készen álljon.
		//
		// A `core/edit-post` inserter-metódusai WP 6.5 óta ELAVULTAK
		// (`dispatch( 'core/edit-post' ).setIsInserterOpened` →
		// `dispatch( 'core/editor' ).setIsInserterOpened`), és konzol-
		// figyelmeztetést írnak. Ezért elsőként a `core/editor` store-t
		// próbáljuk, és csak régebbi WordPresseken esünk vissza a régire.
		if ( cfg.openInserter ) {
			var tries = 0;

			// A használható store neve: az új `core/editor`, különben a régi.
			var pickStore = function () {
				var names = [ 'core/editor', 'core/edit-post' ];
				for ( var i = 0; i < names.length; i++ ) {
					var dispatcher = wp.data.dispatch( names[ i ] );
					var selector = wp.data.select( names[ i ] );
					if (
						dispatcher && dispatcher.setIsInserterOpened &&
						selector && selector.isInserterOpened
					) {
						return { dispatch: dispatcher, select: selector };
					}
				}
				return null;
			};

			var open = function () {
				tries++;
				try {
					var store = pickStore();
					if ( store ) {
						if ( ! store.select.isInserterOpened() ) {
							store.dispatch.setIsInserterOpened( true );
						}
						return;
					}
				} catch ( e ) {}
				if ( tries < 20 ) {
					window.setTimeout( open, 150 );
				}
			};
			window.setTimeout( open, 200 );
		}
	} );
} )( window.wp );

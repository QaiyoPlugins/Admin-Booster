/**
 * Qaiyo Admin Booster — Plugin upload panel auto-open.
 *
 * A plugin-install.php képernyőn a WordPress a feltöltő dobozt egy
 * összecsukott panelben tartja, amit a „Bővítmény feltöltése" gomb
 * a `.wrap` elemre tett `show-upload-view` osztállyal gördít le.
 * Ezt a panelt nyitjuk ki automatikusan, hogy a feltöltő doboz egyből
 * látszódjon — a bővítmény-könyvtár (fülek + kártyák) alatta megmarad.
 *
 * A böngésző/keresés fülek nélküli `?tab=upload` nézetben nincs
 * `.upload-view-toggle` gomb, így ott a kód nem csinál semmit.
 */
( function () {
	'use strict';

	function openUploadPanel() {
		var toggle = document.querySelector( '.upload-view-toggle' );
		var wrap   = document.querySelector( '.wrap' );

		// Csak a böngésző nézetben (ahol a legördíthető panel létezik).
		if ( ! toggle || ! wrap ) {
			return;
		}

		if ( ! wrap.classList.contains( 'show-upload-view' ) ) {
			wrap.classList.add( 'show-upload-view' );
			toggle.setAttribute( 'aria-expanded', 'true' );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', openUploadPanel );
	} else {
		openUploadPanel();
	}
} )();

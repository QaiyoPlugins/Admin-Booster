/**
 * Qaiyo Admin Booster — Bulk Create oldal: élő sorszámláló.
 */
( function () {
	'use strict';

	var ta  = document.getElementById( 'qwab_bulk_titles' );
	var out = document.querySelector( '.qwab-bulk-counter' );
	if ( ! ta || ! out ) {
		return;
	}

	var tpl = ( typeof qwabBulk !== 'undefined' && qwabBulk.counterTpl )
		? qwabBulk.counterTpl
		: '%d';

	function update() {
		var n = ta.value.split( /\r\n|\r|\n/ ).filter( function ( line ) {
			return line.trim() !== '';
		} ).length;
		out.textContent = tpl.replace( '%d', n );
	}

	ta.addEventListener( 'input', update );
	update();
}() );

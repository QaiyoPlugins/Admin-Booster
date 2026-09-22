/**
 * Qaiyo Admin Booster — külön „Frissítések" táblázat a Bővítmények oldalon.
 *
 * A frissítésre váró bővítményeket kiemeljük a fő listából, és egy TELJESEN
 * KÜLÖN <table> elembe tesszük, saját címsorral; alatta második címsorral
 * következik az érintetlen fő táblázat az összes többi bővítménnyel.
 *
 * MIÉRT KLIENSOLDALON: a `WP_Plugins_List_Table::prepare_items()` a saját
 * `plugins_list` szűrője (WP 6.3+) UTÁN mindig lefuttat egy név szerinti
 * `uasort()`-ot, ami bármilyen szerveroldali sorrendet felülírna. Ugyanez a
 * minta, mint az admin-notice tálcánál: a WP kirenderelt HTML-jét rendezzük
 * át, nem a belső adatszerkezetét.
 *
 * A CORE-BÓL LEVEZETETT MEGKÖTÉSEK (wp-admin/js/updates.js, common.js):
 *
 * 1. Az új táblázat a `#bulk-action-form`-on BELÜL marad, különben a tömeges
 *    műveletek (pl. „Frissítés") nem küldenék be a kipipált sorokat
 *    (`$bulkActionForm.find('input[name="checked[]"]:checked')`).
 * 2. A plugin sora és a hozzá tartozó `tr.plugin-update-tr` értesítő-sor
 *    EGYMÁS TESTVÉRE kell maradjon: az updates.js
 *    `$plugin.siblings('[data-plugin="…"]')` alapján keresi meg.
 * 3. Az új táblázat NEM kaphat <thead>-et: az updates.js a frissítési
 *    üzenetek colspan-jét így számolja:
 *    `$('#bulk-action-form').find('thead th:not(.hidden), thead td').length`
 *    — egy második <thead> megduplázná az értéket, és szétesne a táblázat.
 *    Ezért a fejléc-sor a <tbody>-ban él, `.qwab-updates-headrow` osztállyal,
 *    és a „mindet kijelöl" jelölőnégyzetet magunk drótozzuk be (a core
 *    common.js is csak `thead`/`tfoot` cellákra figyel).
 * 4. A klónozott fejléc-sor minden `id`-jét átnevezzük, hogy ne duplázzuk az
 *    eredeti tábla azonosítóit (pl. `cb-select-all-1`).
 *
 * Ha nincs frissítendő bővítmény, semmi nem történik — nincs üres címsor.
 */
( function () {
	'use strict';

	var cfg = window.qwabUpdateGroups || {};

	function label( key, fallback ) {
		return cfg[ key ] || fallback;
	}

	/**
	 * Frissítendő sorok begyűjtése, mindegyik a saját értesítő-sorával együtt.
	 *
	 * @param {HTMLElement} body A fő táblázat tbody eleme.
	 * @return {Array} Sor-csoportok tömbje, az eredeti sorrendben.
	 */
	function collect( body ) {
		var rows = Array.prototype.slice.call( body.children ),
			groups = [],
			i, row, plugin, pair, next;

		for ( i = 0; i < rows.length; i++ ) {
			row = rows[ i ];

			if ( ! row.classList || ! row.classList.contains( 'update' ) ) {
				continue;
			}

			plugin = row.getAttribute( 'data-plugin' );
			if ( ! plugin ) {
				continue;
			}

			pair = [ row ];

			// A sárga értesítő-sor(ok) a plugin sora után közvetlenül állnak.
			next = rows[ i + 1 ];
			while (
				next &&
				next.classList &&
				next.classList.contains( 'plugin-update-tr' ) &&
				( ! next.hasAttribute( 'data-plugin' ) || next.getAttribute( 'data-plugin' ) === plugin )
			) {
				pair.push( next );
				i++;
				next = rows[ i + 1 ];
			}

			groups.push( pair );
		}

		return groups;
	}

	/**
	 * A fő táblázat fejléc-sorának klónja, ütközésmentes azonosítókkal.
	 *
	 * @param {HTMLElement} table A fő táblázat.
	 * @return {HTMLElement|null} A klónozott sor, vagy null.
	 */
	function buildHeadRow( table ) {
		var source = table.querySelector( 'thead tr' ),
			row;

		if ( ! source ) {
			return null;
		}

		row = source.cloneNode( true );
		row.classList.add( 'qwab-updates-headrow' );

		Array.prototype.forEach.call( row.querySelectorAll( '[id]' ), function ( el ) {
			var oldId = el.id,
				newId = 'qwab-updates-' + oldId,
				tied = row.querySelector( 'label[for="' + oldId + '"]' );

			el.id = newId;

			if ( tied ) {
				tied.setAttribute( 'for', newId );
			}
		} );

		return row;
	}

	/**
	 * „Mindet kijelöl" a saját fejléc-sorunkban — a core csak thead/tfoot
	 * jelölőnégyzeteket kezel, a mienk viszont a tbody-ban van.
	 *
	 * @param {HTMLElement} headRow Fejléc-sor.
	 * @param {HTMLElement} body    Az új táblázat tbody eleme.
	 */
	function wireSelectAll( headRow, body ) {
		var master = headRow.querySelector( 'input[type="checkbox"]' );

		if ( ! master ) {
			return;
		}

		function boxes() {
			return Array.prototype.slice.call(
				body.querySelectorAll( 'input[name="checked[]"]:not(:disabled)' )
			);
		}

		master.checked = false;

		master.addEventListener( 'change', function () {
			boxes().forEach( function ( box ) {
				box.checked = master.checked;
			} );
		} );

		body.addEventListener( 'change', function ( event ) {
			var all;

			if ( ! event.target || 'checked[]' !== event.target.name ) {
				return;
			}

			all = boxes();
			master.checked = all.length > 0 && all.every( function ( box ) {
				return box.checked;
			} );
		} );
	}

	/**
	 * Szekció-címsor létrehozása.
	 *
	 * @param {string} text Címsor szövege.
	 * @param {string} id   Azonosító (aria-labelledby hivatkozáshoz).
	 * @return {HTMLElement} A címsor eleme.
	 */
	function heading( text, id ) {
		var node = document.createElement( 'h2' );

		node.className = 'qwab-update-group-title';
		node.id = id;
		node.textContent = text;

		return node;
	}

	/**
	 * A két táblázat oszlopszélességeinek összehangolása.
	 *
	 * A core `white-space: nowrap`-et tesz a `.plugin-title` cellákra, és a
	 * többi oszlop is a saját tartalmához igazodik, így a két táblázatban más
	 * helyen kezdődnének az oszlopok. Minden rögzített oszlopra a két
	 * táblázat közül a nagyobbik természetes szélességet írjuk be, a táblázat
	 * szélességének százalékában — a rugalmas leírás-oszlop és a
	 * jelölőnégyzet-oszlop marad a böngészőre.
	 *
	 * Előbb mindig NULLÁZZUK a korábbi értékeket és úgy mérünk (a
	 * `getBoundingClientRect()` kikényszeríti az újratördelést), különben a
	 * legutóbb beállított szélességet mérnénk vissza.
	 *
	 * @param {HTMLElement} mine   A saját táblázat fejléc-sora.
	 * @param {HTMLElement} theirs A fő táblázat fejléc-sora.
	 * @param {HTMLElement} ref    Referencia táblázat a teljes szélességhez.
	 */
	function syncColumns( mine, theirs, ref ) {
		var pairs = [],
			total, i, a, b;

		if ( ! mine || ! theirs || ! ref ) {
			return;
		}

		for ( i = 0; i < mine.children.length; i++ ) {
			a = mine.children[ i ];
			b = theirs.children[ i ];

			// A két sor ugyanannak a fejlécnek a mása, az indexek fedik egymást.
			if ( ! b ) {
				break;
			}

			if ( a.classList.contains( 'column-description' ) || a.classList.contains( 'check-column' ) ) {
				continue;
			}

			a.style.width = '';
			b.style.width = '';
			pairs.push( [ a, b ] );
		}

		total = ref.getBoundingClientRect().width;

		if ( total <= 0 || ! pairs.length ) {
			return;
		}

		// Előbb minden szélességet kimérünk, és csak utána írjuk be őket —
		// egy beírt érték már megváltoztatná a többi oszlop tördelését.
		pairs.forEach( function ( pair ) {
			pair.push( Math.max(
				pair[ 0 ].getBoundingClientRect().width,
				pair[ 1 ].getBoundingClientRect().width
			) );
		} );

		pairs.forEach( function ( pair ) {
			var wide = pair[ 2 ];

			// Értelmetlen mérés (még nem állt be az elrendezés): hagyjuk a
			// böngésző saját számítását — a `load`/`resize` úgyis újrafuttat.
			if ( wide <= 0 || wide >= total ) {
				return;
			}

			pair[ 0 ].style.width = pair[ 1 ].style.width = ( ( wide / total ) * 100 ).toFixed( 3 ) + '%';
		} );
	}

	function init() {
		var body = document.getElementById( 'the-list' ),
			table = body ? body.closest( 'table' ) : null,
			form = document.getElementById( 'bulk-action-form' ),
			groups, parent, newTable, newBody, headRow, restHeading;

		// Csak a Bővítmények listaoldal saját, tömeges műveletet kezelő
		// űrlapján dolgozunk — máshol ne nyúljunk semmihez.
		if ( ! body || ! table || ! table.parentNode || ! form || ! form.contains( table ) ) {
			return;
		}

		groups = collect( body );
		if ( ! groups.length ) {
			return;
		}

		newTable = document.createElement( 'table' );
		newTable.className = table.className + ' qwab-updates-table';
		newTable.setAttribute( 'aria-labelledby', 'qwab-updates-heading' );

		newBody = document.createElement( 'tbody' );
		newBody.className = 'qwab-updates-tbody';
		newTable.appendChild( newBody );

		headRow = buildHeadRow( table );
		if ( headRow ) {
			newBody.appendChild( headRow );
		}

		// appendChild() áthelyez: a sorok kikerülnek az eredeti táblázatból.
		groups.forEach( function ( pair ) {
			pair.forEach( function ( row ) {
				newBody.appendChild( row );
			} );
		} );

		if ( headRow ) {
			wireSelectAll( headRow, newBody );
		}

		parent = table.parentNode;
		parent.insertBefore( heading( label( 'updatesHeading', 'Updates' ), 'qwab-updates-heading' ), table );
		parent.insertBefore( newTable, table );

		restHeading = heading( label( 'restHeading', 'All plugins' ), 'qwab-rest-heading' );
		parent.insertBefore( restHeading, table );
		table.setAttribute( 'aria-labelledby', 'qwab-rest-heading' );

		// Ha minden bővítmény frissítendő, az alsó táblázat üresen maradna.
		if ( ! body.querySelector( 'tr' ) ) {
			restHeading.hidden = true;
			table.classList.add( 'qwab-update-group-empty' );
			return;
		}

		if ( ! headRow ) {
			return;
		}

		( function () {
			var theirs = table.querySelector( 'thead tr' ),
				timer = null;

			function sync() {
				syncColumns( headRow, theirs, table );
			}

			sync();

			// A képek/betűtípusok betöltése és minden átméretezés után
			// újraszámolunk — a DOMContentLoaded pillanatában az elrendezés
			// még nem feltétlenül végleges.
			window.addEventListener( 'load', sync );
			window.addEventListener( 'resize', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( sync, 150 );
			} );
		} )();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

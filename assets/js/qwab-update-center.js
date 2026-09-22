/**
 * Qaiyo Admin Booster — Frissítés-központ widget.
 *
 * A frissítéseket a saját szerveroldali AJAX handler (`qwab_uc_update`) végzi,
 * amely a core Plugin_Upgrader/Theme_Upgrader-t futtatja. NEM a headless
 * wp.updates JS API-t használjuk, mert az a Vezérlőpulton gyakran beragad a
 * fájlrendszer-hitelesítésnél (örök „frissítés…" hibaüzenet nélkül). Így minden
 * frissítés egyértelmű siker/hiba választ ad.
 *
 * Az „Összes frissítése" egyszerre csak EGY frissítést futtat (sor), hogy ne
 * terhelje túl a szervert és ne ütközzenek a fájlműveletek.
 */
( function ( $ ) {
	'use strict';

	var UC = window.qwabUC || {};
	var I  = UC.i18n || {};

	var queue   = [];
	var running = false;

	function status( $row, text ) {
		$row.find( '.qwab-uc-status' ).text( text || '' );
	}

	function reenableAll() {
		if ( ! running && 0 === queue.length ) {
			$( '#qwab-uc .qwab-uc-update-all' ).prop( 'disabled', false );
		}
	}

	function checkAllDone() {
		if ( 0 === $( '#qwab-uc .qwab-uc-row' ).length ) {
			$( '#qwab-uc .qwab-uc-list, #qwab-uc .qwab-uc-actions' ).hide();
			$( '#qwab-uc .qwab-uc-empty' ).show();
		}
	}

	function onDone( $row ) {
		$row.attr( 'data-state', 'done' ).addClass( 'qwab-uc-row--done' );
		status( $row, I.updated || 'Updated' );
		window.setTimeout( function () {
			$row.slideUp( 200, function () {
				$( this ).remove();
				checkAllDone();
			} );
		}, 1000 );
	}

	function onFail( $row, message ) {
		$row.attr( 'data-state', 'error' );
		$row.find( '.qwab-uc-update' ).prop( 'disabled', false );
		status( $row, message || I.failed || 'Update failed' );
	}

	function doUpdate( $row, done ) {
		$row.attr( 'data-state', 'updating' );
		$row.find( '.qwab-uc-update' ).prop( 'disabled', true );
		status( $row, I.updating || 'Updating…' );

		$.post( UC.ajaxUrl, {
			action: 'qwab_uc_update',
			nonce:  UC.nonce,
			type:   String( $row.data( 'type' ) ),
			slug:   String( $row.data( 'slug' ) ),
			plugin: String( $row.data( 'plugin' ) || '' )
		} ).done( function ( res ) {
			if ( res && res.success ) {
				onDone( $row );
			} else {
				onFail( $row, res && res.data && res.data.message );
			}
		} ).fail( function () {
			onFail( $row );
		} ).always( function () {
			done();
		} );
	}

	function pump() {
		if ( running ) {
			return;
		}
		var $row = queue.shift();
		if ( ! $row ) {
			reenableAll();
			return;
		}
		if ( 'done' === $row.attr( 'data-state' ) || 'updating' === $row.attr( 'data-state' ) ) {
			pump();
			return;
		}
		running = true;
		doUpdate( $row, function () {
			running = false;
			pump();
		} );
	}

	function enqueueRow( $row ) {
		queue.push( $row );
		pump();
	}

	$( function () {
		var $root = $( '#qwab-uc' );
		if ( ! $root.length ) {
			return;
		}

		$root.on( 'click', '.qwab-uc-update', function ( e ) {
			e.preventDefault();
			enqueueRow( $( this ).closest( '.qwab-uc-row' ) );
		} );

		$root.on( 'click', '.qwab-uc-update-all', function ( e ) {
			e.preventDefault();
			$( this ).prop( 'disabled', true );
			$root.find( '.qwab-uc-row' ).each( function () {
				enqueueRow( $( this ) );
			} );
		} );

		$root.on( 'click', '.qwab-uc-check', function ( e ) {
			e.preventDefault();
			var $btn = $( this ).prop( 'disabled', true );
			$btn.find( '.qwab-uc-check-label' ).text( I.checking || 'Checking…' );
			$.post( UC.ajaxUrl, { action: 'qwab_uc_check', nonce: UC.nonce } )
				.always( function () {
					window.location.reload();
				} );
		} );
	} );
} )( jQuery );

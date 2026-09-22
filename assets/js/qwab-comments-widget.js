/**
 * Qaiyo Admin Booster — Hozzászólás-widget.
 *
 * A kijelölt hozzászólás(oka)t a saját AJAX handlerünk (qwab_comments_action)
 * helyezi kukába a core wp_trash_comment()-tel. Egyesével (soronkénti kuka
 * gomb) vagy tömegesen (jelölőnégyzetek + „Kukába" gomb).
 */
( function ( $ ) {
	'use strict';

	var CW = window.qwabComments || {};
	var I  = CW.i18n || {};

	function updateCounts( counts ) {
		if ( ! counts ) {
			return;
		}
		$( '#qwab-cw .qwab-cw-stat--approved strong' ).text( counts.approved );
		$( '#qwab-cw .qwab-cw-stat--pending strong' ).text( counts.moderated );
		$( '#qwab-cw .qwab-cw-stat--spam strong' ).text( counts.spam );
		$( '#qwab-cw .qwab-cw-stat--trash strong' ).text( counts.trash );

		// Cím-badge (moderálásra váró darabszám) frissítése.
		var $badge = $( '#qwab_comments .qwab-cw-title-count, #qwab-cw' ).closest( '.postbox' ).find( '.qwab-cw-title-count' );
		if ( $badge.length ) {
			if ( counts.moderated > 0 ) {
				$badge.text( counts.moderated );
			} else {
				$badge.remove();
			}
		}
	}

	function refreshBulkState() {
		var any = $( '#qwab-cw .qwab-cw-cb:checked' ).length > 0;
		$( '#qwab-cw .qwab-cw-bulk-trash' ).prop( 'disabled', ! any );
	}

	function afterRemoval() {
		if ( 0 === $( '#qwab-cw .qwab-cw-row' ).length ) {
			$( '#qwab-cw .qwab-cw-toolbar, #qwab-cw .qwab-cw-list, #qwab-cw .qwab-cw-footer' ).hide();
			$( '#qwab-cw' ).append(
				'<div class="qwab-cw-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><p>' +
				( I.allDone || 'No comments to show.' ) + '</p></div>'
			);
		}
		refreshBulkState();
	}

	function trash( ids, $rows, $btn ) {
		if ( ! ids.length ) {
			return;
		}
		if ( $btn ) {
			$btn.prop( 'disabled', true );
		}
		$rows.find( '.qwab-cw-status' ).text( I.working || 'Working…' );

		$.post( CW.ajaxUrl, {
			action: 'qwab_comments_action',
			nonce:  CW.nonce,
			ids:    ids
		} ).done( function ( res ) {
			if ( res && res.success ) {
				var done = ( res.data && res.data.trashed ) || [];
				done.forEach( function ( id ) {
					$( '#qwab-cw .qwab-cw-row[data-id="' + id + '"]' ).slideUp( 180, function () {
						$( this ).remove();
						afterRemoval();
					} );
				} );
				updateCounts( res.data && res.data.counts );
			} else {
				$rows.find( '.qwab-cw-status' ).text( ( res && res.data && res.data.message ) || I.error || 'Error' );
				if ( $btn ) {
					$btn.prop( 'disabled', false );
				}
			}
		} ).fail( function () {
			$rows.find( '.qwab-cw-status' ).text( I.error || 'Error' );
			if ( $btn ) {
				$btn.prop( 'disabled', false );
			}
		} );
	}

	$( function () {
		var $root = $( '#qwab-cw' );
		if ( ! $root.length ) {
			return;
		}

		// Egyenkénti kukázás.
		$root.on( 'click', '.qwab-cw-trash', function ( e ) {
			e.preventDefault();
			var $row = $( this ).closest( '.qwab-cw-row' );
			trash( [ $row.data( 'id' ) ], $row );
		} );

		// Tömeges kukázás.
		$root.on( 'click', '.qwab-cw-bulk-trash', function ( e ) {
			e.preventDefault();
			var ids = [];
			var $rows = $();
			$root.find( '.qwab-cw-cb:checked' ).each( function () {
				ids.push( $( this ).val() );
				$rows = $rows.add( $( this ).closest( '.qwab-cw-row' ) );
			} );
			if ( ! ids.length ) {
				return;
			}
			if ( ! window.confirm( I.confirmBulk || 'Move the selected comments to the Trash?' ) ) {
				return;
			}
			trash( ids, $rows, $( this ) );
		} );

		// „Összes kijelölése".
		$root.on( 'change', '.qwab-cw-checkall', function () {
			$root.find( '.qwab-cw-cb' ).prop( 'checked', $( this ).prop( 'checked' ) );
			refreshBulkState();
		} );

		$root.on( 'change', '.qwab-cw-cb', refreshBulkState );
	} );
} )( jQuery );

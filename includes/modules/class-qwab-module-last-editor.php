<?php
/**
 * Modul: Utolsó szerkesztő oszlop.
 *
 * A bejegyzés/oldal (és egyéb tartalmtípus) listanézetbe egy „Utoljára
 * szerkesztette" oszlopot tesz: ki nyúlt hozzá utoljára a szerkesztőben
 * (`_edit_last`, vagy ha nincs, a szerző) és mennyivel ezelőtt módosult.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Last_Editor {

    const COL = 'qwab_last_editor';

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        foreach ( Qwab_Settings::boostable_post_types() as $type ) {
            add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
            add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
        }
    }

    /**
     * Oszlop hozzáadása.
     *
     * @param array $columns Oszlopok.
     * @return array
     */
    public function add_column( $columns ) {
        $columns[ self::COL ] = __( 'Last edited', 'qaiyo-admin-booster' );
        return $columns;
    }

    /**
     * Oszlop tartalom.
     *
     * @param string $column  Oszlop azonosító.
     * @param int    $post_id Poszt azonosító.
     */
    public function render_column( $column, $post_id ): void {
        if ( self::COL !== $column ) {
            return;
        }

        $uid = (int) get_post_meta( $post_id, '_edit_last', true );
        if ( ! $uid ) {
            $uid = (int) get_post_field( 'post_author', $post_id );
        }
        $name = $uid ? get_the_author_meta( 'display_name', $uid ) : __( 'Unknown', 'qaiyo-admin-booster' );

        $ago      = '';
        $modified = get_post_field( 'post_modified_gmt', $post_id );
        if ( $modified && '0000-00-00 00:00:00' !== $modified ) {
            $ts = (int) mysql2date( 'U', $modified );
            if ( $ts ) {
                $ago = sprintf(
                    /* translators: %s: human-readable time difference, e.g. "3 days". */
                    __( '%s ago', 'qaiyo-admin-booster' ),
                    human_time_diff( $ts, time() )
                );
            }
        }

        echo '<span class="qwab-last-editor">';
        echo '<span class="qwab-last-editor__name">' . esc_html( $name ) . '</span>';
        if ( $ago ) {
            echo '<span class="qwab-last-editor__ago">' . esc_html( $ago ) . '</span>';
        }
        echo '</span>';
    }
}

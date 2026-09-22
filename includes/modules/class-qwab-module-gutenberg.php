<?php
/**
 * Modul: Gutenberg UX javítás.
 *
 * A blokk-szerkesztő betöltésekor alapból kinyitja a bal oldali
 * blokkillesztő panelt (inserter), és opcionálisan kikapcsolja a
 * teljes képernyős módot, hogy a WP admin menü mindig látszódjon.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Gutenberg {

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
    }

    /**
     * Beágyazott szerkesztő-script a panel/teljesképernyő viselkedéshez.
     */
    public function enqueue(): void {
        $handle = 'qwab-gutenberg';
        $path   = QWAB_PATH . 'assets/js/qwab-gutenberg.js';
        $url    = QWAB_URL . 'assets/js/qwab-gutenberg.js';

        if ( ! file_exists( $path ) ) {
            return;
        }

        // A blokkillesztőt csak a Bejegyzések szerkesztőjében nyitjuk ki
        // alapból — oldalaknál és egyéb tartalomtípusoknál nem.
        $screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $post_type     = ( $screen && isset( $screen->post_type ) ) ? $screen->post_type : '';
        $open_inserter = (bool) Qwab_Settings::get( 'gutenberg_open_inserter', 1 ) && ( 'post' === $post_type );

        wp_enqueue_script(
            $handle,
            $url,
            array( 'wp-data', 'wp-dom-ready' ),
            QWAB_VERSION . '.' . filemtime( $path ),
            true
        );

        wp_localize_script(
            $handle,
            'qwabGutenberg',
            array(
                'openInserter'      => $open_inserter,
                'disableFullscreen' => (bool) Qwab_Settings::get( 'gutenberg_disable_fullscreen', 1 ),
            )
        );
    }
}

<?php
/**
 * Modul: Tapadó táblázat-fejlécek.
 *
 * A wp-admin lista-táblázatok (Bejegyzések, Oldalak, Média, Felhasználók,
 * Bővítmények, Címkék) fejléc-sora görgetéskor a képernyő tetejéhez tapad,
 * így hosszú listáknál is látszik, melyik oszlop micsoda. Tisztán CSS.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Sticky_Headers {

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Stílus betöltése a lista-képernyőkön.
     *
     * @param string $hook Aktuális admin oldal hook.
     */
    public function enqueue( $hook ): void {
        $screens = array( 'edit.php', 'edit-tags.php', 'users.php', 'plugins.php', 'upload.php' );
        if ( ! in_array( $hook, $screens, true ) ) {
            return;
        }
        $css = QWAB_PATH . 'assets/css/qwab-sticky.css';
        wp_enqueue_style(
            'qwab-sticky',
            QWAB_URL . 'assets/css/qwab-sticky.css',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : QWAB_VERSION )
        );
    }
}

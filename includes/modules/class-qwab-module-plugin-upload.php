<?php
/**
 * Modul: 1 kattintásos plugin feltöltés.
 *
 * A „Bővítmény hozzáadása" képernyőn (plugin-install.php) a WordPress a
 * feltöltő dobozt egy összecsukott panelben rejti, amit a „Bővítmény
 * feltöltése" gombbal lehet legördíteni (a `.wrap` elemre kerülő
 * `show-upload-view` osztály mutatja meg). Ez a modul ezt a panelt
 * automatikusan kinyitja, így a feltöltő doboz egyből látszik —
 * a WordPress bővítmény-könyvtár (Kiemelt, Népszerű, Keresés fülek)
 * pedig alatta VÁLTOZATLANUL megmarad.
 *
 * Nincs átirányítás és nincs tab-váltás: a `?tab=upload` képernyő (ami
 * elrejtené a könyvtárat) nem jön közbe.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Plugin_Upload {

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script' ) );
    }

    /**
     * A plugin-install.php képernyőn betölti a kis JS-t, ami kinyitja
     * a feltöltő panelt.
     *
     * @param string $hook Az aktuális admin oldal hook neve.
     */
    public function enqueue_script( $hook ): void {
        if ( 'plugin-install.php' !== $hook ) {
            return;
        }
        if ( ! current_user_can( 'upload_plugins' ) ) {
            return;
        }

        wp_enqueue_script(
            'qwab-plugin-upload',
            QWAB_URL . 'assets/js/qwab-plugin-upload.js',
            array( 'jquery' ),
            QWAB_VERSION,
            true
        );
    }
}

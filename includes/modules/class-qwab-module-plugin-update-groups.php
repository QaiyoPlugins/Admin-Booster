<?php
/**
 * Modul: Frissítendő bővítmények külön táblázatban.
 *
 * A Bővítmények (plugins.php) képernyőn a frissítésre váró bővítményeket
 * kiemeli a fő listából egy TELJESEN KÜLÖN táblázatba, „Frissítések" címsor
 * alatt; alatta második címsorral következik az összes többi bővítmény a
 * megszokott (ábécé szerinti) sorrendben.
 *
 * MIÉRT KLIENSOLDALI (JS) MEGOLDÁS, NEM SZERVEROLDALI ÁTRENDEZÉS:
 * a `WP_Plugins_List_Table::prepare_items()` a saját `plugins_list` szűrője
 * (WP 6.3+) UTÁN mindig lefuttat egy `uasort()`-ot név szerint
 * (`_order_callback()`), ami bármilyen egyedi sorrendet felülírna. Ráadásul a
 * core egyetlen táblázatot renderel — kettéosztani csak a kész DOM-on lehet.
 * Ugyanaz a minta, mint az admin-notice tálcánál (`Qwab_Module_Admin_Notices`):
 * a WP saját HTML-kimenetét rendezzük át, nem a belső adatszerkezetét.
 *
 * A core minden frissítendő bővítmény sorát `update` CSS-osztállyal látja el
 * (`WP_Plugins_List_Table::single_row()`), és a sor közvetlen testvéreként
 * (`after_plugin_row_{$plugin_file}` hook, lásd `wp_plugin_update_row()`)
 * egy `tr.plugin-update-tr` értesítő-sort szúr be — ezt a párt együtt, egymás
 * testvéreként mozgatjuk, mert a core `updates.js` a
 * `$plugin.siblings( '[data-plugin="…"]' )` alapján találja meg.
 *
 * Az új táblázat a `#bulk-action-form`-on belül marad (különben a tömeges
 * műveletek nem küldenék be a kipipált sorokat), és szándékosan nem kap
 * `<thead>`-et — a részletes indoklást lásd az `assets/js/` fájl fejlécében.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Plugin_Update_Groups {

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
     * Asset-ek betöltése kizárólag a Bővítmények listaoldalon.
     *
     * @param string $hook Aktuális admin oldal hook.
     */
    public function enqueue( $hook ): void {
        if ( 'plugins.php' !== $hook || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        $js = QWAB_PATH . 'assets/js/qwab-plugin-update-groups.js';
        wp_enqueue_script(
            'qwab-plugin-update-groups',
            QWAB_URL . 'assets/js/qwab-plugin-update-groups.js',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $js ) ? filemtime( $js ) : QWAB_VERSION ),
            true
        );

        $css = QWAB_PATH . 'assets/css/qwab-plugin-update-groups.css';
        wp_enqueue_style(
            'qwab-plugin-update-groups',
            QWAB_URL . 'assets/css/qwab-plugin-update-groups.css',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : QWAB_VERSION )
        );

        wp_localize_script(
            'qwab-plugin-update-groups',
            'qwabUpdateGroups',
            array(
                'updatesHeading' => __( 'Updates', 'qaiyo-admin-booster' ),
                'restHeading'    => __( 'All plugins', 'qaiyo-admin-booster' ),
            )
        );
    }
}

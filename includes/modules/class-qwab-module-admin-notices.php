<?php
/**
 * Modul: Admin értesítések rendezése.
 *
 * A wp-admin felső részén halmozódó admin értesítéseket (plugin-promók,
 * nag-ek, frissítési figyelmeztetések) egy összecsukható „tálcába" gyűjti
 * egy kis harang-számláló alá, így nem tolják lejjebb a tényleges tartalmat.
 * Az értesítéseket NEM törli — egy kattintással kinyithatók, a beépített
 * „elvetés" gombjuk továbbra is működik.
 *
 * Csak a #wpbody-content KÖZVETLEN gyermek értesítéseit gyűjti be (ezek a
 * globális, „stacked" notice-ok), így a kontextuális, .wrap-on belüli
 * üzenetek (pl. a saját beállítás-mentés visszajelzése) érintetlenek.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Admin_Notices {

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
     * Asset-ek betöltése (a saját beállító oldalt kihagyva).
     *
     * @param string $hook Aktuális admin oldal hook.
     */
    public function enqueue( $hook ): void {
        if ( 'toplevel_page_' . Qwab_Admin::MENU_SLUG === $hook ) {
            return;
        }

        $css = QWAB_PATH . 'assets/css/qwab-notices.css';
        $js  = QWAB_PATH . 'assets/js/qwab-notices.js';

        wp_enqueue_style(
            'qwab-notices',
            QWAB_URL . 'assets/css/qwab-notices.css',
            array( 'dashicons' ),
            QWAB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : QWAB_VERSION )
        );
        wp_enqueue_script(
            'qwab-notices',
            QWAB_URL . 'assets/js/qwab-notices.js',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $js ) ? filemtime( $js ) : QWAB_VERSION ),
            true
        );
        wp_localize_script(
            'qwab-notices',
            'qwabNotices',
            array(
                'i18n' => array(
                    /* translators: %d: number of admin notices. */
                    'count' => __( '%d notices', 'qaiyo-admin-booster' ),
                    'one'   => __( '1 notice', 'qaiyo-admin-booster' ),
                    'label' => __( 'Admin notices', 'qaiyo-admin-booster' ),
                ),
            )
        );
    }
}

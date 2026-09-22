<?php
/**
 * Modul: Vezérlőpult takarítás.
 *
 * A kiválasztott alapértelmezett dashboard widgeteket (WordPress hírek,
 * Gyors vázlat, Tevékenység, Egy pillanat alatt, Állapot, Üdvözlő panel)
 * eltávolítja a Vezérlőpultról, hogy tisztább kezdőképernyő fogadjon.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Dashboard_Cleanup {

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'wp_dashboard_setup', array( $this, 'remove_widgets' ), 9999 );
        add_action( 'admin_init', array( $this, 'maybe_remove_welcome' ) );
    }

    /**
     * Az ismert core dashboard widgetek listája (id => emberi név).
     *
     * @return array<string,string>
     */
    public static function known_widgets(): array {
        return array(
            'dashboard_primary'     => __( 'WordPress Events and News', 'qaiyo-admin-booster' ),
            'dashboard_quick_press' => __( 'Quick Draft', 'qaiyo-admin-booster' ),
            'dashboard_activity'    => __( 'Activity', 'qaiyo-admin-booster' ),
            'dashboard_right_now'   => __( 'At a Glance', 'qaiyo-admin-booster' ),
            'dashboard_site_health' => __( 'Site Health Status', 'qaiyo-admin-booster' ),
            'welcome_panel'         => __( 'Welcome panel', 'qaiyo-admin-booster' ),
        );
    }

    /**
     * A kiválasztott meta-box widgetek eltávolítása minden oszlopból.
     */
    public function remove_widgets(): void {
        $ids      = (array) Qwab_Settings::get( 'dashboard_cleanup_widgets', array() );
        $contexts = array( 'normal', 'side', 'column3', 'column4' );

        foreach ( $ids as $id ) {
            if ( 'welcome_panel' === $id ) {
                continue; // Külön kezeljük (nem meta-box).
            }
            foreach ( $contexts as $ctx ) {
                remove_meta_box( $id, 'dashboard', $ctx );
            }
        }
    }

    /**
     * Az üdvözlő panel külön kezelése (nem meta-box).
     */
    public function maybe_remove_welcome(): void {
        $ids = (array) Qwab_Settings::get( 'dashboard_cleanup_widgets', array() );
        if ( in_array( 'welcome_panel', $ids, true ) ) {
            remove_action( 'welcome_panel', 'wp_welcome_panel' );
        }
    }
}

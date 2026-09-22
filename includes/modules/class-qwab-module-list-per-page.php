<?php
/**
 * Modul: Listanézet alapértelmezett elemszám növelése.
 *
 * A WP listanézetek (edit.php) alapból 20 elemet mutatnak. Ez a modul a
 * beállított értékre emeli az alapot — DE csak akkor, ha a felhasználó még
 * nem állított be saját „Megjelenítendő elemek" képernyő-opciót, így a
 * kézi választást soha nem írja felül.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_List_Per_Page {

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'pre_get_posts', array( $this, 'maybe_set_per_page' ) );
    }

    /**
     * Listanézet elemszám felülírása, ha nincs felhasználói képernyő-opció.
     *
     * @param WP_Query $query Lekérdezés.
     */
    public function maybe_set_per_page( $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit' !== $screen->base ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( is_array( $post_type ) || '' === $post_type ) {
            $post_type = $screen->post_type ? $screen->post_type : 'post';
        }

        // Tiszteljük a felhasználó saját képernyő-opcióját.
        $option_name = 'edit_' . $post_type . '_per_page';
        $user_choice = get_user_option( $option_name );
        if ( false !== $user_choice && '' !== $user_choice && null !== $user_choice ) {
            return;
        }

        $count = (int) Qwab_Settings::get( 'list_per_page_count', 50 );
        if ( $count > 0 ) {
            $query->set( 'posts_per_page', $count );
        }
    }
}

<?php
/**
 * Modul: Gyors admin navigáció.
 *
 * Az admin sávba egy „Gyors hozzáadás" csoportot tesz a kiválasztott
 * tartalomtípusok új-elem linkjeivel (Új oldal / Új bejegyzés / Új termék
 * / egyedi poszttípusok), így egy kattintással lehet új tartalmat kezdeni
 * bárhonnan a wp-adminból és a frontend admin sávból is.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Quick_Add {

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 90 );
    }

    /**
     * Admin sáv csomópontok.
     *
     * @param WP_Admin_Bar $admin_bar Admin sáv objektum.
     */
    public function add_nodes( $admin_bar ): void {
        $types = (array) Qwab_Settings::get( 'quick_add_post_types', array() );
        if ( empty( $types ) ) {
            return;
        }

        $addable = array();
        foreach ( $types as $type ) {
            $pt = get_post_type_object( $type );
            if ( ! $pt || ! current_user_can( $pt->cap->create_posts ) ) {
                continue;
            }
            $addable[ $type ] = $pt;
        }

        if ( empty( $addable ) ) {
            return;
        }

        $admin_bar->add_node(
            array(
                'id'    => 'qwab-quick-add',
                'title' => '<span class="ab-icon dashicons dashicons-plus-alt2" style="top:2px;"></span><span class="ab-label">' . esc_html__( 'Quick Add', 'qaiyo-admin-booster' ) . '</span>',
                'href'  => false,
                'meta'  => array( 'title' => esc_attr__( 'Qaiyo Admin Booster — Quick Add', 'qaiyo-admin-booster' ) ),
            )
        );

        foreach ( $addable as $type => $pt ) {
            $admin_bar->add_node(
                array(
                    'parent' => 'qwab-quick-add',
                    'id'     => 'qwab-quick-add-' . $type,
                    /* translators: %s: post type singular name (e.g. Page, Post, Product). */
                    'title'  => esc_html( sprintf( __( 'New %s', 'qaiyo-admin-booster' ), $pt->labels->singular_name ) ),
                    'href'   => esc_url( admin_url( 'post-new.php?post_type=' . $type ) ),
                )
            );
        }
    }
}

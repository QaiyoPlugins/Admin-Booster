<?php
/**
 * Modul: Gyors státuszváltás.
 *
 * A bejegyzés/oldal listanézetbe egy sor-műveletet tesz, amivel a szerkesztő
 * megnyitása nélkül lehet publikálni egy piszkozatot, vagy egy publikált
 * elemet visszaállítani piszkozatba — egy kattintással, a listából.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Quick_Status {

    const ACTION = 'qwab_quick_status';

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
        add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
        add_action( 'admin_action_' . self::ACTION, array( $this, 'handle' ) );
    }

    /**
     * Státuszváltó link a sor-műveletek közé.
     *
     * @param array   $actions Műveletek.
     * @param WP_Post $post    Poszt.
     * @return array
     */
    public function row_action( $actions, $post ) {
        if ( ! is_object( $post ) ) {
            return $actions;
        }
        $pt = get_post_type_object( $post->post_type );
        if ( ! $pt || ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }

        if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'pending' ), true ) ) {
            return $actions;
        }

        if ( 'publish' === $post->post_status ) {
            $to    = 'draft';
            $label = __( 'Switch to draft', 'qaiyo-admin-booster' );
        } else {
            if ( ! current_user_can( $pt->cap->publish_posts ) ) {
                return $actions;
            }
            $to    = 'publish';
            $label = __( 'Publish now', 'qaiyo-admin-booster' );
        }

        $url = wp_nonce_url(
            admin_url( 'admin.php?action=' . self::ACTION . '&post=' . $post->ID . '&to=' . $to ),
            self::ACTION . '_' . $post->ID
        );
        $actions['qwab_quick_status'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url( $url ),
            esc_html( $label )
        );
        return $actions;
    }

    /**
     * Státuszváltás végrehajtása + visszairányítás a listára.
     */
    public function handle(): void {
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        $to      = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';

        if ( ! $post_id || ! in_array( $to, array( 'publish', 'draft' ), true ) ) {
            wp_die( esc_html__( 'Invalid request.', 'qaiyo-admin-booster' ) );
        }
        check_admin_referer( self::ACTION . '_' . $post_id );

        $post = get_post( $post_id );
        $pt   = $post ? get_post_type_object( $post->post_type ) : null;

        if ( ! $post || ! $pt || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You are not allowed to edit this item.', 'qaiyo-admin-booster' ) );
        }
        if ( 'publish' === $to && ! current_user_can( $pt->cap->publish_posts ) ) {
            wp_die( esc_html__( 'You are not allowed to publish this item.', 'qaiyo-admin-booster' ) );
        }

        wp_update_post(
            array(
                'ID'          => $post_id,
                'post_status' => $to,
            )
        );

        $back = wp_get_referer();
        if ( ! $back ) {
            $back = admin_url( 'edit.php?post_type=' . $post->post_type );
        }
        wp_safe_redirect( $back );
        exit;
    }
}

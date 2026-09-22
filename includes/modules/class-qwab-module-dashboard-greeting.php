<?php
/**
 * Modul: Üdvözlés a Vezérlőpulton.
 *
 * A plugin admin oldalán megjelenő személyre szabott üdvözlést a fő
 * WordPress Vezérlőpult tetejére is kiteszi (a „Vezérlőpult" cím fölé),
 * hogy a napi belépéskor is barátságos legyen a felület.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Dashboard_Greeting {

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'in_admin_header', array( $this, 'render' ) );
    }

    /**
     * Stílus betöltése kizárólag a Vezérlőpulton.
     *
     * @param string $hook Aktuális admin oldal hook.
     */
    public function enqueue( $hook ): void {
        if ( 'index.php' !== $hook ) {
            return;
        }
        $css = QWAB_PATH . 'assets/css/qwab-dash-greeting.css';
        wp_enqueue_style(
            'qwab-dash-greeting',
            QWAB_URL . 'assets/css/qwab-dash-greeting.css',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : QWAB_VERSION )
        );
    }

    /**
     * Az üdvözlés kirajzolása a Vezérlőpult tetejére.
     */
    public function render(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'dashboard' !== $screen->id ) {
            return;
        }

        $user = wp_get_current_user();
        $name = $user->first_name ? $user->first_name : $user->display_name;
        if ( '' === $name ) {
            $name = $user->user_login;
        }
        ?>
        <div class="qwab-dash-greeting-wrap">
            <p class="qwab-greeting">
                <?php
                /* translators: %s: current user's first name. */
                printf( esc_html__( 'Hi, %s!', 'qaiyo-admin-booster' ), esc_html( $name ) );
                ?>
                <span class="qwab-wave" aria-hidden="true">&#128075;</span>
            </p>
        </div>
        <?php
    }
}

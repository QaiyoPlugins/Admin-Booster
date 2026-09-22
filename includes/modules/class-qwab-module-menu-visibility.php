<?php
/**
 * Modul: Menüelem láthatósága felbontás szerint.
 *
 * A klasszikus menüszerkesztőben (Megjelenés → Menük) minden menüelemhez
 * hozzáad egy „Megjelenés” választót: Mindig / Csak mobilon / Csak asztali
 * gépen. Tipikus eset: a fejlécben lévő „Ajánlatkérés” gomb mobilon nem fér
 * el, ezért a hamburgermenübe kell — de csak oda, asztali nézetben a gomb
 * amúgy is látszik.
 *
 * A rejtés kizárólag CSS-sel történik (a menüelem class-listájára kerülő
 * `qwab-nav-mobile-only`/`qwab-nav-desktop-only` osztály + egy `wp_head`-be
 * írt media query), sosem a HTML kihagyásával — így a menü szerkezete minden
 * nézetben ugyanaz marad, csak a megjelenés vált.
 *
 * FONTOS KORLÁT: ez a `nav_menu_css_class` szűrőn megy, ami csak a klasszikus
 * `wp_nav_menu()`-vel kirajzolt menüket érinti. A block-alapú Navigáció
 * blokk saját maga rajzol, ezt a szűrőt nem hívja meg — ugyanaz a korlát,
 * mint a `Qwab_Module_Menu_Manager`-nél.
 *
 * A törésvonal Free-ben fix 768px; a `qwab_nav_visibility_breakpoint` szűrő
 * a Pro-nak ad helyet egyedi érték beállítására (lásd HOOKS.md).
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Menu_Visibility {

    /** Post meta kulcs a menüelemen: '', 'mobile' vagy 'desktop'. */
    const META_KEY = '_qwab_nav_visibility';

    /** A mentéskor beküldött mező neve (item ID szerint indexelt tömb). */
    const FIELD_NAME = 'qwab-nav-visibility';

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_field' ), 10, 4 );
        add_action( 'wp_update_nav_menu_item', array( $this, 'save_field' ), 10, 2 );
        add_filter( 'nav_menu_css_class', array( $this, 'add_css_class' ), 10, 2 );
        add_action( 'wp_head', array( $this, 'print_css' ) );
    }

    /**
     * A törésvonal (px). Free: fix 768. A Pro ezt a szűrőt írja felül egy
     * felhasználó által megadott értékkel.
     *
     * @return int
     */
    private static function breakpoint(): int {
        $bp = (int) apply_filters( 'qwab_nav_visibility_breakpoint', 768 );
        return ( $bp >= 320 && $bp <= 2000 ) ? $bp : 768;
    }

    /**
     * A mentett érték a menüelemen, whitelistelve.
     *
     * @param int $item_id Menüelem post ID.
     * @return string '', 'mobile' vagy 'desktop'.
     */
    private static function value( int $item_id ): string {
        $value = get_post_meta( $item_id, self::META_KEY, true );
        return in_array( $value, array( 'mobile', 'desktop' ), true ) ? $value : '';
    }

    /* ---------------------------------------------------------------------
     * Menüszerkesztő mező
     * ------------------------------------------------------------------- */

    /**
     * Egyedi mező kirajzolása minden menüelemnél a Megjelenés → Menük
     * képernyőn, a core CSS Classes mezőjével azonos stílusban.
     *
     * @param int    $item_id Menüelem post ID.
     * @param object $item    A menüelem (nem használt).
     * @param int    $depth   Mélység (nem használt).
     * @param object $args    Menü-argumentumok (nem használt).
     */
    public function render_field( $item_id, $item, $depth, $args ): void {
        $current = self::value( $item_id );
        $bp      = self::breakpoint();
        ?>
        <p class="field-qwab-nav-visibility description description-wide">
            <label for="edit-menu-item-qwab-nav-visibility-<?php echo esc_attr( $item_id ); ?>">
                <?php esc_html_e( 'Visibility', 'qaiyo-admin-booster' ); ?><br />
                <select id="edit-menu-item-qwab-nav-visibility-<?php echo esc_attr( $item_id ); ?>" class="widefat" name="<?php echo esc_attr( self::FIELD_NAME ); ?>[<?php echo esc_attr( $item_id ); ?>]">
                    <option value="" <?php selected( '', $current ); ?>><?php esc_html_e( 'Always', 'qaiyo-admin-booster' ); ?></option>
                    <option value="mobile" <?php selected( 'mobile', $current ); ?>>
                        <?php
                        printf(
                            /* translators: %d: breakpoint in pixels. */
                            esc_html__( 'Mobile only (%d px or narrower)', 'qaiyo-admin-booster' ),
                            (int) $bp
                        );
                        ?>
                    </option>
                    <option value="desktop" <?php selected( 'desktop', $current ); ?>>
                        <?php
                        printf(
                            /* translators: %d: breakpoint in pixels. */
                            esc_html__( 'Desktop only (wider than %d px)', 'qaiyo-admin-booster' ),
                            (int) $bp
                        );
                        ?>
                    </option>
                </select>
            </label>
        </p>
        <?php
    }

    /**
     * A mező mentése. Külön nonce-ellenőrzés nélkül: a hívó `wp_update_nav_menu_item()`-et
     * a wp-admin/nav-menus.php csak az `update-nav_menu` nonce sikeres
     * ellenőrzése UTÁN hívja meg minden menüelemre — ugyanígy jár el a core
     * saját `menu-item-classes` mezője is.
     *
     * @param int $menu_id         Menü post ID (nem használt).
     * @param int $menu_item_db_id Menüelem post ID.
     */
    public function save_field( $menu_id, $menu_item_db_id ): void {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core már ellenőrizte az 'update-nav_menu' nonce-t a nav-menus.php-ban, mielőtt ez a hook lefut minden menüelemre (ugyanaz a minta, mint a core saját menu-item-classes mezőjénél).
        $posted = isset( $_POST[ self::FIELD_NAME ][ $menu_item_db_id ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD_NAME ][ $menu_item_db_id ] ) ) : '';
        $value  = in_array( $posted, array( 'mobile', 'desktop' ), true ) ? $posted : '';

        if ( '' === $value ) {
            delete_post_meta( $menu_item_db_id, self::META_KEY );
        } else {
            update_post_meta( $menu_item_db_id, self::META_KEY, $value );
        }
    }

    /* ---------------------------------------------------------------------
     * Frontend: class + CSS
     * ------------------------------------------------------------------- */

    /**
     * Osztály hozzáadása a menüelemhez a beállított láthatóság szerint.
     *
     * @param array  $classes A menüelem meglévő class-listája.
     * @param object $item    A menüelem.
     * @return array
     */
    public function add_css_class( $classes, $item ) {
        $value = self::value( $item->ID );
        if ( 'mobile' === $value ) {
            $classes[] = 'qwab-nav-mobile-only';
        } elseif ( 'desktop' === $value ) {
            $classes[] = 'qwab-nav-desktop-only';
        }
        return $classes;
    }

    /**
     * A rejtést végző media query kiírása. Szándékosan csak TILTÓ szabályok
     * (sosem „display: valami-visszaállítás”), hogy ne kelljen kitalálni a
     * téma saját display-értékét a menüelemeken.
     */
    public function print_css(): void {
        $bp = self::breakpoint();
        ?>
        <style id="qwab-nav-visibility">
            @media (min-width: <?php echo (int) ( $bp + 1 ); ?>px) { .qwab-nav-mobile-only { display: none !important; } }
            @media (max-width: <?php echo (int) $bp; ?>px) { .qwab-nav-desktop-only { display: none !important; } }
        </style>
        <?php
    }
}

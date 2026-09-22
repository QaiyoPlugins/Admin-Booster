<?php
/**
 * Modul: Page Collections.
 *
 * Az oldalakat (és bejegyzéseket) vizuálisan csoportokba — kollekciókba —
 * rendezi. Néhány egyértelmű csoportot a rendszer automatikusan felismer
 * (Főoldal, Jogi, WooCommerce), a többit a felhasználó hozza létre és
 * rendeli hozzá kézzel. A hozzárendelés a szerkesztő meta dobozából és a
 * lista nézet oszlopából/szűrőjéből történik.
 *
 * Tárolás:
 *  - Egyedi kollekciók: `qwab_collections` option ( id => [name,color,icon] ).
 *  - Hozzárendelés: `_qwab_collection` post meta (egy kollekció-id / poszt).
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Page_Collections {

    const META_KEY        = '_qwab_collection';
    const COLLECTIONS_OPT = 'qwab_collections';
    const NONCE           = 'qwab_collection_meta';

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'init', array( $this, 'register_meta' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );

        foreach ( $this->post_types() as $type ) {
            add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
            add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
        }

        add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown' ) );
        add_action( 'pre_get_posts', array( $this, 'apply_filter' ) );
    }

    /**
     * Engedélyezett poszttípusok.
     *
     * @return array
     */
    private function post_types(): array {
        $types = (array) Qwab_Settings::get( 'collections_post_types', array( 'page', 'post' ) );
        return array_values( array_filter( $types, 'post_type_exists' ) );
    }

    /* ---------------------------------------------------------------------
     * Kollekció-tár (static API a többi osztálynak is)
     * ------------------------------------------------------------------- */

    /**
     * Beépített, automatikusan felismert kollekciók.
     *
     * @return array<string,array{name:string,color:string,icon:string,auto:bool}>
     */
    public static function reserved(): array {
        return array(
            'main' => array(
                'name'  => __( 'Homepage', 'qaiyo-admin-booster' ),
                'color' => '#6c5ce7',
                'icon'  => 'dashicons-admin-home',
                'auto'  => true,
            ),
            'legal' => array(
                'name'  => __( 'Legal', 'qaiyo-admin-booster' ),
                'color' => '#64748b',
                'icon'  => 'dashicons-text-page',
                'auto'  => true,
            ),
            'woocommerce' => array(
                'name'  => __( 'WooCommerce', 'qaiyo-admin-booster' ),
                'color' => '#7f54b3',
                'icon'  => 'dashicons-cart',
                'auto'  => true,
            ),
        );
    }

    /**
     * Felhasználói kollekciók a `qwab_collections` option-ból.
     *
     * @return array<string,array{name:string,color:string,icon:string,auto:bool}>
     */
    public static function custom(): array {
        $stored = get_option( self::COLLECTIONS_OPT, array() );
        if ( ! is_array( $stored ) ) {
            return array();
        }
        $out = array();
        foreach ( $stored as $id => $data ) {
            $id = sanitize_key( $id );
            if ( '' === $id || isset( self::reserved()[ $id ] ) ) {
                continue;
            }
            $out[ $id ] = array(
                'name'  => isset( $data['name'] ) ? (string) $data['name'] : $id,
                'color' => isset( $data['color'] ) ? (string) $data['color'] : '#6c5ce7',
                'icon'  => isset( $data['icon'] ) ? (string) $data['icon'] : 'dashicons-category',
                'auto'  => false,
            );
        }
        return $out;
    }

    /**
     * Összes kollekció (reserved + custom).
     *
     * @return array<string,array>
     */
    public static function all(): array {
        return array_merge( self::reserved(), self::custom() );
    }

    /**
     * Egy poszt tényleges kollekciója: kézi hozzárendelés, vagy auto-felismerés.
     *
     * @param int|WP_Post $post Poszt.
     * @return array|null ['id','name','color','icon','source'] vagy null.
     */
    public static function for_post( $post ): ?array {
        $post = get_post( $post );
        if ( ! $post ) {
            return null;
        }

        $all    = self::all();
        $manual = get_post_meta( $post->ID, self::META_KEY, true );
        if ( $manual && isset( $all[ $manual ] ) ) {
            $c = $all[ $manual ];
            return array(
                'id'     => $manual,
                'name'   => $c['name'],
                'color'  => $c['color'],
                'icon'   => $c['icon'],
                'source' => 'manual',
            );
        }

        $auto = self::auto_detect( $post );
        if ( $auto && isset( $all[ $auto ] ) ) {
            $c = $all[ $auto ];
            return array(
                'id'     => $auto,
                'name'   => $c['name'],
                'color'  => $c['color'],
                'icon'   => $c['icon'],
                'source' => 'auto',
            );
        }

        return null;
    }

    /**
     * Automatikus kollekció-felismerés egyértelmű esetekre.
     *
     * @param WP_Post $post Poszt.
     * @return string|null Kollekció id.
     */
    public static function auto_detect( WP_Post $post ): ?string {
        $id      = (int) $post->ID;
        $special = self::special_page_ids();

        // Főoldal / bejegyzés-oldal.
        if ( isset( $special['main'][ $id ] ) ) {
            return 'main';
        }

        // WooCommerce rendszeroldalak.
        if ( isset( $special['woocommerce'][ $id ] ) ) {
            return 'woocommerce';
        }

        // Jogi tartalmak slug/cím alapján (több nyelven).
        $haystack = strtolower( $post->post_name . ' ' . $post->post_title );
        $legal    = array(
            'aszf', 'altalanos-szerzodesi', 'adatvedelm', 'adatkezel', 'jogi',
            'privacy', 'terms', 'cookie', 'gdpr', 'disclaimer', 'imprint',
            'impressum', 'datenschutz', 'agb', 'mentions-legales', 'aviso-legal',
            'politique-de-confidentialite', 'condiciones',
        );
        foreach ( $legal as $needle ) {
            if ( false !== strpos( $haystack, $needle ) ) {
                return 'legal';
            }
        }

        return null;
    }

    /**
     * A „speciális" (fő / WooCommerce rendszer) oldal-ID-k halmaza, kérésenként
     * EGYSZER kiszámolva. Így a lista-táblázat minden sorára nem futnak újra a
     * `wc_get_page_id()` szűrők és az opció-olvasások.
     *
     * @return array<string,array<int,bool>> ['main'=>[id=>true], 'woocommerce'=>[id=>true]]
     */
    private static function special_page_ids(): array {
        static $map = null;
        if ( null !== $map ) {
            return $map;
        }

        $map = array(
            'main'        => array(),
            'woocommerce' => array(),
        );

        foreach ( array( 'page_on_front', 'page_for_posts' ) as $opt ) {
            $pid = (int) get_option( $opt );
            if ( $pid > 0 ) {
                $map['main'][ $pid ] = true;
            }
        }

        if ( function_exists( 'wc_get_page_id' ) ) {
            foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $wc_page ) {
                $pid = (int) wc_get_page_id( $wc_page );
                if ( $pid > 0 ) {
                    $map['woocommerce'][ $pid ] = true;
                }
            }
        }

        return $map;
    }

    /* ---------------------------------------------------------------------
     * Meta regisztráció + meta box
     * ------------------------------------------------------------------- */

    public function register_meta() {
        foreach ( $this->post_types() as $type ) {
            register_post_meta(
                $type,
                self::META_KEY,
                array(
                    'type'              => 'string',
                    'single'            => true,
                    'show_in_rest'      => false,
                    'sanitize_callback' => 'sanitize_key',
                    'auth_callback'     => function () {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
        }
    }

    /**
     * Meta box hozzáadása a szerkesztőhöz.
     *
     * @param string $post_type Aktuális poszttípus.
     */
    public function add_meta_box( $post_type ): void {
        if ( ! in_array( $post_type, $this->post_types(), true ) ) {
            return;
        }
        add_meta_box(
            'qwab-collection',
            __( 'Collection', 'qaiyo-admin-booster' ),
            array( $this, 'render_meta_box' ),
            $post_type,
            'side',
            'default'
        );
    }

    /**
     * Meta box tartalma.
     *
     * @param WP_Post $post Poszt.
     */
    public function render_meta_box( $post ): void {
        wp_nonce_field( self::NONCE, self::NONCE . '_field' );
        $current = get_post_meta( $post->ID, self::META_KEY, true );
        $auto    = self::auto_detect( $post );
        $all     = self::all();
        ?>
        <p>
            <label for="qwab-collection-select" class="screen-reader-text"><?php esc_html_e( 'Collection', 'qaiyo-admin-booster' ); ?></label>
            <select name="qwab_collection" id="qwab-collection-select" style="width:100%;max-width:100%;box-sizing:border-box;">
                <option value=""><?php esc_html_e( '— Auto / none —', 'qaiyo-admin-booster' ); ?></option>
                <?php foreach ( $all as $id => $c ) : ?>
                    <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, $id ); ?>>
                        <?php echo esc_html( $c['name'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php if ( ! $current && $auto && isset( $all[ $auto ] ) ) : ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: auto-detected collection name. */
                    esc_html__( 'Auto-detected as: %s. Pick a collection above to override.', 'qaiyo-admin-booster' ),
                    '<strong>' . esc_html( $all[ $auto ]['name'] ) . '</strong>'
                );
                ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Meta box mentése.
     *
     * @param int     $post_id Poszt id.
     * @param WP_Post $post    Poszt.
     */
    public function save_meta( $post_id, $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! in_array( $post->post_type, $this->post_types(), true ) ) {
            return;
        }
        $nonce = isset( $_POST[ self::NONCE . '_field' ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_field' ] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $value = isset( $_POST['qwab_collection'] ) ? sanitize_key( wp_unslash( $_POST['qwab_collection'] ) ) : '';
        $all   = self::all();
        if ( '' !== $value && isset( $all[ $value ] ) ) {
            update_post_meta( $post_id, self::META_KEY, $value );
        } else {
            delete_post_meta( $post_id, self::META_KEY );
        }
    }

    /* ---------------------------------------------------------------------
     * Lista oszlop + szűrő
     * ------------------------------------------------------------------- */

    /**
     * Oszlop hozzáadása.
     *
     * @param array $columns Oszlopok.
     * @return array
     */
    public function add_column( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['qwab_collection'] = __( 'Collection', 'qaiyo-admin-booster' );
            }
        }
        if ( ! isset( $new['qwab_collection'] ) ) {
            $new['qwab_collection'] = __( 'Collection', 'qaiyo-admin-booster' );
        }
        return $new;
    }

    /**
     * Oszlop tartalma.
     *
     * @param string $column  Oszlop kulcs.
     * @param int    $post_id Poszt id.
     */
    public function render_column( $column, $post_id ): void {
        if ( 'qwab_collection' !== $column ) {
            return;
        }
        $c = self::for_post( $post_id );
        if ( ! $c ) {
            echo '<span class="qwab-col-empty" aria-hidden="true">—</span>';
            return;
        }
        $auto = 'auto' === $c['source'];
        printf(
            '<span class="qwab-collection-badge%1$s" style="--qwab-c:%2$s"><span class="dashicons %3$s"></span>%4$s%5$s</span>',
            $auto ? ' is-auto' : '',
            esc_attr( $c['color'] ),
            esc_attr( $c['icon'] ),
            esc_html( $c['name'] ),
            $auto ? '<span class="qwab-collection-auto" title="' . esc_attr__( 'Auto-detected', 'qaiyo-admin-booster' ) . '">' . esc_html__( 'auto', 'qaiyo-admin-booster' ) . '</span>' : ''
        );
    }

    /**
     * Szűrő legördülő a lista nézet tetején.
     *
     * @param string $post_type Aktuális poszttípus.
     */
    public function filter_dropdown( $post_type ): void {
        if ( ! in_array( $post_type, $this->post_types(), true ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter, no data is processed.
        $selected = isset( $_GET['qwab_collection'] ) ? sanitize_key( wp_unslash( $_GET['qwab_collection'] ) ) : '';
        $all      = self::all();
        ?>
        <label for="qwab-collection-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by collection', 'qaiyo-admin-booster' ); ?></label>
        <select name="qwab_collection" id="qwab-collection-filter">
            <option value=""><?php esc_html_e( 'All collections', 'qaiyo-admin-booster' ); ?></option>
            <option value="__none" <?php selected( $selected, '__none' ); ?>><?php esc_html_e( 'Uncategorized', 'qaiyo-admin-booster' ); ?></option>
            <?php foreach ( $all as $id => $c ) : ?>
                <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $selected, $id ); ?>><?php echo esc_html( $c['name'] ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Szűrő alkalmazása a lekérdezésre (kézi hozzárendelés alapján).
     *
     * @param WP_Query $query Lekérdezés.
     */
    public function apply_filter( $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, $this->post_types(), true ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter, no data is processed.
        $selected = isset( $_GET['qwab_collection'] ) ? sanitize_key( wp_unslash( $_GET['qwab_collection'] ) ) : '';
        if ( '' === $selected ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );

        if ( '__none' === $selected ) {
            $meta_query[] = array(
                'key'     => self::META_KEY,
                'compare' => 'NOT EXISTS',
            );
        } else {
            $meta_query[] = array(
                'key'   => self::META_KEY,
                'value' => $selected,
            );
        }

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Egyedi kollekciók mentése (az admin AJAX hívja).
     *
     * @param array $collections Nyers kollekció-tömb.
     * @return array A tisztított, elmentett kollekciók.
     */
    public static function save_custom( array $collections ): array {
        $clean    = array();
        $reserved = self::reserved();
        if ( is_array( $collections ) ) {
            foreach ( $collections as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $name = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
                if ( '' === $name ) {
                    continue;
                }
                $id = isset( $item['id'] ) && '' !== $item['id'] ? sanitize_key( $item['id'] ) : sanitize_key( $name );
                if ( '' === $id || isset( $reserved[ $id ] ) || isset( $clean[ $id ] ) ) {
                    $id = $id . '-' . wp_generate_password( 4, false, false );
                    $id = sanitize_key( $id );
                }
                $color = isset( $item['color'] ) ? sanitize_hex_color( $item['color'] ) : '';
                $icon  = isset( $item['icon'] ) ? sanitize_html_class( $item['icon'] ) : '';
                $clean[ $id ] = array(
                    'name'  => $name,
                    'color' => $color ? $color : '#6c5ce7',
                    'icon'  => ( $icon && 0 === strpos( $icon, 'dashicons-' ) ) ? $icon : 'dashicons-category',
                );
            }
        }
        update_option( self::COLLECTIONS_OPT, $clean );
        return $clean;
    }
}

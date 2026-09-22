<?php
/**
 * Modul: Page Filter.
 *
 * Egy kattintással láthatóvá teszi a lista nézetben: melyik a főoldal,
 * melyik árva oldal (nincs menüben és nem a főoldal), melyik nincs
 * navigációs menüben, melyik noindex, és mi a státusza. A lista tetején
 * legördülő szűrő, az oldalakon pedig állapot-badge oszlop.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Page_Filter {

    /** @var array<int,bool>|null Menüben szereplő poszt id-k cache. */
    private static $menu_ids = null;

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        foreach ( $this->post_types() as $type ) {
            add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
            add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
        }
        add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown' ) );
        add_action( 'pre_get_posts', array( $this, 'apply_filter' ) );
    }

    /**
     * Mely poszttípusokon működjön.
     *
     * @return array
     */
    private function post_types(): array {
        return array_values( array_filter( array( 'page', 'post' ), 'post_type_exists' ) );
    }

    /* ---------------------------------------------------------------------
     * Felismerő helperek
     * ------------------------------------------------------------------- */

    /**
     * Navigációs menükben szereplő objektum-id-k.
     *
     * @return array<int,bool> id => true.
     */
    public static function menu_object_ids(): array {
        if ( null !== self::$menu_ids ) {
            return self::$menu_ids;
        }
        $ids   = array();
        $menus = wp_get_nav_menus();
        if ( ! is_wp_error( $menus ) && ! empty( $menus ) ) {
            foreach ( $menus as $menu ) {
                $items = wp_get_nav_menu_items( $menu->term_id );
                if ( empty( $items ) ) {
                    continue;
                }
                foreach ( $items as $item ) {
                    if ( in_array( $item->type, array( 'post_type', 'post_type_archive' ), true ) && $item->object_id ) {
                        $ids[ (int) $item->object_id ] = true;
                    }
                }
            }
        }
        self::$menu_ids = $ids;
        return $ids;
    }

    /**
     * Egy poszt benne van-e bármelyik menüben.
     *
     * @param int $post_id Poszt id.
     * @return bool
     */
    public static function is_in_menu( int $post_id ): bool {
        $ids = self::menu_object_ids();
        return isset( $ids[ (int) $post_id ] );
    }

    /**
     * Főoldal-e a poszt.
     *
     * @param int $post_id Poszt id.
     * @return bool
     */
    public static function is_front_page( int $post_id ): bool {
        return (int) $post_id === (int) get_option( 'page_on_front' );
    }

    /**
     * noindex-e a poszt (Yoast / Rank Math / SEOPress felismerés).
     *
     * @param int $post_id Poszt id.
     * @return bool
     */
    public static function is_noindex( int $post_id ): bool {
        // Yoast.
        if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
            return true;
        }
        // SEOPress.
        if ( 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
            return true;
        }
        // Rank Math (sorozatosított tömb, tartalmazza a "noindex"-et).
        $rm = get_post_meta( $post_id, 'rank_math_robots', true );
        if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
            return true;
        }
        return false;
    }

    /**
     * Árva-e: published, nincs menüben, nem a főoldal, nincs gyereke és nincs szülője.
     *
     * @param WP_Post $post Poszt.
     * @return bool
     */
    public static function is_orphan( WP_Post $post ): bool {
        if ( 'publish' !== $post->post_status ) {
            return false;
        }
        if ( self::is_front_page( $post->ID ) || self::is_in_menu( $post->ID ) ) {
            return false;
        }
        if ( (int) $post->post_parent > 0 ) {
            return false;
        }
        $children = get_children(
            array(
                'post_parent' => $post->ID,
                'post_type'   => $post->post_type,
                'numberposts' => 1,
                'fields'      => 'ids',
            )
        );
        return empty( $children );
    }

    /* ---------------------------------------------------------------------
     * Oszlop
     * ------------------------------------------------------------------- */

    /**
     * Oszlop hozzáadása.
     *
     * @param array $columns Oszlopok.
     * @return array
     */
    public function add_column( $columns ) {
        $columns['qwab_page_status'] = __( 'Page status', 'qaiyo-admin-booster' );
        return $columns;
    }

    /**
     * Oszlop tartalma — badge-ek.
     *
     * @param string $column  Oszlop kulcs.
     * @param int    $post_id Poszt id.
     */
    public function render_column( $column, $post_id ): void {
        if ( 'qwab_page_status' !== $column ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $badges = array();

        if ( self::is_front_page( $post_id ) ) {
            $badges[] = array( 'home', __( 'Homepage', 'qaiyo-admin-booster' ), 'dashicons-admin-home' );
        }

        if ( 'page' === $post->post_type ) {
            if ( self::is_orphan( $post ) ) {
                $badges[] = array( 'orphan', __( 'Orphan', 'qaiyo-admin-booster' ), 'dashicons-warning' );
            } elseif ( ! self::is_in_menu( $post_id ) && ! self::is_front_page( $post_id ) ) {
                $badges[] = array( 'nomenu', __( 'Not in menu', 'qaiyo-admin-booster' ), 'dashicons-menu' );
            } elseif ( self::is_in_menu( $post_id ) ) {
                $badges[] = array( 'inmenu', __( 'In menu', 'qaiyo-admin-booster' ), 'dashicons-menu-alt3' );
            }
        }

        if ( self::is_noindex( $post_id ) ) {
            $badges[] = array( 'noindex', __( 'noindex', 'qaiyo-admin-booster' ), 'dashicons-hidden' );
        }

        if ( 'publish' !== $post->post_status ) {
            $obj = get_post_status_object( $post->post_status );
            if ( $obj ) {
                $badges[] = array( 'status', $obj->label, 'dashicons-edit' );
            }
        }

        if ( empty( $badges ) ) {
            echo '<span class="qwab-col-empty" aria-hidden="true">—</span>';
            return;
        }

        echo '<span class="qwab-status-badges">';
        foreach ( $badges as $b ) {
            printf(
                '<span class="qwab-status-badge qwab-status-%1$s"><span class="dashicons %2$s"></span>%3$s</span>',
                esc_attr( $b[0] ),
                esc_attr( $b[2] ),
                esc_html( $b[1] )
            );
        }
        echo '</span>';
    }

    /* ---------------------------------------------------------------------
     * Szűrő
     * ------------------------------------------------------------------- */

    /**
     * Szűrő legördülő.
     *
     * @param string $post_type Poszttípus.
     */
    public function filter_dropdown( $post_type ): void {
        if ( ! in_array( $post_type, $this->post_types(), true ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter, no data is processed.
        $selected = isset( $_GET['qwab_pfilter'] ) ? sanitize_key( wp_unslash( $_GET['qwab_pfilter'] ) ) : '';
        $options  = array(
            'homepage'    => __( 'Homepage', 'qaiyo-admin-booster' ),
            'orphan'      => __( 'Orphan pages', 'qaiyo-admin-booster' ),
            'not_in_menu' => __( 'Not in any menu', 'qaiyo-admin-booster' ),
            'noindex'     => __( 'Noindex', 'qaiyo-admin-booster' ),
        );
        ?>
        <label for="qwab-page-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by page status', 'qaiyo-admin-booster' ); ?></label>
        <select name="qwab_pfilter" id="qwab-page-filter">
            <option value=""><?php esc_html_e( 'Any page status', 'qaiyo-admin-booster' ); ?></option>
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Szűrő alkalmazása.
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
        $filter = isset( $_GET['qwab_pfilter'] ) ? sanitize_key( wp_unslash( $_GET['qwab_pfilter'] ) ) : '';
        if ( '' === $filter ) {
            return;
        }

        switch ( $filter ) {
            case 'homepage':
                $front = (int) get_option( 'page_on_front' );
                $query->set( 'post__in', $front ? array( $front ) : array( 0 ) );
                break;

            case 'not_in_menu':
                $ids   = array_keys( self::menu_object_ids() );
                $front = (int) get_option( 'page_on_front' );
                if ( $front ) {
                    $ids[] = $front;
                }
                $query->set( 'post__not_in', ! empty( $ids ) ? array_map( 'intval', $ids ) : array( 0 ) );
                break;

            case 'orphan':
                $ids   = array_keys( self::menu_object_ids() );
                $front = (int) get_option( 'page_on_front' );
                if ( $front ) {
                    $ids[] = $front;
                }
                $query->set( 'post__not_in', ! empty( $ids ) ? array_map( 'intval', $ids ) : array( 0 ) );
                $query->set( 'post_parent', 0 );
                break;

            case 'noindex':
                $meta_query   = (array) $query->get( 'meta_query' );
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key'   => '_yoast_wpseo_meta-robots-noindex',
                        'value' => '1',
                    ),
                    array(
                        'key'   => '_seopress_robots_index',
                        'value' => 'yes',
                    ),
                    array(
                        'key'     => 'rank_math_robots',
                        'value'   => 'noindex',
                        'compare' => 'LIKE',
                    ),
                );
                $query->set( 'meta_query', $meta_query );
                break;
        }
    }
}

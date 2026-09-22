<?php
/**
 * Modul: Tömeges oldal-/bejegyzés-létrehozás.
 *
 * Egy külön admin oldalt ad az „Admin Booster” menü alá, ahol soronként egy
 * címet megadva egyszerre sok oldal/bejegyzés hozható létre. Behúzással
 * (Tab vagy 2 szóköz = egy szint) szülő-gyerek hierarchia is felépíthető a
 * hierarchikus típusoknál (pl. Oldalak).
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Bulk_Create {

    const MENU_SLUG  = 'qwab-bulk-create';
    const NONCE      = 'qwab_bulk_create';
    const MAX_ITEMS  = 200;

    /** Az almenü hook-suffixe. */
    private static $hook = '';

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_page' ), 35 );
    }

    /* ---------------------------------------------------------------------
     * Menü + feldolgozás
     * ------------------------------------------------------------------- */

    public function register_page(): void {
        self::$hook = add_submenu_page(
            'qaiyo-admin-booster',
            __( 'Bulk Create', 'qaiyo-admin-booster' ),
            __( 'Bulk Create', 'qaiyo-admin-booster' ),
            'edit_pages',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );

        if ( self::$hook ) {
            add_action( 'load-' . self::$hook, array( $this, 'maybe_process' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        }
    }

    public function enqueue( $hook ): void {
        if ( $hook !== self::$hook ) {
            return;
        }
        $admin_css = QWAB_PATH . 'assets/css/qwab-admin.css';
        $bulk_css  = QWAB_PATH . 'assets/css/qwab-bulk.css';

        wp_enqueue_style(
            'qwab-admin',
            QWAB_URL . 'assets/css/qwab-admin.css',
            array( 'dashicons' ),
            QWAB_VERSION . '.' . ( file_exists( $admin_css ) ? filemtime( $admin_css ) : QWAB_VERSION )
        );
        wp_enqueue_style(
            'qwab-bulk',
            QWAB_URL . 'assets/css/qwab-bulk.css',
            array( 'qwab-admin' ),
            QWAB_VERSION . '.' . ( file_exists( $bulk_css ) ? filemtime( $bulk_css ) : QWAB_VERSION )
        );

        $bulk_js = QWAB_PATH . 'assets/js/qwab-bulk.js';
        wp_enqueue_script(
            'qwab-bulk',
            QWAB_URL . 'assets/js/qwab-bulk.js',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $bulk_js ) ? filemtime( $bulk_js ) : QWAB_VERSION ),
            true
        );
        wp_localize_script(
            'qwab-bulk',
            'qwabBulk',
            array(
                /* translators: %d: number of non-empty lines in the textarea. */
                'counterTpl' => __( '%d non-empty line(s) ready to create.', 'qaiyo-admin-booster' ),
            )
        );
    }

    /**
     * POST feldolgozás a kimenet előtt (PRG minta — átirányítás után notice).
     */
    public function maybe_process(): void {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
        if ( 'POST' !== $method ) {
            return;
        }
        if ( ! isset( $_POST['qwab_bulk_nonce'] ) ) {
            return;
        }
        check_admin_referer( self::NONCE, 'qwab_bulk_nonce' );

        // Poszttípus + jogosultság.
        $post_type = isset( $_POST['qwab_bulk_post_type'] )
            ? sanitize_key( wp_unslash( $_POST['qwab_bulk_post_type'] ) )
            : 'page';

        $pt_obj = get_post_type_object( $post_type );
        if ( ! $pt_obj || ! in_array( $post_type, Qwab_Settings::boostable_post_types(), true ) ) {
            $this->redirect_back( array( 'qwab_error' => 'type' ) );
        }
        $create_cap = isset( $pt_obj->cap->create_posts ) ? $pt_obj->cap->create_posts : $pt_obj->cap->edit_posts;
        if ( ! current_user_can( $create_cap ) ) {
            $this->redirect_back( array( 'qwab_error' => 'cap' ) );
        }

        // Státusz.
        $allowed_status = array( 'draft', 'publish', 'pending', 'private' );
        $status         = isset( $_POST['qwab_bulk_status'] ) ? sanitize_key( wp_unslash( $_POST['qwab_bulk_status'] ) ) : 'draft';
        if ( ! in_array( $status, $allowed_status, true ) ) {
            $status = 'draft';
        }
        if ( 'publish' === $status && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
            $status = 'pending';
        }

        // Alap szülő (csak hierarchikus típusnál érvényes).
        $base_parent = isset( $_POST['qwab_bulk_parent'] ) ? absint( $_POST['qwab_bulk_parent'] ) : 0;
        $is_hier     = is_post_type_hierarchical( $post_type );
        if ( ! $is_hier ) {
            $base_parent = 0;
        }

        // Címek. Nyers olvasás (a behúzás megőrzéséhez); soronként sanitize lentebb.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each line is sanitized with sanitize_text_field() before use; raw read preserves indentation for hierarchy.
        $raw   = isset( $_POST['qwab_bulk_titles'] ) ? (string) wp_unslash( $_POST['qwab_bulk_titles'] ) : '';
        $lines = preg_split( '/\r\n|\r|\n/', $raw );

        $created     = 0;
        $failed      = 0;
        $parents     = array(); // depth => post_id
        $menu_order  = 0;
        $first_id    = 0;

        foreach ( $lines as $line ) {
            if ( $created + $failed >= self::MAX_ITEMS ) {
                break;
            }

            // Behúzás-szint kiszámítása (Tab = 1, 2 szóköz = 1).
            $depth = 0;
            if ( $is_hier && preg_match( '/^([\t ]+)/', $line, $m ) ) {
                $tabs   = substr_count( $m[1], "\t" );
                $spaces = substr_count( $m[1], ' ' );
                $depth  = $tabs + (int) floor( $spaces / 2 );
            }

            $title = sanitize_text_field( wp_strip_all_tags( trim( $line ) ) );
            if ( '' === $title ) {
                continue;
            }

            // Szülő meghatározása a behúzás alapján.
            $parent = $base_parent;
            if ( $is_hier && $depth > 0 ) {
                for ( $d = $depth - 1; $d >= 0; $d-- ) {
                    if ( isset( $parents[ $d ] ) ) {
                        $parent = $parents[ $d ];
                        break;
                    }
                }
            }

            $new_id = wp_insert_post(
                wp_slash(
                    array(
                        'post_title'  => $title,
                        'post_type'   => $post_type,
                        'post_status' => $status,
                        'post_parent' => $is_hier ? $parent : 0,
                        'post_author' => get_current_user_id(),
                        'menu_order'  => $menu_order,
                    )
                ),
                true
            );

            if ( is_wp_error( $new_id ) || ! $new_id ) {
                ++$failed;
                continue;
            }

            ++$created;
            ++$menu_order;
            if ( ! $first_id ) {
                $first_id = $new_id;
            }

            if ( $is_hier ) {
                $parents[ $depth ] = $new_id;
                // Mélyebb szintek érvénytelenítése.
                foreach ( array_keys( $parents ) as $d ) {
                    if ( $d > $depth ) {
                        unset( $parents[ $d ] );
                    }
                }
            }
        }

        $this->redirect_back(
            array(
                'qwab_created' => $created,
                'qwab_failed'  => $failed,
                'qwab_ptype'   => $post_type,
            )
        );
    }

    private function redirect_back( $args ): void {
        $url = add_query_arg(
            array_merge( array( 'page' => self::MENU_SLUG ), $args ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /* ---------------------------------------------------------------------
     * Oldal
     * ------------------------------------------------------------------- */

    public function render_page(): void {
        if ( ! current_user_can( 'edit_pages' ) ) {
            return;
        }

        $types = $this->creatable_types();
        ?>
        <div class="wrap qwab-wrap qwab-bulk-wrap">
            <h1>
                <span class="qwab-brand">Qaiyo</span> <?php esc_html_e( 'Bulk Create', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-version">v<?php echo esc_html( QWAB_VERSION ); ?></span>
            </h1>
            <p class="qwab-subtitle"><?php esc_html_e( 'Create many pages or posts at once — one title per line. Indent with a Tab (or 2 spaces) to nest child pages.', 'qaiyo-admin-booster' ); ?></p>

            <?php $this->result_notice(); ?>

            <?php if ( empty( $types ) ) : ?>
                <div class="qwab-card">
                    <p><?php esc_html_e( 'You do not have permission to create any content types.', 'qaiyo-admin-booster' ); ?></p>
                </div>
                <?php
                echo '</div>';
                return;
            endif;
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="qwab-bulk-form">
                <?php wp_nonce_field( self::NONCE, 'qwab_bulk_nonce' ); ?>

                <div class="qwab-card">
                    <h2 class="qwab-card__title">
                        <span class="qwab-card__title-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg></span>
                        <?php esc_html_e( 'What to create', 'qaiyo-admin-booster' ); ?>
                    </h2>

                    <div class="qwab-bulk-row">
                        <div class="qwab-field">
                            <label class="qwab-field__label" for="qwab_bulk_post_type"><?php esc_html_e( 'Content type', 'qaiyo-admin-booster' ); ?></label>
                            <select id="qwab_bulk_post_type" name="qwab_bulk_post_type" class="qwab-bulk-select">
                                <?php foreach ( $types as $type => $label ) : ?>
                                    <option value="<?php echo esc_attr( $type ); ?>" <?php selected( 'page', $type ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="qwab-field">
                            <label class="qwab-field__label" for="qwab_bulk_status"><?php esc_html_e( 'Status', 'qaiyo-admin-booster' ); ?></label>
                            <select id="qwab_bulk_status" name="qwab_bulk_status" class="qwab-bulk-select">
                                <option value="draft"><?php esc_html_e( 'Draft', 'qaiyo-admin-booster' ); ?></option>
                                <option value="publish"><?php esc_html_e( 'Published', 'qaiyo-admin-booster' ); ?></option>
                                <option value="pending"><?php esc_html_e( 'Pending review', 'qaiyo-admin-booster' ); ?></option>
                                <option value="private"><?php esc_html_e( 'Private', 'qaiyo-admin-booster' ); ?></option>
                            </select>
                        </div>

                        <div class="qwab-field">
                            <label class="qwab-field__label" for="qwab_bulk_parent"><?php esc_html_e( 'Parent page', 'qaiyo-admin-booster' ); ?>
                                <span class="qwab-field__desc"><?php esc_html_e( 'Top-level parent for the whole batch. Only applies to hierarchical types (e.g. Pages).', 'qaiyo-admin-booster' ); ?></span>
                            </label>
                            <?php
                            wp_dropdown_pages(
                                array(
                                    'name'              => 'qwab_bulk_parent',
                                    'id'                => 'qwab_bulk_parent',
                                    'show_option_none'  => esc_html__( '— None (top level) —', 'qaiyo-admin-booster' ),
                                    'option_none_value' => 0,
                                    'selected'          => 0,
                                )
                            );
                            ?>
                        </div>
                    </div>

                    <div class="qwab-field">
                        <label class="qwab-field__label" for="qwab_bulk_titles"><?php esc_html_e( 'Titles — one per line', 'qaiyo-admin-booster' ); ?>
                            <span class="qwab-field__desc">
                                <?php
                                printf(
                                    /* translators: %d: maximum number of items per batch. */
                                    esc_html__( 'Up to %d items per batch. Indent a line with a Tab (or 2 spaces) to make it a child of the line above.', 'qaiyo-admin-booster' ),
                                    (int) self::MAX_ITEMS
                                );
                                ?>
                            </span>
                        </label>
                        <textarea id="qwab_bulk_titles" name="qwab_bulk_titles" class="qwab-bulk-textarea" rows="12" spellcheck="false" placeholder="<?php echo esc_attr( "About\nServices\n\tWeb design\n\tConsulting\nContact" ); ?>"></textarea>
                        <p class="qwab-bulk-counter" aria-live="polite"></p>
                    </div>

                    <div class="qwab-actions">
                        <button type="submit" class="qwab-button-primary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            <?php esc_html_e( 'Create items', 'qaiyo-admin-booster' ); ?>
                        </button>
                    </div>
                </div>
            </form>

            <div class="qwab-footer">
                <?php
                Qwab_Admin::render_footer();
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Eredmény-értesítő a PRG redirect után (GET paraméterek).
     */
    private function result_notice(): void {
        // Olvasás-only visszajelzés; nincs állapotváltozás, ezért a nonce nem szükséges.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['qwab_error'] ) ) {
            $err = sanitize_key( wp_unslash( $_GET['qwab_error'] ) );
            $msg = ( 'cap' === $err )
                ? __( 'You are not allowed to create that content type.', 'qaiyo-admin-booster' )
                : __( 'Unknown or unsupported content type.', 'qaiyo-admin-booster' );
            printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $msg ) );
            return;
        }

        if ( ! isset( $_GET['qwab_created'] ) ) {
            return;
        }

        $created = absint( wp_unslash( $_GET['qwab_created'] ) );
        $failed  = isset( $_GET['qwab_failed'] ) ? absint( wp_unslash( $_GET['qwab_failed'] ) ) : 0;
        $ptype   = isset( $_GET['qwab_ptype'] ) ? sanitize_key( wp_unslash( $_GET['qwab_ptype'] ) ) : 'page';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $list_url = admin_url( 'edit.php?post_type=' . $ptype );

        if ( $created > 0 ) {
            $text = sprintf(
                /* translators: %d: number of created items. */
                __( '%d item(s) created.', 'qaiyo-admin-booster' ),
                $created
            );
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php echo esc_html( $text ); ?></strong>
                    <?php if ( $failed > 0 ) : ?>
                        <?php
                        printf(
                            /* translators: %d: number of items that failed. */
                            esc_html__( '%d item(s) could not be created.', 'qaiyo-admin-booster' ),
                            (int) $failed
                        );
                        ?>
                    <?php endif; ?>
                    &nbsp;<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View the list →', 'qaiyo-admin-booster' ); ?></a>
                </p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php esc_html_e( 'No items were created. Add at least one non-empty title.', 'qaiyo-admin-booster' ); ?></p>
            </div>
            <?php
        }
    }


    /**
     * A létrehozható poszttípusok: boostable típusok, amikre van jogosultság.
     *
     * @return array<string,string> type => label
     */
    private function creatable_types(): array {
        $out = array();
        foreach ( Qwab_Settings::boostable_post_types() as $type ) {
            $obj = get_post_type_object( $type );
            if ( ! $obj ) {
                continue;
            }
            $cap = isset( $obj->cap->create_posts ) ? $obj->cap->create_posts : $obj->cap->edit_posts;
            if ( ! current_user_can( $cap ) ) {
                continue;
            }
            $out[ $type ] = $obj->labels->name;
        }
        return $out;
    }
}

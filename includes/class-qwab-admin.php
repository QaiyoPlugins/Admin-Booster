<?php
/**
 * Admin felület — beállító oldal (modul-kapcsolók, navigáció, fájlkezelés,
 * kollekciók) + AJAX kollekció-kezelés.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Admin {

    const MENU_SLUG = 'qaiyo-admin-booster';

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( 'Qwab_Settings', 'register' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_qwab_save_collections', array( $this, 'ajax_save_collections' ) );
        add_filter( 'plugin_action_links_' . QWAB_BASENAME, array( $this, 'action_links' ) );
    }

    /* ---------------------------------------------------------------------
     * Menü
     * ------------------------------------------------------------------- */

    public function register_menu(): void {
        add_menu_page(
            __( 'Qaiyo Admin Booster', 'qaiyo-admin-booster' ),
            __( 'Admin Booster', 'qaiyo-admin-booster' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_page' ),
            'dashicons-superhero-alt',
            Qwab_Brand_Menu::plugin_position()
        );

        Qwab_Brand_Menu::register_plugin_slug( self::MENU_SLUG );

        // Cross-plugin fallback — ha másik Qaiyo plugin brand menüje tölt be előbb.
        foreach ( array( 'Qaiyo_Access_Manager_Brand_Menu', 'Qsve_Brand_Menu', 'Qt_Brand_Menu', 'Qls_Brand_Menu' ) as $cls ) {
            if ( class_exists( $cls ) && method_exists( $cls, 'register_plugin_slug' ) ) {
                call_user_func( array( $cls, 'register_plugin_slug' ), self::MENU_SLUG );
            }
        }
    }

    public function action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
            esc_html__( 'Settings', 'qaiyo-admin-booster' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /* ---------------------------------------------------------------------
     * Asset-ek
     * ------------------------------------------------------------------- */

    public function enqueue_assets( $hook ) {
        // Lista nézetek (Oldalak / Bejegyzések): csak a badge-stílus kell.
        if ( 'edit.php' === $hook ) {
            $list_path = QWAB_PATH . 'assets/css/qwab-list.css';
            wp_enqueue_style(
                'qwab-list',
                QWAB_URL . 'assets/css/qwab-list.css',
                array( 'dashicons' ),
                QWAB_VERSION . '.' . ( file_exists( $list_path ) ? filemtime( $list_path ) : QWAB_VERSION )
            );
            return;
        }

        if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
            return;
        }

        $css_path = QWAB_PATH . 'assets/css/qwab-admin.css';
        $js_path  = QWAB_PATH . 'assets/js/qwab-admin.js';

        wp_enqueue_style(
            'qwab-admin',
            QWAB_URL . 'assets/css/qwab-admin.css',
            array( 'dashicons' ),
            QWAB_VERSION . '.' . ( file_exists( $css_path ) ? filemtime( $css_path ) : QWAB_VERSION )
        );

        $ecop_path = QWAB_PATH . 'assets/css/qwab-ecosystem-panel.css';
        wp_enqueue_style(
            'qwab-ecosystem-panel',
            QWAB_URL . 'assets/css/qwab-ecosystem-panel.css',
            array( 'dashicons' ),
            QWAB_VERSION . '.' . ( file_exists( $ecop_path ) ? filemtime( $ecop_path ) : QWAB_VERSION )
        );

        wp_enqueue_script(
            'qwab-admin',
            QWAB_URL . 'assets/js/qwab-admin.js',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $js_path ) ? filemtime( $js_path ) : QWAB_VERSION ),
            true
        );

        wp_localize_script(
            'qwab-admin',
            'qwabAdmin',
            array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'qwab_collections' ),
                'mailNonce'   => wp_create_nonce( 'qwab_test_update_mail' ),
                'collections' => array_map(
                    function ( $id, $c ) {
                        return array(
                            'id'    => $id,
                            'name'  => $c['name'],
                            'color' => $c['color'],
                            'icon'  => $c['icon'],
                        );
                    },
                    array_keys( Qwab_Module_Page_Collections::custom() ),
                    array_values( Qwab_Module_Page_Collections::custom() )
                ),
                'iconChoices' => array(
                    'dashicons-category', 'dashicons-admin-home', 'dashicons-hammer',
                    'dashicons-megaphone', 'dashicons-text-page', 'dashicons-cart',
                    'dashicons-admin-page', 'dashicons-portfolio', 'dashicons-star-filled',
                    'dashicons-tag', 'dashicons-archive', 'dashicons-groups',
                ),
                'i18n'        => array(
                    'saved'        => __( 'Collections saved.', 'qaiyo-admin-booster' ),
                    'saveError'    => __( 'Could not save collections. Please try again.', 'qaiyo-admin-booster' ),
                    'confirmDel'   => __( 'Remove this collection? Pages assigned to it will fall back to auto/none.', 'qaiyo-admin-booster' ),
                    'namePlace'    => __( 'Collection name', 'qaiyo-admin-booster' ),
                    'remove'       => __( 'Remove', 'qaiyo-admin-booster' ),
                    'hexLabel'     => __( 'Color', 'qaiyo-admin-booster' ),
                    'iconLabel'    => __( 'Icon', 'qaiyo-admin-booster' ),
                    'mailSending'  => __( 'Sending…', 'qaiyo-admin-booster' ),
                    'mailError'    => __( 'Could not send the test email. Please try again.', 'qaiyo-admin-booster' ),
                ),
            )
        );
    }

    /* ---------------------------------------------------------------------
     * AJAX — kollekciók mentése
     * ------------------------------------------------------------------- */

    public function ajax_save_collections(): void {
        check_ajax_referer( 'qwab_collections', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'qaiyo-admin-booster' ) ), 403 );
        }

        // Nyers JSON payload: CSAK unslash, majd json_decode — a dekódolt
        // értékeket a save_custom() sanitizálja mezőnként (név/szín/ikon).
        // A sanitize_text_field() a JSON-stringen a dekódolás ELŐTT megcsonkítaná
        // az értékeket (pl. %-szekvenciák, < jelek, többszörös szóköz).
        $raw = isset( $_POST['collections'] ) && is_string( $_POST['collections'] )
            ? wp_unslash( $_POST['collections'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, mezőnként sanitizálva a json_decode után.
            : '[]';
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            $decoded = array();
        }

        $saved = Qwab_Module_Page_Collections::save_custom( $decoded );

        $out = array();
        foreach ( $saved as $id => $c ) {
            $out[] = array(
                'id'    => $id,
                'name'  => $c['name'],
                'color' => $c['color'],
                'icon'  => $c['icon'],
            );
        }
        wp_send_json_success( array( 'collections' => $out ) );
    }

    /* ---------------------------------------------------------------------
     * Oldal renderelés
     * ------------------------------------------------------------------- */

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s            = Qwab_Settings::all();
        $module_count = count( Qwab_Settings::default_modules() );
        // A „Pro features" lakatos teaser tab CSAK akkor jelenik meg, ha a Pro
        // plugin NINCS aktiválva. Ha aktív (licenccel vagy anélkül), a Pro maga
        // ad egy valódi „Pro" tabot (a qwab_render_settings_* hookokon), és a
        // teaser elmaradna, hogy ne legyen két Pro-tab.
        $pro_active     = qwab_is_pro_active();
        $has_pro_teaser = ( ! $pro_active && Qwab_Pro_Catalog::has_locked() );
        ?>
        <div class="wrap qwab-wrap">
            <?php
            $qwab_user = wp_get_current_user();
            $qwab_name = $qwab_user->first_name ? $qwab_user->first_name : $qwab_user->display_name;
            if ( '' === $qwab_name ) {
                $qwab_name = $qwab_user->user_login;
            }
            ?>
            <p class="qwab-greeting">
                <?php
                /* translators: %s: current user's first name. */
                printf( esc_html__( 'Hi, %s!', 'qaiyo-admin-booster' ), esc_html( $qwab_name ) );
                ?>
                <span class="qwab-wave" aria-hidden="true">&#128075;</span>
            </p>
            <h1>
                <span class="qwab-brand">Qaiyo</span> <?php esc_html_e( 'Admin Booster', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-version">v<?php echo esc_html( QWAB_VERSION ); ?></span>
            </h1>
            <p class="qwab-subtitle"><?php esc_html_e( 'Fixes the annoying wp-admin UX/UI rough edges that cost you time every day.', 'qaiyo-admin-booster' ); ?></p>

            <?php settings_errors(); ?>

            <nav class="qwab-tabs" role="tablist">
                <button type="button" class="qwab-tab is-active" data-tab="modules" role="tab" aria-selected="true">
                    <?php esc_html_e( 'Modules', 'qaiyo-admin-booster' ); ?>
                    <span class="qwab-tab-count"><?php echo (int) $module_count; ?></span>
                </button>
                <button type="button" class="qwab-tab" data-tab="navigation" role="tab" aria-selected="false">
                    <?php esc_html_e( 'Navigation & editor', 'qaiyo-admin-booster' ); ?>
                </button>
                <button type="button" class="qwab-tab" data-tab="dashboard" role="tab" aria-selected="false">
                    <?php esc_html_e( 'Dashboard cleanup', 'qaiyo-admin-booster' ); ?>
                </button>
                <button type="button" class="qwab-tab" data-tab="files" role="tab" aria-selected="false">
                    <?php esc_html_e( 'File handling', 'qaiyo-admin-booster' ); ?>
                </button>
                <button type="button" class="qwab-tab" data-tab="collections" role="tab" aria-selected="false">
                    <?php esc_html_e( 'Page Collections', 'qaiyo-admin-booster' ); ?>
                </button>
                <button type="button" class="qwab-tab" data-tab="notifications" role="tab" aria-selected="false">
                    <?php esc_html_e( 'Update notifications', 'qaiyo-admin-booster' ); ?>
                </button>
                <button type="button" class="qwab-tab" data-tab="update-guard" role="tab" aria-selected="false">
                    <?php esc_html_e( 'Update crash protection', 'qaiyo-admin-booster' ); ?>
                </button>
                <button type="button" class="qwab-tab" data-tab="safe-mode" role="tab" aria-selected="false">
                    <?php esc_html_e( 'Safe Mode', 'qaiyo-admin-booster' ); ?>
                </button>
                <button type="button" class="qwab-tab" data-tab="ecosystem" role="tab" aria-selected="false">
                    <?php esc_html_e( 'Qaiyo ecosystem', 'qaiyo-admin-booster' ); ?>
                </button>
                <?php if ( $has_pro_teaser ) : ?>
                    <button type="button" class="qwab-tab qwab-tab--pro" data-tab="pro" role="tab" aria-selected="false">
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <?php esc_html_e( 'Pro features', 'qaiyo-admin-booster' ); ?>
                    </button>
                <?php endif; ?>
                <?php
                /**
                 * Kiterjesztési pont: az aktív Pro plugin ide adhat tab-gombo(ka)t
                 * a pill-sávba (a Free tabok után).
                 */
                do_action( 'qwab_render_settings_tab' );
                ?>
            </nav>

            <form method="post" action="options.php" class="qwab-settings-form">
                <?php settings_fields( 'qwab_group' ); ?>

                <div class="qwab-tab-panel is-active" data-panel="modules" role="tabpanel">
                    <?php
                    $this->card_modules( $s );
                    $this->card_info();
                    ?>
                </div>
                <div class="qwab-tab-panel" data-panel="navigation" role="tabpanel" hidden>
                    <?php $this->card_navigation( $s ); ?>
                </div>
                <div class="qwab-tab-panel" data-panel="dashboard" role="tabpanel" hidden>
                    <?php $this->card_dashboard( $s ); ?>
                </div>
                <div class="qwab-tab-panel" data-panel="files" role="tabpanel" hidden>
                    <?php $this->card_files( $s ); ?>
                </div>
                <div class="qwab-tab-panel" data-panel="collections" role="tabpanel" hidden>
                    <?php $this->card_collections( $s ); ?>
                </div>
                <div class="qwab-tab-panel" data-panel="notifications" role="tabpanel" hidden>
                    <?php $this->card_notifications( $s ); ?>
                </div>

                <div class="qwab-actions qwab-submit-bar">
                    <button type="submit" class="qwab-button-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <?php esc_html_e( 'Save settings', 'qaiyo-admin-booster' ); ?>
                    </button>
                </div>
            </form>

            <div class="qwab-tab-panel" data-panel="update-guard" role="tabpanel" hidden>
                <?php $this->card_update_guard(); ?>
            </div>

            <div class="qwab-tab-panel" data-panel="safe-mode" role="tabpanel" hidden>
                <?php $this->card_safe_mode(); ?>
            </div>

            <div class="qwab-tab-panel" data-panel="ecosystem" role="tabpanel" hidden>
                <?php Qwab_Ecosystem_Panel::render(); ?>
            </div>

            <?php if ( $has_pro_teaser ) : ?>
                <div class="qwab-tab-panel" data-panel="pro" role="tabpanel" hidden>
                    <?php Qwab_Pro_Teaser::render_card(); ?>
                </div>
            <?php endif; ?>
            <?php
            /**
             * Kiterjesztési pont: az aktív Pro plugin ide rendereli a saját
             * tab-paneljét/paneljeit (a Free űrlapon KÍVÜL, saját form(ok)kal).
             */
            do_action( 'qwab_render_settings_panel' );
            ?>

            <div class="qwab-footer">
                <?php
                self::render_footer();
                ?>
                <a href="https://qaiyo-plugins.com" target="_blank" rel="noopener noreferrer">qaiyo-plugins.com</a>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Kártyák
     * ------------------------------------------------------------------- */

    /**
     * A kártya-ikonokban használt inline SVG engedélyezett elemei/attribútumai.
     *
     * Saját, statikus markupot adunk ki, de `wp_kses`-en átengedve: így a
     * kimenet akkor is biztonságos, ha az ikon valaha változóból jönne.
     *
     * @return array<string,array<string,bool>>
     */
    /**
     * A beállító-oldalak lábléce. Közös, mert több képernyő is kiírja.
     *
     * A teljes kimenet `wp_kses`-en megy át (csak egy <a> engedett), a
     * behelyettesített értékek külön escape-eltek.
     */
    public static function render_footer(): void {
        echo wp_kses(
            str_replace(
                'Qaiyo by PixelDesigns',
                '<a href="' . esc_url( 'https://qaiyo-plugins.com' ) . '" target="_blank" rel="noopener noreferrer">Qaiyo</a>',
                sprintf(
                    /* translators: 1: plugin name, 2: version number. */
                    esc_html__( 'Qaiyo %1$s v%2$s — Made by Qaiyo by PixelDesigns', 'qaiyo-admin-booster' ),
                    esc_html__( 'Admin Booster', 'qaiyo-admin-booster' ),
                    esc_html( QWAB_VERSION )
                )
            ),
            array(
                'a' => array(
                    'href'   => array(),
                    'target' => array(),
                    'rel'    => array(),
                ),
            )
        );
    }

    public static function svg_allowed_html(): array {
        $shape = array(
            'fill'             => true,
            'stroke'           => true,
            'stroke-width'     => true,
            'stroke-linecap'   => true,
            'stroke-linejoin'  => true,
        );
        return array(
            'svg'      => $shape + array( 'width' => true, 'height' => true, 'viewbox' => true, 'xmlns' => true, 'class' => true, 'aria-hidden' => true, 'focusable' => true ),
            'path'     => $shape + array( 'd' => true ),
            'polyline' => $shape + array( 'points' => true ),
            'polygon'  => $shape + array( 'points' => true ),
            'rect'     => $shape + array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ),
            'circle'   => $shape + array( 'cx' => true, 'cy' => true, 'r' => true ),
            'ellipse'  => $shape + array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ),
            'line'     => $shape + array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ),
            'g'        => $shape,
        );
    }

    private function card_open( $icon_svg, $title, $subtitle, $pro_pill = false ): void {
        ?>
        <div class="qwab-card">
            <h2 class="qwab-card__title">
                <span class="qwab-card__title-icon"><?php echo wp_kses( $icon_svg, self::svg_allowed_html() ); ?></span>
                <?php echo esc_html( $title ); ?>
                <?php if ( $pro_pill ) : ?><span class="qwab-pro-pill">PRO</span><?php endif; ?>
            </h2>
            <?php if ( $subtitle ) : ?><p class="qwab-card__subtitle"><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
        <?php
    }

    private function card_close(): void {
        echo '</div>';
    }

    /**
     * Egy be/ki kapcsoló mező.
     */
    private function toggle( $name, $checked, $label, $sub = '' ): void {
        ?>
        <div class="qwab-field">
            <label class="qwab-toggle">
                <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( 1, (int) $checked ); ?> />
                <span class="qwab-toggle__slider"></span>
                <span class="qwab-toggle__label">
                    <?php echo esc_html( $label ); ?>
                    <?php if ( $sub ) : ?><span class="qwab-toggle__sub"><?php echo esc_html( $sub ); ?></span><?php endif; ?>
                </span>
            </label>
        </div>
        <?php
    }

    private function card_modules( $s ): void {
        $modules = array(
            'plugin_upload'    => array( __( '1-click plugin upload', 'qaiyo-admin-booster' ), __( 'Open the plugin upload screen straight from “Add Plugin”.', 'qaiyo-admin-booster' ) ),
            'gutenberg'        => array( __( 'Gutenberg UX fixes', 'qaiyo-admin-booster' ), __( 'Keep the block inserter open and the editor out of fullscreen.', 'qaiyo-admin-booster' ) ),
            'quick_add'        => array( __( 'Quick add menu', 'qaiyo-admin-booster' ), __( 'New Page / Post / Product shortcuts in the admin bar.', 'qaiyo-admin-booster' ) ),
            'list_per_page'    => array( __( 'Larger list views', 'qaiyo-admin-booster' ), __( 'Show more items per page in admin list tables.', 'qaiyo-admin-booster' ) ),
            'uploads'          => array( __( 'File handling', 'qaiyo-admin-booster' ), __( 'Upload size, allowed file types and clearer errors.', 'qaiyo-admin-booster' ) ),
            'page_collections' => array( __( 'Page Collections', 'qaiyo-admin-booster' ), __( 'Group pages and posts into visual collections.', 'qaiyo-admin-booster' ) ),
            'page_filter'      => array( __( 'Page Filter', 'qaiyo-admin-booster' ), __( 'See homepage, orphan, not-in-menu and noindex at a glance.', 'qaiyo-admin-booster' ) ),
            'admin_notices'       => array( __( 'Tidy admin notices', 'qaiyo-admin-booster' ), __( 'Collapse stacked admin notices into a small counter tray.', 'qaiyo-admin-booster' ) ),
            'dashboard_cleanup'   => array( __( 'Dashboard cleanup', 'qaiyo-admin-booster' ), __( 'Hide the dashboard widgets you never use.', 'qaiyo-admin-booster' ) ),
            'scheduled_countdown' => array( __( 'Scheduled countdown', 'qaiyo-admin-booster' ), __( 'Show “Publishing in 3 days” on scheduled posts.', 'qaiyo-admin-booster' ) ),
            'sticky_headers'      => array( __( 'Sticky table headers', 'qaiyo-admin-booster' ), __( 'Keep list-table column headers visible while scrolling.', 'qaiyo-admin-booster' ) ),
            'last_editor'         => array( __( 'Last edited column', 'qaiyo-admin-booster' ), __( 'Who last edited each item and how long ago.', 'qaiyo-admin-booster' ) ),
            'quick_status'        => array( __( 'Quick status switch', 'qaiyo-admin-booster' ), __( 'Publish or unpublish from the list without opening the editor.', 'qaiyo-admin-booster' ) ),
            'bulk_create'         => array( __( 'Bulk create', 'qaiyo-admin-booster' ), __( 'Create many pages or posts at once from a list of titles.', 'qaiyo-admin-booster' ) ),
            'update_center'       => array( __( 'Update center widget', 'qaiyo-admin-booster' ), __( 'A dashboard widget to update plugins and themes in one place.', 'qaiyo-admin-booster' ) ),
            'comments_widget'     => array( __( 'Comments widget', 'qaiyo-admin-booster' ), __( 'A dashboard widget with comment counts and one-click / bulk trashing.', 'qaiyo-admin-booster' ) ),
            'dashboard_greeting'  => array( __( 'Dashboard greeting', 'qaiyo-admin-booster' ), __( 'Show a personal greeting at the top of the main WordPress dashboard.', 'qaiyo-admin-booster' ) ),
            'menu_manager'        => array( __( 'Add to menu from the editor', 'qaiyo-admin-booster' ), __( 'Put a page or post into a navigation menu straight from the editor sidebar.', 'qaiyo-admin-booster' ) ),
            'menu_visibility'     => array( __( 'Menu item visibility', 'qaiyo-admin-booster' ), __( 'Show a menu item on mobile only or on desktop only — handy for a header button that needs to live in the mobile menu too.', 'qaiyo-admin-booster' ) ),
            'ecosystem_widgets'   => array( __( 'Qaiyo ecosystem widget', 'qaiyo-admin-booster' ), __( 'Collect summary cards from your other Qaiyo plugins into one dashboard widget.', 'qaiyo-admin-booster' ) ),
            'plugin_update_groups' => array( __( 'Separate table for pending updates', 'qaiyo-admin-booster' ), __( 'On the Plugins screen, list plugins with an available update in their own table at the top, and every other plugin in a separate table below it.', 'qaiyo-admin-booster' ) ),
            'update_guard'         => array( __( 'Update crash protection', 'qaiyo-admin-booster' ), __( 'If a plugin or theme update crashes the site, automatically restore the previous version — no matter what triggered the update.', 'qaiyo-admin-booster' ) ),
            'safe_mode'            => array( __( 'Safe Mode link', 'qaiyo-admin-booster' ), __( 'A secret link, saved in advance, that gets you back into wp-admin when a plugin or theme update breaks the site.', 'qaiyo-admin-booster' ) ),
            'update_notifications' => array( __( 'Update notification emails', 'qaiyo-admin-booster' ), __( 'Email every administrator a daily or weekly digest of the plugin, theme and WordPress updates that are waiting.', 'qaiyo-admin-booster' ) ),
        );

        $svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>';
        $this->card_open( $svg, __( 'Modules', 'qaiyo-admin-booster' ), __( 'Turn each booster on or off. Everything is on by default, except the Safe Mode link.', 'qaiyo-admin-booster' ) );
        ?>
        <div class="qwab-modules-bulk">
            <button type="button" class="button qwab-modules-all" data-check="1"><?php esc_html_e( 'Enable all', 'qaiyo-admin-booster' ); ?></button>
            <button type="button" class="button qwab-modules-all" data-check="0"><?php esc_html_e( 'Disable all', 'qaiyo-admin-booster' ); ?></button>
        </div>
        <?php
        echo '<div class="qwab-modules-grid">';
        foreach ( $modules as $slug => $m ) {
            $checked = ! empty( $s['modules'][ $slug ] );
            ?>
            <label class="qwab-module-toggle">
                <input type="checkbox" name="qwab_settings[modules][<?php echo esc_attr( $slug ); ?>]" value="1" <?php checked( true, $checked ); ?> />
                <span class="qwab-module-toggle__box">
                    <span class="qwab-module-toggle__name"><?php echo esc_html( $m[0] ); ?></span>
                    <span class="qwab-module-toggle__desc"><?php echo esc_html( $m[1] ); ?></span>
                </span>
            </label>
            <?php
        }

        // Pro modulok a rácsban (a settings-mentes, csak be/ki Pro funkciók).
        // Ha a Pro aktív + feloldott → valódi kapcsoló (a Pro Loader ezt olvassa).
        // Egyébként lakatos „PRO" kártya az upgrade oldalra mutatva.
        $pro_modules = array(
            'dark-mode'    => array( __( 'Admin dark mode', 'qaiyo-admin-booster' ), __( 'A per-user dark theme for wp-admin, toggled from the admin bar.', 'qaiyo-admin-booster' ) ),
            'svg-sanitize' => array( __( 'SVG sanitization', 'qaiyo-admin-booster' ), __( 'Automatically strip scripts from uploaded SVG files.', 'qaiyo-admin-booster' ) ),
            'bulk-seo'     => array( __( 'Bulk noindex actions', 'qaiyo-admin-booster' ), __( 'Set or remove noindex on many pages or posts at once.', 'qaiyo-admin-booster' ) ),
            'pinned-pages' => array( __( 'Pinned pages & favorites', 'qaiyo-admin-booster' ), __( 'Pin pages to a quick admin-bar menu and a dashboard widget.', 'qaiyo-admin-booster' ) ),
            'core-updater' => array( __( 'Core update bridge', 'qaiyo-admin-booster' ), __( 'Step up to an intermediate WordPress version your server can still run.', 'qaiyo-admin-booster' ) ),
        );
        $upgrade   = qwab_upgrade_url();
        // Ha a Pro PLUGIN aktív (akár licenc nélkül is) → valódi kapcsoló a
        // rácsban. A tényleges futtatást a Pro Loader gate-eli licenc szerint;
        // itt csak a felhasználói preferenciát tároljuk. Pro nélkül → lakatos.
        $pro_active = qwab_is_pro_active();
        foreach ( $pro_modules as $slug => $m ) {
            if ( $pro_active ) {
                $checked = ! empty( $s['modules'][ $slug ] );
                ?>
                <label class="qwab-module-toggle">
                    <input type="checkbox" name="qwab_settings[modules][<?php echo esc_attr( $slug ); ?>]" value="1" <?php checked( true, $checked ); ?> />
                    <span class="qwab-module-toggle__box">
                        <span class="qwab-module-toggle__name"><?php echo esc_html( $m[0] ); ?> <span class="qwab-pro-pill qwab-pro-pill--mini">PRO</span></span>
                        <span class="qwab-module-toggle__desc"><?php echo esc_html( $m[1] ); ?></span>
                    </span>
                </label>
                <?php
            } else {
                // Lakatos: a kártya az upgrade-re visz, nem kapcsolható. A jelenlegi
                // (alapból ON) értéket rejtett mezővel megőrizzük, hogy mentéskor ne
                // íródjon false-ra → aktiváláskor bekapcsolva induljon.
                $keep = ! empty( $s['modules'][ $slug ] );
                ?>
                <a class="qwab-module-toggle qwab-module-toggle--locked" href="<?php echo esc_url( $upgrade ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php if ( $keep ) : ?><input type="hidden" name="qwab_settings[modules][<?php echo esc_attr( $slug ); ?>]" value="1" /><?php endif; ?>
                    <span class="qwab-module-toggle__box">
                        <span class="qwab-module-toggle__name"><?php echo esc_html( $m[0] ); ?> <span class="qwab-pro-pill qwab-pro-pill--mini">PRO</span></span>
                        <span class="qwab-module-toggle__desc"><?php echo esc_html( $m[1] ); ?></span>
                        <span class="qwab-module-locked-hint"><?php esc_html_e( 'Unlock in Pro →', 'qaiyo-admin-booster' ); ?></span>
                    </span>
                </a>
                <?php
            }
        }
        echo '</div>';
        $this->card_close();
    }

    private function card_navigation( $s ): void {
        $svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"></polyline><polyline points="6 17 11 12 6 7"></polyline></svg>';
        $this->card_open( $svg, __( 'Navigation & editor', 'qaiyo-admin-booster' ), __( 'Fewer clicks to the things you do all day.', 'qaiyo-admin-booster' ) );

        $this->toggle( 'qwab_settings[plugin_upload_redirect]', $s['plugin_upload_redirect'], __( 'Show plugin upload immediately', 'qaiyo-admin-booster' ), __( '“Add Plugin” opens the upload tab directly — the browse tabs stay available on top.', 'qaiyo-admin-booster' ) );
        $this->toggle( 'qwab_settings[gutenberg_open_inserter]', $s['gutenberg_open_inserter'], __( 'Open the block inserter by default', 'qaiyo-admin-booster' ), __( 'The left “+” block panel is open when the editor loads.', 'qaiyo-admin-booster' ) );
        $this->toggle( 'qwab_settings[gutenberg_disable_fullscreen]', $s['gutenberg_disable_fullscreen'], __( 'Disable editor fullscreen mode', 'qaiyo-admin-booster' ), __( 'Keep the wp-admin sidebar visible while editing.', 'qaiyo-admin-booster' ) );

        // Quick add post types.
        ?>
        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Quick add shortcuts', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-field__desc"><?php esc_html_e( 'Which content types appear in the admin bar “Quick Add” menu.', 'qaiyo-admin-booster' ); ?></span>
            </span>
            <div class="qwab-checklist">
                <?php $this->post_type_checklist( 'qwab_settings[quick_add_post_types]', (array) $s['quick_add_post_types'] ); ?>
            </div>
        </div>

        <div class="qwab-field">
            <label class="qwab-field__label" for="qwab_list_per_page"><?php esc_html_e( 'Items per list page', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-field__desc"><?php esc_html_e( 'Default rows in admin list tables (only when you have not set your own Screen Option).', 'qaiyo-admin-booster' ); ?></span>
            </label>
            <input type="number" id="qwab_list_per_page" class="small-text" name="qwab_settings[list_per_page_count]" value="<?php echo esc_attr( $s['list_per_page_count'] ); ?>" min="1" max="999" />
        </div>

        <?php if ( Qwab_Settings::is_module_enabled( 'menu_visibility' ) ) : ?>
        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Menu item visibility', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-field__desc">
                    <?php
                    printf(
                        wp_kses(
                            /* translators: %s: link to the Pro upgrade page. */
                            __( 'Set per menu item on the Appearance → Menus screen. The breakpoint is fixed at 768px; %s for a custom value.', 'qaiyo-admin-booster' ),
                            array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
                        ),
                        '<a href="' . esc_url( qwab_upgrade_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'upgrade to Pro', 'qaiyo-admin-booster' ) . '</a>'
                    );
                    ?>
                </span>
            </span>
        </div>
        <?php endif; ?>
        <?php
        $this->card_close();
    }

    /**
     * Frissítési e-mail értesítések kártya.
     */
    /**
     * Safe Mode (vészbejárat) kártya — a beállítás-űrlapon KÍVÜL, mert saját
     * (admin-post) űrlapjai vannak.
     */
    /**
     * Frissítés-védelem kártya — csak a napló megjelenítése, beállítás
     * nincs (a modul kapcsolóján kívül, ami a Modulok fülön van).
     */
    private function card_update_guard(): void {
        $svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"></path><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>';
        $this->card_open(
            $svg,
            __( 'Update crash protection', 'qaiyo-admin-booster' ),
            __( 'WordPress backs up the previous version before every plugin or theme update, but only checks whether the site still works — and restores that backup if not — for updates it runs itself in the background. A manual click, WP-CLI, or a remote manager like ManageWP or MainWP skips that check. This module runs the same check after every update, whoever triggered it, and restores the backup automatically if the site crashed.', 'qaiyo-admin-booster' )
        );

        if ( ! Qwab_Settings::is_module_enabled( 'update_guard' ) ) {
            echo '<p class="qwab-notify-info">' . esc_html__( 'Off. Turn on “Update crash protection” on the Modules tab to enable it.', 'qaiyo-admin-booster' ) . '</p>';
            $this->card_close();
            return;
        }

        $unverified = Qwab_Module_Update_Guard::unverified_since();
        if ( $unverified ) {
            echo '<p class="qwab-notify-error">' . esc_html(
                sprintf(
                    /* translators: %s: date and time of the last update that could not be checked. */
                    __( 'Protection could not work during the last update (%s): this server cannot load its own pages (loopback requests are blocked), so there is no way to tell whether an update broke the site. Ask your host to allow the site to reach itself; Site Health will show the same problem. This warning disappears after the next update that can be checked.', 'qaiyo-admin-booster' ),
                    wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $unverified )
                )
            ) . '</p>';
        }

        $log = Qwab_Module_Update_Guard::log();
        if ( ! $log && $unverified ) {
            $this->card_close();
            return;
        }
        if ( ! $log ) {
            echo '<p class="qwab-notify-ok">' . esc_html__( 'On. No update has needed to be rolled back so far.', 'qaiyo-admin-booster' ) . '</p>';
            $this->card_close();
            return;
        }
        ?>
        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Interventions', 'qaiyo-admin-booster' ); ?></span>
            <div class="qwab-safe-mode-log-wrap">
                <table class="widefat striped qwab-safe-mode-log">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'When', 'qaiyo-admin-booster' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Affected', 'qaiyo-admin-booster' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Result', 'qaiyo-admin-booster' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) : ?>
                            <tr class="<?php echo empty( $entry['healthy'] ) ? 'qwab-safe-mode-log--warn' : ''; ?>">
                                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
                                <td><?php echo esc_html( implode( ', ', (array) $entry['items'] ) ); ?></td>
                                <td>
                                    <?php if ( ! empty( $entry['healthy'] ) ) : ?>
                                        <?php esc_html_e( 'Restored automatically', 'qaiyo-admin-booster' ); ?>
                                    <?php else : ?>
                                        <?php esc_html_e( 'Could not restore automatically', 'qaiyo-admin-booster' ); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        $this->card_close();
    }

    private function card_safe_mode(): void {
        $svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>';
        $this->card_open(
            $svg,
            __( 'Safe Mode — emergency access', 'qaiyo-admin-booster' ),
            __( 'When a plugin or theme update breaks the site, WordPress only emails a one-time recovery link — once a day, and never on multisite. If that email is lost, you are locked out. Safe Mode gives you a link you save now, before anything goes wrong.', 'qaiyo-admin-booster' )
        );

        if ( ! Qwab_Safe_Mode::current_user_may() ) {
            echo '<p class="qwab-field__desc">' . esc_html__( 'Only a network administrator can manage Safe Mode.', 'qaiyo-admin-booster' ) . '</p>';
            $this->card_close();
            return;
        }

        if ( ! Qwab_Safe_Mode::enabled() ) {
            echo '<p class="qwab-notify-info">' . esc_html__( 'Safe Mode is off. Turn on “Safe Mode link” on the Modules tab and save to get your link.', 'qaiyo-admin-booster' ) . '</p>';
            $this->card_close();
            return;
        }

        if ( ! Qwab_Safe_Mode::is_installed() ) {
            $result = Qwab_Safe_Mode::sync( true );
            if ( ! Qwab_Safe_Mode::is_installed() ) {
                echo '<p class="qwab-notify-error">' . esc_html( is_wp_error( $result ) ? $result->get_error_message() : __( 'The Safe Mode file could not be installed.', 'qaiyo-admin-booster' ) ) . '</p>';
                $this->card_close();
                return;
            }
        }

        $reveal = Qwab_Safe_Mode::take_reveal();
        $meta   = Qwab_Safe_Mode::meta();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Csak megjelenítés a saját, nonce-cal védett átirányításaink után.
        $ip_error  = isset( $_GET['qwab_sm_ip_error'] ) ? sanitize_text_field( wp_unslash( $_GET['qwab_sm_ip_error'] ) ) : '';
        $ips_saved = isset( $_GET['qwab_sm_ips_saved'] );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Your Safe Mode link', 'qaiyo-admin-booster' ); ?></span>

            <?php if ( '' !== $reveal ) : ?>
                <p class="qwab-notify-error"><strong><?php esc_html_e( 'Save this link now — it is shown only this once.', 'qaiyo-admin-booster' ); ?></strong> <?php esc_html_e( 'For security, only a fingerprint of the key is stored, so the link cannot be displayed again. If you lose it, create a new one.', 'qaiyo-admin-booster' ); ?></p>
                <div class="qwab-safe-mode-url">
                    <input type="text" id="qwab_safe_mode_url" class="large-text code" readonly value="<?php echo esc_attr( $reveal ); ?>" />
                    <button type="button" class="button" id="qwab-safe-mode-copy" data-copied="<?php esc_attr_e( 'Copied', 'qaiyo-admin-booster' ); ?>"><?php esc_html_e( 'Copy', 'qaiyo-admin-booster' ); ?></button>
                </div>
                <p class="qwab-field__desc"><?php esc_html_e( 'Put it in your password manager, next to this site’s login. It is useless if you only look for it after the site is down.', 'qaiyo-admin-booster' ); ?></p>
            <?php elseif ( Qwab_Safe_Mode::has_link() ) : ?>
                <p class="qwab-notify-ok">
                    <?php
                    printf(
                        /* translators: 1: date and time, 2: username. */
                        esc_html__( 'A link is active — created on %1$s by %2$s. It cannot be shown again; if nobody saved it, create a new one.', 'qaiyo-admin-booster' ),
                        esc_html( isset( $meta['time'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $meta['time'] ) : '—' ),
                        esc_html( isset( $meta['user'] ) ? $meta['user'] : '—' )
                    );
                    ?>
                </p>
            <?php else : ?>
                <p class="qwab-notify-error"><?php esc_html_e( 'There is no active link — Safe Mode cannot be entered. Create one and save it.', 'qaiyo-admin-booster' ); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qwab-safe-mode-create">
                <input type="hidden" name="action" value="qwab_safe_mode_create" />
                <?php wp_nonce_field( 'qwab_safe_mode_create' ); ?>
                <button type="submit" class="button<?php echo Qwab_Safe_Mode::has_link() ? '' : ' button-primary'; ?>"><?php esc_html_e( 'Create a new link', 'qaiyo-admin-booster' ); ?></button>
                <?php if ( Qwab_Safe_Mode::has_link() ) : ?>
                    <span class="qwab-field__desc"><?php esc_html_e( 'The current link and any open Safe Mode session stop working immediately.', 'qaiyo-admin-booster' ); ?></span>
                <?php endif; ?>
            </form>

            <?php if ( ! Qwab_Safe_Mode::config_keys_strong() ) : ?>
                <p class="qwab-notify-error"><?php esc_html_e( 'Your wp-config.php has no unique SECURE_AUTH_KEY / SECURE_AUTH_SALT, so the key fingerprint is protected by keys stored in the database. Add unique keys to wp-config.php (api.wordpress.org/secret-key/1.1/salt) for full protection, then create a new link.', 'qaiyo-admin-booster' ); ?></p>
            <?php endif; ?>
        </div>

        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'When the site is broken', 'qaiyo-admin-booster' ); ?></span>
            <ol class="qwab-safe-mode-steps">
                <li><?php esc_html_e( 'Open the saved link — it works even when the site only shows “There has been a critical error”.', 'qaiyo-admin-booster' ); ?></li>
                <li><?php esc_html_e( 'Log in with an administrator account. A password is required even if you were already logged in.', 'qaiyo-admin-booster' ); ?></li>
                <li><?php esc_html_e( 'Go to Plugins and deactivate the plugin that was just updated (or switch themes under Appearance).', 'qaiyo-admin-booster' ); ?></li>
                <li><?php esc_html_e( 'Click “Exit Safe Mode” in the red bar. Once the admin loads normally again, the used link is retired and you are asked to create a new one.', 'qaiyo-admin-booster' ); ?></li>
            </ol>
        </div>

        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'How the link is protected', 'qaiyo-admin-booster' ); ?></span>
            <ul class="qwab-safe-mode-protections">
                <li><?php esc_html_e( 'The key sits after the # in the link, which browsers never send to the server — it cannot end up in server, CDN or proxy logs.', 'qaiyo-admin-booster' ); ?></li>
                <li><?php esc_html_e( 'Only a fingerprint is stored, keyed with your wp-config.php security keys: a leaked or stolen database does not reveal the link.', 'qaiyo-admin-booster' ); ?></li>
                <li><?php esc_html_e( 'The link alone is not enough: only administrators can log in, with their password, and a built-in limit locks logins for 15 minutes after 5 failures.', 'qaiyo-admin-booster' ); ?></li>
                <li><?php esc_html_e( 'Safe Mode is a recovery console only: Dashboard, Plugins and Themes. Installing or uploading plugins and themes, editing files, and adding or changing users are all blocked.', 'qaiyo-admin-booster' ); ?></li>
                <li><?php esc_html_e( 'Every use emails all administrators, is logged with IP address and browser, and retires the link once the site works again.', 'qaiyo-admin-booster' ); ?></li>
                <li><?php esc_html_e( 'On an HTTPS site the key is only accepted over HTTPS; the session is tied to the browser and ends after one hour. Visitors always see the normal site.', 'qaiyo-admin-booster' ); ?></li>
            </ul>
        </div>

        <div class="qwab-field">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="qwab_safe_mode_ips" />
                <?php wp_nonce_field( 'qwab_safe_mode_ips' ); ?>
                <label class="qwab-field__label" for="qwab_safe_mode_ips"><?php esc_html_e( 'Only allow from these IP addresses (optional)', 'qaiyo-admin-booster' ); ?>
                    <span class="qwab-field__desc">
                        <?php
                        printf(
                            /* translators: %s: the current visitor's IP address. */
                            esc_html__( 'One IP address or range per line (e.g. 203.0.113.7 or 203.0.113.0/24). Leave empty to allow any address. Only use this with a fixed office or VPN address — otherwise you can lock yourself out. Your current address: %s', 'qaiyo-admin-booster' ),
                            '<code>' . esc_html( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '—' ) . '</code>'
                        );
                        ?>
                    </span>
                </label>
                <textarea id="qwab_safe_mode_ips" name="qwab_safe_mode_ips" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", Qwab_Safe_Mode::ips() ) ); ?></textarea>
                <?php if ( '' !== $ip_error ) : ?>
                    <p class="qwab-notify-error">
                        <?php
                        /* translators: %s: invalid entries. */
                        printf( esc_html__( 'Not saved — these entries are not valid IP addresses or ranges: %s', 'qaiyo-admin-booster' ), esc_html( $ip_error ) );
                        ?>
                    </p>
                <?php elseif ( $ips_saved ) : ?>
                    <p class="qwab-notify-ok"><?php esc_html_e( 'IP restriction saved.', 'qaiyo-admin-booster' ); ?></p>
                <?php endif; ?>
                <p><button type="submit" class="button"><?php esc_html_e( 'Save IP restriction', 'qaiyo-admin-booster' ); ?></button></p>
            </form>
        </div>

        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Safe Mode log', 'qaiyo-admin-booster' ); ?></span>
            <?php
            $log    = Qwab_Safe_Mode::log();
            $labels = array(
                'entry'           => __( 'Entered Safe Mode', 'qaiyo-admin-booster' ),
                'bad_key'         => __( 'Wrong key', 'qaiyo-admin-booster' ),
                'ip_denied'       => __( 'Blocked address', 'qaiyo-admin-booster' ),
                'login'           => __( 'Logged in', 'qaiyo-admin-booster' ),
                'login_failed'    => __( 'Failed login', 'qaiyo-admin-booster' ),
                'login_locked'    => __( 'Logins locked', 'qaiyo-admin-booster' ),
                'login_not_admin' => __( 'Non-administrator refused', 'qaiyo-admin-booster' ),
            );
            ?>
            <?php if ( ! $log ) : ?>
                <p class="qwab-field__desc"><?php esc_html_e( 'Nothing logged yet.', 'qaiyo-admin-booster' ); ?></p>
            <?php else : ?>
                <div class="qwab-safe-mode-log-wrap">
                    <table class="widefat striped qwab-safe-mode-log">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'When', 'qaiyo-admin-booster' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Event', 'qaiyo-admin-booster' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'User', 'qaiyo-admin-booster' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'IP address', 'qaiyo-admin-booster' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Browser', 'qaiyo-admin-booster' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $log as $entry ) : ?>
                                <?php $type = isset( $entry['type'] ) ? (string) $entry['type'] : ''; ?>
                                <tr class="<?php echo in_array( $type, array( 'bad_key', 'ip_denied', 'login_failed', 'login_locked', 'login_not_admin' ), true ) ? 'qwab-safe-mode-log--warn' : ''; ?>">
                                    <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
                                    <td><?php echo esc_html( isset( $labels[ $type ] ) ? $labels[ $type ] : $type ); ?></td>
                                    <td><?php echo esc_html( ! empty( $entry['user'] ) ? $entry['user'] : '—' ); ?></td>
                                    <td><code><?php echo esc_html( $entry['ip'] ); ?></code></td>
                                    <td><?php echo esc_html( $entry['ua'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $this->card_close();
    }

    private function card_notifications( $s ): void {
        $svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"></path><polyline points="4 7 12 13 20 7"></polyline></svg>';
        $this->card_open(
            $svg,
            __( 'Update notification emails', 'qaiyo-admin-booster' ),
            __( 'Get an email when a plugin, theme or WordPress update becomes available. Requires the “Update notification emails” module.', 'qaiyo-admin-booster' )
        );

        $frequency   = Qwab_Module_Update_Notifications::frequency();
        $frequencies = Qwab_Module_Update_Notifications::frequencies();
        $scope       = (array) ( isset( $s['update_notifications_scope'] ) ? $s['update_notifications_scope'] : array() );
        $recipients  = Qwab_Module_Update_Notifications::recipients();
        $mail_error  = Qwab_Module_Update_Notifications::last_mail_error();
        $next_run    = wp_next_scheduled( Qwab_Module_Update_Notifications::CRON_HOOK );

        $types = array(
            'core'    => __( 'WordPress core', 'qaiyo-admin-booster' ),
            'plugins' => __( 'Plugins', 'qaiyo-admin-booster' ),
            'themes'  => __( 'Themes', 'qaiyo-admin-booster' ),
        );
        ?>
        <div class="qwab-field">
            <label class="qwab-field__label" for="qwab_notify_frequency">
                <?php esc_html_e( 'How often', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-field__desc"><?php esc_html_e( 'One summary email per period — never one email per update check. Nothing is sent when there is nothing new.', 'qaiyo-admin-booster' ); ?></span>
            </label>
            <select id="qwab_notify_frequency" name="qwab_settings[update_notifications_frequency]">
                <?php foreach ( $frequencies as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $frequency, $key ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Notify me about', 'qaiyo-admin-booster' ); ?></span>
            <div class="qwab-checklist">
                <?php foreach ( $types as $key => $label ) : ?>
                    <label class="qwab-check">
                        <input type="checkbox" name="qwab_settings[update_notifications_scope][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $scope, true ) ); ?> />
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Recipients', 'qaiyo-admin-booster' ); ?></span>
            <p class="qwab-field__desc">
                <?php
                printf(
                    /* translators: %s: comma-separated list of administrator email addresses. */
                    esc_html__( 'Every administrator of this site: %s', 'qaiyo-admin-booster' ),
                    esc_html( implode( ', ', $recipients ) )
                );
                ?>
            </p>
            <?php if ( $next_run ) : ?>
                <p class="qwab-field__desc">
                    <?php
                    printf(
                        /* translators: %s: human-readable time difference, e.g. "3 hours". */
                        esc_html__( 'Next summary is due in %s.', 'qaiyo-admin-booster' ),
                        esc_html( human_time_diff( time(), $next_run ) )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Is mail working?', 'qaiyo-admin-booster' ); ?></span>
            <p class="qwab-field__desc">
                <?php esc_html_e( 'WordPress hands the email to your server or SMTP plugin; it cannot guarantee delivery. Send yourself a test to make sure mail actually leaves this site.', 'qaiyo-admin-booster' ); ?>
            </p>
            <p>
                <button type="button" class="button" id="qwab-test-mail"><?php esc_html_e( 'Send test email', 'qaiyo-admin-booster' ); ?></button>
                <span class="qwab-test-mail-result" role="status" aria-live="polite"></span>
            </p>
            <?php if ( $mail_error ) : ?>
                <p class="qwab-notify-error">
                    <?php
                    printf(
                        /* translators: 1: number of failed recipients, 2: total number of recipients, 3: human-readable time difference, e.g. "2 hours". */
                        esc_html__( 'The last summary could not be handed over for %1$d of %2$d recipients (%3$s ago). Check your hosting mail settings or install an SMTP plugin.', 'qaiyo-admin-booster' ),
                        (int) $mail_error['failed'],
                        (int) $mail_error['total'],
                        esc_html( human_time_diff( (int) $mail_error['time'], time() ) )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        $this->card_close();
    }

    private function card_dashboard( $s ): void {
        $svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>';
        $this->card_open( $svg, __( 'Dashboard cleanup', 'qaiyo-admin-booster' ), __( 'Hide the default dashboard widgets you do not use. Requires the “Dashboard cleanup” module.', 'qaiyo-admin-booster' ) );

        $selected = (array) ( isset( $s['dashboard_cleanup_widgets'] ) ? $s['dashboard_cleanup_widgets'] : array() );
        ?>
        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Hide these widgets', 'qaiyo-admin-booster' ); ?></span>
            <div class="qwab-checklist">
                <?php foreach ( Qwab_Module_Dashboard_Cleanup::known_widgets() as $id => $label ) : ?>
                    <label class="qwab-check">
                        <input type="checkbox" name="qwab_settings[dashboard_cleanup_widgets][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $selected, true ) ); ?> />
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        $this->card_close();
    }

    private function card_files( $s ): void {
        $svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';
        $this->card_open( $svg, __( 'File handling', 'qaiyo-admin-booster' ), __( 'Control upload size and which file types are allowed.', 'qaiyo-admin-booster' ) );

        $server_bytes = Qwab_Module_Uploads::server_limit();
        $server_limit = size_format( $server_bytes );
        $wanted_mb    = (int) $s['upload_max_size_mb'];
        $env          = Qwab_Server_Limits::detect();
        $raise_status = Qwab_Server_Limits::status();
        $may_raise    = Qwab_Server_Limits::current_user_may();
        $over_server  = $server_bytes > 0 && $wanted_mb * MB_IN_BYTES > $server_bytes;
        ?>
        <div class="qwab-field">
            <label class="qwab-field__label" for="qwab_upload_size"><?php esc_html_e( 'Max upload size (MB)', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-field__desc">
                    <?php
                    printf(
                        /* translators: %s: upload limit the server currently accepts, e.g. 8 MB. */
                        esc_html__( '0 = use the server limit. The server currently accepts files up to %s.', 'qaiyo-admin-booster' ),
                        esc_html( $server_limit )
                    );
                    ?>
                </span>
            </label>
            <input type="number" id="qwab_upload_size" class="small-text" name="qwab_settings[upload_max_size_mb]" value="<?php echo esc_attr( $s['upload_max_size_mb'] ); ?>" min="0" max="5120" />
        </div>

        <?php
        if ( $env['targets'] && $may_raise ) {
            $this->toggle(
                'qwab_settings[upload_raise_server]',
                $s['upload_raise_server'],
                __( 'Raise the server limit too', 'qaiyo-admin-booster' ),
                sprintf(
                    /* translators: %s: one or two file paths, e.g. /var/www/html/.htaccess + /var/www/html/.user.ini. */
                    __( 'No cPanel or FTP needed. PHP cannot change this limit while WordPress is running (not even from wp-config.php), so Admin Booster writes it into %s, which your server reads before each request. It can take a few minutes to apply. Switching this off removes the lines again.', 'qaiyo-admin-booster' ),
                    implode( ' + ', $env['targets'] )
                )
            );
        }
        ?>

        <div class="qwab-field">
            <?php if ( 'active' === $raise_status['state'] ) : ?>
                <p class="qwab-notify-ok">
                    <?php
                    printf(
                        /* translators: %s: upload limit, e.g. 200 MB. */
                        esc_html__( 'Server limit raised: uploads up to %s are accepted now.', 'qaiyo-admin-booster' ),
                        esc_html( size_format( $raise_status['mb'] * MB_IN_BYTES ) )
                    );
                    ?>
                </p>
            <?php elseif ( 'pending' === $raise_status['state'] ) : ?>
                <p class="qwab-notify-info">
                    <?php
                    printf(
                        /* translators: 1: upload limit, e.g. 200 MB, 2: human-readable wait time, e.g. "4 mins". */
                        esc_html__( 'The %1$s limit has been written. PHP picks it up within %2$s — reload this page to check.', 'qaiyo-admin-booster' ),
                        esc_html( size_format( $raise_status['mb'] * MB_IN_BYTES ) ),
                        esc_html( human_time_diff( time(), time() + $raise_status['wait'] ) )
                    );
                    ?>
                </p>
            <?php elseif ( 'ignored' === $raise_status['state'] ) : ?>
                <p class="qwab-notify-error">
                    <?php
                    printf(
                        /* translators: 1: requested upload limit, e.g. 200 MB, 2: limit the server still applies, e.g. 8 MB. */
                        esc_html__( 'The %1$s limit was written, but the server still applies only %2$s. This host does not read these settings from the WordPress folder. The details below show exactly how PHP runs here — if you contact your host, send them this list.', 'qaiyo-admin-booster' ),
                        esc_html( size_format( $raise_status['mb'] * MB_IN_BYTES ) ),
                        esc_html( $server_limit )
                    );
                    ?>
                </p>
            <?php elseif ( $over_server ) : ?>
                <p class="qwab-notify-error">
                    <?php
                    if ( $env['targets'] && $may_raise ) {
                        printf(
                            /* translators: 1: upload size set in the plugin, e.g. 200 MB, 2: upload limit of the server, e.g. 8 MB. */
                            esc_html__( 'You set %1$s, but the server only accepts %2$s, so %2$s is what applies. Switch on “Raise the server limit too” and save.', 'qaiyo-admin-booster' ),
                            esc_html( size_format( $wanted_mb * MB_IN_BYTES ) ),
                            esc_html( $server_limit )
                        );
                    } else {
                        printf(
                            /* translators: 1: upload size set in the plugin, e.g. 200 MB, 2: upload limit of the server, e.g. 8 MB, 3: reason it cannot be raised from WordPress. */
                            esc_html__( 'You set %1$s, but the server only accepts %2$s, so %2$s is what applies. %3$s', 'qaiyo-admin-booster' ),
                            esc_html( size_format( $wanted_mb * MB_IN_BYTES ) ),
                            esc_html( $server_limit ),
                            esc_html( '' !== $env['reason'] ? $env['reason'] : __( 'Only a network administrator can change the server limit.', 'qaiyo-admin-booster' ) )
                        );
                    }
                    ?>
                </p>
            <?php endif; ?>
            <?php if ( in_array( $raise_status['state'], array( 'ignored', 'pending' ), true ) || ( $over_server && ! $env['targets'] ) ) : ?>
                <details class="qwab-diagnostics">
                    <summary><?php esc_html_e( 'Server details', 'qaiyo-admin-booster' ); ?></summary>
                    <table>
                        <?php foreach ( Qwab_Server_Limits::diagnostics() as $qwab_label => $qwab_value ) : ?>
                            <tr><th scope="row"><?php echo esc_html( $qwab_label ); ?></th><td><code><?php echo esc_html( $qwab_value ); ?></code></td></tr>
                        <?php endforeach; ?>
                    </table>
                </details>
            <?php endif; ?>
        </div>

        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Enable extra image types', 'qaiyo-admin-booster' ); ?></span>
        </div>
        <?php
        $this->toggle( 'qwab_settings[enable_webp]', $s['enable_webp'], 'WebP (.webp)', __( 'Modern, well-compressed image format. Safe to enable.', 'qaiyo-admin-booster' ) );
        $this->toggle( 'qwab_settings[enable_avif]', $s['enable_avif'], 'AVIF (.avif)', __( 'Next-gen image format (WordPress 6.5+ and supported servers).', 'qaiyo-admin-booster' ) );
        $this->toggle( 'qwab_settings[enable_svg]', $s['enable_svg'], 'SVG (.svg)', __( 'Vector graphics. SVG can contain scripts — only enable if you trust who uploads. Pro adds automatic SVG sanitization.', 'qaiyo-admin-booster' ) );
        ?>

        <div class="qwab-field">
            <label class="qwab-field__label" for="qwab_extra_mimes"><?php esc_html_e( 'Custom file types', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-field__desc"><?php esc_html_e( 'One per line, format: extension = mime/type. Example: ico = image/x-icon', 'qaiyo-admin-booster' ); ?></span>
            </label>
            <textarea id="qwab_extra_mimes" class="large-text code" rows="4" name="qwab_settings[extra_mimes_raw]" placeholder="ico = image/x-icon&#10;woff2 = font/woff2"><?php echo esc_textarea( $this->extra_mimes_to_text( $s['extra_mimes'] ) ); ?></textarea>
            <?php $this->dangerous_warning( $s['extra_mimes'] ); ?>
        </div>
        <?php
        $this->toggle( 'qwab_settings[friendly_errors]', $s['friendly_errors'], __( 'Friendlier upload error messages', 'qaiyo-admin-booster' ), __( 'Replace the cryptic “file type not permitted” message with a clear explanation and the list of allowed types.', 'qaiyo-admin-booster' ) );
        $this->toggle( 'qwab_settings[friendly_filenames]', $s['friendly_filenames'], __( 'Clean up uploaded filenames', 'qaiyo-admin-booster' ), __( 'Convert spaces, accents and uppercase in uploaded filenames to URL-friendly slugs (e.g. “Árvíz Tükör.JPG” → “arviz-tukor.jpg”).', 'qaiyo-admin-booster' ) );
        $this->toggle( 'qwab_settings[dangerous_warning]', $s['dangerous_warning'], __( 'Warn about dangerous file types', 'qaiyo-admin-booster' ), __( 'Show a warning here when you allow executable / script extensions.', 'qaiyo-admin-booster' ) );

        $this->card_close();
    }

    private function card_collections( $s ): void {
        $svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>';
        $this->card_open( $svg, __( 'Page Collections', 'qaiyo-admin-booster' ), __( 'Built-in collections (Main, Legal, WooCommerce) are detected automatically. Create your own below.', 'qaiyo-admin-booster' ) );
        ?>
        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Apply collections to', 'qaiyo-admin-booster' ); ?></span>
            <div class="qwab-checklist">
                <?php $this->post_type_checklist( 'qwab_settings[collections_post_types]', (array) $s['collections_post_types'] ); ?>
            </div>
        </div>

        <div class="qwab-field">
            <span class="qwab-field__label"><?php esc_html_e( 'Your collections', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-field__desc"><?php esc_html_e( 'These are saved separately and immediately — they do not require the main “Save settings” button.', 'qaiyo-admin-booster' ); ?></span>
            </span>
            <div id="qwab-collections-manager" class="qwab-collections-manager">
                <div id="qwab-collections-list" class="qwab-collections-list"></div>
                <div class="qwab-collections-actions">
                    <button type="button" class="button" id="qwab-add-collection">
                        <span class="dashicons dashicons-plus-alt2 qwab-ico-mid"></span>
                        <?php esc_html_e( 'Add collection', 'qaiyo-admin-booster' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="qwab-save-collections">
                        <?php esc_html_e( 'Save collections', 'qaiyo-admin-booster' ); ?>
                    </button>
                    <span class="qwab-collections-status" id="qwab-collections-status" aria-live="polite"></span>
                </div>
            </div>
        </div>
        <?php
        $this->card_close();
    }

    private function card_info(): void {
        ?>
        <div class="qwab-card qwab-info-card">
            <h2 class="qwab-card__title">
                <span class="qwab-card__title-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg></span>
                <?php esc_html_e( 'Where to find it', 'qaiyo-admin-booster' ); ?>
            </h2>
            <ul class="qwab-info-list">
                <li><span class="dashicons dashicons-category"></span><?php esc_html_e( 'Collection & status columns appear on the Pages and Posts list screens.', 'qaiyo-admin-booster' ); ?></li>
                <li><span class="dashicons dashicons-filter"></span><?php esc_html_e( 'Use the dropdowns above the list to filter by collection or page status.', 'qaiyo-admin-booster' ); ?></li>
                <li><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'The “Quick Add” menu lives in the top admin bar.', 'qaiyo-admin-booster' ); ?></li>
            </ul>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Apró helperek
     * ------------------------------------------------------------------- */

    private function post_type_checklist( $name, $selected ): void {
        $types = get_post_types( array( 'show_ui' => true ), 'objects' );
        $skip  = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_face', 'wp_font_family' );
        foreach ( $types as $type => $obj ) {
            if ( in_array( $type, $skip, true ) ) {
                continue;
            }
            ?>
            <label class="qwab-check">
                <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $selected, true ) ); ?> />
                <?php echo esc_html( $obj->labels->name ); ?>
            </label>
            <?php
        }
    }

    private function extra_mimes_to_text( $mimes ) {
        if ( ! is_array( $mimes ) || empty( $mimes ) ) {
            return '';
        }
        $lines = array();
        foreach ( $mimes as $ext => $mime ) {
            $lines[] = $ext . ' = ' . $mime;
        }
        return implode( "\n", $lines );
    }

    private function dangerous_warning( $mimes ): void {
        if ( ! Qwab_Settings::get( 'dangerous_warning', 1 ) || ! is_array( $mimes ) ) {
            return;
        }
        $dangerous = Qwab_Module_Uploads::dangerous_extensions();
        $hits      = array();
        foreach ( array_keys( $mimes ) as $ext ) {
            foreach ( explode( '|', $ext ) as $e ) {
                if ( in_array( $e, $dangerous, true ) ) {
                    $hits[] = $e;
                }
            }
        }
        if ( empty( $hits ) ) {
            return;
        }
        ?>
        <p class="qwab-danger-warning">
            <span class="dashicons dashicons-warning"></span>
            <?php
            printf(
                /* translators: %s: comma-separated list of risky file extensions. */
                esc_html__( 'Warning: you are allowing executable / script file types (%s). These can be used to run code on your server. Only keep them if you really need them.', 'qaiyo-admin-booster' ),
                '<strong>' . esc_html( implode( ', ', array_unique( $hits ) ) ) . '</strong>'
            );
            ?>
        </p>
        <?php
    }
}

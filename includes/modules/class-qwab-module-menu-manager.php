<?php
/**
 * Modul: Menühöz adás a szerkesztőből.
 *
 * A WordPressben egy frissen létrehozott oldal vagy bejegyzés csak úgy kerül
 * navigációs menübe, ha a szerkesztés után átmész a Megjelenés → Menük
 * képernyőre, megkeresed ott a tartalmat, és kézzel hozzáadod. Ez a modul ezt
 * a kerülőutat szünteti meg: a szerkesztő oldalsávjában egy panelről egy
 * kattintással menühöz adható az éppen szerkesztett tartalom — al-menüpontként
 * is —, és ha még egyetlen menü sincs, itt helyben létre lehet hozni.
 *
 * A panel klasszikus `side` meta box, ezért a blokk-szerkesztő oldalsávjában és
 * a klasszikus szerkesztőben is megjelenik. A meta boxok a szerkesztő
 * iframe-jén KÍVÜL renderelnek, így a WP 7.1-es iframe-változás nem érinti.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Menu_Manager {

    const NONCE = 'qwab_menu_manager';

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'wp_ajax_qwab_menu_action', array( $this, 'ajax_action' ) );
    }

    /* ---------------------------------------------------------------------
     * Jogosultság + poszttípusok
     * ------------------------------------------------------------------- */

    /**
     * Szerkeszthet-e a felhasználó menüket? A menükezelés külön képesség,
     * amivel egy szerkesztő nem feltétlenül rendelkezik.
     *
     * @return bool
     */
    private function user_can_edit_menus(): bool {
        return current_user_can( 'edit_theme_options' );
    }

    /**
     * Azok a poszttípusok, amelyek egyáltalán tehetők navigációs menübe.
     *
     * @return array<int,string>
     */
    private function post_types(): array {
        $types = get_post_types(
            array(
                'show_ui'           => true,
                'show_in_nav_menus' => true,
            ),
            'names'
        );
        unset( $types['attachment'] );

        /**
         * A menü-panelt megjelenítő poszttípusok szűrése.
         *
         * @param array $types Poszttípus nevek.
         */
        return array_values( apply_filters( 'qwab_menu_manager_post_types', $types ) );
    }

    /* ---------------------------------------------------------------------
     * Meta box
     * ------------------------------------------------------------------- */

    /**
     * A panel regisztrálása a szerkesztő oldalsávjába.
     *
     * @param string $post_type Aktuális poszttípus.
     */
    public function add_meta_box( $post_type ): void {
        if ( ! in_array( $post_type, $this->post_types(), true ) || ! $this->user_can_edit_menus() ) {
            return;
        }
        add_meta_box(
            'qwab-menu-manager',
            __( 'Menus', 'qaiyo-admin-booster' ),
            array( $this, 'render_meta_box' ),
            $post_type,
            'side',
            'default',
            array( '__block_editor_compatible_meta_box' => true )
        );
    }

    /**
     * Asset-ek betöltése a szerkesztő képernyőkön.
     *
     * @param string $hook Aktuális admin oldal hook.
     */
    public function enqueue( $hook ): void {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $this->user_can_edit_menus() ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, $this->post_types(), true ) ) {
            return;
        }

        $css = QWAB_PATH . 'assets/css/qwab-menu-manager.css';
        $js  = QWAB_PATH . 'assets/js/qwab-menu-manager.js';

        wp_enqueue_style(
            'qwab-menu-manager',
            QWAB_URL . 'assets/css/qwab-menu-manager.css',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : QWAB_VERSION )
        );
        wp_enqueue_script(
            'qwab-menu-manager',
            QWAB_URL . 'assets/js/qwab-menu-manager.js',
            array(),
            QWAB_VERSION . '.' . ( file_exists( $js ) ? filemtime( $js ) : QWAB_VERSION ),
            true
        );
        wp_localize_script(
            'qwab-menu-manager',
            'qwabMenuManager',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::NONCE ),
                'i18n'    => array(
                    'working'    => __( 'Working…', 'qaiyo-admin-booster' ),
                    'error'      => __( 'Something went wrong. Please try again.', 'qaiyo-admin-booster' ),
                    'topLevel'   => __( '— Top level —', 'qaiyo-admin-booster' ),
                    'confirmDel' => __( 'Remove this item from the menu?', 'qaiyo-admin-booster' ),
                ),
            )
        );
    }

    /**
     * A panel kirajzolása.
     *
     * @param WP_Post $post Aktuális poszt.
     */
    public function render_meta_box( $post ): void {
        echo '<div class="qwab-mm" data-post="' . esc_attr( (int) $post->ID ) . '">';

        // Mentetlen (auto-draft) tartalomhoz nincs értelme menüpontot csinálni:
        // nincs végleges címe és linkje.
        if ( 'auto-draft' === $post->post_status ) {
            echo '<p class="qwab-mm-hint">' . esc_html__( 'Save this content first, then you can add it to a menu.', 'qaiyo-admin-booster' ) . '</p>';
            echo '</div>';
            return;
        }

        // A panel törzsét külön metódus írja ki, mert AJAX után ezt cseréljük le.
        $this->render_panel_body( (int) $post->ID );
        echo '</div>';
    }

    /* ---------------------------------------------------------------------
     * A panel törzse (AJAX után is ezt küldjük vissza)
     * ------------------------------------------------------------------- */

    /**
     * A panel belső HTML-je az aktuális állapot alapján.
     *
     * @param int $post_id Poszt azonosító.
     * @return string
     */
    /**
     * A panel törzsének kiírása.
     *
     * Közvetlenül a kimenetre ír (minden mezőt a helyén escape-elve), nem
     * HTML-stringet ad vissza — így nincs „echo egy változó" pont sehol.
     * Az AJAX-válaszhoz a hívó pufferel.
     *
     * @param int $post_id A szerkesztett tartalom ID-ja.
     */
    private function render_panel_body( int $post_id ): void {
        $menus       = wp_get_nav_menus();
        $assignments = $this->assignments( $post_id );

        // Blokk-témák jellemzően nem regisztrálnak menü-helyet: ott a
        // navigációs blokk veszi át a szerepet, és a klasszikus menü sehol nem
        // jelenne meg. Jobb ezt előre megmondani, mint hagyni, hogy a
        // felhasználó hiába keresse az eredményt a frontenden.
        if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && ! get_registered_nav_menus() ) {
            echo '<p class="qwab-mm-hint qwab-mm-hint--warn">' .
                esc_html__( 'This theme uses navigation blocks instead of classic menus, so a menu created here will not appear on the site by itself. You can still insert it with a Navigation block.', 'qaiyo-admin-booster' ) .
                '</p>';
        }

        // 1) Jelenlegi menü-tagságok.
        if ( $assignments ) {
            echo '<ul class="qwab-mm-current">';
            foreach ( $assignments as $a ) {
                ?>
                <li class="qwab-mm-current__item">
                    <span class="qwab-mm-current__text">
                        <strong><?php echo esc_html( $a['menu_name'] ); ?></strong>
                        <?php if ( $a['parent'] ) : ?>
                            <span class="qwab-mm-badge"><?php esc_html_e( 'submenu', 'qaiyo-admin-booster' ); ?></span>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="button-link qwab-mm-remove" data-item="<?php echo esc_attr( $a['item_id'] ); ?>">
                        <?php esc_html_e( 'Remove', 'qaiyo-admin-booster' ); ?>
                    </button>
                </li>
                <?php
            }
            echo '</ul>';
        }

        // 2) Hozzáadás meglévő menühöz — csak ha van menü.
        if ( $menus ) {
            $parents = array();
            foreach ( $menus as $menu ) {
                $parents[ $menu->term_id ] = $this->top_level_items( $menu->term_id, $post_id );
            }

            // Melyik menüben hol áll már a tartalom (menü id => szülő id). A JS
            // ebből állítja be előre a szint-választót és a gomb feliratát, így
            // a panel a tényleges állapotot mutatja, és a szint módosítható.
            $current = array();
            foreach ( $assignments as $a ) {
                $current[ $a['menu_id'] ] = $a['parent'];
            }
            $preselect = $assignments ? (int) $assignments[0]['menu_id'] : 0;
            ?>
            <div class="qwab-mm-add"
                data-parents="<?php echo esc_attr( wp_json_encode( $parents ) ); ?>"
                data-current="<?php echo esc_attr( wp_json_encode( (object) $current ) ); ?>">
                <p class="qwab-mm-field">
                    <label for="qwab-mm-menu"><?php esc_html_e( 'Menu', 'qaiyo-admin-booster' ); ?></label>
                    <select id="qwab-mm-menu" class="qwab-mm-menu">
                        <?php foreach ( $menus as $menu ) : ?>
                            <option value="<?php echo esc_attr( $menu->term_id ); ?>" <?php selected( $preselect, (int) $menu->term_id ); ?>>
                                <?php echo esc_html( $menu->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="qwab-mm-field">
                    <label for="qwab-mm-parent"><?php esc_html_e( 'Level', 'qaiyo-admin-booster' ); ?></label>
                    <select id="qwab-mm-parent" class="qwab-mm-parent">
                        <option value="0"><?php esc_html_e( '— Top level —', 'qaiyo-admin-booster' ); ?></option>
                    </select>
                </p>
                <p>
                    <button type="button" class="button button-primary qwab-mm-submit" data-action="add"
                        data-label-add="<?php esc_attr_e( 'Add to menu', 'qaiyo-admin-booster' ); ?>"
                        data-label-move="<?php esc_attr_e( 'Update level', 'qaiyo-admin-booster' ); ?>">
                        <?php esc_html_e( 'Add to menu', 'qaiyo-admin-booster' ); ?>
                    </button>
                </p>
            </div>
            <?php
        }

        // 3) Új menü létrehozása — ha még egy sincs, ez az elsődleges út.
        $locations = get_registered_nav_menus();
        ?>
        <div class="qwab-mm-create<?php echo $menus ? ' is-secondary' : ''; ?>">
            <?php if ( ! $menus ) : ?>
                <p class="qwab-mm-hint"><?php esc_html_e( 'This site has no navigation menus yet. Create the first one right here.', 'qaiyo-admin-booster' ); ?></p>
            <?php else : ?>
                <p><button type="button" class="button-link qwab-mm-toggle-create"><?php esc_html_e( '+ Create a new menu', 'qaiyo-admin-booster' ); ?></button></p>
            <?php endif; ?>

            <div class="qwab-mm-create__form"<?php echo $menus ? ' hidden' : ''; ?>>
                <p class="qwab-mm-field">
                    <label for="qwab-mm-name"><?php esc_html_e( 'Menu name', 'qaiyo-admin-booster' ); ?></label>
                    <input type="text" id="qwab-mm-name" class="qwab-mm-name" value="" placeholder="<?php esc_attr_e( 'Main menu', 'qaiyo-admin-booster' ); ?>" />
                </p>
                <?php if ( $locations ) : ?>
                    <p class="qwab-mm-field">
                        <label for="qwab-mm-location"><?php esc_html_e( 'Display location', 'qaiyo-admin-booster' ); ?></label>
                        <select id="qwab-mm-location" class="qwab-mm-location">
                            <option value=""><?php esc_html_e( '— Do not assign —', 'qaiyo-admin-booster' ); ?></option>
                            <?php foreach ( $locations as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                <?php endif; ?>
                <p>
                    <button type="button" class="button button-primary qwab-mm-submit" data-action="create">
                        <?php esc_html_e( 'Create menu and add', 'qaiyo-admin-booster' ); ?>
                    </button>
                </p>
            </div>
        </div>

        <p class="qwab-mm-status" aria-live="polite"></p>
        <?php

    }

    /* ---------------------------------------------------------------------
     * Adat-segédek
     * ------------------------------------------------------------------- */

    /**
     * A poszt jelenlegi menü-tagságai.
     *
     * @param int $post_id Poszt azonosító.
     * @return array<int,array<string,mixed>>
     */
    private function assignments( int $post_id ): array {
        $out = array();
        foreach ( wp_get_nav_menus() as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id );
            if ( ! $items ) {
                continue;
            }
            foreach ( $items as $item ) {
                if ( 'post_type' === $item->type && (int) $item->object_id === (int) $post_id ) {
                    $out[] = array(
                        'menu_id'   => (int) $menu->term_id,
                        'menu_name' => $menu->name,
                        'item_id'   => (int) $item->ID,
                        'parent'    => (int) $item->menu_item_parent,
                    );
                }
            }
        }
        return $out;
    }

    /**
     * Egy menü felső szintű elemei — ezek választhatók szülőnek, így a
     * beszúrt elem legfeljebb 2. szintű (al-menüpont) lesz.
     *
     * @param int $menu_id Menü azonosító.
     * @param int $post_id Az aktuális poszt (magát nem kínáljuk szülőnek).
     * @return array<int,array<string,mixed>>
     */
    private function top_level_items( int $menu_id, int $post_id ): array {
        $items = wp_get_nav_menu_items( $menu_id );
        $out   = array();
        if ( ! $items ) {
            return $out;
        }
        foreach ( $items as $item ) {
            if ( (int) $item->menu_item_parent ) {
                continue; // Csak felső szint lehet szülő (max 2 szint).
            }
            if ( 'post_type' === $item->type && (int) $item->object_id === (int) $post_id ) {
                continue; // Önmaga alá ne lehessen tenni.
            }
            $out[] = array(
                'id'    => (int) $item->ID,
                'title' => wp_strip_all_tags( $item->title ),
            );
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------- */

    /**
     * Menü-műveletek: hozzáadás, eltávolítás, új menü létrehozása.
     */
    public function ajax_action(): void {
        check_ajax_referer( self::NONCE, 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post    = $post_id ? get_post( $post_id ) : null;

        if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this item.', 'qaiyo-admin-booster' ) ), 403 );
        }
        if ( ! $this->user_can_edit_menus() ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to edit menus on this site.', 'qaiyo-admin-booster' ) ), 403 );
        }
        if ( ! in_array( $post->post_type, $this->post_types(), true ) ) {
            wp_send_json_error( array( 'message' => __( 'This content type cannot be added to a menu.', 'qaiyo-admin-booster' ) ) );
        }

        $what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';

        // Minden bemenetet ITT olvasunk és sanitizálunk — egy helyen, közvetlenül
        // a fenti nonce-ellenőrzés után. (A segéd-metódusokban olvasva a PHPCS
        // nem látná a nonce-ot, mert soronként/hatókörönként vizsgál.)
        $input = array(
            'menu_name' => isset( $_POST['menu_name'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_name'] ) ) : '',
            'location'  => isset( $_POST['location'] ) ? sanitize_key( wp_unslash( $_POST['location'] ) ) : '',
            'menu_id'   => isset( $_POST['menu_id'] ) ? absint( $_POST['menu_id'] ) : 0,
            'parent_id' => isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0,
            'item_id'   => isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0,
        );

        switch ( $what ) {
            case 'create':
                $result = $this->do_create( $post, $input );
                break;
            case 'add':
                $result = $this->do_add( $post, $input );
                break;
            case 'remove':
                $result = $this->do_remove( $input );
                break;
            default:
                $result = new WP_Error( 'bad_action', __( 'Unknown action.', 'qaiyo-admin-booster' ) );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        ob_start();
        $this->render_panel_body( (int) $post->ID );
        $html = (string) ob_get_clean();

        wp_send_json_success(
            array(
                'html'    => $html,
                'message' => $result,
            )
        );
    }

    /**
     * Új menü létrehozása (+ opcionális megjelenési hely), majd a poszt
     * hozzáadása az új menühöz.
     *
     * @param WP_Post $post  Poszt.
     * @param array   $input Sanitizált bemenet (lásd ajax_action).
     * @return string|WP_Error Siker-üzenet vagy hiba.
     */
    private function do_create( WP_Post $post, array $input ) {
        $name = $input['menu_name'];
        if ( '' === $name ) {
            return new WP_Error( 'no_name', __( 'Please enter a name for the menu.', 'qaiyo-admin-booster' ) );
        }

        $menu_id = wp_create_nav_menu( $name );
        if ( is_wp_error( $menu_id ) ) {
            return $menu_id;
        }

        // Opcionális: a téma egyik menü-helyéhez rendelés, különben a menü
        // létrejön, de sehol nem jelenne meg.
        $location = $input['location'];
        if ( '' !== $location && array_key_exists( $location, get_registered_nav_menus() ) ) {
            $locations              = get_theme_mod( 'nav_menu_locations', array() );
            $locations              = is_array( $locations ) ? $locations : array();
            $locations[ $location ] = (int) $menu_id;
            set_theme_mod( 'nav_menu_locations', $locations );
        }

        $added = $this->insert_item( (int) $menu_id, $post, 0 );
        if ( is_wp_error( $added ) ) {
            return $added;
        }

        return __( 'Menu created and this content added to it.', 'qaiyo-admin-booster' );
    }

    /**
     * Hozzáadás meglévő menühöz (opcionálisan al-menüpontként).
     *
     * @param WP_Post $post  Poszt.
     * @param array   $input Sanitizált bemenet (lásd ajax_action).
     * @return string|WP_Error
     */
    private function do_add( WP_Post $post, array $input ) {
        $menu_id = $input['menu_id'];
        $parent  = $input['parent_id'];

        if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
            return new WP_Error( 'bad_menu', __( 'Please choose a menu.', 'qaiyo-admin-booster' ) );
        }

        // A szülő tényleg ebben a menüben van és felső szintű?
        if ( $parent ) {
            $valid = false;
            foreach ( $this->top_level_items( $menu_id, (int) $post->ID ) as $item ) {
                if ( $item['id'] === $parent ) {
                    $valid = true;
                    break;
                }
            }
            if ( ! $valid ) {
                return new WP_Error( 'bad_parent', __( 'The chosen parent item is not available in that menu.', 'qaiyo-admin-booster' ) );
            }
        }

        // Ha a tartalom MÁR benne van ebben a menüben, nem hibázunk: a meglévő
        // menüpontot helyezzük át a kért szintre. Enélkül amit egyszer felső
        // szintre tettek, azt soha nem lehetne utólag al-menüponttá tenni (és
        // fordítva sem).
        $existing = null;
        foreach ( $this->assignments( (int) $post->ID ) as $a ) {
            if ( $a['menu_id'] === (int) $menu_id ) {
                $existing = $a;
                break;
            }
        }

        if ( $existing ) {
            if ( (int) $existing['parent'] === (int) $parent ) {
                return new WP_Error( 'no_change', __( 'This content is already at that place in the menu.', 'qaiyo-admin-booster' ) );
            }

            $moved = $this->move_item( $menu_id, (int) $existing['item_id'], $parent );
            if ( is_wp_error( $moved ) ) {
                return $moved;
            }

            return $parent
                ? __( 'Moved under the chosen menu item.', 'qaiyo-admin-booster' )
                : __( 'Moved to the top level of the menu.', 'qaiyo-admin-booster' );
        }

        $added = $this->insert_item( $menu_id, $post, $parent );
        if ( is_wp_error( $added ) ) {
            return $added;
        }

        return $parent
            ? __( 'Added to the menu as a submenu item.', 'qaiyo-admin-booster' )
            : __( 'Added to the menu.', 'qaiyo-admin-booster' );
    }

    /**
     * Meglévő menüpont áthelyezése másik szintre a menün belül.
     *
     * A `wp_update_nav_menu_item()` a NEM átadott mezőket alapértelmezettre
     * (üresre) állítja, ezért a meglévő értékeket beolvassuk és változatlanul
     * visszaírjuk — így a Menük képernyőn megadott egyedi címke, CSS-osztály,
     * leírás, cél stb. nem vész el az áthelyezéskor.
     *
     * @param int $menu_id Menü azonosító.
     * @param int $item_id Menüpont azonosító.
     * @param int $parent  Új szülő menüpont (0 = felső szint).
     * @return true|WP_Error
     */
    private function move_item( int $menu_id, int $item_id, int $parent ) {
        $raw = get_post( $item_id );
        if ( ! $raw || 'nav_menu_item' !== $raw->post_type ) {
            return new WP_Error( 'bad_item', __( 'That menu item no longer exists.', 'qaiyo-admin-booster' ) );
        }

        $item   = wp_setup_nav_menu_item( $raw );
        $result = wp_update_nav_menu_item(
            (int) $menu_id,
            (int) $item_id,
            array(
                'menu-item-db-id'       => (int) $item_id,
                'menu-item-object-id'   => (int) $item->object_id,
                'menu-item-object'      => $item->object,
                'menu-item-type'        => $item->type,
                'menu-item-title'       => $item->title,
                'menu-item-url'         => $item->url,
                'menu-item-description' => $item->description,
                'menu-item-attr-title'  => $item->attr_title,
                'menu-item-target'      => $item->target,
                'menu-item-classes'     => implode( ' ', (array) $item->classes ),
                'menu-item-xfn'         => $item->xfn,
                'menu-item-status'      => $item->post_status,
                'menu-item-position'    => (int) $item->menu_order,
                'menu-item-parent-id'   => (int) $parent,
            )
        );

        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Menüpont eltávolítása. A törölt elem gyermekeit eggyel feljebb húzzuk,
     * hogy ne váljanak elárvult, láthatatlan elemekké.
     *
     * @param array $input Sanitizált bemenet (lásd ajax_action).
     * @return string|WP_Error
     */
    private function do_remove( array $input ) {
        $item_id = $input['item_id'];
        $item    = $item_id ? get_post( $item_id ) : null;

        if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
            return new WP_Error( 'bad_item', __( 'That menu item no longer exists.', 'qaiyo-admin-booster' ) );
        }

        $parent_of_removed = (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true );

        foreach ( (array) get_posts(
            array(
                'post_type'   => 'nav_menu_item',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_key'    => '_menu_item_menu_item_parent', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Menu items only, admin-side one-off.
                'meta_value'  => (string) $item_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Lásd fent.
            )
        ) as $child_id ) {
            update_post_meta( $child_id, '_menu_item_menu_item_parent', $parent_of_removed );
        }

        wp_delete_post( $item_id, true );

        return __( 'Removed from the menu.', 'qaiyo-admin-booster' );
    }

    /**
     * Új menüpont beszúrása. A duplikátum/áthelyezés kérdését a hívó dönti el
     * (lásd `do_add()`), ide csak valóban új elemmel jutunk.
     *
     * @param int     $menu_id Menü azonosító.
     * @param WP_Post $post    Poszt.
     * @param int     $parent  Szülő menüpont azonosító (0 = felső szint).
     * @return true|WP_Error
     */
    private function insert_item( int $menu_id, WP_Post $post, int $parent ) {
        // A már-benne-van esetet a hívó (do_add) kezeli áthelyezéssel; ide
        // csak akkor jutunk, ha tényleg új menüpontot kell létrehozni.
        $item_id = wp_update_nav_menu_item(
            (int) $menu_id,
            0,
            array(
                'menu-item-object-id' => (int) $post->ID,
                'menu-item-object'    => $post->post_type,
                'menu-item-type'      => 'post_type',
                'menu-item-title'     => $post->post_title,
                'menu-item-parent-id' => (int) $parent,
                'menu-item-status'    => 'publish',
            )
        );

        if ( is_wp_error( $item_id ) ) {
            return $item_id;
        }
        return true;
    }
}

<?php
/**
 * Beállítás-tár: defaults, get/set helperek, register_setting + sanitize.
 *
 * Egyetlen option (`qwab_settings`) tárolja a modul-kapcsolókat és a
 * modul-specifikus konfigurációt. A felhasználó által létrehozott
 * oldal-kollekciók külön option-ban élnek (`qwab_collections`), mert
 * dinamikusan bővülnek.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Settings {

    const OPTION = 'qwab_settings';

    /** @var array|null Futásidejű cache. */
    private static $cache = null;

    /**
     * A modul-slug → alapból engedélyezve térkép. Minden Free modul ON.
     *
     * @return array<string,bool>
     */
    public static function default_modules(): array {
        return array(
            'plugin_upload'    => true,
            'gutenberg'        => true,
            'quick_add'        => true,
            'list_per_page'    => true,
            'uploads'          => true,
            'page_collections' => true,
            'page_filter'      => true,
            'admin_notices'       => true,
            'dashboard_cleanup'   => true,
            'scheduled_countdown' => true,
            'sticky_headers'      => true,
            'last_editor'         => true,
            'quick_status'        => true,
            'bulk_create'         => true,
            'update_center'       => true,
            'comments_widget'     => true,
            'dashboard_greeting'  => true,
            'menu_manager'        => true,
            'menu_visibility'     => true,
            'ecosystem_widgets'   => true,
            'plugin_update_groups' => true,
            // Szándékosan KI: egy „minden plugint kikapcsoló" titkos link
            // bekapcsolása tudatos döntés legyen, ne alapértelmezés.
            'safe_mode'           => false,
            'update_notifications' => true,
            'update_guard'         => true,
            // Pro modulok, amelyek a Modulok rácsban kapcsolhatók (a Pro plugin
            // ezt a kapcsolót olvassa; a Free csak a kapcsoló-állapotot tárolja).
            // A slugok a Pro katalógus slugjaival egyeznek (kötőjeles).
            'dark-mode'           => true,
            'svg-sanitize'        => true,
            'bulk-seo'            => true,
            'pinned-pages'        => true,
            'core-updater'        => true,
        );
    }

    /**
     * A „booster" oszlop-/sor-műveletekhez használt poszttípusok:
     * minden látható (show_ui) típus a rendszer-típusok kihagyásával.
     *
     * @return array<int,string>
     */
    public static function boostable_post_types(): array {
        $skip = array(
            'attachment', 'wp_block', 'wp_template', 'wp_template_part',
            'wp_navigation', 'wp_font_face', 'wp_font_family', 'wp_global_styles',
            'oembed_cache', 'user_request', 'custom_css', 'customize_changeset',
            'revision', 'nav_menu_item',
        );
        $types = get_post_types( array( 'show_ui' => true ), 'names' );
        return array_values( array_diff( $types, $skip ) );
    }

    /**
     * Teljes alapértelmezett beállítás-tömb.
     *
     * @return array
     */
    public static function defaults(): array {
        return array(
            'modules' => self::default_modules(),

            // Plugin upload modul.
            'plugin_upload_redirect' => 1,

            // Gutenberg modul.
            'gutenberg_open_inserter' => 1,
            'gutenberg_disable_fullscreen' => 1,

            // Quick add modul.
            'quick_add_post_types' => array( 'page', 'post', 'product' ),

            // Listanézet elemszám.
            'list_per_page_count' => 50,

            // Feltöltés modul.
            'upload_max_size_mb' => 0, // 0 = ne módosítsa a szerver limitet.
            'upload_raise_server' => 0, // 1 = .user.ini / .htaccess írásával a szerver limitjét is emeli.
            'enable_svg'         => 0,
            'enable_webp'        => 1,
            'enable_avif'        => 0,
            'extra_mimes'        => array(), // ['ext' => 'mime/type', ...] egyedi típusok.
            'friendly_errors'    => 1,
            'friendly_filenames' => 0,
            'dangerous_warning'  => 1,

            // Page Collections modul.
            'collections_post_types' => array( 'page', 'post' ),

            // Vezérlőpult takarítás — alapból csak a WP hírek widgetet rejti.
            'dashboard_cleanup_widgets' => array( 'dashboard_primary' ),

            // Frissítési e-mail értesítések.
            'update_notifications_frequency' => 'daily',
            'update_notifications_scope'     => array( 'core', 'plugins', 'themes' ),
        );
    }

    /**
     * Teljes beállítás-tömb (defaults-szal kitöltve).
     *
     * @return array
     */
    public static function all(): array {
        if ( null === self::$cache ) {
            $stored = get_option( self::OPTION, array() );
            if ( ! is_array( $stored ) ) {
                $stored = array();
            }
            self::$cache = self::merge_defaults( $stored );
        }
        return self::$cache;
    }

    /**
     * Egy kulcs értéke.
     *
     * @param string $key     Kulcs.
     * @param mixed  $default Tartalék.
     * @return mixed
     */
    public static function get( string $key, $default = null ) {
        $all = self::all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    /**
     * Egy modul engedélyezve van-e.
     *
     * @param string $slug Modul slug.
     * @return bool
     */
    public static function is_module_enabled( string $slug ): bool {
        $modules = self::get( 'modules', array() );
        return ! empty( $modules[ $slug ] );
    }

    /**
     * A futásidejű cache eldobása (mentés után).
     */
    public static function flush(): void {
        self::$cache = null;
    }

    /**
     * Defaults rekurzív összefésülés a tárolt értékkel (csak első + második szint).
     *
     * @param array $stored Tárolt értékek.
     * @return array
     */
    private static function merge_defaults( array $stored ): array {
        $defaults = self::defaults();
        $out      = $defaults;

        foreach ( $stored as $key => $value ) {
            if ( 'modules' === $key && is_array( $value ) ) {
                $out['modules'] = array_merge( $defaults['modules'], $value );
            } else {
                $out[ $key ] = $value;
            }
        }
        return $out;
    }

    /**
     * register_setting() regisztráció + sanitize.
     */
    public static function register(): void {
        register_setting(
            'qwab_group',
            self::OPTION,
            array(
                'type'              => 'array',
                'description'       => __( 'Qaiyo Admin Booster settings', 'qaiyo-admin-booster' ),
                'sanitize_callback' => array( __CLASS__, 'sanitize' ),
                'show_in_rest'      => false,
                'default'           => self::defaults(),
            )
        );
    }

    /**
     * Beállítások sanitizálása. A kulcsokat allowlist-eljük a valós
     * entitások ellen (post típusok stb.).
     *
     * @param mixed $input Bemenet.
     * @return array
     */
    public static function sanitize( $input ) {
        if ( ! is_array( $input ) ) {
            $input = array();
        }

        $defaults = self::defaults();
        $out      = array();

        // Modul-kapcsolók — csak ismert slug-okat fogadunk el.
        $known_modules   = array_keys( self::default_modules() );
        $out['modules']  = array();
        $input_modules   = isset( $input['modules'] ) && is_array( $input['modules'] ) ? $input['modules'] : array();
        foreach ( $known_modules as $slug ) {
            $out['modules'][ $slug ] = ! empty( $input_modules[ $slug ] ) ? true : false;
        }

        // Bool kapcsolók.
        $bools = array(
            'plugin_upload_redirect',
            'gutenberg_open_inserter',
            'gutenberg_disable_fullscreen',
            'enable_svg',
            'enable_webp',
            'enable_avif',
            'friendly_errors',
            'friendly_filenames',
            'dangerous_warning',
            'upload_raise_server',
        );
        foreach ( $bools as $key ) {
            $out[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
        }

        // Numerikus értékek határokkal.
        $out['list_per_page_count'] = isset( $input['list_per_page_count'] )
            ? max( 1, min( 999, absint( $input['list_per_page_count'] ) ) )
            : $defaults['list_per_page_count'];

        $out['upload_max_size_mb'] = isset( $input['upload_max_size_mb'] )
            ? max( 0, min( 5120, absint( $input['upload_max_size_mb'] ) ) )
            : $defaults['upload_max_size_mb'];

        // Post típus listák — csak létező publikus post típusok.
        $valid_types = array_keys( get_post_types( array( 'show_ui' => true ), 'names' ) );

        $out['quick_add_post_types'] = self::sanitize_type_list(
            isset( $input['quick_add_post_types'] ) ? $input['quick_add_post_types'] : array(),
            $valid_types
        );
        $out['collections_post_types'] = self::sanitize_type_list(
            isset( $input['collections_post_types'] ) ? $input['collections_post_types'] : array(),
            $valid_types
        );

        // Frissítési értesítések — gyakoriság csak az ismert kulcsok közül.
        $frequencies = array_keys( Qwab_Module_Update_Notifications::frequencies() );
        $frequency   = isset( $input['update_notifications_frequency'] )
            ? sanitize_key( $input['update_notifications_frequency'] )
            : $defaults['update_notifications_frequency'];
        $out['update_notifications_frequency'] = in_array( $frequency, $frequencies, true )
            ? $frequency
            : $defaults['update_notifications_frequency'];

        $out['update_notifications_scope'] = self::sanitize_type_list(
            isset( $input['update_notifications_scope'] ) ? $input['update_notifications_scope'] : array(),
            array( 'core', 'plugins', 'themes' )
        );

        // Dashboard widget lista — csak ismert widget id-k.
        $valid_widgets = array_keys( Qwab_Module_Dashboard_Cleanup::known_widgets() );
        $out['dashboard_cleanup_widgets'] = array();
        $in_widgets = isset( $input['dashboard_cleanup_widgets'] ) && is_array( $input['dashboard_cleanup_widgets'] )
            ? $input['dashboard_cleanup_widgets']
            : array();
        foreach ( $in_widgets as $wid ) {
            $wid = sanitize_key( $wid );
            if ( in_array( $wid, $valid_widgets, true ) && ! in_array( $wid, $out['dashboard_cleanup_widgets'], true ) ) {
                $out['dashboard_cleanup_widgets'][] = $wid;
            }
        }

        // Egyedi MIME típusok: ['ext' => 'mime/type']. Forrás lehet tömb VAGY
        // a UI textarea-ja (`extra_mimes_raw`), soronként "ext = mime/type".
        $out['extra_mimes'] = array();

        $pairs = array();
        if ( isset( $input['extra_mimes'] ) && is_array( $input['extra_mimes'] ) ) {
            $pairs = $input['extra_mimes'];
        } elseif ( isset( $input['extra_mimes_raw'] ) && is_string( $input['extra_mimes_raw'] ) ) {
            $lines = preg_split( '/\r\n|\r|\n/', $input['extra_mimes_raw'] );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( '' === $line || false === strpos( $line, '=' ) ) {
                    continue;
                }
                list( $ext, $mime ) = array_pad( explode( '=', $line, 2 ), 2, '' );
                $pairs[ trim( $ext ) ] = trim( $mime );
            }
        }

        $dangerous = Qwab_Module_Uploads::dangerous_extensions();
        foreach ( $pairs as $ext => $mime ) {
            $ext  = preg_replace( '/[^a-z0-9|]/', '', strtolower( (string) $ext ) );
            $mime = sanitize_text_field( (string) $mime );
            // Végrehajtható / script kiterjesztéseket már mentéskor eldobjuk.
            if ( '' !== $ext && array_intersect( explode( '|', $ext ), $dangerous ) ) {
                continue;
            }
            if ( '' !== $ext && '' !== $mime && preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime ) ) {
                $out['extra_mimes'][ $ext ] = $mime;
            }
        }

        self::flush();
        return $out;
    }

    /**
     * Post-típus lista tisztítása allowlist ellen.
     *
     * @param mixed $list  Bemenet.
     * @param array $valid Megengedett típusok.
     * @return array
     */
    private static function sanitize_type_list( $list, array $valid ): array {
        if ( ! is_array( $list ) ) {
            return array();
        }
        $out = array();
        foreach ( $list as $type ) {
            $type = sanitize_key( $type );
            if ( in_array( $type, $valid, true ) && ! in_array( $type, $out, true ) ) {
                $out[] = $type;
            }
        }
        return $out;
    }
}

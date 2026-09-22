<?php
/**
 * Safe Mode — előre elmenthető vészbejárat, ha egy plugin- vagy
 * témafrissítés miatt nem lehet belépni az oldalra.
 *
 * Ez az osztály KEZELI a funkciót (kulcs létrehozása, a mu-plugin kiírása és
 * törlése, a használt link visszavonása, riasztás, napló). Maga a vészbejárat
 * a `wp-content/mu-plugins/`-be másolt, önálló `includes/safe-mode/mu-plugin.php`
 * — a teljes biztonsági modell annak a fejlécében van leírva.
 *
 * A KULCS SOHA NINCS TÁROLVA: csak a wp-config.php sóival kulcsolt hash-e.
 * Ezért a linket csak a létrehozás pillanatában lehet megmutatni (egyszer,
 * 10 percig, csak annak a felhasználónak, aki létrehozta).
 *
 * Precedens a WP.org-on: a hivatalos „Health Check & Troubleshooting" plugin
 * ugyanígy mu-plugint telepít a hibaelhárító módjához.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Safe_Mode {

    const MODULE    = 'safe_mode';
    const HASH_OPT  = 'qwab_safe_mode_hash';
    const META_OPT  = 'qwab_safe_mode_meta';
    const USED_OPT  = 'qwab_safe_mode_used';
    const LOG_OPT   = 'qwab_safe_mode_log';
    const IPS_OPT   = 'qwab_safe_mode_ips';
    const ALERT_OPT = 'qwab_safe_mode_alerts';
    const NOTE_OPT  = 'qwab_safe_mode_notice';
    const FILENAME  = 'qaiyo-admin-booster-safe-mode.php';

    /**
     * Bekötés.
     */
    public static function init(): void {
        add_filter( 'pre_update_option_qwab_settings', array( __CLASS__, 'on_save' ), 20, 2 );
        add_action( 'admin_init', array( __CLASS__, 'self_heal' ) );
        add_action( 'admin_init', array( __CLASS__, 'retire_used_link' ), 5 );
        add_action( 'admin_notices', array( __CLASS__, 'used_notice' ) );
        add_action( 'network_admin_notices', array( __CLASS__, 'used_notice' ) );
        add_action( 'admin_post_qwab_safe_mode_create', array( __CLASS__, 'handle_create' ) );
        add_action( 'admin_post_qwab_safe_mode_ips', array( __CLASS__, 'handle_ips' ) );
        add_action( 'admin_post_qwab_safe_mode_dismiss', array( __CLASS__, 'handle_dismiss' ) );
    }

    /* ---------------------------------------------------------------------
     * Tárolás
     * ------------------------------------------------------------------- */

    /**
     * @param string $name    Opció.
     * @param mixed  $default Alapérték.
     * @return mixed
     */
    private static function get( string $name, $default = false ) {
        return is_multisite() ? get_site_option( $name, $default ) : get_option( $name, $default );
    }

    /**
     * @param string $name  Opció.
     * @param mixed  $value Érték.
     */
    private static function set( string $name, $value ): void {
        if ( is_multisite() ) {
            update_site_option( $name, $value );
        } else {
            update_option( $name, $value, false );
        }
    }

    /**
     * @param string $name Opció.
     */
    private static function delete( string $name ): void {
        if ( is_multisite() ) {
            delete_site_option( $name );
        } else {
            delete_option( $name );
        }
    }

    /**
     * Webhely-kulcs a wp-config.php sóiból.
     *
     * ⚠️ BITRE egyeznie kell a mu-plugin `qwab_sm_site_key()` függvényével.
     *
     * @return string
     */
    public static function site_key(): string {
        // A tartalék (adatbázisban tárolt) sókat a wp_salt() hozza létre, ha
        // még nem léteznek.
        wp_salt( 'secure_auth' );

        $out = '';
        foreach ( array( 'SECURE_AUTH_KEY' => 'secure_auth_key', 'SECURE_AUTH_SALT' => 'secure_auth_salt' ) as $const => $option ) {
            $value = defined( $const ) ? (string) constant( $const ) : '';
            if ( strlen( $value ) < 32 || false !== stripos( $value, 'put your unique phrase here' ) ) {
                $value = (string) get_site_option( $option, '' );
            }
            $out .= $value;
        }

        return strlen( $out ) >= 64 ? $out : '';
    }

    /**
     * A wp-config.php saját, egyedi kulcsokat tartalmaz-e? (Ha nem, a hash
     * kulcsa is az adatbázisban van — ezt jelezzük.)
     *
     * @return bool
     */
    public static function config_keys_strong(): bool {
        foreach ( array( 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ) as $const ) {
            $value = defined( $const ) ? (string) constant( $const ) : '';
            if ( strlen( $value ) < 32 || false !== stripos( $value, 'put your unique phrase here' ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kulcs → tárolandó hash (egyezik a mu-plugin `qwab_sm_hash_key()`-ével).
     *
     * @param string $key      Kulcs.
     * @param string $site_key Webhely-kulcs.
     * @return string
     */
    public static function hash_key( string $key, string $site_key ): string {
        return hash_hmac( 'sha256', $key, 'qwab-safe-mode-key|' . $site_key );
    }

    /* ---------------------------------------------------------------------
     * Állapot
     * ------------------------------------------------------------------- */

    /**
     * Ki kezelheti? Multisite-on a hálózati pluginokat is kikapcsolja → csak
     * szuper-admin.
     *
     * @return bool
     */
    public static function current_user_may(): bool {
        return is_multisite() ? is_super_admin() : current_user_can( 'manage_options' );
    }

    /**
     * @param array|null $settings Opcionális (mentés közbeni) beállítás-tömb.
     * @return bool
     */
    public static function enabled( ?array $settings = null ): bool {
        if ( is_array( $settings ) ) {
            return ! empty( $settings['modules'][ self::MODULE ] );
        }

        return Qwab_Settings::is_module_enabled( self::MODULE );
    }

    /**
     * @return string
     */
    public static function target(): string {
        return WPMU_PLUGIN_DIR . '/' . self::FILENAME;
    }

    /**
     * @return string
     */
    public static function template(): string {
        return QWAB_PATH . 'includes/safe-mode/mu-plugin.php';
    }

    /**
     * Telepítve van-e, és az aktuális verzió-e?
     *
     * @return bool
     */
    public static function is_installed(): bool {
        return file_exists( self::target() ) && md5_file( self::target() ) === md5_file( self::template() );
    }

    /**
     * Van-e érvényes link?
     *
     * @return bool
     */
    public static function has_link(): bool {
        $hash = self::get( self::HASH_OPT, '' );

        return is_string( $hash ) && 64 === strlen( $hash );
    }

    /**
     * A link adatai (létrehozás ideje, létrehozó).
     *
     * @return array
     */
    public static function meta(): array {
        $meta = self::get( self::META_OPT, array() );

        return is_array( $meta ) ? $meta : array();
    }

    /**
     * @return array
     */
    public static function log(): array {
        $log = self::get( self::LOG_OPT, array() );

        return is_array( $log ) ? $log : array();
    }

    /**
     * @return array
     */
    public static function ips(): array {
        $ips = self::get( self::IPS_OPT, array() );

        return is_array( $ips ) ? $ips : array();
    }

    /**
     * Új link létrehozása. Az előző link és minden nyitott Safe Mode
     * munkamenet azonnal érvénytelen (a süti a hash-sel van aláírva).
     *
     * @return string|WP_Error A teljes link — csak most, egyetlen alkalommal.
     */
    public static function create_link() {
        $site_key = self::site_key();
        if ( '' === $site_key ) {
            return new WP_Error( 'qwab_safe_mode_keys', __( 'WordPress security keys are missing, so a Safe Mode link cannot be created safely.', 'qaiyo-admin-booster' ) );
        }

        $key = bin2hex( random_bytes( 32 ) );

        self::set( self::HASH_OPT, self::hash_key( $key, $site_key ) );
        self::set(
            self::META_OPT,
            array(
                'time' => time(),
                'user' => wp_get_current_user()->user_login,
            )
        );
        self::delete( self::USED_OPT );

        return add_query_arg( 'qwab_safe_mode', '1', site_url( 'wp-login.php', 'login' ) ) . '#' . $key;
    }

    /**
     * A link visszavonása (hash törlése).
     */
    private static function revoke(): void {
        self::delete( self::HASH_OPT );
        self::delete( self::META_OPT );
    }

    /* ---------------------------------------------------------------------
     * Szinkron
     * ------------------------------------------------------------------- */

    /**
     * Mentéskor: a mu-plugin kiírása vagy eltávolítása.
     *
     * @param array $value     Új érték.
     * @param array $old_value Régi érték.
     * @return array
     */
    public static function on_save( $value, $old_value ) {
        if ( ! is_admin() || ! self::current_user_may() ) {
            return $value;
        }

        $enable = self::enabled( is_array( $value ) ? $value : array() );
        $result = self::sync( $enable );
        if ( is_wp_error( $result ) ) {
            add_settings_error( 'qwab_group', 'qwab_safe_mode', $result->get_error_message(), 'error' );
            return $value;
        }

        // Első bekapcsolás: azonnal legyen link, egyszer megmutatva.
        if ( $enable && ! self::has_link() ) {
            $url = self::create_link();
            if ( ! is_wp_error( $url ) ) {
                self::stash_reveal( $url );
            }
        }

        return $value;
    }

    /**
     * Admin oldalbetöltéskor: ha a fájl eltűnt vagy elavult, csendben visszaírjuk.
     */
    public static function self_heal(): void {
        if ( ! self::current_user_may() || ! self::enabled() || self::is_installed() ) {
            return;
        }
        self::sync( true );
    }

    /**
     * A kívánt állapot beállítása.
     *
     * Kikapcsoláskor a fájlt ÉS a linket is töröljük: egy kikapcsolt funkció
     * ne hagyjon maga után élő (esetleg kiszivárgott) vészbejáratot.
     *
     * @param bool $enabled Be legyen-e kapcsolva.
     * @return true|WP_Error
     */
    public static function sync( bool $enabled ) {
        if ( ! $enabled ) {
            self::revoke();
        }

        $fs = self::filesystem();
        if ( is_wp_error( $fs ) ) {
            return $enabled ? $fs : true;
        }

        $target = self::target();

        if ( ! $enabled ) {
            if ( $fs->exists( $target ) && ! $fs->delete( $target ) ) {
                return new WP_Error( 'qwab_safe_mode_fs', __( 'Could not remove the Safe Mode file from the mu-plugins folder.', 'qaiyo-admin-booster' ) );
            }
            return true;
        }

        if ( ! $fs->is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
            return self::write_error();
        }

        if ( ! self::is_installed() && ! $fs->copy( self::template(), $target, true, FS_CHMOD_FILE ) ) {
            return self::write_error();
        }

        return true;
    }

    /**
     * Deaktiváláskor: a fájl és a link törlése.
     */
    public static function deactivate(): void {
        self::revoke();
        $fs = self::filesystem();
        if ( ! is_wp_error( $fs ) && $fs->exists( self::target() ) ) {
            $fs->delete( self::target() );
        }
    }

    /**
     * Teljes takarítás (uninstall).
     */
    public static function cleanup(): void {
        self::deactivate();
        foreach ( array( self::USED_OPT, self::LOG_OPT, self::IPS_OPT, self::ALERT_OPT, self::NOTE_OPT, 'qwab_safe_mode_secret' ) as $name ) {
            self::delete( $name );
        }
        delete_transient( 'qwab_sm_login_fails' );
    }

    /**
     * Frissítés az első (titkot nyíltan tároló) fejlesztői változatról: a
     * nyílt titkot töröljük, a linket újra kell létrehozni.
     */
    public static function migrate(): void {
        if ( false !== self::get( 'qwab_safe_mode_secret', false ) ) {
            self::delete( 'qwab_safe_mode_secret' );
        }
    }

    /**
     * WP_Filesystem, csak közvetlen elérésnél.
     *
     * @return WP_Filesystem_Base|WP_Error
     */
    private static function filesystem() {
        global $wp_filesystem;

        if ( ! function_exists( 'get_filesystem_method' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
            return new WP_Error( 'qwab_safe_mode_fs', __( 'File changes are disabled on this site (DISALLOW_FILE_MODS), so the Safe Mode file cannot be installed.', 'qaiyo-admin-booster' ) );
        }

        if ( 'direct' !== get_filesystem_method( array(), WP_CONTENT_DIR ) || ! WP_Filesystem() ) {
            return self::write_error();
        }

        return $wp_filesystem;
    }

    /**
     * @return WP_Error
     */
    private static function write_error(): WP_Error {
        return new WP_Error(
            'qwab_safe_mode_fs',
            sprintf(
                /* translators: %s: folder path, e.g. /var/www/html/wp-content/mu-plugins. */
                __( 'Admin Booster cannot write to %s on this server, so Safe Mode could not be installed.', 'qaiyo-admin-booster' ),
                WPMU_PLUGIN_DIR
            )
        );
    }

    /* ---------------------------------------------------------------------
     * Használt link visszavonása + értesítés
     * ------------------------------------------------------------------- */

    /**
     * Ha a linket használták, és az admin most RENDES módban tölt be (tehát
     * az oldal újra működik), a linket visszavonjuk.
     *
     * Szándékosan nem a használat pillanatában: ha a javítás után az oldal
     * még mindig hibás, a linknek működnie kell, különben a felhasználó
     * ugyanúgy kizárná magát.
     */
    public static function retire_used_link(): void {
        if ( defined( 'QWAB_SAFE_MODE_ACTIVE' ) || ! current_user_can( 'read' ) ) {
            return;
        }

        $used = self::get( self::USED_OPT, false );
        if ( ! is_array( $used ) ) {
            return;
        }

        self::revoke();
        self::delete( self::USED_OPT );
        self::set(
            self::NOTE_OPT,
            array(
                'time' => isset( $used['time'] ) ? (int) $used['time'] : time(),
                'ip'   => isset( $used['ip'] ) ? (string) $used['ip'] : '',
            )
        );
    }

    /**
     * Tartós értesítés minden adminnak, amíg valaki nyugtázza.
     */
    public static function used_notice(): void {
        if ( ! self::current_user_may() ) {
            return;
        }
        $note = self::get( self::NOTE_OPT, false );
        if ( ! is_array( $note ) ) {
            return;
        }

        $dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=qwab_safe_mode_dismiss' ), 'qwab_safe_mode_dismiss' );
        $manage  = admin_url( 'admin.php?page=qaiyo-admin-booster&qwab_tab=safe-mode' );

        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p><p>%3$s</p><p><a class="button button-primary" href="%4$s">%5$s</a> <a class="button" href="%6$s">%7$s</a></p></div>',
            esc_html__( 'The Safe Mode link was used and has been retired.', 'qaiyo-admin-booster' ),
            esc_html(
                sprintf(
                    /* translators: 1: date and time, 2: IP address. */
                    __( 'Used on %1$s from IP address %2$s.', 'qaiyo-admin-booster' ),
                    wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $note['time'] ),
                    '' !== $note['ip'] ? $note['ip'] : '—'
                )
            ),
            esc_html__( 'If this was not you or a colleague: change every administrator password now and check the Safe Mode log. Either way, create a new Safe Mode link and save it.', 'qaiyo-admin-booster' ),
            esc_url( $manage ),
            esc_html__( 'Create a new link', 'qaiyo-admin-booster' ),
            esc_url( $dismiss ),
            esc_html__( 'Dismiss', 'qaiyo-admin-booster' )
        );
    }

    /* ---------------------------------------------------------------------
     * Egyszer megjelenő link
     * ------------------------------------------------------------------- */

    /**
     * @return string
     */
    private static function reveal_key(): string {
        return 'qwab_sm_reveal_' . get_current_user_id();
    }

    /**
     * A friss linket 10 percre, csak a létrehozónak tesszük félre.
     *
     * @param string $url Link.
     */
    private static function stash_reveal( string $url ): void {
        set_transient( self::reveal_key(), $url, 10 * MINUTE_IN_SECONDS );
    }

    /**
     * A félretett link kivétele (utána törlődik).
     *
     * @return string
     */
    public static function take_reveal(): string {
        $url = get_transient( self::reveal_key() );
        delete_transient( self::reveal_key() );

        return is_string( $url ) ? $url : '';
    }

    /* ---------------------------------------------------------------------
     * Űrlapkezelők
     * ------------------------------------------------------------------- */

    /**
     * Visszairányítás a Safe Mode fülre.
     *
     * @param array $args Query argumentumok.
     */
    private static function back( array $args = array() ): void {
        wp_safe_redirect( add_query_arg( array_merge( array( 'qwab_tab' => 'safe-mode' ), $args ), admin_url( 'admin.php?page=qaiyo-admin-booster' ) ) );
        exit;
    }

    /**
     * admin-post: új link.
     */
    public static function handle_create(): void {
        if ( ! self::current_user_may() ) {
            wp_die( esc_html__( 'You are not allowed to do that.', 'qaiyo-admin-booster' ), 403 );
        }
        check_admin_referer( 'qwab_safe_mode_create' );

        $url = self::create_link();
        if ( is_wp_error( $url ) ) {
            wp_die( esc_html( $url->get_error_message() ) );
        }
        self::stash_reveal( $url );
        self::delete( self::NOTE_OPT );

        self::back();
    }

    /**
     * admin-post: IP-korlátozás mentése.
     */
    public static function handle_ips(): void {
        if ( ! self::current_user_may() ) {
            wp_die( esc_html__( 'You are not allowed to do that.', 'qaiyo-admin-booster' ), 403 );
        }
        check_admin_referer( 'qwab_safe_mode_ips' );

        $raw   = isset( $_POST['qwab_safe_mode_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['qwab_safe_mode_ips'] ) ) : '';
        $clean = self::parse_ips( $raw );

        if ( $clean['invalid'] ) {
            self::back( array( 'qwab_sm_ip_error' => implode( ', ', $clean['invalid'] ) ) );
        }

        self::set( self::IPS_OPT, $clean['valid'] );
        self::back( array( 'qwab_sm_ips_saved' => '1' ) );
    }

    /**
     * IP / CIDR lista ellenőrzése.
     *
     * @param string $raw Soronként egy bejegyzés.
     * @return array{valid:array,invalid:array}
     */
    public static function parse_ips( string $raw ): array {
        $valid   = array();
        $invalid = array();

        foreach ( preg_split( '/[\s,]+/', (string) $raw ) as $entry ) {
            $entry = trim( $entry );
            if ( '' === $entry ) {
                continue;
            }
            $parts = explode( '/', $entry, 2 );
            $ip    = filter_var( $parts[0], FILTER_VALIDATE_IP );
            $max   = ( false !== $ip && false !== strpos( $ip, ':' ) ) ? 128 : 32;
            $ok    = false !== $ip && ( ! isset( $parts[1] ) || ( ctype_digit( $parts[1] ) && (int) $parts[1] <= $max ) );

            if ( $ok ) {
                $valid[] = $entry;
            } else {
                $invalid[] = $entry;
            }
        }

        return array(
            'valid'   => array_values( array_unique( $valid ) ),
            'invalid' => $invalid,
        );
    }

    /**
     * admin-post: értesítés nyugtázása.
     */
    public static function handle_dismiss(): void {
        if ( ! self::current_user_may() ) {
            wp_die( esc_html__( 'You are not allowed to do that.', 'qaiyo-admin-booster' ), 403 );
        }
        check_admin_referer( 'qwab_safe_mode_dismiss' );
        self::delete( self::NOTE_OPT );

        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
        exit;
    }
}

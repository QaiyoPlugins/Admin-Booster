<?php
/**
 * A szerver PHP feltöltési limitjének emelése — cPanel / FTP nélkül.
 *
 * MIÉRT NEM `ini_set()` ÉS NEM `wp-config.php`:
 * az `upload_max_filesize` és a `post_max_size` PHP_INI_PERDIR direktívák. A
 * PHP a kérés törzsét (a feltöltött fájlt) MÉG A SZKRIPT INDULÁSA ELŐTT
 * feldolgozza, így futásidőben már késő: az `ini_set()` ezekre `false`-t ad,
 * és a `wp-config.php` is csak PHP kód. (A `memory_limit` PHP_INI_ALL, ezért
 * a `WP_MEMORY_LIMIT` működik — innen a tévhit, hogy a feltöltési limit is.)
 *
 * Ami valóban működik, és WordPressből írható fájl:
 *  - PHP-FPM / FastCGI / CGI  → `.user.ini` a WP gyökerében. A PHP a futó
 *    szkript könyvtárától FELFELÉ keresi, így a `wp-admin/async-upload.php`
 *    is felveszi. A PHP `user_ini.cache_ttl` másodpercig (alapból 300)
 *    gyorsítótárazza, tehát pár perc, mire érvénybe lép.
 *  - Apache mod_php / LiteSpeed → `php_value` a `.htaccess`-ben, azonnal.
 *
 * ⚠️ A `.htaccess` `php_value` sora PHP-FPM alatt az EGÉSZ OLDALT 500-as
 * hibára dönti. Ezért (1) csak a ténylegesen futó SAPI alapján választunk
 * módszert, és (2) a sorokat `<IfModule>` blokkba tesszük, hogy egy későbbi
 * szerverváltás (pl. a host FPM-re áll át) se okozzon leállást.
 *
 * Minden írás jelölt blokkba kerül, a fájl többi (a host által írt) tartalma
 * érintetlen marad, és a blokk visszakapcsoláskor / törléskor eltűnik.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Server_Limits {

    const MARKER     = 'Qaiyo Admin Booster';
    const STATUS_OPT = 'qwab_server_limits_status';

    /**
     * Bekötés.
     */
    public static function init(): void {
        // Mentéskor MINDIG lefut (az update_option_* csak változáskor), így
        // egy kézzel törölt fájl is visszaírható egy sima mentéssel.
        add_filter( 'pre_update_option_qwab_settings', array( __CLASS__, 'on_save' ), 20, 2 );
    }

    /**
     * Beállítás mentése → a szerver-fájl összehangolása.
     *
     * @param array $value     Új (már sanitizált) érték.
     * @param array $old_value Régi érték.
     * @return array Változatlan új érték.
     */
    public static function on_save( $value, $old_value ) {
        // Csak valódi admin mentésre (cron, WP-CLI, más kód update_option-je
        // ne írjon szerver-konfigurációt).
        if ( ! is_admin() || ! self::current_user_may() ) {
            return $value;
        }

        $result = self::sync( is_array( $value ) ? $value : array() );

        if ( is_wp_error( $result ) ) {
            add_settings_error( 'qwab_group', 'qwab_server_limits', $result->get_error_message(), 'error' );
        }

        return $value;
    }

    /**
     * Az aktuális felhasználó módosíthat-e szerver-konfigurációt?
     *
     * Többsite esetén a `.user.ini` / `.htaccess` az EGÉSZ hálózatra hat, ezért
     * csak szuper-admin nyúlhat hozzá.
     *
     * @return bool
     */
    public static function current_user_may(): bool {
        if ( is_multisite() ) {
            return is_super_admin();
        }

        return current_user_can( 'manage_options' );
    }

    /**
     * A futó környezethez illő célfájlok.
     *
     * TÖBB célt is visszaadhat: DirectAdmin/cPanel + CloudLinux tárhelyeken a
     * PHP LiteSpeed SAPI-n fut, ahol a host beállításától függ, hogy a
     * `.htaccess php_value` (LSWS / `lsapi_mod_php_behaviour On`) vagy a
     * `.user.ini` (`LSPHP_ENABLE_USER_INI=on` / `lsapi_enable_user_ini On`) hat
     * — kívülről nem látszik biztosan. A `.user.ini` sehol nem okozhat hibát
     * (ahol nem olvassák, ott egyszerűen hatástalan), ezért ha a PHP ismeri,
     * mindig írjuk; a `.htaccess`-t csak Apache mod_php / LiteSpeed alatt.
     *
     * @return array{targets:array<string,string>,reason:string,locked:bool}
     *         targets: method ('htaccess'|'user_ini') → fájl.
     */
    public static function detect(): array {
        $sapi = php_sapi_name();
        $out  = array( 'targets' => array(), 'reason' => '', 'locked' => false );

        if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
            $out['reason'] = __( 'File changes are disabled on this site (DISALLOW_FILE_MODS), so the server limit cannot be changed from here.', 'qaiyo-admin-booster' );
            return (array) apply_filters( 'qwab_server_limits_method', $out );
        }

        // A host `php_admin_value`-val rögzítette? Akkor semmilyen
        // könyvtár-szintű fájl nem írhatja felül — ezt ki is lehet olvasni.
        if ( self::is_locked() ) {
            $out['locked'] = true;
            $out['reason'] = __( 'Your host has locked the upload limit for this site (php_admin_value), so no file inside WordPress can change it — only the host can raise it.', 'qaiyo-admin-booster' );
            return (array) apply_filters( 'qwab_server_limits_method', $out );
        }

        if ( in_array( $sapi, array( 'apache2handler', 'litespeed' ), true ) ) {
            $out['targets']['htaccess'] = ABSPATH . '.htaccess';
        }

        $user_ini = (string) ini_get( 'user_ini.filename' );
        if ( '' !== $user_ini && in_array( $sapi, array( 'fpm-fcgi', 'cgi-fcgi', 'cgi', 'litespeed' ), true ) ) {
            $out['targets']['user_ini'] = ABSPATH . basename( $user_ini );
        }

        if ( ! $out['targets'] ) {
            $out['reason'] = ( '' === $user_ini && in_array( $sapi, array( 'fpm-fcgi', 'cgi-fcgi', 'cgi' ), true ) )
                ? __( 'This server has per-directory PHP settings (.user.ini) switched off, so the limit can only be raised by your host.', 'qaiyo-admin-booster' )
                : sprintf(
                    /* translators: %s: PHP server API name, e.g. "cli-server". */
                    __( 'This server runs PHP as “%s”, which does not read per-directory settings, so the limit can only be raised by your host.', 'qaiyo-admin-booster' ),
                    $sapi
                );
        }

        /**
         * Szűrő: a felismert célok felülírása (egzotikus hostokhoz és teszthez).
         *
         * @param array $out targets / reason / locked.
         */
        return (array) apply_filters( 'qwab_server_limits_method', $out );
    }

    /**
     * Rögzítette-e a host a feltöltési direktívákat (php_admin_value)?
     *
     * Normál esetben mindkettő PHP_INI_PERDIR; a `php_admin_value` (FPM pool,
     * Apache/LiteSpeed konfig) PHP_INI_SYSTEM-re szigorítja, és ilyenkor a
     * `.user.ini` / `.htaccess php_value` csendben hatástalan.
     *
     * @return bool
     */
    public static function is_locked(): bool {
        $all = function_exists( 'ini_get_all' ) ? ini_get_all( null, true ) : array();
        foreach ( array( 'upload_max_filesize', 'post_max_size' ) as $key ) {
            if ( isset( $all[ $key ]['access'] ) && ! ( (int) $all[ $key ]['access'] & INI_PERDIR ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A beállítás szerinti állapot beírása vagy eltávolítása.
     *
     * @param array $settings qwab_settings érték.
     * @return true|WP_Error
     */
    public static function sync( array $settings ) {
        $mb     = isset( $settings['upload_max_size_mb'] ) ? (int) $settings['upload_max_size_mb'] : 0;
        $raise  = ! empty( $settings['upload_raise_server'] );
        $module = ! empty( $settings['modules']['uploads'] );
        $env    = self::detect();
        $want   = ( $module && $raise && $mb > 0 && $env['targets'] );

        // Minden ismert fájlból takarítunk, amelyik most NEM cél — így egy
        // korábbi (pl. szerverváltás előtti) blokk sem marad árván.
        foreach ( self::cleanup_files( $env['targets'] ) as $file => $method ) {
            if ( $want && isset( $env['targets'][ $method ] ) && $env['targets'][ $method ] === $file ) {
                continue;
            }
            if ( file_exists( $file ) ) {
                $removed = self::write_block( $file, $method, array() );
                if ( is_wp_error( $removed ) ) {
                    return $removed;
                }
            }
        }

        if ( ! $want ) {
            delete_option( self::STATUS_OPT );
            return ( $raise && $mb > 0 && '' !== $env['reason'] ) ? new WP_Error( 'qwab_limits_unsupported', $env['reason'] ) : true;
        }

        foreach ( $env['targets'] as $method => $file ) {
            $written = self::write_block( $file, $method, self::lines( $method, $mb ) );
            if ( is_wp_error( $written ) ) {
                return $written;
            }
        }

        update_option(
            self::STATUS_OPT,
            array(
                'time'    => time(),
                'mb'      => $mb,
                'targets' => $env['targets'],
            ),
            false
        );

        return true;
    }

    /**
     * Minden fájl, amiben blokkunk lehet: a szabványos helyek, a legutóbb
     * ténylegesen írt célok (pl. egyedi `user_ini.filename`) és a mostaniak.
     *
     * @param array $current Jelenlegi célok (method → fájl).
     * @return array<string,string> Fájl → method.
     */
    private static function cleanup_files( array $current ): array {
        $files  = array();
        $status = get_option( self::STATUS_OPT, array() );
        $prev   = ( is_array( $status ) && isset( $status['targets'] ) && is_array( $status['targets'] ) ) ? $status['targets'] : array();

        foreach ( array( self::known_files(), $prev, (array) $current ) as $set ) {
            foreach ( $set as $method => $file ) {
                $files[ (string) $file ] = (string) $method;
            }
        }

        return $files;
    }

    /**
     * A plugin által valaha kezelt fájlok.
     *
     * @return array<string,string>
     */
    private static function known_files(): array {
        return array(
            'htaccess' => ABSPATH . '.htaccess',
            'user_ini' => ABSPATH . '.user.ini',
        );
    }

    /**
     * A beírandó sorok.
     *
     * A `post_max_size` egy MB-tal nagyobb: a teljes POST törzs a fájlon felül
     * a multipart-fejléceket és az űrlapmezőket is tartalmazza, és ha pont a
     * fájlmérettel egyezne, egy limitre pontosan akkora fájl mégis elbukna.
     *
     * @param string $method Módszer.
     * @param int    $mb     Kívánt limit MB-ban.
     * @return array
     */
    private static function lines( string $method, int $mb ): array {
        $upload = (int) $mb . 'M';
        $post   = ( (int) $mb + 1 ) . 'M';

        if ( 'htaccess' === $method ) {
            $out = array();
            // mod_lsapi.c: Apache + CloudLinux mod_lsapi (a SAPI ott is „litespeed”).
            foreach ( array( 'mod_php.c', 'mod_php7.c', 'LiteSpeed', 'mod_lsapi.c' ) as $module ) {
                $out[] = '<IfModule ' . $module . '>';
                $out[] = '    php_value upload_max_filesize ' . $upload;
                $out[] = '    php_value post_max_size ' . $post;
                $out[] = '</IfModule>';
            }
            return $out;
        }

        return array(
            'upload_max_filesize = ' . $upload,
            'post_max_size = ' . $post,
        );
    }

    /**
     * Jelölt blokk beírása / cseréje / törlése egy konfigurációs fájlban.
     *
     * @param string $file   Fájl.
     * @param string $method Módszer (a megjegyzés-jel miatt: `#` vagy `;`).
     * @param array  $lines  Blokk sorai; üres tömb = blokk törlése.
     * @return true|WP_Error
     */
    public static function write_block( string $file, string $method, array $lines ) {
        global $wp_filesystem;

        if ( ! function_exists( 'get_filesystem_method' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Csak közvetlen fájlelérésnél írunk: FTP-hitelesítő adatot egy
        // beállítás-mentés közben nem kérhetünk be.
        if ( 'direct' !== get_filesystem_method( array(), dirname( $file ) ) || ! WP_Filesystem() ) {
            return new WP_Error(
                'qwab_limits_fs',
                sprintf(
                    /* translators: %s: file path. */
                    __( 'Admin Booster cannot write %s on this server, so the upload limit was not raised.', 'qaiyo-admin-booster' ),
                    $file
                )
            );
        }

        // A PHP 7 óta a `#` NEM megjegyzés .ini fájlban — ott `;` kell.
        $c     = ( 'user_ini' === $method ) ? ';' : '#';
        $begin = $c . ' BEGIN ' . self::MARKER;
        $end   = $c . ' END ' . self::MARKER;

        $exists  = $wp_filesystem->exists( $file );
        $current = $exists ? (string) $wp_filesystem->get_contents( $file ) : '';

        // Meglévő blokk kivágása (bármilyen sorvéggel).
        $pattern = '#\R?' . preg_quote( $begin, '#' ) . '.*?' . preg_quote( $end, '#' ) . '\R?#s';
        $clean   = (string) preg_replace( $pattern, "\n", $current );
        $clean   = rtrim( $clean );

        if ( $lines ) {
            $block = $begin . "\n" . implode( "\n", $lines ) . "\n" . $end . "\n";
            $new   = ( '' === $clean ? '' : $clean . "\n\n" ) . $block;
        } else {
            $new = ( '' === $clean ) ? '' : $clean . "\n";
        }

        if ( $new === $current ) {
            return true;
        }

        // Ha a blokk volt az egyetlen tartalom, és a fájlt mi hoztuk létre,
        // ne hagyjunk üres fájlt.
        if ( '' === $new ) {
            return ( ! $exists || $wp_filesystem->delete( $file ) ) ? true : new WP_Error( 'qwab_limits_fs', __( 'Could not remove the upload limit from the server file.', 'qaiyo-admin-booster' ) );
        }

        if ( ! $wp_filesystem->put_contents( $file, $new, FS_CHMOD_FILE ) ) {
            return new WP_Error(
                'qwab_limits_fs',
                sprintf(
                    /* translators: %s: file path. */
                    __( 'Admin Booster cannot write %s on this server, so the upload limit was not raised.', 'qaiyo-admin-booster' ),
                    $file
                )
            );
        }

        return true;
    }

    /**
     * Mi a helyzet MOST? (A kérést kiszolgáló PHP valódi értékei alapján.)
     *
     * @return array{state:string,mb:int,wait:int}
     *         state: 'none' | 'active' | 'pending' | 'ignored'.
     */
    public static function status(): array {
        $status = get_option( self::STATUS_OPT, array() );
        if ( ! is_array( $status ) || empty( $status['mb'] ) ) {
            return array( 'state' => 'none', 'mb' => 0, 'wait' => 0 );
        }

        $mb = (int) $status['mb'];
        if ( Qwab_Module_Uploads::server_limit() >= $mb * MB_IN_BYTES ) {
            return array( 'state' => 'active', 'mb' => $mb, 'wait' => 0 );
        }

        // A .user.ini-t a PHP gyorsítótárazza, a LiteSpeed/LSAPI PHP-folyamatok
        // pedig újrahasznosulnak — addig nem hibának számít.
        $targets = isset( $status['targets'] ) && is_array( $status['targets'] ) ? $status['targets'] : array();
        $ttl     = isset( $targets['user_ini'] ) ? max( 60, (int) ini_get( 'user_ini.cache_ttl' ) ) : 60;
        $elapsed = time() - (int) $status['time'];

        if ( $elapsed < $ttl + 30 ) {
            return array( 'state' => 'pending', 'mb' => $mb, 'wait' => max( 1, $ttl + 30 - $elapsed ) );
        }

        return array( 'state' => 'ignored', 'mb' => $mb, 'wait' => 0 );
    }

    /**
     * Diagnosztika a „nem hatott" esetre — amit egy host-supporttal is meg
     * lehet osztani.
     *
     * @return array<string,string> Címke → érték.
     */
    public static function diagnostics() {
        $all    = function_exists( 'ini_get_all' ) ? ini_get_all( null, true ) : array();
        $access = function ( $key ) use ( $all ) {
            if ( ! isset( $all[ $key ]['access'] ) ) {
                return '?';
            }
            return ( (int) $all[ $key ]['access'] & INI_PERDIR ) ? 'PERDIR' : 'SYSTEM (locked)';
        };

        $files = array();
        foreach ( self::known_files() as $method => $file ) {
            $has     = file_exists( $file ) && false !== strpos( (string) file_get_contents( $file ), 'BEGIN ' . self::MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local config file, read-only diagnostic.
            $files[] = basename( $file ) . ': ' . ( $has ? 'yes' : 'no' );
        }

        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        $lsphp    = getenv( 'LSPHP_ENABLE_USER_INI' );

        return array(
            'PHP SAPI'                  => php_sapi_name() . ' (PHP ' . PHP_VERSION . ')',
            'Server software'           => '' !== $software ? $software : '—',
            'Loaded php.ini'            => (string) php_ini_loaded_file(),
            'upload_max_filesize'       => ini_get( 'upload_max_filesize' ) . ' — ' . $access( 'upload_max_filesize' ),
            'post_max_size'             => ini_get( 'post_max_size' ) . ' — ' . $access( 'post_max_size' ),
            'user_ini.filename'         => '' !== (string) ini_get( 'user_ini.filename' ) ? ini_get( 'user_ini.filename' ) . ' (cache ' . ini_get( 'user_ini.cache_ttl' ) . 's)' : '(off)',
            'LSPHP_ENABLE_USER_INI'     => false === $lsphp ? '(not set)' : (string) $lsphp,
            'Admin Booster block in'    => implode( ', ', $files ),
        );
    }

    /**
     * Eltávolításkor: a blokk törlése minden lehetséges fájlból.
     */
    public static function cleanup(): void {
        foreach ( self::cleanup_files( array() ) as $file => $method ) {
            if ( file_exists( $file ) ) {
                self::write_block( $file, $method, array() );
            }
        }
        delete_option( self::STATUS_OPT );
    }
}

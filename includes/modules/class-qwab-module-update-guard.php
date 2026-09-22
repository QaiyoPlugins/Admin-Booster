<?php
/**
 * Modul: Frissítés-védelem — automatikus visszaállítás, ha egy plugin- vagy
 * témafrissítés lefagyasztja az oldalt.
 *
 * KIINDULÓ TÉNY (a WP forrásában ellenőrizve, `class-wp-upgrader.php`):
 * a `Plugin_Upgrader::upgrade()` / `bulk_upgrade()` ÉS a `Theme_Upgrader`
 * megfelelői MINDIG átadnak egy `hook_extra['temp_backup']` tömböt, ETTŐL
 * FÜGGETLENÜL, hogy KI hívta őket — kézi kattintás a Bővítmények oldalon,
 * WP-CLI, vagy egy külső kezelőeszköz (ManageWP, MainWP) API-hívása. Emiatt
 * a `WP_Upgrader::install_package()` MINDEN frissítésnél ideiglenes
 * biztonsági másolatot csinál a régi kódról (`wp-content/upgrade-temp-backup/`),
 * mielőtt felülírná — ez a mentés tehát MINDIG ott van, csak senki nem
 * kérdezi le utána, hogy szükség volt-e rá.
 *
 * Az „ellenőrizzük, elszállt-e utána az oldal, és ha igen, állítsuk vissza”
 * lépést a WP csak a SAJÁT háttér-automatafrissítőjénél csinálja meg
 * (`WP_Automatic_Updater::has_fatal_error()`, `class-wp-automatic-updater.php` —
 * ez a metódus `protected`, innen nem hívható). Ez a metódus KIZÁRÓLAG a
 * `wp_maybe_auto_update` cron-akcióból fut le. Minden más frissítési út —
 * kézi kattintás, WP-CLI, ManageWP/MainWP és más távoli kezelők — ezt a
 * védelmet NEM kapja meg, pedig a mentés nekik is elkészül.
 *
 * VALÓS ESET, AMI EZT A MODULT INDOKOLTA: a ManageWP frissítette a WP Dark
 * Mode (free) pluginjukat 5.3.16-ra; a fizetős Dark Mode Ultimate 4.0.17 egy
 * időközben átnevezett belső osztályt keresett a régi néven → minden oldal
 * 500-as hibával állt le. A ManageWP a `Plugin_Upgrader::upgrade()`-et
 * hívta, tehát a biztonsági másolat elkészült — csak soha nem lett
 * felhasználva.
 *
 * MEGOLDÁS: a `upgrader_process_complete` hook minden frissítési útról fut
 * (ezt a WP saját automata frissítője is ide fut bele — ezért az onnan jövő
 * eseteket kihagyjuk, ott a fenti core-mechanizmus már lefut). A frissítés
 * után ugyanazt a nyilvános, core-beli technikát használjuk a fatal hiba
 * ellenőrzésére, mint amit a `has_fatal_error()` használ:
 * `wp_start_scraping_edited_file_errors()` / `wp_finalize_scraping_edited_file_errors()`
 * (`wp-includes/load.php`) — ez MINDEN kérésnél fut, egy titkos kulcs+nonce
 * párost vár a query-ben, és ha egyeznek, a válaszba csomagolva visszaadja,
 * történt-e valódi PHP fatal hiba (E_ERROR/E_PARSE/stb.) az adott betöltésen.
 * Egy hurok-kérést (`wp_remote_get`) küldünk a saját főoldalunkra ezzel a
 * kulcspárral, és ha a válasz fatalt jelez, a MÁR ELKÉSZÜLT biztonsági
 * másolatot állítjuk vissza (`WP_Upgrader::restore_temp_backup()` — ezt a
 * frissítést végző `$upgrader` objektumon hívjuk, amit a hook maga ad át).
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Update_Guard {

    /** Option: az eddigi beavatkozások naplója (legfeljebb 20). */
    const LOG_OPTION = 'qwab_update_guard_log';

    /** Option: mikor derült ki utoljára, hogy a szerver tiltja a loopback-kérést. */
    const UNVERIFIED_OPTION = 'qwab_update_guard_unverified';

    /** Mérés eredménye: az oldal betölt. */
    const OK = 'ok';

    /** Mérés eredménye: PHP fatal hiba. */
    const FATAL = 'fatal';

    /** Mérés eredménye: nem sikerült ellenőrizni (a szerver nem éri el önmagát). */
    const UNKNOWN = 'unknown';

    /**
     * Az oldal állapota a frissítés ELŐTT (kérésenként egyszer mérve).
     *
     * @var string|null
     */
    private static $baseline = null;

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        // A biztonsági mentés és a fájlcsere ELŐTT fut (install_package()),
        // amikor még a régi kód él — itt mérjük meg az előtte-állapotot.
        add_filter( 'upgrader_pre_install', array( $this, 'measure_baseline' ), 1, 2 );
        add_action( 'upgrader_process_complete', array( $this, 'check_after_update' ), 10, 2 );
    }

    /**
     * Figyelünk-e erre a frissítésre?
     *
     * @param array $hook_extra A frissítés részletei.
     * @return string '' ha nem, egyébként 'plugin' vagy 'theme'.
     */
    private static function watched_type( array $hook_extra ): string {
        if ( ! is_array( $hook_extra ) ) {
            return '';
        }
        // A WP saját háttér-automatafrissítője (`wp_maybe_auto_update`) ugyanide
        // befut, ÉS a saját `has_fatal_error()`+visszaállítását is lefuttatja —
        // ott nem mérünk feleslegesen másodszor.
        if ( doing_action( 'wp_maybe_auto_update' ) ) {
            return '';
        }
        $type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';

        // A tömeges frissítés (ezt használja a wp-admin „Frissítés" gombja is)
        // elemenként NEM ad át `type` kulcsot, csak `plugin`/`theme` +
        // `temp_backup` — a kulcsból ismerjük fel.
        if ( '' === $type ) {
            if ( ! empty( $hook_extra['plugin'] ) ) {
                $type = 'plugin';
            } elseif ( ! empty( $hook_extra['theme'] ) ) {
                $type = 'theme';
            }
        }

        return in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : '';
    }

    /**
     * Előtte-állapot mérése.
     *
     * A `upgrader_pre_install` egy tömeges frissítésnél elemenként lefut; csak
     * az elsőnél mérünk, az mutatja a frissítések ELŐTTI állapotot.
     *
     * @param bool|WP_Error $response   Továbbadandó érték.
     * @param array         $hook_extra A frissítés részletei.
     * @return bool|WP_Error Változatlanul.
     */
    public function measure_baseline( $response, $hook_extra ) {
        if ( is_wp_error( $response ) || null !== self::$baseline || '' === self::watched_type( $hook_extra ) ) {
            return $response;
        }
        // Egyes frissítésnél a `plugin`/`theme`, tömegesnél az elem kulcsa van meg.
        if ( empty( $hook_extra['plugin'] ) && empty( $hook_extra['theme'] ) ) {
            return $response;
        }

        self::$baseline = self::probe();

        return $response;
    }

    /**
     * Frissítés után lefutó ellenőrzés.
     *
     * CSAK akkor görgetünk vissza, ha az oldal a frissítés előtt BIZONYÍTOTTAN
     * működött, utána pedig elszáll. Ha már előtte is hibás volt, nem ez a
     * frissítés okozta — egy ártatlan frissítést nem vonunk vissza. Ha a szerver
     * nem éri el önmagát (loopback tiltva), semmit nem tudunk ellenőrizni, és
     * ezt jelezzük az adminnak, ahelyett hogy vakon döntenénk.
     *
     * @param WP_Upgrader $upgrader   A frissítést végző objektum.
     * @param array       $hook_extra A frissítés részletei.
     */
    public function check_after_update( $upgrader, $hook_extra ): void {
        $baseline       = self::$baseline;
        self::$baseline = null;

        if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
            return;
        }
        $type = self::watched_type( $hook_extra );
        if ( '' === $type ) {
            return;
        }

        if ( self::UNKNOWN === $baseline ) {
            update_option( self::UNVERIFIED_OPTION, time(), false );
            return;
        }
        if ( self::OK !== $baseline ) {
            return;
        }
        delete_option( self::UNVERIFIED_OPTION );

        $items = self::items_from_hook_extra( $type, $hook_extra );
        if ( ! $items || ! method_exists( $upgrader, 'restore_temp_backup' ) ) {
            return;
        }

        // A frissen felírt fájloknak és egy esetleges opcache-nek időt hagyunk
        // beállni — ugyanígy tesz a core saját `has_fatal_error()`-ja is.
        sleep( 2 );

        // Ha a loopback az imént még működött, most viszont nem kap választ
        // (pl. végtelen ciklus az új kódban), azt is hibának vesszük — ahogy a core.
        if ( self::OK === self::probe() ) {
            return;
        }

        $temp_backups = array();
        foreach ( $items as $item ) {
            $temp_backups[] = self::temp_backup_args( $type, $item );
        }

        $restore = $upgrader->restore_temp_backup( $temp_backups );

        // A visszaírt fájloknak is idő kell (opcache újraérvényesítés, ami
        // alapból 2 másodperces) — enélkül a mérés még a hibás kódot láthatja,
        // és tévesen „nem sikerült" riasztást küldenénk. Egyszer ismétlünk.
        sleep( 2 );
        $healthy = ( self::OK === self::probe() );
        if ( ! $healthy ) {
            sleep( 3 );
            $healthy = ( self::OK === self::probe() );
        }

        self::record( $type, $items, $healthy, is_wp_error( $restore ) ? $restore : null );
    }

    /**
     * Az érintett elemek (plugin fájl vagy téma-mappanév) kiolvasása a
     * hook_extra-ból — egyes és tömeges frissítésnél más-más kulcs alatt.
     *
     * @param string $type       'plugin' vagy 'theme'.
     * @param array  $hook_extra A hook adatai.
     * @return array<int,string>
     */
    private static function items_from_hook_extra( string $type, array $hook_extra ): array {
        if ( ! empty( $hook_extra[ $type ] ) && is_string( $hook_extra[ $type ] ) ) {
            return array( $hook_extra[ $type ] );
        }
        $bulk_key = $type . 's';
        if ( ! empty( $hook_extra[ $bulk_key ] ) && is_array( $hook_extra[ $bulk_key ] ) ) {
            return array_values( array_filter( $hook_extra[ $bulk_key ], 'is_string' ) );
        }

        return array();
    }

    /**
     * A `WP_Upgrader::restore_temp_backup()` várt formátuma.
     *
     * @param string $type Típus.
     * @param string $item Plugin fájl vagy téma-mappanév.
     * @return array
     */
    private static function temp_backup_args( string $type, string $item ): array {
        if ( 'plugin' === $type ) {
            return array(
                'slug' => dirname( $item ),
                'src'  => WP_PLUGIN_DIR,
                'dir'  => 'plugins',
            );
        }

        return array(
            'slug' => $item,
            'src'  => get_theme_root( $item ),
            'dir'  => 'themes',
        );
    }

    /**
     * Az oldal állapotának mérése egy friss betöltéssel.
     *
     * Ugyanazt a nyilvános core-mechanizmust használja, mint a
     * `WP_Automatic_Updater::has_fatal_error()` (az a metódus `protected`,
     * innen nem hívható) — a részletes indoklás a fájl fejlécében.
     *
     * @return string self::OK | self::FATAL | self::UNKNOWN
     */
    private static function probe(): string {
        if ( ! function_exists( 'wp_generate_password' ) ) {
            require_once ABSPATH . WPINC . '/pluggable.php';
        }

        // Tömeges frissítés közben a WP karbantartási módban van (`.maintenance`),
        // és a `wp_is_maintenance_mode()` (load.php) CSAK azt a mérő-kérést
        // engedi át, amelynek kulcsa `md5( $upgrading )`, nonce-a pedig maga az
        // `$upgrading` időbélyeg — ezért használja ezt a core is. Különben a
        // „karbantartás miatt nem elérhető" oldal jönne vissza, jelölő nélkül.
        // A fájlt nem futtatjuk le, csak kiolvassuk belőle a számot.
        $upgrading   = 0;
        $maintenance = ABSPATH . '.maintenance';
        if ( is_readable( $maintenance ) && preg_match( '/\$upgrading\s*=\s*(\d+)/', (string) file_get_contents( $maintenance ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local core-written file, read only.
            $upgrading = (int) $m[1];
        }

        if ( $upgrading && ( time() - $upgrading ) < 10 * MINUTE_IN_SECONDS ) {
            $key   = md5( (string) $upgrading );
            $nonce = (string) $upgrading;
        } else {
            $key   = substr( md5( wp_generate_password( 20, false ) ), 0, 32 );
            $nonce = wp_generate_password( 20, false );
        }
        set_transient( 'scrape_key_' . $key, $nonce, 60 );

        $url = add_query_arg(
            array(
                'wp_scrape_key'   => $key,
                'wp_scrape_nonce' => $nonce,
            ),
            home_url( '/' )
        );

        /** This filter is documented in wp-includes/class-wp-http-streams.php */
        $sslverify = apply_filters( 'https_local_ssl_verify', false, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied the same way as core's own loopback check.
        $response  = wp_remote_get(
            $url,
            array(
                'timeout'   => 30,
                'sslverify' => $sslverify,
                'headers'   => array( 'Cache-Control' => 'no-cache' ),
            )
        );
        delete_transient( 'scrape_key_' . $key );

        if ( is_wp_error( $response ) ) {
            return self::UNKNOWN;
        }

        $body  = wp_remote_retrieve_body( $response );
        $start = "###### wp_scraping_result_start:$key ######";
        $end   = "###### wp_scraping_result_end:$key ######";
        $pos   = strpos( $body, $start );

        // Nincs benne a jelölő: a válasz nem egy friss betöltésből jött (pl. egy
        // gyorsítótár szolgálta ki), így nem tudjuk, mi történt.
        if ( false === $pos ) {
            return self::UNKNOWN;
        }

        $chunk  = substr( $body, $pos + strlen( $start ) );
        $chunk  = substr( $chunk, 0, (int) strpos( $chunk, $end ) );
        $result = json_decode( trim( $chunk ), true );

        // A finalizáló csak valódi fatalnál ír `type` kulcsot (a PHP
        // hibakonstanst); sikeres betöltésnél sima `true`-t ad vissza.
        if ( is_array( $result ) && isset( $result['type'] ) ) {
            return self::FATAL;
        }

        return true === $result ? self::OK : self::UNKNOWN;
    }

    /**
     * Mikor derült ki utoljára, hogy a szerver nem tudja önmagát ellenőrizni?
     *
     * @return int 0, ha nem ismert ilyen.
     */
    public static function unverified_since(): int {
        return (int) get_option( self::UNVERIFIED_OPTION, 0 );
    }

    /* ---------------------------------------------------------------------
     * Napló + riasztás
     * ------------------------------------------------------------------- */

    /**
     * A beavatkozás feljegyzése és riasztás küldése az adminoknak.
     *
     * @param string        $type    'plugin' vagy 'theme'.
     * @param array         $items   Érintett elemek.
     * @param bool          $healthy Az oldal a visszaállítás UTÁN egészséges-e.
     * @param WP_Error|null $error   Hiba a visszaállítás során, ha volt.
     */
    private static function record( string $type, array $items, bool $healthy, ?wp_error $error ): void {
        $names = array();
        foreach ( $items as $item ) {
            $names[] = self::display_name( $type, $item );
        }

        $entry = array(
            'time'    => time(),
            'type'    => $type,
            'items'   => $names,
            'healthy' => (bool) $healthy,
            'error'   => $error ? $error->get_error_message() : '',
        );

        $log   = get_option( self::LOG_OPTION, array() );
        $log   = is_array( $log ) ? $log : array();
        array_unshift( $log, $entry );
        update_option( self::LOG_OPTION, array_slice( $log, 0, 20 ), false );

        self::alert( $entry );
    }

    /**
     * Megjelenítendő név egy elemhez (plugin/téma neve, ha kiolvasható).
     *
     * @param string $type Típus.
     * @param string $item Plugin fájl vagy téma-mappanév.
     * @return string
     */
    private static function display_name( string $type, string $item ): string {
        if ( 'plugin' === $type ) {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $file = WP_PLUGIN_DIR . '/' . $item;
            if ( file_exists( $file ) ) {
                $data = get_plugin_data( $file, false, false );
                if ( ! empty( $data['Name'] ) ) {
                    return $data['Name'];
                }
            }
            return $item;
        }

        $theme = wp_get_theme( $item );
        return $theme->exists() ? (string) $theme->get( 'Name' ) : $item;
    }

    /**
     * E-mail riasztás minden adminisztrátornak.
     *
     * Ugyanazt a címzett-listát használja, mint a frissítési e-mail
     * értesítők modulja, attól függetlenül fut, hogy az a modul be van-e
     * kapcsolva — ez egy vészhelyzeti riasztás, nem egy digest.
     *
     * @param array $entry Naplóbejegyzés.
     */
    private static function alert( array $entry ): void {
        $emails = (array) get_users(
            array(
                'role'   => 'administrator',
                'fields' => 'user_email',
            )
        );
        $emails = array_values( array_unique( array_filter( $emails, 'is_email' ) ) );
        if ( ! $emails ) {
            return;
        }

        $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $list = implode( ', ', $entry['items'] );

        if ( $entry['healthy'] ) {
            /* translators: %s: site name. */
            $subject = sprintf( __( '[%s] An update was automatically rolled back', 'qaiyo-admin-booster' ), $site );
            $body    = sprintf(
                /* translators: %s: comma-separated plugin or theme names. */
                __( 'An update just made the site crash with a fatal error. Admin Booster detected it immediately and restored the previous version — the site is back up and no plugin was left disabled. Affected: %s', 'qaiyo-admin-booster' ),
                $list
            );
        } else {
            /* translators: %s: site name. */
            $subject = sprintf( __( '[%s] An update broke the site and could not be rolled back automatically', 'qaiyo-admin-booster' ), $site );
            $body    = sprintf(
                /* translators: %s: comma-separated plugin or theme names. */
                __( 'An update just made the site crash with a fatal error, and Admin Booster could not restore the previous version automatically. Affected: %s. Use the Admin Booster Safe Mode link to get back into wp-admin, or restore the affected files from wp-content/upgrade-temp-backup/ over FTP.', 'qaiyo-admin-booster' ),
                $list
            );
            if ( $entry['error'] ) {
                $body .= "\n\n" . $entry['error'];
            }
        }

        $body .= "\n\n" . site_url();

        foreach ( $emails as $email ) {
            wp_mail( $email, $subject, $body );
        }
    }

    /**
     * A napló, az admin felülethez.
     *
     * @return array
     */
    public static function log(): array {
        $log = get_option( self::LOG_OPTION, array() );

        return is_array( $log ) ? $log : array();
    }
}

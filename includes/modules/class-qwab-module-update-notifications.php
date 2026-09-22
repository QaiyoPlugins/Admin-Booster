<?php
/**
 * Modul: Frissítési e-mail értesítések.
 *
 * Napi vagy heti összesítő e-mailt küld a site adminjainak arról, hogy mely
 * bővítmény, téma vagy a WordPress core frissítése érhető el. A levelet a WP
 * saját `wp_mail()`-je viszi — SMTP-integrációt SZÁNDÉKOSAN nem építünk:
 * ha a site-on fut SMTP plugin vagy a hosting konfigurálta a levelezést, a
 * `wp_mail()` a `phpmailer_init` hookon automatikusan azon megy át.
 *
 * A FRISSÍTÉSI ÁLLAPOT FORRÁSA A HÁROM ÉLŐ SITE TRANSIENT
 * (`update_plugins` / `update_themes` / `update_core`), amit a digest a küldés
 * pillanatában olvas ki — nem gyűjtünk külön várólistát. Ez azért jobb, mert
 * (a) ha egy frissítés a digest előtt már fel is lett telepítve (pl. auto-update),
 * akkor helyesen NEM szólunk róla, és (b) ha egy bővítmény két lépésben lép
 * (1.0 → 1.1 → 1.2), akkor a valóban aktuális 1.2-ről szólunk, nem mindkettőről.
 *
 * SPAM-VÉDELEM: a `qwab_update_notified` option tárolja, melyik elem melyik
 * verziójáról küldtünk már értesítést (`plugin:fájl` → verzió). A WP a saját
 * update-transienseit kb. 12 óránként újraírja, és a digest is többször futhat,
 * mint ahányszor új verzió jön — ezért CSAK azokról az elemekről megy levél,
 * amelyek felajánlott verziója eltér a legutóbb értesítettől.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Update_Notifications {

    /** Cron hook a digest küldéséhez. */
    const CRON_HOOK = 'qwab_update_digest';

    /** Option: elem-kulcs → utoljára értesített verzió. */
    const OPT_NOTIFIED = 'qwab_update_notified';

    /** Option: a legutóbbi sikertelen levélküldés adatai. */
    const OPT_MAIL_ERROR = 'qwab_update_mail_error';

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_digest' ) );

        add_action( 'wp_ajax_qwab_test_update_mail', array( $this, 'ajax_test_mail' ) );

        // MIÉRT NEM FIGYELJÜK A WP FRISSÍTÉS-TRANSIENSEIT:
        // a WordPress.org Plugin Check „plugin updater" kódot keres egy nyers
        // szövegregexszel a core frissítés-transiensének nevére, és ERROR-t ad
        // rá akkor is, ha csak PASSZÍVAN hallgatóznánk a hozzá tartozó
        // akción. (A regex a KOMMENTEKRE is illeszkedik, ezért nincs itt
        // kiírva a hook neve.) A Free-nek ez semmit nem adna — az összesítő a
        // cronból megy —, ezért nem kötünk rá.
        //
        // Egy „azonnali értesítés" kiterjesztés maga kapcsolódhat a core
        // akciójára, és meghívhatja a publikus `current_items()`-et; a
        // pontos hooknevet lásd a HOOKS.md-ben.
    }

    /* ---------------------------------------------------------------------
     * Ütemezés
     * ------------------------------------------------------------------- */

    /**
     * Az ütemezés összehangolása a beállításokkal.
     *
     * Statikus, mert akkor is le kell futnia, amikor a modul KI van kapcsolva
     * (ilyenkor a `Qwab_Plugin` nem példányosítja az osztályt) — különben egy
     * kikapcsolás után is ott maradna a cron-bejegyzés.
     */
    public static function sync_schedule(): void {
        if ( ! Qwab_Settings::is_module_enabled( 'update_notifications' ) ) {
            self::clear_schedule();
            return;
        }

        $frequency = self::frequency();
        $schedules = wp_get_schedules();

        // Nem cron-alapú gyakoriság (pl. egy kiterjesztés „azonnali" módja):
        // ilyenkor nincs digest-cron, a küldésről a kiterjesztés gondoskodik.
        if ( ! isset( $schedules[ $frequency ] ) ) {
            self::clear_schedule();
            return;
        }

        $event = wp_get_scheduled_event( self::CRON_HOOK );
        if ( $event ) {
            if ( isset( $event->schedule ) && $frequency === $event->schedule ) {
                return;
            }
            self::clear_schedule();
        }

        // Az első futás egy óra múlva: ne menjen levél közvetlenül a mentés
        // pillanatában (a „Teszt e-mail" gomb való az azonnali ellenőrzésre).
        wp_schedule_event( time() + HOUR_IN_SECONDS, $frequency, self::CRON_HOOK );
    }

    /**
     * A digest-cron törlése.
     */
    public static function clear_schedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ---------------------------------------------------------------------
     * Beállítások
     * ------------------------------------------------------------------- */

    /**
     * A választható digest-gyakoriságok.
     *
     * @return array<string,string> Cron schedule slug → megjelenő címke.
     */
    public static function frequencies(): array {
        $frequencies = array(
            'daily'  => __( 'Once a day', 'qaiyo-admin-booster' ),
            'weekly' => __( 'Once a week', 'qaiyo-admin-booster' ),
        );

        /**
         * Szűrő: a választható digest-gyakoriságok.
         *
         * Egy kiterjesztés adhat ide további bejegyzést. Ha a kulcs nem egy
         * valódi WP cron intervallum (lásd `wp_get_schedules()`), a Free nem
         * ütemez cront — a küldésért ilyenkor teljes egészében a kiterjesztés
         * felel (lásd HOOKS.md).
         *
         * @param array<string,string> $frequencies Slug → címke.
         */
        return (array) apply_filters( 'qwab_update_notification_frequencies', $frequencies );
    }

    /**
     * A beállított gyakoriság.
     *
     * @return string
     */
    public static function frequency(): string {
        $frequency  = (string) Qwab_Settings::get( 'update_notifications_frequency', 'daily' );
        $frequencies = self::frequencies();

        return isset( $frequencies[ $frequency ] ) ? $frequency : 'daily';
    }

    /**
     * Mely frissítés-típusokról kérünk értesítést.
     *
     * @return array<string,bool>
     */
    public static function scope(): array {
        $scope = (array) Qwab_Settings::get( 'update_notifications_scope', array( 'core', 'plugins', 'themes' ) );

        return array(
            'core'    => in_array( 'core', $scope, true ),
            'plugins' => in_array( 'plugins', $scope, true ),
            'themes'  => in_array( 'themes', $scope, true ),
        );
    }

    /* ---------------------------------------------------------------------
     * Frissítés-észlelés
     * ------------------------------------------------------------------- */

    /**
     * Az összes jelenleg elérhető frissítés, a beállított típusokra szűkítve.
     *
     * @return array<int,array<string,string>>
     */
    public static function current_items(): array {
        $scope = self::scope();
        $items = array();

        // Többsite esetén a core frissítés hálózati művelet: csak a főoldal
        // adminjait értesítjük róla, ne kapja meg minden alsite.
        if ( $scope['core'] && ( ! is_multisite() || is_main_site() ) ) {
            $items = array_merge( $items, self::core_items() );
        }
        if ( $scope['plugins'] ) {
            $items = array_merge( $items, self::plugin_items() );
        }
        if ( $scope['themes'] ) {
            $items = array_merge( $items, self::theme_items() );
        }

        /**
         * Szűrő: az értesítendő elemek listája.
         *
         * Egy kiterjesztés itt szűkítheti (pl. figyelt elemek listája) vagy
         * annotálhatja (pl. biztonsági kiadás jelölése, changelog-részlet) a
         * listát. Minden elem: type, key, name, current, new, url.
         *
         * @param array $items Elemek.
         */
        $items = (array) apply_filters( 'qwab_update_notification_items', $items );

        return array_values( array_filter( $items, array( __CLASS__, 'is_valid_item' ) ) );
    }

    /**
     * Egy elem használható-e (a szűrők után is).
     *
     * @param mixed $item Elem.
     * @return bool
     */
    public static function is_valid_item( $item ) {
        return is_array( $item )
            && ! empty( $item['key'] )
            && ! empty( $item['name'] )
            && ! empty( $item['new'] );
    }

    /**
     * A WordPress core elérhető frissítése.
     *
     * @return array<int,array<string,string>>
     */
    private static function core_items(): array {
        $data = get_site_transient( 'update_core' );
        if ( ! is_object( $data ) || empty( $data->updates ) || ! is_array( $data->updates ) ) {
            return array();
        }

        foreach ( $data->updates as $update ) {
            if ( ! is_object( $update ) || empty( $update->response ) || 'upgrade' !== $update->response ) {
                continue;
            }
            if ( empty( $update->current ) ) {
                continue;
            }

            // Csak az első felajánlott frissítés kell: a `updates` tömb
            // ugyanarra a verzióra több ajánlatot is tartalmazhat.
            return array(
                array(
                    'type'    => 'core',
                    'key'     => 'core',
                    'name'    => 'WordPress',
                    'current' => (string) get_bloginfo( 'version' ),
                    'new'     => (string) $update->current,
                    'url'     => '',
                ),
            );
        }

        return array();
    }

    /**
     * Az elérhető bővítmény-frissítések.
     *
     * @return array<int,array<string,string>>
     */
    private static function plugin_items(): array {
        $data = get_site_transient( 'update_plugins' );
        if ( ! is_object( $data ) || empty( $data->response ) || ! is_array( $data->response ) ) {
            return array();
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = get_plugins();
        $items     = array();

        foreach ( $data->response as $file => $info ) {
            if ( ! isset( $installed[ $file ] ) ) {
                continue;
            }
            $new = is_object( $info ) && ! empty( $info->new_version ) ? (string) $info->new_version : '';
            if ( '' === $new ) {
                continue;
            }

            $items[] = array(
                'type'    => 'plugin',
                'key'     => 'plugin:' . $file,
                'name'    => (string) $installed[ $file ]['Name'],
                'current' => (string) $installed[ $file ]['Version'],
                'new'     => $new,
                'url'     => self::safe_url( is_object( $info ) && ! empty( $info->url ) ? $info->url : '' ),
            );
        }

        return $items;
    }

    /**
     * Az elérhető téma-frissítések.
     *
     * @return array<int,array<string,string>>
     */
    private static function theme_items(): array {
        $data = get_site_transient( 'update_themes' );
        if ( ! is_object( $data ) || empty( $data->response ) || ! is_array( $data->response ) ) {
            return array();
        }

        $items = array();

        foreach ( $data->response as $stylesheet => $info ) {
            $theme = wp_get_theme( $stylesheet );
            if ( ! $theme->exists() ) {
                continue;
            }
            $new = is_array( $info ) && ! empty( $info['new_version'] ) ? (string) $info['new_version'] : '';
            if ( '' === $new ) {
                continue;
            }

            $items[] = array(
                'type'    => 'theme',
                'key'     => 'theme:' . $stylesheet,
                'name'    => (string) $theme->get( 'Name' ),
                'current' => (string) $theme->get( 'Version' ),
                'new'     => $new,
                'url'     => self::safe_url( is_array( $info ) && ! empty( $info['url'] ) ? $info['url'] : '' ),
            );
        }

        return $items;
    }

    /**
     * URL a frissítés-API válaszából — csak http(s) séma engedett.
     *
     * A transiens tartalma külső forrásból jön, ezért nem bízunk benne.
     *
     * @param string $url Nyers URL.
     * @return string Biztonságos URL vagy üres string.
     */
    private static function safe_url( string $url ): string {
        $url = esc_url_raw( (string) $url, array( 'http', 'https' ) );

        return $url ? $url : '';
    }

    /* ---------------------------------------------------------------------
     * Digest
     * ------------------------------------------------------------------- */

    /**
     * Az elem-kulcs → utoljára értesített verzió térkép.
     *
     * @return array<string,string>
     */
    public static function notified_map(): array {
        $map = get_option( self::OPT_NOTIFIED, array() );

        return is_array( $map ) ? $map : array();
    }

    /**
     * A cron belépési pontja: összesítő küldése, ha van miről.
     */
    public static function run_digest(): void {
        if ( ! Qwab_Settings::is_module_enabled( 'update_notifications' ) ) {
            return;
        }

        $items    = self::current_items();
        $notified = self::notified_map();
        $fresh    = array();

        foreach ( $items as $item ) {
            // Erről a PONTOS verzióról már szóltunk → kihagyjuk. Ez a
            // spam-védelem lelke: a transiensek naponta többször íródnak.
            if ( isset( $notified[ $item['key'] ] ) && (string) $notified[ $item['key'] ] === (string) $item['new'] ) {
                continue;
            }
            $fresh[] = $item;
        }

        if ( ! $fresh ) {
            self::save_notified( self::prune_notified( $notified, $items ) );
            return;
        }

        // Ha a küldés nem sikerült, NEM jegyezzük fel — a következő digest
        // újra próbálja, a hibát pedig a beállító oldalon jelezzük.
        if ( ! self::send( $fresh ) ) {
            return;
        }

        foreach ( $fresh as $item ) {
            $notified[ $item['key'] ] = (string) $item['new'];
        }
        self::save_notified( self::prune_notified( $notified, $items ) );
    }

    /**
     * A már nem aktuális bejegyzések kitakarítása a térképből.
     *
     * @param array $notified Térkép.
     * @param array $items    A jelenleg elérhető frissítések.
     * @return array<string,string>
     */
    private static function prune_notified( array $notified, array $items ): array {
        $live = array();
        foreach ( $items as $item ) {
            $live[ $item['key'] ] = true;
        }

        return array_intersect_key( $notified, $live );
    }

    /**
     * A térkép mentése (autoload nélkül — csak a cron és az admin olvassa).
     *
     * @param array $map Térkép.
     */
    private static function save_notified( array $map ): void {
        update_option( self::OPT_NOTIFIED, $map, false );
    }

    /* ---------------------------------------------------------------------
     * Küldés
     * ------------------------------------------------------------------- */

    /**
     * A címzettek: a site minden adminisztrátora.
     *
     * @return array<int,string>
     */
    public static function recipients(): array {
        $emails = (array) get_users(
            array(
                'role'   => 'administrator',
                'fields' => 'user_email',
            )
        );

        if ( ! $emails ) {
            $emails = array( get_option( 'admin_email' ) );
        }

        /**
         * Szűrő: a frissítési értesítők címzettjei.
         *
         * @param array $emails E-mail címek.
         */
        $emails = (array) apply_filters( 'qwab_update_notification_recipients', $emails );

        // Kisbetűs kulccsal dedupláljuk, de az ELSŐ előfordulás írásmódját
        // tartjuk meg — így a lista sorrendje és alakja kiszámítható.
        $clean = array();
        foreach ( $emails as $email ) {
            $email = sanitize_email( (string) $email );
            if ( ! is_email( $email ) ) {
                continue;
            }
            $key = strtolower( $email );
            if ( ! isset( $clean[ $key ] ) ) {
                $clean[ $key ] = $email;
            }
        }

        return array_values( $clean );
    }

    /**
     * Az összesítő elküldése.
     *
     * @param array $items Az értesítendő elemek.
     * @return bool Sikerült-e legalább egy címzettnek kiküldeni.
     */
    private static function send( array $items ): bool {
        $recipients = self::recipients();
        if ( ! $recipients ) {
            return false;
        }

        /**
         * Szűrő: menjen-e egyáltalán e-mail.
         *
         * Egy kiterjesztés false-ra állíthatja, ha csak más csatornán
         * (pl. webhook) akar értesíteni.
         *
         * @param bool  $send  Alapértelmezés: igen.
         * @param array $items Elemek.
         */
        $send_email = (bool) apply_filters( 'qwab_update_notification_send_email', true, $items );

        $sent   = 0;
        $failed = array();

        if ( $send_email ) {
            $subject = self::subject( $items );
            $message = self::message( $items );
            $headers = array( 'Content-Type: text/html; charset=UTF-8' );

            // Címzettenként külön levél: így egy hibás cím nem viszi el az
            // egész kiküldést, és az adminok e-mail címe sem szivárog ki
            // egymásnak a To: fejlécben.
            foreach ( $recipients as $email ) {
                if ( wp_mail( $email, $subject, $message, $headers ) ) {
                    ++$sent;
                } else {
                    $failed[] = $email;
                }
            }

            self::record_mail_result( $failed, count( $recipients ) );
        }

        /**
         * Akció: az értesítés kiküldése megtörtént.
         *
         * Ide kapcsolódhat egy kiterjesztés, ha e-mail helyett vagy mellett
         * más csatornára (pl. Slack/Discord webhook) is küldeni akar.
         *
         * @param array $items  Az értesített elemek.
         * @param int   $sent   Hány címzettnek sikerült kiküldeni.
         * @param array $failed A sikertelen címzettek.
         */
        do_action( 'qwab_update_notification_sent', $items, $sent, $failed );

        // Ha a levelezést egy kiterjesztés kikapcsolta, ő felel a küldésért —
        // ilyenkor is feljegyezhetjük az elemeket értesítettként.
        return $send_email ? ( $sent > 0 ) : true;
    }

    /**
     * A levél tárgya.
     *
     * @param array $items Elemek.
     * @return string
     */
    private static function subject( array $items ): string {
        $count = count( $items );
        $name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        // Két külön egyes számú string, `_n()` helyett: a bundle-elt fordítások
        // build scriptje nem kezel `msgid_plural`-t, és egyetlen többes számú
        // hívás miatt nem érdemes 10 nyelv plural-szabályát bevezetni. Egy
        // elemnél amúgy is beszédesebb kiírni, MI frissült.
        if ( 1 === $count ) {
            $item    = reset( $items );
            $subject = sprintf(
                /* translators: 1: name of the plugin, theme or WordPress core, 2: available version number, 3: site name. */
                __( 'Update available: %1$s %2$s on %3$s', 'qaiyo-admin-booster' ),
                $item['name'],
                $item['new'],
                $name
            );
        } else {
            $subject = sprintf(
                /* translators: 1: number of available updates, 2: site name. */
                __( '%1$d updates available on %2$s', 'qaiyo-admin-booster' ),
                $count,
                $name
            );
        }

        /**
         * Szűrő: a frissítési értesítő tárgya.
         *
         * @param string $subject Tárgy.
         * @param array  $items   Elemek.
         */
        return (string) apply_filters( 'qwab_update_notification_subject', $subject, $items );
    }

    /**
     * A levél HTML törzse.
     *
     * @param array $items Elemek.
     * @return string
     */
    private static function message( array $items ): string {
        $groups = array(
            'core'   => array(
                'label' => __( 'WordPress core', 'qaiyo-admin-booster' ),
                'items' => array(),
            ),
            'plugin' => array(
                'label' => __( 'Plugins', 'qaiyo-admin-booster' ),
                'items' => array(),
            ),
            'theme'  => array(
                'label' => __( 'Themes', 'qaiyo-admin-booster' ),
                'items' => array(),
            ),
        );

        foreach ( $items as $item ) {
            $type = isset( $item['type'] ) && isset( $groups[ $item['type'] ] ) ? $item['type'] : 'plugin';
            $groups[ $type ]['items'][] = $item;
        }

        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $out       = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#1d2327;">';

        $out .= '<p style="margin:0 0 16px;">' . esc_html(
            sprintf(
                /* translators: %s: site name. */
                __( 'The following updates are available on %s:', 'qaiyo-admin-booster' ),
                $site_name
            )
        ) . '</p>';

        foreach ( $groups as $group ) {
            if ( ! $group['items'] ) {
                continue;
            }

            $out .= '<p style="margin:20px 0 6px;font-weight:600;">' . esc_html( $group['label'] ) . '</p>';
            $out .= '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;border-collapse:collapse;">';

            foreach ( $group['items'] as $item ) {
                $name = esc_html( $item['name'] );
                if ( ! empty( $item['url'] ) ) {
                    $name = '<a href="' . esc_url( $item['url'] ) . '" style="color:#2271b1;">' . $name . '</a>';
                }

                $security = '';
                // A `security` jelölést egy kiterjesztés teheti az elemre a
                // `qwab_update_notification_items` szűrővel.
                if ( ! empty( $item['security'] ) ) {
                    $security = ' <strong style="color:#b32d2e;">'
                        . esc_html__( 'Security release', 'qaiyo-admin-booster' )
                        . '</strong>';
                }

                $out .= '<tr>';
                $out .= '<td style="padding:6px 12px 6px 0;border-bottom:1px solid #f0f0f1;">' . $name . $security . '</td>';
                // Verzió → verzió: puszta számpár, nincs fordítandó szöveg.
                $out .= '<td style="padding:6px 0;border-bottom:1px solid #f0f0f1;color:#646970;white-space:nowrap;">'
                    . esc_html( $item['current'] ) . ' &rarr; ' . esc_html( $item['new'] )
                    . '</td>';
                $out .= '</tr>';

                if ( ! empty( $item['excerpt'] ) ) {
                    $out .= '<tr><td colspan="2" style="padding:0 0 10px;color:#646970;">'
                        . esc_html( (string) $item['excerpt'] ) . '</td></tr>';
                }
            }

            $out .= '</table>';
        }

        $out .= '<p style="margin:24px 0 0;"><a href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '" style="color:#2271b1;font-weight:600;">'
            . esc_html__( 'Review and install updates', 'qaiyo-admin-booster' ) . '</a></p>';

        $out .= '<p style="margin:24px 0 0;font-size:12px;color:#8c8f94;">' . esc_html__( 'You are receiving this because you are an administrator of this site. You can change or switch off these notifications under Admin Booster → Update notifications.', 'qaiyo-admin-booster' ) . '</p>';

        $out .= '</div>';

        /**
         * Szűrő: a frissítési értesítő HTML törzse.
         *
         * @param string $out   HTML.
         * @param array  $items Elemek.
         */
        return (string) apply_filters( 'qwab_update_notification_message', $out, $items );
    }

    /* ---------------------------------------------------------------------
     * Hiba-visszajelzés
     * ------------------------------------------------------------------- */

    /**
     * A küldés eredményének feljegyzése.
     *
     * A `wp_mail()` true visszatérése CSAK azt jelenti, hogy a levelet átadtuk
     * a levelező rendszernek — a kézbesítést nem garantálja. A false viszont
     * biztos hiba, ezt megmutatjuk a beállító oldalon.
     *
     * @param array $failed A sikertelen címzettek.
     * @param int   $total  Az összes címzett száma.
     */
    private static function record_mail_result( array $failed, int $total ): void {
        if ( ! $failed ) {
            delete_option( self::OPT_MAIL_ERROR );
            return;
        }

        update_option(
            self::OPT_MAIL_ERROR,
            array(
                'time'   => time(),
                'failed' => count( $failed ),
                'total'  => (int) $total,
            ),
            false
        );
    }

    /**
     * A legutóbbi levélküldési hiba (vagy null).
     *
     * @return array|null
     */
    public static function last_mail_error(): ?array {
        $error = get_option( self::OPT_MAIL_ERROR, null );

        return is_array( $error ) && ! empty( $error['failed'] ) ? $error : null;
    }

    /* ---------------------------------------------------------------------
     * Teszt e-mail
     * ------------------------------------------------------------------- */

    /**
     * AJAX: teszt e-mail a bejelentkezett adminnak.
     *
     * Azért kell, mert a `wp_mail()` csendben is elhalhat (nincs SMTP, a
     * hosting tiltja a `mail()`-t stb.) — így a felhasználó azonnal látja,
     * működik-e a levelezés, anélkül hogy a következő digestre kellene várnia.
     */
    public function ajax_test_mail(): void {
        check_ajax_referer( 'qwab_test_update_mail' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You are not allowed to send test emails.', 'qaiyo-admin-booster' ) ),
                403
            );
        }

        $user = wp_get_current_user();
        if ( ! $user || ! is_email( $user->user_email ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Your user account has no valid email address.', 'qaiyo-admin-booster' ) )
            );
        }

        $items = self::current_items();
        if ( $items ) {
            $subject = self::subject( $items );
            $message = self::message( $items );
        } else {
            $subject = sprintf(
                /* translators: %s: site name. */
                __( 'Update notifications are working on %s', 'qaiyo-admin-booster' ),
                wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
            );
            $message = '<p>' . esc_html__( 'This is a test email from Qaiyo Admin Booster. Update notifications are set up correctly — there are no pending updates right now.', 'qaiyo-admin-booster' ) . '</p>';
        }

        $sent = wp_mail(
            $user->user_email,
            $subject,
            $message,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );

        if ( ! $sent ) {
            wp_send_json_error(
                array(
                    'message' => __( 'WordPress could not hand the email over to your mail system. Check your hosting mail settings, or install an SMTP plugin.', 'qaiyo-admin-booster' ),
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %s: email address the test was sent to. */
                    __( 'Test email sent to %s. If it does not arrive, check your spam folder — mail handed over successfully can still be filtered.', 'qaiyo-admin-booster' ),
                    $user->user_email
                ),
            )
        );
    }
}

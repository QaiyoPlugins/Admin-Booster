<?php
/**
 * Qaiyo Admin Booster — Uninstall script.
 *
 * Csak akkor fut, amikor a plugint TÖRLI a felhasználó.
 *
 * SZÁNDÉKOSAN MEGTARTOTT ADAT: a tartalomhoz tartozó poszt-meták
 * (`_qwab_collection` = kollekció-hozzárendelés, `_qwab_nav_visibility` =
 * menüelem láthatósága). Ezek a felhasználó saját tartalmi döntései, nem
 * plugin-beállítások — egy újratelepítés után visszakapja őket, törölni
 * viszont nem lehetne visszacsinálni. A plugin minden SAJÁT option-jét,
 * transientjét és a kiírt szerver-konfigurációt viszont eltakarítjuk.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Egy webhely (single site, vagy multisite egy alszájtja) saját adatai.
 *
 * Minden itt felsorolt option/transient a `get_option()` szintjén él, ezért
 * multisite-on webhelyenként külön kell törölni — a `delete_option()` mindig
 * csak az AKTUÁLIS webhely tábláját érinti.
 */
function qwab_uninstall_site_data() {
    $options = array(
        'qwab_settings',
        'qwab_collections',
        // Frissítési értesítések állapota (mely verzióról szóltunk már, levélhiba).
        'qwab_update_notified',
        'qwab_update_mail_error',
        // Frissítés-védelem naplója és a „nem ellenőrizhető" jelzés.
        'qwab_update_guard_log',
        'qwab_update_guard_unverified',
        // A szerver-limit írás állapota. A fájlokat a Server_Limits::cleanup()
        // takarítja (fájlrendszer = hálózatonként egy), de ez az option
        // webhelyenként külön él.
        'qwab_server_limits_status',
    );
    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Gyorsítótárak: a frissítés-központ normalizált sorai és a Qaiyo
    // plugin-katalógus wp.org-ról kérdezett listája.
    delete_transient( 'qwab_uc_rows' );
    delete_transient( 'qwab_more_plugins_wporg' );

    // A digest cron-bejegyzése a deaktiváláskor már eltűnik, de ha a plugint
    // fájlszinten törölték deaktiválás nélkül, itt kapjuk el.
    wp_clear_scheduled_hook( 'qwab_update_digest' );
}

// A szerver feltöltési limitjéhez írt .user.ini / .htaccess blokk törlése —
// ne maradjon utánunk szerver-konfiguráció. Fájlszintű, hálózatonként egyszer.
require_once __DIR__ . '/includes/class-qwab-server-limits.php';
Qwab_Server_Limits::cleanup();

// A Safe Mode mu-plugin, a titok és a napló. Multisite-on site option-ökben
// él (hálózati szint), ezért szintén egyszer fut.
require_once __DIR__ . '/includes/class-qwab-safe-mode.php';
Qwab_Safe_Mode::cleanup();

if ( is_multisite() ) {
    // Hálózaton minden webhely külön option-táblát használ; a plugin sosem ír
    // `update_site_option()`-t a beállításaihoz (csak a Safe Mode teszi, azt a
    // saját cleanup()-ja intézi), ezért itt webhelyenként takarítunk.
    $qwab_site_ids = get_sites(
        array(
            'fields'                 => 'ids',
            'number'                 => 0,
            'update_site_meta_cache' => false,
        )
    );
    foreach ( $qwab_site_ids as $qwab_site_id ) {
        switch_to_blog( $qwab_site_id );
        qwab_uninstall_site_data();
        restore_current_blog();
    }
} else {
    qwab_uninstall_site_data();
}

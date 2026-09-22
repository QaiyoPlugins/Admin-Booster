<?php
/**
 * Plugin Name: Qaiyo Admin Booster
 * Plugin URI: https://qaiyo-plugins.com/qaiyo-admin-booster/
 * Description: Fixes the annoying wp-admin UX/UI rough edges that cost you time every day: add a page or post to a navigation menu straight from the editor, page collections and visual filters, 1-click plugin upload, an always-open Gutenberg block inserter, quick admin navigation, larger list views, full upload size &amp; file-type handling (SVG/WebP/AVIF), a tidy notices tray, dashboard update &amp; comments widgets, bulk page creation and more — each a module you can switch on or off.
 * Version: 1.4.6
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Qaiyo
 * Author URI: https://qaiyo-plugins.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qaiyo-admin-booster
 * Domain Path: /languages
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'QWAB_VERSION', '1.4.6' );
define( 'QWAB_FILE', __FILE__ );
define( 'QWAB_PATH', plugin_dir_path( __FILE__ ) );
define( 'QWAB_URL', plugin_dir_url( __FILE__ ) );
define( 'QWAB_BASENAME', plugin_basename( __FILE__ ) );

require_once QWAB_PATH . 'includes/class-qwab-brand-menu.php';
require_once QWAB_PATH . 'includes/class-qwab-more-plugins.php';
Qwab_More_Plugins::register( array( 'menu_parent' => 'qaiyo-admin-booster' ) );
require_once QWAB_PATH . 'includes/class-qwab-settings.php';
require_once QWAB_PATH . 'includes/class-qwab-pro-catalog.php';
require_once QWAB_PATH . 'includes/class-qwab-pro-teaser.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-plugin-upload.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-gutenberg.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-quick-add.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-list-per-page.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-uploads.php';
require_once QWAB_PATH . 'includes/class-qwab-server-limits.php';
Qwab_Server_Limits::init();
require_once QWAB_PATH . 'includes/class-qwab-safe-mode.php';
Qwab_Safe_Mode::init();
add_action( 'admin_init', array( 'Qwab_Safe_Mode', 'migrate' ), 1 );
require_once QWAB_PATH . 'includes/modules/class-qwab-module-page-collections.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-page-filter.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-admin-notices.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-dashboard-cleanup.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-scheduled-countdown.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-sticky-headers.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-last-editor.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-quick-status.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-bulk-create.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-update-center.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-comments-widget.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-dashboard-greeting.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-menu-manager.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-menu-visibility.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-ecosystem-widgets.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-plugin-update-groups.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-update-notifications.php';
require_once QWAB_PATH . 'includes/modules/class-qwab-module-update-guard.php';
require_once QWAB_PATH . 'includes/class-qwab-ecosystem-panel.php';
require_once QWAB_PATH . 'includes/class-qwab-admin.php';
require_once QWAB_PATH . 'includes/class-qwab-plugin.php';

Qwab_Brand_Menu::init();
Qwab_Brand_Menu::register_plugin_slug( 'qaiyo-admin-booster' );

// „Qaiyo felfedezése” oldal + új plugin / frissítés értesítők.

/**
 * Bootstrap.
 */
function qwab_init(): void {
    Qwab_Plugin::get_instance();
}
add_action( 'plugins_loaded', 'qwab_init' );

/**
 * Fordítások betöltése (Qaiyo i18n szabvány).
 *
 * load_textdomain() (NEM load_plugin_textdomain()), hogy a Plugin Check ne
 * adjon DiscouragedFunctions WARNING-ot. Variáns locale-ok leképezve az
 * 5 gyári nyelvre, minden más → angol fallback.
 */
function qwab_load_translations(): void {
    $locale = determine_locale();

    $locale_map = array(
        'de_CH'        => 'de_DE',
        'de_AT'        => 'de_DE',
        'de_DE_formal' => 'de_DE',
        'fr_BE'        => 'fr_FR',
        'fr_CA'        => 'fr_FR',
        'fr_CH'        => 'fr_FR',
        'es_MX'        => 'es_ES',
        'es_AR'        => 'es_ES',
        'es_CO'        => 'es_ES',
        'es_CL'        => 'es_ES',
        'es_PE'        => 'es_ES',
        'es_VE'        => 'es_ES',
        // Japán: a .mo fájlnév „ja” (NEM ja_JP).
        'ja_JP'        => 'ja',
        // Portugál variánsok → pt_PT (a brazil pt_BR is ide esik vissza).
        'pt_BR'        => 'pt_PT',
        // Orosz variáns.
        'ru_UA'        => 'ru_RU',
    );

    if ( isset( $locale_map[ $locale ] ) ) {
        $locale = $locale_map[ $locale ];
    }

    $supported = array( 'en_US', 'hu_HU', 'de_DE', 'fr_FR', 'es_ES', 'ja', 'pt_PT', 'it_IT', 'ru_RU', 'tr_TR', 'pl_PL' );
    if ( ! in_array( $locale, $supported, true ) || 'en_US' === $locale ) {
        return;
    }

    $mofile = QWAB_PATH . 'languages/qaiyo-admin-booster-' . $locale . '.mo';
    if ( file_exists( $mofile ) ) {
        load_textdomain( 'qaiyo-admin-booster', $mofile );
    }
}
add_action( 'init', 'qwab_load_translations', 1 );

/**
 * Aktiváláskor: alapértelmezett beállítások beírása, ha még nincsenek.
 */
function qwab_activate(): void {
    if ( false === get_option( 'qwab_settings', false ) ) {
        add_option( 'qwab_settings', Qwab_Settings::defaults() );
    }
    Qwab_Module_Update_Notifications::sync_schedule();
}
register_activation_hook( __FILE__, 'qwab_activate' );

/**
 * Deaktiváláskor: a frissítési digest cron-bejegyzésének eltávolítása.
 *
 * Cron-esemény nélkül a WP hiába hívná a hookot — a deaktivált plugin
 * callbackje már nincs bekötve, a bejegyzés viszont ott maradna.
 */
function qwab_deactivate(): void {
    Qwab_Module_Update_Notifications::clear_schedule();
    Qwab_Safe_Mode::deactivate();
}
register_deactivation_hook( __FILE__, 'qwab_deactivate' );

/**
 * A frissítési digest ütemezésének összehangolása a beállításokkal.
 *
 * Mindig bekötjük (nem csak a modul aktív állapotában), mert a modul
 * KIkapcsolása után is el kell tüntetni a cron-bejegyzést — a modul osztályát
 * ilyenkor a `Qwab_Plugin` már nem példányosítja.
 */
add_action( 'admin_init', array( 'Qwab_Module_Update_Notifications', 'sync_schedule' ) );

/**
 * Pro aktív-e?
 *
 * Free↔Pro contract: a Pro plugin aktiváláskor add_filter-rel true-ra állítja.
 * A Free admin ez alapján dönt, hogy lakatos teasert vagy valódi Pro UI-t mutat.
 *
 * @return bool
 */
function qwab_is_pro_active(): bool {
    return (bool) apply_filters( 'qwab_pro_active', false );
}

/**
 * A feloldott Pro modulok slug-listája — EGY forrás, a Free ezt használja
 * mindenhol (a katalógus is).
 *
 * A Free maga sosem old fel semmit: az alapérték üres tömb. A listát a Pro
 * plugin tölti fel a `qwab_pro_unlocked_modules` szűrőn, és csak akkor, ha a
 * licence aktív — a Free-ben szándékosan NINCS licenc-ellenőrzés (WP.org
 * Guideline 5: a Free nem tartalmazhat licenc- vagy trial-logikát).
 *
 * A szűrő EGY paramétert kap (a lista). Korábban a két hívási hely eltérő
 * paraméterszámmal hívta, ami egy 2 paraméteres callbacknél PHP 8-on
 * ArgumentCountError-t okozott.
 *
 * @return array<int,string>
 */
function qwab_pro_unlocked_modules(): array {
    if ( ! qwab_is_pro_active() ) {
        return array();
    }
    $unlocked = apply_filters( 'qwab_pro_unlocked_modules', array() );
    return is_array( $unlocked ) ? $unlocked : array();
}

/**
 * Egy adott Pro modul fel van-e oldva?
 *
 * @param string $module_slug pl. "dark-mode", "pinned-pages".
 * @return bool
 */
function qwab_is_pro_module_unlocked( string $module_slug ): bool {
    return in_array( $module_slug, qwab_pro_unlocked_modules(), true );
}

/**
 * Upgrade URL — a Pro termékoldal a Qaiyo brand domainen.
 *
 * @return string
 */
function qwab_upgrade_url(): string {
    return apply_filters( 'qwab_upgrade_url', 'https://qaiyo-plugins.com' );
}

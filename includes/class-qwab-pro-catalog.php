<?php
/**
 * Pro funkció katalógus — a Free birtokolja a teljes Pro modul-listát
 * (slug, név, leírás, ikon), és lakatos teaserként rendereli őket az adminban.
 * A Pro plugin a `qwab_pro_unlocked_modules` filterrel jelzi, mely modulok
 * vannak feloldva — ezeket a Free nem mutatja lakatosan.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Pro_Catalog {

    /**
     * Az összes ismert Pro modul katalógusa.
     *
     * @return array<string,array{title:string,desc:string,icon:string}>
     */
    public static function all(): array {
        $modules = array(
            'core-updater' => array(
                'title' => __( 'Core update bridge', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Stuck on an old WordPress because the newest release needs a newer PHP? Pick an intermediate version your server can actually run and step up to it — straight from wp-admin, no FTP or cPanel needed.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-update',
            ),
            'collection-rules' => array(
                'title' => __( 'Smart collection rules', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Auto-assign pages and posts to collections by URL pattern, page template, parent or regex — new content lands in the right collection automatically, no manual sorting.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-randomize',
            ),
            'pinned-pages' => array(
                'title' => __( 'Pinned pages & favorites', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Pin the pages and posts you touch every day to the admin bar and dashboard for one-click access from anywhere in wp-admin.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-sticky',
            ),
            'saved-filter-views' => array(
                'title' => __( 'Saved filter views', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Save any combination of Page Filter conditions (orphan + noindex + draft, etc.) as a named view and switch between them with one click.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-filter',
            ),
            'bulk-seo' => array(
                'title' => __( 'Bulk noindex & menu actions', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Select multiple pages from the filtered view and toggle noindex, add them to a menu, or change status in one bulk action.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-editor-ol',
            ),
            'role-uploads' => array(
                'title' => __( 'Per-role upload rules', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Allow SVG only for administrators, raise the upload limit for editors, or restrict risky file types per user role — granular control instead of one global rule.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-groups',
            ),
            'svg-sanitize' => array(
                'title' => __( 'SVG sanitization on upload', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Automatically strip scripts and event handlers from SVG files on upload, so you can enable SVG safely even for non-admin users.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-shield-alt',
            ),
            'menu-editor' => array(
                'title' => __( 'Admin menu editor', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Rename, reorder and hide wp-admin menu items per user role — give editors and clients a clean, focused admin without touching code.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-menu-alt3',
            ),
            'inline-edit' => array(
                'title' => __( 'Inline list-table editing', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Edit titles, statuses, collections and custom fields straight in the list table — spreadsheet-style, without opening each item.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-editor-table',
            ),
            'login-customizer' => array(
                'title' => __( 'Login screen customizer', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Replace the WordPress logo, background and colors on the login screen with your own brand, plus custom CSS — no extra plugin needed.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-admin-network',
            ),
            'activity-log' => array(
                'title' => __( 'Activity log', 'qaiyo-admin-booster' ),
                'desc'  => __( 'A clear audit trail of who changed what and when — posts, settings, users and uploads — so nothing happens on your site unnoticed.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-backup',
            ),
            'dark-mode' => array(
                'title' => __( 'Admin dark mode', 'qaiyo-admin-booster' ),
                'desc'  => __( 'A polished dark theme for the whole wp-admin, with a one-click toggle in the admin bar and a per-user preference.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-lightbulb',
            ),
            'revision-cleaner' => array(
                'title' => __( 'Revision cleaner', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Limit how many revisions each post keeps and bulk-delete old ones to shrink a bloated database and speed up your admin.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-database',
            ),
            'notify-targets' => array(
                'title' => __( 'Targeted update alerts', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Pick exactly which plugins and themes you want to hear about, get security releases flagged in the email, and see a slice of the changelog next to each item.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-yes-alt',
            ),
            'notify-channels' => array(
                'title' => __( 'Instant & Slack update alerts', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Send update alerts the moment they appear instead of waiting for the daily digest, push them to a Slack or Discord webhook, and set your own sender name and address.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-megaphone',
            ),
            'nav-breakpoint' => array(
                'title' => __( 'Custom menu visibility breakpoint', 'qaiyo-admin-booster' ),
                'desc'  => __( 'Mobile-only and desktop-only menu items switch over at 768px. Set the breakpoint your theme actually uses instead, so a menu item appears exactly where the theme switches to its mobile menu.', 'qaiyo-admin-booster' ),
                'icon'  => 'dashicons-smartphone',
            ),
        );

        /**
         * Filter: qwab_pro_catalog
         *
         * @param array $modules Pro modul katalógus.
         */
        return apply_filters( 'qwab_pro_catalog', $modules );
    }

    /**
     * A még lakatos (= Pro alatt nem feloldott) modulok.
     *
     * @return array<string,array>
     */
    public static function locked(): array {
        $all      = self::all();
        $unlocked = qwab_pro_unlocked_modules();

        $locked = array();
        foreach ( $all as $slug => $data ) {
            if ( ! in_array( $slug, $unlocked, true ) ) {
                $locked[ $slug ] = $data;
            }
        }
        return $locked;
    }

    /**
     * Van-e legalább egy lakatos modul (= van mit teaserelni)?
     *
     * @return bool
     */
    public static function has_locked(): bool {
        return ! empty( self::locked() );
    }

    /**
     * Pro termékoldal URL.
     *
     * @return string
     */
    public static function upgrade_url(): string {
        return qwab_upgrade_url();
    }
}

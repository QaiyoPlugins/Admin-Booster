<?php
/**
 * Fő plugin osztály — betölti az engedélyezett modulokat és az admin UI-t.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Plugin {

    /** @var Qwab_Plugin|null */
    private static $instance = null;

    /** @var Qwab_Admin|null */
    public $admin = null;

    /** @var array<string,object> Betöltött modulok. */
    public $modules = array();

    /**
     * Modul-slug → osztálynév térkép.
     *
     * @return array<string,string>
     */
    private function module_map(): array {
        return array(
            'plugin_upload'    => 'Qwab_Module_Plugin_Upload',
            'gutenberg'        => 'Qwab_Module_Gutenberg',
            'quick_add'        => 'Qwab_Module_Quick_Add',
            'list_per_page'    => 'Qwab_Module_List_Per_Page',
            'uploads'          => 'Qwab_Module_Uploads',
            'page_collections' => 'Qwab_Module_Page_Collections',
            'page_filter'      => 'Qwab_Module_Page_Filter',
            'admin_notices'    => 'Qwab_Module_Admin_Notices',
            'dashboard_cleanup' => 'Qwab_Module_Dashboard_Cleanup',
            'scheduled_countdown' => 'Qwab_Module_Scheduled_Countdown',
            'sticky_headers'   => 'Qwab_Module_Sticky_Headers',
            'last_editor'      => 'Qwab_Module_Last_Editor',
            'quick_status'     => 'Qwab_Module_Quick_Status',
            'bulk_create'      => 'Qwab_Module_Bulk_Create',
            'update_center'    => 'Qwab_Module_Update_Center',
            'comments_widget'  => 'Qwab_Module_Comments_Widget',
            'dashboard_greeting' => 'Qwab_Module_Dashboard_Greeting',
            'menu_manager'       => 'Qwab_Module_Menu_Manager',
            'menu_visibility'    => 'Qwab_Module_Menu_Visibility',
            'ecosystem_widgets'  => 'Qwab_Module_Ecosystem_Widgets',
            'plugin_update_groups' => 'Qwab_Module_Plugin_Update_Groups',
            'update_notifications' => 'Qwab_Module_Update_Notifications',
            'update_guard'         => 'Qwab_Module_Update_Guard',
        );
    }

    /**
     * @return Qwab_Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_modules();

        if ( is_admin() ) {
            $this->admin = new Qwab_Admin();
            $this->admin->register();
        }
    }

    /**
     * Az engedélyezett modulok példányosítása és bekötése.
     *
     * A modulok a `register()`-ben kötik be a hook-jaikat, nem a
     * konstruktorban — így példányosíthatók mellékhatás nélkül is.
     */
    private function load_modules(): void {
        foreach ( $this->module_map() as $slug => $class ) {
            if ( ! Qwab_Settings::is_module_enabled( $slug ) ) {
                continue;
            }
            $module = new $class();
            $module->register();
            $this->modules[ $slug ] = $module;
        }
    }
}

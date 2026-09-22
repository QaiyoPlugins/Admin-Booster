<?php
/**
 * Qaiyo brand menu — wraps the Qaiyo plugin group with a Crocoblock-style chip
 * separator on TOP and a thin line separator on the BOTTOM, in the WP admin sidebar.
 *
 * Each Qaiyo plugin ships a copy of this class with its own prefix, but they all
 * coordinate through shared $GLOBALS so the separators wrap the whole group
 * regardless of which plugin loads first.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Qwab_Brand_Menu' ) ) {

    class Qwab_Brand_Menu {

        const TOP_SLUG    = 'qaiyo-brand-separator-top';
        const BOTTOM_SLUG = 'qaiyo-brand-separator-bottom';

        /** Hint position used if the Qaiyo plugin doesn't have a strong opinion. */
        const HINT_POSITION = 25.99;

        private static $plugin_count = 0;

        /**
         * Each Qaiyo plugin registers its top-level menu slug here, so the brand
         * separator can locate the group at admin_menu time regardless of where
         * WordPress ultimately placed it. Shared across plugins via $GLOBALS.
         *
         * @param string $slug e.g. "qaiyo-admin-booster".
         */
        private static $brand_slugs = array();
        private static $brand_filter_added = false;

        public static function register_plugin_slug( string $slug ): void {
        	if ( $slug && ! in_array( $slug, self::$brand_slugs, true ) ) {
        		self::$brand_slugs[] = $slug;
        	}
        	if ( ! self::$brand_filter_added ) {
        		self::$brand_filter_added = true;
        		add_filter( 'qaiyo_brand_menu_slugs', array( __CLASS__, 'contribute_brand_slugs' ) );
        	}
        }

        /** Adds this copy's slugs to the cross-plugin collection. */
        public static function contribute_brand_slugs( $slugs ) {
        	return array_merge( (array) $slugs, self::$brand_slugs );
        }

        /**
         * Optional hint for menu_position. The actual placement is computed
         * dynamically by inject_separators().
         */
        public static function plugin_position() {
            self::$plugin_count++;
            return self::HINT_POSITION + ( 0.0001 * self::$plugin_count );
        }

        public static function init(): void {
            add_action( 'admin_menu', array( __CLASS__, 'inject_separators' ), 999 );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_css' ), 999 );
        }

        public static function inject_separators(): void {
            global $menu;
            if ( ! is_array( $menu ) ) {
                return;
            }

            // Only the first loaded copy renders the group; the rest stand down.
            if ( did_action( 'qaiyo_brand_menu_injected' ) ) {
            	return;
            }

            $slugs = array_unique( (array) apply_filters( 'qaiyo_brand_menu_slugs', array() ) );
            if ( empty( $slugs ) ) {
                return;
            }

            do_action( 'qaiyo_brand_menu_injected' );

            $has_top    = false;
            $has_bottom = false;
            $found_keys = array();

            foreach ( $menu as $key => $item ) {
                if ( ! isset( $item[2] ) ) {
                    continue;
                }
                if ( self::TOP_SLUG === $item[2] ) {
                    $has_top = true;
                }
                if ( self::BOTTOM_SLUG === $item[2] ) {
                    $has_bottom = true;
                }
                if ( in_array( $item[2], $slugs, true ) ) {
                    $found_keys[] = (float) $key;
                }
            }

            if ( empty( $found_keys ) ) {
                return;
            }

            $group_base = $found_keys[0];
            sort( $found_keys, SORT_NUMERIC );

            // Slide the Qaiyo entries together, so a foreign menu item that WordPress
            // happened to place between two of them is not swept inside the group.
            $group = array();
            foreach ( $found_keys as $found_key ) {
            	foreach ( $menu as $menu_key => $menu_item ) {
            		if ( (float) $menu_key === $found_key ) {
            			$group[] = $menu_item;
            			unset( $menu[ $menu_key ] );
            			break;
            		}
            	}
            }

            $found_keys = array();
            $slot       = $group_base;
            foreach ( $group as $group_item ) {
            	$guard = 0;
            	while ( isset( $menu[ (string) $slot ] ) && $guard < 500 ) {
            		$slot += 0.00001;
            		$guard++;
            	}
            	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Az admin menü átrendezése csak a $menu global közvetlen írásával lehetséges; ez a bevett WordPress minta.
            	$menu[ (string) $slot ]  = $group_item;
            	$found_keys[]            = (float) $slot;
            	$slot                   += 0.00001;
            }

            if ( empty( $found_keys ) ) {
            	return;
            }
            $first = $found_keys[0];
            $last  = end( $found_keys );

            if ( ! $has_top ) {
                $pos   = $first - 0.0001;
                $guard = 0;
                while ( isset( $menu[ (string) $pos ] ) && $guard < 200 ) {
                    $pos -= 0.00001;
                    $guard++;
                }
                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Adding an admin-menu separator is the documented way to place menu items (same pattern WP core uses for its own separators).
                $menu[ (string) $pos ] = array(
                    '',
                    'read',
                    self::TOP_SLUG,
                    '',
                    'wp-menu-separator qaiyo-brand-separator qaiyo-brand-separator-top',
                );
            }

            if ( ! $has_bottom ) {
                $pos   = $last + 0.0001;
                $guard = 0;
                while ( isset( $menu[ (string) $pos ] ) && $guard < 200 ) {
                    $pos += 0.00001;
                    $guard++;
                }
                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Adding an admin-menu separator is the documented way to place menu items (same pattern WP core uses for its own separators).
                $menu[ (string) $pos ] = array(
                    '',
                    'read',
                    self::BOTTOM_SLUG,
                    '',
                    'wp-menu-separator qaiyo-brand-separator qaiyo-brand-separator-bottom',
                );
            }
        }

        /** Build the chip as a base64 SVG data URI (with localized label baked in). */
        private static function chip_data_uri() {
            $label       = (string) __( 'Qaiyo Plugins', 'qaiyo-admin-booster' );
            $label_upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $label, 'UTF-8' ) : strtoupper( $label );
            $label_xml   = htmlspecialchars( $label_upper, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

            $char_count = function_exists( 'mb_strlen' ) ? mb_strlen( $label_upper ) : strlen( $label_upper );
            $width      = max( 78, (int) ( $char_count * 6.4 ) + 18 );
            $height     = 18;

            $svg = sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">' .
                    '<rect x="0.5" y="0.5" width="%3$d" height="%4$d" rx="9" ry="9" ' .
                    'fill="#ffffff" fill-opacity="0.05" stroke="#ffffff" stroke-opacity="0.18" stroke-width="1"/>' .
                    '<text x="%5$d" y="%6$d" text-anchor="middle" ' .
                    'font-family="-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,sans-serif" ' .
                    'font-size="9" font-weight="700" fill="#a7aaad" letter-spacing="0.6">%7$s</text>' .
                '</svg>',
                $width,
                $height,
                $width - 1,
                $height - 1,
                (int) ( $width / 2 ),
                (int) ( $height / 2 + 3 ),
                $label_xml
            );

            return 'data:image/svg+xml;base64,' . base64_encode( $svg );
        }

        public static function enqueue_css(): void {
            // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Intentional cross-plugin shared global; ensures only ONE Qaiyo plugin prints the brand-menu CSS even when multiple are active.
            if ( did_action( 'qaiyo_brand_menu_css' ) ) {
            	return;
            }
            do_action( 'qaiyo_brand_menu_css' );
            // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

            wp_register_style( 'qaiyo-brand-menu', false, array(), '1.0.0' );
            wp_enqueue_style( 'qaiyo-brand-menu' );
            wp_add_inline_style( 'qaiyo-brand-menu', self::build_css() );
        }

        /**
         * Build the brand-menu CSS string. The base64 data URI is intrinsically safe
         * (only [A-Za-z0-9+/=]) and is embedded directly in the stylesheet.
         */
        private static function build_css() {
            $uri = self::chip_data_uri();

            return '
                #adminmenu li.wp-menu-separator.qaiyo-brand-separator {
                    height: 10px !important;
                    min-height: 0;
                    padding: 0 !important;
                    margin: 8px 0 6px !important;
                    background: transparent !important;
                    border: 0;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.12) !important;
                    position: relative;
                    cursor: default;
                    pointer-events: none;
                }
                #adminmenu li.wp-menu-separator.qaiyo-brand-separator > div,
                #adminmenu li.wp-menu-separator.qaiyo-brand-separator > div.separator {
                    display: none !important;
                }
                #adminmenu li.wp-menu-separator.qaiyo-brand-separator-top::before {
                    content: url("' . $uri . '");
                    display: inline-block;
                    transform: translate(8px, 1px);
                    line-height: 1;
                    vertical-align: top;
                }
                #adminmenu li.wp-menu-separator.qaiyo-brand-separator-bottom {
                    margin: 6px 0 8px !important;
                }
                .folded #adminmenu li.wp-menu-separator.qaiyo-brand-separator-top::before {
                    display: none;
                }
                .folded #adminmenu li.wp-menu-separator.qaiyo-brand-separator {
                    margin: 6px 0 !important;
                }
                body.admin-color-light #adminmenu li.wp-menu-separator.qaiyo-brand-separator {
                    border-bottom-color: rgba(0, 0, 0, 0.12) !important;
                }';
        }
    }
}

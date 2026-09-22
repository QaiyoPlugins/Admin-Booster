<?php
/**
 * Qaiyo "More plugins" — WordPress.org-compliant discovery panel.
 *
 * Replaces the old remote-catalog "ecosystem" drop-in, which the WP.org review
 * rejected as phoning home. This version makes NO calls to qaiyo-plugins.com:
 *
 *   - Live section: the official WordPress.org plugin API (plugins_api,
 *     author=qaiyo) — WordPress's own infrastructure, cached in a transient,
 *     queried only when an admin opens this page. New free Qaiyo plugins on
 *     WordPress.org appear automatically, with no plugin update.
 *   - Pro section: a small static, bundled list of paid extensions (plain
 *     links to qaiyo-plugins.com — no remote request is made by the plugin).
 *
 * Self-contained, zero-dependency, copied into each plugin's includes/.
 * First loader wins (class_exists guard).
 *
 * Usage (in the main plugin file, after the brand menu):
 *   require_once <DIR> . 'includes/class-qwab-more-plugins.php';
 *   Qwab_More_Plugins::register( array( 'menu_parent' => '<top-level-slug>' ) );
 *
 * @package qaiyo-sdk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Qwab_More_Plugins' ) ) {

	class Qwab_More_Plugins {

		const VERSION       = '1.1.0';
		const PAGE_SLUG     = 'qwab-more-plugins';
		const TRANSIENT_KEY = 'qwab_more_plugins_wporg';
		const WPORG_AUTHOR  = 'qaiyo';
		const CACHE_TTL     = 12 * HOUR_IN_SECONDS;

		private static $booted     = false;
		private static $page_hooks = array();
		private static $i18n       = array();

		/**
		 * @param array $config {
		 *     @type string $menu_parent Top-level menu slug to attach the submenu to.
		 *     @type array  $i18n        Optional label overrides.
		 * }
		 */
		public static function register( array $config = array() ): void {
			$config = is_array( $config ) ? $config : array();

			if ( ! empty( $config['i18n'] ) && is_array( $config['i18n'] ) ) {
				self::$i18n = array_merge( self::$i18n, $config['i18n'] );
			}

			if ( ! empty( $config['menu_parent'] ) ) {
				if ( ! isset( $GLOBALS['qwab_more_plugins_parents'] ) || ! is_array( $GLOBALS['qwab_more_plugins_parents'] ) ) {
					$GLOBALS['qwab_more_plugins_parents'] = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- intentional cross-plugin shared global.
				}
				$parent = sanitize_text_field( $config['menu_parent'] );
				if ( ! in_array( $parent, $GLOBALS['qwab_more_plugins_parents'], true ) ) {
					$GLOBALS['qwab_more_plugins_parents'][] = $parent; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- intentional cross-plugin shared global.
				}
			}

			self::boot();
		}

		private static function boot(): void {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			add_action( 'admin_menu', array( __CLASS__, 'register_submenus' ), 80 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		}

		public static function register_submenus(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$parents = isset( $GLOBALS['qwab_more_plugins_parents'] ) ? $GLOBALS['qwab_more_plugins_parents'] : array();
			foreach ( $parents as $parent ) {
				$hook = add_submenu_page(
					$parent,
					self::t( 'page_title' ),
					self::t( 'menu_title' ),
					'manage_options',
					self::PAGE_SLUG . '-' . sanitize_key( $parent ),
					array( __CLASS__, 'render_page' )
				);
				if ( $hook ) {
					self::$page_hooks[] = $hook;
				}
			}
		}

		/* -----------------------------------------------------------------
		 * Data — WordPress.org plugin directory (no call to our own server)
		 * ----------------------------------------------------------------- */

		/**
		 * Fetch this author's plugins from the official WordPress.org API.
		 * Cached in a transient; queried only on this admin page.
		 *
		 * @return array List of plugin arrays (name, slug, short_description, icon, version).
		 */
		private static function get_wporg_plugins(): array {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			$response = plugins_api(
				'query_plugins',
				array(
					'author'   => self::WPORG_AUTHOR,
					'per_page' => 24,
					'fields'   => array(
						'short_description' => true,
						'icons'             => true,
						'version'           => true,
						'slug'              => true,
						'name'              => true,
					),
				)
			);

			$list = array();
			if ( ! is_wp_error( $response ) && ! empty( $response->plugins ) ) {
				foreach ( $response->plugins as $p ) {
					$p    = (array) $p;
					$icon = '';
					if ( ! empty( $p['icons'] ) ) {
						$icons = (array) $p['icons'];
						$icon  = isset( $icons['svg'] ) ? $icons['svg'] : ( isset( $icons['2x'] ) ? $icons['2x'] : ( isset( $icons['1x'] ) ? $icons['1x'] : ( isset( $icons['default'] ) ? $icons['default'] : '' ) ) );
					}
					$list[] = array(
						'slug'        => isset( $p['slug'] ) ? $p['slug'] : '',
						'name'        => isset( $p['name'] ) ? wp_strip_all_tags( $p['name'] ) : '',
						'description' => isset( $p['short_description'] ) ? wp_strip_all_tags( $p['short_description'] ) : '',
						'icon'        => $icon,
						'version'     => isset( $p['version'] ) ? $p['version'] : '',
					);
				}
			}

			// Cache even an empty result briefly so a failed call doesn't retry on every load.
			set_transient( self::TRANSIENT_KEY, $list, $list ? self::CACHE_TTL : HOUR_IN_SECONDS );
			return $list;
		}

		/**
		 * Static, bundled list of paid Qaiyo extensions (no remote call).
		 * Update this list when shipping a new Pro plugin.
		 *
		 * @return array
		 */
		private static function pro_plugins(): array {
			$base = 'https://qaiyo-plugins.com/';
			return array(
				array(
					'name' => 'Qaiyo Testimonials Pro',
					'desc' => 'Six extra layouts (V7–V12), star ratings, a front-end submission form and video testimonials.',
					'url'  => $base . 'qaiyo-testimonials/',
				),
				array(
					'name' => 'Qaiyo Access Manager Pro',
					'desc' => 'Per-term taxonomy access, advanced per-user rules and finer-grained access control.',
					'url'  => $base . 'qaiyo-access-manager/',
				),
				array(
					'name' => 'Qaiyo Featured Image Bulk Replacer Pro',
					'desc' => 'Background processing, scheduling, automatic image sourcing and full change history.',
					'url'  => $base . 'qaiyo-featured-image-bulk-replacer/',
				),
				array(
					'name' => 'Qaiyo Social Video Embed Pro',
					'desc' => 'TikTok channel feeds, a visual gallery builder and automatic synchronisation.',
					'url'  => $base . 'qaiyo-social-video-embed/',
				),
				array(
					'name' => 'Qaiyo Smart Appointment Booking Pro',
					'desc' => 'Unlimited services and staff, online payments and advanced scheduling rules.',
					'url'  => $base . 'qaiyo-smart-appointment-booking/',
				),
				array(
					'name' => 'Qaiyo Admin Booster Pro',
					'desc' => 'Advanced wp-admin tools and workflow enhancements that build on the free modules.',
					'url'  => $base . 'qaiyo-admin-booster/',
				),
				array(
					'name' => 'Qaiyo Web Performance Surgeon Pro',
					'desc' => 'Deeper performance diagnostics, more optimisation rules and one-click fixes.',
					'url'  => $base . 'qaiyo-web-performance-surgeon/',
				),
			);
		}

		/* -----------------------------------------------------------------
		 * Local install/active detection (no remote call)
		 * ----------------------------------------------------------------- */

		private static function status( $slug ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed = get_plugins();
			foreach ( $installed as $file => $data ) {
				if ( 0 === strpos( $file, $slug . '/' ) || $file === $slug . '.php' ) {
					return is_plugin_active( $file ) ? 'active' : 'installed';
				}
			}
			return 'none';
		}

		private static function install_url( $slug ) {
			return wp_nonce_url(
				self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ),
				'install-plugin_' . $slug
			);
		}

		/* -----------------------------------------------------------------
		 * Render
		 * ----------------------------------------------------------------- */

		public static function render_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$plugins     = self::get_wporg_plugins();
			$can_install = current_user_can( 'install_plugins' );
			?>
			<div class="wrap">
				<div class="qae-wrap">
					<div class="qae-header">
						<h1><?php echo esc_html( self::t( 'page_title' ) ); ?></h1>
						<p class="qae-subtitle"><?php echo esc_html( self::t( 'subtitle' ) ); ?></p>
					</div>

					<?php if ( empty( $plugins ) ) : ?>
						<p><?php echo esc_html( self::t( 'empty' ) ); ?></p>
					<?php else : ?>
						<div class="qae-grid">
						<?php
						foreach ( $plugins as $p ) :
							$slug   = $p['slug'];
							$status = self::status( $slug );
							?>
							<div class="qae-card">
								<div class="qae-card-head">
									<div class="qae-icon<?php echo $p['icon'] ? '' : ' qae-icon--fallback'; ?>">
										<?php if ( $p['icon'] ) : ?>
											<img class="qae-icon-img" src="<?php echo esc_url( $p['icon'] ); ?>" alt="" width="46" height="46" loading="lazy" />
										<?php else : ?>
											<span class="qae-icon-mono"><?php echo esc_html( self::monogram( $p['name'] ) ); ?></span>
										<?php endif; ?>
									</div>
									<div class="qae-badges">
										<?php if ( 'active' === $status ) : ?>
											<span class="qae-badge qae-badge--active"><span class="dashicons dashicons-yes-alt"></span><?php echo esc_html( self::t( 'active' ) ); ?></span>
										<?php elseif ( 'installed' === $status ) : ?>
											<span class="qae-badge qae-badge--installed"><?php echo esc_html( self::t( 'installed' ) ); ?></span>
										<?php endif; ?>
									</div>
								</div>
								<div class="qae-card-body">
									<h3 class="qae-name"><?php echo esc_html( $p['name'] ); ?></h3>
									<?php if ( $p['description'] ) : ?>
										<p class="qae-desc"><?php echo esc_html( $p['description'] ); ?></p>
									<?php endif; ?>
								</div>
								<div class="qae-card-footer">
									<?php if ( 'none' === $status && $can_install ) : ?>
										<a class="button button-primary qae-btn-pro" href="<?php echo esc_url( self::install_url( $slug ) ); ?>"><?php echo esc_html( self::t( 'install' ) ); ?></a>
									<?php endif; ?>
									<a class="button qae-btn-free" href="<?php echo esc_url( 'https://wordpress.org/plugins/' . $slug . '/' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::t( 'details' ) ); ?></a>
								</div>
							</div>
						<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<h2 class="qae-section-title"><?php echo esc_html( self::t( 'pro_title' ) ); ?></h2>
					<p class="qae-subtitle"><?php echo esc_html( self::t( 'pro_subtitle' ) ); ?></p>
					<div class="qae-grid">
						<?php foreach ( self::pro_plugins() as $pro ) : ?>
							<div class="qae-card">
								<div class="qae-card-head">
									<div class="qae-icon qae-icon--fallback">
										<span class="qae-icon-mono"><?php echo esc_html( self::monogram( $pro['name'] ) ); ?></span>
									</div>
									<div class="qae-badges"><span class="qae-badge qae-badge--pro">PRO</span></div>
								</div>
								<div class="qae-card-body">
									<h3 class="qae-name"><?php echo esc_html( $pro['name'] ); ?></h3>
									<?php if ( ! empty( $pro['desc'] ) ) : ?>
										<p class="qae-desc"><?php echo esc_html( $pro['desc'] ); ?></p>
									<?php endif; ?>
								</div>
								<div class="qae-card-footer">
									<a class="button button-primary qae-btn-pro" href="<?php echo esc_url( $pro['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::t( 'get_pro' ) ); ?></a>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<p class="qae-footer-note">
						<?php echo esc_html( self::t( 'source' ) ); ?>
						<a href="https://wordpress.org/plugins/search/qaiyo/" target="_blank" rel="noopener noreferrer">WordPress.org</a>
						&nbsp;·&nbsp;
						<a href="https://qaiyo-plugins.com" target="_blank" rel="noopener noreferrer">qaiyo-plugins.com</a>
					</p>
				</div>
			</div>
			<?php
		}

		/* -----------------------------------------------------------------
		 * Assets — enqueued via wp_add_inline_style (no echoed markup tags).
		 * ----------------------------------------------------------------- */

		public static function enqueue_assets(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$screen = get_current_screen();
			if ( ! $screen || ! in_array( $screen->id, self::$page_hooks, true ) ) {
				return;
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared one-time guard.
			if ( ! empty( $GLOBALS['qwab_more_plugins_assets'] ) ) {
				return;
			}
			$GLOBALS['qwab_more_plugins_assets'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared one-time guard.

			wp_enqueue_style( 'dashicons' );
			wp_register_style( 'qwab-more-plugins', false, array( 'dashicons' ), self::VERSION );
			wp_enqueue_style( 'qwab-more-plugins' );
			wp_add_inline_style( 'qwab-more-plugins', self::panel_css() );
		}

		private static function panel_css(): string {
			return '
			.qae-wrap{--qae-accent:#6c5ce7;--qae-accent-hover:#5a4bd1;--qae-card-bg:#fff;--qae-border:#e2e8f0;--qae-text:#0f172a;--qae-text-muted:#64748b;--qae-text-light:#94a3b8;--qae-shadow:0 1px 2px rgba(15,23,42,.04),0 2px 6px rgba(15,23,42,.04);--qae-shadow-hover:0 1px 3px rgba(15,23,42,.06),0 8px 24px rgba(15,23,42,.06);background:#f8f6ff;margin:0 0 0 -22px;padding:32px 28px;min-height:calc(100vh - 32px);box-sizing:border-box;overflow-x:clip}
			.qae-wrap *{box-sizing:border-box}
			.qae-header{max-width:1180px;margin:0 0 24px}
			.qae-header h1{font-size:28px;font-weight:700;color:var(--qae-text);letter-spacing:-.02em;margin:0 0 6px;padding:0;line-height:1.2}
			.qae-subtitle{font-size:14px;color:var(--qae-text-muted);margin:0 0 18px;max-width:760px;line-height:1.5}
			.qae-section-title{font-size:18px;font-weight:700;color:var(--qae-text);max-width:1180px;margin:34px 0 4px}
			.qae-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;max-width:1180px;margin:24px 0 28px}
			.qae-card{position:relative;background:var(--qae-card-bg);border:1px solid transparent;border-radius:16px;padding:22px 24px;display:flex;flex-direction:column;box-shadow:var(--qae-shadow);transition:box-shadow .3s cubic-bezier(.16,1,.3,1),border-color .2s,transform .2s}
			.qae-card:hover{box-shadow:var(--qae-shadow-hover);border-color:var(--qae-accent);transform:translateY(-2px)}
			.qae-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
			.qae-icon{position:relative;flex-shrink:0;width:46px;height:46px;border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center}
			.qae-icon-img{width:100%;height:100%;object-fit:cover;display:block}
			.qae-icon-mono{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#fff;letter-spacing:-.5px;background:linear-gradient(135deg,#7d6cf0 0%,#6c5ce7 60%,#5a4bd1 100%)}
			.qae-icon--fallback .qae-icon-img{display:none}
			.qae-badges{display:flex;flex-wrap:wrap;gap:5px;justify-content:flex-end}
			.qae-badge{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:700;line-height:1;padding:4px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
			.qae-badge .dashicons{font-size:12px;width:12px;height:12px}
			.qae-badge--active{background:#ecfdf3;color:#15803d;box-shadow:inset 0 0 0 1px rgba(21,128,61,.18)}
			.qae-badge--installed{background:#fffbeb;color:#b45309;box-shadow:inset 0 0 0 1px rgba(180,83,9,.18)}
			.qae-badge--pro{background:var(--qae-accent);color:#fff}
			.qae-card-body{flex:1 1 auto;margin-bottom:18px}
			.qae-name{margin:0 0 2px!important;padding:0!important;font-size:16px!important;font-weight:700!important;color:var(--qae-text)!important;line-height:1.3!important}
			.qae-desc{margin:10px 0 0!important;font-size:13px!important;color:var(--qae-text-muted)!important;line-height:1.55!important}
			.qae-card-footer{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto}
			.qae-card-footer .button{flex:1 1 0;min-width:120px;text-align:center;justify-content:center;font-size:13px!important;height:auto;padding:6px 12px;line-height:1.6}
			.qae-btn-free{background:var(--qae-card-bg)!important;border:1px solid var(--qae-border)!important;color:var(--qae-text)!important}
			.qae-btn-free:hover{border-color:var(--qae-accent)!important;color:var(--qae-accent)!important}
			.qae-btn-pro{background:var(--qae-accent)!important;border:1px solid var(--qae-accent)!important;color:#fff!important}
			.qae-btn-pro:hover{background:var(--qae-accent-hover)!important;border-color:var(--qae-accent-hover)!important;color:#fff!important}
			.qae-footer-note{max-width:1180px;font-size:12px;color:var(--qae-text-light);margin:0;line-height:1.6}
			.qae-footer-note a{color:var(--qae-accent);text-decoration:none;font-weight:600}
			@media screen and (max-width:782px){.qae-wrap{margin:0 -10px;padding:20px 14px}.qae-grid{grid-template-columns:1fr}}';
		}

		/* -----------------------------------------------------------------
		 * Helpers
		 * ----------------------------------------------------------------- */

		private static function monogram( $name ) {
			$name = trim( wp_strip_all_tags( (string) $name ) );
			if ( '' === $name ) {
				return 'Q';
			}
			$c = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
			return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $c ) : strtoupper( $c );
		}

		private static function t( $key ) {
			if ( isset( self::$i18n[ $key ] ) ) {
				return self::$i18n[ $key ];
			}
			$strings = self::strings();
			$lang    = substr( determine_locale(), 0, 2 );
			if ( isset( $strings[ $lang ][ $key ] ) ) {
				return $strings[ $lang ][ $key ];
			}
			if ( isset( $strings['en'][ $key ] ) ) {
				return $strings['en'][ $key ];
			}
			return $key;
		}

		private static function strings(): array {
			return array(
				'en' => array(
					'menu_title'   => 'Discover Qaiyo',
					'page_title'   => 'More plugins from Qaiyo',
					'subtitle'     => 'Free Qaiyo plugins, live from the WordPress.org directory — install any of them in one click.',
					'active'       => 'Active',
					'installed'    => 'Installed',
					'install'      => 'Install',
					'details'      => 'Details →',
					'pro_title'    => 'Pro extensions',
					'pro_subtitle' => 'Unlock advanced features with the paid Qaiyo add-ons.',
					'get_pro'      => 'Learn more →',
					'source'       => 'Source:',
					'empty'        => 'The plugin list could not be loaded right now. Please try again later.',
				),
				'hu' => array(
					'menu_title'   => 'Qaiyo felfedezése',
					'page_title'   => 'További Qaiyo pluginok',
					'subtitle'     => 'Ingyenes Qaiyo pluginok élőben a WordPress.org katalógusából — bármelyik telepíthető egy kattintással.',
					'active'       => 'Aktív',
					'installed'    => 'Telepítve',
					'install'      => 'Telepítés',
					'details'      => 'Részletek →',
					'pro_title'    => 'Pro bővítmények',
					'pro_subtitle' => 'Oldd fel a haladó funkciókat a fizetős Qaiyo kiegészítőkkel.',
					'get_pro'      => 'Tudj meg többet →',
					'source'       => 'Forrás:',
					'empty'        => 'A pluginlista jelenleg nem tölthető be. Próbáld újra később.',
				),
			);
		}
	}
}

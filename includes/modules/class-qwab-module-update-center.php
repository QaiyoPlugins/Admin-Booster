<?php
/**
 * Modul: Frissítés-központ vezérlőpult widget.
 *
 * A Vezérlőpultra tesz egy widgetet, amely listázza az elérhető plugin- és
 * téma-frissítéseket (név, jelenlegi → új verzió), soronként „Frissítés"
 * gombbal és egy „Összes frissítése" gombbal — anélkül, hogy a Bővítmények
 * vagy a Frissítések oldalra kellene navigálni.
 *
 * A tényleges frissítést NEM mi végezzük: a WordPress beépített `wp.updates`
 * JS API-jára ülünk rá (`updatePlugin` / `updateTheme`), amely a core saját
 * AJAX-át, nonce-át, jogosultság-ellenőrzését és rollback-jét használja. Több
 * gomb egyszerre lenyomása a core `ajaxLocked` sorát tölti, így a frissítések
 * sorban, nem párhuzamosan futnak.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vezérlőpult widget plugin- és téma-frissítésekhez.
 */
class Qwab_Module_Update_Center {

	const WIDGET_ID = 'qwab_update_center';
	const NONCE     = 'qwab_uc_nonce';
	const CACHE_KEY = 'qwab_uc_rows';

	/**
	 * Per-request memoizáció a normalizált (cap-független) frissítés-sorokhoz.
	 *
	 * @var array|null
	 */
	private $rows_cache = null;

	/**
	 * Hook-ok bekötése.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_qwab_uc_check', array( $this, 'ajax_check' ) );
		add_action( 'wp_ajax_qwab_uc_update', array( $this, 'ajax_update' ) );

		// Amikor a WordPress frissíti a saját frissítés-adatait (ütemezett
		// ellenőrzés, „Keresés újra", vagy egy lefutott frissítés), dobjuk el a
		// normalizált gyorsítótárunkat, hogy a widget mindig friss legyen.
		add_action( 'set_site_transient', array( $this, 'flush_cache' ) );
	}

	/**
	 * A normalizált frissítés-lista gyorsítótárának ürítése.
	 *
	 * @param string $transient A most mentett site transient neve.
	 */
	public function flush_cache( $transient = '' ): void {
		if ( ! in_array( $transient, array( 'update_plugins', 'update_themes' ), true ) ) {
			return;
		}
		$this->rows_cache = null;
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Frissíthet-e a felhasználó bármit? (Multisite alszájton ez a két cap
	 * eleve hamis a sima adminoknak, így külön kezelés nélkül elrejtjük.)
	 *
	 * @return bool
	 */
	private function user_can_update(): bool {
		return ( current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' ) );
	}

	// -------------------------------------------------------------------------
	// Widget regisztráció
	// -------------------------------------------------------------------------

	/**
	 * A Vezérlőpult widget regisztrálása (csak ha a felhasználó frissíthet).
	 */
	public function register_widget(): void {
		if ( ! $this->user_can_update() ) {
			return;
		}

		$count = $this->update_count();
		$title = esc_html__( 'Updates', 'qaiyo-admin-booster' );
		if ( $count > 0 ) {
			$title .= ' <span class="qwab-uc-title-count">' . (int) $count . '</span>';
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			$title, // A core nem escape-eli; a darabszám int-re kényszerítve.
			array( $this, 'render_widget' )
		);

		// Ha van mit frissíteni, a widget a Vezérlőpult tetejére kerül, mert ez
		// a cselekvésre hívó kártya.
		if ( $count > 0 ) {
			$this->move_widget_top();
		}
	}

	/**
	 * A widgetet a 'normal' oszlop legtetejére helyezi.
	 */
	private function move_widget_top(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A dashboard widget átrendezése csak a $wp_meta_boxes közvetlen módosításával lehetséges; ez a bevett WordPress minta.
		global $wp_meta_boxes;
		if ( empty( $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ] ) ) {
			return;
		}
		$box = $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ];
		unset( $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Lásd fent.
		$wp_meta_boxes['dashboard']['normal']['core'] = array_merge(
			array( self::WIDGET_ID => $box ),
			$wp_meta_boxes['dashboard']['normal']['core']
		);
	}

	// -------------------------------------------------------------------------
	// Asset-ek (csak a Vezérlőpulton)
	// -------------------------------------------------------------------------

	/**
	 * Asset-ek betöltése a Vezérlőpulton.
	 *
	 * @param string $hook Aktuális admin oldal hook.
	 */
	public function enqueue( $hook ): void {
		if ( 'index.php' !== $hook || ! $this->user_can_update() ) {
			return;
		}

		// A changelog modalhoz a natív plugin-info thickbox. A frissítést NEM a
		// headless wp.updates JS végzi (az egyedi képernyőn — pl. a Vezérlőpulton
		// — gyakran beragad a fájlrendszer-hitelesítésnél), hanem a saját
		// szerveroldali AJAX handlerünk a core Plugin_Upgrader/Theme_Upgrader-rel.
		wp_enqueue_script( 'plugin-install' );
		add_thickbox();

		$css = QWAB_PATH . 'assets/css/qwab-update-center.css';
		$js  = QWAB_PATH . 'assets/js/qwab-update-center.js';

		wp_enqueue_style(
			'qwab-update-center',
			QWAB_URL . 'assets/css/qwab-update-center.css',
			array( 'dashicons' ),
			QWAB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : QWAB_VERSION )
		);
		wp_enqueue_script(
			'qwab-update-center',
			QWAB_URL . 'assets/js/qwab-update-center.js',
			array( 'jquery' ),
			QWAB_VERSION . '.' . ( file_exists( $js ) ? filemtime( $js ) : QWAB_VERSION ),
			true
		);
		wp_localize_script(
			'qwab-update-center',
			'qwabUC',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'updating' => __( 'Updating…', 'qaiyo-admin-booster' ),
					'updated'  => __( 'Updated', 'qaiyo-admin-booster' ),
					'failed'   => __( 'Update failed', 'qaiyo-admin-booster' ),
					'checking' => __( 'Checking…', 'qaiyo-admin-booster' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Adat: elérhető frissítések (a tárolt transient-ből, nincs távoli hívás)
	// -------------------------------------------------------------------------

	/**
	 * Betölti a frissítés-lekérdező core függvényeket, ha kell.
	 */
	private function ensure_update_funcs(): void {
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
	}

	/**
	 * Az összes elérhető frissítés-sor a felhasználó jogosultságai szerint
	 * szűrve. A drága lekérdezés a `all_rows()`-ban 1× fut és cache-elődik.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows_for_user(): array {
		$can_plugins = current_user_can( 'update_plugins' );
		$can_themes  = current_user_can( 'update_themes' );

		$out = array();
		foreach ( $this->all_rows() as $row ) {
			$allowed = ( 'theme' === $row['type'] ) ? $can_themes : $can_plugins;
			if ( $allowed ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * A nyers (jogosultságtól FÜGGETLEN) normalizált frissítés-sorok.
	 *
	 * Per-request memoizálva ÉS 1 órás transientben cache-elve, hogy a
	 * `get_plugin_updates()` / `get_theme_updates()` (amelyek a plugin/téma
	 * fejléceket olvassák a fájlrendszerről) ne fussanak minden Vezérlőpult-
	 * betöltéskor. A cache a WordPress frissítés-adatainak változásakor ürül
	 * (lásd `flush_cache()`), így nem avul el.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all_rows(): array {
		if ( null !== $this->rows_cache ) {
			return $this->rows_cache;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			$this->rows_cache = $cached;
			return $cached;
		}

		$rows = array_merge( $this->gather_plugin_updates(), $this->gather_theme_updates() );
		set_transient( self::CACHE_KEY, $rows, HOUR_IN_SECONDS );
		$this->rows_cache = $rows;
		return $rows;
	}

	/**
	 * Elérhető plugin-frissítések normalizált listája (cap-független adat).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function gather_plugin_updates(): array {
		$this->ensure_update_funcs();

		$out = array();
		foreach ( get_plugin_updates() as $file => $data ) {
			$update = isset( $data->update ) ? $data->update : null;
			if ( ! $update || empty( $update->new_version ) ) {
				continue;
			}

			// Tömbként olvassuk a plugin-fejléc mezőit (Name/Version/Author),
			// hogy ne ütközzünk a snake_case property-szabállyal.
			$header = (array) $data;

			$slug = ! empty( $update->slug ) ? $update->slug : dirname( $file );
			if ( '.' === $slug ) {
				$slug = preg_replace( '/\.php$/', '', $file );
			}

			$author = isset( $header['Author'] ) ? wp_strip_all_tags( $header['Author'] ) : '';

			$out[] = array(
				'type'     => 'plugin',
				'file'     => $file,
				'slug'     => $slug,
				'name'     => isset( $header['Name'] ) ? $header['Name'] : $file,
				'current'  => isset( $header['Version'] ) ? $header['Version'] : '',
				'new'      => $update->new_version,
				'is_qaiyo' => ( 0 === strpos( $slug, 'qaiyo-' ) || false !== stripos( $author, 'Qaiyo' ) ),
				'details'  => ! empty( $update->slug )
					? self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $update->slug ) . '&section=changelog&TB_iframe=true&width=722&height=748' )
					: '',
			);
		}
		return $out;
	}

	/**
	 * Elérhető téma-frissítések normalizált listája (cap-független adat).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function gather_theme_updates(): array {
		$this->ensure_update_funcs();

		$out = array();
		foreach ( get_theme_updates() as $stylesheet => $theme ) {
			$update = isset( $theme->update ) ? $theme->update : null;
			$new    = ( is_array( $update ) && isset( $update['new_version'] ) ) ? $update['new_version'] : '';
			if ( '' === $new ) {
				continue;
			}
			$out[] = array(
				'type'     => 'theme',
				'slug'     => $stylesheet,
				'name'     => $theme->display( 'Name' ),
				'current'  => $theme->display( 'Version' ),
				'new'      => $new,
				'is_qaiyo' => false,
				'details'  => '',
			);
		}
		return $out;
	}

	/**
	 * Az elérhető frissítések összes száma (plugin + téma).
	 *
	 * @return int
	 */
	private function update_count(): int {
		return count( $this->rows_for_user() );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * A widget tartalmának kirajzolása.
	 */
	public function render_widget(): void {
		$rows  = $this->rows_for_user();
		$total = count( $rows );
		?>
		<div id="qwab-uc" class="qwab-uc">
			<ul class="qwab-uc-list<?php echo $total ? '' : ' qwab-uc-hidden'; ?>">
				<?php
				foreach ( $rows as $item ) {
					$this->render_row( $item );
				}
				?>
			</ul>

			<div class="qwab-uc-actions<?php echo $total ? '' : ' qwab-uc-hidden'; ?>">
				<button type="button" class="button button-primary qwab-uc-update-all">
					<?php
					/* translators: %d: number of available updates. */
					printf( esc_html__( 'Update all (%d)', 'qaiyo-admin-booster' ), (int) $total );
					?>
				</button>
				<button type="button" class="button-link qwab-uc-check">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<span class="qwab-uc-check-label"><?php esc_html_e( 'Check again', 'qaiyo-admin-booster' ); ?></span>
				</button>
			</div>

			<div class="qwab-uc-empty<?php echo $total ? ' qwab-uc-hidden' : ''; ?>">
				<span class="qwab-uc-empty-icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<p class="qwab-uc-empty-title"><?php esc_html_e( 'Everything is up to date', 'qaiyo-admin-booster' ); ?></p>
				<button type="button" class="button-link qwab-uc-check">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<span class="qwab-uc-check-label"><?php esc_html_e( 'Check again', 'qaiyo-admin-booster' ); ?></span>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Egyetlen frissítés-sor kirajzolása.
	 *
	 * @param array<string,mixed> $item Normalizált frissítés-sor.
	 */
	private function render_row( array $item ): void {
		$is_theme = ( 'theme' === $item['type'] );
		?>
		<li class="qwab-uc-row" data-type="<?php echo esc_attr( $item['type'] ); ?>" data-slug="<?php echo esc_attr( $item['slug'] ); ?>"<?php if ( ! $is_theme ) : ?> data-plugin="<?php echo esc_attr( $item['file'] ); ?>"<?php endif; ?>>

			<div class="qwab-uc-main">
				<span class="qwab-uc-name">
					<?php echo esc_html( $item['name'] ); ?>
					<?php if ( ! empty( $item['is_qaiyo'] ) ) : ?>
						<span class="qwab-uc-chip">Qaiyo</span>
					<?php endif; ?>
				</span>
				<span class="qwab-uc-vers">
					<span class="qwab-uc-cur">v<?php echo esc_html( $item['current'] ); ?></span>
					<span class="qwab-uc-arrow" aria-hidden="true">&rarr;</span>
					<span class="qwab-uc-new">v<?php echo esc_html( $item['new'] ); ?></span>
					<?php if ( $is_theme ) : ?>
						<span class="qwab-uc-type"><?php esc_html_e( 'Theme', 'qaiyo-admin-booster' ); ?></span>
					<?php endif; ?>
				</span>
				<span class="qwab-uc-status" aria-live="polite"></span>
			</div>

			<div class="qwab-uc-row-actions">
				<?php if ( ! empty( $item['details'] ) ) : ?>
					<a href="<?php echo esc_url( $item['details'] ); ?>"
						class="qwab-uc-details thickbox open-plugin-details-modal"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin name. */ __( 'Changelog for %s', 'qaiyo-admin-booster' ), $item['name'] ) ); ?>">
						<?php esc_html_e( 'Details', 'qaiyo-admin-booster' ); ?>
					</a>
				<?php endif; ?>
				<button type="button" class="button qwab-uc-update"><?php esc_html_e( 'Update', 'qaiyo-admin-booster' ); ?></button>
			</div>
		</li>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: frissítés-keresés kényszerítése
	// -------------------------------------------------------------------------

	/**
	 * Kényszerített frissítés-keresés (a 12 órás core ütemezés megkerülése).
	 */
	public function ajax_check(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! $this->user_can_update() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to check for updates.', 'qaiyo-admin-booster' ) ), 403 );
		}
		if ( current_user_can( 'update_plugins' ) ) {
			wp_update_plugins();
		}
		if ( current_user_can( 'update_themes' ) ) {
			wp_update_themes();
		}
		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// AJAX: egy plugin/téma frissítése szerveroldalon (core upgrader)
	// -------------------------------------------------------------------------

	/**
	 * Egyetlen plugin vagy téma frissítése a core Plugin_Upgrader/Theme_Upgrader
	 * segítségével, AJAX skinnel. A headless wp.updates helyett ez fut, mert az
	 * a Vezérlőpulton gyakran beragad a fájlrendszer-hitelesítésnél.
	 */
	public function ajax_update(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'plugin';

		// A frissítéshez szükséges core-fájlokat csak akkor töltjük be, ha a
		// belőlük használt szimbólum még nem elérhető, és rögtön utána használjuk.
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}

		if ( 'theme' === $type ) {
			if ( ! current_user_can( 'update_themes' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to update themes.', 'qaiyo-admin-booster' ) ) );
			}
			$slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			$updates = get_theme_updates();
			if ( '' === $slug || ! isset( $updates[ $slug ] ) ) {
				wp_send_json_error( array( 'message' => __( 'No update is available for this theme.', 'qaiyo-admin-booster' ) ) );
			}
			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Theme_Upgrader( $skin );
			$result   = $upgrader->bulk_upgrade( array( $slug ) );
			$this->send_upgrade_result( $skin, $result, $slug );
		}

		// Alapértelmezett: plugin.
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to update plugins.', 'qaiyo-admin-booster' ) ) );
		}
		$plugin  = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$updates = get_plugin_updates();
		if ( '' === $plugin || ! isset( $updates[ $plugin ] ) ) {
			wp_send_json_error( array( 'message' => __( 'No update is available for this plugin.', 'qaiyo-admin-booster' ) ) );
		}
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade( array( $plugin ) );
		$this->send_upgrade_result( $skin, $result, $plugin );
	}

	/**
	 * A bulk_upgrade() eredményének értelmezése és JSON válasz (a core
	 * wp_ajax_update_plugin logikáját követi).
	 *
	 * @param WP_Ajax_Upgrader_Skin $skin   Az upgrader skin.
	 * @param mixed                 $result A bulk_upgrade() visszatérési értéke.
	 * @param string                $key    A plugin-fájl vagy téma-stylesheet kulcs.
	 */
	private function send_upgrade_result( WP_Ajax_Upgrader_Skin $skin, $result, string $key ): void {
		if ( is_wp_error( $skin->result ) ) {
			wp_send_json_error( array( 'message' => $skin->result->get_error_message() ) );
		}
		if ( $skin->get_errors()->has_errors() ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( implode( ' ', $skin->get_errors()->get_error_messages() ) ) ) );
		}
		if ( is_array( $result ) && ! empty( $result[ $key ] ) ) {
			wp_send_json_success();
		}
		if ( false === $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'Could not access the filesystem. Your host may require FTP/SSH credentials to install updates.', 'qaiyo-admin-booster' ),
				)
			);
		}
		wp_send_json_error( array( 'message' => __( 'Update failed.', 'qaiyo-admin-booster' ) ) );
	}
}

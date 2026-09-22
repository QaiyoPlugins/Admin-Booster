<?php
/**
 * Qaiyo ökoszisztéma panel — megosztott drop-in.
 *
 * Egy egységes panel, ami MINDEN Qaiyo plugin saját admin oldalán megjelenik,
 * és megmutatja, milyen összefoglaló kártyát adna a többi Qaiyo plugin. Három
 * kártyaállapot:
 *
 *   - AKTÍV:   a testvér plugin fut és jelentett kártyát → valós adat.
 *   - LAKATOS: a testvér fut, de a mélyebb tartalom a saját Pro-jában van.
 *   - GHOST:   a testvér nincs telepítve → mit kapnál, ha lenne.
 *
 * TERJESZTÉS: ez a fájl pluginonként MÁSOLÓDIK, és a másolatban MINDEN
 * azonosítót a fogadó plugin saját prefixére kell átnevezni (osztálynév, hook,
 * CSS handle, text domain). Márka-szintű `Qaiyo_*` névtér NEM használható:
 * a WordPress.org felülvizsgálat ezt kifogásolja (ez a plugin már át is
 * nevezett egy megosztott drop-int emiatt).
 *
 * ADATCSERE: a panel a SAJÁT prefixű szűrőjét hirdeti meg, a testvérek pedig
 * feliratkoznak rá. Más plugin hookjára feliratkozni mindig szabad, ezért a
 * testvéreknek nem kell közös kódot becsomagolniuk, és nincs betöltési
 * sorrend-függés. Ha egy plugin hiányzik, a feliratkozása egyszerűen nincs.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Ecosystem_Panel {

    /** Ennek a pluginnak a WP.org slugja — a saját kártyáját nem mutatjuk. */
    const SELF_SLUG = 'qaiyo-admin-booster';

    /**
     * A Qaiyo plugin-család katalógusa.
     *
     * Statikus tömb, nincs mögötte hálózati hívás. Az `card` mező azt írja le,
     * MILYEN összefoglalót adna az adott plugin az ökoszisztéma-panelen —
     * funkcionális leírás, nem reklámszöveg.
     *
     * @return array<string,array<string,string>>
     */
    public static function catalog(): array {
        return array(
            'qaiyo-testimonials'            => array(
                'name' => 'Qaiyo Testimonials',
                'icon' => 'dashicons-star-filled',
                'card' => __( 'Shows how many reviews are waiting for approval, with a link to moderation.', 'qaiyo-admin-booster' ),
            ),
            'qaiyo-smart-appointment'       => array(
                'name' => 'Qaiyo Smart Appointment',
                'icon' => 'dashicons-calendar-alt',
                'card' => __( 'Shows today’s bookings and when the next appointment starts.', 'qaiyo-admin-booster' ),
            ),
            'qaiyo-web-performance-surgeon' => array(
                'name' => 'Qaiyo Web Performance Surgeon',
                'icon' => 'dashicons-performance',
                'card' => __( 'Shows the latest measured performance score for this site.', 'qaiyo-admin-booster' ),
            ),
            'qaiyo-clean-gallery'           => array(
                'name' => 'Qaiyo Clean Gallery',
                'icon' => 'dashicons-format-gallery',
                'card' => __( 'Shows how many galleries and images you have.', 'qaiyo-admin-booster' ),
            ),
            'qaiyo-social-video-embed'      => array(
                'name' => 'Qaiyo Social Video Embed',
                'icon' => 'dashicons-video-alt3',
                'card' => __( 'Shows how many videos are embedded across your content.', 'qaiyo-admin-booster' ),
            ),
            'qaiyo-access-manager'          => array(
                'name' => 'Qaiyo Access Manager',
                'icon' => 'dashicons-lock',
                'card' => __( 'Shows which roles currently have restricted access.', 'qaiyo-admin-booster' ),
            ),
            'qaiyo-text-marquee-slider'     => array(
                'name' => 'Qaiyo Text Marquee Slider',
                'icon' => 'dashicons-controls-repeat',
                'card' => __( 'Shows how many marquee sliders are running on the site.', 'qaiyo-admin-booster' ),
            ),
            'qaiyo-admin-booster'           => array(
                'name' => 'Qaiyo Admin Booster',
                'icon' => 'dashicons-superhero-alt',
                'card' => __( 'Fixes the everyday wp-admin rough edges.', 'qaiyo-admin-booster' ),
            ),
        );
    }

    /* ---------------------------------------------------------------------
     * Bejelentett kártyák
     * ------------------------------------------------------------------- */

    /**
     * A testvérek által bejelentett kártyák, slug szerint indexelve.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function reported(): array {
        /** This filter is documented in includes/modules/class-qwab-module-ecosystem-widgets.php */
        $raw = apply_filters( 'qwab_ecosystem_widgets', array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $out = array();
        foreach ( $raw as $card ) {
            if ( ! is_array( $card ) || empty( $card['slug'] ) || empty( $card['title'] ) ) {
                continue;
            }
            if ( ! empty( $card['capability'] ) && ! current_user_can( $card['capability'] ) ) {
                continue;
            }
            $out[ (string) $card['slug'] ] = $card;
        }
        return $out;
    }

    /**
     * Telepítve ÉS aktív-e az adott Qaiyo plugin? A Qaiyo pluginok mind
     * `slug/slug.php` felépítésűek.
     *
     * @param string $slug Plugin slug.
     * @return bool
     */
    private static function is_active( string $slug ): bool {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active( $slug . '/' . $slug . '.php' );
    }

    /* ---------------------------------------------------------------------
     * Render
     * ------------------------------------------------------------------- */

    /**
     * A teljes panel kirajzolása.
     */
    public static function render(): void {
        $reported = self::reported();
        ?>
        <div class="qwab-ecop">
            <p class="qwab-ecop__intro">
                <?php esc_html_e( 'Qaiyo plugins can show a summary card here. Cards from plugins you already run show live data; the rest show what they would add.', 'qaiyo-admin-booster' ); ?>
            </p>

            <div class="qwab-ecop__grid">
                <?php
                foreach ( self::catalog() as $slug => $meta ) {
                    if ( self::SELF_SLUG === $slug ) {
                        continue; // A saját kártyánkat nem mutatjuk.
                    }
                    if ( isset( $reported[ $slug ] ) ) {
                        self::card_active( $slug, $meta, $reported[ $slug ] );
                    } elseif ( self::is_active( $slug ) ) {
                        self::card_silent( $slug, $meta );
                    } else {
                        self::card_ghost( $slug, $meta );
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Kártya fejléc (mindhárom állapotban közös).
     *
     * @param array  $meta  Katalógus-bejegyzés.
     * @param string $badge Opcionális jobb oldali címke.
     */
    private static function head( array $meta, string $badge = '' ): void {
        ?>
        <div class="qwab-ecop-card__head">
            <span class="dashicons <?php echo esc_attr( $meta['icon'] ); ?>" aria-hidden="true"></span>
            <span class="qwab-ecop-card__name"><?php echo esc_html( $meta['name'] ); ?></span>
            <?php if ( '' !== $badge ) : ?>
                <span class="qwab-ecop-card__badge"><?php echo esc_html( $badge ); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AKTÍV kártya — a testvér plugin valós adata.
     *
     * @param string $slug Plugin slug.
     * @param array  $meta Katalógus-bejegyzés.
     * @param array  $card Bejelentett kártya.
     */
    private static function card_active( string $slug, array $meta, array $card ): void {
        $locked  = ! empty( $card['locked'] );
        $upgrade = isset( $card['upgrade'] ) ? (string) $card['upgrade'] : '';
        ?>
        <div class="qwab-ecop-card is-active<?php echo $locked ? ' is-locked' : ''; ?>">
            <?php self::head( $meta ); ?>

            <div class="qwab-ecop-card__body">
                <?php
                if ( ! empty( $card['render'] ) && is_callable( $card['render'] ) ) {
                    try {
                        call_user_func( $card['render'] );
                    } catch ( Throwable $e ) {
                        echo '<p class="qwab-ecop-card__error">' .
                            esc_html__( 'This card could not be displayed.', 'qaiyo-admin-booster' ) .
                            '</p>';
                    }
                }
                ?>
            </div>

            <?php if ( $locked && '' !== $upgrade ) : ?>
                <p class="qwab-ecop-card__foot">
                    <a class="qwab-ecop-card__pro" href="<?php echo esc_url( $upgrade ); ?>" target="_blank" rel="noopener noreferrer">
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <?php
                        printf(
                            /* translators: %s: plugin name. */
                            esc_html__( 'More detail in %s Pro', 'qaiyo-admin-booster' ),
                            esc_html( $meta['name'] )
                        );
                        ?>
                    </a>
                </p>
            <?php elseif ( ! empty( $card['url'] ) ) : ?>
                <p class="qwab-ecop-card__foot">
                    <a href="<?php echo esc_url( $card['url'] ); ?>"><?php esc_html_e( 'Open', 'qaiyo-admin-booster' ); ?> &rarr;</a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Aktív, de nem jelentett kártyát (jellemzően régebbi verzió) — nem
     * hirdetünk semmit, csak jelezzük, hogy fut.
     *
     * @param string $slug Plugin slug.
     * @param array  $meta Katalógus-bejegyzés.
     */
    private static function card_silent( string $slug, array $meta ): void {
        ?>
        <div class="qwab-ecop-card is-silent">
            <?php self::head( $meta, __( 'Active', 'qaiyo-admin-booster' ) ); ?>
            <div class="qwab-ecop-card__body">
                <p><?php esc_html_e( 'This plugin is active but did not report a summary card. Updating it to the latest version enables one.', 'qaiyo-admin-booster' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * GHOST kártya — a plugin nincs telepítve. Csak leírás + „Részletek" link
     * a hivatalos WordPress.org oldalra; nincs telepítő gomb.
     *
     * @param string $slug Plugin slug.
     * @param array  $meta Katalógus-bejegyzés.
     */
    private static function card_ghost( string $slug, array $meta ): void {
        ?>
        <div class="qwab-ecop-card is-ghost">
            <?php self::head( $meta ); ?>
            <div class="qwab-ecop-card__body">
                <p><?php echo esc_html( $meta['card'] ); ?></p>
            </div>
            <p class="qwab-ecop-card__foot">
                <a href="<?php echo esc_url( 'https://wordpress.org/plugins/' . $slug . '/' ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Details', 'qaiyo-admin-booster' ); ?> &rarr;
                </a>
            </p>
        </div>
        <?php
    }
}

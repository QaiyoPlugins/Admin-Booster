<?php
/**
 * Modul: Qaiyo ökoszisztéma widgetek.
 *
 * Egy Vezérlőpult-widget, amelyben a többi telepített Qaiyo plugin a saját
 * összefoglaló kártyáját jelenítheti meg — így egy helyen látszik minden
 * Qaiyo-adat, ahelyett hogy pluginonként külön widget szemetelné tele a
 * Vezérlőpultot.
 *
 * ARCHITEKTÚRA — miért nincs megosztott „bridge" könyvtár:
 * a gazda (ez a plugin) a SAJÁT prefixű szűrőjét hirdeti meg, a testvér
 * pluginok pedig egyszerűen ráülnek. Egy másik plugin hookjára feliratkozni
 * mindig szabad, ezért a testvéreknek nem kell semmilyen közös kódot
 * becsomagolniuk, és nincs márka-szintű (nem prefixelt) osztály/függvény,
 * amit a WordPress.org felülvizsgálat kifogásolna. Ha ez a plugin nincs
 * telepítve, a testvérek `add_filter()` hívása egyszerűen nem fut le semmire —
 * nincs függőség egyik irányban sem.
 *
 * A szerződést lásd: HOOKS.md.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Ecosystem_Widgets {

    const WIDGET_ID = 'qwab_ecosystem';

    /**
     * Per-request gyorsítótár a összegyűjtött kártyáknak.
     *
     * @var array|null
     */
    private $cards = null;

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /* ---------------------------------------------------------------------
     * Gyűjtés + validálás
     * ------------------------------------------------------------------- */

    /**
     * A testvér pluginok által bejelentett kártyák, ellenőrizve és rendezve.
     *
     * Mindent védekezően validálunk: egy testvér plugin lehet régi verziójú,
     * félkész, vagy épp deaktiválás alatt — a Vezérlőpult ettől nem törhet el.
     *
     * @return array<int,array<string,mixed>>
     */
    private function cards(): array {
        if ( null !== $this->cards ) {
            return $this->cards;
        }

        /**
         * Ökoszisztéma-kártyák bejelentése.
         *
         * Minden elem: title (kötelező), render (kötelező, callable, echo-zik),
         * plugin, icon (dashicon osztály), url, capability, priority.
         *
         * @param array $widgets Bejelentett kártyák.
         */
        $raw = apply_filters( 'qwab_ecosystem_widgets', array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }

        $out = array();
        foreach ( $raw as $key => $card ) {
            if ( ! is_array( $card ) ) {
                continue;
            }
            // Cím és renderelő nélkül nincs mit mutatni.
            if ( empty( $card['title'] ) || empty( $card['render'] ) || ! is_callable( $card['render'] ) ) {
                continue;
            }
            // A testvér plugin megszabhatja, milyen joggal látható a kártyája.
            if ( ! empty( $card['capability'] ) && ! current_user_can( $card['capability'] ) ) {
                continue;
            }

            $out[] = array(
                'key'      => sanitize_key( (string) $key ),
                'title'    => (string) $card['title'],
                'plugin'   => isset( $card['plugin'] ) ? (string) $card['plugin'] : '',
                'icon'     => isset( $card['icon'] ) ? sanitize_html_class( (string) $card['icon'] ) : 'dashicons-marker',
                'url'      => isset( $card['url'] ) ? (string) $card['url'] : '',
                'render'   => $card['render'],
                'priority' => isset( $card['priority'] ) ? (int) $card['priority'] : 10,
            );
        }

        usort(
            $out,
            static function ( $a, $b ) {
                if ( $a['priority'] === $b['priority'] ) {
                    return strcmp( $a['title'], $b['title'] );
                }
                return ( $a['priority'] < $b['priority'] ) ? -1 : 1;
            }
        );

        $this->cards = $out;
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Widget
     * ------------------------------------------------------------------- */

    /**
     * A widget regisztrálása — CSAK ha van legalább egy bejelentett kártya.
     * Üres „hamarosan" doboz nem kerül a Vezérlőpultra.
     *
     * A widget összecsukását és elrejtését a WordPress saját Vezérlőpult-UI-ja
     * kezeli (fogd-és-vidd + Képernyő beállításai), ezért nem építünk saját
     * bezáró/elvető mechanizmust.
     */
    public function register_widget(): void {
        if ( ! $this->cards() ) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            esc_html__( 'Qaiyo ecosystem', 'qaiyo-admin-booster' ),
            array( $this, 'render_widget' )
        );
    }

    /**
     * Stílus betöltése a Vezérlőpulton.
     *
     * @param string $hook Aktuális admin oldal hook.
     */
    public function enqueue( $hook ): void {
        if ( 'index.php' !== $hook || ! $this->cards() ) {
            return;
        }
        $css = QWAB_PATH . 'assets/css/qwab-ecosystem.css';
        wp_enqueue_style(
            'qwab-ecosystem',
            QWAB_URL . 'assets/css/qwab-ecosystem.css',
            array( 'dashicons' ),
            QWAB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : QWAB_VERSION )
        );
    }

    /**
     * A widget tartalma: kártyánként a testvér plugin saját renderelője.
     */
    public function render_widget(): void {
        echo '<div class="qwab-eco">';

        foreach ( $this->cards() as $card ) {
            ?>
            <div class="qwab-eco-card" data-card="<?php echo esc_attr( $card['key'] ); ?>">
                <div class="qwab-eco-card__head">
                    <span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true"></span>
                    <span class="qwab-eco-card__title"><?php echo esc_html( $card['title'] ); ?></span>
                    <?php if ( '' !== $card['plugin'] ) : ?>
                        <span class="qwab-eco-card__plugin"><?php echo esc_html( $card['plugin'] ); ?></span>
                    <?php endif; ?>
                </div>

                <div class="qwab-eco-card__body">
                    <?php $this->render_card_body( $card ); ?>
                </div>

                <?php if ( '' !== $card['url'] ) : ?>
                    <p class="qwab-eco-card__foot">
                        <a href="<?php echo esc_url( $card['url'] ); ?>"><?php esc_html_e( 'Open', 'qaiyo-admin-booster' ); ?> &rarr;</a>
                    </p>
                <?php endif; ?>
            </div>
            <?php
        }

        echo '</div>';
    }

    /**
     * Egy kártya törzsének renderelése a testvér plugin visszahívásával.
     *
     * A visszahívás idegen kód: ha hibázik, az NEM döntheti le az egész
     * Vezérlőpultot. Ezért elkapjuk a hibát, és csak az adott kártya helyén
     * jelzünk. A kimenetet nem escape-eljük, mert szándékosan tetszőleges
     * HTML — a testvér plugin felelőssége (ugyanúgy, ahogy a WordPress is
     * kezeli a `wp_add_dashboard_widget()` visszahívásokat).
     *
     * @param array $card Validált kártya-leíró.
     */
    private function render_card_body( array $card ): void {
        try {
            call_user_func( $card['render'] );
        } catch ( Throwable $e ) {
            echo '<p class="qwab-eco-card__error">' .
                esc_html__( 'This card could not be displayed.', 'qaiyo-admin-booster' ) .
                '</p>';
        }
    }
}

<?php
/**
 * Pro teaser renderer — a Free admin oldalon megjeleníti a Pro
 * modulokat lakatos kártyaként, az upgrade CTA-val.
 *
 * Qaiyo Design System lakatos minta (szaggatott keret, PRO badge,
 * lakat overlay, „Unlock in Pro →" link, upgrade doboz).
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Pro_Teaser {

    /**
     * Pro funkciók kártya HTML.
     */
    public static function render_card(): void {
        $locked = Qwab_Pro_Catalog::locked();
        if ( empty( $locked ) ) {
            return;
        }

        $url    = esc_url( Qwab_Pro_Catalog::upgrade_url() );
        $unlock = esc_html__( 'Unlock in Pro →', 'qaiyo-admin-booster' );
        $lock   = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="#fff" d="M6 10V8a6 6 0 1 1 12 0v2h1a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1h1zm2 0h8V8a4 4 0 1 0-8 0v2z"/></svg>';
        ?>
        <div class="qwab-card qwab-pro-teaser-card">
            <h2 class="qwab-card__title">
                <span class="qwab-card__title-icon qwab-card__title-icon--pro">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                </span>
                <?php esc_html_e( 'Pro features', 'qaiyo-admin-booster' ); ?>
                <span class="qwab-pro-pill">PRO</span>
            </h2>
            <p class="qwab-card__subtitle"><?php esc_html_e( 'Power-user upgrades available in the Pro add-on.', 'qaiyo-admin-booster' ); ?></p>

            <div class="qwab-pro-grid">
                <?php foreach ( $locked as $slug => $f ) : ?>
                    <a class="qwab-pro-locked" href="<?php echo esc_url( add_query_arg( 'utm_source', 'qwab-' . sanitize_key( $slug ), $url ) ); ?>" target="_blank" rel="noopener noreferrer">
                        <span class="qwab-pro-badge">PRO</span>
                        <span class="qwab-pro-preview">
                            <span class="dashicons <?php echo esc_attr( $f['icon'] ); ?> qwab-pro-preview-icon"></span>
                            <span class="qwab-pro-lock-overlay"><?php echo wp_kses( $lock, Qwab_Admin::svg_allowed_html() ); ?></span>
                        </span>
                        <h3 class="qwab-pro-title"><?php echo esc_html( $f['title'] ); ?></h3>
                        <p class="qwab-pro-desc"><?php echo esc_html( $f['desc'] ); ?></p>
                        <span class="qwab-pro-unlock"><?php echo esc_html( $unlock ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="qwab-pro-cta">
                <strong><?php esc_html_e( 'Qaiyo Admin Booster Pro', 'qaiyo-admin-booster' ); ?></strong>
                <a class="button button-primary qwab-pro-cta-button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Get Pro', 'qaiyo-admin-booster' ); ?>
                </a>
            </div>
        </div>
        <?php
    }
}

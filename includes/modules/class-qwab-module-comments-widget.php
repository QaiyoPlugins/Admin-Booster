<?php
/**
 * Modul: Hozzászólás-áttekintő vezérlőpult widget.
 *
 * A Vezérlőpultra tesz egy widgetet, amely mutatja a hozzászólások számát
 * (jóváhagyott / moderálásra váró / spam / kuka), és listázza a legutóbbi
 * hozzászólásokat. A widgetből a hozzászólások egyesével vagy tömegesen a
 * kukába helyezhetők — a tényleges műveletet a core `wp_trash_comment()`
 * végzi, megfelelő jogosultság-ellenőrzéssel.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hozzászólás-widget a Vezérlőpulton, törlési (kukázási) műveletekkel.
 */
class Qwab_Module_Comments_Widget {

	const WIDGET_ID = 'qwab_comments';
	const NONCE     = 'qwab_comments_nonce';
	const LIST_MAX  = 20;

	/**
	 * Hook-ok bekötése.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_qwab_comments_action', array( $this, 'ajax_action' ) );
	}

	/**
	 * Moderálhat-e a felhasználó hozzászólásokat?
	 *
	 * @return bool
	 */
	private function user_can(): bool {
		return current_user_can( 'moderate_comments' );
	}

	// -------------------------------------------------------------------------
	// Widget regisztráció
	// -------------------------------------------------------------------------

	/**
	 * A Vezérlőpult widget regisztrálása (csak moderátor jogkörrel).
	 */
	public function register_widget(): void {
		if ( ! $this->user_can() ) {
			return;
		}

		$counts = wp_count_comments();
		$title  = esc_html__( 'Comments', 'qaiyo-admin-booster' );
		if ( ! empty( $counts->moderated ) ) {
			$title .= ' <span class="qwab-cw-title-count">' . (int) $counts->moderated . '</span>';
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			$title, // A core nem escape-eli; a darabszám int-re kényszerítve.
			array( $this, 'render_widget' )
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
		if ( 'index.php' !== $hook || ! $this->user_can() ) {
			return;
		}

		$css = QWAB_PATH . 'assets/css/qwab-comments-widget.css';
		$js  = QWAB_PATH . 'assets/js/qwab-comments-widget.js';

		wp_enqueue_style(
			'qwab-comments-widget',
			QWAB_URL . 'assets/css/qwab-comments-widget.css',
			array( 'dashicons' ),
			QWAB_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : QWAB_VERSION )
		);
		wp_enqueue_script(
			'qwab-comments-widget',
			QWAB_URL . 'assets/js/qwab-comments-widget.js',
			array( 'jquery' ),
			QWAB_VERSION . '.' . ( file_exists( $js ) ? filemtime( $js ) : QWAB_VERSION ),
			true
		);
		wp_localize_script(
			'qwab-comments-widget',
			'qwabComments',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'confirmBulk' => __( 'Move the selected comments to the Trash?', 'qaiyo-admin-booster' ),
					'working'     => __( 'Working…', 'qaiyo-admin-booster' ),
					'error'       => __( 'Something went wrong.', 'qaiyo-admin-booster' ),
					'allDone'     => __( 'No comments to show.', 'qaiyo-admin-booster' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * A widget tartalmának kirajzolása.
	 */
	public function render_widget(): void {
		$counts   = wp_count_comments();
		$comments = get_comments(
			array(
				'number' => self::LIST_MAX,
				'status' => 'all', // jóváhagyott + moderálásra váró (spam/kuka nélkül).
				'type'   => 'comment',
				'order'  => 'DESC',
			)
		);
		?>
		<div id="qwab-cw" class="qwab-cw">
			<div class="qwab-cw-stats">
				<span class="qwab-cw-stat qwab-cw-stat--approved">
					<strong><?php echo (int) $counts->approved; ?></strong>
					<?php esc_html_e( 'Approved', 'qaiyo-admin-booster' ); ?>
				</span>
				<span class="qwab-cw-stat qwab-cw-stat--pending">
					<strong><?php echo (int) $counts->moderated; ?></strong>
					<?php esc_html_e( 'Pending', 'qaiyo-admin-booster' ); ?>
				</span>
				<span class="qwab-cw-stat qwab-cw-stat--spam">
					<strong><?php echo (int) $counts->spam; ?></strong>
					<?php esc_html_e( 'Spam', 'qaiyo-admin-booster' ); ?>
				</span>
				<span class="qwab-cw-stat qwab-cw-stat--trash">
					<strong><?php echo (int) $counts->trash; ?></strong>
					<?php esc_html_e( 'Trash', 'qaiyo-admin-booster' ); ?>
				</span>
			</div>

			<?php if ( empty( $comments ) ) : ?>
				<div class="qwab-cw-empty">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<p><?php esc_html_e( 'No comments to show.', 'qaiyo-admin-booster' ); ?></p>
				</div>
			<?php else : ?>
				<div class="qwab-cw-toolbar">
					<label class="qwab-cw-selall">
						<input type="checkbox" class="qwab-cw-checkall" />
						<?php esc_html_e( 'Select all', 'qaiyo-admin-booster' ); ?>
					</label>
					<button type="button" class="button qwab-cw-bulk-trash" disabled>
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Move to Trash', 'qaiyo-admin-booster' ); ?>
					</button>
				</div>

				<ul class="qwab-cw-list">
					<?php foreach ( $comments as $comment ) : ?>
						<?php $this->render_row( $comment ); ?>
					<?php endforeach; ?>
				</ul>

				<p class="qwab-cw-footer">
					<a href="<?php echo esc_url( admin_url( 'edit-comments.php' ) ); ?>">
						<?php esc_html_e( 'Manage all comments →', 'qaiyo-admin-booster' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Egyetlen hozzászólás-sor kirajzolása.
	 *
	 * @param WP_Comment $comment A hozzászólás.
	 */
	private function render_row( WP_Comment $comment ): void {
		$is_pending = ( '0' === (string) $comment->comment_approved );
		$author     = $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'qaiyo-admin-booster' );
		$excerpt    = wp_trim_words( $comment->comment_content, 18, '…' );
		$post_title = get_the_title( $comment->comment_post_ID );
		$post_link  = get_edit_post_link( $comment->comment_post_ID );
		?>
		<li class="qwab-cw-row<?php echo $is_pending ? ' is-pending' : ''; ?>" data-id="<?php echo (int) $comment->comment_ID; ?>">
			<label class="qwab-cw-check">
				<input type="checkbox" class="qwab-cw-cb" value="<?php echo (int) $comment->comment_ID; ?>" />
			</label>
			<div class="qwab-cw-body">
				<div class="qwab-cw-meta">
					<span class="qwab-cw-author"><?php echo esc_html( $author ); ?></span>
					<?php if ( $is_pending ) : ?>
						<span class="qwab-cw-pill"><?php esc_html_e( 'Pending', 'qaiyo-admin-booster' ); ?></span>
					<?php endif; ?>
					<span class="qwab-cw-date"><?php echo esc_html( get_comment_date( '', $comment ) ); ?></span>
				</div>
				<div class="qwab-cw-text"><?php echo esc_html( $excerpt ); ?></div>
				<?php if ( $post_title ) : ?>
					<div class="qwab-cw-on">
						<?php esc_html_e( 'on', 'qaiyo-admin-booster' ); ?>
						<?php if ( $post_link ) : ?>
							<a href="<?php echo esc_url( $post_link ); ?>"><?php echo esc_html( $post_title ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $post_title ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="qwab-cw-actions">
				<span class="qwab-cw-status" aria-live="polite"></span>
				<button type="button" class="button-link qwab-cw-trash" aria-label="<?php esc_attr_e( 'Move to Trash', 'qaiyo-admin-booster' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>
		</li>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: kukázás (egyesével / tömegesen)
	// -------------------------------------------------------------------------

	/**
	 * A kijelölt hozzászólás(ok) kukába helyezése.
	 */
	public function ajax_action(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! $this->user_can() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to moderate comments.', 'qaiyo-admin-booster' ) ), 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_filter( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing selected.', 'qaiyo-admin-booster' ) ) );
		}

		$done = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_comment', $id ) ) {
				continue;
			}
			if ( wp_trash_comment( $id ) ) {
				$done[] = $id;
			}
		}

		$counts = wp_count_comments();
		wp_send_json_success(
			array(
				'trashed' => $done,
				'counts'  => array(
					'approved'  => (int) $counts->approved,
					'moderated' => (int) $counts->moderated,
					'spam'      => (int) $counts->spam,
					'trash'     => (int) $counts->trash,
				),
			)
		);
	}
}

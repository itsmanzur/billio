<?php
/**
 * One-time "leave a review" nudge, shown only after the store has
 * generated enough invoices to have formed a real opinion of the plugin.
 *
 * @package Billio
 */

namespace Billio\Admin;

use Billio\Invoice\NumberManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReviewNotice {

	const THRESHOLD      = 10;
	const OPTION_DISMISS = 'billio_review_notice_dismissed';
	const NONCE_ACTION   = 'billio_dismiss_review_notice';

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_ajax_billio_dismiss_review_notice', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Whether the notice should show on the current screen.
	 *
	 * Only on WooCommerce / Billio screens (where the store owner is
	 * already working with orders) — not on every wp-admin page. And not
	 * on the Billio Dashboard itself, which renders its own inline version
	 * of this same notice (same threshold/dismiss logic).
	 *
	 * @return bool
	 */
	private function is_eligible() {
		if ( ! current_user_can( 'manage_woocommerce' ) || get_option( self::OPTION_DISMISS ) ) {
			return false;
		}

		if ( (int) get_option( NumberManager::OPTION_TOTAL_COUNT, 0 ) < self::THRESHOLD ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Dashboard::SCREEN_ID === $screen->id ) {
			return false;
		}

		$screen_ids = function_exists( 'wc_get_screen_ids' ) ? wc_get_screen_ids() : array();

		return in_array( $screen->id, $screen_ids, true ) || false !== strpos( $screen->id, 'billio' );
	}

	/**
	 * Enqueue the tiny dismiss handler (as a registered inline script, not
	 * a raw <script> tag in the notice markup) only where the notice shows.
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		if ( ! $this->is_eligible() ) {
			return;
		}

		wp_register_script( 'billio-review-notice', false, array(), BILLIO_VERSION, true );
		wp_enqueue_script( 'billio-review-notice' );
		wp_add_inline_script(
			'billio-review-notice',
			"document.addEventListener( 'click', function ( event ) {
				var notice = event.target.closest( '.billio-review-notice' );
				if ( ! notice || ! event.target.classList.contains( 'notice-dismiss' ) ) {
					return;
				}
				var body = new URLSearchParams();
				body.set( 'action', 'billio_dismiss_review_notice' );
				body.set( 'nonce', notice.dataset.nonce );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } );
			} );"
		);
	}

	/**
	 * Render the notice when eligible.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! $this->is_eligible() ) {
			return;
		}

		$total      = (int) get_option( NumberManager::OPTION_TOTAL_COUNT, 0 );
		$review_url = 'https://wordpress.org/support/plugin/billio/reviews/#new-post';
		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		?>
		<div class="notice notice-info is-dismissible billio-review-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<?php
				printf(
					/* translators: %d: number of invoices generated so far. */
					esc_html__( 'Billio has generated %d invoices for you. If it\'s working well, a quick review helps other store owners find it.', 'billio' ),
					esc_html( $total )
				);
				?>
				<a href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a review', 'billio' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX: remember the dismissal so the notice never shows again.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( null, 403 );
		}

		update_option( self::OPTION_DISMISS, 1 );
		wp_send_json_success();
	}
}

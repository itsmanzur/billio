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
		add_action( 'wp_ajax_billio_dismiss_review_notice', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Render the notice when eligible.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( get_option( self::OPTION_DISMISS ) ) {
			return;
		}

		// The Billio Dashboard renders its own inline version of this same
		// notice (same threshold/dismiss logic, just restyled to match the
		// rest of that page) — showing the generic admin_notices one too
		// would duplicate it right above.
		$screen = get_current_screen();
		if ( $screen && Dashboard::SCREEN_ID === $screen->id ) {
			return;
		}

		$total = (int) get_option( NumberManager::OPTION_TOTAL_COUNT, 0 );
		if ( $total < self::THRESHOLD ) {
			return;
		}

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
		<script>
		document.addEventListener( 'click', function ( event ) {
			var notice = event.target.closest( '.billio-review-notice' );
			if ( ! notice || ! event.target.classList.contains( 'notice-dismiss' ) ) {
				return;
			}
			var body = new URLSearchParams();
			body.set( 'action', 'billio_dismiss_review_notice' );
			body.set( 'nonce', notice.dataset.nonce );
			fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } );
		} );
		</script>
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

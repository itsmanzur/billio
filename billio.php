<?php
/**
 * Plugin Name:       Billio — PDF Invoices & Billing for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/billio
 * Description:       Automatically generate and attach branded PDF invoices to WooCommerce orders.
 * Version:           1.3.1
 * Author:            getwpkit
 * Author URI:        https://profiles.wordpress.org/getwpkit/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       billio
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      10.8
 *
 * @package Billio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BILLIO_VERSION', '1.3.1' );
define( 'BILLIO_FILE', __FILE__ );
define( 'BILLIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'BILLIO_URL', plugin_dir_url( __FILE__ ) );
define( 'BILLIO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Render an admin notice and bail out of boot.
 *
 * Used before we can rely on Composer's autoloader / Billio\Plugin existing.
 *
 * @param string $message Already-escaped notice text.
 * @return void
 */
function billio_require_notice( $message ) {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			?>
			<div class="notice notice-error">
				<p><?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			</div>
			<?php
		}
	);
}

if ( ! is_readable( BILLIO_PATH . 'vendor/autoload.php' ) ) {
	billio_require_notice( esc_html__( 'Billio: Composer dependencies are missing. Please reinstall the plugin.', 'billio' ) );
	return;
}

require_once BILLIO_PATH . 'vendor/autoload.php';

/**
 * Activation hook — delegate to the installer.
 */
register_activation_hook( __FILE__, array( \Billio\Installer::class, 'activate' ) );

/**
 * Deactivation hook — delegate to the installer.
 */
register_deactivation_hook( __FILE__, array( \Billio\Installer::class, 'deactivate' ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin after all plugins are loaded, but only when WooCommerce is active.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'billio_woocommerce_missing_notice' );
			return;
		}

		\Billio\Plugin::get_instance();
	}
);

/**
 * Render an admin notice when WooCommerce is not active.
 *
 * @return void
 */
function billio_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php esc_html_e( 'Billio requires WooCommerce to be installed and active.', 'billio' ); ?>
		</p>
	</div>
	<?php
}

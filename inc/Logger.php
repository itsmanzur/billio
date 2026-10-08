<?php
/**
 * Thin wrapper around WooCommerce's logger so invoice failures land
 * somewhere store admins already know to check (WooCommerce → Status → Logs)
 * instead of vanishing silently.
 *
 * @package Billio
 */

namespace Billio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	const SOURCE = 'billio';

	/**
	 * @param string $message Log message.
	 * @param array  $context Extra context (merged with the 'source' key WC expects).
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->error( $message, array_merge( array( 'source' => self::SOURCE ), $context ) );
	}
}

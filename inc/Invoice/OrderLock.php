<?php
/**
 * A minimal per-order lock so two near-simultaneous requests (e.g. a
 * customer double-clicking "Download", or an email webhook firing at the
 * same moment as a manual "Generate") can't both assign the order a
 * sequential invoice number.
 *
 * Built on a single atomic SQL statement rather than WordPress transients:
 * transients have no atomic "create if not exists" primitive (a read-then-
 * write has the same race condition we're trying to avoid), whereas
 * MySQL's INSERT IGNORE against wp_options' unique key on option_name
 * gives a real atomic acquire with no extra table.
 *
 * @package Billio
 */

namespace Billio\Invoice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderLock {

	const PREFIX = 'billio_lock_order_';

	/**
	 * Try to acquire the lock for an order.
	 *
	 * @param int $order_id Order to lock.
	 * @param int $ttl      Seconds before the lock is considered stale and
	 *                      reclaimable (guards against a crashed request
	 *                      leaving the lock held forever).
	 * @return bool True if the lock was acquired.
	 */
	public static function acquire( $order_id, $ttl = 30 ) {
		global $wpdb;

		$key     = self::PREFIX . $order_id;
		$expires = time() + $ttl;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$key,
				$expires
			)
		);

		if ( $inserted ) {
			return true;
		}

		// Lock already exists — reclaim it if it's stale (a previous holder
		// never released it, e.g. a fatal error or timeout mid-request).
		$current_expiry = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );

		if ( ! $current_expiry || $current_expiry >= time() ) {
			return false;
		}

		// Compare-and-swap on the previously-read expiry: a plain UPDATE with
		// no condition on the old value would let two waiters who both read
		// the same stale expiry both "succeed" and both believe they hold
		// the lock. Only the one whose WHERE still matches wins.
		$reclaimed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $expires ),
			array(
				'option_name'  => $key,
				'option_value' => (string) $current_expiry,
			)
		);

		return (bool) $reclaimed;
	}

	/**
	 * Release a previously-acquired lock.
	 *
	 * @param int $order_id Order to unlock.
	 * @return void
	 */
	public static function release( $order_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::PREFIX . $order_id ) );
	}
}

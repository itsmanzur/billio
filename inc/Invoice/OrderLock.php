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
	 * The stored option_value is "{$expires}:{$token}" — the token makes
	 * this a real ownership handle, not just a boolean, so release() can
	 * require the caller to prove it's still the current holder before
	 * deleting the row (see release() below).
	 *
	 * @param int $order_id Order to lock.
	 * @param int $ttl      Seconds before the lock is considered stale and
	 *                      reclaimable (guards against a crashed request
	 *                      leaving the lock held forever).
	 * @return string|false The lock handle to pass to release(), or false
	 *                       if the lock is currently held by someone else.
	 */
	public static function acquire( $order_id, $ttl = 30 ) {
		global $wpdb;

		$key   = self::PREFIX . $order_id;
		$value = ( time() + $ttl ) . ':' . wp_generate_password( 20, false, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Concurrency mutex lock requires direct atomic queries without caching.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$key,
				$value
			)
		);

		if ( $inserted ) {
			return $value;
		}

		// Lock already exists — reclaim it if it's stale (a previous holder
		// never released it, e.g. a fatal error or timeout mid-request).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh lock value lookup cannot be cached.
		$current_value  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		$current_expiry = $current_value ? (int) strtok( $current_value, ':' ) : 0;

		if ( ! $current_expiry || $current_expiry >= time() ) {
			return false;
		}

		// Compare-and-swap on the previously-read value: a plain UPDATE with
		// no condition on the old value would let two waiters who both read
		// the same stale value both "succeed" and both believe they hold
		// the lock. Only the one whose WHERE still matches wins.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap lock acquisition.
		$reclaimed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $value ),
			array(
				'option_name'  => $key,
				'option_value' => $current_value,
			)
		);

		return $reclaimed ? $value : false;
	}

	/**
	 * Release a previously-acquired lock.
	 *
	 * Deletes the row only if its value still matches the exact handle this
	 * caller was given by acquire() — if the lock expired and a new holder
	 * already reclaimed it (new token), this caller's delete matches zero
	 * rows instead of destroying the new holder's lock out from under it.
	 *
	 * @param int    $order_id Order to unlock.
	 * @param string $token    The handle returned by a prior acquire() call.
	 * @return void
	 */
	public static function release( $order_id, $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate, ownership-checked lock release.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::PREFIX . $order_id,
				'option_value' => $token,
			)
		);
	}
}

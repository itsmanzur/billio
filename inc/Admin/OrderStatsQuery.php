<?php
/**
 * Bulk, read-only column/meta lookups for the Dashboard's stats and search.
 *
 * The Dashboard only ever needs a handful of scalar fields (status, total,
 * currency, one date meta) for potentially thousands of orders — hydrating a
 * full \WC_Order for each (items, addresses, every meta row) just to read
 * those is what made the stats and search slow on large stores. Which
 * orders to include is still decided by wc_get_orders() (so WooCommerce's
 * own query semantics stay the source of truth); this class only reads the
 * fields for an already-chosen ID list, in chunked IN() queries, from
 * whichever order storage is authoritative (HPOS tables or posts/postmeta).
 *
 * @package Billio
 */

namespace Billio\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderStatsQuery {

	/**
	 * IDs per IN() query — keeps each statement well under typical
	 * max_allowed_packet / placeholder limits.
	 */
	const CHUNK_SIZE = 1000;

	/**
	 * Whether HPOS (custom order tables) is the authoritative order store.
	 *
	 * @return bool
	 */
	public static function uses_hpos() {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * One meta value per order/refund ID.
	 *
	 * @param int[]  $ids      Order or refund IDs.
	 * @param string $meta_key Meta key to read.
	 * @return array<int,string> ID => meta value (IDs without the meta are absent).
	 */
	public static function meta_map( array $ids, $meta_key ) {
		global $wpdb;

		$ids = self::clean_ids( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		if ( self::uses_hpos() ) {
			$table  = OrdersTableDataStore::get_meta_table_name();
			$id_col = 'order_id';
		} else {
			$table  = $wpdb->postmeta;
			$id_col = 'post_id';
		}

		$map = array();
		foreach ( array_chunk( $ids, self::CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table/column names are fixed internal identifiers; every value goes through prepare(); bulk read of fields for an ID list wc_get_orders() already chose.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$id_col} AS id, meta_value FROM {$table} WHERE meta_key = %s AND {$id_col} IN ({$placeholders})",
					array_merge( array( $meta_key ), $chunk )
				)
			);
			// phpcs:enable

			foreach ( $rows as $row ) {
				$map[ (int) $row->id ] = (string) $row->meta_value;
			}
		}

		return $map;
	}

	/**
	 * Status (without the "wc-" prefix), total and currency per order ID.
	 *
	 * @param int[] $ids Order IDs.
	 * @return array<int,array{status:string,total:float,currency:string}>
	 */
	public static function order_fields( array $ids ) {
		global $wpdb;

		$ids = self::clean_ids( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$fields = array();

		if ( self::uses_hpos() ) {
			$table = OrdersTableDataStore::get_orders_table_name();
			foreach ( array_chunk( $ids, self::CHUNK_SIZE ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- see meta_map().
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, status, total_amount, currency FROM {$table} WHERE id IN ({$placeholders})", $chunk )
				);
				// phpcs:enable
				foreach ( $rows as $row ) {
					$fields[ (int) $row->id ] = array(
						'status'   => self::strip_status_prefix( $row->status ),
						'total'    => (float) $row->total_amount,
						'currency' => (string) $row->currency,
					);
				}
			}

			return $fields;
		}

		foreach ( array_chunk( $ids, self::CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- see meta_map().
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT ID AS id, post_status FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $chunk )
			);
			// phpcs:enable
			foreach ( $rows as $row ) {
				$fields[ (int) $row->id ] = array(
					'status'   => self::strip_status_prefix( $row->post_status ),
					'total'    => 0.0,
					'currency' => '',
				);
			}
		}

		foreach ( self::meta_map( array_keys( $fields ), '_order_total' ) as $id => $total ) {
			$fields[ $id ]['total'] = (float) $total;
		}
		foreach ( self::meta_map( array_keys( $fields ), '_order_currency' ) as $id => $currency ) {
			$fields[ $id ]['currency'] = $currency;
		}

		return $fields;
	}

	/**
	 * "wc-completed" → "completed", matching WC_Order::get_status().
	 *
	 * @param string $status Raw stored status.
	 * @return string
	 */
	private static function strip_status_prefix( $status ) {
		$status = (string) $status;
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Positive, unique integer IDs.
	 *
	 * @param array $ids Raw IDs.
	 * @return int[]
	 */
	private static function clean_ids( array $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}
}

<?php
/**
 * Lightweight order stat record for Dashboard metrics.
 *
 * Stores only what the Dashboard needs (status, total, timestamp), avoiding
 * PHP memory exhaustion on stores with high order volumes.
 *
 * @package Billio
 */

namespace Billio\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardOrderRecord {

	/**
	 * @var string
	 */
	private $status;

	/**
	 * @var float
	 */
	private $total;

	/**
	 * @var int
	 */
	private $timestamp;

	/**
	 * @var string Order currency code ('' if unknown).
	 */
	private $currency = '';

	/**
	 * @param string $status    Order status.
	 * @param float  $total     Order total.
	 * @param int    $timestamp Invoice issue timestamp.
	 * @param string $currency  Order currency code.
	 */
	public function __construct( $status, $total, $timestamp, $currency = '' ) {
		$this->status    = (string) $status;
		$this->total     = (float) $total;
		$this->timestamp = (int) $timestamp;
		$this->currency  = (string) $currency;
	}

	/**
	 * @return string
	 */
	public function get_currency() {
		return (string) $this->currency;
	}

	/**
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * @return float
	 */
	public function get_total() {
		return $this->total;
	}

	/**
	 * @param string $key Meta key.
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return '_billio_invoice_date' === $key ? $this->timestamp : '';
	}
}

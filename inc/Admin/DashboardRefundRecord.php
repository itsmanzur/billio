<?php
/**
 * Lightweight refund stat record for Dashboard metrics.
 *
 * Stores only what the Dashboard needs (amount, timestamp), avoiding
 * PHP memory exhaustion on stores with high refund volumes.
 *
 * @package Billio
 */

namespace Billio\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardRefundRecord {

	/**
	 * @var float
	 */
	private $amount;

	/**
	 * @var int
	 */
	private $timestamp;

	/**
	 * @param float $amount    Refund amount.
	 * @param int   $timestamp Credit note issue timestamp.
	 */
	public function __construct( $amount, $timestamp ) {
		$this->amount    = (float) $amount;
		$this->timestamp = (int) $timestamp;
	}

	/**
	 * @return float
	 */
	public function get_amount() {
		return $this->amount;
	}

	/**
	 * @param string $key Meta key.
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return '_billio_credit_note_date' === $key ? $this->timestamp : '';
	}
}

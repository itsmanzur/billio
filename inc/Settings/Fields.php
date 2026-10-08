<?php
/**
 * Settings field schema — single source of truth for rendering, saving,
 * and sanitizing the Billio settings page.
 *
 * @package Billio
 */

namespace Billio\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fields {

	/**
	 * Get the field definitions.
	 *
	 * Each field: option (wp_options key), label, type
	 * (text|textarea|media|select|color|checkboxes|checkbox|number),
	 * default, and for "select"/"checkboxes" a choices map.
	 *
	 * @return array[]
	 */
	public static function get() {
		return array(
			array(
				'option'  => 'billio_company_name',
				'label'   => __( 'Company name', 'billio' ),
				'type'    => 'text',
				'default' => get_bloginfo( 'name' ),
			),
			array(
				'option'  => 'billio_company_logo_id',
				'label'   => __( 'Company logo', 'billio' ),
				'type'    => 'media',
				'default' => 0,
			),
			array(
				'option'  => 'billio_company_address',
				'label'   => __( 'Company address', 'billio' ),
				'type'    => 'textarea',
				'default' => '',
			),
			array(
				'option'  => 'billio_company_vat',
				'label'   => __( 'VAT / Tax number', 'billio' ),
				'type'    => 'text',
				'default' => '',
			),
			array(
				'option'  => 'billio_number_prefix',
				'label'   => __( 'Invoice number prefix', 'billio' ),
				'type'    => 'text',
				'default' => 'INV',
			),
			array(
				'option'      => 'billio_credit_note_prefix',
				'label'       => __( 'Credit note prefix', 'billio' ),
				'type'        => 'text',
				'default'     => 'CN',
				'description' => __( 'Credit notes have their own number sequence, separate from invoices.', 'billio' ),
			),
			array(
				'option'      => 'billio_number_format',
				'label'       => __( 'Number format', 'billio' ),
				'type'        => 'text',
				'default'     => '{PREFIX}-{YEAR}-{NUMBER}',
				'description' => __( 'Used for both invoices and credit notes. Placeholders: {PREFIX}, {YEAR}, {NUMBER}.', 'billio' ),
			),
			array(
				'option'      => 'billio_number_padding',
				'label'       => __( 'Number padding', 'billio' ),
				'type'        => 'number',
				'default'     => 4,
				'min'         => 1,
				'max'         => 10,
				'description' => __( 'How many digits the {NUMBER} placeholder is padded to, e.g. 4 → 0001.', 'billio' ),
			),
			array(
				'option'      => 'billio_number_reset_yearly',
				'label'       => __( 'Reset numbering each year', 'billio' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Starts a fresh sequence (1, 2, 3…) every January instead of counting up forever. Changing this mid-year starts a new sequence immediately.', 'billio' ),
			),
			array(
				'option'  => 'billio_paper_size',
				'label'   => __( 'Paper size', 'billio' ),
				'type'    => 'select',
				'default' => 'A4',
				'choices' => array(
					'A4'     => __( 'A4', 'billio' ),
					'Letter' => __( 'Letter', 'billio' ),
				),
			),
			array(
				'option'  => 'billio_email_triggers',
				'label'   => __( 'Send invoice when order is', 'billio' ),
				'type'    => 'checkboxes',
				'default' => array( 'customer_completed_order' ),
				'choices' => array(
					'customer_processing_order' => __( 'Processing (payment received)', 'billio' ),
					'customer_completed_order'  => __( 'Completed (order fulfilled)', 'billio' ),
				),
			),
			array(
				'option'  => 'billio_template',
				'label'   => __( 'Invoice template', 'billio' ),
				'type'    => 'select',
				'default' => 'default',
				'choices' => array(
					'default' => __( 'Default', 'billio' ),
					'modern'  => __( 'Modern', 'billio' ),
				),
			),
			array(
				'option'  => 'billio_accent_color',
				'label'   => __( 'Accent color (Modern template)', 'billio' ),
				'type'    => 'color',
				'default' => '#3b6cf4',
			),
		);
	}

	/**
	 * Sanitize a single submitted value according to its field type.
	 *
	 * @param array $field Field definition (from self::get()).
	 * @param mixed $value Raw submitted value.
	 * @return mixed
	 */
	public static function sanitize( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( wp_unslash( $value ) );
			case 'media':
				return absint( $value );
			case 'checkbox':
				// Unchecked boxes aren't submitted at all, so maybe_save()'s
				// isset()-fallback already turns that into '' here.
				return ! empty( $value );
			case 'number':
				$number = absint( $value );
				$min    = isset( $field['min'] ) ? (int) $field['min'] : 0;
				$max    = isset( $field['max'] ) ? (int) $field['max'] : PHP_INT_MAX;
				return max( $min, min( $max, $number ) );
			case 'checkboxes':
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_values( array_intersect( array_map( 'sanitize_text_field', wp_unslash( $value ) ), array_keys( $field['choices'] ) ) );
			case 'select':
				// array_key_exists() throws a TypeError on PHP 8 if $value
				// isn't scalar — a crafted field[]=a&field[]=b POST turns
				// $_POST[$field] into an array, which would otherwise fatal
				// the whole settings save.
				$value = is_scalar( $value ) ? (string) $value : '';
				return array_key_exists( $value, $field['choices'] ) ? $value : $field['default'];
			case 'color':
				$hex = sanitize_text_field( wp_unslash( $value ) );
				return preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ? $hex : $field['default'];
			default:
				return sanitize_text_field( wp_unslash( $value ) );
		}
	}
}

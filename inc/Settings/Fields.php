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
	 * Fallback "Send invoice when order is" triggers — used both as the
	 * field's own default and by email_triggers() below, so the value
	 * lives in exactly one place.
	 */
	const DEFAULT_EMAIL_TRIGGERS = array( 'customer_completed_order' );

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
				'default' => self::DEFAULT_EMAIL_TRIGGERS,
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
			array(
				'option'      => 'billio_watermark_paid',
				'label'       => __( 'PAID watermark', 'billio' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Display a subtle, angled PAID stamp on paid and completed order invoices.', 'billio' ),
			),
			array(
				'option'      => 'billio_show_thankyou_download',
				'label'       => __( 'Thank You page download', 'billio' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Show a prominent PDF download button on the checkout order received screen.', 'billio' ),
			),
			array(
				'option'      => 'billio_enable_tax_id',
				'label'       => __( 'Customer Tax ID at checkout', 'billio' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Allow B2B customers to enter their VAT / Tax / BIN number during checkout.', 'billio' ),
			),
			array(
				'option'      => 'billio_tax_id_label',
				'label'       => __( 'Customer Tax ID label', 'billio' ),
				'type'        => 'text',
				'default'     => __( 'VAT / Tax / BIN Number', 'billio' ),
				'description' => __( 'Label displayed on the checkout page, order details, and PDF invoice.', 'billio' ),
			),
			array(
				'option'      => 'billio_tax_id_required',
				'label'       => __( 'Require Tax ID at checkout', 'billio' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Make entering a Tax ID mandatory for customers during checkout.', 'billio' ),
			),
			array(
				'option'      => 'billio_customer_link_validity_days',
				'label'       => __( 'Customer link validity', 'billio' ),
				'type'        => 'number',
				'default'     => 30,
				'min'         => 0,
				'max'         => 3650,
				'description' => __( 'How many days a customer invoice/credit-note download link (My Account, Thank You page, Dashboard share links) stays valid before expiring. 0 = never expires.', 'billio' ),
			),
			array(
				'option'      => 'billio_customer_ondemand_generate',
				'label'       => __( 'Customer download generates invoice on demand', 'billio' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'If a customer requests an invoice before one exists yet (e.g. right after checkout), generate it on demand instead of only serving one already issued by the email trigger or an admin. Turning this off is a policy choice: a customer download link then only ever works once an invoice has actually been generated.', 'billio' ),
			),
		);
	}

	/**
	 * Sanitize a single submitted value according to its field type.
	 *
	 * @param array $field Field definition (from self::get()).
	 * @param mixed $value Submitted value, already wp_unslash()'d by the caller —
	 *                     unslashing again here would strip real backslashes.
	 * @return mixed
	 */
	public static function sanitize( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'media':
				// Must actually be an image attachment, not just any valid
				// attachment ID — render_preview() already guards logo
				// output the same way; without the same guard here, a
				// stale/tampered client value could persist a non-image ID
				// as the stored logo.
				$attachment_id = absint( $value );
				return $attachment_id && wp_attachment_is_image( $attachment_id ) ? $attachment_id : 0;
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
				return array_values( array_intersect( array_map( 'sanitize_text_field', $value ), array_keys( $field['choices'] ) ) );
			case 'select':
				// array_key_exists() throws a TypeError on PHP 8 if $value
				// isn't scalar — a crafted field[]=a&field[]=b POST turns
				// $_POST[$field] into an array, which would otherwise fatal
				// the whole settings save.
				$value = is_scalar( $value ) ? (string) $value : '';
				return array_key_exists( $value, $field['choices'] ) ? $value : $field['default'];
			case 'color':
				$hex = sanitize_text_field( $value );
				return preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ? $hex : $field['default'];
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * The enabled "Send invoice when order is" email triggers, guaranteed
	 * to be a non-empty array.
	 *
	 * get_option() normally returns the seeded default or a
	 * checkboxes-sanitized (and therefore already-array) value, but every
	 * reader historically called get_option() directly and cast/guarded
	 * it ad hoc — inconsistently, and not at all in a couple of spots,
	 * where a corrupted or hand-edited non-array option value would reach
	 * in_array() and throw a TypeError on PHP 8. Every reader now goes
	 * through here instead, so there's exactly one place this is handled.
	 *
	 * @return string[]
	 */
	public static function email_triggers() {
		$value = get_option( 'billio_email_triggers', self::DEFAULT_EMAIL_TRIGGERS );

		return is_array( $value ) && ! empty( $value ) ? $value : self::DEFAULT_EMAIL_TRIGGERS;
	}
}

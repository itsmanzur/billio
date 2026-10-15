<?php
/**
 * Zero-dependency unit tests for Billio's pure logic — runs with plain PHP,
 * no WordPress, database or PHPUnit install needed (same idea as
 * bin/make-pot.php). The few WordPress functions these classes call are
 * stubbed below with faithful-enough minimal versions.
 *
 * Covers: Bengali reordering, invoice path containment, number
 * formatting/padding, and settings sanitization.
 *
 * Usage: php tests/run.php        (exit code 0 = all passed)
 *
 * Not shipped in the production zip (see bin/build-zip.ps1).
 *
 * @package Billio
 */

// phpcs:ignoreFile -- dev-only test runner with WordPress function stubs.

define( 'ABSPATH', __DIR__ . '/' );
define( 'BILLIO_PATH', dirname( __DIR__ ) . '/' );

// ---------------------------------------------------------------------
// Minimal WordPress stubs.
// ---------------------------------------------------------------------

$GLOBALS['billio_test_options'] = array();
$GLOBALS['billio_test_uploads'] = '/srv/www/wp-content/uploads';

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['billio_test_options'] ) ? $GLOBALS['billio_test_options'][ $name ] : $default;
}
function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? time() : $timestamp );
}
function wp_normalize_path( $path ) {
	$path = str_replace( '\\', '/', $path );
	return preg_replace( '|(?<=.)/+|', '/', $path );
}
function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}
function wp_get_upload_dir() {
	return array( 'basedir' => $GLOBALS['billio_test_uploads'] );
}
function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value );
}
function sanitize_text_field( $str ) {
	if ( is_object( $str ) || is_array( $str ) ) {
		return '';
	}
	$str = strip_tags( (string) $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}
function sanitize_textarea_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}
function absint( $value ) {
	return abs( (int) $value );
}
function __( $text ) {
	return $text;
}
function get_bloginfo() {
	return 'Test Store';
}

require BILLIO_PATH . 'vendor/autoload.php';

use Billio\Invoice\BengaliShaper;
use Billio\Invoice\Generator;
use Billio\Invoice\NumberManager;
use Billio\Settings\Fields;

// ---------------------------------------------------------------------
// Tiny assertion helpers.
// ---------------------------------------------------------------------

$billio_failures = 0;
$billio_passes   = 0;

function check( $label, $expected, $actual ) {
	global $billio_failures, $billio_passes;
	if ( $expected === $actual ) {
		++$billio_passes;
		echo "  ok   {$label}\n";
		return;
	}
	++$billio_failures;
	echo "  FAIL {$label}\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n";
}

function section( $title ) {
	echo "\n{$title}\n";
}

function set_options( array $options ) {
	$GLOBALS['billio_test_options'] = $options;
}

// ---------------------------------------------------------------------
// BengaliShaper
// ---------------------------------------------------------------------

section( 'BengaliShaper' );
check( 'pre-base i-kar moves before its consonant', 'রিহম', BengaliShaper::reorder( 'রহিম' ) );
check( 'o-kar is split around the consonant', 'েকা', BengaliShaper::reorder( 'কো' ) );
check( 'au-kar is split around the consonant', 'েকৗ', BengaliShaper::reorder( 'কৌ' ) );
check( 'vowel sign moves before a whole conjunct', 'িস্ক', BengaliShaper::reorder( 'স্কি' ) );
check( 'non-Bengali text is untouched', '<p>Invoice #12</p>', BengaliShaper::reorder( '<p>Invoice #12</p>' ) );
$billio_bad_utf8 = "রহিম \xC3\x28";
check( 'invalid UTF-8 returns the input, not null', $billio_bad_utf8, BengaliShaper::reorder( $billio_bad_utf8 ) );

// ---------------------------------------------------------------------
// Generator::get_absolute_path() containment
// ---------------------------------------------------------------------

section( 'Generator::get_absolute_path()' );
$billio_blocked = '/srv/www/wp-content/uploads/__billio_blocked__';
check( 'normal invoice path resolves inside uploads/billio', '/srv/www/wp-content/uploads/billio/2026/12-abc.pdf', Generator::get_absolute_path( 'billio/2026/12-abc.pdf' ) );
check( 'credit note path resolves', '/srv/www/wp-content/uploads/billio/credit-notes/2026/1-2-x.pdf', Generator::get_absolute_path( 'billio/credit-notes/2026/1-2-x.pdf' ) );
check( 'traversal out of uploads is blocked', $billio_blocked, Generator::get_absolute_path( '../../wp-config.php' ) );
check( 'traversal from inside billio is blocked', $billio_blocked, Generator::get_absolute_path( 'billio/../../wp-config.php' ) );
check( 'the billio dir itself is blocked', $billio_blocked, Generator::get_absolute_path( 'billio' ) );
check( 'a sibling "billio-evil" dir is blocked', $billio_blocked, Generator::get_absolute_path( 'billio-evil/x.pdf' ) );
$GLOBALS['billio_test_uploads'] = '/srv/www/sites/2/../uploads';
check( 'basedir with ".." segments still allows real paths', '/srv/www/sites/uploads/billio/2026/1-a.pdf', Generator::get_absolute_path( 'billio/2026/1-a.pdf' ) );
$GLOBALS['billio_test_uploads'] = 'C:\\sites\\shop\\wp-content\\uploads';
check( 'Windows basedir works', 'C:/sites/shop/wp-content/uploads/billio/2026/1-a.pdf', Generator::get_absolute_path( 'billio/2026/1-a.pdf' ) );
$GLOBALS['billio_test_uploads'] = '/srv/www/wp-content/uploads';

// ---------------------------------------------------------------------
// NumberManager formatting
// ---------------------------------------------------------------------

section( 'NumberManager' );
$billio_year = gmdate( 'Y' );
set_options( array() );
check( 'default format', "INV-{$billio_year}-0001", NumberManager::preview_number( 1 ) );
check( 'credit note uses its own prefix', "CN-{$billio_year}-0007", NumberManager::preview_number( 7, 'credit_note' ) );
set_options( array( 'billio_number_padding' => 0 ) );
check( 'padding is clamped to at least 1', "INV-{$billio_year}-5", NumberManager::preview_number( 5 ) );
set_options( array( 'billio_number_padding' => 50 ) );
check( 'padding is clamped to at most 10', "INV-{$billio_year}-0000000005", NumberManager::preview_number( 5 ) );
set_options( array( 'billio_number_format' => '{PREFIX}/{YEAR}' ) );
check( 'format without {NUMBER} still yields a unique number', "INV/{$billio_year}-0003", NumberManager::preview_number( 3 ) );
set_options(
	array(
		'billio_number_format' => '{NUMBER}-{PREFIX}',
		'billio_number_prefix' => 'বিল',
	)
);
check( 'custom order and non-ASCII prefix', '0042-বিল', NumberManager::preview_number( 42 ) );
check( 'sequence key (no yearly reset)', 'billio_invoice_seq', NumberManager::sequence_option_key( 'invoice', false ) );
check( 'sequence key (yearly reset)', 'billio_invoice_seq_' . wp_date( 'Y' ), NumberManager::sequence_option_key( 'invoice', true ) );
check( 'credit note sequence key', 'billio_credit_note_seq', NumberManager::sequence_option_key( 'credit_note', false ) );

// ---------------------------------------------------------------------
// Fields::sanitize()
// ---------------------------------------------------------------------

section( 'Fields::sanitize()' );
set_options( array() );
$billio_fields = array();
foreach ( Fields::get() as $billio_field ) {
	$billio_fields[ $billio_field['option'] ] = $billio_field;
}
check( 'textarea keeps backslashes (no second unslash)', "Road\\5\nDhaka", Fields::sanitize( $billio_fields['billio_company_address'], "Road\\5\nDhaka" ) );
check( 'text strips tags', 'Acme', Fields::sanitize( $billio_fields['billio_company_name'], '<b>Acme</b>' ) );
check( 'text given an array becomes empty', '', Fields::sanitize( $billio_fields['billio_company_name'], array( 'x' ) ) );
check( 'select keeps a valid choice', 'Letter', Fields::sanitize( $billio_fields['billio_paper_size'], 'Letter' ) );
check( 'select falls back to default on an unknown value', 'A4', Fields::sanitize( $billio_fields['billio_paper_size'], 'A0' ) );
check( 'select given an array falls back to default', 'A4', Fields::sanitize( $billio_fields['billio_paper_size'], array( 'A4', 'Letter' ) ) );
check( 'color keeps a valid hex', '#112233', Fields::sanitize( $billio_fields['billio_accent_color'], '#112233' ) );
check( 'color rejects anything else', '#3b6cf4', Fields::sanitize( $billio_fields['billio_accent_color'], 'red;}' ) );
check( 'checkboxes keep only known choices', array( 'customer_completed_order' ), Fields::sanitize( $billio_fields['billio_email_triggers'], array( 'customer_completed_order', 'evil' ) ) );
check( 'checkboxes given a string become empty', array(), Fields::sanitize( $billio_fields['billio_email_triggers'], 'customer_completed_order' ) );
check( 'number is clamped to max', 10, Fields::sanitize( $billio_fields['billio_number_padding'], '99' ) );
check( 'number is clamped to min', 1, Fields::sanitize( $billio_fields['billio_number_padding'], '0' ) );
check( 'checkbox on', true, Fields::sanitize( $billio_fields['billio_watermark_paid'], '1' ) );
check( 'checkbox off', false, Fields::sanitize( $billio_fields['billio_watermark_paid'], '' ) );
check( 'media is an absolute int', 15, Fields::sanitize( $billio_fields['billio_company_logo_id'], '-15' ) );

// ---------------------------------------------------------------------

echo "\n{$billio_passes} passed, {$billio_failures} failed\n";
exit( $billio_failures ? 1 : 0 );

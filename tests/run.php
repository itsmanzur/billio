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

$GLOBALS['billio_test_options']            = array();
$GLOBALS['billio_test_uploads']            = '/srv/www/wp-content/uploads';
$GLOBALS['billio_test_image_attachments']  = array();

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
function wp_attachment_is_image( $attachment_id ) {
	return in_array( (int) $attachment_id, $GLOBALS['billio_test_image_attachments'], true );
}
function wp_salt( $scheme = 'auth' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- test stub matches the real function's signature.
	return 'test-only-fake-salt-not-a-real-secret';
}
$GLOBALS['billio_test_logged_in_user_id'] = 0;
$GLOBALS['billio_test_is_admin']          = false;
function is_user_logged_in() {
	return 0 !== $GLOBALS['billio_test_logged_in_user_id'];
}
function get_current_user_id() {
	return $GLOBALS['billio_test_logged_in_user_id'];
}
function current_user_can( $capability ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- test stub; only 'manage_woocommerce' is ever checked by the code under test.
	return $GLOBALS['billio_test_is_admin'];
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 ); // Dashboard::STATS_CACHE_TTL's class-const expression needs this at declare time.
}
$GLOBALS['billio_test_filters'] = array();
function add_filter( $tag, $callback ) {
	$GLOBALS['billio_test_filters'][ $tag ][] = $callback;
}
function remove_all_billio_test_filters( $tag ) {
	unset( $GLOBALS['billio_test_filters'][ $tag ] );
}
function apply_filters( $tag, $value ) {
	if ( empty( $GLOBALS['billio_test_filters'][ $tag ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['billio_test_filters'][ $tag ] as $callback ) {
		$value = call_user_func( $callback, $value );
	}
	return $value;
}

// Minimal stand-ins for the two WC classes EmailAttacher::latest_credit_noted_refund()
// and MyAccount::is_authorized_for_order() touch — just enough of their real
// shape (get_id/get_meta/get_date_created/get_refunds/get_customer_id/
// get_order_key) to exercise that logic without a real WooCommerce install.
class WC_Order_Refund {
	private $id;
	private $meta;
	private $date;

	public function __construct( $id, array $meta = array(), $date_timestamp = null ) {
		$this->id   = $id;
		$this->meta = $meta;
		$this->date = $date_timestamp;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function get_date_created() {
		return null === $this->date ? null : new DateTime( '@' . $this->date );
	}
}

class WC_Order {
	private $id;
	private $customer_id;
	private $order_key;
	private $refunds;

	// Public and mutable (unlike the constructor-set properties above) so
	// customer_download_eligible() tests can set just the bits they need
	// on a plain instance, e.g. `$o->meta = [...]; $o->is_paid = true;`,
	// without growing the constructor's positional arg list every time a
	// new test needs a different combination.
	public $meta    = array();
	public $is_paid = false;
	public $status  = '';

	public function __construct( $id = 1, $customer_id = 0, $order_key = '', array $refunds = array() ) {
		$this->id          = $id;
		$this->customer_id = $customer_id;
		$this->order_key   = $order_key;
		$this->refunds     = $refunds;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_customer_id() {
		return $this->customer_id;
	}

	public function get_order_key() {
		return $this->order_key;
	}

	public function get_refunds() {
		return $this->refunds;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function is_paid() {
		return $this->is_paid;
	}

	public function has_status( $statuses ) {
		return in_array( $this->status, (array) $statuses, true );
	}
}

require BILLIO_PATH . 'vendor/autoload.php';

use Billio\Admin\Dashboard;
use Billio\Frontend\MyAccount;
use Billio\Invoice\BengaliShaper;
use Billio\Invoice\EmailAttacher;
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
$GLOBALS['billio_test_image_attachments'] = array( 15 );
check( 'media accepts an absolute int that is an image attachment', 15, Fields::sanitize( $billio_fields['billio_company_logo_id'], '-15' ) );
check( 'media rejects an attachment id that is not an image', 0, Fields::sanitize( $billio_fields['billio_company_logo_id'], '99' ) );
$GLOBALS['billio_test_image_attachments'] = array();

// ---------------------------------------------------------------------
// Fields::email_triggers()
// ---------------------------------------------------------------------

section( 'Fields::email_triggers()' );
set_options( array() );
check( 'unset option falls back to the default', array( 'customer_completed_order' ), Fields::email_triggers() );
set_options( array( 'billio_email_triggers' => array( 'customer_processing_order' ) ) );
check( 'a valid stored array is returned as-is', array( 'customer_processing_order' ), Fields::email_triggers() );
set_options( array( 'billio_email_triggers' => array() ) );
check( 'an empty array falls back to the default', array( 'customer_completed_order' ), Fields::email_triggers() );
set_options( array( 'billio_email_triggers' => '' ) );
check( 'a non-array value falls back to the default (would otherwise TypeError in_array() on PHP 8)', array( 'customer_completed_order' ), Fields::email_triggers() );
set_options( array( 'billio_email_triggers' => false ) );
check( 'false falls back to the default', array( 'customer_completed_order' ), Fields::email_triggers() );
set_options( array() );

// ---------------------------------------------------------------------
// EmailAttacher::latest_credit_noted_refund()
// ---------------------------------------------------------------------

section( 'EmailAttacher::latest_credit_noted_refund()' );
check( 'no refunds at all returns null', null, EmailAttacher::latest_credit_noted_refund( new WC_Order( 1, 0, '', array() ) ) );
check(
	'a refund with no credit note number yet is not picked',
	null,
	EmailAttacher::latest_credit_noted_refund( new WC_Order( 1, 0, '', array( new WC_Order_Refund( 1, array() ) ) ) )
);
$billio_only_one = EmailAttacher::latest_credit_noted_refund(
	new WC_Order(
		1,
		0,
		'',
		array(
			new WC_Order_Refund( 1, array() ), // No credit note number — must be skipped.
			new WC_Order_Refund( 2, array( '_billio_credit_note_number' => 'CN-2026-0002' ), 1000 ),
		)
	)
);
check( 'among several refunds, only the one with a credit note number is picked', 2, $billio_only_one ? $billio_only_one->get_id() : null );
$billio_newest = EmailAttacher::latest_credit_noted_refund(
	new WC_Order(
		1,
		0,
		'',
		array(
			new WC_Order_Refund( 1, array( '_billio_credit_note_number' => 'CN-2026-0001' ), 1000 ),
			new WC_Order_Refund( 2, array( '_billio_credit_note_number' => 'CN-2026-0002' ), 2000 ),
		)
	)
);
check( 'the most recently created credit-noted refund is picked, not just the last in the list', 2, $billio_newest ? $billio_newest->get_id() : null );
$billio_tie = EmailAttacher::latest_credit_noted_refund(
	new WC_Order(
		1,
		0,
		'',
		array(
			new WC_Order_Refund( 5, array( '_billio_credit_note_number' => 'CN-2026-0005' ), 1000 ),
			new WC_Order_Refund( 3, array( '_billio_credit_note_number' => 'CN-2026-0003' ), 1000 ),
		)
	)
);
check( 'a tied date is broken by the higher (more recently created) refund id', 5, $billio_tie ? $billio_tie->get_id() : null );

// ---------------------------------------------------------------------
// MyAccount::make_token() / token_status()
// ---------------------------------------------------------------------

section( 'MyAccount::token_status()' );
$billio_future = time() + 3600;
$billio_past   = time() - 10;
$billio_valid_token = MyAccount::make_token( 5, 0, $billio_future );
check( 'make_token() returns a 64-char hex HMAC-SHA256', 1, preg_match( '/^[0-9a-f]{64}$/', $billio_valid_token ) );
check( 'a freshly-made, not-yet-expired token is valid', MyAccount::TOKEN_VALID, MyAccount::token_status( 5, 0, $billio_valid_token, $billio_future ) );
check(
	'a token whose signed expiry has already passed is expired',
	MyAccount::TOKEN_EXPIRED,
	MyAccount::token_status( 5, 0, MyAccount::make_token( 5, 0, $billio_past ), $billio_past )
);
check(
	'expires = 0 (the "never expires" sentinel) is always valid, however old',
	MyAccount::TOKEN_VALID,
	MyAccount::token_status( 5, 0, MyAccount::make_token( 5, 0, 0 ), 0 )
);
$billio_tampered    = $billio_valid_token;
$billio_tampered[0] = ( '0' === $billio_tampered[0] ) ? '1' : '0';
check( 'a tampered (one flipped character) token is invalid', MyAccount::TOKEN_INVALID, MyAccount::token_status( 5, 0, $billio_tampered, $billio_future ) );
check(
	"a token made for order #5 doesn't validate against order #6",
	MyAccount::TOKEN_INVALID,
	MyAccount::token_status( 6, 0, $billio_valid_token, $billio_future )
);
check(
	"a token made for an invoice (refund_id 0) doesn't validate for a credit note on the same order",
	MyAccount::TOKEN_INVALID,
	MyAccount::token_status( 5, 7, $billio_valid_token, $billio_future )
);
check( 'an empty token is "absent", not "invalid"', MyAccount::TOKEN_ABSENT, MyAccount::token_status( 5, 0, '', $billio_future ) );

// ---------------------------------------------------------------------
// MyAccount::is_authorized_for_order()
// ---------------------------------------------------------------------

section( 'MyAccount::is_authorized_for_order()' );
$billio_order = new WC_Order( 5, 42, 'wc_order_realkey123' );

$GLOBALS['billio_test_logged_in_user_id'] = 42;
$GLOBALS['billio_test_is_admin']          = false;
check( 'the logged-in owner is authorized even with a garbage token', true, MyAccount::is_authorized_for_order( $billio_order, 0, 'garbage', 0, '' ) );

$GLOBALS['billio_test_logged_in_user_id'] = 999; // a different, unrelated logged-in customer
$GLOBALS['billio_test_is_admin']          = true;
check( 'an admin is authorized even with a garbage token', true, MyAccount::is_authorized_for_order( $billio_order, 0, 'garbage', 0, '' ) );

$GLOBALS['billio_test_logged_in_user_id'] = 0; // guest
$GLOBALS['billio_test_is_admin']          = false;
check(
	'a guest with a valid token is authorized',
	true,
	MyAccount::is_authorized_for_order( $billio_order, 0, MyAccount::make_token( 5, 0, $billio_future ), $billio_future, '' )
);
check(
	'a guest with an expired token and no order_key is denied',
	false,
	MyAccount::is_authorized_for_order( $billio_order, 0, MyAccount::make_token( 5, 0, $billio_past ), $billio_past, '' )
);
check( 'a guest with the correct legacy order_key is authorized by default', true, MyAccount::is_authorized_for_order( $billio_order, 0, '', 0, 'wc_order_realkey123' ) );
check( 'a guest with the wrong legacy order_key is denied', false, MyAccount::is_authorized_for_order( $billio_order, 0, '', 0, 'wrong-key' ) );

add_filter(
	'billio_allow_legacy_order_key',
	function () {
		return false;
	}
);
check(
	'the correct legacy order_key is rejected once billio_allow_legacy_order_key returns false',
	false,
	MyAccount::is_authorized_for_order( $billio_order, 0, '', 0, 'wc_order_realkey123' )
);
remove_all_billio_test_filters( 'billio_allow_legacy_order_key' );

// ---------------------------------------------------------------------
// MyAccount::customer_download_eligible()
// ---------------------------------------------------------------------

section( 'MyAccount::customer_download_eligible()' );

set_options( array( 'billio_customer_ondemand_generate' => true ) );
$billio_already_issued = new WC_Order();
$billio_already_issued->meta = array( '_billio_invoice_number' => 'INV-2026-0001' );
check( 'an order with an invoice number already is always eligible', true, MyAccount::customer_download_eligible( $billio_already_issued ) );

$billio_paid = new WC_Order();
$billio_paid->is_paid = true;
check( 'on-demand generation on (default): a paid order with no invoice yet is eligible', true, MyAccount::customer_download_eligible( $billio_paid ) );

$billio_processing = new WC_Order();
$billio_processing->status = 'processing';
check( 'on-demand generation on: a processing order (not necessarily is_paid()) is eligible', true, MyAccount::customer_download_eligible( $billio_processing ) );

$billio_pending = new WC_Order();
$billio_pending->status = 'pending';
check( 'on-demand generation on: a pending, unpaid order is not eligible', false, MyAccount::customer_download_eligible( $billio_pending ) );

set_options( array( 'billio_customer_ondemand_generate' => false ) );
$billio_paid_off = new WC_Order();
$billio_paid_off->is_paid = true;
check( 'on-demand generation off: a paid order with no invoice yet is NOT eligible', false, MyAccount::customer_download_eligible( $billio_paid_off ) );

$billio_issued_off = new WC_Order();
$billio_issued_off->meta = array( '_billio_invoice_number' => 'INV-2026-0002' );
check( 'on-demand generation off: an order with an invoice number already is still eligible', true, MyAccount::customer_download_eligible( $billio_issued_off ) );
set_options( array() );

// ---------------------------------------------------------------------
// Dashboard::invoice_issue_timestamp() / credit_note_issue_timestamp()
// ---------------------------------------------------------------------
// These used to fall back through get_date_paid()/get_date_completed()/
// get_date_created() for orders invoiced before _billio_invoice_date
// existed (pre-1.1.0 data). That fallback chain — and the matching
// get_legacy_invoiced_orders()/get_legacy_credit_noted_refunds() bulk
// queries that fed it — has been removed: every invoiced order has had
// this meta set at assignment time since 1.1.0, and nothing before that
// is live anywhere, so it's now a plain, direct meta read.

section( 'Dashboard::invoice_issue_timestamp()' );

$billio_dated_order = new WC_Order();
$billio_dated_order->meta = array( '_billio_invoice_date' => 1700000000 );
check( 'a present, valid _billio_invoice_date meta is returned as-is', 1700000000, Dashboard::invoice_issue_timestamp( $billio_dated_order ) );

$billio_undated_order = new WC_Order();
check( 'a missing _billio_invoice_date meta returns 0, not a notice/fatal', 0, Dashboard::invoice_issue_timestamp( $billio_undated_order ) );

$billio_bad_date_order = new WC_Order();
$billio_bad_date_order->meta = array( '_billio_invoice_date' => -5 );
check( 'a negative/invalid _billio_invoice_date meta is clamped to 0', 0, Dashboard::invoice_issue_timestamp( $billio_bad_date_order ) );

check( 'an object with no get_meta() at all (e.g. unexpected input) returns 0, not a fatal', 0, Dashboard::invoice_issue_timestamp( new stdClass() ) );

section( 'Dashboard::credit_note_issue_timestamp()' );

$billio_dated_refund = new WC_Order_Refund( 1, array( '_billio_credit_note_date' => 1700000000 ) );
check( 'a present, valid _billio_credit_note_date meta is returned as-is', 1700000000, Dashboard::credit_note_issue_timestamp( $billio_dated_refund ) );

$billio_undated_refund = new WC_Order_Refund( 2, array() );
check( 'a missing _billio_credit_note_date meta returns 0, not a notice/fatal', 0, Dashboard::credit_note_issue_timestamp( $billio_undated_refund ) );

$billio_bad_date_refund = new WC_Order_Refund( 3, array( '_billio_credit_note_date' => -10 ) );
check( 'a negative/invalid _billio_credit_note_date meta is clamped to 0', 0, Dashboard::credit_note_issue_timestamp( $billio_bad_date_refund ) );

check( 'an object with no get_meta() at all returns 0, not a fatal', 0, Dashboard::credit_note_issue_timestamp( new stdClass() ) );

// Note on date-range boundary (inclusive/exclusive): orders_issued_between()/
// credit_notes_issued_between() now resolve the window entirely inside a
// single wc_get_orders() meta_query ('compare' => 'BETWEEN'), which is
// WooCommerce/MySQL's own both-inclusive semantics — there's no Billio-side
// conditional left on the PHP side to unit-test here. If you want that
// verified empirically against a real BETWEEN query, that's a
// tests/integration.php / WP-CLI check, not this zero-dependency suite.

// ---------------------------------------------------------------------

echo "\n{$billio_passes} passed, {$billio_failures} failed\n";
exit( $billio_failures ? 1 : 0 );

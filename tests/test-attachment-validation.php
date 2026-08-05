<?php
/**
 * Standalone logic tests for TBTS_Classes::validate_attachment().
 *
 * This is the server-side guarantee behind the generator's class/lesson picker:
 * a deck attached to a class must name a lesson in that class, and "Bez klasy"
 * stays a fully supported state needing no lesson. The picker's pre-selection is
 * convenience only, so these rules are asserted here rather than in the browser.
 *
 * WordPress is not loaded; TBT_Notes_DB is stubbed with just the two lookups the
 * bridge reads through.
 *
 * Run: php tests/test-attachment-validation.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

function __( $s, $d = '' ) { return $s; }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/** Teacher 2 owns class 5 (lessons 7, 8). Teacher 99 owns class 6. */
class TBT_Notes_DB {
	public static $classes = array(
		5 => array( 'id' => 5, 'title' => 'Klasa A', 'teacher_id' => 2 ),
		6 => array( 'id' => 6, 'title' => 'Klasa B', 'teacher_id' => 99 ),
	);
	public static $lessons = array(
		7 => array( 'id' => 7, 'class_id' => 5, 'header' => 'Lekcja 3' ),
		8 => array( 'id' => 8, 'class_id' => 5, 'header' => 'Lekcja 4' ),
		9 => array( 'id' => 9, 'class_id' => 6, 'header' => 'Lekcja 1' ),
	);
	public static function get_classes_for_teacher( $id ) { return array(); }
	public static function get_lessons_for_class( $id ) { return array(); }
	public static function get_class( $id ) {
		return isset( self::$classes[ (int) $id ] ) ? self::$classes[ (int) $id ] : null;
	}
	public static function get_lesson( $id ) {
		return isset( self::$lessons[ (int) $id ] ) ? self::$lessons[ (int) $id ] : null;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-tbts-classes.php';

echo "Attachment validation — a class needs a lesson:\n";

$ok = TBTS_Classes::validate_attachment( 2, 5, 7 );
ok( ! is_wp_error( $ok ) && $ok === array( 'class_id' => 5, 'lesson_id' => 7 ), 'a class with one of its own lessons is accepted' );

$missing = TBTS_Classes::validate_attachment( 2, 5, null );
ok( is_wp_error( $missing ) && $missing->get_error_code() === 'tbts_lesson_required', 'a class with no lesson is rejected as tbts_lesson_required' );
ok( is_wp_error( $missing ) && $missing->get_error_message() === 'Wybierz lekcję dla tej klasy.', 'the rejection explains what to do' );
ok( is_wp_error( $missing ) && $missing->data['status'] === 400, 'it is a 400, not a 403 — the request is malformed, not forbidden' );

// absint() turns an absent lesson_id into 0 before this is called.
$zero = TBTS_Classes::validate_attachment( 2, 5, 0 );
ok( is_wp_error( $zero ) && $zero->get_error_code() === 'tbts_lesson_required', 'lesson_id 0 is treated as absent, not as a lesson' );

echo "Attachment validation — Bez klasy stays supported:\n";
ok( TBTS_Classes::validate_attachment( 2, null, null ) === array( 'class_id' => null, 'lesson_id' => null ), 'no class and no lesson is accepted' );
ok( TBTS_Classes::validate_attachment( 2, 0, 0 ) === array( 'class_id' => null, 'lesson_id' => null ), 'class 0 is unattached, not an error' );
ok( TBTS_Classes::validate_attachment( 2, null, 7 ) === array( 'class_id' => null, 'lesson_id' => null ), 'a lesson without a class is dropped, not rejected' );

echo "Attachment validation — the older rules still run:\n";
$foreign = TBTS_Classes::validate_attachment( 2, 6, 9 );
ok( is_wp_error( $foreign ) && $foreign->get_error_code() === 'tbts_invalid_class', "another teacher's class is still refused" );

$wrongLesson = TBTS_Classes::validate_attachment( 2, 5, 9 );
ok( is_wp_error( $wrongLesson ) && $wrongLesson->get_error_code() === 'tbts_invalid_lesson', 'a lesson from a different class is still refused' );
ok( is_wp_error( TBTS_Classes::validate_attachment( 2, 5, 404 ) ), 'a lesson that does not exist is refused' );

// Ownership is checked before the lesson requirement: a teacher poking at
// someone else's class should not learn whether it has lessons.
ok( TBTS_Classes::validate_attachment( 2, 6, null )->get_error_code() === 'tbts_invalid_class', "ownership is reported before the missing lesson" );

echo "\nPassed: $pass  Failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );

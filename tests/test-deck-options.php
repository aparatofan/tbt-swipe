<?php
/**
 * Standalone logic tests for the deck options added in the DECK OPTIONS spec:
 * which face a deck leads with, and whether it belongs to a class at all.
 *
 * These are the two server-side guarantees behind the new radios. The builder
 * pre-selects the right values, but that is convenience — what matters is that
 * a submitted value that is missing, misspelt or hostile resolves to the
 * historic behaviour ('term', 'class'), and that a deck saved as open cannot
 * carry a class along with it however the payload is shaped.
 *
 * WordPress is not loaded; TBT_Notes_DB is stubbed with the two lookups the
 * class bridge reads through.
 *
 * Run: php tests/test-deck-options.php
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

/** Teacher 2 owns class 5, which has lesson 7. */
class TBT_Notes_DB {
	public static function get_classes_for_teacher( $id ) { return array(); }
	public static function get_lessons_for_class( $id ) { return array(); }
	public static function get_class( $id ) {
		return 5 === (int) $id ? array( 'id' => 5, 'title' => 'Klasa A', 'teacher_id' => 2 ) : null;
	}
	public static function get_lesson( $id ) {
		return 7 === (int) $id ? array( 'id' => 7, 'class_id' => 5, 'header' => 'Lekcja 3' ) : null;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-tbts-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-tbts-db.php';

echo "First face — the deck decides, and 'term' is what silence means:\n";
ok( 'term' === TBTS_DB::normalise_front_face( 'term' ), 'term is kept' );
ok( 'translation' === TBTS_DB::normalise_front_face( 'translation' ), 'translation is kept' );
ok( 'translation' === TBTS_DB::normalise_front_face( ' Translation ' ), 'case and padding do not change the answer' );
ok( 'term' === TBTS_DB::normalise_front_face( '' ), 'an empty value reads term, as every deck did before the option existed' );
ok( 'term' === TBTS_DB::normalise_front_face( null ), 'a non-string reads term' );
ok( 'term' === TBTS_DB::normalise_front_face( 'ipa' ), 'an unknown face reads term rather than failing the save' );

echo "\nDeck type — open is only ever open because the deck says so:\n";
ok( 'class' === TBTS_DB::normalise_deck_type( 'class' ), 'class is kept' );
ok( 'open' === TBTS_DB::normalise_deck_type( 'open' ), 'open is kept' );
ok( 'open' === TBTS_DB::normalise_deck_type( 'OPEN' ), 'case does not change the answer' );
ok( 'class' === TBTS_DB::normalise_deck_type( '' ), 'an empty value reads class' );
ok( 'class' === TBTS_DB::normalise_deck_type( 'public' ), 'an unknown type reads class, never open' );

$row = new stdClass();
$row->deck_type = 'open';
ok( TBTS_DB::is_open_deck( $row ), 'a row saying open is an open deck' );
ok( ! TBTS_DB::is_open_deck( new stdClass() ), 'a row from before the column existed is a class deck' );

echo "\nOpen decks carry no class, whatever the payload claims:\n";
$tampered = TBTS_Classes::validate_attachment_for_type( 'open', 2, 5, 7 );
ok( $tampered === array( 'class_id' => null, 'lesson_id' => null ), 'a class the teacher really owns is still dropped from an open deck' );
ok(
	TBTS_Classes::validate_attachment_for_type( 'open', 2, 6, 9 ) === array( 'class_id' => null, 'lesson_id' => null ),
	"another teacher's class is dropped rather than refused — an open deck never had a class to check"
);
ok( TBTS_Classes::validate_attachment_for_type( 'open', 2, null, null ) === array( 'class_id' => null, 'lesson_id' => null ), 'an open deck with nothing attached is accepted' );

echo "\nClass decks keep every rule they had:\n";
ok( TBTS_Classes::validate_attachment_for_type( 'class', 2, 5, 7 ) === array( 'class_id' => 5, 'lesson_id' => 7 ), 'a class with one of its own lessons is accepted' );
ok( is_wp_error( TBTS_Classes::validate_attachment_for_type( 'class', 2, 6, 9 ) ), "another teacher's class is still refused" );
ok( is_wp_error( TBTS_Classes::validate_attachment_for_type( 'class', 2, 5, null ) ), 'a class with no lesson is still refused' );
ok( TBTS_Classes::validate_attachment_for_type( 'class', 2, null, null ) === array( 'class_id' => null, 'lesson_id' => null ), 'a class deck with no class stays the supported unattached state' );

echo "\nPassed: $pass  Failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Standalone logic tests for TBTS_Levels.
 *
 * These are the rules a teacher would have to catch by eye otherwise: a
 * decimal level from TBT Students collapses to its band, A0 generates as A1,
 * a group class is pitched at its weakest student, and every "no idea" case
 * leaves the picker alone instead of guessing.
 *
 * WordPress is not loaded. TBT_Students and the Notes bridge are stubbed with
 * exactly the two lookups TBTS_Levels reads through, so only its own logic is
 * under test.
 *
 * Run: php tests/test-levels.php
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
function _n( $single, $plural, $n, $d = '' ) { return 1 === $n ? $single : $plural; }

$GLOBALS['user_meta'] = array();
function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['user_meta'][ $user_id ][ $key ] ?? '';
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['user_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function get_userdata( $user_id ) {
	$names = array( 11 => 'Anna Baran', 12 => 'Piotr Nowak', 13 => 'Ewa Lis', 14 => 'Jan Kos' );
	if ( ! isset( $names[ $user_id ] ) ) {
		return false;
	}
	return (object) array( 'display_name' => $names[ $user_id ] );
}

/** The class rosters the bridge would read out of TBT Notes. */
class TBTS_Classes {
	public static $rosters = array();
	public static function student_ids_for_class( $class_id ) {
		return self::$rosters[ (int) $class_id ] ?? array();
	}
}

/** The levels TBT Students holds, on its own 25-step scale. */
class TBT_Students {
	public static $levels = array();
	public static function get_level( $user_id ) {
		return self::$levels[ (int) $user_id ] ?? '';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-tbts-levels.php';

echo "Normalising — Swipe has six bands and no decimals:\n";
ok( 'B1' === TBTS_Levels::normalise( 'B1.3' ), 'B1.3 reduces to B1' );
ok( 'B1' === TBTS_Levels::normalise( 'B1.5' ), 'B1.5 reduces to B1' );
ok( 'B1' === TBTS_Levels::normalise( 'B1.7' ), 'B1.7 reduces to B1' );
ok( 'C1' === TBTS_Levels::normalise( 'c1.7' ), 'a lower-case level still resolves' );
ok( 'C2' === TBTS_Levels::normalise( ' C2 ' ), 'surrounding space is trimmed' );
ok( 'A1' === TBTS_Levels::normalise( 'A0' ), 'A0 maps to A1 — there is no sentence below A1' );
ok( 'A1' === TBTS_Levels::normalise( 'A0.7' ), 'A0.7 maps to A1 as well' );
ok( '' === TBTS_Levels::normalise( '' ), 'no level stays no level' );
ok( '' === TBTS_Levels::normalise( 'D3' ), 'a level off the scale is not a level' );
ok( '' === TBTS_Levels::normalise( null ), 'a non-string is not a level' );

echo "Sanitising — a bad level never costs a generation:\n";
ok( 'B1' === TBTS_Levels::sanitize( 'nonsense' ), 'nonsense falls back to B1' );
ok( 'B1' === TBTS_Levels::sanitize( '' ), 'an empty level falls back to B1' );
ok( 'A2' === TBTS_Levels::sanitize( 'A2' ), 'a real band is kept' );
ok( 'B1' === TBTS_Levels::DEFAULT_BAND, "the default is today's hardcoded level" );

echo "Prompt — the level is spelled out, not merely named:\n";
$a1 = TBTS_Levels::prompt_block( 'A1' );
$c1 = TBTS_Levels::prompt_block( 'C1' );
ok( false !== strpos( $a1, 'CEFR A1 (elementary)' ), 'the A1 block names the band and its plain name' );
ok( false !== strpos( $a1, '6-10 words' ), 'the A1 block carries its length guardrail' );
ok( false !== strpos( $c1, 'nominalisation' ), 'the C1 block carries its grammar ceiling' );
ok( $a1 !== $c1, 'two bands produce different instructions' );
ok( false !== strpos( $a1, 'guardrails, not targets' ), 'word counts are stated as guardrails' );
// The one rule the whole feature rests on: without it the model swaps a hard
// item for an easy synonym and the card teaches nothing.
$verbatim = 'The level constrains the language around the target item, never the target item itself. '
	. 'If the item is above the requested level, keep it exactly as given and build a sentence at '
	. 'the requested level around it. Do not substitute an easier word for the item, and do not '
	. 'simplify the item.';
ok( false !== strpos( $a1, $verbatim ), 'the target-item rule appears verbatim' );
ok( false !== strpos( $c1, $verbatim ), 'and in every band, not just the low ones' );
ok( TBTS_Levels::prompt_block( 'junk' ) === TBTS_Levels::prompt_block( 'B1' ), 'an unknown band still yields the B1 block' );

echo "Suggesting — the class picks the level, lowest student first:\n";
TBTS_Classes::$rosters = array(
	1 => array( 11 ),                     // one student, B1.7
	2 => array( 11, 12, 13 ),             // three students, all levelled
	3 => array( 11, 12, 13, 14 ),         // four students, two levelled
	4 => array( 12, 13 ),                 // two students, neither levelled
	5 => array(),                         // empty class
	6 => array( 11, 14 ),                 // A0 in the room
);
TBT_Students::$levels = array(
	11 => 'B1.7',
	12 => '',
	13 => '',
	14 => 'A0.5',
);

$one = TBTS_Levels::suggest_for_class( 1 );
ok( 'B1' === $one['suggested'], 'a one-student class pre-selects that student\'s band' );
ok( 'Anna Baran · B1' === $one['note'], 'and the note names the student' );

TBT_Students::$levels = array( 11 => 'B2', 12 => 'A2.3', 13 => 'C1' );
$group = TBTS_Levels::suggest_for_class( 2 );
ok( 'A2' === $group['suggested'], 'a group takes the lowest band, not the average' );
ok( '3 students, A2–C1 · set from the lowest' === $group['note'], 'and the note shows the spread' );

TBT_Students::$levels = array( 11 => 'B1', 12 => 'B1.5', 13 => 'B1.7' );
$same = TBTS_Levels::suggest_for_class( 2 );
ok( 'B1' === $same['suggested'], 'a class on one band suggests that band' );
ok( '3 students, all B1 · set from the lowest' === $same['note'], 'and says so rather than printing B1–B1' );

TBT_Students::$levels = array( 11 => 'B2', 12 => 'A2', 13 => '', 14 => '' );
$partial = TBTS_Levels::suggest_for_class( 3 );
ok( 'A2' === $partial['suggested'], 'unlevelled students do not drag the suggestion' );
ok( '2 of 4 students have a level · set from the lowest' === $partial['note'], 'and the note admits the gap' );

TBT_Students::$levels = array( 11 => 'B2', 12 => '', 13 => '', 14 => '' );
$lonely = TBTS_Levels::suggest_for_class( 3 );
ok( '1 of 4 students has a level · set from the lowest' === $lonely['note'], 'one levelled student of four reads as singular' );

TBT_Students::$levels = array( 11 => 'B1', 14 => 'A0' );
$beginner = TBTS_Levels::suggest_for_class( 6 );
ok( 'A1' === $beginner['suggested'], 'an A0 student pre-selects A1' );

echo "Suggesting — every unknown is a null, never a guess:\n";
TBT_Students::$levels = array();
$noLevels = TBTS_Levels::suggest_for_class( 4 );
ok( null === $noLevels['suggested'] && '' === $noLevels['note'], 'a class whose students have no levels suggests nothing' );

$empty = TBTS_Levels::suggest_for_class( 5 );
ok( null === $empty['suggested'], 'an empty class suggests nothing' );

$missing = TBTS_Levels::suggest_for_class( 404 );
ok( null === $missing['suggested'], 'a class with no roster suggests nothing' );

echo "Remembering — the picker opens where the teacher left it:\n";
ok( 'B1' === TBTS_Levels::initial_band( 7 ), 'a teacher who has never generated starts at B1' );
TBTS_Levels::remember( 7, 'C1' );
ok( 'C1' === TBTS_Levels::initial_band( 7 ), 'the last used band comes back' );
TBTS_Levels::remember( 7, 'rubbish' );
ok( 'C1' === TBTS_Levels::initial_band( 7 ), 'and rubbish never overwrites it' );

echo "\nPassed: $pass  Failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );

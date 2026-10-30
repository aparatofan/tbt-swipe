<?php
/**
 * Standalone logic tests for TBTS_Examples.
 *
 * The group counts are stated to the model as exact numbers, so they are
 * pinned here as numbers. The rest checks that each conditional rule appears
 * only where it applies, and that no real name has crept into the prompt — a
 * name in the instructions turns up on every deck.
 *
 * WordPress is not loaded; TBT_Students is not needed because the profile is
 * passed straight in.
 *
 * Run: php tests/test-examples.php
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

require_once dirname( __DIR__ ) . '/includes/class-tbts-register.php';
require_once dirname( __DIR__ ) . '/includes/class-tbts-levels.php';
require_once dirname( __DIR__ ) . '/includes/class-tbts-examples.php';

/** W/E/P as one string, so a mismatch prints readably. */
function wep( $counts ) {
	return $counts['world'] . '/' . $counts['everyday'] . '/' . $counts['profile'];
}

echo "Strand counts — exact numbers, rounded up on the new strand:\n";
$table = array(
	0  => array( '0/0/0', '0/0/0' ),
	1  => array( '1/0/0', '0/0/1' ),
	5  => array( '3/2/0', '1/1/3' ),
	10 => array( '5/5/0', '3/2/5' ),
);
foreach ( $table as $g => $expected ) {
	ok( $expected[0] === wep( TBTS_Examples::strand_counts( $g, false ) ), "G = $g with no profile is {$expected[0]}" );
	ok( $expected[1] === wep( TBTS_Examples::strand_counts( $g, true ) ), "G = $g with a profile is {$expected[1]}" );
}

echo "Block — the groups rule and rule 8 appear only where they apply:\n";
$business = TBTS_Examples::block( 'B1', 'business', 10, false );
$general  = TBTS_Examples::block( 'B1', 'general', 10, false );
$mix      = TBTS_Examples::block( 'B1', 'mix', 10, true );
ok( false === strpos( $business, 'WORLD (exactly' ) && false === strpos( $business, 'EVERYDAY (exactly' ), 'Business has no group counts' );
ok( false !== strpos( $business, '8. Business-context sentences follow rules 1, 4, 5, 6 and 7.' ), 'and does carry rule 8' );
ok( false === strpos( $general, '8. Business-context' ), 'General carries no rule 8' );
ok( false !== strpos( $general, 'The 10 general-context sentences' ) && false !== strpos( $general, 'WORLD (exactly 5)' ) && false !== strpos( $general, 'EVERYDAY (exactly 5)' ), 'General ten splits five and five' );
ok( false !== strpos( $mix, 'The 5 general-context sentences' ) && false !== strpos( $mix, 'PROFILE (exactly 3)' ) && false !== strpos( $mix, '8. Business-context' ), 'Mix ten with a profile: five general, three of them PROFILE, plus rule 8' );
ok( 0 === strpos( $general, "What the example sentences are for — follow these rules in order:\n\n1. Meaning first." ), 'meaning first is rule 1' );

echo "Block — the A1 present-tense WORLD line:\n";
ok( false !== strpos( TBTS_Examples::block( 'A1', 'general', 6, false ), 'At this level, WORLD sentences describe places and works in the present' ), 'A1 carries it' );
ok( false === strpos( TBTS_Examples::block( 'B1', 'general', 6, false ), 'At this level, WORLD sentences' ), 'B1 does not' );

echo "Block — connectors are graded and capped:\n";
$own_list = true;
$capped   = true;
foreach ( TBTS_Levels::BANDS as $band ) {
	foreach ( TBTS_Register::TYPES as $type ) {
		$block = TBTS_Examples::block( $band, $type, 6, false );
		if ( false === strpos( $block, "these suit CEFR {$band}: " . TBTS_Examples::connectors( $band ) . '.' ) ) { $own_list = false; }
		if ( false === strpos( $block, 'more than twice' ) ) { $capped = false; }
	}
}
ok( $own_list, 'each band carries its own connector list' );
ok( $capped, 'and every block caps a connector at twice' );
ok( 'although, unless, until, as soon as, so that, however' === TBTS_Examples::connectors( 'junk' ), 'an unknown band yields the B1 connectors' );
ok( 0 === strpos( TBTS_Examples::connectors( 'C2' ), TBTS_Examples::connectors( 'C1' ) ), 'C2 extends the C1 list' );
ok( false !== strpos( TBTS_Examples::block( 'A1', 'general', 6, false ), 'and, but, or, also, too. Join words or phrases inside the one clause; no subordinate clauses. Simpler ones' ), 'A1 carries its single-clause note' );
ok( false !== strpos( TBTS_Examples::block( 'A2', 'general', 6, false ), 'before, after. Lower-band connectors also allowed. Simpler ones' ), 'A2 carries its lower-band note' );
$never_demands = true;
foreach ( TBTS_Levels::BANDS as $band ) {
	if ( false !== strpos( TBTS_Examples::block( $band, 'general', 6, false ), 'Most sentences should contain a connector' ) ) { $never_demands = false; }
}
ok( $never_demands, 'no band demands a connector in most sentences' );
ok( false !== strpos( TBTS_Examples::block( 'B1', 'general', 6, false ), '6. Keep it simple.' ), 'rule 6 asks for simple sentences' );
ok( false !== strpos( TBTS_Examples::block( 'B2', 'general', 6, false ), 'natural, logical and true' ), 'and ends with the read-back check' );

echo "Block — no profile, no PROFILE group:\n";
$never = true;
foreach ( TBTS_Levels::BANDS as $band ) {
	foreach ( TBTS_Register::TYPES as $type ) {
		foreach ( array( 1, 5, 10 ) as $n ) {
			if ( false !== strpos( TBTS_Examples::block( $band, $type, $n, false ), 'PROFILE (exactly' ) ) { $never = false; }
		}
	}
}
ok( $never, 'without a profile the block never states a PROFILE count' );
ok( false === strpos( TBTS_Levels::prompt_block( 'B1', 'business', 10, 'Likes films.' ), 'PROFILE (exactly' ), 'and a Business deck never gets one, profile or not' );

echo "Anchor guard — no real name anywhere in the prompt:\n";
$anchors = array( 'Curie', 'Chopin', 'Aurelius', 'Disraeli', 'Yellowstone', 'Kraków', 'Warsaw' );
$clean   = true;
foreach ( TBTS_Levels::BANDS as $band ) {
	foreach ( TBTS_Register::TYPES as $type ) {
		foreach ( array( '', 'An engineer who loves films and hiking.' ) as $profile ) {
			$block = TBTS_Levels::prompt_block( $band, $type, 10, $profile );
			foreach ( $anchors as $name ) {
				if ( false !== strpos( $block, $name ) ) {
					$clean = false;
					echo "    found $name in $band/$type\n";
				}
			}
		}
	}
}
ok( $clean, 'no example person or place in any band, type or profile state' );

echo "\nPassed: $pass  Failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );

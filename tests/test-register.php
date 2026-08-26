<?php
/**
 * Standalone logic tests for TBTS_Register.
 *
 * The whole class is one closed set of three values and the two ways of
 * reading a candidate against it, so what is under test is the distinction
 * those two functions exist for: normalise() can say "no value recorded" and
 * sanitize() cannot. A deck saved before the picker existed depends on the
 * difference.
 *
 * WordPress is not loaded; __() is the only thing the class reads through.
 *
 * Run: php tests/test-register.php
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

echo "Normalising — three values, and an honest empty for anything else:\n";
ok( 'general' === TBTS_Register::normalise( 'general' ), 'general survives' );
ok( 'mix' === TBTS_Register::normalise( 'mix' ), 'mix survives' );
ok( 'business' === TBTS_Register::normalise( 'business' ), 'business survives' );
ok( 'business' === TBTS_Register::normalise( 'Business' ), 'a capitalised value still resolves' );
ok( 'general' === TBTS_Register::normalise( ' general ' ), 'surrounding space is trimmed' );
ok( '' === TBTS_Register::normalise( '' ), 'no type stays no type' );
ok( '' === TBTS_Register::normalise( 'technical' ), 'a fourth type is not a type' );
ok( '' === TBTS_Register::normalise( null ), 'a non-string is not a type' );
ok( '' === TBTS_Register::normalise( array( 'mix' ) ), 'nor is an array' );

echo "Sanitising — a bad type never costs a generation:\n";
ok( 'mix' === TBTS_Register::sanitize( 'technical' ), 'nonsense falls back to Mix' );
ok( 'mix' === TBTS_Register::sanitize( '' ), 'an empty type falls back to Mix' );
ok( 'mix' === TBTS_Register::sanitize( null ), 'and so does a non-string' );
ok( 'general' === TBTS_Register::sanitize( 'general' ), 'a real type is kept' );
ok( 'business' === TBTS_Register::sanitize( 'BUSINESS' ), 'and kept whatever its case' );
ok( 'mix' === TBTS_Register::DEFAULT_TYPE, 'the default is Mix' );

echo "The two functions differ on exactly one thing:\n";
// This is the whole reason there are two. NULL on a deck row means "generated
// before the picker existed", which is not the claim "generated at Mix".
ok( '' === TBTS_Register::normalise( 'junk' ) && 'mix' === TBTS_Register::sanitize( 'junk' ), 'normalise() records nothing where sanitize() records Mix' );
foreach ( TBTS_Register::TYPES as $type ) {
	ok( TBTS_Register::normalise( $type ) === TBTS_Register::sanitize( $type ), "and they agree on $type" );
}

echo "Labels — one per type, none of them empty:\n";
$labels = TBTS_Register::labels();
ok( array_keys( $labels ) === TBTS_Register::TYPES, 'every type has a label, in picker order' );
ok( 'General English' === $labels['general'], 'general reads as General English' );
ok( 'Mix' === $labels['mix'], 'mix reads as Mix' );
ok( 'Business English' === $labels['business'], 'business reads as Business English' );

echo "\nPassed: $pass  Failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );

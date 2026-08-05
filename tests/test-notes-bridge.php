<?php
/**
 * Standalone logic tests for the TBT Notes bridge.
 *
 * These cover the rules that must not regress: a draft never reaches a student,
 * a deck is scoped by class rather than by owner (so a student sees their
 * teacher's decks), ordering is newest first, and the subtitle carries the
 * lesson only when there is one.
 *
 * WordPress is not loaded, so the collaborators are stubbed — only the bridge's
 * own shaping logic is under test. TBTS_DB::get_published_sets_for_class() is
 * stubbed to mirror what its SQL does.
 *
 * Run: php tests/test-notes-bridge.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'TBTS_URL', 'https://example.test/wp-content/plugins/tbt-swipe/' );
define( 'TBTS_DIR', dirname( __DIR__ ) . '/' );
define( 'TBTS_VERSION', '1.4.0' );

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

function __( $s, $d = '' ) { return $s; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { return true; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { return true; }
function wp_enqueue_script() {}

class TBT_Notes_REST {}

class TBTS_Classes {
	public static $available = true;
	public static $lessons   = array( 7 => 'Lekcja 3' );
	public static function available() { return self::$available; }
	public static function lesson_title( $id ) {
		return isset( self::$lessons[ (int) $id ] ) ? self::$lessons[ (int) $id ] : '';
	}
}

class TBTS_Frontend {
	public static function card_count_label( $n ) {
		return $n . ( 1 === $n ? ' card' : ' cards' );
	}
}

class TBTS_DB {
	public static $rows     = array();
	public static $deckPage = true;
	public static $queried  = array();
	public static function get_published_sets_for_class( $class_id ) {
		self::$queried[] = (int) $class_id;
		// The real method filters in SQL; mirror that here.
		$out = array();
		foreach ( self::$rows as $r ) {
			if ( (int) $r->class_id === (int) $class_id && 'published' === $r->status ) { $out[] = $r; }
		}
		usort( $out, function ( $a, $b ) { return strcmp( $b->created, $a->created ); } );
		return $out;
	}
	public static function deck_url( $set ) {
		return self::$deckPage ? 'https://thebluetree.pl/swipe/?deck=' . $set->slug : '';
	}
}

function mkset( $id, $title, $status, $class_id, $lesson_id, $cards, $created, $owner = 2 ) {
	return (object) array(
		'id' => $id, 'title' => $title, 'status' => $status, 'class_id' => $class_id,
		'lesson_id' => $lesson_id, 'card_count' => $cards, 'created' => $created,
		'slug' => 'slug' . $id, 'owner_id' => $owner,
	);
}

require_once dirname( __DIR__ ) . '/includes/class-tbts-notes-bridge.php';

TBTS_DB::$rows = array(
	mkset( 1, 'Unit 4 — collocations', 'published', 5, 7, 18, '2026-06-01 10:00:00' ),
	mkset( 2, 'Phrasal verbs',         'published', 5, null, 1, '2026-07-01 10:00:00' ),
	mkset( 3, 'Niedokończony',         'draft',     5, 7, 4,  '2026-08-01 10:00:00' ),
	mkset( 4, 'Inna klasa',            'published', 6, null, 3, '2026-06-15 10:00:00' ),
	// Owned by a different teacher, attached to the same class: a student must
	// still see it, so ownership must not enter into the query.
	mkset( 5, 'Deck od innego nauczyciela', 'published', 5, null, 9, '2026-05-01 10:00:00', 99 ),
);

$bridge = new TBTS_Notes_Bridge();

echo "Bridge — group shape and ordering:\n";
$out = $bridge->add_class_decks( array(), 5, 3 );
ok( count( $out ) === 1, 'one group is appended' );
ok( $out[0]['key'] === 'swipe_decks' && $out[0]['label'] === 'Swipe decks', 'group key and label' );
$titles = array_map( function ( $i ) { return $i['title']; }, $out[0]['items'] );
ok( $titles === array( 'Phrasal verbs', 'Unit 4 — collocations', 'Deck od innego nauczyciela' ), 'newest first: ' . implode( ' | ', $titles ) );
ok( ! in_array( 'Niedokończony', $titles, true ), 'a draft attached to the class never appears' );
ok( ! in_array( 'Inna klasa', $titles, true ), 'another class\'s deck never appears' );
ok( in_array( 'Deck od innego nauczyciela', $titles, true ), 'a deck owned by another teacher is still listed (class scoping, not owner)' );

echo "Bridge — subtitles and lesson anchoring:\n";
$byTitle = array();
foreach ( $out[0]['items'] as $i ) { $byTitle[ $i['title'] ] = $i; }
ok( $byTitle['Unit 4 — collocations']['subtitle'] === '18 cards', 'card count alone for an anchored deck — the lesson row already places it (' . $byTitle['Unit 4 — collocations']['subtitle'] . ')' );
ok( $byTitle['Phrasal verbs']['subtitle'] === '1 card', 'card count alone when lesson_id is null' );
ok( $byTitle['Unit 4 — collocations']['lesson_id'] === 7, 'an anchored deck carries its lesson_id' );
ok( $byTitle['Phrasal verbs']['lesson_id'] === 0, 'an unanchored deck reports lesson_id 0' );

echo "Bridge — group icon:\n";
ok( strpos( $out[0]['icon'], '<svg' ) === 0, 'the group carries the plugin\'s own inline SVG' );
ok( strpos( $out[0]['icon'], '<script' ) === false && strpos( $out[0]['icon'], 'onload' ) === false, 'the shipped icon carries no script or handler' );
ok( ! preg_match( '/\b(href|src|url\()/i', $out[0]['icon'] ), 'the shipped icon fetches nothing — no href/src/url()' );
ok( $byTitle['Phrasal verbs']['url'] === 'https://thebluetree.pl/swipe/?deck=slug2', 'url comes from the shared builder' );
ok( $byTitle['Phrasal verbs']['id'] === 2, 'id is the set id' );

echo "Bridge — empty and degraded cases:\n";
$prev = $bridge->add_class_decks( array( array( 'key' => 'other', 'label' => 'X', 'items' => array( 1 ) ) ), 999, 3 );
ok( count( $prev ) === 1 && $prev[0]['key'] === 'other', 'a class with no decks returns extras untouched' );

TBTS_DB::$deckPage = false;
ok( $bridge->add_class_decks( array(), 5, 3 ) === array(), 'no deck page configured → no group at all' );
TBTS_DB::$deckPage = true;

TBTS_Classes::$available = false;
ok( $bridge->add_class_decks( array(), 5, 3 ) === array(), 'Notes unavailable → nothing contributed' );
TBTS_Classes::$available = true;

ok( $bridge->add_class_decks( array(), 0, 3 ) === array(), 'a class id of 0 contributes nothing' );
ok( $bridge->add_class_decks( 'nonsense', 5, 3 ) === array(), 'a non-array extras value is replaced, not concatenated' );

echo "Bridge — scoping:\n";
ok( array_values( array_unique( TBTS_DB::$queried ) ) === array( 5, 999 ), 'only the requested class is ever queried (' . implode( ',', array_unique( TBTS_DB::$queried ) ) . ')' );

/*
 * The shipped icon has to survive the allowlist Notes filters it through
 * (TBT_Notes_REST::sanitize_extras_icon). Anything outside that list is
 * stripped, and a shape whose fill points at a stripped gradient renders
 * invisible — a blank control rather than a visible fallback. Design tools
 * export gradients, clip paths and <style> blocks routinely, so this guards the
 * artwork itself: replace deck-icon.svg with such an export and this fails
 * rather than shipping an icon nobody can see.
 */
echo "Bridge — the shipped icon fits the allowlist Notes enforces:\n";

$allowed_elements = array( 'svg', 'g', 'defs', 'title', 'path', 'circle', 'rect', 'polygon' );
$shape_attrs      = array(
	'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap',
	'stroke-linejoin', 'stroke-opacity', 'clip-rule', 'opacity', 'transform', 'class',
);
$allowed_attrs = array(
	'svg'     => array_merge( $shape_attrs, array( 'xmlns', 'viewbox', 'width', 'height', 'role', 'aria-hidden', 'focusable' ) ),
	'g'       => $shape_attrs,
	'defs'    => array(),
	'title'   => array(),
	'path'    => array_merge( $shape_attrs, array( 'd' ) ),
	'circle'  => array_merge( $shape_attrs, array( 'cx', 'cy', 'r' ) ),
	'rect'    => array_merge( $shape_attrs, array( 'x', 'y', 'width', 'height', 'rx', 'ry' ) ),
	'polygon' => array_merge( $shape_attrs, array( 'points' ) ),
);

$icon = file_get_contents( dirname( __DIR__ ) . '/assets/img/deck-icon.svg' );
ok( is_string( $icon ) && '' !== trim( $icon ), 'the icon file is present and non-empty' );

$doc = new DOMDocument();
libxml_use_internal_errors( true );
ok( $doc->loadXML( $icon ), 'the icon parses as XML' );
libxml_clear_errors();

$bad_elements = array();
$bad_attrs    = array();
foreach ( $doc->getElementsByTagName( '*' ) as $node ) {
	$tag = strtolower( $node->nodeName );
	if ( ! in_array( $tag, $allowed_elements, true ) ) {
		$bad_elements[] = $tag;
		continue;
	}
	foreach ( $node->attributes as $attr ) {
		if ( ! in_array( strtolower( $attr->nodeName ), $allowed_attrs[ $tag ], true ) ) {
			$bad_attrs[] = $tag . '/' . $attr->nodeName;
		}
	}
}

ok( empty( $bad_elements ), 'every element is on the allowlist (' . implode( ',', array_unique( $bad_elements ) ) . ')' );
ok( empty( $bad_attrs ), 'every attribute is on the allowlist (' . implode( ',', array_unique( $bad_attrs ) ) . ')' );

// A fill or stroke pointing at a definition — url(#gradient) — only works if
// that definition survives, and none of the elements that hold one do.
ok( ! preg_match( '/url\(\s*#/i', $icon ), 'no fill or stroke references a definition that the allowlist would strip' );
ok( ! preg_match( '/\son[a-z]+\s*=/i', $icon ), 'the icon carries no event handler' );

echo "\nPassed: $pass  Failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );

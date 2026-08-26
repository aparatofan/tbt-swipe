<?php
/**
 * The type of English an example sentence is written in: general, business, or
 * a stated mix of the two.
 *
 * A CEFR band says how hard a sentence is; it says nothing about the world the
 * sentence is set in. A teacher preparing vocabulary for a corporate client and
 * a teacher preparing the same words for a teenager want the same grammar and
 * different contexts, and only this value can tell them apart.
 *
 * Three values, not a free-text field: the value goes into the prompt, so the
 * set of things it can say has to be closed.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Register {

	/**
	 * The three types the picker offers, in the order it renders them:
	 * general and business at the ends, the mix of the two between them.
	 */
	const TYPES = array( 'general', 'mix', 'business' );

	/**
	 * What a generation falls back to. Mix is the only safe default: it is
	 * the one value that is never simply wrong for a deck whose teacher did
	 * not say, because it produces some of both.
	 */
	const DEFAULT_TYPE = 'mix';

	/**
	 * Type => teacher-facing label, as shown on the picker tile.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'general'  => __( 'General English', 'tbt-swipe' ),
			'mix'      => __( 'Mix', 'tbt-swipe' ),
			'business' => __( 'Business English', 'tbt-swipe' ),
		);
	}

	/**
	 * Reduce anything type-shaped to one of the three, or to ''.
	 *
	 * The empty return is the point of having two functions rather than one:
	 * a stored deck needs to be able to say "no value recorded", which is not
	 * the same claim as "recorded as Mix". Only sanitize() collapses the two.
	 *
	 * @param mixed $raw Type string from anywhere: a POST body, a stored row.
	 * @return string One of the three types, or '' when the value is not one.
	 */
	public static function normalise( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$type = strtolower( trim( $raw ) );

		return in_array( $type, self::TYPES, true ) ? $type : '';
	}

	/**
	 * A type that is always safe to generate with.
	 *
	 * A bad type is never worth losing a generation over, so anything
	 * unrecognised falls back to Mix silently rather than erroring.
	 *
	 * @param mixed $raw Candidate type.
	 * @return string One of the three types.
	 */
	public static function sanitize( $raw ) {
		$type = self::normalise( $raw );
		return '' !== $type ? $type : self::DEFAULT_TYPE;
	}
}

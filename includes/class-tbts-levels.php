<?php
/**
 * The CEFR level model: six bands, the prompt rules that make them audible in
 * the generated sentences, and the suggestion drawn from a class's students.
 *
 * Swipe has no decimals. TBT Students stores a 25-step scale (B1.3, B1.5,
 * B1.7); a teacher choosing how an example sentence should read needs six
 * choices, not twenty-five, so everything coming in from Students is reduced
 * to its base band on the way through normalise().
 *
 * TBT Students and TBT Notes are both optional. Every method here returns a
 * safe value when either is inactive: without them the picker still works, it
 * just stops pre-selecting itself.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Levels {

	/**
	 * The six bands the picker offers, weakest first. The order is the
	 * comparison order too — "lowest band" means lowest index here.
	 */
	const BANDS = array( 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' );

	/**
	 * What a generation falls back to: today's hardcoded behaviour, so a
	 * teacher who ignores the picker sees exactly what they saw before.
	 */
	const DEFAULT_BAND = 'B1';

	/** User meta holding the band this user generated with last. */
	const LAST_META = 'tbt_swipe_last_level';

	/**
	 * Band code => plain-English name, as shown under the code in the picker.
	 *
	 * The names match TBT Students' own band_names() for the six bands they
	 * share. They are duplicated rather than read across because Swipe must
	 * render the picker with Students deactivated.
	 *
	 * @return array<string,string>
	 */
	public static function band_names() {
		return array(
			'A1' => __( 'elementary', 'tbt-swipe' ),
			'A2' => __( 'pre-intermediate', 'tbt-swipe' ),
			'B1' => __( 'intermediate', 'tbt-swipe' ),
			'B2' => __( 'upper-intermediate', 'tbt-swipe' ),
			'C1' => __( 'advanced', 'tbt-swipe' ),
			'C2' => __( 'proficient', 'tbt-swipe' ),
		);
	}

	/**
	 * Reduce anything level-shaped to one of the six bands.
	 *
	 * Decimals are dropped: B1.3, B1.5 and B1.7 are all B1. A0 becomes A1 —
	 * a student below A1 cannot read an example sentence at all, so there is
	 * no seventh band to generate for.
	 *
	 * @param mixed $raw Level string from anywhere: a POST body, TBT Students,
	 *                   a stored deck row.
	 * @return string One of the six bands, or '' when the value is not a level.
	 */
	public static function normalise( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$band = strtoupper( trim( $raw ) );

		// Everything from the dot onwards is Students' business, not ours.
		$dot = strpos( $band, '.' );
		if ( false !== $dot ) {
			$band = substr( $band, 0, $dot );
		}

		if ( 'A0' === $band ) {
			return 'A1';
		}

		return in_array( $band, self::BANDS, true ) ? $band : '';
	}

	/**
	 * A band that is always safe to generate with.
	 *
	 * A bad level is never worth losing a generation over, so anything
	 * unrecognised falls back to B1 silently rather than erroring.
	 *
	 * @param mixed $raw Candidate level.
	 * @return string One of the six bands.
	 */
	public static function sanitize( $raw ) {
		$band = self::normalise( $raw );
		return '' !== $band ? $band : self::DEFAULT_BAND;
	}

	/**
	 * Compare two bands. Lower band, lower number.
	 *
	 * @param string $band Band code.
	 * @return int Index in BANDS, or PHP_INT_MAX for an unknown band so it
	 *             never wins a "lowest" comparison.
	 */
	private static function index( $band ) {
		$index = array_search( $band, self::BANDS, true );
		return false === $index ? PHP_INT_MAX : (int) $index;
	}

	/**
	 * The band this user generated with last, or '' if they never have.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function last_used( $user_id ) {
		return self::normalise( get_user_meta( (int) $user_id, self::LAST_META, true ) );
	}

	/**
	 * Remember the band a generation actually ran at.
	 *
	 * @param int    $user_id User ID.
	 * @param string $band    Already sanitised band.
	 */
	public static function remember( $user_id, $band ) {
		$band = self::normalise( $band );
		if ( '' !== $band ) {
			update_user_meta( (int) $user_id, self::LAST_META, $band );
		}
	}

	/**
	 * The band the picker opens on before any class is chosen: the last one
	 * this teacher used, or B1.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function initial_band( $user_id ) {
		$band = self::last_used( $user_id );
		return '' !== $band ? $band : self::DEFAULT_BAND;
	}

	/**
	 * The per-band sentence constraints that go into the prompt.
	 *
	 * A bare CEFR label produces inconsistent output — "B2" means whatever the
	 * model last read it to mean. Concrete ceilings, clause counts, lengths and
	 * topic ranges are what actually make two levels sound different.
	 *
	 * @param string $band CEFR band. Re-sanitised here rather than trusted:
	 *                     this text goes verbatim into the prompt, so a stray
	 *                     value must not reach the model as an instruction.
	 * @return array<string,string>
	 */
	public static function rules( $band ) {
		$band  = self::sanitize( $band );
		$rules = array(
			'A1' => array(
				'grammar' => 'present simple, present continuous, "can", "there is / there are"',
				'clauses' => 'exactly one clause',
				'length'  => '6-10 words',
				'topics'  => 'immediate, concrete, personal — home, family, food, school, the room you are in',
			),
			'A2' => array(
				'grammar' => 'past simple, "going to", comparatives',
				'clauses' => 'one or two clauses',
				'length'  => '8-13 words',
				'topics'  => 'everyday routine and the recent past — journeys, shopping, weekends, work and school days',
			),
			'B1' => array(
				'grammar' => 'present perfect, first conditional, "used to"',
				'clauses' => 'two clauses',
				'length'  => '11-16 words',
				'topics'  => 'experience, plans and opinions',
			),
			'B2' => array(
				'grammar' => 'passives, second and third conditional, relative clauses',
				'clauses' => 'two or three clauses',
				'length'  => '14-20 words',
				'topics'  => 'abstract, social and hypothetical — issues, consequences, comparisons of ideas',
			),
			'C1' => array(
				'grammar' => 'subordination, hedging, inversion, nominalisation',
				'clauses' => 'two to four clauses',
				'length'  => '16-25 words',
				'topics'  => 'specialised and evaluative — professional, academic and critical subject matter',
			),
			'C2' => array(
				'grammar' => 'the full range, including marked and literary structures',
				'clauses' => 'two to four clauses',
				'length'  => '16-28 words',
				'topics'  => 'nuanced, allusive and register-sensitive',
			),
		);

		return isset( $rules[ $band ] ) ? $rules[ $band ] : $rules[ self::DEFAULT_BAND ];
	}

	/**
	 * The level section of the prompt.
	 *
	 * Deliberately not translated: it is instruction text for the model, not
	 * something a teacher reads.
	 *
	 * @param string $band CEFR band, re-sanitised for the same reason as in
	 *                     rules(): the band code is interpolated into the text
	 *                     the model reads.
	 * @return string
	 */
	public static function prompt_block( $band ) {
		$band  = self::sanitize( $band );
		$rules = self::rules( $band );
		$names = array(
			'A1' => 'elementary',
			'A2' => 'pre-intermediate',
			'B1' => 'intermediate',
			'B2' => 'upper-intermediate',
			'C1' => 'advanced',
			'C2' => 'proficient',
		);
		$name = $names[ $band ];

		return "Level of the example sentences — CEFR {$band} ({$name}):\n"
			. "- Grammar ceiling: {$rules['grammar']}. Do not use structures above this ceiling.\n"
			. "- Sentence shape: {$rules['clauses']}.\n"
			. "- Length: {$rules['length']}. Word counts are guardrails, not targets — a natural sentence "
			. "a word or two outside the range beats a stilted one inside it.\n"
			. "- Topic range: {$rules['topics']}. The topic moves with the level as much as the grammar does: "
			. "if only the grammar changes and the topic stays generic, the levels become indistinguishable.\n"
			// Verbatim, and load-bearing: without it the model quietly swaps a
			// hard target item for an easier synonym and the card teaches
			// nothing.
			. "- The level constrains the language around the target item, never the target item itself. "
			. "If the item is above the requested level, keep it exactly as given and build a sentence at "
			. "the requested level around it. Do not substitute an easier word for the item, and do not "
			. "simplify the item.\n";
	}

	/**
	 * The band to suggest for a class, and a line saying where it came from.
	 *
	 * The lowest band present wins, never the average: a sentence pitched at
	 * the weakest student in the room is still understood by the strongest,
	 * and the reverse is not true.
	 *
	 * @param int $class_id Class ID, already checked for ownership.
	 * @return array array( 'suggested' => string|null, 'note' => string )
	 */
	public static function suggest_for_class( $class_id ) {
		$none = array(
			'suggested' => null,
			'note'      => '',
		);

		// Swipe must work normally without Students: no levels to read means
		// no suggestion, not an error.
		if ( ! class_exists( 'TBT_Students' ) || ! method_exists( 'TBT_Students', 'get_level' ) ) {
			return $none;
		}

		$student_ids = TBTS_Classes::student_ids_for_class( $class_id );
		if ( empty( $student_ids ) ) {
			return $none;
		}

		$bands = array();
		foreach ( $student_ids as $student_id ) {
			$band = self::normalise( TBT_Students::get_level( $student_id ) );
			if ( '' !== $band ) {
				$bands[ $student_id ] = $band;
			}
		}

		if ( empty( $bands ) ) {
			return $none;
		}

		$total   = count( $student_ids );
		$counted = count( $bands );

		$sorted = array_values( $bands );
		usort(
			$sorted,
			function ( $a, $b ) {
				return self::index( $a ) - self::index( $b );
			}
		);
		$lowest  = $sorted[0];
		$highest = $sorted[ count( $sorted ) - 1 ];

		return array(
			'suggested' => $lowest,
			'note'      => self::note( $bands, $total, $counted, $lowest, $highest ),
		);
	}

	/**
	 * The human-readable line under the picker.
	 *
	 * Three forms, because three different things happened: one student with a
	 * level names them, a whole class shows its spread, and a partial class
	 * says how partial it is rather than implying the number covers everyone.
	 *
	 * @param array  $bands   student_id => band, levelled students only.
	 * @param int    $total   Students in the class.
	 * @param int    $counted Students with a level.
	 * @param string $lowest  Lowest band present.
	 * @param string $highest Highest band present.
	 * @return string
	 */
	private static function note( $bands, $total, $counted, $lowest, $highest ) {
		if ( 1 === $total && 1 === $counted ) {
			$student_id = array_key_first( $bands );
			$user       = get_userdata( $student_id );
			$name       = $user ? $user->display_name : '';

			if ( '' !== $name ) {
				/* translators: 1: student name, 2: CEFR band, e.g. "Anna Baran · B1" */
				return sprintf( __( '%1$s · %2$s', 'tbt-swipe' ), $name, $lowest );
			}
			/* translators: %s: CEFR band */
			return sprintf( __( '1 student · %s', 'tbt-swipe' ), $lowest );
		}

		if ( $counted < $total ) {
			return sprintf(
				/* translators: 1: students with a level, 2: students in the class */
				_n(
					'%1$d of %2$d students has a level · set from the lowest',
					'%1$d of %2$d students have a level · set from the lowest',
					$counted,
					'tbt-swipe'
				),
				$counted,
				$total
			);
		}

		if ( $lowest === $highest ) {
			return sprintf(
				/* translators: 1: number of students, 2: CEFR band */
				__( '%1$d students, all %2$s · set from the lowest', 'tbt-swipe' ),
				$counted,
				$lowest
			);
		}

		return sprintf(
			/* translators: 1: number of students, 2: lowest CEFR band, 3: highest CEFR band */
			__( '%1$d students, %2$s–%3$s · set from the lowest', 'tbt-swipe' ),
			$counted,
			$lowest,
			$highest
		);
	}
}

<?php
/**
 * The example-sentence rules: what each sentence is for, which group it
 * belongs to, and the connectors that join its ideas.
 *
 * The level block says how a sentence may be built; this block says what it
 * has to do. It sits after the level block in the prompt and inside its
 * grammar ceiling, clause and length rules — nothing here raises them.
 *
 * Deliberately names no real person and no real place. A model shown a name
 * in its instructions uses that name on every deck, so the rules describe the
 * kinds of people and places only.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Examples {

	/**
	 * How the general-context sentences divide between the strands.
	 *
	 * Stated to the model as exact numbers, as the Mix split is: asked for
	 * "about half" it returns 8/2 as readily as 5/5. Rounding goes up on the
	 * strand that carries the new value, so a one-item General generation
	 * lands on WORLD, or on PROFILE when there is one.
	 *
	 * @param int  $general_count General-context sentences in this generation.
	 * @param bool $has_profile   Whether a one-to-one profile shapes them.
	 * @return array{world:int,everyday:int,profile:int}
	 */
	public static function strand_counts( $general_count, $has_profile ) {
		$general = max( 0, (int) $general_count );
		$profile = $has_profile ? (int) ceil( $general / 2 ) : 0;
		$rest    = $general - $profile;
		$world   = (int) ceil( $rest / 2 );

		return array(
			'world'    => $world,
			'everyday' => $rest - $world,
			'profile'  => $profile,
		);
	}

	/**
	 * The band's own connectors, as one comma-separated string.
	 *
	 * @param string $band CEFR band. Re-sanitised for the same reason as in
	 *                     TBTS_Levels::rules(): it goes into the prompt.
	 * @return string
	 */
	public static function connectors( $band ) {
		$band  = TBTS_Levels::sanitize( $band );
		$c1    = 'nevertheless, given that, provided that, hence, albeit, on the grounds that';
		$lists = array(
			'A1' => 'and, but, or, also, too',
			'A2' => 'because, so, when, then, before, after',
			'B1' => 'although, while, unless, until, as soon as, so that, however',
			'B2' => 'whereas, despite, even though, therefore, as long as, otherwise, which is why',
			'C1' => $c1,
			'C2' => $c1 . ', plus any connector a skilled writer would choose; prefer the less obvious',
		);

		return $lists[ $band ];
	}

	/**
	 * The example-rules section of the prompt.
	 *
	 * Rule numbers are fixed whether or not the groups rule is present, so
	 * rule 8's cross-references stay true for a business-only deck.
	 *
	 * @param string $band        CEFR band, re-sanitised.
	 * @param string $type        Type of English, re-sanitised.
	 * @param int    $count       Items in this generation.
	 * @param bool   $has_profile Whether a one-to-one profile shapes the
	 *                            general sentences. The caller already rules
	 *                            it out for Business.
	 * @return string
	 */
	public static function block( $band, $type, $count, $has_profile ) {
		$band  = TBTS_Levels::sanitize( $band );
		$type  = TBTS_Register::sanitize( $type );
		$count = max( 1, (int) $count );

		// The same split prompt_block() states for Mix, so the two blocks
		// never disagree about how many general sentences there are.
		if ( 'general' === $type ) {
			$general = $count;
		} elseif ( 'business' === $type ) {
			$general = 0;
		} else {
			$general = $count - intdiv( $count, 2 );
		}
		$business = $count - $general;
		$strands  = self::strand_counts( $general, $has_profile );

		$rules   = array();
		$rules[] = "1. Meaning first. Each sentence must show what the item means. A learner who does not know "
			. "the item should be able to roughly guess its meaning from the rest of the sentence. If almost "
			. "any word of the same kind could replace the item and the sentence would still make sense, the "
			. "sentence fails this rule, however correct or interesting it is. Use the item in its most natural "
			. "collocation. This rule outranks every rule below.";

		if ( $general > 0 ) {
			$groups = "2. Groups. The {$general} general-context sentences fall into these groups. Use exactly "
				. "these numbers, and choose which item goes in which group yourself, putting each item where it "
				. "can show its meaning best:\n"
				. "- WORLD (exactly {$strands['world']}): set in the real world — a real person, a real place, "
				. "or a real work (a book, a painting, a piece of music, a discovery, an invention, a building).\n"
				. "- EVERYDAY (exactly {$strands['everyday']}): an ordinary adult situation. These sentences may "
				. "use the recurring characters Alita, Pete, Sven and Kat.";
			if ( $strands['profile'] > 0 ) {
				$groups .= "\n- PROFILE (exactly {$strands['profile']}): set in the learner's world, as described "
					. "in the learner context. A PROFILE sentence may also use a real person, place or work from "
					. "that world, and that is encouraged.";
			}
			// A1 has no past simple, and a life story without it is either
			// above the ceiling or nonsense.
			if ( 'A1' === $band ) {
				$groups .= "\n- At this level, WORLD sentences describe places and works in the present "
					. "(\"this city has…\", \"people still listen to…\"), not life stories in the past.";
			}
			$rules[] = $groups;
		}

		$rules[] = "3. Real people. Prefer people who are no longer alive and whose work is admired: scientists, "
			. "inventors, explorers, writers, artists, composers, musicians, philosophers, and statesmen remembered "
			. "for constructive achievement. Use a living person only where the learner context clearly points to "
			. "one, and never a living politician. Do not use religious figures or religious topics, or anyone "
			. "known mainly for war, crime, scandal or cruelty. Each real person appears at most once in this set. "
			. "Vary the fields and the centuries across the set.";

		$rules[] = "4. Facts. Use only facts that are widely known and that you are certain are true. Never put "
			. "words in a real person's mouth: no quotations, no \"once said\", no invented thoughts or feelings. "
			. "Avoid exact dates and figures unless they are famous. If you are not certain a fact is true, write "
			. "about a place or a work instead, or put the item in the EVERYDAY group. A plain true sentence is "
			. "better than a vivid doubtful one.";

		$rules[] = "5. Real places. Name real places: Polish cities, towns, regions and landmarks often; also world "
			. "cities, well-known travel destinations and national parks. Facts about places follow rule 4.";

		$notes = array(
			'A1' => ' Join words or phrases inside the one clause; no subordinate clauses.',
			'A2' => ' Lower-band connectors also allowed.',
		);
		$connectors = "6. Connectors. Link ideas with connectors and vary them. Connectors for CEFR {$band}: "
			. self::connectors( $band ) . '.' . ( $notes[ $band ] ?? '' )
			. " At least half of the connectors you use must come from this list; the rest may come from lower "
			. "levels. No single connector may appear more than twice in this whole set — \"and\", \"but\" and "
			. "\"because\" included. A connector never takes a sentence above the grammar ceiling or outside the "
			. "clause rule.";
		if ( ! in_array( $band, array( 'A1', 'A2' ), true ) ) {
			$connectors .= ' Most sentences should contain a connector.';
		}
		$rules[] = $connectors;

		$rules[] = "7. Variety. Do not begin more than two sentences with the same word, and do not begin most "
			. "sentences with a person's name. The only invented names allowed anywhere are Alita, Pete, Sven and "
			. "Kat; never invent any other name.";

		if ( $business > 0 ) {
			$rules[] = "8. Business-context sentences follow rules 1, 4, 5, 6 and 7. They may be set in real "
				. "cities and places, but they do not need a real person.";
		}

		return "What the example sentences are for — follow these rules in order:\n\n"
			. implode( "\n\n", $rules ) . "\n";
	}
}

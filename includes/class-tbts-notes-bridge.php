<?php
/**
 * Contributes Swipe decks to the TBT Notes class panel.
 *
 * Notes exposes a generic extension point — a `tbt_notes_class_extras` filter —
 * and knows nothing about Swipe. All the coupling lives here, in one file, on
 * Swipe's side of the line: the decks a class already has become reachable from
 * the notes panel instead of the teacher pasting links by hand.
 *
 * Notes is an optional dependency. The hook is Notes' own, so with Notes
 * inactive nothing in this class ever runs and Swipe behaves exactly as before.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Notes_Bridge {

	/**
	 * Group slug in the extras contract. Stable: it is what Notes uses to
	 * remember which section a user expanded.
	 */
	const GROUP_KEY = 'swipe_decks';

	public function __construct() {
		add_filter( 'tbt_notes_class_extras', array( $this, 'add_class_decks' ), 10, 3 );
	}

	/**
	 * Is the host plugin actually there? The hooks alone are a good signal (they
	 * only fire from Notes), but a site could define them elsewhere, so the
	 * pieces this bridge reads through are checked too.
	 *
	 * @return bool
	 */
	protected function notes_available() {
		return class_exists( 'TBT_Notes_REST' ) && TBTS_Classes::available();
	}

	/**
	 * Append this class's published decks to the extras Notes will render.
	 *
	 * Scoping is by class, not by owner: the readers here are the class's
	 * students, so filtering on owner_id would hand every student an empty list
	 * and the feature would silently do nothing. That is safe without a check of
	 * our own because Notes runs this filter behind can_read_class — by the time
	 * we are called, the caller is already a proven member (or the owner) of the
	 * class. Adding a second membership rule here would only create somewhere
	 * for the two to disagree.
	 *
	 * @param array $extras   Groups collected so far.
	 * @param int   $class_id Class being viewed.
	 * @param int   $user_id  Current user (unused: access is already settled).
	 * @return array
	 */
	public function add_class_decks( $extras, $class_id, $user_id = 0 ) {
		if ( ! is_array( $extras ) || ! $this->notes_available() ) {
			return is_array( $extras ) ? $extras : array();
		}

		$class_id = (int) $class_id;
		if ( $class_id <= 0 ) {
			return $extras;
		}

		// Published only, newest first — enforced in SQL, so a draft cannot
		// reach a student even if something downstream misbehaves.
		$sets = TBTS_DB::get_published_sets_for_class( $class_id );
		if ( empty( $sets ) ) {
			return $extras;
		}

		$items = array();
		foreach ( $sets as $set ) {
			// The one existing URL builder, so a deck link is identical wherever
			// it appears and a change to the deck page reaches this panel too.
			$url = TBTS_DB::deck_url( $set );
			if ( '' === $url ) {
				// No deck page configured: there is nothing to open or scan.
				continue;
			}

			$items[] = array(
				'id'       => (int) $set->id,
				'title'    => (string) $set->title,
				'subtitle' => $this->subtitle( $set ),
				'url'      => $url,
			);
		}

		if ( empty( $items ) ) {
			return $extras;
		}

		$extras[] = array(
			'key'   => self::GROUP_KEY,
			'label' => __( 'Fiszki', 'tbt-swipe' ),
			'items' => $items,
		);

		return $extras;
	}

	/**
	 * "Lekcja 3 · 18 kart" for a deck pinned to a lesson, just the card count
	 * for one attached to the class as a whole.
	 *
	 * @param object $set Set row with card_count.
	 * @return string
	 */
	protected function subtitle( $set ) {
		$count  = TBTS_Frontend::card_count_label( (int) $set->card_count );
		$lesson = $set->lesson_id ? TBTS_Classes::lesson_title( (int) $set->lesson_id ) : '';

		return '' !== $lesson ? $lesson . ' · ' . $count : $count;
	}

}

<?php
/**
 * Contributes Swipe decks to the TBT Notes class panel.
 *
 * Notes exposes a generic extension point — a `tbt_notes_class_extras` filter
 * for the data and a `tbt_notes_frontend_assets` action for the scripts — and
 * knows nothing about Swipe. All the coupling lives here, in one file, on
 * Swipe's side of the line: the decks a class already has become reachable from
 * the notes panel instead of the teacher pasting links by hand.
 *
 * Notes is an optional dependency. Both hooks are Notes' own, so with Notes
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
		add_action( 'tbt_notes_frontend_assets', array( $this, 'enqueue_qr_adapter' ) );
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
				'id'        => (int) $set->id,
				// Which lesson the deck belongs to, so Notes can put it on that
				// lesson's row. 0 for an older deck attached to the class only.
				'lesson_id' => (int) $set->lesson_id,
				'title'     => (string) $set->title,
				'subtitle'  => $this->subtitle( $set ),
				'url'       => $url,
			);
		}

		if ( empty( $items ) ) {
			return $extras;
		}

		$extras[] = array(
			'key'   => self::GROUP_KEY,
			'label' => __( 'Swipe decks', 'tbt-swipe' ),
			'icon'  => self::icon_svg(),
			'items' => $items,
		);

		return $extras;
	}

	/**
	 * The deck's own line under its title: the card count.
	 *
	 * The lesson name used to lead this string and no longer does. An anchored
	 * deck is drawn on its lesson's row in the panel, so naming the lesson again
	 * would just repeat what the position already says. An unanchored deck —
	 * one from before lessons were required — has no lesson to name in the first
	 * place, so it gets the same count-only line.
	 *
	 * @param object $set Set row with card_count.
	 * @return string
	 */
	protected function subtitle( $set ) {
		return TBTS_Frontend::card_count_label( (int) $set->card_count );
	}

	/**
	 * The Swipe mark, inline, for the extras contract.
	 *
	 * Shipped with the plugin rather than linked from uploads: a media-library
	 * URL can be moved or cleaned up, and an uploaded file is one more request
	 * for a 24px mark. Read once per request and cached, so a panel with several
	 * groups does not re-read it.
	 *
	 * Multicolour by design — Notes is told not to recolour it — so it carries
	 * its own fills and does not rely on currentColor.
	 *
	 * @return string SVG markup, or '' if the file cannot be read (Notes then
	 *                falls back to its own glyph).
	 */
	protected static function icon_svg() {
		static $svg = null;

		if ( null === $svg ) {
			$path = TBTS_DIR . 'assets/img/deck-icon.svg';
			$raw  = is_readable( $path ) ? file_get_contents( $path ) : '';
			$svg  = is_string( $raw ) ? trim( $raw ) : '';
		}

		return $svg;
	}

	/**
	 * Give the Notes panel a QR renderer.
	 *
	 * Notes ships no QR library and must not gain one, so it calls
	 * window.TBTNotesQR.render() if something defined it and renders the link
	 * alone if nothing did. Swipe already carries the library for its own
	 * catalogue, so it supplies the adapter — and only when Notes says it has
	 * loaded, never globally. Deactivate Swipe and no QR script goes near a
	 * Notes page.
	 *
	 * @param array $context Context from Notes. Unused: the panel is identical
	 *                       for teachers and students, so the QR is too.
	 */
	public function enqueue_qr_adapter( $context = array() ) {
		if ( ! $this->notes_available() ) {
			return;
		}

		wp_enqueue_script( 'tbts-qrcode', TBTS_URL . 'assets/js/lib/qrcode.min.js', array(), TBTS_VERSION, true );
		wp_enqueue_script( 'tbts-notes-qr', TBTS_URL . 'assets/js/notes-qr.js', array( 'tbts-qrcode' ), TBTS_VERSION, true );
	}
}

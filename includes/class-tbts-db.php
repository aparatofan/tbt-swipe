<?php
/**
 * Table creation and all database access for TBT Swipe.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_DB {

	/**
	 * The two faces a deck can lead with.
	 *
	 * 'term' is the historic behaviour and the default, so a deck that says
	 * nothing about its faces reads exactly as it did before the option
	 * existed.
	 */
	const FRONT_FACES = array( 'term', 'translation' );

	/** A deck either belongs to a class or is deliberately open to anyone. */
	const DECK_TYPES = array( 'class', 'open' );

	public static function sets_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbts_sets';
	}

	public static function cards_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbts_cards';
	}

	/**
	 * Coerce a submitted front_face to one of the two allowed values.
	 *
	 * Anything unrecognised falls back to 'term' rather than failing the save:
	 * the worst outcome of a bad value is the default reading order, which is
	 * never wrong, only possibly not what was asked for.
	 *
	 * @param mixed $value Submitted value.
	 * @return string 'term' or 'translation'.
	 */
	public static function normalise_front_face( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, self::FRONT_FACES, true ) ? $value : 'term';
	}

	/**
	 * Coerce a submitted deck_type to one of the two allowed values.
	 *
	 * Falls back to 'class', the historic shape: a deck is only open when it
	 * says so, so a garbled value can never publish a class deck as open.
	 *
	 * @param mixed $value Submitted value.
	 * @return string 'class' or 'open'.
	 */
	public static function normalise_deck_type( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, self::DECK_TYPES, true ) ? $value : 'class';
	}

	/**
	 * Is this deck open to anyone with the link, rather than a class deck?
	 *
	 * Reads through the normaliser so a row written before the column existed
	 * — or by an older version — answers 'class' rather than failing.
	 *
	 * @param object $set Set row.
	 * @return bool
	 */
	public static function is_open_deck( $set ) {
		return 'open' === self::normalise_deck_type( isset( $set->deck_type ) ? $set->deck_type : '' );
	}

	public static function activate() {
		self::create_tables();
		update_option( 'tbts_db_version', TBTS_DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'tbts_db_version' ) !== TBTS_DB_VERSION ) {
			self::create_tables();
			update_option( 'tbts_db_version', TBTS_DB_VERSION );
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sets            = self::sets_table();
		$cards           = self::cards_table();

		// class_id / lesson_id are both nullable on purpose: NULL is an
		// unattached ("guest") set, which is a first-class supported state.
		// Existing rows keep NULL — there is deliberately no backfill. An
		// open deck is the same shape — NULL, never a 0 sentinel — so every
		// existing query that reads "no class" keeps working unchanged.
		//
		// deck_type and front_face are NOT NULL with a default, so dbDelta
		// gives every existing row 'class' / 'term' as it adds the columns:
		// the two values that describe how those decks already behave.
		//
		// level is nullable for the same reason and follows the same pattern:
		// NULL means "generated before the picker existed", which is not the
		// same claim as "generated at B1". Backfilling it would invent history.
		dbDelta( "CREATE TABLE {$sets} (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title     VARCHAR(190)    NOT NULL DEFAULT '',
  owner_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  slug      CHAR(12)        NOT NULL,
  status    VARCHAR(20)     NOT NULL DEFAULT 'draft',
  class_id  BIGINT UNSIGNED NULL DEFAULT NULL,
  lesson_id BIGINT UNSIGNED NULL DEFAULT NULL,
  level     VARCHAR(2)      NULL DEFAULT NULL,
  deck_type VARCHAR(20)     NOT NULL DEFAULT 'class',
  front_face VARCHAR(20)    NOT NULL DEFAULT 'term',
  created   DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY class_id (class_id)
) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$cards} (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  set_id      BIGINT UNSIGNED NOT NULL,
  term        VARCHAR(190)    NOT NULL DEFAULT '',
  ipa         VARCHAR(190)    NOT NULL DEFAULT '',
  translation VARCHAR(255)    NOT NULL DEFAULT '',
  example     TEXT            NULL,
  sort        INT             NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY set_id (set_id)
) {$charset_collate};" );
	}

	/**
	 * Unguessable 12-char slug; regenerated on collision.
	 */
	public static function generate_slug() {
		global $wpdb;
		$sets = self::sets_table();

		do {
			$slug   = wp_generate_password( 12, false, false );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sets} WHERE slug = %s", $slug ) );
		} while ( $exists );

		return $slug;
	}

	public static function get_set( $id ) {
		global $wpdb;
		$sets = self::sets_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sets} WHERE id = %d", $id ) );
	}

	/**
	 * Fetch a set for management, scoped to an owner.
	 *
	 * Every management path (edit, delete, attach) goes through this rather
	 * than get_set(), so holding TBTS_CAP is never enough to reach another
	 * user's set by guessing an ID.
	 *
	 * @param int $id       Set ID.
	 * @param int $owner_id Owner user ID.
	 * @return object|null
	 */
	public static function get_set_for_owner( $id, $owner_id ) {
		global $wpdb;
		$sets = self::sets_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$sets} WHERE id = %d AND owner_id = %d", $id, $owner_id )
		);
	}

	/**
	 * May the current user edit or delete this set?
	 *
	 * Ownership, not capability. Administrators oversee every set from
	 * wp-admin; everyone else is limited to their own.
	 *
	 * @param int $id Set ID.
	 * @return bool
	 */
	public static function user_can_edit_set( $id ) {
		if ( ! TBTS_Capabilities::user_can_manage() ) {
			return false;
		}
		if ( TBTS_Capabilities::user_can_manage_all() ) {
			return (bool) self::get_set( $id );
		}
		return (bool) self::get_set_for_owner( $id, get_current_user_id() );
	}

	/**
	 * Public deck URL for a set, or '' if no deck page is configured.
	 *
	 * @param object $set Set row.
	 * @return string
	 */
	public static function deck_url( $set ) {
		$page_id = (int) get_option( 'tbts_deck_page_id', 0 );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		return add_query_arg( 'deck', $set->slug, get_permalink( $page_id ) );
	}

	public static function get_set_by_slug( $slug ) {
		global $wpdb;
		$sets = self::sets_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sets} WHERE slug = %s", $slug ) );
	}

	/**
	 * Sets with card counts, newest first.
	 *
	 * @param int|null $owner_id Restrict to one owner, or null for every set.
	 *                           Only ever null for an administrator in wp-admin.
	 * @return object[]
	 */
	public static function get_sets( $owner_id = null ) {
		global $wpdb;
		$sets  = self::sets_table();
		$cards = self::cards_table();
		$count = "( SELECT COUNT(*) FROM {$cards} c WHERE c.set_id = s.id ) AS card_count";

		if ( null === $owner_id ) {
			return $wpdb->get_results( "SELECT s.*, {$count} FROM {$sets} s ORDER BY s.created DESC" );
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, {$count} FROM {$sets} s WHERE s.owner_id = %d ORDER BY s.created DESC",
				$owner_id
			)
		);
	}

	/**
	 * Published sets attached to a class, with card counts, newest first.
	 *
	 * Scoped by class_id alone — deliberately not by owner_id, and the opposite
	 * of the catalogue rule in get_sets(). The catalogue answers "which sets are
	 * mine?"; this answers "which sets belong to this class?", and its readers
	 * are the class's students, who own nothing. Filtering by owner here would
	 * return an empty list for every student.
	 *
	 * That is safe because the caller has already been proven a member of the
	 * class (see TBTS_Notes_Bridge): class membership is the authorization
	 * boundary here, not set ownership.
	 *
	 * Drafts are excluded in SQL, not hidden downstream, so an unfinished set
	 * cannot reach a student by way of a rendering bug.
	 *
	 * @param int $class_id Class ID.
	 * @return object[]
	 */
	public static function get_published_sets_for_class( $class_id ) {
		global $wpdb;
		$class_id = (int) $class_id;
		if ( $class_id <= 0 ) {
			return array();
		}

		$sets  = self::sets_table();
		$cards = self::cards_table();
		$count = "( SELECT COUNT(*) FROM {$cards} c WHERE c.set_id = s.id ) AS card_count";

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, {$count} FROM {$sets} s WHERE s.class_id = %d AND s.status = 'published' ORDER BY s.created DESC, s.id DESC",
				$class_id
			)
		);
	}

	public static function get_cards( $set_id ) {
		global $wpdb;
		$cards = self::cards_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$cards} WHERE set_id = %d ORDER BY sort ASC, id ASC", $set_id )
		);
	}

	/**
	 * Create or update a set and replace all of its cards.
	 *
	 * @param int    $id     0 to create.
	 * @param string $title  Already sanitised.
	 * @param string $status 'draft' or 'published'.
	 * @param array  $cards  List of arrays with term/ipa/translation/example (already sanitised).
	 * @param array  $extra  Optional 'class_id' / 'lesson_id' (int or null),
	 *                       'level' (band string or null), 'deck_type' and
	 *                       'front_face' (strings), all already validated.
	 *                       A key that is absent leaves the column untouched on
	 *                       update, so the admin editor — which knows nothing
	 *                       about classes, levels or faces — cannot silently
	 *                       detach a set, erase its level or flip its faces.
	 * @return int|WP_Error  Set ID.
	 */
	public static function save_set( $id, $title, $status, $cards, $extra = array() ) {
		global $wpdb;
		$sets_table  = self::sets_table();
		$cards_table = self::cards_table();

		$attach        = array();
		$attach_format = array();
		$optional      = array(
			'class_id'   => '%d',
			'lesson_id'  => '%d',
			'level'      => '%s',
			'deck_type'  => '%s',
			'front_face' => '%s',
		);
		foreach ( $optional as $key => $format ) {
			if ( ! array_key_exists( $key, $extra ) ) {
				continue;
			}
			if ( null === $extra[ $key ] ) {
				$attach[ $key ] = null;
			} else {
				$attach[ $key ] = '%d' === $format ? (int) $extra[ $key ] : (string) $extra[ $key ];
			}
			$attach_format[] = $format;
		}

		if ( $id ) {
			$existing = self::get_set( $id );
			if ( ! $existing ) {
				return new WP_Error( 'tbts_not_found', __( 'Deck not found.', 'tbt-swipe' ) );
			}
			$wpdb->update(
				$sets_table,
				array_merge( array( 'title' => $title, 'status' => $status ), $attach ),
				array( 'id' => $id ),
				array_merge( array( '%s', '%s' ), $attach_format ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$sets_table,
				array_merge(
					array(
						'title'    => $title,
						'owner_id' => get_current_user_id(),
						'slug'     => self::generate_slug(),
						'status'   => $status,
						'created'  => current_time( 'mysql' ),
					),
					$attach
				),
				array_merge( array( '%s', '%d', '%s', '%s', '%s' ), $attach_format )
			);
			$id = (int) $wpdb->insert_id;
		}

		// The card set is replaced wholesale rather than diffed: no table
		// anywhere references a card ID — not the Notes bridge, which carries
		// set IDs, and there is no progress or favourites store — so a card
		// row has no identity worth preserving. Wrapped in a transaction so an
		// edit that fails halfway cannot leave a deck holding half its cards.
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$cards_table} WHERE set_id = %d", $id ) );

		foreach ( array_values( $cards ) as $i => $card ) {
			$wpdb->insert(
				$cards_table,
				array(
					'set_id'      => $id,
					'term'        => $card['term'],
					'ipa'         => $card['ipa'],
					'translation' => $card['translation'],
					'example'     => $card['example'],
					'sort'        => $i,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		}
		$wpdb->query( 'COMMIT' );

		return $id;
	}

	public static function delete_set( $id ) {
		global $wpdb;
		$wpdb->delete( self::cards_table(), array( 'set_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::sets_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Copy a set and its cards. The copy is a draft with a fresh slug.
	 *
	 * @return int|WP_Error New set ID.
	 */
	public static function duplicate_set( $id ) {
		global $wpdb;
		$set = self::get_set( $id );
		if ( ! $set ) {
			return new WP_Error( 'tbts_not_found', __( 'Deck not found.', 'tbt-swipe' ) );
		}

		// The copy belongs to whoever made it, so it only keeps the class
		// attachment when that user owns the class — otherwise it becomes an
		// unattached set rather than landing in someone else's class.
		$user_id   = get_current_user_id();
		$class_id  = null;
		$lesson_id = null;
		// The level travels with the copy: it describes the cards, which are
		// copied verbatim, not the class the original was attached to.
		$level     = isset( $set->level ) && $set->level ? (string) $set->level : null;
		if ( $set->class_id && TBTS_Classes::user_owns_class( $user_id, (int) $set->class_id ) ) {
			$class_id  = (int) $set->class_id;
			$lesson_id = $set->lesson_id ? (int) $set->lesson_id : null;
		}

		$wpdb->insert(
			self::sets_table(),
			array(
				'title'      => $set->title . ' ' . __( '(copy)', 'tbt-swipe' ),
				'owner_id'   => $user_id,
				'slug'       => self::generate_slug(),
				'status'     => 'draft',
				'class_id'   => $class_id,
				'lesson_id'  => $lesson_id,
				'level'      => $level,
				// The copy is the same deck of cards, so it reads the same way
				// round. It only stays open if the original was: a class deck
				// that lost its class just above is unattached, not open — the
				// two look alike in the columns and mean different things.
				'deck_type'  => self::is_open_deck( $set ) ? 'open' : 'class',
				'front_face' => self::normalise_front_face( isset( $set->front_face ) ? $set->front_face : '' ),
				'created'    => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		$new_id = (int) $wpdb->insert_id;

		foreach ( self::get_cards( $id ) as $card ) {
			$wpdb->insert(
				self::cards_table(),
				array(
					'set_id'      => $new_id,
					'term'        => $card->term,
					'ipa'         => $card->ipa,
					'translation' => $card->translation,
					'example'     => $card->example,
					'sort'        => $card->sort,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		return $new_id;
	}
}

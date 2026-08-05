<?php
/**
 * Table creation and all database access for TBT Swipe.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_DB {

	public static function sets_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbts_sets';
	}

	public static function cards_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbts_cards';
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
		// Existing rows keep NULL — there is deliberately no backfill.
		dbDelta( "CREATE TABLE {$sets} (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title     VARCHAR(190)    NOT NULL DEFAULT '',
  owner_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  slug      CHAR(12)        NOT NULL,
  status    VARCHAR(20)     NOT NULL DEFAULT 'draft',
  class_id  BIGINT UNSIGNED NULL DEFAULT NULL,
  lesson_id BIGINT UNSIGNED NULL DEFAULT NULL,
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
	 * @param array  $extra  Optional 'class_id' / 'lesson_id' (int or null, already validated).
	 *                       A key that is absent leaves the column untouched on
	 *                       update, so the admin editor — which knows nothing
	 *                       about classes — cannot silently detach a set.
	 * @return int|WP_Error  Set ID.
	 */
	public static function save_set( $id, $title, $status, $cards, $extra = array() ) {
		global $wpdb;
		$sets_table  = self::sets_table();
		$cards_table = self::cards_table();

		$attach        = array();
		$attach_format = array();
		foreach ( array( 'class_id', 'lesson_id' ) as $key ) {
			if ( array_key_exists( $key, $extra ) ) {
				$attach[ $key ]  = null === $extra[ $key ] ? null : (int) $extra[ $key ];
				$attach_format[] = '%d';
			}
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
		if ( $set->class_id && TBTS_Classes::user_owns_class( $user_id, (int) $set->class_id ) ) {
			$class_id  = (int) $set->class_id;
			$lesson_id = $set->lesson_id ? (int) $set->lesson_id : null;
		}

		$wpdb->insert(
			self::sets_table(),
			array(
				'title'     => $set->title . ' ' . __( '(copy)', 'tbt-swipe' ),
				'owner_id'  => $user_id,
				'slug'      => self::generate_slug(),
				'status'    => 'draft',
				'class_id'  => $class_id,
				'lesson_id' => $lesson_id,
				'created'   => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
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

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

		dbDelta( "CREATE TABLE {$sets} (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title     VARCHAR(190)    NOT NULL DEFAULT '',
  owner_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  slug      CHAR(12)        NOT NULL,
  status    VARCHAR(20)     NOT NULL DEFAULT 'draft',
  created   DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
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

	public static function get_set_by_slug( $slug ) {
		global $wpdb;
		$sets = self::sets_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sets} WHERE slug = %s", $slug ) );
	}

	/**
	 * All sets with card counts, newest first.
	 */
	public static function get_sets() {
		global $wpdb;
		$sets  = self::sets_table();
		$cards = self::cards_table();
		return $wpdb->get_results(
			"SELECT s.*, ( SELECT COUNT(*) FROM {$cards} c WHERE c.set_id = s.id ) AS card_count
			 FROM {$sets} s ORDER BY s.created DESC"
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
	 * @return int|WP_Error  Set ID.
	 */
	public static function save_set( $id, $title, $status, $cards ) {
		global $wpdb;
		$sets_table  = self::sets_table();
		$cards_table = self::cards_table();

		if ( $id ) {
			$existing = self::get_set( $id );
			if ( ! $existing ) {
				return new WP_Error( 'tbts_not_found', __( 'Set not found.', 'tbt-swipe' ) );
			}
			$wpdb->update(
				$sets_table,
				array( 'title' => $title, 'status' => $status ),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$sets_table,
				array(
					'title'    => $title,
					'owner_id' => get_current_user_id(),
					'slug'     => self::generate_slug(),
					'status'   => $status,
					'created'  => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%s', '%s', '%s' )
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
			return new WP_Error( 'tbts_not_found', __( 'Set not found.', 'tbt-swipe' ) );
		}

		$wpdb->insert(
			self::sets_table(),
			array(
				'title'    => $set->title . ' ' . __( '(copy)', 'tbt-swipe' ),
				'owner_id' => get_current_user_id(),
				'slug'     => self::generate_slug(),
				'status'   => 'draft',
				'created'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
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

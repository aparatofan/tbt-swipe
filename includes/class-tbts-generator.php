<?php
/**
 * The one generation path. Both the wp-admin editor and the frontend page call
 * generate() — nothing calls TBTS_API::generate() directly. If the two surfaces
 * ever diverged, a prompt or quota fix would land in only one of them.
 *
 * All limits are enforced here, server side, before the API call. The clients
 * pre-check the line count for UX only and are never the gate.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Generator {

	/** Terms allowed in one generation, out of the box. */
	const DEFAULT_MAX_CARDS = 20;

	/** Ceiling on the configurable per-generation cap, to protect the token budget. */
	const HARD_MAX_CARDS = 100;

	/** Generations per user per day, out of the box. 0 means unlimited. */
	const DEFAULT_MAX_GENERATIONS = 20;

	/** Prefix for the per-day counter user meta key. */
	const COUNT_META_PREFIX = 'tbt_swipe_gen_count_';

	/** User meta that overrides the global daily limit for one user. */
	const LIMIT_META = 'tbt_swipe_max_generations_per_day';

	/**
	 * Configured maximum terms per generation.
	 *
	 * @return int
	 */
	public static function max_cards_per_generation() {
		$max = (int) get_option( 'tbt_swipe_max_cards_per_generation', self::DEFAULT_MAX_CARDS );
		if ( $max < 1 ) {
			$max = self::DEFAULT_MAX_CARDS;
		}
		return min( $max, self::HARD_MAX_CARDS );
	}

	/**
	 * The site-wide daily generation limit. 0 means unlimited.
	 *
	 * @return int
	 */
	public static function default_max_generations_per_day() {
		return max( 0, (int) get_option( 'tbt_swipe_max_generations_per_day', self::DEFAULT_MAX_GENERATIONS ) );
	}

	/**
	 * The daily generation limit for one user. A numeric per-user meta value
	 * wins over the global option; anything else falls back to the global.
	 *
	 * Nothing writes this meta yet. It exists now because retrofitting per-user
	 * limits once there are paying subscribers means rewriting the settings.
	 *
	 * @param int $user_id User ID.
	 * @return int 0 means unlimited.
	 */
	public static function max_generations_per_day( $user_id ) {
		$override = get_user_meta( (int) $user_id, self::LIMIT_META, true );
		if ( '' !== $override && is_numeric( $override ) ) {
			return max( 0, (int) $override );
		}
		return self::default_max_generations_per_day();
	}

	/**
	 * Meta key holding today's counter, in site time so a teacher's "day"
	 * matches their own timezone rather than UTC.
	 *
	 * @return string
	 */
	public static function count_meta_key() {
		return self::COUNT_META_PREFIX . current_time( 'Y-m-d' );
	}

	/**
	 * How many successful generations this user has made today.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function generations_today( $user_id ) {
		return (int) get_user_meta( (int) $user_id, self::count_meta_key(), true );
	}

	/**
	 * How many generations this user has left today. Null means unlimited.
	 *
	 * @param int $user_id User ID.
	 * @return int|null
	 */
	public static function generations_remaining( $user_id ) {
		$limit = self::max_generations_per_day( $user_id );
		if ( 0 === $limit ) {
			return null;
		}
		return max( 0, $limit - self::generations_today( $user_id ) );
	}

	/**
	 * Record one successful generation.
	 *
	 * @param int $user_id User ID.
	 */
	private static function increment_count( $user_id ) {
		$key = self::count_meta_key();
		update_user_meta( (int) $user_id, $key, self::generations_today( $user_id ) + 1 );
	}

	/**
	 * Split a pasted block into terms: one per line, trimmed, blanks dropped.
	 *
	 * @param string $raw Raw textarea content.
	 * @return string[]
	 */
	public static function parse_terms( $raw ) {
		$raw   = sanitize_textarea_field( (string) $raw );
		$lines = preg_split( '/\R/', $raw );
		return array_values( array_filter( array_map( 'trim', (array) $lines ), 'strlen' ) );
	}

	/**
	 * Generate cards for a block of pasted terms.
	 *
	 * Order matters: capability, then term count, then quota, and only then the
	 * API call. The counter moves after a successful response, so a failed call
	 * never consumes quota.
	 *
	 * @param string $raw_terms Raw textarea content.
	 * @param int    $user_id   User the generation is billed to.
	 * @param string $level     CEFR band for the example sentences. Anything
	 *                          outside the six bands falls back to B1 silently:
	 *                          a bad level is never worth losing a generation
	 *                          over, and B1 is what every deck was generated at
	 *                          before the picker existed.
	 * @return array|WP_Error List of ['term','ipa','translation','example'].
	 */
	public static function generate( $raw_terms, $user_id, $level = TBTS_Levels::DEFAULT_BAND ) {
		$user_id = (int) $user_id;
		$level   = TBTS_Levels::sanitize( $level );

		if ( ! TBTS_Capabilities::user_can_manage( $user_id ) ) {
			return new WP_Error(
				'tbts_forbidden',
				__( 'You don\'t have permission to create decks.', 'tbt-swipe' ),
				array( 'status' => 403 )
			);
		}

		$terms = self::parse_terms( $raw_terms );

		if ( empty( $terms ) ) {
			return new WP_Error(
				'tbts_no_terms',
				__( 'Add at least one word.', 'tbt-swipe' ),
				array( 'status' => 400 )
			);
		}

		$max = self::max_cards_per_generation();
		if ( count( $terms ) > $max ) {
			return new WP_Error(
				'tbts_too_many_cards',
				sprintf(
					/* translators: %d: maximum number of terms per generation */
					__( 'Too many words — the maximum is %d.', 'tbt-swipe' ),
					$max
				),
				array(
					'status' => 400,
					'max'    => $max,
				)
			);
		}

		$remaining = self::generations_remaining( $user_id );
		if ( null !== $remaining && $remaining < 1 ) {
			return new WP_Error(
				'tbts_quota_exceeded',
				__( 'You\'ve used today\'s generation limit.', 'tbt-swipe' ),
				array( 'status' => 429 )
			);
		}

		$cards = TBTS_API::generate( $terms, $level );

		if ( is_wp_error( $cards ) ) {
			// Quota is untouched: nothing usable came back. The many ways the
			// API can fail collapse to one code for the client, with the
			// original code and message kept for the admin screen and logs.
			return new WP_Error(
				'tbts_api_error',
				$cards->get_error_message(),
				array(
					'status' => 502,
					'source' => $cards->get_error_code(),
				)
			);
		}

		self::increment_count( $user_id );

		// The picker opens on this next time, when no class suggests one.
		TBTS_Levels::remember( $user_id, $level );

		return $cards;
	}
}

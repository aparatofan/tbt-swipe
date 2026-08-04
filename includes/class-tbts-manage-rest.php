<?php
/**
 * Authenticated REST routes behind the frontend management page.
 *
 * Separate from TBTS_Rest, which serves students the public deck by slug and
 * must stay unauthenticated. Everything here requires TBTS_CAP, a valid
 * wp_rest nonce (WordPress checks the X-WP-Nonce header itself for cookie
 * authentication) and, for anything touching an existing set, ownership.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Manage_Rest {

	const NS = 'tbt-swipe/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		// The class ID is a path segment rather than a query parameter: with
		// plain permalinks rest_url() returns ?rest_route=..., and appending a
		// second query string to that would not survive.
		register_rest_route(
			self::NS,
			'/manage/classes/(?P<class_id>\d+)/lessons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'get_lessons' ),
				'args'                => array(
					'class_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'generate' ),
				'args'                => array(
					'terms' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/sets',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'create_set' ),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/sets/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'delete_set' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Capability gate. The nonce is checked by WordPress itself before this
	 * runs: cookie-authenticated REST requests without a valid X-WP-Nonce are
	 * rejected with rest_cookie_invalid_nonce, which the client shows as an
	 * expired session.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage() {
		if ( ! TBTS_Capabilities::user_can_manage() ) {
			return new WP_Error(
				'tbts_forbidden',
				__( 'Nie masz uprawnień do zarządzania zestawami.', 'tbt-swipe' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Lessons in one of the current user's own classes.
	 */
	public function get_lessons( WP_REST_Request $request ) {
		$class_id = absint( $request->get_param( 'class_id' ) );
		$user_id  = get_current_user_id();

		// Ownership, not just existence: the lesson list of someone else's
		// class is not this user's to read.
		if ( ! TBTS_Classes::user_owns_class( $user_id, $class_id ) ) {
			return new WP_Error(
				'tbts_invalid_class',
				__( 'Nie masz dostępu do tej klasy.', 'tbt-swipe' ),
				array( 'status' => 403 )
			);
		}

		$lessons = array();
		foreach ( TBTS_Classes::lessons_for_class( $class_id ) as $lesson ) {
			$lessons[] = array(
				'id'    => (int) $lesson['id'],
				'title' => (string) $lesson['header'],
			);
		}

		return rest_ensure_response( array( 'lessons' => $lessons ) );
	}

	/**
	 * Generate cards. Every limit is enforced inside TBTS_Generator, before
	 * the API call.
	 */
	public function generate( WP_REST_Request $request ) {
		$cards = TBTS_Generator::generate( (string) $request->get_param( 'terms' ), get_current_user_id() );

		if ( is_wp_error( $cards ) ) {
			return $cards;
		}

		return rest_ensure_response(
			array(
				'cards'     => $cards,
				'remaining' => TBTS_Generator::generations_remaining( get_current_user_id() ),
			)
		);
	}

	/**
	 * Save a reviewed set and its cards. Frontend sets are published straight
	 * away: the slug URL and QR code shown on save have to work immediately.
	 */
	public function create_set( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$title   = sanitize_text_field( (string) $request->get_param( 'title' ) );

		if ( '' === $title ) {
			return new WP_Error(
				'tbts_no_title',
				__( 'Podaj tytuł zestawu.', 'tbt-swipe' ),
				array( 'status' => 400 )
			);
		}

		$attachment = TBTS_Classes::validate_attachment(
			$user_id,
			absint( $request->get_param( 'class_id' ) ),
			absint( $request->get_param( 'lesson_id' ) )
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$cards = $this->sanitize_cards( $request->get_param( 'cards' ) );
		if ( is_wp_error( $cards ) ) {
			return $cards;
		}

		$set_id = TBTS_DB::save_set( 0, $title, 'published', $cards, $attachment );
		if ( is_wp_error( $set_id ) ) {
			return $set_id;
		}

		$set = TBTS_DB::get_set_for_owner( $set_id, $user_id );
		if ( ! $set ) {
			return new WP_Error(
				'tbts_save_failed',
				__( 'Nie udało się zapisać zestawu. Spróbuj ponownie.', 'tbt-swipe' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'id'      => (int) $set->id,
				'title'   => $set->title,
				'deckUrl' => TBTS_DB::deck_url( $set ),
				'cards'   => count( $cards ),
			)
		);
	}

	/**
	 * Delete one of the current user's own sets.
	 */
	public function delete_set( WP_REST_Request $request ) {
		$set_id = absint( $request->get_param( 'id' ) );

		// Owner-scoped without exception, including for administrators: this
		// is the frontend surface, where everyone sees only their own sets.
		if ( ! TBTS_DB::get_set_for_owner( $set_id, get_current_user_id() ) ) {
			return new WP_Error(
				'tbts_not_found',
				__( 'Nie znaleziono zestawu.', 'tbt-swipe' ),
				array( 'status' => 404 )
			);
		}

		TBTS_DB::delete_set( $set_id );

		return rest_ensure_response( array( 'deleted' => true, 'id' => $set_id ) );
	}

	/**
	 * Validate the reviewed card rows.
	 *
	 * @param mixed $raw Card list from the request.
	 * @return array|WP_Error
	 */
	private function sanitize_cards( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return new WP_Error(
				'tbts_no_cards',
				__( 'Nie ma czego zapisać — najpierw wygeneruj karty.', 'tbt-swipe' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $raw ) > TBTS_Generator::HARD_MAX_CARDS ) {
			return new WP_Error(
				'tbts_too_many_cards',
				sprintf(
					/* translators: %d: maximum number of cards in one set */
					__( 'Za dużo słów w jednym zestawie (maks. %d).', 'tbt-swipe' ),
					TBTS_Generator::HARD_MAX_CARDS
				),
				array( 'status' => 400 )
			);
		}

		$cards = array();
		foreach ( $raw as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$term = sanitize_text_field( $card['term'] ?? '' );
			if ( '' === $term ) {
				continue;
			}
			$cards[] = array(
				'term'        => $term,
				'ipa'         => sanitize_text_field( $card['ipa'] ?? '' ),
				'translation' => sanitize_text_field( $card['translation'] ?? '' ),
				'example'     => sanitize_textarea_field( $card['example'] ?? '' ),
			);
		}

		if ( empty( $cards ) ) {
			return new WP_Error(
				'tbts_no_cards',
				__( 'Nie ma czego zapisać — najpierw wygeneruj karty.', 'tbt-swipe' ),
				array( 'status' => 400 )
			);
		}

		return $cards;
	}
}

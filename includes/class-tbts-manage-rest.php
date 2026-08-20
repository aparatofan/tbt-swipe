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

		// The band to pre-select for a class, from the levels its students
		// carry in TBT Students. A path segment for the same reason as above.
		register_rest_route(
			self::NS,
			'/manage/classes/(?P<class_id>\d+)/level',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'get_class_level' ),
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
					'level' => array(
						'required' => false,
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

		// Read, update and delete share one route. The id argument is repeated
		// per endpoint rather than hoisted: register_rest_route treats a
		// non-numeric top-level key as a route option, so a shared 'args'
		// would never reach the handlers.
		$id_arg = array(
			'id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/manage/sets/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'get_set' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'update_set' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'delete_set' ),
					'args'                => $id_arg,
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
				__( 'You don\'t have permission to manage decks.', 'tbt-swipe' ),
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
				__( 'You don\'t have access to that class.', 'tbt-swipe' ),
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
	 * The level to suggest for one of the current user's own classes.
	 *
	 * Never an error when the answer is "no idea": TBT Students absent, Notes
	 * absent, an empty class or a class whose students have no levels all come
	 * back as suggested: null, and the picker keeps whatever it is showing.
	 */
	public function get_class_level( WP_REST_Request $request ) {
		$class_id = absint( $request->get_param( 'class_id' ) );
		$user_id  = get_current_user_id();

		$none = array(
			'suggested' => null,
			'note'      => '',
		);

		// Without Notes there are no classes to own, so there is nothing to
		// refuse — and nothing to suggest either.
		if ( ! TBTS_Classes::available() ) {
			return rest_ensure_response( $none );
		}

		// Ownership, not just existence: which students are in someone else's
		// class, and how good they are, is not this user's to read.
		if ( ! TBTS_Classes::user_owns_class( $user_id, $class_id ) ) {
			return new WP_Error(
				'tbts_invalid_class',
				__( 'You don\'t have access to that class.', 'tbt-swipe' ),
				array( 'status' => 403 )
			);
		}

		return rest_ensure_response( TBTS_Levels::suggest_for_class( $class_id ) );
	}

	/**
	 * Generate cards. Every limit is enforced inside TBTS_Generator, before
	 * the API call — the level included: an unrecognised band falls back to
	 * B1 there rather than failing the request.
	 */
	public function generate( WP_REST_Request $request ) {
		$cards = TBTS_Generator::generate(
			(string) $request->get_param( 'terms' ),
			get_current_user_id(),
			(string) $request->get_param( 'level' )
		);

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
	 * One of the current user's own sets, in the shape the builder loads it
	 * back in: stage 1 admin fields, the deck options and every card in its
	 * saved order.
	 */
	public function get_set( WP_REST_Request $request ) {
		$set_id = absint( $request->get_param( 'id' ) );

		// Owner-scoped without exception, as everywhere on this surface: Edit
		// must not reach a deck the list would never have shown.
		$set = TBTS_DB::get_set_for_owner( $set_id, get_current_user_id() );
		if ( ! $set ) {
			return new WP_Error(
				'tbts_not_found',
				__( 'Deck not found.', 'tbt-swipe' ),
				array( 'status' => 404 )
			);
		}

		$cards = array();
		foreach ( TBTS_DB::get_cards( $set->id ) as $card ) {
			$cards[] = array(
				'term'        => $card->term,
				'ipa'         => $card->ipa,
				'translation' => $card->translation,
				'example'     => (string) $card->example,
			);
		}

		return rest_ensure_response(
			array(
				'id'        => (int) $set->id,
				'title'     => $set->title,
				'deckType'  => TBTS_DB::normalise_deck_type( $set->deck_type ),
				'frontFace' => TBTS_DB::normalise_front_face( $set->front_face ),
				'classId'   => $set->class_id ? (int) $set->class_id : 0,
				'lessonId'  => $set->lesson_id ? (int) $set->lesson_id : 0,
				'level'     => (string) $set->level,
				'deckUrl'   => TBTS_DB::deck_url( $set ),
				'cards'     => $cards,
			)
		);
	}

	/**
	 * Save a reviewed set and its cards. Frontend sets are published straight
	 * away: the slug URL and QR code shown on save have to work immediately.
	 */
	public function create_set( WP_REST_Request $request ) {
		return $this->save_set( $request, 0 );
	}

	/**
	 * Save an edited deck over itself.
	 *
	 * The set keeps its id, slug, share link and QR code: a link a student
	 * saved last week must still open the deck their teacher edited today, so
	 * this updates the row rather than creating a replacement.
	 */
	public function update_set( WP_REST_Request $request ) {
		$set_id = absint( $request->get_param( 'id' ) );

		if ( ! TBTS_DB::get_set_for_owner( $set_id, get_current_user_id() ) ) {
			return new WP_Error(
				'tbts_not_found',
				__( 'Deck not found.', 'tbt-swipe' ),
				array( 'status' => 404 )
			);
		}

		return $this->save_set( $request, $set_id );
	}

	/**
	 * The shared create/update path.
	 *
	 * @param WP_REST_Request $request Save payload.
	 * @param int             $set_id  0 to create, or a set the caller has
	 *                                 already been proven to own.
	 * @return WP_REST_Response|WP_Error
	 */
	private function save_set( WP_REST_Request $request, $set_id ) {
		$user_id = get_current_user_id();
		$title   = sanitize_text_field( (string) $request->get_param( 'title' ) );

		if ( '' === $title ) {
			return new WP_Error(
				'tbts_no_title',
				__( 'Give the deck a title.', 'tbt-swipe' ),
				array( 'status' => 400 )
			);
		}

		$deck_type = TBTS_DB::normalise_deck_type( $request->get_param( 'deck_type' ) );

		/*
		 * A class deck that says nothing about its class keeps the one it has.
		 * The builder omits the pair when it could not show the teacher which
		 * class the deck is on — TBT Notes inactive, say — and a save that read
		 * that silence as "no class" would detach the deck behind their back.
		 * An open deck is never in doubt: it has no class, whatever it sends.
		 */
		$attachment = array();
		if ( 'open' === $deck_type || null !== $request->get_param( 'class_id' ) ) {
			$attachment = TBTS_Classes::validate_attachment_for_type(
				$deck_type,
				$user_id,
				absint( $request->get_param( 'class_id' ) ),
				absint( $request->get_param( 'lesson_id' ) )
			);
			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}
		}

		$cards = $this->sanitize_cards( $request->get_param( 'cards' ) );
		if ( is_wp_error( $cards ) ) {
			return $cards;
		}

		// The level the cards were generated at, stored on the deck. Nothing
		// reads it back yet; it is here so a later regenerate or deck listing
		// does not have to guess.
		$extra          = $attachment;
		$extra['level'] = TBTS_Levels::normalise( $request->get_param( 'level' ) );
		if ( '' === $extra['level'] ) {
			$extra['level'] = null;
		}
		$extra['deck_type']  = $deck_type;
		$extra['front_face'] = TBTS_DB::normalise_front_face( $request->get_param( 'front_face' ) );

		$saved_id = TBTS_DB::save_set( $set_id, $title, 'published', $cards, $extra );
		if ( is_wp_error( $saved_id ) ) {
			return $saved_id;
		}

		$set = TBTS_DB::get_set_for_owner( $saved_id, $user_id );
		if ( ! $set ) {
			return new WP_Error(
				'tbts_save_failed',
				__( 'Couldn\'t save the deck. Try again.', 'tbt-swipe' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'id'      => (int) $set->id,
				'title'   => $set->title,
				'deckUrl' => TBTS_DB::deck_url( $set ),
				'cards'   => count( $cards ),
				'updated' => (bool) $set_id,
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
				__( 'Deck not found.', 'tbt-swipe' ),
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
				__( 'Nothing to save yet — generate cards first.', 'tbt-swipe' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $raw ) > TBTS_Generator::HARD_MAX_CARDS ) {
			return new WP_Error(
				'tbts_too_many_cards',
				sprintf(
					/* translators: %d: maximum number of cards in one set */
					__( 'Too many words — the maximum is %d.', 'tbt-swipe' ),
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
				__( 'Nothing to save yet — generate cards first.', 'tbt-swipe' ),
				array( 'status' => 400 )
			);
		}

		return $cards;
	}
}

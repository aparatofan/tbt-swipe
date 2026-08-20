<?php
/**
 * [tbt_swipe] shortcode. Assets enqueue only when the shortcode is present.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Shortcode {

	private static $deck_done     = false;
	private static $frontend_done = false;

	public function __construct() {
		add_shortcode( 'tbt_swipe', array( $this, 'render' ) );
		// Detect the shortcode early so we can enqueue only when needed.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		// Late enough to be on a real page load, early enough to send headers.
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
	}

	/**
	 * Is this request the deck player page?
	 *
	 * One definition, used by both the enqueue and the no-cache pass: a
	 * second, differently worded detection is how the two drift apart.
	 *
	 * @return bool
	 */
	private function is_deck_page() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		return has_shortcode( $post->post_content, 'tbt_swipe' );
	}

	/**
	 * Keep the deck page out of every cache between the server and the phone.
	 *
	 * The assets are versioned, so a stale browser cannot serve stale JS — but
	 * it can serve a stale *page*, whose HTML still points at the old ?ver=,
	 * and a student has no way to know or to fix that. "Clear your cache" is
	 * not an instruction a fourteen-year-old can act on mid-lesson.
	 *
	 * Only pages carrying the shortcode are affected; the rest of the site
	 * keeps whatever caching it has.
	 */
	public function maybe_nocache() {
		if ( headers_sent() || ! $this->is_deck_page() ) {
			return;
		}
		nocache_headers();
		// LiteSpeed's page cache decides for itself and ignores Cache-Control
		// on a page it has already claimed; this is its documented opt-out,
		// and the same one TBTS_Rest uses on the deck endpoint.
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/**
	 * The player and the management UI are separate surfaces: deck.css and
	 * deck.js load only where [tbt_swipe] itself is, never on a page that
	 * merely holds the two management shortcodes.
	 */
	public function maybe_enqueue() {
		if ( $this->is_deck_page() ) {
			$this->enqueue();
		}

		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$has_generator = has_shortcode( $post->post_content, TBTS_Frontend::GENERATOR_SHORTCODE );
		$has_sets      = has_shortcode( $post->post_content, TBTS_Frontend::SETS_SHORTCODE );

		// Nothing to enqueue for a visitor who cannot see either shortcode.
		if ( ( $has_generator || $has_sets ) && TBTS_Capabilities::user_can_manage() ) {
			self::enqueue_frontend( TBTS_Generator::max_cards_per_generation() );
		}
	}

	/**
	 * Assets for the frontend management page.
	 *
	 * Public and idempotent because the shortcodes also call it: a cached page
	 * can render them after wp_enqueue_scripts has already run.
	 *
	 * @param int $max_terms Configured per-generation cap, for the client-side
	 *                       pre-check (UX only — the server re-checks).
	 */
	public static function enqueue_frontend( $max_terms ) {
		if ( self::$frontend_done ) {
			return;
		}
		self::$frontend_done = true;

		// Tokens first, and declared as a dependency rather than merely queued
		// earlier, so the variables exist whatever order WordPress prints in.
		// Swipe's token file is not interchangeable with the Hub's. Besides a
		// spacing/radius/font vocabulary the Hub file does not define, it
		// carries the self-hosted @font-face rules, the .tbt reset and the
		// [hidden] guard that keeps closed panels closed under Divi. Own the
		// handle outright so no other plugin can substitute a file that
		// frontend.css was never written against.
		wp_enqueue_style( 'tbts-tokens', TBTS_URL . 'assets/css/tbt-tokens.css', array(), TBTS_VERSION );
		wp_enqueue_style( 'tbts-frontend', TBTS_URL . 'assets/css/frontend.css', array( 'tbts-tokens' ), TBTS_VERSION );
		wp_enqueue_script( 'tbts-qrcode', TBTS_URL . 'assets/js/lib/qrcode.min.js', array(), TBTS_VERSION, true );
		wp_enqueue_script( 'tbts-frontend', TBTS_URL . 'assets/js/frontend.js', array( 'tbts-qrcode' ), TBTS_VERSION, true );

		wp_localize_script(
			'tbts-frontend',
			'tbtsFe',
			array(
				'restBase' => esc_url_raw( rest_url( TBTS_Manage_Rest::NS . '/manage/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'maxTerms' => (int) $max_terms,
				'i18n'     => array(
					'cardOne'       => __( 'card', 'tbt-swipe' ),
					'cardMany'      => __( 'cards', 'tbt-swipe' ),
					'tooMany'       => sprintf(
						/* translators: %d: maximum number of terms per generation */
						__( 'Too many words — the maximum is %d.', 'tbt-swipe' ),
						(int) $max_terms
					),
					'noTerms'       => __( 'Add at least one word.', 'tbt-swipe' ),
					'needTitle'     => __( 'Give the deck a title.', 'tbt-swipe' ),
					'generating'    => __( 'Generating cards — this can take up to 30 seconds.', 'tbt-swipe' ),
					'saving'        => __( 'Saving…', 'tbt-swipe' ),
					'saveChanges'   => __( 'Save changes', 'tbt-swipe' ),
					'noCards'       => __( 'Nothing to save yet — generate cards first.', 'tbt-swipe' ),
					'copied'        => __( 'Copied!', 'tbt-swipe' ),
					'confirmDelete' => __( 'Delete this deck and all of its cards? This cannot be undone.', 'tbt-swipe' ),
					'quota'         => __( 'You\'ve used today\'s generation limit.', 'tbt-swipe' ),
					'apiError'      => __( 'Couldn\'t generate the cards. Try again.', 'tbt-swipe' ),
					'staleNonce'    => __( 'Your session has expired. Refresh the page.', 'tbt-swipe' ),
					'networkError'  => __( 'Something went wrong. Check your connection and try again.', 'tbt-swipe' ),
					'noDeckPage'    => __( 'Deck saved, but an administrator has not chosen the deck page yet, so the link is not ready.', 'tbt-swipe' ),
					'classNoLessons' => __( 'That class has no lessons yet. Add one in Notes, or save the deck with no class.', 'tbt-swipe' ),
					'noLessonsSuffix' => __( '(no lessons)', 'tbt-swipe' ),
					// The two chips the deck list can gain when a deck is
					// edited. Same wording as TBTS_Frontend renders them with.
					'openDeck'      => __( 'OPEN DECK', 'tbt-swipe' ),
					'unattached'    => __( 'Unattached', 'tbt-swipe' ),
					'removeRow'     => __( 'Remove row', 'tbt-swipe' ),
					'stackEmpty'    => __( 'Your first card lands here', 'tbt-swipe' ),
				),
			)
		);
	}

	private function enqueue() {
		if ( self::$deck_done ) {
			return;
		}
		self::$deck_done = true;

		wp_enqueue_style( 'tbts-deck', TBTS_URL . 'assets/css/deck.css', array(), TBTS_VERSION );
		wp_enqueue_script( 'tbts-deck', TBTS_URL . 'assets/js/deck.js', array(), TBTS_VERSION, true );

		/**
		 * Filter the strings the deck player renders.
		 *
		 * The player is a closed surface — a student never sees wp-admin — so
		 * every word it shows is exposed here for a site to reword.
		 *
		 * The placeholders in 'knewLine', 'allLine' and 'toWorkOn' are
		 * positional so a translation can reorder them: %1$d / %2$d are
		 * counts, %s is the already-inflected 'wordOne' or 'wordMany'.
		 * 'allTitleName' takes one %s, the learner's first name.
		 *
		 * @param array $strings Key => string, as passed to the player.
		 */
		$i18n = apply_filters(
			'tbt_swipe_deck_i18n',
			array(
				'noSet'      => __( 'No deck selected. Please scan the QR code from your teacher.', 'tbt-swipe' ),
				'notFound'   => __( 'This deck is not available. Please check with your teacher.', 'tbt-swipe' ),
				'loadError'  => __( 'Could not load the deck. Please try again.', 'tbt-swipe' ),
				'empty'      => __( 'This deck has no cards yet.', 'tbt-swipe' ),
				'loading'    => __( 'Loading…', 'tbt-swipe' ),
				'knowIt'     => __( 'Know it', 'tbt-swipe' ),
				'notYet'     => __( 'Not yet', 'tbt-swipe' ),
				'tapToFlip'  => __( 'Tap to flip', 'tbt-swipe' ),
				'stillLearn' => __( 'Words to work on', 'tbt-swipe' ),
				'goAgain'    => __( 'Go again', 'tbt-swipe' ),
				'allKnown'   => __( 'All done — you knew every card!', 'tbt-swipe' ),
				'restart'    => __( 'Start over', 'tbt-swipe' ),
				// The end-of-round beat.
				'wellDone'   => __( 'Well done!', 'tbt-swipe' ),
				/* translators: 1: cards known, 2: cards in the deck, 3: the word "word" or "words" */
				'knewLine'   => __( 'You knew %1$d of %2$d %3$s.', 'tbt-swipe' ),
				/* translators: 1: number of cards still to learn, 2: the word "word" or "words" */
				'toWorkOn'   => __( '%1$d %2$s to work on', 'tbt-swipe' ),
				// The celebration, shown when every card in the deck is known.
				/* translators: %s: the learner's first name */
				'allTitleName' => __( '%s, well done!', 'tbt-swipe' ),
				'allTitle'   => __( 'Well done!', 'tbt-swipe' ),
				/* translators: 1: cards in the deck, 2: the word "word" or "words" */
				'allLine'    => __( 'You have just learnt %1$d new %2$s!', 'tbt-swipe' ),
				'allSub'     => __( 'Keep up the good work!', 'tbt-swipe' ),
				'wordOne'    => __( 'word', 'tbt-swipe' ),
				'wordMany'   => __( 'words', 'tbt-swipe' ),
			)
		);

		// The learner is always signed in, so the celebration can greet them
		// by name: their first name, their display name if the account has
		// no first name, and an empty string if it has neither — the player
		// keeps a greeting that needs no name.
		$user = wp_get_current_user();
		$name = $user ? trim( $user->first_name ) : '';
		if ( '' === $name && $user ) {
			$name = trim( $user->display_name );
		}

		wp_localize_script(
			'tbts-deck',
			'tbtsDeck',
			array(
				'restBase' => esc_url_raw( rest_url( TBTS_Rest::NS . '/set/' ) ),
				'learnerName' => $name,
				'logo'     => esc_url_raw( TBTS_URL . 'assets/img/tbt-logo.png' ),
				// Baked into the page HTML, so on a stale page this is the
				// *old* version — which is what makes the handshake in
				// deck.js's load() work.
				'version'  => TBTS_VERSION,
				'i18n'     => $i18n,
			)
		);
	}

	public function render( $atts ) {
		// If a cache served the page before maybe_enqueue ran, make sure assets load.
		$this->enqueue();

		return '<div class="tbts-deck" id="tbts-deck-root">'
			. '<div class="tbts-deck-loading">' . esc_html__( 'Loading…', 'tbt-swipe' ) . '</div>'
			. '</div>';
	}
}

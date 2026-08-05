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
	}

	/**
	 * The player and the management UI are separate surfaces: deck.css and
	 * deck.js load only where [tbt_swipe] itself is, never on a page that
	 * merely holds the two management shortcodes.
	 */
	public function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		if ( has_shortcode( $post->post_content, 'tbt_swipe' ) ) {
			$this->enqueue();
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
		// The handle is deliberately plugin-neutral: another TBT plugin on the
		// same page shares this one file, so register it only once.
		if ( ! wp_style_is( 'tbt-tokens', 'registered' ) ) {
			wp_register_style( 'tbt-tokens', TBTS_URL . 'assets/css/tbt-tokens.css', array(), TBTS_VERSION );
		}
		wp_enqueue_style( 'tbt-tokens' );
		wp_enqueue_style( 'tbts-frontend', TBTS_URL . 'assets/css/frontend.css', array( 'tbt-tokens' ), TBTS_VERSION );
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
					'wordOne'       => __( 'word', 'tbt-swipe' ),
					'wordMany'      => __( 'words', 'tbt-swipe' ),
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

		wp_localize_script(
			'tbts-deck',
			'tbtsDeck',
			array(
				'restBase' => esc_url_raw( rest_url( TBTS_Rest::NS . '/set/' ) ),
				'logo'     => esc_url_raw( TBTS_URL . 'assets/img/tbt-logo.png' ),
				'i18n'     => array(
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
				),
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

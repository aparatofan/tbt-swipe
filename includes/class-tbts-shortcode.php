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

		wp_enqueue_style( 'tbts-frontend', TBTS_URL . 'assets/css/frontend.css', array(), TBTS_VERSION );
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
					'countOne'      => __( 'słowo', 'tbt-swipe' ),
					'countFew'      => __( 'słowa', 'tbt-swipe' ),
					'countMany'     => __( 'słów', 'tbt-swipe' ),
					'tooMany'       => sprintf(
						/* translators: %d: maximum number of terms per generation */
						__( 'Za dużo słów w jednym zestawie (maks. %d).', 'tbt-swipe' ),
						(int) $max_terms
					),
					'noTerms'       => __( 'Wpisz przynajmniej jedno słowo.', 'tbt-swipe' ),
					'needTitle'     => __( 'Podaj tytuł zestawu.', 'tbt-swipe' ),
					'generating'    => __( 'Generuję karty… to może potrwać do 30 sekund.', 'tbt-swipe' ),
					'saving'        => __( 'Zapisuję…', 'tbt-swipe' ),
					'noCards'       => __( 'Nie ma czego zapisać — najpierw wygeneruj karty.', 'tbt-swipe' ),
					'copied'        => __( 'Skopiowano!', 'tbt-swipe' ),
					'confirmDelete' => __( 'Usunąć ten zestaw i wszystkie jego karty? Tej operacji nie można cofnąć.', 'tbt-swipe' ),
					'quota'         => __( 'Dzienny limit generowania został wyczerpany.', 'tbt-swipe' ),
					'apiError'      => __( 'Nie udało się wygenerować kart. Spróbuj ponownie.', 'tbt-swipe' ),
					'staleNonce'    => __( 'Sesja wygasła. Odśwież stronę.', 'tbt-swipe' ),
					'networkError'  => __( 'Coś poszło nie tak. Sprawdź połączenie i spróbuj ponownie.', 'tbt-swipe' ),
					'noDeckPage'    => __( 'Zestaw zapisany, ale administrator nie wskazał jeszcze strony z talią, więc link nie jest gotowy.', 'tbt-swipe' ),
					'classNoLessons' => __( 'Ta klasa nie ma jeszcze lekcji. Dodaj lekcję w Notes albo zapisz zestaw bez klasy.', 'tbt-swipe' ),
					'noLessonsSuffix' => __( '(brak lekcji)', 'tbt-swipe' ),
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

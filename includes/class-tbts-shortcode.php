<?php
/**
 * [tbt_swipe] shortcode. Assets enqueue only when the shortcode is present.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Shortcode {

	private $present = false;

	public function __construct() {
		add_shortcode( 'tbt_swipe', array( $this, 'render' ) );
		// Detect the shortcode early so we can enqueue only when needed.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'tbt_swipe' ) ) {
			$this->enqueue();
		}
	}

	private function enqueue() {
		if ( $this->present ) {
			return;
		}
		$this->present = true;

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

<?php
/**
 * Admin AJAX handlers. Every handler checks the nonce AND the capability.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Ajax {

	const NONCE_ACTION = 'tbts_admin';

	public function __construct() {
		add_action( 'wp_ajax_tbts_generate', array( $this, 'generate' ) );
		add_action( 'wp_ajax_tbts_save_set', array( $this, 'save_set' ) );
	}

	private function guard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! TBTS_Capabilities::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'tbt-swipe' ) ), 403 );
		}
	}

	/**
	 * Generate cards for the pasted terms.
	 *
	 * Goes through TBTS_Generator, the same path the frontend page uses, so
	 * the prompt, the per-generation cap and the daily quota are identical on
	 * both surfaces.
	 */
	public function generate() {
		$this->guard();

		$raw   = isset( $_POST['terms'] ) ? wp_unslash( $_POST['terms'] ) : '';
		$cards = TBTS_Generator::generate( $raw, get_current_user_id() );

		if ( is_wp_error( $cards ) ) {
			wp_send_json_error(
				array(
					'message' => $cards->get_error_message(),
					'code'    => $cards->get_error_code(),
				)
			);
		}

		wp_send_json_success( array( 'cards' => $cards ) );
	}

	/**
	 * Save the set and all cards in one call, replacing existing cards.
	 */
	public function save_set() {
		$this->guard();

		$set_id = isset( $_POST['set_id'] ) ? absint( $_POST['set_id'] ) : 0;
		$title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'draft';
		$json   = isset( $_POST['cards'] ) ? wp_unslash( $_POST['cards'] ) : '';

		if ( ! in_array( $status, array( 'draft', 'published' ), true ) ) {
			$status = 'draft';
		}
		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => __( 'Please give the set a title.', 'tbt-swipe' ) ) );
		}

		// Editing an existing set requires owning it, not merely holding the
		// capability. Administrators oversee every set.
		if ( $set_id && ! TBTS_DB::user_can_edit_set( $set_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit that set.', 'tbt-swipe' ) ), 403 );
		}

		$raw_cards = json_decode( $json, true );
		if ( ! is_array( $raw_cards ) || empty( $raw_cards ) ) {
			wp_send_json_error( array( 'message' => __( 'There are no cards to save. Generate or add cards first.', 'tbt-swipe' ) ) );
		}
		if ( count( $raw_cards ) > TBTS_Generator::HARD_MAX_CARDS ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: maximum number of cards in one set */
						__( 'Too many cards — the maximum is %d.', 'tbt-swipe' ),
						TBTS_Generator::HARD_MAX_CARDS
					),
				)
			);
		}

		$cards = array();
		foreach ( $raw_cards as $card ) {
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
			wp_send_json_error( array( 'message' => __( 'There are no valid cards to save.', 'tbt-swipe' ) ) );
		}

		$result = TBTS_DB::save_set( $set_id, $title, $status, $cards );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'id'       => $result,
				'edit_url' => add_query_arg(
					array(
						'page'    => 'tbt-swipe',
						'action'  => 'edit',
						'set'     => $result,
						'updated' => 1,
					),
					admin_url( 'admin.php' )
				),
			)
		);
	}
}

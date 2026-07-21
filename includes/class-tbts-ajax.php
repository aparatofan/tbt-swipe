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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'tbt-swipe' ) ), 403 );
		}
	}

	/**
	 * Generate cards for the pasted terms via the server-side AI proxy.
	 */
	public function generate() {
		$this->guard();

		$raw   = isset( $_POST['terms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['terms'] ) ) : '';
		$terms = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );

		if ( count( $terms ) < 5 || count( $terms ) > 20 ) {
			wp_send_json_error( array( 'message' => __( 'Please enter between 5 and 20 terms, one per line.', 'tbt-swipe' ) ) );
		}

		$cards = TBTS_API::generate( $terms );

		if ( is_wp_error( $cards ) ) {
			wp_send_json_error( array( 'message' => $cards->get_error_message() ) );
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

		$raw_cards = json_decode( $json, true );
		if ( ! is_array( $raw_cards ) || empty( $raw_cards ) ) {
			wp_send_json_error( array( 'message' => __( 'There are no cards to save. Generate or add cards first.', 'tbt-swipe' ) ) );
		}
		if ( count( $raw_cards ) > 40 ) {
			wp_send_json_error( array( 'message' => __( 'Too many cards — the maximum is 40.', 'tbt-swipe' ) ) );
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

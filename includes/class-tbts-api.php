<?php
/**
 * Server-side proxy to the OpenAI Chat Completions API.
 *
 * The API key never leaves the server: the browser talks to admin-ajax.php,
 * this class talks to api.openai.com.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_API {

	const ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
	const DEFAULT_MODEL = 'gpt-4o-mini';

	/**
	 * Generate card data for a batch of terms in one API call.
	 *
	 * @param string[] $terms Sanitised English terms, one per card.
	 * @return array|WP_Error List of ['term','ipa','translation','example'] in input order.
	 */
	public static function generate( array $terms ) {
		$api_key = get_option( 'tbts_api_key', '' );
		if ( '' === $api_key ) {
			return new WP_Error(
				'tbts_no_key',
				__( 'No API key configured. Add your OpenAI API key under TBT Swipe → Settings.', 'tbt-swipe' )
			);
		}

		$model  = get_option( 'tbts_model', self::DEFAULT_MODEL );
		$prompt = self::build_prompt( $terms );

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'                 => $model,
						'max_completion_tokens' => 8192,
						'messages'              => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'tbts_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach the AI service: %s', 'tbt-swipe' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'unknown error', 'tbt-swipe' );
			return new WP_Error(
				'tbts_api_error',
				sprintf(
					/* translators: 1: HTTP status, 2: API error detail */
					__( 'AI service returned an error (HTTP %1$d): %2$s', 'tbt-swipe' ),
					$code,
					sanitize_text_field( $detail )
				)
			);
		}

		return self::parse_response( $body, $terms );
	}

	private static function build_prompt( array $terms ) {
		return "You are helping a Polish teacher of English prepare vocabulary flashcards. "
			. "For each item in the list below, return the IPA phonetic transcription (British English, in slashes), "
			. "the Polish translation, and one natural example sentence in English at B1 level that uses the item in context.\n\n"
			. "Return ONLY a JSON array, no preamble, no markdown fences. Each element: "
			. '{"term": "...", "ipa": "...", "translation": "...", "example": "..."}. '
			. "Preserve the input order exactly and return exactly one element per input item.\n\n"
			. "Items:\n"
			. implode( "\n", $terms );
	}

	/**
	 * Pull the text out of the completion, strip stray fences, decode and
	 * validate. A count mismatch is a hard error — never a partial save.
	 *
	 * @return array|WP_Error
	 */
	private static function parse_response( $body, array $terms ) {
		$text = '';
		if ( isset( $body['choices'][0]['message']['content'] ) ) {
			$text = (string) $body['choices'][0]['message']['content'];
		}

		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		// Be tolerant of any stray prose around the array.
		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$text = substr( $text, $start, $end - $start + 1 );
		}

		$data = json_decode( $text, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'tbts_parse_error', __( 'The AI response could not be parsed. Please try again.', 'tbt-swipe' ) );
		}

		if ( count( $data ) !== count( $terms ) ) {
			return new WP_Error(
				'tbts_count_mismatch',
				sprintf(
					/* translators: 1: expected count, 2: returned count */
					__( 'The AI returned %2$d items for %1$d terms. Nothing was saved — please try again.', 'tbt-swipe' ),
					count( $terms ),
					count( $data )
				)
			);
		}

		$cards = array();
		foreach ( array_values( $data ) as $i => $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'tbts_parse_error', __( 'The AI response could not be parsed. Please try again.', 'tbt-swipe' ) );
			}
			$cards[] = array(
				'term'        => sanitize_text_field( $item['term'] ?? $terms[ $i ] ),
				'ipa'         => sanitize_text_field( $item['ipa'] ?? '' ),
				'translation' => sanitize_text_field( $item['translation'] ?? '' ),
				'example'     => sanitize_textarea_field( $item['example'] ?? '' ),
			);
		}

		return $cards;
	}
}

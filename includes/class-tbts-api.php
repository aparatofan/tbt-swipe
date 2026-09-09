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
	 * @param string   $level CEFR band for the example sentences. Defaults to
	 *                        B1, the level every card was generated at before
	 *                        the picker existed.
	 * @param string   $type  Type of English for the example sentences.
	 *                        Defaults to Mix, what a deck generated before the
	 *                        picker existed reads as.
	 * @return array|WP_Error List of ['term','ipa','translation','example'] in input order.
	 */
	public static function generate( array $terms, $level = TBTS_Levels::DEFAULT_BAND, $type = TBTS_Register::DEFAULT_TYPE ) {
		$api_key = get_option( 'tbts_api_key', '' );
		if ( '' === $api_key ) {
			return new WP_Error(
				'tbts_no_key',
				__( 'No API key configured. Add your OpenAI API key under TBT Swipe → Settings.', 'tbt-swipe' )
			);
		}

		$model  = get_option( 'tbts_model', self::DEFAULT_MODEL );
		$prompt = self::build_prompt( $terms, $level, $type );

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

	/**
	 * The one prompt. Everything except the level block is fixed: the article
	 * warning, the British IPA requirements and the JSON-only output contract
	 * are what keep the cards usable, whatever level they are pitched at.
	 *
	 * @param string[] $terms Sanitised English terms.
	 * @param string   $level Already-sanitised CEFR band.
	 * @param string   $type  Already-sanitised type of English.
	 * @return string
	 */
	private static function build_prompt( array $terms, $level, $type = TBTS_Register::DEFAULT_TYPE ) {
		return "You are helping a Polish teacher of English prepare vocabulary flashcards. "
			. "For each item in the list below, return the IPA phonetic transcription (British English, in slashes), "
			. "the Polish translation, and one natural example sentence in English that uses the item in context "
			. "and is written to the level rules below.\n\n"
			// The item count goes in so Mix can state its split as a number
			// rather than as "about half", which the model reads loosely.
			. TBTS_Levels::prompt_block( $level, $type, count( $terms ) )
			. "\nQuality requirements — follow all of them:\n"
			. "1. Example sentences must be grammatically correct, natural British English. "
			. "Pay particular attention to articles (a / an / the / zero article): Polish has no articles, "
			. "so article mistakes are easy to miss but must not appear. Use articles exactly as a native speaker would.\n"
			. "2. IPA must be valid British English IPA (RP) with no repeated, stray, or duplicated characters. "
			. "Wrap it in slashes. For multi-word items, transcribe the whole phrase as connected speech, "
			. "with a single space between words (e.g. \"to strike a balance\" -> \"/tə straɪk ə ˈbæləns/\").\n"
			. "3. Before returning, re-read each transcription character by character and correct any doubled "
			. "or misplaced symbols.\n"
			. "4. An item may end with a note in round brackets, for example \"spring (car part)\" or "
			. "\"pitch (sound)\". The note is guidance for you, not part of the word. Use it to choose the "
			. "correct sense of the item, then return the \"term\" field WITHOUT the brackets and without "
			. "the note: \"spring (car part)\" is returned as \"spring\". The IPA, the translation and the "
			. "example sentence must all match the sense the note indicates. If an item has no note, "
			. "treat it exactly as before.\n\n"
			// The level governs the example sentence only. The transcription
			// and the translation belong to the item itself and do not move.
			. "The level rules apply to the example sentence only. The IPA and the Polish translation are "
			. "properties of the item and never change with the level.\n\n"
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
				'term'        => self::strip_sense_note( sanitize_text_field( $item['term'] ?? $terms[ $i ] ) ),
				'ipa'         => sanitize_text_field( $item['ipa'] ?? '' ),
				'translation' => sanitize_text_field( $item['translation'] ?? '' ),
				'example'     => sanitize_textarea_field( $item['example'] ?? '' ),
			);
		}

		return $cards;
	}

	/**
	 * Ask the model which of the teacher's items are misspelt, before any card
	 * is built from them.
	 *
	 * A courtesy pass, not a generation: it is deliberately cheap (a short
	 * prompt, a short answer) and it advises rather than blocks. The caller
	 * treats every failure as "nothing to say" and generates anyway, so this
	 * method never has to be right — only useful when it is.
	 *
	 * @param string[] $terms Sanitised English terms, as parse_terms() built them.
	 * @return array|WP_Error List of ['index' => int, 'suggestion' => string].
	 */
	public static function check_terms( array $terms ) {
		$api_key = get_option( 'tbts_api_key', '' );
		if ( '' === $api_key ) {
			return new WP_Error(
				'tbts_no_key',
				__( 'No API key configured. Add your OpenAI API key under TBT Swipe → Settings.', 'tbt-swipe' )
			);
		}

		$model  = get_option( 'tbts_model', self::DEFAULT_MODEL );
		$prompt = self::build_check_prompt( $terms );

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
						// A list of indices and single words. Nothing like the
						// budget a full generation needs.
						'max_completion_tokens' => 1024,
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

		return self::parse_check_response( $body, $terms );
	}

	/**
	 * The spell-check prompt. Much smaller than build_prompt(): the answer is
	 * a list of indices, so everything here is about suppressing false
	 * positives rather than shaping prose.
	 *
	 * @param string[] $terms Sanitised English terms.
	 * @return string
	 */
	private static function build_check_prompt( array $terms ) {
		$numbered = array();
		foreach ( array_values( $terms ) as $i => $term ) {
			$numbered[] = $i . '. ' . $term;
		}

		return "You are checking a list of English vocabulary items a teacher has typed, before "
			. "flashcards are made from them. Report only clear spelling mistakes.\n\n"
			. "Rules:\n"
			. "- An item may end with a note in round brackets, for example \"spring (car part)\". "
			. "Ignore the note completely. Check only the word or phrase before it.\n"
			. "- Do NOT report: correct British or American spellings, proper nouns, rare or "
			. "technical words, informal words, or phrases whose grammar you dislike. Only "
			. "report an item that is not a word in any standard spelling of English.\n"
			. "- Do NOT report an item merely because it is unusual. When in doubt, say nothing.\n\n"
			. "Return ONLY a JSON array, no preamble, no markdown fences. Include one element "
			. "per SUSPECT item only — return [] if every item is fine. Each element: "
			. '{"index": 0, "suggestion": "..."} where "index" is the item\'s zero-based position '
			. "in the list below and \"suggestion\" is the corrected spelling of the word only, "
			. "without any bracketed note.\n\n"
			. "Items:\n"
			. implode( "\n", $numbered );
	}

	/**
	 * Parse the check response, on the same terms as parse_response(): fences
	 * stripped, outermost array taken, an unreadable body a WP_Error.
	 *
	 * The error matters. The caller has to be able to tell "nothing is wrong"
	 * from "the check did not run" — the first stays silent, the second
	 * generates anyway rather than pretending the list is clean.
	 *
	 * @param mixed    $body  Decoded API response.
	 * @param string[] $terms The terms that were submitted.
	 * @return array|WP_Error
	 */
	private static function parse_check_response( $body, array $terms ) {
		$text = '';
		if ( isset( $body['choices'][0]['message']['content'] ) ) {
			$text = (string) $body['choices'][0]['message']['content'];
		}

		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		// Unlike parse_response(), the array here is required rather than
		// merely preferred: without it a bare object would decode cleanly and
		// read as an empty flag list, which is exactly the "nothing wrong"
		// answer this method must never invent.
		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );
		if ( false === $start || false === $end || $end <= $start ) {
			return new WP_Error( 'tbts_parse_error', __( 'The AI response could not be parsed. Please try again.', 'tbt-swipe' ) );
		}

		$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'tbts_parse_error', __( 'The AI response could not be parsed. Please try again.', 'tbt-swipe' ) );
		}

		$terms = array_values( $terms );
		$flags = array();

		foreach ( $data as $item ) {
			// Never more flags than there were terms, whatever comes back.
			if ( count( $flags ) >= count( $terms ) ) {
				break;
			}
			if ( ! is_array( $item ) || ! isset( $item['index'] ) ) {
				continue;
			}

			$index = (int) $item['index'];
			if ( ! isset( $terms[ $index ] ) ) {
				continue;
			}

			$suggestion = sanitize_text_field( $item['suggestion'] ?? '' );
			// A suggestion identical to the item is not a correction, and an
			// empty one is nothing to show the teacher.
			if ( '' === $suggestion || $suggestion === $terms[ $index ] ) {
				continue;
			}

			$flags[] = array(
				'index'      => $index,
				'suggestion' => $suggestion,
			);
		}

		return $flags;
	}

	/**
	 * Remove a trailing sense note in round brackets from a term.
	 *
	 * "spring (car part)" becomes "spring". Anchored to the end of the string on
	 * purpose: a leading "(to) pitch" is a legitimate way to write an infinitive
	 * and must survive untouched, and so must a term that genuinely contains
	 * brackets in the middle.
	 *
	 * Applied to whatever the model returns, not only to what the teacher typed:
	 * the prompt asks for a stripped term, and this is what makes it true.
	 *
	 * @param string $term Term as typed or as returned.
	 * @return string Term with any trailing bracketed note removed. Never empty:
	 *                a term that is nothing but a note is returned unchanged
	 *                rather than reduced to ''.
	 */
	private static function strip_sense_note( $term ) {
		$stripped = preg_replace( '/\s*\([^()]*\)\s*$/u', '', (string) $term );
		$stripped = trim( (string) $stripped );

		return '' !== $stripped ? $stripped : trim( (string) $term );
	}
}

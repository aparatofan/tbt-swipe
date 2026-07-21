<?php
/**
 * Public, read-only REST endpoint that feeds the frontend deck.
 *
 * Returns published sets only, exposes no IDs or user data, and sends
 * no-cache headers so a shared cache can't leak one set's data at another slug.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Rest {

	const NS = 'tbt-swipe/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/set/(?P<slug>[A-Za-z0-9]{12})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'get_set' ),
				'args'                => array(
					'slug' => array(
						'validate_callback' => function ( $value ) {
							return (bool) preg_match( '/^[A-Za-z0-9]{12}$/', $value );
						},
					),
				),
			)
		);
	}

	public function get_set( WP_REST_Request $request ) {
		$slug = $request->get_param( 'slug' );
		$set  = TBTS_DB::get_set_by_slug( $slug );

		$this->no_cache_headers();

		if ( ! $set || 'published' !== $set->status ) {
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
				'title' => $set->title,
				'cards' => $cards,
			)
		);
	}

	/**
	 * Defeat LiteSpeed and other page/edge caches for this route.
	 */
	private function no_cache_headers() {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}
}

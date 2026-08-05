<?php
/**
 * Settings page: API key, model string, deck page selector.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Settings {

	const GROUP = 'tbts_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_menu() {
		$parent = defined( 'TBT_HUB_SLUG' ) ? TBT_HUB_SLUG : 'tbt-swipe';
		$label  = defined( 'TBT_HUB_SLUG' )
			? __( 'TBT Swipe Settings', 'tbt-swipe' )
			: __( 'Settings', 'tbt-swipe' );

		add_submenu_page(
			$parent,
			__( 'TBT Swipe Settings', 'tbt-swipe' ),
			$label,
			'manage_options',
			'tbt-swipe-settings',
			array( $this, 'render' )
		);
	}

	public function register_settings() {
		register_setting(
			self::GROUP,
			'tbts_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			'tbts_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => TBTS_API::DEFAULT_MODEL,
			)
		);
		register_setting(
			self::GROUP,
			'tbts_deck_page_id',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		register_setting(
			self::GROUP,
			'tbt_swipe_manager_roles',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_manager_roles' ),
				'default'           => array( 'administrator' ),
			)
		);
		register_setting(
			self::GROUP,
			'tbt_swipe_max_cards_per_generation',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_cards' ),
				'default'           => TBTS_Generator::DEFAULT_MAX_CARDS,
			)
		);
		register_setting(
			self::GROUP,
			'tbt_swipe_max_generations_per_day',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_generations' ),
				'default'           => TBTS_Generator::DEFAULT_MAX_GENERATIONS,
			)
		);
	}

	/**
	 * Normalise the role selection and push it onto the roles themselves.
	 *
	 * Administrators are always included: they hold manage_options anyway, and
	 * letting the box be unticked would only create a confusing half-state.
	 *
	 * @param mixed $value Submitted role slugs.
	 * @return string[]
	 */
	public function sanitize_manager_roles( $value ) {
		$selected = array_values( array_unique( array_map( 'sanitize_key', (array) $value ) ) );
		$selected = array_filter( $selected, function ( $slug ) {
			return (bool) get_role( $slug );
		} );
		if ( ! in_array( 'administrator', $selected, true ) ) {
			$selected[] = 'administrator';
		}
		$selected = array_values( $selected );

		TBTS_Capabilities::sync_role_caps( $selected );

		return $selected;
	}

	/**
	 * Cards per generation: at least 1, and capped so one runaway paste cannot
	 * blow through the model's output budget.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize_max_cards( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			$value = TBTS_Generator::DEFAULT_MAX_CARDS;
		}
		return min( $value, TBTS_Generator::HARD_MAX_CARDS );
	}

	/**
	 * Generations per day. 0 means unlimited.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize_max_generations( $value ) {
		return absint( $value );
	}

	/**
	 * Keep the stored key if the field is left blank (so we never wipe it by
	 * submitting the masked placeholder).
	 */
	public function sanitize_api_key( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return get_option( 'tbts_api_key', '' );
		}
		return sanitize_text_field( $value );
	}

	public function render() {
		$has_key       = '' !== get_option( 'tbts_api_key', '' );
		$model         = get_option( 'tbts_model', TBTS_API::DEFAULT_MODEL );
		$page_id       = (int) get_option( 'tbts_deck_page_id', 0 );
		$manager_roles = TBTS_Capabilities::managing_roles();
		$all_roles     = wp_roles()->roles;
		$max_cards     = TBTS_Generator::max_cards_per_generation();
		$max_gens      = TBTS_Generator::default_max_generations_per_day();
		?>
		<div class="wrap tbts-wrap">
			<h1><?php esc_html_e( 'TBT Swipe Settings', 'tbt-swipe' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbts_api_key"><?php esc_html_e( 'OpenAI API key', 'tbt-swipe' ); ?></label></th>
						<td>
							<input type="password" name="tbts_api_key" id="tbts_api_key" class="regular-text" autocomplete="off"
								value="" placeholder="<?php echo $has_key ? esc_attr__( 'Saved — leave blank to keep', 'tbt-swipe' ) : 'sk-…'; ?>">
							<p class="description"><?php esc_html_e( 'Stored server-side and never sent to the browser. Leave blank to keep the current key.', 'tbt-swipe' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbts_model"><?php esc_html_e( 'Model', 'tbt-swipe' ); ?></label></th>
						<td>
							<input type="text" name="tbts_model" id="tbts_model" class="regular-text" value="<?php echo esc_attr( $model ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: default model id */
									esc_html__( 'OpenAI model identifier. Default: %s', 'tbt-swipe' ),
									'<code>' . esc_html( TBTS_API::DEFAULT_MODEL ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbts_deck_page_id"><?php esc_html_e( 'Deck page', 'tbt-swipe' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'tbts_deck_page_id',
									'id'                => 'tbts_deck_page_id',
									'selected'          => $page_id,
									'show_option_none'  => __( '— Select a page —', 'tbt-swipe' ),
									'option_none_value' => 0,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'The published page that contains the [tbt_swipe] shortcode. Used to build the deck URL and QR code.', 'tbt-swipe' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Who can build decks', 'tbt-swipe' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Everyone in a ticked role can create, generate and delete their own decks — on the frontend page, with no wp-admin access needed. Avoid ticking a role that students also use. Each user only ever sees their own decks.', 'tbt-swipe' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Roles', 'tbt-swipe' ); ?></th>
						<td>
							<?php foreach ( $all_roles as $slug => $role ) : ?>
								<?php
								$is_admin_role = ( 'administrator' === $slug );
								$checked       = $is_admin_role || in_array( $slug, $manager_roles, true );
								?>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="tbt_swipe_manager_roles[]" value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( $checked ); ?> <?php disabled( $is_admin_role ); ?>>
									<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
									<?php if ( $is_admin_role ) : ?>
										<em>(<?php esc_html_e( 'always', 'tbt-swipe' ); ?>)</em>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Settings on this page stay administrator-only whatever is ticked here.', 'tbt-swipe' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'AI usage limits', 'tbt-swipe' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="tbt_swipe_max_cards_per_generation"><?php esc_html_e( 'Cards per generation', 'tbt-swipe' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" max="<?php echo esc_attr( TBTS_Generator::HARD_MAX_CARDS ); ?>" step="1"
								name="tbt_swipe_max_cards_per_generation" id="tbt_swipe_max_cards_per_generation"
								class="small-text" value="<?php echo esc_attr( $max_cards ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: 1: default value, 2: hard maximum */
									esc_html__( 'Most terms allowed in one generation. Default %1$d, hard maximum %2$d.', 'tbt-swipe' ),
									(int) TBTS_Generator::DEFAULT_MAX_CARDS,
									(int) TBTS_Generator::HARD_MAX_CARDS
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tbt_swipe_max_generations_per_day"><?php esc_html_e( 'Generations per user per day', 'tbt-swipe' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" step="1"
								name="tbt_swipe_max_generations_per_day" id="tbt_swipe_max_generations_per_day"
								class="small-text" value="<?php echo esc_attr( $max_gens ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %d: default value */
									esc_html__( 'Default %d. Set to 0 for no limit. Only successful generations count — a failed API call does not consume quota. A single user can be given a different limit with the user meta key %s.', 'tbt-swipe' ),
									(int) TBTS_Generator::DEFAULT_MAX_GENERATIONS,
									'<code>tbt_swipe_max_generations_per_day</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

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
		add_submenu_page(
			'tbt-swipe',
			__( 'TBT Swipe Settings', 'tbt-swipe' ),
			__( 'Settings', 'tbt-swipe' ),
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
		$has_key = '' !== get_option( 'tbts_api_key', '' );
		$model   = get_option( 'tbts_model', TBTS_API::DEFAULT_MODEL );
		$page_id = (int) get_option( 'tbts_deck_page_id', 0 );
		?>
		<div class="wrap tbts-wrap">
			<h1><?php esc_html_e( 'TBT Swipe Settings', 'tbt-swipe' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbts_api_key"><?php esc_html_e( 'Anthropic API key', 'tbt-swipe' ); ?></label></th>
						<td>
							<input type="password" name="tbts_api_key" id="tbts_api_key" class="regular-text" autocomplete="off"
								value="" placeholder="<?php echo $has_key ? esc_attr__( 'Saved — leave blank to keep', 'tbt-swipe' ) : 'sk-ant-…'; ?>">
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
									esc_html__( 'Anthropic model identifier. Default: %s', 'tbt-swipe' ),
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

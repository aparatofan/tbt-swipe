<?php
/**
 * Plugin Name:       TBT Swipe
 * Description:       Swipeable mobile vocabulary flashcard decks for live lessons. Teacher builds a set in wp-admin, AI fills in IPA, Polish translation and example sentences, students scan a QR code and swipe through the deck on their phones.
 * Version:           1.8.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            TBT
 * License:           GPL-2.0-or-later
 * Text Domain:       tbt-swipe
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTS_VERSION', '1.8.1' );
define( 'TBTS_DB_VERSION', '1.3' );
define( 'TBTS_PLUGIN_FILE', __FILE__ );
define( 'TBTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTS_URL', plugin_dir_url( __FILE__ ) );

/**
 * The one capability that gates set and card CRUD plus AI generation. Site
 * configuration (API key, model, role grants, AI limits) stays on
 * manage_options — see TBTS_Capabilities.
 */
define( 'TBTS_CAP', 'tbts_manage' );

require_once TBTS_DIR . 'includes/class-tbts-capabilities.php';
require_once TBTS_DIR . 'includes/class-tbts-classes.php';
require_once TBTS_DIR . 'includes/class-tbts-levels.php';
require_once TBTS_DIR . 'includes/class-tbts-db.php';
require_once TBTS_DIR . 'includes/class-tbts-api.php';
require_once TBTS_DIR . 'includes/class-tbts-generator.php';
require_once TBTS_DIR . 'includes/class-tbts-ajax.php';
require_once TBTS_DIR . 'includes/class-tbts-admin.php';
require_once TBTS_DIR . 'includes/class-tbts-rest.php';
require_once TBTS_DIR . 'includes/class-tbts-manage-rest.php';
require_once TBTS_DIR . 'includes/class-tbts-shortcode.php';
require_once TBTS_DIR . 'includes/class-tbts-frontend.php';
require_once TBTS_DIR . 'includes/class-tbts-settings.php';
require_once TBTS_DIR . 'includes/class-tbts-notes-bridge.php';

/**
 * Register this plugin on the TBT Hub Overview page.
 *
 * Registered at file scope so it loads regardless of admin context,
 * matching the pattern used by the other TBT plugins.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbts_register_hub_item( $items ) {
	$items[] = array(
		'slug'        => 'tbt-swipe',
		'title'       => 'TBT Swipe',
		'description' => 'Swipeable phone flashcard decks for live lessons; students scan a QR code and swipe through the set.',
		'capability'  => TBTS_CAP,
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbts_register_hub_item' );

/**
 * Create the tables and grant the management capability.
 */
function tbts_activate() {
	TBTS_DB::activate();
	TBTS_Capabilities::add_caps();
	update_option( 'tbts_caps_version', TBTS_Capabilities::CAPS_VERSION );
}
register_activation_hook( __FILE__, 'tbts_activate' );

add_action( 'plugins_loaded', function () {
	TBTS_DB::maybe_upgrade();
	TBTS_Capabilities::maybe_add_caps();

	new TBTS_Rest();
	new TBTS_Manage_Rest();
	new TBTS_Shortcode();
	new TBTS_Frontend();
	// Hooks TBT Notes' extension point. Inert when Notes is not installed.
	new TBTS_Notes_Bridge();

	if ( is_admin() ) {
		new TBTS_Admin();
		new TBTS_Ajax();
		new TBTS_Settings();
	}
} );

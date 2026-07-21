<?php
/**
 * Plugin Name:       TBT Swipe
 * Description:       Swipeable mobile vocabulary flashcard decks for live lessons. Teacher builds a set in wp-admin, AI fills in IPA, Polish translation and example sentences, students scan a QR code and swipe through the deck on their phones.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            TBT
 * License:           GPL-2.0-or-later
 * Text Domain:       tbt-swipe
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTS_VERSION', '1.1.0' );
define( 'TBTS_DB_VERSION', '1.0' );
define( 'TBTS_PLUGIN_FILE', __FILE__ );
define( 'TBTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTS_URL', plugin_dir_url( __FILE__ ) );

require_once TBTS_DIR . 'includes/class-tbts-db.php';
require_once TBTS_DIR . 'includes/class-tbts-api.php';
require_once TBTS_DIR . 'includes/class-tbts-ajax.php';
require_once TBTS_DIR . 'includes/class-tbts-admin.php';
require_once TBTS_DIR . 'includes/class-tbts-rest.php';
require_once TBTS_DIR . 'includes/class-tbts-shortcode.php';
require_once TBTS_DIR . 'includes/class-tbts-settings.php';

register_activation_hook( __FILE__, array( 'TBTS_DB', 'activate' ) );

add_action( 'plugins_loaded', function () {
	TBTS_DB::maybe_upgrade();

	new TBTS_Rest();
	new TBTS_Shortcode();

	if ( is_admin() ) {
		new TBTS_Admin();
		new TBTS_Ajax();
		new TBTS_Settings();
	}
} );

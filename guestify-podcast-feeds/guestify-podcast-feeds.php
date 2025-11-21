<?php
/**
 * Plugin Name:     Guestify Podcast Feeds
 * Plugin URI:      https://your.site/
 * Description:     Lightweight podcast RSS shortcodes + infinite scroll loader.
 * Version:         1.0.0
 * Author:          Your Name
 * Text Domain:     guestify-podcast-feeds
 * Domain Path:     /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin path and URL constants for robust file referencing
if ( ! defined( 'GPF_PLUGIN_DIR' ) ) {
    define( 'GPF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'GPF_PLUGIN_URL' ) ) {
    define( 'GPF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// 1) load translations at init
add_action( 'init', function() {
    load_plugin_textdomain( 'guestify-podcast-feeds', false, dirname( plugin_basename(__FILE__) ) . '/languages' );
});

// 2) include our core classes
require_once GPF_PLUGIN_DIR . 'includes/class-gpf-core.php';
require_once GPF_PLUGIN_DIR . 'includes/class-gpf-ajax.php';
require_once GPF_PLUGIN_DIR . 'includes/class-gpf-shortcodes.php';

// 3) fire them up
GPF_Core::init();
GPF_Ajax::init();
GPF_Shortcodes::init();

// Note: The stray closing brace '}' from the original user-provided snippet has been removed as it would cause a syntax error.

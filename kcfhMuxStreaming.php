<?php
/**
 * Plugin Name: KCFH-Streaming
 * Description: Secure Mux integration (shortcodes, utilities). First feature: [kcfh_stream_gallery] gallery of Mux VOD assets.
 * Version:     0.1.0
 * Author:      KCFH
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;


define('KCFH_STREAMING_VERSION', '0.1.0');
//__FILE__ provides the full path and filename of the current file where this is being used
define('KCFH_STREAMING_FILE', __FILE__);
//plugin_dir_path receives the full filesystem path to the directory
define('KCFH_STREAMING_DIR', plugin_dir_path(__FILE__));
//plugin_dir_url is used to retrieve the URL of the directory
define('KCFH_STREAMING_URL', plugin_dir_url(__FILE__));
define('KCFH_STREAMING_CACHE_PREFIX', 'kcfh_streaming_'); // used for transients

// Hard security checks: ensure creds come from server-only config.
if (!defined('MUX_TOKEN_ID') || !defined('MUX_TOKEN_SECRET')) {
    // Don’t block activation; shortcode will show a helpful message.
}

// Autoload (simple): require needed classes.
require_once KCFH_STREAMING_DIR . 'includes/class-admin.php';
require_once KCFH_STREAMING_DIR . 'includes/class-cpt-client.php';
require_once KCFH_STREAMING_DIR . 'includes/class-asset-service.php';
require_once KCFH_STREAMING_DIR . 'includes/class-shortcode-gallery.php';
require_once KCFH_STREAMING_DIR . 'includes/class-shortcode-client-search.php';

require_once KCFH_STREAMING_DIR . 'includes/class-utility-admin.php';


add_action('plugins_loaded', function () {
    // Init services
    \KCFH\Streaming\CPT_Client::init();
    \KCFH\Streaming\Admin_UI::boot();
    \KCFH\Streaming\Asset_Service::init();
    \KCFH\Streaming\Shortcode_Gallery::init();
    \KCFH\Streaming\Shortcode_Client_Search::init();
});



add_action('admin_menu', function () {
  
  

  //\KCFH\Streaming\Admin_UI::register_menus();
});

//add_action('admin_post_kcfh_assign_vod', ['KCFH\Streaming\Utility_Admin', 'handle_assign_vod']);
//add_action('admin_post_kcfh_assign_vod', ['\\KCFH\\Streaming\\Admin_UI', 'handle_assign_vod']);

add_filter('wp_resource_hints', function($hints, $relation){
  if ($relation === 'preconnect') {
    $hints[] = 'https://image.mux.com';
    $hints[] = 'https://stream.mux.com';
    $hints[] = 'https://cdn.jsdelivr.net';
  }
  return $hints;
}, 10, 2);

// Show an admin notice telling you if the shortcode is registered
add_action('admin_notices', function () {
  echo '<div class="notice '.(shortcode_exists('kcfh_stream_gallery') ? 'notice-success' : 'notice-error').'"><p>';
  echo shortcode_exists('kcfh_stream_gallery')
    ? '[KCFH] Shortcode <code>kcfh_stream_gallery</code> registered.'
    : '[KCFH] Shortcode <code>kcfh_stream_gallery</code> NOT registered.';
  echo '</p></div>';
});

// Log whether your MUX constants are defined on THIS site
add_action('init', function () {
  if (!defined('MUX_TOKEN_ID') || !defined('MUX_TOKEN_SECRET')) {
    error_log('[KCFH] MUX constants NOT defined');
  } else {
    error_log('[KCFH] MUX constants present');
  }
});




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


require_once KCFH_STREAMING_DIR . 'includes/VOD_Assignment.php'; // you already added earlier
require_once KCFH_STREAMING_DIR . 'includes/class-core.php';
require_once KCFH_STREAMING_DIR . 'includes/class-view.php'; // optional, if you use View

require_once KCFH_STREAMING_DIR . 'includes/class-utility-admin.php';
require_once KCFH_STREAMING_DIR . 'includes/class-utility-mux.php'; // optional, if you use View
require_once KCFH_STREAMING_DIR . 'includes/class-utility-debug.php';

require_once KCFH_STREAMING_DIR . 'includes/class-live-service.php'; //editing the live stream asset

require_once KCFH_STREAMING_DIR. '/includes/class-live-scheduler.php';




//Hook the scheduler on init so cron hooks & the 5-min tick are registered.
add_action('init', ['KCFH\Streaming\Live_Scheduler', 'bootstrap']);


add_action('plugins_loaded', function () {
    // Init services
    \KCFH\Streaming\CPT_Client::init();
    \KCFH\Streaming\Admin_UI::boot();
    \KCFH\STREAMING\Live_Scheduler::bootstrap();
    \KCFH\Streaming\Asset_Service::init();
    \KCFH\Streaming\Shortcode_Gallery::init();
    \KCFH\Streaming\Shortcode_Client_Search::init();
  
});

add_action('utilities_loaded', function(){
  //
  \KCFH\Streaming\Utility_Admin::init();
  \KCFH\Streaming\Utility_Mux::init();

});



add_action('admin_menu', function () {
  
  

  //\KCFH\Streaming\Admin_UI::register_menus();
});

add_action('admin_notices', function(){
    if (!current_user_can('manage_options')) return;
    $id   = (int) get_option(\KCFH\Streaming\Admin_UI::OPT_LIVE_CLIENT, 0);
    $nxtS = wp_next_scheduled(\KCFH\Streaming\Live_Scheduler::HOOK_START, [$id]);
    $nxtE = wp_next_scheduled(\KCFH\Streaming\Live_Scheduler::HOOK_END,   [$id]);
    echo '<div class="notice notice-info"><p>Live client: '.esc_html($id).
         ' | Next start: '.($nxtS ? date_i18n('Y-m-d H:i:s', $nxtS) : '—').
         ' | Next end: '.($nxtE ? date_i18n('Y-m-d H:i:s', $nxtE) : '—').'</p></div>';
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
  /*
  if (!defined('MUX_TOKEN_ID') || !defined('MUX_TOKEN_SECRET')) {
    error_log('[KCFH] MUX constants NOT defined');
  } else {
    error_log('[KCFH] MUX constants present');
  }
*/
});

// 1) Compute status very early so it's available for notices
add_action('init', function () {
    $hook = \KCFH\Streaming\Live_Scheduler::HOOK_START;
    $GLOBALS['kcfh_hook_status'] = has_action($hook) ? 'OK' : 'MISSING';
}, 1);

// 2) Show an admin notice on every admin page (only for admins)
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;

    $status = isset($GLOBALS['kcfh_hook_status']) ? $GLOBALS['kcfh_hook_status'] : 'unknown';

    // If you're on an Edit Client screen, also show its schedule
    $post_id   = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    $is_client = $post_id && get_post_type($post_id) === \KCFH\Streaming\CPT_Client::POST_TYPE;

    $extra = '';
    if ($is_client) {
        $ns = wp_next_scheduled(\KCFH\Streaming\Live_Scheduler::HOOK_START, [$post_id]);
        $ne = wp_next_scheduled(\KCFH\Streaming\Live_Scheduler::HOOK_END,   [$post_id]);
        $extra = ' | Next start: ' . ($ns ? date_i18n('Y-m-d H:i:s', $ns) : '—')
               . ' | Next end: '   . ($ne ? date_i18n('Y-m-d H:i:s', $ne) : '—');
    }

    echo '<div class="notice notice-info"><p>KCFH cron hook status: <strong>'
         . esc_html($status) . '</strong>' . esc_html($extra) . '</p></div>';
});






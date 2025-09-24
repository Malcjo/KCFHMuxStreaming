<?php
/**
 * Plugin Name: KCFH-Streaming
 * Description: Secure Mux integration (shortcodes, utilities). First feature: [kcfh_stream] gallery of Mux VOD assets.
 * Version:     0.1.0
 * Author:      KCFH
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

define('KCFH_STREAMING_VERSION', '0.1.0');
define('KCFH_STREAMING_FILE', __FILE__);
define('KCFH_STREAMING_DIR', plugin_dir_path(__FILE__));
define('KCFH_STREAMING_URL', plugin_dir_url(__FILE__));
define('KCFH_STREAMING_CACHE_PREFIX', 'kcfh_streaming_'); // used for transients

// Hard security checks: ensure creds come from server-only config.
if (!defined('MUX_TOKEN_ID') || !defined('MUX_TOKEN_SECRET')) {
    // Don’t block activation; shortcode will show a helpful message.
}

// Autoload (simple): require needed classes.
require_once KCFH_STREAMING_DIR . 'includes/class-asset-service.php';
require_once KCFH_STREAMING_DIR . 'includes/class-shortcode-gallery.php';

add_action('plugins_loaded', function () {
    // Init services
    \KCFH\Streaming\Asset_Service::init();
    \KCFH\Streaming\Shortcode_Gallery::init();
});

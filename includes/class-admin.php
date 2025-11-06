<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class Admin_UI {
  public const OPT_LIVE_CLIENT = 'kcfh_live_client_id';
  public const OPT_LIVE_PLAYBACK = 'kcfh_live_playback_id';
  private const NONCE_VOD_ACTIONS = 'kcfh_vod_actions';


  public static function boot(){

            // Only load in wp-admin
        if (!is_admin()) return;

        // Require admin layer (split controllers)
        require_once __DIR__ . '/admin/class-constants.php';
        require_once __DIR__ . '/admin/class-notices.php';
        require_once __DIR__ . '/admin/class-menu.php';
        require_once __DIR__ . '/admin/class-dashboard.php';
        require_once __DIR__ . '/admin/class-live.php';
        require_once __DIR__ . '/admin/class-vod-manager.php';
        require_once __DIR__ . '/admin/class-admin-toolbar.php';

        // (If these aren’t already required elsewhere)
        if (!class_exists(__NAMESPACE__ . '\\Asset_Service')) {
            require_once __DIR__ . '/class-asset.php';
        }
        if (!class_exists(__NAMESPACE__ . '\\Live_Service')) {
            require_once __DIR__ . '/class-live-service.php';
        }
        if (!class_exists(__NAMESPACE__ . '\\Utility_Admin')) {
            require_once __DIR__ . '/class-utility-admin.php';
        }

        // Hand off to the admin menu/router
        \KCFH\Streaming\Admin\Menu::boot();
        return;


  }

}

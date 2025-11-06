<?php
namespace KCFH\Streaming\Admin;

use KCFH\Streaming\CPT_Client;

if (!defined('ABSPATH')) exit;

final class Menu {
    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'register_menus']);

        // Admin-post endpoints
        add_action('admin_post_kcfh_set_live',             [Live::class, 'handle_set_live']);
        add_action('admin_post_kcfh_save_live_settings',   [Live::class, 'handle_save_live_settings']);
        add_action('admin_post_kcfh_set_reconnect_window', [Live::class, 'handle_set_reconnect_window']);
        add_action('admin_post_kcfh_enable_mp4',           [Vod_Manager::class, 'handle_enable_mp4']);
        add_action('admin_post_kcfh_download_mp4',         [Vod_Manager::class, 'handle_download_mp4']);

        // keep your existing assign_vod handler
        add_action('admin_post_kcfh_assign_vod', ['KCFH\\Streaming\\Utility_Admin', 'handle_assign_vod']);
    }

    public static function register_menus(): void {
        add_menu_page(
            'KCFH Streaming',
            'KCFH Streaming',
            'manage_options',
            'kcfh_streaming',
            [Dashboard::class, 'render'],
            'dashicons-video-alt3',
            25
        );

        add_submenu_page('kcfh_streaming', 'Dashboard', 'Dashboard', 'manage_options', 'kcfh_streaming', [Dashboard::class, 'render']);
        add_submenu_page('kcfh_streaming', 'VOD Manager', 'VOD Manager', 'manage_options', 'kcfh_vod_manager', [Vod_Manager::class, 'render']);
        add_submenu_page('kcfh_streaming', 'Live Settings', 'Live Settings', 'manage_options', 'kcfh_live_settings', [Live::class, 'render_settings']);

        // Native post screens
        add_submenu_page('kcfh_streaming','All Clients', 'All Clients','manage_options',\KCFH\Streaming\Admin\All_Clients_Page::SLUG,[\KCFH\Streaming\Admin\All_Clients_Page::class, 'render']);
        //add_submenu_page('kcfh_streaming', 'All Clients', 'All Clients', 'edit_posts', 'edit.php?post_type=' . CPT_Client::POST_TYPE);
        add_submenu_page('kcfh_streaming', 'Add New Client', 'Add New Client', 'edit_posts', 'post-new.php?post_type=' . CPT_Client::POST_TYPE);
    }
}

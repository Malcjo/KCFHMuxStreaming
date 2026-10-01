<?php
namespace KCFH\Streaming\Admin;

use KCFH\Streaming\Live_Service;

if (!defined('ABSPATH')) exit;

final class Live {

    public static function enqueue_assets($hook_suffix): void {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'kcfh_live_settings') {
            return;
        }

        wp_enqueue_media();

        $script_path = KCFH_STREAMING_DIR . 'assets/admin-image-tools.js';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : KCFH_STREAMING_VERSION;

        wp_enqueue_script(
            'kcfh-admin-image-tools',
            KCFH_STREAMING_URL . 'assets/admin-image-tools.js',
            [],
            $version,
            true
        );
    }

    public static function render_settings(): void {
        if (!current_user_can('kcfh_streaming_access')) wp_die('Nope');

        $live_playback = get_option(Constants::OPT_LIVE_PLAYBACK, '');
        $live_client   = (int) get_option(Constants::OPT_LIVE_CLIENT, 0);
        $default_image_id = (int) get_option(Constants::OPT_DEFAULT_GALLERY_IMAGE, 0);
        $default_image_url = $default_image_id
            ? wp_get_attachment_image_url($default_image_id, 'medium')
            : '';

        $rtmp_url   = defined('KCFH_LIVE_RTMP_URL')   ? KCFH_LIVE_RTMP_URL   : '';
        $stream_key = defined('KCFH_LIVE_STREAM_KEY') ? KCFH_LIVE_STREAM_KEY : '';
        $stream_id  = defined('KCFH_LIVE_STREAM_ID')  ? KCFH_LIVE_STREAM_ID  : '';

        echo '<div class="wrap"><h1>Live Settings</h1>';

        if (!$rtmp_url || !$stream_key) {
            echo '<div class="notice notice-error"><p><strong>Missing RTMP URL or Stream Key.</strong> Add KCFH_LIVE_RTMP_URL and KCFH_LIVE_STREAM_KEY to <code>wp-config.php</code>.</p></div>';
        }

        if (!$stream_id) {
            echo '<div class="notice notice-error"><p><strong>Missing Mux Live Stream ID.</strong> Add KCFH_LIVE_STREAM_ID to <code>wp-config.php</code> so scheduled start/stop can enable and disable ingest.</p></div>';
        }

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('kcfh_save_live_settings');
        echo '<input type="hidden" name="action" value="kcfh_save_live_settings">';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Live Playback ID</th><td>';
        echo '<input type="text" name="kcfh_live_playback_id" value="'.esc_attr($live_playback).'" class="regular-text">';
        echo '<p class="description">Playback ID from the Live Stream in Mux (not a VOD asset).</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Currently Live Client</th><td>';
        echo $live_client ? esc_html(get_the_title($live_client)).' (#'.$live_client.')' : 'None';
        echo '<p class="description">Use “Set Live / Unset Live” on the Dashboard. Only one client can be Live.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Default gallery image</th><td>';
        echo '<input type="hidden" id="kcfhDefaultGalleryImageId" name="kcfh_default_gallery_image_id" value="'.esc_attr($default_image_id).'">';
        echo '<div id="kcfhDefaultGalleryImagePreview" style="margin-bottom:10px;'.($default_image_url ? '' : 'display:none;').'">';
        echo '<img src="'.esc_url($default_image_url ?: '').'" alt="Default gallery image preview" style="display:block;width:150px;aspect-ratio:3/4;object-fit:cover;border:1px solid #c3c4c7;border-radius:8px;background:#f0f0f1;">';
        echo '</div>';
        echo '<button type="button" class="button" id="kcfhSelectDefaultGalleryImage">Choose default image</button> ';
        echo '<button type="button" class="button" id="kcfhRemoveDefaultGalleryImage"'.($default_image_url ? '' : ' style="display:none;"').'>Remove</button>';
        echo '<p class="description">Used in the public gallery whenever a client does not have a featured image.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button('Save Settings');
        echo '</form>';

        echo '<h2>Larix Broadcaster Setup</h2>';
        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">RTMP URL</th><td>';
        echo $rtmp_url ? '<code>'.esc_html($rtmp_url).'</code>' : '<em>Not set</em>';
        echo '<p class="description">Larix → Settings → Connections → New → URL.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Stream Key</th><td>';
        if ($stream_key) {
            $masked = str_repeat('•', max(0, strlen($stream_key) - 6)) . substr($stream_key, -6);
            echo '<input type="text" readonly value="'.esc_attr($masked).'" class="regular-text" style="max-width:360px;">';
            echo '<p class="description">Kept only in <code>wp-config.php</code>. Do not share.</p>';
        } else {
            echo '<em>Not set</em>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">Live Stream ID</th><td>';
        echo $stream_id ? '<code>'.esc_html($stream_id).'</code>' : '<em>Not set</em>';
        echo '<p class="description">Required for the scheduled Mux hard stop and automatic re-enable.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        // Live stream details
        if ($stream_id && class_exists(Live_Service::class)) {
            $ls = Live_Service::get_live_stream($stream_id);
            if (is_wp_error($ls)) {
                echo '<div class="notice notice-error"><p>Could not fetch Mux live stream: '.esc_html($ls->get_error_message()).'</p></div>';
            } else {
                $latency = !empty($ls['latency_mode']) ? $ls['latency_mode'] : 'standard';
                $reconn  = isset($ls['reconnect_window']) ? (int)$ls['reconnect_window'] : 0;
                $status  = !empty($ls['status']) ? sanitize_key($ls['status']) : 'unknown';
                echo '<h2>Mux Live Stream</h2><table class="form-table"><tbody>';
                echo '<tr><th>Live Stream ID</th><td><code>'.esc_html($stream_id).'</code></td></tr>';
                echo '<tr><th>Status</th><td><code>'.esc_html($status).'</code></td></tr>';
                echo '<tr><th>Latency Mode</th><td><code>'.esc_html($latency).'</code></td></tr>';
                echo '<tr><th>Reconnect Window</th><td><code>'.esc_html($reconn).'</code> seconds</td></tr>';
                echo '</tbody></table>';
            }
        }

        echo '</div>';
    }

    public static function handle_set_live(): void {
        if (!current_user_can('kcfh_streaming_access')) wp_die('Nope');
        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
        check_admin_referer('kcfh_set_live_'.$client_id);

        update_option(Constants::OPT_LIVE_CLIENT, $client_id);

        if ($client_id > 0) {
            $response = Live_Service::refresh_for_client($client_id);
        } else {
            $response = Live_Service::force_stop_configured_stream();
        }

        if (is_wp_error($response)) {
            error_log('[KCFH] Mux live action failed: ' . $response->get_error_message());
        }

        wp_safe_redirect(admin_url('admin.php?page=kcfh_streaming'));
        exit;
    }

    public static function handle_save_live_settings(): void {
        if (!current_user_can('kcfh_streaming_access')) wp_die('Nope');
        check_admin_referer('kcfh_save_live_settings');
        $playback = isset($_POST['kcfh_live_playback_id']) ? sanitize_text_field($_POST['kcfh_live_playback_id']) : '';
        update_option(Constants::OPT_LIVE_PLAYBACK, $playback);

        $default_image_id = isset($_POST['kcfh_default_gallery_image_id'])
            ? absint($_POST['kcfh_default_gallery_image_id'])
            : 0;

        if ($default_image_id && wp_attachment_is_image($default_image_id)) {
            update_option(Constants::OPT_DEFAULT_GALLERY_IMAGE, $default_image_id);
        } else {
            delete_option(Constants::OPT_DEFAULT_GALLERY_IMAGE);
        }

        wp_safe_redirect(admin_url('admin.php?page=kcfh_live_settings'));
        exit;
    }

    public static function handle_set_reconnect_window(): void {
        if (!current_user_can('kcfh_streaming_access')) wp_die('Nope');
        check_admin_referer('kcfh_set_reconnect_window');

        $win = isset($_POST['window']) ? (int) $_POST['window'] : 0;
        $win = max(0, min(1800, $win));

        $live_stream_id = defined('KCFH_LIVE_STREAM_ID') ? KCFH_LIVE_STREAM_ID : '';
        if ($live_stream_id && class_exists(Live_Service::class)) {
            $resp = Live_Service::update_live_stream($live_stream_id, ['reconnect_window' => $win]);
            if (is_wp_error($resp)) {
                wp_safe_redirect(admin_url('admin.php?page=kcfh_live_settings&updated=0&kcfh_msg='.rawurlencode($resp->get_error_message())));
                exit;
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=kcfh_live_settings&updated=1'));
        exit;
    }
}

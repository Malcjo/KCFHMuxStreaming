<?php
namespace KCFH\Streaming\Admin;

use KCFH\Streaming\Asset_Service;
use KCFH\Streaming\CPT_Client;
use KCFH\Streaming\Admin_Util;

if (!defined('ABSPATH')) exit;

final class Vod_Manager {

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Permission denied');

        AdminToolbar::render('vod');
        $preparing_asset = get_option('kcfh_mp4_preparing_asset', '');

        $notice = isset($_GET['kcfh_notice']) ? sanitize_text_field($_GET['kcfh_notice']) : '';
        $msg    = isset($_GET['kcfh_msg']) ? wp_kses_post(wp_unslash($_GET['kcfh_msg'])) : '';
        if ($notice) Notices::show($notice, $msg);

        $res = Asset_Service::fetch_assets(['limit'=>50, 'order'=>'created_at', 'direction'=>'desc', 'status'=>'ready'], 30);




        echo '<div class="wrap"><h1>VOD Manager</h1>';

        echo '<style>
        .kcfh-mp4-preparing {
            opacity: 0.5;
            pointer-events: none;
            cursor: not-allowed;
        }
        </style>';

        if (is_wp_error($res)) {
            echo '<p style="color:#c00">' . esc_html($res->get_error_message()) . '</p></div>';
            return;
        }

        $assets  = $res['assets'];
        $clients = get_posts(['post_type'=>CPT_Client::POST_TYPE, 'numberposts'=>-1, 'orderby'=>'title', 'order'=>'ASC']);

        $client_vod_map = [];
        foreach ($clients as $c) $client_vod_map[$c->ID] = get_post_meta($c->ID, '_kcfh_asset_id', true);

        ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th>Asset ID</th>
              <th>Created</th>
              <th>Title</th>
              <th>Creator ID</th>
              <th>External ID</th>
              <th>Preview</th>
              <th>Assign to Client</th>
              <th>Download</th>
            </tr>
          </thead>
          <tbody>
        <?php
        foreach ($assets as $a) {
            $created = !empty($a['created_at'])
              ? date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($a['created_at']))
              : '—';

            $title      = $a['title']       ? esc_html($a['title'])       : '—';
            $creator_id = $a['creator_id']  ? esc_html($a['creator_id'])  : '—';
            $external   = $a['external_id'] ? esc_html($a['external_id']) : '—';

            $current_client_id = 0;
            foreach ($client_vod_map as $cid => $assigned_asset) {
                if ($assigned_asset === $a['id']) { $current_client_id = (int) $cid; break; }
            }

            $pid   = self::first_public_playback_id_local($a);
            $thumb = $pid ? esc_url(add_query_arg(['width'=>320,'height'=>180,'time'=>2,'fit_mode'=>'smartcrop'], "https://image.mux.com/$pid/thumbnail.jpg")) : '';

            echo '<tr>';
            echo '<td style="font-family:monospace">'.esc_html($a['id']).'</td>';
            echo '<td>'.esc_html($created).'</td>';
            echo '<td>'.$title.'</td>';
            echo '<td>'.$creator_id.'</td>';
            echo '<td>'.$external.'</td>';
            echo '<td>'.($thumb ? '<img src="'.$thumb.'" width="160" height="90" style="border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.12)">' : '—').'</td>';

            // Assign column
            echo '<td>';
            echo '<form method="post" action="' . esc_url( admin_url('admin-post.php?action=kcfh_assign_vod') ) . '">';
            wp_nonce_field( 'kcfh_assign_vod_' . $a['id'], 'kcfh_nonce' );
            echo '<input type="hidden" name="action" value="kcfh_assign_vod">';
            echo '<input type="hidden" name="asset_id" value="'.esc_attr($a['id']).'">';
            echo '<select name="client_id">';
                $selU = ($current_client_id === 0) ? ' selected' : '';
                echo '<option value="0"'.$selU.'>— Unassigned —</option>';
                foreach ($clients as $c) {
                    $assigned_asset = isset($client_vod_map[$c->ID]) ? $client_vod_map[$c->ID] : '';
                    $is_current = ($c->ID === $current_client_id);
                    if (!$is_current && !empty($assigned_asset)) continue;
                    $sel = $is_current ? ' selected' : '';
                    echo '<option value="'.esc_attr($c->ID).'"'.$sel.'>'.esc_html(get_the_title($c)).' (#'.$c->ID.')</option>';
                }
            echo '</select> ';
            submit_button('Save', 'secondary', '', false);
            echo '</form>';
            echo '</td>';

            Admin_Util::DownloadVOD($a);

            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Helpers local to this page */
    private static function first_public_playback_id_local(array $asset) {
        if (empty($asset['playback_ids'])) return null;
        foreach ($asset['playback_ids'] as $p) {
            $policy = isset($p['policy']) ? strtolower($p['policy']) : 'public';
            if ($policy === 'public' && !empty($p['id'])) return $p['id'];
        }
        return !empty($asset['playback_ids'][0]['id']) ? $asset['playback_ids'][0]['id'] : null;
    }

    /** Actions */
    public static function handle_enable_mp4(): void {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer(Constants::NONCE_VOD_ACTIONS);

        $asset_id   = isset($_GET['asset_id']) ? sanitize_text_field($_GET['asset_id']) : '';
        $resolution = isset($_GET['res']) ? sanitize_text_field($_GET['res']) : 'highest';
        if (!$asset_id) wp_die('Missing asset_id');

        $res = Asset_Service::create_static_rendition($asset_id, $resolution);
        if (is_wp_error($res)) {
            Notices::redirect_vod('mux_err', 'Mux error: ' . esc_html($res->get_error_message()));
        }
        Notices::redirect_vod('mp4_req', sprintf('Requested %s MP4. It will appear once processing finishes.', esc_html($resolution)));
    }

    public static function handle_download_mp4(): void {
        if (!current_user_can('manage_options')) wp_die('Nope');
        check_admin_referer(Constants::NONCE_VOD_ACTIONS);

        $asset_id = isset($_GET['asset_id']) ? sanitize_text_field($_GET['asset_id']) : '';
        if (!$asset_id) wp_die('Missing asset_id');

        $raw = Asset_Service::get_asset_raw($asset_id);
        if (is_wp_error($raw)) {
            Notices::redirect_vod('mux_err', 'Mux error: ' . esc_html($raw->get_error_message()));
        }

        $playback_id = Asset_Service::first_public_playback_id_from_raw($raw);
        if (!$playback_id) {
            Notices::redirect_vod('mux_err', 'No public playback ID on this asset.');
        }

        $ready_name = Asset_Service::pick_ready_static_name_from_raw($raw);
        if ($ready_name) {
            // If this asset was previously marked as "preparing", clear that flag.
            if (get_option('kcfh_mp4_preparing_asset') === $asset_id) {
                delete_option('kcfh_mp4_preparing_asset');
            }

            $save_as = Asset_Service::suggest_filename_from_raw($raw);
            $url     = Asset_Service::build_static_download_url($playback_id, $ready_name, $save_as);
            wp_redirect($url);
            exit;
        }

        // Not ready yet → ask Mux to prepare the MP4
        $req = Asset_Service::create_static_rendition($asset_id, 'highest');
        if (is_wp_error($req)) {
            Notices::redirect_vod('mux_err', 'Mux error: ' . esc_html($req->get_error_message()));
        }

        // Remember which asset we requested, so we can grey out its button.
        update_option('kcfh_mp4_preparing_asset', $asset_id);

        Notices::redirect_vod(
            'mp4_wait',
            'Preparing the MP4 now. Please click “Download MP4” again once it is ready.'
        );

    }
}

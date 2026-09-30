<?php
namespace KCFH\Streaming\Admin;

use KCFH\Streaming\Asset_Service;
use KCFH\Streaming\CPT_Client;
use KCFH\Streaming\Admin_Util;

if (!defined('ABSPATH')) exit;

final class Vod_Manager {

    public static function render(): void {
        if (!current_user_can('kcfh_streaming_access')) wp_die('Permission denied');

        AdminToolbar::render('vod');
        $preparing_asset = get_option('kcfh_mp4_preparing_asset', '');

        $notice = isset($_GET['kcfh_notice']) ? sanitize_text_field($_GET['kcfh_notice']) : '';
        $msg    = isset($_GET['kcfh_msg']) ? wp_kses_post(wp_unslash($_GET['kcfh_msg'])) : '';
        if ($notice) Notices::show($notice, $msg);

        $selected_asset_id = isset($_GET['asset_id'])
            ? sanitize_text_field(wp_unslash($_GET['asset_id']))
            : '';

        if ($selected_asset_id !== '') {
            self::render_editor($selected_asset_id);
            return;
        }

        $res = Asset_Service::fetch_assets(['limit'=>50, 'order'=>'created_at', 'direction'=>'desc'], 15);




        echo '<div class="wrap"><h1>Video On Demand (VOD) Manager</h1>';

        echo '<style>
        .kcfh-mp4-preparing {
            opacity: 0.5;
            pointer-events: none;
            cursor: not-allowed;
        }
        .kcfh-vod-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            background: #f0f0f1;
            font-size: 12px;
            font-weight: 600;
        }
        .kcfh-vod-status--ready { background: #edfaef; color: #146c2e; }
        .kcfh-vod-status--preparing { background: #fff4ce; color: #6d4b00; }
        .kcfh-vod-status--errored { background: #fcf0f1; color: #8a2424; }
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
              <th>Status</th>
              <th>Duration</th>
              <th>Creator ID</th>
              <th>External ID</th>
              <th>Preview</th>
              <th>Assign to Client</th>
              <th>Actions</th>
              <!--<th>Download</th>-->
            </tr>
          </thead>
          <tbody>
        <?php
        foreach ($assets as $a) {
            $created_timestamp = !empty($a['created_at'])
                ? (is_numeric($a['created_at']) ? (int) $a['created_at'] : strtotime($a['created_at']))
                : 0;
            $created = $created_timestamp
                ? date_i18n(get_option('date_format').' '.get_option('time_format'), $created_timestamp)
                : '—';

            $title      = $a['title']       ? esc_html($a['title'])       : '—';
            $status     = !empty($a['status']) ? sanitize_key($a['status']) : 'unknown';
            $is_ready   = ($status === 'ready');
            $duration   = !empty($a['duration']) ? self::format_timecode((float) $a['duration']) : '—';
            $creator_id = $a['creator_id']  ? esc_html($a['creator_id'])  : '—';
            $external   = $a['external_id'] ? esc_html($a['external_id']) : '—';

            $current_client_id = 0;
            foreach ($client_vod_map as $cid => $assigned_asset) {
                if ($assigned_asset === $a['id']) { $current_client_id = (int) $cid; break; }
            }

            $pid   = self::first_public_playback_id_local($a);
            $thumb = ($pid && $is_ready) ? esc_url(add_query_arg(['width'=>320,'height'=>180,'time'=>2,'fit_mode'=>'smartcrop'], "https://image.mux.com/$pid/thumbnail.jpg")) : '';

            echo '<tr>';
            echo '<td style="font-family:monospace">'.esc_html($a['id']).'</td>';
            echo '<td>'.esc_html($created).'</td>';
            echo '<td>'.$title.'</td>';
            echo '<td><span class="kcfh-vod-status kcfh-vod-status--'.esc_attr($status).'">'.esc_html(ucfirst($status)).'</span></td>';
            echo '<td><code>'.esc_html($duration).'</code></td>';
            echo '<td>'.$creator_id.'</td>';
            echo '<td>'.$external.'</td>';
            echo '<td>'.($thumb ? '<img src="'.$thumb.'" width="160" height="90" style="border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.12)">' : '—').'</td>';

            // Assign column
            echo '<td>';
            echo '<form method="post" action="' . esc_url( admin_url('admin-post.php?action=kcfh_assign_vod') ) . '">';
            wp_nonce_field( 'kcfh_assign_vod_' . $a['id'], 'kcfh_nonce' );
            echo '<input type="hidden" name="action" value="kcfh_assign_vod">';
            echo '<input type="hidden" name="asset_id" value="'.esc_attr($a['id']).'">';
            echo '<select name="client_id"'.($is_ready ? '' : ' disabled').'>';
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
            if ($is_ready) {
                submit_button('Save', 'secondary', '', false);
            } else {
                echo '<span class="description">Available after processing</span>';
            }
            echo '</form>';
            echo '</td>';

            echo '<td>';
            if ($is_ready && $pid) {
                $editor_url = add_query_arg([
                    'page' => 'kcfh_vod_manager',
                    'asset_id' => $a['id'],
                ], admin_url('admin.php'));
                echo '<a class="button button-primary" href="'.esc_url($editor_url).'">View / Trim</a>';
            } elseif ($status === 'preparing') {
                echo '<span class="description">Processing trimmed video…</span>';
            } else {
                echo '—';
            }
            echo '</td>';

            //Admin_Util::DownloadVOD($a);

            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_editor(string $asset_id): void {
        $asset = Asset_Service::get_asset_raw($asset_id);

        echo '<div class="wrap kcfh-vod-editor">';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=kcfh_vod_manager')).'">&larr; Back to VOD Manager</a></p>';

        if (is_wp_error($asset)) {
            echo '<div class="notice notice-error"><p>'.esc_html($asset->get_error_message()).'</p></div></div>';
            return;
        }

        $playback_id = Asset_Service::first_public_playback_id_from_raw($asset);
        $status = isset($asset['status']) ? sanitize_key($asset['status']) : 'unknown';
        $duration = isset($asset['duration']) ? (float) $asset['duration'] : 0;
        $asset_meta = isset($asset['meta']) && is_array($asset['meta']) ? $asset['meta'] : [];
        $title = !empty($asset_meta['title'])
            ? sanitize_text_field($asset_meta['title'])
            : (!empty($asset['title']) ? sanitize_text_field($asset['title']) : 'Video');

        echo '<h1>View and Trim VOD</h1>';
        echo '<p><strong>'.esc_html($title).'</strong><br><code>'.esc_html($asset_id).'</code></p>';

        if ($status !== 'ready' || !$playback_id || $duration <= 0) {
            echo '<div class="notice notice-warning inline"><p>This Mux asset is not ready to preview or trim yet. Current status: <strong>'.esc_html($status).'</strong>.</p></div></div>';
            return;
        }

        $suggested_title = $title . ' – trimmed';

        ?>
        <style>
          .kcfh-vod-editor-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(300px,1fr);gap:24px;max-width:1200px}
          .kcfh-vod-editor-player{background:#111;border-radius:10px;overflow:hidden}
          .kcfh-vod-editor-player mux-player{display:block;width:100%;aspect-ratio:16/9}
          .kcfh-vod-editor-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;height:max-content}
          .kcfh-vod-editor-field{margin:0 0 18px}
          .kcfh-vod-editor-field label{display:block;font-weight:600;margin-bottom:6px}
          .kcfh-vod-editor-field input[type="text"]{width:100%}
          .kcfh-vod-editor-time-row{display:grid;grid-template-columns:1fr auto;gap:8px}
          .kcfh-vod-editor-help{color:#646970;font-size:12px;margin-top:5px}
          .kcfh-vod-editor-actions{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 18px}
          .kcfh-vod-timeline-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin-top:14px}
          .kcfh-vod-timeline-heading{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}
          .kcfh-vod-timeline-heading strong{font-size:14px}
          .kcfh-vod-selected-duration{color:#50575e;font-variant-numeric:tabular-nums}
          .kcfh-vod-trim-track-wrap{position:relative;height:42px;touch-action:none}
          .kcfh-vod-trim-track{position:absolute;left:0;right:0;top:17px;height:8px;border-radius:999px;background:#dcdcde;cursor:pointer}
          .kcfh-vod-trim-selection{position:absolute;top:0;bottom:0;border-radius:999px;background:#3858e9;pointer-events:none}
          .kcfh-vod-range{position:absolute;left:0;top:0;width:100%;height:42px;margin:0;background:transparent;appearance:none;-webkit-appearance:none;pointer-events:none;outline:none}
          .kcfh-vod-range::-webkit-slider-runnable-track{height:8px;background:transparent;border:0}
          .kcfh-vod-range::-webkit-slider-thumb{width:18px;height:30px;margin-top:-11px;border:3px solid #fff;border-radius:7px;box-shadow:0 1px 5px rgba(0,0,0,.35);appearance:none;-webkit-appearance:none;pointer-events:auto;cursor:ew-resize}
          .kcfh-vod-range::-moz-range-track{height:8px;background:transparent;border:0}
          .kcfh-vod-range::-moz-range-thumb{width:14px;height:26px;border:3px solid #fff;border-radius:7px;box-shadow:0 1px 5px rgba(0,0,0,.35);pointer-events:auto;cursor:ew-resize}
          .kcfh-vod-range--start::-webkit-slider-thumb{background:#72cf19}
          .kcfh-vod-range--start::-moz-range-thumb{background:#72cf19}
          .kcfh-vod-range--end::-webkit-slider-thumb{background:#ed2525}
          .kcfh-vod-range--end::-moz-range-thumb{background:#ed2525}
          .kcfh-vod-range:focus-visible::-webkit-slider-thumb{box-shadow:0 0 0 3px rgba(56,88,233,.3),0 1px 5px rgba(0,0,0,.35)}
          .kcfh-vod-range:focus-visible::-moz-range-thumb{box-shadow:0 0 0 3px rgba(56,88,233,.3),0 1px 5px rgba(0,0,0,.35)}
          .kcfh-vod-timeline-labels{display:flex;justify-content:space-between;gap:12px;margin-top:4px;font-size:12px;font-weight:600;font-variant-numeric:tabular-nums}
          .kcfh-vod-start-label{color:#398000}
          .kcfh-vod-end-label{color:#b21818;text-align:right}
          .kcfh-vod-timeline-help{margin:8px 0 0;color:#646970;font-size:12px}
          @media (max-width:900px){.kcfh-vod-editor-layout{grid-template-columns:1fr}}
        </style>
        <script type="module" src="https://cdn.jsdelivr.net/npm/@mux/mux-player"></script>
        <script src="<?php echo esc_url(KCFH_STREAMING_URL . 'assets/vod-editor.js'); ?>?ver=<?php echo esc_attr(KCFH_STREAMING_VERSION); ?>" defer></script>

        <div class="kcfh-vod-editor-layout">
          <div>
            <div class="kcfh-vod-editor-player">
              <mux-player
                id="kcfhVodEditorPlayer"
                playback-id="<?php echo esc_attr($playback_id); ?>"
                stream-type="on-demand"
                metadata-video-title="<?php echo esc_attr($title); ?>"
              ></mux-player>
            </div>

            <div class="kcfh-vod-timeline-panel">
              <div class="kcfh-vod-timeline-heading">
                <strong>Trim selection</strong>
                <span class="kcfh-vod-selected-duration">Selected: <span id="kcfhSelectedDuration"><?php echo esc_html(self::format_timecode($duration)); ?></span></span>
              </div>

              <div class="kcfh-vod-trim-track-wrap" id="kcfhTrimTimeline" data-duration="<?php echo esc_attr($duration); ?>">
                <div class="kcfh-vod-trim-track" id="kcfhTrimTrack" aria-hidden="true">
                  <div class="kcfh-vod-trim-selection" id="kcfhTrimSelection"></div>
                </div>

                <input
                  type="range"
                  class="kcfh-vod-range kcfh-vod-range--start"
                  id="kcfhTrimStartHandle"
                  min="0"
                  max="<?php echo esc_attr($duration); ?>"
                  step="0.1"
                  value="0"
                  aria-label="Trim start position"
                >

                <input
                  type="range"
                  class="kcfh-vod-range kcfh-vod-range--end"
                  id="kcfhTrimEndHandle"
                  min="0"
                  max="<?php echo esc_attr($duration); ?>"
                  step="0.1"
                  value="<?php echo esc_attr($duration); ?>"
                  aria-label="Trim end position"
                >
              </div>

              <div class="kcfh-vod-timeline-labels">
                <span class="kcfh-vod-start-label">Start: <span id="kcfhTrimStartLabel">00:00:00.000</span></span>
                <span class="kcfh-vod-end-label">End: <span id="kcfhTrimEndLabel"><?php echo esc_html(self::format_timecode($duration)); ?></span></span>
              </div>

              <p class="kcfh-vod-timeline-help">Drag the green and red handles, or click the timeline to move the nearest handle. Arrow keys make precise adjustments when a handle is selected.</p>
            </div>

            <div class="kcfh-vod-editor-actions">
              <button type="button" class="button" id="kcfhSetClipStart">Set start to current position</button>
              <button type="button" class="button" id="kcfhSetClipEnd">Set end to current position</button>
              <button type="button" class="button button-primary" id="kcfhPreviewClip">&#9654; Play trimmed preview</button>
            </div>
          </div>

          <div class="kcfh-vod-editor-panel">
            <h2 style="margin-top:0">Create trimmed version</h2>
            <p>This keeps the original video and asks Mux to create a new, frame-accurate VOD.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="kcfhVodTrimForm">
              <?php wp_nonce_field('kcfh_create_vod_clip_' . $asset_id); ?>
              <input type="hidden" name="action" value="kcfh_create_vod_clip">
              <input type="hidden" name="asset_id" value="<?php echo esc_attr($asset_id); ?>">

              <div class="kcfh-vod-editor-field">
                <label for="kcfhClipTitle">New VOD title</label>
                <input type="text" id="kcfhClipTitle" name="clip_title" value="<?php echo esc_attr($suggested_title); ?>" maxlength="512" required>
              </div>

              <div class="kcfh-vod-editor-field">
                <label for="kcfhClipStart">Start time</label>
                <div class="kcfh-vod-editor-time-row">
                  <input type="text" id="kcfhClipStart" name="clip_start" value="00:00:00.000" inputmode="decimal" required>
                  <button type="button" class="button" data-seek-to="start">Go</button>
                </div>
                <div class="kcfh-vod-editor-help">Use HH:MM:SS, for example 00:03:15.500.</div>
              </div>

              <div class="kcfh-vod-editor-field">
                <label for="kcfhClipEnd">End time</label>
                <div class="kcfh-vod-editor-time-row">
                  <input type="text" id="kcfhClipEnd" name="clip_end" value="<?php echo esc_attr(self::format_timecode($duration)); ?>" inputmode="decimal" required>
                  <button type="button" class="button" data-seek-to="end">Go</button>
                </div>
                <div class="kcfh-vod-editor-help">Original duration: <?php echo esc_html(self::format_timecode($duration)); ?></div>
              </div>

              <?php submit_button('Create Trimmed VOD', 'primary', 'submit', false); ?>
            </form>
          </div>
        </div>
        <?php
        echo '</div>';
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
        if (!current_user_can('kcfh_streaming_access')) wp_die('Nope');
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
        if (!current_user_can('kcfh_streaming_access')) wp_die('Nope');
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

    public static function handle_create_clip(): void {
        if (!current_user_can('kcfh_streaming_access')) wp_die('Permission denied');

        $asset_id = isset($_POST['asset_id'])
            ? sanitize_text_field(wp_unslash($_POST['asset_id']))
            : '';
        if ($asset_id === '') wp_die('Missing asset_id');

        check_admin_referer('kcfh_create_vod_clip_' . $asset_id);

        $start_value = isset($_POST['clip_start']) ? sanitize_text_field(wp_unslash($_POST['clip_start'])) : '';
        $end_value = isset($_POST['clip_end']) ? sanitize_text_field(wp_unslash($_POST['clip_end'])) : '';
        $clip_title = isset($_POST['clip_title']) ? sanitize_text_field(wp_unslash($_POST['clip_title'])) : 'Trimmed video';

        $start_time = self::parse_timecode($start_value);
        $end_time = self::parse_timecode($end_value);

        if ($start_time === null || $end_time === null || $start_time < 0 || ($end_time - $start_time) < 0.5) {
            self::redirect_to_editor($asset_id, 'clip_error', 'Enter a valid start and end time. The trimmed video must be at least 0.5 seconds long.');
        }

        $source_asset = Asset_Service::get_asset_raw($asset_id);
        if (is_wp_error($source_asset)) {
            self::redirect_to_editor($asset_id, 'clip_error', $source_asset->get_error_message());
        }

        $source_duration = isset($source_asset['duration']) ? (float) $source_asset['duration'] : 0;
        if ($source_duration <= 0 || $end_time > ($source_duration + 0.05)) {
            self::redirect_to_editor($asset_id, 'clip_error', 'The end time is beyond the original video duration.');
        }

        $video_quality = isset($source_asset['video_quality'])
            ? sanitize_key($source_asset['video_quality'])
            : 'basic';

        $new_asset = Asset_Service::create_asset_clip(
            $asset_id,
            $start_time,
            $end_time,
            $clip_title,
            $video_quality
        );

        if (is_wp_error($new_asset)) {
            self::redirect_to_editor($asset_id, 'clip_error', $new_asset->get_error_message());
        }

        $new_asset_id = isset($new_asset['id']) ? sanitize_text_field($new_asset['id']) : '';
        Notices::redirect_vod(
            'clip_created',
            'The trimmed VOD has been created and is processing in Mux. Its Asset ID is <code>'
            . esc_html($new_asset_id)
            . '</code>. Refresh the list shortly, then assign the trimmed version to the client.'
        );
    }

    private static function parse_timecode(string $value): ?float {
        $value = trim($value);
        if ($value === '') return null;

        if (is_numeric($value)) {
            return (float) $value;
        }

        $parts = explode(':', $value);
        if (count($parts) < 2 || count($parts) > 3) return null;

        $parts = array_map('trim', $parts);
        if (count($parts) === 2) {
            [$minutes, $seconds] = $parts;
            $hours = '0';
        } else {
            [$hours, $minutes, $seconds] = $parts;
        }

        if (!ctype_digit($hours) || !ctype_digit($minutes) || !is_numeric($seconds)) return null;

        $minutes_number = (int) $minutes;
        $seconds_number = (float) $seconds;
        if ($minutes_number > 59 || $seconds_number < 0 || $seconds_number >= 60) return null;

        return ((int) $hours * 3600) + ($minutes_number * 60) + $seconds_number;
    }

    private static function format_timecode(float $seconds): string {
        $seconds = max(0, $seconds);
        $whole_seconds = (int) floor($seconds);
        $milliseconds = (int) round(($seconds - $whole_seconds) * 1000);

        if ($milliseconds === 1000) {
            $whole_seconds++;
            $milliseconds = 0;
        }

        $hours = intdiv($whole_seconds, 3600);
        $minutes = intdiv($whole_seconds % 3600, 60);
        $remaining_seconds = $whole_seconds % 60;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $remaining_seconds, $milliseconds);
    }

    private static function redirect_to_editor(string $asset_id, string $notice, string $message): void {
        $url = add_query_arg([
            'page' => 'kcfh_vod_manager',
            'asset_id' => $asset_id,
            'kcfh_notice' => $notice,
            'kcfh_msg' => $message,
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }
}

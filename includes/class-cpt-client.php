<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class CPT_Client {

  const POST_TYPE = 'kcfh_client';
  const META_START_AT = '_kcfh_live_start_at'; // UTC timestamp
  const META_END_AT   = '_kcfh_live_end_at';   // UTC timestamp

  public static function init() {
    add_action('init', [__CLASS__, 'register']);
    add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
    add_action('save_post_' . self::POST_TYPE, [__CLASS__, 'save_meta']);
  }

  public static function register() {
    register_post_type(self::POST_TYPE, [
      'label' => 'Clients',
      'labels' => [
        'name' => 'Clients',
        'singular_name' => 'Client',
        'add_new_item' => 'Add New Client',
        'edit_item' => 'Edit Client',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => false, // we’ll put it under our own menu
      'supports' => ['title', 'thumbnail'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public static function meta_boxes() {
    //Main details box
    add_meta_box(
      'kcfh_client_details', 
      'Client Details', 
      [__CLASS__, 'render_meta'],
      self::POST_TYPE, 
      'normal', 
      'high');

      add_meta_box(
      'kcfh_schedule_debug',
      'Schedule Debug',
      function($post){
        $startUtc = (int) get_post_meta($post->ID, \KCFH\Streaming\CPT_Client::META_START_AT, true);
        $endUtc   = (int) get_post_meta($post->ID, \KCFH\Streaming\CPT_Client::META_END_AT, true);

        $nextStart = wp_next_scheduled(\KCFH\Streaming\Live_Scheduler::HOOK_START, [$post->ID]);
        $nextEnd   = wp_next_scheduled(\KCFH\Streaming\Live_Scheduler::HOOK_END,   [$post->ID]);

        echo '<p><strong>Stored UTC</strong><br>';
        echo 'Start: '.($startUtc ? date_i18n('Y-m-d H:i:s', $startUtc) : '—').' UTC<br>';
        echo 'End: '.($endUtc ? date_i18n('Y-m-d H:i:s', $endUtc) : '—').' UTC</p>';

        echo '<p><strong>Next scheduled</strong><br>';
        echo 'Start: '.($nextStart ? date_i18n('Y-m-d H:i:s', $nextStart) : '—').' (site time)<br>';
        echo 'End: '.($nextEnd ? date_i18n('Y-m-d H:i:s', $nextEnd) : '—').' (site time)</p>';

        $live = (int) get_option(\KCFH\Streaming\Admin_UI::OPT_LIVE_CLIENT, 0);
        echo '<p><strong>Current Live Client:</strong> '.($live ?: 'none').' </p>';
      },
      self::POST_TYPE,
      'side',
      'low'
    );

  }

  public static function render_meta($post) {
    wp_nonce_field('kcfh_client_save', 'kcfh_client_nonce');




    $asset_id = get_post_meta($post->ID, '_kcfh_asset_id', true);
    
    
    ?>

    <style>
      .kcfh-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:800px}
    </style>

    <div class="kcfh-grid">
      <?php 
        self::render_client_details($post);
        self::render_stream_timeframe_metabox($post); 
      ?>

      
  </br>




<?php
  // Current selection
  $current_asset_id = get_post_meta($post->ID, '_kcfh_asset_id', true);

  // Pull recent ready assets from Mux (for labels)
  $result = \KCFH\Streaming\Asset_Service::fetch_assets([
    'limit'     => 100,
    'order'     => 'created_at',
    'direction' => 'desc',
    'status'    => 'ready',
  ], 60);

  $assets = (!is_wp_error($result) && !empty($result['assets'])) ? $result['assets'] : [];

  // Map of asset_id => client_id for already-assigned assets
  $assigned_ids = [];
  $assigned_posts = get_posts([
    'post_type'      => self::POST_TYPE,
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
    'meta_query'     => [[ 'key' => '_kcfh_asset_id', 'compare' => 'EXISTS' ]],
  ]);
  foreach ($assigned_posts as $cid) {
    $aid = get_post_meta($cid, '_kcfh_asset_id', true);
    if ($aid) $assigned_ids[$aid] = (int)$cid;
  }
?>

<p>
  <label for="kcfh_asset_id">Primary VOD Asset</label><br>
  <select id="kcfh_asset_id" name="kcfh_asset_id" class="widefat">
    <option value="">— Unassigned —</option>
    <?php if (empty($assets)): ?>
      <!-- No assets (or no creds). Keep just the Unassigned option. -->
    <?php else: ?>
      <?php foreach ($assets as $a):
        $aid   = esc_attr($a['id']);
        $title = !empty($a['title']) ? $a['title']
                : (!empty($a['passthrough']) ? $a['passthrough'] : ('Asset ' . $a['id']));
        $label = esc_html($title);
        $selected = selected($current_asset_id, $a['id'], false);
        $assigned_elsewhere = isset($assigned_ids[$a['id']]) && ($assigned_ids[$a['id']] !== (int)$post->ID);
        if ($assigned_elsewhere) continue; // hide assets already tied to other clients
      ?>
        <option value="<?= $aid ?>" <?= $selected ?>><?= $label ?></option>
      <?php endforeach; ?>
    <?php endif; ?>
  </select>
  <br><small>Select a Mux asset by name; we’ll save the Asset ID and pull playback/meta automatically.</small>
</p>
    </div>

    <p><small>Use the Featured Image box for the profile photo.</small></p>
    <?php

    $playback   = get_post_meta($post->ID, '_kcfh_playback_id', true);
    $vod_title  = get_post_meta($post->ID, '_kcfh_vod_title', true);
    $ext_id     = get_post_meta($post->ID, '_kcfh_external_id', true);

    ?>
    <hr>
    <h3>Assigned VOD2</h3>
    <p>
      <label>
        VOD Title<br>
        <input type="text" value="<?= esc_attr($vod_title); ?>" class="regular-text" disabled>
      </label>
    </p>
    <p>
      <label>
        Playback ID (for player)<br>
        <input type="text" value="<?= esc_attr($playback) ?>" class="regular-text" disabled>
      </label>
    </p>
    <p>
      <label>
        External ID (Mux meta)<br>
        <input type="text" value="<?= esc_attr($ext_id) ?>" class="regular-text" disabled>
      </label>
    </p>
    <?php

    if ($playback) {
        $thumb = esc_url(add_query_arg(['width'=>480,'height'=>270,'time'=>2,'fit_mode'=>'smartcrop'], "https://image.mux.com/$playback/thumbnail.jpg"));
        echo '<p><img src="'.$thumb.'" width="240" height="135" style="border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.12)"></p>';
    }

  }

  public static function render_stream_timeframe_metabox($post) {
    wp_nonce_field('kcfh_stream_window', 'kcfh_stream_window_nonce');

    $startUtc = (int) get_post_meta($post->ID, self::META_START_AT, true);
    $endUtc   = (int) get_post_meta($post->ID, self::META_END_AT, true);

    // Convert stored UTC -> site local string for <input type="datetime-local">
    $startLocal = self::utc_to_local_input_value($startUtc);
    $endLocal   = self::utc_to_local_input_value($endUtc);
    ?>
    <p><label for="kcfh_live_start_local"><strong><?php _e('Stream start (local)', 'kcfh'); ?></strong></label>
    <input type="datetime-local" id="kcfh_live_start_local" name="kcfh_live_start_local" value="<?php echo esc_attr($startLocal); ?>" class="widefat"></p>

    <p><label for="kcfh_live_end_local"><strong><?php _e('Stream end (local)', 'kcfh'); ?></strong></label>
    <input type="datetime-local" id="kcfh_live_end_local" name="kcfh_live_end_local" value="<?php echo esc_attr($endLocal); ?>" class="widefat"></p>

    <p class="description">
        <?php _e('When within this window, this client will be marked Live automatically.', 'kcfh'); ?>
    </p>
    <?php
  }

  public static function render_client_details($post){
    $dob  = get_post_meta($post->ID, '_kcfh_dob', true);
    $dod  = get_post_meta($post->ID, '_kcfh_dod', true);

    ?>
      <p>
        <label for="kcfh_dob">Date of Birth</label><br>
        <input type="date" id="kcfh_dob" name="kcfh_dob" value="<?= esc_attr($dob); ?>">
      </p>

      <p>
        <label for="kcfh_dod">Date of Death</label><br>
        <input type="date" id="kcfh_dod" name="kcfh_dod" value="<?= esc_attr($dod); ?>">
      </p>

    <?php
  }

  public static function save_meta($post_id) {
    if (!isset($_POST['kcfh_client_nonce']) || !wp_verify_nonce($_POST['kcfh_client_nonce'], 'kcfh_client_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

    $dob = isset($_POST['kcfh_dob']) ? sanitize_text_field($_POST['kcfh_dob']) : '';
    $dod = isset($_POST['kcfh_dod']) ? sanitize_text_field($_POST['kcfh_dod']) : '';
    update_post_meta($post_id, '_kcfh_dob', $dob);
    update_post_meta($post_id, '_kcfh_dod', $dod);

    // VOD dropdown selection
    $new_asset_id = isset($_POST['kcfh_asset_id']) ? sanitize_text_field($_POST['kcfh_asset_id']) : '';
    $old_asset_id = get_post_meta($post_id, '_kcfh_asset_id', true);

  if ($new_asset_id === '') {

      // Unassign
      delete_post_meta($post_id, '_kcfh_asset_id');
      delete_post_meta($post_id, '_kcfh_playback_id');
      delete_post_meta($post_id, '_kcfh_vod_title');
      delete_post_meta($post_id, '_kcfh_external_id');
    } elseif ($new_asset_id !== $old_asset_id) {
      // Make it exclusive: unassign this asset from other clients
      $others = get_posts([
        'post_type'      => self::POST_TYPE,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'post__not_in'   => [$post_id],
        'meta_query'     => [[ 'key' => '_kcfh_asset_id', 'value' => $new_asset_id, 'compare' => '=' ]],
      ]);
      foreach ($others as $oid) {
        delete_post_meta($oid, '_kcfh_asset_id');
        delete_post_meta($oid, '_kcfh_playback_id');
        delete_post_meta($oid, '_kcfh_vod_title');
        delete_post_meta($oid, '_kcfh_external_id');
      }

      // Fetch asset details from Mux for playback/meta
      $asset = \KCFH\Streaming\Asset_Service::get_asset($new_asset_id, 120);
      if (!is_wp_error($asset)) {
        $playback = \KCFH\Streaming\Asset_Service::first_public_playback_id($asset);
        $title    = !empty($asset['title']) ? $asset['title']
                  : (!empty($asset['passthrough']) ? $asset['passthrough'] : '');
        $ext_id   = !empty($asset['external_id']) ? $asset['external_id'] : '';

        update_post_meta($post_id, '_kcfh_asset_id',     $new_asset_id);
        if ($playback) update_post_meta($post_id, '_kcfh_playback_id', $playback);
        update_post_meta($post_id, '_kcfh_vod_title',   sanitize_text_field($title));
        update_post_meta($post_id, '_kcfh_external_id', sanitize_text_field($ext_id));
      } else {
        // Save at least the chosen ID; you can refresh meta later
        update_post_meta($post_id, '_kcfh_asset_id', $new_asset_id);
      }
    }


    // Handle time saving
    $startLocal = isset($_POST['kcfh_live_start_local']) ? sanitize_text_field($_POST['kcfh_live_start_local']) : '';
    $endLocal   = isset($_POST['kcfh_live_end_local'])   ? sanitize_text_field($_POST['kcfh_live_end_local'])   : '';

    $startUtc = self::local_input_to_utc_ts($startLocal);
    $endUtc   = self::local_input_to_utc_ts($endLocal);

    // Basic sanity: clear if invalid or end <= start
    if ($startUtc && $endUtc && $endUtc <= $startUtc) {
      $startUtc = $endUtc = 0;
    }

    update_post_meta($post_id, self::META_START_AT, $startUtc);
    update_post_meta($post_id, self::META_END_AT,   $endUtc);

    // (Re)Schedule start/end one-offs
    \KCFH\Streaming\Live_Scheduler::reschedule_for_client($post_id, $startUtc, $endUtc);

    // Immediate enforcement if we’re already at/after start
    $now = time();
    $in_window =
        ($startUtc && $now >= $startUtc) &&
        (!$endUtc || $now < $endUtc); // end optional

    if ($in_window) {
        \KCFH\Streaming\Live_Scheduler::set_live_client($post_id);
    } else {
        $liveId  = (int) get_option(\KCFH\Streaming\Admin_UI::OPT_LIVE_CLIENT, 0);
        $outside =
            ($startUtc && $endUtc && $now >= $endUtc) || // past end
            ($startUtc && !$endUtc && $now < $startUtc) || // start-only but not started yet
            (!$startUtc); // no schedule

        if ($liveId === (int) $post_id && $outside) {
            \KCFH\Streaming\Live_Scheduler::unset_live_if_matches($post_id);
        }
    }


  }



  private static function utc_to_local_input_value($utcTs) {
    if (!$utcTs) return '';
    $tz = wp_timezone();
    $dt = new \DateTime('@' . $utcTs);  // <-- fully qualified
    $dt->setTimezone($tz);
    return $dt->format('Y-m-d\TH:i');
  }


  private static function local_input_to_utc_ts($input) {
    if (!$input) return 0;
    try {
        $tz    = wp_timezone(); // uses Settings → General timezone (e.g., Pacific/Auckland)
        $local = \DateTime::createFromFormat('Y-m-d\TH:i', $input, $tz);
        if (!$local) return 0;

        // Move to UTC and return Unix timestamp
        $local->setTimezone(new \DateTimeZone('UTC'));
        return (int) $local->format('U');
    } catch (\Throwable $e) {
        error_log('[KCFH] local_input_to_utc_ts error: ' . $e->getMessage());
        return 0;
    }
}

}


 /* new assingment
  if ($new_asset_id !== $old_asset_id) {
    $res = \KCFH\Streaming\Core::assign_vod_to_client($post_id, $new_asset_id);
    if (is_wp_error($res)) {
      error_log('[KCFH] VOD assign via client save failed: ' . $res->get_error_message());
    }
  }
*/

/* old assignment



*/


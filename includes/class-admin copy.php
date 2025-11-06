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
    //register UI
    add_action('admin_menu', [__CLASS__, 'register_menus']);


    //requests adn functions
    add_action('admin_post_kcfh_set_live',           [__CLASS__, 'handle_set_live']);
    add_action('admin_post_kcfh_save_live_settings', [__CLASS__, 'handle_save_live_settings']);
    add_action('admin_post_kcfh_assign_vod',         ['KCFH\Streaming\Utility_Admin', 'handle_assign_vod']);
    add_action('admin_post_kcfh_set_reconnect_window', [__CLASS__, 'handle_set_reconnect_window']);
    add_action('admin_post_kcfh_enable_mp4',  [__CLASS__, 'handle_enable_mp4']);
    add_action('admin_post_kcfh_download_mp4', [__CLASS__, 'handle_download_mp4']);


  }
  public static function register_menus() {
    echo 'register Menus';
    add_menu_page(
        'KCFH Streaming', 
        'KCFH Streaming', 
        'manage_options', 
        'kcfh_streaming', 
        [__CLASS__, 
        'render_dashboard'], 
        'dashicons-video-alt3', 
        25);
    add_submenu_page(
        'kcfh_streaming', 
        'Dashboard', 
        'Dashboard', 
        'manage_options', 
        'kcfh_streaming', 
        [__CLASS__, 
        'render_dashboard']);
    add_submenu_page(
        'kcfh_streaming', 
        'VOD Manager', 
        'VOD Manager', 
        'manage_options', 
        'kcfh_vod_manager', 
        [__CLASS__, 
        'render_vod_manager']);
    add_submenu_page(
        'kcfh_streaming', 
        'Live Settings', 
        'Live Settings', 
        'manage_options', 
        'kcfh_live_settings', 
        [__CLASS__, 
        'render_live_settings']);


    // native WP list table for clients
    add_submenu_page(
        'kcfh_streaming',
        'All Clients',
        'All Clients',
        'edit_posts', // or 'manage_options' if you want admin-only
        'edit.php?post_type=' . \KCFH\Streaming\CPT_Client::POST_TYPE);

    // native WP "Add New" screen
    add_submenu_page(
        'kcfh_streaming',
        'Add New Client',
        'Add New Client',
        'edit_posts', // or 'manage_options'
        'post-new.php?post_type=' . \KCFH\Streaming\CPT_Client::POST_TYPE);



    // Actions

    //add_action('admin_post_kcfh_set_live', [__CLASS__, 'handle_set_live']);
    //add_action('admin_post_kcfh_assign_vod', [__CLASS__, 'handle_assign_vod']);
    //add_action('admin_post_kcfh_save_live_settings', [__CLASS__, 'handle_save_live_settings']);

  }

  /** ---------- Dashboard: Clients list ---------- */
  public static function render_dashboard() {
    if (!current_user_can('manage_options')) wp_die('Nope');

    $live_id = (int) get_option(self::OPT_LIVE_CLIENT, 0);

    $clients = get_posts([
      'post_type' => CPT_Client::POST_TYPE,
      'posts_per_page' => 50,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);
    ?>

    <div class="wrap"><h1>KCFH Streaming — Dashboard</h1>
    <a href="<?php esc_url(admin_url('post-new.php?post_type=' . CPT_Client::POST_TYPE)) ?>" class="page-title-action">Add New Client</a>

    <p>Manage clients and set which one is currently <strong>Live Now</strong>.</p>

    <table class="widefat striped"><thead><tr>
    <th>ID</th>
    <th>Name</th>
    <th>Created</th>
    <th>DOB</th>
    <th>DOD</th>
    <?php // <th>Asset ID</th> ?>
    <?php //<th>Image</th> ?>
    <th>Live</th>
    <th>Actions</th>
    </tr></thead><tbody>
<?php
    foreach ($clients as $p) {
      $dob = get_post_meta($p->ID, '_kcfh_dob', true);
      $dod = get_post_meta($p->ID, '_kcfh_dod', true);
      $asset = get_post_meta($p->ID, '_kcfh_asset_id', true);
      $thumb = get_the_post_thumbnail($p->ID, [60,60], ['style'=>'border-radius:6px']) ?: '';

      $is_live = ($p->ID === $live_id);
      $live_badge = $is_live ? '<span style="color:#0a0;font-weight:600;">Live Now</span>' : '—';

      $edit_link = get_edit_post_link($p->ID, '');
      $nonce = wp_create_nonce('kcfh_set_live_'.$p->ID);

      $toggle_url = admin_url('admin-post.php?action=kcfh_set_live&client_id='.$p->ID.'&_wpnonce='.$nonce);
      $unset_url  = admin_url('admin-post.php?action=kcfh_set_live&client_id=0&_wpnonce='.wp_create_nonce('kcfh_set_live_0'));

      echo '<tr>';
      echo '<td>'.esc_html($p->ID).'</td>';
      echo '<td>'.esc_html(get_the_title($p)).'</td>';
      echo '<td>'.esc_html(get_the_date('', $p)).'</td>';
      echo '<td>'.esc_html($dob).'</td>';
      echo '<td>'.esc_html($dod).'</td>';
      //echo '<td style="font-family:monospace">'.esc_html($asset).'</td>';
      //echo '<td>'.$thumb.'</td>';
      echo '<td>'.$live_badge.'</td>';
      echo '<td>';
      echo '<a href="'.esc_url($edit_link).'">Edit</a> • ';
      if ($is_live) {
        echo '<a href="'.esc_url($unset_url).'">Unset Live</a>';
      } else {
        echo '<a href="'.esc_url($toggle_url).'">Set Live</a>';
      }
      echo '</td>';
      echo '</tr>';
    }

    echo '</tbody></table></div>';
  }

  public static function handle_set_live() {
    if (!current_user_can('manage_options')) wp_die('Nope');
    $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
    check_admin_referer('kcfh_set_live_'.$client_id);

    // Only one live: store the chosen ID (0 unsets)
    update_option(self::OPT_LIVE_CLIENT, $client_id);

    // If you defined KCFH_LIVE_STREAM_ID in wp-config, tag future assets with this client id
    // after you Set Live (e.g., in Admin_UI::handle_set_live(), after updating kcfh_live_client_id)
    $live_stream_id = defined('KCFH_LIVE_STREAM_ID') ? KCFH_LIVE_STREAM_ID : '';
    if ($live_stream_id) {
      // choose your window (e.g., 600s = 10 min)
      $window_seconds = 600;

      $resp = \KCFH\Streaming\Live_Service::update_live_stream($live_stream_id, [
        'reconnect_window' => $window_seconds,
        'use_slate_for_standard_latency' => 'true',
        // optional but very useful: tag future VODs to this client
        'passthrough' => 'client-' . (int)$client_id,
        'new_asset_settings' => [
          'playback_policy' => ['public'],
          'passthrough'     => 'client-' . (int)$client_id,
        ],
        // optional: show an image while disconnected during the window
        // 'reconnect_slate_url' => 'https://your-cdn.example.com/slate.jpg',
      ]);
      if (is_wp_error($resp)) {
        error_log('[KCFH] Mux update_live_stream failed: ' . $resp->get_error_message());
      }
    }


    wp_safe_redirect(admin_url('admin.php?page=kcfh_streaming'));
    exit;
  }

    /** ---------- VOD Manager: list Mux assets + assign ---------- */
public static function render_vod_manager() {
  if (!current_user_can('manage_options')) wp_die('Permission denied');

  // --- Notices (single, consolidated) ---
  $notice = isset($_GET['kcfh_notice']) ? sanitize_text_field($_GET['kcfh_notice']) : '';
  $msg    = isset($_GET['kcfh_msg']) ? wp_kses_post(wp_unslash($_GET['kcfh_msg'])) : '';
  if ($notice) {
    $success_codes = ['assigned','unassigned','mp4_req','mp4_wait'];
    $cls = in_array($notice, $success_codes, true) ? 'success' : 'error';
    echo '<div class="notice notice-' . esc_attr($cls) . '"><p>' . ($msg ? $msg : esc_html($notice)) . '</p></div>';
  }

  // Fetch assets
  $res = Asset_Service::fetch_assets(
    ['limit'=>50, 'order'=>'created_at', 'direction'=>'desc', 'status'=>'ready'],
    30
  );
  echo '<div class="wrap"><h1>VOD Manager</h1>';

  if (is_wp_error($res)) {
    echo '<p style="color:#c00">' . esc_html($res->get_error_message()) . '</p></div>';
    return;
  }

  $assets  = $res['assets'];
  $clients = get_posts([
    'post_type'   => CPT_Client::POST_TYPE,
    'numberposts' => -1,
    'orderby'     => 'title',
    'order'       => 'ASC'
  ]);

  // Build client → assigned asset map
  $client_vod_map = [];
  foreach ($clients as $c) {
    $client_vod_map[$c->ID] = get_post_meta($c->ID, '_kcfh_asset_id', true);
  }

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

    // Who currently holds this asset?
    $current_client_id = 0;
    foreach ($client_vod_map as $cid => $assigned_asset) {
      if ($assigned_asset === $a['id']) { $current_client_id = (int) $cid; break; }
    }

    $pid   = self::first_public_playback_id_local($a);
    $thumb = $pid
      ? esc_url(add_query_arg(
          ['width'=>320,'height'=>180,'time'=>2,'fit_mode'=>'smartcrop'],
          "https://image.mux.com/$pid/thumbnail.jpg"
        ))
      : '';

    echo '<tr>';
    echo '<td style="font-family:monospace">'.esc_html($a['id']).'</td>';
    echo '<td>'.esc_html($created).'</td>';
    echo '<td>'.$title.'</td>';
    echo '<td>'.$creator_id.'</td>';
    echo '<td>'.$external.'</td>';
    echo '<td>'.($thumb ? '<img src="'.$thumb.'" width="160" height="90" style="border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.12)">' : '—').'</td>';

    // -------- Assign to Client column (own <td>) --------
    echo '<td>';
    echo '<form method="post" action="' . esc_url( admin_url('admin-post.php?action=kcfh_assign_vod') ) . '">';
    wp_nonce_field( 'kcfh_assign_vod_' . $a['id'], 'kcfh_nonce' );

    echo '<input type="hidden" name="action" value="kcfh_assign_vod">';
    echo '<input type="hidden" name="asset_id" value="'.esc_attr($a['id']).'">';

    echo '<select name="client_id">';
      // Unassigned option
      $selU = ($current_client_id === 0) ? ' selected' : '';
      echo '<option value="0"'.$selU.'>— Unassigned —</option>';

      // Only clients with NO VOD, plus the one currently assigned to THIS asset
      foreach ($clients as $c) {
        $assigned_asset = isset($client_vod_map[$c->ID]) ? $client_vod_map[$c->ID] : '';
        $is_current     = ($c->ID === $current_client_id);
        if (!$is_current && !empty($assigned_asset)) continue;

        $sel = $is_current ? ' selected' : '';
        echo '<option value="'.esc_attr($c->ID).'"'.$sel.'>'.esc_html(get_the_title($c)).' (#'.$c->ID.')</option>';
      }
    echo '</select> ';
    submit_button('Save', 'secondary', '', false);
    echo '</form>';
    echo '</td>';

    // -------- Download column (separate <td>) --------
    ?>
      <script>
        function SetDownloadQuality(selectEl) {
          var url = selectEl.value;
          if (url) {
            window.location.href = url;
          }
        }
      </script>
    <?php
    $nonce_vod = wp_create_nonce(self::NONCE_VOD_ACTIONS);

    $enable_highest_url = admin_url('admin-post.php?action=kcfh_enable_mp4'
      . '&asset_id=' . rawurlencode($a['id'])
      . '&res=highest&_wpnonce=' . $nonce_vod);

    $enable_1080p_url = admin_url('admin-post.php?action=kcfh_enable_mp4'
      . '&asset_id=' . rawurlencode($a['id'])
      . '&res=1080p&_wpnonce=' . $nonce_vod);

    $enable_720p_url = admin_url('admin-post.php?action=kcfh_enable_mp4'
      . '&asset_id=' . rawurlencode($a['id'])
      . '&res=720p&_wpnonce=' . $nonce_vod);

    $download_url = admin_url('admin-post.php?action=kcfh_download_mp4'
      . '&asset_id=' . rawurlencode($a['id'])
      . '&_wpnonce=' . $nonce_vod);

    ?>


    <td>
      <label>Set Download quality</label>
      <select onchange="SetDownloadQuality(this)">
        <option value="<?php echo esc_url( $enable_highest_url ); ?>">Enable MP4 (Highest)</option>
        <option value="<?php  echo esc_url($enable_1080p_url) ?>">Enable MP4 (1080p)</option>
        <option value="<?php  echo esc_url($enable_720p_url) ?>">Enable MP4 (720p)</option>
      </select>
          <a class="button button-small" href="<?php echo esc_url($download_url) ?>">Download MP4</a>
    </td>
    </tr>
    <?php
  }

  echo '</tbody></table></div>';
}




public static function render_live_settings() {
  if (!current_user_can('manage_options')) wp_die('Nope');
  $live_playback = get_option(self::OPT_LIVE_PLAYBACK, '');
  $live_client   = (int) get_option(self::OPT_LIVE_CLIENT, 0);

  // Read from wp-config (do NOT store these in DB)
  $rtmp_url   = defined('KCFH_LIVE_RTMP_URL')   ? KCFH_LIVE_RTMP_URL   : '';
  $stream_key = defined('KCFH_LIVE_STREAM_KEY') ? KCFH_LIVE_STREAM_KEY : '';
  $stream_id  = defined('KCFH_LIVE_STREAM_ID')  ? KCFH_LIVE_STREAM_ID  : '';

  echo '<div class="wrap"><h1>Live Settings</h1>';

  if (!$rtmp_url || !$stream_key) {
    echo '<div class="notice notice-error"><p><strong>Missing RTMP URL or Stream Key.</strong> Add KCFH_LIVE_RTMP_URL and KCFH_LIVE_STREAM_KEY to <code>wp-config.php</code>.</p></div>';
  }

  // DB-backed: Live Playback ID
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field('kcfh_save_live_settings');
  echo '<input type="hidden" name="action" value="kcfh_save_live_settings">';

  echo '<table class="form-table"><tbody>';

  echo '<tr><th scope="row">Live Playback ID</th><td>';
  echo '<input type="text" name="kcfh_live_playback_id" value="'.esc_attr($live_playback).'" class="regular-text">';
  echo '<p class="description">Playback ID from the <em>Live Stream</em> in Mux (not a VOD asset). This powers the public live player.</p>';
  echo '</td></tr>';

  echo '<tr><th scope="row">Currently Live Client</th><td>';
  echo $live_client ? esc_html(get_the_title($live_client)).' (#'.$live_client.')' : 'None';
  echo '<p class="description">Use “Set Live / Unset Live” on the Dashboard. Only one client can be Live.</p>';
  echo '</td></tr>';

  echo '</tbody></table>';
  submit_button('Save Settings');
  echo '</form>';

  // Read-only Larix setup
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
  echo $stream_id ? '<code>'.esc_html($stream_id).'</code>' : '<em>Optional</em>';
  echo '<p class="description">Optional. Useful for future API automation.</p>';
  echo '</td></tr>';

  echo '</tbody></table>';
  echo '</div>';

  // After your existing settings form
$live_stream_id = defined('KCFH_LIVE_STREAM_ID') ? KCFH_LIVE_STREAM_ID : '';
if ($live_stream_id && class_exists('\KCFH\Streaming\Live_Service')) {
  $ls = \KCFH\Streaming\Live_Service::get_live_stream($live_stream_id);
  if (is_wp_error($ls)) {
    echo '<div class="notice notice-error"><p>Could not fetch Mux live stream: '.esc_html($ls->get_error_message()).'</p></div>';
  } else {
    $latency = !empty($ls['latency_mode']) ? $ls['latency_mode'] : 'standard';
    $reconn  = isset($ls['reconnect_window']) ? (int)$ls['reconnect_window'] : 0;
    echo '<h2>Mux Live Stream</h2><table class="form-table"><tbody>';
    echo '<tr><th>Live Stream ID</th><td><code>'.esc_html($live_stream_id).'</code></td></tr>';
    echo '<tr><th>Latency Mode</th><td><code>'.esc_html($latency).'</code></td></tr>';
    echo '<tr><th>Reconnect Window</th><td><code>'.esc_html($reconn).'</code> seconds</td></tr>';
    echo '</tbody></table>';
  }
}



}

public static function handle_set_reconnect_window() {
  if (!current_user_can('manage_options')) wp_die('Nope');
  check_admin_referer('kcfh_set_reconnect_window');

  $win = isset($_POST['window']) ? (int) $_POST['window'] : 0;
  $win = max(0, min(1800, $win)); // Mux allows 0..1800 seconds

  $live_stream_id = defined('KCFH_LIVE_STREAM_ID') ? KCFH_LIVE_STREAM_ID : '';
  if ($live_stream_id && class_exists('\KCFH\Streaming\Live_Service')) {
    $resp = \KCFH\Streaming\Live_Service::update_live_stream($live_stream_id, [
      'reconnect_window' => $win,
      // (Optional) keep these if you want to also tag future assets to the current live client:
      // 'passthrough' => 'client-' . (int) get_option(self::OPT_LIVE_CLIENT, 0),
      // 'new_asset_settings' => [
      //   'playback_policy' => ['public'],
      //   'passthrough'     => 'client-' . (int) get_option(self::OPT_LIVE_CLIENT, 0),
      // ]
    ]);
    if (is_wp_error($resp)) {
      wp_safe_redirect(admin_url('admin.php?page=kcfh_live_settings&updated=0&kcfh_msg='.rawurlencode($resp->get_error_message())));
      exit;
    }
  }
  wp_safe_redirect(admin_url('admin.php?page=kcfh_live_settings&updated=1'));
  exit;
}




  public static function handle_save_live_settings() {
    if (!current_user_can('manage_options')) wp_die('Nope');
    check_admin_referer('kcfh_save_live_settings');
    $playback = isset($_POST['kcfh_live_playback_id']) ? sanitize_text_field($_POST['kcfh_live_playback_id']) : '';
    update_option(self::OPT_LIVE_PLAYBACK, $playback);
    wp_safe_redirect(admin_url('admin.php?page=kcfh_live_settings'));
    exit;
  }

  /** Helper for local array (avoid calling Asset_Service here again) */
  private static function first_public_playback_id_local(array $asset) {
    if (empty($asset['playback_ids'])) return null;
    foreach ($asset['playback_ids'] as $p) {
      $policy = isset($p['policy']) ? strtolower($p['policy']) : 'public';
      if ($policy === 'public' && !empty($p['id'])) return $p['id'];
    }
    return !empty($asset['playback_ids'][0]['id']) ? $asset['playback_ids'][0]['id'] : null;
  }

  /** Create a static MP4 (highest/1080p) for an asset */
public static function handle_enable_mp4() {
    if (!current_user_can('manage_options')) wp_die('Nope');
    check_admin_referer(self::NONCE_VOD_ACTIONS);

    $asset_id   = isset($_GET['asset_id']) ? sanitize_text_field($_GET['asset_id']) : '';
    $resolution = isset($_GET['res']) ? sanitize_text_field($_GET['res']) : 'highest';
    if (!$asset_id) wp_die('Missing asset_id');

    $res = \KCFH\Streaming\Asset_Service::create_static_rendition($asset_id, $resolution);
    if (is_wp_error($res)) {
        self::redirect_vod_notice('mux_err', 'Mux error: ' . esc_html($res->get_error_message()));
    }

    self::redirect_vod_notice('mp4_req', sprintf('Requested %s MP4. It will appear once processing finishes.', esc_html($resolution)));
}

/** Download static MP4 (if ready). If not ready, request 'highest' and inform user. */
public static function handle_download_mp4() {
    if (!current_user_can('manage_options')) wp_die('Nope');
    check_admin_referer(self::NONCE_VOD_ACTIONS);

    $asset_id = isset($_GET['asset_id']) ? sanitize_text_field($_GET['asset_id']) : '';
    if (!$asset_id) wp_die('Missing asset_id');

    // Get RAW asset so we can see static_renditions + playback_ids
    $raw = \KCFH\Streaming\Asset_Service::get_asset_raw($asset_id);
    if (is_wp_error($raw)) {
        self::redirect_vod_notice('mux_err', 'Mux error: ' . esc_html($raw->get_error_message()));
    }

    $playback_id = \KCFH\Streaming\Asset_Service::first_public_playback_id_from_raw($raw);
    if (!$playback_id) {
        self::redirect_vod_notice('mux_err', 'No public playback ID on this asset.');
    }

    $ready_name = \KCFH\Streaming\Asset_Service::pick_ready_static_name_from_raw($raw);
    if ($ready_name) {
        $save_as = \KCFH\Streaming\Asset_Service::suggest_filename_from_raw($raw);
        $url     = \KCFH\Streaming\Asset_Service::build_static_download_url($playback_id, $ready_name, $save_as);
        wp_redirect($url);
        exit;
    }

    // Not ready yet → request 'highest' (idempotent) and show a friendly notice.
    $req = \KCFH\Streaming\Asset_Service::create_static_rendition($asset_id, 'highest');
    if (is_wp_error($req)) {
        self::redirect_vod_notice('mux_err', 'Mux error: ' . esc_html($req->get_error_message()));
    }

    self::redirect_vod_notice('mp4_wait', 'Preparing the MP4 now. Please click “Download MP4” again once it’s ready.');
}

/** Small redirect helper back to VOD Manager with a notice+message */
private static function redirect_vod_notice(string $code, string $message) {
    $url = add_query_arg([
        'page'        => 'kcfh_vod_manager',
        'kcfh_notice' => $code,
        'kcfh_msg'    => rawurlencode($message),
    ], admin_url('admin.php'));
    wp_safe_redirect($url);
    exit;
}

}

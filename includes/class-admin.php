<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class Admin_UI {
  const OPT_LIVE_CLIENT = 'kcfh_live_client_id';
  const OPT_LIVE_PLAYBACK = 'kcfh_live_playback_id';

  public static function register_menus() {
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
    add_action('admin_post_kcfh_set_live', [__CLASS__, 'handle_set_live']);
    //add_action('admin_post_kcfh_assign_vod', [__CLASS__, 'handle_assign_vod']);

    //assigning outside class functions
    add_action('admin_post_kcfh_assign_vod', ['KCFH\Streaming\Utility_Admin', 'handle_assign_vod']);
    add_action('admin_post_kcfh_save_live_settings', [__CLASS__, 'handle_save_live_settings']);
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

    echo '<div class="wrap"><h1>KCFH Streaming — Dashboard</h1>';
    echo '<a href="' . esc_url(admin_url('post-new.php?post_type=' . CPT_Client::POST_TYPE)) . '" class="page-title-action">Add New Client</a>';

    echo '<p>Manage clients and set which one is currently <strong>Live Now</strong>.</p>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>ID</th><th>Name</th><th>Created</th><th>DOB</th><th>DOD</th><th>Asset ID</th><th>Image</th><th>Live</th><th>Actions</th>';
    echo '</tr></thead><tbody>';

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
      echo '<td style="font-family:monospace">'.esc_html($asset).'</td>';
      echo '<td>'.$thumb.'</td>';
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
    wp_safe_redirect(admin_url('admin.php?page=kcfh_streaming'));
    exit;
  }

    /** ---------- VOD Manager: list Mux assets + assign ---------- */
    public static function render_vod_manager() {

      $notice = isset($_GET['kcfh_notice']) ? sanitize_text_field($_GET['kcfh_notice']) : '';
      if ($notice) {
        echo '<div class="notice notice-' . (in_array($notice, ['assigned','unassigned']) ? 'success' : 'error') . '"><p>';
        echo $notice === 'assigned'   ? 'VOD assigned.' :
            ($notice === 'unassigned' ? 'VOD unassigned.' :
            ($notice === 'nonce'      ? 'Security check failed.' :
            ($notice === 'cap'        ? 'Insufficient permissions.' :
            ($notice === 'login'      ? 'Please log in.' :
            ($notice === 'badreq'     ? 'Bad request.' : '')))));
        echo '</p></div>';
      }

        
        if (!current_user_can('manage_options')) wp_die('Permission denied');

        /*
        $notice = isset($_GET['kcfh_notice']) ? sanitize_text_field($_GET['kcfh_notice']) : '';
        if ($notice) {
            echo '<div class="notice notice-success"><p>';
            echo $notice === 'assigned'   ? 'VOD assigned.' :
                ($notice === 'unassigned' ? 'VOD unassigned.' :
                ($notice === 'nonce'      ? 'Security check failed.' :
                ($notice === 'badreq'     ? 'Bad request.' : '')));
            echo '</p></div>';
        }
            */

        $res = Asset_Service::fetch_assets(['limit'=>50, 'order'=>'created_at', 'direction'=>'desc', 'status'=>'ready'], 30);
        ?>
          <div class="wrap">
            <h1>VOD Manager</h1>
              <?php
              if (is_wp_error($res)) 
                { 
                  ?>
                  <p style="color:#c00">
                    <?php esc_html($res->get_error_message())?>
                  </p>
                </div>
                  <?php
                  return; 
                }

        $assets  = $res['assets'];
        $clients = get_posts(['post_type'=>CPT_Client::POST_TYPE, 'numberposts'=>-1, 'orderby'=>'title', 'order'=>'ASC']);

        // Build client → assigned asset map
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
            $thumb = $pid ? esc_url(add_query_arg(['width'=>320,'height'=>180,'time'=>2,'fit_mode'=>'smartcrop'], "https://image.mux.com/$pid/thumbnail.jpg")) : '';

            echo '<tr>';
            echo '<td style="font-family:monospace">'.esc_html($a['id']).'</td>';
            echo '<td>'.esc_html($created).'</td>';
            echo '<td>'.$title.'</td>';
            echo '<td>'.$creator_id.'</td>';
            echo '<td>'.$external.'</td>';
            echo '<td>'.($thumb ? '<img src="'.$thumb.'" width="160" height="90" style="border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.12)">' : '—').'</td>';
            echo '<td>';
            
            // linking up the save function with kcfh assign vod
            echo '<form method="post" action="' . esc_url( admin_url('admin-post.php?action=kcfh_assign_vod') ) . '">';
            wp_nonce_field( 'kcfh_assign_vod_' . $a['id'], 'kcfh_nonce' ); // named field

            echo '<input type="hidden" name="asset_id" value="'.esc_attr($a['id']).'">';
            echo '<input type="hidden" name="action" value="kcfh_assign_vod">';
            echo '<input type="hidden" name="asset_id" value="'.esc_attr($a['id']).'">';

            echo '<select name="client_id">';
                // Unassigned option
                $selU = ($current_client_id === 0) ? ' selected' : '';
                echo '<option value="0"'.$selU.'>— Unassigned —</option>';

                // Only clients with NO VOD, plus the one currently assigned to THIS asset
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
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }




  /** ---------- Live Settings ---------- */
  public static function render_live_settings() {
    if (!current_user_can('manage_options')) wp_die('Nope');
    $live_playback = get_option(self::OPT_LIVE_PLAYBACK, '');
    $live_client   = (int) get_option(self::OPT_LIVE_CLIENT, 0);

    echo '<div class="wrap"><h1>Live Settings</h1>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('kcfh_save_live_settings');
    echo '<input type="hidden" name="action" value="kcfh_save_live_settings">';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th scope="row">Live Playback ID</th><td>';
    echo '<input type="text" name="kcfh_live_playback_id" value="'.esc_attr($live_playback).'" class="regular-text">';
    echo '<p class="description">Playback ID for your single camera live stream. Public playback recommended for testing.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Currently Live Client</th><td>';
    echo $live_client ? esc_html(get_the_title($live_client)).' (#'.$live_client.')' : 'None';
    echo '<p class="description">Set/unset on the Dashboard using “Set Live / Unset Live”. Only one client can be Live at a time.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    submit_button('Save Settings');
    echo '</form></div>';
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
}

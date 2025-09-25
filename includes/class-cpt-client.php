<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class CPT_Client {
  const POST_TYPE = 'kcfh_client';

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
    add_meta_box('kcfh_client_details', 'Client Details', [__CLASS__, 'render_meta'], self::POST_TYPE, 'normal', 'high');
  }

  public static function render_meta($post) {
    wp_nonce_field('kcfh_client_save', 'kcfh_client_nonce');

    $dob  = get_post_meta($post->ID, '_kcfh_dob', true);
    $dod  = get_post_meta($post->ID, '_kcfh_dod', true);
    $asset_id = get_post_meta($post->ID, '_kcfh_asset_id', true);
    
    
    ?>

    <style>
      .kcfh-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:800px}
    </style>

    <div class="kcfh-grid">
      <p>
        <label for="kcfh_dob">Date of Birth</label><br>
        <input type="date" id="kcfh_dob" name="kcfh_dob" value="<?= esc_attr($dob); ?>">
      </p>

      <p>
        <label for="kcfh_dod">Date of Death</label><br>
        <input type="date" id="kcfh_dod" name="kcfh_dod" value="<?= esc_attr($dod); ?>">
      </p>

      <p>
        <label for="kcfh_asset_id">Primary VOD Asset ID</label><br>
        <input type="text" id="kcfh_asset_id" name="kcfh_asset_id" value="<?= esc_attr($asset_id); ?>" class="regular-text">
        <br><small>Paste a Mux Asset ID you want as the default VOD.</small>
      </p>
    </div>

    <p><small>Use the Featured Image box for the profile photo.</small></p>
    <?php

    $playback   = get_post_meta($post->ID, '_kcfh_playback_id', true);
    $vod_title  = get_post_meta($post->ID, '_kcfh_vod_title', true);
    $ext_id     = get_post_meta($post->ID, '_kcfh_external_id', true);

    ?>
    <hr>
    <h3>Assigned VOD</h3>
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

  public static function save_meta($post_id) {
    if (!isset($_POST['kcfh_client_nonce']) || !wp_verify_nonce($_POST['kcfh_client_nonce'], 'kcfh_client_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $dob = isset($_POST['kcfh_dob']) ? sanitize_text_field($_POST['kcfh_dob']) : '';
    $dod = isset($_POST['kcfh_dod']) ? sanitize_text_field($_POST['kcfh_dod']) : '';
    $asset_id = isset($_POST['kcfh_asset_id']) ? sanitize_text_field($_POST['kcfh_asset_id']) : '';

    update_post_meta($post_id, '_kcfh_dob', $dob);
    update_post_meta($post_id, '_kcfh_dod', $dod);
    update_post_meta($post_id, '_kcfh_asset_id', $asset_id);
  }
}

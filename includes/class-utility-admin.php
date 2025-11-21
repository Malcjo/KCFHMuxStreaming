<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;


class Admin_Util {
  public static function handle_assign_vod() {
    // must be logged-in
    if ( ! is_user_logged_in() ) {
      wp_safe_redirect( admin_url('admin.php?page=kcfh_vod_manager&kcfh_notice=login') );
      exit;
    }

    // caps
    if ( ! current_user_can('manage_options') ) {
      wp_safe_redirect( admin_url('admin.php?page=kcfh_vod_manager&kcfh_notice=cap') );
      exit;
    }

    // required fields
    $asset_id  = isset($_POST['asset_id']) ? sanitize_text_field( wp_unslash($_POST['asset_id']) ) : '';
    $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
    if ( ! $asset_id ) {
      wp_safe_redirect( admin_url('admin.php?page=kcfh_vod_manager&kcfh_notice=badreq') );
      exit;
    }

    // nonce
    if ( ! isset($_POST['kcfh_nonce']) || ! wp_verify_nonce($_POST['kcfh_nonce'], 'kcfh_assign_vod_' . $asset_id) ) {
      wp_safe_redirect( admin_url('admin.php?page=kcfh_vod_manager&kcfh_notice=nonce') );
      exit;
    }

    // one-to-one: remove this asset from any other clients first
    $clients = get_posts(['post_type'=>CPT_Client::POST_TYPE, 'numberposts'=>-1, 'fields'=>'ids']);
    foreach ( $clients as $cid ) {
      if ( get_post_meta($cid, '_kcfh_asset_id', true) === $asset_id ) {
        delete_post_meta($cid, '_kcfh_asset_id');
        delete_post_meta($cid, '_kcfh_playback_id');
        delete_post_meta($cid, '_kcfh_vod_title');
        delete_post_meta($cid, '_kcfh_external_id');
      }
    }

    if ( $client_id === 0 ) {
      // Unassign
      nocache_headers();
      wp_safe_redirect( admin_url('admin.php?page=kcfh_vod_manager&kcfh_notice=unassigned') );
      exit;
    }

    // Assign + store helpful fields for public rendering
    update_post_meta($client_id, '_kcfh_asset_id', $asset_id);

    // NOTE: keep the same function name you actually have in Asset_Service
    $asset = Asset_Service::fetch_asset($asset_id, 60);

    if ( ! is_wp_error($asset) ) {
      $playback = Asset_Service::first_public_playback_id($asset);
      if ($playback) update_post_meta($client_id, '_kcfh_playback_id', $playback);
      if ( ! empty($asset['title']) )       update_post_meta($client_id, '_kcfh_vod_title', $asset['title']);
      if ( ! empty($asset['external_id']) ) update_post_meta($client_id, '_kcfh_external_id', $asset['external_id']);
    }

    nocache_headers();
    wp_safe_redirect( admin_url('admin.php?page=kcfh_vod_manager&kcfh_notice=assigned') );
    exit;
  }

  public static function DisplayIsLive( $is_live, $unset_url, $set_url){
    if($is_live){
      ?>
      <a href="<?php echo esc_url($unset_url); ?>">Unset Live</a>
      <?php
      }
      else{
        ?>
        <a href="<?php echo esc_url($set_url); ?>">Set Live</a>
        <?php
      }
    }
  }

?>

<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class VOD_Assignment {

  /**
   * Assign (or unassign if $asset_id === '') one Mux asset to exactly one client.
   * - Enforces one-to-one across all clients.
   * - Fetches from Mux and caches playback/title/external on the client.
   * - Returns array with keys: 'client_id','asset_id','playback','title','external_id'
   */
  public static function set_client_vod(int $client_id, string $asset_id) {
    $client_id = (int) $client_id;
    $asset_id  = sanitize_text_field($asset_id);

    if (!$client_id || get_post_type($client_id) !== CPT_Client::POST_TYPE) {
      return new \WP_Error('kcfh_bad_client', 'Invalid client ID.');
    }

    // Unassign
    if ($asset_id === '') {
      delete_post_meta($client_id, '_kcfh_asset_id');
      delete_post_meta($client_id, '_kcfh_playback_id');
      delete_post_meta($client_id, '_kcfh_vod_title');
      delete_post_meta($client_id, '_kcfh_external_id');
      return [
        'client_id' => $client_id,
        'asset_id'  => '',
        'playback'  => '',
        'title'     => '',
        'external_id' => '',
      ];
    }

    // 1) Unassign from everyone else (one-to-one)
    $others = get_posts([
      'post_type'      => CPT_Client::POST_TYPE,
      'posts_per_page' => -1,
      'fields'         => 'ids',
      'no_found_rows'  => true,
      'post__not_in'   => [$client_id],
      'meta_query'     => [
        ['key' => '_kcfh_asset_id', 'value' => $asset_id, 'compare' => '=']
      ],
    ]);
    foreach ($others as $oid) {
      delete_post_meta($oid, '_kcfh_asset_id');
      delete_post_meta($oid, '_kcfh_playback_id');
      delete_post_meta($oid, '_kcfh_vod_title');
      delete_post_meta($oid, '_kcfh_external_id');
    }

    // 2) Fetch from Mux
    $asset = Asset_Service::get_asset($asset_id, 120);
    if (is_wp_error($asset)) {
      // Save at least the ID; meta can be backfilled later
      update_post_meta($client_id, '_kcfh_asset_id', $asset_id);
      return new \WP_Error('kcfh_mux_fetch', 'Mux fetch failed; saved Asset ID only.', $asset->get_error_message());
    }

    $playback = Asset_Service::first_public_playback_id($asset);
    $title    = !empty($asset['title']) ? $asset['title']
              : (!empty($asset['passthrough']) ? $asset['passthrough'] : ('Asset ' . $asset_id));
    $ext_id   = !empty($asset['external_id']) ? $asset['external_id'] : '';

    // 3) Persist on this client
    update_post_meta($client_id, '_kcfh_asset_id', $asset_id);
    if ($playback) update_post_meta($client_id, '_kcfh_playback_id', $playback);
    update_post_meta($client_id, '_kcfh_vod_title',   sanitize_text_field($title));
    update_post_meta($client_id, '_kcfh_external_id', sanitize_text_field($ext_id));

    return [
      'client_id'   => $client_id,
      'asset_id'    => $asset_id,
      'playback'    => (string) $playback,
      'title'       => (string) $title,
      'external_id' => (string) $ext_id,
    ];
  }
}

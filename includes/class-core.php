<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

/**
 * Thin façade that centralizes the calls you make in admin + shortcodes.
 * Internally delegates to Asset_Service, VOD_Assignment, etc.
 */
class Core {

  /* ========= Data / Mux ========= */

  // List assets from Mux (normalized)
  public static function list_assets(array $args = [], int $cache_ttl = 60) {
    return Asset_Service::fetch_assets($args, $cache_ttl);
  }

  // Get a single asset from Mux (normalized)
  public static function get_asset(string $asset_id, int $cache_ttl = 300) {
    return Asset_Service::get_asset($asset_id, $cache_ttl);
  }

  // Utility: first public playback id from normalized asset
  public static function first_public_playback_id(array $asset) {
    return Asset_Service::first_public_playback_id($asset);
  }

  // Build a Mux thumbnail URL
  public static function thumbnail(string $playback_id, int $w=640, int $h=360, int $sec=2): string {
    $w = max(1, $w); $h = max(1, $h); $sec = max(0, $sec);
    $base = 'https://image.mux.com/' . rawurlencode($playback_id) . '/thumbnail.jpg';
    $qs   = ['width'=>$w,'height'=>$h,'time'=>$sec,'fit_mode'=>'smartcrop'];
    return add_query_arg($qs, $base);
  }

  // Front-end page URL (safe during AJAX)
  public static function current_page_url(): string {
    if (defined('DOING_AJAX') && DOING_AJAX) {
      $ref = wp_get_referer();
      if ($ref) return $ref;
    }
    $req = sanitize_text_field($_SERVER['REQUEST_URI'] ?? '/');
    return home_url(add_query_arg([], $req));
  }

  /* ========= Domain actions ========= */

  // One canonical entrypoint to assign/unassign a VOD to a client (1-to-1)
  public static function assign_vod_to_client(int $client_id, string $asset_id) {
    return VOD_Assignment::set_client_vod($client_id, $asset_id);
  }

  /* ========= Client helpers ========= */

  // Read client meta in one go (typed array)
  public static function get_client_vod_meta(int $client_id): array {
    return [
      'asset_id'    => get_post_meta($client_id, '_kcfh_asset_id', true),
      'playback_id' => get_post_meta($client_id, '_kcfh_playback_id', true),
      'vod_title'   => get_post_meta($client_id, '_kcfh_vod_title', true),
      'external_id' => get_post_meta($client_id, '_kcfh_external_id', true),
      'dob'         => get_post_meta($client_id, '_kcfh_dob', true),
      'dod'         => get_post_meta($client_id, '_kcfh_dod', true),
    ];
  }
}

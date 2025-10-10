<?php
// includes/class-live-service.php
namespace KCFH\Streaming;

if (!defined('ABSPATH')) exit;

class Live_Service {
  private static function auth_headers() {
    return [
      'Authorization' => 'Basic ' . base64_encode(MUX_TOKEN_ID . ':' . MUX_TOKEN_SECRET),
      'Content-Type'  => 'application/json',
      'Accept'        => 'application/json',
    ];
  }

    public static function get_live_stream($live_stream_id) {
    $url = "https://api.mux.com/video/v1/live-streams/" . rawurlencode($live_stream_id);
    $res = wp_remote_get($url, [
      'headers' => self::auth_headers(),
      'timeout' => 20,
    ]);
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
      return new \WP_Error('mux_http', 'Mux GET live stream failed ('.$code.'): '. wp_remote_retrieve_body($res));
    }
    $body = json_decode(wp_remote_retrieve_body($res), true);
    return $body['data'] ?? [];
  }


  public static function update_live_stream($live_stream_id, array $fields) {
    $url = "https://api.mux.com/video/v1/live-streams/" . rawurlencode($live_stream_id);
    $res = wp_remote_request($url, [
      'method'  => 'PATCH',
      'headers' => self::auth_headers(),
      'body'    => wp_json_encode($fields),
      'timeout' => 30,
    ]);
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
      return new \WP_Error('mux_http', 'Mux PATCH live stream failed ('.$code.'): '. wp_remote_retrieve_body($res));
    }
    return json_decode(wp_remote_retrieve_body($res), true);
  }

  public static function patch_asset_passthrough($asset_id, $passthrough) {
    $url = "https://api.mux.com/video/v1/assets/" . rawurlencode($asset_id);
    $res = wp_remote_request($url, [
      'method'  => 'PATCH',
      'headers' => self::auth_headers(),
      'body'    => wp_json_encode(['passthrough' => (string)$passthrough]),
      'timeout' => 30,
    ]);
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
      return new \WP_Error('mux_http', 'Mux PATCH asset failed ('.$code.'): '. wp_remote_retrieve_body($res));
    }
    return json_decode(wp_remote_retrieve_body($res), true);
  }
}

<?php
// includes/class-live-service.php
namespace KCFH\Streaming;

if (!defined('ABSPATH')) exit;

class Live_Service {
  private static function auth_headers() {
    $token_id = defined('MUX_TOKEN_ID') ? MUX_TOKEN_ID : '';
    $token_secret = defined('MUX_TOKEN_SECRET') ? MUX_TOKEN_SECRET : '';

    return [
      'Authorization' => 'Basic ' . base64_encode($token_id . ':' . $token_secret),
      'Content-Type'  => 'application/json',
      'Accept'        => 'application/json',
    ];
  }

  private static function validate_configuration() {
    if (!defined('MUX_TOKEN_ID') || !MUX_TOKEN_ID || !defined('MUX_TOKEN_SECRET') || !MUX_TOKEN_SECRET) {
      return new \WP_Error('mux_credentials', 'Mux credentials are not configured.');
    }

    return true;
  }

    public static function get_live_stream($live_stream_id) {
    $configuration = self::validate_configuration();
    if (is_wp_error($configuration)) return $configuration;

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
    $configuration = self::validate_configuration();
    if (is_wp_error($configuration)) return $configuration;

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

  /** Enable a Mux live stream so Larix/RTMP connections are accepted. */
  public static function enable_live_stream($live_stream_id) {
    return self::set_live_stream_enabled_state($live_stream_id, 'enable');
  }

  /**
   * Immediately close the encoder connection and reject reconnections.
   * The same live stream must be enabled again before the next service.
   */
  public static function disable_live_stream($live_stream_id) {
    return self::set_live_stream_enabled_state($live_stream_id, 'disable');
  }

  private static function set_live_stream_enabled_state($live_stream_id, $action) {
    $configuration = self::validate_configuration();
    if (is_wp_error($configuration)) return $configuration;

    $live_stream_id = trim((string) $live_stream_id);
    if ($live_stream_id === '') {
      return new \WP_Error('mux_live_stream_id', 'Mux Live Stream ID is not configured.');
    }

    if (!in_array($action, ['enable', 'disable'], true)) {
      return new \WP_Error('mux_live_action', 'Unsupported Mux live stream action.');
    }

    $url = 'https://api.mux.com/video/v1/live-streams/'
      . rawurlencode($live_stream_id)
      . '/'
      . $action;

    $response = wp_remote_request($url, [
      'method'  => 'PUT',
      'headers' => self::auth_headers(),
      'timeout' => 30,
    ]);

    if (is_wp_error($response)) return $response;

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    if ($status_code < 200 || $status_code >= 300) {
      return new \WP_Error(
        'mux_http',
        'Mux ' . $action . ' live stream failed (' . $status_code . '): ' . $response_body
      );
    }

    $decoded_body = json_decode($response_body, true);
    return is_array($decoded_body) ? $decoded_body : [];
  }

  /** Enable the shared camera and label the next Mux recording for a client. */
  public static function refresh_for_client($client_id) {
    $live_stream_id = defined('KCFH_LIVE_STREAM_ID') ? trim((string) KCFH_LIVE_STREAM_ID) : '';
    if ($live_stream_id === '') {
      return new \WP_Error('mux_live_stream_id', 'KCFH_LIVE_STREAM_ID is not configured.');
    }

    $client_id = (int) $client_id;
    $client_title = $client_id ? get_the_title($client_id) : '';

    // Configure the next recording before enabling ingest. This prevents a
    // continuously reconnecting Larix app from creating an unlabelled asset.
    $updated = self::update_live_stream($live_stream_id, [
      'reconnect_window' => 600,
      'use_slate_for_standard_latency' => true,
      'passthrough' => 'client-' . $client_id,
      'new_asset_settings' => [
        'playback_policies' => ['public'],
        'passthrough' => 'client-' . $client_id,
        'meta' => [
          'title' => $client_title ?: 'KCFH live stream',
          'creator_id' => 'kcfh-streaming',
          'external_id' => 'client-' . $client_id,
        ],
      ],
    ]);

    if (is_wp_error($updated)) return $updated;

    $enabled = self::enable_live_stream($live_stream_id);
    if (is_wp_error($enabled)) return $enabled;

    return $updated;
  }

  /** Hard-stop the shared camera configured in wp-config.php. */
  public static function force_stop_configured_stream() {
    $live_stream_id = defined('KCFH_LIVE_STREAM_ID') ? trim((string) KCFH_LIVE_STREAM_ID) : '';
    if ($live_stream_id === '') {
      return new \WP_Error('mux_live_stream_id', 'KCFH_LIVE_STREAM_ID is not configured.');
    }

    return self::disable_live_stream($live_stream_id);
  }

  public static function patch_asset_passthrough($asset_id, $passthrough) {
    $configuration = self::validate_configuration();
    if (is_wp_error($configuration)) return $configuration;

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

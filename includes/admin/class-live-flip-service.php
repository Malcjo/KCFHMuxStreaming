<?php
namespace KCFH\Streaming;

use KCFH\Streaming\Asset_Service;
use KCFH\Streaming\CPT_Client;

if (!defined('ABSPATH')) exit;

class Live_Flip_Service {
  const CRON_HOOK = 'kcfh_live_flip_check';
  const AJAX_CHECK = 'kcfh_check_vod';

  /** Boot: cron + ajax */
  public static function boot() {
    add_action(self::CRON_HOOK, [__CLASS__, 'cron_flip_check'], 10, 1);

    add_action('wp_ajax_' . self::AJAX_CHECK, [__CLASS__, 'ajax_check_vod']);
    add_action('wp_ajax_nopriv_' . self::AJAX_CHECK, [__CLASS__, 'ajax_check_vod']);
  }

  /**
   * Schedule a one-shot flip for a client at $end_ts (UTC).
   * If a job exists, reschedule it.
   */
  public static function schedule_flip(int $client_id, int $end_ts) {
    $hook_args = ['client_id' => $client_id];
    self::unschedule_flip($client_id);
    if ($end_ts > time()) {
      wp_schedule_single_event($end_ts + 30, self::CRON_HOOK, $hook_args); // 30s buffer
    }
  }

  public static function unschedule_flip(int $client_id) {
    $timestamp = wp_next_scheduled(self::CRON_HOOK, ['client_id' => $client_id]);
    if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK, ['client_id' => $client_id]);
  }

  /** Cron: after end time, attach latest asset to client and cache playback id */
  public static function cron_flip_check(array $args) {
    $client_id = (int)($args['client_id'] ?? 0);
    if (!$client_id) return;

    // If VOD already assigned, nothing to do
    $existing_asset = get_post_meta($client_id, '_kcfh_asset_id', true);
    $existing_pb    = get_post_meta($client_id, '_kcfh_playback_id', true);
    if ($existing_asset && $existing_pb) return;

    $asset = self::find_latest_asset_for_client($client_id);
    if (!$asset) return;

    // Rename asset on Mux to client's title
    $client_title = get_the_title($client_id);
    if (!empty($client_title)) {
        $rename = Asset_Service::update_asset_title($asset['id'], $client_title);
        if (is_wp_error($rename)) {
            error_log('[KCFH] Failed to update Mux title for asset '
                . $asset['id'] . ': ' . $rename->get_error_message());
        }
    }

    update_post_meta($client_id, '_kcfh_asset_id', $asset['id']);
    if (!empty($asset['playback_ids'][0]['id'])) {
        update_post_meta($client_id, '_kcfh_playback_id', $asset['playback_ids'][0]['id']);
    }

    if (!empty($client_title)) {
        update_post_meta($client_id, '_kcfh_vod_title', sanitize_text_field($client_title));
    }

  }

      /**
     * Try to find and attach the latest READY asset for this client by passthrough.
     * Returns playback_id (string) on success, '' otherwise.
     */
    public static function maybe_attach_ready_asset(int $client_id): string {
        // Ask Mux for newest ready assets with passthrough "client-{id}"
        $resp = Asset_Service::fetch_assets([
            'limit'      => 1,
            'order'      => 'created_at',
            'direction'  => 'desc',
            'status'     => 'ready',
            'passthrough'=> 'client-' . $client_id,   // <-- make sure you set this when you Set Live
        ], 30);

        if (is_wp_error($resp) || empty($resp['assets'][0])) return '';

        $asset = $resp['assets'][0];

        // Get playback id
        $pb = Asset_Service::first_public_playback_id($asset);
        if (!$pb) return '';

        // Client title for naming
        $client_title = get_the_title($client_id);

        // Try to rename the Mux asset to match the client title
        if (!empty($client_title)) {
            $rename = Asset_Service::update_asset_title($asset['id'], $client_title);
            if (is_wp_error($rename)) {
                // Don’t break anything if rename fails – just log it.
                error_log('[KCFH] Failed to update Mux title for asset '
                    . $asset['id'] . ': ' . $rename->get_error_message());
            }
        }

        // Persist to client (use client title as VOD title if available)
        update_post_meta($client_id, '_kcfh_asset_id',    $asset['id']);
        update_post_meta($client_id, '_kcfh_playback_id', $pb);
        update_post_meta(
            $client_id,
            '_kcfh_vod_title',
            !empty($client_title)
                ? sanitize_text_field($client_title)
                : (!empty($asset['title']) ? sanitize_text_field($asset['title']) : '')
        );
        update_post_meta(
            $client_id,
            '_kcfh_external_id',
            !empty($asset['external_id']) ? sanitize_text_field($asset['external_id']) : ''
        );

        return $pb;

    }

      /** AJAX poll from front-end to know when VOD is ready */
    public static function ajax_check_vod() {
        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
        if (!$client_id) wp_send_json_success(['ready' => false]);

        // 1) If already assigned, great.
        $pb = (string) get_post_meta($client_id, '_kcfh_playback_id', true);
        if (!$pb) {
            // 2) Try to auto-attach the freshest READY asset for this client.
            $pb = self::maybe_attach_ready_asset($client_id);
        }

        if ($pb) {
          /*
          this wp function returns a json function if success
          {
            "success": true,
            "data": {
              "ready": true,
              "playback_id": "abcd1234"
            }
          }
          */
            wp_send_json_success(['ready' => true, 'playback_id' => $pb]);
        } else {
            wp_send_json_success(['ready' => false]);
        }
    }

  /** Helper: newest READY asset with passthrough=client-<id> */
  private static function find_latest_asset_for_client(int $client_id) {
    if (!class_exists('\KCFH\Streaming\Asset_Service')) return null;

    // Pull a small page of newest assets; filter by passthrough and status
    $res = Asset_Service::fetch_assets([
      'limit'     => 15,
      'order'     => 'created_at',
      'direction' => 'desc',
      'status'    => 'ready',
    ], 15);

    if (is_wp_error($res) || empty($res['assets'])) return null;

    foreach ($res['assets'] as $a) {
      $pt = isset($a['passthrough']) ? (string)$a['passthrough'] : '';
      if ($pt === 'client-' . $client_id) {
        return $a; // newest match
      }
    }
    return null;
  }
}

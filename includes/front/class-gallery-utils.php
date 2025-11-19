<?php
namespace KCFH\STREAMING;

if (!defined('ABSPATH')) { exit; }

/**
 * Small shared helpers used by gallery classes.
 */
class Gallery_Utils
{
    /**
     * Works on most setups; keeps existing query minus kcfh_client.
     */
    public static function current_page_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';

        $url = $scheme . $host . $uri;

        return remove_query_arg('kcfh_client', $url);
    }

    /**
     * Decide playback for a client:
     * - If this is the live client and global live playback exists, use live.
     * - Else use VOD playback from client meta.
     *
     * @return array|null ['playback_id' => string, 'is_live' => bool] or null
     */
    public static function determine_playback_for_client(int $client_id): ?array
    {
        $live_client_id   = (int) get_option('kcfh_live_client_id', 0);
        $live_playback_id = trim((string) get_option('kcfh_live_playback_id', ''));

        // Live takes priority if this client is currently live
        if ($live_client_id && $live_playback_id && $live_client_id === $client_id) {
            return [
                'playback_id' => $live_playback_id,
                'is_live'     => true,
            ];
        }

        // Fallback to VOD playback
        $vod_playback_id = (string) get_post_meta($client_id, '_kcfh_playback_id', true);
        if ($vod_playback_id) {
            return [
                'playback_id' => $vod_playback_id,
                'is_live'     => false,
            ];
        }

        return null;
    }
}

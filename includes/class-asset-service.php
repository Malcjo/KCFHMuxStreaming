<?php
namespace KCFH\Streaming;

if (!defined('ABSPATH')) exit;

//all MUX access is done in this file so far
class Asset_Service {
    const API_BASE = 'https://api.mux.com/video/v1';

    public static function init() {
        // Reserved for future (e.g., cron to warm cache, etc.)
    }

    /**
     * Fetch assets from Mux (server-side).
     *
     * @param array $args ['limit'=>int,'page'=>string|null,'order'=>string,'direction'=>string,'status'=>string|null]
     * @param int $cache_ttl seconds to cache transient
     * @return array|WP_Error  normalized asset array
     */


     //builds a qery for /assets with 
     //limit, order, direciton, status and pagination cursor
    public static function fetch_assets(array $args = [], $cache_ttl = 60) {
        $defaults = [
            'limit'     => 12,
            'page'      => null,                 // pagination cursor
            'order'     => 'created_at',         // created_at, updated_at, etc.
            'direction' => 'desc',               // asc|desc
            'status'    => null,                 // 'ready', 'errored', etc.
        ];
        $args = wp_parse_args($args, $defaults);

        $token_id     = defined('MUX_TOKEN_ID')     ? MUX_TOKEN_ID     : '';
        $token_secret = defined('MUX_TOKEN_SECRET') ? MUX_TOKEN_SECRET : '';

        

        if (!$token_id || !$token_secret) {
            return new \WP_Error('kcfh_mux_creds', 'Mux credentials not configured in wp-config.php.');
        }

        // Allow external code to adjust args safely
        $args = apply_filters('kcfh_streaming_assets_args', $args);

        $qs = [
            'limit'     => max(1, (int) $args['limit']),
            'order'     => sanitize_text_field($args['order']),
            'direction' => (strtolower($args['direction']) === 'asc' ? 'asc' : 'desc'),
        ];
        if (!empty($args['page']))   { $qs['page'] = sanitize_text_field($args['page']); }
        if (!empty($args['status'])) { $qs['status'] = sanitize_text_field($args['status']); }

        $url = add_query_arg($qs, self::API_BASE . '/assets');

        $cache_key = KCFH_STREAMING_CACHE_PREFIX . 'assets_' . md5(wp_json_encode($qs));
        if ($cache_ttl > 0) {
            $cached = get_transient($cache_key);
            if ($cached !== false) return $cached;
        }


        //Checking credentials
        //Credentials are sent using HTTP Basic
        $resp = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($token_id . ':' . $token_secret),
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('kcfh_mux_http', 'Mux API HTTP ' . $code . ': ' . $body);
        }
        
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['data'])) {
            return new \WP_Error('kcfh_mux_json', 'Unexpected Mux response.');
        }


        //if credentials are correct, return the $array

        // sanitize
        // Normalize minimal fields for rendering
        $assets = array_map(function ($a) {
            $meta = isset($a['meta']) && is_array($a['meta']) ? $a['meta'] : [];
            $title = '';
            if (!empty($meta['title'])) {
                $title = sanitize_text_field($meta['title']);
            } elseif (!empty($a['title'])) {
                // Just in case a top-level `title` appears from older/newer responses
                $title = sanitize_text_field($a['title']);
            } elseif (!empty($a['passthrough'])) {
                // Last-ditch fallback if you used passthrough as the “title”
                $title = sanitize_text_field($a['passthrough']);
            }


            return [
                'id'           => isset($a['id']) ? sanitize_text_field($a['id']) : '',
                'status'       => isset($a['status']) ? sanitize_text_field($a['status']) : '',
                'created_at'   => isset($a['created_at']) ? sanitize_text_field($a['created_at']) : '',
                'playback_ids' => isset($a['playback_ids']) && is_array($a['playback_ids']) ? $a['playback_ids'] : [],
                
                'title'        => !empty($meta['title']) ? sanitize_text_field($meta['title'])
                                : (!empty($a['title']) ? sanitize_text_field($a['title']) : ''),
                'creator_id'   => !empty($meta['creator_id'])  ? sanitize_text_field($meta['creator_id'])  : '',
                'external_id'  => !empty($meta['external_id']) ? sanitize_text_field($meta['external_id']) : '',
                'passthrough'  => isset($a['passthrough']) ? sanitize_text_field($a['passthrough']) : '',
            ];
        }, $json['data']);

        //The result is cached under a key that includes the query parameters.
        //Default cache_ttl is 60 seconds but can be changed per shortcode.
        //This reduces the number of calls to Mux when your page gets traffic.

        // Include pagination cursor if present
        $result = [
            'assets' => $assets,
            'next'   => isset($json['next']) ? sanitize_text_field($json['next']) : null,
        ];

        if ($cache_ttl > 0) {
            set_transient($cache_key, $result, $cache_ttl);
        }

        return $result;

    }

    /**
     * Get first public playback ID from a normalized asset.
     */
    public static function first_public_playback_id(array $asset) {
        if (empty($asset['playback_ids'])) return null;
        foreach ($asset['playback_ids'] as $p) {
            $policy = isset($p['policy']) ? strtolower(sanitize_text_field($p['policy'])) : 'public';
            if ($policy === 'public' && !empty($p['id'])) return sanitize_text_field($p['id']);
        }
        // fallback to first if policy missing
        return !empty($asset['playback_ids'][0]['id']) ? sanitize_text_field($asset['playback_ids'][0]['id']) : null;
    }

    public static function fetch_asset($asset_id, $cache_ttl = 300) {
    $token_id     = defined('MUX_TOKEN_ID') ? MUX_TOKEN_ID : '';
    $token_secret = defined('MUX_TOKEN_SECRET') ? MUX_TOKEN_SECRET : '';
    if (!$token_id || !$token_secret) {
        return new \WP_Error('kcfh_mux_creds', 'Mux credentials not configured.');
    }

    $url = self::API_BASE . '/assets/' . rawurlencode($asset_id);
    $cache_key = KCFH_STREAMING_CACHE_PREFIX . 'asset_' . md5($asset_id);
    if ($cache_ttl > 0 && ($cached = get_transient($cache_key)) !== false) return $cached;

    $resp = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($token_id . ':' . $token_secret),
            'Accept'        => 'application/json',
        ],
        'timeout' => 20,
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300) {
        return new \WP_Error('kcfh_mux_http', 'Mux API HTTP ' . $code . ': ' . $body);
    }
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['data'])) {
        return new \WP_Error('kcfh_mux_json', 'Unexpected Mux response.');
    }

    // Reuse the same normalizer (single item)
    $a = $json['data'];
    $meta = isset($a['meta']) && is_array($a['meta']) ? $a['meta'] : [];
    $asset = [
        'id'           => isset($a['id']) ? sanitize_text_field($a['id']) : '',
        'status'       => isset($a['status']) ? sanitize_text_field($a['status']) : '',
        'created_at'   => isset($a['created_at']) ? sanitize_text_field($a['created_at']) : '',
        'playback_ids' => isset($a['playback_ids']) && is_array($a['playback_ids']) ? $a['playback_ids'] : [],
        'title'        => !empty($meta['title']) ? sanitize_text_field($meta['title'])
                        : (!empty($a['title']) ? sanitize_text_field($a['title']) : ''),
        'creator_id'   => !empty($meta['creator_id'])  ? sanitize_text_field($meta['creator_id'])  : '',
        'external_id'  => !empty($meta['external_id']) ? sanitize_text_field($meta['external_id']) : '',
        'passthrough'  => isset($a['passthrough']) ? sanitize_text_field($a['passthrough']) : '',
    ];

    if ($cache_ttl > 0) set_transient($cache_key, $asset, $cache_ttl);
    return $asset;
}

public static function get_asset($asset_id, $cache_ttl = 300) {
    $asset_id = sanitize_text_field($asset_id);
    if (!$asset_id) {
        return new \WP_Error('kcfh_mux_assetid', 'Missing Mux Asset ID.');
    }

    $token_id     = defined('MUX_TOKEN_ID')     ? MUX_TOKEN_ID     : '';
    $token_secret = defined('MUX_TOKEN_SECRET') ? MUX_TOKEN_SECRET : '';
    if (!$token_id || !$token_secret) {
        return new \WP_Error('kcfh_mux_creds', 'Mux credentials not configured.');
    }

    $cache_key = KCFH_STREAMING_CACHE_PREFIX . 'asset_' . $asset_id;
    if ($cache_ttl > 0) {
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
    }

    $url  = self::API_BASE . '/assets/' . rawurlencode($asset_id);
    $resp = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($token_id . ':' . $token_secret),
            'Accept'        => 'application/json',
        ],
        'timeout' => 20,
    ]);

    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300) {
        return new \WP_Error('kcfh_mux_http', 'Mux API HTTP ' . $code . ': ' . $body);
    }

    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['data'])) {
        return new \WP_Error('kcfh_mux_json', 'Unexpected Mux response.');
    }

    // Normalize minimal fields we use elsewhere
    $a = $json['data'];
    $normalized = [
        'id'           => isset($a['id']) ? sanitize_text_field($a['id']) : '',
        'status'       => isset($a['status']) ? sanitize_text_field($a['status']) : '',
        'created_at'   => isset($a['created_at']) ? sanitize_text_field($a['created_at']) : '',
        'title'        => isset($a['title']) ? sanitize_text_field($a['title']) : '',
        'passthrough'  => isset($a['passthrough']) ? sanitize_text_field($a['passthrough']) : '',
        'external_id'  => isset($a['external_id']) ? sanitize_text_field($a['external_id']) : '',
        'playback_ids' => (isset($a['playback_ids']) && is_array($a['playback_ids'])) ? $a['playback_ids'] : [],
    ];

    if ($cache_ttl > 0) {
        set_transient($cache_key, $normalized, $cache_ttl);
    }

    return $normalized;
}

/** ---- STATIC MP4: helpers (ADD THESE) ----------------------------------- */

/** Create a static rendition for this asset: 'highest' | '1080p' | '720p' | 'audio-only' */
public static function create_static_rendition(string $asset_id, string $resolution = 'highest') {
    $token_id     = defined('MUX_TOKEN_ID') ? MUX_TOKEN_ID : '';
    $token_secret = defined('MUX_TOKEN_SECRET') ? MUX_TOKEN_SECRET : '';
    if (!$token_id || !$token_secret) {
        return new \WP_Error('kcfh_mux_creds', 'Mux credentials not configured in wp-config.php.');
    }
    $url  = self::API_BASE . '/assets/' . rawurlencode($asset_id) . '/static-renditions';
    $resp = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($token_id . ':' . $token_secret),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 25,
        'body'    => wp_json_encode(['resolution' => $resolution]),
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    return ($code >= 200 && $code < 300) ? $body : new \WP_Error('kcfh_mux_http', 'Mux API HTTP ' . $code, $body);
}

/** Fetch the RAW asset (full Mux shape). Returns the array under 'data'. */
public static function get_asset_raw(string $asset_id) {
    $token_id     = defined('MUX_TOKEN_ID') ? MUX_TOKEN_ID : '';
    $token_secret = defined('MUX_TOKEN_SECRET') ? MUX_TOKEN_SECRET : '';
    if (!$token_id || !$token_secret) {
        return new \WP_Error('kcfh_mux_creds', 'Mux credentials not configured in wp-config.php.');
    }
    $url  = self::API_BASE . '/assets/' . rawurlencode($asset_id);
    $resp = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($token_id . ':' . $token_secret),
            'Accept'        => 'application/json',
        ],
        'timeout' => 20,
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['data'])) {
        return new \WP_Error('kcfh_mux_http', 'Mux asset fetch failed', ['status'=>$code, 'body'=>$body]);
    }
    return $body['data'];
}

/** From RAW asset, pick a ready static mp4 file name. Prefers 1080p.mp4 > highest.mp4 > any ready .mp4 */
public static function pick_ready_static_name_from_raw(array $raw): ?string {
    $files = [];
    if (isset($raw['static_renditions']['files']) && is_array($raw['static_renditions']['files'])) {
        $files = $raw['static_renditions']['files'];
    } elseif (isset($raw['static_renditions']) && is_array($raw['static_renditions'])) {
        $files = $raw['static_renditions']; // some examples show flat array
    }
    if (!$files) return null;

    $ready = [];
    foreach ($files as $r) {
        $name = $r['name'] ?? '';
        if (($r['status'] ?? '') === 'ready' && $name) $ready[$name] = true;
    }
    if (isset($ready['1080p.mp4'])) return '1080p.mp4';
    if (isset($ready['highest.mp4'])) return 'highest.mp4';
    foreach (array_keys($ready) as $name) {
        if (substr($name, -4) === '.mp4') return $name;
    }
    return null;
}

/** From RAW asset, get the first public playback id. */
public static function first_public_playback_id_from_raw(array $raw): ?string {
    $pbs = $raw['playback_ids'] ?? [];
    foreach ($pbs as $pb) {
        $policy = strtolower($pb['policy'] ?? 'public');
        if ($policy === 'public' && !empty($pb['id'])) return $pb['id'];
    }
    return !empty($pbs[0]['id']) ? $pbs[0]['id'] : null;
}

/** Build a direct download URL for a static MP4 file. */
public static function build_static_download_url(string $playback_id, string $static_name, string $save_as): string {
    return "https://stream.mux.com/{$playback_id}/{$static_name}?download=" . rawurlencode($save_as);
}

/** Suggest a friendly filename from RAW asset (title|external_id|passthrough + created date). */
public static function suggest_filename_from_raw(array $raw): string {
    $base = 'video';
    // try meta.title or name/title, external_id, passthrough
    $metaTitle = isset($raw['meta']['title']) ? sanitize_title($raw['meta']['title']) : '';
    $name      = isset($raw['name']) ? sanitize_title($raw['name']) : '';
    $title     = isset($raw['title']) ? sanitize_title($raw['title']) : '';
    $external  = isset($raw['external_id']) ? sanitize_title($raw['external_id']) : '';
    $pass      = isset($raw['passthrough']) ? sanitize_title($raw['passthrough']) : '';
    foreach ([$metaTitle, $title, $name, $external, $pass] as $try) {
        if ($try) { $base = $try; break; }
    }
    if (!empty($raw['created_at'])) {
        $ts = is_numeric($raw['created_at']) ? (int)$raw['created_at'] : strtotime($raw['created_at']);
        if ($ts) $base .= '_' . date_i18n('Y-m-d', $ts);
    }
    return $base . '.mp4';
}

public static function update_asset_title(string $asset_id, string $title) {
    $asset_id = trim($asset_id);
    $title    = trim($title);

    if (!$asset_id || $title === '') {
        return new \WP_Error('kcfh_mux_no_asset', 'Missing asset_id or title for update.');
    }

    $token_id     = defined('MUX_TOKEN_ID')     ? MUX_TOKEN_ID     : '';
    $token_secret = defined('MUX_TOKEN_SECRET') ? MUX_TOKEN_SECRET : '';

    if (!$token_id || !$token_secret) {
        return new \WP_Error('kcfh_mux_creds', 'Mux credentials not configured in wp-config.php.');
    }

    $url  = self::API_BASE . '/assets/' . rawurlencode($asset_id);

    $args = [
        'method'  => 'PATCH',
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($token_id . ':' . $token_secret),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body'    => wp_json_encode([
            'meta' => [
                'title' => $title,
                // If you ever want to also set external_id etc, you can add:
                // 'external_id' => 'client-' . $asset_id,
                // 'creator_id'  => 'kcfh',
            ],
        ]),
        'timeout' => 30,
    ];

    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) {
        return $res;
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code < 200 || $code >= 300) {
        return new \WP_Error(
            'kcfh_mux_http',
            'Mux API HTTP ' . $code . ': ' . $body
        );
    }

    // Clear any cached copy of this asset (optional but helpful)
    $cache_key = KCFH_STREAMING_CACHE_PREFIX . 'asset_' . md5($asset_id);
    delete_transient($cache_key);

    return json_decode($body, true);
}




}

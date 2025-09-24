<?php
namespace KCFH\Streaming;

if (!defined('ABSPATH')) exit;

class Shortcode_Gallery {
    public static function init() {
        add_shortcode('kcfh_stream_gallery', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    public static function register_assets() {
        // Mux Player web component
        wp_register_script(
            'mux-player',
            'https://cdn.jsdelivr.net/npm/@mux/mux-player@latest/dist/mux-player.js',
            [],
            null,
            true
        );

        // CSS
        wp_register_style(
            'kcfh-streaming-gallery',
            KCFH_STREAMING_URL . 'assets/gallery.css',
            [],
            KCFH_STREAMING_VERSION
        );
    }

    public static function render($atts = []) {
        $atts = shortcode_atts([
            // Shared
            'view'         => 'auto',   // auto|grid|single
            'columns'      => 2,
            'cache_ttl'    => 60,
            'detail_page'  => '',       // optional: link thumbnails to a specific page URL
            'poster_fallback' => '',    // optional: fallback image URL for posters
            'showtitle'    => 'false',

            // Grid fetch options
            'limit'      => 12,
            'status'     => 'ready',
            'order'      => 'created_at',
            'direction'  => 'desc',
            'page'       => '',

            // Single view option (manual override if you embed on a separate page)
            'playback_id' => '',
        ], $atts, 'kcfh_stream_gallery');

        // Enqueue assets
        wp_enqueue_script('mux-player');
        wp_enqueue_style('kcfh-streaming-gallery');

        // Normalise
        $columns   = min(4, max(1, (int) $atts['columns']));//------------
        $cache_ttl = max(0, (int) $atts['cache_ttl']);
        $showtitle = strtolower($atts['showtitle']) === 'true';
        $poster_fallback = $atts['poster_fallback'] ? esc_url($atts['poster_fallback']) : '';

        // Decide view
        $qs_playback = isset($_GET['kcfh_pb']) ? sanitize_text_field($_GET['kcfh_pb']) : '';
        $view = $atts['view'];
        if ($view === 'auto') {
            $view = $qs_playback ? 'single' : 'grid';
        }

        //IF YOU HAVE SELECTED A VIDEO
        if ($view === 'single') {
            $playback_id = $qs_playback ?: ( $atts['playback_id'] ? sanitize_text_field($atts['playback_id']) : '' );
            return self::render_single($playback_id, $poster_fallback);
        }

        // Otherwise grid
        $limit     = max(1, (int) $atts['limit']);
        $status    = trim((string) $atts['status']) ?: null;
        $order     = sanitize_key($atts['order']);
        $direction = strtolower($atts['direction']) === 'asc' ? 'asc' : 'desc';
        $page      = $atts['page'] ? sanitize_text_field($atts['page']) : null;

        $result = Asset_Service::fetch_assets([
            'limit'     => $limit,
            'order'     => $order,
            'direction' => $direction,
            'page'      => $page,
            'status'    => $status,
        ], $cache_ttl);

        if (is_wp_error($result)) {
            return '<p class="kcfh-streaming-error" style="color:#c33;">' . esc_html($result->get_error_message()) . '</p>';
        }

        $assets = isset($result['assets']) ? $result['assets'] : [];
        $next   = isset($result['next']) ? $result['next'] : null;

        if (empty($assets)) {
            return '<p>No videos to display yet.</p>';
        }

        $detail_page = $atts['detail_page'] ? esc_url_raw($atts['detail_page']) : '';
        $grid_style = sprintf('grid-template-columns:repeat(%d, minmax(0,1fr));', $columns);//-----------------

        $html  = '<div class="kcfh-stream-grid" style="' . esc_attr($grid_style) . '">';//---------------

        foreach ($assets as $asset) {
            $playback = Asset_Service::first_public_playback_id($asset);
            if (!$playback) continue;

            $thumb = self::thumbnail_url($playback, 640, 360, 2); // 2s in to avoid black frames
            $link = $detail_page ? add_query_arg('kcfh_pb', rawurlencode($playback), $detail_page)
                                 : add_query_arg('kcfh_pb', rawurlencode($playback)); // same page

            $title     = $showtitle ? ('Asset ' . $asset['id']) : '';
            $createdAt = !empty($asset['created_at']) ? esc_html($asset['created_at']) : '';

            $card  = '<a class="kcfh-thumb" href="'. esc_url($link) .'" aria-label="Open video">';
            $card .= '  <span class="kcfh-thumb-inner">';
            $card .= '    <img src="'. esc_url($thumb) .'" alt="" loading="lazy" ' .
                     ($poster_fallback ? 'onerror="this.onerror=null;this.src=\''. esc_url($poster_fallback) .'\';"' : '') .
                     '>';
            $card .= '    <span class="kcfh-play-badge" aria-hidden="true">▶</span>';
            $card .= '  </span>';
            if ($title || $createdAt) {
                $card .= '<div class="kcfh-stream-meta">';
                if ($title)     $card .= '<div class="kcfh-stream-title">'. esc_html($title) .'</div>';
                if ($createdAt) $card .= '<div class="kcfh-stream-subtle">Created: '. $createdAt .'</div>';
                $card .= '</div>';
            }
            $card .= '</a>';

            // Allow enrichment via filter (ACF/CPT injection)
            $card = apply_filters('kcfh_streaming_card_html', $card, $asset, $playback);
            $html .= $card;
        }

        $html .= '</div>';

        if (!empty($next)) {
            $href = esc_url(add_query_arg(['kcfh_page' => $next], remove_query_arg('kcfh_page')));
            $html .= '<div class="kcfh-stream-pager"><a class="kcfh-stream-next" href="' . $href . '">Load more</a></div>';
        }

        return $html;
    }

    private static function render_single($playback_id, $poster_fallback) {
        if (!$playback_id) {
            return '<p>Missing playback ID.</p>';
        }

        $back_url = esc_url(remove_query_arg('kcfh_pb'));
        $poster   = self::thumbnail_url($playback_id, 1280, 720, 2);

        $html  = '<div class="kcfh-single">';
        $html .= '  <a class="kcfh-back" href="'. $back_url .'">← Back</a>';
        // poster is optional—mux-player auto-assigns one using the playback id
        $html .= '  <mux-player playback-id="'. esc_attr($playback_id) .'" stream-type="on-demand" controls ' .
                 '           poster="'. esc_url($poster) .'"></mux-player>';
        $html .= '</div>';

        return $html;
    }

    private static function thumbnail_url($playback_id, $w=640, $h=360, $sec=1) {
        // See: https://image.mux.com/{PLAYBACK_ID}/thumbnail.jpg?[params]
        $w = max(1, (int)$w);
        $h = max(1, (int)$h);
        $sec = max(0, (int)$sec);
        $base = 'https://image.mux.com/' . rawurlencode($playback_id) . '/thumbnail.jpg';
        $qs = [
            'width' => $w,
            'height' => $h,
            'time' => $sec,
            'fit_mode' => 'smartcrop'
        ];
        return add_query_arg($qs, $base);
    }
}

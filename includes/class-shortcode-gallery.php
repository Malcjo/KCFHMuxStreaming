<?php
namespace KCFH\Streaming;

if (!defined('ABSPATH')) exit;

class Shortcode_Gallery {
    public static function init() {
        add_shortcode('kcfh_stream_gallery', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    public static function register_assets() {
        // Mux Player web component (ES module, loads safely on the frontend)
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

    /**
     * Shortcode: [kcfh_stream limit="12" columns="2" status="ready" order="created_at" direction="desc" cache_ttl="60"]
     */
    public static function render($atts = []) {
        $atts = shortcode_atts([
            'limit'      => 12,
            'columns'    => 2,
            'status'     => 'ready',    // ready|errored|null
            'order'      => 'created_at',
            'direction'  => 'desc',
            'page'       => '',         // pagination cursor (optional)
            'cache_ttl'  => 60,         // seconds
            'showtitle'  => 'false',    // show "Asset {id}" (until metadata mapping)
        ], $atts, 'kcfh_stream_gallery');

        // Enqueue assets
        wp_enqueue_script('mux-player');
        wp_enqueue_style('kcfh-streaming-gallery');

        // Sanitize + enforce sensible bounds
        $limit     = max(1, (int) $atts['limit']);
        $columns   = min(4, max(1, (int) $atts['columns']));
        $status    = trim((string) $atts['status']);
        $status    = $status !== '' ? $status : null;
        $order     = sanitize_key($atts['order']);
        $direction = strtolower($atts['direction']) === 'asc' ? 'asc' : 'desc';
        $page      = $atts['page'] ? sanitize_text_field($atts['page']) : null;
        $cache_ttl = max(0, (int) $atts['cache_ttl']);
        $showtitle = strtolower((string) $atts['showtitle']) === 'true';

        // Fetch assets server-side
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

        // Build responsive grid
        $grid_style = sprintf('grid-template-columns:repeat(%d, minmax(0,1fr));', $columns);
        $html  = '<div class="kcfh-stream-grid" style="' . esc_attr($grid_style) . '">';

        foreach ($assets as $asset) {
            $playback = Asset_Service::first_public_playback_id($asset);
            if (!$playback) continue;

            $title     = $showtitle ? ('Asset ' . $asset['id']) : '';
            $createdAt = !empty($asset['created_at']) ? esc_html($asset['created_at']) : '';

            // Card HTML (filterable for ACF/CPT enrichment later)
            $card = '<div class="kcfh-stream-card">';
            $card .= '<mux-player playback-id="' . esc_attr($playback) . '" stream-type="on-demand" controls></mux-player>';
            if ($title || $createdAt) {
                $card .= '<div class="kcfh-stream-meta">';
                if ($title)     { $card .= '<div class="kcfh-stream-title">' . esc_html($title) . '</div>'; }
                if ($createdAt) { $card .= '<div class="kcfh-stream-subtle">Created: ' . $createdAt . '</div>'; }
                $card .= '</div>';
            }
            $card .= '</div>';

            // Allow developers (you) to inject funeral metadata via filter
            $card = apply_filters('kcfh_streaming_card_html', $card, $asset, $playback);
            $html .= $card;
        }

        $html .= '</div>';

        // Simple pager link if 'next' exists
        if (!empty($next)) {
            // Build a link with same shortcode params except 'page'
            $query = [
                'kcfh_page' => $next,
            ];
            $href = esc_url(add_query_arg($query, remove_query_arg('kcfh_page')));
            $html .= '<div class="kcfh-stream-pager"><a class="kcfh-stream-next" href="' . $href . '">Load more</a></div>';
        }

        return $html;
    }
}

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
            'showtitle'    => 'true',

            'source'           => 'clients',   // 'clients' | 'mux'
            'client_order'     => 'date',      // 'date' | 'title'
            'client_order_dir' => 'desc',      // 'asc' | 'desc'
            'include_empty'    => 'false',     // show clients with no playback/live? default: false

            // Grid fetch options
            'limit'      => 12,
            'status'     => 'ready',
            'order'      => 'created_at',
            'direction'  => 'desc',
            'page'       => '',

            // Single view option (manual override if you embed on a separate page)
            'playback_id' => '',

            'search_param' => 'kcfh_q',
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

        //if you have selected a video, render it as solo
        if ($view === 'single') {
            $playback_id = $qs_playback ?: ( $atts['playback_id'] ? sanitize_text_field($atts['playback_id']) : '' );
            return self::render_single($playback_id, $poster_fallback);
        }

        //render from Clients instead of Mux if requested
        $source = strtolower($atts['source']);
        if ($view === 'grid' && $source === 'clients') {
            return self::render_clients_grid($atts, $columns, $poster_fallback);
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

        return self::render_mux_grid($assets, $columns, $poster_fallback, $showtitle, $detail_page, $next);
    }

    public static function render_single($playback_id, $poster_fallback) {
        if (!$playback_id) { return '<p>Missing playback ID.</p>'; }

        // If the clicked playback id equals the saved live playback id → render as live
        $live_playback  = get_option(Admin_UI::OPT_LIVE_PLAYBACK, '');
        $is_live_single = ($live_playback && hash_equals($live_playback, $playback_id));

        $back_url = esc_url(remove_query_arg('kcfh_pb'));
        $poster   = self::thumbnail_url($playback_id, 1280, 720, 2);

        ob_start(); ?>
        <style>figure#FrontImage{display:none !important;}</style>
        <div class="kcfh-single">
        <a class="kcfh-back" href="<?= $back_url ?>">← Back</a>
        <mux-player
            playback-id="<?php esc_attr($playback_id) ?>"
            stream-type="<?php $is_live_single ? 'live' : 'on-demand' ?>"
            controls
            <?php $poster ? 'poster="'.esc_url($poster).'"' : '' ?>>
        </mux-player>
        </div>
        <?php
        return ob_get_clean();
    }

public static function render_clients_grid($atts, $columns, $poster_fallback, $search_term_override = null) {
    $live_client_id = (int) get_option(Admin_UI::OPT_LIVE_CLIENT, 0);
    $live_playback  = get_option(Admin_UI::OPT_LIVE_PLAYBACK, '');

    $orderby = ($atts['client_order'] === 'title') ? 'title' : 'date';
    $order   = (strtolower($atts['client_order_dir']) === 'asc') ? 'ASC' : 'DESC';
    $include_empty = (strtolower($atts['include_empty']) === 'true');

    //search bar vvvvvv

    $param = sanitize_key($atts['search_param']);
    $q = ($search_term_override !== null)
          ? $search_term_override
          : (isset($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '');

    $args = [
        'post_type'      => CPT_Client::POST_TYPE,
        'posts_per_page' => -1,
        'orderby'        => $orderby,
        'order'          => $order,
        'no_found_rows'  => true,
    ];

    if ($q !== '') {
        $args['s'] = $q;

        // Only on newer WP — fine to omit if unknown; we’ll also do a strict fallback below.
        if (method_exists('WP_Query', 'parse_search')) {
            $args['search_columns'] = ['post_title'];
        }
    }

    $clients = get_posts($args);

    // Fallback: enforce title-only filter in PHP to guarantee behavior
    if ($q !== '') {
        $needle = mb_strtolower($q);
        $clients = array_values(array_filter($clients, function($p) use ($needle) {
            return mb_stripos(get_the_title($p), $needle) !== false;
        }));
    }

    if (empty($clients)) {
        return $q === '' ? '<p>No clients yet.</p>'
                         : '<p>No results for <strong>' . esc_html($q) . '</strong>.</p>';
    }

    //searchbar  ^^^^^^


    /*$clients = get_posts([
        'post_type'      => CPT_Client::POST_TYPE,
        'posts_per_page' => -1,
        'orderby'        => $orderby,
        'order'          => $order,
        'no_found_rows'  => true,
    ]);

    */
    if (empty($clients)) return '<p>No clients yet.</p>';

$grid_style = sprintf(
  'display:grid;grid-template-columns:repeat(%d, minmax(0,1fr));gap:16px;',
  $columns
);


    ob_start(); ?>
    <div class="kcfh-grid-wrap">
    <div class="kcfh-stream-grid" style="<?= esc_attr($grid_style) ?>">
      <?php foreach ($clients as $p):
        $name         = get_the_title($p);
        $dob          = get_post_meta($p->ID, '_kcfh_dob', true);
        $dod          = get_post_meta($p->ID, '_kcfh_dod', true);
        $vod_playback = get_post_meta($p->ID, '_kcfh_playback_id', true);

        $is_live  = ($p->ID === $live_client_id) && !empty($live_playback);
        $playback = $is_live ? $live_playback : $vod_playback;

        if (!$playback && !$include_empty) continue;

        $thumb   = $playback ? self::thumbnail_url($playback, 640, 360, 2) : '';
        
        //Cards always link back to page we're on using 'kcfh_pb' even when the grid HTML was create by AJAX
        $detail_page = !empty($atts['detail_page']) ? esc_url_raw($atts['detail_page']) : '';
        $base_url = $detail_page ?: self::current_page_url();
        $link    = $playback 
        ? add_query_arg('kcfh_pb', rawurlencode($playback), $base_url) 
        : '#';


        $dob_str = $dob ? date_i18n(get_option('date_format'), strtotime($dob)) : '';
        $dod_str = $dod ? date_i18n(get_option('date_format'), strtotime($dod)) : '';
        $dates   = trim($dob_str . (($dob_str && $dod_str) ? ' – ' : '') . $dod_str);
      ?>
        <a class="kcfh-thumb<?= $playback ? '' : ' kcfh-thumb-disabled' ?>"
           href="<?= esc_url($link) ?>"
           aria-label="Open video for <?= esc_attr($name) ?>">
          <span class="kcfh-thumb-inner">
            <?php if ($thumb): ?>
              <img src="<?= esc_url($thumb) ?>" alt="" loading="lazy"
                   <?php if ($poster_fallback): ?>
                     onerror="this.onerror=null;this.src='<?= esc_url($poster_fallback) ?>';"
                   <?php endif; ?>>
            <?php else: ?>
              <span class="kcfh-thumb-placeholder"></span>
            <?php endif; ?>
            <span class="kcfh-play-badge" aria-hidden="true">▶</span>
            <?php if ($is_live): ?><span class="kcfh-live-badge" aria-hidden="true">LIVE</span><?php endif; ?>
          </span>

          <div class="kcfh-stream-meta">
            <div class="kcfh-stream-title"><?= esc_html($name) ?></div>
            <?php if ($dates): ?><div class="kcfh-stream-subtle"><?= esc_html($dates) ?></div><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
</div>
    <?php
    return ob_get_clean();
}

private static function current_page_url() {
    // During AJAX, prefer referer; otherwise use the current front-end URL
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $ref = wp_get_referer();
        if ($ref) return $ref;
    }
    // Fallback
    return home_url(add_query_arg([], sanitize_text_field($_SERVER['REQUEST_URI'])));
}

private static function render_mux_grid($assets, $columns, $poster_fallback, $showtitle, $detail_page, $next) {
    $grid_style = sprintf('grid-template-columns:repeat(%d, minmax(0,1fr));', $columns);

    ob_start(); ?>
    <style>figure#FrontImage{display:block !important;}</style>
    <div class="kcfh-stream-grid" style="<?= esc_attr($grid_style) ?>">
      <?php foreach ($assets as $asset):
        $playback = Asset_Service::first_public_playback_id($asset);
        if (!$playback) continue;

        $thumb = self::thumbnail_url($playback, 640, 360, 2);
        $link  = $detail_page
                   ? add_query_arg('kcfh_pb', rawurlencode($playback), $detail_page)
                   : add_query_arg('kcfh_pb', rawurlencode($playback)); // same page

        // Best label
        $label = !empty($asset['title']) ? $asset['title']
               : (!empty($asset['passthrough']) ? $asset['passthrough'] : ('Asset ' . $asset['id']));

        // Title text only if showtitle="true"
        $title_text = $showtitle ? $label : '';

        $createdAt = !empty($asset['created_at'])
          ? date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($asset['created_at']))
          : '';
      ?>
        <a class="kcfh-thumb" href="<?= esc_url($link) ?>" aria-label="Open video">
          <span class="kcfh-thumb-inner">
            <img src="<?= esc_url($thumb) ?>" alt="" loading="lazy"
                 <?php if ($poster_fallback): ?>
                   onerror="this.onerror=null;this.src='<?= esc_url($poster_fallback) ?>';"
                 <?php endif; ?>>
            <span class="kcfh-play-badge" aria-hidden="true">▶</span>
          </span>
          <?php if ($title_text || $createdAt): ?>
            <div class="kcfh-stream-meta">
              <?php if ($title_text): ?>
                <div class="kcfh-stream-title"><?= esc_html($title_text) ?></div>
              <?php endif; ?>
              <?php if ($createdAt): ?>
                <div class="kcfh-stream-subtle">Created: <?= esc_html($createdAt) ?></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($next)):
      $href = esc_url(add_query_arg(['kcfh_page' => $next], remove_query_arg('kcfh_page'))); ?>
      <div class="kcfh-stream-pager">
        <a class="kcfh-stream-next" href="<?= $href ?>">Load more</a>
      </div>
    <?php endif; ?>

    <?php
    return ob_get_clean();
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

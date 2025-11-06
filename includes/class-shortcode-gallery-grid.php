<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

/**
 * Front-end gallery grid + single view for Clients.
 *
 * Shortcode: [KCFHGallery columns="2" searchbar="true"]
 *
 * URL behavior:
 * - Grid view:    /your-page
 * - Single view:  /your-page?client=<client-slug>
 *
 * Data sources (from existing project):
 * - CPT: kcfh_client
 * - Meta: _kcfh_dob, _kcfh_dod, _kcfh_playback_id
 * - Options (globals): Admin_UI::OPT_LIVE_CLIENT, Admin_UI::OPT_LIVE_PLAYBACK
 */
class Shortcode_Gallery_Grid {

  // Defaults
  private const DEFAULT_COLUMNS = 2;
  private const SHORTCODE_TAG   = 'KCFHGallery';

  public static function init() {
    add_shortcode(self::SHORTCODE_TAG, [__CLASS__, 'render']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

    // AJAX search (public + logged-in)
    add_action('wp_ajax_kcfh_gallery_search', [__CLASS__, 'ajax_search']);
    add_action('wp_ajax_nopriv_kcfh_gallery_search', [__CLASS__, 'ajax_search']);
  }

  /** Register styles/scripts (deferred enqueue inside render) */
  public static function register_assets() {
    wp_register_style(
      'kcfh-gallery-grid',
      plugins_url('../assets/gallery-grid.css', __FILE__),
      [],
      defined('KCFH_STREAMING_VERSION') ? KCFH_STREAMING_VERSION : '1.0.0'
    );

    wp_register_script(
      'kcfh-gallery-grid',
      plugins_url('../assets/gallery-grid.js', __FILE__),
      ['jquery'],
      defined('KCFH_STREAMING_VERSION') ? KCFH_STREAMING_VERSION : '1.0.0',
      true
    );
  }

  /** Shortcode entry */
  public static function render($atts = []) {
    $atts = shortcode_atts([
      'columns'   => self::DEFAULT_COLUMNS,
      'searchbar' => 'true', // "true" | "false"
      // Optional: include_empty (cards with no playback?) – default hide
      'include_empty' => 'false',
    ], $atts, self::SHORTCODE_TAG);

    $columns        = max(1, (int)$atts['columns']);
    $show_searchbar = filter_var($atts['searchbar'], FILTER_VALIDATE_BOOLEAN);
    $include_empty  = filter_var($atts['include_empty'], FILTER_VALIDATE_BOOLEAN);

    // Enqueue + localize
    wp_enqueue_style('kcfh-gallery-grid');
    wp_enqueue_script('kcfh-gallery-grid');
    wp_localize_script('kcfh-gallery-grid', 'KCFH_GALLERY_GRID', [
      'ajaxUrl'      => admin_url('admin-ajax.php'),
      'nonce'        => wp_create_nonce('kcfh_gallery_grid'),
      'columns'      => $columns,
      'includeEmpty' => $include_empty,
      'queryParam'   => 'client', // keep in one place
    ]);

    // Router: single vs grid
    $requested_slug = isset($_GET['client']) ? sanitize_title(wp_unslash($_GET['client'])) : '';

    ob_start();

    echo '<div class="kcfh-gallery-wrap">';

    if ($requested_slug) {
      echo self::render_single_view($requested_slug);
    } else {
      if ($show_searchbar) {
        echo self::render_search_form();
      }
      echo self::render_grid_container($columns, $include_empty);
    }

    echo '</div>';

    return ob_get_clean();
  }

  /* ---------- Renderers ---------- */

  private static function render_search_form(): string {
    ob_start(); ?>
      <form class="kcfh-gallery-search" id="kcfh-gallery-search" action="" method="get" onsubmit="return false;">
        <label class="screen-reader-text" for="kcfh_gallery_q">Search clients</label>
        <input type="text" id="kcfh_gallery_q" name="kcfh_q" placeholder="Search by name…" autocomplete="off" />
        <button type="button" id="kcfh_gallery_btn">Search</button>
        <button type="button" id="kcfh_gallery_clear">Clear</button>
      </form>
    <?php
    return ob_get_clean();
  }

  private static function render_grid_container(int $columns, bool $include_empty): string {
    // Initial full list (no query)
    $clients = self::get_clients([
      'search'        => '',
      'include_empty' => $include_empty,
    ]);

    ob_start();
    printf('<div id="kcfh-gallery-grid" class="kcfh-grid cols-%d">', $columns);

    if ($clients) {
      foreach ($clients as $client) {
        echo self::render_client_card($client);
      }
    } else {
      echo '<p class="kcfh-empty">No clients found.</p>';
    }

    echo '</div>';
    return ob_get_clean();
  }

  private static function render_client_card(\WP_Post $client): string {
    $client_id       = $client->ID;
    $client_title    = get_the_title($client_id);
    $client_slug     = $client->post_name;

    $dob = get_post_meta($client_id, '_kcfh_dob', true);
    $dod = get_post_meta($client_id, '_kcfh_dod', true);

    // Determine best playback for thumbnail (prefer live if this is the live client)
    $playback_for_thumb = self::choose_effective_playback_id_for_card($client_id);

    // Thumbnail URL: Mux Images API, or fallback to featured image
    $thumb_url = '';
    if ($playback_for_thumb) {
      $thumb_url = self::thumbnail_url($playback_for_thumb, 640, 360, 2);
    }
    if (!$thumb_url) {
      $thumb_id  = (int) get_post_thumbnail_id($client_id);
      $img       = $thumb_id ? wp_get_attachment_image_src($thumb_id, 'medium_large') : false;
      $thumb_url = $img ? $img[0] : '';
    }

    $dates_str = '';
    if ($dob || $dod) {
      $dates_str = esc_html(($dob ?: '—') . ' – ' . ($dod ?: '—'));
    }

    $single_url = esc_url(add_query_arg(['client' => $client_slug]));

    $badge_html = '';
    if (self::is_client_live($client_id)) {
      $badge_html = '<span class="kcfh-badge kcfh-live">LIVE</span>';
    } elseif ($playback_for_thumb) {
      $badge_html = '<span class="kcfh-badge kcfh-vod">VOD</span>';
    }

    ob_start(); ?>
      <article class="kcfh-card">
        <a class="kcfh-card-link" href="<?= $single_url ?>">
          <div class="kcfh-thumb">
            <?php if ($badge_html) echo $badge_html; ?>
            <?php if ($thumb_url): ?>
              <img src="<?= esc_url($thumb_url) ?>" alt="" loading="lazy" />
            <?php else: ?>
              <div class="kcfh-thumb-fallback">No preview</div>
            <?php endif; ?>
          </div>
          <header class="kcfh-card-meta">
            <h3 class="kcfh-card-title"><?= esc_html($client_title) ?></h3>
            <?php if ($dates_str): ?>
              <div class="kcfh-card-dates"><?= $dates_str ?></div>
            <?php endif; ?>
          </header>
        </a>
      </article>
    <?php
    return ob_get_clean();
  }

  private static function render_single_view(string $client_slug): string {
    $client = get_page_by_path($client_slug, OBJECT, 'kcfh_client');
    if (!$client) {
      return '<p>Client not found.</p><p><a class="kcfh-back" href="' . esc_url(remove_query_arg('client')) . '">← Back</a></p>';
    }

    $client_id    = $client->ID;
    $client_title = get_the_title($client_id);

    $playback_id = self::choose_effective_playback_id_for_single($client_id);
    $poster_url  = $playback_id ? self::thumbnail_url($playback_id, 1280, 720, 2) : '';

    $is_live     = self::is_client_live($client_id);
    $back_url    = esc_url(remove_query_arg('client'));



// DEBUG: show decision (remove later)
if (defined('KCFH_DEBUGMODE') && KCFH_DEBUGMODE) {
  $vod_meta      = get_post_meta($client_id, '_kcfh_playback_id', true);
  $live_client   = (int) get_option(Admin_UI::OPT_LIVE_CLIENT, 0);
  $live_playback = get_option(Admin_UI::OPT_LIVE_PLAYBACK, '');
  echo '<div style="background:#fff3cd;border:1px solid #ffeeba;padding:.6rem .8rem;margin:.5rem 0;border-radius:6px;font:12px/1.4 system-ui">';
  echo '<strong>DEBUG (single decision)</strong><br>';
  echo 'client_id: '. (int)$client_id .'<br>';
  echo 'is_live?: '. (self::is_client_live($client_id) ? 'yes' : 'no') .'<br>';
  echo 'live_client_id(opt): '. $live_client .'<br>';
  echo 'live_playback(opt): '. esc_html($live_playback) .'<br>';
  echo 'vod_playback(meta): '. esc_html($vod_meta) .'<br>';
  echo 'final playback_id: '. esc_html($playback_id) .'<br>';
  echo 'stream-type: '. ($is_live ? 'live' : 'on-demand') .'<br>';
  echo '</div>';
}



    // Simple single view with mux-player (script assumed to be already enqueued somewhere globally)
    ob_start(); ?>
      <div class="kcfh-single">
        <a class="kcfh-back" href="<?= $back_url ?>">← Back</a>
        <h2 class="kcfh-single-title">
          <?= esc_html($client_title) ?>
          <?php if ($is_live): ?><span class="kcfh-badge kcfh-live">LIVE</span><?php endif; ?>
        </h2>
        <?php if (!$playback_id): ?>
          <p>Video unavailable.</p>
        <?php else: ?>
          <mux-player
            stream-type="<?= $is_live ? 'live' : 'on-demand' ?>"
            playback-id="<?= esc_attr($playback_id) ?>"
            poster="<?= esc_url($poster_url) ?>"
            playsinline
            style="aspect-ratio:16/9;width:100%;max-width:1200px;"
          ></mux-player>
        <?php endif; ?>
      </div>
    <?php
    return ob_get_clean();
  }

  /* ---------- AJAX ---------- */

  public static function ajax_search() {
    check_ajax_referer('kcfh_gallery_grid', 'nonce');

    $query        = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $columns      = isset($_POST['columns']) ? max(1, (int) $_POST['columns']) : self::DEFAULT_COLUMNS;
    $include_empty= isset($_POST['includeEmpty']) ? (bool) $_POST['includeEmpty'] : false;

    $clients = self::get_clients([
      'search'        => $query,
      'include_empty' => $include_empty,
    ]);

    ob_start();
    printf('<div class="kcfh-grid cols-%d">', $columns);
    if ($clients) {
      foreach ($clients as $client) {
        echo self::render_client_card($client);
      }
    } else {
      echo '<p class="kcfh-empty">No matching clients.</p>';
    }
    echo '</div>';

    wp_send_json_success(['html' => ob_get_clean()]);
  }

  /* ---------- Data helpers ---------- */

  /**
   * Fetch clients with optional name search; skip empty cards unless include_empty=true.
   */
  private static function get_clients(array $args): array {
    $search        = isset($args['search']) ? $args['search'] : '';
    $include_empty = !empty($args['include_empty']);

    $q = [
      'post_type'      => 'kcfh_client',
      'post_status'    => 'publish',
      'posts_per_page' => 200,
      'orderby'        => 'title',
      'order'          => 'ASC',
      's'              => $search,
      'no_found_rows'  => true,
    ];

    $posts = get_posts($q);
    if (!$posts) return [];

    if (!$include_empty) {
      $filtered = [];
      foreach ($posts as $p) {
        if (self::is_client_live($p->ID)) { $filtered[] = $p; continue; }
        $vod_playback = get_post_meta($p->ID, '_kcfh_playback_id', true);
        if ($vod_playback) $filtered[] = $p;
      }
      return $filtered;
    }
    return $posts;
  }

  private static function is_client_live(int $client_id): bool {
    $live_client_id = (int) get_option(Admin_UI::OPT_LIVE_CLIENT, 0);
    $live_playback  = get_option(Admin_UI::OPT_LIVE_PLAYBACK, '');
    return ($live_client_id === $client_id) && !empty($live_playback);
  }

  /** For cards: prefer live playback if this client is live; else VOD playback */
  private static function choose_effective_playback_id_for_card(int $client_id): string {
    if (self::is_client_live($client_id)) {
      $live_playback = get_option(Admin_UI::OPT_LIVE_PLAYBACK, '');
      if ($live_playback) return $live_playback;
    }
    $vod_playback = get_post_meta($client_id, '_kcfh_playback_id', true);
    return $vod_playback ?: '';
  }

  /** For single: same decision, but strict (if neither, return empty) */
  private static function choose_effective_playback_id_for_single(int $client_id): string {
    return self::choose_effective_playback_id_for_card($client_id);
  }

  /** Mux Images API helper */
  private static function thumbnail_url(string $playback_id, int $w, int $h, int $time=2): string {
    if (!$playback_id) return '';
    $w = max(16, $w); $h = max(16, $h);
    // smartcrop gives a nicer auto-crop
    return sprintf('https://image.mux.com/%s/thumbnail.jpg?width=%d&height=%d&time=%d&fit_mode=smartcrop',
      rawurlencode($playback_id), $w, $h, $time
    );
  }
}

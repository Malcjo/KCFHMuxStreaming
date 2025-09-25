<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class Shortcode_Client_Search {
  public static function init() {
    add_shortcode('kcfh_client_search', [__CLASS__, 'render']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

    // AJAX (logged-in + public)
    add_action('wp_ajax_kcfh_client_search', [__CLASS__, 'ajax_search']);
    add_action('wp_ajax_nopriv_kcfh_client_search', [__CLASS__, 'ajax_search']);
  }

  public static function register_assets() {
    // optional search CSS
    wp_register_style(
      'kcfh-client-search',
      KCFH_STREAMING_URL . 'assets/client-search.css',
      [],
      KCFH_STREAMING_VERSION
    );
  }

  public static function render($atts = []) {
    $atts = shortcode_atts([
      // Search UI
      'param'           => 'kcfh_q',
      'placeholder'     => 'Search by name…',
      'button_label'    => 'Search',
      'clear_label'     => 'Clear',
      'autofocus'       => 'false',

      // Grid options (passed to gallery)
      'columns'          => 2,
      'include_empty'    => 'false',
      'client_order'     => 'date',   // date|title
      'client_order_dir' => 'desc',   // asc|desc
      'poster_fallback'  => '',
    ], $atts, 'kcfh_client_search');

    wp_enqueue_style('kcfh-client-search');
    wp_enqueue_style('kcfh-streaming-gallery'); // ensure grid styles

    $param        = sanitize_key($atts['param']);
    $q            = isset($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
    $autofocus    = strtolower($atts['autofocus']) === 'true' ? 'autofocus' : '';
    $columns      = min(4, max(1, (int)$atts['columns']));
    $incEmpty     = strtolower($atts['include_empty']) === 'true' ? 'true' : 'false';
    $order        = ($atts['client_order'] === 'title') ? 'title' : 'date';
    $dir          = (strtolower($atts['client_order_dir']) === 'asc') ? 'asc' : 'desc';
    $poster       = $atts['poster_fallback'] ? esc_url_raw($atts['poster_fallback']) : '';
    $ajax_url     = admin_url('admin-ajax.php');

    // unique ids so multiple shortcodes on one page don’t collide
    $uid       = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('kcfh_', true);
    $formId    = 'kcfh-search-form-' . $uid;
    $resultsId = 'kcfh-search-results-' . $uid;

    // initial grid render (server-side), using the gallery’s helper
    $gallery_atts = [
      'client_order'     => $order,
      'client_order_dir' => $dir,
      'include_empty'    => $incEmpty,
      'search_param'     => $param,
    ];
    $initial_grid = \KCFH\Streaming\Shortcode_Gallery::render_clients_grid(
      $gallery_atts,
      $columns,
      $poster,
      $q // override term
    );

    ob_start(); ?>
    <form id="<?= esc_attr($formId) ?>" class="kcfh-searchbar" method="get" action="">
      <label class="kcfh-searchbar-label" for="kcfh-search-input-<?= esc_attr($param) ?>">Search</label>
      <div class="kcfh-searchbar-row">
        <input
          id="kcfh-search-input-<?= esc_attr($param) ?>"
          class="kcfh-searchbar-input"
          type="text"
          name="<?= esc_attr($param) ?>"
          value="<?= esc_attr($q) ?>"
          placeholder="<?= esc_attr($atts['placeholder']) ?>"
          <?= $autofocus ?>
        >
        <button class="kcfh-searchbar-btn kcfh-searchbar-btn--primary" type="submit">
          <?= esc_html($atts['button_label']) ?>
        </button>
        <a class="kcfh-searchbar-btn kcfh-searchbar-btn--ghost" href="#">
          <?= esc_html($atts['clear_label']) ?>
        </a>
      </div>
    </form>

    <div id="<?= esc_attr($resultsId) ?>"><?= $initial_grid ?></div>

    <script>
    (function(){
      const form    = document.getElementById('<?= esc_js($formId) ?>');
      const input   = form.querySelector('input[name="<?= esc_js($param) ?>"]');
      const clear   = form.querySelector('.kcfh-searchbar-btn--ghost');
      const results = document.getElementById('<?= esc_js($resultsId) ?>');

      function updateURL(term){
        const url = new URL(window.location);
        if (term) url.searchParams.set('<?= esc_js($param) ?>', term);
        else url.searchParams.delete('<?= esc_js($param) ?>');
        window.history.replaceState(null, '', url);
      }

      function render(term){
        const params = new URLSearchParams({
          action: 'kcfh_client_search',
          q: term || '',
          columns: '<?= (int)$columns ?>',
          include_empty: '<?= esc_js($incEmpty) ?>',
          order: '<?= esc_js($order) ?>',
          dir: '<?= esc_js($dir) ?>',
          poster: '<?= esc_js($poster) ?>'
        });
        fetch('<?= esc_url($ajax_url) ?>?' + params.toString(), { credentials: 'same-origin' })
          .then(r => r.json())
          .then(data => {
            if (data && data.success && data.data && typeof data.data.html === 'string') {
              results.innerHTML = data.data.html;
              updateURL(term);
            }
          });
      }

      form.addEventListener('submit', function(e){
        e.preventDefault();
        render(input.value.trim());
      });

      clear.addEventListener('click', function(e){
        e.preventDefault();
        input.value = '';
        render('');
      });
    })();
    </script>
    <?php
    return ob_get_clean();
  }

public static function ajax_search() {
  $q        = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
  $columns  = isset($_GET['columns']) ? max(1, min(4, (int)$_GET['columns'])) : 2;
  $incEmpty = (isset($_GET['include_empty']) && $_GET['include_empty'] === 'true') ? 'true' : 'false';
  $order    = (isset($_GET['order']) && strtolower($_GET['order']) === 'title') ? 'title' : 'date';
  $dir      = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'asc' : 'desc';
  $poster   = isset($_GET['poster']) ? esc_url_raw($_GET['poster']) : '';

  $gallery_atts = [
    'client_order'     => $order,
    'client_order_dir' => $dir,
    'include_empty'    => $incEmpty,
    'search_param'     => 'kcfh_q',
  ];

  $html = \KCFH\Streaming\Shortcode_Gallery::render_clients_grid(
    $gallery_atts, $columns, $poster, $q   // <- pass $q here
  );

  wp_send_json_success(['html' => $html]);
}

}

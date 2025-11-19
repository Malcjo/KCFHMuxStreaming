<?php
namespace KCFH\STREAMING;

if (!defined('ABSPATH')) { exit; }

/**
 * Public shortcode + AJAX wiring for the gallery.
 *
 * Shortcode: [KCFHGallery searchbar="true"]
 *
 * - Grid view:    /your-page
 * - Single view:  /your-page?kcfh_client=<client-slug>
 */
class Gallery_Display
{
    public const AJAX_ACTION   = 'kcfh_gallery_search';
    public const NONCE_ACTION  = 'kcfh_gallery_nonce';
    public const SCRIPT_HANDLE = 'kcfh-gallery-js';
    public const STYLE_HANDLE  = 'kcfh-gallery-css';

    /**
     * Register shortcode + hooks.
     */
    public static function bootstrap(): void
    {
        add_shortcode('KCFHGallery', [__CLASS__, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        // AJAX endpoints (public + logged-in)
        add_action('wp_ajax_' . self::AJAX_ACTION,        [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [__CLASS__, 'ajax_search']);
    }

    /**
     * Register front-end assets (not enqueued yet).
     */
    public static function register_assets(): void
    {
        wp_register_script(
            self::SCRIPT_HANDLE,
            plugins_url('../../assets/kcfh_gallery.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url('../../assets/kcfh_gallery.css', __FILE__),
            [],
            '1.0.0'
        );
    }

    /**
     * Shortcode entry point.
     *
     * - Handles atts
     * - Enqueues & localises scripts
     * - Routes to single or grid view.
     */
    public static function render_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'searchbar' => 'false',
        ], $atts, 'KCFHGallery');

        $show_search = filter_var($atts['searchbar'], FILTER_VALIDATE_BOOLEAN);

        // If ?kcfh_client=<slug> is present, show single view
        $slug = isset($_GET['kcfh_client'])
            ? sanitize_title(wp_unslash($_GET['kcfh_client']))
            : '';

        self::enqueue_and_localise_assets();

        ob_start();

        if ($slug) {
            echo Gallery_Single::render_single_view($slug);
        } else {
            echo Gallery_Grid::render_gallery_view($show_search);
        }

        return ob_get_clean();
    }

    /**
     * Enqueue and localise scripts/styles once.
     */
    private static function enqueue_and_localise_assets(): void
    {
        wp_enqueue_style(self::STYLE_HANDLE);
        wp_enqueue_script(self::SCRIPT_HANDLE);

        $nonce = wp_create_nonce(self::NONCE_ACTION);

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'KCFH_Gallery',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action'  => self::AJAX_ACTION,
                'nonce'   => $nonce,
            ]
        );
    }

    /**
     * AJAX: return filtered cards HTML for q.
     */
    public static function ajax_search(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $q = isset($_POST['q'])
            ? sanitize_text_field(wp_unslash($_POST['q']))
            : '';

        $clients = Gallery_Grid::query_clients_for_gallery($q);
        $html    = Gallery_Grid::render_client_cards_html($clients);

        wp_send_json_success(['html' => $html]);
    }
}

<?php
namespace KCFH\STREAMING;

use KCFH\Streaming\Front\Search_bar;

if (!defined('ABSPATH')) { exit; }

/**
 * Handles the gallery grid: querying clients and rendering cards.
 */
class Gallery_Grid
{
    /**
     * Server-rendered gallery view (search bar + grid).
     *
     * @param bool $show_search
     * @return string
     */
    public static function render_gallery_view(bool $show_search = false): string
    {
        ob_start();

        if ($show_search) {
            echo Search_Bar::render();
            //self::render_search_form();
        }

        $page = isset($_GET['kcfh_page']) ? max(1, (int) $_GET['kcfh_page']) : 1;
        $per_page = 10;

        $data = self::query_clients_for_gallery('', $page, $per_page);
        $items_html = self::render_client_cards_html($data['items']);
        $pager_html = self::render_client_pagination($data['current_page'], $data['max_pages']);

        //$clients    = self::query_clients_for_gallery();
        //$items_html = self::render_client_cards_html($clients);

        ?>
        <div class="kcfh-gallery"
             id="kcfhGallery"
             data-page-url="<?php echo esc_url(Gallery_Utils::current_page_url()); ?>">
            <?php echo $items_html; ?>
            <?php echo $pager_html; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Query clients that have a playback (live or VOD).
     * If $search provided, search by post_title LIKE $search.
     *
     * @param string $search
     * @return array[]
     */
    public static function query_clients_for_gallery(string $search = '', int $page = 1, int $per_page = 10): array
    {
        $page = max(1, $page);
        $per_page = max(1, $per_page);

        $q = new \WP_Query([
            'post_type' => 'kcfh_client',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'orderby' => 'title',
            'order' => 'ASC',
            's' => $search ?:'',
            'paged' => $page,
            'fields' => 'ids',
            'no_found_rows' => false,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'=>'_kcfh_playback_id',
                    'compare'=>'EXISTS',
                ],
                [
                    'key'=>'_kcfh_playback_id',
                    'value'=>'',
                    'compare'=>'!=',
                ],
            ],
        ]);

        $ids = $q->posts;

        if(!$ids){
            return[
                'items' => [],
                'current_page' => $page,
                'max_pages' => 0,
            ];
        }

        /*
        $args = [
            'post_type'      => 'kcfh_client',
            'post_status'    => 'publish',
            'posts_per_page' => 100, // adjust if needed
            'orderby'        => 'title',
            'order'          => 'ASC',
            's'              => $search ?: '',
            'fields'         => 'ids',
        ];

        $ids = get_posts($args);
        if (!$ids) {
            return [];
        }
            */



        $results = [];
        foreach ($ids as $id) {
            $id    = (int) $id;
            $play  = Gallery_Utils::determine_playback_for_client($id);

            if ($play) {
                $results[] = [
                    'ID'          => $id,
                    'playback_id' => $play['playback_id'],
                    'is_live'     => !empty($play['is_live']),
                ];
            }
        }

        return [
            'items'        => $results,
            'current_page' => $page,
            'max_pages'    => (int) $q->max_num_pages,
        ];
    }

    /**
     * Render cards HTML for the gallery grid.
     *
     * @param array $items
     * @return string
     */
    public static function render_client_cards_html(array $items): string
    {
        if (!$items) {
            return '<p class="kcfh-empty">No matching clients.</p>';
        }

        $page_url = esc_url(Gallery_Utils::current_page_url());
        $html     = '<div class="kcfh-grid">';

        foreach ($items as $row) {
            $id     = (int) $row['ID'];
            $name   = get_the_title($id); //raw
            $name_esc = esc_html($name); //for display
            $slug   = esc_attr(get_post_field('post_name', $id));
            $dob    = esc_html(get_post_meta($id, '_kcfh_dob', true));
            $dod    = esc_html(get_post_meta($id, '_kcfh_dod', true));
            $isLive = !empty($row['is_live']);

            $link = esc_url(add_query_arg('kcfh_client', $slug, $page_url));

            // Use Mux thumbnail if we have a playback id (live thumb works too)
            $thumb = '';
            if (!empty($row['playback_id'])) {
                $pbid  = esc_attr($row['playback_id']);
                $thumb = sprintf(
                    'https://image.mux.com/%s/thumbnail.jpg?width=640&height=360&fit_mode=smartcrop&time=2',
                    $pbid
                );
            }

            $dateLine = trim("{$dob} - {$dod}", " - ");
            $badge = $isLive ? '<span class="kcfh-badge-live">LIVE</span>' : '';

            //Data-name added here
            //.= is a string concatination
            $html .= '<a class="kcfh-card" href="' . $link . '" data-name="' . esc_attr($name) . '">';
            $html .= '<div class="kcfh-thumb-wrap">';
            if ($thumb) {
                $html .= '<img class="kcfh-thumb" src="' . esc_url($thumb) . '" alt="" loading="lazy" />';
            } else {
                $html .= '<div class="kcfh-thumb kcfh-thumb--placeholder"></div>';
            }
            $html .= $badge;
            $html .= '</div>';
            $html .= '<div class="kcfh-card-meta">';
            $html .= '<div class="kcfh-name">' . $name_esc . '</div>';
            if ($dateLine) {
                $html .= '    <div class="kcfh-dates">' . esc_html($dateLine) . '</div>';
            }
            $html .= '  </div>';
            $html .= '</a>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function render_client_pagination(int $current, int $max){
        if($max <= 1){
            return '';
        }

        $base_url = Gallery_Utils::current_page_url();
        $html = '<nav class="kcfh-pagination">';

        //previous
        if($current > 1){
            $prev_url = esc_url(add_query_arg('kcfh_page', $current - 1, $base_url));
            $html .= '<a class="kcfh-page-link kcfh-page-prev" href="' . $prev_url . '">&laquo; Previous</a>';
        }

        //status
        $html .= '<span class="kcfh-page-status">Page ' . esc_html($current) . ' of ' . esc_html($max) . '</span>';

        // Next
        if($current < $max){
            $next_url = esc_url(add_query_arg('kcfh_page',$current + 1, $base_url));
            $html .= '<a class="kcfh-page-link kcfh-page-next" href="' . $next_url . '">Next &raquo;</a>';
        }
        $html .= '</nav>';

        return $html;
    }
}

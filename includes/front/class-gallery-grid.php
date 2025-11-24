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

        $clients    = self::query_clients_for_gallery();
        $items_html = self::render_client_cards_html($clients);

        ?>
        <div class="kcfh-gallery"
             id="kcfhGallery"
             data-page-url="<?php echo esc_url(Gallery_Utils::current_page_url()); ?>">
            <?php echo $items_html; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Search form markup.
     */
    /*
    private static function render_search_form(): void
    {
        ?>
        <form class="kcfh-search" id="kcfhSearchForm">
            <input type="text"
                   id="kcfhSearchInput"
                   name="q"
                   placeholder="Search by name…" />
            <button type="submit">Search</button>
            <button type="button" id="kcfhSearchClear">Clear</button>
        </form>
        <?php
    }
    */

    /**
     * Query clients that have a playback (live or VOD).
     * If $search provided, search by post_title LIKE $search.
     *
     * @param string $search
     * @return array[]
     */
    public static function query_clients_for_gallery(string $search = ''): array
    {
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

        return $results;
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
}

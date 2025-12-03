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
        $items_html = self::render_client_cards_html_with_base(
            $data['items'],
        Gallery_Utils::current_page_url());
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
    $page     = max(1, $page);
    $per_page = max(1, $per_page);

    // Step 1: only fetch clients that are explicitly allowed to show in gallery
    $q = new \WP_Query([
        'post_type'      => 'kcfh_client',
        'post_status'    => 'publish',
        'posts_per_page' => -1,          // get all, we'll paginate AFTER filtering + ordering
        'orderby'        => 'title',
        'order'          => 'ASC',
        's'              => $search ?: '',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            // FINAL GATE: only show when checkbox is ON
            [
                'key'     => '_kcfh_show_in_gallery',
                'value'   => '1',
                'compare' => '=',
            ],
        ],
    ]);

    $ids = $q->posts ?: [];

    if (!$ids) {
        return [
            'items'        => [],
            'current_page' => $page,
            'max_pages'    => 0,
        ];
    }

    $usable = [];
    $now    = time();

    foreach ($ids as $id) {
        $id = (int) $id;

        // Extra safety: if someone messed with meta manually, honour it again.
        $flag = get_post_meta($id, '_kcfh_show_in_gallery', true);
        if ($flag !== '1') {
            continue; // hard stop, no matter what playback/schedule says
        }

        // A) Playback (live or VOD)
        $play        = Gallery_Utils::determine_playback_for_client($id);
        $hasPlayback = (bool) $play;

        // B) Scheduled future (start time in the future)
        $startUtc         = (int) get_post_meta($id, \KCFH\Streaming\CPT_Client::META_START_AT, true);
        $isScheduledFuture = $startUtc && ($startUtc > $now);

        // If neither playback nor scheduled, skip
        if (!$hasPlayback && !$isScheduledFuture) {
            continue;
        }

        $usable[] = [
            'ID'           => $id,
            'playback_id'  => $hasPlayback ? $play['playback_id'] : '',
            'is_live'      => $hasPlayback ? !empty($play['is_live']) : false,
            'is_scheduled' => !$hasPlayback && $isScheduledFuture,
            'start_utc'    => $isScheduledFuture ? $startUtc : 0,
        ];
    }

    // If nothing usable, bail early
    if (empty($usable)) {
        return [
            'items'        => [],
            'current_page' => $page,
            'max_pages'    => 0,
        ];
    }

    // Step 2: reorder → LIVE first, then UPCOMING (scheduled future), then PAST VOD
    $live     = [];
    $upcoming = [];
    $past     = [];

    foreach ($usable as $row) {
        if (!empty($row['is_live'])) {
            $live[] = $row;
        } elseif (!empty($row['is_scheduled'])) {
            $upcoming[] = $row;
        } else {
            $past[] = $row;
        }
    }

    // (optional) sort upcoming by start time ascending
    usort($upcoming, function ($a, $b) {
        return ($a['start_utc'] <=> $b['start_utc']);
    });

    // Keep $live and $past in their existing order (which is already title ASC)
    $ordered = array_merge($live, $upcoming, $past);

    // Step 3: manual pagination AFTER ordering
    $total     = count($ordered);
    $max_pages = (int) ceil($total / $per_page);

    if ($max_pages < 1) {
        $max_pages = 1;
    }

    if ($page > $max_pages) {
        $page = $max_pages;
    }

    $offset = ($page - 1) * $per_page;
    $items  = array_slice($ordered, $offset, $per_page);

    return [
        'items'        => $items,
        'current_page' => $page,
        'max_pages'    => $max_pages,
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
            $isScheduled = !empty($row['is_scheduled']);
            $startUtc    = isset($row['start_utc']) ? (int) $row['start_utc'] : 0;

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
            
            // Build a status line with proper timezone conversion
            $statusLine = '';
            if ($isLive) {
                $statusLine = 'Live now';
            } elseif ($isScheduled && $startUtc) {
                $statusLine = 'Scheduled: ' . wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $startUtc,
                    wp_timezone()
                );
            } elseif ($dateLine) {
                $statusLine = $dateLine;
            }
            
            // Badge logic:
            $badge = '';
            if ($isLive) {
                $badge = '<span class="kcfh-badge-live">LIVE</span>';
            } elseif ($isScheduled) {
                $badge = '<span class="kcfh-badge-upcoming">UPCOMING</span>';
            }

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
                    if ($statusLine) {
            $html .= '    <div class="kcfh-dates">' . esc_html($statusLine) . '</div>';
            }

            /*
            if ($dateLine) {
                $html .= '<div class="kcfh-dates">' . esc_html($dateLine) . '</div>';
            }


            // Show “Streaming: <date/time>” for scheduled clients
            if ($isScheduled && $startUtc) {
                $when = date_i18n(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $startUtc
                );
                $html .= '<div class="kcfh-scheduled">Streaming: ' . esc_html($when) . '</div>';
            }
            */
            $html .= '</div>'; // .kcfh-card-meta
            $html .= '</a>';
        }

        $html .= '</div>';

        return $html;
    }

        public static function render_client_cards_html_with_base(array $items,  string $base_url): string
    {
        if (!$items) {
            return '<p class="kcfh-empty">No matching clients.</p>';
        }

        $html     = '<div class="kcfh-grid">';

        foreach ($items as $row) {
            $id     = (int) $row['ID'];
            $name   = get_the_title($id); //raw
            $name_esc = esc_html($name); //for display
            $slug   = esc_attr(get_post_field('post_name', $id));

            $dob    = esc_html(get_post_meta($id, '_kcfh_dob', true));
            $dod    = esc_html(get_post_meta($id, '_kcfh_dod', true));
            
            $isLive = !empty($row['is_live']);
            $isScheduled = !empty($row['is_scheduled']);
            $startUtc    = isset($row['start_utc']) ? (int) $row['start_utc'] : 0;

            $link = esc_url(add_query_arg('kcfh_client', $slug, $base_url));

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
            
            // Build a status line with proper timezone conversion
            $statusLine = '';
            if ($isLive) {
                $statusLine = 'Live now';
            } elseif ($isScheduled && $startUtc) {
                $statusLine = 'Scheduled: ' . wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $startUtc,
                    wp_timezone()
                );
            } elseif ($dateLine) {
                $statusLine = $dateLine;
            }
            
            // Badge logic:
            $badge = '';
            if ($isLive) {
                $badge = '<span class="kcfh-badge-live">LIVE</span>';
            } elseif ($isScheduled) {
                $badge = '<span class="kcfh-badge-upcoming">UPCOMING</span>';
            }

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
                    if ($statusLine) {
            $html .= '    <div class="kcfh-dates">' . esc_html($statusLine) . '</div>';
            }

            /*
            if ($dateLine) {
                $html .= '<div class="kcfh-dates">' . esc_html($dateLine) . '</div>';
            }


            // Show “Streaming: <date/time>” for scheduled clients
            if ($isScheduled && $startUtc) {
                $when = date_i18n(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $startUtc
                );
                $html .= '<div class="kcfh-scheduled">Streaming: ' . esc_html($when) . '</div>';
            }
            */
            $html .= '</div>'; // .kcfh-card-meta
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

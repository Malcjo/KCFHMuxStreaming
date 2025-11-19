<?php
namespace KCFH\STREAMING;

if (!defined('ABSPATH')) { exit; }

class Shortcode_KCFHGallery {

    const AJAX_ACTION   = 'kcfh_gallery_search';
    const NONCE_ACTION  = 'kcfh_gallery_nonce';
    const SCRIPT_HANDLE = 'kcfh-gallery-js';
    const STYLE_HANDLE  = 'kcfh-gallery-css';

    public static function bootstrap() {
        add_shortcode('KCFHGallery', [__CLASS__, 'render_shortcode']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        // AJAX endpoints (public + logged-in)
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [__CLASS__, 'ajax_search']);
    }

    public static function register_assets() {
        // JS
        wp_register_script(
            self::SCRIPT_HANDLE,
            plugins_url('../assets/kcfh_gallery.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        // CSS (minimal; adjust as needed or move to theme)
        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url('../assets/kcfh_gallery.css', __FILE__),
            [],
            '1.0.0'
        );
    }

    public static function render_shortcode($atts) {
        $atts = shortcode_atts([
            'searchbar' => 'false',
        ], $atts, 'KCFHGallery');

        $show_search = filter_var($atts['searchbar'], FILTER_VALIDATE_BOOLEAN);

        // Single view if ?kcfh_client=<slug>
        $slug = isset($_GET['kcfh_client']) ? sanitize_title(wp_unslash($_GET['kcfh_client'])) : '';

        wp_enqueue_style(self::STYLE_HANDLE);
        wp_enqueue_script(self::SCRIPT_HANDLE);

        // Localize AJAX details
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        wp_localize_script(self::SCRIPT_HANDLE, 'KCFH_Gallery', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'action'   => self::AJAX_ACTION,
            'nonce'    => $nonce,
        ]);

        ob_start();

        // Single view
        if ($slug) {
            $client = get_page_by_path($slug, OBJECT, 'kcfh_client');
            if ($client) {
                $play = self::determine_playback_for_client((int)$client->ID);
                if ($play) {
                    $name = esc_html(get_the_title($client));
                    $dob  = esc_html(get_post_meta($client->ID, '_kcfh_dob', true));
                    $dod  = esc_html(get_post_meta($client->ID, '_kcfh_dod', true));

                    $back_url = esc_url(remove_query_arg('kcfh_client'));
                    $pbid     = esc_attr($play['playback_id']);
                    $is_live  = !empty($play['is_live']);
                    
                    $live_id   = (int) get_option('kcfh_live_client_id', 0);
                    $live_pbid = (string) get_option('kcfh_live_playback_id', '');

                    $client_id = (int) $client->ID;
                    $end_utc   = (int) get_post_meta($client_id, \KCFH\Streaming\CPT_Client::META_END_AT, true);



                    ?>
                    
                    <div class="kcfh-single">
                        <a class="kcfh-back" href="<?php echo $back_url; ?>">&larr; Back</a>
                        <h2 class="kcfh-single-title"><?php echo $name; ?></h2>
                        <?php if ($dob || $dod): ?>
                            <p class="kcfh-single-dates">
                                <?php echo esc_html(trim("{$dob} – {$dod}", " – ")); ?>
                            </p>
                        <?php endif; ?>

                        <!-- mux-player -->
                        <script type="module" src="https://cdn.jsdelivr.net/npm/@mux/mux-player"></script>
                        <div id="kcfhPlayerWrap" 
                        data-client-id="<?php echo (int)$client_id; ?>"
                        data-end-utc="<?php echo (int)$end_utc;?>"
                        data-ajax-url="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>"
                        data-ajax-action="<?php echo esc_attr( \KCFH\Streaming\Live_Flip_Service::AJAX_CHECK ); ?>"
                        data-live-stream-id="<?php echo esc_attr( defined('KCFH_LIVE_STREAM_ID') ? KCFH_LIVE_STREAM_ID : '' ); ?>"
                        >
                        
                            <mux-player id="kcfhPlayer"
                            stream-type="<?php echo $is_live ? 'live' : 'on-demand'; ?>"
                            playback-id="<?php echo $pbid; ?>"
                            style="width:100%;max-width:1080px;height:auto;"
                            thumbnail-time="2"
                            playsinline
                            crossorigin
                            default-hidden-captions
                            ></mux-player>
                        </div>
                    </div>
                        <script>
                            (function(){
                                //get the mux player
                                const wrap = document.getElementById('kcfhPlayerWrap');
                                if(!wrap) return;

                                //get the client Id and end dateTime
                                const clientId = wrap.dataset.clientId;
                                //read the data-end-utc attribute from the mux player, default to 0 at base-10(decimal)
                                const endUtc = parseInt(wrap.dataset.endUtc || '0', 10);

                                const ajaxUrl       = wrap.dataset.ajaxUrl;
                                const ajaxAction    = wrap.dataset.ajaxAction;     // e.g. kcfh_check_vod
                                const liveStreamId  = wrap.dataset.liveStreamId || '';

                                //debug


                                //helper to switch the mux player from LIVE to 'on-demand'
                                function swapToVOD(pb){
                                    const p = document.getElementById('kcfhPlayer');
                                    if (!p){ 
                                        console.warn('[KCFH] mux-player not found; reloading page'); 
                                        location.reload(); 
                                        return;
                                    }
                                    // Update attributes in the mux webplayer
                                    p.setAttribute('stream-type','on-demand');
                                    p.setAttribute('playback-id', pb);
                                    //Force the wb component to re-init
                                    const clone = p.cloneNode(true);
                                    p.replaceWith(clone);
                                }

                                async function checkVOD(){
                                    try{
                                        //get Url to Wordpress' AJAX endpoint
                                        //const url = new URL('<?php //echo esc_url(admin_url('admin-ajax.php')); ?>');
                                        const url = new URL(wrap.dataset.ajaxUrl);

                                        //add the action name so wordpress knows which handler to run
                                        //url.searchParams.set('action', '<?php //echo \KCFH\Streaming\Live_Flip_Service::AJAX_CHECK; ?>');
                                        url.searchParams.set('action', wrap.dataset.ajaxAction); // e.g. kcfh_check_vod

                                        //tell the server which client we're connecting to
                                        url.searchParams.set('client_id', clientId);


                                        // fetch GET credentials sends cookies if any needed
                                        const r = await fetch(url.toString(), {credentials:'same-origin'});
                                        const text = await r.text();
                                        let data
                                        try { data = JSON.parse(text); } catch(e) {
                                            console.warn('JSON parse error:', e);
                                            console.groupEnd();
                                            return false;
                                        }
                                        console.log('parsed:', data);

                                            //only proceed if the response has the fields we need
                                            /*
                                                the PHP handler Live_Flip_Service::AJAX_CHECK should return something like
                                                {
                                                    "success": true,
                                                    "data": { "ready": true, "playback_id": "abcd1234" }
                                                }
                                            */
                                        if(data && data.success && data.data && data.data.ready && data.data.playback_id){
                                            swapToVOD(data.data.playback_id);
                                            return true;
                                        }
                                    }
                                    catch (e){
                                        console.warn('[KCFH] Poll error:', e);
                                    }
                                    return false;
                                }

                                function startPolling(){
                                    const tryOnce = () => checkVOD().then(ok => { if (!ok) setTimeout(tryOnce, 15000);});
                                    tryOnce();
                                }

                                if(!endUtc || (Date.now()/1000)  >= endUtc){
                                    startPolling();
                                } else{
                                    const delayMs = Math.max(0,(endUtc*1000) - Date.now() + 5000);
                                    setTimeout(startPolling, delayMs);
                                }
                            })();
                        </script>
                    <?php

                } else {
                    echo '<p class="kcfh-empty">Video unavailable for this client.</p>';
                }
            } else {
                echo '<p class="kcfh-empty">Client not found.</p>';
            }

            return ob_get_clean();
        }

        // Gallery view
        if ($show_search) {
            ?>
            <form class="kcfh-search" id="kcfhSearchForm">
                <input type="text" id="kcfhSearchInput" name="q" placeholder="Search by name…" />
                <button type="submit">Search</button>
                <button type="button" id="kcfhSearchClear">Clear</button>
            </form>
            <?php
        }

        // Initial server render (all)
        $items_html = self::render_client_cards_html(self::query_clients_for_gallery());

        ?>
        <div class="kcfh-gallery" id="kcfhGallery" data-page-url="<?php echo esc_url(self::current_page_url()); ?>">
            <?php echo $items_html; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * AJAX: return filtered cards HTML for q
     */
    public static function ajax_search() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';

        $clients = self::query_clients_for_gallery($q);
        $html    = self::render_client_cards_html($clients);

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Query clients that have a playback (live or vod).
     * If $search provided, search by post_title LIKE $search
     */
    private static function query_clients_for_gallery($search = '') {
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
        if (!$ids) return [];

        $results = [];
        foreach ($ids as $id) {
            $play = self::determine_playback_for_client((int)$id);
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
     * Decide playback for a client: if this is the live client and global live playback exists, use live; else use VOD.
     * Returns ['playback_id' => ..., 'is_live' => bool] or null.
     */
    private static function determine_playback_for_client(int $client_id) {
        $live_client_id    = (int) get_option('kcfh_live_client_id', 0);
        $live_playback_id  = trim((string) get_option('kcfh_live_playback_id', ''));

        if ($live_client_id && $live_playback_id && $live_client_id === $client_id) {
            return ['playback_id' => $live_playback_id, 'is_live' => true];
        }

        $vod_playback_id = (string) get_post_meta($client_id, '_kcfh_playback_id', true);
        if ($vod_playback_id) {
            return ['playback_id' => $vod_playback_id, 'is_live' => false];
        }

        return null;
    }

    /**
     * Render cards HTML for the gallery grid.
     */
    private static function render_client_cards_html(array $items) {
        if (!$items) {
            return '<p class="kcfh-empty">No matching clients.</p>';
        }

        $page_url = esc_url(self::current_page_url());
        $html = '<div class="kcfh-grid">';

        foreach ($items as $row) {
            $id     = (int) $row['ID'];
            $name   = esc_html(get_the_title($id));
            $slug   = esc_attr(get_post_field('post_name', $id));
            $dob    = esc_html(get_post_meta($id, '_kcfh_dob', true));
            $dod    = esc_html(get_post_meta($id, '_kcfh_dod', true));
            $isLive = !empty($row['is_live']);

            $link   = esc_url(add_query_arg('kcfh_client', $slug, $page_url));

            // Use Mux thumbnail if we have a playback id (live thumb works too)
            $thumb  = '';
            if (!empty($row['playback_id'])) {
                $pbid = esc_attr($row['playback_id']);
                $thumb = sprintf(
                    'https://image.mux.com/%s/thumbnail.jpg?width=640&height=360&fit_mode=smartcrop&time=2',
                    $pbid
                );
            }

            $dateLine = trim("{$dob} – {$dod}", " – ");
            $badge    = $isLive ? '<span class="kcfh-badge-live">LIVE</span>' : '';

            $html .= '<a class="kcfh-card" href="'.$link.'">';
            $html .= '  <div class="kcfh-thumb-wrap">';
            if ($thumb) {
                $html .= '    <img class="kcfh-thumb" src="'.esc_url($thumb).'" alt="" loading="lazy" />';
            } else {
                $html .= '    <div class="kcfh-thumb kcfh-thumb--placeholder"></div>';
            }
            $html .= $badge;
            $html .= '  </div>';
            $html .= '  <div class="kcfh-card-meta">';
            $html .= '    <div class="kcfh-name">'.$name.'</div>';
            if ($dateLine) {
                $html .= '    <div class="kcfh-dates">'.esc_html($dateLine).'</div>';
            }
            $html .= '  </div>';
            $html .= '</a>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function current_page_url() {
        // Works on most setups; keeps existing query minus kcfh_client
        $url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        return remove_query_arg('kcfh_client', $url);
    }


}

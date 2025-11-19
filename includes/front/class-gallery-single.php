<?php
namespace KCFH\STREAMING;

if (!defined('ABSPATH')) { exit; }

/**
 * Handles the single client view: mux-player + polling script.
 */
class Gallery_Single
{
    /**
     * Render the single view for a given client slug.
     *
     * @param string $slug
     * @return string
     */
    public static function render_single_view(string $slug): string
    {
        ob_start();

        $slug   = sanitize_title($slug);
        $client = get_page_by_path($slug, OBJECT, 'kcfh_client');

        if (!$client) {
            echo '<p class="kcfh-empty">Client not found.</p>';
            return ob_get_clean();
        }

        $client_id = (int) $client->ID;
        $play      = Gallery_Utils::determine_playback_for_client($client_id);

        if (!$play) {
            echo '<p class="kcfh-empty">Video unavailable for this client.</p>';
            return ob_get_clean();
        }

        $name = esc_html(get_the_title($client));
        $dob  = esc_html(get_post_meta($client_id, '_kcfh_dob', true));
        $dod  = esc_html(get_post_meta($client_id, '_kcfh_dod', true));

        $back_url = esc_url(remove_query_arg('kcfh_client'));
        $pbid     = esc_attr($play['playback_id']);
        $is_live  = !empty($play['is_live']);

        // Live flip data
        $end_utc = (int) get_post_meta(
            $client_id,
            \KCFH\Streaming\CPT_Client::META_END_AT,
            true
        );

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
                 data-client-id="<?php echo (int) $client_id; ?>"
                 data-end-utc="<?php echo (int) $end_utc; ?>"
                 data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                 data-ajax-action="<?php echo esc_attr(\KCFH\Streaming\Live_Flip_Service::AJAX_CHECK); ?>"
                 data-live-stream-id="<?php echo esc_attr(defined('KCFH_LIVE_STREAM_ID') ? KCFH_LIVE_STREAM_ID : ''); ?>">
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
        <?php

        // Inline polling script
        self::render_polling_script($client_id, $end_utc);

        return ob_get_clean();
    }

    /**
     * Inline JS that polls the server to flip from LIVE to VOD when ready.
     */
    private static function render_polling_script(int $client_id, int $end_utc): void
    {
        ?>
        <script>
            (function () {
                const wrap = document.getElementById('kcfhPlayerWrap');
                if (!wrap) return;

                const clientId     = wrap.dataset.clientId;
                const endUtc       = parseInt(wrap.dataset.endUtc || '0', 10);
                const ajaxUrl      = wrap.dataset.ajaxUrl;
                const ajaxAction   = wrap.dataset.ajaxAction;   // e.g. kcfh_check_vod
                const liveStreamId = wrap.dataset.liveStreamId || '';

                // helper to switch the mux player from LIVE to on-demand
                function swapToVOD(playbackId) {
                    const p = document.getElementById('kcfhPlayer');
                    if (!p) {
                        console.warn('[KCFH] mux-player not found; reloading page');
                        location.reload();
                        return;
                    }
                    p.setAttribute('stream-type', 'on-demand');
                    p.setAttribute('playback-id', playbackId);

                    // Force the web component to re-init
                    const clone = p.cloneNode(true);
                    p.replaceWith(clone);
                }

                async function checkVOD() {
                    try {
                        const url = new URL(ajaxUrl);
                        url.searchParams.set('action', ajaxAction);
                        url.searchParams.set('client_id', clientId);

                        const r    = await fetch(url.toString(), {credentials: 'same-origin'});
                        const text = await r.text();
                        let data;

                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            console.warn('[KCFH] JSON parse error:', e);
                            return false;
                        }

                        if (data && data.success && data.data && data.data.ready && data.data.playback_id) {
                            swapToVOD(data.data.playback_id);
                            return true;
                        }
                    } catch (e) {
                        console.warn('[KCFH] Poll error:', e);
                    }
                    return false;
                }

                function startPolling() {
                    const tryOnce = () => checkVOD().then(ok => {
                        if (!ok) setTimeout(tryOnce, 15000);
                    });
                    tryOnce();
                }

                // If stream end time is in the past, start polling immediately;
                // otherwise wait until end time + small buffer.
                if (!endUtc || (Date.now() / 1000) >= endUtc) {
                    startPolling();
                } else {
                    const delayMs = Math.max(0, (endUtc * 1000) - Date.now() + 5000);
                    setTimeout(startPolling, delayMs);
                }
            })();
        </script>
        <?php
    }
}

<?php
namespace KCFH\Streaming;

if (!defined('ABSPATH')) exit;

class Live_Scheduler {
    const HOOK_START = 'kcfh_set_live_at';
    const HOOK_END   = 'kcfh_unset_live_at';
    const HOOK_TICK  = 'kcfh_live_tick'; // optional safety tick
        const HOOK_VERIFY = 'kcfh_live_verify'; // new: short follow-up

    public static function bootstrap() {
        // One-off start/end handlers
        add_action(self::HOOK_START, [__CLASS__, 'handle_start'], 10, 1);
        add_action(self::HOOK_END,   [__CLASS__, 'handle_end'],   10, 1);
                add_action(self::HOOK_VERIFY, [__CLASS__, 'handle_verify'],10, 1);

        // Add a 5-minute cron schedule (if not present)
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['five_minutes'])) {
                $schedules['five_minutes'] = [
                    'interval' => 300,
                    'display'  => __('Every 5 Minutes', 'kcfh'),
                ];
            }
            if (!isset($schedules['one_minute'])) {
                $schedules['one_minute']   = ['interval' => 60,  'display' => __('Every Minute', 'kcfh')];
            }
            return $schedules;
        });

        // Safety tick (keeps state correct if a one-off was missed)
        add_action(self::HOOK_TICK, [__CLASS__, 'handle_tick']);
        if (!wp_next_scheduled(self::HOOK_TICK)) {
            wp_schedule_event(time() + 120, 'five_minutes', self::HOOK_TICK);
        }
    }

    /**
     * Schedule (or clear) the start/end events for a specific client.
     */
    public static function reschedule_for_client($post_id, $startUtc, $endUtc) {
        // Clear old singles for this client
        wp_clear_scheduled_hook(self::HOOK_START, [$post_id]);
        wp_clear_scheduled_hook(self::HOOK_END,   [$post_id]);

        $now = time();
        if ($startUtc && $startUtc > $now) {
            wp_schedule_single_event($startUtc, self::HOOK_START, [$post_id]);
        }
        if ($endUtc && $endUtc > $now) {
            wp_schedule_single_event($endUtc, self::HOOK_END, [$post_id]);
        }
    }

    public static function handle_start($post_id) {
        self::set_live_client($post_id);

        
        // Force an immediate refresh (helps with initial lag)
        if (class_exists(__NAMESPACE__ . '\\Live_Service')) {
            Live_Service::refresh_for_client($post_id);
        }

        // Schedule a short follow-up to “nudge” again in 1 minute
        wp_schedule_single_event(time() + 60, self::HOOK_VERIFY, [$post_id]);
    }

    public static function handle_verify($post_id) {
        // Only refresh if still within window (avoid touching if schedule changed)
        $now   = time();
        $start = (int) get_post_meta($post_id, CPT_Client::META_START_AT, true);
        $end   = (int) get_post_meta($post_id, CPT_Client::META_END_AT, true);

        if ($start && $end && $now >= $start && $now < $end) {
            if (class_exists(__NAMESPACE__ . '\\Live_Service')) {
                Live_Service::refresh_for_client($post_id);
            }
        }
    }

    public static function handle_end($post_id) {
        self::unset_live_if_matches($post_id);

                // Optional: one more refresh to clear caches / posters
        if (class_exists(__NAMESPACE__ . '\\Asset_Service') && method_exists(Asset_Service::class, 'bust_cache')) {
            Asset_Service::bust_cache();
        }
    }

    public static function set_live_client($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== CPT_Client::POST_TYPE || $post->post_status !== 'publish') return;

        // Only one live at a time
        update_option(Admin_UI::OPT_LIVE_CLIENT, (int) $post_id);
        // Live playback is global (Admin settings) — we leave that as-is.
    }

    public static function unset_live_if_matches($post_id) {
        $liveId = (int) get_option(Admin_UI::OPT_LIVE_CLIENT, 0);
        if ($liveId === (int) $post_id) {
            update_option(Admin_UI::OPT_LIVE_CLIENT, 0);
        }
    }

    /**
     * Safety loop every 5 minutes:
     * - If now is within a client's window, ensure that client is live.
     * - If no windows are active, ensure live is cleared.
     */
    public static function handle_tick() {
        $now = time();

        $ids = get_posts([
            'post_type'      => CPT_Client::POST_TYPE,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);

        $liveShouldBe = 0;

        foreach ($ids as $id) {
            $start = (int) get_post_meta($id, CPT_Client::META_START_AT, true);
            $end   = (int) get_post_meta($id, CPT_Client::META_END_AT, true);
            if ($start && $end && $now >= $start && $now < $end) {
                // If multiple overlap, last wins (customise tie-break if you want)
                $liveShouldBe = (int) $id;
            }
        }

        $currentLive = (int) get_option(Admin_UI::OPT_LIVE_CLIENT, 0);
        if ($liveShouldBe && $currentLive !== $liveShouldBe) {
            self::set_live_client($liveShouldBe);
        } elseif (!$liveShouldBe && $currentLive) {
            update_option(Admin_UI::OPT_LIVE_CLIENT, 0);
        }
    }
}

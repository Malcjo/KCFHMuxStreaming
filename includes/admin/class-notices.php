<?php
namespace KCFH\Streaming\Admin;

if (!defined('ABSPATH')) exit;

final class Notices {
    /** Show a single WP admin notice */
    public static function show(string $code = '', string $message = ''): void {
        if (!$code && !$message) return;
        $success_codes = ['assigned','unassigned','mp4_req','mp4_wait'];
        $cls = in_array($code, $success_codes, true) ? 'success' : 'error';
        $text = $message ?: esc_html($code);
        echo '<div class="notice notice-' . esc_attr($cls) . '"><p>' . $text . '</p></div>';
    }

    /** Redirect back to VOD Manager with code + message */
    public static function redirect_vod(string $code, string $message): void {
        $url = add_query_arg([
            'page'        => 'kcfh_vod_manager',
            'kcfh_notice' => $code,
            'kcfh_msg'    => rawurlencode($message),
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}

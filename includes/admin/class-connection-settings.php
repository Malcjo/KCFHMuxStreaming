<?php
namespace KCFH\Streaming\Admin;

use KCFH\Streaming\Admin\AdminToolbar;
use KCFH\Streaming\Asset_Service;
use KCFH\Streaming\Live_Service;
use KCFH\Streaming\Streaming_Settings;

if (!defined('ABSPATH')) exit;

final class Connection_Settings_Page
{
    public const PAGE_SLUG = 'kcfh_connection_settings';

    public static function render_setup_notice(): void
    {
        if (!current_user_can('manage_options') || Streaming_Settings::has_mux_credentials()) {
            return;
        }

        $current_page = isset($_GET['page'])
            ? sanitize_key(wp_unslash($_GET['page']))
            : '';
        if ($current_page === self::PAGE_SLUG) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>KCFH Streaming setup is incomplete.</strong> '
            . '<a href="' . esc_url(menu_page_url(self::PAGE_SLUG, false)) . '">Connect this site to Mux</a>.'
            . '</p></div>';
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to manage streaming credentials.');
        }

        AdminToolbar::render('connection');

        $mux_token_id = Streaming_Settings::get_mux_token_id();
        $live_stream_id = Streaming_Settings::get_live_stream_id();
        $live_playback_id = Streaming_Settings::get_live_playback_id();
        $rtmp_url = Streaming_Settings::get_rtmp_url();
        $rtmp_stream_key = Streaming_Settings::get_rtmp_stream_key();

        $mux_token_id_override = Streaming_Settings::is_constant_override('MUX_TOKEN_ID');
        $mux_token_secret_override = Streaming_Settings::is_constant_override('MUX_TOKEN_SECRET');
        $live_stream_id_override = Streaming_Settings::is_constant_override('KCFH_LIVE_STREAM_ID');
        $rtmp_url_override = Streaming_Settings::is_constant_override('KCFH_LIVE_RTMP_URL');
        $rtmp_stream_key_override = Streaming_Settings::is_constant_override('KCFH_LIVE_STREAM_KEY');
        $has_config_overrides = $mux_token_id_override
            || $mux_token_secret_override
            || $live_stream_id_override
            || $rtmp_url_override
            || $rtmp_stream_key_override;

        $saved_live_stream_id = Streaming_Settings::get_saved_value('live_stream_id');
        $saved_rtmp_url = Streaming_Settings::get_saved_value('rtmp_url');

        $result = isset($_GET['kcfh_connection_result'])
            ? sanitize_key(wp_unslash($_GET['kcfh_connection_result']))
            : '';
        $message = isset($_GET['kcfh_connection_message'])
            ? sanitize_text_field(wp_unslash($_GET['kcfh_connection_message']))
            : '';

        ?>
        <div class="wrap kcfh-connection-settings">
            <h1>Mux Connection Settings</h1>
            <p class="kcfh-settings-intro">
                Connect this WordPress site to the organisation's own Mux account.
                Sensitive values are encrypted before they are stored in the database.
            </p>

            <?php if ($result === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Connection settings saved securely.</p></div>
            <?php elseif ($result === 'connected'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message ?: 'Mux connection successful.'); ?></p></div>
            <?php elseif ($result === 'error'): ?>
                <div class="notice notice-error"><p><?php echo esc_html($message ?: 'The connection could not be verified.'); ?></p></div>
            <?php endif; ?>

            <div class="kcfh-connection-status-grid">
                <?php self::render_status_card(
                    'Mux API',
                    Streaming_Settings::has_mux_credentials(),
                    Streaming_Settings::get_value_source('MUX_TOKEN_SECRET', 'mux_token_secret')
                ); ?>
                <?php self::render_status_card(
                    'Live Stream',
                    $live_stream_id !== '',
                    Streaming_Settings::get_value_source('KCFH_LIVE_STREAM_ID', 'live_stream_id')
                ); ?>
                <?php self::render_status_card(
                    'Broadcaster',
                    $rtmp_url !== '' && $rtmp_stream_key !== '',
                    Streaming_Settings::get_value_source('KCFH_LIVE_STREAM_KEY', 'rtmp_stream_key')
                ); ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('kcfh_save_connection_settings'); ?>
                <input type="hidden" name="action" value="kcfh_save_connection_settings">

                <?php if ($has_config_overrides): ?>
                    <div class="notice notice-info inline kcfh-import-config-notice">
                        <p><strong>This site currently uses wp-config.php overrides.</strong></p>
                        <p>
                            <label>
                                <input type="checkbox" name="kcfh_import_wp_config_values" value="1">
                                Copy the current wp-config.php values into encrypted plugin storage when saving
                            </label>
                        </p>
                        <p class="description">
                            The constants will continue to take priority. After saving, they can be removed from
                            <code>wp-config.php</code> without interrupting the connection.
                        </p>
                    </div>
                <?php endif; ?>

                <section class="kcfh-settings-card">
                    <h2>Mux API credentials</h2>
                    <p>Use an access token created in the customer's Mux account. These credentials remain on this WordPress installation.</p>

                    <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="kcfh_mux_token_id">Mux Token ID</label></th>
                        <td>
                            <input
                                type="password"
                                id="kcfh_mux_token_id"
                                name="kcfh_mux_token_id"
                                class="regular-text"
                                autocomplete="new-password"
                                placeholder="<?php echo esc_attr($mux_token_id !== '' ? Streaming_Settings::mask_value($mux_token_id) : 'Paste the Mux Token ID'); ?>"
                                <?php disabled($mux_token_id_override); ?>
                            >
                            <?php self::render_field_source('MUX_TOKEN_ID', 'mux_token_id'); ?>
                            <p class="description">Leave blank to keep the currently saved Token ID.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kcfh_mux_token_secret">Mux Token Secret</label></th>
                        <td>
                            <input
                                type="password"
                                id="kcfh_mux_token_secret"
                                name="kcfh_mux_token_secret"
                                class="regular-text"
                                autocomplete="new-password"
                                placeholder="<?php echo esc_attr($mux_token_secret_override || Streaming_Settings::get_saved_value('mux_token_secret') !== '' ? 'Configured — enter a new secret to replace it' : 'Paste the Mux Token Secret'); ?>"
                                <?php disabled($mux_token_secret_override); ?>
                            >
                            <?php self::render_field_source('MUX_TOKEN_SECRET', 'mux_token_secret'); ?>
                            <p class="description">The secret is encrypted and is never displayed again.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Clear credentials</th>
                        <td>
                            <label>
                                <input type="checkbox" name="kcfh_clear_mux_credentials" value="1" <?php disabled($mux_token_id_override || $mux_token_secret_override); ?>>
                                Remove the database-stored Mux Token ID and Token Secret
                            </label>
                        </td>
                    </tr>
                    </tbody></table>
                </section>

                <section class="kcfh-settings-card">
                    <h2>Live stream</h2>
                    <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="kcfh_live_stream_id">Mux Live Stream ID</label></th>
                        <td>
                            <input
                                type="text"
                                id="kcfh_live_stream_id"
                                name="kcfh_live_stream_id"
                                class="regular-text code"
                                value="<?php echo esc_attr($live_stream_id_override ? $live_stream_id : $saved_live_stream_id); ?>"
                                <?php disabled($live_stream_id_override); ?>
                            >
                            <?php self::render_field_source('KCFH_LIVE_STREAM_ID', 'live_stream_id'); ?>
                            <p class="description">The shared Mux live stream used for scheduled services.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kcfh_live_playback_id">Live Playback ID</label></th>
                        <td>
                            <input
                                type="text"
                                id="kcfh_live_playback_id"
                                name="kcfh_live_playback_id"
                                class="regular-text code"
                                value="<?php echo esc_attr($live_playback_id); ?>"
                            >
                            <p class="description">The public playback ID used by the gallery while a client is live.</p>
                        </td>
                    </tr>
                    </tbody></table>
                </section>

                <section class="kcfh-settings-card">
                    <h2>Broadcaster connection</h2>
                    <p>These values are used when configuring Larix Broadcaster or OBS. The stream key is treated as a secret.</p>

                    <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="kcfh_rtmp_url">RTMP URL</label></th>
                        <td>
                            <input
                                type="text"
                                id="kcfh_rtmp_url"
                                name="kcfh_rtmp_url"
                                class="regular-text code"
                                value="<?php echo esc_attr($rtmp_url_override ? $rtmp_url : $saved_rtmp_url); ?>"
                                <?php disabled($rtmp_url_override); ?>
                            >
                            <?php self::render_field_source('KCFH_LIVE_RTMP_URL', 'rtmp_url'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kcfh_rtmp_stream_key">RTMP Stream Key</label></th>
                        <td>
                            <input
                                type="password"
                                id="kcfh_rtmp_stream_key"
                                name="kcfh_rtmp_stream_key"
                                class="regular-text"
                                autocomplete="new-password"
                                placeholder="<?php echo esc_attr($rtmp_stream_key_override || Streaming_Settings::get_saved_value('rtmp_stream_key') !== '' ? 'Configured — enter a new key to replace it' : 'Paste the RTMP Stream Key'); ?>"
                                <?php disabled($rtmp_stream_key_override); ?>
                            >
                            <?php self::render_field_source('KCFH_LIVE_STREAM_KEY', 'rtmp_stream_key'); ?>
                            <p class="description">Leave blank to keep the currently saved stream key.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Clear broadcaster settings</th>
                        <td>
                            <label>
                                <input type="checkbox" name="kcfh_clear_broadcaster_settings" value="1" <?php disabled($rtmp_url_override || $rtmp_stream_key_override); ?>>
                                Remove the database-stored RTMP URL and Stream Key
                            </label>
                        </td>
                    </tr>
                    </tbody></table>
                </section>

                <?php submit_button('Save Connection Settings'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kcfh-test-connection-form">
                <?php wp_nonce_field('kcfh_test_mux_connection'); ?>
                <input type="hidden" name="action" value="kcfh_test_mux_connection">
                <?php submit_button('Test Mux Connection', 'secondary', 'submit', false); ?>
                <span class="description">Save any changes before testing.</span>
            </form>

            <details class="kcfh-advanced-settings">
                <summary>Advanced: wp-config.php overrides</summary>
                <p>Values defined in <code>wp-config.php</code> take priority over encrypted plugin settings. This keeps existing and security-managed installations compatible.</p>
                <pre>define('MUX_TOKEN_ID', '...');
define('MUX_TOKEN_SECRET', '...');
define('KCFH_LIVE_STREAM_ID', '...');
define('KCFH_LIVE_RTMP_URL', '...');
define('KCFH_LIVE_STREAM_KEY', '...');</pre>
            </details>
        </div>

        <style>
            .kcfh-connection-settings { max-width: 1050px; }
            .kcfh-settings-intro { max-width: 760px; font-size: 14px; }
            .kcfh-connection-status-grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 14px;
                margin: 20px 0;
            }
            .kcfh-status-card,
            .kcfh-settings-card,
            .kcfh-advanced-settings {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 10px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
            }
            .kcfh-status-card { padding: 16px; }
            .kcfh-status-card h2 { margin: 0 0 8px; font-size: 15px; }
            .kcfh-status-indicator { font-weight: 600; }
            .kcfh-status-indicator.is-connected { color: #008a20; }
            .kcfh-status-indicator.is-incomplete { color: #b32d2e; }
            .kcfh-status-source { margin: 6px 0 0; color: #646970; }
            .kcfh-settings-card { padding: 18px 22px; margin: 16px 0; }
            .kcfh-settings-card > h2 { margin-top: 0; }
            .kcfh-import-config-notice { margin: 16px 0; padding: 4px 12px 10px; }
            .kcfh-field-source { display: inline-block; margin-left: 8px; color: #646970; }
            .kcfh-test-connection-form { display: flex; align-items: center; gap: 10px; margin: 18px 0; }
            .kcfh-advanced-settings { padding: 14px 18px; margin-top: 20px; }
            .kcfh-advanced-settings summary { cursor: pointer; font-weight: 600; }
            .kcfh-advanced-settings pre { overflow: auto; padding: 12px; background: #f6f7f7; }
            @media (max-width: 782px) {
                .kcfh-connection-status-grid { grid-template-columns: 1fr; }
                .kcfh-settings-card { padding: 14px; }
            }
        </style>
        <?php
    }

    public static function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to manage streaming credentials.');
        }
        check_admin_referer('kcfh_save_connection_settings');

        $saved_values = [
            'mux_token_id'      => Streaming_Settings::get_saved_value('mux_token_id'),
            'mux_token_secret'  => Streaming_Settings::get_saved_value('mux_token_secret'),
            'live_stream_id'    => Streaming_Settings::get_saved_value('live_stream_id'),
            'rtmp_url'          => Streaming_Settings::get_saved_value('rtmp_url'),
            'rtmp_stream_key'   => Streaming_Settings::get_saved_value('rtmp_stream_key'),
        ];

        $token_id_override = Streaming_Settings::is_constant_override('MUX_TOKEN_ID');
        $token_secret_override = Streaming_Settings::is_constant_override('MUX_TOKEN_SECRET');
        $live_stream_id_override = Streaming_Settings::is_constant_override('KCFH_LIVE_STREAM_ID');
        $rtmp_url_override = Streaming_Settings::is_constant_override('KCFH_LIVE_RTMP_URL');
        $rtmp_stream_key_override = Streaming_Settings::is_constant_override('KCFH_LIVE_STREAM_KEY');

        if (!empty($_POST['kcfh_import_wp_config_values'])) {
            if ($token_id_override) {
                $saved_values['mux_token_id'] = Streaming_Settings::get_mux_token_id();
            }
            if ($token_secret_override) {
                $saved_values['mux_token_secret'] = Streaming_Settings::get_mux_token_secret();
            }
            if ($live_stream_id_override) {
                $saved_values['live_stream_id'] = Streaming_Settings::get_live_stream_id();
            }
            if ($rtmp_url_override) {
                $saved_values['rtmp_url'] = Streaming_Settings::get_rtmp_url();
            }
            if ($rtmp_stream_key_override) {
                $saved_values['rtmp_stream_key'] = Streaming_Settings::get_rtmp_stream_key();
            }
        }

        $clear_mux_credentials = !empty($_POST['kcfh_clear_mux_credentials'])
            && !$token_id_override
            && !$token_secret_override;

        if ($clear_mux_credentials) {
            $saved_values['mux_token_id'] = '';
            $saved_values['mux_token_secret'] = '';
        } else {
            $new_token_id = isset($_POST['kcfh_mux_token_id'])
                ? sanitize_text_field(wp_unslash($_POST['kcfh_mux_token_id']))
                : '';
            $new_token_secret = isset($_POST['kcfh_mux_token_secret'])
                ? sanitize_text_field(wp_unslash($_POST['kcfh_mux_token_secret']))
                : '';

            if (!$token_id_override && $new_token_id !== '') {
                $saved_values['mux_token_id'] = $new_token_id;
            }
            if (!$token_secret_override && $new_token_secret !== '') {
                $saved_values['mux_token_secret'] = $new_token_secret;
            }
        }

        if (!$live_stream_id_override) {
            $saved_values['live_stream_id'] = isset($_POST['kcfh_live_stream_id'])
                ? sanitize_text_field(wp_unslash($_POST['kcfh_live_stream_id']))
                : $saved_values['live_stream_id'];
        }

        $clear_broadcaster_settings = !empty($_POST['kcfh_clear_broadcaster_settings'])
            && !$rtmp_url_override
            && !$rtmp_stream_key_override;

        if ($clear_broadcaster_settings) {
            $saved_values['rtmp_url'] = '';
            $saved_values['rtmp_stream_key'] = '';
        } else {
            if (!$rtmp_url_override) {
                $submitted_rtmp_url = isset($_POST['kcfh_rtmp_url'])
                    ? sanitize_text_field(wp_unslash($_POST['kcfh_rtmp_url']))
                    : $saved_values['rtmp_url'];

                if (
                    $submitted_rtmp_url !== '' &&
                    !preg_match('#^rtmps?://#i', $submitted_rtmp_url)
                ) {
                    self::redirect_with_result(
                        'error',
                        'The RTMP URL must begin with rtmp:// or rtmps://.'
                    );
                }

                $saved_values['rtmp_url'] = $submitted_rtmp_url;
            }

            $new_stream_key = isset($_POST['kcfh_rtmp_stream_key'])
                ? sanitize_text_field(wp_unslash($_POST['kcfh_rtmp_stream_key']))
                : '';
            if (!$rtmp_stream_key_override && $new_stream_key !== '') {
                $saved_values['rtmp_stream_key'] = $new_stream_key;
            }
        }

        $save_result = Streaming_Settings::save_connection_values($saved_values);
        if (is_wp_error($save_result)) {
            self::redirect_with_result('error', $save_result->get_error_message());
        }

        $live_playback_id = isset($_POST['kcfh_live_playback_id'])
            ? sanitize_text_field(wp_unslash($_POST['kcfh_live_playback_id']))
            : Streaming_Settings::get_live_playback_id();
        update_option(Constants::OPT_LIVE_PLAYBACK, $live_playback_id, false);

        if (method_exists(Asset_Service::class, 'bust_cache')) {
            Asset_Service::bust_cache();
        }

        self::redirect_with_result('saved');
    }

    public static function handle_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to test streaming credentials.');
        }
        check_admin_referer('kcfh_test_mux_connection');

        if (!Streaming_Settings::has_mux_credentials()) {
            self::redirect_with_result(
                'error',
                'Enter and save both the Mux Token ID and Token Secret before testing.'
            );
        }

        $assets_result = Asset_Service::fetch_assets(['limit' => 1], 0);
        if (is_wp_error($assets_result)) {
            self::redirect_with_result(
                'error',
                self::safe_error_message($assets_result->get_error_message())
            );
        }

        $live_stream_id = Streaming_Settings::get_live_stream_id();
        if ($live_stream_id !== '') {
            $live_stream_result = Live_Service::get_live_stream($live_stream_id);
            if (is_wp_error($live_stream_result)) {
                self::redirect_with_result(
                    'error',
                    'Mux credentials connected, but the Live Stream ID could not be verified: '
                        . self::safe_error_message($live_stream_result->get_error_message())
                );
            }

            self::redirect_with_result(
                'connected',
                'Mux credentials connected and the Live Stream ID was verified.'
            );
        }

        self::redirect_with_result(
            'connected',
            'Mux credentials connected successfully. Add a Live Stream ID when ready.'
        );
    }

    private static function render_status_card(
        string $label,
        bool $is_connected,
        string $source
    ): void {
        ?>
        <div class="kcfh-status-card">
            <h2><?php echo esc_html($label); ?></h2>
            <div class="kcfh-status-indicator <?php echo $is_connected ? 'is-connected' : 'is-incomplete'; ?>">
                <?php echo $is_connected ? 'Connected' : 'Setup required'; ?>
            </div>
            <p class="kcfh-status-source"><?php echo esc_html($source); ?></p>
        </div>
        <?php
    }

    private static function render_field_source(
        string $constant_name,
        string $database_field
    ): void {
        $source = Streaming_Settings::get_value_source(
            $constant_name,
            $database_field
        );
        echo '<span class="kcfh-field-source">Source: ' . esc_html($source) . '</span>';
    }

    private static function safe_error_message(string $message): string
    {
        $message = wp_strip_all_tags($message);
        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 300);
        }

        return substr($message, 0, 300);
    }

    private static function redirect_with_result(
        string $result,
        string $message = ''
    ): void {
        $arguments = [
            'page'                   => self::PAGE_SLUG,
            'kcfh_connection_result' => sanitize_key($result),
        ];

        if ($message !== '') {
            $arguments['kcfh_connection_message'] = self::safe_error_message($message);
        }

        wp_safe_redirect(add_query_arg($arguments, admin_url('admin.php')));
        exit;
    }
}

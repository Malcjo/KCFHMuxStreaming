<?php
namespace KCFH\Streaming;

if (!defined('ABSPATH')) exit;

/**
 * Central access point for Mux and streaming connection settings.
 *
 * Values defined in wp-config.php always take priority. Values entered through
 * the plugin are encrypted before being stored in the WordPress database.
 */
final class Streaming_Settings
{
    public const OPTION_CONNECTION_SETTINGS = 'kcfh_streaming_connection_settings';

    private const ENCRYPTION_CONTEXT = 'kcfh-streaming-connection-settings-v1';
    private const SODIUM_PREFIX = 'kcfh:v1:sodium:';
    private const OPENSSL_PREFIX = 'kcfh:v1:openssl:';

    private const DATABASE_FIELDS = [
        'mux_token_id',
        'mux_token_secret',
        'live_stream_id',
        'rtmp_url',
        'rtmp_stream_key',
    ];

    public static function get_mux_token_id(): string
    {
        return self::get_value_with_override('MUX_TOKEN_ID', 'mux_token_id');
    }

    public static function get_mux_token_secret(): string
    {
        return self::get_value_with_override('MUX_TOKEN_SECRET', 'mux_token_secret');
    }

    public static function get_live_stream_id(): string
    {
        return self::get_value_with_override('KCFH_LIVE_STREAM_ID', 'live_stream_id');
    }

    public static function get_rtmp_url(): string
    {
        return self::get_value_with_override('KCFH_LIVE_RTMP_URL', 'rtmp_url');
    }

    public static function get_rtmp_stream_key(): string
    {
        return self::get_value_with_override('KCFH_LIVE_STREAM_KEY', 'rtmp_stream_key');
    }

    public static function get_live_playback_id(): string
    {
        return trim((string) get_option('kcfh_live_playback_id', ''));
    }

    public static function has_mux_credentials(): bool
    {
        return self::get_mux_token_id() !== '' && self::get_mux_token_secret() !== '';
    }

    public static function is_constant_override(string $constant_name): bool
    {
        return defined($constant_name) && trim((string) constant($constant_name)) !== '';
    }

    public static function get_value_source(string $constant_name, string $database_field): string
    {
        if (self::is_constant_override($constant_name)) {
            return self::get_saved_value($database_field) !== ''
                ? 'wp-config.php override; encrypted fallback saved'
                : 'wp-config.php override';
        }

        return self::get_saved_value($database_field) !== ''
            ? 'encrypted plugin setting'
            : 'not configured';
    }

    /**
     * Return one decrypted database value without applying wp-config overrides.
     * This is intended only for authorised server-side settings handlers.
     */
    public static function get_saved_value(string $field): string
    {
        if (!in_array($field, self::DATABASE_FIELDS, true)) {
            return '';
        }

        $stored_settings = get_option(self::OPTION_CONNECTION_SETTINGS, []);
        if (!is_array($stored_settings) || empty($stored_settings[$field])) {
            return '';
        }

        return self::decrypt_value((string) $stored_settings[$field]);
    }

    /**
     * Save a complete set of database-backed values.
     *
     * @return true|\WP_Error
     */
    public static function save_connection_values(array $values)
    {
        $encrypted_settings = [
            'version'    => 1,
            'updated_at' => time(),
        ];

        foreach (self::DATABASE_FIELDS as $field) {
            $plain_value = isset($values[$field])
                ? trim((string) $values[$field])
                : '';

            if ($plain_value === '') {
                $encrypted_settings[$field] = '';
                continue;
            }

            $encrypted_value = self::encrypt_value($plain_value);
            if (is_wp_error($encrypted_value)) {
                return $encrypted_value;
            }

            $encrypted_settings[$field] = $encrypted_value;
        }

        update_option(
            self::OPTION_CONNECTION_SETTINGS,
            $encrypted_settings,
            false
        );

        return true;
    }

    public static function mask_value(string $value, int $visible_characters = 4): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Not configured';
        }

        $visible_characters = max(2, min(8, $visible_characters));
        $suffix = substr($value, -$visible_characters);

        return str_repeat('•', 8) . $suffix;
    }

    private static function get_value_with_override(
        string $constant_name,
        string $database_field
    ): string {
        if (self::is_constant_override($constant_name)) {
            return trim((string) constant($constant_name));
        }

        return self::get_saved_value($database_field);
    }

    /**
     * @return string|\WP_Error
     */
    private static function encrypt_value(string $plain_value)
    {
        try {
            $encryption_key = self::get_encryption_key();

            if (
                function_exists('sodium_crypto_secretbox') &&
                defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
            ) {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $cipher_text = sodium_crypto_secretbox(
                    $plain_value,
                    $nonce,
                    $encryption_key
                );

                return self::SODIUM_PREFIX . base64_encode($nonce . $cipher_text);
            }

            if (function_exists('openssl_encrypt')) {
                $initialisation_vector = random_bytes(12);
                $authentication_tag = '';
                $cipher_text = openssl_encrypt(
                    $plain_value,
                    'aes-256-gcm',
                    $encryption_key,
                    OPENSSL_RAW_DATA,
                    $initialisation_vector,
                    $authentication_tag
                );

                if ($cipher_text === false || strlen($authentication_tag) !== 16) {
                    return new \WP_Error(
                        'kcfh_encryption_failed',
                        'The connection settings could not be encrypted.'
                    );
                }

                return self::OPENSSL_PREFIX . base64_encode(
                    $initialisation_vector . $authentication_tag . $cipher_text
                );
            }
        } catch (\Throwable $exception) {
            return new \WP_Error(
                'kcfh_encryption_failed',
                'The connection settings could not be encrypted.'
            );
        }

        return new \WP_Error(
            'kcfh_encryption_unavailable',
            'This server does not provide a supported encryption extension.'
        );
    }

    private static function decrypt_value(string $encrypted_value): string
    {
        try {
            $encryption_key = self::get_encryption_key();

            if (strpos($encrypted_value, self::SODIUM_PREFIX) === 0) {
                if (!function_exists('sodium_crypto_secretbox_open')) {
                    return '';
                }

                $encoded_payload = substr(
                    $encrypted_value,
                    strlen(self::SODIUM_PREFIX)
                );
                $payload = base64_decode($encoded_payload, true);

                if (
                    $payload === false ||
                    strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
                ) {
                    return '';
                }

                $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $cipher_text = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $plain_value = sodium_crypto_secretbox_open(
                    $cipher_text,
                    $nonce,
                    $encryption_key
                );

                return $plain_value === false ? '' : (string) $plain_value;
            }

            if (strpos($encrypted_value, self::OPENSSL_PREFIX) === 0) {
                if (!function_exists('openssl_decrypt')) {
                    return '';
                }

                $encoded_payload = substr(
                    $encrypted_value,
                    strlen(self::OPENSSL_PREFIX)
                );
                $payload = base64_decode($encoded_payload, true);

                if ($payload === false || strlen($payload) <= 28) {
                    return '';
                }

                $initialisation_vector = substr($payload, 0, 12);
                $authentication_tag = substr($payload, 12, 16);
                $cipher_text = substr($payload, 28);
                $plain_value = openssl_decrypt(
                    $cipher_text,
                    'aes-256-gcm',
                    $encryption_key,
                    OPENSSL_RAW_DATA,
                    $initialisation_vector,
                    $authentication_tag
                );

                return $plain_value === false ? '' : (string) $plain_value;
            }
        } catch (\Throwable $exception) {
            return '';
        }

        return '';
    }

    private static function get_encryption_key(): string
    {
        $key_material = wp_salt('auth')
            . '|'
            . wp_salt('secure_auth')
            . '|'
            . self::ENCRYPTION_CONTEXT;

        return hash('sha256', $key_material, true);
    }
}

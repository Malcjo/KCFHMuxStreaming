<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Best-effort: delete any transients set by this plugin.
// We used the prefix 'kcfh_streaming_' in transient keys.
global $wpdb;
$prefix = '_transient_kcfh_streaming_%';
$wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix) );

$prefix_to = '_transient_timeout_kcfh_streaming_%';
$wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix_to) );

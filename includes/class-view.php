<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class View {

  // Build a detail link to the current page with ?kcfh_pb=...
  public static function detail_link(string $playback_id, ?string $explicit_base = null): string {
    $base = $explicit_base ?: Core::current_page_url();
    return add_query_arg('kcfh_pb', rawurlencode($playback_id), $base);
  }

  // Pretty date range for dob–dod
  public static function life_dates(?string $dob, ?string $dod): string {
    $dob_str = $dob ? date_i18n(get_option('date_format'), strtotime($dob)) : '';
    $dod_str = $dod ? date_i18n(get_option('date_format'), strtotime($dod)) : '';
    return trim($dob_str . (($dob_str && $dod_str) ? ' – ' : '') . $dod_str);
  }
}

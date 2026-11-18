<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class Utility_Mux{

    public static function init() {
        ConnectMux();
    }

public static function ConnectMux(){
    if (!Streaming_Settings::has_mux_credentials()) {
        error_log('[KCFH] Mux credentials are not configured');
    } else {
        error_log('[KCFH] Mux credentials are configured');
    }
    echo "<script>console.log('Mux Connected'); </script>";
}



}
?>

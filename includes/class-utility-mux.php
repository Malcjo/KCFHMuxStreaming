<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class Utility_Mux{

    public static function init() {
        ConnectMux();
    }

public static function ConnectMux(){
    if (!defined('MUX_TOKEN_ID') || !defined('MUX_TOKEN_SECRET')) {
        error_log('[KCFH] MUX constants NOT defined');
    } else {
        error_log('[KCFH] MUX constants present');
    }
    echo "<script>console.log('Mux Connected'); </script>";
}



}
?>
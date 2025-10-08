<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;

class Utility_Debug {
    public static function ConsoleLog($data) {
        $output = $data;
        if (is_array($output))
            $output = implode(',', $output);

        echo "<script>console.log('$output' );</script>";
    }
}
?>
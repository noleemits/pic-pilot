<?php

namespace PicPilot;

class Logger {
    protected static $log_file;

    public static function log($msg) {
        if (!self::$log_file) {
            $upload_dir = wp_upload_dir();
            self::$log_file = $upload_dir['basedir'] . '/pic-pilot.log';
        }

        $entry = '[' . date('Y-m-d H:i:s') . '] [PicPilot] ' . $msg . PHP_EOL;

        // Cap log file size at 100MB
        if (file_exists(self::$log_file) && filesize(self::$log_file) > 100 * 1024 * 1024) {
            return; // Stop logging
        }

        file_put_contents(self::$log_file, $entry, FILE_APPEND);
    }
}

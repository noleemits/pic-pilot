<?php

namespace PicPilot;

class Settings {
    protected static $defaults = [
        'enable_jpeg'           => false,
        'jpeg_quality'          => '80',
        'enable_backup'         => false,
        'auto_optimize_uploads' => false,
    ];

    public static function get(): array {
        return get_option('pic_pilot_options', []);
    }
    //Fucntion to re-use backup enabled check
    public static function is_backup_enabled() {
        $options = self::all();
        return !empty($options['enable_backup']);
    }

    public static function all() {
        return wp_parse_args(get_option('pic_pilot_options', []), self::$defaults);
    }
    //Server capabilities detection
    public static function get_capabilities() {
        return [
            'imagick' => extension_loaded('imagick'),
            'gd'      => function_exists('gd_info'),
            'webp'    => self::detect_webp_support(),
        ];
    }

    protected static function detect_webp_support() {
        if (extension_loaded('imagick')) {
            $formats = array_map('strtolower', \Imagick::queryFormats());
            if (in_array('webp', $formats)) return true;
        }

        if (function_exists('gd_info')) {
            $gd = gd_info();
            if (!empty($gd['WebP Support'])) return true;
        }

        return false;
    }
}

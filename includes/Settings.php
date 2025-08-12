<?php

namespace PicPilot;

class Settings {
    protected static $defaults = [
        // Core settings
        'enable_backup'         => false,
        'enable_logging'        => false,
        
        // Upload processing
        'upload_mode'           => 'disabled', // disabled|compress|convert
        
        // Compression mode settings
        'compression_engine'    => 'local',    // local|tinypng
        'jpeg_quality'          => '80',
        'resize_during_compression' => false,
        'compression_max_width' => '2048',
        'keep_original_after_compression_resize' => '1',
        
        // Conversion mode settings
        'convert_to_webp_on_upload' => true,
        'webp_engine'           => 'local',    // local|tinypng
        'webp_quality'          => '75',
        'resize_during_conversion' => false,
        'conversion_max_width'  => '2048',
        'keep_original_after_conversion_resize' => '1',
        
        // Manual optimization settings
        'resize_during_optimization' => false,
        'optimization_max_width' => '2048',
        'keep_original_after_optimization_resize' => '1',
        
        // Engine settings
        'enable_local_png'      => false,
        'use_tinypng_for_jpeg'  => false,
        'tinypng_api_key'       => '',
        'png_engine'            => '',
        
        // Advanced settings
        'strip_metadata'        => false,
        
        // Legacy settings (keep for compatibility)
        'enable_jpeg'           => false,
        'auto_optimize_upload'  => false,
        'optimize_on_upload'    => false,
        'resize_max_width'      => '2048',
        'resize_on_upload'      => false,
        'keep_original_after_resize' => '1',
    ];


    public static function get($key = null, $default = null) {
        $options = self::all();

        if ($key === null) {
            return $options;
        }

        return $options[$key] ?? $default;
    }

    //Fucntion to re-use backup enabled check
    public static function is_backup_enabled() {
        $options = self::all();
        return !empty($options['enable_backup']);
    }

    //Function to re-use auto optimize uploads check
    public static function is_optimize_on_upload_enabled(): bool {
        $options = self::all();
        return !empty($options['optimize_on_upload']);
    }

    public static function all() {
        $options = get_option('pic_pilot_options', []);
        
        // Migrate legacy settings to new consolidated structure (without logging to avoid recursion)
        $options = self::migrate_legacy_settings($options);
        
        return wp_parse_args($options, self::$defaults);
    }
    
    /**
     * Migrate legacy settings to new consolidated structure
     */
    private static function migrate_legacy_settings(array $options): array {
        // Migrate optimize_on_upload to upload_mode
        if (!empty($options['optimize_on_upload']) && empty($options['upload_mode'])) {
            // If they had compression enabled, set to compress mode
            $options['upload_mode'] = 'compress';
        }
        
        // Migrate resize_on_upload settings to compression mode
        if (!empty($options['resize_on_upload'])) {
            $options['resize_during_compression'] = $options['resize_on_upload'];
            if (!empty($options['resize_max_width'])) {
                $options['compression_max_width'] = $options['resize_max_width'];
            }
            if (!empty($options['keep_original_after_resize'])) {
                $options['keep_original_after_compression_resize'] = $options['keep_original_after_resize'];
            }
        }
        
        return $options;
    }
    //Server capabilities detection
    public static function get_capabilities() {
        return [
            'imagick' => extension_loaded('imagick'),
            'gd'      => function_exists('gd_info'),
            'webp'    => self::detect_webp_support(),
            'png_compression' => self::detect_png_compression_support(),
        ];
    }

    protected static function detect_webp_support() {
        // Check Imagick WebP support
        if (extension_loaded('imagick')) {
            $formats = array_map('strtolower', \Imagick::queryFormats());
            if (in_array('webp', $formats)) return 'imagick';
        }

        // Check GD WebP support
        if (function_exists('imagewebp') && function_exists('gd_info')) {
            $gd = gd_info();
            if (!empty($gd['WebP Support'])) return 'gd';
        }

        return false;
    }

    protected static function detect_png_compression_support() {
        // Check if we can process PNG files for compression
        if (extension_loaded('imagick')) {
            $formats = array_map('strtolower', \Imagick::queryFormats());
            if (in_array('png', $formats)) return 'imagick';
        }

        if (function_exists('gd_info') && function_exists('imagecreatefrompng')) {
            $gd = gd_info();
            if (!empty($gd['PNG Support'])) return 'gd';
        }

        return false;
    }

    //Check if settings are enabled
    public static function is_enabled(string $key): bool {
        $options = self::all();
        return !empty($options[$key]) && $options[$key] !== '0';
    }

    /**
     * Get the max width for optimization resize with fallback logic
     */
    public static function get_optimization_max_width(): int {
        $options = self::all();
        
        // Use optimization_max_width if set, otherwise fall back to resize_max_width
        $width = !empty($options['optimization_max_width']) 
            ? (int) $options['optimization_max_width']
            : (int) ($options['resize_max_width'] ?? 2048);
            
        return $width > 0 ? $width : 2048;
    }
}

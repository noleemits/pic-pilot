<?php

namespace PicPilot\Compressor;

use PicPilot\Logger;
use PicPilot\Settings;

class EngineRouter {
    public static function get_compressor(string $mime): CompressorInterface {
        $settings = Settings::get();
        if (strpos($mime, 'png') !== false) {
            // Smart PNG processing logic
            return self::resolve_png_compressor($settings);
        }

        if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) {
            if (!empty($settings['use_tinypng_for_jpeg']) && !empty($settings['tinypng_api_key'])) {
                \PicPilot\Logger::log("🔌 Routing JPEG to external engine");
                // Add external JPEG logic here later
                return new \PicPilot\Compressor\External\TinyPngCompressor();
            }

            Logger::log('🛑 Falling back to LocalJpegCompressor');

            return new \PicPilot\Compressor\Local\LocalJpegCompressor();
        }

        if (strpos($mime, 'webp') !== false) {
            Logger::log('🌐 WebP detected - using placeholder (WebP is already optimized)');
            return new \PicPilot\Compressor\PngCompressorPlaceholder(); // Use placeholder as no-op compressor
        }

        throw new \Exception("Unsupported MIME type: $mime");
    }
    //Resolve engine
    public static function resolve_engine(string $file_path): ?CompressorInterface {
        if (!file_exists($file_path)) {
            Logger::log('❌ resolve_engine: File does not exist — ' . $file_path);
            return null;
        }

        $mime = mime_content_type($file_path);
        Logger::log('🧭 resolve_engine mime: ' . $mime);

        // Special handling for PNG files: check if they should be converted to JPEG first
        // This is disabled when conversion mode is active (conversion mode handles WebP conversion)
        if (strpos($mime, 'png') !== false && Settings::is_enabled('convert_png_to_jpeg_if_opaque') && Settings::get('upload_mode') !== 'convert') {
            if (self::should_convert_png_to_jpeg($file_path)) {
                Logger::log('🔄 PNG will be converted to JPEG before compression');
                // Convert PNG to JPEG and return JPEG compressor
                $jpeg_path = self::convert_png_to_jpeg($file_path);
                if ($jpeg_path) {
                    return self::get_compressor('image/jpeg');
                }
                Logger::log('❌ PNG to JPEG conversion failed, proceeding with PNG compression');
            }
        }

        $compressor = self::get_compressor($mime);
        Logger::log('🛠️ resolved compressor: ' . get_class($compressor));

        return $compressor;
    }

    /**
     * Smart PNG compressor resolution
     */
    private static function resolve_png_compressor(array $settings): CompressorInterface {
        // Priority 1: Try local PNG compression if enabled
        if (!empty($settings['enable_local_png'])) {
            $capabilities = Settings::get_capabilities();
            if ($capabilities['imagick'] || $capabilities['gd']) {
                Logger::log("🔌 Routing PNG to Local PNG compressor");
                return new \PicPilot\Compressor\Local\LocalPngCompressor();
            } else {
                Logger::log("⚠️ Local PNG compression requested but no image libraries available");
            }
        }

        // Priority 2: Use external API if configured
        if (!empty($settings['png_engine']) && !empty($settings['tinypng_api_key'])) {
            Logger::log("🔌 Routing PNG to TinyPNG engine");
            return new \PicPilot\Compressor\External\TinyPngCompressor();
        }

        Logger::log("⚠️ No PNG compression method available. Falling back to placeholder.");
        return new \PicPilot\Compressor\PngCompressorPlaceholder();
    }

    /**
     * Check if PNG should be converted to JPEG (opaque images only)
     */
    private static function should_convert_png_to_jpeg(string $file_path): bool {
        if (!function_exists('imagecreatefrompng')) {
            return false;
        }

        $image = @imagecreatefrompng($file_path);
        if (!$image) {
            return false;
        }

        // Check if image has transparency
        $has_transparency = false;
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Sample check - check corners and center for transparency
        $check_points = [
            [0, 0], [$width-1, 0], [0, $height-1], [$width-1, $height-1], 
            [intval($width/2), intval($height/2)]
        ];
        
        foreach ($check_points as [$x, $y]) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha > 0) { // Any transparency found
                $has_transparency = true;
                break;
            }
        }

        imagedestroy($image);
        
        Logger::log($has_transparency ? "🖼️ PNG has transparency, keeping as PNG" : "🖼️ PNG is opaque, eligible for JPEG conversion");
        return !$has_transparency;
    }

    /**
     * Convert PNG to JPEG
     */
    private static function convert_png_to_jpeg(string $png_path): ?string {
        $quality = (int) Settings::get('jpeg_quality', 80);
        return \PicPilot\PngToJpegConverter::convert($png_path, $quality);
    }
}

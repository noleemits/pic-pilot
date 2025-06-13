<?php

namespace PicPilot\Compressor\Local;

use PicPilot\Compressor\CompressorInterface;
use PicPilot\Logger;
use PicPilot\Settings;
use WP_Image_Editor;

class LocalJpegCompressor implements CompressorInterface {
    public function compress($file_path): array {
        $settings = \PicPilot\Settings::get();
        $quality = min(max((int) ($settings['jpeg_quality'] ?? 80), 10), 100);

        if (!file_exists($file_path)) {
            Logger::log("❌ File does not exist: $file_path");
            return ['success' => false, 'original' => 0, 'optimized' => 0, 'saved' => 0];
        }

        $original_size = filesize($file_path);

        $editor = wp_get_image_editor($file_path);

        if (is_wp_error($editor)) {
            Logger::log("❌ No image editor available (Imagick/GD missing?): " . $editor->get_error_message());
            return [
                'success' => false,
                'original' => $original_size,
                'optimized' => $original_size,
                'saved' => 0
            ];
        }

        try {
            $editor->set_quality($quality);

            // Strip metadata, if possible
            // if (method_exists($editor, 'strip_meta')) {
            //     $editor->strip_meta();
            // }

            $result = $editor->save($file_path);

            if (is_wp_error($result)) {
                Logger::log("❌ Failed to save compressed JPEG: " . $result->get_error_message());
                return [
                    'success' => false,
                    'original' => $original_size,
                    'optimized' => $original_size,
                    'saved' => 0
                ];
            }

            clearstatcache(true, $file_path);
            $optimized_size = filesize($file_path);
            $saved = max($original_size - $optimized_size, 0);

            Logger::log("📉 JPEG optimized (q=$quality): $original_size → $optimized_size bytes (Saved: $saved)");

            return [
                'success' => true,
                'original' => $original_size,
                'optimized' => $optimized_size,
                'saved' => $saved
            ];
        } catch (\Throwable $e) {
            Logger::log("❌ JPEG compression failed: " . $e->getMessage());
            return [
                'success' => false,
                'original' => $original_size,
                'optimized' => $original_size,
                'saved' => 0
            ];
        }
    }
}

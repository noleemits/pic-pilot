<?php

namespace PicPilot\Compressor\Local;

use PicPilot\Compressor\CompressorInterface;
use PicPilot\Logger;
use PicPilot\Settings;

class LocalJpegCompressor implements CompressorInterface {
    public function compress($file_path): array {
        $settings = \PicPilot\Settings::get();
        $quality = min(max((int) ($settings['jpeg_quality'] ?? 80), 10), 100);

        if (!file_exists($file_path)) {
            \PicPilot\Logger::log("❌ File does not exist: $file_path");
            return ['success' => false, 'original' => 0, 'optimized' => 0, 'saved' => 0];
        }

        try {
            $original_size = filesize($file_path);
            if (!class_exists('Imagick')) {
                Logger::log("❌ Imagick not available on this server.");
                return [
                    'success' => false,
                    'original' => 0,
                    'optimized' => 0,
                    'saved' => 0,
                    'error' => 'Imagick not available'
                ];
            }
            $image = new \Imagick($file_path);
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality($quality);
            $image->stripImage();
            $image->writeImage($file_path);
            $image->clear();
            clearstatcache(true, $file_path); // ⚠️ very important

            $optimized_size = filesize($file_path);
            $saved = max($original_size - $optimized_size, 0);

            \PicPilot\Logger::log("📉 JPEG optimized (q=$quality): $original_size → $optimized_size bytes (Saved: $saved)");

            return [
                'success' => true,
                'original' => $original_size,
                'optimized' => $optimized_size,
                'saved' => $saved
            ];
        } catch (\Exception $e) {
            \PicPilot\Logger::log("❌ Imagick compression failed: " . $e->getMessage());
            return [
                'success' => false,
                'original' => 0,
                'optimized' => 0,
                'saved' => 0
            ];
        }
    }
}

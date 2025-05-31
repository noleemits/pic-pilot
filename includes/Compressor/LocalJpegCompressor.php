<?php

namespace PicPilot\Compressor;

use PicPilot\Logger;
use PicPilot\Settings;

class LocalJpegCompressor implements CompressorInterface {
    public function compress($file_path): bool {
        if (!file_exists($file_path)) {
            Logger::log("❌ File not found: $file_path");
            return false;
        }

        $mime = mime_content_type($file_path);
        if ($mime !== 'image/jpeg') {
            Logger::log("⛔ Skipping non-JPEG file: $file_path");
            return false;
        }

        $quality = (int) Settings::get('jpeg_quality');
        $original_size = filesize($file_path);

        // Try Imagick
        if (extension_loaded('imagick')) {
            try {
                $image = new \Imagick($file_path);
                $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality($quality);
                $image->stripImage(); // Remove EXIF, comments, etc.
                $image->writeImage($file_path);
                $image->destroy();

                $new_size = filesize($file_path);
                if ($new_size > $original_size) {
                    Logger::log("📈 Compressed file is larger. Skipping overwrite: $file_path");
                    return false;
                }

                Logger::log("✅ Compressed JPEG using Imagick: $file_path");
                return true;
            } catch (\Exception $e) {
                Logger::log("⚠️ Imagick failed: {$e->getMessage()}. Falling back to GD.");
            }
        }

        // Try GD fallback
        if (function_exists('imagecreatefromjpeg')) {
            $image = @imagecreatefromjpeg($file_path);
            if (!$image) {
                Logger::log("❌ GD failed to load image: $file_path");
                return false;
            }

            // Save to temp path to test size
            $tmp_path = $file_path . '.tmp';
            imagejpeg($image, $tmp_path, $quality);
            imagedestroy($image);

            if (filesize($tmp_path) >= $original_size) {
                unlink($tmp_path);
                Logger::log("📈 GD result is larger. Skipping overwrite: $file_path");
                return false;
            }

            rename($tmp_path, $file_path);
            Logger::log("✅ Compressed JPEG using GD: $file_path");
            return true;
        }

        Logger::log("❌ No compression library available for: $file_path");
        return false;
    }
}

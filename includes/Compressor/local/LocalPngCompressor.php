<?php

namespace PicPilot\Compressor\Local;

use PicPilot\Compressor\CompressorInterface;
use PicPilot\Logger;
use PicPilot\Settings;
use WP_Image_Editor;

class LocalPngCompressor implements CompressorInterface {
    public function compress($file_path): array {
        if (!file_exists($file_path)) {
            Logger::log("❌ File does not exist: $file_path");
            return ['success' => false, 'original' => 0, 'optimized' => 0, 'saved' => 0];
        }

        $original_size = filesize($file_path);

        // Check if server has required capabilities
        $capabilities = Settings::get_capabilities();
        if (!$capabilities['imagick'] && !$capabilities['gd']) {
            Logger::log("❌ No PNG compression libraries available (Imagick/GD missing)");
            return [
                'success' => false,
                'error' => 'Server lacks image processing libraries for PNG compression. Consider using an external API.',
                'original' => $original_size,
                'optimized' => $original_size,
                'saved' => 0,
                'server_limitation' => true
            ];
        }

        $editor = wp_get_image_editor($file_path);

        if (is_wp_error($editor)) {
            Logger::log("❌ No image editor available for PNG: " . $editor->get_error_message());
            return [
                'success' => false,
                'error' => 'Unable to load PNG for compression: ' . $editor->get_error_message(),
                'original' => $original_size,
                'optimized' => $original_size,
                'saved' => 0,
                'server_limitation' => true
            ];
        }

        try {
            // Create a backup to compare compression quality
            $temp_path = $file_path . '.tmp';
            copy($file_path, $temp_path);

            // Apply PNG optimization using WordPress image editor
            $result = $this->optimize_png($editor, $file_path);

            if (is_wp_error($result)) {
                // Restore original if compression failed
                if (file_exists($temp_path)) {
                    rename($temp_path, $file_path);
                }
                Logger::log("❌ PNG optimization failed: " . $result->get_error_message());
                return [
                    'success' => false,
                    'error' => 'PNG compression failed: ' . $result->get_error_message(),
                    'original' => $original_size,
                    'optimized' => $original_size,
                    'saved' => 0
                ];
            }

            clearstatcache(true, $file_path);
            $optimized_size = filesize($file_path);
            $saved = max($original_size - $optimized_size, 0);

            // Quality check: if compression didn't save much, keep original
            $compression_ratio = $original_size > 0 ? ($saved / $original_size) * 100 : 0;
            $min_savings_threshold = 2; // Minimum 2% savings to be worthwhile

            if ($compression_ratio < $min_savings_threshold) {
                // Restore original file
                if (file_exists($temp_path)) {
                    rename($temp_path, $file_path);
                }
                Logger::log("📊 PNG compression savings too minimal ({$compression_ratio}%), keeping original");
                return [
                    'success' => false,
                    'error' => 'Local PNG compression achieved minimal savings. Consider external API for better results.',
                    'original' => $original_size,
                    'optimized' => $original_size,
                    'saved' => 0,
                    'minimal_savings' => true
                ];
            }

            // Clean up temp file
            if (file_exists($temp_path)) {
                unlink($temp_path);
            }

            // Strip metadata if enabled
            if (Settings::is_enabled('strip_metadata')) {
                \PicPilot\Utils::strip_metadata($file_path);
                clearstatcache(true, $file_path);
                $final_size = filesize($file_path);
                $metadata_saved = max($optimized_size - $final_size, 0);
                $saved += $metadata_saved;
                Logger::log("🧼 PNG metadata stripped: Additional {$metadata_saved} bytes saved");
            }

            Logger::log("📉 PNG locally optimized: $original_size → $optimized_size bytes (Saved: $saved, {$compression_ratio}%)");

            return [
                'success' => true,
                'original' => $original_size,
                'optimized' => filesize($file_path),
                'saved' => $saved,
                'compression_ratio' => round($compression_ratio, 1)
            ];

        } catch (\Throwable $e) {
            // Restore original on any error
            if (file_exists($temp_path)) {
                rename($temp_path, $file_path);
            }
            Logger::log("❌ PNG compression failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'PNG compression error: ' . $e->getMessage(),
                'original' => $original_size,
                'optimized' => $original_size,
                'saved' => 0
            ];
        }
    }

    /**
     * Optimize PNG using available image library
     */
    private function optimize_png(WP_Image_Editor $editor, string $file_path) {
        // For PNG optimization, we primarily focus on:
        // 1. Lossless compression
        // 2. Color palette optimization
        // 3. Removing unnecessary chunks

        // WordPress image editor handles basic PNG optimization automatically
        // when saving, but we can enhance it by setting PNG-specific options
        
        if (method_exists($editor, 'set_quality')) {
            // For PNG, quality doesn't work the same as JPEG
            // But some editors use it for compression level (0-9)
            $editor->set_quality(95); // High quality, lossless compression
        }

        // Save the optimized PNG
        $result = $editor->save($file_path);
        
        return $result;
    }

    public function get_engine_name(): string {
        return 'Local PNG';
    }
}
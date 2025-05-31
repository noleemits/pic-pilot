<?php

namespace PicPilot;

use PicPilot\Compressor\LocalJpegCompressor;
use PicPilot\Logger;

class Optimizer {
    /**
     * Optimize the original and all thumbnails of an attachment.
     *
     * @param int $attachment_id
     * @return array Summary of optimization
     */
    public static function optimize_attachment(int $attachment_id): array {
        $total_saved = 0;
        $total_original = 0;
        $total_optimized = 0;
        $results = [];

        // Get the scaled image path (used as the "main" image by WP)
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            Logger::log("❌ Cannot optimize: file not found for attachment ID $attachment_id");
            return ['success' => false, 'reason' => 'file_not_found'];
        }

        // Attempt to find the unscaled/original version if WP created a scaled one
        $original_path = preg_replace('/-scaled\.(jpe?g)$/i', '.$1', $file_path);
        if ($original_path && file_exists($original_path)) {
            $result = self::optimize_file($original_path);
            if ($result['success']) {
                $results[] = $result;
                $total_original += $result['original'];
                $total_optimized += $result['optimized'];
                $total_saved += $result['saved'];
                Logger::log("🖼️ Unscaled original also optimized: $original_path");
            }
        }

        // Compress the "main" image (scaled or not)
        $result = self::optimize_file($file_path);
        if ($result['success']) {
            $results[] = $result;
            $total_original += $result['original'];
            $total_optimized += $result['optimized'];
            $total_saved += $result['saved'];
        }

        // Compress thumbnails
        $metadata = wp_get_attachment_metadata($attachment_id);
        $base_dir = dirname($file_path);

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $info) {
                $thumb_path = $base_dir . '/' . $info['file'];
                if (file_exists($thumb_path)) {
                    $thumb_result = self::optimize_file($thumb_path);
                    if ($thumb_result['success']) {
                        $results[] = $thumb_result;
                        $total_original += $thumb_result['original'];
                        $total_optimized += $thumb_result['optimized'];
                        $total_saved += $thumb_result['saved'];
                    }
                }
            }
        }

        // Save optimization metadata
        update_post_meta($attachment_id, '_pic_pilot_optimization', [
            'original' => $total_original,
            'optimized' => $total_optimized,
            'saved' => $total_saved,
            'status' => 'optimized',
            'timestamp' => time(),
        ]);

        Logger::log("✅ Total saved for ID $attachment_id: " . size_format($total_saved));

        return [
            'success' => true,
            'saved' => $total_saved,
            'original' => $total_original,
            'optimized' => $total_optimized,
            'files' => $results,
        ];
    }


    /**
     * Optimize a single JPEG file and return size info.
     */
    protected static function optimize_file($file_path): array {
        $compressor = new LocalJpegCompressor();
        $before = filesize($file_path);
        $success = $compressor->compress($file_path);
        $after = filesize($file_path);
        Logger::log("📊 Before: " . size_format($before) . " | After: " . size_format($after) . " | File: $file_path");


        return [
            'success' => $success,
            'file' => $file_path,
            'original' => $before,
            'optimized' => $after,
            'saved' => $success && $before > $after ? ($before - $after) : 0,
        ];
    }
}

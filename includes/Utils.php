<?php

namespace PicPilot;

class Utils {
    /**
     * Check if the given attachment is eligible for compression.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function is_compressible($attachment_id): bool {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) return false;

        $mime = mime_content_type($file_path);
        $settings = \PicPilot\Settings::get();

        // JPEGs are always compressible locally
        if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) {
            return true;
        }

        // WebP files are compressible if server supports WebP
        if (strpos($mime, 'webp') !== false) {
            $capabilities = \PicPilot\Settings::get_capabilities();
            return $capabilities['imagick'] || $capabilities['gd'];
        }

        // PNGs are compressible if:
        // 1. TinyPNG is configured, OR
        // 2. Local PNG compression is enabled
        if (strpos($mime, 'png') !== false) {
            // TinyPNG available
            if (!empty($settings['png_engine']) && !empty($settings['tinypng_api_key'])) {
                return true;
            }
            
            // Local PNG compression available
            if (!empty($settings['enable_local_png'])) {
                $capabilities = \PicPilot\Settings::get_capabilities();
                if ($capabilities['imagick'] || $capabilities['gd']) {
                    return true;
                }
            }
        }

        return false;
    }
    /**
     * Compress all thumbnails for a given attachment using the provided compressor.
     *
     * @param int $attachment_id
     * @param object $compressor  An object with a compress($file_path) method (e.g., a CompressorInterface)
     * @param string|null $base_file_path Optional. The directory path of the original image. If null, will be derived from the attachment.
     * @return int Total bytes saved across all thumbnails
     */
    public static function compress_thumbnails($attachment_id, $compressor, $base_file_path = null): int {
        $meta = wp_get_attachment_metadata($attachment_id);
        if (empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return 0;
        }

        // Determine the directory for thumbnails
        if ($base_file_path === null) {
            $base_file_path = get_attached_file($attachment_id);
        }
        $dir = dirname($base_file_path);

        $total_saved = 0;
        $thumbnail_details = [];
        
        foreach ($meta['sizes'] as $size => $info) {
            if (empty($info['file'])) continue;
            $thumb_path = path_join($dir, $info['file']);
            if (file_exists($thumb_path)) {
                $before = filesize($thumb_path);
                $result = $compressor->compress($thumb_path);
                $after = file_exists($thumb_path) ? filesize($thumb_path) : $before;
                $saved = ($before > $after) ? ($before - $after) : 0;
                $total_saved += $saved;
                
                // Store detailed information for each thumbnail
                $thumbnail_details[$size] = [
                    'file' => $info['file'],
                    'dimensions' => $info['width'] . 'x' . $info['height'],
                    'before' => $before,
                    'after' => $after,
                    'saved' => $saved,
                    'saved_percent' => $before > 0 ? round(($saved / $before) * 100, 1) : 0
                ];
            }
        }
        
        // Store thumbnail compression details for tooltip use
        update_post_meta($attachment_id, '_pic_pilot_thumbnail_details', $thumbnail_details);
        
        // Debug logging
        Logger::log("💾 Stored thumbnail details for attachment {$attachment_id}:", $thumbnail_details);
        
        return $total_saved;
    }

    /**
     * Generate detailed tooltip content for optimization savings
     *
     * @param int $attachment_id
     * @return string HTML content for tooltip
     */
    public static function generate_optimization_tooltip(int $attachment_id): string {
        $summary = \PicPilot\Optimizer::summarize_optimization($attachment_id);
        $thumbnail_details = get_post_meta($attachment_id, '_pic_pilot_thumbnail_details', true);
        $engine = $summary['engine'];
        $total_saved_bytes = (int) get_post_meta($attachment_id, '_pic_pilot_bytes_saved', true);
        $wp_meta = wp_get_attachment_metadata($attachment_id);
        
        // Debug logging
        Logger::log("🔍 Tooltip debug for attachment {$attachment_id}:", [
            'thumbnail_details' => $thumbnail_details,
            'total_saved_bytes' => $total_saved_bytes,
            'summary' => $summary
        ]);
        
        $tooltip = '<div class="pic-pilot-tooltip">';
        $tooltip .= '<div class="tooltip-header"><strong>Optimization Summary</strong></div>';
        
        // Resize information (show prominently if resized)
        if (!empty($wp_meta['pic_pilot_resize_info'])) {
            $resize_data = $wp_meta['pic_pilot_resize_info'];
            $tooltip .= '<div class="tooltip-section" style="background: #e7f3ff; padding: 6px; border-radius: 4px; margin-bottom: 8px; border: 1px solid #007cba;">';
            $tooltip .= '<div class="tooltip-label" style="color: #0066a0;"><strong>📏 Image Resized from Original:</strong></div>';
            $tooltip .= '<div class="tooltip-value" style="margin-bottom: 4px; color: #333;">';
            $tooltip .= $resize_data['original_dimensions']['width'] . '×' . $resize_data['original_dimensions']['height'];
            $tooltip .= ' → ';
            $tooltip .= $resize_data['final_dimensions']['width'] . '×' . $resize_data['final_dimensions']['height'];
            $tooltip .= '</div>';
            $tooltip .= '<div class="tooltip-value" style="font-size: 11px; color: #333; margin-bottom: 2px;">Max width: ' . $resize_data['max_width'] . 'px (' . $resize_data['mode'] . ' mode)</div>';
            
            // Show whether original was kept
            $original_status = isset($resize_data['original_kept']) && $resize_data['original_kept'] 
                ? '<span style="color: #198754; font-weight: bold;">✓ Original kept as backup</span>' 
                : '<span style="color: #dc3545; font-weight: bold;">✗ Original replaced</span>';
            $tooltip .= '<div class="tooltip-value" style="font-size: 11px;">' . $original_status . '</div>';
            $tooltip .= '</div>';
        }
        
        // Total savings info (now includes removed thumbnails automatically)
        $tooltip .= '<div class="tooltip-section">';
        $tooltip .= '<div class="tooltip-label">Total Saved:</div>';
        $tooltip .= '<div class="tooltip-value">' . self::format_bytes($total_saved_bytes) . ' (' . $summary['saved_percent'] . '%)</div>';
        $tooltip .= '</div>';
        
        // Engine info
        $tooltip .= '<div class="tooltip-section">';
        $tooltip .= '<div class="tooltip-label">Engine:</div>';
        $tooltip .= '<div class="tooltip-value">' . esc_html($engine) . '</div>';
        $tooltip .= '</div>';
        
        // Main image info
        if ($summary['main_saved'] > 0) {
            $tooltip .= '<div class="tooltip-section">';
            $tooltip .= '<div class="tooltip-label">Main Image:</div>';
            $tooltip .= '<div class="tooltip-value">' . self::format_bytes($summary['main_saved']) . ' (' . $summary['main_percent'] . '%)</div>';
            $tooltip .= '</div>';
        }
        
        // Thumbnails breakdown
        if (!empty($thumbnail_details) && is_array($thumbnail_details)) {
            $total_thumb_saved = array_sum(array_column($thumbnail_details, 'saved'));
            
            $tooltip .= '<div class="tooltip-section">';
            $tooltip .= '<div class="tooltip-label"><strong>Thumbnails (' . count($thumbnail_details) . '):</strong></div>';
            $tooltip .= '<div class="tooltip-value" style="margin-bottom: 4px;">' . self::format_bytes($total_thumb_saved) . ' total</div>';
            
            foreach ($thumbnail_details as $size => $details) {
                $tooltip .= '<div class="tooltip-thumb-row">';
                $tooltip .= '<span class="thumb-name">' . esc_html($size) . '</span> ';
                $tooltip .= '<span class="thumb-dims">(' . esc_html($details['dimensions']) . ')</span>: ';
                if ($details['saved'] > 0) {
                    $tooltip .= '<span class="thumb-savings">' . self::format_bytes($details['saved']) . ' (' . $details['saved_percent'] . '%)</span>';
                } else {
                    $tooltip .= '<span class="thumb-savings" style="color: #999;">No savings</span>';
                }
                $tooltip .= '</div>';
            }
            $tooltip .= '</div>';
        } elseif ($summary['thumb_count'] > 0) {
            // Try to get basic thumbnail info from WordPress metadata
            $wp_meta = wp_get_attachment_metadata($attachment_id);
            if (!empty($wp_meta['sizes'])) {
                $tooltip .= '<div class="tooltip-section">';
                $tooltip .= '<div class="tooltip-label">Thumbnails (' . count($wp_meta['sizes']) . '):</div>';
                $tooltip .= '<div class="tooltip-value" style="margin-bottom: 4px;">Total: ' . self::format_bytes($summary['thumbs']) . '</div>';
                
                foreach ($wp_meta['sizes'] as $size => $info) {
                    $tooltip .= '<div class="tooltip-thumb-row">';
                    $tooltip .= '<span class="thumb-name">' . esc_html($size) . '</span> ';
                    $tooltip .= '<span class="thumb-dims">(' . $info['width'] . 'x' . $info['height'] . ')</span>: ';
                    $tooltip .= '<span class="thumb-savings" style="color: #999;">Optimized</span>';
                    $tooltip .= '</div>';
                }
                $tooltip .= '</div>';
            } else {
                $tooltip .= '<div class="tooltip-section">';
                $tooltip .= '<div class="tooltip-label">Thumbnails:</div>';
                $tooltip .= '<div class="tooltip-value">' . self::format_bytes($summary['thumbs']) . ' saved</div>';
                $tooltip .= '</div>';
            }
        }
        
        // Removed thumbnails breakdown (integrated into main savings)
        if (!empty($wp_meta['pic_pilot_removed_thumbnails'])) {
            $removed_data = $wp_meta['pic_pilot_removed_thumbnails'];
            $tooltip .= '<div class="tooltip-section" style="border-top: 1px solid #ddd; padding-top: 8px; margin-top: 8px;">';
            $tooltip .= '<div class="tooltip-label"><strong>🧹 Storage Saved (Removed Thumbnails):</strong></div>';
            $tooltip .= '<div class="tooltip-value" style="margin-bottom: 4px; color: #d63384;">' . self::format_bytes($removed_data['total_savings']) . '</div>';
            $tooltip .= '<div class="tooltip-value" style="font-size: 11px; color: #333; margin-bottom: 4px;">Thumbnails larger than ' . $removed_data['max_width'] . 'px were removed (' . $removed_data['mode'] . ' mode)</div>';
            
            foreach ($removed_data['thumbnails'] as $thumb) {
                $tooltip .= '<div class="tooltip-thumb-row" style="font-size: 11px; color: #333;">';
                $tooltip .= '<span class="thumb-name">' . esc_html($thumb['size_name']) . '</span> ';
                $tooltip .= '<span class="thumb-dims">(' . $thumb['width'] . 'x' . $thumb['height'] . ')</span>: ';
                $tooltip .= '<span class="thumb-savings" style="color: #d63384;">-' . self::format_bytes($thumb['file_size']) . '</span>';
                $tooltip .= '</div>';
            }
            $tooltip .= '</div>';
        }
        
        $tooltip .= '</div>';
        return $tooltip;
    }
    
    /**
     * Format bytes into human readable format
     */
    private static function format_bytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        } else {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
    }


    /**
     * Get the file extension from a MIME type.
     * Useful for debugging or filtering.
     *
     * @param string $mime
     * @return string|null
     */
    public static function extension_from_mime($mime): ?string {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        return $map[$mime] ?? null;
    }
    //Clean oversized images
    public static function clean_oversized_images(array $metadata, string $base_path, int $max_dimension): array {
        if (!\PicPilot\Settings::is_enabled('resize_on_upload')) {
            return $metadata;
        }
        $base_dir = dirname($base_path);
        $main_filename = basename($metadata['file']);
        $main_path = path_join($base_dir, $main_filename);

        // Protect main file and all thumbnails
        $protected_files = [$main_filename];
        foreach ($metadata['sizes'] ?? [] as $info) {
            $protected_files[] = $info['file'];
        }

        $original_filename = basename($base_path);

        \PicPilot\Logger::log("🧪 Delete check for original:\n" . print_r([
            'original_filename' => $original_filename,
            'main_filename'     => $main_filename,
            'protected_files'   => $protected_files,
            'file_exists'       => file_exists($base_path) ? 'yes' : 'no',
        ], true));

        // Remove $base_path file only if it's neither main nor protected
        if (
            !in_array($original_filename, $protected_files) &&
            $original_filename !== $main_filename &&
            file_exists($base_path)
        ) {
            unlink($base_path);
        }

        // 🧨 Delete separate original file if a -scaled file is being used
        $possible_original = preg_replace('/-scaled(\.\w+)$/', '$1', $main_filename);
        $possible_original_path = path_join($base_dir, $possible_original);

        if (
            $possible_original !== $main_filename &&
            !in_array($possible_original, $protected_files) &&
            file_exists($possible_original_path)
        ) {
            unlink($possible_original_path);
            \PicPilot\Logger::log("🧨 Deleted separate original file: $possible_original");
        }

        // Clean oversized thumbnails and track savings
        $removed_thumbnails_data = [];
        $total_savings = 0;
        
        foreach ($metadata['sizes'] as $size => $info) {
            $thumb_path = path_join($base_dir, $info['file']);
            if (!file_exists($thumb_path)) continue;

            [$width, $height] = getimagesize($thumb_path);
            if ($width > $max_dimension) {
                $file_size = filesize($thumb_path);
                $removed_thumbnails_data[] = [
                    'size_name' => $size,
                    'file' => $info['file'],
                    'width' => $width,
                    'height' => $height,
                    'file_size' => $file_size
                ];
                $total_savings += $file_size;
                
                unlink($thumb_path);
                unset($metadata['sizes'][$size]);
                \PicPilot\Logger::log("🧹 Removed oversized thumbnail [$size]: {$info['file']} ({$width}px, " . size_format($file_size) . ")");
            }
        }
        
        // Store removed thumbnails data for reporting
        if (!empty($removed_thumbnails_data)) {
            $metadata['pic_pilot_removed_thumbnails'] = [
                'thumbnails' => $removed_thumbnails_data,
                'total_savings' => $total_savings,
                'max_width' => $max_dimension,
                'mode' => 'resize'
            ];
            \PicPilot\Logger::log("💾 Captured " . count($removed_thumbnails_data) . " removed thumbnails data, total savings: " . size_format($total_savings));
        }

        return $metadata;
    }
    //Centeralize how we save the results of an image
    public static function update_compression_metadata(
        int $attachment_id,
        int $total_saved,
        string $engine = 'unknown',
        bool $log = false,
        ?int $original_size = null,
        ?int $optimized_size = null,
        ?int $thumbs_saved = null
    ): void {
        $timestamp = time();
        
        // Check for PNG conversion savings and add them to total
        $png_conversion_savings = (int) get_post_meta($attachment_id, '_pic_pilot_png_conversion_savings', true);
        if ($png_conversion_savings > 0) {
            $total_saved += $png_conversion_savings;
            Logger::log("📊 Adding PNG conversion savings: {$png_conversion_savings} bytes to total: {$total_saved} bytes");
            
            // Use original PNG size if available for better calculation
            $original_png_size = (int) get_post_meta($attachment_id, '_pic_pilot_original_png_size', true);
            if ($original_png_size > 0 && !$original_size) {
                $original_size = $original_png_size;
                Logger::log("📊 Using original PNG size for metadata: {$original_size} bytes");
            }
            
            // Clean up temporary metadata
            delete_post_meta($attachment_id, '_pic_pilot_png_conversion_savings');
            delete_post_meta($attachment_id, '_pic_pilot_original_png_size');
        }
        
        // Check for removed thumbnails savings and add them to total
        $wp_meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($wp_meta['pic_pilot_removed_thumbnails'])) {
            $removed_savings = $wp_meta['pic_pilot_removed_thumbnails']['total_savings'];
            $total_saved += $removed_savings;
            Logger::log("📊 Adding removed thumbnails savings: {$removed_savings} bytes to total: {$total_saved} bytes");
        }

        update_post_meta($attachment_id, '_pic_pilot_optimized', true);
        update_post_meta($attachment_id, '_pic_pilot_bytes_saved', $total_saved);
        update_post_meta($attachment_id, '_pic_pilot_engine', sanitize_text_field($engine));
        update_post_meta($attachment_id, '_pic_pilot_optimized_version', $timestamp);

        // Only update size-specific fields if data was provided
        if (!is_null($original_size)) {
            update_post_meta($attachment_id, '_pic_pilot_original_size', $original_size);
        }

        if (!is_null($optimized_size)) {
            update_post_meta($attachment_id, '_pic_pilot_optimized_size', $optimized_size);
        }

        if (!is_null($thumbs_saved)) {
            update_post_meta($attachment_id, '_pic_pilot_optimized_thumbs', $thumbs_saved);
        }

        update_post_meta($attachment_id, '_pic_pilot_optimization', [
            'status'    => 'optimized',
            'saved'     => $total_saved,
            'timestamp' => $timestamp,
        ]);

        if ($log && class_exists('\\PicPilot\\Logger')) {
            Logger::log("✅ Optimization metadata saved", [
                'attachment_id'   => $attachment_id,
                'engine'          => $engine,
                'total_saved'     => $total_saved,
                'original_size'   => $original_size,
                'optimized_size'  => $optimized_size,
                'thumbs_saved'    => $thumbs_saved,
                'source'          => __METHOD__,
            ]);
        }
    }

    //Clear optimization metadata
    public static function clear_optimization_metadata($attachment_id) {
        delete_post_meta($attachment_id, '_pic_pilot_optimization');
        delete_post_meta($attachment_id, '_pic_pilot_bytes_saved');
        delete_post_meta($attachment_id, '_pic_pilot_engine');
        delete_post_meta($attachment_id, '_pic_pilot_optimized_version');
        delete_post_meta($attachment_id, '_pic_pilot_optimized');
    }
    //Strip metadata from an image
    public static function strip_metadata(string $path): void {
        try {
            if (class_exists('Imagick')) {
                $img = new \Imagick($path);
                $img->stripImage();
                $img->writeImage($path);
                \PicPilot\Logger::log("🧽 Stripped metadata: " . basename($path));
            }
        } catch (\Throwable $e) {
            \PicPilot\Logger::log("⚠️ Failed to strip metadata for $path: " . $e->getMessage());
        }
    }
    //Short compressor name
    public static function short_class_name($object): string {
        $class = is_object($object) ? get_class($object) : (string) $object;
        $parts = explode('\\', $class);
        $short = end($parts);

        // Optional label mapping
        return match ($short) {
            'LocalJpegCompressor' => 'Local Engine',
            'TinyPngCompressor'   => 'TinyPNG',
            default               => $short,
        };
    }

    /**
     * Check if a PNG file is fully opaque (no transparency).
     *
     * @param string $file Path to the PNG file.
     * @return bool True if the PNG is fully opaque, false if it has transparency or does not exist.
     */
    public static function isOpaquePng(string $file): bool {
        if (!file_exists($file)) {
            return false;
        }

        $image = @imagecreatefrompng($file);
        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = imagecolorat($image, $x, $y);
                $colors = imagecolorsforindex($image, $index);

                if ($colors['alpha'] > 0) {
                    imagedestroy($image);
                    return false; // Found transparency
                }
            }
        }

        imagedestroy($image);
        return true; // Fully opaque
    }
}

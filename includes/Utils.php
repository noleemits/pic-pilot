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

        // PNGs are compressible if TinyPNG is active
        if (
            strpos($mime, 'png') !== false &&
            !empty($settings['png_engine']) &&
            !empty($settings['tinypng_api_key'])
        ) {
            return true;
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
        foreach ($meta['sizes'] as $size => $info) {
            if (empty($info['file'])) continue;
            $thumb_path = path_join($dir, $info['file']);
            if (file_exists($thumb_path)) {
                $before = filesize($thumb_path);
                $result = $compressor->compress($thumb_path);
                $after = file_exists($thumb_path) ? filesize($thumb_path) : $before;
                $saved = ($before > $after) ? ($before - $after) : 0;
                $total_saved += $saved;
            }
        }
        return $total_saved;
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

        // Clean oversized thumbnails
        foreach ($metadata['sizes'] as $size => $info) {
            $thumb_path = path_join($base_dir, $info['file']);
            if (!file_exists($thumb_path)) continue;

            [$width, $height] = getimagesize($thumb_path);
            if ($width > $max_dimension || $height > $max_dimension) {
                unlink($thumb_path);
                unset($metadata['sizes'][$size]);
                \PicPilot\Logger::log("🧹 Removed oversized thumbnail [$size]: {$info['file']} ($width px)");
            }
        }

        return $metadata;
    }
}

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
            !empty($settings['enable_tinypng']) &&
            !empty($settings['tinypng_api_key'])
        ) {
            return true;
        }

        return false;
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
}

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

        if (!$file_path || !file_exists($file_path)) {
            return false;
        }

        $mime = mime_content_type($file_path);

        // Only allow JPEG for now. PNG and WebP handled in future.
        return in_array($mime, ['image/jpeg', 'image/jpg']);
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

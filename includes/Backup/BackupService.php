<?php

namespace PicPilot\Backup;

require_once __DIR__ . '/Restore/ContentUpdater.php';

use PicPilot\Restore\ContentUpdater;

if (! defined('ABSPATH')) exit;

use PicPilot\Settings;
use PicPilot\Logger;


class BackupService {
    const META_KEY = '_pic_pilot_backup';
    const BACKUP_DIR = '/pic-pilot-backups/';


    /** @var array<int, bool> */
    protected static $alreadyBackedUp = [];

    /**
     * Get the backup directory (creates if missing)
     */
    public static function get_backup_dir(): string {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'pic-pilot-backups/';
        if (! file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
            // Create an empty index.html to prevent directory listing
            @file_put_contents($backup_dir . 'index.html', '');
        }
        return $backup_dir;
    }

    /**
     * Generate a backup file path for an attachment
     */
    public static function get_backup_file_path(int $attachment_id): string {
        $file = get_attached_file($attachment_id);
        $info = pathinfo($file);
        $timestamp = date('YmdHis');
        return self::get_backup_dir() . "original-{$attachment_id}-{$timestamp}." . $info['extension'];
    }

    /**
     * Does a backup exist for this attachment?
     */
    public static function backup_exists(int $attachment_id): bool {
        $meta = self::get_backup_metadata($attachment_id);
        return ! empty($meta) && file_exists(self::get_backup_dir() . $meta['backup_filename']);
    }

    /**
     * Create a full backup (main file + all thumbs) for an attachment.
     */
    public static function create_backup(int $attachment_id, bool $force = false): bool {
        // Prevent multiple backups for the same attachment in one request
        if (isset(self::$alreadyBackedUp[$attachment_id])) {
            return true; // Already backed up
        }

        self::$alreadyBackedUp[$attachment_id] = true;

        if (!$force && !Settings::is_enabled('enable_backup')) {
            return false;
        }
        if (!$force && !Settings::is_backup_enabled()) {
            Logger::log("⏩ Skipping backup for ID $attachment_id: Backups disabled.");
            return false;
        }


        $main_file = get_attached_file($attachment_id);
        if (!$main_file || !file_exists($main_file)) {
            Logger::log("❌ Cannot backup: Main file missing for ID $attachment_id");
            return false;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (empty($meta)) {
            Logger::log("❌ Cannot backup: No attachment metadata for ID $attachment_id");
            return false;
        }

        $uploads = wp_upload_dir();
        $backup_root = trailingslashit($uploads['basedir']) . ltrim(self::BACKUP_DIR, '/');
        $backup_dir = $backup_root . $attachment_id . '/';

        // Create the backup folder
        if (!wp_mkdir_p($backup_dir)) {
            Logger::log("❌ Cannot backup: Failed to create backup folder for ID $attachment_id");
            return false;
        }

        // Prepare manifest
        $mime = mime_content_type($main_file);
        $manifest = [
            'main' => [
                'filename'      => 'main.' . pathinfo($main_file, PATHINFO_EXTENSION),
                'original_path' => self::relative_upload_path($main_file, $uploads['basedir'])
            ],
            'thumbnails' => [],
            'backup_created' => time(),
            'original_filesize' => filesize($main_file)
        ];

        // Handle format conversion tracking
        if ($mime === 'image/png') {
            $manifest['converted_from_png'] = true;
            $manifest['original_mime'] = 'image/png';
        } elseif ($mime === 'image/webp') {
            // Check if this was converted to WebP from another format
            $original_format = get_post_meta($attachment_id, '_pic_pilot_original_format', true);
            if ($original_format) {
                $manifest['converted_to_webp'] = true;
                $manifest['original_mime'] = $original_format;
                Logger::log("📝 WebP backup: Original format was $original_format");
            } else {
                $manifest['original_mime'] = 'image/webp';
            }
        }

        Logger::log("Detected MIME during backup: " . $mime);
        // Copy main file
        $main_backup = $backup_dir . $manifest['main']['filename'];
        if (!@copy($main_file, $main_backup)) {
            Logger::log("❌ Cannot backup: Failed to copy main file for ID $attachment_id");
            return false;
        }

        // Copy all thumbnails
        $thumb_errors = 0;
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $base_dir = dirname($main_file);
            foreach ($meta['sizes'] as $size_key => $size_data) {
                if (empty($size_data['file'])) continue;
                $thumb_file = $base_dir . '/' . $size_data['file'];
                $thumb_ext  = pathinfo($thumb_file, PATHINFO_EXTENSION);
                $thumb_backup = $backup_dir . 'thumb-' . $size_key . '.' . $thumb_ext;

                $manifest['thumbnails'][$size_key] = [
                    'filename' => basename($thumb_backup),
                    'original_path' => self::relative_upload_path($thumb_file, $uploads['basedir'])
                ];

                if (file_exists($thumb_file)) {
                    if (!@copy($thumb_file, $thumb_backup)) {
                        Logger::log("❌ Cannot backup: Failed to copy thumb $size_key for ID $attachment_id");
                        $thumb_errors++;
                    }
                } else {
                    Logger::log("Skipped backup for $size_key: Already deleted after resize (expected)
");
                    $thumb_errors++;
                }
            }
        }

        // Write manifest
        $manifest_file = $backup_dir . 'manifest.json';
        file_put_contents($manifest_file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return true;
    }

    /**
     * Get the relative upload path for manifest (e.g., 2025/06/photo-scaled.jpg).
     */
    protected static function relative_upload_path($file, $basedir) {
        $rel = ltrim(str_replace($basedir, '', $file), '/\\');
        return str_replace('\\', '/', $rel); // normalize for Windows hosts
    }
    /**
     * Restore the original image from backup (and regenerate thumbnails)
     */

    public static function restore_backup(int $attachment_id): bool {
        if (!\PicPilot\Settings::is_backup_enabled()) {
            \PicPilot\Logger::log("Restore failed: Backups not enabled.");
            return false;
        }

        global $pic_pilot_restoring;
        $pic_pilot_restoring = true;

        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir']);
        $backup_dir = trailingslashit($basedir) . 'pic-pilot-backups/' . $attachment_id . '/';
        $manifest_file = path_join($backup_dir, 'manifest.json');

        if (!file_exists($manifest_file)) {
            \PicPilot\Logger::log("Restore failed: Manifest not found for ID $attachment_id");
            $pic_pilot_restoring = false;
            return false;
        }

        $manifest = json_decode(file_get_contents($manifest_file), true);
        if (empty($manifest['main']['filename']) || empty($manifest['main']['original_path'])) {
            \PicPilot\Logger::log("Restore failed: Manifest incomplete for ID $attachment_id");
            $pic_pilot_restoring = false;
            return false;
        }

        $main_backup = path_join($backup_dir, $manifest['main']['filename']);
        $main_target = path_join($basedir, wp_normalize_path($manifest['main']['original_path']));
        $relative_path = ltrim(wp_normalize_path($manifest['main']['original_path']), '/');

        \PicPilot\Logger::log("\ud83d\udd0d Checking existence of main backup: $main_backup");
        if (!file_exists($main_backup)) {
            \PicPilot\Logger::log("\u274c Main backup file missing: $main_backup");
            $pic_pilot_restoring = false;
            return false;
        }
        if (!is_writable(dirname($main_target))) {
            \PicPilot\Logger::log("\u274c Main target directory not writable: " . dirname($main_target));
            $pic_pilot_restoring = false;
            return false;
        }

        if (!@copy($main_backup, $main_target)) {
            \PicPilot\Logger::log("Restore failed: Could not copy main backup for ID $attachment_id");
            $pic_pilot_restoring = false;
            return false;
        }
        \PicPilot\Logger::log("\u2705 Main backup restored to: $main_target");

        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
        wp_update_post([
            'ID' => $attachment_id,
            'guid' => $uploads['baseurl'] . '/' . $relative_path,
            'post_mime_type' => $manifest['original_mime'] ?? 'image/png',
        ]);

        if (!empty($manifest['converted_from_png'])) {
            \PicPilot\Logger::log("\ud83d\udd01 Updated MIME and file path to PNG: $relative_path");

            $jpg_path = preg_replace('/\.png$/i', '.jpg', $main_target);
            if (file_exists($jpg_path)) {
                \PicPilot\Logger::log("\ud83e\ude79 Deleting leftover JPEG: $jpg_path");
                @unlink($jpg_path);
            }

            foreach ($manifest['thumbnails'] as $thumb_meta) {
                $jpg_thumb = preg_replace('/\.png$/i', '.jpg', $thumb_meta['original_path'] ?? '');
                if ($jpg_thumb) {
                    $jpg_thumb_path = path_join($basedir, wp_normalize_path($jpg_thumb));
                    if (file_exists($jpg_thumb_path)) {
                        \PicPilot\Logger::log("\ud83e\ude79 Deleting leftover JPEG thumb: $jpg_thumb_path");
                        @unlink($jpg_thumb_path);
                    }
                }
            }
        } elseif (!empty($manifest['converted_to_webp'])) {
            \PicPilot\Logger::log("🔁 Restoring from WebP to original format: " . ($manifest['original_mime'] ?? 'unknown'));

            // Delete WebP files that are being replaced
            $webp_extension = pathinfo($main_target, PATHINFO_EXTENSION);
            $webp_path = str_replace('.' . $webp_extension, '.webp', $main_target);
            if (file_exists($webp_path)) {
                \PicPilot\Logger::log("🗿 Deleting leftover WebP: $webp_path");
                @unlink($webp_path);
            }

            // Delete WebP thumbnails
            foreach ($manifest['thumbnails'] as $thumb_meta) {
                $original_thumb_path = $thumb_meta['original_path'] ?? '';
                if ($original_thumb_path) {
                    $webp_thumb = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $original_thumb_path);
                    if ($webp_thumb !== $original_thumb_path) {
                        $webp_thumb_path = path_join($basedir, wp_normalize_path($webp_thumb));
                        if (file_exists($webp_thumb_path)) {
                            \PicPilot\Logger::log("🗿 Deleting leftover WebP thumb: $webp_thumb_path");
                            @unlink($webp_thumb_path);
                        }
                    }
                }
            }
        }

        $thumb_count = 0;
        $thumb_failures = 0;

        if (!empty($manifest['thumbnails']) && is_array($manifest['thumbnails'])) {
            foreach ($manifest['thumbnails'] as $size_key => $thumb_meta) {
                $thumb_backup = path_join($backup_dir, $thumb_meta['filename']);
                $thumb_target = path_join($basedir, wp_normalize_path($thumb_meta['original_path']));

                if (file_exists($thumb_backup) && is_writable(dirname($thumb_target))) {
                    if (@copy($thumb_backup, $thumb_target)) {
                        $thumb_count++;
                    } else {
                        $thumb_failures++;
                        \PicPilot\Logger::log("Restore failed: Could not copy thumb $size_key for ID $attachment_id");
                    }
                } else {
                    $thumb_failures++;
                    \PicPilot\Logger::log("Restore failed: Thumb backup missing or unwritable ($size_key) for ID $attachment_id");
                }
            }
        }

        clean_post_cache($attachment_id);
        wp_cache_delete($attachment_id, 'post_meta');

        $file_path = get_attached_file($attachment_id);
        $mime_type = wp_check_filetype($file_path)['type'] ?? 'image/png';
        if ($mime_type !== 'image/png') {
            $mime_type = 'image/png';
            wp_update_post([
                'ID' => $attachment_id,
                'post_mime_type' => $mime_type,
            ]);
        }

        remove_filter('wp_generate_attachment_metadata', ['PicPilot\Upload\UploadOptimizer', 'maybeConvertPngToJpeg'], 9);
        global $pic_pilot_upload_optimizer_instance;
        if ($pic_pilot_upload_optimizer_instance) {
            remove_filter('wp_generate_attachment_metadata', [$pic_pilot_upload_optimizer_instance, 'maybe_optimize_on_upload'], 20);
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);

        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $key => $size) {
                $thumb_file = path_join(dirname($file_path), $size['file']);
                if (!file_exists($thumb_file)) {
                    unset($metadata['sizes'][$key]);
                } else {
                    // Set correct MIME type based on what we're restoring to
                    $metadata['sizes'][$key]['mime-type'] = $manifest['original_mime'] ?? 'image/png';
                }
            }
        }
        wp_update_attachment_metadata($attachment_id, $metadata);

        add_filter('wp_generate_attachment_metadata', ['PicPilot\Upload\UploadOptimizer', 'maybeConvertPngToJpeg'], 9, 2);
        if ($pic_pilot_upload_optimizer_instance) {
            add_filter('wp_generate_attachment_metadata', [$pic_pilot_upload_optimizer_instance, 'maybe_optimize_on_upload'], 20, 2);
        }

        \PicPilot\Logger::log("\ud83d\udce6 Final metadata restored for PNG file.");
        update_post_meta($attachment_id, '_pic_pilot_restore_version', $manifest['backup_created'] + 1);
        \PicPilot\Logger::log("\u2705 Restored backup for ID $attachment_id: Main file + $thumb_count thumbs. " . ($thumb_failures ? 'Failures: ' . $thumb_failures : ''));
        \PicPilot\Utils::clear_optimization_metadata($attachment_id);

        // Handle URL replacement for different conversion types
        $new_url = wp_get_attachment_url($attachment_id);
        $old_url = null;

        if (!empty($manifest['converted_from_png'])) {
            // PNG was converted to JPEG, now restoring to PNG
            $old_url = preg_replace('/\.png$/i', '.jpg', $new_url);
        } elseif (!empty($manifest['converted_to_webp'])) {
            // Original format was converted to WebP, now restoring to original
            $original_ext = self::getExtensionFromMime($manifest['original_mime'] ?? '');
            if ($original_ext) {
                $old_url = preg_replace('/\.' . preg_quote($original_ext, '/') . '$/i', '.webp', $new_url);
            }
        }

        if ($old_url && $old_url !== $new_url) {
            \PicPilot\Logger::log("🔄 Replacing URLs: $old_url → $new_url");
            \PicPilot\Restore\ContentUpdater::replace_image_urls($attachment_id, $old_url, $new_url);
        }

        $pic_pilot_restoring = false;
        return true;
    }



    //Delete jpegs after png is restored
    public static function delete_restored_files(int $attachment_id): void {
        \PicPilot\Logger::log("🧼 delete_restored_files() triggered for ID $attachment_id");

        Logger::log("🧼 Running delete_restored_files for ID $attachment_id");

        $uploads = wp_upload_dir();
        $backup_dir = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/' . $attachment_id . '/';
        $manifest_file = $backup_dir . 'manifest.json';

        if (!file_exists($manifest_file)) {
            return;
        }

        $manifest = json_decode(file_get_contents($manifest_file), true);

        // Delete main restored file
        $main = $manifest['main']['original_path'] ?? '';
        if ($main) {
            $main_path = trailingslashit($uploads['basedir']) . ltrim($main, '/');
            @unlink($main_path);
        }

        // Delete restored thumbnails
        foreach ($manifest['thumbnails'] ?? [] as $thumb) {
            $thumb_path = trailingslashit($uploads['basedir']) . ltrim($thumb['original_path'] ?? '', '/');
            @unlink($thumb_path);
            Logger::log("Attempting to delete thumb: $thumb_path");
        }
    }

    /**
     * Delete the backup file and its metadata
     */
    public static function delete_backup(int $attachment_id): bool {
        $uploads = wp_upload_dir();
        $backup_dir = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/' . $attachment_id . '/';
        if (!is_dir($backup_dir)) {
            \PicPilot\Logger::log("Delete backup: No backup folder for ID $attachment_id");
            return false;
        }
        // Recursively remove all files
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backup_dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($backup_dir);
        \PicPilot\Logger::log("🗑️ Deleted backup folder for ID $attachment_id");
        return true;
    }


    /**
     * Get backup metadata from postmeta
     */
    public static function get_backup_metadata(int $attachment_id): ?array {
        $meta = get_post_meta($attachment_id, self::META_KEY, true);
        return is_array($meta) ? $meta : null;
    }

    /**
     * Save backup metadata to postmeta
     */
    public static function set_backup_metadata(int $attachment_id, array $meta): void {
        update_post_meta($attachment_id, self::META_KEY, $meta);
    }

    /**
     * Delete backup metadata from postmeta
     */
    public static function delete_backup_metadata(int $attachment_id): void {
        delete_post_meta($attachment_id, self::META_KEY);
    }

    /**
     * Get image dimensions as array [width, height]
     */
    public static function get_image_dimensions($file): array {
        $size = @getimagesize($file);
        return is_array($size) ? ['width' => $size[0], 'height' => $size[1]] : ['width' => 0, 'height' => 0];
    }

    /**
     * Get file extension from MIME type
     */
    protected static function getExtensionFromMime(string $mime): ?string {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        
        return $extensions[$mime] ?? null;
    }
}

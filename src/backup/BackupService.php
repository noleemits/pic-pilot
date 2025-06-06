<?php

namespace PicPilot\Backup;

if (! defined('ABSPATH')) exit;

use PicPilot\Settings;
use PicPilot\Logger;

class BackupService {
    const META_KEY = '_pic_pilot_backup';
    const BACKUP_DIR = '/pic-pilot-backups/';

    /**
     * Get the backup directory (creates if missing)
     */
    public static function get_backup_dir(): string {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'pic-pilot-backups/';
        if (! file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
            // Optional: add .htaccess or index.html for security
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
    public static function create_backup(int $attachment_id): bool {
        // Check if backup is enabled
        if (!Settings::is_backup_enabled()) {
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
        $manifest = [
            'main' => [
                'filename'      => 'main' . '.' . pathinfo($main_file, PATHINFO_EXTENSION),
                'original_path' => self::relative_upload_path($main_file, $uploads['basedir'])
            ],
            'thumbnails' => [],
            'backup_created' => time(),
            'original_filesize' => filesize($main_file)
        ];

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
                    Logger::log("⚠️ Cannot backup: Thumb $size_key not found for ID $attachment_id");
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

        $uploads = wp_upload_dir();
        $backup_dir = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/' . $attachment_id . '/';
        $manifest_file = $backup_dir . 'manifest.json';

        if (!file_exists($manifest_file)) {
            \PicPilot\Logger::log("Restore failed: Manifest not found for ID $attachment_id");
            return false;
        }

        $manifest = json_decode(file_get_contents($manifest_file), true);
        if (empty($manifest['main']['filename']) || empty($manifest['main']['original_path'])) {
            \PicPilot\Logger::log("Restore failed: Manifest incomplete for ID $attachment_id");
            return false;
        }

        // 1. Restore main file
        $main_backup = $backup_dir . $manifest['main']['filename'];
        $main_target = trailingslashit($uploads['basedir']) . ltrim($manifest['main']['original_path'], '/');
        if (!file_exists($main_backup) || !is_writable(dirname($main_target))) {
            \PicPilot\Logger::log("Restore failed: Main backup missing or unwritable for ID $attachment_id");
            return false;
        }
        if (!@copy($main_backup, $main_target)) {
            \PicPilot\Logger::log("Restore failed: Could not copy main backup for ID $attachment_id");
            return false;
        }

        // 2. Restore all thumbnails
        $thumb_count = 0;
        $thumb_failures = 0;
        if (!empty($manifest['thumbnails']) && is_array($manifest['thumbnails'])) {
            foreach ($manifest['thumbnails'] as $size_key => $thumb_meta) {
                $thumb_backup = $backup_dir . $thumb_meta['filename'];
                $thumb_target = trailingslashit($uploads['basedir']) . ltrim($thumb_meta['original_path'], '/');
                if (file_exists($thumb_backup) && is_writable(dirname($thumb_target))) {
                    if (!@copy($thumb_backup, $thumb_target)) {
                        $thumb_failures++;
                        \PicPilot\Logger::log("Restore failed: Could not copy thumb $size_key for ID $attachment_id");
                    } else {
                        $thumb_count++;
                    }
                } else {
                    $thumb_failures++;
                    \PicPilot\Logger::log("Restore failed: Thumb backup missing or unwritable ($size_key) for ID $attachment_id");
                }
            }
        }

        // 3. Optionally update attachment metadata (sizes, timestamps, etc)
        // In most cases, metadata does not need regeneration since the files are exact matches.
        // If you want to refresh the metadata, uncomment below:
        /*
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($attachment_id, $main_target);
    wp_update_attachment_metadata($attachment_id, $attach_data);
    */

        update_post_meta($attachment_id, '_pic_pilot_restore_version', $manifest['backup_created'] + 1);
        \PicPilot\Logger::log("✅ Restored backup for ID $attachment_id: Main file + $thumb_count thumbs. " . ($thumb_failures ? 'Failures: ' . $thumb_failures : ''));

        return true;
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
}

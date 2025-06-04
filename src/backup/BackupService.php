<?php

namespace PicPilot\Backup;

if (! defined('ABSPATH')) exit;

use PicPilot\Settings;
use PicPilot\Logger;

class BackupService {
    const META_KEY = '_pic_pilot_backup';

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
     * Create a backup for an attachment (if not already backed up)
     * Only if backup is enabled in settings
     */
    public static function create_backup(int $attachment_id): bool {
        // Settings check
        if (! Settings::is_backup_enabled()) {

            return false;
        }

        $file = get_attached_file($attachment_id);
        if (! file_exists($file)) return false;

        $meta = self::get_backup_metadata($attachment_id);
        if (! empty($meta) && file_exists(self::get_backup_dir() . $meta['backup_filename'])) {
            // Already backed up
            return true;
        }

        $backup_path = self::get_backup_file_path($attachment_id);
        $backup_filename = basename($backup_path);
        if (! @copy($file, $backup_path)) return false;

        $meta = [
            'backup_filename'      => $backup_filename,
            'backup_created'       => time(),
            'original_filename'    => basename($file),
            'original_filesize'    => filesize($file),
            'original_mime'        => get_post_mime_type($attachment_id),
            'original_dimensions'  => self::get_image_dimensions($file),
            'backup_version'       => date('YmdHis'),
        ];
        self::set_backup_metadata($attachment_id, $meta);
        return true;
    }

    /**
     * Restore the original image from backup (and regenerate thumbnails)
     */
    public static function restore_backup(int $attachment_id): bool {
        // Only restore if setting is enabled
        if (!Settings::is_backup_enabled()) {
            return false;
        }

        $meta = self::get_backup_metadata($attachment_id);
        if (empty($meta)) {
            return false;
        }

        $backup_file = self::get_backup_dir() . $meta['backup_filename'];
        if (!file_exists($backup_file)) {
            return false;
        }

        $current_file = get_attached_file($attachment_id);
        if (!$current_file || !is_writable(dirname($current_file))) {
            return false;
        }

        // Overwrite current file with backup
        if (!@copy($backup_file, $current_file)) {
            return false;
        }

        // Regenerate thumbnails/metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attachment_id, $current_file);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        return true;
    }

    /**
     * Delete the backup file and its metadata
     */
    public static function delete_backup(int $attachment_id): bool {
        $meta = self::get_backup_metadata($attachment_id);
        if (empty($meta)) {
            return false;
        }

        $backup_file = self::get_backup_dir() . $meta['backup_filename'];
        if (file_exists($backup_file)) {
            @unlink($backup_file);
        }
        self::delete_backup_metadata($attachment_id);

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

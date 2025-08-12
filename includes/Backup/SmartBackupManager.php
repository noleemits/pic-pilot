<?php

namespace PicPilot\Backup;

use PicPilot\Settings;
use PicPilot\Logger;
use PicPilot\Utils;

defined('ABSPATH') || exit;

class SmartBackupManager {
    
    const BACKUP_USER = 'user';           // User-controlled backups
    const BACKUP_CONVERSION = 'conversion'; // Auto-backup for format changes
    const BACKUP_SERVING = 'serving';       // Browser fallback originals

    /**
     * Determine if backup should be created based on operation type
     */
    public static function should_backup(string $operation_type, int $attachment_id): bool {
        switch ($operation_type) {
            // Format conversions - always backup (regardless of user setting)
            case 'convert_png_to_jpeg':
            case 'convert_to_webp':
            case 'convert_webp_to_png':
            case 'convert_jpeg_to_webp':
            case 'convert_webp_to_jpeg':
                Logger::log("🔄 Auto-backup required for format conversion: $operation_type");
                return true;

            // Compression operations - user choice
            case 'compress_jpeg':
            case 'compress_png':
            case 'optimize_image':
                $user_backup_enabled = Settings::is_enabled('enable_backup');
                Logger::log("🤔 User backup setting for compression: " . ($user_backup_enabled ? 'enabled' : 'disabled'));
                return $user_backup_enabled;

            // Browser serving preparation - conditional
            case 'browser_serving_prep':
                $serving_method = Settings::get('webp_serving_method', 'disabled');
                $needs_originals = in_array($serving_method, ['htaccess', 'picture_tag', 'javascript']);
                Logger::log("🌐 Browser serving backup needed: " . ($needs_originals ? 'yes' : 'no') . " (method: $serving_method)");
                return $needs_originals;

            default:
                Logger::log("❓ Unknown operation type for backup decision: $operation_type");
                return Settings::is_enabled('enable_backup'); // Default to user setting
        }
    }

    /**
     * Get backup type based on operation
     */
    public static function get_backup_type(string $operation_type): string {
        switch ($operation_type) {
            case 'convert_png_to_jpeg':
            case 'convert_to_webp':
            case 'convert_webp_to_png':
            case 'convert_jpeg_to_webp':
            case 'convert_webp_to_jpeg':
                return self::BACKUP_CONVERSION;

            case 'browser_serving_prep':
                return self::BACKUP_SERVING;

            case 'compress_jpeg':
            case 'compress_png':
            case 'optimize_image':
            default:
                return self::BACKUP_USER;
        }
    }

    /**
     * Create smart backup with appropriate type and expiration
     */
    public static function create_smart_backup(int $attachment_id, string $operation_type): bool {
        if (!self::should_backup($operation_type, $attachment_id)) {
            Logger::log("⏩ Skipping backup for operation: $operation_type");
            return true; // Not an error, just not needed
        }

        $backup_type = self::get_backup_type($operation_type);
        Logger::log("📦 Creating $backup_type backup for operation: $operation_type");

        // Create backup with type-specific settings
        return self::create_typed_backup($attachment_id, $backup_type);
    }

    /**
     * Create backup of specific type
     */
    private static function create_typed_backup(int $attachment_id, string $backup_type): bool {
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir']);
        
        // Type-specific backup directory
        $backup_root = trailingslashit($basedir) . 'pic-pilot-backups/' . $backup_type . '/';
        $backup_dir = $backup_root . $attachment_id . '/';

        if (!wp_mkdir_p($backup_dir)) {
            Logger::log("❌ Failed to create $backup_type backup directory: $backup_dir");
            return false;
        }

        $main_file = get_attached_file($attachment_id);
        if (!file_exists($main_file)) {
            Logger::log("❌ Main file not found for backup: $main_file");
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $mime = mime_content_type($main_file);

        // Create manifest with backup type info
        $manifest = [
            'backup_type' => $backup_type,
            'backup_created' => time(),
            'operation_context' => self::get_operation_context($backup_type),
            'expiry_days' => self::get_expiry_days($backup_type),
            'main' => [
                'filename' => 'main.' . pathinfo($main_file, PATHINFO_EXTENSION),
                'original_path' => self::relative_upload_path($main_file, $basedir),
                'original_size' => filesize($main_file),
                'original_mime' => $mime,
            ],
            'thumbnails' => [],
        ];

        // Handle format-specific metadata
        if ($mime === 'image/webp') {
            $original_format = get_post_meta($attachment_id, '_pic_pilot_original_format', true);
            if ($original_format) {
                $manifest['converted_to_webp'] = true;
                $manifest['main']['original_mime'] = $original_format;
            }
        } elseif ($mime === 'image/png') {
            $manifest['main']['has_transparency'] = !Utils::isOpaquePng($main_file);
        }

        // Copy main file
        $main_backup = $backup_dir . $manifest['main']['filename'];
        if (!@copy($main_file, $main_backup)) {
            Logger::log("❌ Failed to copy main file for $backup_type backup: $main_file");
            return false;
        }

        // Copy thumbnails
        $thumb_count = 0;
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base_dir = dirname($main_file);
            foreach ($metadata['sizes'] as $size_key => $size_data) {
                if (empty($size_data['file'])) continue;
                
                $thumb_file = $base_dir . '/' . $size_data['file'];
                $thumb_ext = pathinfo($thumb_file, PATHINFO_EXTENSION);
                $thumb_backup = $backup_dir . 'thumb-' . $size_key . '.' . $thumb_ext;

                $manifest['thumbnails'][$size_key] = [
                    'filename' => basename($thumb_backup),
                    'original_path' => self::relative_upload_path($thumb_file, $basedir),
                ];

                if (file_exists($thumb_file)) {
                    if (@copy($thumb_file, $thumb_backup)) {
                        $thumb_count++;
                    } else {
                        Logger::log("⚠️ Failed to backup thumbnail: $thumb_file");
                    }
                }
            }
        }

        // Write manifest
        $manifest_file = $backup_dir . 'manifest.json';
        file_put_contents($manifest_file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Logger::log("✅ Created $backup_type backup for ID $attachment_id: main + $thumb_count thumbnails");
        return true;
    }

    /**
     * Get operation context description
     */
    private static function get_operation_context(string $backup_type): string {
        switch ($backup_type) {
            case self::BACKUP_CONVERSION:
                return 'Format conversion backup (always created for restoration)';
            case self::BACKUP_SERVING:
                return 'Browser serving backup (originals for fallback)';
            case self::BACKUP_USER:
            default:
                return 'User-enabled backup (compression operations)';
        }
    }

    /**
     * Get expiry days for backup type
     */
    private static function get_expiry_days(string $backup_type): int {
        switch ($backup_type) {
            case self::BACKUP_CONVERSION:
            case self::BACKUP_USER:
                return 30; // 30 days like EWWW
            case self::BACKUP_SERVING:
                return 0; // No expiry (needed for serving)
            default:
                return 30;
        }
    }

    /**
     * Check if any backup exists for attachment
     */
    public static function has_any_backup(int $attachment_id): bool {
        $backup_types = [self::BACKUP_USER, self::BACKUP_CONVERSION, self::BACKUP_SERVING];
        
        foreach ($backup_types as $type) {
            if (self::has_backup_of_type($attachment_id, $type)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if backup of specific type exists
     */
    public static function has_backup_of_type(int $attachment_id, string $backup_type): bool {
        $uploads = wp_upload_dir();
        $backup_dir = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/' . $backup_type . '/' . $attachment_id . '/';
        $manifest_file = $backup_dir . 'manifest.json';
        
        return file_exists($manifest_file);
    }

    /**
     * Get backup info for attachment
     */
    public static function get_backup_info(int $attachment_id): array {
        $info = [];
        $backup_types = [self::BACKUP_USER, self::BACKUP_CONVERSION, self::BACKUP_SERVING];
        
        foreach ($backup_types as $type) {
            if (self::has_backup_of_type($attachment_id, $type)) {
                $info[$type] = self::get_backup_manifest($attachment_id, $type);
            }
        }
        
        return $info;
    }

    /**
     * Get backup manifest for specific type
     */
    private static function get_backup_manifest(int $attachment_id, string $backup_type): ?array {
        $uploads = wp_upload_dir();
        $backup_dir = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/' . $backup_type . '/' . $attachment_id . '/';
        $manifest_file = $backup_dir . 'manifest.json';
        
        if (!file_exists($manifest_file)) {
            return null;
        }
        
        $manifest = json_decode(file_get_contents($manifest_file), true);
        if (is_array($manifest)) {
            // Add the backup directory path to the manifest for restoration handlers
            $manifest['backup_dir'] = $backup_dir;
            return $manifest;
        }
        return null;
    }

    /**
     * Helper: Get relative upload path
     */
    private static function relative_upload_path(string $file, string $basedir): string {
        $rel = ltrim(str_replace($basedir, '', wp_normalize_path($file)), '/\\');
        return str_replace('\\', '/', $rel);
    }

    /**
     * Clean expired backups
     */
    public static function clean_expired_backups(): int {
        $cleaned = 0;
        $backup_types = [self::BACKUP_USER, self::BACKUP_CONVERSION]; // Don't auto-clean serving backups
        
        foreach ($backup_types as $type) {
            $cleaned += self::clean_expired_backups_of_type($type);
        }
        
        Logger::log("🧹 Cleaned $cleaned expired backup directories");
        return $cleaned;
    }

    /**
     * Clean expired backups of specific type
     */
    private static function clean_expired_backups_of_type(string $backup_type): int {
        $uploads = wp_upload_dir();
        $type_dir = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/' . $backup_type . '/';
        
        if (!is_dir($type_dir)) {
            return 0;
        }
        
        $cleaned = 0;
        $expiry_seconds = 30 * 24 * 60 * 60; // 30 days
        $now = time();
        
        $directories = glob($type_dir . '*', GLOB_ONLYDIR);
        foreach ($directories as $backup_dir) {
            $manifest_file = $backup_dir . '/manifest.json';
            if (file_exists($manifest_file)) {
                $manifest = json_decode(file_get_contents($manifest_file), true);
                if (is_array($manifest) && isset($manifest['backup_created'])) {
                    $age = $now - $manifest['backup_created'];
                    if ($age > $expiry_seconds) {
                        self::delete_backup_directory($backup_dir);
                        $cleaned++;
                    }
                }
            }
        }
        
        return $cleaned;
    }

    /**
     * Delete backup directory recursively
     */
    private static function delete_backup_directory(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        
        return rmdir($dir);
    }

    /**
     * AJAX handler: Clean expired backups
     */
    public static function handle_clean_expired(): void {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'pic-pilot'));
        }

        // Verify nonce for security
        $nonce = $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'pic_pilot_backup_cleanup')) {
            wp_die(__('Invalid nonce', 'pic-pilot'));
        }

        $cleaned = self::clean_expired_backups();
        
        wp_send_json_success([
            'message' => sprintf(
                __('Cleaned %d expired backup directories', 'pic-pilot'),
                $cleaned
            ),
            'cleaned_count' => $cleaned
        ]);
    }

    /**
     * AJAX handler: Clean all backups (dangerous!)
     */
    public static function handle_clean_all(): void {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'pic-pilot'));
        }

        // Verify nonce for security
        $nonce = $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'pic_pilot_backup_cleanup')) {
            wp_die(__('Invalid nonce', 'pic-pilot'));
        }

        // Require explicit confirmation
        $confirm = $_POST['confirm'] ?? '';
        if ($confirm !== 'DELETE_ALL_BACKUPS') {
            wp_send_json_error([
                'message' => __('Confirmation text required', 'pic-pilot')
            ]);
        }

        $cleaned = self::clean_all_backups();
        
        wp_send_json_success([
            'message' => sprintf(
                __('Deleted all %d backup directories (IRREVERSIBLE!)', 'pic-pilot'),
                $cleaned
            ),
            'cleaned_count' => $cleaned
        ]);
    }

    /**
     * Clean ALL backups (dangerous operation)
     */
    public static function clean_all_backups(): int {
        $uploads = wp_upload_dir();
        $backup_base = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
        
        if (!is_dir($backup_base)) {
            return 0;
        }

        $cleaned = 0;
        $backup_types = [self::BACKUP_USER, self::BACKUP_CONVERSION, self::BACKUP_SERVING];
        
        foreach ($backup_types as $type) {
            $type_dir = $backup_base . $type . '/';
            if (is_dir($type_dir)) {
                $directories = glob($type_dir . '*', GLOB_ONLYDIR);
                foreach ($directories as $backup_dir) {
                    if (self::delete_backup_directory($backup_dir)) {
                        $cleaned++;
                    }
                }
            }
        }

        Logger::log("🧹 DANGEROUS: Cleaned ALL $cleaned backup directories");
        return $cleaned;
    }

    /**
     * Delete all backups for a specific attachment
     */
    public static function delete_all_backups(int $attachment_id): bool {
        $uploads = wp_upload_dir();
        $backup_base = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
        
        $deleted_count = 0;
        $backup_types = [self::BACKUP_USER, self::BACKUP_CONVERSION, self::BACKUP_SERVING];
        
        foreach ($backup_types as $type) {
            $backup_dir = $backup_base . $type . '/' . $attachment_id . '/';
            if (is_dir($backup_dir)) {
                if (self::delete_backup_directory($backup_dir)) {
                    $deleted_count++;
                    Logger::log("🗑️ Deleted $type backup folder for ID $attachment_id");
                }
            }
        }
        
        if ($deleted_count > 0) {
            Logger::log("🧹 Deleted backup folder for ID $attachment_id");
            return true;
        }
        
        return false;
    }

    /**
     * Get backup storage statistics
     */
    public static function get_backup_stats(): array {
        $uploads = wp_upload_dir();
        $backup_base = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
        
        $stats = [
            'total_directories' => 0,
            'total_size_bytes' => 0,
            'by_type' => [],
            'expired_count' => 0,
        ];

        if (!is_dir($backup_base)) {
            return $stats;
        }

        $backup_types = [self::BACKUP_USER, self::BACKUP_CONVERSION, self::BACKUP_SERVING];
        $expiry_seconds = 30 * 24 * 60 * 60; // 30 days
        $now = time();

        foreach ($backup_types as $type) {
            $type_dir = $backup_base . $type . '/';
            $type_stats = [
                'directories' => 0,
                'size_bytes' => 0,
                'expired' => 0,
            ];

            if (is_dir($type_dir)) {
                $directories = glob($type_dir . '*', GLOB_ONLYDIR);
                foreach ($directories as $backup_dir) {
                    $type_stats['directories']++;
                    $stats['total_directories']++;
                    
                    // Calculate directory size
                    $dir_size = self::get_directory_size($backup_dir);
                    $type_stats['size_bytes'] += $dir_size;
                    $stats['total_size_bytes'] += $dir_size;

                    // Check if expired (for user and conversion backups)
                    if (in_array($type, [self::BACKUP_USER, self::BACKUP_CONVERSION])) {
                        $manifest_file = $backup_dir . '/manifest.json';
                        if (file_exists($manifest_file)) {
                            $manifest = json_decode(file_get_contents($manifest_file), true);
                            if (is_array($manifest) && isset($manifest['backup_created'])) {
                                $age = $now - $manifest['backup_created'];
                                if ($age > $expiry_seconds) {
                                    $type_stats['expired']++;
                                    $stats['expired_count']++;
                                }
                            }
                        }
                    }
                }
            }

            $stats['by_type'][$type] = $type_stats;
        }

        return $stats;
    }

    /**
     * Get directory size recursively
     */
    private static function get_directory_size(string $dir): int {
        $size = 0;
        
        if (!is_dir($dir)) {
            return 0;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
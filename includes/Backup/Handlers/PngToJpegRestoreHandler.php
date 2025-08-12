<?php

namespace PicPilot\Backup;

use PicPilot\Logger;
use PicPilot\Utils;

defined('ABSPATH') || exit;

/**
 * Handles restoration of PNG files that were converted to JPEG
 */
class PngToJpegRestoreHandler implements RestorationHandler {
    
    public function canHandle(string $operation_type): bool {
        return $operation_type === 'restore_png_from_jpeg';
    }
    
    public function getRestoreSteps(int $attachment_id, array $backup_info): array {
        return [
            'Restore original PNG file as main attachment',
            'Delete converted JPEG file and thumbnails',
            'Update WordPress attachment metadata (MIME, file path)',
            'Replace all JPEG URLs with PNG URLs across site content',
            'Regenerate PNG thumbnails',
            'Clean up optimization metadata',
        ];
    }
    
    public function execute(int $attachment_id, array $backup_info): RestoreResult {
        Logger::log("🔄 Executing PNG→JPEG restoration for attachment ID $attachment_id");
        
        // Verify we have a conversion backup
        if (!isset($backup_info['conversion'])) {
            return new RestoreResult(false, "No conversion backup found");
        }
        
        $manifest = $backup_info['conversion'];
        $backup_dir = $manifest['backup_dir'] ?? '';
        
        if (!$backup_dir || !is_dir($backup_dir)) {
            return new RestoreResult(false, "Backup directory not found");
        }
        
        // Verify this is a PNG→JPEG conversion
        $original_mime = $manifest['main']['original_mime'] ?? '';
        if ($original_mime !== 'image/png') {
            return new RestoreResult(false, "This is not a PNG→JPEG conversion backup");
        }
        
        // Get current file info for URL replacement
        $current_file = get_attached_file($attachment_id);
        $current_url = wp_get_attachment_url($attachment_id);
        
        if (!$current_file || !file_exists($current_file)) {
            return new RestoreResult(false, "Current attachment file not found");
        }
        
        // Step 1: Restore PNG file
        $restore_result = $this->restore_png_file($attachment_id, $manifest, $backup_dir);
        if (!$restore_result['success']) {
            return new RestoreResult(false, $restore_result['error']);
        }
        
        $restored_file = $restore_result['restored_file'];
        $new_url = wp_get_attachment_url($attachment_id);
        
        // Step 2: Delete old JPEG files
        $this->cleanup_jpeg_files($current_file, $attachment_id);
        
        // Step 3: Update WordPress metadata
        $this->update_attachment_metadata($attachment_id, $restored_file, $manifest);
        
        // Step 4: Replace URLs across content
        if ($current_url !== $new_url) {
            Logger::log("🔄 Replacing URLs: $current_url → $new_url");
            ContentReplacer::replace_image_urls($current_url, $new_url, $attachment_id);
        }
        
        // Step 5: Clean up optimization metadata
        Utils::clear_optimization_metadata($attachment_id);
        
        Logger::log("✅ PNG→JPEG restoration completed for attachment ID $attachment_id");
        
        return new RestoreResult(true, '', [
            'old_url' => $current_url,
            'new_url' => $new_url,
            'restored_file' => $restored_file,
        ]);
    }
    
    /**
     * Restore the PNG file from backup
     */
    private function restore_png_file(int $attachment_id, array $manifest, string $backup_dir): array {
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir']);
        
        // Get backup file
        $main_backup = $backup_dir . ($manifest['main']['filename'] ?? '');
        if (!file_exists($main_backup)) {
            return ['success' => false, 'error' => 'Backup file not found'];
        }
        
        // Determine restore path
        $original_path = $manifest['main']['original_path'] ?? '';
        if (!$original_path) {
            return ['success' => false, 'error' => 'Original path not found in manifest'];
        }
        
        $restore_path = path_join($basedir, wp_normalize_path($original_path));
        $restore_dir = dirname($restore_path);
        
        // Ensure directory exists and is writable
        if (!wp_mkdir_p($restore_dir)) {
            return ['success' => false, 'error' => 'Cannot create restore directory'];
        }
        
        if (!is_writable($restore_dir)) {
            return ['success' => false, 'error' => 'Restore directory not writable'];
        }
        
        // Copy main file
        if (!@copy($main_backup, $restore_path)) {
            return ['success' => false, 'error' => 'Failed to copy main backup file'];
        }
        
        Logger::log("✅ Restored PNG file to: $restore_path");
        
        // Update WordPress file reference
        $relative_path = ltrim(wp_normalize_path($original_path), '/');
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
        
        // Restore thumbnails
        $this->restore_thumbnails($attachment_id, $manifest, $backup_dir, $basedir);
        
        return [
            'success' => true,
            'restored_file' => $restore_path,
        ];
    }
    
    /**
     * Restore thumbnail files
     */
    private function restore_thumbnails(int $attachment_id, array $manifest, string $backup_dir, string $basedir): void {
        if (empty($manifest['thumbnails']) || !is_array($manifest['thumbnails'])) {
            return;
        }
        
        $restored_count = 0;
        $failed_count = 0;
        
        foreach ($manifest['thumbnails'] as $size_key => $thumb_meta) {
            $thumb_backup = $backup_dir . $thumb_meta['filename'];
            $thumb_target = path_join($basedir, wp_normalize_path($thumb_meta['original_path']));
            
            if (file_exists($thumb_backup) && is_writable(dirname($thumb_target))) {
                if (@copy($thumb_backup, $thumb_target)) {
                    $restored_count++;
                } else {
                    $failed_count++;
                    Logger::log("⚠️ Failed to restore thumbnail: $size_key");
                }
            } else {
                $failed_count++;
                Logger::log("⚠️ Thumbnail backup missing or target not writable: $size_key");
            }
        }
        
        Logger::log("📄 Restored $restored_count thumbnails" . ($failed_count ? ", $failed_count failed" : ""));
    }
    
    /**
     * Clean up JPEG files that are being replaced
     */
    private function cleanup_jpeg_files(string $current_file, int $attachment_id): void {
        // Delete main JPEG file
        if (file_exists($current_file)) {
            @unlink($current_file);
            Logger::log("🗑️ Deleted JPEG file: $current_file");
        }
        
        // Delete JPEG thumbnails
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base_dir = dirname($current_file);
            foreach ($metadata['sizes'] as $size_key => $size_data) {
                if (empty($size_data['file'])) continue;
                
                $thumb_file = $base_dir . '/' . $size_data['file'];
                if (file_exists($thumb_file)) {
                    @unlink($thumb_file);
                    Logger::log("🗑️ Deleted JPEG thumbnail: $thumb_file");
                }
            }
        }
    }
    
    /**
     * Update WordPress attachment metadata
     */
    private function update_attachment_metadata(int $attachment_id, string $restored_file, array $manifest): void {
        // Update post data
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => 'image/png',
            'guid' => wp_get_attachment_url($attachment_id),
        ]);
        
        // Clear and regenerate metadata
        clean_post_cache($attachment_id);
        wp_cache_delete($attachment_id, 'post_meta');
        
        // Set restore flag to prevent upload processor from re-converting
        global $pic_pilot_restoring;
        $pic_pilot_restoring = true;
        
        // Regenerate attachment metadata and thumbnails
        $metadata = wp_generate_attachment_metadata($attachment_id, $restored_file);
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        // Clear restore flag
        $pic_pilot_restoring = false;
        
        // Update restore tracking
        update_post_meta($attachment_id, '_pic_pilot_restore_version', time());
        
        Logger::log("📝 Updated attachment metadata for PNG file");
    }
}
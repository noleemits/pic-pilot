<?php

namespace PicPilot\Backup;

use PicPilot\Logger;
use PicPilot\Utils;

defined('ABSPATH') || exit;

/**
 * Handles restoration of original files that were converted to WebP
 */
class WebpConversionRestoreHandler implements RestorationHandler {
    
    public function canHandle(string $operation_type): bool {
        return $operation_type === 'restore_original_from_webp';
    }
    
    public function getRestoreSteps(int $attachment_id, array $backup_info): array {
        return [
            'Restore original format file (PNG/JPEG) as main attachment',
            'Delete converted WebP file and thumbnails',
            'Update WordPress attachment metadata (MIME, file path)',
            'Replace all WebP URLs with original format URLs across site content',
            'Regenerate thumbnails in original format',
            'Clean up optimization metadata',
        ];
    }
    
    public function execute(int $attachment_id, array $backup_info): RestoreResult {
        Logger::log("🔄 Executing WebP→Original restoration for attachment ID $attachment_id");
        
        // Verify we have a conversion backup
        if (!isset($backup_info['conversion'])) {
            return new RestoreResult(false, "No conversion backup found");
        }
        
        $manifest = $backup_info['conversion'];
        $backup_dir = $manifest['backup_dir'] ?? '';
        
        if (!$backup_dir || !is_dir($backup_dir)) {
            return new RestoreResult(false, "Backup directory not found");
        }
        
        // Verify this is a WebP conversion
        $original_mime = $manifest['main']['original_mime'] ?? '';
        if (!in_array($original_mime, ['image/png', 'image/jpeg'])) {
            return new RestoreResult(false, "This is not a WebP conversion backup");
        }
        
        // Get current file info for URL replacement
        $current_file = get_attached_file($attachment_id);
        $current_url = wp_get_attachment_url($attachment_id);
        
        if (!$current_file || !file_exists($current_file)) {
            return new RestoreResult(false, "Current attachment file not found");
        }
        
        // Step 1: Restore original file
        $restore_result = $this->restore_original_file($attachment_id, $manifest, $backup_dir);
        if (!$restore_result['success']) {
            return new RestoreResult(false, $restore_result['error']);
        }
        
        $restored_file = $restore_result['restored_file'];
        $new_url = wp_get_attachment_url($attachment_id);
        
        // Step 2: Delete old WebP files
        $this->cleanup_webp_files($current_file, $attachment_id);
        
        // Step 3: Update WordPress metadata
        $this->update_attachment_metadata($attachment_id, $restored_file, $manifest);
        
        // Step 4: Replace URLs across content
        if ($current_url !== $new_url) {
            Logger::log("🔄 Replacing URLs: $current_url → $new_url");
            ContentReplacer::replace_image_urls($current_url, $new_url, $attachment_id);
        }
        
        // Step 5: Clean up optimization metadata
        Utils::clear_optimization_metadata($attachment_id);
        
        Logger::log("✅ WebP→Original restoration completed for attachment ID $attachment_id");
        
        return new RestoreResult(true, '', [
            'old_url' => $current_url,
            'new_url' => $new_url,
            'restored_file' => $restored_file,
            'original_format' => $original_mime,
        ]);
    }
    
    /**
     * Restore the original file from backup
     */
    private function restore_original_file(int $attachment_id, array $manifest, string $backup_dir): array {
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
        
        // Normalize path: remove -resized suffix to avoid naming conflicts
        $normalized_path = $this->normalize_restore_path($original_path);
        $restore_path = path_join($basedir, wp_normalize_path($normalized_path));
        
        Logger::log("🔄 Restoring: $original_path → $normalized_path");
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
        
        Logger::log("✅ Restored original file to: $restore_path");
        
        // Update WordPress file reference with normalized path
        $relative_path = ltrim(wp_normalize_path($normalized_path), '/');
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
        
        // Restore thumbnails with normalized paths
        $this->restore_thumbnails($attachment_id, $manifest, $backup_dir, $basedir, $normalized_path);
        
        return [
            'success' => true,
            'restored_file' => $restore_path,
        ];
    }
    
    /**
     * Restore thumbnail files
     */
    private function restore_thumbnails(int $attachment_id, array $manifest, string $backup_dir, string $basedir, string $normalized_main_path = null): void {
        if (empty($manifest['thumbnails']) || !is_array($manifest['thumbnails'])) {
            return;
        }
        
        $restored_count = 0;
        $failed_count = 0;
        
        // Get normalized base filename for thumbnails
        $normalized_base = null;
        if ($normalized_main_path) {
            $main_info = pathinfo($normalized_main_path);
            $normalized_base = $main_info['filename'];
        }
        
        foreach ($manifest['thumbnails'] as $size_key => $thumb_meta) {
            $thumb_backup = $backup_dir . $thumb_meta['filename'];
            $original_thumb_path = $thumb_meta['original_path'];
            
            // Normalize thumbnail path to match main file
            if ($normalized_base) {
                $thumb_target_path = $this->normalize_thumbnail_path($original_thumb_path, $normalized_base);
                Logger::log("🔧 Thumbnail normalized: $original_thumb_path → $thumb_target_path");
            } else {
                $thumb_target_path = $original_thumb_path;
            }
            
            $thumb_target = path_join($basedir, wp_normalize_path($thumb_target_path));
            
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
     * Clean up WebP files that are being replaced
     */
    private function cleanup_webp_files(string $current_file, int $attachment_id): void {
        // Delete main WebP file
        if (file_exists($current_file)) {
            @unlink($current_file);
            Logger::log("🗑️ Deleted WebP file: $current_file");
        }
        
        // Delete WebP thumbnails
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base_dir = dirname($current_file);
            foreach ($metadata['sizes'] as $size_key => $size_data) {
                if (empty($size_data['file'])) continue;
                
                $thumb_file = $base_dir . '/' . $size_data['file'];
                if (file_exists($thumb_file)) {
                    @unlink($thumb_file);
                    Logger::log("🗑️ Deleted WebP thumbnail: $thumb_file");
                }
            }
        }
    }
    
    /**
     * Update WordPress attachment metadata
     */
    private function update_attachment_metadata(int $attachment_id, string $restored_file, array $manifest): void {
        $original_mime = $manifest['main']['original_mime'] ?? 'image/jpeg';
        
        // Update post data
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $original_mime,
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
        
        // Clean up WebP-specific metadata
        delete_post_meta($attachment_id, '_pic_pilot_original_format');
        delete_post_meta($attachment_id, '_pic_pilot_webp_conversion');
        
        Logger::log("📝 Updated attachment metadata for original format: $original_mime");
    }
    
    /**
     * Normalize restore path to remove -resized suffix and avoid naming conflicts
     */
    private function normalize_restore_path(string $original_path): string {
        // Remove -resized suffix from filename if present
        $path_info = pathinfo($original_path);
        $filename = $path_info['filename'];
        
        if (strpos($filename, '-resized') !== false) {
            $filename = str_replace('-resized', '', $filename);
            Logger::log("🔧 Normalizing filename: removed '-resized' suffix");
        }
        
        return $path_info['dirname'] . '/' . $filename . '.' . $path_info['extension'];
    }
    
    /**
     * Normalize thumbnail path to match the normalized main filename
     */
    private function normalize_thumbnail_path(string $thumbnail_path, string $normalized_base_name): string {
        $path_info = pathinfo($thumbnail_path);
        $thumb_filename = $path_info['filename'];
        
        // Extract size suffix (e.g., "768x512" from "2150225267-768x512")
        if (preg_match('/^(.+?)(-\d+x\d+)$/', $thumb_filename, $matches)) {
            $old_base = $matches[1];
            $size_suffix = $matches[2];
            
            // Replace old base with normalized base
            $new_thumb_filename = $normalized_base_name . $size_suffix;
            
            return $path_info['dirname'] . '/' . $new_thumb_filename . '.' . $path_info['extension'];
        }
        
        // Fallback: couldn't parse size suffix, return original
        return $thumbnail_path;
    }
}
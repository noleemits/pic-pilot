<?php

namespace PicPilot\Backup;

use PicPilot\Logger;
use PicPilot\Utils;

defined('ABSPATH') || exit;

/**
 * Handles restoration of compressed images (same format)
 */
class CompressionRestoreHandler implements RestorationHandler {
    
    public function canHandle(string $operation_type): bool {
        return in_array($operation_type, ['restore_uncompressed', 'restore_generic']);
    }
    
    public function getRestoreSteps(int $attachment_id, array $backup_info): array {
        return [
            'Restore original uncompressed file (same format)',
            'Replace compressed version', 
            'Regenerate uncompressed thumbnails',
            'Clean up optimization metadata',
            'URLs remain the same (same filename/format)',
        ];
    }
    
    public function execute(int $attachment_id, array $backup_info): RestoreResult {
        Logger::log("🔄 Executing compression restoration for attachment ID $attachment_id");
        
        // Find appropriate backup (user or legacy)
        $manifest = null;
        $backup_dir = null;
        
        if (isset($backup_info['user'])) {
            $manifest = $backup_info['user'];
        } elseif (isset($backup_info['legacy'])) {
            $manifest = $backup_info['legacy']; 
        } else {
            return new RestoreResult(false, "No user or legacy backup found");
        }
        
        $backup_dir = $manifest['backup_dir'] ?? '';
        if (!$backup_dir || !is_dir($backup_dir)) {
            return new RestoreResult(false, "Backup directory not found");
        }
        
        // Get current file info
        $current_file = get_attached_file($attachment_id);
        if (!$current_file || !file_exists($current_file)) {
            return new RestoreResult(false, "Current attachment file not found");
        }
        
        // Step 1: Restore uncompressed file
        $restore_result = $this->restore_uncompressed_file($attachment_id, $manifest, $backup_dir);
        if (!$restore_result['success']) {
            return new RestoreResult(false, $restore_result['error']);
        }
        
        $restored_file = $restore_result['restored_file'];
        
        // Step 2: Update WordPress metadata (regenerate thumbnails)
        $this->update_attachment_metadata($attachment_id, $restored_file);
        
        // Step 3: Clean up optimization metadata
        Utils::clear_optimization_metadata($attachment_id);
        
        Logger::log("✅ Compression restoration completed for attachment ID $attachment_id");
        
        return new RestoreResult(true, '', [
            'restored_file' => $restored_file,
            'same_format' => true,
        ]);
    }
    
    /**
     * Restore the uncompressed file from backup
     */
    private function restore_uncompressed_file(int $attachment_id, array $manifest, string $backup_dir): array {
        // Get backup file
        $main_backup = $backup_dir . ($manifest['main']['filename'] ?? '');
        if (!file_exists($main_backup)) {
            return ['success' => false, 'error' => 'Backup file not found'];
        }
        
        // Get current file location (restore to same path)
        $current_file = get_attached_file($attachment_id);
        if (!is_writable(dirname($current_file))) {
            return ['success' => false, 'error' => 'Target directory not writable'];
        }
        
        // Copy main file (overwrite compressed version)
        if (!@copy($main_backup, $current_file)) {
            return ['success' => false, 'error' => 'Failed to copy main backup file'];
        }
        
        Logger::log("✅ Restored uncompressed file to: $current_file");
        
        // Restore thumbnails if available
        $this->restore_thumbnails($attachment_id, $manifest, $backup_dir);
        
        return [
            'success' => true,
            'restored_file' => $current_file,
        ];
    }
    
    /**
     * Restore thumbnail files
     */
    private function restore_thumbnails(int $attachment_id, array $manifest, string $backup_dir): void {
        if (empty($manifest['thumbnails']) || !is_array($manifest['thumbnails'])) {
            return;
        }
        
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir']);
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
     * Update WordPress attachment metadata
     */
    private function update_attachment_metadata(int $attachment_id, string $restored_file): void {
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
        
        Logger::log("📝 Updated attachment metadata for uncompressed file");
    }
}
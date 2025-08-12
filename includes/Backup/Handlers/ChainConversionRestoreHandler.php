<?php

namespace PicPilot\Backup;

use PicPilot\Logger;
use PicPilot\Utils;

defined('ABSPATH') || exit;

/**
 * Handles restoration of complex conversion chains (PNG→JPEG→WebP)
 * Always restores to the earliest backup (original format)
 */
class ChainConversionRestoreHandler implements RestorationHandler {
    
    public function canHandle(string $operation_type): bool {
        return $operation_type === 'restore_conversion_chain';
    }
    
    public function getRestoreSteps(int $attachment_id, array $backup_info): array {
        return [
            'Analyze conversion chain to find original format',
            'Restore to earliest backup (original PNG/JPEG)',
            'Delete all intermediate files (JPEG, WebP)',
            'Update WordPress attachment metadata to original format',
            'Replace final URLs with original format URLs across site content',
            'Clean up all conversion chain metadata',
        ];
    }
    
    public function execute(int $attachment_id, array $backup_info): RestoreResult {
        Logger::log("🔄 Executing conversion chain restoration for attachment ID $attachment_id");
        
        // Analyze the conversion chain
        $chain_analysis = $this->analyze_conversion_chain($backup_info);
        if (!$chain_analysis['success']) {
            return new RestoreResult(false, $chain_analysis['error']);
        }
        
        $original_backup = $chain_analysis['original_backup'];
        $current_url = wp_get_attachment_url($attachment_id);
        
        // Determine which handler to use for the final restoration
        $original_mime = $original_backup['main']['original_mime'] ?? '';
        $current_file = get_attached_file($attachment_id);
        $current_mime = wp_check_filetype($current_file)['type'] ?? '';
        
        // Delegate to appropriate single-step handler
        if ($original_mime === 'image/png' && $current_mime === 'image/jpeg') {
            $handler = new PngToJpegRestoreHandler();
        } elseif (in_array($original_mime, ['image/png', 'image/jpeg']) && $current_mime === 'image/webp') {
            $handler = new WebpConversionRestoreHandler();
        } else {
            return new RestoreResult(false, "Unsupported conversion chain type");
        }
        
        // Execute the restoration using the appropriate handler
        $result = $handler->execute($attachment_id, ['conversion' => $original_backup]);
        
        if ($result->success) {
            // Clean up chain-specific metadata
            $this->cleanup_chain_metadata($attachment_id);
            
            $new_url = wp_get_attachment_url($attachment_id);
            Logger::log("✅ Conversion chain restoration completed: $current_url → $new_url");
            
            // Update result data
            $result->data['chain_restored'] = true;
            $result->data['original_format'] = $original_mime;
            $result->data['steps_skipped'] = $chain_analysis['intermediate_steps'];
        }
        
        return $result;
    }
    
    /**
     * Analyze the conversion chain to find the original backup
     */
    private function analyze_conversion_chain(array $backup_info): array {
        // Look for conversion backup first
        if (!isset($backup_info['conversion'])) {
            return [
                'success' => false,
                'error' => 'No conversion backup found for chain analysis',
            ];
        }
        
        $manifest = $backup_info['conversion'];
        
        // Check if this manifest has chain information
        if (isset($manifest['conversion_chain'])) {
            // This is a proper chain with recorded steps
            $chain = $manifest['conversion_chain'];
            $original_backup = $chain['original'] ?? null;
            $steps = $chain['steps'] ?? [];
            
            if (!$original_backup) {
                return [
                    'success' => false, 
                    'error' => 'No original backup found in conversion chain',
                ];
            }
            
            return [
                'success' => true,
                'original_backup' => $original_backup,
                'intermediate_steps' => count($steps),
                'chain_data' => $chain,
            ];
        } else {
            // This is a single conversion, treat as chain of 1
            return [
                'success' => true,
                'original_backup' => $manifest,
                'intermediate_steps' => 1,
                'chain_data' => null,
            ];
        }
    }
    
    /**
     * Clean up conversion chain metadata
     */
    private function cleanup_chain_metadata(int $attachment_id): void {
        $meta_keys_to_clean = [
            '_pic_pilot_conversion_chain',
            '_pic_pilot_conversion_steps', 
            '_pic_pilot_original_format',
            '_pic_pilot_webp_conversion',
            '_pic_pilot_jpeg_conversion',
            '_pic_pilot_png_conversion',
        ];
        
        foreach ($meta_keys_to_clean as $key) {
            delete_post_meta($attachment_id, $key);
        }
        
        Logger::log("🧹 Cleaned up conversion chain metadata");
    }
}
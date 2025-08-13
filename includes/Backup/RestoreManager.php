<?php

namespace PicPilot\Backup;

use PicPilot\Logger;
use PicPilot\Settings;

defined('ABSPATH') || exit;

/**
 * Central restoration coordinator for all backup types
 * Handles restoration with complete URL replacement across content
 */
class RestoreManager {
    
    /** @var RestorationHandler[] */
    private static $handlers = [];
    
    /**
     * Register a restoration handler
     */
    public static function register_handler(RestorationHandler $handler): void {
        self::$handlers[] = $handler;
    }
    
    /**
     * Initialize default handlers
     */
    public static function init(): void {
        // Register built-in handlers
        self::register_handler(new PngToJpegRestoreHandler());
        self::register_handler(new WebpConversionRestoreHandler());
        self::register_handler(new CompressionRestoreHandler());
        self::register_handler(new ChainConversionRestoreHandler());
        
        // RestoreManager initialized with " . count(self::$handlers) . " handlers
    }
    
    /**
     * Restore attachment from backup with full content replacement
     */
    public static function restore_attachment(int $attachment_id, string $backup_type = null): RestoreResult {
        Logger::log("🔄 Starting restoration for attachment ID $attachment_id (type: " . ($backup_type ?? 'auto-detect') . ")");
        
        // Get backup info for this attachment
        $backup_info = SmartBackupManager::get_backup_info($attachment_id);
        Logger::log_compact("🔍 RESTORE DEBUG: Backup info for ID $attachment_id:", $backup_info);
        
        if (empty($backup_info)) {
            Logger::log("❌ No backups found for attachment ID $attachment_id");
            
            // Also check legacy BackupService
            $legacy_exists = \PicPilot\Backup\BackupService::backup_exists($attachment_id);
            Logger::log("🔍 RESTORE DEBUG: Legacy backup exists: " . ($legacy_exists ? 'YES' : 'NO'));
            
            return new RestoreResult(false, "No backups found for this attachment");
        }
        
        // Determine what type of restoration to perform
        $operation_type = self::detect_operation_type($attachment_id, $backup_info, $backup_type);
        if (!$operation_type) {
            Logger::log("❌ Could not determine restoration operation type for attachment ID $attachment_id");
            return new RestoreResult(false, "Could not determine how to restore this attachment");
        }
        
        Logger::log("🎯 Detected operation type: $operation_type");
        
        // Find appropriate handler
        $handler = self::find_handler($operation_type);
        if (!$handler) {
            Logger::log("❌ No handler found for operation type: $operation_type");  
            return new RestoreResult(false, "No restoration handler available for this operation");
        }
        
        Logger::log("🔧 Using handler: " . get_class($handler));
        
        // Execute restoration
        try {
            $result = $handler->execute($attachment_id, $backup_info);
            
            if ($result->success) {
                Logger::log("✅ Restoration completed successfully for attachment ID $attachment_id");
                
                // Update restoration metadata
                update_post_meta($attachment_id, '_pic_pilot_last_restore', [
                    'timestamp' => time(),
                    'operation_type' => $operation_type,
                    'handler' => get_class($handler),
                ]);
            } else {
                Logger::log("❌ Restoration failed for attachment ID $attachment_id: " . $result->error);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Logger::log("❌ Exception during restoration for attachment ID $attachment_id: " . $e->getMessage());
            return new RestoreResult(false, "Restoration failed: " . $e->getMessage());
        }
    }
    
    /**
     * Detect what type of restoration operation is needed
     */
    private static function detect_operation_type(int $attachment_id, array $backup_info, string $backup_type = null): ?string {
        // If specific backup type requested, use that
        if ($backup_type && isset($backup_info[$backup_type])) {
            $manifest = $backup_info[$backup_type];
            return self::get_operation_from_manifest($manifest);
        }
        
        // Auto-detect based on available backups and current file state
        $current_file = get_attached_file($attachment_id);
        $current_mime = wp_check_filetype($current_file)['type'] ?? '';
        
        // Check for conversion backups first (highest priority)
        if (isset($backup_info['conversion'])) {
            $manifest = $backup_info['conversion'];
            $original_mime = $manifest['main']['original_mime'] ?? '';
            
            // Determine conversion type
            if ($original_mime === 'image/png' && $current_mime === 'image/jpeg') {
                return 'restore_png_from_jpeg';
            } elseif (in_array($original_mime, ['image/png', 'image/jpeg']) && $current_mime === 'image/webp') {
                return 'restore_original_from_webp';
            } elseif ($manifest['converted_to_webp'] ?? false) {
                return 'restore_original_from_webp';
            } elseif ($manifest['converted_from_png'] ?? false) {
                return 'restore_png_from_jpeg';
            }
        }
        
        // Check for user backups (compression)
        if (isset($backup_info['user'])) {
            return 'restore_uncompressed';
        }
        
        // Check for serving backups
        if (isset($backup_info['serving'])) {
            return 'restore_serving_original';
        }
        
        return null;
    }
    
    /**
     * Get operation type from manifest data
     */
    private static function get_operation_from_manifest(array $manifest): ?string {
        if ($manifest['converted_from_png'] ?? false) {
            return 'restore_png_from_jpeg';
        }
        
        if ($manifest['converted_to_webp'] ?? false) {
            return 'restore_original_from_webp';
        }
        
        if (($manifest['backup_type'] ?? '') === 'user') {
            return 'restore_uncompressed';
        }
        
        if (($manifest['backup_type'] ?? '') === 'serving') {
            return 'restore_serving_original';
        }
        
        return 'restore_generic';
    }
    
    /**
     * Find handler that can handle the operation type
     */
    private static function find_handler(string $operation_type): ?RestorationHandler {
        foreach (self::$handlers as $handler) {
            if ($handler->canHandle($operation_type)) {
                return $handler;
            }
        }
        return null;
    }
    
    /**
     * Get restoration preview info (what will happen)
     */
    public static function get_restore_preview(int $attachment_id, string $backup_type = null): array {
        $backup_info = SmartBackupManager::get_backup_info($attachment_id);
        if (empty($backup_info)) {
            return [
                'can_restore' => false,
                'error' => 'No backups found',
            ];
        }
        
        $operation_type = self::detect_operation_type($attachment_id, $backup_info, $backup_type);
        if (!$operation_type) {
            return [
                'can_restore' => false,
                'error' => 'Cannot determine restoration method',
            ];
        }
        
        $handler = self::find_handler($operation_type);
        if (!$handler) {
            return [
                'can_restore' => false,
                'error' => 'No handler available',
            ];
        }
        
        return [
            'can_restore' => true,
            'operation_type' => $operation_type,
            'handler' => get_class($handler),
            'steps' => $handler->getRestoreSteps($attachment_id, $backup_info),
        ];
    }
}

/**
 * Restoration result container
 */
class RestoreResult {
    public bool $success;
    public string $error;
    public array $data;
    
    public function __construct(bool $success, string $error = '', array $data = []) {
        $this->success = $success;
        $this->error = $error;
        $this->data = $data;
    }
}

/**
 * Interface for restoration handlers
 */
interface RestorationHandler {
    /**
     * Check if this handler can handle the operation type
     */
    public function canHandle(string $operation_type): bool;
    
    /**
     * Get restoration steps description
     */
    public function getRestoreSteps(int $attachment_id, array $backup_info): array;
    
    /**
     * Execute the restoration
     */
    public function execute(int $attachment_id, array $backup_info): RestoreResult;
}
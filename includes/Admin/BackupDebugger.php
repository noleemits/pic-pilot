<?php

namespace PicPilot\Admin;

use PicPilot\Backup\SmartBackupManager;
use PicPilot\Backup\BackupService;
use PicPilot\Logger;

defined('ABSPATH') || exit;

/**
 * Debug helper for backup system
 */
class BackupDebugger {
    
    /**
     * Add debug info to media library
     */
    public static function add_debug_column($post_id) {
        // Check SmartBackupManager backups
        $smart_backups = SmartBackupManager::get_backup_info($post_id);
        
        // Check legacy backups
        $legacy_exists = BackupService::backup_exists($post_id);
        
        // Check backup directories
        $uploads = wp_upload_dir();
        $backup_root = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
        
        $debug_info = [
            'smart_backups' => $smart_backups,
            'legacy_exists' => $legacy_exists,
            'backup_root' => $backup_root,
            'folders' => []
        ];
        
        // Check what folders exist
        if (is_dir($backup_root)) {
            $folders = scandir($backup_root);
            foreach ($folders as $folder) {
                if ($folder !== '.' && $folder !== '..' && is_dir($backup_root . $folder)) {
                    $debug_info['folders'][] = $folder;
                }
            }
        }
        
        Logger::log_compact("🔍 BACKUP DEBUG for ID $post_id:", $debug_info);
        
        return $debug_info;
    }
}
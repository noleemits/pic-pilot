<?php
/*
Plugin Name: Pic Pilot
Description: Smart Image Optimization & WebP for WordPress.
Version: 0.1.2
Author: Lee (Noleemits)
*/

// Autoload with Composer
require_once __DIR__ . '/vendor/autoload.php';

// Include RestoreManager first (contains RestorationHandler interface)
require_once __DIR__ . '/includes/Backup/RestoreManager.php';
require_once __DIR__ . '/includes/Backup/ContentReplacer.php';
require_once __DIR__ . '/includes/Admin/BackupDebugger.php';

// Include restoration handlers (after interface is loaded)
require_once __DIR__ . '/includes/Backup/Handlers/PngToJpegRestoreHandler.php';
require_once __DIR__ . '/includes/Backup/Handlers/WebpConversionRestoreHandler.php';
require_once __DIR__ . '/includes/Backup/Handlers/CompressionRestoreHandler.php';
require_once __DIR__ . '/includes/Backup/Handlers/ChainConversionRestoreHandler.php';

use PicPilot\Admin\MediaLibrary;
//use backup manger
use PicPilot\Compressor\EngineRouter;
use PicPilot\Backup\BackupService;
use PicPilot\Admin\Settings;
use PicPilot\Backup\BackupManager;
use PicPilot\Upload\UploadOptimizer;
use PicPilot\admin\SettingsPage;
use PicPilot\Logger;
use PicPilot\Admin\BulkOptimize;
use PicPilot\WebPConverter;
use PicPilot\Upload\UploadProcessor;
use PicPilot\Backup\SmartBackupManager;
use PicPilot\Backup\RestoreManager;
use PicPilot\Backup\ContentReplacer;

//Globals

global $pic_pilot_restoring;
$pic_pilot_restoring = false;

\PicPilot\Admin\SettingsPage::init();
\PicPilot\Admin\BulkOptimize::init();

$uploadOptimizer = new UploadOptimizer(
    new EngineRouter(),
    new Logger()
);


define('PIC_PILOT_VERSION', '0.1.0');
// Define the plugin directory
define('PIC_PILOT_DIR', plugin_dir_path(__FILE__));



//Settings page
add_action('plugins_loaded', function () {
    \PicPilot\Admin\SettingsPage::init();
    MediaLibrary::init();
    BackupManager::init();
    RestoreManager::init();
    add_action('admin_init', ['\PicPilot\Admin\SettingsPage', 'register_settings']);
});

add_action('delete_attachment', [\PicPilot\Backup\BackupService::class, 'delete_restored_files']);

// Register the AJAX endpoint
add_action('wp_ajax_pic_pilot_optimize', function () {
    $attachment_id = (int) ($_GET['attachment_id'] ?? 0);
    $nonce = $_GET['_wpnonce'] ?? '';

    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'pic-pilot'));
    }

    // Verify nonce for security
    if (!wp_verify_nonce($nonce, 'pic_pilot_optimize_' . $attachment_id)) {
        wp_die(__('Invalid nonce', 'pic-pilot'));
    }

    // Call the optimization handler
    $result = \PicPilot\Admin\MediaLibrary::handle_optimize_now_ajax($attachment_id);

    // Save the error message in the metadata if optimization failed
    if (!$result['success']) {
        // Here we can save a more detailed error message if available
        $error_message = $result['error'] ?? __('Unknown error occurred during optimization.', 'pic-pilot');
        update_post_meta($attachment_id, '_pic_pilot_optimization', [
            'status' => 'failed',
            'saved' => 0,
            'error' => $error_message // Save the error message
        ]);
    } else {
        // Save success status
        update_post_meta($attachment_id, '_pic_pilot_optimization', [
            'status' => 'optimized',
            'saved' => $result['saved']
        ]);
    }

    // Redirect back to the Media Library with success/failure status
    wp_redirect(admin_url('upload.php?optimized=' . ($result['success'] ? '1' : '0') . '&saved=' . (int) $result['saved']));
    exit;
});

//This will delete the backup file and its metadata automatically whenever an attachment (Media Library image) is deleted.
add_action('delete_attachment', function ($attachment_id) {
    if (!function_exists('get_attached_file')) return;

    \PicPilot\Logger::log("🧹 Starting comprehensive cleanup for attachment ID $attachment_id");

    // Get the current attached file and metadata before deletion
    $attached_file = get_attached_file($attachment_id);
    $metadata = wp_get_attachment_metadata($attachment_id);
    
    // Ensure we delete the current main file (in case WordPress missed it)
    if ($attached_file && file_exists($attached_file)) {
        @unlink($attached_file);
        \PicPilot\Logger::log("🧹 Deleted main attachment file: " . basename($attached_file));
    }

    // Delete all registered thumbnails
    if ($metadata && !empty($metadata['sizes'])) {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']);
        foreach ($metadata['sizes'] as $size_name => $size_info) {
            if (!empty($size_info['file'])) {
                $thumb_path = $base_dir . trailingslashit(dirname($attached_file ? str_replace($base_dir, '', $attached_file) : '')) . $size_info['file'];
                if (file_exists($thumb_path)) {
                    @unlink($thumb_path);
                    \PicPilot\Logger::log("🧹 Deleted thumbnail: " . basename($thumb_path));
                }
            }
        }
    }

    // Check if there's an original untouched file that needs cleanup
    if ($attached_file && strpos(basename($attached_file), '-resized.') !== false) {
        // Reconstruct the original filename by removing '-resized' from the current file
        $original_file = str_replace('-resized.', '.', $attached_file);
        if (file_exists($original_file)) {
            @unlink($original_file);
            \PicPilot\Logger::log("🧹 Deleted original untouched file: " . basename($original_file));
        }
    }

    // Clean up backup folders (both legacy and smart backup systems)
    if (class_exists('\\PicPilot\\Backup\\BackupService')) {
        \PicPilot\Backup\BackupService::delete_backup($attachment_id);
    }
    if (class_exists('\\PicPilot\\Backup\\SmartBackupManager')) {
        \PicPilot\Backup\SmartBackupManager::delete_all_backups($attachment_id);
    }
    //Clean optimization metadata
    if (class_exists('\\PicPilot\\Utils')) {
        \PicPilot\Utils::clear_optimization_metadata($attachment_id);
    }

    \PicPilot\Logger::log("🧹 Comprehensive cleanup completed for attachment ID $attachment_id");
});

//Ajax handler for bulk optimization
add_action('wp_ajax_pic_pilot_scan_bulk_optimize', [BulkOptimize::class, 'scan_bulk_optimize']);
add_action('wp_ajax_pic_pilot_start_bulk', [BulkOptimize::class, 'start_bulk']);
add_action('wp_ajax_pic_pilot_process_batch', [BulkOptimize::class, 'process_batch']);
add_action('wp_ajax_pic_pilot_pause_bulk', [BulkOptimize::class, 'pause_bulk']);
add_action('wp_ajax_pic_pilot_stop_bulk', [BulkOptimize::class, 'stop_bulk']);
add_action('wp_ajax_pic_pilot_resume_bulk', [BulkOptimize::class, 'resume_bulk']);

// Ajax handler for clearing the log
add_action('wp_ajax_pic_pilot_clear_log', ['\\PicPilot\\Admin\\LogViewer', 'handle_clear']);

// Ajax handlers for backup cleanup
add_action('wp_ajax_pic_pilot_clean_expired_backups', ['\\PicPilot\\Backup\\SmartBackupManager', 'handle_clean_expired']);
add_action('wp_ajax_pic_pilot_clean_all_backups', ['\\PicPilot\\Backup\\SmartBackupManager', 'handle_clean_all']);

// Schedule automatic backup cleanup
add_action('pic_pilot_cleanup_backups', ['\\PicPilot\\Backup\\SmartBackupManager', 'clean_expired_backups']);
if (!wp_next_scheduled('pic_pilot_cleanup_backups')) {
    wp_schedule_event(time(), 'daily', 'pic_pilot_cleanup_backups');
}


add_filter('wp_generate_attachment_metadata', function ($metadata, $attachment_id) {
    $file = get_attached_file($attachment_id);
    $mime = mime_content_type($file);
    \PicPilot\Logger::log("🧪 Global hook: wp_generate_attachment_metadata for ID $attachment_id (MIME: $mime, Path: $file)");
    return $metadata;
}, 10, 2);

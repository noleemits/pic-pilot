<?php
/*
Plugin Name: Pic Pilot
Description: Smart Image Optimization & WebP for WordPress.
Version: 0.1.0
Author: Lee (Noleemits)
*/

// Autoload with Composer
require_once __DIR__ . '/vendor/autoload.php';

use PicPilot\Admin\MediaLibrary;
//use backup manger
use PicPilot\Backup\BackupManager;
use PicPilot\Admin\Settings;


\PicPilot\Admin\SettingsPage::init();
\PicPilot\Admin\BulkOptimize::init();



define('PIC_PILOT_VERSION', '0.1.0');


//Settings page
add_action('plugins_loaded', function () {
    \PicPilot\Admin\SettingsPage::init();
    MediaLibrary::init();
    BackupManager::init();
    add_action('admin_init', ['\PicPilot\Admin\SettingsPage', 'register_settings']);
});


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
        $timestamp = time();
        update_post_meta($attachment_id, '_pic_pilot_optimized_version', $timestamp);
    }

    // Redirect back to the Media Library with success/failure status
    wp_redirect(admin_url('upload.php?optimized=' . ($result['success'] ? '1' : '0') . '&saved=' . (int) $result['saved']));
    exit;
});

//This will delete the backup file and its metadata automatically whenever an attachment (Media Library image) is deleted.
add_action('delete_attachment', function ($attachment_id) {
    if (class_exists('\\PicPilot\\Backup\\BackupService')) {
        \PicPilot\Backup\BackupService::delete_backup($attachment_id);
    }
});

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

\PicPilot\Admin\SettingsPage::init();
\PicPilot\Admin\BulkOptimize::init();



define('PIC_PILOT_VERSION', '0.1.0');

//Settings page
add_action('plugins_loaded', function () {
    \PicPilot\Admin\SettingsPage::init();
    MediaLibrary::init();
    add_action('admin_init', ['\PicPilot\Admin\SettingsPage', 'register_settings']);
});

// Register the AJAX endpoint (still needs to live in global scope)
add_action('wp_ajax_pic_pilot_optimize', function () {
    $attachment_id = (int) ($_GET['attachment_id'] ?? 0);
    $nonce = $_GET['_wpnonce'] ?? '';

    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'pic-pilot'));
    }

    if (!wp_verify_nonce($nonce, 'pic_pilot_optimize_' . $attachment_id)) {
        wp_die(__('Invalid nonce', 'pic-pilot'));
    }

    $result = \PicPilot\Admin\MediaLibrary::handle_optimize_now_ajax($attachment_id);

    // Return to media screen with success/failure
    wp_redirect(admin_url('upload.php?optimized=' . ($result['success'] ? '1' : '0') . '&saved=' . (int) $result['saved']));
    exit;
});

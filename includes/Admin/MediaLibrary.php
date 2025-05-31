<?php

namespace PicPilot\Admin;

use PicPilot\Logger;
use PicPilot\Settings;
use PicPilot\Compressor\LocalJpegCompressor;

class MediaLibrary {
    public static function init() {
        add_action('admin_notices', [self::class, 'maybe_show_optimization_notice']);
        add_filter('manage_upload_columns', [self::class, 'add_custom_column']);
        add_action('manage_media_custom_column', [self::class, 'render_custom_column'], 10, 2);
    }
    /*
Register new column
*/
    public static function add_custom_column($columns) {
        $columns['pic_pilot_status'] = __('Pic Pilot', 'pic-pilot');
        return $columns;
    }
    /**
     * Render the custom column content for the Media Library.
     */
    public static function render_custom_column($column_name, $post_id) {
        if ($column_name !== 'pic_pilot_status') return;

        if (!\PicPilot\Utils::is_compressible($post_id)) {
            echo '<span style="color:gray;">' . esc_html__('Not eligible', 'pic-pilot') . '</span>';
            return;
        }

        $meta = get_post_meta($post_id, '_pic_pilot_optimization', true);
        $status = $meta['status'] ?? null;
        $saved = isset($meta['saved']) ? (int) $meta['saved'] : 0;

        switch ($status) {
            case 'optimized':
                echo '<span class="pic-pilot-status pic-pilot-success">✅ ' . esc_html__('Optimized', 'pic-pilot') . '</span>';
                echo '<span class="pic-pilot-saved">' . esc_html(size_format($saved)) . ' ' . esc_html__('saved', 'pic-pilot') . '</span>';

                break;

            case 'backup_only':
                echo '<span style="color:orange;">💾 ' . esc_html__('Backed up, not compressed', 'pic-pilot') . '</span>';
                break;

            case 'pending_api':
                echo '<span style="color:blue;">⏳ ' . esc_html__('Waiting for API compression', 'pic-pilot') . '</span>';
                break;

            case 'failed':
                echo '<span class="pic-pilot-status pic-pilot-error">⛔ ' . esc_html__('Optimization failed', 'pic-pilot') . '</span>';

                break;

            default:
                // Not yet optimized — show Optimize Now button
                $nonce = wp_create_nonce('pic_pilot_optimize_' . $post_id);
                $url = admin_url('admin-ajax.php?action=pic_pilot_optimize&attachment_id=' . $post_id . '&_wpnonce=' . $nonce);
                echo '<a class="button" style="margin-top:3px;" href="' . esc_url($url) . '">' . esc_html__('Optimize Now', 'pic-pilot') . '</a>';
                break;
        }
    }


    /**
     * Performs optimization on a single attachment file via AJAX.
     */
    public static function handle_optimize_now_ajax($attachment_id) {
        $file_path = get_attached_file($attachment_id);

        if (!$file_path || !file_exists($file_path)) {
            Logger::log("❌ Attachment not found: ID $attachment_id");
            return false;
        }

        $result = \PicPilot\Optimizer::optimize_attachment($attachment_id);

        if ($result['success']) {
            return $result['saved'] ?? 0;
        }

        return 0;
    }
    //Admin notices
    public static function maybe_show_optimization_notice() {
        if (!isset($_GET['optimized'])) return;

        $saved = size_format((int) ($_GET['saved'] ?? 0));
        if ($_GET['optimized'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                sprintf(esc_html__('Image optimized successfully. Saved %s.', 'pic-pilot'), $saved) .
                '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' .
                esc_html__('Image optimization failed.', 'pic-pilot') .
                '</p></div>';
        }
    }
}

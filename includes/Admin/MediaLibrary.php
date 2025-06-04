<?php

namespace PicPilot\Admin;

use PicPilot\Logger;
use PicPilot\Settings;
use PicPilot\Compressor\Local\LocalJpegCompressor;

use PicPilot\Compressor\EngineRouter;

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
            echo '<span class="pic-pilot-status pic-pilot-not-eligible">' . esc_html__('Not eligible', 'pic-pilot') . '</span>';
            return;
        }

        $meta = get_post_meta($post_id, '_pic_pilot_optimization', true);
        $status = $meta['status'] ?? null;
        $saved = isset($meta['saved']) ? (int) $meta['saved'] : 0;

        switch ($status) {
            case 'optimized':
                echo '<span class="pic-pilot-status pic-pilot-success">✅ ' . esc_html__('Optimized', 'pic-pilot') . '</span><br>';
                echo '<span class="pic-pilot-saved">' . esc_html(size_format($saved)) . ' ' . esc_html__('saved', 'pic-pilot') . '</span>';
                break;

            case 'backup_only':
                echo '<span class="pic-pilot-status pic-pilot-warning">💾 ' . esc_html__('Backed up, not compressed', 'pic-pilot') . '</span>';
                break;

            case 'pending_api':
                echo '<span class="pic-pilot-status pic-pilot-info">⏳ ' . esc_html__('Waiting for API compression', 'pic-pilot') . '</span>';
                break;

            case 'failed':
                // Retrieve metadata for the attachment
                $meta = get_post_meta($post_id, '_pic_pilot_optimization', true);

                // Get the error message from metadata or use a default message
                $error_message = $meta['error'] ?? esc_html__('Optimization failed', 'pic-pilot');

                // Generate a nonce for the retry action
                $nonce = wp_create_nonce('pic_pilot_optimize_' . $post_id);

                // Generate the retry URL for the AJAX request
                $retry_url = admin_url('admin-ajax.php?action=pic_pilot_optimize&attachment_id=' . $post_id . '&_wpnonce=' . $nonce);

                // Display the error message and the retry button
                echo '<span class="pic-pilot-status pic-pilot-error">❌ ' . esc_html($error_message) . '</span><br>';
                echo '<a class="button button-small" href="' . esc_url($retry_url) . '">' . esc_html__('Retry', 'pic-pilot') . '</a>';
                break;




            default:
                $nonce = wp_create_nonce('pic_pilot_optimize_' . $post_id);
                $url = admin_url('admin-ajax.php?action=pic_pilot_optimize&attachment_id=' . $post_id . '&_wpnonce=' . $nonce);
                echo '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Optimize Now', 'pic-pilot') . '</a>';
                break;
        }
    }



    /**
     * Performs optimization on a single attachment file via AJAX.
     */

    public static function handle_optimize_now_ajax(int $attachment_id): array {
        if (\PicPilot\Settings::is_backup_enabled()) {
            \PicPilot\Backup\BackupService::create_backup($attachment_id);
        }
        Logger::log("🧪 Optimizing attachment ID $attachment_id");

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            Logger::log("❌ File not found for Optimize Now: ID $attachment_id");
            return ['success' => false, 'saved' => 0];
        }

        $mime = mime_content_type($file_path);
        Logger::log("🧪 File path: $file_path");
        Logger::log("🧪 MIME type: $mime");

        $settings = Settings::get();

        $mime = mime_content_type($file_path);
        Logger::log("🧪 File path: $file_path");
        Logger::log("🧪 MIME type: $mime");

        $compressor = null;

        try {
            $compressor = EngineRouter::get_compressor($mime);
            Logger::log("🔌 Routing $mime to compressor: " . get_class($compressor));
        } catch (\Exception $e) {
            Logger::log("❌ Unsupported MIME type or no valid compressor: $mime — " . $e->getMessage());
            return ['success' => false, 'saved' => 0, 'error' => __('Unsupported MIME type or no valid compressor.', 'pic-pilot')];
        }

        $result = $compressor->compress($file_path);

        // Optional: compress unscaled original
        $original_path = preg_replace('/-scaled\.(jpe?g)$/i', '.$1', $file_path);
        if ($original_path !== $file_path && file_exists($original_path)) {
            $original_result = $compressor->compress($original_path);
            $result['saved'] += $original_result['saved'] ?? 0;
            Logger::log("🖼️ Unscaled original also optimized: $original_path");
        }

        // Compress thumbnails
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size => $info) {
                $thumb_path = path_join(dirname($file_path), $info['file']);
                if (file_exists($thumb_path)) {
                    $thumb_result = $compressor->compress($thumb_path);
                    $result['saved'] += $thumb_result['saved'] ?? 0;
                }
            }
        }

        // Save status for Media Library column
        if ($result['success']) {
            update_post_meta($attachment_id, '_pic_pilot_optimization', [
                'status' => 'optimized',
                'saved' => $result['saved']
            ]);
        } else {
            update_post_meta($attachment_id, '_pic_pilot_optimization', [
                'status' => 'failed',
                'saved' => 0,
                'error' => $result['error'] ?? __('Unknown error occurred during optimization.', 'pic-pilot')
            ]);
        }

        return [
            'success' => $result['success'],
            'saved' => $result['saved'],
            'error' => $result['error'] ?? '' // Include error message in the response
        ];
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

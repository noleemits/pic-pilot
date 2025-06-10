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

        $optimized_version = get_post_meta($post_id, '_pic_pilot_optimized_version', true);
        $restored_version  = get_post_meta($post_id, '_pic_pilot_restore_version', true);
        if (!\PicPilot\Utils::is_compressible($post_id)) {
            echo '<span class="pic-pilot-status pic-pilot-not-eligible">' . esc_html__('Not eligible', 'pic-pilot') . '</span>';
            return;
        }

        $meta   = get_post_meta($post_id, '_pic_pilot_optimization', true);
        $status = $meta['status'] ?? null;
        $saved  = isset($meta['saved']) ? (int) $meta['saved'] : 0;

        $nonce = wp_create_nonce('pic_pilot_optimize_' . $post_id);
        $url   = admin_url('admin-ajax.php?action=pic_pilot_optimize&attachment_id=' . $post_id . '&_wpnonce=' . $nonce);

        // Check for restored and not yet re-optimized (covers: restored, or restored and never optimized)
        if ($restored_version && (!$optimized_version || $restored_version <= 0 || $restored_version >= $optimized_version)) {
            echo '<span class="pic-pilot-status pic-pilot-restored">♻️ ' . esc_html__('Restored', 'pic-pilot') . '</span><br>';
            echo '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Optimize Now', 'pic-pilot') . '</a>';
            return;
        }


        // ✅ Otherwise handle by status
        switch ($status) {
            case 'optimized':
                echo '<span class="pic-pilot-status pic-pilot-success">✅ ' . esc_html__('Optimized', 'pic-pilot') . '</span><br>';
                echo '<span class="pic-pilot-saved">' . esc_html(size_format($saved)) . ' ' . esc_html__('saved', 'pic-pilot') . '</span><br>';
                echo '<button class="button button-small" disabled>' . esc_html__('Optimized', 'pic-pilot') . '</button>';
                break;

            case 'backup_only':
                echo '<span class="pic-pilot-status pic-pilot-warning">💾 ' . esc_html__('Backed up, not compressed', 'pic-pilot') . '</span>';
                break;

            case 'pending_api':
                echo '<span class="pic-pilot-status pic-pilot-info">⏳ ' . esc_html__('Waiting for API compression', 'pic-pilot') . '</span>';
                break;

            case 'failed':
                $error_message = $meta['error'] ?? esc_html__('Optimization failed', 'pic-pilot');
                echo '<span class="pic-pilot-status pic-pilot-error">❌ ' . esc_html($error_message) . '</span><br>';
                echo '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Retry', 'pic-pilot') . '</a>';
                break;

            default:
                echo '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Optimize Now', 'pic-pilot') . '</a>';
                break;
        }
    }


    /**
     * Performs optimization on a single attachment file via AJAX.
     */

    public static function handle_optimize_now_ajax(int $attachment_id): array {
        Logger::log("Backup about to run: " . time());
        //Backup before optimization
        if (\PicPilot\Settings::is_backup_enabled()) {
            \PicPilot\Backup\BackupService::create_backup($attachment_id);
        }
        Logger::log("Backup finished: " . time());
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
        $total_saved = \PicPilot\Utils::compress_thumbnails($attachment_id, $compressor, $file_path);


        // Save status for Media Library column
        if ($result['success']) {
            // Save optimization metadata
            $uploads = wp_upload_dir();
            $backup_dir = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/' . $attachment_id . '/';
            $manifest = json_decode(file_get_contents($backup_dir . 'manifest.json'), true);
            $backup_created = $manifest['backup_created'] ?? time();

            Logger::log("Optimize finished: " . time());
            update_post_meta($attachment_id, '_pic_pilot_optimized_version', $backup_created);
            Logger::log("This is the backup created value: " . $backup_created);
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

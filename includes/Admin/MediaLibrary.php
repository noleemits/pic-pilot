<?php

namespace PicPilot\Admin;

use PicPilot\Logger;
use PicPilot\Settings;
use PicPilot\Compressor\Local\LocalJpegCompressor;
use PicPilot\Utils;
use PicPilot\Compressor\EngineRouter;
use PicPilot\Backup\SmartBackupManager;
use PicPilot\Backup\RestoreManager;
use PicPilot\Admin\BackupDebugger;

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
        $meta   = get_post_meta($post_id, '_pic_pilot_optimization', true);
        $status = $meta['status'] ?? null;
        $saved  = isset($meta['saved']) ? (int) $meta['saved'] : 0;

        // Check compressibility only if the image hasn't been optimized yet
        if (!$optimized_version && !\PicPilot\Utils::is_compressible($post_id)) {
            echo '<span class="pic-pilot-status pic-pilot-not-eligible">' . esc_html__('Not eligible', 'pic-pilot') . '</span>';
            return;
        }

        $nonce = wp_create_nonce('pic_pilot_optimize_' . $post_id);
        $url   = admin_url('admin-ajax.php?action=pic_pilot_optimize&attachment_id=' . $post_id . '&_wpnonce=' . $nonce);

        // Check for restored and not yet re-optimized (covers: restored, or restored and never optimized)
        if ($restored_version && (!$optimized_version || $restored_version <= 0 || $restored_version >= $optimized_version)) {
            echo '<span class="pic-pilot-status pic-pilot-restored">♻️ ' . esc_html__('Restored', 'pic-pilot') . '</span><br>';
            echo '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Optimize Now', 'pic-pilot') . '</a>';
            return;
        }
        
        // Debug backup info
        $debug_info = BackupDebugger::add_debug_column($post_id);
        
        // Check for available backups (excluding conversion backups) and show restore option
        $backup_info = SmartBackupManager::get_backup_info($post_id);
        if (!empty($backup_info)) {
            // Filter out conversion backups - we no longer support restoring PNG→JPEG conversions
            $filtered_backup_info = array_filter($backup_info, function($backup, $type) {
                return $type !== 'conversion';
            }, ARRAY_FILTER_USE_BOTH);
            
            if (!empty($filtered_backup_info)) {
                echo '<div class="pic-pilot-backup-info" style="margin-top: 4px; font-size: 11px; color: #666;">';
                
                // Show backup badges
                $backup_types = array_keys($filtered_backup_info);
                foreach ($backup_types as $type) {
                    $badge_color = self::get_backup_badge_color($type);
                    echo '<span style="' . $badge_color . ' padding: 1px 4px; border-radius: 2px; font-size: 10px; margin-right: 2px; display: inline-block;">' . esc_html(ucfirst($type)) . '</span>';
                }
                
                // Show simple restore button (using AJAX to avoid permission issues)
                $restore_nonce = wp_create_nonce('pic_pilot_restore_backup_' . $post_id);
                
                // Use a form instead of a link to avoid JavaScript interference
                $restore_url = admin_url('admin-post.php');
                echo '<br><form method="post" action="' . esc_url($restore_url) . '" style="display: inline-block; margin-top: 2px;" onsubmit="return confirm(\'Are you sure you want to restore this image? This will replace the current version and update all references across your site.\');">';
                echo '<input type="hidden" name="action" value="pic_pilot_restore_backup">';
                echo '<input type="hidden" name="attachment_id" value="' . esc_attr($post_id) . '">';
                echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($restore_nonce) . '">';
                echo '<button type="submit" class="button button-small">🔄 ' . esc_html__('Restore', 'pic-pilot') . '</button>';
                echo '</form>';
                echo '</div>';
            }
        }


        // ✅ Otherwise handle by status
        switch ($status) {
            case 'optimized':
                $summary = \PicPilot\Optimizer::summarize_optimization($post_id);
                $engine_label = \PicPilot\Utils::short_class_name($summary['engine']);
                $percent_saved = $summary['saved_percent'];

                // ✅ Optimized badge with all info (no duplicate button)  
                echo '<span class="pic-pilot-status pic-pilot-success">✅ ' . esc_html__('Optimized', 'pic-pilot') . '</span><br>';

                // ✅ Engine and saved percent info with tooltip
                $tooltip_content = \PicPilot\Utils::generate_optimization_tooltip($post_id);
                echo '<div class="pic-pilot-meta-info pic-pilot-has-tooltip" style="font-size: 11px; color: #666; margin-top: 4px; position: relative; cursor: help;" title="Hover for detailed breakdown">';
                echo esc_html__("Optimized with {$engine_label}", 'pic-pilot') . ' – ' . esc_html("Saved {$percent_saved}%", 'pic-pilot');
                echo ' <span style="color: #0073aa; font-size: 10px;">ⓘ</span>'; // Info icon
                echo '<div class="pic-pilot-tooltip-content" style="display: none;">' . $tooltip_content . '</div>';
                echo '</div>';
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
        Logger::log("🧪 Optimizing attachment ID $attachment_id");

        // Use the centralized optimization method which includes resize logic
        $result = \PicPilot\Optimizer::optimize_attachment($attachment_id);

        // The Optimizer handles all metadata saving, so we just need to return the result
        return $result;
    }



    /**
     * Get backup badge color styling
     */
    private static function get_backup_badge_color(string $type): string {
        switch ($type) {
            case 'conversion':
                return 'background: #d63384; color: white;';
            case 'user':
                return 'background: #0d6efd; color: white;';
            case 'serving':
                return 'background: #198754; color: white;';
            case 'legacy':
                return 'background: #6c757d; color: white;';
            default:
                return 'background: #6c757d; color: white;';
        }
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

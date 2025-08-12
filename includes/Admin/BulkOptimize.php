<?php

namespace PicPilot\Admin;

use PicPilot\Utils;
use PicPilot\Settings;
use PicPilot\Optimizer;
use PicPilot\Admin\SettingsPage;
use PicPilot\Logger;


class BulkOptimize {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
    }


    public static function add_menu() {
        add_submenu_page(
            'pic-pilot',
            __('Bulk Optimize', 'pic-pilot'),
            __('Bulk Optimize', 'pic-pilot'),
            'manage_options', // Only allow admins
            'pic-pilot-bulk', // this is your key!
            [self::class, 'render_page']
        );
    }

    public static function scan_bulk_optimize() {
        check_ajax_referer('pic_pilot_nonce');

        $ids = self::get_optimizable_attachment_ids();

        // Filter out already optimized
        $unoptimized = array_filter($ids, function ($id) {
            return !get_post_meta($id, '_pic_pilot_optimized', true);
        });

        wp_send_json_success([
            'count' => count($unoptimized),
            'ids' => array_values($unoptimized)
        ]);
    }


    public static function render_page() {

        if (!current_user_can('read')) {
            wp_die(__('You do not have permission to access this page.', 'pic-pilot'));
        }
?>
        <div class="wrap">

            <h1><?php esc_html_e('Bulk Image Optimization', 'pic-pilot'); ?></h1>
            <?php
            $unoptimized_ids = self::get_optimizable_attachment_ids();
            if (Settings::is_enabled('enable_backup') && !empty($unoptimized_ids)) {
                $total_bytes = 0;

                foreach ($unoptimized_ids as $id) {
                    if (!get_post_meta($id, '_pic_pilot_optimized', true)) {
                        $file = get_attached_file($id);
                        if ($file && file_exists($file)) {
                            $total_bytes += filesize($file);
                        }
                    }
                }

                $estimated_mb = round($total_bytes / 1024 / 1024);

                echo '<div class="notice notice-warning" style="margin-top: 20px;">
        <p><strong>' . esc_html__('Backup is enabled, but we currently don not support backups on bulk optimization.', 'pic-pilot') . '</strong> ' .
                    esc_html__('This may double your storage usage during bulk optimization.', 'pic-pilot') . '<br>' .
                    sprintf(
                        esc_html__('Estimated additional space usage: ~%dMB (rough estimate).', 'pic-pilot'),
                        $estimated_mb
                    ) . ' ' .
                    '<a href="' . esc_url(admin_url('admin.php?page=pic-pilot#backup-settings')) . '" target="_blank">' .
                    esc_html__('Go to Settings to disable it', 'pic-pilot') . '</a></p>
    </div>';
            }

            ?>

            <p><?php esc_html_e('Scan your Media Library and see how many images can be optimized.', 'pic-pilot'); ?></p>

            <button type="button" class="button button-primary" id="pp-scan-bulk">
                <?php esc_html_e('Scan for Optimizable Images', 'pic-pilot'); ?>
            </button>
            <div id="pp-scan-summary" style="margin-top: 12px;"></div>

            <?php
            if (isset($_POST['pic_pilot_bulk_optimize'])) {
                self::run_bulk_optimize();
            }
            ?>
            <div id="pic-pilot-bulk-ui">
                <button id="pp-start-bulk" class="button">Start Bulk Optimize</button>
                <button id="pp-pause-bulk" class="button">Pause</button>
                <button id="pp-resume-bulk" class="button">Resume</button>
                <button id="pp-stop-bulk" class="button">Stop</button>
                <div id="pp-bulk-progress">Not started</div>
                <div id="pp-log-wrapper" style="margin-top: 30px;">
                    <h3 style="margin-bottom: 10px;">Optimization Log</h3>
                    <div id="pp-log-table" style="margin-bottom: 10px;"></div>
                    <div id="pp-log-pagination"></div>
                </div>

            </div>

        </div>
<?php
    }

    /**
     * Find JPEGs that haven't been optimized, run optimizer, and show results.
     */
    protected static function run_bulk_optimize() {
        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 20,
            'meta_query'     => [
                [
                    'key'     => '_pic_pilot_optimization',
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'fields' => 'ids',
        ]);

        if (!$query->have_posts()) {
            echo '<p>' . esc_html__('No unoptimized images found.', 'pic-pilot') . '</p>';
            return;
        }

        $total_saved = 0;
        $count = 0;

        echo '<ul>';
        foreach ($query->posts as $attachment_id) {
            if (!Utils::is_compressible($attachment_id)) continue;

            $result = Optimizer::optimize_attachment($attachment_id);
            $saved = $result['saved'] ?? 0;

            echo '<li>' . esc_html(get_the_title($attachment_id)) . ' - ' . esc_html(size_format($saved)) . ' saved</li>';
            $total_saved += $saved;
            $count++;
        }
        echo '</ul>';

        echo '<p><strong>' . sprintf(
            esc_html__('Optimized %d images. Total space saved: %s', 'pic-pilot'),
            $count,
            size_format($total_saved)
        ) . '</strong></p>';
    }
    //Bulke throttle optimization to prevent server overload
    const QUEUE_KEY = 'pic_pilot_bulk_queue';
    const FLAGS_KEY = 'pic_pilot_bulk_flags';
    const BATCH_SIZE = 5;

    public static function start_bulk() {
        if (!check_ajax_referer('pic_pilot_nonce', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed']);
        }

        $ids_raw = isset($_POST['ids']) ? $_POST['ids'] : '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

        if (empty($ids)) {
            wp_send_json_error(['message' => 'No valid IDs found.']);
        }

        set_transient(self::QUEUE_KEY, [
            'ids' => $ids,
            'index' => 0,
        ], HOUR_IN_SECONDS);

        set_transient(self::FLAGS_KEY, [
            'paused' => false,
            'stopped' => false,
        ], HOUR_IN_SECONDS);

        wp_send_json_success(['message' => 'Queue initialized.']);
    }


    public static function pause_bulk() {
        check_ajax_referer('pic_pilot_nonce');
        $flags = get_transient(self::FLAGS_KEY);
        $flags['paused'] = true;
        set_transient(self::FLAGS_KEY, $flags, HOUR_IN_SECONDS);
        wp_send_json_success(['message' => 'Queue paused.']);
    }

    public static function resume_bulk() {
        check_ajax_referer('pic_pilot_nonce');

        $flags = get_transient(self::FLAGS_KEY);
        $flags['paused'] = false;
        set_transient(self::FLAGS_KEY, $flags, HOUR_IN_SECONDS);

        wp_send_json_success(['message' => 'Queue resumed.']);
    }

    public static function stop_bulk() {
        check_ajax_referer('pic_pilot_nonce');
        delete_transient(self::QUEUE_KEY);
        delete_transient(self::FLAGS_KEY);
        wp_send_json_success(['message' => 'Queue stopped and cleared.']);
    }
    //Process batch
    public static function process_batch() {
        check_ajax_referer('pic_pilot_nonce');

        $queue = get_transient(self::QUEUE_KEY);
        $flags = get_transient(self::FLAGS_KEY);

        Logger::log('📦 Starting batch processing check');

        $queue = get_transient(self::QUEUE_KEY);
        $flags = get_transient(self::FLAGS_KEY);

        Logger::log('🎯 Queue:', $queue);
        Logger::log('🎯 Flags:', $flags);

        if (empty($queue['ids']) || $flags['stopped']) {
            Logger::log('✅ Queue complete or manually stopped.');
            delete_transient(self::QUEUE_KEY);
            delete_transient(self::FLAGS_KEY);
            wp_send_json_success(['done' => true, 'message' => 'Queue completed or stopped.']);
        }

        if ($flags['paused']) {
            wp_send_json_success(['paused' => true, 'message' => 'Queue paused.']);
        }

        $ids = $queue['ids'];
        $start = $queue['index'];
        $batch = array_slice($ids, $start, self::BATCH_SIZE);
        $results = [];

        foreach ($batch as $id) {
            $optimization = Optimizer::optimize_attachment($id);

            $path = get_attached_file($id);
            $filename = basename($path);
            $engine = get_post_meta($id, '_pic_pilot_engine', true);

            $summary = Optimizer::summarize_optimization($id);

            $results[] = [
                'id' => $id,
                'filename' => $filename,
                'path' => $path,
                'engine' => $engine,
                'saved_percent' => $summary['saved_percent'],
                'main_percent' => $summary['main_percent'],
                'thumb_percent' => $summary['thumb_percent'],
                'thumbnails' => $summary['thumbs'] ?? 0,
                'thumb_count' => $summary['thumb_count'],

            ];
        }

        $new_index = $start + count($batch);
        $queue['index'] = $new_index;
        set_transient(self::QUEUE_KEY, $queue, HOUR_IN_SECONDS);

        if ($new_index >= count($ids)) {
            delete_transient(self::QUEUE_KEY);
            delete_transient(self::FLAGS_KEY);
            wp_send_json_success([
                'done' => true,
                'results' => $results,
                'message' => 'All images optimized.'
            ]);
        }

        wp_send_json_success([
            'done' => false,
            'progress' => [
                'processed' => $new_index,
                'total' => count($ids),
                'percent' => round(($new_index / count($ids)) * 100)
            ],
            'results' => $results
        ]);
    }


    public static function get_optimizable_attachment_ids(): array {
        // TODO: Implement real filter logic
        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        return $query->posts;
    }
}

<?php

namespace PicPilot\Admin;

use PicPilot\Utils;
use PicPilot\Settings;
use PicPilot\Optimizer;

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

    public static function render_page() {
        if (!current_user_can('read')) {
            wp_die(__('You do not have permission to access this page.', 'pic-pilot'));
        }
?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Image Optimization', 'pic-pilot'); ?></h1>
            <p><?php esc_html_e('Scan your Media Library and optimize JPEG images using your current settings.', 'pic-pilot'); ?></p>

            <form method="post">
                <?php submit_button(__('Scan for Unoptimized Images', 'pic-pilot'), 'primary', 'pic_pilot_bulk_optimize'); ?>
            </form>

            <?php
            if (isset($_POST['pic_pilot_bulk_optimize'])) {
                self::run_bulk_optimize();
            }
            ?>
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
}

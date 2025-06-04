<?php

namespace PicPilot\Backup;

if (! defined('ABSPATH')) exit;

use PicPilot\Logger;

class BackupManager {
    public static function init() {
        add_action('admin_post_pic_pilot_restore_backup', [self::class, 'handle_restore_backup']);
        add_action('admin_post_pic_pilot_delete_backup', [self::class, 'handle_delete_backup']);
    }
    const PER_PAGE = 20;

    public static function render_backup_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Pic Pilot Backups', 'pic-pilot') . '</h1>';
        echo '<div class="notice notice-info" style="margin-bottom:15px;"><p>'
            . esc_html__('Note: Backups are tied to their Media Library images. If you delete an image from the Media Library, its backup will be deleted as well.', 'pic-pilot')
            . '</p></div>';


        // Display notices for restore/delete
        if (isset($_GET['restored'])) {
            if ($_GET['restored']) {
                echo '<div class="notice notice-success"><p>' . esc_html__('Backup restored successfully!', 'pic-pilot') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('Failed to restore backup.', 'pic-pilot') . '</p></div>';
            }
        }
        if (isset($_GET['deleted'])) {
            if ($_GET['deleted']) {
                echo '<div class="notice notice-success"><p>' . esc_html__('Backup deleted successfully!', 'pic-pilot') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('Failed to delete backup.', 'pic-pilot') . '</p></div>';
            }
        }

        // Stats section
        echo '<div id="pic-pilot-backup-stats">';
        echo '<strong>' . esc_html__('Total Backups:', 'pic-pilot') . '</strong> ' . esc_html(self::get_total_backups());
        echo ' &mdash; <strong>' . esc_html__('Space Used:', 'pic-pilot') . '</strong> ' . esc_html(self::get_total_space());
        echo '</div>';

        // Search form
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        echo '<form method="get" style="margin:1em 0;">';
        echo '<input type="hidden" name="page" value="pic-pilot-backups">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search backups...', 'pic-pilot') . '">';
        echo '<input type="submit" class="button" value="' . esc_attr__('Search', 'pic-pilot') . '">';
        echo '</form>';

        // Query for backups
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => self::PER_PAGE,
            'paged'          => $paged,
            'meta_query'     => [
                [
                    'key'     => \PicPilot\Backup\BackupService::META_KEY,
                    'compare' => 'EXISTS',
                ],
            ],
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ];
        if ($search) {
            $args['s'] = $search;
        }
        $query = new \WP_Query($args);

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Preview', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('Title', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('File', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('Date', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('Size', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('Actions', 'pic-pilot') . '</th>';
        echo '</tr></thead><tbody>';

        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $meta = \PicPilot\Backup\BackupService::get_backup_metadata($post->ID);
                $thumb = wp_get_attachment_image($post->ID, [60, 60], true);
                $edit_link = get_edit_post_link($post->ID);
                $title = esc_html(get_the_title($post->ID));
                $file = esc_html($meta['backup_filename'] ?? '-');
                $date = !empty($meta['backup_created']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $meta['backup_created']) : '-';
                $size = !empty($meta['original_filesize']) ? size_format($meta['original_filesize'], 2) : '-';
                $backup_file = \PicPilot\Backup\BackupService::get_backup_dir() . ($meta['backup_filename'] ?? '');
                $current_file = get_attached_file($post->ID);
                // Check if backup exists and is different from current
                $can_restore = file_exists($backup_file) && (filesize($backup_file) !== @filesize($current_file));
                echo '<tr>';
                echo '<td>' . $thumb . '</td>';
                echo '<td><a href="' . esc_url($edit_link) . '" target="_blank">' . $title . '</a></td>';
                echo '<td>' . $file . '</td>';
                echo '<td>' . $date . '</td>';
                echo '<td>' . $size . '</td>';
                echo '<td>
                <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">'
                    . wp_nonce_field('pic_pilot_restore_backup_' . $post->ID, '_wpnonce', true, false)
                    . '<input type="hidden" name="action" value="pic_pilot_restore_backup">'
                    . '<input type="hidden" name="attachment_id" value="' . esc_attr($post->ID) . '">'
                    . '<button class="button" aria-label="' . esc_attr__('Restore original image', 'pic-pilot') . '"'
                    . ($can_restore ? '' : ' disabled')
                    . '>' . esc_html__('Restore', 'pic-pilot') . '</button>'
                    . '</form>
                    <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">'
                    . wp_nonce_field('pic_pilot_delete_backup_' . $post->ID, '_wpnonce', true, false)
                    . '<input type="hidden" name="action" value="pic_pilot_delete_backup">'
                    . '<input type="hidden" name="attachment_id" value="' . esc_attr($post->ID) . '">'
                    . '<button class="button button-danger" aria-label="' . esc_attr__('Delete backup', 'pic-pilot') . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this backup?', 'pic-pilot')) . '\');">' . esc_html__('Delete', 'pic-pilot') . '</button>'
                    . '</form>
                </td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6" style="text-align:center;">' . esc_html__('No backups found.', 'pic-pilot') . '</td></tr>';
        }
        echo '</tbody></table>';

        // Pagination
        $total = $query->max_num_pages;
        if ($total > 1) {
            $current = $paged;
            $base = esc_url_raw(add_query_arg(['paged' => '%#%', 's' => $search]));
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
                'base'      => $base,
                'format'    => '',
                'current'   => $current,
                'total'     => $total,
                'prev_text' => __('« Prev'),
                'next_text' => __('Next »'),
            ]);
            echo '</div></div>';
        }

        echo '</div>';
    }

    public static function get_total_backups() {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", \PicPilot\Backup\BackupService::META_KEY));
        return $count ? number_format_i18n($count) : '0';
    }

    public static function get_total_space() {
        global $wpdb;
        $meta_key = \PicPilot\Backup\BackupService::META_KEY;
        $total = 0;
        $results = $wpdb->get_results($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key));
        if ($results) {
            foreach ($results as $row) {
                $meta = maybe_unserialize($row->meta_value);
                if (!empty($meta['original_filesize'])) {
                    $total += (int)$meta['original_filesize'];
                }
            }
        }
        return size_format($total, 2);
    }

    public static function handle_restore_backup() {
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        check_admin_referer('pic_pilot_restore_backup_' . $attachment_id);

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'pic-pilot'));
        }

        $success = \PicPilot\Backup\BackupService::restore_backup($attachment_id);
        Logger::log(($success ? '✅ Restored' : '❌ Failed to restore') . ' backup for image ID ' . $attachment_id);

        wp_redirect(admin_url('admin.php?page=pic-pilot-backups&restored=' . ($success ? '1' : '0')));
        exit;
    }

    public static function handle_delete_backup() {
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        check_admin_referer('pic_pilot_delete_backup_' . $attachment_id);

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'pic-pilot'));
        }

        $success = \PicPilot\Backup\BackupService::delete_backup($attachment_id);
        Logger::log(($success ? '🗑️ Deleted' : '❌ Failed to delete') . ' backup for image ID ' . $attachment_id);

        wp_redirect(admin_url('admin.php?page=pic-pilot-backups&deleted=' . ($success ? '1' : '0')));
        exit;
    }
}

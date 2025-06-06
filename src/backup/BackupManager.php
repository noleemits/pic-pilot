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
        // 1. Calculate backup stats here:
        $uploads = wp_upload_dir();
        $backup_root = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
        $backup_dirs = is_dir($backup_root) ? array_filter(scandir($backup_root), function ($d) use ($backup_root) {
            return $d !== '.' && $d !== '..' && is_dir($backup_root . $d);
        }) : [];

        $backup_count = count($backup_dirs);
        $total_size = 0;
        foreach ($backup_dirs as $dir) {
            $dir_path = $backup_root . $dir . '/';
            foreach (glob($dir_path . '*') as $file) {
                $total_size += filesize($file);
            }
        }
        $total_size_mb = $total_size ? round($total_size / 1024 / 1024, 2) : 0;

        // 2. Pass variables to partial:
        $backup_summary = [
            'count'   => $backup_count,
            'size_mb' => $total_size_mb,
        ];
        // 3. Include the partial at the right spot in your page render:
        $summary_path = PIC_PILOT_DIR . 'includes/partials/backup-manager-info.php';
        if (file_exists($summary_path)) {
            include $summary_path;
        }


        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Pic Pilot Backups', 'pic-pilot') . '</h1>';

        // Scan all backup folders in uploads/pic-pilot-backups
        $uploads = wp_upload_dir();
        $backup_root = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
        $dirs = is_dir($backup_root) ? scandir($backup_root) : [];
        $backups = [];

        foreach ($dirs as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($backup_root . $entry)) continue;
            $manifest_file = $backup_root . $entry . '/manifest.json';
            if (file_exists($manifest_file)) {
                $manifest = json_decode(file_get_contents($manifest_file), true);
                $attachment_id = intval($entry);
                $post = get_post($attachment_id);
                if ($post && $post->post_type === 'attachment') {
                    $backups[] = [
                        'attachment_id' => $attachment_id,
                        'post' => $post,
                        'manifest' => $manifest,
                        'backup_dir' => $backup_root . $entry . '/',
                    ];
                }
            }
        }

        // Pagination
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $total = count($backups);
        $pages = ceil($total / self::PER_PAGE);
        $offset = ($paged - 1) * self::PER_PAGE;
        $paged_backups = array_slice($backups, $offset, self::PER_PAGE);

        // Table headers
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Preview', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('Title', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('File', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('Date', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('Size', 'pic-pilot') . '</th>';
        echo '<th>' . esc_html__('Actions', 'pic-pilot') . '</th>';
        echo '</tr></thead><tbody>';

        if ($paged_backups) {
            foreach ($paged_backups as $backup) {
                $post = $backup['post'];
                $manifest = $backup['manifest'];
                $backup_dir = $backup['backup_dir'];
                $thumb = wp_get_attachment_image($post->ID, [60, 60], true);
                $edit_link = get_edit_post_link($post->ID);
                $title = esc_html(get_the_title($post->ID));
                $file = esc_html($manifest['main']['original_path'] ?? '-');
                $date = !empty($manifest['backup_created']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $manifest['backup_created']) : '-';
                $size = !empty($manifest['original_filesize']) ? size_format($manifest['original_filesize'], 2) : '-';

                // Restore button logic
                $restored_version  = get_post_meta($post->ID, '_pic_pilot_restore_version', true);
                $optimized_version = get_post_meta($post->ID, '_pic_pilot_optimized_version', true);
                $already_restored = $restored_version && (!$optimized_version || $restored_version >= $optimized_version);

                $main_backup_file = $backup_dir . ($manifest['main']['filename'] ?? '');
                $manifest_exists = file_exists($backup_dir . 'manifest.json');
                $can_restore = $manifest_exists && file_exists($main_backup_file) && !$already_restored;
                $restore_disabled = !$can_restore ? 'disabled aria-disabled="true" title="' . esc_attr__('Already restored.', 'pic-pilot') . '"' : '';

                $restore_label = $already_restored ? esc_html__('Restored', 'pic-pilot') : esc_html__('Restore', 'pic-pilot');


                // Delete button always enabled if folder exists
                $delete_disabled = !$manifest_exists ? 'disabled aria-disabled="true"' : '';

                echo '<tr>';
                echo '<td>' . $thumb . '</td>';
                echo '<td><a href="' . esc_url($edit_link) . '" target="_blank">' . $title . '</a></td>';
                echo '<td>' . $file . '</td>';
                echo '<td>' . $date . '</td>';
                echo '<td>' . $size . '</td>';
                echo '<td>';
                // Restore button
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
                echo wp_nonce_field('pic_pilot_restore_backup_' . $post->ID, '_wpnonce', true, false);
                echo '<input type="hidden" name="action" value="pic_pilot_restore_backup">';
                echo '<input type="hidden" name="attachment_id" value="' . esc_attr($post->ID) . '">';
                echo '<button class="button pic-pilot-m-r-5" ' . $restore_disabled . ' aria-label="' . esc_attr__('Restore original image', 'pic-pilot') . '">' . $restore_label . '</button>';
                echo '<pre style="font-size:10px; color:#666;">';
                echo '</form>';
                // Delete button
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
                echo wp_nonce_field('pic_pilot_delete_backup_' . $post->ID, '_wpnonce', true, false);
                echo '<input type="hidden" name="action" value="pic_pilot_delete_backup">';
                echo '<input type="hidden" name="attachment_id" value="' . esc_attr($post->ID) . '">';
                echo '<button class="button button-danger" ' . $delete_disabled . ' aria-label="' . esc_attr__('Delete backup', 'pic-pilot') . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this backup?', 'pic-pilot')) . '\');">' . esc_html__('Delete', 'pic-pilot') . '</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6" style="text-align:center;">' . esc_html__('No backups found.', 'pic-pilot') . '</td></tr>';
        }
        echo '</tbody></table>';

        // Pagination links
        if ($pages > 1) {
            $base = esc_url_raw(add_query_arg(['paged' => '%#%']));
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
                'base'      => $base,
                'format'    => '',
                'current'   => $paged,
                'total'     => $pages,
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

<?php

namespace PicPilot\Backup;

if (! defined('ABSPATH')) exit;

use PicPilot\Logger;

class BackupManager {
    public static function init() {
        add_action('admin_post_pic_pilot_restore_backup', [self::class, 'handle_restore_backup']);
        add_action('admin_post_nopriv_pic_pilot_restore_backup', [self::class, 'handle_restore_backup']);
        add_action('admin_post_pic_pilot_delete_backup', [self::class, 'handle_delete_backup']);
        add_action('admin_post_nopriv_pic_pilot_delete_backup', [self::class, 'handle_delete_backup']);
        
        // Bulk operations
        add_action('admin_post_pic_pilot_bulk_restore_backups', [self::class, 'handle_bulk_restore']);
        add_action('admin_post_pic_pilot_bulk_delete_backups', [self::class, 'handle_bulk_delete']);
        
        // Debug: Test if admin-post.php is working at all
        add_action('admin_post_pic_pilot_test_bulk', [self::class, 'test_bulk_handler']);
        
        // EWWW-style action hooks for restoration (multiple access points)
        add_action('admin_action_pic_pilot_restore_backup', [self::class, 'handle_restore_backup']);
        add_action('wp_ajax_pic_pilot_restore_backup', [self::class, 'handle_restore_ajax']);
        add_action('wp_ajax_pic_pilot_search_backups', [self::class, 'handle_search_ajax']);
        
    }
    const DEFAULT_PER_PAGE = 20;


    public static function render_backup_page() {
        // 1. Calculate backup stats using same logic as backup display:
        $uploads = wp_upload_dir();
        $backup_root = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
        $unique_attachments = [];
        $total_size = 0;

        // Scan legacy backups (direct attachment ID folders)
        if (is_dir($backup_root)) {
            $dirs = scandir($backup_root);
            foreach ($dirs as $entry) {
                if ($entry === '.' || $entry === '..' || !is_dir($backup_root . $entry)) continue;
                
                // Skip SmartBackupManager type directories
                if (in_array($entry, ['user', 'conversion', 'serving'])) continue;
                
                $manifest_file = $backup_root . $entry . '/manifest.json';
                if (file_exists($manifest_file)) {
                    $attachment_id = intval($entry);
                    $post = get_post($attachment_id);
                    if ($post && $post->post_type === 'attachment') {
                        $unique_attachments[$attachment_id] = true;
                        // Calculate size for this backup
                        $dir_path = $backup_root . $entry . '/';
                        foreach (glob($dir_path . '*') as $file) {
                            if (is_file($file)) $total_size += filesize($file);
                        }
                    }
                }
            }
        }

        // Scan SmartBackupManager backups (typed folders)
        $smart_backup_types = ['user', 'serving', 'conversion'];
        foreach ($smart_backup_types as $type) {
            $type_dir = $backup_root . $type . '/';
            if (is_dir($type_dir)) {
                $type_dirs = scandir($type_dir);
                foreach ($type_dirs as $entry) {
                    if ($entry === '.' || $entry === '..' || !is_dir($type_dir . $entry)) continue;
                    
                    $manifest_file = $type_dir . $entry . '/manifest.json';
                    if (file_exists($manifest_file)) {
                        $attachment_id = intval($entry);
                        $post = get_post($attachment_id);
                        if ($post && $post->post_type === 'attachment') {
                            $unique_attachments[$attachment_id] = true;
                            // Calculate size for this backup
                            $dir_path = $type_dir . $entry . '/';
                            foreach (glob($dir_path . '*') as $file) {
                                if (is_file($file)) $total_size += filesize($file);
                            }
                        }
                    }
                }
            }
        }

        $backup_count = count($unique_attachments);
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
        
        // Show bulk operation messages
        if (isset($_GET['bulk_restored'])) {
            $restored = intval($_GET['bulk_restored']);
            $failed = intval($_GET['bulk_failed'] ?? 0);
            if ($restored > 0) {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo sprintf(_n('%d backup restored successfully.', '%d backups restored successfully.', $restored, 'pic-pilot'), $restored);
                if ($failed > 0) {
                    echo ' ' . sprintf(_n('%d backup failed to restore.', '%d backups failed to restore.', $failed, 'pic-pilot'), $failed);
                }
                echo '</p></div>';
            }
        }
        
        if (isset($_GET['bulk_deleted'])) {
            $deleted = intval($_GET['bulk_deleted']);
            $failed = intval($_GET['bulk_failed'] ?? 0);
            if ($deleted > 0) {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo sprintf(_n('%d backup deleted successfully.', '%d backups deleted successfully.', $deleted, 'pic-pilot'), $deleted);
                if ($failed > 0) {
                    echo ' ' . sprintf(_n('%d backup failed to delete.', '%d backups failed to delete.', $failed, 'pic-pilot'), $failed);
                }
                echo '</p></div>';
            }
        }
        
        if (isset($_GET['error']) && $_GET['error'] === 'no_selection') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please select at least one backup.', 'pic-pilot') . '</p></div>';
        }
        
        // Search and filter form
        $search = sanitize_text_field($_GET['s'] ?? '');
        $backup_type_filter = sanitize_text_field($_GET['backup_type'] ?? '');
        $per_page = intval($_GET['per_page'] ?? self::DEFAULT_PER_PAGE);
        
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<form method="get" style="display: inline-block;">';
        echo '<input type="hidden" name="page" value="pic-pilot-backups">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search backups...', 'pic-pilot') . '" style="margin-right: 5px;">';
        echo '<select name="backup_type" style="margin-right: 5px;">';
        echo '<option value="">' . esc_html__('All Types', 'pic-pilot') . '</option>';
        echo '<option value="legacy"' . selected($backup_type_filter, 'legacy', false) . '>' . esc_html__('Legacy', 'pic-pilot') . '</option>';
        echo '<option value="user"' . selected($backup_type_filter, 'user', false) . '>' . esc_html__('User', 'pic-pilot') . '</option>';
        echo '<option value="conversion"' . selected($backup_type_filter, 'conversion', false) . '>' . esc_html__('Conversion', 'pic-pilot') . '</option>';
        echo '<option value="serving"' . selected($backup_type_filter, 'serving', false) . '>' . esc_html__('Serving', 'pic-pilot') . '</option>';
        echo '</select>';
        echo '<select name="per_page" style="margin-right: 5px;">';
        foreach ([10, 20, 50, 100] as $option) {
            echo '<option value="' . $option . '"' . selected($per_page, $option, false) . '>' . sprintf(__('%d items', 'pic-pilot'), $option) . '</option>';
        }
        echo '</select>';
        echo '<input type="submit" class="button" value="' . esc_attr__('Filter', 'pic-pilot') . '">';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        // Scan all backup folders in uploads/pic-pilot-backups (both legacy and SmartBackupManager)
        $uploads = wp_upload_dir();
        $backup_root = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
        $backups = [];

        // Scan legacy backups (direct attachment ID folders)
        if (is_dir($backup_root)) {
            $dirs = scandir($backup_root);
            foreach ($dirs as $entry) {
                if ($entry === '.' || $entry === '..' || !is_dir($backup_root . $entry)) continue;
                
                // Skip SmartBackupManager type directories (user, conversion, serving)
                if (in_array($entry, ['user', 'conversion', 'serving'])) continue;
                
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
                            'backup_type' => 'legacy',
                        ];
                    }
                }
            }
        }

        // Scan SmartBackupManager backups (typed folders) - including conversion backups
        $smart_backup_types = ['user', 'serving', 'conversion'];
        foreach ($smart_backup_types as $type) {
            $type_dir = $backup_root . $type . '/';
            if (is_dir($type_dir)) {
                $type_dirs = scandir($type_dir);
                foreach ($type_dirs as $entry) {
                    if ($entry === '.' || $entry === '..' || !is_dir($type_dir . $entry)) continue;
                    
                    $manifest_file = $type_dir . $entry . '/manifest.json';
                    if (file_exists($manifest_file)) {
                        $manifest = json_decode(file_get_contents($manifest_file), true);
                        $attachment_id = intval($entry);
                        $post = get_post($attachment_id);
                        if ($post && $post->post_type === 'attachment') {
                            // Check if we already have a backup for this attachment
                            $existing_key = array_search($attachment_id, array_column($backups, 'attachment_id'));
                            if ($existing_key !== false) {
                                // Add this backup type to existing entry
                                if (!isset($backups[$existing_key]['backup_types'])) {
                                    $backups[$existing_key]['backup_types'] = [$backups[$existing_key]['backup_type']];
                                }
                                $backups[$existing_key]['backup_types'][] = $type;
                                $backups[$existing_key]['smart_backups'][$type] = [
                                    'manifest' => $manifest,
                                    'backup_dir' => $type_dir . $entry . '/',
                                ];
                            } else {
                                // Create new entry for this attachment
                                $backups[] = [
                                    'attachment_id' => $attachment_id,
                                    'post' => $post,
                                    'manifest' => $manifest,
                                    'backup_dir' => $type_dir . $entry . '/',
                                    'backup_type' => $type,
                                    'backup_types' => [$type],
                                    'smart_backups' => [
                                        $type => [
                                            'manifest' => $manifest,
                                            'backup_dir' => $type_dir . $entry . '/',
                                        ]
                                    ],
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Filter backups based on search and type
        if ($search || $backup_type_filter) {
            $filtered_backups = [];
            foreach ($backups as $backup) {
                $match = true;
                
                // Search filter
                if ($search) {
                    $title = get_the_title($backup['post']->ID);
                    $filename = $backup['manifest']['main']['original_path'] ?? '';
                    if (stripos($title, $search) === false && stripos($filename, $search) === false) {
                        $match = false;
                    }
                }
                
                // Type filter
                if ($backup_type_filter && $match) {
                    $backup_types = $backup['backup_types'] ?? [$backup['backup_type']];
                    if (!in_array($backup_type_filter, $backup_types)) {
                        $match = false;
                    }
                }
                
                if ($match) {
                    $filtered_backups[] = $backup;
                }
            }
            $backups = $filtered_backups;
        }
        
        // Pagination
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $total = count($backups);
        $pages = ceil($total / $per_page);
        $offset = ($paged - 1) * $per_page;
        $paged_backups = array_slice($backups, $offset, $per_page);

        // Bulk action form and table
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="bulk-backup-form">';
        echo wp_nonce_field('pic_pilot_bulk_backup_action', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="" id="bulk-action-input">';
        
        // Bulk actions dropdown
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions bulkactions">';
        echo '<select name="action2" id="bulk-action-selector-top">';
        echo '<option value="-1">' . esc_html__('Bulk Actions', 'pic-pilot') . '</option>';
        echo '<option value="test_bulk">' . esc_html__('Test (Debug)', 'pic-pilot') . '</option>';
        echo '<option value="bulk_restore">' . esc_html__('Restore', 'pic-pilot') . '</option>';
        echo '<option value="bulk_delete">' . esc_html__('Delete', 'pic-pilot') . '</option>';
        echo '</select>';
        echo '<input type="submit" class="button action" value="' . esc_attr__('Apply', 'pic-pilot') . '" id="bulk-action-submit">';
        echo '</div>';
        
        // Results info
        if ($search || $backup_type_filter) {
            echo '<div class="alignright" style="margin-top: 10px;">';
            echo sprintf(__('Showing %d of %d backups', 'pic-pilot'), count($paged_backups), $total);
            if ($search) echo ' - <strong>' . sprintf(__('Search: "%s"', 'pic-pilot'), esc_html($search)) . '</strong>';
            echo '</div>';
        }
        echo '</div>';
        
        // Table headers
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all"></td>';
        echo '<th class="manage-column">' . esc_html__('Preview', 'pic-pilot') . '</th>';
        echo '<th class="manage-column">' . esc_html__('Title', 'pic-pilot') . '</th>';
        echo '<th class="manage-column">' . esc_html__('File', 'pic-pilot') . '</th>';
        echo '<th class="manage-column">' . esc_html__('Backup Type', 'pic-pilot') . '</th>';
        echo '<th class="manage-column">' . esc_html__('Date', 'pic-pilot') . '</th>';
        echo '<th class="manage-column">' . esc_html__('Size', 'pic-pilot') . '</th>';
        echo '<th class="manage-column">' . esc_html__('Actions', 'pic-pilot') . '</th>';
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

                // Generate backup type badges
                $backup_types = $backup['backup_types'] ?? [$backup['backup_type']];
                $type_badges = '';
                foreach ($backup_types as $type) {
                    $badge_class = 'pic-pilot-backup-badge';
                    $badge_text = '';
                    $badge_color = '';
                    
                    switch ($type) {
                        case 'conversion':
                            $badge_text = __('Conversion', 'pic-pilot');
                            $badge_color = 'background: #d63384; color: white;';
                            break;
                        case 'user':
                            $badge_text = __('User', 'pic-pilot');
                            $badge_color = 'background: #0d6efd; color: white;';
                            break;
                        case 'serving':
                            $badge_text = __('Serving', 'pic-pilot');
                            $badge_color = 'background: #198754; color: white;';
                            break;
                        case 'legacy':
                            $badge_text = __('Legacy', 'pic-pilot');
                            $badge_color = 'background: #6c757d; color: white;';
                            break;
                        default:
                            $badge_text = ucfirst($type);
                            $badge_color = 'background: #6c757d; color: white;';
                    }
                    
                    $type_badges .= '<span class="' . $badge_class . '" style="' . $badge_color . ' padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-right: 4px; display: inline-block;">' . esc_html($badge_text) . '</span>';
                }

                echo '<tr>';
                echo '<th scope="row" class="check-column"><input type="checkbox" name="backup_ids[]" value="' . esc_attr($post->ID) . '" class="backup-checkbox"></th>';
                echo '<td>' . $thumb . '</td>';
                echo '<td><a href="' . esc_url($edit_link) . '" target="_blank">' . $title . '</a></td>';
                echo '<td>' . $file . '</td>';
                echo '<td>' . $type_badges . '</td>';
                echo '<td>' . $date . '</td>';
                echo '<td>' . $size . '</td>';
                echo '<td class="individual-actions" data-attachment-id="' . $post->ID . '">';
                // Buttons will be moved here by JavaScript
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="8" style="text-align:center;">' . esc_html__('No backups found.', 'pic-pilot') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</form>';
        
        // Individual action forms (outside bulk form)
        if ($paged_backups) {
            echo '<div id="individual-action-forms" style="display: none;">';
            foreach ($paged_backups as $backup) {
                $post = $backup['post'];
                
                // Restore button logic (copied from above)
                $restored_version  = get_post_meta($post->ID, '_pic_pilot_restore_version', true);
                $optimized_version = get_post_meta($post->ID, '_pic_pilot_optimized_version', true);
                $already_restored = $restored_version && (!$optimized_version || $restored_version >= $optimized_version);
                
                $main_backup_file = $backup['backup_dir'] . ($backup['manifest']['main']['filename'] ?? '');
                $manifest_exists = file_exists($backup['backup_dir'] . 'manifest.json');
                $can_restore = $manifest_exists && file_exists($main_backup_file) && !$already_restored;
                $restore_disabled = !$can_restore ? 'disabled aria-disabled="true" title="' . esc_attr__('Already restored.', 'pic-pilot') . '"' : '';
                $restore_label = $already_restored ? esc_html__('Restored', 'pic-pilot') : esc_html__('Restore', 'pic-pilot');
                $delete_disabled = !$manifest_exists ? 'disabled aria-disabled="true"' : '';
                
                // Individual restore form
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;" class="individual-restore-form" data-attachment-id="' . $post->ID . '">';
                echo wp_nonce_field('pic_pilot_restore_backup_' . $post->ID, '_wpnonce', true, false);
                echo '<input type="hidden" name="action" value="pic_pilot_restore_backup">';
                echo '<input type="hidden" name="attachment_id" value="' . esc_attr($post->ID) . '">';
                echo '<button class="button pic-pilot-m-r-5" ' . $restore_disabled . ' aria-label="' . esc_attr__('Restore original image', 'pic-pilot') . '">' . $restore_label . '</button>';
                echo '</form>';
                
                // Individual delete form  
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;" class="individual-delete-form" data-attachment-id="' . $post->ID . '">';
                echo wp_nonce_field('pic_pilot_delete_backup_' . $post->ID, '_wpnonce', true, false);
                echo '<input type="hidden" name="action" value="pic_pilot_delete_backup">';
                echo '<input type="hidden" name="attachment_id" value="' . esc_attr($post->ID) . '">';
                echo '<button class="button button-danger" ' . $delete_disabled . ' aria-label="' . esc_attr__('Delete backup', 'pic-pilot') . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this backup?', 'pic-pilot')) . '\');">' . esc_html__('Delete', 'pic-pilot') . '</button>';
                echo '</form>';
            }
            echo '</div>';
        }

        // Pagination links
        if ($pages > 1) {
            $base = esc_url_raw(add_query_arg(['paged' => '%#%']));
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo '<span class="displaying-num">' . sprintf(_n('%s item', '%s items', $total, 'pic-pilot'), number_format_i18n($total)) . '</span>';
            echo paginate_links([
                'base'      => $base,
                'format'    => '',
                'current'   => $paged,
                'total'     => $pages,
                'prev_text' => __('‹ Previous'),
                'next_text' => __('Next ›'),
                'type'      => 'list',
            ]);
            echo '</div></div>';
        }

        // JavaScript for bulk actions and search
        echo '<script>
        function handleBulkAction(event) {
            console.log("handleBulkAction called", event);
            
            // Prevent default form submission initially
            if (event) {
                event.preventDefault();
            }
            
            var action = document.getElementById("bulk-action-selector-top").value;
            console.log("Selected action:", action);
            
            if (action === "-1") {
                alert("' . esc_js(__('Please select an action.', 'pic-pilot')) . '");
                return false;
            }
            
            var checked = document.querySelectorAll(".backup-checkbox:checked");
            console.log("Checked items:", checked.length);
            
            if (checked.length === 0) {
                alert("' . esc_js(__('Please select at least one backup.', 'pic-pilot')) . '");
                return false;
            }
            
            var actionValue = "";
            if (action === "test_bulk") {
                actionValue = "pic_pilot_test_bulk";
            } else if (action === "bulk_delete") {
                if (!confirm("' . esc_js(__('Are you sure you want to delete the selected backups? This cannot be undone.', 'pic-pilot')) . '")) {
                    return false;
                }
                actionValue = "pic_pilot_bulk_delete_backups";
            } else if (action === "bulk_restore") {
                if (!confirm("' . esc_js(__('Are you sure you want to restore the selected backups?', 'pic-pilot')) . '")) {
                    return false;
                }
                actionValue = "pic_pilot_bulk_restore_backups";
            }
            
            console.log("Action value to be set:", actionValue);
            
            if (actionValue) {
                document.getElementById("bulk-action-input").value = actionValue;
                console.log("Action input value set to:", document.getElementById("bulk-action-input").value);
                
                // Log form data before submission
                var formData = new FormData(document.getElementById("bulk-backup-form"));
                for (var pair of formData.entries()) {
                    console.log("Form data:", pair[0], pair[1]);
                }
                
                // Now submit the form
                document.getElementById("bulk-backup-form").submit();
            }
            
            return false; // Always prevent default since we handle submission manually
        }
        
        // Bulk action submit handler
        document.getElementById("bulk-action-submit").addEventListener("click", handleBulkAction);
        
        // Also handle form submit to catch any other triggers
        document.getElementById("bulk-backup-form").addEventListener("submit", handleBulkAction);
        
        // Select all checkbox functionality
        document.getElementById("cb-select-all").addEventListener("change", function() {
            var checkboxes = document.querySelectorAll(".backup-checkbox");
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = this.checked;
            }
        });
        
        // Update select all when individual checkboxes change
        document.addEventListener("change", function(e) {
            if (e.target.classList.contains("backup-checkbox")) {
                var all = document.querySelectorAll(".backup-checkbox");
                var checked = document.querySelectorAll(".backup-checkbox:checked");
                document.getElementById("cb-select-all").indeterminate = checked.length > 0 && checked.length < all.length;
                document.getElementById("cb-select-all").checked = checked.length === all.length;
            }
        });
        
        // Move individual action buttons from hidden area to table cells
        document.addEventListener("DOMContentLoaded", function() {
            var individualForms = document.getElementById("individual-action-forms");
            if (individualForms) {
                var restoreForms = individualForms.querySelectorAll(".individual-restore-form");
                var deleteForms = individualForms.querySelectorAll(".individual-delete-form");
                
                restoreForms.forEach(function(form) {
                    var attachmentId = form.getAttribute("data-attachment-id");
                    var targetCell = document.querySelector("td.individual-actions[data-attachment-id=\\"" + attachmentId + "\\"]");
                    if (targetCell) {
                        targetCell.appendChild(form);
                        form.style.display = "inline";
                    }
                });
                
                deleteForms.forEach(function(form) {
                    var attachmentId = form.getAttribute("data-attachment-id");
                    var targetCell = document.querySelector("td.individual-actions[data-attachment-id=\\"" + attachmentId + "\\"]");
                    if (targetCell) {
                        targetCell.appendChild(form);
                        form.style.display = "inline";
                    }
                });
            }
        });
        </script>';
        
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
        // Debug logging
        Logger::log("🔧 RESTORE: Starting restoration process");
        Logger::log_compact("🔧 RESTORE: REQUEST data:", $_REQUEST);
        
        // Accept both GET and POST requests
        $attachment_id = intval($_REQUEST['attachment_id'] ?? 0);
        $backup_type = sanitize_text_field($_REQUEST['backup_type'] ?? '');
        
        Logger::log("🔧 RESTORE: Attachment ID: $attachment_id, Backup Type: $backup_type");
        
        // Verify nonce
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        $expected_nonce = 'pic_pilot_restore_backup_' . $attachment_id;
        
        Logger::log("🔧 RESTORE: Nonce: $nonce, Expected: $expected_nonce");
        
        if (!wp_verify_nonce($nonce, $expected_nonce)) {
            Logger::log("❌ RESTORE: Nonce verification failed");
            wp_die(__('Security check failed. Please try again.', 'pic-pilot'));
        }
        
        Logger::log("✅ RESTORE: Nonce verified successfully");

        if (!current_user_can('manage_options')) {
            Logger::log("❌ RESTORE: User lacks manage_options capability");
            wp_die(__('You do not have permission to restore images.', 'pic-pilot'));
        }

        // Get backup info for debugging
        $backup_info = \PicPilot\Backup\SmartBackupManager::get_backup_info($attachment_id);
        Logger::log_compact("🔍 RESTORE: Available backups for ID $attachment_id:", $backup_info);
        
        // Try new RestoreManager first
        $result = \PicPilot\Backup\RestoreManager::restore_attachment($attachment_id, $backup_type ?: null);
        
        Logger::log("🔍 RESTORE: RestoreManager result - Success: " . ($result->success ? 'YES' : 'NO') . ", Error: " . ($result->error ?: 'None'));
        
        if ($result->success) {
            $success = true;
            Logger::log('✅ RestoreManager restored backup for image ID ' . $attachment_id);
        } else {
            // Only fallback to legacy for user-created backups, not conversion backups
            if (strpos($result->error, 'conversion') === false) {
                Logger::log("🔄 RESTORE: Falling back to legacy BackupService for ID $attachment_id");
                $success = \PicPilot\Backup\BackupService::restore_backup($attachment_id);
                Logger::log(($success ? '✅ Legacy restored' : '❌ Failed to restore') . ' backup for image ID ' . $attachment_id . ' (RestoreManager error: ' . $result->error . ')');
            } else {
                $success = false;
                Logger::log('❌ Conversion backup restoration failed for image ID ' . $attachment_id . ': ' . $result->error);
            }
        }

        // Redirect back to where the user came from
        $redirect_url = wp_get_referer() ?: admin_url('upload.php');
        $redirect_url = add_query_arg(['restored' => ($success ? '1' : '0'), 'attachment_id' => $attachment_id], $redirect_url);
        
        Logger::log("🔄 RESTORE: Redirecting to: $redirect_url");
        wp_redirect($redirect_url);
        exit;
    }

    public static function handle_delete_backup() {
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        check_admin_referer('pic_pilot_delete_backup_' . $attachment_id);

        if (!current_user_can('manage_options')) {
            Logger::log("❌ RESTORE: User lacks manage_options capability");
            wp_die(__('You do not have permission to restore images.', 'pic-pilot'));
        }

        $success = \PicPilot\Backup\BackupService::delete_backup($attachment_id);
        Logger::log(($success ? '🗑️ Deleted' : '❌ Failed to delete') . ' backup for image ID ' . $attachment_id);

        wp_redirect(admin_url('admin.php?page=pic-pilot-backups&deleted=' . ($success ? '1' : '0')));
        exit;
    }

    /**
     * Handle AJAX restoration requests (EWWW-inspired)
     */
    public static function handle_restore_ajax() {
        // Accept both GET and POST requests
        $attachment_id = intval($_REQUEST['attachment_id'] ?? 0);
        $backup_type = sanitize_text_field($_REQUEST['backup_type'] ?? '');
        
        // Verify nonce
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'pic_pilot_restore_backup_' . $attachment_id)) {
            wp_send_json_error(['message' => __('Security check failed. Please try again.', 'pic-pilot')]);
            return;
        }

        if (!current_user_can('upload_files')) {
            Logger::log("❌ AJAX RESTORE: User lacks upload_files capability");
            wp_send_json_error(['message' => __('You do not have permission to restore images.', 'pic-pilot')]);
            return;
        }

        // Try new RestoreManager first
        $result = \PicPilot\Backup\RestoreManager::restore_attachment($attachment_id, $backup_type ?: null);
        
        if ($result->success) {
            Logger::log('✅ AJAX RestoreManager restored backup for image ID ' . $attachment_id);
            wp_send_json_success([
                'message' => __('Image restored successfully!', 'pic-pilot'),
                'data' => $result->data
            ]);
        } else {
            // Only fallback to legacy for user-created backups, not conversion backups
            if (strpos($result->error, 'conversion') === false) {
                $legacy_success = \PicPilot\Backup\BackupService::restore_backup($attachment_id);
                
                if ($legacy_success) {
                    Logger::log('✅ AJAX Legacy restored backup for image ID ' . $attachment_id);
                    wp_send_json_success(['message' => __('Image restored successfully (legacy)!', 'pic-pilot')]);
                } else {
                    Logger::log('❌ AJAX Failed to restore backup for image ID ' . $attachment_id . ': ' . $result->error);
                    wp_send_json_error(['message' => $result->error ?: __('Restoration failed', 'pic-pilot')]);
                }
            } else {
                Logger::log('❌ AJAX Conversion backup restoration failed for image ID ' . $attachment_id . ': ' . $result->error);
                wp_send_json_error(['message' => $result->error ?: __('Conversion backup restoration failed', 'pic-pilot')]);
            }
        }
    }

    /**
     * Handle bulk restore requests
     */
    public static function handle_bulk_restore() {
        Logger::log("🔧 BULK RESTORE: Handler called");
        Logger::log_compact("🔧 BULK RESTORE: POST data:", $_POST);
        
        check_admin_referer('pic_pilot_bulk_backup_action');

        if (!current_user_can('manage_options')) {
            Logger::log("❌ BULK RESTORE: User lacks manage_options capability");
            wp_die(__('You do not have permission to restore images.', 'pic-pilot'));
        }

        $backup_ids = array_map('intval', $_POST['backup_ids'] ?? []);
        if (empty($backup_ids)) {
            wp_redirect(admin_url('admin.php?page=pic-pilot-backups&error=no_selection'));
            exit;
        }

        $restored = 0;
        $failed = 0;

        foreach ($backup_ids as $attachment_id) {
            Logger::log("🔄 BULK RESTORE: Processing attachment ID $attachment_id");
            
            // Try new RestoreManager first
            $result = \PicPilot\Backup\RestoreManager::restore_attachment($attachment_id);
            
            if ($result->success) {
                $restored++;
                Logger::log("✅ BULK RESTORE: Successfully restored attachment ID $attachment_id");
            } else {
                // Fallback to legacy for non-conversion backups
                if (strpos($result->error, 'conversion') === false) {
                    $legacy_success = \PicPilot\Backup\BackupService::restore_backup($attachment_id);
                    if ($legacy_success) {
                        $restored++;
                        Logger::log("✅ BULK RESTORE: Legacy restored attachment ID $attachment_id");
                    } else {
                        $failed++;
                        Logger::log("❌ BULK RESTORE: Failed to restore attachment ID $attachment_id");
                    }
                } else {
                    $failed++;
                    Logger::log("❌ BULK RESTORE: Failed to restore conversion backup for attachment ID $attachment_id");
                }
            }
        }

        $redirect_url = admin_url('admin.php?page=pic-pilot-backups&bulk_restored=' . $restored . '&bulk_failed=' . $failed);
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Handle bulk delete requests
     */
    public static function handle_bulk_delete() {
        Logger::log("🔧 BULK DELETE: Handler called");
        Logger::log_compact("🔧 BULK DELETE: POST data:", $_POST);
        
        check_admin_referer('pic_pilot_bulk_backup_action');

        if (!current_user_can('manage_options')) {
            Logger::log("❌ BULK DELETE: User lacks manage_options capability");
            wp_die(__('You do not have permission to delete backups.', 'pic-pilot'));
        }

        $backup_ids = array_map('intval', $_POST['backup_ids'] ?? []);
        if (empty($backup_ids)) {
            wp_redirect(admin_url('admin.php?page=pic-pilot-backups&error=no_selection'));
            exit;
        }

        $deleted = 0;
        $failed = 0;

        foreach ($backup_ids as $attachment_id) {
            Logger::log("🗑️ BULK DELETE: Processing attachment ID $attachment_id");
            
            // Delete from legacy system
            $legacy_success = \PicPilot\Backup\BackupService::delete_backup($attachment_id);
            
            // Delete from SmartBackupManager system
            $smart_success = \PicPilot\Backup\SmartBackupManager::delete_all_backups($attachment_id);
            
            if ($legacy_success || $smart_success) {
                $deleted++;
                Logger::log("✅ BULK DELETE: Successfully deleted backups for attachment ID $attachment_id");
            } else {
                $failed++;
                Logger::log("❌ BULK DELETE: Failed to delete backups for attachment ID $attachment_id");
            }
        }

        $redirect_url = admin_url('admin.php?page=pic-pilot-backups&bulk_deleted=' . $deleted . '&bulk_failed=' . $failed);
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Handle AJAX search requests
     */
    public static function handle_search_ajax() {
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'pic_pilot_search_backups')) {
            wp_send_json_error(['message' => __('Security check failed.', 'pic-pilot')]);
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('You do not have permission to search backups.', 'pic-pilot')]);
            return;
        }

        $search = sanitize_text_field($_REQUEST['search'] ?? '');
        $backup_type_filter = sanitize_text_field($_REQUEST['backup_type'] ?? '');
        $page = max(1, intval($_REQUEST['page'] ?? 1));
        $per_page = max(10, min(100, intval($_REQUEST['per_page'] ?? self::DEFAULT_PER_PAGE)));

        // This would return the filtered results as JSON
        // Implementation would be similar to render_backup_page but return JSON
        wp_send_json_success([
            'search' => $search,
            'backup_type' => $backup_type_filter,
            'page' => $page,
            'per_page' => $per_page,
            'message' => __('AJAX search functionality ready for implementation', 'pic-pilot')
        ]);
    }

    /**
     * Test handler to debug admin-post.php
     */
    public static function test_bulk_handler() {
        Logger::log("🧪 TEST BULK: Handler called");
        Logger::log_compact("🧪 TEST BULK: POST data:", $_POST);
        Logger::log_compact("🧪 TEST BULK: GET data:", $_GET);
        Logger::log_compact("🧪 TEST BULK: REQUEST data:", $_REQUEST);
        
        // Don't check nonce for test - just die with success
        wp_die("Test handler called successfully! Check logs for data.");
    }
}

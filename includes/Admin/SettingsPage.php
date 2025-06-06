<?php

namespace PicPilot\Admin;

use PicPilot\Settings;

//Register settings menu
class SettingsPage {
    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_action('admin_init', [self::class, 'register_settings']);
    }
    public static function enqueue_admin_assets($hook) {
        // Load only on Media Library and Pic Pilot admin pages
        if (!in_array($hook, ['upload.php', 'post.php', 'post-new.php', 'toplevel_page_pic-pilot'])) {
            return;
        }

        wp_enqueue_style(
            'pic-pilot-admin',
            plugin_dir_url(__DIR__) . '../assets/css/admin.css',
            [],
            PIC_PILOT_VERSION
        );
    }
    //Register the settings menu
    public static function register_menu() {
        add_menu_page(
            __('Pic Pilot Settings', 'pic-pilot'),
            'Pic Pilot',
            'manage_options', // Only allow admins
            'pic-pilot',
            [self::class, 'render_settings_page'],
            'dashicons-format-image',
            80
        );
        add_submenu_page(
            'pic-pilot',                        // Parent menu slug (must match top-level 'pic-pilot')
            __('Settings', 'pic-pilot'),        // Page <title> (shown in browser tab)
            __('Settings', 'pic-pilot'),        // Menu label (shown in sidebar)
            'read',
            'pic-pilot',                      // Submenu slug (same as parent — required!)
            [self::class, 'render_settings_page']
        );
        // Add Backup Manager submenu, only if backup is enabled
        if (Settings::is_backup_enabled()) {
            add_submenu_page(
                'pic-pilot', // Parent slug
                __('Backup Manager', 'pic-pilot'), // Page <title>
                __('Backup Manager', 'pic-pilot'), // Menu label
                'manage_options',
                'pic-pilot-backups', // Slug for this submenu
                ['\\PicPilot\\Backup\\BackupManager', 'render_backup_page'] // Callback to render the UI
            );
        }
    }

    public static function render_settings_page() {
        // Tab logic
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $tabs = [
            'general'   => __('General Settings', 'pic-pilot'),
            'advanced'  => __('Advanced', 'pic-pilot'),
            'tools'     => __('Tools', 'pic-pilot'),
            'docs'      => __('Documentation', 'pic-pilot')
        ];

        echo '<div class="wrap pic-pilot-settings">';
        echo '<h1>' . esc_html__('Pic Pilot Settings', 'pic-pilot') . '</h1>';
        // Tabs
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $tab_key => $tab_name) {
            $active = ($tab === $tab_key) ? ' nav-tab-active' : '';
            $url = esc_url(add_query_arg('tab', $tab_key));
            echo '<a href="' . $url . '" class="nav-tab' . $active . '">' . esc_html($tab_name) . '</a>';
        }
        echo '</nav>';
        echo '<div class="pic-pilot-settings-tab-content">';
        switch ($tab) {
            case 'advanced':
                self::render_advanced_tab();
                break;
            case 'tools':
                self::render_tools_tab();
                break;
            case 'docs':
                self::render_docs_tab();
                break;
            case 'general':
            default:
                self::render_general_tab();
                break;
        }
        echo '</div>';
        echo '</div>';
    }

    protected static function render_general_tab() {
        self::render_server_capabilities();
        echo '<h2>' . esc_html__('General Settings', 'pic-pilot') . '</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('pic_pilot_settings_group');
        do_settings_sections('pic-pilot');
        submit_button();
        echo '</form>';
    }

    protected static function render_advanced_tab() {
        echo '<h2>' . esc_html__('Advanced Settings', 'pic-pilot') . '</h2>';
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Warning: Advanced options can affect all images and backups. Proceed with caution.', 'pic-pilot') . '</p></div>';
        echo '<form method="post" action="options.php">';
        do_settings_sections('pic-pilot-advanced');
        submit_button();
        echo '</form>';
    }

    protected static function render_tools_tab() {
        echo '<h2>' . esc_html__('Image Tools', 'pic-pilot') . '</h2>';
        echo '<div class="notice notice-info"><p>'
            . esc_html__('More image tools coming soon (add alt text, duplicate images, etc).', 'pic-pilot') . '</p></div>';
        do_settings_sections('pic-pilot-tools');
    }

    protected static function render_docs_tab() {
        echo '<h2>' . esc_html__('Documentation', 'pic-pilot') . '</h2>';
        echo '<div class="pic-pilot-docs">';
        echo wpautop(esc_html__('Pic Pilot lets you optimize, backup, and restore images with maximum control. Every optimization is routed through a safe workflow that guarantees a backup is created before your images are compressed, so you can always revert.\n\nBackups include the scaled image and all WordPress-generated thumbnails for each Media Library attachment. (The original upload is only backed up if it is still used by WordPress.) Restoring, re-optimizing, or repeating these actions may result in slightly different file sizes due to lossy compression and format conversions.\n\nBackups can double disk usage for each image. Always monitor available space, and use the Backup Manager to delete unused backups or originals as needed.', 'pic-pilot'));
        echo '</div>';
    }

    // Extra: Server Capabilities Section
    public static function render_server_capabilities() {
        $cap = \PicPilot\Settings::get_capabilities();

        echo '<h2 style="margin-top:40px;">' . esc_html__('Server Capabilities', 'pic-pilot') . '</h2>';
        echo '<ul>';
        echo '<li><strong>Imagick:</strong> ' . ($cap['imagick'] ? '✅ ' . __('Available', 'pic-pilot') : '❌ ' . __('Missing', 'pic-pilot')) . '</li>';
        echo '<li><strong>GD:</strong> ' . ($cap['gd'] ? '✅ ' . __('Available', 'pic-pilot') : '❌ ' . __('Missing', 'pic-pilot')) . '</li>';
        echo '<li><strong>WebP Support:</strong> ' . ($cap['webp'] ? '✅ ' . __('Enabled', 'pic-pilot') : '❌ ' . __('Not Supported', 'pic-pilot')) . '</li>';
        echo '</ul>';

        if (!$cap['imagick'] && !$cap['gd']) {
            echo '<p style="color:red;"><strong>' . esc_html__('Warning: No image libraries found. JPEG compression will not work on this server.', 'pic-pilot') . '</strong></p>';
        } elseif (!$cap['webp']) {
            echo '<p style="color:orange;"><strong>' . esc_html__('Note: WebP conversion will not work until WebP support is available in GD or Imagick.', 'pic-pilot') . '</strong></p>';
        }
    }

    //Register settings and fields

    public static function register_settings() {
        register_setting('pic_pilot_settings_group', 'pic_pilot_options');

        add_settings_section(
            'pic_pilot_main',
            __('Image Optimization Settings', 'pic-pilot'),
            null,
            'pic-pilot'
        );

        add_settings_field(
            'enable_jpeg',
            __('Enable JPEG Compression', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'enable_jpeg']
        );

        add_settings_field(
            'jpeg_quality',
            __('Compression Level', 'pic-pilot'),
            [self::class, 'render_quality_dropdown'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'jpeg_quality']
        );

        add_settings_field(
            'enable_backup',
            __('Backup Originals', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'enable_backup']
        );
        add_settings_field(
            'auto_optimize_uploads',
            __('Compress on Upload', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'auto_optimize_uploads']
        );
        add_settings_section(
            'pic_pilot_tinypng',
            __('TinyPNG Integration', 'pic-pilot'),

            function () {
                echo '<p>' . esc_html__('Compress PNGs using the TinyPNG API. Recommended for best results.', 'pic-pilot') . '</p>';
                echo '<p><strong>' . esc_html__('Note:', 'pic-pilot') . '</strong> ' .
                    esc_html__('Free TinyPNG accounts are limited to 500 images per month and 5MB per image.', 'pic-pilot') .
                    '</p>';
            },
            'pic-pilot'
        );

        add_settings_field(
            'enable_tinypng',
            __('Enable TinyPNG for PNG compression', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_tinypng',
            ['label_for' => 'enable_tinypng']
        );

        add_settings_field(
            'tinypng_api_key',
            __('TinyPNG API Key', 'pic-pilot'),
            [self::class, 'render_text_input'],
            'pic-pilot',
            'pic_pilot_tinypng',
            ['label_for' => 'tinypng_api_key']
        );

        add_settings_field(
            'use_tinypng_for_jpeg',
            __('Use TinyPNG for JPEGs too?', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_tinypng',
            ['label_for' => 'use_tinypng_for_jpeg']
        );
    }
    public static function render_text_input($args) {
        $options = get_option('pic_pilot_options', []);
        $value = esc_attr($options[$args['label_for']] ?? '');
        echo "<input type='text' id='{$args['label_for']}' name='pic_pilot_options[{$args['label_for']}]' value='$value' class='regular-text' />";
    }

    public static function render_checkbox($args) {
        $options = get_option('pic_pilot_options', []);
        $checked = !empty($options[$args['label_for']]) ? 'checked' : '';
        echo "<input type='checkbox' id='{$args['label_for']}' name='pic_pilot_options[{$args['label_for']}]' value='1' $checked />";
    }

    public static function render_quality_dropdown($args) {

        $options = get_option('pic_pilot_options', []);
        $selected = $options['jpeg_quality'] ?? '80';

        echo "<select id='jpeg_quality' name='pic_pilot_options[jpeg_quality]'>";
        echo "<option value='100'" . selected($selected, '100', false) . ">" . __('Lossless (100)', 'pic-pilot') . "</option>";
        echo "<option value='80'" . selected($selected, '80', false) . ">" . __('Good (80)', 'pic-pilot') . "</option>";
        echo "<option value='60'" . selected($selected, '60', false) . ">" . __('Maximum Savings (60)', 'pic-pilot') . "</option>";
        echo "</select>";
    }
}

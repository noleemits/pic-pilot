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
        wp_enqueue_script(
            'pic-pilot-admin',
            plugin_dir_url(__FILE__) . '../../assets/js/admin.js',
            [],
            PIC_PILOT_VERSION,
            true
        );
    }

    //Register the settings menu
    public static function register_menu() {
        add_menu_page(
            __('Pic Pilot Settings', 'pic-pilot'),
            'Pic Pilot',
            'manage_options',
            'pic-pilot',
            [self::class, 'render_settings_page'],
            'dashicons-format-image',
            80
        );
        add_submenu_page(
            'pic-pilot',
            __('Settings', 'pic-pilot'),
            __('Settings', 'pic-pilot'),
            'read',
            'pic-pilot',
            [self::class, 'render_settings_page']
        );
        // Add Backup Manager submenu, only if backup is enabled
        if (Settings::is_backup_enabled()) {
            add_submenu_page(
                'pic-pilot',
                __('Backup Manager', 'pic-pilot'),
                __('Backup Manager', 'pic-pilot'),
                'manage_options',
                'pic-pilot-backups',
                ['\\PicPilot\\Backup\\BackupManager', 'render_backup_page']
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
        echo '<div class="pic-pilot-settings-columns">';
        echo '<div class="pic-pilot-col-main">';
        echo '<h2>' . esc_html__('General Settings', 'pic-pilot') . '</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('pic_pilot_settings_group');
        do_settings_sections('pic-pilot');
        submit_button();
        echo '</form>';
        echo '</div>';
        echo '<div class="pic-pilot-col-side">';
        self::render_server_capabilities();
        $warn_path = PIC_PILOT_DIR . 'includes/partials/settings-warnings.php';
        if (file_exists($warn_path)) {
            include $warn_path;
        }
        $backup_info_path = PIC_PILOT_DIR . 'includes/partials/settings-backup-info.php';
        if (file_exists($backup_info_path)) {
            include $backup_info_path;
        }
        echo '</div>';
        echo '</div>';
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
        echo '<h2>' . esc_html__('TinyPNG API Usage', 'pic-pilot') . '</h2>';
        echo '<h2>' . esc_html__('Image Tools', 'pic-pilot') . '</h2>';
        echo '<div class="notice notice-info"><p>'
            . esc_html__('More image tools coming soon (add alt text, duplicate images, etc).', 'pic-pilot') . '</p></div>';
        do_settings_sections('pic-pilot-tools');
    }

    protected static function render_docs_tab() {
        $docs_path = PIC_PILOT_DIR . 'includes/partials/docs-admin.php';
        if (file_exists($docs_path)) {
            echo '<div class="pic-pilot-docs">';
            include $docs_path;
            echo '</div>';
        } else {
            echo '<p>' . esc_html__('Documentation not found.', 'pic-pilot') . '</p>';
        }
    }

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

    public static function register_settings() {
        // Register the main settings group
        register_setting('pic_pilot_settings_group', 'pic_pilot_options');

        // Main settings section for general image optimization
        add_settings_section(
            'pic_pilot_main',
            __('Image Optimization Settings', 'pic-pilot'),
            null,
            'pic-pilot'
        );

        // Resize on upload checkbox
        add_settings_field(
            'resize_on_upload',
            __('Resize large images on upload', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'resize_on_upload']
        );

        // Max width field (only shows if resize enabled, see JS below)
        add_settings_field(
            'resize_max_width',
            __('Maximum width (pixels)', 'pic-pilot'),
            [self::class, 'render_resize_width_input'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'resize_max_width']
        );

        // Enable JPEG Compression
        add_settings_field(
            'enable_jpeg',
            __('Enable JPEG Compression', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'enable_jpeg']
        );

        // JPEG Compression Quality Dropdown
        add_settings_field(
            'jpeg_quality',
            __('Compression Level', 'pic-pilot'),
            [self::class, 'render_quality_dropdown'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'jpeg_quality']
        );

        // Enable Backup of Original Images
        add_settings_field(
            'enable_backup',
            __('Backup Originals', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'enable_backup']
        );

        // Automatically Optimize Images on Upload
        add_settings_field(
            'optimize_on_upload',
            __('Compress on Upload', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'optimize_on_upload']
        );

        //Enable logging
        add_settings_field(
            'enable_logging',
            __('Enable Logging', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_main',
            ['label_for' => 'enable_logging']
        );

        // Section for Compression Engines
        add_settings_section(
            'pic_pilot_compression_engines',
            __('Image Compression Engines', 'pic-pilot'),
            function () {
                echo '<p>' . esc_html__(
                    "For best results, we recommend using external image compression APIs for PNGs. Local compression is not available for PNG files, but you can choose your preferred external tool below. Pic Pilot is designed to let you use top-tier compression at the lowest possible cost. We recommend TinyPNG for PNGs, but you can also enable external tools for JPEG and WebP images if desired.",
                    'pic-pilot'
                ) . '</p>';
            },
            'pic-pilot'
        );

        // PNG Compression Engine Dropdown
        add_settings_field(
            'png_engine',
            __('Select PNG Compression Engine', 'pic-pilot'),
            [self::class, 'render_png_engine_dropdown'],
            'pic-pilot',
            'pic_pilot_compression_engines'
        );

        // TinyPNG Section (wrapped in a container with a class for toggling visibility)
        add_settings_section(
            'pic_pilot_tinypng',
            '',
            function () {
                echo '<div class="pic-pilot-tinypng-section">';

                // TinyPNG API Key Input
                echo '<div class="pic-pilot-tinypng-api-row">';
                self::render_text_input(['label_for' => 'tinypng_api_key']);
                echo '</div>';

                // Use TinyPNG for JPEG Checkbox - now on its own line
                echo '<div class="pic-pilot-tinypng-jpeg-row" style="margin-top:15px;">';
                self::render_checkbox(['label_for' => 'use_tinypng_for_jpeg']);
                echo '<label for="use_tinypng_for_jpeg">' . esc_html__('Use TinyPNG for JPEGs too?', 'pic-pilot') . '</label>';
                echo '</div>';

                // Disclaimer text
                echo '<div class="pic-pilot-tinypng-disclaimer" style="margin-top:15px;color:#555;">'
                    . esc_html__("To check if your API key is valid, compress an image. If the operation succeeds, your key is valid. TinyPNG no longer supports API key validation or quota checks via their API. To verify your key, compress an image or visit your TinyPNG dashboard.", 'pic-pilot')
                    . ' <a href="https://tinypng.com/dashboard" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Open Dashboard', 'pic-pilot') . '</a>.<br>'
                    . '<strong>' . esc_html__('Note:', 'pic-pilot') . '</strong> '
                    . esc_html__('Free TinyPNG accounts are limited to 500 images per month and 5MB per image.', 'pic-pilot')
                    . '</div>';
                echo '</div>';
            },
            'pic-pilot'
        );
    }
    public static function render_resize_width_input($args) {
        $options = get_option('pic_pilot_options', []);
        $resize_enabled = !empty($options['resize_on_upload']);
        $value = esc_attr($options['resize_max_width'] ?? '2048');
        $style = $resize_enabled ? '' : 'display:none;';
        echo "<input type='number' min='300' max='8000' step='1' id='{$args['label_for']}' name='pic_pilot_options[{$args['label_for']}]' value='$value' style='width:80px;$style' />";
        echo '<span style="margin-left:8px;color:#777;">' . esc_html__('Leave blank to use WordPress default (2048)', 'pic-pilot') . '</span>';
    }


    public static function render_text_input($args) {
        $options = get_option('pic_pilot_options', []);
        $value = esc_attr($options[$args['label_for']] ?? '');
        $type = $args['label_for'] === 'tinypng_api_key' ? 'password' : 'text';
        echo "<input type='$type' id='{$args['label_for']}' name='pic_pilot_options[{$args['label_for']}]' value='$value' class='regular-text' autocomplete='off' />";
    }

    public static function render_png_engine_dropdown() {
        $options = get_option('pic_pilot_options', []);
        $selected = $options['png_engine'] ?? '';

        echo "<select id='png_engine' name='pic_pilot_options[png_engine]' class='pic-pilot-engine-select'>";
        echo "<option value='' " . (empty($selected) ? 'selected' : '') . ">" . esc_html__('Choose your preferred API…', 'pic-pilot') . "</option>";
        echo "<option value='tinypng'" . selected($selected, 'tinypng', false) . ">" . esc_html__('TinyPNG (Recommended)', 'pic-pilot') . "</option>";
        echo "</select>";
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

    public static function render_checkbox($args) {
        $options = get_option('pic_pilot_options', []);
        $checked = isset($options[$args['label_for']]) ? checked(1, $options[$args['label_for']], false) : '';
        echo "<input type='checkbox' id='{$args['label_for']}' name='pic_pilot_options[{$args['label_for']}]' value='1' $checked />";
        // Add explanation below the resize checkbox
        if ($args['label_for'] === 'resize_on_upload') {
            echo '<div class="pic-pilot-resize-description" style="margin-top:8px;color:#555;">'
                . esc_html__("WordPress automatically scales down very large images, but the original full-size image is kept and often not optimized. When enabled, Pic Pilot will resize any image above the WordPress maximum size, making sure your original upload is optimized and saving disk space. You can also set your own maximum width (pixels); leave blank to use the WordPress default.", 'pic-pilot')
                . '</div>';
        }
    }
}

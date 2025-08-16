<?php

namespace PicPilot\Admin;

use PicPilot\Settings;
use PicPilot\Admin\FormHelper;


//Register settings menu
class SettingsPage {
    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function enqueue_admin_assets($hook) {
        // Load only on Media Library and Pic Pilot admin pages
        if (!in_array($hook, ['upload.php', 'post.php', 'post-new.php', 'pic-pilot_page_pic-pilot-bulk', 'toplevel_page_pic-pilot'])) {
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

        // Localize admin.js with AJAX nonce and optional bulk IDs
        wp_localize_script('pic-pilot-admin', 'pic_pilot_admin', [
            'nonce' => wp_create_nonce('pic_pilot_nonce'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'bulk_ids' => [], // for now empty; dynamically filled by JS
        ]);
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

        if (\PicPilot\Settings::is_enabled('enable_logging')) {
            \PicPilot\Admin\LogViewer::register();
        }
    }

    public static function render_settings_page() {
        echo '<div class="wrap pic-pilot-settings">';
        echo '<h1>' . esc_html__('Pic Pilot Settings', 'pic-pilot') . '</h1>';
        echo '<p>' . esc_html__('Configure your image optimization settings below. Click on each section to expand and configure options.', 'pic-pilot') . '</p>';
        
        echo '<div class="pic-pilot-settings-columns">';
        echo '<div class="pic-pilot-col-main">';
        
        // Single form with all settings
        echo '<form method="post" action="options.php">';
        settings_fields('pic_pilot_settings_group');
        
        // All sections will be rendered with accordion styling
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
        echo '</div>';
    }



    public static function render_server_capabilities() {
        $cap = \PicPilot\Settings::get_capabilities();

        echo '<h2 style="margin-top:40px;">' . esc_html__('Server Capabilities', 'pic-pilot') . '</h2>';
        echo '<ul>';
        echo '<li><strong>Imagick:</strong> ' . ($cap['imagick'] ? '✅ ' . __('Available', 'pic-pilot') : '❌ ' . __('Missing', 'pic-pilot')) . '</li>';
        echo '<li><strong>GD:</strong> ' . ($cap['gd'] ? '✅ ' . __('Available', 'pic-pilot') : '❌ ' . __('Missing', 'pic-pilot')) . '</li>';
        echo '<li><strong>PNG Compression:</strong> ' . ($cap['png_compression'] ? '✅ ' . sprintf(__('Available via %s', 'pic-pilot'), $cap['png_compression']) : '❌ ' . __('Not Supported', 'pic-pilot')) . '</li>';
        echo '<li><strong>WebP Support:</strong> ' . ($cap['webp'] ? '✅ ' . __('Enabled', 'pic-pilot') : '❌ ' . __('Not Supported', 'pic-pilot')) . '</li>';
        echo '</ul>';

        if (!$cap['imagick'] && !$cap['gd']) {
            echo '<p style="color:red;"><strong>' . esc_html__('Warning: No image libraries found. Local compression will not work on this server.', 'pic-pilot') . '</strong></p>';
        } else {
            if (!$cap['png_compression']) {
                echo '<p style="color:orange;"><strong>' . esc_html__('Note: PNG compression requires GD or Imagick libraries with PNG support. External APIs recommended for PNG files.', 'pic-pilot') . '</strong></p>';
            }
            if (!$cap['webp']) {
                echo '<p style="color:orange;"><strong>' . esc_html__('Note: WebP conversion requires WebP support in GD or Imagick.', 'pic-pilot') . '</strong></p>';
            }
        }
    }

    public static function register_settings() {
        register_setting('pic_pilot_settings_group', 'pic_pilot_options', ['sanitize_callback' => [self::class, 'sanitize_options']]);

        // =======================================
        // 1. CORE SETTINGS
        // =======================================
        add_settings_section(
            'pic_pilot_core',
            __('Core Settings', 'pic-pilot'),
            function () {
                echo '<p>' . esc_html__('Essential settings for image optimization.', 'pic-pilot') . '</p>';
            },
            'pic-pilot'
        );

        FormHelper::checkbox('enable_backup', __('Backup Originals', 'pic-pilot'), 'pic_pilot_core');
        FormHelper::checkbox('enable_logging', __('Enable Logging', 'pic-pilot'), 'pic_pilot_core');

        // =======================================
        // 2. UPLOAD PROCESSING
        // =======================================
        add_settings_section(
            'pic_pilot_upload_processing',
            __('Upload Processing', 'pic-pilot'),
            function () {
                echo '<p>' . esc_html__('Control how images are processed when uploaded to your site.', 'pic-pilot') . '</p>';
                
                echo '<div class="notice notice-info inline" style="margin: 15px 0;">';
                echo '<h4 style="margin-top: 0;">' . __('Quick Setup Guide:', 'pic-pilot') . '</h4>';
                echo '<p><strong>' . __('For WebP conversion on upload:', 'pic-pilot') . '</strong></p>';
                echo '<ol style="margin-left: 20px;">';
                echo '<li>' . __('Set Upload Processing Mode to "Convert formats"', 'pic-pilot') . '</li>';
                echo '<li>' . __('Enable "Convert to WebP" below', 'pic-pilot') . '</li>';
                echo '<li>' . __('Choose your preferred WebP quality and engine', 'pic-pilot') . '</li>';
                echo '</ol>';
                echo '</div>';
            },
            'pic-pilot'
        );

        // Upload Processing Mode (main setting)
        FormHelper::radio(
            'upload_mode',
            __('Upload Processing Mode', 'pic-pilot'),
            [
                'disabled' => __('🚫 Disabled', 'pic-pilot') . '<br><small style="color: #666;">' . __('No automatic processing on upload', 'pic-pilot') . '</small>',
                'compress' => __('🗜️ Compress Same Formats', 'pic-pilot') . '<br><small style="color: #666;">' . __('JPEG → JPEG, PNG → PNG (optimizes without changing format)', 'pic-pilot') . '</small>',
                'convert' => __('🔄 Convert to WebP', 'pic-pilot') . '<br><small style="color: #666;">' . __('JPEG/PNG/GIF → WebP (modern format for better compression)', 'pic-pilot') . '</small>',
            ],
            'pic_pilot_upload_processing',
            [
                'description' => __('<div class="notice notice-info inline"><p><strong>🎯 Choose your optimization strategy:</strong><br>• <strong>Compress</strong>: Best for JPEG files that need quality reduction<br>• <strong>Convert</strong>: Best for converting all image types to modern WebP format<br>• <strong>Disabled</strong>: Process images manually using "Optimize Now" button</p></div>', 'pic-pilot'),
            ]
        );

        // =======================================
        // 2A. COMPRESSION MODE SETTINGS
        // =======================================
        add_settings_section(
            'pic_pilot_compression_mode',
            __('🗜️ Compression Mode Settings', 'pic-pilot'),
            function () {
                echo '<div class="notice notice-warning inline"><p>';
                echo '<strong>' . esc_html__('⚙️ Compression Mode Settings:', 'pic-pilot') . '</strong><br>';
                echo esc_html__('These settings only work when Upload Processing Mode is set to "Compress Same Formats". They will be disabled otherwise.', 'pic-pilot');
                echo '</p></div>';
                echo '<p>' . esc_html__('Compression mode keeps the same file format but reduces file size through quality adjustments and metadata removal.', 'pic-pilot') . '</p>';
            },
            'pic-pilot'
        );

        FormHelper::radio(
            'compression_engine',
            __('Compression Engine', 'pic-pilot'),
            [
                'local' => __('Local (server CPU, free)', 'pic-pilot'),
                'tinypng' => __('TinyPNG API (external, paid)', 'pic-pilot'),
            ],
            'pic_pilot_compression_mode',
            [
                'description' => __('Choose compression method for same-format optimization.', 'pic-pilot'),
            ]
        );

        add_settings_field(
            'jpeg_quality',
            __('JPEG Quality', 'pic-pilot'),
            [self::class, 'render_quality_dropdown'],
            'pic-pilot',
            'pic_pilot_compression_mode',
            ['label_for' => 'jpeg_quality']
        );

        FormHelper::checkbox(
            'resize_during_compression',
            __('Resize images during compression', 'pic-pilot'),
            'pic_pilot_compression_mode',
            [
                'description' => __('Automatically resize large images during compression.', 'pic-pilot'),
            ]
        );

        FormHelper::input(
            'compression_max_width',
            __('Maximum width for compression (pixels)', 'pic-pilot'),
            'number',
            'pic_pilot_compression_mode',
            [
                'placeholder' => '2048',
                'description' => __('Images wider than this will be resized during compression.', 'pic-pilot'),
            ]
        );

        FormHelper::radio(
            'keep_original_after_compression_resize',
            __('Original image handling (compression resize)', 'pic-pilot'),
            [
                '0' => __('Delete original (save storage space)', 'pic-pilot'),
                '1' => __('Keep original (preserve full quality)', 'pic-pilot')
            ],
            'pic_pilot_compression_mode',
            [
                'description' => __('Choose whether to keep or delete the original large image after resizing during compression.', 'pic-pilot'),
            ]
        );

        FormHelper::checkbox(
            'convert_png_to_jpeg_in_compress_mode',
            __('Convert opaque PNG to JPEG in compression mode', 'pic-pilot'),
            'pic_pilot_compression_mode',
            [
                'description' => __('When compressing, also convert PNG files without transparency to JPEG for better compression. The JPEG will then be compressed for maximum optimization.', 'pic-pilot'),
            ]
        );

        // =======================================
        // 2B. CONVERSION MODE SETTINGS
        // =======================================
        add_settings_section(
            'pic_pilot_conversion_mode',
            __('🔄 WebP Conversion Settings', 'pic-pilot'),
            function () {
                echo '<div class="notice notice-success inline"><p>';
                echo '<strong>' . esc_html__('🎯 WebP Conversion Settings:', 'pic-pilot') . '</strong><br>';
                echo esc_html__('These settings only work when Upload Processing Mode is set to "Convert to WebP". They will be disabled otherwise.', 'pic-pilot');
                echo '</p></div>';
                echo '<p>' . esc_html__('WebP conversion converts JPEG, PNG, and GIF images to the modern WebP format for superior compression and quality. Perfect for all image types!', 'pic-pilot') . '</p>';
            },
            'pic-pilot'
        );

        // Format Conversion Settings (for convert mode)
        $webp_support = Settings::get_capabilities()['webp'];
        if ($webp_support) {
            // WebP conversion status field
            add_settings_field(
                'webp_status',
                '',
                function() {
                    echo '<p><strong>' . __('✅ WebP Conversion Enabled', 'pic-pilot') . '</strong><br>';
                    echo '<small>' . __('When conversion mode is active, all compatible images (JPEG, PNG, GIF) will be converted to WebP format automatically.', 'pic-pilot') . '</small></p>';
                },
                'pic-pilot',
                'pic_pilot_conversion_mode'
            );

            FormHelper::radio(
                'webp_engine',
                __('WebP Conversion Engine', 'pic-pilot'),
                [
                    'local' => __('Local (server CPU, free)', 'pic-pilot'),
                    'tinypng' => __('TinyPNG API (external, paid)', 'pic-pilot'),
                ],
                'pic_pilot_conversion_mode',
                [
                    'description' => __('Choose WebP conversion method.', 'pic-pilot'),
                ]
            );

            add_settings_field(
                'webp_quality',
                __('WebP Quality', 'pic-pilot'),
                [self::class, 'render_webp_quality_dropdown'],
                'pic-pilot',
                'pic_pilot_conversion_mode',
                ['label_for' => 'webp_quality']
            );
        }


        FormHelper::checkbox(
            'resize_during_conversion',
            __('Resize images during conversion', 'pic-pilot'),
            'pic_pilot_conversion_mode',
            [
                'description' => __('Automatically resize large images during format conversion. <div class="notice notice-warning inline" style="margin: 10px 0;"><p><strong>⚠️ Quality Warning:</strong> Resizing before WebP conversion reduces image quality due to double processing (resize→convert). For best quality: disable this option and resize images before uploading, or use higher WebP quality settings (85-95).</p></div>', 'pic-pilot'),
            ]
        );

        FormHelper::input(
            'conversion_max_width',
            __('Maximum width for conversion (pixels)', 'pic-pilot'),
            'number',
            'pic_pilot_conversion_mode',
            [
                'placeholder' => '2048',
                'description' => __('Images wider than this will be resized during conversion.', 'pic-pilot'),
            ]
        );

        FormHelper::radio(
            'keep_original_after_conversion_resize',
            __('Original image handling (conversion resize)', 'pic-pilot'),
            [
                '0' => __('Delete original (save storage space)', 'pic-pilot'),
                '1' => __('Keep original (preserve full quality)', 'pic-pilot')
            ],
            'pic_pilot_conversion_mode',
            [
                'description' => __('Choose whether to keep or delete the original large image after resizing during conversion.', 'pic-pilot'),
            ]
        );



        // =======================================
        // 3. OPTIMIZATION & BULK PROCESSING
        // =======================================
        add_settings_section(
            'pic_pilot_optimization_bulk',
            __('Optimization & Bulk Processing', 'pic-pilot'),
            function () {
                echo '<p>' . esc_html__('Settings for when you optimize existing images using "Optimize Now" or bulk optimization.', 'pic-pilot') . '</p>';
                echo '<div class="notice notice-info inline"><p>';
                echo '<strong>' . esc_html__('Perfect for cleanup:', 'pic-pilot') . '</strong> ';
                echo esc_html__('If you have existing large images from before installing this plugin, enable these settings to resize them during optimization.', 'pic-pilot');
                echo '</p></div>';
            },
            'pic-pilot'
        );

        FormHelper::checkbox(
            'resize_during_optimization',
            __('Resize oversized images during optimization', 'pic-pilot'),
            'pic_pilot_optimization_bulk',
            [
                'description' => __('When using "Optimize Now" or bulk optimization, resize images that exceed the maximum width. Perfect for cleaning up existing large images.', 'pic-pilot'),
            ]
        );

        FormHelper::input('optimization_max_width', __('Maximum Width for Optimization (pixels)', 'pic-pilot'), 'number', 'pic_pilot_optimization_bulk', [
            'placeholder'   => '2048',
            'description'   => __('During optimization, images wider than this will be resized. Leave blank to use the same value as upload resize.', 'pic-pilot'),
            'wrapper_class' => 'pp-optimization-resize-conditional',
        ]);

        FormHelper::radio(
            'keep_original_after_optimization_resize',
            __('Original image handling', 'pic-pilot'),
            [
                '0' => __('Delete original (save storage space)', 'pic-pilot'),
                '1' => __('Keep original (preserve full quality)', 'pic-pilot')
            ],
            'pic_pilot_optimization_bulk',
            [
                'description' => __('Choose whether to keep or delete the original large image after resizing during optimization.', 'pic-pilot'),
                'wrapper_class' => 'pp-optimization-resize-conditional',
            ]
        );

        // =======================================
        // 4. COMPRESSION ENGINES
        // =======================================
        add_settings_section(
            'pic_pilot_engines',
            __('Compression Engines', 'pic-pilot'),
            function () {
                $capabilities = \PicPilot\Settings::get_capabilities();
                $png_support = $capabilities['png_compression'];
                
                echo '<p>' . esc_html__('Configure how different image types are compressed.', 'pic-pilot') . '</p>';
                
                if ($png_support) {
                    echo '<div class="notice notice-success inline"><p>';
                    echo '<strong>' . esc_html__('Server Capability:', 'pic-pilot') . '</strong> ';
                    echo sprintf(esc_html__('Your server supports PNG compression using %s.', 'pic-pilot'), $png_support);
                    echo '</p></div>';
                } else {
                    echo '<div class="notice notice-warning inline"><p>';
                    echo '<strong>' . esc_html__('Server Limitation:', 'pic-pilot') . '</strong> ';
                    echo esc_html__('Your server cannot compress PNG files locally. External APIs like TinyPNG are recommended for PNG compression.', 'pic-pilot');
                    echo '</p></div>';
                }
            },
            'pic-pilot'
        );

        FormHelper::checkbox(
            'enable_local_png',
            __('Try Local PNG Compression First', 'pic-pilot'),
            'pic_pilot_engines',
            [
                'description' => __('Attempt PNG compression using server resources before falling back to external APIs. May not achieve the same compression ratios as specialized services.', 'pic-pilot'),
            ]
        );

        add_settings_field(
            'png_engine',
            __('PNG Compression Engine', 'pic-pilot'),
            [self::class, 'render_png_engine_dropdown'],
            'pic-pilot',
            'pic_pilot_engines'
        );

        add_settings_field(
            'tinypng_api_key',
            __('TinyPNG API Key', 'pic-pilot'),
            [self::class, 'render_text_input'],
            'pic-pilot',
            'pic_pilot_engines',
            ['label_for' => 'tinypng_api_key']
        );

        add_settings_field(
            'use_tinypng_for_jpeg',
            __('Use TinyPNG for JPEGs', 'pic-pilot'),
            [self::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_engines',
            ['label_for' => 'use_tinypng_for_jpeg']
        );

        // Engine Settings Explanation
        add_settings_field(
            'engine_settings_note',
            '',
            function() {
                echo '<div class="notice notice-info inline" style="margin-top: 10px;">';
                echo '<p><strong>' . __('Engine Settings Note:', 'pic-pilot') . '</strong></p>';
                echo '<p>' . __('WebP Engine and Compression Engine settings can be configured independently. WebP Engine is used in Convert mode (Upload & Resize section), Compression Engine is used in Compress mode. TinyPNG API requires an API key to be configured above.', 'pic-pilot') . '</p>';
                echo '</div>';
            },
            'pic-pilot',
            'pic_pilot_engines'
        );

        // =======================================
        // 5. ADVANCED OPTIONS
        // =======================================
        add_settings_section(
            'pic_pilot_advanced',
            __('Advanced Options', 'pic-pilot'),
            function () {
                echo '<p>' . esc_html__('Fine-tune optimization behavior for power users.', 'pic-pilot') . '</p>';
                echo '<div class="notice notice-warning inline"><p>';
                echo '<strong>' . esc_html__('Warning:', 'pic-pilot') . '</strong> ';
                echo esc_html__('These options can affect all images and backups. Proceed with caution.', 'pic-pilot');
                echo '</p></div>';
            },
            'pic-pilot'
        );

        add_settings_field(
            'strip_metadata',
            __('Strip Metadata for Smaller Files', 'pic-pilot'),
            [FormHelper::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_advanced',
            [
                'label_for' => 'strip_metadata',
                'description' => __('Removes EXIF/IPTC data from images to reduce file size. ⚠️ If you use alt text plugins that depend on metadata, this may reduce accuracy.', 'pic-pilot')
            ]
        );

        add_settings_field(
            'enable_format_conversion_backups',
            __('Enable Format Conversion Backups', 'pic-pilot'),
            [FormHelper::class, 'render_checkbox'],
            'pic-pilot',
            'pic_pilot_advanced',
            [
                'label_for' => 'enable_format_conversion_backups',
                'description' => __('Creates automatic backups when converting between formats (PNG↔JPEG↔WebP), allowing you to restore to original formats. <strong>DISK SPACE WARNING:</strong> This uses additional storage space. When disabled, format conversions cannot be undone.', 'pic-pilot')
            ]
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
        $autocomplete = $args['label_for'] === 'tinypng_api_key' ? 'new-password' : 'off';
        echo "<input type='$type' id='{$args['label_for']}' name='pic_pilot_options[{$args['label_for']}]' value='$value' class='regular-text' autocomplete='$autocomplete' />";
    }

    public static function render_input($args) {
        $name = $args['label_for'] ?? '';
        $type = $args['type'] ?? 'text';
        $placeholder = $args['placeholder'] ?? '';
        $description = $args['description'] ?? '';
        $wrapper_class = $args['wrapper_class'] ?? '';

        // Pull value after confirming settings are initialized
        $options = get_option('pic_pilot_options', []);
        $value = $options[$name] ?? '';
        $value = is_array($value) ? '' : esc_attr($value);

        echo "<div class='$wrapper_class'>";
        echo "<input type='$type' id='$name' name='pic_pilot_options[$name]' value='$value' placeholder='$placeholder' class='regular-text' />";
        if ($description) {
            echo "<p class='description'>$description</p>";
        }
        echo "</div>";
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
        echo "<option value='95'" . selected($selected, '95', false) . ">" . __('Excellent (95)', 'pic-pilot') . "</option>";
        echo "<option value='85'" . selected($selected, '85', false) . ">" . __('Very Good (85)', 'pic-pilot') . "</option>";
        echo "<option value='80'" . selected($selected, '80', false) . ">" . __('Good (80)', 'pic-pilot') . "</option>";
        echo "<option value='75'" . selected($selected, '75', false) . ">" . __('Average (75)', 'pic-pilot') . "</option>";
        echo "<option value='70'" . selected($selected, '70', false) . ">" . __('Fair (70)', 'pic-pilot') . "</option>";
        echo "<option value='65'" . selected($selected, '65', false) . ">" . __('Low (65)', 'pic-pilot') . "</option>";
        echo "<option value='60'" . selected($selected, '60', false) . ">" . __('Maximum Savings (60)', 'pic-pilot') . "</option>";
        echo "</select>";
    }

    public static function render_webp_quality_dropdown($args) {
        $options = get_option('pic_pilot_options', []);
        $selected = $options['webp_quality'] ?? '75';

        echo "<select id='webp_quality' name='pic_pilot_options[webp_quality]'>";
        echo "<option value='100'" . selected($selected, '100', false) . ">" . __('Lossless (100)', 'pic-pilot') . "</option>";
        echo "<option value='95'" . selected($selected, '95', false) . ">" . __('Excellent (95)', 'pic-pilot') . "</option>";
        echo "<option value='85'" . selected($selected, '85', false) . ">" . __('Very Good (85)', 'pic-pilot') . "</option>";
        echo "<option value='80'" . selected($selected, '80', false) . ">" . __('Good (80)', 'pic-pilot') . "</option>";
        echo "<option value='75'" . selected($selected, '75', false) . ">" . __('Average (75)', 'pic-pilot') . "</option>";
        echo "<option value='70'" . selected($selected, '70', false) . ">" . __('Fair (70)', 'pic-pilot') . "</option>";
        echo "<option value='65'" . selected($selected, '65', false) . ">" . __('Low (65)', 'pic-pilot') . "</option>";
        echo "<option value='60'" . selected($selected, '60', false) . ">" . __('Maximum Savings (60)', 'pic-pilot') . "</option>";
        echo "</select>";
        echo '<p class="description">' . __('WebP quality setting. Lower values = smaller files, higher values = better quality.', 'pic-pilot') . '</p>';
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

    //Sanitize settings on save
    public static function sanitize_options($input) {
        $output = [];

        // List of known boolean checkboxes
        $checkboxes = [
            // Core settings
            'enable_backup',
            'enable_logging',
            
            // Compression mode
            'resize_during_compression',
            'convert_png_to_jpeg_in_compress_mode',
            
            // Conversion mode
            'resize_during_conversion',
            
            // Manual optimization
            'resize_during_optimization',
            
            // Engine settings
            'enable_local_png',
            'use_tinypng_for_jpeg',
            
            // Advanced settings
            'strip_metadata',
            'enable_format_conversion_backups',
            
            // Legacy settings (for compatibility)
            'enable_jpeg',
            'optimize_on_upload',
            'resize_on_upload',
        ];

        // List of radio button settings
        $radio_buttons = [
            'upload_mode',
            'compression_engine',
            'webp_engine',
            'keep_original_after_compression_resize',
            'keep_original_after_conversion_resize',
            'keep_original_after_optimization_resize',
            // Legacy settings
            'keep_original_after_resize',
        ];

        foreach ($checkboxes as $key) {
            $output[$key] = isset($input[$key]) ? '1' : '0';
        }

        // Handle radio button settings
        foreach ($radio_buttons as $key) {
            if (isset($input[$key])) {
                // Define valid values for each radio button setting
                $valid_values = [
                    'upload_mode' => ['disabled', 'compress', 'convert'],
                    'compression_engine' => ['local', 'tinypng'],
                    'webp_engine' => ['local', 'tinypng'],
                    'keep_original_after_compression_resize' => ['0', '1'],
                    'keep_original_after_conversion_resize' => ['0', '1'],
                    'keep_original_after_optimization_resize' => ['0', '1'],
                    // Legacy settings
                    'keep_original_after_resize' => ['0', '1'],
                ];
                
                $allowed = $valid_values[$key] ?? ['0', '1']; // Default to 0/1 for backwards compatibility
                
                if (in_array($input[$key], $allowed)) {
                    $output[$key] = sanitize_text_field($input[$key]);
                }
            }
        }

        // Quality settings: force 10–100 range
        $quality_settings = ['jpeg_quality', 'webp_quality'];
        foreach ($quality_settings as $key) {
            if (isset($input[$key])) {
                $output[$key] = max(10, min(100, intval($input[$key])));
            }
        }

        // Width settings: numeric and no greater than 8000
        $width_settings = [
            'compression_max_width',
            'conversion_max_width', 
            'optimization_max_width',
            // Legacy settings
            'resize_max_width'
        ];
        foreach ($width_settings as $key) {
            if (!empty($input[$key])) {
                $output[$key] = min(intval($input[$key]), 8000);
            }
        }

        // PNG engine
        if (!empty($input['png_engine'])) {
            $output['png_engine'] = sanitize_text_field($input['png_engine']);
        }

        // TinyPNG API key
        if (!empty($input['tinypng_api_key'])) {
            $output['tinypng_api_key'] = sanitize_text_field($input['tinypng_api_key']);
        }


        return $output;
    }
}

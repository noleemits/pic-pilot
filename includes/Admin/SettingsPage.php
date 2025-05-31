<?php

namespace PicPilot\Admin;
//Register settings menu
class SettingsPage {
    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
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
    }

    public static function render_settings_page() {

?>

        <div class="wrap">
            <h1><?php esc_html_e('Pic Pilot Settings', 'pic-pilot'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('pic_pilot_settings');
                do_settings_sections('pic-pilot');
                submit_button();
                ?>
            </form>

        </div>
<?php
        self::render_server_capabilities();
    }

    //Register settings and fields

    public static function register_settings() {
        register_setting('pic_pilot_settings', 'pic_pilot_options');

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
    }
    //render server capabilities
    public static function render_server_capabilities() {
        $cap = \PicPilot\Settings::get_capabilities();

        echo '<h2 style="margin-top:40px;">' . esc_html__('Server Capabilities', 'pic-pilot') . '</h2>';
        echo '<ul style="margin-left:20px;">';
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

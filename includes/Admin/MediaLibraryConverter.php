<?php

namespace PicPilot\Admin;

use PicPilot\Settings;
use PicPilot\Logger;
use PicPilot\WebPConverter;
use PicPilot\PngToJpegConverter;
use PicPilot\Backup\SmartBackupManager;
use PicPilot\Backup\RestoreManager;
use PicPilot\Utils;

defined('ABSPATH') || exit;

class MediaLibraryConverter {
    
    /**
     * Initialize media library conversion features
     */
    public static function init(): void {
        // Add conversion buttons to media library
        add_filter('attachment_fields_to_edit', [self::class, 'add_conversion_fields'], 10, 2);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_media_scripts']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media_scripts']);
        
        // AJAX handlers for conversions
        add_action('wp_ajax_pic_pilot_convert_format', [self::class, 'handle_format_conversion']);
        add_action('wp_ajax_pic_pilot_batch_convert', [self::class, 'handle_batch_conversion']);
        add_action('wp_ajax_pic_pilot_conversion_progress', [self::class, 'handle_conversion_progress']);
        
        // Add custom column to media library
        add_filter('manage_media_columns', [self::class, 'add_media_column']);
        add_action('manage_media_custom_column', [self::class, 'display_media_column'], 10, 2);
        
        // MediaLibraryConverter initialized
    }
    
    /**
     * Add conversion fields to attachment edit screen
     */
    public static function add_conversion_fields(array $fields, \WP_Post $post): array {
        if (!wp_attachment_is_image($post->ID)) {
            return $fields;
        }
        
        $mime_type = get_post_mime_type($post->ID);
        $conversion_buttons = self::get_conversion_buttons($post->ID, $mime_type);
        
        if (!empty($conversion_buttons)) {
            $fields['pic_pilot_conversions'] = [
                'label' => __('Format Conversions', 'pic-pilot'),
                'input' => 'html',
                'html' => $conversion_buttons
            ];
        }
        
        return $fields;
    }
    
    /**
     * Generate conversion buttons based on current format
     */
    private static function get_conversion_buttons(int $attachment_id, string $mime_type): string {
        $buttons = [];
        $file_path = get_attached_file($attachment_id);
        
        if (!file_exists($file_path)) {
            return '';
        }
        
        // Check backup availability for restore functionality
        $backup_info = SmartBackupManager::get_backup_info($attachment_id);
        $has_backups = !empty($backup_info);
        
        // Format conversion buttons
        switch ($mime_type) {
            case 'image/jpeg':
                $buttons[] = self::create_conversion_button($attachment_id, 'jpeg_to_png', '→ PNG', 'Convert to PNG format');
                if (WebPConverter::hasWebPSupport()) {
                    $buttons[] = self::create_conversion_button($attachment_id, 'jpeg_to_webp', '→ WebP', 'Convert to WebP format');
                }
                break;
                
            case 'image/png':
                // Check if PNG has transparency
                $has_transparency = !Utils::isOpaquePng($file_path);
                if (!$has_transparency) {
                    $buttons[] = self::create_conversion_button($attachment_id, 'png_to_jpeg', '→ JPEG', 'Convert to JPEG format (opaque PNG)');
                } else {
                    $buttons[] = '<span class="pic-pilot-disabled-btn" title="PNG has transparency - JPEG conversion would lose alpha channel">→ JPEG (transparency)</span>';
                }
                
                if (WebPConverter::hasWebPSupport()) {
                    $buttons[] = self::create_conversion_button($attachment_id, 'png_to_webp', '→ WebP', 'Convert to WebP format');
                }
                break;
                
            case 'image/webp':
                if ($has_backups) {
                    $buttons[] = self::create_restore_button($attachment_id, '← Original', 'Restore to original format');
                } else {
                    // If no backup, offer conversion to other formats
                    $buttons[] = self::create_conversion_button($attachment_id, 'webp_to_jpeg', '→ JPEG', 'Convert WebP to JPEG format');
                    $buttons[] = self::create_conversion_button($attachment_id, 'webp_to_png', '→ PNG', 'Convert WebP to PNG format');
                }
                break;
        }
        
        // Restore button for non-WebP formats (only if backups exist and can be restored)
        if ($has_backups && $mime_type !== 'image/webp') {
            // Check if this attachment can actually be restored
            if (self::can_be_restored($attachment_id, $backup_info, $mime_type)) {
                $buttons[] = self::create_restore_button($attachment_id, '🔄 Restore', 'Restore from backup');
            }
        }
        
        if (empty($buttons)) {
            return '';
        }
        
        $button_html = '<div class="pic-pilot-conversion-buttons">' . implode(' ', $buttons) . '</div>';
        $button_html .= '<div class="pic-pilot-conversion-progress" id="pic-pilot-progress-' . $attachment_id . '" style="display:none;"></div>';
        
        return $button_html;
    }
    
    /**
     * Check if attachment can be restored based on backup info and current format
     */
    private static function can_be_restored(int $attachment_id, array $backup_info, string $current_mime): bool {
        // If there are user backups (compression), it can always be restored
        if (isset($backup_info['user'])) {
            return true;
        }
        
        // If there are conversion backups, check if it makes sense
        if (isset($backup_info['conversion'])) {
            $manifest = $backup_info['conversion'];
            
            // If current format is WebP and we have conversion backup, can restore to original
            if ($current_mime === 'image/webp' && ($manifest['converted_to_webp'] ?? false)) {
                return true;
            }
            
            // If current format is JPEG and it was converted from PNG, can restore to PNG
            if ($current_mime === 'image/jpeg' && ($manifest['converted_from_png'] ?? false)) {
                return true;
            }
            
            // Other conversion scenarios don't make sense for restoration
            return false;
        }
        
        return false;
    }
    
    /**
     * Create a conversion button
     */
    private static function create_conversion_button(int $attachment_id, string $conversion_type, string $label, string $title): string {
        $nonce = wp_create_nonce('pic_pilot_convert_' . $attachment_id);
        
        return sprintf(
            '<button type="button" class="button pic-pilot-convert-btn" 
                     data-attachment-id="%d" 
                     data-conversion-type="%s" 
                     data-nonce="%s" 
                     title="%s">%s</button>',
            $attachment_id,
            esc_attr($conversion_type),
            $nonce,
            esc_attr($title),
            esc_html($label)
        );
    }
    
    /**
     * Create a restore button
     */
    private static function create_restore_button(int $attachment_id, string $label, string $title): string {
        $nonce = wp_create_nonce('pic_pilot_restore_attachment_' . $attachment_id);
        
        return sprintf(
            '<button type="button" class="button pic-pilot-restore-btn" 
                     data-attachment-id="%d" 
                     data-nonce="%s" 
                     title="%s">%s</button>',
            $attachment_id,
            $nonce,
            esc_attr($title),
            esc_html($label)
        );
    }
    
    
    /**
     * Add custom column to media library
     */
    public static function add_media_column(array $columns): array {
        $columns['pic_pilot_formats'] = __('Format Options', 'pic-pilot');
        return $columns;
    }
    
    /**
     * Display content in custom media column
     */
    public static function display_media_column(string $column_name, int $attachment_id): void {
        if ($column_name !== 'pic_pilot_formats' || !wp_attachment_is_image($attachment_id)) {
            return;
        }
        
        $mime_type = get_post_mime_type($attachment_id);
        $buttons = self::get_conversion_buttons($attachment_id, $mime_type);
        
        if ($buttons) {
            echo '<div class="pic-pilot-media-column">' . $buttons . '</div>';
        }
    }
    
    /**
     * Enqueue media library scripts
     */
    public static function enqueue_media_scripts(): void {
        if (!function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        
        if (!$screen || !in_array($screen->id, ['upload', 'attachment'])) {
            return;
        }
        
        wp_enqueue_script(
            'pic-pilot-media-converter',
            plugins_url('assets/js/media-converter.js', dirname(__DIR__)),
            ['jquery'],
            '1.0.0',
            true
        );
        
        wp_localize_script('pic-pilot-media-converter', 'picPilotConverter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pic_pilot_media_converter'),
            'strings' => [
                'converting' => __('Converting...', 'pic-pilot'),
                'success' => __('Conversion completed!', 'pic-pilot'),
                'error' => __('Conversion failed', 'pic-pilot'),
                'confirm' => __('Are you sure you want to convert this image?', 'pic-pilot'),
                'confirmRestore' => __('Are you sure you want to restore this image?', 'pic-pilot'),
            ]
        ]);
        
        wp_enqueue_style(
            'pic-pilot-media-converter',
            plugins_url('assets/css/media-converter.css', dirname(__DIR__)),
            [],
            '1.0.0'
        );
    }
    
    /**
     * Handle format conversion AJAX request
     */
    public static function handle_format_conversion(): void {
        // Verify nonce and permissions
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        $conversion_type = sanitize_text_field($_POST['conversion_type'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        if (!wp_verify_nonce($nonce, 'pic_pilot_convert_' . $attachment_id)) {
            wp_send_json_error(['message' => __('Invalid nonce', 'pic-pilot')]);
        }
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'pic-pilot')]);
        }
        
        Logger::log("🔄 Starting inline conversion: $conversion_type for ID $attachment_id");
        
        try {
            $result = self::perform_conversion($attachment_id, $conversion_type);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (\Exception $e) {
            Logger::log("❌ Conversion exception: " . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Perform the actual format conversion
     */
    private static function perform_conversion(int $attachment_id, string $conversion_type): array {
        $file_path = get_attached_file($attachment_id);
        
        if (!file_exists($file_path)) {
            return ['success' => false, 'message' => __('File not found', 'pic-pilot')];
        }
        
        $original_size = filesize($file_path);
        $original_mime = get_post_mime_type($attachment_id);
        
        // Create backup before conversion
        $operation_map = [
            'jpeg_to_png' => 'convert_jpeg_to_png',
            'jpeg_to_webp' => 'convert_to_webp',
            'png_to_jpeg' => 'convert_png_to_jpeg', 
            'png_to_webp' => 'convert_to_webp',
            'webp_to_jpeg' => 'convert_webp_to_jpeg',
            'webp_to_png' => 'convert_webp_to_png'
        ];
        
        $operation_type = $operation_map[$conversion_type] ?? 'optimize_image';
        SmartBackupManager::create_smart_backup($attachment_id, $operation_type);
        
        switch ($conversion_type) {
            case 'jpeg_to_png':
                return self::convert_jpeg_to_png($attachment_id, $file_path);
                
            case 'jpeg_to_webp':
            case 'png_to_webp':
                return self::convert_to_webp($attachment_id, $file_path);
                
            case 'png_to_jpeg':
                return self::convert_png_to_jpeg($attachment_id, $file_path);
                
            case 'webp_to_jpeg':
                return self::convert_webp_to_jpeg($attachment_id, $file_path);
                
            case 'webp_to_png':
                return self::convert_webp_to_png($attachment_id, $file_path);
                
            default:
                return ['success' => false, 'message' => __('Unknown conversion type', 'pic-pilot')];
        }
    }
    
    /**
     * Convert JPEG to PNG
     */
    private static function convert_jpeg_to_png(int $attachment_id, string $file_path): array {
        // Implementation will use ImageMagick/GD
        Logger::log("🔄 Converting JPEG to PNG for ID $attachment_id");
        
        $path_info = pathinfo($file_path);
        $png_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.png';
        
        // Convert using WordPress image editor
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return ['success' => false, 'message' => $editor->get_error_message()];
        }
        
        $save_result = $editor->save($png_path, 'image/png');
        if (is_wp_error($save_result)) {
            return ['success' => false, 'message' => $save_result->get_error_message()];
        }
        
        // Store original size before updating attachment
        $original_size = filesize($file_path);
        
        // Update attachment
        self::update_attachment_file($attachment_id, $file_path, $png_path, 'image/png');
        
        $new_size = file_exists($png_path) ? filesize($png_path) : 0;
        $saved = max(0, $original_size - $new_size);
        
        Logger::log("✅ JPEG→PNG conversion completed for ID $attachment_id");
        
        return [
            'success' => true,
            'message' => __('Successfully converted to PNG', 'pic-pilot'),
            'new_format' => 'PNG',
            'original_size' => $original_size,
            'new_size' => $new_size,
            'saved' => $saved
        ];
    }
    
    /**
     * Convert PNG to JPEG
     */
    private static function convert_png_to_jpeg(int $attachment_id, string $file_path): array {
        Logger::log("🔄 Converting PNG to JPEG for ID $attachment_id");
        
        $quality = (int) Settings::get('jpeg_quality', 80);
        $jpeg_path = PngToJpegConverter::convert($file_path, $quality);
        
        if (!$jpeg_path || !file_exists($jpeg_path)) {
            return ['success' => false, 'message' => __('PNG to JPEG conversion failed', 'pic-pilot')];
        }
        
        // Store original size before updating attachment
        $original_size = filesize($file_path);
        
        // Update attachment
        self::update_attachment_file($attachment_id, $file_path, $jpeg_path, 'image/jpeg');
        
        $new_size = file_exists($jpeg_path) ? filesize($jpeg_path) : 0;
        $saved = max(0, $original_size - $new_size);
        
        Logger::log("✅ PNG→JPEG conversion completed for ID $attachment_id");
        
        return [
            'success' => true,
            'message' => __('Successfully converted to JPEG', 'pic-pilot'),
            'new_format' => 'JPEG',
            'original_size' => $original_size,
            'new_size' => $new_size,
            'saved' => $saved
        ];
    }
    
    /**
     * Convert to WebP
     */
    private static function convert_to_webp(int $attachment_id, string $file_path): array {
        Logger::log("🔄 Converting to WebP for ID $attachment_id");
        
        $quality = (int) Settings::get('webp_quality', 80);
        $webp_path = WebPConverter::convert($file_path, $quality);
        
        if (!$webp_path || !file_exists($webp_path)) {
            return ['success' => false, 'message' => __('WebP conversion failed', 'pic-pilot')];
        }
        
        // Store original size before updating attachment
        $original_size = filesize($file_path);
        
        // Update attachment
        self::update_attachment_file($attachment_id, $file_path, $webp_path, 'image/webp');
        
        $new_size = file_exists($webp_path) ? filesize($webp_path) : 0;
        $saved = max(0, $original_size - $new_size);
        
        Logger::log("✅ WebP conversion completed for ID $attachment_id");
        
        return [
            'success' => true,
            'message' => __('Successfully converted to WebP', 'pic-pilot'),
            'new_format' => 'WebP',
            'original_size' => $original_size,
            'new_size' => $new_size,
            'saved' => $saved
        ];
    }
    
    /**
     * Convert WebP to JPEG
     */
    private static function convert_webp_to_jpeg(int $attachment_id, string $file_path): array {
        Logger::log("🔄 Converting WebP to JPEG for ID $attachment_id");
        
        $path_info = pathinfo($file_path);
        $jpeg_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.jpg';
        
        // Convert using WordPress image editor
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return ['success' => false, 'message' => $editor->get_error_message()];
        }
        
        $save_result = $editor->save($jpeg_path, 'image/jpeg');
        if (is_wp_error($save_result)) {
            return ['success' => false, 'message' => $save_result->get_error_message()];
        }
        
        // Store original size before updating attachment
        $original_size = filesize($file_path);
        
        // Update attachment
        self::update_attachment_file($attachment_id, $file_path, $jpeg_path, 'image/jpeg');
        
        $new_size = file_exists($jpeg_path) ? filesize($jpeg_path) : 0;
        $saved = max(0, $original_size - $new_size);
        
        Logger::log("✅ WebP→JPEG conversion completed for ID $attachment_id");
        
        return [
            'success' => true,
            'message' => __('Successfully converted to JPEG', 'pic-pilot'),
            'new_format' => 'JPEG',
            'original_size' => $original_size,
            'new_size' => $new_size,
            'saved' => $saved
        ];
    }
    
    /**
     * Convert WebP to PNG
     */
    private static function convert_webp_to_png(int $attachment_id, string $file_path): array {
        Logger::log("🔄 Converting WebP to PNG for ID $attachment_id");
        
        $path_info = pathinfo($file_path);
        $png_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.png';
        
        // Convert using WordPress image editor
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return ['success' => false, 'message' => $editor->get_error_message()];
        }
        
        $save_result = $editor->save($png_path, 'image/png');
        if (is_wp_error($save_result)) {
            return ['success' => false, 'message' => $save_result->get_error_message()];
        }
        
        // Store original size before updating attachment
        $original_size = filesize($file_path);
        
        // Update attachment
        self::update_attachment_file($attachment_id, $file_path, $png_path, 'image/png');
        
        $new_size = file_exists($png_path) ? filesize($png_path) : 0;
        $saved = max(0, $original_size - $new_size);
        
        Logger::log("✅ WebP→PNG conversion completed for ID $attachment_id");
        
        return [
            'success' => true,
            'message' => __('Successfully converted to PNG', 'pic-pilot'),
            'new_format' => 'PNG',
            'original_size' => $original_size,
            'new_size' => $new_size,
            'saved' => $saved
        ];
    }
    
    /**
     * Update attachment file and metadata after conversion
     */
    private static function update_attachment_file(int $attachment_id, string $old_file, string $new_file, string $new_mime): void {
        // Update file path
        update_attached_file($attachment_id, $new_file);
        
        // Update MIME type
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $new_mime,
        ]);
        
        // Delete old file
        @unlink($old_file);
        
        // Regenerate thumbnails
        $metadata = wp_generate_attachment_metadata($attachment_id, $new_file);
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        // Clear caches
        clean_post_cache($attachment_id);
    }
    
    /**
     * Handle batch conversion AJAX request
     */
    public static function handle_batch_conversion(): void {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pic_pilot_media_converter')) {
            wp_send_json_error(['message' => __('Invalid nonce', 'pic-pilot')]);
        }
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'pic-pilot')]);
        }
        
        $attachment_ids = array_map('intval', $_POST['attachment_ids'] ?? []);
        $conversion_type = sanitize_text_field($_POST['conversion_type'] ?? '');
        
        if (empty($attachment_ids)) {
            wp_send_json_error(['message' => __('No images selected', 'pic-pilot')]);
        }
        
        Logger::log("🔄 Starting batch conversion: $conversion_type for " . count($attachment_ids) . " images");
        
        $results = [];
        foreach ($attachment_ids as $attachment_id) {
            $results[$attachment_id] = self::perform_conversion($attachment_id, $conversion_type);
        }
        
        wp_send_json_success([
            'results' => $results,
            'total' => count($attachment_ids),
            'successful' => count(array_filter($results, fn($r) => $r['success']))
        ]);
    }
    
    /**
     * Handle conversion progress AJAX request
     */
    public static function handle_conversion_progress(): void {
        // Implementation for progress tracking
        wp_send_json_success(['progress' => 100, 'status' => 'completed']);
    }
}
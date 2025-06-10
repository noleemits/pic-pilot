<?php

namespace PicPilot\Upload;

use PicPilot\Settings;
use PicPilot\Logger;
use PicPilot\Compressor\EngineRouter;
use PicPilot\Backup\BackupService;
use PicPilot\Utils;


defined('ABSPATH') || exit;

class UploadOptimizer {
    protected $router;
    protected $backup;
    protected $logger;

    public function __construct(EngineRouter $router, Logger $logger) {
        $this->router = $router;
        $this->logger = $logger;
    }


    /**
     * Register the upload optimization hook.
     */
    public function register() {
        add_filter('wp_generate_attachment_metadata', [$this, 'handleUpload'], 10, 2);
    }

    /**
     * Optimize an uploaded image after it's been added to the Media Library.
     *
     * @param array $metadata
     * @param int $attachmentId
     * @return array
     */
    public function handleUpload($metadata, $attachmentId) {
        if (!Settings::get('optimize_on_upload')) {
            return $metadata;
        }
        //Check settings to see if resize is enabled
        $options = \PicPilot\Settings::all();
        $resize_enabled = !empty($options['resize_on_upload']);
        $max_width = intval($options['resize_max_width'] ?? 2048);


        $path = get_attached_file($attachmentId);

        if (!file_exists($path) || !wp_attachment_is_image($attachmentId)) {
            return $metadata;
        }

        try {
            //check max widhth and resize if necessary
            if ($resize_enabled && $max_width > 0) {
                $original_path = $path; // BEFORE resize happens

                $editor = wp_get_image_editor($path);
                if (!is_wp_error($editor)) {
                    $size = $editor->get_size();
                    if ($size['width'] > $max_width) {
                        $editor->resize($max_width, null, false);
                        $editor->save($path);

                        // ✅ Clean up original if different
                        if ($original_path !== get_attached_file($attachmentId)) {
                            @unlink($original_path);
                            \PicPilot\Logger::log("🧹 Deleted original pre-resize upload: " . basename($original_path));
                        }
                    }
                }
            }

            //After resizing, clean bigger images than the max width
            foreach ($metadata['sizes'] as $size => $info) {
                $thumb_path = path_join(dirname($path), $info['file']);

                if (file_exists($thumb_path)) {
                    [$width, $height] = getimagesize($thumb_path);

                    if ($width > $max_width) {
                        unlink($thumb_path); // remove too-large thumbnail
                        $this->logger->log("🧹 Removed oversized thumbnail [$size]: $info[file] ($width px)");
                        unset($metadata['sizes'][$size]); // remove from metadata
                    }
                }
            }

            // Create a backup before compression
            \PicPilot\Backup\BackupService::create_backup($attachmentId);

            //Delete oversized images
            $metadata = Utils::clean_oversized_images($metadata, $path, $max_width);

            $mime = mime_content_type($path);
            $compressor = $this->router::get_compressor($mime);
            $main_result = $compressor->compress($path);

            // Compress all thumbnails and sum up saved bytes
            $thumbs_saved = \PicPilot\Utils::compress_thumbnails($attachmentId, $compressor, $path);

            $total_saved = ($main_result['saved'] ?? 0) + $thumbs_saved;

            // Save meta/status as before
            if ($main_result && $total_saved > 0) {
                update_post_meta($attachmentId, '_pic_pilot_optimized', true);
                update_post_meta($attachmentId, '_pic_pilot_bytes_saved', (int)$total_saved);
                update_post_meta($attachmentId, '_pic_pilot_engine', sanitize_text_field($main_result['engine'] ?? 'unknown'));
                update_post_meta($attachmentId, '_pic_pilot_optimization', [
                    'status' => 'optimized',
                    'saved'  => (int)$total_saved,
                    'timestamp' => time(),
                ]);
                update_post_meta($attachmentId, '_pic_pilot_optimized_version', time());

                $this->logger->log("✅ Compressed on upload", [
                    'attachment_id' => $attachmentId,
                    'engine' => $main_result['engine'] ?? 'unknown',
                    'bytes_saved' => $total_saved,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->log("❌ Upload compression failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }


        return $metadata;
    }
}

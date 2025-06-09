<?php

namespace PicPilot\Upload;

use PicPilot\Settings;
use PicPilot\Logger;
use PicPilot\Compressor\EngineRouter;
use PicPilot\Backup\BackupManager;

defined('ABSPATH') || exit;

class UploadOptimizer {
    protected $router;
    protected $backup;
    protected $logger;

    public function __construct(EngineRouter $router, BackupManager $backup, Logger $logger) {
        $this->router = $router;
        $this->backup = $backup;
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

        $path = get_attached_file($attachmentId);

        if (!file_exists($path) || !wp_attachment_is_image($attachmentId)) {
            return $metadata;
        }

        try {
            // Backup original file
            $this->backup->backup($attachmentId);

            // Route image to appropriate compressor
            $result = $this->router->compress($attachmentId, $path);

            // Save metadata and log if compression succeeded
            if ($result && isset($result['bytes_saved'])) {
                update_post_meta($attachmentId, '_pic_pilot_optimized', true);
                update_post_meta($attachmentId, '_pic_pilot_bytes_saved', (int) $result['bytes_saved']);
                update_post_meta($attachmentId, '_pic_pilot_engine', sanitize_text_field($result['engine'] ?? 'unknown'));

                Logger::log("Compressed on upload", [
                    'attachment_id' => $attachmentId,
                    'engine' => $result['engine'] ?? 'unknown',
                    'bytes_saved' => $result['bytes_saved'] ?? 0,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::log("Upload compression failed", [
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);
        }

        return $metadata;
    }
}

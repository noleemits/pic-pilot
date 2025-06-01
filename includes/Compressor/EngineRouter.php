<?php

namespace PicPilot\Compressor;

use PicPilot\Logger;
use PicPilot\Settings;

class EngineRouter {
    public static function get_compressor(string $mime): CompressorInterface {
        $settings = Settings::get();

        if (strpos($mime, 'png') !== false) {
            if (!empty($settings['enable_tinypng']) && !empty($settings['tinypng_api_key'])) {
                Logger::log("🔌 Routing PNG to TinyPNG engine");
                return new \PicPilot\Compressor\External\TinyPngCompressor();
            }

            Logger::log("⚠️ No PNG engine configured. Falling back.");
            return new \PicPilot\Compressor\PngCompressorPlaceholder();
        }

        if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) {
            if (!empty($settings['external_jpeg']) && !empty($settings['external_api_key'])) {
                Logger::log("🔌 Routing JPEG to external engine");
                // Add external JPEG logic here later
            }

            Logger::log("🔧 Routing JPEG to local compressor");
            return new \PicPilot\Compressor\Local\LocalJpegCompressor();
        }

        throw new \Exception("Unsupported MIME type: $mime");
    }
}

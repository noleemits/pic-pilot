<?php

namespace PicPilot\Compressor;

class PngCompressorPlaceholder implements CompressorInterface {
    public function compress($file_path): array {
        \PicPilot\Logger::log("❌ PNG compression attempted, but no engine is available: $file_path");

        $capabilities = \PicPilot\Settings::get_capabilities();
        $has_local_support = $capabilities['png_compression'];
        
        if ($has_local_support) {
            $error_message = 'Local PNG compression is available but not enabled. Enable "Try Local PNG Compression First" in settings, or configure an external API like TinyPNG.';
        } else {
            $error_message = 'No PNG compression method available. Your server lacks PNG processing libraries. Please configure an external API like TinyPNG.';
        }

        return [
            'success' => false,
            'error' => $error_message,
            'original' => file_exists($file_path) ? filesize($file_path) : 0,
            'optimized' => 0,
            'saved' => 0,
            'server_capability' => $has_local_support ? 'available_not_enabled' : 'not_available'
        ];
    }
}

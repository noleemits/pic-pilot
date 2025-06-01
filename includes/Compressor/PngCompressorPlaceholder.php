<?php

namespace PicPilot\Compressor;

class PngCompressorPlaceholder implements CompressorInterface {
    public function compress($file_path): array {
        \PicPilot\Logger::log("❌ PNG compression attempted, but no engine is available: $file_path");

        return [
            'success' => false,
            'original' => file_exists($file_path) ? filesize($file_path) : 0,
            'optimized' => 0,
            'saved' => 0
        ];
    }
}

<?php

namespace PicPilot\Compressor\External;

use PicPilot\Compressor\CompressorInterface;
use PicPilot\Logger;
use PicPilot\Settings;

class TinyPngCompressor implements CompressorInterface {
    public function compress($file_path): bool {
        $settings = Settings::get();
        $api_key = $settings['tinypng_api_key'] ?? '';
        if (empty($api_key)) {
            Logger::log("❌ TinyPNG API key missing.");
            return false;
        }

        if (!file_exists($file_path) || !is_readable($file_path)) {
            Logger::log("❌ File not found or unreadable: $file_path");
            return false;
        }

        if (filesize($file_path) > 5 * 1024 * 1024) {
            Logger::log("⚠️ Skipping file (over 5MB TinyPNG limit): $file_path");
            return false;
        }

        $filename = basename($file_path);
        Logger::log("📤 Sending $filename to TinyPNG...");

        $request = curl_init();
        curl_setopt_array($request, [
            CURLOPT_URL => "https://api.tinify.com/shrink",
            CURLOPT_USERPWD => "api:$api_key",
            CURLOPT_POSTFIELDS => file_get_contents($file_path),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_HTTP_CODE);
        curl_close($request);

        if ($status !== 201 || !preg_match('/Location: (https:\/\/api\.tinify\.com\/output\/[^\s]+)/', $response, $matches)) {
            Logger::log("❌ TinyPNG compression failed: $filename. HTTP $status");
            return false;
        }

        $compressed_url = trim($matches[1]);

        $compressed = file_get_contents($compressed_url, false, stream_context_create([
            "http" => [
                "header" => "Authorization: Basic " . base64_encode("api:$api_key")
            ]
        ]));

        if (!$compressed) {
            Logger::log("❌ Failed to download compressed image from TinyPNG.");
            return false;
        }

        $original_size = filesize($file_path);
        file_put_contents($file_path, $compressed);
        $new_size = filesize($file_path);

        Logger::log("✅ TinyPNG success: $filename (" . size_format($original_size) . " → " . size_format($new_size) . ")");
        return true;
    }
}

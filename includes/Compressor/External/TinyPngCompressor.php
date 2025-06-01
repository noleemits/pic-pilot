<?php

namespace PicPilot\Compressor\External;

use PicPilot\Compressor\CompressorInterface;
use PicPilot\Logger;
use PicPilot\Settings;

class TinyPngCompressor implements CompressorInterface {
    public function compress($file_path): array {
        $settings = Settings::get();
        $api_key = $settings['tinypng_api_key'] ?? '';

        if (empty($api_key)) {
            Logger::log("❌ TinyPNG API key missing.");
            return $this->fail($file_path);
        }

        if (!file_exists($file_path) || !is_readable($file_path)) {
            Logger::log("❌ File not found or unreadable: $file_path");
            return $this->fail($file_path);
        }

        $original_size = filesize($file_path);
        if ($original_size > 5 * 1024 * 1024) {
            Logger::log("⚠️ Skipping file (over 5MB TinyPNG limit): $file_path");
            return $this->fail($file_path, $original_size);
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
        $header_size = curl_getinfo($request, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($request, CURLINFO_HTTP_CODE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        curl_close($request);
        Logger::log("🔍 TinyPNG response headers:\n" . trim($headers));

        // Parse Location header safely
        if (!preg_match('/location:\s*(https:\/\/api\.tinify\.com\/output\/[^\r\n]+)/i', $headers, $matches)) {
            Logger::log("❌ TinyPNG: Location header missing or malformed. HTTP $status\nHeaders:\n$headers");
            return $this->fail($file_path, $original_size);
        }
        $compressed_url = trim($matches[1]);
        Logger::log("📥 Download URL: $compressed_url"); // ✅ NEW LINE
        $download = curl_init();
        curl_setopt_array($download, [
            CURLOPT_URL => $compressed_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode("api:$api_key")
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 20,
        ]);

        $compressed = curl_exec($download);
        $http_code = curl_getinfo($download, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($download);
        curl_close($download);

        if ($http_code !== 200 || !$compressed) {
            Logger::log("❌ Failed to download optimized image. HTTP $http_code. cURL error: $curl_error");
            Logger::log("🔁 Download URL was: $compressed_url");
            return $this->fail($file_path, $original_size);
        }


        if (!$compressed) {
            Logger::log("❌ Failed to download compressed image from TinyPNG.");
            Logger::log("🔁 Download URL was: $compressed_url");
            return $this->fail($file_path, $original_size);
        }

        file_put_contents($file_path, $compressed);
        clearstatcache(true, $file_path);
        $new_size = filesize($file_path);
        $saved = max($original_size - $new_size, 0);

        Logger::log("✅ TinyPNG success: $filename (" . size_format($original_size) . " → " . size_format($new_size) . ")");
        Logger::log("🔁 Download URL was: $compressed_url");
        return [
            'success' => true,
            'original' => $original_size,
            'optimized' => $new_size,
            'saved' => $saved
        ];
    }

    private function fail(string $file_path, int $original_size = 0): array {
        if ($original_size <= 0 && file_exists($file_path)) {
            $original_size = filesize($file_path);
        }

        return [
            'success' => false,
            'original' => $original_size,
            'optimized' => $original_size,
            'saved' => 0
        ];
    }
}

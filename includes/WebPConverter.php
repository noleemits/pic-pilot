<?php

namespace PicPilot;

class WebPConverter {
    
    public static function convert(string $sourcePath, int $quality = 80): ?string {
        if (!file_exists($sourcePath)) {
            Logger::log("❌ WebP conversion failed: Source file doesn't exist: $sourcePath");
            return null;
        }

        // Check WebP support
        if (!self::hasWebPSupport()) {
            Logger::log("❌ WebP conversion failed: No WebP support available");
            return null;
        }

        $mime = mime_content_type($sourcePath);
        $destinationPath = self::getWebPPath($sourcePath);
        
        if (!$destinationPath) {
            Logger::log("❌ WebP conversion failed: Could not generate destination path for $sourcePath");
            return null;
        }

        Logger::log("🔄 Converting to WebP: $sourcePath → $destinationPath (quality: $quality)");

        // Try different conversion methods based on available extensions
        $success = false;

        // Method 1: Try Imagick first (better quality and features)
        if (extension_loaded('imagick')) {
            Logger::log("🎨 Using Imagick for WebP conversion");
            $success = self::convertWithImagick($sourcePath, $destinationPath, $quality, $mime);
        }

        // Method 2: Fallback to GD if Imagick failed or not available
        if (!$success && function_exists('imagewebp')) {
            Logger::log("🖼️ " . (extension_loaded('imagick') ? "Imagick failed, falling back to GD" : "Using GD for WebP conversion"));
            $success = self::convertWithGD($sourcePath, $destinationPath, $quality, $mime);
        }

        if ($success && file_exists($destinationPath)) {
            $original_size = filesize($sourcePath);
            $webp_size = filesize($destinationPath);
            $savings = $original_size - $webp_size;
            Logger::log("✅ WebP conversion successful: {$original_size} → {$webp_size} bytes (Saved: {$savings})");
            return $destinationPath;
        }

        Logger::log("❌ WebP conversion failed for $sourcePath");
        return null;
    }

    protected static function convertWithImagick(string $sourcePath, string $destinationPath, int $quality, string $mime): bool {
        try {
            Logger::log("🎨 Starting Imagick WebP conversion");
            $imagick = new \Imagick($sourcePath);
            
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            Logger::log("📏 Imagick source dimensions: {$width}x{$height}");
            
            // Set WebP format and quality
            $imagick->setImageFormat('webp');
            
            $imagick->setImageCompressionQuality($quality);
            
            // WebP natively supports transparency, so no special handling needed

            // Check if image has content before writing
            $imageBlob = $imagick->getImageBlob();
            $blobSize = strlen($imageBlob);
            Logger::log("🔍 Image blob size before WebP conversion: $blobSize bytes");
            
            Logger::log("🔄 About to write WebP with Imagick, quality: $quality");
            $success = $imagick->writeImage($destinationPath);
            
            if ($success) {
                $file_size = file_exists($destinationPath) ? filesize($destinationPath) : 0;
                Logger::log("✅ Imagick WebP file created successfully, size: $file_size bytes");
                
                // Check if file size is suspiciously small (less than 10KB for images larger than 100x100)
                if ($file_size < 10240 && ($width * $height) > 10000) {
                    Logger::log("⚠️ WebP file size seems too small, possible quality issue");
                }
            } else {
                Logger::log("❌ Imagick writeImage() returned false");
            }
            
            $imagick->clear();
            $imagick->destroy();
            
            return $success;
        } catch (\Exception $e) {
            Logger::log("❌ Imagick WebP conversion failed: " . $e->getMessage());
            return false;
        }
    }

    protected static function convertWithGD(string $sourcePath, string $destinationPath, int $quality, string $mime): bool {
        try {
            $image = null;
            
            // Load source image based on MIME type
            switch ($mime) {
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($sourcePath);
                    if ($image) {
                        $width = imagesx($image);
                        $height = imagesy($image);
                        Logger::log("📏 PNG dimensions: {$width}x{$height}");
                        
                        // WebP handles transparency natively, just ensure true color
                        imagepalettetotruecolor($image);
                        Logger::log("✅ PNG loaded for WebP conversion");
                    }
                    break;
                case 'image/gif':
                    $image = @imagecreatefromgif($sourcePath);
                    break;
                default:
                    Logger::log("❌ GD WebP conversion: Unsupported MIME type: $mime");
                    return false;
            }

            if (!$image) {
                Logger::log("❌ GD WebP conversion: Failed to create image from $sourcePath");
                return false;
            }

            Logger::log("🔄 About to create WebP with quality: $quality");
            $success = imagewebp($image, $destinationPath, $quality);
            
            if ($success) {
                $file_size = file_exists($destinationPath) ? filesize($destinationPath) : 0;
                Logger::log("✅ WebP file created successfully, size: $file_size bytes");
            } else {
                Logger::log("❌ imagewebp() function returned false");
            }
            
            imagedestroy($image);
            
            return $success;
        } catch (\Exception $e) {
            Logger::log("❌ GD WebP conversion failed: " . $e->getMessage());
            return false;
        }
    }

    public static function hasWebPSupport(): bool {
        // Check Imagick WebP support
        if (extension_loaded('imagick')) {
            $formats = array_map('strtolower', \Imagick::queryFormats());
            if (in_array('webp', $formats)) {
                return true;
            }
        }

        // Check GD WebP support
        if (function_exists('imagewebp') && function_exists('gd_info')) {
            $gd = gd_info();
            if (!empty($gd['WebP Support'])) {
                return true;
            }
        }

        return false;
    }

    public static function canConvert(string $sourcePath): bool {
        if (!file_exists($sourcePath)) {
            return false;
        }

        $mime = mime_content_type($sourcePath);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif']) && self::hasWebPSupport();
    }

    protected static function getWebPPath(string $sourcePath): ?string {
        $pathInfo = pathinfo($sourcePath);
        if (empty($pathInfo['dirname']) || empty($pathInfo['filename'])) {
            return null;
        }

        return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';
    }

    public static function getOriginalFormat(string $webpPath): ?string {
        // This will be used during restore to determine what format to restore to
        // We'll store this information in the backup manifest
        return null; // Implementation will depend on backup manifest data
    }
}
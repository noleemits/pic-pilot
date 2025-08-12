<?php

namespace PicPilot;

class PngToJpegConverter {
    public static function convert(string $sourcePath, int $quality = 85): ?string {
        if (!file_exists($sourcePath)) {
            return null;
        }

        $image = @imagecreatefrompng($sourcePath);
        if (!$image) {
            return null;
        }

        // Convert path from .png to .jpg
        $destinationPath = preg_replace('/\.png$/i', '.jpg', $sourcePath);
        if (!$destinationPath) {
            imagedestroy($image);
            return null;
        }

        // White background for images with transparency
        $width = imagesx($image);
        $height = imagesy($image);
        $jpegImage = imagecreatetruecolor($width, $height);

        $white = imagecolorallocate($jpegImage, 255, 255, 255);
        imagefill($jpegImage, 0, 0, $white);
        imagecopy($jpegImage, $image, 0, 0, 0, 0, $width, $height);

        $success = imagejpeg($jpegImage, $destinationPath, $quality);

        imagedestroy($image);
        imagedestroy($jpegImage);

        return $success ? $destinationPath : null;
    }
}

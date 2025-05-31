<?php

namespace PicPilot\Compressor;

class PngCompressorPlaceholder implements CompressorInterface {
    public function compress($file_path): bool {
        throw new \Exception("PNG compression not supported locally. Please connect an external API.");
    }
}

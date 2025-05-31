<?php

namespace PicPilot\Compressor;

interface CompressorInterface {
    public function compress($file_path): bool;
}

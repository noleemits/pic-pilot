<?php

namespace PicPilot;

class Logger {
    public static function log($msg) {
        error_log("[PicPilot] " . $msg);
    }
}

<?php

namespace PicPilot;

class Logger {
    public static function get_log_file_path(): string {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . 'pic-pilot/pic-pilot.log';
    }

    public static function get_recent_entries(int $limit = 100): array {
        $file = self::get_log_file_path();
        if (!file_exists($file)) return [];

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice(array_reverse($lines), 0, $limit);
    }

    public static function clear_log(): bool {
        $file = self::get_log_file_path();

        // Ensure directory exists
        wp_mkdir_p(dirname($file));

        $success = file_put_contents($file, '') !== false;

        self::log($success ? '🧼 Log cleared manually' : '❌ Failed to clear log file');

        return $success;
    }



    public static function log_is_too_large(int $max_size_mb = 5): bool {
        $file = self::get_log_file_path();
        return file_exists($file) && filesize($file) > ($max_size_mb * 1024 * 1024);
    }

    public static function truncate_log(): bool {
        $file = self::get_log_file_path();
        if (!file_exists($file)) return false;

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $keep = array_slice($lines, -2000); // Keep last 2,000 lines
        return file_put_contents($file, implode("\n", $keep) . "\n") !== false;
    }

    public static function log(string $message, array $context = []): void {
        if (!\PicPilot\Settings::is_enabled('enable_logging')) {
            return; // 🔕 Logging is disabled in settings
        }

        $file = self::get_log_file_path();
        wp_mkdir_p(dirname($file));

        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message;

        if (!empty($context)) {
            $entry .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $entry .= PHP_EOL;

        // Prevent infinite growth
        if (self::log_is_too_large()) {
            self::truncate_log();
        }

        @file_put_contents($file, $entry, FILE_APPEND);
    }

    /**
     * Log an array in a compact format (showing structure without full content)
     */
    public static function log_compact(string $message, array $data): void {
        if (!\PicPilot\Settings::is_enabled('enable_logging')) {
            return;
        }

        $compact = self::array_to_compact_string($data);
        self::log("$message $compact");
    }

    /**
     * Convert array to compact string representation
     */
    private static function array_to_compact_string(array $data, int $depth = 0): string {
        if ($depth > 3) return '[...deep]'; // Prevent infinite recursion
        
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $count = count($value);
                if ($count > 5) {
                    $result[] = "$key:[array($count)]";
                } else {
                    $result[] = "$key:" . self::array_to_compact_string($value, $depth + 1);
                }
            } else {
                $str_value = is_string($value) ? $value : (string)$value;
                if (strlen($str_value) > 50) {
                    $result[] = "$key:" . substr($str_value, 0, 50) . '...';
                } else {
                    $result[] = "$key:$str_value";
                }
            }
        }
        return '[' . implode(', ', $result) . ']';
    }
}

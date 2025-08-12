<?php

namespace PicPilot\Admin;

use PicPilot\Logger;

class LogViewer {
    public static function register() {
        add_submenu_page(
            'pic-pilot',
            __('Logs', 'pic-pilot'),
            __('Logs', 'pic-pilot'),
            'manage_options',
            'pic-pilot-logs',
            [self::class, 'render']
        );

        add_action('wp_ajax_pic_pilot_clear_log', [self::class, 'handle_clear']);
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;

        $log_entries = Logger::get_recent_entries(200);
        $log_file = Logger::get_log_file_path();
        $log_size = file_exists($log_file) ? size_format(filesize($log_file)) : '0 B';
        $nonce = wp_create_nonce('pic_pilot_clear_log');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Pic Pilot Logs', 'pic-pilot') . '</h1>';
        echo '<p>' . esc_html__("Current log size: $log_size", 'pic-pilot') . '</p>';
        echo '<p>' . esc_html__('After 5mb this file will clear automatically.', 'pic-pilot') . '</p>';
        echo '<div style="margin-bottom: 20px;">';
        echo '<form method="post" id="pic-pilot-clear-log-form" style="display: inline-block; margin-right: 10px;">';
        echo '<input type="hidden" name="action" value="pic_pilot_clear_log">';
        echo '<input type="hidden" name="_ajax_nonce" value="' . esc_attr($nonce) . '">';
        submit_button(__('Clear Log', 'pic-pilot'), 'delete', 'submit', false);
        echo '</form>';
        echo '<button type="button" id="pic-pilot-copy-log" class="button button-secondary">' . esc_html__('Copy to Clipboard', 'pic-pilot') . '</button>';
        echo '</div>';

        echo '<hr><h2>' . esc_html__('Recent Entries', 'pic-pilot') . '</h2>';
        echo '<pre id="pic-pilot-log-content" style="background:#fff; padding:1em; max-height:500px; overflow:auto;">';
        foreach ($log_entries as $entry) {
            echo esc_html($entry) . "\n";
        }
        echo '</pre>';
        echo '</div>';

        self::print_js();
    }

    public static function handle_clear() {
        check_ajax_referer('pic_pilot_clear_log');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $success = Logger::clear_log();
        if ($success) {
            wp_send_json_success('Log cleared');
        } else {
            wp_send_json_error('Could not clear log');
        }
    }

    protected static function print_js() {
        echo <<<JS
<script>
// Clear log functionality
document.getElementById('pic-pilot-clear-log-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(json => {
        if (json.success) {
            alert('✅ Log cleared!');
            location.reload();
        } else {
            alert('❌ Failed to clear log: ' + (json.data || 'unknown error'));
        }
    });
});

// Copy to clipboard functionality
document.getElementById('pic-pilot-copy-log').addEventListener('click', function() {
    const logContent = document.getElementById('pic-pilot-log-content').textContent;
    
    // Use the modern clipboard API if available
    if (navigator.clipboard) {
        navigator.clipboard.writeText(logContent).then(function() {
            alert('✅ Log copied to clipboard!');
        }).catch(function(err) {
            console.error('Failed to copy: ', err);
            fallbackCopyTextToClipboard(logContent);
        });
    } else {
        fallbackCopyTextToClipboard(logContent);
    }
});

// Fallback copy function for older browsers
function fallbackCopyTextToClipboard(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    textArea.style.top = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            alert('✅ Log copied to clipboard!');
        } else {
            alert('❌ Failed to copy log to clipboard');
        }
    } catch (err) {
        console.error('Fallback copy failed: ', err);
        alert('❌ Failed to copy log to clipboard');
    }
    
    document.body.removeChild(textArea);
}
</script>
JS;
    }
}

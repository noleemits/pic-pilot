<?php

namespace PicPilot\Admin;

use PicPilot\Settings;

class FormHelper {
    public static function checkbox(string $id, string $label, string $section = 'pic_pilot_main', array $args = [], string $page = 'pic-pilot') {
        add_settings_field(
            $id,
            $label,
            [self::class, 'render_checkbox'],
            $page,
            $section,
            array_merge(['label_for' => $id], $args)
        );
    }

    public static function input(string $id, string $label, string $type = 'text', string $section = 'pic_pilot_main', array $args = [], string $page = 'pic-pilot') {
        add_settings_field(
            $id,
            $label,
            [self::class, 'render_input'],
            $page,
            $section,
            array_merge(['label_for' => $id, 'type' => $type], $args)
        );
    }

    public static function radio(string $id, string $label, array $options, string $section = 'pic_pilot_main', array $args = [], string $page = 'pic-pilot') {
        add_settings_field(
            $id,
            $label,
            [self::class, 'render_radio'],
            $page,
            $section,
            array_merge(['label_for' => $id, 'options' => $options], $args)
        );
    }

    public static function render_checkbox($args) {
        $name = $args['label_for'];
        $value = Settings::is_enabled($name) ? '1' : '0';
        $description = $args['description'] ?? '';
        $wrapper_class = $args['wrapper_class'] ?? '';

        echo "<div class='$wrapper_class'>";
        echo "<input type='checkbox' id='$name' name='pic_pilot_options[$name]' value='1'" . checked($value, '1', false) . " />";
        if ($description) {
            echo "<p class='description'>$description</p>";
        }
        echo "</div>";
    }

    public static function render_input($args) {
        $name = $args['label_for'] ?? '';
        $type = $args['type'] ?? 'text';
        $placeholder = $args['placeholder'] ?? '';
        $description = $args['description'] ?? '';
        $wrapper_class = $args['wrapper_class'] ?? '';
        $min = isset($args['min']) ? "min='{$args['min']}'" : '';
        $max = isset($args['max']) ? "max='{$args['max']}'" : '';

        $options = get_option('pic_pilot_options', []);
        $value = $options[$name] ?? '';
        $value = is_array($value) ? '' : esc_attr($value);

        echo "<div class='$wrapper_class'>";
        echo "<input type='$type' id='$name' name='pic_pilot_options[$name]' value='$value' placeholder='$placeholder' class='regular-text' $min $max />";
        if ($description) {
            echo "<p class='description'>$description</p>";
        }
        echo "</div>";
    }

    public static function render_radio($args) {
        $name = $args['label_for'];
        $options = $args['options'] ?? [];
        $description = $args['description'] ?? '';
        $wrapper_class = $args['wrapper_class'] ?? '';
        $current_value = Settings::get($name, '');

        echo "<div class='$wrapper_class pic-pilot-radio-group'>";
        foreach ($options as $value => $label) {
            $checked = checked($current_value, $value, false);
            echo "<label class='pic-pilot-radio-option' style='display: block; margin-bottom: 12px; padding: 12px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; transition: all 0.2s ease;'>";
            echo "<input type='radio' name='pic_pilot_options[$name]' value='$value' $checked style='margin-right: 8px;' /> ";
            echo wp_kses($label, [
                'br' => [],
                'small' => ['style' => []],
                'strong' => [],
                'em' => [],
                'span' => ['style' => []]
            ]);
            echo "</label>";
        }
        if ($description) {
            echo "<div class='description' style='margin-top: 12px;'>$description</div>";
        }
        echo "</div>";
    }
}

<?php

declare(strict_types=1);

namespace FarosCMS;

/** The form editor's field definitions: which types exist and how submitted field rows are cleaned before they are stored. */
final class FormFields
{
    /** @return string[] */
    public static function types(): array
    {
        return [
            'text',
            'email',
            'textarea',
            'number',
            'tel',
            'url',
            'date',
            'time',
            'datetime-local',
            'select',
            'radio',
            'checkbox',
            'checkboxes',
            'hidden',
            'color',
            'range',
        ];
    }

    public static function sanitizeName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace(' ', '-', $value);
        return Slug::plain($value);
    }

    /** @return string[] */
    public static function parseOptions(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $parts = preg_split('/\R|,/', $value) ?: [];
        $options = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $options[] = $part;
        }
        return $options;
    }

    /** @return array<int, array<string, mixed>> */
    public static function parseInput(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $fields = [];
        $allowedTypes = self::types();
        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $name = self::sanitizeName((string)($row['name'] ?? ''));
            if ($name === '' || str_starts_with($name, 'form_')) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                $label = Slug::title($name);
            }
            $field = [
                'type' => $type,
                'name' => $name,
                'label' => $label,
            ];
            if (Format::isTruthy($row['required'] ?? false)) {
                $field['required'] = true;
            }
            $placeholder = trim((string)($row['placeholder'] ?? ''));
            if ($placeholder !== '') {
                $field['placeholder'] = $placeholder;
            }
            $options = self::parseOptions((string)($row['options'] ?? ''));
            if (!empty($options) && in_array($type, ['select', 'radio', 'checkboxes'], true)) {
                $field['options'] = $options;
            }
            $default = (string)($row['default'] ?? '');
            if ($default !== '') {
                $field['default'] = $default;
            }
            $help = trim((string)($row['help'] ?? ''));
            if ($help !== '') {
                $field['help'] = $help;
            }
            $rows = (int)($row['rows'] ?? 4);
            if ($type === 'textarea' && $rows > 0) {
                $field['rows'] = $rows;
            }
            $min = trim((string)($row['min'] ?? ''));
            if ($min !== '') {
                $field['min'] = $min;
            }
            $max = trim((string)($row['max'] ?? ''));
            if ($max !== '') {
                $field['max'] = $max;
            }
            $step = trim((string)($row['step'] ?? ''));
            if ($step !== '') {
                $field['step'] = $step;
            }
            $fields[] = $field;
        }
        return $fields;
    }
}

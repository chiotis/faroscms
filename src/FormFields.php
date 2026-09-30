<?php

declare(strict_types=1);

namespace FarosCMS;

/** The form editor's field definitions: which types exist, how submitted field rows are cleaned before they are stored, and how stored ones are read back for the form on the site and for the editor. */
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

    /** @return array<int, array<string, mixed>> */
    public static function normalize(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $normalized = [];
        $allowedTypes = self::types();
        foreach ($fields as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $name = self::sanitizeName((string)($row['name'] ?? ''));
            if ($name === '') {
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
                'required' => Format::isTruthy($row['required'] ?? false),
                'placeholder' => (string)($row['placeholder'] ?? ''),
                'help' => (string)($row['help'] ?? ''),
                'default' => $row['default'] ?? '',
                'options' => self::normalizeOptions($row['options'] ?? []),
                'rows' => (int)($row['rows'] ?? 4),
                'min' => (string)($row['min'] ?? ''),
                'max' => (string)($row['max'] ?? ''),
                'step' => (string)($row['step'] ?? ''),
            ];
            $normalized[] = $field;
        }
        return $normalized;
    }

    /** @return array<int, array<string, mixed>> */
    public static function forAdmin(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $normalized = [];
        $allowedTypes = self::types();
        foreach ($fields as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $name = self::sanitizeName((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                $label = Slug::title($name);
            }
            $options = '';
            if (isset($row['options'])) {
                if (is_array($row['options'])) {
                    $options = implode(', ', array_map('strval', $row['options']));
                } else {
                    $options = (string)$row['options'];
                }
            }
            $normalized[] = [
                'id' => 'field_' . $index,
                'type' => $type,
                'name' => $name,
                'label' => $label,
                'required' => Format::isTruthy($row['required'] ?? false),
                'placeholder' => (string)($row['placeholder'] ?? ''),
                'help' => (string)($row['help'] ?? ''),
                'default' => (string)($row['default'] ?? ''),
                'options' => $options,
                'rows' => (string)($row['rows'] ?? 4),
                'min' => (string)($row['min'] ?? ''),
                'max' => (string)($row['max'] ?? ''),
                'step' => (string)($row['step'] ?? ''),
            ];
        }
        return $normalized;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function normalizeOptions(mixed $value): array
    {
        $raw = [];
        if (is_array($value)) {
            $raw = $value;
        } elseif (is_string($value)) {
            $raw = preg_split('/\R|,/', $value) ?: [];
        }
        $options = [];
        foreach ($raw as $option) {
            $option = trim((string)$option);
            if ($option === '') {
                continue;
            }
            $value = $option;
            $label = $option;
            if (str_contains($option, '|')) {
                [$value, $label] = array_map('trim', explode('|', $option, 2));
            } elseif (str_contains($option, ':')) {
                [$value, $label] = array_map('trim', explode(':', $option, 2));
            }
            if ($label === '') {
                $label = $value;
            }
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }
        return $options;
    }
}

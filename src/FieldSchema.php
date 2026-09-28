<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * Declarative field definitions for theme settings and content blocks (content types later).
 *
 * A definition list is a map of key => definition. Values are always checked against the
 * definition: a missing value takes the field default and an invalid one falls back to it, so a
 * stored value can never push unexpected markup or CSS into a template.
 */
final class FieldSchema
{
    public const TYPES = ['text', 'textarea', 'markdown', 'email', 'url', 'link', 'image', 'color', 'number', 'select', 'toggle', 'repeater'];

    /**
     * @param array<string, mixed> $definitions raw map of key => definition
     * @return array<string, array<string, mixed>>
     */
    public static function normalize(array $definitions): array
    {
        $fields = [];
        foreach ($definitions as $key => $definition) {
            $key = (string)$key;
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $key) || !is_array($definition)) {
                continue;
            }
            $type = strtolower((string)($definition['type'] ?? 'text'));
            if (!in_array($type, self::TYPES, true)) {
                $type = 'text';
            }
            $field = [
                'key' => $key,
                'type' => $type,
                'label' => (string)($definition['label'] ?? ucfirst(str_replace('_', ' ', $key))),
                'help' => (string)($definition['help'] ?? ''),
                'placeholder' => (string)($definition['placeholder'] ?? ''),
                'span' => ($definition['span'] ?? '') === 'full' ? 'full' : '',
                'hidden' => ($definition['hidden'] ?? false) === true,
                'required' => ($definition['required'] ?? false) === true,
            ];
            if ($type === 'select') {
                $field['options'] = self::normalizeOptions($definition['options'] ?? []);
            }
            if ($type === 'number' || $type === 'repeater') {
                $field['min'] = isset($definition['min']) && is_numeric($definition['min']) ? (int)$definition['min'] : null;
                $field['max'] = isset($definition['max']) && is_numeric($definition['max']) ? (int)$definition['max'] : null;
            }
            if ($type === 'repeater') {
                // Repeater items are flat: nested repeaters are not supported.
                $subDefinitions = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
                $field['fields'] = array_filter(
                    self::normalize($subDefinitions),
                    static fn(array $sub): bool => $sub['type'] !== 'repeater'
                );
                $field['item_label'] = (string)($definition['item_label'] ?? 'Item');
            }
            $field['default'] = self::clean($field, $definition['default'] ?? null, true);
            $fields[$key] = $field;
        }
        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $fields normalized definitions
     * @return array<string, mixed>
     */
    public static function defaults(array $fields): array
    {
        $values = [];
        foreach ($fields as $key => $field) {
            $values[$key] = $field['default'];
        }
        return $values;
    }

    /**
     * Checks stored values: each defined field gets a valid value, other keys are left alone.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function resolve(array $fields, array $values): array
    {
        foreach ($fields as $key => $field) {
            $values[$key] = self::clean($field, $values[$key] ?? null);
        }
        return $values;
    }

    /**
     * Reads submitted form values. Fields the form did not render (hidden ones) keep their current
     * value; a visible toggle that was not submitted is off.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function fromInput(array $fields, array $input, array $current): array
    {
        $values = $current;
        foreach ($fields as $key => $field) {
            if ($field['hidden']) {
                $values[$key] = self::clean($field, $current[$key] ?? null);
                continue;
            }
            if ($field['type'] === 'toggle') {
                $values[$key] = self::isTruthy($input[$key] ?? false);
                continue;
            }
            $currentValue = self::clean($field, $current[$key] ?? null);
            // An invalid submission keeps the current valid value instead of resetting to the default.
            $values[$key] = array_key_exists($key, $input)
                ? self::sanitize($field, $input[$key], $currentValue)
                : $currentValue;
        }
        return $values;
    }

    /** @param array<string, mixed> $field */
    public static function clean(array $field, mixed $value, bool $isDefault = false): mixed
    {
        if ($isDefault) {
            if ($value === null && $field['type'] === 'select') {
                return (string)(array_key_first($field['options']) ?? '');
            }
            $value = self::sanitize($field, $value, self::emptyValue($field));
            // A declared default that is not one of the options means the first option.
            if ($field['type'] === 'select' && !array_key_exists($value, $field['options'])) {
                return (string)(array_key_first($field['options']) ?? '');
            }
            return $value;
        }
        return self::sanitize($field, $value, $field['default']);
    }

    /** @param array<string, mixed> $field */
    private static function sanitize(array $field, mixed $value, mixed $fallback): mixed
    {
        if ($value === null) {
            return $fallback;
        }

        switch ($field['type']) {
            case 'toggle':
                return is_bool($value) ? $value : self::isTruthy($value);

            case 'number':
                if (!is_numeric($value)) {
                    return $fallback;
                }
                $number = (int)$value;
                if ($field['min'] !== null) {
                    $number = max($field['min'], $number);
                }
                if ($field['max'] !== null) {
                    $number = min($field['max'], $number);
                }
                return $number;

            case 'select':
                $value = is_scalar($value) ? (string)$value : '';
                return array_key_exists($value, $field['options']) ? $value : $fallback;

            case 'color':
                $value = is_scalar($value) ? trim((string)$value) : '';
                return $value === '' || self::isSafeColor($value) ? $value : $fallback;

            case 'image':
            case 'url':
                $value = is_scalar($value) ? trim((string)$value) : '';
                return $value === '' || self::isSafeUrl($value) ? $value : $fallback;

            case 'link':
                $value = is_scalar($value) ? trim((string)$value) : '';
                return $value === '' || self::isSafeLink($value) ? $value : $fallback;

            case 'repeater':
                if (!is_array($value)) {
                    return $fallback;
                }
                $items = [];
                foreach (array_values($value) as $item) {
                    if (is_array($item)) {
                        $items[] = self::resolve($field['fields'], array_intersect_key($item, $field['fields']));
                    }
                }
                return $field['max'] !== null ? array_slice($items, 0, max(0, $field['max'])) : $items;

            case 'markdown':
            case 'textarea':
                if (!is_scalar($value)) {
                    return $fallback;
                }
                return self::limit(str_replace(["\r\n", "\r"], "\n", trim((string)$value)), 10000);

            default:
                if (!is_scalar($value)) {
                    return $fallback;
                }
                return self::limit(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$value) ?? ''), 2000);
        }
    }

    public static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)(is_scalar($value) ? $value : ''))), ['1', 'true', 'yes', 'on'], true);
    }

    /** Colours end up in inline style attributes, so only plain colour syntax is accepted. */
    public static function isSafeColor(string $value): bool
    {
        return (bool)preg_match(
            '/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s\/deg]+\)|var\(--[a-z0-9-]+\)|[a-z]{3,30})$/i',
            $value
        );
    }

    /** URLs end up inside CSS url('...') and attributes, so quotes, brackets and spaces are refused. */
    public static function isSafeUrl(string $value): bool
    {
        if (preg_match('/[\s"\'()<>\\\\]/', $value)) {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value)) {
            return (bool)preg_match('#^https?://#i', $value);
        }
        return true;
    }

    /**
     * Links may also point inside the page (#id), to email, or to a phone number; script and data
     * schemes, quotes, brackets, and whitespace are refused.
     */
    public static function isSafeLink(string $value): bool
    {
        if (preg_match('/[\s"\'<>\\\\]/', $value)) {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value)) {
            return (bool)preg_match('#^(https?://|mailto:|tel:)#i', $value);
        }
        return true;
    }

    /** @return array<string, string> */
    private static function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }
        $normalized = [];
        // List form [a, b] means value a with label "A"; a map (even with numeric keys) is value => label.
        $isList = array_is_list($options);
        foreach ($options as $value => $label) {
            if ($isList) {
                $value = (string)$label;
                if ($value === '') {
                    continue;
                }
                $label = ucfirst(str_replace(['_', '-'], ' ', $value));
            }
            $normalized[(string)$value] = (string)$label;
        }
        return $normalized;
    }

    /** @param array<string, mixed> $field */
    private static function emptyValue(array $field): mixed
    {
        return match ($field['type']) {
            'toggle' => false,
            'number' => $field['min'] ?? 0,
            'repeater' => [],
            default => '',
        };
    }

    private static function limit(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}

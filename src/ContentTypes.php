<?php

declare(strict_types=1);

namespace FarosCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Definitions of content types: the fields an editor fills in and how the type is listed.
 *
 * A type is still a folder in content/. A definition adds to it and is optional:
 *   themes/<theme>/content-types/<type>.yaml   ships with the theme
 *   custom/content-types/<type>.yaml           belongs to the site, kept across updates
 * A site file for a type the theme also defines is merged in: fields are added or changed one by
 * one (mark a theme field `hidden: true` to retire it), and archive settings replace the theme's.
 *
 *   label / singular / description   text, or a map per language (el: …, en: …)
 *   fields:                          key => definition, see FieldSchema (repeaters are not supported)
 *     filterable: true               select fields only: offered as a filter in the archive
 *     card: true                     shown on the item's card
 *     show: false                    not shown on the item's own page
 *   archive:
 *     title, subtitle                text or a map per language
 *     layout, columns, per_page, order (newest, oldest, title, or field:<key>:asc|desc), show_image,
 *     show_excerpt, show_date, show_meta
 *     taxonomies: [categories]       taxonomies offered as filters
 *
 * Values are stored in the item's front matter under `custom_fields`, and are always checked
 * against the definition before they reach a template.
 */
final class ContentTypes
{
    public const LAYOUTS = [
        'cards' => 'Cards',
        'list' => 'Minimal list',
        'compact' => 'Compact with thumbnails',
        'overlay' => 'Image tiles with text on top',
        'featured' => 'Featured story and a list',
        'magazine' => 'Magazine',
        'editorial' => 'Editorial',
    ];

    public const ORDERS = [
        'date_desc' => 'Newest first',
        'date_asc' => 'Oldest first',
        'title_asc' => 'Title A–Z',
        'title_desc' => 'Title Z–A',
    ];

    /** Field types an archive can be ordered by. */
    public const SORTABLE_TYPES = ['date', 'number', 'decimal', 'text'];

    public const FIELD_TYPES = ['text', 'textarea', 'markdown', 'email', 'url', 'link', 'image', 'color', 'number', 'decimal', 'date', 'select', 'toggle'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $raw = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $themeRaw = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $customRaw = null;
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $archiveSchema = null;

    public function __construct(private Theme $theme)
    {
    }

    /** Types that have a definition. @return string[] */
    public function defined(): array
    {
        return array_keys($this->rawAll());
    }

    public function has(string $type): bool
    {
        return isset($this->rawAll()[$type]);
    }

    /** 'theme', 'custom', 'theme+custom', or '' for a type without a definition. */
    public function origin(string $type): string
    {
        return (string)($this->rawAll()[$type]['_origin'] ?? '');
    }

    /**
     * The definition of a type with texts chosen for a language. Types without a file still get
     * a usable definition (no fields, default archive), so callers need no special cases.
     *
     * @return array{type: string, origin: string, label: string, singular: string, description: string, fields: array<string, array<string, mixed>>, archive: array<string, mixed>}
     */
    public function definition(string $type, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $key = $type . '|' . $lang . '|' . $defaultLang;
        return $this->cache[$key] ??= $this->build($type, $this->rawAll()[$type] ?? [], $lang, $defaultLang);
    }

    /**
     * The definition as the theme alone ships it, without the site's file. The admin compares
     * against it so only real differences are written to custom/.
     *
     * @return array{type: string, origin: string, label: string, singular: string, description: string, fields: array<string, array<string, mixed>>, archive: array<string, mixed>}
     */
    public function themeDefinition(string $type, string $lang = 'en', string $defaultLang = 'en'): array
    {
        return $this->build($type, $this->loadDir($this->theme->path() . '/content-types', 'theme')[$type] ?? [], $lang, $defaultLang);
    }

    /** What the site's own file for a type holds (empty when there is none). @return array<string, mixed> */
    public function customRaw(string $type): array
    {
        $raw = $this->loadDir($this->theme->customPath() . '/content-types', 'custom')[$type] ?? [];
        unset($raw['_origin']);
        return $raw;
    }

    /** Raw definition as merged from theme and site, before texts are chosen. @return array<string, mixed> */
    public function raw(string $type): array
    {
        return $this->rawAll()[$type] ?? [];
    }

    /**
     * Writes the site's definition of a type to custom/content-types/<type>.yaml, or removes the
     * file when there is nothing left to say. Returns false when the file cannot be written.
     *
     * @param array<string, mixed> $definition
     */
    public function saveCustom(string $type, array $definition): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $type)) {
            return false;
        }
        $dir = $this->theme->customPath() . '/content-types';
        $file = $dir . '/' . $type . '.yaml';
        $definition = array_filter($definition, static fn(mixed $value): bool => $value !== [] && $value !== '' && $value !== null);
        $this->raw = $this->customRaw = null;
        $this->cache = [];
        if ($definition === []) {
            return !is_file($file) || @unlink($file);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $yaml = "# Written by Admin > Content types. Kept across updates; theme definitions are merged underneath.\n"
            . Yaml::dump($definition, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        return @file_put_contents($file, $yaml, LOCK_EX) !== false;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{type: string, origin: string, label: string, singular: string, description: string, fields: array<string, array<string, mixed>>, archive: array<string, mixed>}
     */
    private function build(string $type, array $raw, string $lang, string $defaultLang): array
    {
        $pick = static fn(mixed $value): mixed => self::pick($value, $lang, $defaultLang);

        $label = trim((string)$pick($raw['label'] ?? ''));
        $label = $label !== '' ? $label : ucfirst($type);
        $singular = trim((string)$pick($raw['singular'] ?? ''));

        $fields = [];
        foreach (is_array($raw['fields'] ?? null) ? $raw['fields'] : [] as $fieldKey => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            foreach (['label', 'help', 'placeholder'] as $text) {
                if (isset($definition[$text])) {
                    $definition[$text] = (string)$pick($definition[$text]);
                }
            }
            if (is_array($definition['options'] ?? null)) {
                foreach ($definition['options'] as $value => $optionLabel) {
                    $definition['options'][$value] = is_array($optionLabel) ? (string)$pick($optionLabel) : $optionLabel;
                }
            }
            $normalized = FieldSchema::normalize([$fieldKey => $definition])[(string)$fieldKey] ?? null;
            if ($normalized === null || !in_array($normalized['type'], self::FIELD_TYPES, true)) {
                continue;
            }
            $normalized['filterable'] = ($definition['filterable'] ?? false) === true && $normalized['type'] === 'select';
            $normalized['card'] = ($definition['card'] ?? false) === true;
            $normalized['show'] = ($definition['show'] ?? true) !== false;
            $fields[(string)$fieldKey] = $normalized;
        }

        $archive = is_array($raw['archive'] ?? null) ? $raw['archive'] : [];
        $archiveValues = FieldSchema::resolve(self::archiveSchema(), array_intersect_key($archive, self::archiveSchema()));
        $archiveValues['title'] = trim((string)$pick($archive['title'] ?? ''));
        $archiveValues['subtitle'] = trim((string)$pick($archive['subtitle'] ?? ''));
        $archiveValues['taxonomies'] = array_values(array_filter(
            array_map('strval', is_array($archive['taxonomies'] ?? null) ? $archive['taxonomies'] : []),
            static fn(string $name): bool => (bool)preg_match('/^[a-z][a-z0-9_-]*$/', $name)
        ));

        // Ordering by a declared field: "field:<key>:asc" or "field:<key>:desc".
        $order = (string)($archive['order'] ?? '');
        if (preg_match('/^field:([a-z][a-z0-9_]*):(asc|desc)$/', $order, $m)
            && isset($fields[$m[1]]) && !$fields[$m[1]]['hidden']
            && in_array($fields[$m[1]]['type'], self::SORTABLE_TYPES, true)) {
            $archiveValues['order'] = $order;
        }

        return [
            'type' => $type,
            'origin' => (string)($raw['_origin'] ?? ''),
            'label' => $label,
            'singular' => $singular !== '' ? $singular : $label,
            'description' => trim((string)$pick($raw['description'] ?? '')),
            'fields' => $fields,
            'archive' => $archiveValues,
        ];
    }

    /**
     * Fields an editor can fill in (retired fields excluded).
     *
     * @return array<string, array<string, mixed>>
     */
    public function editableFields(string $type, string $lang = 'en', string $defaultLang = 'en'): array
    {
        return array_filter(
            $this->definition($type, $lang, $defaultLang)['fields'],
            static fn(array $field): bool => !$field['hidden']
        );
    }

    /**
     * Stored values of the declared fields, checked against the definition. Fields without a
     * value are left out.
     *
     * @param array<string, mixed> $meta front matter of the item
     * @return array<string, mixed>
     */
    public function values(string $type, array $meta, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $stored = is_array($meta['custom_fields'] ?? null) ? $meta['custom_fields'] : [];
        $values = [];
        foreach ($this->editableFields($type, $lang, $defaultLang) as $key => $field) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            $raw = $stored[$key];
            if ($field['type'] === 'date' && is_int($raw)) {
                // An unquoted YAML date is read as a timestamp.
                $raw = gmdate('Y-m-d', $raw);
            }
            $value = FieldSchema::clean($field, $raw);
            if (self::isEmpty($field, $value)) {
                continue;
            }
            $values[$key] = $value;
        }
        return $values;
    }

    /**
     * Fields of an item ready to print: label, checked value, and a text to show. Empty values
     * are skipped, and so are fields not meant for the context.
     *
     * @param array<string, mixed> $meta
     * @param 'page'|'card' $context
     * @param \Closure(string): string $formatDate
     * @return array<int, array{key: string, label: string, type: string, value: mixed, display: string}>
     */
    public function display(string $type, array $meta, string $context, \Closure $formatDate, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $entries = [];
        $values = $this->values($type, $meta, $lang, $defaultLang);
        foreach ($this->editableFields($type, $lang, $defaultLang) as $key => $field) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            if ($context === 'card' ? !$field['card'] : !$field['show']) {
                continue;
            }
            $value = $values[$key];
            $display = match ($field['type']) {
                'select' => (string)($field['options'][(string)$value] ?? $value),
                'toggle' => $value === true ? 'yes' : '',
                'date' => $formatDate((string)$value),
                'url' => (string)(preg_replace('#^www\.#i', '', (string)parse_url((string)$value, PHP_URL_HOST)) ?: $value),
                'link' => (string)preg_replace('#^(mailto:|tel:|https?://(www\.)?)#i', '', (string)$value),
                default => (string)$value,
            };
            $entries[] = ['key' => $key, 'label' => $field['label'], 'type' => $field['type'], 'value' => $value, 'display' => $display];
        }
        return $entries;
    }

    /**
     * Values to store for the declared fields from a submitted form. Empty values are omitted so
     * front matter stays tidy; retired fields keep whatever they had.
     *
     * @param array<string, mixed> $input submitted values by field key
     * @param array<string, mixed> $existing the item's current custom_fields
     * @return array<string, mixed>
     */
    public function fromInput(string $type, array $input, array $existing, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $store = [];
        foreach ($this->definition($type, $lang, $defaultLang)['fields'] as $key => $field) {
            if ($field['hidden']) {
                if (array_key_exists($key, $existing)) {
                    $store[$key] = $existing[$key];
                }
                continue;
            }
            if ($field['type'] === 'toggle') {
                if (FieldSchema::isTruthy($input[$key] ?? false)) {
                    $store[$key] = true;
                }
                continue;
            }
            $raw = $input[$key] ?? '';
            if (!is_scalar($raw) || trim((string)$raw) === '') {
                continue;
            }
            if (in_array($field['type'], ['number', 'decimal'], true) && !is_numeric($raw)) {
                continue;
            }
            $value = FieldSchema::clean($field, $raw);
            if (!self::isEmpty($field, $value)) {
                $store[$key] = $value;
            }
        }
        return $store;
    }

    /** Keys of every declared field, including retired ones. @return string[] */
    public function declaredKeys(string $type): array
    {
        return array_map('strval', array_keys($this->definition($type)['fields']));
    }

    /**
     * Orders an archive can use: the fixed ones plus one pair per sortable declared field.
     *
     * @param array<string, array<string, mixed>> $fields normalized fields of the type
     * @return array<string, string> value => label
     */
    public static function orderOptions(array $fields): array
    {
        $options = self::ORDERS;
        foreach ($fields as $key => $field) {
            if ($field['hidden'] || !in_array($field['type'], self::SORTABLE_TYPES, true)) {
                continue;
            }
            $ascending = $field['type'] === 'date' ? 'earliest first' : ($field['type'] === 'text' ? 'A–Z' : 'lowest first');
            $descending = $field['type'] === 'date' ? 'latest first' : ($field['type'] === 'text' ? 'Z–A' : 'highest first');
            $options['field:' . $key . ':asc'] = $field['label'] . ': ' . $ascending;
            $options['field:' . $key . ':desc'] = $field['label'] . ': ' . $descending;
        }
        return $options;
    }

    /** @return array<string, array<string, mixed>> */
    public static function archiveSchema(): array
    {
        return self::$archiveSchema ??= FieldSchema::normalize([
            'layout' => ['type' => 'select', 'label' => 'Layout', 'default' => 'cards', 'options' => self::LAYOUTS],
            'columns' => ['type' => 'select', 'label' => 'Columns', 'default' => '3', 'options' => ['2' => '2', '3' => '3', '4' => '4']],
            'per_page' => ['type' => 'number', 'label' => 'Items per page', 'default' => 12, 'min' => 0, 'max' => 60],
            'order' => ['type' => 'select', 'label' => 'Order', 'default' => 'date_desc', 'options' => self::ORDERS],
            'show_image' => ['type' => 'toggle', 'label' => 'Show images', 'default' => true],
            'show_excerpt' => ['type' => 'toggle', 'label' => 'Show the excerpt', 'default' => true],
            'show_date' => ['type' => 'toggle', 'label' => 'Show the date', 'default' => true],
            'show_meta' => ['type' => 'toggle', 'label' => 'Show category and card fields', 'default' => true],
        ]);
    }

    /**
     * Picks the text for a language from a plain value or a map per language.
     */
    public static function pick(mixed $value, string $lang, string $defaultLang): mixed
    {
        if (!is_array($value) || array_is_list($value)) {
            return $value;
        }
        return $value[$lang] ?? $value[$defaultLang] ?? $value['en'] ?? reset($value);
    }

    /** @param array<string, mixed> $field */
    private static function isEmpty(array $field, mixed $value): bool
    {
        return match ($field['type']) {
            'toggle' => $value !== true,
            'number', 'decimal' => !is_int($value) && !is_float($value),
            default => $value === '' || $value === null,
        };
    }

    /**
     * Theme and site definitions merged by type.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rawAll(): array
    {
        if ($this->raw !== null) {
            return $this->raw;
        }
        $this->themeRaw ??= $this->loadDir($this->theme->path() . '/content-types', 'theme');
        $this->customRaw ??= $this->loadDir($this->theme->customPath() . '/content-types', 'custom');
        $merged = $this->themeRaw;
        foreach ($this->customRaw as $type => $definition) {
            $definition['_origin'] = isset($merged[$type]) ? ($merged[$type]['_origin'] . '+custom') : 'custom';
            $merged[$type] = isset($merged[$type]) ? self::merge($merged[$type], $definition) : $definition;
        }
        ksort($merged);
        return $this->raw = $merged;
    }

    /**
     * Definitions found in one folder, by type.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadDir(string $dir, string $origin): array
    {
        $found = [];
        $files = glob($dir . '/*.yaml') ?: [];
        sort($files);
        foreach ($files as $file) {
            $type = basename($file, '.yaml');
            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $type)) {
                continue;
            }
            try {
                $definition = Yaml::parseFile($file);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($definition)) {
                $definition['_origin'] = $origin;
                $found[$type] = $definition;
            }
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if ($key === 'fields' && is_array($value)) {
                $fields = is_array($base['fields'] ?? null) ? $base['fields'] : [];
                foreach ($value as $fieldKey => $definition) {
                    $fields[$fieldKey] = is_array($definition) && is_array($fields[$fieldKey] ?? null)
                        ? array_replace($fields[$fieldKey], $definition)
                        : $definition;
                }
                $base['fields'] = $fields;
            } elseif ($key === 'archive' && is_array($value)) {
                $base['archive'] = array_replace(is_array($base['archive'] ?? null) ? $base['archive'] : [], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}

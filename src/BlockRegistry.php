<?php

declare(strict_types=1);

namespace FarosCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Block definitions from themes/<theme>/blocks/<type>/block.yaml, plus new block types a site
 * adds in custom/blocks/<type>/block.yaml. A theme block's definition cannot be replaced from
 * custom/ (its template and CSS can be overridden or extended by path instead).
 *
 * block.yaml keys: label, description, variants (value: label), tone, spacing, fields.
 * Field extras understood here: `type: icon` (select from the icon set) and
 * `options_from: <source>` (select options supplied by the application, e.g. content types).
 */
final class BlockRegistry
{
    public const TONES = ['default' => 'Default', 'muted' => 'Muted', 'contrast' => 'Contrast', 'accent' => 'Accent'];
    public const SPACINGS = ['default' => 'Default', 'compact' => 'Compact', 'spacious' => 'Spacious', 'none' => 'None'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $definitions = null;

    /**
     * @param array<string, \Closure(): array<string, string>> $optionSources
     */
    public function __construct(private Theme $theme, private array $optionSources = [])
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }
        $definitions = [];
        foreach ($this->theme->blockRoots() as $origin => $root) {
            foreach (glob($root . '/*/block.yaml') ?: [] as $file) {
                $type = basename(dirname($file));
                if (!preg_match('/^[a-z][a-z0-9-]*$/', $type) || isset($definitions[$type])) {
                    continue;
                }
                $definition = $this->load($type, $file, $origin);
                if ($definition !== null) {
                    $definitions[$type] = $definition;
                }
            }
        }
        ksort($definitions);
        return $this->definitions = $definitions;
    }

    /** @return array<string, mixed>|null */
    public function get(string $type): ?array
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * Definitions for the admin block editor: fields with defaults, and select options as ordered
     * [value, label] pairs so JSON keeps their order and string values.
     *
     * @return array<int, array<string, mixed>>
     */
    public function editorDefinitions(): array
    {
        $prepare = static function (array $fields) use (&$prepare): array {
            $list = [];
            foreach ($fields as $field) {
                if (isset($field['options'])) {
                    $pairs = [];
                    foreach ($field['options'] as $value => $label) {
                        $pairs[] = [(string)$value, (string)$label];
                    }
                    $field['options'] = $pairs;
                }
                if (isset($field['fields'])) {
                    $field['fields'] = $prepare($field['fields']);
                }
                $list[] = $field;
            }
            return $list;
        };
        $definitions = [];
        foreach ($this->all() as $definition) {
            $definitions[] = [
                'type' => $definition['type'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'origin' => $definition['origin'],
                'common' => $prepare($definition['common']),
                'fields' => $prepare($definition['fields']),
            ];
        }
        return $definitions;
    }

    /**
     * Blocks as they should be written to front matter: known blocks keep only defined fields,
     * checked, with values that equal the field default left out; unknown block types (for
     * example from a removed custom block) are kept unchanged so no content is lost.
     *
     * @param array<int, mixed> $rawBlocks
     * @return array<int, array<string, mixed>>
     */
    public function sanitizeForStorage(array $rawBlocks): array
    {
        $blocks = [];
        foreach ($rawBlocks as $raw) {
            if (!is_array($raw) || !is_string($raw['type'] ?? null) || !preg_match('/^[a-z][a-z0-9-]*$/', $raw['type'])) {
                continue;
            }
            $definition = $this->get($raw['type']);
            if ($definition === null) {
                $blocks[] = $raw;
                continue;
            }
            $block = ['type' => $raw['type']];
            foreach ([$definition['common'], $definition['fields']] as $fields) {
                foreach ($fields as $key => $field) {
                    if (!array_key_exists($key, $raw)) {
                        continue;
                    }
                    $value = FieldSchema::clean($field, $raw[$key]);
                    if ($field['type'] === 'repeater') {
                        $rows = [];
                        foreach ($value as $row) {
                            foreach ($field['fields'] as $subKey => $subField) {
                                if (($row[$subKey] ?? null) === $subField['default']) {
                                    unset($row[$subKey]);
                                }
                            }
                            if ($row !== []) {
                                $rows[] = $row;
                            }
                        }
                        $value = $rows;
                    }
                    if ($value === $field['default'] || $value === []) {
                        continue;
                    }
                    $block[$key] = $value;
                }
            }
            $blocks[] = $block;
        }
        return $blocks;
    }

    /** @return array<string, mixed>|null */
    private function load(string $type, string $file, string $origin): ?array
    {
        try {
            $raw = Yaml::parseFile($file);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($raw)) {
            return null;
        }
        $variants = [];
        foreach (is_array($raw['variants'] ?? null) ? $raw['variants'] : [] as $value => $label) {
            if (preg_match('/^[a-z0-9-]+$/', (string)$value)) {
                $variants[(string)$value] = (string)$label;
            }
        }
        if ($variants === []) {
            $variants = ['default' => 'Default'];
        }
        $tone = (string)($raw['tone'] ?? 'default');
        $spacing = (string)($raw['spacing'] ?? 'default');

        return [
            'type' => $type,
            'origin' => $origin,
            'label' => (string)($raw['label'] ?? ucfirst(str_replace('-', ' ', $type))),
            'description' => (string)($raw['description'] ?? ''),
            'variants' => $variants,
            'fields' => FieldSchema::normalize($this->expandFields(is_array($raw['fields'] ?? null) ? $raw['fields'] : [])),
            // Every block shares these presentation fields.
            'common' => FieldSchema::normalize([
                'variant' => ['type' => 'select', 'label' => 'Layout', 'options' => $variants, 'default' => (string)array_key_first($variants)],
                'tone' => ['type' => 'select', 'label' => 'Background', 'options' => self::TONES, 'default' => isset(self::TONES[$tone]) ? $tone : 'default'],
                'spacing' => ['type' => 'select', 'label' => 'Spacing', 'options' => self::SPACINGS, 'default' => isset(self::SPACINGS[$spacing]) ? $spacing : 'default'],
                'anchor' => ['type' => 'text', 'label' => 'Anchor id', 'help' => 'Optional id for in-page links, e.g. services.'],
                'hidden' => ['type' => 'toggle', 'label' => 'Hide this block'],
            ]),
        ];
    }

    /** @param array<string, mixed> $fields */
    private function expandFields(array $fields): array
    {
        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['type'] ?? '') === 'icon') {
                $options = ['' => 'None'];
                foreach ($this->theme->iconNames() as $name) {
                    $options[$name] = $name;
                }
                $field['type'] = 'select';
                $field['options'] = $options;
                $field['default'] = (string)($field['default'] ?? '');
            }
            $source = (string)($field['options_from'] ?? '');
            if ($source !== '' && isset($this->optionSources[$source])) {
                $options = ($this->optionSources[$source])();
                if (is_array($field['options'] ?? null)) {
                    $options = $field['options'] + $options;
                }
                $field['type'] = 'select';
                $field['options'] = $options;
            }
            if (($field['type'] ?? '') === 'repeater' && is_array($field['fields'] ?? null)) {
                $field['fields'] = $this->expandFields($field['fields']);
            }
            $fields[$key] = $field;
        }
        return $fields;
    }
}

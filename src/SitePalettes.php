<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * The palettes a site makes for itself (Theme > Branding > Colour), kept next to the theme's ready-made ones.
 *
 * A site palette is a name and colours: for light and for dark mode the six roles (accent, background, surface, text, muted,
 * border), optionally the dark panel colour (`ink`) and the `secondary` role. Everything is kept in one theme setting,
 * `design.site_palettes`, as JSON, and the one in use is `design.site_palette` (its id). A palette in use fills every colour
 * role the site has not set itself (`design.light_accent` and the like), so the colours a person sets by hand still win.
 * Nothing here runs for a site without site palettes, so its pages are exactly what they were.
 */
final class SitePalettes
{
    public const MAX = 24;
    public const NAME_LENGTH = 40;
    private const ROLES = PaletteGenerator::ROLES;

    /**
     * Palettes from the stored text (or an array), cleaned: unknown keys dropped, colours checked, ids made unique.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parse(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $palettes = [];
        $seen = [];
        foreach ($raw as $item) {
            $palette = is_array($item) ? self::clean($item) : null;
            if ($palette === null || isset($seen[$palette['id']])) {
                continue;
            }
            $seen[$palette['id']] = true;
            $palettes[] = $palette;
            if (count($palettes) >= self::MAX) {
                break;
            }
        }
        return $palettes;
    }

    /** The compact JSON that is stored. */
    public static function encode(array $palettes): string
    {
        $clean = self::parse($palettes);
        return $clean === [] ? '' : (string)json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed>|null */
    private static function clean(array $item): ?array
    {
        $id = strtolower(trim((string)($item['id'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
            return null;
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', strip_tags((string)($item['name'] ?? ''))) ?? '');
        $name = mb_substr($name, 0, self::NAME_LENGTH);
        $out = ['id' => $id, 'name' => $name === '' ? 'Palette' : $name];
        $any = false;
        foreach (['light', 'dark'] as $mode) {
            $colours = [];
            foreach (self::ROLES as $role) {
                $hex = self::hex($item[$mode][$role] ?? null);
                if ($hex !== null) {
                    $colours[$role] = $hex;
                    $any = true;
                }
            }
            $out[$mode] = $colours;
        }
        // The two colours the palette was made from, kept so the studio can open it again.
        $from = ['accent' => self::hex($item['from']['accent'] ?? null), 'base' => self::hex($item['from']['base'] ?? null)];
        if ($from['accent'] !== null && $from['base'] !== null) {
            $out['from'] = $from + ['role' => ($item['from']['role'] ?? '') === 'ink' ? 'ink' : 'background'];
        }
        $ink = self::hex($item['ink'] ?? null);
        if ($ink !== null) {
            $out['ink'] = $ink;
        }
        $secondary = [];
        foreach (['light', 'dark'] as $mode) {
            $hex = self::hex($item['secondary'][$mode] ?? null);
            if ($hex !== null) {
                $secondary[$mode] = $hex;
                $any = true;
            }
        }
        if ($secondary !== []) {
            $out['secondary'] = $secondary;
        }
        return $any ? $out : null;
    }

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? FieldSchema::normalizeHex($value) : null;
    }

    /** The palette in use, or null. @return array<string, mixed>|null */
    public static function active(array $design): ?array
    {
        $id = trim((string)($design['site_palette'] ?? ''));
        if ($id === '') {
            return null;
        }
        foreach (self::parse($design['site_palettes'] ?? '') as $palette) {
            if ($palette['id'] === $id) {
                return $palette;
            }
        }
        return null;
    }

    /**
     * The design settings with the colours of the palette in use filled in wherever the site has not set that colour itself.
     * Returns $design as it is when no site palette is in use.
     *
     * @param array<string, mixed> $design
     * @return array<string, mixed>
     */
    public static function apply(array $design): array
    {
        $palette = self::active($design);
        if ($palette === null) {
            return $design;
        }
        $fill = static function (string $key, ?string $value) use (&$design): void {
            if ($value !== null && ($design[$key] ?? '') === '') {
                $design[$key] = $value;
            }
        };
        foreach (['light', 'dark'] as $mode) {
            foreach (self::ROLES as $role) {
                $fill($mode . '_' . $role, $palette[$mode][$role] ?? null);
            }
            $fill($mode . '_secondary', $palette['secondary'][$mode] ?? null);
        }
        $fill('ink', $palette['ink'] ?? null);
        return $design;
    }

    /** A new id for a name, unique among the palettes. */
    public static function newId(string $name, array $palettes): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $slug = substr($slug === '' ? 'palette' : $slug, 0, 24);
        $taken = array_column($palettes, 'id');
        for ($i = 1; ; $i++) {
            $id = $i === 1 ? $slug : $slug . '-' . $i;
            if (!in_array($id, $taken, true)) {
                return $id;
            }
        }
    }

    /** The palettes with $palette added, or replacing the one with its id. @return array<int, array<string, mixed>> */
    public static function save(array $palettes, array $palette): array
    {
        $palettes = self::parse($palettes);
        $clean = self::clean($palette);
        if ($clean === null) {
            return $palettes;
        }
        foreach ($palettes as $i => $existing) {
            if ($existing['id'] === $clean['id']) {
                $palettes[$i] = $clean;
                return $palettes;
            }
        }
        $palettes[] = $clean;
        return array_slice($palettes, 0, self::MAX);
    }

    /** @return array<int, array<string, mixed>> */
    public static function rename(array $palettes, string $id, string $name): array
    {
        $palettes = self::parse($palettes);
        foreach ($palettes as $i => $palette) {
            if ($palette['id'] === $id) {
                $palettes[$i]['name'] = $name;
            }
        }
        return self::parse($palettes);
    }

    /** @return array<int, array<string, mixed>> */
    public static function delete(array $palettes, string $id): array
    {
        return array_values(array_filter(self::parse($palettes), static fn(array $palette): bool => $palette['id'] !== $id));
    }
}

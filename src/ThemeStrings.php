<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * The words the theme prints ("Read more", form messages, and so on) as the Translations screen edits them. The theme
 * ships them and updates replace them, so an edit is stored as an override in `custom/lang/<lang>.yaml`, which updates
 * never touch; an override that says what the theme says is dropped. Menu labels (`nav.*`) are not edited here.
 */
final class ThemeStrings
{
    public function __construct(private Theme $theme)
    {
    }

    /** Keys kept out of the screen: menu labels live in the menu editor. */
    public static function isHidden(string $key): bool
    {
        return str_starts_with($key, 'nav.');
    }

    /**
     * @param array<string|int, mixed> $translations
     * @return array<string, string> the strings that can be edited on the screen
     */
    public static function visible(array $translations): array
    {
        $rows = [];
        foreach ($translations as $key => $value) {
            $key = (string)$key;
            if (self::isHidden($key)) {
                continue;
            }
            $rows[$key] = (string)$value;
        }
        return $rows;
    }

    /**
     * What the screen shows for a language.
     *
     * @return array{translations: array<string, string>, defaults: array<string, string>, customized: string[], custom_file: string}
     */
    public function screen(string $lang, string $defaultLang): array
    {
        $visible = self::visible(array_replace($this->theme->inheritedTranslations($lang, $defaultLang), $this->theme->customTranslations($lang)));
        ksort($visible);
        return [
            'translations' => $visible,
            'defaults' => self::visible($this->theme->themeTranslations($defaultLang)),
            'customized' => array_keys(self::visible($this->theme->customTranslations($lang))),
            'custom_file' => 'custom/lang/' . $lang . '.yaml',
        ];
    }

    /**
     * Saves a submitted form for a language. A string set back to what the theme says, or ticked for reset, stops
     * being an override; a key the form does not mention keeps its override.
     *
     * @param array<string, mixed> $post keys[], values[] (same order), reset[] (keys to give back to the theme)
     * @return array{ok: bool, overrides: int}
     */
    public function save(string $lang, string $defaultLang, array $post): array
    {
        $inherited = $this->theme->inheritedTranslations($lang, $defaultLang);
        $next = $this->theme->customTranslations($lang);
        $keys = is_array($post['keys'] ?? null) ? $post['keys'] : [];
        $values = is_array($post['values'] ?? null) ? $post['values'] : [];
        $reset = array_map('strval', is_array($post['reset'] ?? null) ? $post['reset'] : []);

        foreach ($keys as $index => $key) {
            $key = trim((string)$key);
            if ($key === '' || self::isHidden($key)) {
                continue;
            }
            $value = (string)($values[$index] ?? '');
            if (in_array($key, $reset, true) || (array_key_exists($key, $inherited) && $inherited[$key] === $value)) {
                unset($next[$key]);
                continue;
            }
            $next[$key] = $value;
        }

        return ['ok' => $this->theme->writeCustomTranslations($lang, $next), 'overrides' => count($next)];
    }
}

<?php

declare(strict_types=1);

namespace FarosCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Everything the editor screen of one entry shows: its fields read from the front matter, the blocks for the block
 * editor, how it opens, its translations, address and old addresses, the links that still use an address that just
 * changed, its latest versions, and for a form its fields, notifications and stored submissions. An entry that does
 * not exist yet opens with the defaults of its type. The class only reads; saving is `ContentEditor`.
 */
final class EntryForm
{
    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(): array<string, mixed> $themeSettings
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(): LinkScanner $linkScanner
     * @param \Closure(): BlockRegistry $blocks
     * @param \Closure(): PresetLibrary $presets
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     */
    public function __construct(
        private string $contentDir,
        private ContentRepository $content,
        private ContentTypes $types,
        private ContentEditor $editor,
        private EntryTranslations $translations,
        private FormsAdmin $forms,
        private RedirectRepository $redirects,
        private RevisionRepository $revisions,
        private RevisionAdmin $history,
        private Theme $theme,
        private \Closure $settings,
        private \Closure $themeSettings,
        private \Closure $taxonomies,
        private \Closure $linkScanner,
        private \Closure $blocks,
        private \Closure $presets,
        private \Closure $absoluteUrl
    ) {
    }

    /**
     * @param array<string, mixed> $query the address's query (what the page was opened with: saved, notice, ...)
     * @return array<string, mixed> what the page shows
     */
    public function form(string $type, string $slug, string $lang, array $query, bool $canRedirects): array
    {
        $settings = ($this->settings)();
        $themeSettings = ($this->themeSettings)();
        $defaultLang = (string)($settings['languages']['default'] ?? 'en');
        $saved = isset($query['saved']);
        $deleted = isset($query['deleted']);

        $path = $this->path($type, $slug, $lang);
        $frontmatter = '';
        $body = '';
        $mainImage = '';
        $metaForm = [
            'title' => '',
            'status' => 'published',
            'visible' => true,
            'date' => '',
            'author' => '',
            'seo_title' => '',
            'seo_description' => '',
            'seo_canonical' => '',
            'seo_og_title' => '',
            'seo_og_description' => '',
            'seo_og_image' => '',
            'seo_noindex' => false,
            'main_image' => '',
            'excerpt' => '',
            'translation_id' => '',
            'custom_fields' => [],
            'taxonomy_terms' => [],
        ];
        $formFields = [];
        $formNotifications = [
            'enabled' => false,
            'to' => '',
            'subject' => '',
            'reply_to_field' => '',
            'cc' => '',
            'bcc' => '',
            'auto_reply' => false,
            'auto_reply_include' => false,
            'auto_reply_subject' => '',
            'auto_reply_message' => '',
        ];
        $formSettings = [
            'store_submissions' => $settings['forms']['store_submissions'] ?? true,
            'submit_label' => '',
            'success_message' => '',
            'redirect_url' => '',
            'honeypot' => (string)($settings['forms']['antispam']['honeypot'] ?? 'website'),
            'rate_limit_seconds' => (string)($settings['forms']['antispam']['rate_limit_seconds'] ?? 20),
        ];
        $formSubmissions = [];

        $isNew = true;
        if ($slug !== '' && file_exists($path)) {
            [$frontmatter, $body] = FrontMatter::split((string)file_get_contents($path));
            $isNew = false;
        }
        if ($frontmatter === '' && $slug !== '') {
            $frontmatter = $this->defaultFrontMatter($type, $slug);
        }
        $meta = [];
        $frontMatterError = trim((string)($query['frontmatter_error'] ?? ''));
        if ($frontmatter !== '') {
            try {
                $meta = Yaml::parse($frontmatter) ?: [];
            } catch (\Throwable $e) {
                // The editor still opens, with the raw front matter to fix by hand.
                $meta = [];
                $frontMatterError = $e->getMessage();
            }
        }
        $pageBlocks = is_array($meta) && is_array($meta['blocks'] ?? null) ? array_values($meta['blocks']) : [];
        if ($pageBlocks !== [] && $type !== 'forms') {
            // Blocks are edited in the Blocks tab; the raw YAML keeps everything else.
            $withoutBlocks = $meta;
            unset($withoutBlocks['blocks']);
            $frontmatter = $withoutBlocks === [] ? '' : trim(Yaml::dump($withoutBlocks, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }
        if (is_array($meta)) {
            $mainImage = (string)($meta['main_image'] ?? '');
            $metaForm['title'] = $isNew ? (string)($meta['title'] ?? '') : (string)($meta['title'] ?? Slug::title($slug));
            $metaForm['status'] = (string)($meta['status'] ?? 'published');
            $metaForm['visible'] = (bool)($meta['visible'] ?? true);
            $rawDate = $meta['date'] ?? '';
            $metaForm['date'] = $this->adminDate($rawDate);
            $metaForm['author'] = (string)($meta['author'] ?? '');
            $metaForm['template'] = (string)($meta['template'] ?? '');
            $metaForm['hero_layout'] = (string)($meta['hero_layout'] ?? '');
            $headerTransparent = $meta['header_transparent'] ?? '';
            $metaForm['header_transparent'] = is_bool($headerTransparent) ? ($headerTransparent ? 'on' : 'off') : (string)$headerTransparent;
            foreach (($this->taxonomies)()->names() as $taxonomyName) {
                $metaForm['taxonomy_terms'][$taxonomyName] = Format::list($meta[$taxonomyName] ?? null);
            }
            $seo = $meta['seo'] ?? [];
            if (is_array($seo)) {
                $metaForm['seo_title'] = (string)($seo['title'] ?? '');
                $metaForm['seo_description'] = (string)($seo['description'] ?? '');
                $metaForm['seo_canonical'] = (string)($seo['canonical'] ?? '');
                $metaForm['seo_og_title'] = (string)($seo['og_title'] ?? '');
                $metaForm['seo_og_description'] = (string)($seo['og_description'] ?? '');
                $metaForm['seo_og_image'] = (string)($seo['og_image'] ?? '');
                $metaForm['seo_noindex'] = Format::isTruthy($seo['noindex'] ?? false);
            }
            $metaForm['main_image'] = $mainImage;
            $metaForm['excerpt'] = (string)($meta['excerpt'] ?? '');
            $metaForm['custom_fields'] = $this->extractCustomFields($meta, $this->types->declaredKeys($type));
            if ($type === 'forms') {
                $formFields = FormFields::forAdmin($meta['fields'] ?? []);
                $notifications = $meta['notifications'] ?? [];
                if (is_array($notifications)) {
                    $formNotifications['enabled'] = Format::isTruthy($notifications['enabled'] ?? false);
                    $formNotifications['to'] = (string)($notifications['to'] ?? '');
                    $formNotifications['subject'] = (string)($notifications['subject'] ?? '');
                    $formNotifications['reply_to_field'] = (string)($notifications['reply_to_field'] ?? '');
                    $formNotifications['cc'] = (string)($notifications['cc'] ?? '');
                    $formNotifications['bcc'] = (string)($notifications['bcc'] ?? '');
                    $formNotifications['auto_reply'] = Format::isTruthy($notifications['auto_reply'] ?? false);
                    $formNotifications['auto_reply_include'] = Format::isTruthy($notifications['auto_reply_include'] ?? false);
                    $formNotifications['auto_reply_subject'] = (string)($notifications['auto_reply_subject'] ?? '');
                    $formNotifications['auto_reply_message'] = (string)($notifications['auto_reply_message'] ?? '');
                }
                $formSettings['store_submissions'] = Format::isTruthy($meta['store_submissions'] ?? $formSettings['store_submissions']);
                $formSettings['submit_label'] = (string)($meta['submit_label'] ?? '');
                $formSettings['success_message'] = (string)($meta['success_message'] ?? '');
                $formSettings['redirect_url'] = (string)($meta['redirect_url'] ?? '');
                $antispam = $meta['antispam'] ?? [];
                if (is_array($antispam)) {
                    $formSettings['honeypot'] = (string)($antispam['honeypot'] ?? $formSettings['honeypot']);
                    $formSettings['rate_limit_seconds'] = (string)($antispam['rate_limit_seconds'] ?? $formSettings['rate_limit_seconds']);
                }
            }
        }

        $translationId = '';
        if (is_array($meta)) {
            $translationId = (string)($meta['translation_id'] ?? '');
        }
        if ($translationId === '') {
            $translationId = (string)($query['translation_id'] ?? '');
        }
        if ($translationId === '') {
            $translationId = $this->editor->translationIdForSlug($type, $slug);
        }
        if ($translationId === '') {
            $translationId = ContentEditor::newTranslationId();
        }
        $metaForm['translation_id'] = $translationId;
        $translations = $this->translations->links($type, $slug, $translationId);
        if ($isNew && $metaForm['date'] === '') {
            $metaForm['date'] = date('Y-m-d');
        }
        $formSubmissionsTotal = 0;
        if ($type === 'forms' && $slug !== '') {
            $formSubmissions = $this->forms->recent($slug);
            $formSubmissionsTotal = count($formSubmissions);
            $formSubmissions = array_slice($formSubmissions, 0, 10);
        }
        $frontUrl = '';
        $linkFix = null;
        $itemExists = $slug !== '' && is_file($path);
        $homeSlug = (new ContentPaths($settings))->homeSlug();
                $address = [
            'exists' => $itemExists,
            'prefix' => '/' . str_replace('__slug__', '', ContentPaths::build($type, '__slug__', $lang, $homeSlug, $defaultLang)),
            // The home page and forms keep their address: the site and its stored submissions refer to it by name.
            'locked' => $type === 'forms' || ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang),
            'siblings' => $itemExists ? array_column($this->editor->translationSiblings($type, $slug, $lang), 'lang') : [],
            'old_addresses' => [],
        ];
        if ($slug !== '') {
            $publicPath = ContentPaths::build($type, $slug, $lang, $homeSlug, $defaultLang);
            $frontUrl = ($this->absoluteUrl)($publicPath);
            if ($itemExists && $canRedirects) {
                $address['old_addresses'] = $this->redirects->pointingTo($publicPath);
            }
            // Just after an address changed: are there links in other content that still use the old one?
            if ($itemExists && (string)($query['address'] ?? '') === 'changed') {
                $permanent = array_values(array_filter($this->redirects->pointingTo($publicPath), static fn(array $r): bool => (bool)$r['enabled'] && (int)$r['status_code'] === 301));
                $counts = $permanent !== [] ? ($this->linkScanner)()->countBySource(array_column($permanent, 'source')) : null;
                if ($counts !== null && array_sum($counts) > 0) {
                    $linkFix = ['count' => array_sum($counts), 'ids' => implode(',', array_column($permanent, 'id'))];
                }
            }
        }

        return [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title_from_slug' => Slug::title($slug),
            'frontmatter' => $frontmatter,
            'front_matter_error' => $frontMatterError,
            'body' => $body,
            'main_image' => $mainImage,
            'meta_form' => $metaForm,
            'type_definition' => $this->types->definition($type, 'en', $defaultLang),
            'type_fields' => $type === 'forms' ? [] : $this->types->editableFields($type, 'en', $defaultLang),
            'type_values' => is_array($meta) ? $this->types->values($type, $meta, 'en', $defaultLang) : [],
            'taxonomies' => $this->taxonomyChoices(),
            'translations' => $translations,
            'front_url' => $frontUrl,
            'types' => $this->content->getTypes(),
            'languages' => $settings['languages']['available'] ?? [],
            'saved' => $saved,
            'notice' => (string)($query['notice'] ?? ''),
            'address' => $address,
            'address_changed' => (string)($query['address'] ?? '') === 'changed',
            'link_fix' => $linkFix,
            'restored' => isset($query['restored']),
            'history' => $itemExists ? $this->history->decorate($this->revisions->forItem($type, $slug, $lang, 8)) : [],
            'history_total' => $itemExists ? $this->revisions->countForItem($type, $slug, $lang) : 0,
            'address_taken' => Slug::plain((string)($query['address_taken'] ?? '')),
            'deleted' => $deleted,
            'admin_section' => $type === 'forms' ? 'forms' : 'content',
            'current_type' => $type,
            'form_fields' => $formFields,
            'form_notifications' => $formNotifications,
            'form_settings' => $formSettings,
            'form_submissions' => $formSubmissions,
            'form_submissions_total' => $formSubmissionsTotal,
            'form_field_types' => FormFields::types(),
            'block_editor_json' => $type === 'forms' ? '' : $this->blockEditorJson($pageBlocks, $lang),
            'page_templates' => $type === 'forms' ? [] : $this->theme->pageTemplates(),
            'opening' => $this->openingChoices($type),
        ];
    }

    /**
     * What the editor may choose about how an entry opens (title layout, transparent header), with what its
     * content type does today so "follow the settings" can say what that is. Empty when the theme offers neither.
     *
     * @return array{layouts: array<string, string>, transparent: array<string, string>, type_layout: string, type_transparent: string}
     */
    private function openingChoices(string $type): array
    {
        $themeSettings = ($this->themeSettings)();
        $layouts = $this->theme->settingOptions('hero_layouts', 'default');
        $transparent = array_diff_key($this->theme->settingOptions('transparent_header', 'default'), ['site' => true]);
        $layoutSettings = is_array($themeSettings['hero_layouts'] ?? null) ? $themeSettings['hero_layouts'] : [];
        $transparentSettings = is_array($themeSettings['transparent_header'] ?? null) ? $themeSettings['transparent_header'] : [];
        $typeTransparent = (string)($transparentSettings[$type] ?? $transparentSettings['default'] ?? 'site');
        if (!isset($transparent[$typeTransparent])) {
            $typeTransparent = Format::isTruthy($themeSettings['header']['transparent'] ?? false) ? 'on' : 'off';
        }
        return [
            'layouts' => $layouts,
            'transparent' => $transparent,
            'type_layout' => (string)($layoutSettings[$type] ?? $layoutSettings['default'] ?? 'default'),
            'type_transparent' => $typeTransparent,
        ];
    }

    /** Data for the admin block editor, safe to embed in a <script type="application/json">. */
    private function blockEditorJson(array $blocks, string $lang): string
    {
        $settings = ($this->settings)();
        return (string)json_encode([
            'definitions' => ($this->blocks)()->editorDefinitions(),
            'blocks' => $blocks,
            'presets' => ($this->presets)()->forEditor($lang, (string)($settings['languages']['default'] ?? 'en')),
            'presets_url' => rtrim((string)($settings['base_url'] ?? ''), '/') . '/admin/block-presets',
            'lang' => $lang,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Free-form custom fields of an item. Fields the content type declares have their own inputs, so
     * they are left out ($exclude).
     *
     * @param string[] $exclude
     */
    private function extractCustomFields(array $meta, array $exclude = []): array
    {
        $custom = [];
        $customFields = $meta['custom_fields'] ?? [];
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                $key = (string)$key;
                if ($key === '' || $this->editor->isReservedKey($key) || in_array($key, $exclude, true)) {
                    continue;
                }
                $custom[$key] = $this->stringifyCustomValue($value);
            }
        }

        foreach ($meta as $key => $value) {
            $key = (string)$key;
            if ($this->editor->isReservedKey($key) || array_key_exists($key, $custom) || in_array($key, $exclude, true)) {
                continue;
            }
            $custom[$key] = $this->stringifyCustomValue($value);
        }

        $rows = [];
        foreach ($custom as $key => $value) {
            $rows[] = [
                'key' => $key,
                'value' => $value,
            ];
        }
        return $rows;
    }

    private function stringifyCustomValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        return (string)$value;
    }

    private function adminDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value)) {
            return $this->dateText($value);
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }
            if (ctype_digit($trimmed)) {
                return $this->dateText((int)$trimmed);
            }
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $trimmed)) {
                return $this->dateText($trimmed);
            }
            if (preg_match('/^\\d{4}\\/\\d{2}\\/\\d{2}$/', $trimmed)) {
                return $this->dateText($trimmed);
            }
            return $trimmed;
        }
        return (string)$value;
    }

    private function defaultFrontMatter(string $type, string $slug): string
    {
        $lines = [
            'status: published',
            'visible: true',
        ];

        if ($type !== 'forms') {
            $lines[] = 'main_image: ""';
        }

        if (in_array($type, ['posts', 'projects'], true)) {
            $lines[] = 'date: ' . date('Y-m-d');
        }

        return implode("\n", $lines);
    }

    /** @return array<int, array{name: string, title: string, terms: array<int, array{id: string, slug: string, labels: array<string, string>}>}> */
    private function taxonomyChoices(): array
    {
        $rows = [];
        $taxonomies = ($this->taxonomies)();
        foreach ($taxonomies->names() as $name) {
            $taxonomy = $taxonomies->load($name);
            $rows[] = [
                'name' => $name,
                'title' => (string)$taxonomy['title'],
                'terms' => $taxonomy['terms'],
            ];
        }
        return $rows;
    }

    private function dateText(mixed $value): string
    {
        return Format::dateValue($value, (string)((($this->settings)())['date_format'] ?? 'd/m/Y'));
    }

    private function path(string $type, string $slug, string $lang): string
    {
        return $this->contentDir . '/' . $type . '/' . (new ContentPaths(($this->settings)()))->filename($slug, $lang);
    }
}

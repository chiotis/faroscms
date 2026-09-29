<?php

declare(strict_types=1);

namespace FarosCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Saves a content item from the editor form: works out its address, builds the front matter from the submitted
 * fields, keeps raw HTML out for people who may not add it, writes the file, moves translations along with a
 * changed address, and leaves redirects behind. It knows nothing about requests or sessions: the caller passes the
 * submitted fields and gets back what happened.
 */
final class ContentEditor
{
    /**
     * @param array<string, mixed> $settings site settings (languages, home page, date format)
     * @param \Closure(): BlockRegistry $blocks
     * @param \Closure(): string[] $taxonomyNames names of the taxonomies (tags, categories, ...)
     */
    public function __construct(
        private string $contentDir,
        private array $settings,
        private ContentRepository $content,
        private ContentIndex $contentIndex,
        private ContentTypes $types,
        private Theme $theme,
        private RedirectRepository $redirects,
        private HtmlGuard $guard,
        private \Closure $blocks,
        private \Closure $taxonomyNames,
        private ?RevisionRepository $revisions = null
    ) {
    }

    /**
     * @param array<string, mixed> $post the submitted editor form (same field names as the form)
     * @param string|null $mainImageOverride a main image that was just uploaded, replacing the submitted value
     * @return array{type: string, slug: string, lang: string, title: string, status: string, was_existing: bool, moved: bool,
     *   moved_together: bool, original_slug: string, original_lang: string, slug_taken: string, html_neutralized: bool,
     *   translations_renamed: int, redirects_created: int}
     */
    public function save(string $type, array $post, ?string $mainImageOverride, bool $canRawHtml, string $actor): array
    {
        $postedSlug = Slug::plain((string)($post['slug'] ?? ''));
        $slug = $postedSlug;
        $lang = Slug::plain((string)($post['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $frontmatter = trim((string)($post['frontmatter'] ?? ''));
        $body = rtrim((string)($post['body'] ?? ''));
        $title = trim((string)($post['title'] ?? ''));
        $status = trim((string)($post['status'] ?? 'published'));
        $visible = isset($post['visible']) && (string)($post['visible']) === '1';
        $date = trim((string)($post['date'] ?? ''));
        $author = trim((string)($post['author'] ?? ''));
        $excerpt = trim((string)($post['excerpt'] ?? ''));
        $seoTitle = trim((string)($post['seo_title'] ?? ''));
        $seoDescription = trim((string)($post['seo_description'] ?? ''));
        $seoCanonical = trim((string)($post['seo_canonical'] ?? ''));
        $seoOgTitle = trim((string)($post['seo_og_title'] ?? ''));
        $seoOgDescription = trim((string)($post['seo_og_description'] ?? ''));
        $seoOgImage = trim((string)($post['seo_og_image'] ?? ''));
        $seoNoindex = isset($post['seo_noindex']) && (string)($post['seo_noindex']) === '1';
        $mainImage = $mainImageOverride ?? trim((string)($post['main_image'] ?? ''));
        $taxonomyTermsInput = $post['taxonomy_terms'] ?? [];
        $customKeys = $post['custom_keys'] ?? [];
        $customValues = $post['custom_values'] ?? [];
        $formFieldsInput = $post['form_fields'] ?? [];
        $formNotificationsInput = $post['form_notifications'] ?? [];
        $formStoreSubmissions = isset($post['form_store_submissions']) && (string)($post['form_store_submissions']) === '1';
        $formSubmitLabel = trim((string)($post['form_submit_label'] ?? ''));
        $formSuccessMessage = trim((string)($post['form_success_message'] ?? ''));
        $formRedirectUrl = trim((string)($post['form_redirect_url'] ?? ''));
        $formHoneypot = trim((string)($post['form_honeypot'] ?? ''));
        $formRateLimit = trim((string)($post['form_rate_limit_seconds'] ?? ''));
        $translationId = trim((string)($post['translation_id'] ?? ''));
        $originalSlug = Slug::plain((string)($post['original_slug'] ?? ''));
        $originalLang = Slug::plain((string)($post['original_lang'] ?? ''));


        $dir = $this->contentDir . '/' . $type;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $oldPath = '';
        if ($originalSlug !== '' && $originalLang !== '') {
            $oldPath = $dir . '/' . $this->paths()->filename($originalSlug, $originalLang);
        }
        $sourceExists = $oldPath !== '' && is_file($oldPath);
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'en');
        $homeSlug = (string)(($this->settings['home_page'] ?? '') !== '' ? $this->settings['home_page'] : 'index');

        // The web address. New content gets it from the title (Greek is converted to Latin letters); a translation
        // being created keeps the address it inherits; an existing item keeps its address unless it was changed on purpose.
        $slugTaken = '';
        if ($sourceExists) {
            $isHome = $type === 'pages' && $originalSlug === $homeSlug && $originalLang === $defaultLang;
            if ($postedSlug === '' || $postedSlug === $originalSlug || $type === 'forms' || $isHome) {
                $slug = $originalSlug;
            } else {
                $slug = Slug::fromText((string)($post['slug'] ?? ''));
                $slug = $slug !== '' ? $slug : $originalSlug;
            }
        } elseif ($postedSlug === '') {
            $slug = Slug::fromText($title);
            if ($slug === '') {
                $slug = Slug::plain(rtrim($type, 's')) . '-' . date('Ymd-His');
            }
        }
        $requestedSlug = $slug;
        if (!$sourceExists || $slug !== $originalSlug || $lang !== $originalLang) {
            $slug = $this->availableSlug($type, $slug, $lang, $sourceExists ? $oldPath : '');
            $slugTaken = $slug !== $requestedSlug ? $requestedSlug : '';
        }

        $path = $dir . '/' . $this->paths()->filename($slug, $lang);
        $wasExisting = file_exists($path);
        $moved = $sourceExists && $oldPath !== $path;
        $oldStatus = $moved ? $this->storedStatus($oldPath) : '';
        $siblings = $moved && $slug !== $originalSlug ? $this->translationSiblings($type, $originalSlug, $originalLang) : [];
        // What is on disk now goes into the history first, in case it is not there yet (the first save since history
        // began, or a file edited outside the editor).
        if ($sourceExists) {
            $this->revisions?->baseline($type, $originalSlug, $originalLang, $oldPath, $actor);
        } elseif ($wasExisting) {
            $this->revisions?->baseline($type, $slug, $lang, $path, $actor);
        }
        $data = [];
        if ($frontmatter !== '') {
            $parsed = Yaml::parse($frontmatter) ?: [];
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        $blocksFromRawYaml = array_key_exists('blocks', $data);
        if ($type !== 'forms') {
            $editorBlocks = ($post['blocks_editor'] ?? '') === '1'
                ? json_decode((string)($post['blocks_json'] ?? ''), true)
                : null;
            if (is_array($editorBlocks)) {
                $blocks = ($this->blocks)()->sanitizeForStorage(array_values($editorBlocks));
                if ($blocks === []) {
                    unset($data['blocks']);
                } else {
                    $data['blocks'] = $blocks;
                }
            } elseif (!array_key_exists('blocks', $data)) {
                // No editor data (script not loaded): keep the blocks the file already has.
                $existing = $this->storedBlocks($oldPath !== '' && is_file($oldPath) ? $oldPath : $path);
                if ($existing !== null) {
                    $data['blocks'] = $existing;
                }
            }
        }

        // Raw HTML can carry script, so it is a privilege of administrators. Anyone else writes Markdown: HTML they
        // type is shown as text, while HTML already in the content (put there by an administrator) stays.
        $htmlNeutralized = false;
        if ($type !== 'forms' && !$canRawHtml) {
            $allowedHtml = $this->guard->storedFragments($oldPath !== '' && is_file($oldPath) ? $oldPath : $path);
            $plainBody = str_replace(["\r\n", "\r"], "\n", $body);
            $body = $this->guard->neutralize($body, $allowedHtml);
            $htmlNeutralized = $body !== $plainBody;
            if (isset($data['blocks']) && is_array($data['blocks'])) {
                if ($blocksFromRawYaml && !is_array($editorBlocks ?? null)) {
                    // Blocks typed into the raw front matter get the same checks as blocks from the editor.
                    $data['blocks'] = ($this->blocks)()->sanitizeForStorage(array_values($data['blocks']));
                }
                $data['blocks'] = $this->guard->eachMarkdownField(array_values($data['blocks']), function (string $value) use ($allowedHtml, &$htmlNeutralized): string {
                    $clean = $this->guard->neutralize($value, $allowedHtml);
                    $htmlNeutralized = $htmlNeutralized || $clean !== str_replace(["\r\n", "\r"], "\n", $value);
                    return $clean;
                });
            }
        }

        $data['title'] = $title !== '' ? $title : Slug::title($slug);
        $data['status'] = $status !== '' ? $status : 'published';
        $data['visible'] = $visible;

        if ($date !== '') {
            $data['date'] = $this->normalizeDate($date);
        } else {
            unset($data['date']);
        }

        if ($author !== '') {
            $data['author'] = $author;
        } else {
            unset($data['author']);
        }

        if ($excerpt !== '') {
            $data['excerpt'] = $excerpt;
        } else {
            unset($data['excerpt']);
        }
        unset($data['summary']);

        if ($type !== 'forms' && array_key_exists('template', $post)) {
            // Only templates the theme offers; "default" (or empty) means the normal hierarchy.
            // An unknown value (e.g. a custom template file set by hand) is left as the front matter has it.
            $template = trim((string)$post['template']);
            if ($template === '' || $template === 'default') {
                unset($data['template']);
            } elseif (isset($this->theme->pageTemplates()[$template])) {
                $data['template'] = $template;
            }
        }

        if ($translationId === '') {
            $translationId = $this->translationIdForSlug($type, $slug);
        }
        if ($translationId === '') {
            $translationId = self::newTranslationId();
        }
        $data['translation_id'] = $translationId;

        $taxonomyTermsInput = is_array($taxonomyTermsInput) ? $taxonomyTermsInput : [];
        $taxonomyNames = ($this->taxonomyNames)();
        if ($type !== 'pages' && $type !== 'forms') {
            foreach ($taxonomyNames as $taxonomyName) {
                $selectedRaw = $taxonomyTermsInput[$taxonomyName] ?? [];
                $selectedRaw = is_array($selectedRaw) ? $selectedRaw : [];
                $selected = [];
                foreach ($selectedRaw as $termId) {
                    $termId = Slug::plain((string)$termId);
                    if ($termId === '' || in_array($termId, $selected, true)) {
                        continue;
                    }
                    $selected[] = $termId;
                }
                if (!empty($selected)) {
                    $data[$taxonomyName] = $selected;
                } else {
                    unset($data[$taxonomyName]);
                }
            }
        } else {
            foreach ($taxonomyNames as $taxonomyName) {
                unset($data[$taxonomyName]);
            }
        }

        if (array_key_exists('main_image', $post)) {
            if ($mainImage !== '') {
                $data['main_image'] = $mainImage;
            } else {
                unset($data['main_image']);
            }
        }

        if (isset($post['seo_title']) || isset($post['seo_description']) || isset($post['seo_canonical']) || isset($post['seo_og_title']) || isset($post['seo_og_description']) || isset($post['seo_og_image']) || isset($post['seo_noindex'])) {
            $seo = is_array($data['seo'] ?? null) ? $data['seo'] : [];
            if ($seoTitle !== '') {
                $seo['title'] = $seoTitle;
            } else {
                unset($seo['title']);
            }
            if ($seoDescription !== '') {
                $seo['description'] = $seoDescription;
            } else {
                unset($seo['description']);
            }
            if ($seoCanonical !== '') {
                $seo['canonical'] = $seoCanonical;
            } else {
                unset($seo['canonical']);
            }
            if ($seoOgTitle !== '') {
                $seo['og_title'] = $seoOgTitle;
            } else {
                unset($seo['og_title']);
            }
            if ($seoOgDescription !== '') {
                $seo['og_description'] = $seoOgDescription;
            } else {
                unset($seo['og_description']);
            }
            if ($seoOgImage !== '') {
                $seo['og_image'] = $seoOgImage;
            } else {
                unset($seo['og_image']);
            }
            if ($seoNoindex) {
                $seo['noindex'] = true;
            } else {
                unset($seo['noindex']);
            }
            if (!empty($seo)) {
                $data['seo'] = $seo;
            } else {
                unset($data['seo']);
            }
        }

        foreach (array_keys($data) as $key) {
            if (!$this->isReservedKey($key)) {
                unset($data[$key]);
            }
        }

        $customFields = [];
        if (is_array($customKeys) && is_array($customValues)) {
            foreach ($customKeys as $index => $key) {
                $key = trim((string)$key);
                if ($key === '' || $this->isReservedKey($key)) {
                    continue;
                }
                $value = (string)($customValues[$index] ?? '');
                $customFields[$key] = self::parseCustomValue($value);
            }
        }

        if ($type !== 'forms' && $this->types->declaredKeys($type) !== []) {
            // Fields the content type declares come from their own inputs, checked against the definition.
            $declaredKeys = $this->types->declaredKeys($type);
            $customFields = array_diff_key($customFields, array_flip($declaredKeys));
            $existingCustom = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
            if (($post['details_present'] ?? '') === '1') {
                $details = is_array($post['details'] ?? null) ? $post['details'] : [];
                $declared = $this->types->fromInput($type, $details, $existingCustom, 'en', $this->paths()->defaultLang());
            } else {
                $declared = array_intersect_key($existingCustom, array_flip($declaredKeys));
            }
            $customFields = $declared + $customFields;
        }

        if (!empty($customFields)) {
            $data['custom_fields'] = $customFields;
        } else {
            unset($data['custom_fields']);
        }

        if ($type === 'forms') {
            $fields = FormFields::parseInput($formFieldsInput);
            if (!empty($fields)) {
                $data['fields'] = $fields;
            } else {
                unset($data['fields']);
            }

            $notifications = [];
            if (is_array($formNotificationsInput)) {
                $notifications['enabled'] = Format::isTruthy($formNotificationsInput['enabled'] ?? false);
                $notifications['to'] = trim((string)($formNotificationsInput['to'] ?? ''));
                $notifications['subject'] = trim((string)($formNotificationsInput['subject'] ?? ''));
                $notifications['reply_to_field'] = trim((string)($formNotificationsInput['reply_to_field'] ?? ''));
                $notifications['cc'] = trim((string)($formNotificationsInput['cc'] ?? ''));
                $notifications['bcc'] = trim((string)($formNotificationsInput['bcc'] ?? ''));
                $notifications['auto_reply'] = Format::isTruthy($formNotificationsInput['auto_reply'] ?? false);
                $notifications['auto_reply_include'] = Format::isTruthy($formNotificationsInput['auto_reply_include'] ?? false);
                $notifications['auto_reply_subject'] = trim((string)($formNotificationsInput['auto_reply_subject'] ?? ''));
                $notifications['auto_reply_message'] = trim((string)($formNotificationsInput['auto_reply_message'] ?? ''));
            }
            if (!empty(array_filter($notifications, function ($value): bool {
                if (is_bool($value)) {
                    return $value;
                }
                return (string)$value !== '';
            }))) {
                $data['notifications'] = $notifications;
            } else {
                unset($data['notifications']);
            }

            if ($formSuccessMessage !== '') {
                $data['success_message'] = $formSuccessMessage;
            } else {
                unset($data['success_message']);
            }

            if ($formSubmitLabel !== '') {
                $data['submit_label'] = $formSubmitLabel;
            } else {
                unset($data['submit_label']);
            }

            if ($formRedirectUrl !== '') {
                $data['redirect_url'] = $formRedirectUrl;
            } else {
                unset($data['redirect_url']);
            }

            $data['store_submissions'] = $formStoreSubmissions;

            $antispam = [];
            if ($formHoneypot !== '') {
                $antispam['honeypot'] = $formHoneypot;
            }
            if ($formRateLimit !== '') {
                $antispam['rate_limit_seconds'] = (int)$formRateLimit;
            }
            if (!empty($antispam)) {
                $data['antispam'] = $antispam;
            } else {
                unset($data['antispam']);
            }
        }

        $frontmatter = trim(Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $payload = "---\n" . $frontmatter . "\n---\n\n" . $body . "\n";
        file_put_contents($path, $payload);
        $this->index($type, $path);

        if ($oldPath !== '' && $oldPath !== $path && file_exists($oldPath)) {
            unlink($oldPath);
            $this->unindex($type, $originalSlug, $originalLang);
        }

        // Addresses that changed leave a redirect behind, so links, bookmarks, and search results keep working.
        $moves = [];
        $movedTogether = false;
        if ($moved) {
            $moves[] = ['lang' => $originalLang, 'to_lang' => $lang, 'status' => $oldStatus];
            if ($siblings !== [] && (string)($post['rename_translations'] ?? '') === '1') {
                foreach ($siblings as $sibling) {
                    $from = $dir . '/' . $this->paths()->filename($originalSlug, $sibling['lang']);
                    $to = $dir . '/' . $this->paths()->filename($slug, $sibling['lang']);
                    if (!is_file($from) || file_exists($to) || !@rename($from, $to)) {
                        continue;
                    }
                    $this->unindex($type, $originalSlug, $sibling['lang']);
                    $this->index($type, $to);
                    $this->revisions?->rename($type, $originalSlug, $sibling['lang'], $slug, $sibling['lang']);
                    $moves[] = ['lang' => $sibling['lang'], 'to_lang' => $sibling['lang'], 'status' => $sibling['status']];
                }
            }
            foreach ($moves as $move) {
                if ($move['status'] !== 'published') {
                    continue; // An address that was never public has nobody to redirect.
                }
                $this->redirects->moved(
                    ContentPaths::build($type, $originalSlug, $move['lang'], $homeSlug, $defaultLang),
                    ContentPaths::build($type, $slug, $move['to_lang'], $homeSlug, $defaultLang),
                    $actor,
                    $type
                );
            }
            $movedTogether = count($moves) === 1 + count($siblings);
            $this->revisions?->rename($type, $originalSlug, $originalLang, $slug, $lang);
        }
        $this->revisions?->capture($type, $slug, $lang, $payload, $sourceExists || $wasExisting ? 'save' : 'create', $actor);
        // Content now lives here, so a redirect that starts from this address would never be used.
        $this->redirects->removeSource(ContentPaths::build($type, $slug, $lang, $homeSlug, $defaultLang));

        return [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title' => (string)($data['title'] ?? ''),
            'status' => (string)($data['status'] ?? ''),
            'was_existing' => $wasExisting,
            'moved' => $moved,
            'moved_together' => $movedTogether,
            'original_slug' => $originalSlug,
            'original_lang' => $originalLang,
            'slug_taken' => $slugTaken,
            'html_neutralized' => $htmlNeutralized,
            'translations_renamed' => $moved ? max(0, count($moves) - 1) : 0,
        ];
    }

    /**
     * Brings back the text of an earlier version, or of a deleted item, at the item's current address. Someone without
     * the raw HTML permission gets it with any HTML that is not already in the current file shown as plain text.
     *
     * @return array{ok: bool, error: string, html_neutralized: bool, was_deleted: bool}
     */
    public function restore(string $type, string $slug, string $lang, string $raw, bool $canRawHtml, string $actor, bool $undelete = false): array
    {
        $fail = static fn(string $error): array => ['ok' => false, 'error' => $error, 'html_neutralized' => false, 'was_deleted' => false];
        $path = $this->contentDir . '/' . $type . '/' . $this->paths()->filename($slug, $lang);
        $exists = is_file($path);
        if ($undelete && $exists) {
            return $fail('address_in_use');
        }
        if (!$undelete && !$exists) {
            return $fail('missing');
        }

        [$front, $body] = FrontMatter::split($raw);
        try {
            $data = $front !== '' ? Yaml::parse($front) : [];
        } catch (\Throwable) {
            return $fail('unreadable');
        }
        if (!is_array($data)) {
            return $fail('unreadable');
        }

        $payload = $raw;
        $neutralized = false;
        if ($type !== 'forms' && !$canRawHtml) {
            $allowed = $this->guard->storedFragments($exists ? $path : '');
            $plainBody = str_replace(["\r\n", "\r"], "\n", rtrim($body));
            $cleanBody = $this->guard->neutralize(rtrim($body), $allowed);
            $neutralized = $cleanBody !== $plainBody;
            if (isset($data['blocks']) && is_array($data['blocks'])) {
                $data['blocks'] = $this->guard->eachMarkdownField(array_values($data['blocks']), function (string $value) use ($allowed, &$neutralized): string {
                    $clean = $this->guard->neutralize($value, $allowed);
                    $neutralized = $neutralized || $clean !== str_replace(["\r\n", "\r"], "\n", $value);
                    return $clean;
                });
            }
            if ($neutralized) {
                $payload = "---\n" . trim(Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n\n" . $cleanBody . "\n";
            }
        }

        if ($exists) {
            $this->revisions?->baseline($type, $slug, $lang, $path, $actor);
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $fail('write');
        }
        if (file_put_contents($path, $payload) === false) {
            return $fail('write');
        }
        $this->index($type, $path);
        $this->revisions?->capture($type, $slug, $lang, $payload, 'restore', $actor);
        // Content lives here again, so a redirect that starts from this address would never be used.
        $this->redirects->removeSource($this->paths()->publicPath($type, $slug, $lang));

        return ['ok' => true, 'error' => '', 'html_neutralized' => $neutralized, 'was_deleted' => $undelete];
    }

    /**
     * Points the links in content at their new places, in every file that has one. Only the address in the text
     * changes; nothing else in a file is touched, and the earlier version stays in the history.
     *
     * @param array<string, string> $map normalised path => where it goes now ("/path" on this site or a full address)
     * @param \Closure(string): bool|null $mayEdit whether the person may change content of a type; files of other types are left alone
     * @return array{files: int, links: int, failed: int}
     */
    public function updateLinks(LinkScanner $scanner, array $map, string $actor, ?\Closure $mayEdit = null): array
    {
        $done = ['files' => 0, 'links' => 0, 'failed' => 0];
        foreach ($scanner->find(array_keys($map)) as $file) {
            if ($mayEdit !== null && !$mayEdit($file['type'])) {
                continue;
            }
            $raw = (string)@file_get_contents($file['path']);
            [$updated, $changed] = $scanner->rewrite($raw, $map);
            if ($changed === 0 || $updated === $raw) {
                continue;
            }
            $this->revisions?->baseline($file['type'], $file['slug'], $file['lang'], $file['path'], $actor);
            if (@file_put_contents($file['path'], $updated) === false) {
                $done['failed']++;
                continue;
            }
            $this->index($file['type'], $file['path']);
            $this->revisions?->capture($file['type'], $file['slug'], $file['lang'], $updated, 'links', $actor);
            $done['files']++;
            $done['links'] += $changed;
        }
        return $done;
    }

    private function paths(): ContentPaths
    {
        return new ContentPaths($this->settings);
    }

    private function index(string $type, string $path): void
    {
        try {
            if (is_file($path)) {
                $this->contentIndex->upsert($this->content->parseFile($type, $path));
            }
        } catch (\Throwable) {
            // The index is rebuildable; a failed write only makes it stale.
        }
    }

    private function unindex(string $type, string $slug, string $lang): void
    {
        try {
            $this->contentIndex->remove($type, $slug, $lang);
        } catch (\Throwable) {
            // The index is rebuildable; a failed delete only makes it stale.
        }
    }

    public static function newTranslationId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * A web address nobody else has: the requested one, or with -2, -3 ... added. Pages live at the root of the site,
     * so they cannot take a word the site already uses (admin, search, a content type, a language code).
     */
    private function availableSlug(string $type, string $slug, string $lang, string $ownPath): string
    {
        $base = $slug;
        if ($type === 'pages') {
            $reserved = array_merge($this->content->getTypes(), (array)($this->settings['languages']['available'] ?? []));
            if (Slug::isReserved($base, $reserved)) {
                $base .= '-page';
            }
        }
        $dir = $this->contentDir . '/' . $type;
        $candidate = $base;
        for ($n = 2; $n < 500; $n++) {
            $candidatePath = $dir . '/' . $this->paths()->filename($candidate, $lang);
            if (!file_exists($candidatePath) || $candidatePath === $ownPath) {
                break;
            }
            $candidate = $base . '-' . $n;
        }
        return $candidate;
    }

    private function storedStatus(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        [$front] = FrontMatter::split((string)file_get_contents($path));
        try {
            $data = Yaml::parse($front);
        } catch (\Throwable) {
            return '';
        }
        return is_array($data) ? (string)($data['status'] ?? 'published') : '';
    }

    /** Blocks stored in an existing content file, used when the editor did not submit any. */
    private function storedBlocks(string $path): ?array
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        [$frontmatter] = FrontMatter::split((string)file_get_contents($path));
        try {
            $meta = $frontmatter !== '' ? Yaml::parse($frontmatter) : [];
        } catch (\Throwable) {
            return null;
        }
        return is_array($meta) && is_array($meta['blocks'] ?? null) ? $meta['blocks'] : null;
    }

    /**
     * The other languages of an item that share its address (translations kept together by translation_id).
     *
     * @return array<int, array{lang: string, status: string}>
     */
    public function translationSiblings(string $type, string $slug, string $lang): array
    {
        $items = $this->content->getItems($type, null, true);
        $id = '';
        foreach ($items as $item) {
            if ($item->slug === $slug && $item->lang === $lang) {
                $id = (string)($item->meta['translation_id'] ?? '');
            }
        }
        $siblings = [];
        foreach ($items as $item) {
            if ($item->slug !== $slug || $item->lang === $lang) {
                continue;
            }
            $itemId = (string)($item->meta['translation_id'] ?? '');
            if ($id !== '' ? $itemId === $id : $itemId === '') {
                $siblings[] = ['lang' => $item->lang, 'status' => (string)($item->meta['status'] ?? 'published')];
            }
        }
        return $siblings;
    }

    public function translationIdForSlug(string $type, string $slug): string
    {
        foreach ($this->content->getItems($type, null, true) as $item) {
            if ($item->slug !== $slug) {
                continue;
            }
            $id = (string)($item->meta['translation_id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    public function normalizeDate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $trimmed)) {
            return $trimmed;
        }
        if (ctype_digit($trimmed)) {
            return date('Y-m-d', (int)$trimmed);
        }
        $format = (string)($this->settings['date_format'] ?? 'd/m/Y');
        $dt = \DateTime::createFromFormat($format, $trimmed);
        if ($dt instanceof \DateTime) {
            return $dt->format('Y-m-d');
        }
        $timestamp = strtotime($trimmed);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        return $trimmed;
    }

    public function isReservedKey(string $key): bool
    {
        $reserved = [
            'title',
            'status',
            'visible',
            'date',
            'author',
            'tags',
            'categories',
            'excerpt',
            'summary',
            'seo',
            'main_image',
            'custom_fields',
            'template',
            'blocks',
            'translation_id',
            'fields',
            'notifications',
            'success_message',
            'submit_label',
            'redirect_url',
            'store_submissions',
            'antispam',
        ];
        foreach (($this->taxonomyNames)() as $taxonomyName) {
            $reserved[] = $taxonomyName;
        }
        return in_array($key, $reserved, true);
    }

    private static function parseCustomValue(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        $lower = strtolower($trimmed);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) ||
            (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float)$trimmed : (int)$trimmed;
        }
        return $value;
    }
}

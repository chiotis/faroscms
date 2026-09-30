<?php

declare(strict_types=1);

namespace FarosCMS;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class App
{
    private string $basePath;
    private string $contentDir;
    private array $settings;
    private TwigEnvironment $twig;
    private ContentRepository $content;
    private Auth $auth;
    private SystemDatabase $systemDatabase;
    private SystemMetaRepository $systemMeta;
    private UserRepository $users;
    private PermissionService $permissions;
    private RedirectRepository $redirects;
    private RevisionRepository $revisions;
    private ?HtmlGuard $htmlGuard = null;
    private ?ContentEditor $contentEditor = null;
    private ActivityLogRepository $activityLogs;
    private EmailLogRepository $emailLogs;
    private NotificationRepository $notifications;
    private BackupRunRepository $backupRuns;
    private LoginThrottle $loginThrottle;
    private BackupService $backups;
    private FormSubmissionRepository $formSubmissions;
    private ContentIndex $contentIndex;
    private MediaLibrary $media;
    private MediaUsage $mediaUsage;
    private Theme $theme;
    private Images $images;
    private MarkdownConverter $markdown;
    private ?BlockRegistry $blockRegistry = null;
    private ?PresetLibrary $presetLibrary = null;
    private ?ContentTypes $contentTypes = null;
    private string $currentLang;
    private array $translations = [];
    private array $formStates = [];
    private ?Menus $menuStore = null;
    private ?StructuredData $structuredDataService = null;
    private ?ContentCsv $contentCsvService = null;
    private ?SiteSettings $siteSettingsService = null;
    private ?BackupManager $backupManagerService = null;
    private ?TaxonomyEditor $taxonomyEditorService = null;
    private ?RedirectAdmin $redirectAdminService = null;
    private ?LanguageAlternates $languageAlternatesService = null;
    private ?EntryTranslations $entryTranslationsService = null;
    private ?ThemeStrings $themeStringsService = null;
    private ?MediaAdmin $mediaAdminService = null;
    private ?SiteLimits $siteLimitsService = null;
    private ?SystemStatus $systemStatusService = null;
    private ?DashboardData $dashboardDataService = null;
    private ?FormsAdmin $formsAdminService = null;
    private ?FormProcessor $formProcessorService = null;
    private ?SignIn $signInService = null;
    private ?GoogleSignIn $googleSignInService = null;
    private ?RoleAdmin $roleAdminService = null;
    private ?UserAdmin $userAdminService = null;
    private ?ContentTypeAdmin $contentTypeAdminService = null;
    private ?PublicPaths $publicPathsService = null;
    private ?Taxonomies $taxonomyStore = null;
    /** @var array<string, mixed>|null */
    private array $themeSettings = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->contentDir = $this->basePath . '/content';

        $this->systemDatabase = new SystemDatabase($this->basePath . '/storage');
        $this->systemDatabase->initialize();
        $this->systemMeta = new SystemMetaRepository($this->systemDatabase);
        $this->settings = $this->siteSettings()->load();
        $this->theme = new Theme($this->basePath, (string)($this->settings['theme'] ?? Theme::DEFAULT_NAME));
        $this->themeSettings = $this->loadThemeSettings();
        $this->menus()->ensureDefaults();
        $this->taxonomies()->ensureDefaults();

        $markdown = $this->markdownConverter();
        $this->markdown = $markdown;

        $this->users = new UserRepository($this->systemDatabase, $this->contentDir . '/users/users.yaml');
        $this->users->importYamlUsersIfEmpty();
        $this->users->ensureSuperadminExists();
        $this->permissions = new PermissionService($this->systemMeta->getJson('role_permissions'), $this->systemMeta->getJson('custom_roles'));
        $this->users->allowRoles(array_keys($this->permissions->customRoles()));
        $this->redirects = new RedirectRepository($this->systemDatabase);
        $this->revisions = new RevisionRepository($this->systemDatabase);
        $this->activityLogs = new ActivityLogRepository($this->systemDatabase);
        $this->emailLogs = new EmailLogRepository($this->systemDatabase);
        $this->notifications = new NotificationRepository($this->systemDatabase);
        $this->backupRuns = new BackupRunRepository($this->systemDatabase);
        $this->loginThrottle = new LoginThrottle($this->systemDatabase);
        $this->backups = new BackupService($this->basePath, $this->systemDatabase);
        $this->formSubmissions = new FormSubmissionRepository($this->contentDir);
        $this->contentIndex = new ContentIndex($this->systemDatabase, $this->contentDir);
        $this->media = new MediaLibrary($this->contentDir, $this->basePath . '/public/uploads');
        $this->media->restrictTo(is_array($this->settings['limits']['upload_types'] ?? null) ? $this->settings['limits']['upload_types'] : []);
        $this->mediaUsage = new MediaUsage($this->contentDir, fn(): array => $this->mediaUsageSettingsSources(), (string)($this->settings['languages']['default'] ?? 'en'), $this->systemMeta);
        $this->images = new Images($this->basePath . '/public', $this->contentDir . '/media');
        $this->content = new ContentRepository($this->contentDir, $markdown, $this->settings);
        $this->content->onUnreadable(fn(string $type, string $path, string $message) => $this->reportUnreadableContent($type, $path, $message));
        $this->auth = new Auth($this->contentDir . '/users/users.yaml', $this->users);

        $this->twig = $this->initTwig();
        $this->currentLang = $this->settings['languages']['default'] ?? 'en';
        $this->translations = $this->loadTranslations($this->currentLang);
    }

    public function handle(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->configureSession();
            session_start();
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = trim($path, '/');

        if (str_starts_with($path, 'admin')) {
            $this->sendSecurityHeaders(true);
            $this->handleAdmin($path);
            return;
        }

        $this->sendSecurityHeaders(false);

        $this->handleFront($path);
    }

    private function contentEditor(): ContentEditor
    {
        return $this->contentEditor ??= new ContentEditor(
            $this->contentDir,
            $this->settings,
            $this->content,
            $this->contentIndex,
            $this->contentTypes(),
            $this->theme,
            $this->redirects,
            $this->htmlGuard(),
            fn(): BlockRegistry => $this->blockRegistry(),
            fn(): array => $this->taxonomies()->names(),
            $this->revisions
        );
    }

    private function htmlGuard(): HtmlGuard
    {
        return $this->htmlGuard ??= new HtmlGuard(fn(): Environment => $this->markdownEnvironment(), fn(): BlockRegistry => $this->blockRegistry());
    }

    private function markdownConverter(): MarkdownConverter
    {
        return new MarkdownConverter($this->markdownEnvironment());
    }

    private function markdownEnvironment(): Environment
    {
        $environment = new Environment([
            'renderer' => ['soft_break' => "<br />\n"],
            // [text](javascript:…) and similar links lose their address instead of running script when clicked.
            'allow_unsafe_links' => false,
            // A wide table scrolls inside its own box; tabindex lets keyboard users scroll it.
            'table' => ['wrap' => ['enabled' => true, 'tag' => 'div', 'attributes' => ['class' => 'table-wrap', 'tabindex' => '0']]],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        return $environment;
    }

    /**
     * Shows raw HTML as plain text instead of letting it through, except HTML that is already in the
     * content being edited (placed there by someone allowed to), so an edit never breaks an embed.
     *
     * @param string[] $allowed HTML fragments to leave as they are
     */
    private function neutralizeRawHtml(string $markdown, array $allowed = []): string
    {
        return $this->htmlGuard()->neutralize($markdown, $allowed);
    }

    /**
     * Raw HTML fragments in the Markdown of an existing content file (its body and its blocks' Markdown fields).
     *
     * @return string[]
     */
    private function storedHtmlFragments(string $path): array
    {
        return $this->htmlGuard()->storedFragments($path);
    }

    /**
     * Applies $change to every Markdown field of the blocks (including items inside repeaters) and returns the blocks.
     *
     * @param array<int, mixed> $blocks
     * @param \Closure(string): string $change
     * @return array<int, mixed>
     */
    private function eachMarkdownField(array $blocks, \Closure $change): array
    {
        return $this->htmlGuard()->eachMarkdownField($blocks, $change);
    }

    private function configureSession(): void
    {
        if (headers_sent()) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function isHttpsRequest(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private function sendSecurityHeaders(bool $admin): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        if ($admin) {
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: same-origin');
            header('Cache-Control: no-store, private');
            return;
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    private function csrfToken(): string
    {
        $token = $_SESSION['_csrf_token'] ?? '';
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token'] = $token;
        }
        return $token;
    }

    private function isValidCsrfRequest(): bool
    {
        $expected = $_SESSION['_csrf_token'] ?? '';
        $provided = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return is_string($expected) && $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
    }

    private function handleFront(string $path): void
    {
        if ($path === 'sitemap.xml') {
            $this->renderSitemap();
            return;
        }

        if ($path === 'robots.txt') {
            $this->renderRobots();
            return;
        }

        [$lang, $segments] = $this->extractLang($path);
        $this->setLanguage($lang);
        $typeList = $this->content->getTypes();
        $langPrefix = $this->langPrefix($lang);
        $includeHidden = $this->auth->check();
        $homeSlug = $this->settings['home_page'] ?? 'index';
        $pathNoLang = implode('/', $segments);
        if ($pathNoLang === $homeSlug) {
            $pathNoLang = '';
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_slug']) && ($segments[0] ?? '') !== 'forms') {
            $formSlug = $this->slugify((string)($_POST['form_slug'] ?? ''));
            if ($formSlug !== '') {
                $formItem = $this->content->find('forms', $formSlug, $lang, $includeHidden, false);
                if ($formItem) {
                    $this->formStates[$formSlug] = $this->handleFormRequest($formItem, $lang, $path);
                }
            }
        }
        $currentUrl = $this->buildAbsoluteUrl($path);
        $viewDefaults = [
            'lang' => $lang,
            'lang_prefix' => $langPrefix,
            'current_lang' => $lang,
            'path_no_lang' => $pathNoLang,
            'canonical_url' => $currentUrl,
            'theme_menus' => $this->menus()->forTheme($lang, $pathNoLang),
        ];

        if (($segments[0] ?? '') === 'pages') {
            $slug = $segments[1] ?? '';
            $target = '/' . $langPrefix;
            if ($slug !== '' && $slug !== $homeSlug) {
                $target .= $slug;
            }
            header('Location: ' . $target, true, 301);
            exit;
        }

        if (in_array($segments[0] ?? '', ['tag', 'tags', 'category', 'categories'], true)) {
            $this->handleTaxonomy($segments, $lang, $viewDefaults);
            return;
        }

        if (($segments[0] ?? '') === 'search') {
            $query = (string)($_GET['q'] ?? '');
            $results = $this->content->search($query, $lang, $includeHidden);
            $this->render('templates/search.twig', [
                'query' => $query,
                'results' => $results,
            ] + $viewDefaults);
            return;
        }

        if (isset($segments[0]) && in_array($segments[0], $typeList, true)) {
            $type = $segments[0];
            $slug = $segments[1] ?? null;
            if ($slug === null || $slug === '') {
                $items = $this->content->getItems($type, $lang, $includeHidden, false);
                $alternates = $this->languageAlternates()->forArchive($type);
                $archive = $this->archiveContext($type, $lang, $items);
                if ($archive['page'] > 1 && !$archive['filtered']) {
                    // Each page of a listing is its own page for search engines.
                    $viewDefaults['canonical_url'] = $currentUrl . '?page=' . $archive['page'];
                }
                $this->render($this->resolveArchiveTemplate($type), [
                    'items' => $archive['items'],
                    'archive' => $archive,
                    'type' => $type,
                    'alternate_urls' => $alternates['urls'],
                    'alternate_default' => $alternates['default'],
                    'block_styles' => [$this->theme->blockStylesheetUrl(rtrim((string)($this->settings['base_url'] ?? ''), '/'), ['latest'])],
                    'noindex_page' => $archive['filtered'],
                ] + $viewDefaults);
                return;
            }

            $item = $this->content->find($type, $slug, $lang, $includeHidden, false);
            if (!$item) {
                $this->render404();
                return;
            }
            $item->html = $this->applyShortcodes($item->html, $lang, $path);

            $viewDefaults['canonical_url'] = $this->resolveCanonicalUrl(
                $item->meta['seo']['canonical'] ?? null,
                $currentUrl
            );
            $alternates = $this->languageAlternates()->forItem($type, $item->slug);
            $languageLinks = $this->languageAlternates()->languageLinks($type, $item);
            if ($type === 'forms') {
                $formState = $this->handleFormRequest($item, $lang, $path);
                $this->render($this->resolveItemTemplate($item), [
                    'item' => $item,
                    'form_fields' => $formState['fields'],
                    'form_values' => $formState['values'],
                    'form_errors' => $formState['errors'],
                    'form_success' => $formState['success'],
                    'form_message' => $formState['message'],
                    'form_action' => $formState['action'],
                    'form_honeypot' => $formState['honeypot'],
                    'form_redirect' => $formState['redirect'],
                ] + $viewDefaults + [
                    'alternate_urls' => $alternates['urls'],
                    'alternate_default' => $alternates['default'],
                    'language_links' => $languageLinks,
                ]);
                return;
            }
            $this->render($this->resolveItemTemplate($item), [
                'item' => $item,
                'alternate_urls' => $alternates['urls'],
                'alternate_default' => $alternates['default'],
                'language_links' => $languageLinks,
            ] + $this->frontItemData($item, $lang, $path, $viewDefaults, false) + $viewDefaults);
            return;
        }

        $slug = $segments[0] ?? $homeSlug;
        if ($slug === '') {
            $slug = $homeSlug;
        }
        $page = $this->content->find('pages', $slug, $lang, $includeHidden, false);
        if (!$page) {
            $this->render404();
            return;
        }
        $page->html = $this->applyShortcodes($page->html, $lang, $path);

        $viewDefaults['canonical_url'] = $this->resolveCanonicalUrl(
            $page->meta['seo']['canonical'] ?? null,
            $currentUrl
        );
        $alternates = $this->languageAlternates()->forItem('pages', $page->slug);
        $languageLinks = $this->languageAlternates()->languageLinks('pages', $page);
        $template = $slug === $homeSlug ? 'templates/home.twig' : $this->resolveItemTemplate($page);
        $this->render($template, [
            'item' => $page,
            'alternate_urls' => $alternates['urls'],
            'alternate_default' => $alternates['default'],
            'language_links' => $languageLinks,
        ] + $this->frontItemData($page, $lang, $path, $viewDefaults, $template === 'templates/home.twig') + $viewDefaults);
    }

    /**
     * Rendered blocks, their stylesheets, and structured data for a page or single item.
     *
     * @param array<string, mixed> $viewDefaults
     * @return array<string, mixed>
     */
    private function frontItemData(ContentItem $item, string $lang, string $path, array $viewDefaults, bool $isHome): array
    {
        $pageBlocks = null;
        $raw = $item->meta['blocks'] ?? null;
        if (is_array($raw) && $raw !== []) {
            // The sidebar template places the text itself, so blocks must not repeat it.
            $bodyInTemplate = !$isHome && $this->resolveItemTemplate($item) === 'templates/sidebar.twig';
            $pageBlocks = $this->blockRenderer($lang, $path)->render(array_values($raw), $viewDefaults + [
                'item' => $item,
                'body_html' => $bodyInTemplate ? '' : $item->html,
            ]);
        }
        return [
            'page_blocks' => $pageBlocks,
            'block_styles' => $pageBlocks['styles'] ?? [],
            'block_scripts' => $pageBlocks['scripts'] ?? [],
            'structured_data' => array_merge(
                $this->structuredData()->forItem($item, $lang, (string)($viewDefaults['canonical_url'] ?? ''), $isHome, (string)($pageBlocks['image'] ?? '')),
                $pageBlocks['structured_data'] ?? []
            ),
            'og_type' => $item->type === 'posts' ? 'article' : 'website',
        ];
    }

    /**
     * What an archive page shows: the items of the requested page after filters and ordering,
     * the filters offered, and pagination. Filters come from the type's definition (filterable
     * select fields and chosen taxonomies) and only list values that some item really has.
     *
     * @param ContentItem[] $items
     * @return array<string, mixed>
     */
    private function archiveContext(string $type, string $lang, array $items): array
    {
        $definition = $this->contentTypes()->definition($type, $lang, $this->defaultLanguage());
        return $this->buildArchive($definition['archive'], $definition['fields'], $definition, $lang, $items);
    }

    /**
     * The archive of any list of entries (a content type, or the entries of a category or tag): filters, order, and pages
     * from settings that a content type or a taxonomy chose. Filters come from the chosen taxonomies and, for a content
     * type, from its filterable select fields.
     *
     * @param array<string, mixed> $settings resolved archive settings
     * @param array<string, array<string, mixed>> $fields the declared fields of the type (none for a taxonomy)
     * @param array<string, mixed>|null $definition the type's definition, when there is one
     * @param ContentItem[] $items
     * @return array<string, mixed>
     */
    private function buildArchive(array $settings, array $fields, ?array $definition, string $lang, array $items): array
    {
        // Facets: name => label, options (value => label), how to read an item's values.
        $facets = [];
        $taxonomyNames = $this->taxonomies()->names();
        foreach ($settings['taxonomies'] as $taxonomy) {
            if (in_array($taxonomy, $taxonomyNames, true)) {
                $facets[$taxonomy] = ['label' => $this->titleFromSlug($taxonomy), 'kind' => 'taxonomy', 'labels' => []];
            }
        }
        foreach ($fields as $key => $field) {
            if ($field['filterable'] && !$field['hidden']) {
                $facets[$key] = ['label' => $field['label'], 'kind' => 'field', 'labels' => $field['options']];
            }
        }
        $valuesOf = function (ContentItem $item, string $name, array $facet): array {
            if ($facet['kind'] === 'taxonomy') {
                return $this->normalizeMetaList($item->meta[$name] ?? null);
            }
            $value = $item->meta['custom_fields'][$name] ?? '';
            return is_scalar($value) && (string)$value !== '' ? [(string)$value] : [];
        };

        $requested = is_array($_GET['filter'] ?? null) ? $_GET['filter'] : [];
        $filters = [];
        $selected = [];
        foreach ($facets as $name => $facet) {
            $counts = [];
            foreach ($items as $item) {
                foreach ($valuesOf($item, $name, $facet) as $value) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            $options = [];
            foreach ($counts as $value => $count) {
                $value = (string)$value;
                $label = $facet['kind'] === 'taxonomy'
                    ? $this->taxonomyTermLabel($name, $value, $lang)
                    : (string)($facet['labels'][$value] ?? $value);
                $options[] = ['value' => $value, 'label' => $label !== '' ? $label : $value, 'count' => $count];
            }
            usort($options, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
            $choice = is_scalar($requested[$name] ?? null) ? (string)$requested[$name] : '';
            if ($choice !== '' && !isset($counts[$choice])) {
                $choice = '';
            }
            if ($choice !== '') {
                $selected[$name] = $choice;
            }
            if (count($options) >= 2 || $choice !== '') {
                $filters[] = ['name' => $name, 'label' => $facet['label'], 'kind' => $facet['kind'], 'options' => $options, 'selected' => $choice];
            }
        }

        foreach ($selected as $name => $choice) {
            $items = array_values(array_filter($items, fn(ContentItem $item): bool => in_array($choice, $valuesOf($item, $name, $facets[$name]), true)));
        }

        $title = static fn(ContentItem $item): string => mb_strtolower((string)($item->meta['title'] ?? $item->slug));
        if (preg_match('/^field:([a-z][a-z0-9_]*):(asc|desc)$/', (string)$settings['order'], $m)) {
            // By a declared field; items without a value go last in either direction.
            [$key, $direction] = [$m[1], $m[2]];
            $type_ = $fields[$key]['type'] ?? 'text';
            $sortKey = static function (ContentItem $item) use ($key, $type_): string|float|null {
                $value = $item->meta['custom_fields'][$key] ?? null;
                if ($value === null || $value === '') {
                    return null;
                }
                if ($type_ === 'number' || $type_ === 'decimal') {
                    return is_numeric($value) ? (float)$value : null;
                }
                if ($type_ === 'date' && is_int($value)) {
                    return gmdate('Y-m-d', $value);
                }
                return mb_strtolower((string)$value);
            };
            $withValue = array_values(array_filter($items, static fn(ContentItem $i): bool => $sortKey($i) !== null));
            $without = array_values(array_filter($items, static fn(ContentItem $i): bool => $sortKey($i) === null));
            usort($withValue, static fn(ContentItem $a, ContentItem $b): int => $direction === 'asc' ? $sortKey($a) <=> $sortKey($b) : $sortKey($b) <=> $sortKey($a));
            $items = array_merge($withValue, $without);
        } elseif ($settings['order'] === 'date_asc') {
            $items = array_reverse($items);
        } elseif ($settings['order'] === 'title_asc') {
            usort($items, static fn(ContentItem $a, ContentItem $b): int => strcmp($title($a), $title($b)));
        } elseif ($settings['order'] === 'title_desc') {
            usort($items, static fn(ContentItem $a, ContentItem $b): int => strcmp($title($b), $title($a)));
        }

        $total = count($items);
        $perPage = (int)$settings['per_page'];
        $pages = $perPage > 0 ? max(1, (int)ceil($total / $perPage)) : 1;
        $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
        if ($perPage > 0) {
            $items = array_slice($items, ($page - 1) * $perPage, $perPage);
        }
        $link = static function (int $number) use ($selected): string {
            $query = [];
            if ($selected !== []) {
                $query['filter'] = $selected;
            }
            if ($number > 1) {
                $query['page'] = $number;
            }
            return '?' . http_build_query($query);
        };

        return [
            'definition' => $definition,
            'settings' => $settings,
            'items' => $items,
            'total' => $total,
            'filters' => $filters,
            'filtered' => $selected !== [],
            'page' => $page,
            'pages' => $pages,
            'prev' => $page > 1 ? $link($page - 1) : '',
            'next' => $page < $pages ? $link($page + 1) : '',
            'page_links' => $pages > 1 ? array_map(static fn(int $n): array => ['number' => $n, 'href' => $link($n), 'current' => $n === $page], range(1, $pages)) : [],
        ];
    }

    private function blockRenderer(string $lang, string $path): BlockRenderer
    {
        $includeHidden = $this->auth->check();
        return new BlockRenderer(
            $this->blockRegistry(),
            $this->twig,
            $this->theme,
            fn(string $markdown): string => $this->applyShortcodes((string)$this->markdown->convert($markdown), $lang, $path),
            [
                'items' => function (string $type, string $itemLang, int $limit, string $term = '') use ($includeHidden): array {
                    if ($type === 'forms' || !in_array($type, $this->content->getTypes(), true)) {
                        return [];
                    }
                    $items = $this->content->getItems($type, $itemLang, $includeHidden, false);
                    $term = $this->slugify($term);
                    if ($term !== '') {
                        // Only items filed under this category or tag.
                        $items = array_values(array_filter($items, function (ContentItem $item) use ($term): bool {
                            foreach (['categories', 'tags'] as $taxonomy) {
                                if (in_array($term, array_map('strval', (array)($item->meta[$taxonomy] ?? [])), true)) {
                                    return true;
                                }
                            }
                            return false;
                        }));
                    }
                    return array_slice($items, 0, max(1, min(24, $limit)));
                },
                'form' => fn(string $slug): string => $slug === '' ? '' : $this->renderFormEmbedBySlug($this->slugify($slug), $lang, $path),
            ],
            rtrim((string)($this->settings['base_url'] ?? ''), '/')
        );
    }

    private function blockRegistry(): BlockRegistry
    {
        return $this->blockRegistry ??= new BlockRegistry($this->theme, [
            'content_types' => function (): array {
                $options = [];
                foreach ($this->content->getTypes() as $type) {
                    if ($type !== 'forms' && $type !== 'pages') {
                        $options[$type] = $this->contentTypes()->definition($type, 'en', $this->defaultLanguage())['label'];
                    }
                }
                return $options;
            },
            'forms' => function (): array {
                $options = ['' => '—'];
                foreach ($this->content->getItems('forms', null, true) as $form) {
                    $options[$form->slug] = (string)($form->meta['title'] ?? $form->slug);
                }
                return $options;
            },
        ]);
    }

    private function handleAdmin(string $path): void
    {
        $segments = explode('/', $path);
        $action = $segments[1] ?? 'index';
        if ($action === 'index' && (isset($_GET['type']) || isset($_GET['lang']))) {
            $action = 'content';
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$this->isValidCsrfRequest()) {
            $this->rejectInvalidCsrf($action);
            return;
        }

        if ($action === 'login') {
            if ($this->auth->check() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                $this->redirect('/admin');
                return;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->handlePasswordLogin();
                return;
            }

            $this->renderLogin();
            return;
        }

        if ($action === 'google-login') {
            $this->handleGoogleLogin();
            return;
        }

        if ($action === 'google-callback') {
            $this->handleGoogleCallback();
            return;
        }

        if ($action === 'logout') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                // Signing out changes state, so it only happens through the CSRF-protected form.
                $this->redirect($this->auth->check() ? '/admin' : '/admin/login');
                return;
            }
            $this->logActivity('auth.logout', 'info', 'user', (string)($this->auth->user()['username'] ?? ''), 'User signed out.');
            $this->auth->logout();
            $this->redirect('/admin/login');
            return;
        }

        if (!$this->auth->check() || !$this->auth->refresh()) {
            $this->redirect('/admin/login');
            return;
        }

        if (!$this->permissions->canAccessAction($this->auth->user(), $action)) {
            $this->logActivity('auth.forbidden', 'warning', 'admin_route', $action, 'Blocked unauthorized admin route.', [
                'action' => $action,
            ]);
            $this->renderForbidden();
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $this->backupManager()->runIfDue();
        }

        if ($action === 'theme') {
            $this->handleTheme();
            return;
        }

        if ($action === 'settings') {
            $this->handleSettings();
            return;
        }

        if ($action === 'index' || $action === 'dashboard') {
            $this->handleDashboard();
            return;
        }

        if ($action === 'content') {
            $this->handleAdminList();
            return;
        }

        if ($action === 'content-bulk') {
            $this->handleContentBulk();
            return;
        }

        if ($action === 'search') {
            $this->handleAdminSearch();
            return;
        }

        if ($action === 'system') {
            $this->handleSystem();
            return;
        }

        if ($action === 'activity-logs') {
            $this->handleActivityLogs();
            return;
        }

        if ($action === 'email-logs') {
            $this->handleEmailLogs();
            return;
        }

        if ($action === 'backups') {
            $this->handleBackups();
            return;
        }

        if ($action === 'updates') {
            $this->handleUpdates();
            return;
        }

        if ($action === 'notification-read') {
            $this->handleNotificationRead();
            return;
        }

        if ($action === 'notifications-read-all') {
            $this->handleNotificationsReadAll();
            return;
        }

        if ($action === 'menus') {
            $this->handleMenusList();
            return;
        }

        if ($action === 'menus-new') {
            $this->handleMenusNew();
            return;
        }

        if ($action === 'menus-edit') {
            $this->handleMenus();
            return;
        }

        if ($action === 'media') {
            $this->handleMedia();
            return;
        }

        if ($action === 'files') {
            $this->redirect('/admin/media?type=document&view=list');
            return;
        }

        if ($action === 'forms') {
            $this->handleFormsList();
            return;
        }

        if ($action === 'form-submissions') {
            $this->handleFormSubmissions();
            return;
        }

        if ($action === 'forms-export') {
            $this->handleFormsExport();
            return;
        }

        if ($action === 'import') {
            $this->handleContentImport();
            return;
        }

        if ($action === 'export') {
            $this->handleContentExport();
            return;
        }

        if ($action === 'translations') {
            $this->handleTranslations();
            return;
        }

        if ($action === 'content-types') {
            $this->handleContentTypes();
            return;
        }

        if ($action === 'taxonomies') {
            $this->handleTaxonomies();
            return;
        }

        if ($action === 'roles') {
            $this->handleRoles();
            return;
        }

        if ($action === 'redirects') {
            $this->handleRedirects();
            return;
        }

        if ($action === 'revisions') {
            $this->handleRevisions();
            return;
        }

        if ($action === 'links') {
            $this->handleLinks();
            return;
        }

        if ($action === 'users') {
            $this->handleUsersList();
            return;
        }

        if ($action === 'users-edit') {
            $this->handleUserEdit();
            return;
        }

        if ($action === 'users-delete') {
            $this->handleUserDelete();
            return;
        }

        if ($action === 'edit') {
            $this->handleEdit();
            return;
        }

        if ($action === 'save') {
            $this->handleSave();
            return;
        }

        if ($action === 'block-presets') {
            $this->handleBlockPresets();
            return;
        }

        if ($action === 'media-picker') {
            $this->handleMediaPicker();
            return;
        }

        if ($action === 'delete') {
            $this->handleDelete();
            return;
        }

        if ($action === 'new') {
            $this->handleNew();
            return;
        }

        $this->handleAdminList();
    }

    private function handlePasswordLogin(): void
    {
        $outcome = $this->signIn()->password(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($outcome['status'] === 'blocked') {
            http_response_code(429);
            header('Retry-After: ' . $outcome['retry_after']);
            $this->renderLogin($outcome['message']);
            return;
        }
        if ($outcome['status'] === 'ok') {
            if ($outcome['default_password']) {
                $_SESSION['security_default_password'] = true;
                $this->notifyDefaultPassword($outcome['username']);
            }
            $this->redirect('/admin');
            return;
        }
        $this->renderLogin($outcome['message']);
    }

    private function signIn(): SignIn
    {
        return $this->signInService ??= new SignIn(
            $this->auth,
            $this->loginThrottle,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context, ?array $actor) => $this->logActivity($action, $level, $type, $id, $message, $context, $actor)
        );
    }

    private function googleSignIn(): GoogleSignIn
    {
        return $this->googleSignInService ??= new GoogleSignIn(fn(): array => $this->settings, fn(string $path): string => $this->buildAbsoluteUrl($path));
    }

    private function notifyDefaultPassword(string $username): void
    {
        try {
            $this->notifications->createIfMissing([
                'type' => 'security.default_password',
                'title' => 'Default password in use',
                'body' => $username . ' still signs in with the password shipped in content/users/users.yaml. Change it.',
                'severity' => 'error',
                'target_url' => '/admin/users-edit?id=' . (int)($this->auth->user()['id'] ?? 0),
                'context' => ['username' => $username],
            ]);
        } catch (\Throwable) {
            // Notifications must never block sign-in.
        }
    }

    private function rejectInvalidCsrf(string $action): void
    {
        $this->logActivity('security.csrf_rejected', 'warning', 'admin_route', $action, 'Rejected a form submission without a valid CSRF token.', [
            'action' => $action,
        ]);
        http_response_code(419);
        $message = 'This form could not be verified. It may have expired, or the upload was larger than the server allows. Reload the page and try again.';
        if ($action === 'login' || !$this->auth->check()) {
            $this->renderLogin($message);
            return;
        }
        $this->renderForbidden($message, 'Form expired');
    }

    private function renderLogin(string $error = ''): void
    {
        $this->render('@admin/login.twig', [
            'error' => $error,
            'google_auth' => $this->googleSignIn()->config(),
        ]);
    }

    private function handleGoogleLogin(): void
    {
        $google = $this->googleSignIn()->config();
        if (!$google['ready']) {
            $this->renderLogin('Google Sign-In is not configured yet.');
            return;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;
        header('Location: ' . $this->googleSignIn()->authorizationUrl($google, $state));
        exit;
    }

    private function handleGoogleCallback(): void
    {
        $sign = $this->googleSignIn();
        $google = $sign->config();
        if (!$google['ready']) {
            $this->renderLogin('Google Sign-In is not configured yet.');
            return;
        }

        $state = (string)($_GET['state'] ?? '');
        if ($state === '' || $state !== (string)($_SESSION['google_oauth_state'] ?? '')) {
            unset($_SESSION['google_oauth_state']);
            $this->renderLogin('Google Sign-In state could not be verified.');
            return;
        }
        unset($_SESSION['google_oauth_state']);

        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            $this->renderLogin('Google did not return an authorization code.');
            return;
        }

        $token = $sign->exchange($code, $google);
        if (!$token['ok']) {
            $this->renderLogin((string)$token['message']);
            return;
        }
        $profile = $sign->profile((string)$token['access_token']);
        if (!$profile['ok']) {
            $this->renderLogin((string)$profile['message']);
            return;
        }
        $accepted = $sign->accept($profile, $google);
        if (!$accepted['ok']) {
            $this->renderLogin($accepted['message']);
            return;
        }

        $email = $accepted['email'];
        $user = $this->users->findByEmail($email);
        if (!$user || !$this->users->isActive($user)) {
            $this->renderLogin('No active FarosCMS user matches this Google account.');
            return;
        }

        $this->users->linkGoogle((int)$user['id'], $accepted['sub'], $email);
        $user = $this->users->find((int)$user['id']) ?: $user;
        $this->auth->loginUser($user);
        $this->logActivity('auth.login_success', 'info', 'user', (string)($user['username'] ?? $email), 'User logged in.', [
            'method' => 'google',
            'email' => $email,
        ]);
        $this->redirect('/admin');
    }

    private function handleDashboard(): void
    {
        $dashboard = $this->dashboardData()->build(
            fn(string $capability): bool => $this->permissions->can($this->auth->user(), $capability),
            fn(string $type): bool => $this->canAccessContentType($type)
        );
        $this->render('@admin/dashboard.twig', [
            'title' => 'Dashboard',
            'dashboard' => $dashboard,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'dashboard',
            'current_type' => 'pages',
            'admin_storage_summary' => $dashboard['storage'],
        ]);
    }

    /** The storage limit typed on the settings form in megabytes, or null when this person may not change it or left it empty. */
    private function submittedStorageLimit(): ?int
    {
        return $this->permissions->can($this->auth->user(), 'limits.manage') ? SiteLimits::storageLimitFromForm($_POST) : null;
    }

    /** The active super admin people can write to when the storage is nearly full. @return array{name: string, email: string}|null */
    private function storageContact(): ?array
    {
        foreach ($this->users->all(['role' => 'superadmin', 'status' => 'active']) as $account) {
            if ((string)($account['email'] ?? '') !== '') {
                return ['name' => (string)($account['display_name'] ?: $account['username']), 'email' => (string)$account['email']];
            }
        }
        return null;
    }

    private function handleRoles(): void
    {
        if (!$this->permissions->can($this->auth->user(), 'roles.manage')) {
            $this->renderForbidden();
            return;
        }

        $roles = $this->roleAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->redirect($roles->apply($this->permissions, $_POST));
            return;
        }

        $screen = $roles->screen($this->permissions);

        $this->render('@admin/roles.twig', $screen + [
            'locked' => ['admin.access', 'users.self'],
            'saved' => isset($_GET['saved']),
            'created' => (string)($_GET['created'] ?? ''),
            'deleted' => isset($_GET['deleted']),
            'error' => (string)($_GET['error'] ?? ''),
            'error_role' => (string)($_GET['role'] ?? ''),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'roles',
            'current_type' => 'pages',
        ]);
    }

    private function roleAdmin(): RoleAdmin
    {
        return $this->roleAdminService ??= new RoleAdmin(
            $this->systemMeta,
            $this->users,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function handleRedirects(): void
    {
        $admin = $this->redirectAdmin();
        $repo = $this->redirects;
        $tab = (string)($_GET['tab'] ?? '') === 'missing' ? 'missing' : 'redirects';
        $form = $admin->blankForm();
        $error = '';
        $importReport = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $admin->apply($_POST, $this->currentUsername());
            if ($result['location'] !== null) {
                $this->redirect($result['location']);
                return;
            }
            $form = $result['form'];
            $error = $result['error'];
            $importReport = $result['report'];
            $tab = $result['tab'] ?? $tab;
        }

        $known = $this->publicPaths()->map();
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'origin' => (string)($_GET['origin'] ?? ''),
            'state' => (string)($_GET['state'] ?? ''),
        ];
        $perPage = 100;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $total = $repo->count($filters);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $form = $admin->formFor((int)($_GET['edit'] ?? 0), isset($_GET['source']) ? (string)$_GET['source'] : null, (string)($_GET['target'] ?? ''));
        }

        $rows = $admin->rows($filters, $perPage, $page);
        // Links inside content that still use an old address (only for permanent redirects that are on).
        $linkCounts = null;
        if ($this->permissions->can($this->auth->user(), 'content.manage')) {
            $usable = array_column(array_filter($rows, static fn(array $r): bool => (bool)$r['enabled'] && (int)$r['status_code'] === 301), 'source');
            $linkCounts = $usable !== [] ? $this->linkScanner()->countBySource($usable) : [];
        }
        foreach ($rows as $i => $row) {
            $rows[$i]['links'] = $linkCounts === null ? null : ($linkCounts[$row['source']] ?? 0);
        }

        $this->render('@admin/redirects.twig', [
            'tab' => $tab,
            'rows' => $rows,
            'missing' => $tab === 'missing' ? $admin->missing(trim((string)($_GET['q'] ?? ''))) : [],
            'stats' => $repo->stats(),
            'missing_total' => $repo->notFoundCount(),
            'filters' => $filters,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'total' => $total,
            'form' => $form,
            'error' => $error !== '' ? $error : (string)($_GET['error'] ?? ''),
            'done' => (string)($_GET['done'] ?? ''),
            'done_count' => (int)($_GET['n'] ?? 0),
            'import_report' => $importReport,
            'known_paths' => array_slice($known, 0, 500, true),
            'store_ok' => $repo->isAvailable(),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'redirects',
            'current_type' => 'pages',
        ]);
    }

    private function redirectAdmin(): RedirectAdmin
    {
        return $this->redirectAdminService ??= new RedirectAdmin(
            $this->redirects,
            $this->publicPaths(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function publicPaths(): PublicPaths
    {
        return $this->publicPathsService ??= new PublicPaths(
            $this->content,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): array => $this->settings,
            fn(): string => $this->getBaseUrl()
        );
    }

    private function linkScanner(): LinkScanner
    {
        $hosts = [
            (string)(parse_url($this->getBaseUrl(), PHP_URL_HOST) ?? ''),
            explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0],
        ];
        return new LinkScanner(
            $this->contentDir,
            array_map('strval', $this->settings['languages']['available'] ?? [$this->defaultLanguage()]),
            $this->defaultLanguage(),
            $hosts
        );
    }

    /**
     * Links inside content that still use an old address. Lists where they are and, on request, points them at the
     * redirect's target, so visitors no longer take the detour. Only the address in the text changes.
     */
    private function handleLinks(): void
    {
        $raw = (string)($_POST['ids'] ?? $_GET['ids'] ?? '');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn(int $id): bool => $id > 0)));
        $map = $this->redirectAdmin()->linkFixes(array_slice($ids, 0, 50));
        $scanner = $this->linkScanner();
        $may = fn(string $type): bool => $this->canAccessContentType($type);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $map !== []) {
            $result = $this->contentEditor()->updateLinks($scanner, $map, $this->currentUsername(), $may);
            $this->logActivity('content.links_updated', 'info', 'links', implode(',', array_keys($map)), 'Links to changed addresses updated.', [
                'entries' => $result['files'],
                'links' => $result['links'],
                'addresses' => array_keys($map),
            ]);
            $this->redirect('/admin/links?ids=' . implode(',', $ids) . '&done=' . $result['files'] . '-' . $result['links'] . '-' . $result['failed']);
            return;
        }

        $entries = [];
        $total = 0;
        foreach ($scanner->find(array_keys($map)) as $found) {
            if (!$may($found['type'])) {
                continue;
            }
            $item = $this->content->find($found['type'], $found['slug'], $found['lang'], true, false);
            $found['title'] = $item !== null ? (string)($item->meta['title'] ?? $found['slug']) : $found['slug'];
            $found['edit_url'] = '/admin/edit?type=' . urlencode($found['type']) . '&slug=' . urlencode($found['slug']) . '&lang=' . urlencode($found['lang']);
            $entries[] = $found;
            $total += $found['count'];
        }
        $done = array_map('intval', explode('-', (string)($_GET['done'] ?? '')) + [0, 0, 0]);
        $this->render('@admin/links.twig', [
            'fixes' => array_map(fn(string $source, string $target): array => ['source' => $source, 'target' => $target], array_keys($map), array_values($map)),
            'skipped' => count($ids) - count($map),
            'ids' => implode(',', $ids),
            'entries' => $entries,
            'total' => $total,
            'done' => isset($_GET['done']) ? ['entries' => $done[0], 'links' => $done[1], 'failed' => $done[2]] : null,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'redirects',
            'current_type' => 'pages',
        ]);
    }

    /** Shown for each kind of change in the history screens. */
    private const REVISION_ACTIONS = [
        'create' => 'Created',
        'save' => 'Saved',
        'import' => 'Imported',
        'restore' => 'Restored',
        'links' => 'Links updated',
        'delete' => 'Deleted',
        'external' => 'Changed outside the editor',
        'baseline' => 'Earlier version',
    ];

    /**
     * @param array<int, array<string, mixed>> $rows revisions
     * @return array<int, array<string, mixed>> the same rows with what the screens need to link and label them
     */
    private function decorateRevisions(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $path = $this->contentDir . '/' . $row['type'] . '/' . $this->buildFilename((string)$row['slug'], (string)$row['lang']);
            $rows[$i]['exists'] = is_file($path);
            $rows[$i]['action_label'] = self::REVISION_ACTIONS[$row['action']] ?? ucfirst((string)$row['action']);
            $rows[$i]['when'] = str_replace('T', ' ', substr((string)$row['created_at'], 0, 16)) . ' UTC';
        }
        return $rows;
    }

    private function handleRevisions(): void
    {
        $repo = $this->revisions;
        $actor = $this->currentUsername();
        $seesForms = $this->permissions->can($this->auth->user(), 'forms.manage');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $do = (string)($_POST['do'] ?? '');
            $revision = $repo->find((int)($_POST['id'] ?? 0));
            if ($revision === null || !in_array($do, ['restore', 'undelete'], true)) {
                $this->redirect('/admin/revisions');
                return;
            }
            $type = (string)$revision['type'];
            if (!$this->canAccessContentType($type)) {
                $this->denyContentType($type);
                return;
            }
            $slug = (string)$revision['slug'];
            $lang = (string)$revision['lang'];
            $result = $this->contentEditor()->restore($type, $slug, $lang, (string)$revision['raw'], $this->permissions->can($this->auth->user(), 'content.raw_html'), $actor, $do === 'undelete');
            if (!$result['ok']) {
                $this->redirect('/admin/revisions?id=' . (int)$revision['id'] . '&error=' . urlencode($result['error']));
                return;
            }
            $this->logActivity($do === 'undelete' ? 'content.undelete' : 'content.restore', 'warning', $type, $slug . ':' . $lang, $do === 'undelete' ? 'Deleted content brought back.' : 'Earlier version restored.', [
                'type' => $type,
                'slug' => $slug,
                'lang' => $lang,
                'revision' => (int)$revision['id'],
                'from' => (string)$revision['created_at'],
            ]);
            $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . '&saved=1&restored=1' . ($result['html_neutralized'] ? '&notice=html' : ''));
            return;
        }

        $common = [
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'history',
            'current_type' => 'pages',
            'error' => (string)($_GET['error'] ?? ''),
        ];

        // One version: what it changed, or what restoring it would do.
        if (isset($_GET['id'])) {
            $revision = $repo->find((int)$_GET['id']);
            if ($revision === null || !$this->canAccessContentType((string)$revision['type'])) {
                $this->redirect('/admin/revisions');
                return;
            }
            $type = (string)$revision['type'];
            $slug = (string)$revision['slug'];
            $lang = (string)$revision['lang'];
            $path = $this->contentDir . '/' . $type . '/' . $this->buildFilename($slug, $lang);
            $current = is_file($path) ? (string)file_get_contents($path) : null;
            $mode = (string)($_GET['mode'] ?? '') === 'restore' && $current !== null ? 'restore' : 'changes';
            $previous = $repo->previous($revision);
            if ($mode === 'restore') {
                $diff = LineDiff::compare((string)$current, (string)$revision['raw']);
            } else {
                $diff = LineDiff::compare($previous !== null ? (string)$previous['raw'] : '', (string)$revision['raw']);
            }
            $summary = LineDiff::summary($diff);
            $latest = $repo->latest($type, $slug, $lang);
            $this->render('@admin/revision-view.twig', [
                'revision' => $this->decorateRevisions([$revision])[0],
                'previous' => $previous !== null ? $this->decorateRevisions([$previous])[0] : null,
                'mode' => $mode,
                'diff' => LineDiff::withContext($diff, 4),
                'summary' => $summary,
                'identical' => $summary['added'] === 0 && $summary['removed'] === 0,
                'item_exists' => $current !== null,
                'is_latest' => $latest !== null && (int)$latest['id'] === (int)$revision['id'],
                'edit_url' => '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang),
                'history_url' => '/admin/revisions?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang),
            ] + $common);
            return;
        }

        $perPage = 50;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'type' => trim((string)($_GET['type'] ?? '')),
            'actor' => trim((string)($_GET['actor'] ?? '')),
            'action' => trim((string)($_GET['action'] ?? '')),
        ];
        if (!$seesForms) {
            $filters['exclude_type'] = 'forms';
        }
        $view = 'recent';
        $item = null;
        $rows = [];
        $total = 0;

        if ((string)($_GET['view'] ?? '') === 'deleted') {
            $view = 'deleted';
            $rows = $this->decorateRevisions($repo->deleted(100, $seesForms ? '' : 'forms'));
            $total = count($rows);
        } elseif (isset($_GET['slug']) && (string)($_GET['slug']) !== '') {
            $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
            if (!$this->canAccessContentType($type)) {
                $this->denyContentType($type);
                return;
            }
            $slug = $this->slugify((string)$_GET['slug']);
            $lang = $this->slugify((string)($_GET['lang'] ?? $this->defaultLanguage()));
            $view = 'item';
            $rows = $this->decorateRevisions($repo->forItem($type, $slug, $lang, 100));
            $total = count($rows);
            $item = [
                'type' => $type,
                'slug' => $slug,
                'lang' => $lang,
                'exists' => is_file($this->contentDir . '/' . $type . '/' . $this->buildFilename($slug, $lang)),
                'title' => (string)($rows[0]['title'] ?? $slug),
            ];
        } else {
            $total = $repo->count($filters);
            $rows = $this->decorateRevisions($repo->recent($filters, $perPage, ($page - 1) * $perPage));
        }

        $this->render('@admin/revisions.twig', [
            'view' => $view,
            'item' => $item,
            'rows' => $rows,
            'total' => $total,
            'filters' => $filters,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'actors' => $repo->actors(),
            'content_types' => array_values(array_filter($this->content->getTypes(), fn(string $t): bool => $seesForms || $t !== 'forms')),
            'actions' => self::REVISION_ACTIONS,
            'kept' => RevisionRepository::KEEP_PER_ITEM,
            'kept_days' => RevisionRepository::KEEP_DELETED_DAYS,
        ] + $common);
    }

    private function handleUsersList(): void
    {
        if (!$this->permissions->can($this->auth->user(), 'users.manage')) {
            $this->renderForbidden();
            return;
        }

        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'role' => trim((string)($_GET['role'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ];
        $this->render('@admin/users.twig', [
            'roles' => $this->permissions->allRoles(),
            'users' => $this->users->all($filters),
            'filters' => $filters,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'users',
            'current_type' => 'pages',
            'saved' => isset($_GET['saved']),
            'deleted' => isset($_GET['deleted']),
        ]);
    }

    private function handleActivityLogs(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 50);
        if (!in_array($perPage, [25, 50, 100], true)) {
            $perPage = 50;
        }
        $filters = [
            'level' => trim((string)($_GET['level'] ?? '')),
            'action' => trim((string)($_GET['action'] ?? '')),
            'actor' => trim((string)($_GET['actor'] ?? '')),
            'subject_type' => trim((string)($_GET['subject_type'] ?? '')),
            'date_from' => trim((string)($_GET['date_from'] ?? '')),
            'date_to' => trim((string)($_GET['date_to'] ?? '')),
            'q' => trim((string)($_GET['q'] ?? '')),
        ];
        $total = $this->activityLogs->count($filters);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $this->render('@admin/activity-logs.twig', [
            'title' => 'Activity logs',
            'logs' => $this->activityLogs->all($filters, $perPage, $offset),
            'filters' => $filters,
            'actions' => $this->activityLogs->actions(),
            'subject_types' => $this->activityLogs->subjectTypes(),
            'total_logs' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'per_page_options' => [25, 50, 100],
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'activity',
            'current_type' => 'pages',
        ]);
    }

    private function handleEmailLogs(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = trim((string)($_POST['email_log_action'] ?? ''));
            if ($action === 'clear') {
                $cleared = $this->emailLogs->clear();
                $this->logActivity('email_logs.clear', 'warning', 'email_logs', 'all', 'Email logs cleared.', [
                    'cleared' => $cleared,
                ]);
                $this->redirect('/admin/email-logs?cleared=' . $cleared);
                return;
            }
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 50);
        if (!in_array($perPage, [25, 50, 100], true)) {
            $perPage = 50;
        }
        $filters = [
            'status' => trim((string)($_GET['status'] ?? '')),
            'provider' => trim((string)($_GET['provider'] ?? '')),
            'recipient' => trim((string)($_GET['recipient'] ?? '')),
            'date_from' => trim((string)($_GET['date_from'] ?? '')),
            'date_to' => trim((string)($_GET['date_to'] ?? '')),
            'q' => trim((string)($_GET['q'] ?? '')),
        ];
        $total = $this->emailLogs->count($filters);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $this->render('@admin/email-logs.twig', [
            'title' => 'Email logs',
            'logs' => $this->emailLogs->all($filters, $perPage, $offset),
            'filters' => $filters,
            'providers' => $this->emailLogs->providers(),
            'total_logs' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'per_page_options' => [25, 50, 100],
            'cleared' => isset($_GET['cleared']) ? (int)$_GET['cleared'] : null,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'email_logs',
            'current_type' => 'pages',
        ]);
    }

    private function handleNotificationRead(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        $target = $this->sanitizeAdminReturnUrl((string)($_POST['target_url'] ?? '/admin'));
        if ($id > 0 && $this->notifications->markRead($id)) {
            $this->logActivity('notification.read', 'info', 'notification', (string)$id, 'Notification marked as read.');
        }
        $this->redirect($target);
    }

    private function handleNotificationsReadAll(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin');
            return;
        }

        $count = $this->notifications->markAllRead();
        if ($count > 0) {
            $this->logActivity('notifications.read_all', 'info', 'notification', 'all', 'Notifications marked as read.', [
                'count' => $count,
            ]);
        }
        $this->redirect($this->sanitizeAdminReturnUrl((string)($_POST['return_to'] ?? '/admin')));
    }

    private function handleBackups(): void
    {
        $downloadBackup = trim((string)($_GET['download'] ?? ''));
        if ($downloadBackup !== '') {
            $this->downloadBackupSnapshot($downloadBackup, '/admin/backups');
            return;
        }

        $restoreTarget = trim((string)($_GET['restore'] ?? ''));
        if ($restoreTarget !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->renderBackupRestore($restoreTarget);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = trim((string)($_POST['backup_action'] ?? ''));
            if ($action === 'verify') {
                $filename = $this->backups->sanitizeFilename((string)($_POST['filename'] ?? ''));
                $verification = $this->backups->verify($filename);
                $this->logActivity($verification['ok'] ? 'backup.verify_success' : 'backup.verify_failure', $verification['ok'] ? 'info' : 'error', 'backup', $filename, $verification['message'], [
                    'checked' => $verification['checked'],
                    'has_manifest' => $verification['has_manifest'],
                    'errors' => $verification['errors'],
                ]);
                $this->redirect('/admin/backups?' . http_build_query([
                    'backup' => $verification['ok'] ? ($verification['has_manifest'] ? 'ok' : 'warn') : 'fail',
                    'backup_msg' => $filename . ': ' . $verification['message'],
                ]));
                return;
            }

            if ($action === 'restore') {
                $this->handleBackupRestore();
                return;
            }

            if ($action === 'create_full') {
                $result = $this->backupManager()->createSnapshot();
                if (($result['ok'] ?? false) === true) {
                    $this->updateBackupLastRun(date('c'));
                }
                $this->backupManager()->recordRun($result);
                $this->logActivity(($result['ok'] ?? false) ? 'backup.create_success' : 'backup.create_failure', BackupManager::logLevel($result), 'backup', (string)($result['filename'] ?? ''), (string)($result['message'] ?? 'Backup action completed.'), [
                    'result' => $result,
                    'source' => 'backups_module',
                ]);
                $this->backupManager()->notify($result, false);
                $this->redirect('/admin/backups?' . http_build_query([
                    'backup' => BackupManager::queryStatus($result),
                    'backup_msg' => (string)($result['message'] ?? ''),
                ]));
                return;
            }

            if ($action === 'create_database') {
                $result = $this->backupManager()->createDatabaseSnapshot();
                $this->backupManager()->recordRun($result);
                $this->logActivity(($result['ok'] ?? false) ? 'backup.database_success' : 'backup.database_failure', BackupManager::logLevel($result), 'backup', (string)($result['filename'] ?? ''), (string)($result['message'] ?? 'Database backup action completed.'), [
                    'result' => $result,
                    'source' => 'backups_module',
                ]);
                $this->backupManager()->notify($result, false);
                $this->redirect('/admin/backups?' . http_build_query([
                    'backup' => BackupManager::queryStatus($result),
                    'backup_msg' => (string)($result['message'] ?? ''),
                ]));
                return;
            }

            if ($action === 'delete') {
                $filename = $this->backups->sanitizeFilename((string)($_POST['filename'] ?? ''));
                $result = $this->backups->delete($filename);
                $this->logActivity(($result['ok'] ?? false) ? 'backup.delete_success' : 'backup.delete_failure', ($result['ok'] ?? false) ? 'warning' : 'error', 'backup', $filename, (string)($result['message'] ?? 'Backup delete action completed.'), [
                    'filename' => $filename,
                ]);
                $this->redirect('/admin/backups?' . http_build_query([
                    'deleted' => (($result['ok'] ?? false) ? 'ok' : 'fail'),
                    'backup_msg' => (string)($result['message'] ?? ''),
                ]));
                return;
            }
        }

        $snapshots = $this->listBackupSnapshots();
        $storageBytes = array_sum(array_map(fn(array $snapshot): int => (int)($snapshot['size'] ?? 0), $snapshots));
        $schedule = $this->settings['backup']['auto'] ?? [];
        if (!is_array($schedule)) {
            $schedule = [];
        }
        $remote = $this->settings['backup']['remote'] ?? [];
        if (!is_array($remote)) {
            $remote = [];
        }
        $keep = (int)($this->settings['backup']['local']['keep'] ?? 20);
        if ($keep < 1) {
            $keep = 1;
        }

        $this->render('@admin/backups.twig', [
            'title' => 'Backups',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'backups',
            'current_type' => 'pages',
            'backup_snapshots' => $snapshots,
            'backup_runs' => $this->backupRuns->recent(20),
            'backup_status' => (string)($_GET['backup'] ?? ''),
            'delete_status' => (string)($_GET['deleted'] ?? ''),
            'backup_message' => trim((string)($_GET['backup_msg'] ?? '')),
            'backup_total' => count($snapshots),
            'backup_storage_human' => Format::bytes($storageBytes),
            'last_backup' => $snapshots[0] ?? null,
            'backup_schedule' => [
                'enabled' => $this->isTruthy($schedule['enabled'] ?? false),
                'frequency' => (string)($schedule['schedule'] ?? 'daily'),
                'last_run' => (string)($schedule['last_run'] ?? ''),
                'keep' => $keep,
            ],
            'backup_remote' => [
                'enabled' => $this->isTruthy($remote['enabled'] ?? false),
                'provider' => (string)($remote['provider'] ?? 'custom'),
                'bucket' => (string)($remote['bucket'] ?? ''),
                'prefix' => (string)($remote['prefix'] ?? ''),
                'keep' => (int)($remote['keep'] ?? 20),
            ],
        ]);
    }

    private function renderBackupRestore(string $filename, string $error = '', array $selected = []): void
    {
        if (!$this->permissions->can($this->auth->user(), 'backups.restore')) {
            $this->renderForbidden('Restoring backups is limited to superadmins.');
            return;
        }
        $filename = $this->backups->sanitizeFilename($filename);
        if ($this->backups->pathFor($filename) === null) {
            $this->redirect('/admin/backups?' . http_build_query(['backup' => 'fail', 'backup_msg' => 'Backup file not found.']));
            return;
        }
        $verification = $this->backups->verify($filename);
        $scopes = [];
        foreach ($this->backups->restoreScopes() as $key => $scope) {
            $count = (int)($verification['areas'][$key] ?? 0);
            $scopes[] = $scope + [
                'key' => $key,
                'count' => $count,
                'available' => $count > 0,
                'checked' => $count > 0 && ($selected === [] || in_array($key, $selected, true)),
            ];
        }
        $snapshot = null;
        foreach ($this->backups->list() as $item) {
            if ($item['filename'] === $filename) {
                $snapshot = $item;
                break;
            }
        }

        $this->render('@admin/backup-restore.twig', [
            'title' => 'Restore backup',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'backups',
            'current_type' => 'pages',
            'filename' => $filename,
            'snapshot' => $snapshot,
            'verification' => $verification,
            'scopes' => $scopes,
            'error' => $error,
            'current_version' => $this->updates()->currentVersion(),
        ]);
    }

    private function handleBackupRestore(): void
    {
        if (!$this->permissions->can($this->auth->user(), 'backups.restore')) {
            $this->renderForbidden('Restoring backups is limited to superadmins.');
            return;
        }
        $filename = $this->backups->sanitizeFilename((string)($_POST['filename'] ?? ''));
        $scopeKeys = array_values(array_intersect(
            array_map('strval', is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : []),
            array_keys($this->backups->restoreScopes())
        ));
        if ($filename === '' || $this->backups->pathFor($filename) === null) {
            $this->redirect('/admin/backups?' . http_build_query(['backup' => 'fail', 'backup_msg' => 'Backup file not found.']));
            return;
        }
        if ($scopeKeys === []) {
            $this->renderBackupRestore($filename, 'Select at least one area to restore.');
            return;
        }
        if (trim((string)($_POST['confirm_filename'] ?? '')) !== $filename) {
            $this->renderBackupRestore($filename, 'Type the archive name exactly as shown to confirm the restore.', $scopeKeys);
            return;
        }

        $verification = $this->backups->verify($filename, $theme);
        if (!$verification['ok']) {
            $this->renderBackupRestore($filename, 'The archive failed verification, so nothing was restored.', $scopeKeys);
            return;
        }
        if (!$verification['has_manifest'] && empty($_POST['ack_unverified'])) {
            $this->renderBackupRestore($filename, 'This archive has no checksum manifest. Tick the acknowledgement to restore it anyway.', $scopeKeys);
            return;
        }

        // A safety snapshot of the current state is mandatory; without it there is no way back.
        $safety = $this->backupManager()->createSnapshot('pre-restore', false, false);
        if (($safety['ok'] ?? false) !== true) {
            $this->backupManager()->recordRun($safety);
            $this->renderBackupRestore($filename, 'The safety snapshot failed, so the restore was not started: ' . (string)($safety['message'] ?? ''), $scopeKeys);
            return;
        }

        $actor = $this->auth->user();
        $result = $this->backups->restore($filename, $scopeKeys);

        // The system database may have been swapped: reload settings and write history into the active database.
        $this->settings = $this->siteSettings()->load();
        $this->themeSettings = $this->loadThemeSettings();
        $this->menus()->forget();
        $this->taxonomies()->forget();
        $this->backupManager()->recordRun($safety);
        if ($result['ok']) {
            $this->rebuildContentIndex();
        }
        $this->logActivity($result['ok'] ? 'backup.restore_success' : 'backup.restore_failure', $result['ok'] ? 'warning' : 'error', 'backup', $filename, $result['message'], [
            'scopes' => $scopeKeys,
            'restored' => $result['restored'],
            'skipped' => $result['skipped'],
            'safety_snapshot' => (string)($safety['filename'] ?? ''),
            'previous_dir' => (string)($result['previous_dir'] ?? ''),
        ], $actor);
        try {
            $this->notifications->create([
                'type' => $result['ok'] ? 'backup.restored' : 'backup.restore_failed',
                'title' => $result['ok'] ? 'Backup restored' : 'Backup restore failed',
                'body' => $result['message'] . ' Safety snapshot: ' . (string)($safety['filename'] ?? '') . '.',
                'severity' => $result['ok'] ? 'warning' : 'error',
                'target_url' => '/admin/backups',
            ]);
        } catch (\Throwable) {
            // Notifications must never block the restore response.
        }

        if (!$result['ok']) {
            $this->renderBackupRestore($filename, $result['message'] . ' Safety snapshot: ' . (string)($safety['filename'] ?? '') . '.', $scopeKeys);
            return;
        }
        $message = $result['message'] . ' Safety snapshot: ' . (string)($safety['filename'] ?? '') . '.';
        if ($result['skipped'] !== []) {
            $message .= ' Not in archive: ' . implode(', ', $result['skipped']) . '.';
        }
        if (in_array('database', $result['restored'], true)) {
            $message .= ' If your account does not exist in the restored database you will be signed out.';
        }
        $this->redirect('/admin/backups?' . http_build_query(['backup' => 'ok', 'backup_msg' => $message]));
    }

    /** @return array{filename: string, created_at: string, verified: bool, version: string}|null */
    private function preUpdateBackupStatus(): ?array
    {
        $meta = $this->systemMeta->getJson('pre_update_backup');
        if (!is_array($meta) || ($meta['filename'] ?? '') === '' || $this->backups->pathFor((string)$meta['filename']) === null) {
            return null;
        }
        return [
            'filename' => (string)$meta['filename'],
            'created_at' => (string)($meta['created_at'] ?? ''),
            'verified' => ($meta['verified'] ?? false) === true,
            'version' => (string)($meta['version'] ?? ''),
        ];
    }

    private function handleUpdates(): void
    {
        $updates = $this->updates();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = trim((string)($_POST['updates_action'] ?? ''));
            if ($action === 'pre_update_backup') {
                if (!$this->permissions->can($this->auth->user(), 'backups.manage')) {
                    $this->renderForbidden();
                    return;
                }
                $result = $this->backupManager()->createSnapshot('pre-update');
                $this->backupManager()->recordRun($result);
                $verification = ($result['ok'] ?? false) === true
                    ? $this->backups->verify((string)$result['filename'])
                    : ['ok' => false, 'message' => (string)($result['message'] ?? 'Backup failed.')];
                if (($result['ok'] ?? false) === true) {
                    $this->systemMeta->setJson('pre_update_backup', [
                        'filename' => (string)$result['filename'],
                        'created_at' => gmdate('c'),
                        'verified' => $verification['ok'] === true,
                        'version' => $updates->currentVersion(),
                    ]);
                }
                $this->logActivity($verification['ok'] ? 'updates.pre_backup_success' : 'updates.pre_backup_failure', $verification['ok'] ? 'info' : 'error', 'backup', (string)($result['filename'] ?? ''), (string)$verification['message'], [
                    'result' => $result,
                ]);
                $this->backupManager()->notify($result, false);
                $this->redirect('/admin/updates?' . http_build_query([
                    'pre_backup' => $verification['ok'] ? 'ok' : 'fail',
                    'pre_backup_msg' => $verification['ok'] ? 'Verified pre-update backup created: ' . (string)$result['filename'] : (string)$verification['message'],
                ]));
                return;
            }
            if ($action === 'check') {
                $status = $updates->status(true);
                $this->syncUpdateNotification($status);
                $this->logActivity('updates.check', 'info', 'updates', 'local', 'Read-only update check completed.', [
                    'current_version' => $status['current_version'],
                    'remote_version' => $status['remote_version'],
                    'source_status' => $status['source_status'],
                    'source' => $status['source'],
                ]);
                $this->redirect('/admin/updates?checked=1');
                return;
            }
        }

        $source = $updates->sourceConfig();
        $status = $updates->status();
        $this->syncUpdateNotification($status);
        $changelog = $updates->readChangelogEntries();
        $changelogSource = 'local';
        $remoteChangelog = $updates->fetchRemoteChangelogEntries((string)$source['changelog_url']);
        if (!empty($remoteChangelog)) {
            $changelog = $remoteChangelog;
            $changelogSource = 'remote';
        }
        $lastRelease = $changelog[0] ?? null;
        $latestDisplay = $status['latest_version'] !== '' ? $status['latest_version'] : (string)($lastRelease['version'] ?? $status['current_version']);
        $backups = $this->listBackupSnapshots();

        $this->render('@admin/updates.twig', [
            'title' => 'Updates',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'updates',
            'current_type' => 'pages',
            'current_version' => $status['current_version'],
            'current_commit' => $updates->currentGitCommit(),
            'latest_version' => $latestDisplay,
            'remote_version' => $status['remote_version'],
            'has_update' => $status['has_update'],
            'update_checked_at' => $status['checked_at'],
            'update_source' => (string)$source['version_url'],
            'update_source_status' => $status['source_status'],
            'update_repository' => (string)$source['repository'],
            'update_branch' => (string)$source['branch'],
            'update_changelog_url' => (string)$source['changelog_url'],
            'update_package_url' => (string)$source['package_url'],
            'update_channel' => $updates->channel(),
            'checked' => isset($_GET['checked']),
            'changelog_entries' => $changelog,
            'changelog_source' => $changelogSource,
            'update_guide' => $updates->readUpdateGuideSummary(),
            'latest_backup' => $backups[0] ?? null,
            'backup_total' => count($backups),
            'preflight_checks' => $this->buildUpdatePreflightChecks(),
            'pre_update_backup' => $this->preUpdateBackupStatus(),
            'pre_backup_status' => (string)($_GET['pre_backup'] ?? ''),
            'pre_backup_message' => trim((string)($_GET['pre_backup_msg'] ?? '')),
        ]);
    }

    /** @param array<string, mixed> $status */
    private function syncUpdateNotification(array $status): void
    {
        if (!($status['has_update'] ?? false)) {
            return;
        }
        try {
            $latest = (string)($status['latest_version'] ?? '');
            // Title carries the version, so each release notifies once even after being read.
            $this->notifications->createIfMissing([
                'type' => 'update.available',
                'title' => 'FarosCMS ' . $latest . ' is available',
                'body' => 'You are running ' . (string)($status['current_version'] ?? '') . '. Review the release notes and create a backup before updating.',
                'severity' => 'info',
                'target_url' => '/admin/updates',
                'context' => [
                    'current_version' => (string)($status['current_version'] ?? ''),
                    'latest_version' => $latest,
                ],
            ], true);
        } catch (\Throwable) {
            // Notifications must never block rendering.
        }
    }

    private function handleUserEdit(): void
    {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $currentUser = $this->auth->user();
        $currentId = (int)($currentUser['id'] ?? 0);
        $canManageUsers = $this->permissions->can($currentUser, 'users.manage');
        if ($id === 0 && !$this->permissions->canCreateUsers($currentUser)) {
            $this->renderForbidden();
            return;
        }
        if ($id > 0 && !$this->permissions->canEditUser($currentUser, $id)) {
            $this->renderForbidden();
            return;
        }

        $saved = isset($_GET['saved']);
        $error = '';
        $user = $id > 0 ? $this->users->find($id) : null;
        if ($id > 0 && !$user) {
            $this->redirect('/admin/users');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $this->userAdmin()->save($id, $user, $currentUser, $_POST);
            if ($result['ok']) {
                if ($result['password_changed'] && $result['id'] === $currentId) {
                    unset($_SESSION['security_default_password']);
                }
                $this->redirect('/admin/users-edit?id=' . $result['id'] . '&saved=1');
                return;
            }
            $error = $result['error'];
            $user = array_merge($user ?: [], $result['payload'], ['id' => $id]);
        }

        $this->render('@admin/user-edit.twig', [
            'title' => $canManageUsers ? ($id === 0 ? 'Add user' : 'Edit user') : 'Your profile',
            'edited_user' => $user ?: UserAdmin::blank(),
            'roles' => $this->permissions->allRoles(),
            'is_new' => $id === 0,
            'error' => $error,
            'saved' => $saved,
            'can_manage_users' => $canManageUsers,
            'can_change_access' => $this->permissions->canChangeAccessForUser($currentUser, $id),
            'is_self' => $id > 0 && $id === $currentId,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'users',
            'current_type' => 'pages',
        ]);
    }

    private function userAdmin(): UserAdmin
    {
        return $this->userAdminService ??= new UserAdmin(
            $this->users,
            $this->auth,
            $this->permissions,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function handleUserDelete(): void
    {
        if (!$this->permissions->can($this->auth->user(), 'users.manage')) {
            $this->renderForbidden();
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/admin/users');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        $currentId = (int)($this->auth->user()['id'] ?? 0);
        if ($id <= 0 || $id === $currentId) {
            $this->renderForbidden();
            return;
        }
        $this->users->deactivate($id);
        $this->logActivity('users.deactivate', 'warning', 'user', (string)$id, 'User deactivated.');
        $this->redirect('/admin/users?deleted=1');
    }

    private function handleAdminList(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        if ($type === 'forms') {
            $this->redirect('/admin/forms' . (isset($_GET['deleted']) ? '?deleted=1' : ''));
            return;
        }
        $lang = $this->slugify((string)($_GET['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $deleted = isset($_GET['deleted']);
        $types = $this->content->getTypes();
        if (!in_array($type, $types, true)) {
            $type = $types[0] ?? 'pages';
        }
        $items = $this->content->getItems($type, $lang, true, false);
        $translationLangs = $this->entryTranslations()->matrix($type, $items);
        $statusOptions = array_values(array_unique(array_merge(['published', 'draft'], array_map(
            static fn(ContentItem $item): string => (string)($item->meta['status'] ?? 'published'),
            $items
        ))));
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'status' => in_array((string)($_GET['status'] ?? ''), $statusOptions, true) ? (string)$_GET['status'] : '',
        ];
        if ($filters['q'] !== '') {
            $needle = mb_strtolower($filters['q']);
            $items = array_values(array_filter($items, static fn(ContentItem $item): bool => str_contains(mb_strtolower((string)($item->meta['title'] ?? '') . ' ' . $item->slug), $needle)));
        }
        if ($filters['status'] !== '') {
            $items = array_values(array_filter($items, static fn(ContentItem $item): bool => (string)($item->meta['status'] ?? 'published') === $filters['status']));
        }
        // Filed under a term: the link from Taxonomies. Anything that is not a term of a taxonomy is ignored.
        $filters['taxonomy'] = '';
        $filters['term'] = '';
        $termFilter = null;
        $filterTaxonomy = $this->slugify((string)($_GET['taxonomy'] ?? ''));
        $filterTerm = $this->slugify((string)($_GET['term'] ?? ''));
        if ($filterTaxonomy !== '' && $filterTerm !== '' && in_array($filterTaxonomy, $this->taxonomies()->names(), true)
            && in_array($filterTerm, array_column($this->taxonomies()->load($filterTaxonomy)['terms'], 'id'), true)) {
            $filters['taxonomy'] = $filterTaxonomy;
            $filters['term'] = $filterTerm;
            $termFilter = [
                'taxonomy' => $filterTaxonomy,
                'term' => $filterTerm,
                'taxonomy_title' => $this->taxonomies()->load($filterTaxonomy)['title'],
                'label' => $this->taxonomies()->label($filterTaxonomy, $filterTerm, $lang),
            ];
            $items = array_values(array_filter($items, fn(ContentItem $item): bool => in_array($filterTerm, $this->normalizeMetaList($item->meta[$filterTaxonomy] ?? null), true)));
        }

        $this->render('@admin/list.twig', [
            'items' => $items,
            'types' => $types,
            'current_type' => $type,
            'lang' => $lang,
            'languages' => $this->settings['languages']['available'] ?? [],
            'user' => $this->auth->user(),
            'admin_section' => 'content',
            'deleted' => $deleted,
            'translation_langs' => $translationLangs,
            'filters' => $filters,
            'filters_active' => $filters['q'] !== '' || $filters['status'] !== '' || $termFilter !== null,
            'term_filter' => $termFilter,
            'status_options' => $statusOptions,
            'bulk_status' => (string)($_GET['bulk'] ?? ''),
            'bulk_message' => trim((string)($_GET['bulk_msg'] ?? '')),
            'deleted_from' => trim((string)($_GET['from'] ?? '')),
            'deleted_to' => trim((string)($_GET['to'] ?? '')),
            'deleted_gone' => trim((string)($_GET['gone'] ?? '')),
            'can_redirects' => $this->permissions->can($this->auth->user(), 'redirects.manage'),
        ]);
    }

    private function handleContentBulk(): void
    {
        $type = $this->sanitizeType((string)($_POST['type'] ?? 'pages'));
        $lang = $this->slugify((string)($_POST['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $action = (string)($_POST['bulk_action'] ?? '');
        $slugs = array_values(array_unique(array_filter(array_map(
            fn($slug): string => $this->slugify((string)$slug),
            is_array($_POST['selected'] ?? null) ? $_POST['selected'] : []
        ))));
        $back = '/admin/content?type=' . urlencode($type) . '&lang=' . urlencode($lang);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($type, $this->content->getTypes(), true) || $type === 'forms') {
            $this->redirect($back);
            return;
        }
        if (!in_array($action, ['publish', 'draft', 'delete'], true) || $slugs === []) {
            $this->redirect($back . '&' . http_build_query(['bulk' => 'fail', 'bulk_msg' => 'Choose a bulk action and select at least one item.']));
            return;
        }

        $homeSlug = trim((string)($this->settings['home_page'] ?? 'index')) ?: 'index';
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'en');
        $changed = 0;
        $skipped = 0;
        $publicDeleted = 0;
        foreach ($slugs as $slug) {
            $path = $this->contentDir . '/' . $type . '/' . $this->buildFilename($slug, $lang);
            if (!is_file($path)) {
                $skipped++;
                continue;
            }
            if ($action === 'delete') {
                // The default-language home page is never deletable, same as the single delete.
                if ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang) {
                    $skipped++;
                    continue;
                }
                $wasPublic = $type !== 'forms' && $this->content->find($type, $slug, $lang, false, false) !== null;
                // Same as deleting one: the text stays in the history so it can be brought back.
                $this->revisions->baseline($type, $slug, $lang, $path, $this->currentUsername());
                $this->revisions->capture($type, $slug, $lang, (string)file_get_contents($path), 'delete', $this->currentUsername());
                if (@unlink($path)) {
                    $this->unindexContent($type, $slug, $lang);
                    $changed++;
                    $publicDeleted += $wasPublic ? 1 : 0;
                }
                continue;
            }
            [$frontmatter, $body] = $this->splitFrontMatter((string)file_get_contents($path));
            try {
                $meta = $frontmatter !== '' ? (Yaml::parse($frontmatter) ?: []) : [];
            } catch (\Throwable) {
                $skipped++;
                continue;
            }
            if (!is_array($meta)) {
                $skipped++;
                continue;
            }
            $meta['status'] = $action === 'publish' ? 'published' : 'draft';
            // Same layout handleSave writes: front matter, one blank line, body.
            $body = preg_replace('/\A\R/', '', $body) ?? $body;
            file_put_contents($path, "---\n" . trim(Yaml::dump($meta, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n\n" . rtrim($body) . "\n");
            $this->indexContentFile($type, $path);
            $changed++;
        }

        $verb = match ($action) {
            'publish' => 'published',
            'draft' => 'moved to draft',
            default => 'deleted',
        };
        $this->logActivity('content.bulk_' . $action, $action === 'delete' ? 'warning' : 'info', $type, $lang, 'Bulk ' . $verb . '.', [
            'type' => $type,
            'lang' => $lang,
            'slugs' => $slugs,
            'changed' => $changed,
            'skipped' => $skipped,
        ]);
        $message = $changed . ' item' . ($changed === 1 ? '' : 's') . ' ' . $verb . '.' . ($skipped > 0 ? ' ' . $skipped . ' skipped.' : '');
        if ($publicDeleted > 0) {
            $message .= ' ' . $publicDeleted . ' of them ' . ($publicDeleted === 1 ? 'was' : 'were') . ' public: visitors of ' . ($publicDeleted === 1 ? 'its address' : 'their addresses') . ' will see "not found".'
                . ($this->permissions->can($this->auth->user(), 'redirects.manage') ? ' Add redirects in Admin > Redirects, or delete them one at a time to choose where visitors go.' : '');
        }
        $this->redirect($back . '&' . http_build_query(['bulk' => $changed > 0 ? 'ok' : 'fail', 'bulk_msg' => $message]));
    }

    private function handleEdit(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }
        $slug = $this->slugify((string)($_GET['slug'] ?? ''));
        $lang = $this->slugify((string)($_GET['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $saved = isset($_GET['saved']);
        $deleted = isset($_GET['deleted']);

        $path = $this->contentDir . '/' . $type . '/' . $this->buildFilename($slug, $lang);
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
            'store_submissions' => $this->settings['forms']['store_submissions'] ?? true,
            'submit_label' => '',
            'success_message' => '',
            'redirect_url' => '',
            'honeypot' => (string)($this->settings['forms']['antispam']['honeypot'] ?? 'website'),
            'rate_limit_seconds' => (string)($this->settings['forms']['antispam']['rate_limit_seconds'] ?? 20),
        ];
        $formSubmissions = [];

        $isNew = true;
        if ($slug !== '' && file_exists($path)) {
            [$frontmatter, $body] = $this->splitFrontMatter((string)file_get_contents($path));
            $isNew = false;
        }
        if ($frontmatter === '' && $slug !== '') {
            $frontmatter = $this->defaultFrontmatter($type, $slug);
        }
        $meta = [];
        $frontMatterError = trim((string)($_GET['frontmatter_error'] ?? ''));
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
            $metaForm['title'] = $isNew ? (string)($meta['title'] ?? '') : (string)($meta['title'] ?? $this->titleFromSlug($slug));
            $metaForm['status'] = (string)($meta['status'] ?? 'published');
            $metaForm['visible'] = (bool)($meta['visible'] ?? true);
            $rawDate = $meta['date'] ?? '';
            $metaForm['date'] = $this->normalizeAdminDate($rawDate);
            $metaForm['author'] = (string)($meta['author'] ?? '');
            $metaForm['template'] = (string)($meta['template'] ?? '');
            $metaForm['hero_layout'] = (string)($meta['hero_layout'] ?? '');
            $headerTransparent = $meta['header_transparent'] ?? '';
            $metaForm['header_transparent'] = is_bool($headerTransparent) ? ($headerTransparent ? 'on' : 'off') : (string)$headerTransparent;
            foreach ($this->taxonomies()->names() as $taxonomyName) {
                $metaForm['taxonomy_terms'][$taxonomyName] = $this->normalizeMetaList($meta[$taxonomyName] ?? null);
            }
            $seo = $meta['seo'] ?? [];
            if (is_array($seo)) {
                $metaForm['seo_title'] = (string)($seo['title'] ?? '');
                $metaForm['seo_description'] = (string)($seo['description'] ?? '');
                $metaForm['seo_canonical'] = (string)($seo['canonical'] ?? '');
                $metaForm['seo_og_title'] = (string)($seo['og_title'] ?? '');
                $metaForm['seo_og_description'] = (string)($seo['og_description'] ?? '');
                $metaForm['seo_og_image'] = (string)($seo['og_image'] ?? '');
                $metaForm['seo_noindex'] = $this->isTruthy($seo['noindex'] ?? false);
            }
            $metaForm['main_image'] = $mainImage;
            $metaForm['excerpt'] = (string)($meta['excerpt'] ?? '');
            $metaForm['custom_fields'] = $this->extractCustomFields($meta, $this->contentTypes()->declaredKeys($type));
            if ($type === 'forms') {
                $formFields = FormFields::forAdmin($meta['fields'] ?? []);
                $notifications = $meta['notifications'] ?? [];
                if (is_array($notifications)) {
                    $formNotifications['enabled'] = $this->isTruthy($notifications['enabled'] ?? false);
                    $formNotifications['to'] = (string)($notifications['to'] ?? '');
                    $formNotifications['subject'] = (string)($notifications['subject'] ?? '');
                    $formNotifications['reply_to_field'] = (string)($notifications['reply_to_field'] ?? '');
                    $formNotifications['cc'] = (string)($notifications['cc'] ?? '');
                    $formNotifications['bcc'] = (string)($notifications['bcc'] ?? '');
                    $formNotifications['auto_reply'] = $this->isTruthy($notifications['auto_reply'] ?? false);
                    $formNotifications['auto_reply_include'] = $this->isTruthy($notifications['auto_reply_include'] ?? false);
                    $formNotifications['auto_reply_subject'] = (string)($notifications['auto_reply_subject'] ?? '');
                    $formNotifications['auto_reply_message'] = (string)($notifications['auto_reply_message'] ?? '');
                }
                $formSettings['store_submissions'] = $this->isTruthy($meta['store_submissions'] ?? $formSettings['store_submissions']);
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
            $translationId = (string)($_GET['translation_id'] ?? '');
        }
        if ($translationId === '') {
            $translationId = $this->contentEditor()->translationIdForSlug($type, $slug);
        }
        if ($translationId === '') {
            $translationId = ContentEditor::newTranslationId();
        }
        $metaForm['translation_id'] = $translationId;
        $translations = $this->entryTranslations()->links($type, $slug, $translationId);
        if ($isNew && $metaForm['date'] === '') {
            $metaForm['date'] = date('Y-m-d');
        }
        $formSubmissionsTotal = 0;
        if ($type === 'forms' && $slug !== '') {
            $formSubmissions = $this->formsAdmin()->recent($slug);
            $formSubmissionsTotal = count($formSubmissions);
            $formSubmissions = array_slice($formSubmissions, 0, 10);
        }
        $frontUrl = '';
        $linkFix = null;
        $itemExists = $slug !== '' && is_file($path);
        $homeSlug = $this->settings['home_page'] ?? 'index';
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        $address = [
            'exists' => $itemExists,
            'prefix' => '/' . str_replace('__slug__', '', $this->buildContentPath($type, '__slug__', $lang, $homeSlug, $defaultLang)),
            // The home page and forms keep their address: the site and its stored submissions refer to it by name.
            'locked' => $type === 'forms' || ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang),
            'siblings' => $itemExists ? array_column($this->contentEditor()->translationSiblings($type, $slug, $lang), 'lang') : [],
            'old_addresses' => [],
        ];
        if ($slug !== '') {
            $publicPath = $this->buildContentPath($type, $slug, $lang, $homeSlug, $defaultLang);
            $frontUrl = $this->buildAbsoluteUrl($publicPath);
            if ($itemExists && $this->permissions->can($this->auth->user(), 'redirects.manage')) {
                $address['old_addresses'] = $this->redirects->pointingTo($publicPath);
            }
            // Just after an address changed: are there links in other content that still use the old one?
            if ($itemExists && (string)($_GET['address'] ?? '') === 'changed') {
                $permanent = array_values(array_filter($this->redirects->pointingTo($publicPath), static fn(array $r): bool => (bool)$r['enabled'] && (int)$r['status_code'] === 301));
                $counts = $permanent !== [] ? $this->linkScanner()->countBySource(array_column($permanent, 'source')) : null;
                if ($counts !== null && array_sum($counts) > 0) {
                    $linkFix = ['count' => array_sum($counts), 'ids' => implode(',', array_column($permanent, 'id'))];
                }
            }
        }

        $this->render('@admin/edit.twig', [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title_from_slug' => $this->titleFromSlug($slug),
            'frontmatter' => $frontmatter,
            'front_matter_error' => $frontMatterError,
            'body' => $body,
            'main_image' => $mainImage,
            'meta_form' => $metaForm,
            'type_definition' => $this->contentTypes()->definition($type, 'en', $this->defaultLanguage()),
            'type_fields' => $type === 'forms' ? [] : $this->contentTypes()->editableFields($type, 'en', $this->defaultLanguage()),
            'type_values' => is_array($meta) ? $this->contentTypes()->values($type, $meta, 'en', $this->defaultLanguage()) : [],
            'taxonomies' => $this->listTaxonomiesForAdmin(),
            'translations' => $translations,
            'front_url' => $frontUrl,
            'types' => $this->content->getTypes(),
            'languages' => $this->settings['languages']['available'] ?? [],
            'user' => $this->auth->user(),
            'saved' => $saved,
            'notice' => (string)($_GET['notice'] ?? ''),
            'address' => $address,
            'address_changed' => (string)($_GET['address'] ?? '') === 'changed',
            'link_fix' => $linkFix,
            'restored' => isset($_GET['restored']),
            'history' => $itemExists ? $this->decorateRevisions($this->revisions->forItem($type, $slug, $lang, 8)) : [],
            'history_total' => $itemExists ? $this->revisions->countForItem($type, $slug, $lang) : 0,
            'address_taken' => $this->slugify((string)($_GET['address_taken'] ?? '')),
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
        ]);
    }

    /**
     * What the editor may choose about how an entry opens (title layout, transparent header), with what its
     * content type does today so "follow the settings" can say what that is. Empty when the theme offers neither.
     *
     * @return array{layouts: array<string, string>, transparent: array<string, string>, type_layout: string, type_transparent: string}
     */
    private function openingChoices(string $type): array
    {
        $layouts = $this->theme->settingOptions('hero_layouts', 'default');
        $transparent = array_diff_key($this->theme->settingOptions('transparent_header', 'default'), ['site' => true]);
        $layoutSettings = is_array($this->themeSettings['hero_layouts'] ?? null) ? $this->themeSettings['hero_layouts'] : [];
        $transparentSettings = is_array($this->themeSettings['transparent_header'] ?? null) ? $this->themeSettings['transparent_header'] : [];
        $typeTransparent = (string)($transparentSettings[$type] ?? $transparentSettings['default'] ?? 'site');
        if (!isset($transparent[$typeTransparent])) {
            $typeTransparent = $this->isTruthy($this->themeSettings['header']['transparent'] ?? false) ? 'on' : 'off';
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
        return (string)json_encode([
            'definitions' => $this->blockRegistry()->editorDefinitions(),
            'blocks' => $blocks,
            'presets' => $this->presetLibrary()->forEditor($lang, (string)($this->settings['languages']['default'] ?? 'en')),
            'presets_url' => rtrim((string)($this->settings['base_url'] ?? ''), '/') . '/admin/block-presets',
            'lang' => $lang,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function contentTypes(): ContentTypes
    {
        return $this->contentTypes ??= new ContentTypes($this->theme);
    }

    /**
     * Content of some types needs more than content.manage: forms hold visitors' personal data and decide
     * where notifications are sent, so only those who manage forms may open, change, or delete them.
     */
    private function canAccessContentType(string $type): bool
    {
        return $type !== 'forms' || $this->permissions->can($this->auth->user(), 'forms.manage');
    }

    private function denyContentType(string $type): void
    {
        $this->logActivity('auth.forbidden', 'warning', 'content_type', $type, 'Blocked access to a content type that needs more permission.', [
            'type' => $type,
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        ]);
        $this->renderForbidden();
    }

    private function defaultLanguage(): string
    {
        return (string)($this->settings['languages']['default'] ?? 'en');
    }

    private function presetLibrary(): PresetLibrary
    {
        return $this->presetLibrary ??= new PresetLibrary(
            $this->theme,
            $this->blockRegistry(),
            fn(string $value): string => $this->slugify($value)
        );
    }

    private function handleMediaPicker(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $this->media->ensureDirectories();
        $this->media->migrateLegacyItems();
        echo json_encode($this->mediaAdmin()->picker($_GET), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Saves or deletes a site section from the block editor (JSON in, JSON out). */
    private function handleBlockPresets(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'POST only.']);
            return;
        }
        $action = (string)($_POST['preset_action'] ?? '');
        $lang = $this->slugify((string)($_POST['lang'] ?? ''));
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'en');
        $library = $this->presetLibrary();

        if ($action === 'save') {
            $blocks = json_decode((string)($_POST['blocks_json'] ?? ''), true);
            $result = $library->saveCustom(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['description'] ?? ''),
                $lang,
                is_array($blocks) ? array_values($blocks) : []
            );
            if ($result['ok']) {
                $this->logActivity('presets.save', 'info', 'preset', (string)$result['id'], 'Block section saved.');
                foreach ($library->forEditor($lang, $defaultLang) as $preset) {
                    if ($preset['id'] === $result['id']) {
                        $result['preset'] = $preset;
                    }
                }
            } else {
                http_response_code(422);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $ok = $library->deleteCustom($id);
            if ($ok) {
                $this->logActivity('presets.delete', 'warning', 'preset', $id, 'Block section deleted.');
            } else {
                http_response_code(404);
            }
            echo json_encode(['ok' => $ok]);
            return;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    }

    private function handleSave(): void
    {
        $type = $this->sanitizeType((string)($_POST['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }

        // A main image uploaded with the form replaces the address typed in the field.
        $uploadedImage = null;
        $storageBlocked = false;
        $mainImageUploads = $this->media->normalizeUploads($_FILES['main_image_upload'] ?? null);
        if ($mainImageUploads !== []) {
            $upload = $mainImageUploads[0];
            $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_OK && !$this->siteLimits()->allows((int)($upload['size'] ?? 0))) {
                $storageBlocked = true;
            } elseif ($uploadError === UPLOAD_ERR_OK) {
                try {
                    $this->media->ensureDirectories();
                    $uploaded = $this->media->upload($upload, '', $this->currentUsername(), $this->siteLimits()->maxUploadBytes());
                    $this->siteLimits()->uploadsChanged((int)($upload['size'] ?? 0));
                    if ((string)($uploaded['kind'] ?? '') === 'image') {
                        $uploadedImage = isset($uploaded['direct_url']) ? (string)$uploaded['direct_url'] : null;
                    } else {
                        $uploadedId = (string)($uploaded['id'] ?? '');
                        if ($uploadedId !== '') {
                            $this->mediaAdmin()->deleteItem($uploadedId);
                        }
                    }
                } catch (\Throwable) {
                    // Keep existing main image unchanged on upload failure.
                }
            }
        }

        $postedFrontMatter = trim((string)($_POST['frontmatter'] ?? ''));
        if ($postedFrontMatter !== '') {
            try {
                Yaml::parse($postedFrontMatter);
            } catch (\Throwable $e) {
                // Nothing is written: the raw front matter must be readable first.
                $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode((string)($_POST['original_slug'] ?? $_POST['slug'] ?? '')) . '&lang=' . urlencode((string)($_POST['original_lang'] ?? $_POST['lang'] ?? '')) . '&frontmatter_error=' . urlencode($e->getMessage()));
                return;
            }
        }
        $result = $this->contentEditor()->save(
            $type,
            $_POST,
            $uploadedImage,
            $this->permissions->can($this->auth->user(), 'content.raw_html'),
            $this->currentUsername()
        );
        $slug = $result['slug'];
        $lang = $result['lang'];

        if ($result['moved'] && $result['moved_together']) {
            // Menu links follow a page whose address changed in every language.
            $old = $result['original_slug'];
            $this->menus()->relink($type === 'pages' ? $old : $type . '/' . $old, $type === 'pages' ? $slug : $type . '/' . $slug);
        }

        $this->logActivity($result['was_existing'] ? 'content.update' : 'content.create', 'info', $type, $slug . ':' . $lang, ($result['was_existing'] ? 'Content updated.' : 'Content created.'), [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title' => $result['title'],
            'status' => $result['status'],
            'renamed_from' => $result['moved'] ? $result['original_slug'] . ':' . $result['original_lang'] : '',
            'translations_renamed' => $result['translations_renamed'],
        ]);
        $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . '&saved=1'
            . ($result['html_neutralized'] ? '&notice=html' : ($storageBlocked ? '&notice=storage' : ''))
            . ($result['moved'] ? '&address=changed' : '')
            . ($result['slug_taken'] !== '' ? '&address_taken=' . urlencode($result['slug_taken']) : ''));
    }

    /**
     * Deleting one entry. A public one goes through a page of its own first, which asks whether visitors of its address
     * should be sent somewhere (a redirect) instead of finding nothing. A draft, which nobody could have visited, is
     * deleted straight away after the confirmation in the list.
     */
    private function handleDelete(): void
    {
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        $source = $post ? $_POST : $_GET;
        $type = $this->sanitizeType((string)($source['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }
        $slug = $this->slugify((string)($source['slug'] ?? ''));
        $lang = $this->slugify((string)($source['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $defaultLang = $this->defaultLanguage();
        $homeSlug = trim((string)($this->settings['home_page'] ?? 'index'));
        if ($homeSlug === '') {
            $homeSlug = 'index';
        }
        $back = '/admin/content?type=' . urlencode($type) . '&lang=' . urlencode($lang);

        if ($slug === '' || ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang)) {
            $this->redirect($back);
            return;
        }

        $path = $this->contentDir . '/' . $type . '/' . $this->buildFilename($slug, $lang);
        $isPublic = is_file($path) && $type !== 'forms' && $this->content->find($type, $slug, $lang, false, false) !== null;
        $publicPath = $this->buildContentPath($type, $slug, $lang, $homeSlug, $defaultLang);
        $mayRedirect = $isPublic && $this->permissions->can($this->auth->user(), 'redirects.manage') && $this->redirects->isAvailable();

        // Where visitors go instead, when the person chose to send them somewhere.
        $target = '';
        $error = '';
        $choice = (string)($_POST['after'] ?? '');
        if ($post && $mayRedirect && in_array($choice, ['archive', 'home', 'custom'], true)) {
            $target = match ($choice) {
                'archive' => $type === 'pages' ? '' : '/' . $this->buildArchivePath($type, $lang, $defaultLang),
                'home' => '/' . ($lang === $defaultLang ? '' : $lang),
                default => $this->publicPaths()->localize((string)($_POST['after_target'] ?? '')),
            };
            $error = $target === '' ? 'target_empty' : (string)$this->redirects->validate($publicPath, $target, 301);
            if ($error === '' && !RedirectRepository::isExternal($target) && !$this->publicPaths()->exists(RedirectRepository::normalizePath($target))) {
                $error = 'target_missing';
            }
        }

        if (!$post || $error !== '') {
            if (!is_file($path)) {
                $this->redirect($back);
                return;
            }
            $this->renderDeleteConfirm($type, $slug, $lang, $publicPath, $isPublic, $mayRedirect, $error, $choice, (string)($_POST['after_target'] ?? ''));
            return;
        }

        $by = $this->currentUsername();
        if (is_file($path)) {
            // The text is kept in the history so the item can be brought back from Admin > History.
            $this->revisions->baseline($type, $slug, $lang, $path, $by);
            $this->revisions->capture($type, $slug, $lang, (string)file_get_contents($path), 'delete', $by);
            unlink($path);
            $this->unindexContent($type, $slug, $lang);
            if ($target !== '') {
                $this->redirects->replacedBy($publicPath, $target, $by, $type);
            }
            $this->logActivity('content.delete', 'warning', $type, $slug . ':' . $lang, 'Content deleted.', [
                'type' => $type,
                'slug' => $slug,
                'lang' => $lang,
                'redirect_to' => $target,
            ]);
        }

        $query = '&deleted=1';
        if ($target !== '') {
            $query .= '&from=' . urlencode('/' . $publicPath) . '&to=' . urlencode($target);
        } elseif ($isPublic) {
            $query .= '&gone=' . urlencode('/' . $publicPath);
        }
        $this->redirect($back . $query);
    }

    /** The page that asks what should happen to visitors of a public entry that is about to be deleted. */
    private function renderDeleteConfirm(string $type, string $slug, string $lang, string $publicPath, bool $isPublic, bool $mayRedirect, string $error, string $choice, string $custom): void
    {
        $defaultLang = $this->defaultLanguage();
        $item = $this->content->find($type, $slug, $lang, true, false);
        $known = $this->publicPaths()->map();
        unset($known[$publicPath]);
        $counts = $this->permissions->can($this->auth->user(), 'content.manage')
            ? $this->linkScanner()->countBySource([RedirectRepository::normalizePath($publicPath)])
            : null;
        $siblings = array_column($this->contentEditor()->translationSiblings($type, $slug, $lang), 'lang');
        $this->render('@admin/delete.twig', [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'entry_title' => $item !== null ? (string)($item->meta['title'] ?? $slug) : $slug,
            'public_path' => '/' . $publicPath,
            'is_public' => $isPublic,
            'may_redirect' => $mayRedirect,
            'archive_path' => $type === 'pages' ? '' : '/' . $this->buildArchivePath($type, $lang, $defaultLang),
            'archive_label' => $this->contentTypes()->definition($type, 'en', $defaultLang)['label'],
            'home_path' => '/' . ($lang === $defaultLang ? '' : $lang),
            'known_paths' => array_slice($known, 0, 500, true),
            'links' => $counts !== null ? array_sum($counts) : null,
            'siblings' => $siblings,
            'choice' => $choice !== '' ? $choice : ($type === 'pages' ? 'none' : 'archive'),
            'custom_target' => $custom,
            'error' => $error,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'content',
            'current_type' => $type,
        ]);
    }

    private function handleNew(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }
        $slug = $this->slugify((string)($_GET['slug'] ?? ''));
        $lang = $this->slugify((string)($_GET['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));

        $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang));
    }

    private function handleSettings(): void
    {
        $saved = isset($_GET['saved']);
        $testStatus = (string)($_GET['test'] ?? '');
        $backupStatus = (string)($_GET['backup'] ?? '');
        $backupMessage = trim((string)($_GET['backup_msg'] ?? ''));
        $settingsError = trim((string)($_GET['settings_error'] ?? ''));
        if (strtolower((string)($_GET['tab'] ?? '')) === 'theme' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            // The theme has its own page now.
            $this->redirect('/admin/theme');
            return;
        }
        $activeTab = $this->sanitizeSettingsTab((string)($_GET['tab'] ?? 'basics'));

        $downloadBackup = trim((string)($_GET['download_backup'] ?? ''));
        if ($downloadBackup !== '') {
            $this->downloadBackupSnapshot($downloadBackup);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['storage_action'] ?? '') === 'recalculate') {
            if ($this->permissions->can($this->auth->user(), 'limits.manage')) {
                $this->siteLimits()->measure();
                $this->logActivity('limits.storage_recalculate', 'info', 'settings', 'storage', 'Storage use measured again.');
                $this->redirect('/admin/settings?tab=limits&storage=measured');
                return;
            }
            $this->redirect('/admin/settings?tab=limits');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $activeTab = $this->sanitizeSettingsTab((string)($_POST['active_tab'] ?? $activeTab));
            $raw = $this->siteSettings()->raw('site_settings', $this->siteSettings()->defaults());
            $upload = $this->submittedUploadSettings();
            $form = SiteSettings::formFromPost($_POST) + [
                'storage_limit_mb' => $this->submittedStorageLimit(),
                'upload_limit_mb' => $upload['mb'],
                'upload_types' => $upload['types'],
            ];
            $limitBefore = (int)($this->settings['limits']['storage_mb'] ?? 1024);
            $uploadBefore = [$this->siteLimits()->uploadLimitMb(), $this->media->allowedGroups()];
            if (!$this->siteSettings()->save($raw, $form)) {
                $this->redirect('/admin/settings?' . http_build_query([
                    'tab' => $activeTab,
                    'settings_error' => 'Settings could not be saved because the SQLite system database is unavailable.',
                ]));
                return;
            }
            $this->settings = $this->siteSettings()->load();
            $limitAfter = (int)($this->settings['limits']['storage_mb'] ?? 1024);
            $this->media->restrictTo(is_array($this->settings['limits']['upload_types'] ?? null) ? $this->settings['limits']['upload_types'] : []);
            $uploadAfter = [$this->siteLimits()->uploadLimitMb(), $this->media->allowedGroups()];
            if ($uploadAfter !== $uploadBefore) {
                $this->logActivity('limits.upload', 'warning', 'settings', 'upload', 'Upload limits changed.', ['from_mb' => $uploadBefore[0], 'to_mb' => $uploadAfter[0], 'kinds' => $uploadAfter[1]]);
            }
            if ($limitAfter !== $limitBefore) {
                $this->logActivity('limits.storage', 'warning', 'settings', 'storage_mb', 'Storage limit changed.', ['from_mb' => $limitBefore, 'to_mb' => $limitAfter]);
            }
            $themeSave = is_array($_POST['theme_settings'] ?? null) ? $this->saveThemeSettings($_POST['theme_settings']) : ['ok' => true];
            $this->themeSettings = $this->loadThemeSettings();
            if (($themeSave['ok'] ?? false) !== true) {
                $msg = urlencode((string)($themeSave['message'] ?? 'Theme settings could not be saved.'));
                $this->redirect('/admin/theme?theme=fail&theme_msg=' . $msg);
                return;
            }
            $this->logActivity('settings.update', 'info', 'settings', $activeTab, 'Settings updated.', [
                'tab' => $activeTab,
            ]);
            if (isset($_POST['send_test'])) {
                $testTo = trim((string)($_POST['test_email_to'] ?? ''));
                if ($testTo === '') {
                    $this->redirect('/admin/settings?saved=1&tab=smtp&test=missing');
                    return;
                }
                $siteTitle = trim((string)($this->settings['title'] ?? 'FarosCMS'));
                if ($siteTitle === '') {
                    $siteTitle = 'FarosCMS';
                }
                $testSubject = $siteTitle . ' - email test';
                $from = trim((string)($this->settings['forms']['notifications']['from'] ?? ''));
                $fromName = trim((string)($this->settings['forms']['notifications']['from_name'] ?? ''));
                if ($from === '') {
                    $from = 'noreply@localhost';
                }
                $fromHeader = $fromName !== '' ? $fromName . ' <' . $from . '>' : $from;
                $ok = $this->sendEmailMessage($testTo, $testSubject, "This is a test email from FarosCMS.", [
                    'From' => $fromHeader,
                ]);
                $this->logActivity($ok ? 'email.test_success' : 'email.test_failure', $ok ? 'info' : 'error', 'email', $testTo, $ok ? 'Test email sent.' : 'Test email failed.', [
                    'recipient' => $testTo,
                ]);
                $this->redirect('/admin/settings?saved=1&tab=smtp&test=' . ($ok ? 'ok' : 'fail'));
                return;
            }
            if (isset($_POST['test_remote_backup'])) {
                $result = $this->backupManager()->testRemote();
                $this->logActivity(($result['ok'] ?? false) ? 'backup.remote_test_success' : 'backup.remote_test_failure', ($result['ok'] ?? false) ? 'info' : 'error', 'backup', 'remote_storage', (string)($result['message'] ?? 'Remote backup test completed.'), [
                    'provider' => (string)($this->settings['backup']['remote']['provider'] ?? 'custom'),
                    'bucket' => (string)($this->settings['backup']['remote']['bucket'] ?? ''),
                ]);
                $query = [
                    'saved' => '1',
                    'tab' => 'backup',
                    'backup' => (($result['ok'] ?? false) ? 'ok' : 'fail'),
                    'backup_msg' => (string)($result['message'] ?? ''),
                ];
                $this->redirect('/admin/settings?' . http_build_query($query));
                return;
            }
            if (isset($_POST['create_backup'])) {
                $result = $this->backupManager()->createSnapshot();
                if (($result['ok'] ?? false) === true) {
                    $this->updateBackupLastRun(date('c'));
                }
                $this->backupManager()->recordRun($result);
                $this->logActivity(($result['ok'] ?? false) ? 'backup.create_success' : 'backup.create_failure', BackupManager::logLevel($result), 'backup', (string)($result['filename'] ?? ''), (string)($result['message'] ?? 'Backup action completed.'), [
                    'result' => $result,
                ]);
                $this->backupManager()->notify($result, false);
                $query = [
                    'saved' => '1',
                    'tab' => 'backup',
                    'backup' => BackupManager::queryStatus($result),
                    'backup_msg' => (string)($result['message'] ?? ''),
                ];
                $this->redirect('/admin/settings?' . http_build_query($query));
                return;
            }
            $this->redirect('/admin/settings?saved=1&tab=' . urlencode($activeTab));
            return;
        }

        $raw = $this->siteSettings()->raw('site_settings', $this->siteSettings()->defaults());
        $parsed = $this->siteSettings()->parse($raw);
        $this->render('@admin/settings.twig', [
            'user' => $this->auth->user(),
            'saved' => $saved,
            'storage_measured' => (string)($_GET['storage'] ?? '') === 'measured',
            'test_status' => $testStatus,
            'types' => $this->content->getTypes(),
            'admin_section' => 'settings',
            'settings_form' => $this->siteSettings()->formValues($parsed),
            'storage' => $this->permissions->can($this->auth->user(), 'limits.manage') ? $this->siteLimits()->summary() : [],
            'upload_groups' => MediaLibrary::UPLOAD_GROUPS,
            'server_upload_mb' => SiteLimits::serverUploadCap() > 0 ? (int)floor(SiteLimits::serverUploadCap() / 1048576) : 0,
            'backup_snapshots' => $this->listBackupSnapshots(),
            'backup_status' => $backupStatus,
            'backup_message' => $backupMessage,
            'settings_error' => $settingsError,
            'active_tab' => $activeTab,
        ]);
    }

    /** Theme settings: one screen, a tab for each section the theme declares. */
    private function handleTheme(): void
    {
        $schema = $this->theme->settingsSchema();
        $tabs = [];
        foreach ($schema as $key => $section) {
            if (array_filter($section['fields'], static fn(array $field): bool => !$field['hidden']) !== []) {
                $tabs[] = $key;
            }
        }
        $activeTab = (string)($_GET['tab'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $activeTab = (string)($_POST['active_tab'] ?? $activeTab);
            $save = $this->saveThemeSettings(is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : []);
            $this->themeSettings = $this->loadThemeSettings();
            if (($save['ok'] ?? false) !== true) {
                $this->redirect('/admin/theme?theme=fail&tab=' . urlencode($activeTab) . '&theme_msg=' . urlencode((string)($save['message'] ?? 'Theme settings could not be saved.')));
                return;
            }
            $this->logActivity('theme.update', 'info', 'settings', $this->theme->name(), 'Theme settings updated.', ['tab' => $activeTab]);
            $this->redirect('/admin/theme?saved=1&tab=' . urlencode($activeTab));
            return;
        }
        if (!in_array($activeTab, $tabs, true)) {
            $activeTab = $tabs[0] ?? '';
        }
        $this->render('@admin/theme.twig', [
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'theme',
            'saved' => isset($_GET['saved']),
            'theme_status' => (string)($_GET['theme'] ?? ''),
            'theme_message' => trim((string)($_GET['theme_msg'] ?? '')),
            'theme_schema' => $schema,
            'theme_tabs' => $tabs,
            'theme_values' => $this->themeSettings,
            'theme_info' => [
                'name' => $this->theme->name(),
                'label' => $this->theme->label(),
                'version' => $this->theme->version(),
                'custom_dir' => is_dir($this->theme->customPath()),
            ],
            'active_tab' => $activeTab,
        ]);
    }

    private function handleMenusList(): void
    {
        $rows = $this->menus()->listForAdmin();

        $this->render('@admin/menus-list.twig', [
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'menus',
            'rows' => $rows,
            'deleted' => isset($_GET['deleted']),
            'saved' => isset($_GET['saved']),
            'created' => isset($_GET['created']),
        ]);
    }

    private function handleMenusNew(): void
    {
        $menuKeys = $this->menus()->keys();
        $newKeyPrefill = $this->slugify((string)($_GET['new_key'] ?? ''));
        $sourceKey = $this->slugify((string)($_GET['source_key'] ?? ''));
        if ($newKeyPrefill === '' && $sourceKey !== '') {
            $newKeyPrefill = $sourceKey;
        }
        $newTitlePrefill = trim((string)($_GET['new_title'] ?? ''));

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $newKey = $this->slugify((string)($_POST['new_key'] ?? $newKeyPrefill));
            $newKeyPrefill = $newKey;
            $title = trim((string)($_POST['new_title'] ?? ''));
            $newTitlePrefill = $title;
            $sourceKey = $this->slugify((string)($_POST['source_key'] ?? $sourceKey));
            if ($newKey === '') {
                $error = 'Menu key is required.';
            } elseif (in_array($newKey, $menuKeys, true)) {
                $error = 'Menu key already exists.';
            } else {
                $items = [];
                if ($sourceKey !== '' && in_array($sourceKey, $menuKeys, true)) {
                    $sourceMenu = $this->menus()->load($sourceKey);
                    $items = $this->menus()->normalize($sourceMenu['items'] ?? []);
                    if ($title === '') {
                        $title = (string)($sourceMenu['title'] ?? '');
                    }
                }
                if ($title === '') {
                    $title = $this->titleFromSlug($newKey);
                }
                $this->menus()->write($newKey, [
                    'title' => $title,
                    'items' => $items,
                ]);
                $this->menus()->forget();
                $this->logActivity('menus.create', 'info', 'menu', $newKey, 'Menu created.', [
                    'title' => $title,
                    'source_key' => $sourceKey,
                ]);
                $this->redirect('/admin/menus-edit?key=' . urlencode($newKey) . '&created=1');
                return;
            }
        }

        $this->render('@admin/menus-new.twig', [
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'menus',
            'error' => $error,
            'new_key' => $newKeyPrefill,
            'new_title' => $newTitlePrefill,
            'source_key' => $sourceKey,
            'menu_keys' => $menuKeys,
        ]);
    }

    private function handleMenus(): void
    {
        $languages = $this->settings['languages']['available'] ?? [(string)($this->settings['languages']['default'] ?? 'el')];

        $menuKeys = $this->menus()->keys();
        $selectedKey = $this->slugify((string)($_GET['key'] ?? $_POST['key'] ?? ''));
        if ($selectedKey === '') {
            if (!empty($menuKeys)) {
                $this->redirect('/admin/menus-edit?key=' . urlencode((string)$menuKeys[0]));
            } else {
                $this->redirect('/admin/menus-new');
            }
            return;
        }
        if (!in_array($selectedKey, $menuKeys, true)) {
            $this->redirect('/admin/menus');
            return;
        }

        $saved = isset($_GET['saved']);
        $created = isset($_GET['created']);
        $deleted = isset($_GET['deleted']);
        $error = '';

        $menu = $this->menus()->load($selectedKey);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['menu_action'] ?? 'save');
            if ($action === 'delete') {
                $path = $this->menus()->path($selectedKey);
                if (is_file($path)) {
                    unlink($path);
                    $this->menus()->forget();
                    $this->logActivity('menus.delete', 'warning', 'menu', $selectedKey, 'Menu deleted.');
                }
                $this->redirect('/admin/menus?deleted=1');
                return;
            } else {
                $title = trim((string)($_POST['menu_title'] ?? ''));
                if ($title === '') {
                    $title = $this->titleFromSlug($selectedKey);
                }
                $labelKeys = $_POST['menu_label_key'] ?? [];
                $labelLangs = $_POST['menu_label_lang'] ?? [];
                $urls = $_POST['menu_url'] ?? [];
                $classes = $_POST['menu_class'] ?? [];
                $targets = $_POST['menu_target'] ?? [];
                $depths = $_POST['menu_depth'] ?? [];
                $items = $this->menus()->fromAdminRows($labelKeys, $labelLangs, $urls, $classes, $targets, $depths, $languages);
                $this->menus()->write($selectedKey, [
                    'title' => $title,
                    'items' => $items,
                ]);
                $this->menus()->forget();
                $this->logActivity('menus.update', 'info', 'menu', $selectedKey, 'Menu updated.', [
                    'title' => $title,
                    'items' => count($items),
                ]);
                $this->redirect('/admin/menus-edit?key=' . urlencode($selectedKey) . '&saved=1');
                return;
            }
        }

        $menu = $this->menus()->load($selectedKey);
        $menuItems = $this->menus()->flatten($menu['items'] ?? [], $languages);
        if (empty($menuItems)) {
            $defaultLabels = [];
            foreach ($languages as $language) {
                $defaultLabels[(string)$language] = '';
            }
            $menuItems[] = [
                'depth' => '1',
                'label_key' => '',
                'labels' => $defaultLabels,
                'url' => '',
                'class' => '',
                'target' => '',
            ];
        }

        $this->render('@admin/menus.twig', [
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'menus',
            'menu_key' => $selectedKey,
            'languages' => $languages,
            'menu_title' => (string)($menu['title'] ?? $this->titleFromSlug($selectedKey)),
            'menu_items' => $menuItems,
            'saved' => $saved,
            'created' => $created,
            'deleted' => $deleted,
            'error' => $error,
        ]);
    }

    private function handleTaxonomies(): void
    {
        $default = $this->defaultLanguage();
        $languages = array_map('strval', $this->settings['languages']['available'] ?? [$default]);
        $store = $this->taxonomies();
        $editor = $this->taxonomyEditor();
        $taxonomyNames = $store->names();
        $taxonomy = $this->slugify((string)($_GET['taxonomy'] ?? $_POST['taxonomy'] ?? ($taxonomyNames[0] ?? 'tags')));
        if (!in_array($taxonomy, $taxonomyNames, true)) {
            $taxonomy = $taxonomyNames[0] ?? 'tags';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $editor->apply($taxonomy, $_POST, $languages, $default, $this->currentUsername());
            $this->logActivity('taxonomies.update', 'info', 'taxonomy', $taxonomy, 'Taxonomy updated.', [
                'title' => $result['title'],
                'terms' => $result['terms'],
                'added' => $result['added'],
                'renamed' => $result['moved'],
                'removed' => $result['removed'],
            ]);
            $this->redirect('/admin/taxonomies?taxonomy=' . urlencode($taxonomy) . '&saved=1'
                . ($result['moved'] > 0 ? '&moved=' . $result['moved'] : '')
                . ($result['removed'] > 0 ? '&removed=' . $result['removed'] . '&orphaned=' . $result['orphaned'] : ''));
            return;
        }

        $current = $store->load($taxonomy);
        $listable = $editor->listableTypes();
        $usage = $editor->usage($taxonomyNames, true);
        $terms = [];
        foreach ($current['terms'] as $term) {
            $byType = $usage[$taxonomy][$term['id']] ?? [];
            $term['used'] = array_sum($byType);
            $term['used_by_type'] = $byType;
            $terms[] = $term;
        }
        $tabs = [];
        foreach ($taxonomyNames as $name) {
            $tabs[] = [
                'name' => $name,
                'title' => $store->load($name)['title'],
                'terms' => count($store->load($name)['terms']),
                'filed' => array_sum(array_map('array_sum', $usage[$name] ?? [])),
            ];
        }
        $types = $this->content->getTypes();
        $this->render('@admin/taxonomies.twig', [
            'types' => $types,
            'user' => $this->auth->user(),
            'admin_section' => 'taxonomies',
            'taxonomy_names' => $taxonomyNames,
            'taxonomy_tabs' => $tabs,
            'taxonomy' => $taxonomy,
            'taxonomy_title' => $current['title'],
            'taxonomy_terms' => $terms,
            'taxonomy_kind' => Taxonomies::kind($taxonomy),
            'languages' => $languages,
            'default_language' => $default,
            'archive' => $store->archive($taxonomy, $default, $default),
            'layouts' => ContentTypes::LAYOUTS,
            'orders' => ContentTypes::ORDERS,
            'other_taxonomies' => array_values(array_diff($taxonomyNames, [$taxonomy])),
            'listable_types' => $listable,
            'type_labels' => array_combine($listable, array_map(fn(string $t): string => $this->contentTypes()->definition($t, 'en', $default)['label'], $listable)),
            'type_names' => array_combine($types, array_map(fn(string $t): string => $this->contentTypes()->definition($t, 'en', $default)['label'], $types)),
            'saved' => isset($_GET['saved']),
            'moved' => (int)($_GET['moved'] ?? 0),
            'removed' => (int)($_GET['removed'] ?? 0),
            'orphaned' => (int)($_GET['orphaned'] ?? 0),
            'can_redirects' => $this->permissions->can($this->auth->user(), 'redirects.manage'),
            'error' => '',
        ]);
    }

    private function taxonomyEditor(): TaxonomyEditor
    {
        return $this->taxonomyEditorService ??= new TaxonomyEditor($this->taxonomies(), $this->redirects, $this->content, fn(): Menus => $this->menus());
    }

    /** The settings texts that can point at uploads (logo, social image, and the like), for the usage scan. @return list<array{label: string, url: string, kind: string, text: string}> */
    private function mediaUsageSettingsSources(): array
    {
        $sources = [];
        foreach ([['site_settings', 'Site settings', '/admin/settings', 'settings'], ['theme_settings', 'Theme settings', '/admin/theme', 'theme']] as [$key, $label, $url, $kind]) {
            $text = (string)$this->systemMeta->get($key);
            if ($text !== '') {
                $sources[] = ['label' => $label, 'url' => $url, 'kind' => $kind, 'text' => $text];
            }
        }
        return $sources;
    }

    private function handleMedia(): void
    {
        $this->media->ensureDirectories();
        $this->media->migrateLegacyItems();

        $admin = $this->mediaAdmin();
        $state = $admin->state($_GET, (string)($_SESSION['admin_media_view'] ?? 'list'));
        if ($state['view_asked']) {
            $_SESSION['admin_media_view'] = $state['view'];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $outcome = $admin->apply($_POST, $_FILES, $this->currentUsername());
            if ($outcome !== null) {
                $this->redirect('/admin/media?' . http_build_query($admin->returnQuery($admin->postState($_POST, $state), $outcome)));
                return;
            }
        }

        $listing = $admin->listing($state);
        $maxUpload = $this->siteLimits()->maxUploadBytes();
        $this->render('@admin/media.twig', $listing + [
            'usage' => $state['usage'],
            'per_page' => $state['per_page'],
            'per_page_options' => MediaAdmin::PER_PAGE_OPTIONS,
            'type' => $state['type'],
            'types_available' => $this->media->typeOptions(),
            'tag' => $state['tag'],
            'q' => $state['q'],
            'view_mode' => $state['view'],
            'available_tags' => $this->media->availableTags(),
            'max_upload_mb' => $maxUpload > 0 ? max(1, (int)floor($maxUpload / 1048576)) : 0,
            'upload_accept' => implode(',', array_map(static fn(string $ext): string => '.' . $ext, $this->media->allowedExtensions())),
            'upload_kinds' => implode(', ', array_map(static fn(string $group): string => strtolower(MediaLibrary::UPLOAD_GROUPS[$group]['label']), $this->media->allowedGroups())),
            'success' => trim((string)($_GET['success'] ?? '')),
            'error' => trim((string)($_GET['error'] ?? '')),
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'media',
        ]);
    }

    private function mediaAdmin(): MediaAdmin
    {
        return $this->mediaAdminService ??= new MediaAdmin(
            $this->media,
            $this->mediaUsage,
            fn(int $bytes): bool => $this->siteLimits()->allows($bytes),
            fn(): string => $this->siteLimits()->fullMessage(),
            fn(int $bytes) => $this->siteLimits()->uploadsChanged($bytes),
            fn(): int => $this->siteLimits()->maxUploadBytes(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function handleFormsExport(): void
    {
        $slug = $this->slugify((string)($_GET['slug'] ?? ''));
        $lang = $this->slugify((string)($_GET['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        if ($slug === '') {
            $this->redirect('/admin/content?type=forms');
            return;
        }

        $form = $this->content->find('forms', $slug, $lang, true, false);
        if (!$form) {
            $this->redirect('/admin/content?type=forms&lang=' . urlencode($lang));
            return;
        }

        $export = $this->formsAdmin()->export($form, (string)($this->settings['title'] ?? ''));
        $this->logActivity('forms.export', 'info', 'forms', $slug . ':' . $lang, 'Form submissions exported.', [
            'slug' => $slug,
            'lang' => $lang,
            'submissions' => $export['count'],
            'filename' => $export['filename'],
        ]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }
        fputcsv($output, $export['headers'], ',', '"', '');
        foreach ($export['rows'] as $row) {
            fputcsv($output, $row, ',', '"', '');
        }
        fclose($output);
        exit;
    }

    /** Index writes must never block content editing. */
    private function indexContentFile(string $type, string $path): void
    {
        try {
            if (is_file($path)) {
                $this->contentIndex->upsert($this->content->parseFile($type, $path));
            }
        } catch (\Throwable) {
            // The index is rebuildable; a failed write only makes it stale.
        }
    }

    private function unindexContent(string $type, string $slug, string $lang): void
    {
        try {
            $this->contentIndex->remove($type, $slug, $lang);
        } catch (\Throwable) {
            // The index is rebuildable; a failed delete only makes it stale.
        }
    }

    /** @return array{ok: bool, indexed: int, removed: int, took_ms: int} */
    private function rebuildContentIndex(): array
    {
        try {
            // A fresh repository avoids this request's cached listings.
            $repository = new ContentRepository($this->contentDir, $this->markdownConverter(), $this->settings);
            return $this->contentIndex->rebuild($repository, $repository->getTypes());
        } catch (\Throwable) {
            return ['ok' => false, 'indexed' => 0, 'removed' => 0, 'took_ms' => 0];
        }
    }

    /** Rebuilds automatically when files changed outside the admin (git pull, FTP) on reasonably small sites. */
    private function ensureContentIndexFresh(): array
    {
        $status = $this->contentIndex->status($this->content->getTypes());
        if ($status['available'] && $status['stale'] && $status['files'] <= 3000) {
            $this->rebuildContentIndex();
            $status = $this->contentIndex->status($this->content->getTypes());
            $status['auto_rebuilt'] = true;
        }
        return $status;
    }

    private function handleAdminSearch(): void
    {
        $query = trim((string)($_GET['q'] ?? ''));
        $results = [];
        if ($query !== '') {
            $this->ensureContentIndexFresh();
            foreach ($this->contentIndex->search($query, 100) as $row) {
                if (!$this->canAccessContentType((string)$row['type'])) {
                    continue;
                }
                $row['edit_url'] = '/admin/edit?' . http_build_query(['type' => $row['type'], 'slug' => $row['slug'], 'lang' => $row['lang']]);
                $results[] = $row;
            }
        }
        $this->render('@admin/admin-search.twig', [
            'title' => 'Search',
            'query' => $query,
            'results' => $results,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'search',
            'current_type' => 'pages',
        ]);
    }

    private function handleSystem(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['system_action'] ?? '') === 'rebuild_index') {
            $result = $this->rebuildContentIndex();
            $this->logActivity($result['ok'] ? 'system.index_rebuild' : 'system.index_rebuild_failure', $result['ok'] ? 'info' : 'error', 'system', 'content_index', $result['ok'] ? 'Content index rebuilt.' : 'Content index rebuild failed.', $result);
            $this->redirect('/admin/system?' . http_build_query([
                'rebuilt' => $result['ok'] ? 'ok' : 'fail',
                'msg' => $result['ok'] ? 'Indexed ' . $result['indexed'] . ' entries in ' . $result['took_ms'] . ' ms' . ($result['removed'] > 0 ? ', removed ' . $result['removed'] . ' stale' : '') . '.' : 'The content index could not be rebuilt.',
            ]));
            return;
        }

        $storage = $this->siteLimits()->summary();
        $checks = $this->systemStatus()->checks($storage);
        $auto = is_array($this->settings['backup']['auto'] ?? null) ? $this->settings['backup']['auto'] : [];
        $update = $this->updates()->cachedStatus();

        $this->render('@admin/system.twig', [
            'title' => 'System',
            'checks' => $checks,
            'system_status' => SystemStatus::summarize($checks),
            'index' => $this->contentIndex->status($this->content->getTypes()),
            'extensions' => SystemStatus::extensions(),
            'environment' => $this->systemStatus()->environment($this->updates()->currentVersion(), $this->updates()->currentGitCommit(), $this->isHttpsRequest()),
            'tasks' => [
                ['name' => 'Automatic backups', 'schedule' => $this->isTruthy($auto['enabled'] ?? false) ? (string)($auto['schedule'] ?? 'daily') : 'off', 'last' => (string)($auto['last_run'] ?? ''), 'how' => 'Runs on the first admin page view after it is due.'],
                ['name' => 'Update check', 'schedule' => 'every 12 hours', 'last' => (string)($update['checked_at'] ?? ''), 'how' => 'Runs on an admin page view for users who manage updates.'],
                ['name' => 'Content index', 'schedule' => 'on change', 'last' => (string)($this->contentIndex->status($this->content->getTypes())['last_indexed_at'] ?? ''), 'how' => 'Updated on save, delete, bulk actions, imports, and restores; rebuilt automatically when files change outside the admin.'],
                ['name' => 'Sign-in attempt cleanup', 'schedule' => 'continuous', 'last' => '', 'how' => 'Entries older than a day are removed while new attempts are recorded.'],
            ],
            'storage' => $storage,
            'rebuilt' => (string)($_GET['rebuilt'] ?? ''),
            'rebuilt_message' => trim((string)($_GET['msg'] ?? '')),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'system',
            'current_type' => 'pages',
        ]);
    }

    private function handleFormsList(): void
    {
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'en');
        $languages = array_values(array_map('strval', $this->settings['languages']['available'] ?? [$defaultLang]));
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ];
        $overview = $this->formsAdmin()->overview($filters, (string)($_GET['sort'] ?? 'updated'), $languages, $defaultLang, $this->isTruthy($this->settings['forms']['store_submissions'] ?? true));

        $this->render('@admin/forms-list.twig', [
            'title' => 'Forms',
            'rows' => $overview['rows'],
            'totals' => $overview['totals'],
            'filters' => $filters,
            'sort' => $overview['sort'],
            'filters_active' => $filters['q'] !== '' || $filters['status'] !== '' || $overview['sort'] !== 'updated',
            'languages' => $languages,
            'default_lang' => $defaultLang,
            'deleted' => isset($_GET['deleted']),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'forms',
            'current_type' => 'forms',
        ]);
    }

    private function handleFormSubmissions(): void
    {
        $slug = $this->slugify((string)($_GET['slug'] ?? ($_POST['slug'] ?? '')));
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'en');
        $versions = $this->formsAdmin()->versions($slug);
        if ($slug === '' || $versions === []) {
            $this->redirect('/admin/forms');
            return;
        }
        $form = $versions[$defaultLang] ?? reset($versions);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = $this->formsAdmin()->deleteSubmissions($slug, $_POST);
            $deleted = $result['deleted'];
            if ($deleted > 0) {
                $this->logActivity('forms.submission_delete', 'warning', 'forms', $slug, $deleted === 1 ? 'Form submission deleted.' : 'Form submissions deleted.', [
                    'slug' => $slug,
                    'count' => $deleted,
                    'ids' => array_slice($result['ids'], 0, 50),
                ]);
            }
            $return = $this->sanitizeAdminReturnUrl((string)($_POST['return_to'] ?? ''));
            parse_str((string)parse_url($return, PHP_URL_QUERY), $query);
            if (!str_starts_with($return, '/admin/form-submissions')) {
                $query = [];
            }
            unset($query['deleted'], $query['error']);
            $query = ['slug' => $slug] + $query + ($deleted > 0 ? ['deleted' => $deleted] : ['error' => 'Nothing was deleted.']);
            $this->redirect('/admin/form-submissions?' . http_build_query($query));
            return;
        }

        $page = $this->formsAdmin()->page($form, $_GET);
        $this->render('@admin/form-submissions.twig', [
            'title' => 'Submissions',
            'form' => [
                'slug' => $slug,
                'title' => (string)($form->meta['title'] ?? $slug),
                'lang' => $form->lang,
                'languages' => array_keys($versions),
            ],
            'rows' => $page['rows'],
            'filters' => $page['filters'],
            'filters_active' => array_filter($page['filters']) !== [],
            'total_all' => $page['total_all'],
            'total' => $page['total'],
            'recent' => $page['recent'],
            'page' => $page['page'],
            'total_pages' => $page['total_pages'],
            'per_page' => $page['per_page'],
            'per_page_options' => FormsAdmin::PER_PAGE_OPTIONS,
            'deleted_count' => (int)($_GET['deleted'] ?? 0),
            'error' => trim((string)($_GET['error'] ?? '')),
            'current_url' => $this->currentRequestPath(),
            'languages' => array_values(array_map('strval', $this->settings['languages']['available'] ?? [])),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'forms',
            'current_type' => 'forms',
        ]);
    }

    private function formsAdmin(): FormsAdmin
    {
        return $this->formsAdminService ??= new FormsAdmin($this->content, $this->formSubmissions);
    }

    private function formProcessor(): FormProcessor
    {
        return $this->formProcessorService ??= new FormProcessor(fn(string $key, string $fallback): string => $this->translate($key, $fallback));
    }

    private function handleContentExport(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        $types = $this->content->getTypes();
        if (!in_array($type, $types, true) || $type === 'forms') {
            $this->redirect('/admin/content?type=' . urlencode($type));
            return;
        }

        $items = $this->content->getItems($type, null, true, false);
        $siteName = (string)($this->settings['title'] ?? 'site');
        $table = $this->contentCsv()->exportTable($type, $items, $siteName);
        $siteSlug = $this->slugify($siteName);
        if ($siteSlug === '') {
            $siteSlug = 'site';
        }
        $filename = $siteSlug . '-' . $type . '-all-languages.csv';

        $this->logActivity('content.export', 'info', $type, 'all', 'Content exported.', [
            'type' => $type,
            'items' => count($items),
            'filename' => $filename,
        ]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }

        fputcsv($output, $table['headers'], ',', '"', '');
        foreach ($table['rows'] as $row) {
            fputcsv($output, $row, ',', '"', '');
        }

        fclose($output);
        exit;
    }

    private function handleContentImport(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? ($_POST['type'] ?? 'pages')));
        $types = $this->content->getTypes();
        if (!in_array($type, $types, true)) {
            $type = $types[0] ?? 'pages';
        }

        $saved = false;
        $error = '';
        $previewRows = [];
        $previewSummary = [
            'create' => 0,
            'update' => 0,
            'skip' => 0,
            'error' => 0,
        ];
        $previewToken = '';
        $sourceFilename = '';

        $this->cleanupImportPreviewCache();

        if ($type === 'forms') {
            $this->redirect('/admin/content?type=forms');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['apply']) && (string)($_POST['apply']) === '1') {
                $token = trim((string)($_POST['preview_token'] ?? ''));
                $cache = $_SESSION['content_import_preview'][$token] ?? null;
                if (!is_array($cache) || ($cache['type'] ?? '') !== $type) {
                    $error = 'Import preview expired. Run dry-run again.';
                } else {
                    $entries = is_array($cache['entries'] ?? null) ? $cache['entries'] : [];
                    $applyResult = $this->contentCsv()->apply($type, $entries);
                    if (($applyResult['ok'] ?? false) === true) {
                        unset($_SESSION['content_import_preview'][$token]);
                        $summary = is_array($cache['summary'] ?? null) ? $cache['summary'] : [];
                        $this->rebuildContentIndex();
                        $this->logActivity('content.import_apply', 'info', $type, 'csv', 'Content import applied.', [
                            'type' => $type,
                            'filename' => (string)($cache['filename'] ?? ''),
                            'summary' => $summary,
                        ]);
                        $this->redirect('/admin/import?type=' . urlencode($type) . '&saved=1');
                        return;
                    }
                    $error = (string)($applyResult['error'] ?? 'Import failed.');
                    $previewRows = is_array($cache['rows'] ?? null) ? $cache['rows'] : [];
                    $previewSummary = is_array($cache['summary'] ?? null) ? $cache['summary'] : $previewSummary;
                    $previewToken = $token;
                    $sourceFilename = (string)($cache['filename'] ?? '');
                }
            } else {
                $file = $_FILES['csv_file'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $error = 'Please upload a valid CSV file.';
                } else {
                    $tmpPath = (string)($file['tmp_name'] ?? '');
                    if ($tmpPath === '' || !is_file($tmpPath)) {
                        $error = 'Upload failed.';
                    } else {
                        $sourceFilename = (string)($file['name'] ?? 'import.csv');
                        $ext = strtolower((string)pathinfo($sourceFilename, PATHINFO_EXTENSION));
                        if ($ext !== 'csv') {
                            $error = 'Please upload a .csv file.';
                        } else {
                            $parse = $this->contentCsv()->parseImport($tmpPath);
                            if (($parse['ok'] ?? false) !== true) {
                                $error = (string)($parse['error'] ?? 'Could not parse CSV.');
                            } else {
                                $preview = $this->contentCsv()->preview($type, (array)($parse['rows'] ?? []), (array)($parse['headers'] ?? []));
                                $previewRows = $preview['rows'];
                                $previewSummary = $preview['summary'];
                                if (!empty($preview['entries'])) {
                                    $previewToken = bin2hex(random_bytes(12));
                                    $_SESSION['content_import_preview'][$previewToken] = [
                                        'type' => $type,
                                        'entries' => $preview['entries'],
                                        'rows' => $previewRows,
                                        'summary' => $previewSummary,
                                        'filename' => $sourceFilename,
                                        'created_at' => time(),
                                    ];
                                }
                                $this->logActivity('content.import_preview', 'info', $type, 'csv', 'Content import preview generated.', [
                                    'type' => $type,
                                    'filename' => $sourceFilename,
                                    'summary' => $previewSummary,
                                ]);
                            }
                        }
                    }
                }
            }
        }

        if (isset($_GET['saved'])) {
            $saved = true;
        }

        $this->render('@admin/import.twig', [
            'type' => $type,
            'types' => $types,
            'user' => $this->auth->user(),
            'admin_section' => 'content',
            'current_type' => $type,
            'saved' => $saved,
            'error' => $error,
            'preview_rows' => $previewRows,
            'preview_summary' => $previewSummary,
            'preview_token' => $previewToken,
            'source_filename' => $sourceFilename,
        ]);
    }

    /**
     * Admin > Content types: what each type is called, how its archive looks, and the fields its
     * editor form has. Site choices are written to custom/content-types/<type>.yaml as differences
     * from the theme's definition, so theme updates keep flowing through; texts and fields the
     * theme translates are left alone unless they are changed here.
     */
    private function handleContentTypes(): void
    {
        $default = $this->defaultLanguage();
        $manageable = array_values(array_filter($this->content->getTypes(), static fn(string $t): bool => $t !== 'forms'));
        $types = $this->contentTypes();
        $admin = $this->contentTypeAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->redirect((string)($_POST['action'] ?? 'save') === 'create' ? $admin->create($_POST, $manageable) : $admin->update($_POST, $manageable, $default));
            return;
        }

        $selected = $this->slugify((string)($_GET['type'] ?? ''));
        $common = [
            'saved' => isset($_GET['saved']),
            'error' => (string)($_GET['error'] ?? ''),
            'custom_file' => 'custom/content-types/',
            'admin_section' => 'content-types',
        ];

        if ($selected !== '' && in_array($selected, $manageable, true)) {
            $definition = $types->definition($selected, $default, $default);
            $themeDefinition = $types->themeDefinition($selected, $default, $default);
            $this->render('@admin/content-type-edit.twig', [
                'type' => $selected,
                'definition' => $definition,
                'theme_definition' => $themeDefinition,
                'rows' => $admin->fieldRows($definition, $themeDefinition),
                'field_types' => ContentTypes::FIELD_TYPES,
                'layouts' => ContentTypes::LAYOUTS,
                'orders' => ContentTypes::orderOptions($definition['fields']),
                'taxonomy_names' => $this->taxonomies()->names(),
                'item_count' => count($this->content->getItems($selected, null, true)),
            ] + $common);
            return;
        }

        $this->render('@admin/content-types.twig', ['types_list' => $admin->overview($manageable, $default)] + $common);
    }

    private function contentTypeAdmin(): ContentTypeAdmin
    {
        return $this->contentTypeAdminService ??= new ContentTypeAdmin(
            $this->contentTypes(),
            $this->contentDir,
            fn(): array => $this->taxonomies()->names(),
            fn(string $key): bool => $this->isReservedFrontmatterKey($key),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function handleTranslations(): void
    {
        $available = $this->settings['languages']['available'] ?? [];
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        if (empty($available)) {
            $available = [$defaultLang];
        }

        $lang = $this->slugify((string)($_GET['lang'] ?? $_POST['lang'] ?? $defaultLang));
        if (!in_array($lang, $available, true)) {
            $lang = $defaultLang;
        }

        $strings = $this->themeStrings();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $strings->save($lang, $defaultLang, $_POST);
            if (!$result['ok']) {
                $this->redirect('/admin/translations?lang=' . urlencode($lang) . '&error=write');
                return;
            }
            $this->logActivity('translations.update', 'info', 'translation', $lang, 'Translations updated.', [
                'lang' => $lang,
                'overrides' => $result['overrides'],
            ]);
            $this->redirect('/admin/translations?lang=' . urlencode($lang) . '&saved=1');
            return;
        }

        $screen = $strings->screen($lang, $defaultLang);

        $this->render('@admin/translations.twig', [
            'lang' => $lang,
            'languages' => $available,
            'translations' => $screen['translations'],
            'defaults' => $screen['defaults'],
            'customized' => $screen['customized'],
            'custom_file' => $screen['custom_file'],
            'saved' => isset($_GET['saved']),
            'write_error' => ($_GET['error'] ?? '') === 'write',
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'default_lang' => $defaultLang,
            'admin_section' => 'translations',
        ]);
    }


    private function initTwig(): TwigEnvironment
    {
        $adminDir = $this->basePath . '/admin/templates';

        $loader = new FilesystemLoader();
        foreach ($this->theme->templateRoots() as $root) {
            $loader->addPath($root);
        }
        // `{% extends '@theme/templates/single.twig' %}` lets a custom/ override change one block only.
        $loader->addPath($this->theme->path(), 'theme');
        $loader->addPath($adminDir, 'admin');

        $twig = new TwigEnvironment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);

        $baseUrl = rtrim((string)($this->settings['base_url'] ?? ''), '/');
        $twig->addFunction(new TwigFunction('asset', function (string $path) use ($baseUrl): string {
            return $baseUrl . '/assets/' . ltrim($path, '/');
        }));

        $twig->addFunction(new TwigFunction('theme_asset', function (string $path) use ($baseUrl): string {
            return $this->theme->assetUrl($baseUrl, $path);
        }));

        $twig->addFunction(new TwigFunction('custom_asset', function (string $path) use ($baseUrl): string {
            return $this->theme->customAssetUrl($baseUrl, $path);
        }));

        $twig->addFunction(new TwigFunction('image', function (string $src, array $options = []): string {
            return $this->images->render($src, $options);
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('icon', function (string $name, string $class = ''): string {
            return $this->theme->icon($name, $class);
        }, ['is_safe' => ['html']]));

        // The icon set as JSON ({name: svg}) for the admin's icon picker, one library for every place an icon is chosen.
        $twig->addFunction(new TwigFunction('icon_library_json', function (): string {
            $library = [];
            foreach ($this->theme->iconNames() as $name) {
                $svg = $this->theme->icon($name);
                if ($svg !== '') {
                    $library[$name] = $svg;
                }
            }
            return (string)json_encode($library, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT);
        }, ['is_safe' => ['html']]));

        // Declared fields of a content item, ready to print: content_fields(item) for its page,
        // content_fields(item, 'card') for the fields marked for cards.
        $twig->addFunction(new TwigFunction('content_fields', function (mixed $item, string $context = 'page'): array {
            if (!$item instanceof ContentItem) {
                return [];
            }
            return $this->contentTypes()->display(
                $item->type,
                $item->meta,
                $context === 'card' ? 'card' : 'page',
                fn(string $value): string => $this->formatDateValue($value),
                $this->currentLang,
                $this->defaultLanguage()
            );
        }));

        $twig->addFunction(new TwigFunction('content_type', function (string $type): array {
            return $this->contentTypes()->definition($type, $this->currentLang, $this->defaultLanguage());
        }));

        // Block links: absolute URLs, #anchors, mailto:/tel: as given; "/path" from the site root;
        // a bare "path" is relative to the current language ("contact" → "/en/contact").
        $twig->addFunction(new TwigFunction('link_url', function (string $value, string $prefix = '') use ($baseUrl): string {
            $value = trim($value);
            if ($value === '' || !FieldSchema::isSafeLink($value)) {
                return '';
            }
            if (preg_match('#^(https?://|mailto:|tel:|\#)#i', $value)) {
                return $value;
            }
            if (str_starts_with($value, '/')) {
                return $baseUrl . $value;
            }
            return $baseUrl . '/' . $prefix . ltrim($value, '/');
        }));

        $twig->addFunction(new TwigFunction('absolute_url', function (string $path): string {
            return preg_match('#^https?://#i', $path) ? $path : $this->buildAbsoluteUrl($path);
        }));

        $twig->addFunction(new TwigFunction('toc', function (string $html): array {
            return Toc::build($html);
        }));

        $twig->addFunction(new TwigFunction('json_ld', function (array $graph): string {
            return $this->structuredData()->script($graph);
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('admin_asset', function (string $path) use ($baseUrl): string {
            return $baseUrl . '/admin-assets/' . ltrim($path, '/');
        }));

        $twig->addFunction(new TwigFunction('url', function (string $path = '') use ($baseUrl): string {
            return $baseUrl . '/' . ltrim($path, '/');
        }));

        $twig->addFunction(new TwigFunction('t', function (string $key, ?string $fallback = null): string {
            return $this->translate($key, $fallback);
        }));

        $twig->addFunction(new TwigFunction('format_date', function (mixed $value): string {
            return $this->formatDateValue($value);
        }));

        $twig->addFunction(new TwigFunction('slug', function (string $value): string {
            return $this->slugify($value);
        }));

        $twig->addFunction(new TwigFunction('taxonomy_label', function (string $taxonomy, string $termId, ?string $lang = null): string {
            return $this->taxonomyTermLabel($taxonomy, $termId, $lang);
        }));

        $twig->addFunction(new TwigFunction('taxonomy_url', function (string $taxonomy, string $termId, string $prefix = ''): string {
            $path = $this->buildTaxonomyPath($taxonomy, $termId, $prefix);
            return $this->buildAbsoluteUrl($path);
        }));

        $twig->addFunction(new TwigFunction('can', function (string $capability): bool {
            return $this->permissions->can($this->auth->user(), $capability);
        }));

        $twig->addFunction(new TwigFunction('csrf_token', function (): string {
            return $this->csrfToken();
        }));

        $twig->addFunction(new TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($this->csrfToken(), ENT_QUOTES) . '">';
        }, ['is_safe' => ['html']]));

        $twig->addGlobal('site', $this->settings);
        $twig->addGlobal('theme_settings', $this->themeSettings);
        $twig->addGlobal('theme', ['name' => $this->theme->name(), 'version' => $this->theme->version()]);
        $twig->addGlobal('is_admin', $this->auth->check());

        return $twig;
    }

    private function render(string $template, array $data = []): void
    {
        $defaults = [
            'is_admin' => $this->auth->check(),
        ];
        if (str_starts_with($template, '@admin/') && $this->auth->check()) {
            // System notices (updates, failed backups, security) are for those who manage the site.
            $seesNotifications = $this->permissions->can($this->auth->user(), 'notifications.manage');
            if (!$seesNotifications) {
                $data += ['admin_notifications' => [], 'admin_notification_unread_count' => 0];
            }
            $syncNotifications = !isset($data['admin_notifications']) || !isset($data['admin_notification_unread_count']);
            if ($syncNotifications) {
                $this->syncSystemNotifications();
            }
            $updates = $this->updates();
            $cachedUpdate = $updates->cachedStatus();
            $defaults['admin_version'] = $updates->currentVersion();
            $defaults['admin_update_available'] = $cachedUpdate !== null && $cachedUpdate['has_update'];
            $defaults['admin_update_latest'] = $cachedUpdate['latest_version'] ?? '';
            $defaults['admin_default_password'] = ($_SESSION['security_default_password'] ?? false) === true;
            if (!isset($data['admin_storage_summary'])) {
                $defaults['admin_storage_summary'] = $this->siteLimits()->summary();
            }
            if (($defaults['admin_storage_summary']['level'] ?? $data['admin_storage_summary']['level'] ?? 'ok') === 'danger') {
                $contact = $this->storageContact();
                $summary = $defaults['admin_storage_summary'] ?? $data['admin_storage_summary'];
                $siteName = (string)($this->settings['title'] ?? 'FarosCMS');
                $defaults['admin_storage_contact'] = $contact === null ? null : $contact + ['href' => 'mailto:' . $contact['email']
                    . '?subject=' . rawurlencode('Storage almost full: ' . $siteName)
                    . '&body=' . rawurlencode("Hello,\n\nThe storage of " . $siteName . ' is ' . ($summary['percent_of_limit'] ?? $summary['percent']) . '% full (' . $summary['label'] . "). Could you raise the limit, or help me free some space?\n\nThank you")];
            }
            if ($syncNotifications) {
                $defaults['admin_notifications'] = $this->notifications->recent(6);
                $defaults['admin_notification_unread_count'] = $this->notifications->unreadCount();
                $defaults['admin_current_url'] = $this->currentRequestPath();
            }
        }

        if (!str_starts_with($template, '@admin/') && !array_key_exists('structured_data', $data)) {
            $defaults['structured_data'] = $this->structuredData()->site();
        }

        $data = array_merge($defaults, $data);
        echo $this->twig->render($template, $data);
    }

    private function renderForbidden(string $message = '', string $title = 'Access denied'): void
    {
        if (http_response_code() < 400) {
            http_response_code(403);
        }
        $this->render('@admin/forbidden.twig', [
            'title' => $title,
            'message' => $message,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'forbidden',
            'current_type' => 'pages',
        ]);
    }

    private function logActivity(
        string $action,
        string $level = 'info',
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $message = null,
        array $context = [],
        ?array $actor = null
    ): void
    {
        try {
            $actor ??= $this->auth->user();
            $this->activityLogs->record([
                'level' => $level,
                'action' => $action,
                'actor_username' => (string)($actor['username'] ?? ''),
                'actor_role' => (string)($actor['role'] ?? ''),
                'ip_address' => $this->clientIpAddress(),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable) {
            // Activity logging must never block the admin workflow.
        }
    }

    private function logEmailAttempt(string $to, string $subject, string $provider, bool $ok, string $error = '', array $context = []): void
    {
        try {
            $this->emailLogs->record([
                'recipient' => $to,
                'subject' => $subject,
                'status' => $ok ? 'sent' : 'failed',
                'provider' => $provider,
                'error_message' => $ok ? '' : $error,
                'context' => $context,
            ]);
            if (!$ok) {
                $this->notifyEmailFailure($to, $subject, $provider, $error);
            }
        } catch (\Throwable) {
            // Email logging must never block sending or form handling.
        }
    }

    /** Tells the admins that a content file has front matter nobody can read, once for each file until it is fixed. */
    private function reportUnreadableContent(string $type, string $path, string $message): void
    {
        try {
            $filename = basename($path, '.md');
            $lang = (string)($this->settings['languages']['default'] ?? 'en');
            $slug = $filename;
            if (preg_match('/^(.*)\.([a-z]{2})$/', $filename, $m)) {
                $slug = $m[1];
                $lang = $m[2];
            }
            $this->notifications->createIfMissing([
                'type' => 'content.unreadable',
                'title' => 'A content file cannot be read',
                'body' => $type . '/' . basename($path) . ': ' . $message,
                'severity' => 'error',
                'target_url' => '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang),
                'context' => ['type' => $type, 'file' => basename($path), 'error' => $message],
            ]);
        } catch (\Throwable) {
            // Reporting must never be what breaks a page.
        }
    }

    private function notifyEmailFailure(string $to, string $subject, string $provider, string $error): void
    {
        try {
            $body = trim($error) !== '' ? $error : 'Email delivery failed.';
            $this->notifications->createIfMissing([
                'type' => 'email.failed',
                'title' => 'Email delivery failed',
                'body' => $to . ' - ' . $body,
                'severity' => 'error',
                'target_url' => '/admin/email-logs?status=failed',
                'context' => [
                    'recipient' => $to,
                    'subject' => $subject,
                    'provider' => $provider,
                    'error' => $error,
                ],
            ]);
        } catch (\Throwable) {
            // Notifications must never block the primary workflow.
        }
    }

    private function syncSystemNotifications(): void
    {
        if ($this->permissions->can($this->auth->user(), 'updates.manage')) {
            try {
                // Refreshes at most every 12 hours (hourly while the source is unreachable).
                $this->syncUpdateNotification($this->updates()->status());
            } catch (\Throwable) {
                // Update checks must never block rendering.
            }
        }
        try {
            $storage = $this->siteLimits()->summary();
            foreach ($this->systemStatus()->checks($storage) as $check) {
                $status = (string)($check['status'] ?? 'ok');
                if (!in_array($status, ['warning', 'error'], true)) {
                    continue;
                }
                $label = (string)($check['label'] ?? 'System check');
                $this->notifications->createIfMissing([
                    'type' => 'system.' . strtolower(str_replace(' ', '_', $label)),
                    'title' => 'System check: ' . $label,
                    'body' => (string)($check['value'] ?? ''),
                    'severity' => $status,
                    'target_url' => '/admin',
                    'context' => [
                        'check' => $check,
                    ],
                ], true);
            }
        } catch (\Throwable) {
            // System notification sync should never block rendering.
        }
    }

    private function currentRequestPath(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/admin');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/admin';
        $query = parse_url($uri, PHP_URL_QUERY);
        return $query ? $path . '?' . $query : $path;
    }

    private function sanitizeAdminReturnUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return '/admin';
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/admin';
        if (!str_starts_with($path, '/admin')) {
            return '/admin';
        }
        $query = parse_url($url, PHP_URL_QUERY);
        return $query ? $path . '?' . $query : $path;
    }

    private function clientIpAddress(): string
    {
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            return (string)($parts[0] ?? '');
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * A request that matched nothing: send it where a redirect says, or remember it so an administrator can fix it.
     * Real pages never get here, so redirects cost nothing on normal requests.
     */
    private function redirectOrRemember404(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || !$this->redirects->isAvailable()) {
            return;
        }
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $hit = $this->redirects->resolve($path);
        if ($hit !== null) {
            $this->redirects->recordHit($hit['id']);
            $target = $hit['target'];
            if (!RedirectRepository::isExternal($target)) {
                $suffix = '';
                if (preg_match('/^([^?#]*)([?#].*)$/', $target, $m)) {
                    $target = $m[1];
                    $suffix = $m[2];
                }
                $target = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($target, '/')))) . $suffix;
                $query = (string)($_SERVER['QUERY_STRING'] ?? '');
                if ($query !== '' && !str_contains($suffix, '?')) {
                    $target = preg_replace('/#.*$/', '', $target) . '?' . $query . (str_contains($suffix, '#') ? substr($suffix, (int)strpos($suffix, '#')) : '');
                }
            }
            header('Location: ' . $target, true, $hit['code']);
            exit;
        }
        // Files and probes are not broken links, so they stay out of the list.
        if (preg_match('#\.(css|js|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|php|env|git)$#i', $path) || preg_match('#^/(assets|uploads|\.well-known)/#', $path)) {
            return;
        }
        $referrer = '';
        $ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if (is_array($ref) && isset($ref['host'])) {
            $referrer = $ref['host'] . ($ref['path'] ?? '');
        }
        $this->redirects->recordNotFound($path, $referrer);
    }

    private function render404(): void
    {
        $this->redirectOrRemember404();
        http_response_code(404);
        $lang = $this->currentLang ?: ($this->settings['languages']['default'] ?? 'en');
        if ($this->theme->hasTemplate('templates/404.twig')) {
            $this->render('templates/404.twig', [
                'lang' => $lang,
                'lang_prefix' => $this->langPrefix($lang),
                'current_lang' => $lang,
            ]);
            return;
        }

        $item = new ContentItem('pages', '404', $lang, [
            'title' => 'Not Found',
            'slug' => '404',
            'type' => 'pages',
        ], '', '<p>Sorry, this page does not exist.</p>', '', time());
        $this->render($this->resolveItemTemplate($item), [
            'item' => $item,
            'lang' => $lang,
            'lang_prefix' => $this->langPrefix($lang),
            'current_lang' => $lang,
        ]);
    }

    private function loadThemeSettings(): array
    {
        $raw = $this->siteSettings()->raw('theme_settings', $this->defaultThemeSettings());
        $data = [];
        if (trim($raw) !== '') {
            try {
                $parsed = Yaml::parse($raw);
                $data = is_array($parsed) ? $parsed : [];
            } catch (\Throwable) {
                $data = [];
            }
        }
        // The theme manifest decides what is valid: new fields get defaults, retired values fall back.
        return $this->theme->resolveSettings($data);
    }

    private function defaultThemeSettings(): array
    {
        return $this->theme->defaultSettings();
    }

    private function getSystemMeta(string $key): ?string
    {
        return $this->systemMeta->get($key);
    }

    private function setSystemMeta(string $key, string $value): void
    {
        $this->systemMeta->set($key, $value);
    }

    private function updates(): UpdateService
    {
        $settings = $this->settings['updates'] ?? [];
        return new UpdateService($this->basePath, is_array($settings) ? $settings : [], $this->systemMeta);
    }

    /**
     * @param array<string, mixed> $input submitted `theme_settings[section][field]` values
     * @return array{ok: bool, message?: string}
     */
    private function saveThemeSettings(array $input): array
    {
        if (!$this->systemDatabase->isAvailable()) {
            return ['ok' => false, 'message' => 'Theme settings could not be saved because the SQLite system database is unavailable.'];
        }

        // Keys the theme does not declare (hand-added options) are kept, not dropped.
        $data = $this->theme->settingsFromInput($input, $this->loadThemeSettings());
        $this->setSystemMeta('theme_settings', Yaml::dump($data, 4, 2));
        return ['ok' => true];
    }

    /** @return array<int, array{name: string, title: string, terms: array<int, array{id: string, slug: string, labels: array<string, string>}>}> */
    private function listTaxonomiesForAdmin(): array
    {
        $rows = [];
        foreach ($this->taxonomies()->names() as $name) {
            $taxonomy = $this->taxonomies()->load($name);
            $rows[] = [
                'name' => $name,
                'title' => (string)$taxonomy['title'],
                'terms' => $taxonomy['terms'],
            ];
        }
        return $rows;
    }

    private function taxonomyTermLabel(string $taxonomy, string $termId, ?string $lang = null): string
    {
        return $this->taxonomies()->label($taxonomy, $termId, $lang !== null && $lang !== '' ? $lang : $this->currentLang);
    }

    private function siteSettings(): SiteSettings
    {
        return $this->siteSettingsService ??= new SiteSettings($this->systemMeta, $this->contentDir);
    }

    private function backupManager(): BackupManager
    {
        return $this->backupManagerService ??= new BackupManager(
            $this->backups,
            $this->systemDatabase,
            $this->backupRuns,
            $this->notifications,
            fn(): array => $this->settings,
            fn(): UpdateService => $this->updates(),
            fn(string $isoDate) => $this->updateBackupLastRun($isoDate),
            fn(array $config) => new S3BackupStorage($config)
        );
    }

    private function languageAlternates(): LanguageAlternates
    {
        return $this->languageAlternatesService ??= new LanguageAlternates(
            $this->content,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): array => $this->settings,
            fn(string $path): string => $this->buildAbsoluteUrl($path)
        );
    }

    private function entryTranslations(): EntryTranslations
    {
        return $this->entryTranslationsService ??= new EntryTranslations($this->content, fn(): array => $this->settings);
    }

    private function themeStrings(): ThemeStrings
    {
        return $this->themeStringsService ??= new ThemeStrings($this->theme);
    }

    private function siteLimits(): SiteLimits
    {
        return $this->siteLimitsService ??= new SiteLimits($this->systemMeta, $this->basePath, $this->contentDir, fn(): array => $this->settings);
    }

    private function systemStatus(): SystemStatus
    {
        return $this->systemStatusService ??= new SystemStatus(
            $this->basePath,
            $this->contentDir,
            $this->systemDatabase,
            fn(): string => $this->mailer()->provider(),
            fn(): array => $this->ensureContentIndexFresh(),
            fn(): array => $this->backupManager()->scheduleStatus()
        );
    }

    private function dashboardData(): DashboardData
    {
        return $this->dashboardDataService ??= new DashboardData(
            $this->content,
            $this->users,
            $this->activityLogs,
            $this->emailLogs,
            fn(): array => $this->listBackupSnapshots(),
            $this->siteLimits(),
            $this->systemStatus()
        );
    }

    private function contentCsv(): ContentCsv
    {
        return $this->contentCsvService ??= new ContentCsv(
            $this->content,
            $this->contentDir,
            fn(): array => $this->settings,
            $this->revisions,
            fn(): HtmlGuard => $this->htmlGuard(),
            fn(string $date): string => $this->normalizeDateForStorage($date),
            fn(): string => $this->currentUsername(),
            fn(): bool => $this->permissions->can($this->auth->user(), 'content.raw_html')
        );
    }

    private function structuredData(): StructuredData
    {
        return $this->structuredDataService ??= new StructuredData(
            fn(): array => $this->settings,
            fn(): array => $this->themeSettings,
            fn(string $key, ?string $fallback = null): string => $this->translate($key, $fallback),
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(string $lang): string => $this->langPrefix($lang)
        );
    }

    private function menus(): Menus
    {
        return $this->menuStore ??= new Menus(
            $this->contentDir,
            fn(): array => $this->settings,
            fn(string $key, ?string $fallback = null): string => $this->translate($key, $fallback),
            fn(): array => $this->theme->menuLocations()
        );
    }

    private function taxonomies(): Taxonomies
    {
        return $this->taxonomyStore ??= new Taxonomies($this->contentDir, array_map('strval', $this->settings['languages']['available'] ?? [(string)($this->settings['languages']['default'] ?? 'el')]));
    }

    /** @return array{0: string, 1: string[]} */
    private function extractLang(string $path): array
    {
        $segments = $path === '' ? [] : explode('/', $path);
        $available = $this->settings['languages']['available'] ?? [];
        $lang = $this->settings['languages']['default'] ?? 'en';
        if (isset($segments[0]) && in_array($segments[0], $available, true)) {
            $lang = array_shift($segments);
        }
        return [$lang, $segments];
    }

    private function resolveItemTemplate(ContentItem $item): string
    {
        $custom = $this->normalizeTemplateName((string)($item->meta['template'] ?? ''));
        if ($custom !== '' && $this->theme->hasTemplate($custom)) {
            return $custom;
        }

        $singular = $this->singularizeType($item->type);
        $candidates = [
            'templates/single-' . $singular . '.twig',
            'templates/single.twig',
            'templates/' . $item->type . '.twig',
        ];
        if ($item->type === 'pages') {
            $candidates[] = 'templates/page.twig';
        } elseif ($item->type === 'posts') {
            $candidates[] = 'templates/post.twig';
        }

        return $this->theme->findTemplate($candidates) ?? 'templates/single.twig';
    }

    /** Front matter `template:` accepts `landing`, `landing.twig`, or `templates/landing.twig`. */
    private function normalizeTemplateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (!str_ends_with($name, '.twig')) {
            $name .= '.twig';
        }
        if (!str_starts_with($name, 'templates/')) {
            $name = 'templates/' . $name;
        }
        return Theme::isSafeRelativePath($name) ? $name : '';
    }

    private function renderSitemap(): void
    {
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        $available = $this->settings['languages']['available'] ?? [$defaultLang];
        $homeSlug = $this->settings['home_page'] ?? 'index';
        if ($homeSlug === '') {
            $homeSlug = 'index';
        }

        $entries = [];
        foreach ($this->content->getTypes() as $type) {
            foreach ($available as $lang) {
                $items = $this->content->getItems($type, $lang, false);
                foreach ($items as $item) {
                    $key = $lang . '|' . $type . '|' . $item->slug;
                    if (isset($entries[$key])) {
                        continue;
                    }
                    $path = $this->buildContentPath($type, $item->slug, $lang, $homeSlug, $defaultLang);
                    $entries[$key] = [
                        'loc' => $this->buildAbsoluteUrl($path),
                        'lastmod' => date('c', $item->mtime),
                    ];
                }
            }
        }

        header('Content-Type: application/xml; charset=utf-8');
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($entries as $entry) {
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($entry['loc'], ENT_QUOTES) . "</loc>\n";
            echo "    <lastmod>" . htmlspecialchars($entry['lastmod'], ENT_QUOTES) . "</lastmod>\n";
            echo "  </url>\n";
        }
        echo "</urlset>\n";
    }

    private function renderRobots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo RobotsTxt::render($this->buildAbsoluteUrl('sitemap.xml'), is_array($this->settings['seo']['robots_disallow'] ?? null) ? $this->settings['seo']['robots_disallow'] : []);
    }

    private function handleTaxonomy(array $segments, string $lang, array $viewDefaults): void
    {
        $kind = $segments[0] ?? '';
        $slug = $segments[1] ?? '';
        if ($slug === '') {
            $this->render404();
            return;
        }

        $kind = in_array($kind, ['category', 'categories'], true) ? 'category' : 'tag';
        $key = $kind === 'category' ? 'categories' : 'tags';
        $term = $this->taxonomies()->findBySlug($key, $slug);
        if ($term === null) {
            $this->render404();
            return;
        }
        $termId = $term['id'];
        $termSlug = $term['slug'];
        $label = $this->taxonomyTermLabel($key, $termId, $lang);

        // How this taxonomy lists its entries is its own choice (Admin > Taxonomies), the same options a content type has.
        $settings = $this->taxonomies()->archive($key, $lang, $this->defaultLanguage());
        $settings['taxonomies'] = array_values(array_diff($settings['taxonomies'], [$key]));
        $includeTypes = $settings['types'];

        $items = [];
        foreach ($this->content->getTypes() as $type) {
            if (in_array($type, ['pages', 'forms'], true) || ($includeTypes !== [] && !in_array($type, $includeTypes, true))) {
                continue;
            }
            foreach ($this->content->getItems($type, $lang, false, false) as $item) {
                if (in_array($termId, $this->normalizeMetaList($item->meta[$key] ?? null), true)) {
                    $items[] = $item;
                }
            }
        }
        // Entries of several types come newest first, as within a single type.
        $when = static fn(ContentItem $i): int => strtotime((string)($i->meta['date'] ?? '')) ?: $i->mtime;
        usort($items, static fn(ContentItem $a, ContentItem $b): int => $when($b) <=> $when($a));
        $archive = $this->buildArchive($settings, [], null, $lang, $items);
        if ($archive['page'] > 1 && !$archive['filtered']) {
            $viewDefaults['canonical_url'] = ($viewDefaults['canonical_url'] ?? '') . '?page=' . $archive['page'];
        }

        $fill = static fn(string $text): string => str_replace('{term}', $label, $text);
        $titlePrefix = $kind === 'category'
            ? $this->translate('taxonomy.category', 'Category')
            : $this->translate('taxonomy.tag', 'Tag');
        $alternates = $this->languageAlternates()->forTaxonomy($kind, $termSlug);
        $this->render($this->resolveTaxonomyTemplate($kind, $slug), [
            'items' => $archive['items'],
            'archive' => $archive,
            'type' => $key,
            'archive_title' => $settings['title'] !== '' ? $fill($settings['title']) : $titlePrefix . ': ' . $label,
            'archive_subtitle' => $settings['subtitle'] !== '' ? $fill($settings['subtitle']) : $this->taxonomies()->description($key, $termId, $lang, $this->defaultLanguage()),
            'term_description' => $this->taxonomies()->description($key, $termId, $lang, $this->defaultLanguage()),
            'block_styles' => [$this->theme->blockStylesheetUrl(rtrim((string)($this->settings['base_url'] ?? ''), '/'), ['latest'])],
            'alternate_urls' => $alternates['urls'],
            'alternate_default' => $alternates['default'],
            'noindex_page' => $archive['filtered'],
        ] + $viewDefaults);
    }

    private function buildContentPath(string $type, string $slug, string $lang, string $homeSlug, string $defaultLang): string
    {
        return ContentPaths::build($type, $slug, $lang, $homeSlug, $defaultLang);
    }

    private function buildArchivePath(string $type, string $lang, string $defaultLang): string
    {
        $prefix = $lang === $defaultLang ? '' : $lang . '/';
        return $prefix . $type;
    }

    private function buildTaxonomyPath(string $taxonomy, string $termId, string $prefix = ''): string
    {
        $kind = $taxonomy === 'categories' ? 'category' : 'tag';
        $slug = $this->taxonomies()->slug($taxonomy, $termId);
        if ($slug === '') {
            return '';
        }
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return $kind . '/' . $slug;
        }
        return $prefix . '/' . $kind . '/' . $slug;
    }

    private function resolveTaxonomyTemplate(string $kind, string $slug): string
    {
        $safeSlug = $this->slugify($slug);
        return $this->theme->findTemplate([
            'templates/archive-' . $kind . '-' . $safeSlug . '.twig',
            'templates/archive-' . $kind . '.twig',
            'templates/archive.twig',
        ]) ?? 'templates/archive.twig';
    }

    private function formatDateValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $format = (string)($this->settings['date_format'] ?? 'd/m/Y');
        if (is_int($value)) {
            return date($format, $value);
        }
        if (is_numeric($value)) {
            return date($format, (int)$value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }
        $timestamp = strtotime((string)$value);
        if ($timestamp === false) {
            return (string)$value;
        }
        return date($format, $timestamp);
    }

    private function normalizeMetaList(mixed $value): array
    {
        return Format::list($value);
    }

    private function isTruthy(mixed $value): bool
    {
        return Format::isTruthy($value);
    }

    private function isReservedFrontmatterKey(string $key): bool
    {
        return $this->contentEditor()->isReservedKey($key);
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
                if ($key === '' || $this->isReservedFrontmatterKey($key) || in_array($key, $exclude, true)) {
                    continue;
                }
                $custom[$key] = $this->stringifyCustomValue($value);
            }
        }

        foreach ($meta as $key => $value) {
            $key = (string)$key;
            if ($this->isReservedFrontmatterKey($key) || array_key_exists($key, $custom) || in_array($key, $exclude, true)) {
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

    private function normalizeAdminDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value)) {
            return $this->formatDateValue($value);
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }
            if (ctype_digit($trimmed)) {
                return $this->formatDateValue((int)$trimmed);
            }
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $trimmed)) {
                return $this->formatDateValue($trimmed);
            }
            if (preg_match('/^\\d{4}\\/\\d{2}\\/\\d{2}$/', $trimmed)) {
                return $this->formatDateValue($trimmed);
            }
            return $trimmed;
        }
        return (string)$value;
    }

    private function normalizeDateForStorage(string $value): string
    {
        return $this->contentEditor()->normalizeDate($value);
    }

    private function parseCommaList(string $value): array
    {
        return Format::commaList($value);
    }

    private function resolveCanonicalUrl(?string $override, string $fallback): string
    {
        if ($override === null || trim($override) === '') {
            return $fallback;
        }
        $override = trim($override);
        if (preg_match('#^https?://#i', $override)) {
            return $override;
        }
        return $this->buildAbsoluteUrl($override);
    }

    private function buildAbsoluteUrl(string $path): string
    {
        $base = $this->getBaseUrl();
        $base = rtrim($base, '/');
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        return $base . '/' . ltrim($path, '/');
    }

    private function getBaseUrl(): string
    {
        $base = trim((string)($this->settings['base_url'] ?? ''));
        if ($base !== '') {
            return $base;
        }

        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($scheme === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    private function setLanguage(string $lang): void
    {
        if ($lang === $this->currentLang && !empty($this->translations)) {
            return;
        }
        $this->currentLang = $lang;
        $this->translations = $this->loadTranslations($lang);
    }

    private function translate(string $key, ?string $fallback = null): string
    {
        if (isset($this->translations[$key])) {
            return (string)$this->translations[$key];
        }
        return $fallback ?? $key;
    }

    private function loadTranslations(string $lang): array
    {
        return $this->theme->translations($lang, (string)($this->settings['languages']['default'] ?? 'en'));
    }

    /** @return array<int, array{label: string, value: string, status: string}> */
    private function buildUpdatePreflightChecks(): array
    {
        $backupDir = $this->backupDirectory();
        $source = $this->updates()->sourceConfig();
        $preBackup = $this->preUpdateBackupStatus();
        $preBackupAge = $preBackup !== null ? time() - (int)strtotime($preBackup['created_at']) : PHP_INT_MAX;
        $preBackupReady = $preBackup !== null && $preBackup['verified'] && $preBackupAge < 86400 && $preBackup['version'] === $this->updates()->currentVersion();
        return [
            [
                'label' => 'Verified pre-update backup',
                'value' => $preBackupReady ? 'ready' : ($preBackup === null ? 'missing' : ($preBackup['verified'] ? 'older than 24h' : 'not verified')),
                'status' => $preBackupReady ? 'ok' : 'warning',
            ],
            [
                'label' => 'PHP compatibility',
                'value' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'error',
            ],
            [
                'label' => 'Content writable',
                'value' => is_writable($this->contentDir) ? 'yes' : 'no',
                'status' => is_writable($this->contentDir) ? 'ok' : 'error',
            ],
            [
                'label' => 'Storage writable',
                'value' => is_writable($this->basePath . '/storage') ? 'yes' : 'no',
                'status' => is_writable($this->basePath . '/storage') ? 'ok' : 'error',
            ],
            [
                'label' => 'Backup directory',
                'value' => is_dir($backupDir) && is_writable($backupDir) ? 'ready' : 'not ready',
                'status' => is_dir($backupDir) && is_writable($backupDir) ? 'ok' : 'warning',
            ],
            [
                'label' => 'Update source',
                'value' => (string)$source['version_url'] !== '' ? 'configured' : 'not configured',
                'status' => (string)$source['version_url'] !== '' ? 'ok' : 'warning',
            ],
        ];
    }

    private function resolveArchiveTemplate(string $type): string
    {
        $singular = $this->singularizeType($type);
        return $this->theme->findTemplate([
            'templates/archive-' . $type . '.twig',
            'templates/archive-' . $singular . '.twig',
            'templates/' . $type . '_archive.twig',
            'templates/archive.twig',
        ]) ?? 'templates/archive.twig';
    }

    private function singularizeType(string $type): string
    {
        if (str_ends_with($type, 's') && strlen($type) > 1) {
            return substr($type, 0, -1);
        }
        return $type;
    }

    private function langPrefix(string $lang): string
    {
        $default = $this->settings['languages']['default'] ?? 'en';
        if ($lang === $default) {
            return '';
        }
        return $lang . '/';
    }

    private function slugify(string $value): string
    {
        return Slug::plain($value);
    }

    private function transliterateGreek(string $value): string
    {
        return Slug::transliterateGreek($value);
    }

    private function sanitizeType(string $value): string
    {
        $value = $this->slugify($value);
        return $value === '' ? 'pages' : $value;
    }

    private function defaultFrontmatter(string $type, string $slug): string
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

    private function titleFromSlug(string $slug): string
    {
        return Slug::title($slug);
    }

    private function sanitizeSettingsTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        $allowed = ['basics', 'menus', 'apis', 'smtp', 'auth', 'backup', 'updates', 'limits'];
        if (!in_array($tab, $allowed, true)) {
            return 'basics';
        }
        return $tab;
    }

    private function backupDirectory(): string
    {
        return $this->backups->directory();
    }

    /** @return array<int, array<string, mixed>> */
    private function listBackupSnapshots(): array
    {
        return $this->backups->list();
    }

    private function currentUsername(): string
    {
        return (string)($this->auth->user()['username'] ?? '');
    }

    /** The upload size and file kinds typed on the settings form, or nothing for what this person may not change. @return array{mb: ?int, types: ?string} */
    private function submittedUploadSettings(): array
    {
        return $this->permissions->can($this->auth->user(), 'limits.manage') ? SiteLimits::uploadSettingsFromForm($_POST) : ['mb' => null, 'types' => null];
    }

    private function downloadBackupSnapshot(string $filename, string $failureBase = '/admin/settings?tab=backup'): void
    {
        $path = $this->backups->pathFor($filename);
        if ($path === null) {
            $this->redirect($failureBase . (str_contains($failureBase, '?') ? '&' : '?') . 'backup=fail&backup_msg=' . urlencode('Backup file not found.'));
            return;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)(filesize($path) ?: 0));
        readfile($path);
        exit;
    }

    private function updateBackupLastRun(string $isoDate): void
    {
        $raw = $this->siteSettings()->raw('site_settings', $this->siteSettings()->defaults());
        $data = $this->siteSettings()->parse($raw);
        $data['backup']['auto']['last_run'] = $isoDate;
        $yaml = Yaml::dump($data, 4, 2);
        if ($this->systemDatabase->isAvailable()) {
            $this->setSystemMeta('site_settings', $yaml);
        }
        $this->settings = $this->siteSettings()->load();
    }

    /** @return array{fields: array, values: array, errors: array, success: bool, message: string, action: string, honeypot: string, redirect: string} */
    private function handleFormRequest(ContentItem $form, string $lang, string $currentPath): array
    {
        $fields = FormFields::normalize($form->meta['fields'] ?? []);
        $values = $this->formProcessor()->defaults($fields);
        $errors = [];
        $success = false;
        $message = (string)($form->meta['success_message'] ?? '');
        if ($message === '') {
            $message = $this->translate('form.success', 'Thanks! Your submission was received.');
        }
        $action = $this->buildContentPath('forms', $form->slug, $lang, $this->settings['home_page'] ?? 'index', $this->settings['languages']['default'] ?? 'en');
        $honeypot = (string)($form->meta['antispam']['honeypot'] ?? $this->settings['forms']['antispam']['honeypot'] ?? 'website');
        $rateLimit = (int)($form->meta['antispam']['rate_limit_seconds'] ?? $this->settings['forms']['antispam']['rate_limit_seconds'] ?? 0);
        $redirectDefault = (string)($form->meta['redirect_url'] ?? '');
        if ($redirectDefault === '') {
            $redirectDefault = '/' . ltrim($currentPath, '/');
        }

        if (isset($_GET['sent']) && (string)($_GET['form'] ?? '') === $form->slug) {
            $success = true;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return [
                'fields' => $fields,
                'values' => $values,
                'errors' => $errors,
                'success' => $success,
                'message' => $message,
                'action' => $action,
                'honeypot' => $honeypot,
                'redirect' => $redirectDefault,
            ];
        }

        if ((string)($_POST['form_slug'] ?? '') !== $form->slug) {
            return [
                'fields' => $fields,
                'values' => $values,
                'errors' => $errors,
                'success' => $success,
                'message' => $message,
                'action' => $action,
                'honeypot' => $honeypot,
                'redirect' => $redirectDefault,
            ];
        }

        if ($honeypot !== '' && trim((string)($_POST[$honeypot] ?? '')) !== '') {
            $success = true;
            return [
                'fields' => $fields,
                'values' => $values,
                'errors' => $errors,
                'success' => $success,
                'message' => $message,
                'action' => $action,
                'honeypot' => $honeypot,
                'redirect' => $redirectDefault,
            ];
        }

        if ($rateLimit > 0 && $this->isFormRateLimited($form->slug, $rateLimit)) {
            $errors['_form'] = $this->translate('form.error.rate_limit', 'Please wait before submitting again.');
            return [
                'fields' => $fields,
                'values' => $values,
                'errors' => $errors,
                'success' => $success,
                'message' => $message,
                'action' => $action,
                'honeypot' => $honeypot,
                'redirect' => $redirectDefault,
            ];
        }

        $collected = $this->formProcessor()->collect($fields, $_POST);
        $values = $collected['values'];
        $errors = $collected['errors'];
        if (!empty($errors)) {
            return [
                'fields' => $fields,
                'values' => $values,
                'errors' => $errors,
                'success' => $success,
                'message' => $message,
                'action' => $action,
                'honeypot' => $honeypot,
                'redirect' => $redirectDefault,
            ];
        }

        if ($this->isTruthy($form->meta['store_submissions'] ?? $this->settings['forms']['store_submissions'] ?? true)) {
            $this->formSubmissions->store($form->slug, $this->formProcessor()->record($form, $values, (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
        }

        $formUrl = $this->buildAbsoluteUrl($this->buildContentPath('forms', $form->slug, $form->lang, $this->settings['home_page'] ?? 'index', $this->settings['languages']['default'] ?? 'en'));
        $siteMail = is_array($this->settings['forms']['notifications'] ?? null) ? $this->settings['forms']['notifications'] : [];
        foreach ($this->formProcessor()->emails($form, $values, $fields, $siteMail, $formUrl) as $message) {
            $this->sendEmailMessage($message['to'], $message['subject'], $message['body'], $message['headers']);
        }
        $this->markFormRateLimit($form->slug);
        $success = true;

        $redirect = $this->sanitizeRedirectUrl((string)($_POST['redirect_url'] ?? ''));
        if ($redirect !== '') {
            $separator = str_contains($redirect, '?') ? '&' : '?';
            $this->redirect($redirect . $separator . 'form=' . urlencode($form->slug) . '&sent=1');
        }

        return [
            'fields' => $fields,
            'values' => $values,
            'errors' => $errors,
            'success' => $success,
            'message' => $message,
            'action' => $action,
            'honeypot' => $honeypot,
            'redirect' => $redirectDefault,
        ];
    }

    private function isFormRateLimited(string $slug, int $limitSeconds): bool
    {
        if ($limitSeconds <= 0) {
            return false;
        }
        $last = $_SESSION['form_rate'][$slug] ?? 0;
        return (time() - (int)$last) < $limitSeconds;
    }

    private function markFormRateLimit(string $slug): void
    {
        $_SESSION['form_rate'][$slug] = time();
    }

    private function cleanupImportPreviewCache(): void
    {
        if (!isset($_SESSION['content_import_preview']) || !is_array($_SESSION['content_import_preview'])) {
            return;
        }
        $now = time();
        foreach ($_SESSION['content_import_preview'] as $token => $row) {
            $created = is_array($row) ? (int)($row['created_at'] ?? 0) : 0;
            if ($created === 0 || ($now - $created) > 3600) {
                unset($_SESSION['content_import_preview'][$token]);
            }
        }
    }

    private function mailer(): Mailer
    {
        $config = $this->settings['forms']['notifications'] ?? [];
        return new Mailer(is_array($config) ? $config : []);
    }

    private function sendEmailMessage(string $to, string $subject, string $body, array $headers): bool
    {
        $result = $this->mailer()->send($to, $subject, $body, $headers);
        $this->logEmailAttempt($to, $subject, $result['provider'], $result['ok'], $result['error'], [
            'driver_setting' => (string)($this->settings['forms']['notifications']['driver'] ?? ''),
            'from' => (string)($headers['From'] ?? ''),
            'reply_to' => (string)($headers['Reply-To'] ?? ''),
            'cc' => (string)($headers['Cc'] ?? ''),
            'bcc' => (string)($headers['Bcc'] ?? ''),
        ]);
        return $result['ok'];
    }

    private function applyShortcodes(string $html, string $lang, string $currentPath): string
    {
        if (str_contains($html, '[form') === false) {
            return $html;
        }

        $pattern = '/<p>\\s*\\[form\\s+([^\\]]+)\\]\\s*<\\/p>|\\[form\\s+([^\\]]+)\\]/i';
        $callback = function (array $matches) use ($lang, $currentPath): string {
            $raw = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');
            // Markdown HTML output may entity-encode quotes (&quot;), so decode before parsing attributes.
            $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $attrs = $this->parseShortcodeAttributes($raw);
            $slug = $this->slugify((string)($attrs['slug'] ?? ''));
            if ($slug === '') {
                return $matches[0];
            }
            return $this->renderFormEmbedBySlug($slug, $lang, $currentPath);
        };

        $result = preg_replace_callback($pattern, $callback, $html);
        return $result ?? $html;
    }

    private function renderFormEmbedBySlug(string $slug, string $lang, string $currentPath): string
    {
        $form = $this->content->find('forms', $slug, $lang, $this->auth->check(), true);
        if (!$form) {
            return '';
        }
        $fields = FormFields::normalize($form->meta['fields'] ?? []);
        $values = $this->formProcessor()->defaults($fields);
        $errors = [];
        $success = isset($_GET['sent']) && (string)($_GET['form'] ?? '') === $form->slug;
        $message = (string)($form->meta['success_message'] ?? '');
        if ($message === '') {
            $message = $this->translate('form.success', 'Thanks! Your submission was received.');
        }
        if (isset($this->formStates[$form->slug])) {
            $state = $this->formStates[$form->slug];
            $values = $state['values'] ?? $values;
            $errors = $state['errors'] ?? $errors;
            $success = $state['success'] ?? $success;
            $message = $state['message'] ?? $message;
        }
        $action = '/' . ltrim($currentPath, '/');
        if ($action === '/') {
            $action = '';
        }
        $honeypot = (string)($form->meta['antispam']['honeypot'] ?? $this->settings['forms']['antispam']['honeypot'] ?? 'website');
        $redirect = (string)($form->meta['redirect_url'] ?? '');
        if ($redirect === '') {
            $redirect = '/' . ltrim($currentPath, '/');
        }
        return $this->twig->render('components/form.twig', [
            'form' => $form,
            'form_fields' => $fields,
            'form_values' => $values,
            'form_errors' => $errors,
            'form_success' => $success,
            'form_message' => $message,
            'form_action' => $action,
            'form_honeypot' => $honeypot,
            'form_redirect' => $redirect,
        ]);
    }

    /** @return array<string, string> */
    private function parseShortcodeAttributes(string $raw): array
    {
        $attrs = [];
        if (preg_match_all('/(\\w+)\\s*=\\s*("([^"]*)"|\\\'([^\\\']*)\\\'|([^\\s]+))/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = $match[3] !== '' ? $match[3] : ($match[4] !== '' ? $match[4] : $match[5]);
                $attrs[$key] = $value;
            }
        }
        return $attrs;
    }

    private function sanitizeRedirectUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return '';
        }
        if (str_starts_with($url, 'http')) {
            $parts = parse_url($url);
            $base = trim((string)($this->settings['base_url'] ?? ''));
            $baseHost = $base !== '' ? parse_url($base, PHP_URL_HOST) : '';
            if ($baseHost && isset($parts['host']) && $parts['host'] !== $baseHost) {
                return '';
            }
            return $url;
        }
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }
        return $url;
    }

    private function buildFilename(string $slug, string $lang): string
    {
        return (new ContentPaths($this->settings))->filename($slug, $lang);
    }

    /** @return array{0: string, 1: string} */
    private function splitFrontMatter(string $raw): array
    {
        return FrontMatter::split($raw);
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}

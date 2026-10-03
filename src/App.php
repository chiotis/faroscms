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
    private ?Sitemap $sitemapService = null;
    private ?TaxonomyPage $taxonomyPageService = null;
    private ?PublicForms $publicFormsService = null;
    private ?SettingsAdmin $settingsAdminService = null;
    private ?AdminChrome $adminChromeService = null;
    private ?UpdateInstaller $updateInstallerService = null;
    private ?FirstAdmin $firstAdminService = null;
    private ?MenuAdmin $menuAdminService = null;
    /** @var array<string, array<string, string>> the theme's text by language, for the labels the menu editor leaves to the theme */
    private array $menuStrings = [];
    private ?LogAdmin $logAdminService = null;
    private ?AdminNotices $adminNoticesService = null;
    private ?UpdateAdmin $updateAdminService = null;
    private ?RevisionAdmin $revisionAdminService = null;
    private ?ContentAdmin $contentAdminService = null;
    private ?EntryForm $entryFormService = null;
    private ?ContentTransfer $contentTransferService = null;
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
    private ?SingleLayouts $singleLayoutsService = null;
    private ?LayoutsAdmin $layoutsAdminService = null;
    private ?ArchiveBuilder $archiveBuilderService = null;
    private ?BackupAdmin $backupAdminService = null;
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
        $this->content = new ContentRepository($this->contentDir, $markdown, $this->settings, fn(): array => $this->contentTypes()->catalogue());
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

        // While an update replaces the code the public site says so; the admin stays open.
        if (!str_starts_with($path, 'admin') && (new MaintenanceMode($this->basePath))->blocks((string)($_SERVER['HTTP_X_FAROS_UPDATE'] ?? ''))) {
            http_response_code(503);
            header('Retry-After: 60');
            header('Cache-Control: no-store');
            header('Content-Type: text/html; charset=utf-8');
            echo MaintenanceMode::page();
            return;
        }

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

    private function revisionAdmin(): RevisionAdmin
    {
        return $this->revisionAdminService ??= new RevisionAdmin(
            $this->revisions,
            $this->contentEditor(),
            $this->content,
            $this->contentDir,
            fn(): array => $this->settings,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function contentAdmin(): ContentAdmin
    {
        return $this->contentAdminService ??= new ContentAdmin(
            $this->contentDir,
            $this->content,
            $this->contentIndex,
            $this->contentTypes(),
            $this->contentEditor(),
            $this->entryTranslations(),
            $this->revisions,
            $this->redirects,
            fn(): array => $this->settings,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): PublicPaths => $this->publicPaths(),
            fn(): LinkScanner => $this->linkScanner(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function entryForm(): EntryForm
    {
        return $this->entryFormService ??= new EntryForm(
            $this->contentDir,
            $this->content,
            $this->contentTypes(),
            $this->contentEditor(),
            $this->entryTranslations(),
            $this->formsAdmin(),
            $this->redirects,
            $this->revisions,
            $this->revisionAdmin(),
            $this->theme,
            fn(): array => $this->settings,
            fn(): array => $this->themeSettings,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): LinkScanner => $this->linkScanner(),
            fn(): BlockRegistry => $this->blockRegistry(),
            fn(): PresetLibrary => $this->presetLibrary(),
            fn(string $path): string => $this->buildAbsoluteUrl($path)
        );
    }

    private function contentTransfer(): ContentTransfer
    {
        return $this->contentTransferService ??= new ContentTransfer(
            $this->contentCsv(),
            $this->content,
            fn(): array => $this->rebuildContentIndex(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function menuAdmin(): MenuAdmin
    {
        return $this->menuAdminService ??= new MenuAdmin(
            $this->menus(),
            fn(): array => $this->settings,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context),
            new MenuSources($this->content, fn(): Taxonomies => $this->taxonomies(), fn(): array => $this->settings),
            function (string $key, string $lang): string {
                $this->menuStrings[$lang] ??= $this->loadTranslations($lang);
                return (string)($this->menuStrings[$lang][$key] ?? '');
            },
            $this->permissions->can($this->auth->user(), 'settings.manage')
                ? fn(string $location, string $menu): bool => $this->siteSettings()->setMenuLocation($location, $menu)
                : null
        );
    }

    private function logAdmin(): LogAdmin
    {
        return $this->logAdminService ??= new LogAdmin(
            $this->activityLogs,
            $this->emailLogs,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function adminNotices(): AdminNotices
    {
        return $this->adminNoticesService ??= new AdminNotices(
            $this->notifications,
            fn(): UpdateService => $this->updates(),
            fn(): array => $this->systemStatus()->checks($this->siteLimits()->summary())
        );
    }

    private function updateInstaller(): UpdateInstaller
    {
        return $this->updateInstallerService ??= new UpdateInstaller(
            $this->basePath,
            $this->systemDatabase,
            new MaintenanceMode($this->basePath),
            fn(string $url, string $destination, int $maxBytes): ?string => UpdateNetwork::download($url, $destination, $maxBytes),
            fn(string $token, string $version): array => UpdateNetwork::checkSite($this->getBaseUrl(), $token),
            fn(string $level, string $message, array $context) => $this->logActivity('updates.install_step', $level, 'updates', 'install', $message, $context)
        );
    }

    private function updateAdmin(): UpdateAdmin
    {
        return $this->updateAdminService ??= new UpdateAdmin(
            fn(): UpdateService => $this->updates(),
            $this->adminNotices(),
            fn(): BackupAdmin => $this->backupAdmin(),
            fn(): UpdateInstaller => $this->updateInstaller(),
            $this->systemMeta,
            $this->backups,
            $this->contentDir,
            $this->basePath,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function sitemap(): Sitemap
    {
        return $this->sitemapService ??= new Sitemap(
            $this->content,
            fn(): array => $this->settings,
            fn(string $path): string => $this->buildAbsoluteUrl($path)
        );
    }

    private function taxonomyPage(): TaxonomyPage
    {
        return $this->taxonomyPageService ??= new TaxonomyPage(
            $this->content,
            $this->theme,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): ArchiveBuilder => $this->archiveBuilder(),
            fn(): LanguageAlternates => $this->languageAlternates(),
            fn(): array => $this->settings,
            fn(string $key, ?string $fallback = null): string => $this->translate($key, $fallback)
        );
    }

    private function publicForms(): PublicForms
    {
        return $this->publicFormsService ??= new PublicForms(
            $this->formProcessor(),
            $this->formSubmissions,
            fn(): array => $this->settings,
            fn(string $key, ?string $fallback = null): string => $this->translate($key, $fallback),
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(string $to, string $subject, string $body, array $headers) => $this->sendEmailMessage($to, $subject, $body, $headers),
            fn(string $slug, int $seconds): bool => (time() - (int)($_SESSION['form_rate'][$slug] ?? 0)) < $seconds,
            function (string $slug): void {
                $_SESSION['form_rate'][$slug] = time();
            }
        );
    }

    private function settingsAdmin(): SettingsAdmin
    {
        return $this->settingsAdminService ??= new SettingsAdmin(
            $this->siteSettings(),
            $this->media,
            fn(): SiteLimits => $this->siteLimits(),
            fn(): BackupManager => $this->backupManager(),
            fn(): array => $this->settings,
            function (array $settings): void {
                $this->settings = $settings;
            },
            function (?array $input): array {
                $result = $input !== null ? $this->saveThemeSettings($input) : ['ok' => true];
                $this->themeSettings = $this->loadThemeSettings();
                return $result;
            },
            fn(string $to, string $subject, string $body, array $headers): bool => $this->sendEmailMessage($to, $subject, $body, $headers),
            fn(string $isoDate) => $this->updateBackupLastRun($isoDate),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function adminChrome(): AdminChrome
    {
        return $this->adminChromeService ??= new AdminChrome(
            $this->notifications,
            $this->users,
            fn(): UpdateService => $this->updates(),
            fn(): SiteLimits => $this->siteLimits(),
            fn(): AdminNotices => $this->adminNotices(),
            fn(): array => $this->settings
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
        $route = FrontRoute::resolve($path, $this->settings, $this->content->getTypes(), array_values(array_filter($this->taxonomies()->names(), [Taxonomies::class, 'isCustom'])));
        if ($route['kind'] === 'sitemap') {
            $this->renderSitemap();
            return;
        }
        if ($route['kind'] === 'robots') {
            $this->renderRobots();
            return;
        }

        $lang = $route['lang'];
        $segments = $route['segments'];
        $homeSlug = $route['home_slug'];
        $this->setLanguage($lang);
        $includeHidden = $this->auth->check();
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
            'lang_prefix' => $route['lang_prefix'],
            'current_lang' => $lang,
            'path_no_lang' => $route['path_no_lang'],
            'canonical_url' => $currentUrl,
            'theme_menus' => $this->menus()->forTheme($lang, $route['path_no_lang']),
        ];

        if ($route['kind'] === 'not_found') {
            $this->render404();
            return;
        }

        if ($route['kind'] === 'legacy_page') {
            header('Location: ' . $route['location'], true, 301);
            exit;
        }

        if ($route['kind'] === 'taxonomy') {
            $this->handleTaxonomy($segments, $lang, $viewDefaults);
            return;
        }

        if ($route['kind'] === 'search') {
            $query = (string)($_GET['q'] ?? '');
            $results = $this->content->search($query, $lang, $includeHidden);
            $this->render('templates/search.twig', [
                'query' => $query,
                'results' => $results,
            ] + $viewDefaults);
            return;
        }

        if ($route['kind'] === 'archive' || $route['kind'] === 'entry') {
            $type = $route['type'];
            $slug = $route['slug'];
            if ($route['kind'] === 'archive') {
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

        $slug = $route['slug'];
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
        return $this->archiveBuilder()->build($definition['archive'], $definition['fields'], $definition, $lang, $items, $_GET);
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

    /** Admin addresses served by one handler each, by the first part of the address after /admin. */
    private const ADMIN_ROUTES = [
        'theme' => 'handleTheme',
        'settings' => 'handleSettings',
        'index' => 'handleDashboard',
        'dashboard' => 'handleDashboard',
        'content' => 'handleAdminList',
        'content-bulk' => 'handleContentBulk',
        'search' => 'handleAdminSearch',
        'system' => 'handleSystem',
        'activity-logs' => 'handleActivityLogs',
        'email-logs' => 'handleEmailLogs',
        'backups' => 'handleBackups',
        'updates' => 'handleUpdates',
        'notification-read' => 'handleNotificationRead',
        'notifications-read-all' => 'handleNotificationsReadAll',
        'menus' => 'handleMenusList',
        'menus-new' => 'handleMenusNew',
        'menus-edit' => 'handleMenus',
        'media' => 'handleMedia',
        'forms' => 'handleFormsList',
        'forms-new' => 'handleFormsNew',
        'form-submissions' => 'handleFormSubmissions',
        'forms-export' => 'handleFormsExport',
        'import' => 'handleContentImport',
        'export' => 'handleContentExport',
        'translations' => 'handleTranslations',
        'content-types' => 'handleContentTypes',
        'taxonomies' => 'handleTaxonomies',
        'roles' => 'handleRoles',
        'redirects' => 'handleRedirects',
        'revisions' => 'handleRevisions',
        'links' => 'handleLinks',
        'users' => 'handleUsersList',
        'users-edit' => 'handleUserEdit',
        'users-delete' => 'handleUserDelete',
        'edit' => 'handleEdit',
        'save' => 'handleSave',
        'block-presets' => 'handleBlockPresets',
        'media-picker' => 'handleMediaPicker',
        'delete' => 'handleDelete',
        'new' => 'handleNew',
    ];

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

        if ($action === 'login' && !$this->auth->check() && $this->firstAdmin()->needed()) {
            $this->handleSetup();
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

        if (isset(self::ADMIN_ROUTES[$action])) {
            $this->{self::ADMIN_ROUTES[$action]}();
            return;
        }

        if ($action === 'files') {
            $this->redirect('/admin/media?type=document&view=list');
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

    /** A site with no accounts: the first visit makes the first administrator. */
    private function handleSetup(): void
    {
        $payload = ['username' => '', 'display_name' => '', 'email' => ''];
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = $this->firstAdmin()->create($_POST);
            if ($result['ok']) {
                $this->redirect('/admin');
                return;
            }
            $payload = $result['payload'];
            $error = $result['error'];
        }
        $this->render('@admin/setup.twig', ['error' => $error, 'payload' => $payload]);
    }

    private function firstAdmin(): FirstAdmin
    {
        return $this->firstAdminService ??= new FirstAdmin(
            $this->users,
            $this->auth,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
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

    private function handleRevisions(): void
    {
        $seesForms = $this->permissions->can($this->auth->user(), 'forms.manage');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $this->revisionAdmin()->restore($_POST, $this->permissions->can($this->auth->user(), 'content.raw_html'), $seesForms, $this->currentUsername());
            if ($result['denied'] !== '') {
                $this->denyContentType($result['denied']);
                return;
            }
            $this->redirect($result['location']);
            return;
        }

        $screen = $this->revisionAdmin()->screen($_GET, $seesForms);
        if ($screen['denied'] !== '') {
            $this->denyContentType($screen['denied']);
            return;
        }
        if ($screen['location'] !== '') {
            $this->redirect($screen['location']);
            return;
        }
        $this->render($screen['template'], $screen['data'] + ['user' => $this->auth->user()]);
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
        $this->render('@admin/activity-logs.twig', $this->logAdmin()->activity($_GET) + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleEmailLogs(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string)($_POST['email_log_action'] ?? '')) === 'clear') {
            $this->redirect('/admin/email-logs?cleared=' . $this->logAdmin()->clearEmail());
            return;
        }
        $this->render('@admin/email-logs.twig', $this->logAdmin()->email($_GET) + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
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
            if ($action === 'restore') {
                $this->handleBackupRestore();
                return;
            }
            $location = $this->backupAdmin()->apply($action, $_POST);
            if ($location !== null) {
                $this->redirect($location);
                return;
            }
        }

        $this->render('@admin/backups.twig', $this->backupAdmin()->overview() + [
            'title' => 'Backups',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'backups',
            'current_type' => 'pages',
            'backup_status' => (string)($_GET['backup'] ?? ''),
            'delete_status' => (string)($_GET['deleted'] ?? ''),
            'backup_message' => trim((string)($_GET['backup_msg'] ?? '')),
        ]);
    }

    private function backupAdmin(): BackupAdmin
    {
        return $this->backupAdminService ??= new BackupAdmin(
            $this->backups,
            $this->backupManager(),
            $this->backupRuns,
            $this->notifications,
            $this->systemMeta,
            fn(): array => $this->settings,
            fn(string $isoDate) => $this->updateBackupLastRun($isoDate),
            function (bool $ok): void {
                $this->settings = $this->siteSettings()->load();
                $this->themeSettings = $this->loadThemeSettings();
                $this->menus()->forget();
                $this->taxonomies()->forget();
                if ($ok) {
                    $this->rebuildContentIndex();
                }
            },
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context, ?array $actor) => $this->logActivity($action, $level, $type, $id, $message, $context, $actor)
        );
    }

    private function renderBackupRestore(string $filename, string $error = '', array $selected = []): void
    {
        if (!$this->permissions->can($this->auth->user(), 'backups.restore')) {
            $this->renderForbidden('Restoring backups is limited to superadmins.');
            return;
        }
        $screen = $this->backupAdmin()->restoreScreen($filename, $selected);
        if ($screen === null) {
            $this->redirect('/admin/backups?' . http_build_query(['backup' => 'fail', 'backup_msg' => 'Backup file not found.']));
            return;
        }

        $this->render('@admin/backup-restore.twig', $screen + [
            'title' => 'Restore backup',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'backups',
            'current_type' => 'pages',
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
        $outcome = $this->backupAdmin()->restore($_POST, $this->auth->user());
        if ($outcome['location'] !== null) {
            $this->redirect($outcome['location']);
            return;
        }
        $this->renderBackupRestore($outcome['filename'], $outcome['error'], $outcome['selected']);
    }

    private function handleUpdates(): void
    {
        $admin = $this->updateAdmin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = trim((string)($_POST['updates_action'] ?? ''));
            if ($action === 'pre_update_backup') {
                if (!$this->permissions->can($this->auth->user(), 'backups.manage')) {
                    $this->renderForbidden();
                    return;
                }
                $this->redirect($admin->backupFirst());
                return;
            }
            if ($action === 'check') {
                $this->redirect($admin->check());
                return;
            }
            if ($action === 'install') {
                $this->redirect($admin->install());
                return;
            }
            if ($action === 'rollback') {
                $this->redirect($admin->rollback());
                return;
            }
        }
        $this->render('@admin/updates.twig', $admin->screen($_GET) + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
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
        $result = $this->contentAdmin()->listing($_GET, $this->permissions->can($this->auth->user(), 'redirects.manage'));
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/list.twig', $result['data'] + ['user' => $this->auth->user()]);
    }

    private function handleContentBulk(): void
    {
        $this->redirect($this->contentAdmin()->bulk(
            $_POST,
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST',
            $this->permissions->can($this->auth->user(), 'redirects.manage'),
            $this->currentUsername()
        ));
    }

    private function handleEdit(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }
        $slug = Slug::plain((string)($_GET['slug'] ?? ''));
        $lang = Slug::plain((string)($_GET['lang'] ?? $this->defaultLanguage()));
        $this->render($type === 'forms' ? '@admin/form-edit.twig' : '@admin/edit.twig', $this->entryForm()->form($type, $slug, $lang, $_GET, $this->permissions->can($this->auth->user(), 'redirects.manage')) + ['user' => $this->auth->user()]);
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
        $main = $this->mediaAdmin()->uploadMainImage($_FILES['main_image_upload'] ?? null, $this->currentUsername());
        $uploadedImage = $main['url'];
        $storageBlocked = $main['blocked'];

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

    private function handleDelete(): void
    {
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        $source = $post ? $_POST : $_GET;
        $type = $this->sanitizeType((string)($source['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }
        $result = $this->contentAdmin()->delete(
            $type,
            $source,
            $post,
            $this->permissions->can($this->auth->user(), 'redirects.manage'),
            $this->permissions->can($this->auth->user(), 'content.manage'),
            $this->currentUsername()
        );
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/delete.twig', $result['view'] + ['user' => $this->auth->user()]);
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
        $template = $this->slugify((string)($_GET['template'] ?? ''));
        if ($type === 'forms' && $slug === '' && $template === '') {
            // A new form starts from a ready-made one, or from nothing.
            $this->redirect('/admin/forms-new?lang=' . urlencode($lang));
            return;
        }

        $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . ($template !== '' ? '&template=' . urlencode($template) : ''));
    }

    private function handleFormsNew(): void
    {
        $languages = array_map('strval', $this->settings['languages']['available'] ?? [$this->defaultLanguage()]);
        $lang = $this->slugify((string)($_GET['lang'] ?? $this->defaultLanguage()));
        if (!in_array($lang, $languages, true)) {
            $lang = $this->defaultLanguage();
        }
        $this->render('@admin/forms-new.twig', [
            'templates' => FormTemplates::all($lang),
            'lang' => $lang,
            'languages' => $languages,
            'admin_section' => 'forms',
            'current_type' => 'forms',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
        ]);
    }

    private function handleSettings(): void
    {
        $admin = $this->settingsAdmin();
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (strtolower((string)($_GET['tab'] ?? '')) === 'theme' && !$post) {
            // The theme has its own page now.
            $this->redirect('/admin/theme');
            return;
        }
        $downloadBackup = trim((string)($_GET['download_backup'] ?? ''));
        if ($downloadBackup !== '') {
            $this->downloadBackupSnapshot($downloadBackup);
            return;
        }
        $canLimits = $this->permissions->can($this->auth->user(), 'limits.manage');
        if ($post && (string)($_POST['storage_action'] ?? '') === 'recalculate') {
            $this->redirect($admin->recalculateStorage($canLimits));
            return;
        }
        if ($post) {
            $upload = $this->submittedUploadSettings();
            $this->redirect($admin->save($_POST, [
                'storage_limit_mb' => $this->submittedStorageLimit(),
                'upload_limit_mb' => $upload['mb'],
                'upload_types' => $upload['types'],
            ], SettingsAdmin::tab((string)($_GET['tab'] ?? 'basics'))));
            return;
        }
        $this->render('@admin/settings.twig', $admin->screen($_GET, $canLimits, $this->listBackupSnapshots()) + [
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
        ]);
    }

    /**
     * Theme settings: one screen, a tab for each section the theme declares, and two tabs that gather how pages look: Single
     * Layouts (a card for each content type) and Archive Layouts (a card for each list of entries).
     */
    private function handleTheme(): void
    {
        $schema = $this->theme->settingsSchema();
        $single = $this->singleLayouts();
        $tabs = [];
        // A theme that declares the design section has the Branding screen: appearance, brand and design in one tab.
        $branding = isset($schema['design']);
        foreach ($schema as $key => $section) {
            // The sidebar card is part of Single Layouts when the theme has them.
            if ($key === 'sidebar' && $single->declared()) {
                continue;
            }
            if ($branding && in_array($key, ['appearance', 'brand', 'design'], true)) {
                if (!in_array('branding', $tabs, true)) {
                    $tabs[] = 'branding';
                }
                continue;
            }
            if (array_filter($section['fields'], static fn(array $field): bool => !$field['hidden']) !== []) {
                $tabs[] = $key;
            }
        }
        // The two tabs that gather how pages look come after the header's.
        $at = array_search('header', $tabs, true);
        array_splice($tabs, $at === false ? min(1, count($tabs)) : $at + 1, 0, $single->declared() ? ['single_layouts', 'archive_layouts'] : ['archive_layouts']);
        $activeTab = (string)($_GET['tab'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // The live preview of Branding asks what the unsaved choices make of the pages (nothing is stored).
            if ($branding && ($_GET['preview'] ?? '') === 'branding') {
                $this->previewBranding(is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : []);
                return;
            }
            $activeTab = (string)($_POST['active_tab'] ?? $activeTab);
            $save = $this->saveThemeSettings(
                is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : [],
                is_array($_POST['single_layouts'] ?? null) ? $_POST['single_layouts'] : null
            );
            $this->themeSettings = $this->loadThemeSettings();
            if (($save['ok'] ?? false) === true && (is_array($_POST['archive_types'] ?? null) || is_array($_POST['archive_taxonomies'] ?? null))) {
                $failed = $this->layoutsAdmin()->saveArchives(
                    is_array($_POST['archive_types'] ?? null) ? $_POST['archive_types'] : [],
                    is_array($_POST['archive_taxonomies'] ?? null) ? $_POST['archive_taxonomies'] : [],
                    $this->defaultLanguage()
                );
                if ($failed !== []) {
                    $save = ['ok' => false, 'message' => 'Could not write the archive layout of ' . implode(', ', $failed) . '. Check that the custom/ folder is writable.'];
                }
            }
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
        $default = $this->defaultLanguage();
        $this->render('@admin/theme.twig', [
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'theme',
            'saved' => isset($_GET['saved']),
            'theme_status' => (string)($_GET['theme'] ?? ''),
            'theme_message' => trim((string)($_GET['theme_msg'] ?? '')),
            'theme_schema' => $schema,
            'theme_tabs' => $tabs,
            'tab_labels' => ['branding' => 'Branding', 'single_layouts' => 'Single Layouts', 'archive_layouts' => 'Archive Layouts'],
            'branding' => $branding ? $this->brandingScreen() : null,
            'theme_values' => $this->themeSettings,
            'theme_info' => [
                'name' => $this->theme->name(),
                'label' => $this->theme->label(),
                'version' => $this->theme->version(),
                'custom_dir' => is_dir($this->theme->customPath()),
            ],
            'active_tab' => $activeTab,
            'single_declared' => $single->declared(),
            'single_fields' => $single->fields(),
            'single_templates' => $single->templates(),
            'single_cards' => $single->declared() ? $this->layoutsAdmin()->singleCards($this->themeSettings, $default) : [],
            'archive_cards' => $this->layoutsAdmin()->archiveCards($default),
            'archive_layouts' => ContentTypes::LAYOUTS,
            'archive_columns' => ['2', '3', '4'],
            'sidebar_section' => $schema['sidebar'] ?? null,
        ]);
    }

    /** What the Branding tab needs besides the settings: the font stacks, the address of the preview, the state of the font file. */
    private function brandingScreen(): array
    {
        $file = trim((string)($this->themeSettings['design']['font_file'] ?? ''));
        $found = null;
        if ($file !== '' && !preg_match('#^(https?://|/)#i', $file) && !str_contains($file, '..')) {
            $found = is_file($this->theme->customPath() . '/assets/' . ltrim($file, '/'));
        }
        return [
            'stacks' => Branding::FONT_STACKS,
            'preview_url' => (string)($this->settings['base_url'] ?? '') . '/',
            'font_found' => $found,
        ];
    }

    /** JSON for the live preview of the Branding tab: the CSS and attributes the submitted choices would give a page. */
    private function previewBranding(array $input): void
    {
        $data = $this->theme->resolveSettings($this->theme->settingsFromInput($input, $this->loadThemeSettings()));
        $base = (string)($this->settings['base_url'] ?? '');
        $brand = is_array($data['brand'] ?? null) ? $data['brand'] : [];
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'css' => Branding::css($data, $base),
            'attributes' => Branding::attributes($data),
            'logo' => (string)($brand['logo'] ?? ''),
            'logo_dark' => (string)($brand['logo_dark'] ?? ''),
            'show_name' => ($brand['show_name'] ?? false) === true,
            'name' => (string)($this->settings['title'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }

    private function starterContent(): StarterContent
    {
        return new StarterContent($this->basePath, $this->contentDir, $this->basePath . '/public/uploads');
    }

    private function singleLayouts(): SingleLayouts
    {
        return $this->singleLayoutsService ??= new SingleLayouts($this->theme, $this->contentTypes());
    }

    private function layoutsAdmin(): LayoutsAdmin
    {
        return $this->layoutsAdminService ??= new LayoutsAdmin(
            $this->theme,
            $this->singleLayouts(),
            $this->contentTypes(),
            $this->contentTypeAdmin(),
            $this->taxonomies(),
            $this->taxonomyEditor(),
            fn(): array => $this->content->getTypes(),
            fn(string $type): int => count($this->content->getItems($type, null, true)),
            fn(string $type): string => $this->singularizeType($type)
        );
    }

    private function handleMenusList(): void
    {
        $this->render('@admin/menus-list.twig', $this->menuAdmin()->overview($_GET) + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleMenusNew(): void
    {
        $result = $this->menuAdmin()->create($_GET, $_POST, $_SERVER['REQUEST_METHOD'] === 'POST');
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/menus-new.twig', $result['view'] + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleMenus(): void
    {
        $result = $this->menuAdmin()->edit($_GET, $_POST, $_SERVER['REQUEST_METHOD'] === 'POST');
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/menus.twig', $result['view'] + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleTaxonomies(): void
    {
        $default = $this->defaultLanguage();
        $languages = array_map('strval', $this->settings['languages']['available'] ?? [$default]);
        $editor = $this->taxonomyEditor();
        $taxonomy = $editor->selected((string)($_GET['taxonomy'] ?? $_POST['taxonomy'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['delete_taxonomy'])) {
                $gone = $editor->delete($taxonomy, $this->currentUsername());
                $this->redirect($gone['ok'] ? '/admin/taxonomies?deleted=' . urlencode($gone['title']) : '/admin/taxonomies?taxonomy=' . urlencode($taxonomy));
                return;
            }
            if (isset($_POST['new_taxonomy'])) {
                $made = $editor->create(
                    (string)($_POST['new_title'] ?? ''),
                    (string)($_POST['new_name'] ?? ''),
                    is_array($_POST['new_types'] ?? null) ? $_POST['new_types'] : [],
                    $languages,
                    (string)($this->settings['home_page'] ?? 'index'),
                    $this->currentUsername()
                );
                $this->redirect($made['error'] === ''
                    ? '/admin/taxonomies?taxonomy=' . urlencode($made['name']) . '&created=1'
                    : '/admin/taxonomies?taxonomy=' . urlencode($taxonomy) . '&new_error=' . urlencode($made['error']) . '&new=1');
                return;
            }
            $this->redirect($editor->save($taxonomy, $_POST, $languages, $default, $this->currentUsername()));
            return;
        }

        $this->render('@admin/taxonomies.twig', $editor->screen(
            $taxonomy,
            $_GET,
            $languages,
            $default,
            $this->permissions->can($this->auth->user(), 'redirects.manage'),
            fn(string $type): string => $this->contentTypes()->definition($type, 'en', $default)['label']
        ) + ['user' => $this->auth->user()]);
    }

    private function taxonomyEditor(): TaxonomyEditor
    {
        return $this->taxonomyEditorService ??= new TaxonomyEditor(
            $this->taxonomies(),
            $this->redirects,
            $this->content,
            fn(): Menus => $this->menus(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
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

    /** @return array{ok: bool, indexed: int, removed: int, took_ms: int} */
    private function rebuildContentIndex(): array
    {
        try {
            // A fresh repository avoids this request's cached listings.
            $repository = new ContentRepository($this->contentDir, $this->markdownConverter(), $this->settings, fn(): array => $this->contentTypes()->catalogue());
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

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['system_action'] ?? '') === 'add_demo') {
            $result = $this->starterContent()->add(is_array($_POST['demo'] ?? null) ? array_map('strval', $_POST['demo']) : []);
            if ($result['added'] > 0) {
                $this->taxonomies()->forget();
                $this->rebuildContentIndex();
                $this->logActivity('system.demo_content', 'info', 'system', 'demo_content', 'Demo content added.', $result);
            }
            $this->redirect('/admin/system?' . http_build_query(['demo' => $result['failed'] > 0 ? 'fail' : 'ok', 'demo_added' => $result['added'], 'demo_failed' => $result['failed']]));
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
            'demo_groups' => $this->starterContent()->available() ? $this->starterContent()->groups() : [],
            'demo' => (string)($_GET['demo'] ?? ''),
            'demo_added' => (int)($_GET['demo_added'] ?? 0),
            'demo_failed' => (int)($_GET['demo_failed'] ?? 0),
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
        if (!in_array($type, $this->content->getTypes(), true) || $type === 'forms') {
            $this->redirect('/admin/content?type=' . urlencode($type));
            return;
        }

        $file = $this->contentTransfer()->export($type, (string)($this->settings['title'] ?? 'site'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }

        fputcsv($output, $file['headers'], ',', '"', '');
        foreach ($file['rows'] as $row) {
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
        if ($type === 'forms') {
            $this->redirect('/admin/content?type=forms');
            return;
        }

        if (!isset($_SESSION['content_import_preview']) || !is_array($_SESSION['content_import_preview'])) {
            $_SESSION['content_import_preview'] = [];
        }
        $result = $this->contentTransfer()->import($type, $_SERVER['REQUEST_METHOD'] === 'POST', $_POST, is_array($_FILES['csv_file'] ?? null) ? $_FILES['csv_file'] : null, $_SESSION['content_import_preview']);
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/import.twig', $result['view'] + [
            'type' => $type,
            'types' => $types,
            'user' => $this->auth->user(),
            'admin_section' => 'content',
            'current_type' => $type,
            'saved' => isset($_GET['saved']),
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
            $action = (string)($_POST['action'] ?? 'save');
            $this->redirect($action === 'toggle' ? $admin->toggle($_POST) : ($action === 'create' ? $admin->create($_POST, $manageable) : $admin->update($_POST, $manageable, $default)));
            return;
        }

        $selected = $this->slugify((string)($_GET['type'] ?? ''));
        $common = [
            'saved' => isset($_GET['saved']),
            'error' => (string)($_GET['error'] ?? ''),
            'custom_file' => 'custom/content-types/',
            'admin_section' => 'content-types',
            // The admin menu lists the types under Content on every screen.
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
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

        $this->render('@admin/content-types.twig', [
            'types_list' => $admin->typeRows($manageable, $default),
            'toggled' => (string)($_GET['toggled'] ?? ''),
            'toggled_type' => $this->slugify((string)($_GET['type_name'] ?? '')),
        ] + $common);
    }

    private function contentTypeAdmin(): ContentTypeAdmin
    {
        return $this->contentTypeAdminService ??= new ContentTypeAdmin(
            $this->contentTypes(),
            $this->contentDir,
            fn(): array => $this->taxonomies()->names(),
            fn(string $key): bool => $this->isReservedFrontmatterKey($key),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context),
            fn(string $type, bool $on): bool => $this->siteSettings()->setContentType($type, $on)
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

        TwigFunctions::register(
            $twig,
            (string)($this->settings['base_url'] ?? ''),
            $this->theme,
            $this->images,
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(): StructuredData => $this->structuredData(),
            $this->basePath . '/public'
        );

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

        // What the page of one entry of a type does (Theme > Single Layouts): title style, header, parts, sidebar.
        $twig->addFunction(new TwigFunction('single_layout', function (string $type): array {
            return $this->singleLayouts()->forType($type, $this->themeSettings);
        }));

        $twig->addFunction(new TwigFunction('content_type', function (string $type): array {
            return $this->contentTypes()->definition($type, $this->currentLang, $this->defaultLanguage());
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

        // The terms an entry has in the taxonomies a site added (categories and tags are drawn by the templates themselves):
        // [{name, title, terms: [{label, url}]}], each term with a page.
        $twig->addFunction(new TwigFunction('item_terms', function (mixed $item, string $prefix = ''): array {
            $meta = is_object($item) && isset($item->meta) && is_array($item->meta) ? $item->meta : [];
            $groups = [];
            foreach ($this->taxonomies()->names() as $name) {
                if (!Taxonomies::isCustom($name)) {
                    continue;
                }
                $terms = [];
                $known = array_column($this->taxonomies()->load($name)['terms'], 'id');
                foreach (Format::list($meta[$name] ?? null) as $termId) {
                    if (!in_array((string)$termId, $known, true)) {
                        continue;
                    }
                    $label = $this->taxonomyTermLabel($name, (string)$termId);
                    $path = $this->buildTaxonomyPath($name, (string)$termId, $prefix);
                    if ($label !== '' && $path !== '') {
                        $terms[] = ['label' => $label, 'url' => $this->buildAbsoluteUrl($path)];
                    }
                }
                if ($terms !== []) {
                    $groups[] = ['name' => $name, 'title' => $this->taxonomies()->load($name)['title'], 'terms' => $terms];
                }
            }
            return $groups;
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

        // What Theme > Branding adds to a page: a style sheet of the choices made there, and the icons and colour of the browser.
        $brandingBase = (string)($this->settings['base_url'] ?? '');
        $twig->addFunction(new TwigFunction('branding_css', fn(): string => Branding::css($this->themeSettings, $brandingBase), ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('branding_head', fn(): string => Branding::head($this->themeSettings, $brandingBase), ['is_safe' => ['html']]));

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
            $defaults += $this->adminChrome()->defaults(
                $data,
                $this->permissions->can($this->auth->user(), 'notifications.manage'),
                $this->permissions->can($this->auth->user(), 'updates.manage'),
                ($_SESSION['security_default_password'] ?? false) === true,
                $this->currentRequestPath()
            );
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
     * @param array<string, mixed>|null $singleInput submitted `single_layouts[<type>][...]` values, when the form had the Single Layouts cards
     * @return array{ok: bool, message?: string}
     */
    private function saveThemeSettings(array $input, ?array $singleInput = null): array
    {
        if (!$this->systemDatabase->isAvailable()) {
            return ['ok' => false, 'message' => 'Theme settings could not be saved because the SQLite system database is unavailable.'];
        }

        // Keys the theme does not declare (hand-added options) are kept, not dropped.
        $current = $this->loadThemeSettings();
        $data = $this->theme->settingsFromInput($input, $current);
        if ($singleInput !== null && $this->singleLayouts()->declared()) {
            $data['single_layouts'] = $this->singleLayouts()->fromInput($singleInput, $this->content->getTypes(), $current);
        }
        $this->setSystemMeta('theme_settings', Yaml::dump($data, 4, 2));
        return ['ok' => true];
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

    private function archiveBuilder(): ArchiveBuilder
    {
        return $this->archiveBuilderService ??= new ArchiveBuilder(
            fn(): array => $this->taxonomies()->names(),
            fn(string $taxonomy, string $termId, string $lang): string => $this->taxonomyTermLabel($taxonomy, $termId, $lang)
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

    private function resolveItemTemplate(ContentItem $item): string
    {
        $custom = $this->normalizeTemplateName((string)($item->meta['template'] ?? ''));
        if ($custom !== '' && $this->theme->hasTemplate($custom)) {
            return $custom;
        }

        $default = $this->theme->defaultSingleTemplate($item->type, $this->singularizeType($item->type));
        // An entry can ask for the plain layout although its content type has another.
        if ($custom === 'templates/' . SingleLayouts::PLAIN . '.twig') {
            return $default;
        }
        // The page layout chosen for the type (Theme > Single Layouts) applies to the types whose own template is the standard one.
        $chosen = $this->singleLayouts()->effectiveTemplate($item->type, $this->themeSettings);
        if ($chosen !== 'default' && $item->type !== 'forms' && $this->theme->hasTemplate('templates/' . $chosen . '.twig') && $this->theme->usesTitleArea($default)) {
            return 'templates/' . $chosen . '.twig';
        }

        return $default;
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
        header('Content-Type: application/xml; charset=utf-8');
        echo $this->sitemap()->xml();
    }

    private function renderRobots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo RobotsTxt::render($this->buildAbsoluteUrl('sitemap.xml'), is_array($this->settings['seo']['robots_disallow'] ?? null) ? $this->settings['seo']['robots_disallow'] : []);
    }

    private function handleTaxonomy(array $segments, string $lang, array $viewDefaults): void
    {
        $page = $this->taxonomyPage()->build($segments, $lang, $viewDefaults, $_GET);
        if ($page === null) {
            $this->render404();
            return;
        }
        $this->render($this->resolveTaxonomyTemplate($page['kind'], $page['slug']), $page['data']);
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
        $kind = Taxonomies::kind($taxonomy);
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
        return Format::dateValue($value, (string)($this->settings['date_format'] ?? 'd/m/Y'));
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
        return FrontRoute::langPrefix($lang, $this->defaultLanguage());
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

    private function titleFromSlug(string $slug): string
    {
        return Slug::title($slug);
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

    private function handleFormRequest(ContentItem $form, string $lang, string $currentPath): array
    {
        $result = $this->publicForms()->handle($form, $lang, $currentPath, [
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'get' => $_GET,
            'post' => $_POST,
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
        }
        return $result['state'];
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
        return PublicForms::replaceShortcodes($html, fn(string $slug): string => $this->renderFormEmbedBySlug($slug, $lang, $currentPath));
    }

    private function renderFormEmbedBySlug(string $slug, string $lang, string $currentPath): string
    {
        $form = $this->content->find('forms', $slug, $lang, $this->auth->check(), true);
        if (!$form) {
            return '';
        }
        return $this->twig->render('components/form.twig', $this->publicForms()->embed($form, $currentPath, $this->formStates[$form->slug] ?? null, $_GET));
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

<?php

declare(strict_types=1);

namespace FarosCMS;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
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
    private ActivityLogRepository $activityLogs;
    private EmailLogRepository $emailLogs;
    private NotificationRepository $notifications;
    private BackupRunRepository $backupRuns;
    private LoginThrottle $loginThrottle;
    private BackupService $backups;
    private FormSubmissionRepository $formSubmissions;
    private ContentIndex $contentIndex;
    private MediaLibrary $media;
    private Theme $theme;
    private Images $images;
    private MarkdownConverter $markdown;
    private ?BlockRegistry $blockRegistry = null;
    private string $currentLang;
    private array $translations = [];
    private array $formStates = [];
    private array $menusCache = [];
    private array $taxonomiesCache = [];
    private array $themeSettings = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->contentDir = $this->basePath . '/content';

        $this->systemDatabase = new SystemDatabase($this->basePath . '/storage');
        $this->systemDatabase->initialize();
        $this->systemMeta = new SystemMetaRepository($this->systemDatabase);
        $this->settings = $this->loadSettings();
        $this->theme = new Theme($this->basePath, (string)($this->settings['theme'] ?? Theme::DEFAULT_NAME));
        $this->themeSettings = $this->loadThemeSettings();
        $this->ensureDefaultMenus();
        $this->ensureDefaultTaxonomies();

        $markdown = $this->markdownConverter();
        $this->markdown = $markdown;

        $this->users = new UserRepository($this->systemDatabase, $this->contentDir . '/users/users.yaml');
        $this->users->importYamlUsersIfEmpty();
        $this->users->ensureSuperadminExists();
        $this->permissions = new PermissionService();
        $this->activityLogs = new ActivityLogRepository($this->systemDatabase);
        $this->emailLogs = new EmailLogRepository($this->systemDatabase);
        $this->notifications = new NotificationRepository($this->systemDatabase);
        $this->backupRuns = new BackupRunRepository($this->systemDatabase);
        $this->loginThrottle = new LoginThrottle($this->systemDatabase);
        $this->backups = new BackupService($this->basePath, $this->systemDatabase);
        $this->formSubmissions = new FormSubmissionRepository($this->contentDir);
        $this->contentIndex = new ContentIndex($this->systemDatabase, $this->contentDir);
        $this->media = new MediaLibrary($this->contentDir, $this->basePath . '/public/uploads');
        $this->images = new Images($this->basePath . '/public', $this->contentDir . '/media');
        $this->content = new ContentRepository($this->contentDir, $markdown, $this->settings);
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

    private function markdownConverter(): MarkdownConverter
    {
        $environment = new Environment(['renderer' => ['soft_break' => "<br />\n"]]);
        $environment->addExtension(new CommonMarkCoreExtension());
        return new MarkdownConverter($environment);
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
            'theme_menus' => $this->resolveThemeMenus($lang, $pathNoLang),
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
                $alternates = $this->buildAlternateUrlsForArchive($type);
                $this->render($this->resolveArchiveTemplate($type), [
                    'items' => $items,
                    'type' => $type,
                    'alternate_urls' => $alternates['urls'],
                    'alternate_default' => $alternates['default'],
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
            $alternates = $this->buildAlternateUrlsForItem($type, $item->slug);
            $languageLinks = $this->buildLanguageLinksForItem($type, $item);
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
        $alternates = $this->buildAlternateUrlsForItem('pages', $page->slug);
        $languageLinks = $this->buildLanguageLinksForItem('pages', $page);
        $template = $slug === $homeSlug ? 'templates/home.twig' : $this->resolveItemTemplate($page);
        $homeData = [];
        if ($template === 'templates/home.twig') {
            $home = $this->themeSettings['home'] ?? [];
            $showProjects = $this->isTruthy($home['show_latest_projects'] ?? true);
            $showPosts = $this->isTruthy($home['show_latest_posts'] ?? true);
            $projectsLimit = max(1, min(12, (int)($home['projects_limit'] ?? 3)));
            $postsLimit = max(1, min(12, (int)($home['posts_limit'] ?? 3)));
            $homeData = [
                'latest_projects' => $showProjects ? array_slice($this->content->getItems('projects', $lang, $includeHidden, false), 0, $projectsLimit) : [],
                'latest_posts' => $showPosts ? array_slice($this->content->getItems('posts', $lang, $includeHidden, false), 0, $postsLimit) : [],
            ];
        }
        $this->render($template, [
            'item' => $page,
            'alternate_urls' => $alternates['urls'],
            'alternate_default' => $alternates['default'],
            'language_links' => $languageLinks,
        ] + $homeData + $this->frontItemData($page, $lang, $path, $viewDefaults, $template === 'templates/home.twig') + $viewDefaults);
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
            $pageBlocks = $this->blockRenderer($lang, $path)->render(array_values($raw), $viewDefaults + [
                'item' => $item,
                'body_html' => $item->html,
            ]);
        }
        return [
            'page_blocks' => $pageBlocks,
            'block_styles' => $pageBlocks['styles'] ?? [],
            'structured_data' => array_merge(
                $this->itemStructuredData($item, $lang, (string)($viewDefaults['canonical_url'] ?? ''), $isHome),
                $pageBlocks['structured_data'] ?? []
            ),
            'og_type' => $item->type === 'posts' ? 'article' : 'website',
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
                'items' => function (string $type, string $itemLang, int $limit) use ($includeHidden): array {
                    if ($type === 'forms' || !in_array($type, $this->content->getTypes(), true)) {
                        return [];
                    }
                    return array_slice($this->content->getItems($type, $itemLang, $includeHidden, false), 0, max(1, min(24, $limit)));
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
                        $options[$type] = ucfirst($type);
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

    /** Organization node shared by every frontend page. @return array<int, array<string, mixed>> */
    private function baseStructuredData(): array
    {
        $siteUrl = $this->buildAbsoluteUrl('');
        $organization = [
            '@type' => 'Organization',
            '@id' => $siteUrl . '#organization',
            'name' => (string)($this->settings['title'] ?? 'FarosCMS'),
            'url' => $siteUrl,
        ];
        $logo = trim((string)($this->themeSettings['brand']['logo'] ?? ''));
        if ($logo !== '') {
            $organization['logo'] = preg_match('#^https?://#i', $logo) ? $logo : $this->buildAbsoluteUrl($logo);
        }
        $social = is_array($this->themeSettings['social'] ?? null) ? $this->themeSettings['social'] : [];
        $sameAs = array_values(array_filter(array_map(static fn($url): string => trim((string)$url), $social), static fn(string $url): bool => $url !== ''));
        if ($sameAs !== []) {
            $organization['sameAs'] = $sameAs;
        }
        return [$organization];
    }

    /** @return array<int, array<string, mixed>> */
    private function itemStructuredData(ContentItem $item, string $lang, string $canonical, bool $isHome): array
    {
        $graph = $this->baseStructuredData();
        $siteUrl = $this->buildAbsoluteUrl('');
        $organization = ['@id' => $siteUrl . '#organization'];
        $prefix = $this->langPrefix($lang);
        $homeUrl = $this->buildAbsoluteUrl($prefix);

        if ($isHome) {
            $graph[] = [
                '@type' => 'WebSite',
                '@id' => $siteUrl . '#website',
                'url' => $homeUrl,
                'name' => (string)($this->settings['title'] ?? 'FarosCMS'),
                'inLanguage' => $lang,
                'publisher' => $organization,
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $this->buildAbsoluteUrl($prefix . 'search') . '?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ];
            return $graph;
        }

        $title = (string)($item->meta['title'] ?? $item->slug);
        if ($item->type === 'posts') {
            $article = [
                '@type' => 'BlogPosting',
                'headline' => $title,
                'mainEntityOfPage' => $canonical,
                'inLanguage' => $lang,
                'author' => $organization,
                'publisher' => $organization,
                'dateModified' => gmdate('c', $item->mtime),
            ];
            $published = strtotime((string)($item->meta['date'] ?? ''));
            if ($published !== false) {
                $article['datePublished'] = date('c', $published);
            }
            $excerpt = trim((string)($item->meta['excerpt'] ?? ''));
            if ($excerpt !== '') {
                $article['description'] = $excerpt;
            }
            $image = trim((string)($item->meta['main_image'] ?? ''));
            if ($image !== '') {
                $article['image'] = preg_match('#^https?://#i', $image) ? $image : $this->buildAbsoluteUrl($image);
            }
            $graph[] = $article;
        }

        $crumbs = [[$this->translate('nav.main.home', 'Home'), $homeUrl]];
        if ($item->type !== 'pages') {
            $crumbs[] = [$this->translate('type.' . $item->type, ucfirst($item->type)), $this->buildAbsoluteUrl($prefix . $item->type)];
        }
        $crumbs[] = [$title, $canonical];
        $list = [];
        foreach ($crumbs as $position => [$name, $url]) {
            $list[] = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $name, 'item' => $url];
        }
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
        return $graph;
    }

    /** JSON-LD graph as a script tag; `<`, `>` and `&` are escaped so content cannot close the tag. */
    private function jsonLdScript(array $graph): string
    {
        if ($graph === []) {
            return '';
        }
        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => array_values($graph)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
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

        $this->maybeRunScheduledBackup();

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

        if ($action === 'taxonomies') {
            $this->handleTaxonomies();
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
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $address = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        $throttle = $this->loginThrottle->check($address, $username);
        if ($throttle['blocked']) {
            $minutes = max(1, (int)ceil($throttle['retry_after'] / 60));
            $this->logActivity('auth.login_throttled', 'warning', 'user', $username, 'Login blocked after repeated failures.', [
                'username' => $username,
                'retry_after' => $throttle['retry_after'],
            ], ['username' => $username]);
            http_response_code(429);
            header('Retry-After: ' . $throttle['retry_after']);
            $this->renderLogin('Too many failed sign-in attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.');
            return;
        }

        if ($this->auth->attempt($username, $password)) {
            $this->loginThrottle->recordSuccess($address, $username);
            $signedIn = (string)($this->auth->user()['username'] ?? $username);
            $this->logActivity('auth.login_success', 'info', 'user', $signedIn, 'User logged in.', [
                'method' => 'password',
            ]);
            if ($this->auth->isShippedDefaultPassword($signedIn, $password)) {
                $_SESSION['security_default_password'] = true;
                $this->notifyDefaultPassword($signedIn);
            }
            $this->redirect('/admin');
            return;
        }

        $this->loginThrottle->recordFailure($address, $username);
        $this->logActivity('auth.login_failure', 'warning', 'user', $username, 'Invalid password login attempt.', [
            'method' => 'password',
            'username' => $username,
        ], ['username' => $username]);
        $this->renderLogin('Invalid credentials.');
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
        $google = $this->googleAuthSettings();
        $this->render('@admin/login.twig', [
            'error' => $error,
            'google_auth' => $google,
        ]);
    }

    private function handleGoogleLogin(): void
    {
        $google = $this->googleAuthSettings();
        if (!$google['ready']) {
            $this->renderLogin('Google Sign-In is not configured yet.');
            return;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;
        $query = http_build_query([
            'client_id' => $google['client_id'],
            'redirect_uri' => $google['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
        exit;
    }

    private function handleGoogleCallback(): void
    {
        $google = $this->googleAuthSettings();
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

        $token = $this->googleTokenRequest($code, $google);
        if (!$token['ok']) {
            $this->renderLogin((string)$token['message']);
            return;
        }
        $profile = $this->googleUserInfo((string)$token['access_token']);
        if (!$profile['ok']) {
            $this->renderLogin((string)$profile['message']);
            return;
        }

        $email = strtolower(trim((string)($profile['email'] ?? '')));
        $sub = trim((string)($profile['sub'] ?? ''));
        $verified = (bool)($profile['email_verified'] ?? false);
        if ($email === '' || !$verified) {
            $this->renderLogin('Google account email is not verified.');
            return;
        }
        if ($google['allowed_domain'] !== '' && !str_ends_with($email, '@' . $google['allowed_domain'])) {
            $this->renderLogin('This Google account is not allowed for this FarosCMS installation.');
            return;
        }

        $user = $this->users->findByEmail($email);
        if (!$user || !$this->users->isActive($user)) {
            $this->renderLogin('No active FarosCMS user matches this Google account.');
            return;
        }

        $this->users->linkGoogle((int)$user['id'], $sub, $email);
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
        $dashboard = $this->buildDashboardData();
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

    private function buildDashboardData(): array
    {
        $types = $this->content->getTypes();
        $contentTypes = [];
        $contentTotal = 0;
        $publishedTotal = 0;
        $draftTotal = 0;
        $recentContent = [];

        foreach ($types as $type) {
            $items = $this->content->getItems($type, null, true, false);
            $published = 0;
            $draft = 0;
            foreach ($items as $item) {
                $status = (string)($item->meta['status'] ?? 'published');
                if ($status === 'draft') {
                    $draft++;
                } else {
                    $published++;
                }
                $recentContent[] = [
                    'type' => $type,
                    'slug' => $item->slug,
                    'lang' => $item->lang,
                    'title' => (string)($item->meta['title'] ?? $item->slug),
                    'status' => $status,
                    'mtime' => $item->mtime,
                    'updated_at' => date('Y-m-d H:i', $item->mtime),
                ];
            }

            $count = count($items);
            $contentTypes[] = [
                'type' => $type,
                'count' => $count,
                'published' => $published,
                'draft' => $draft,
            ];
            $contentTotal += $count;
            $publishedTotal += $published;
            $draftTotal += $draft;
        }

        usort($recentContent, fn(array $a, array $b): int => ((int)$b['mtime']) <=> ((int)$a['mtime']));
        $recentContent = array_slice($recentContent, 0, 5);

        $users = $this->users->all();
        $activeUsers = array_values(array_filter($users, fn(array $user): bool => (string)($user['status'] ?? '') === 'active'));
        $backups = $this->listBackupSnapshots();
        $storage = $this->buildStorageSummary();
        $systemChecks = $this->buildDashboardSystemChecks($storage);
        $systemStatus = $this->summarizeSystemStatus($systemChecks);
        $recentActivity = $this->activityLogs->all([], 5, 0);
        $recentEmails = $this->emailLogs->all([], 5, 0);

        return [
            'content_total' => $contentTotal,
            'content_published' => $publishedTotal,
            'content_draft' => $draftTotal,
            'content_types' => $contentTypes,
            'recent_content' => $recentContent,
            'users_total' => count($users),
            'users_active' => count($activeUsers),
            'backups_total' => count($backups),
            'last_backup' => $backups[0] ?? null,
            'recent_activity' => $recentActivity,
            'activity_total' => $this->activityLogs->count(),
            'recent_emails' => $recentEmails,
            'email_total' => $this->emailLogs->count(),
            'failed_emails' => $this->emailLogs->count(['status' => 'failed']),
            'storage' => $storage,
            'system_checks' => $systemChecks,
            'system_status' => $systemStatus,
            'php_version' => PHP_VERSION,
            'upload_limit' => ini_get('upload_max_filesize') ?: '',
            'memory_limit' => ini_get('memory_limit') ?: '',
        ];
    }

    private function buildStorageSummary(): array
    {
        $paths = [
            $this->contentDir,
            $this->basePath . '/storage',
            $this->basePath . '/public/uploads',
        ];
        $used = 0;
        foreach ($paths as $path) {
            $used += $this->directorySize($path);
        }
        $diskFree = (int)(disk_free_space($this->basePath) ?: 0);
        $total = $used + $diskFree;
        $percent = $total > 0 ? (int)round(($used / $total) * 100) : 0;
        if ($used > 0 && $percent === 0) {
            $percent = 1;
        }

        return [
            'used' => $used,
            'used_human' => $this->formatFileSize($used),
            'disk_free' => $diskFree,
            'disk_free_human' => $this->formatFileSize($diskFree),
            'percent' => max(0, min(100, $percent)),
            'label' => $this->formatFileSize($used),
        ];
    }

    private function buildDashboardSystemChecks(array $storage): array
    {
        $mailProvider = $this->mailer()->provider();

        return [
            [
                'label' => 'PHP',
                'value' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'error',
            ],
            [
                'label' => 'Upload limit',
                'value' => ini_get('upload_max_filesize') ?: 'unknown',
                'status' => (ini_get('upload_max_filesize') ?: '') !== '' ? 'ok' : 'warning',
            ],
            [
                'label' => 'Memory limit',
                'value' => ini_get('memory_limit') ?: 'unknown',
                'status' => (ini_get('memory_limit') ?: '') !== '' ? 'ok' : 'warning',
            ],
            [
                'label' => 'SQLite',
                'value' => $this->systemDatabase->isAvailable() ? 'available' : ($this->systemDatabase->lastError() ?: 'unavailable'),
                'status' => $this->systemDatabase->isAvailable() ? 'ok' : 'error',
            ],
            [
                'label' => 'Content directory',
                'value' => is_writable($this->contentDir) ? 'writable' : 'not writable',
                'status' => is_writable($this->contentDir) ? 'ok' : 'error',
            ],
            [
                'label' => 'Storage directory',
                'value' => is_writable($this->basePath . '/storage') ? 'writable' : 'not writable',
                'status' => is_writable($this->basePath . '/storage') ? 'ok' : 'error',
            ],
            [
                'label' => 'Uploads directory',
                'value' => is_writable($this->basePath . '/public/uploads') ? 'writable' : 'not writable',
                'status' => is_writable($this->basePath . '/public/uploads') ? 'ok' : 'warning',
            ],
            [
                'label' => 'Email provider',
                'value' => $mailProvider === 'none' ? 'not configured' : strtoupper($mailProvider),
                'status' => $mailProvider === 'none' ? 'warning' : 'ok',
            ],
            [
                'label' => 'Disk free',
                'value' => $storage['disk_free_human'],
                'status' => ((int)$storage['percent']) > 90 ? 'warning' : 'ok',
            ],
            $this->contentIndexCheck(),
            $this->scheduledBackupCheck(),
        ];
    }

    /** @return array{label: string, value: string, status: string} */
    private function contentIndexCheck(): array
    {
        try {
            $index = $this->ensureContentIndexFresh();
        } catch (\Throwable) {
            return ['label' => 'Content index', 'value' => 'unavailable', 'status' => 'warning'];
        }
        if (!$index['available']) {
            return ['label' => 'Content index', 'value' => 'SQLite unavailable', 'status' => 'warning'];
        }
        return [
            'label' => 'Content index',
            'value' => $index['stale'] ? 'out of date' : $index['rows'] . ' entries',
            'status' => $index['stale'] ? 'warning' : 'ok',
        ];
    }

    /** @return array{label: string, value: string, status: string} */
    private function scheduledBackupCheck(): array
    {
        $auto = is_array($this->settings['backup']['auto'] ?? null) ? $this->settings['backup']['auto'] : [];
        $latest = $this->backups->list()[0] ?? null;
        $latestAge = $latest !== null ? time() - (int)$latest['mtime'] : PHP_INT_MAX;
        if (!$this->isTruthy($auto['enabled'] ?? false)) {
            // Without a schedule, only warn when nobody has taken a backup for a month.
            return [
                'label' => 'Scheduled backups',
                'value' => $latest === null ? 'off, no backups yet' : 'off',
                'status' => $latestAge > 30 * 86400 ? 'warning' : 'ok',
            ];
        }
        $schedule = (string)($auto['schedule'] ?? 'daily');
        $lastRun = (int)strtotime((string)($auto['last_run'] ?? ''));
        $interval = match ($schedule) {
            'weekly' => 604800,
            'monthly' => 2592000,
            default => 86400,
        };
        $overdue = $lastRun > 0 && time() - $lastRun > $interval + 86400;
        return [
            'label' => 'Scheduled backups',
            'value' => $overdue ? 'overdue (' . $schedule . ')' : $schedule . ($lastRun > 0 ? ', last ' . date('Y-m-d', $lastRun) : ''),
            'status' => $overdue ? 'warning' : 'ok',
        ];
    }

    private function summarizeSystemStatus(array $checks): array
    {
        $errors = count(array_filter($checks, fn(array $check): bool => (string)($check['status'] ?? '') === 'error'));
        $warnings = count(array_filter($checks, fn(array $check): bool => (string)($check['status'] ?? '') === 'warning'));
        if ($errors > 0) {
            return ['label' => 'Needs attention', 'status' => 'error', 'detail' => $errors . ' critical checks'];
        }
        if ($warnings > 0) {
            return ['label' => 'Warnings', 'status' => 'warning', 'detail' => $warnings . ' checks to review'];
        }
        return ['label' => 'Healthy', 'status' => 'ok', 'detail' => 'All checks passing'];
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
                $result = $this->createBackupSnapshot();
                if (($result['ok'] ?? false) === true) {
                    $this->updateBackupLastRun(date('c'));
                }
                $this->recordBackupRun($result);
                $this->logActivity(($result['ok'] ?? false) ? 'backup.create_success' : 'backup.create_failure', $this->backupLogLevel($result), 'backup', (string)($result['filename'] ?? ''), (string)($result['message'] ?? 'Backup action completed.'), [
                    'result' => $result,
                    'source' => 'backups_module',
                ]);
                $this->notifyBackupResult($result, false);
                $this->redirect('/admin/backups?' . http_build_query([
                    'backup' => $this->backupResultQueryStatus($result),
                    'backup_msg' => (string)($result['message'] ?? ''),
                ]));
                return;
            }

            if ($action === 'create_database') {
                $result = $this->createDatabaseBackupSnapshot();
                $this->recordBackupRun($result);
                $this->logActivity(($result['ok'] ?? false) ? 'backup.database_success' : 'backup.database_failure', $this->backupLogLevel($result), 'backup', (string)($result['filename'] ?? ''), (string)($result['message'] ?? 'Database backup action completed.'), [
                    'result' => $result,
                    'source' => 'backups_module',
                ]);
                $this->notifyBackupResult($result, false);
                $this->redirect('/admin/backups?' . http_build_query([
                    'backup' => $this->backupResultQueryStatus($result),
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
            'backup_storage_human' => $this->formatFileSize($storageBytes),
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
        $safety = $this->createBackupSnapshot('pre-restore', false, false);
        if (($safety['ok'] ?? false) !== true) {
            $this->recordBackupRun($safety);
            $this->renderBackupRestore($filename, 'The safety snapshot failed, so the restore was not started: ' . (string)($safety['message'] ?? ''), $scopeKeys);
            return;
        }

        $actor = $this->auth->user();
        $result = $this->backups->restore($filename, $scopeKeys);

        // The system database may have been swapped: reload settings and write history into the active database.
        $this->settings = $this->loadSettings();
        $this->themeSettings = $this->loadThemeSettings();
        $this->menusCache = [];
        $this->taxonomiesCache = [];
        $this->recordBackupRun($safety);
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
                $result = $this->createBackupSnapshot('pre-update');
                $this->recordBackupRun($result);
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
                $this->notifyBackupResult($result, false);
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
            $payload = [
                'username' => trim((string)($_POST['username'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'display_name' => trim((string)($_POST['display_name'] ?? '')),
                'role' => trim((string)($_POST['role'] ?? 'admin')),
                'status' => trim((string)($_POST['status'] ?? 'active')),
                'google_sub' => trim((string)($_POST['google_sub'] ?? '')),
                'google_email' => trim((string)($_POST['google_email'] ?? '')),
            ];
            if ($id > 0 && !$this->permissions->canChangeAccessForUser($currentUser, $id)) {
                $payload['role'] = (string)($user['role'] ?? 'user');
                $payload['status'] = (string)($user['status'] ?? 'active');
            }
            if ($id > 0 && !$canManageUsers) {
                $payload['google_sub'] = (string)($user['google_sub'] ?? '');
                $payload['google_email'] = (string)($user['google_email'] ?? '');
            }
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
            if ($payload['username'] === '') {
                $error = 'Username is required.';
            } elseif ($id === 0 && $password === '') {
                $error = 'Password is required for new users.';
            } elseif ($password !== '' && $password !== $passwordConfirm) {
                $error = 'Password confirmation does not match.';
            } elseif ($password !== '' && mb_strlen($password) < 8) {
                $error = 'Passwords must be at least 8 characters long.';
            } elseif ($password !== '' && $this->auth->isShippedDefaultPassword($payload['username'], $password)) {
                $error = 'Choose a password other than the one shipped with FarosCMS.';
            } else {
                if ($password !== '') {
                    $payload['password_hash'] = $this->users->passwordHash($password);
                }
                try {
                    if ($id > 0) {
                        $this->users->update($id, $payload);
                        if ($id === $currentId && $password !== '') {
                            unset($_SESSION['security_default_password']);
                        }
                        $this->logActivity('users.update', 'info', 'user', (string)$id, 'User updated.', [
                            'username' => $payload['username'],
                            'role' => $payload['role'],
                            'status' => $payload['status'],
                        ]);
                    } else {
                        $id = $this->users->create($payload);
                        $this->logActivity('users.create', 'info', 'user', (string)$id, 'User created.', [
                            'username' => $payload['username'],
                            'role' => $payload['role'],
                            'status' => $payload['status'],
                        ]);
                    }
                    $this->redirect('/admin/users-edit?id=' . $id . '&saved=1');
                    return;
                } catch (\Throwable $e) {
                    $error = 'User could not be saved. Check for duplicate usernames or invalid values.';
                }
            }
            $user = array_merge($user ?: [], $payload, ['id' => $id]);
        }

        $this->render('@admin/user-edit.twig', [
            'title' => $canManageUsers ? ($id === 0 ? 'Add user' : 'Edit user') : 'Your profile',
            'edited_user' => $user ?: [
                'id' => 0,
                'username' => '',
                'email' => '',
                'display_name' => '',
                'role' => 'admin',
                'status' => 'active',
                'google_sub' => '',
                'google_email' => '',
                'created_at' => '',
                'updated_at' => '',
                'last_login_at' => '',
            ],
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
        $translationLangs = $this->buildTranslationLangMatrix($type, $items);
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
            'filters_active' => $filters['q'] !== '' || $filters['status'] !== '',
            'status_options' => $statusOptions,
            'bulk_status' => (string)($_GET['bulk'] ?? ''),
            'bulk_message' => trim((string)($_GET['bulk_msg'] ?? '')),
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
                if (@unlink($path)) {
                    $this->unindexContent($type, $slug, $lang);
                    $changed++;
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
        $this->redirect($back . '&' . http_build_query(['bulk' => $changed > 0 ? 'ok' : 'fail', 'bulk_msg' => $message]));
    }

    private function handleEdit(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
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
        $mediaPickerImages = [];

        $isNew = true;
        if ($slug !== '' && file_exists($path)) {
            [$frontmatter, $body] = $this->splitFrontMatter((string)file_get_contents($path));
            $isNew = false;
        }
        if ($frontmatter === '' && $slug !== '') {
            $frontmatter = $this->defaultFrontmatter($type, $slug);
        }
        $meta = [];
        if ($frontmatter !== '') {
            $meta = Yaml::parse($frontmatter) ?: [];
        }
        if (is_array($meta)) {
            $mainImage = (string)($meta['main_image'] ?? '');
            $metaForm['title'] = $isNew ? (string)($meta['title'] ?? '') : (string)($meta['title'] ?? $this->titleFromSlug($slug));
            $metaForm['status'] = (string)($meta['status'] ?? 'published');
            $metaForm['visible'] = (bool)($meta['visible'] ?? true);
            $rawDate = $meta['date'] ?? '';
            $metaForm['date'] = $this->normalizeAdminDate($rawDate);
            $metaForm['author'] = (string)($meta['author'] ?? '');
            foreach ($this->listTaxonomyNames() as $taxonomyName) {
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
            $metaForm['custom_fields'] = $this->extractCustomFields($meta);
            if ($type === 'forms') {
                $formFields = $this->normalizeFormFieldsForAdmin($meta['fields'] ?? []);
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
            $translationId = $this->findTranslationIdBySlug($type, $slug);
        }
        if ($translationId === '') {
            $translationId = $this->generateTranslationId();
        }
        $metaForm['translation_id'] = $translationId;
        $translations = $this->buildTranslationLinks($type, $slug, $translationId);
        if ($isNew && $metaForm['date'] === '') {
            $metaForm['date'] = date('Y-m-d');
        }
        $formSubmissionsTotal = 0;
        if ($type === 'forms' && $slug !== '') {
            $formSubmissions = $this->listFormSubmissions($slug);
            $formSubmissionsTotal = count($formSubmissions);
            $formSubmissions = array_slice($formSubmissions, 0, 10);
        }
        if ($type !== 'forms') {
            $this->media->ensureDirectories();
            $this->media->migrateLegacyItems();
            $mediaPickerImages = array_slice($this->media->list([
                'type' => 'image',
            ]), 0, 120);
        }
        $frontUrl = '';
        if ($slug !== '') {
            $homeSlug = $this->settings['home_page'] ?? 'index';
            $defaultLang = $this->settings['languages']['default'] ?? 'en';
            $path = $this->buildContentPath($type, $slug, $lang, $homeSlug, $defaultLang);
            $frontUrl = $this->buildAbsoluteUrl($path);
        }

        $this->render('@admin/edit.twig', [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title_from_slug' => $this->titleFromSlug($slug),
            'frontmatter' => $frontmatter,
            'body' => $body,
            'main_image' => $mainImage,
            'meta_form' => $metaForm,
            'taxonomies' => $this->listTaxonomiesForAdmin(),
            'translations' => $translations,
            'front_url' => $frontUrl,
            'types' => $this->content->getTypes(),
            'languages' => $this->settings['languages']['available'] ?? [],
            'user' => $this->auth->user(),
            'saved' => $saved,
            'deleted' => $deleted,
            'admin_section' => $type === 'forms' ? 'forms' : 'content',
            'current_type' => $type,
            'form_fields' => $formFields,
            'form_notifications' => $formNotifications,
            'form_settings' => $formSettings,
            'form_submissions' => $formSubmissions,
            'form_submissions_total' => $formSubmissionsTotal,
            'form_field_types' => $this->formFieldTypes(),
            'media_picker_images' => $mediaPickerImages,
        ]);
    }

    private function handleSave(): void
    {
        $type = $this->sanitizeType((string)($_POST['type'] ?? 'pages'));
        $slug = $this->slugify((string)($_POST['slug'] ?? ''));
        $lang = $this->slugify((string)($_POST['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $frontmatter = trim((string)($_POST['frontmatter'] ?? ''));
        $body = rtrim((string)($_POST['body'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'published'));
        $visible = isset($_POST['visible']) && (string)($_POST['visible']) === '1';
        $date = trim((string)($_POST['date'] ?? ''));
        $author = trim((string)($_POST['author'] ?? ''));
        $excerpt = trim((string)($_POST['excerpt'] ?? ''));
        $seoTitle = trim((string)($_POST['seo_title'] ?? ''));
        $seoDescription = trim((string)($_POST['seo_description'] ?? ''));
        $seoCanonical = trim((string)($_POST['seo_canonical'] ?? ''));
        $seoOgTitle = trim((string)($_POST['seo_og_title'] ?? ''));
        $seoOgDescription = trim((string)($_POST['seo_og_description'] ?? ''));
        $seoOgImage = trim((string)($_POST['seo_og_image'] ?? ''));
        $seoNoindex = isset($_POST['seo_noindex']) && (string)($_POST['seo_noindex']) === '1';
        $mainImage = trim((string)($_POST['main_image'] ?? ''));
        $taxonomyTermsInput = $_POST['taxonomy_terms'] ?? [];
        $customKeys = $_POST['custom_keys'] ?? [];
        $customValues = $_POST['custom_values'] ?? [];
        $formFieldsInput = $_POST['form_fields'] ?? [];
        $formNotificationsInput = $_POST['form_notifications'] ?? [];
        $formStoreSubmissions = isset($_POST['form_store_submissions']) && (string)($_POST['form_store_submissions']) === '1';
        $formSubmitLabel = trim((string)($_POST['form_submit_label'] ?? ''));
        $formSuccessMessage = trim((string)($_POST['form_success_message'] ?? ''));
        $formRedirectUrl = trim((string)($_POST['form_redirect_url'] ?? ''));
        $formHoneypot = trim((string)($_POST['form_honeypot'] ?? ''));
        $formRateLimit = trim((string)($_POST['form_rate_limit_seconds'] ?? ''));
        $translationId = trim((string)($_POST['translation_id'] ?? ''));
        $originalSlug = $this->slugify((string)($_POST['original_slug'] ?? ''));
        $originalLang = $this->slugify((string)($_POST['original_lang'] ?? ''));

        $mainImageUploadRaw = $_FILES['main_image_upload'] ?? null;
        $mainImageUploads = $this->media->normalizeUploads($mainImageUploadRaw);
        if ($mainImageUploads !== []) {
            $upload = $mainImageUploads[0];
            $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_OK) {
                try {
                    $this->media->ensureDirectories();
                    $uploaded = $this->media->upload($upload, '', $this->currentUsername(), $this->maxUploadBytes());
                    if ((string)($uploaded['kind'] ?? '') === 'image') {
                        $mainImage = (string)($uploaded['direct_url'] ?? $mainImage);
                    } else {
                        $uploadedId = (string)($uploaded['id'] ?? '');
                        if ($uploadedId !== '') {
                            $this->media->delete($uploadedId);
                        }
                    }
                } catch (\Throwable) {
                    // Keep existing main image unchanged on upload failure.
                }
            }
        }

        if ($slug === '' && $title !== '') {
            $slug = $this->slugify($title);
        }

        if ($slug === '') {
            $this->redirect('/admin');
            return;
        }

        $dir = $this->contentDir . '/' . $type;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . '/' . $this->buildFilename($slug, $lang);
        $wasExisting = file_exists($path);
        $oldPath = '';
        if ($originalSlug !== '' && $originalLang !== '') {
            $oldPath = $dir . '/' . $this->buildFilename($originalSlug, $originalLang);
        }
        $data = [];
        if ($frontmatter !== '') {
            $parsed = Yaml::parse($frontmatter) ?: [];
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        $data['title'] = $title !== '' ? $title : $this->titleFromSlug($slug);
        $data['status'] = $status !== '' ? $status : 'published';
        $data['visible'] = $visible;

        if ($date !== '') {
            $data['date'] = $this->normalizeDateForStorage($date);
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

        if ($translationId === '') {
            $translationId = $this->findTranslationIdBySlug($type, $slug);
        }
        if ($translationId === '') {
            $translationId = $this->generateTranslationId();
        }
        $data['translation_id'] = $translationId;

        $taxonomyTermsInput = is_array($taxonomyTermsInput) ? $taxonomyTermsInput : [];
        $taxonomyNames = $this->listTaxonomyNames();
        if ($type !== 'pages' && $type !== 'forms') {
            foreach ($taxonomyNames as $taxonomyName) {
                $selectedRaw = $taxonomyTermsInput[$taxonomyName] ?? [];
                $selectedRaw = is_array($selectedRaw) ? $selectedRaw : [];
                $selected = [];
                foreach ($selectedRaw as $termId) {
                    $termId = $this->slugify((string)$termId);
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

        if (array_key_exists('main_image', $_POST)) {
            if ($mainImage !== '') {
                $data['main_image'] = $mainImage;
            } else {
                unset($data['main_image']);
            }
        }

        if (isset($_POST['seo_title']) || isset($_POST['seo_description']) || isset($_POST['seo_canonical']) || isset($_POST['seo_og_title']) || isset($_POST['seo_og_description']) || isset($_POST['seo_og_image']) || isset($_POST['seo_noindex'])) {
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
            if (!$this->isReservedFrontmatterKey($key)) {
                unset($data[$key]);
            }
        }

        $customFields = [];
        if (is_array($customKeys) && is_array($customValues)) {
            foreach ($customKeys as $index => $key) {
                $key = trim((string)$key);
                if ($key === '' || $this->isReservedFrontmatterKey($key)) {
                    continue;
                }
                $value = (string)($customValues[$index] ?? '');
                $customFields[$key] = $this->parseCustomValue($value);
            }
        }

        if (!empty($customFields)) {
            $data['custom_fields'] = $customFields;
        } else {
            unset($data['custom_fields']);
        }

        if ($type === 'forms') {
            $fields = $this->parseFormFieldsInput($formFieldsInput);
            if (!empty($fields)) {
                $data['fields'] = $fields;
            } else {
                unset($data['fields']);
            }

            $notifications = [];
            if (is_array($formNotificationsInput)) {
                $notifications['enabled'] = $this->isTruthy($formNotificationsInput['enabled'] ?? false);
                $notifications['to'] = trim((string)($formNotificationsInput['to'] ?? ''));
                $notifications['subject'] = trim((string)($formNotificationsInput['subject'] ?? ''));
                $notifications['reply_to_field'] = trim((string)($formNotificationsInput['reply_to_field'] ?? ''));
                $notifications['cc'] = trim((string)($formNotificationsInput['cc'] ?? ''));
                $notifications['bcc'] = trim((string)($formNotificationsInput['bcc'] ?? ''));
                $notifications['auto_reply'] = $this->isTruthy($formNotificationsInput['auto_reply'] ?? false);
                $notifications['auto_reply_include'] = $this->isTruthy($formNotificationsInput['auto_reply_include'] ?? false);
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
        $this->indexContentFile($type, $path);

        if ($oldPath !== '' && $oldPath !== $path && file_exists($oldPath)) {
            unlink($oldPath);
            $this->unindexContent($type, $originalSlug, $originalLang);
        }

        $this->logActivity($wasExisting ? 'content.update' : 'content.create', 'info', $type, $slug . ':' . $lang, ($wasExisting ? 'Content updated.' : 'Content created.'), [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title' => (string)($data['title'] ?? ''),
            'status' => (string)($data['status'] ?? ''),
            'renamed_from' => $oldPath !== '' && $oldPath !== $path ? $originalSlug . ':' . $originalLang : '',
        ]);
        $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . '&saved=1');
    }

    private function handleDelete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/admin');
            return;
        }

        $type = $this->sanitizeType((string)($_POST['type'] ?? 'pages'));
        $slug = $this->slugify((string)($_POST['slug'] ?? ''));
        $lang = $this->slugify((string)($_POST['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        $homeSlug = trim((string)($this->settings['home_page'] ?? 'index'));
        if ($homeSlug === '') {
            $homeSlug = 'index';
        }

        if ($slug === '') {
            $this->redirect('/admin/content?type=' . urlencode($type) . '&lang=' . urlencode($lang));
            return;
        }

        if ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang) {
            $this->redirect('/admin/content?type=' . urlencode($type) . '&lang=' . urlencode($lang));
            return;
        }

        $path = $this->contentDir . '/' . $type . '/' . $this->buildFilename($slug, $lang);
        if (file_exists($path)) {
            unlink($path);
            $this->unindexContent($type, $slug, $lang);
            $this->logActivity('content.delete', 'warning', $type, $slug . ':' . $lang, 'Content deleted.', [
                'type' => $type,
                'slug' => $slug,
                'lang' => $lang,
            ]);
        }

        $this->redirect('/admin/content?type=' . urlencode($type) . '&lang=' . urlencode($lang) . '&deleted=1');
    }

    private function handleNew(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
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
        $themeStatus = (string)($_GET['theme'] ?? '');
        $themeMessage = trim((string)($_GET['theme_msg'] ?? ''));
        $settingsError = trim((string)($_GET['settings_error'] ?? ''));
        $activeTab = $this->sanitizeSettingsTab((string)($_GET['tab'] ?? 'basics'));

        $downloadBackup = trim((string)($_GET['download_backup'] ?? ''));
        if ($downloadBackup !== '') {
            $this->downloadBackupSnapshot($downloadBackup);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $activeTab = $this->sanitizeSettingsTab((string)($_POST['active_tab'] ?? $activeTab));
            $raw = $this->loadSettingsRaw('site_settings', $this->defaultSettings());
            $form = [
                'title' => (string)($_POST['title'] ?? ''),
                'tagline' => (string)($_POST['tagline'] ?? ''),
                'base_url' => (string)($_POST['base_url'] ?? ''),
                'theme' => (string)($_POST['theme'] ?? ''),
                'home_page' => (string)($_POST['home_page'] ?? ''),
                'date_format' => (string)($_POST['date_format'] ?? ''),
                'languages_default' => (string)($_POST['languages_default'] ?? ''),
                'languages_available' => (string)($_POST['languages_available'] ?? ''),
                'mail_driver' => (string)($_POST['mail_driver'] ?? ''),
                'mail_from' => (string)($_POST['mail_from'] ?? ''),
                'mail_from_name' => (string)($_POST['mail_from_name'] ?? ''),
                'smtp_host' => (string)($_POST['smtp_host'] ?? ''),
                'smtp_port' => (string)($_POST['smtp_port'] ?? ''),
                'smtp_user' => (string)($_POST['smtp_user'] ?? ''),
                'smtp_pass' => (string)($_POST['smtp_pass'] ?? ''),
                'smtp_encryption' => (string)($_POST['smtp_encryption'] ?? ''),
                'ses_key' => (string)($_POST['ses_key'] ?? ''),
                'ses_secret' => (string)($_POST['ses_secret'] ?? ''),
                'ses_region' => (string)($_POST['ses_region'] ?? ''),
                'menu_location_header' => (string)($_POST['menu_location_header'] ?? ''),
                'menu_location_footer' => (string)($_POST['menu_location_footer'] ?? ''),
                'menu_location_keys' => $_POST['menu_location_keys'] ?? [],
                'menu_location_values' => $_POST['menu_location_values'] ?? [],
                'backup_auto_enabled' => isset($_POST['backup_auto_enabled']) ? '1' : '0',
                'backup_schedule' => (string)($_POST['backup_schedule'] ?? ''),
                'backup_keep_local' => (string)($_POST['backup_keep_local'] ?? ''),
                'backup_remote_enabled' => isset($_POST['backup_remote_enabled']) ? '1' : '0',
                'backup_remote_provider' => (string)($_POST['backup_remote_provider'] ?? ''),
                'backup_remote_endpoint' => (string)($_POST['backup_remote_endpoint'] ?? ''),
                'backup_remote_region' => (string)($_POST['backup_remote_region'] ?? ''),
                'backup_remote_bucket' => (string)($_POST['backup_remote_bucket'] ?? ''),
                'backup_remote_access_key' => (string)($_POST['backup_remote_access_key'] ?? ''),
                'backup_remote_secret_key' => (string)($_POST['backup_remote_secret_key'] ?? ''),
                'backup_remote_prefix' => (string)($_POST['backup_remote_prefix'] ?? ''),
                'backup_remote_keep' => (string)($_POST['backup_remote_keep'] ?? ''),
                'backup_remote_path_style' => isset($_POST['backup_remote_path_style']) ? '1' : '0',
                'google_enabled' => isset($_POST['google_enabled']) ? '1' : '0',
                'google_client_id' => (string)($_POST['google_client_id'] ?? ''),
                'google_client_secret' => (string)($_POST['google_client_secret'] ?? ''),
                'google_allowed_domain' => (string)($_POST['google_allowed_domain'] ?? ''),
                'update_repository' => (string)($_POST['update_repository'] ?? ''),
                'update_branch' => (string)($_POST['update_branch'] ?? ''),
                'update_version_url' => (string)($_POST['update_version_url'] ?? ''),
                'update_changelog_url' => (string)($_POST['update_changelog_url'] ?? ''),
                'update_package_url' => (string)($_POST['update_package_url'] ?? ''),
                'update_github_token' => (string)($_POST['update_github_token'] ?? ''),
                'clear_secrets' => is_array($_POST['clear_secret'] ?? null) ? array_map('strval', $_POST['clear_secret']) : [],
            ];
            if (!$this->saveSettings($raw, $form)) {
                $this->redirect('/admin/settings?' . http_build_query([
                    'tab' => $activeTab,
                    'settings_error' => 'Settings could not be saved because the SQLite system database is unavailable.',
                ]));
                return;
            }
            $this->settings = $this->loadSettings();
            $themeSave = $this->saveThemeSettings(is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : []);
            $this->themeSettings = $this->loadThemeSettings();
            if (($themeSave['ok'] ?? false) !== true) {
                $msg = urlencode((string)($themeSave['message'] ?? 'Theme settings could not be saved.'));
                $this->redirect('/admin/settings?saved=1&tab=theme&theme=fail&theme_msg=' . $msg);
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
                $result = $this->testRemoteBackupStorage();
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
                $result = $this->createBackupSnapshot();
                if (($result['ok'] ?? false) === true) {
                    $this->updateBackupLastRun(date('c'));
                }
                $this->recordBackupRun($result);
                $this->logActivity(($result['ok'] ?? false) ? 'backup.create_success' : 'backup.create_failure', $this->backupLogLevel($result), 'backup', (string)($result['filename'] ?? ''), (string)($result['message'] ?? 'Backup action completed.'), [
                    'result' => $result,
                ]);
                $this->notifyBackupResult($result, false);
                $query = [
                    'saved' => '1',
                    'tab' => 'backup',
                    'backup' => $this->backupResultQueryStatus($result),
                    'backup_msg' => (string)($result['message'] ?? ''),
                ];
                $this->redirect('/admin/settings?' . http_build_query($query));
                return;
            }
            $this->redirect('/admin/settings?saved=1&tab=' . urlencode($activeTab));
            return;
        }

        $raw = $this->loadSettingsRaw('site_settings', $this->defaultSettings());
        $parsed = $this->parseSettingsYaml($raw);
        $this->render('@admin/settings.twig', [
            'user' => $this->auth->user(),
            'saved' => $saved,
            'test_status' => $testStatus,
            'types' => $this->content->getTypes(),
            'admin_section' => 'settings',
            'settings_form' => $this->extractSettingsForm($parsed),
            'theme_schema' => $this->theme->settingsSchema(),
            'theme_values' => $this->themeSettings,
            'theme_info' => [
                'name' => $this->theme->name(),
                'label' => $this->theme->label(),
                'version' => $this->theme->version(),
                'custom_dir' => is_dir($this->theme->customPath()),
            ],
            'backup_snapshots' => $this->listBackupSnapshots(),
            'backup_status' => $backupStatus,
            'backup_message' => $backupMessage,
            'theme_status' => $themeStatus,
            'theme_message' => $themeMessage,
            'settings_error' => $settingsError,
            'active_tab' => $activeTab,
        ]);
    }

    private function handleMenusList(): void
    {
        $rows = $this->listMenusForAdmin();

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
        $menuKeys = $this->listMenuKeys();
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
                    $sourceMenu = $this->loadMenuDefinition($sourceKey, '');
                    $items = $this->normalizeMenuItems($sourceMenu['items'] ?? []);
                    if ($title === '') {
                        $title = (string)($sourceMenu['title'] ?? '');
                    }
                }
                if ($title === '') {
                    $title = $this->titleFromSlug($newKey);
                }
                $this->writeMenuDefinition($newKey, '', [
                    'title' => $title,
                    'items' => $items,
                ]);
                $this->menusCache = [];
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

        $menuKeys = $this->listMenuKeys();
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

        $menu = $this->loadMenuDefinition($selectedKey, '');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['menu_action'] ?? 'save');
            if ($action === 'delete') {
                $path = $this->menuWritePath($selectedKey, '');
                if (is_file($path)) {
                    unlink($path);
                    $this->menusCache = [];
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
                $items = $this->buildMenuItemsFromAdminRows($labelKeys, $labelLangs, $urls, $classes, $targets, $depths, $languages);
                $this->writeMenuDefinition($selectedKey, '', [
                    'title' => $title,
                    'items' => $items,
                ]);
                $this->menusCache = [];
                $this->logActivity('menus.update', 'info', 'menu', $selectedKey, 'Menu updated.', [
                    'title' => $title,
                    'items' => count($items),
                ]);
                $this->redirect('/admin/menus-edit?key=' . urlencode($selectedKey) . '&saved=1');
                return;
            }
        }

        $menu = $this->loadMenuDefinition($selectedKey, '');
        $menuItems = $this->flattenMenuItemsForAdmin($menu['items'] ?? [], $languages);
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
        $languages = $this->settings['languages']['available'] ?? [(string)($this->settings['languages']['default'] ?? 'el')];
        $taxonomyNames = $this->listTaxonomyNames();
        $taxonomy = $this->slugify((string)($_GET['taxonomy'] ?? $_POST['taxonomy'] ?? ($taxonomyNames[0] ?? 'tags')));
        if (!in_array($taxonomy, $taxonomyNames, true)) {
            $taxonomy = $taxonomyNames[0] ?? 'tags';
        }

        $saved = isset($_GET['saved']);
        $error = '';
        $current = $this->loadTaxonomy($taxonomy);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = trim((string)($_POST['taxonomy_title'] ?? $current['title']));
            $ids = $_POST['term_id'] ?? [];
            $slugs = $_POST['term_slug'] ?? [];
            $labels = $_POST['term_label'] ?? [];
            $terms = [];
            $ids = is_array($ids) ? $ids : [];
            $slugs = is_array($slugs) ? $slugs : [];
            $labels = is_array($labels) ? $labels : [];
            $count = max(count($ids), count($slugs));
            for ($i = 0; $i < $count; $i++) {
                $id = $this->slugify((string)($ids[$i] ?? ''));
                $slug = $this->slugify((string)($slugs[$i] ?? ''));
                if ($id === '' && $slug === '') {
                    continue;
                }
                if ($id === '') {
                    $id = $slug;
                }
                if ($slug === '') {
                    $slug = $id;
                }
                $term = [
                    'id' => $id,
                    'slug' => $slug,
                    'labels' => [],
                ];
                foreach ($languages as $langCode) {
                    $langCode = (string)$langCode;
                    $term['labels'][$langCode] = trim((string)($labels[$langCode][$i] ?? ''));
                }
                $terms[] = $term;
            }

            $this->saveTaxonomy($taxonomy, $title, $terms);
            $this->logActivity('taxonomies.update', 'info', 'taxonomy', $taxonomy, 'Taxonomy updated.', [
                'title' => $title,
                'terms' => count($terms),
            ]);
            $this->redirect('/admin/taxonomies?taxonomy=' . urlencode($taxonomy) . '&saved=1');
            return;
        }

        $this->render('@admin/taxonomies.twig', [
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'taxonomies',
            'taxonomy_names' => $taxonomyNames,
            'taxonomy' => $taxonomy,
            'taxonomy_title' => (string)($current['title'] ?? $this->titleFromSlug($taxonomy)),
            'taxonomy_terms' => $current['terms'] ?? [],
            'languages' => $languages,
            'saved' => $saved,
            'error' => $error,
        ]);
    }

    private function handleMedia(): void
    {
        $this->media->ensureDirectories();
        $this->media->migrateLegacyItems();

        $typeOptions = $this->media->typeOptions();
        $viewOptions = ['list', 'thumbs'];
        $perPageOptions = [20, 50, 100];

        $type = strtolower(trim((string)($_GET['type'] ?? 'all')));
        if (!in_array($type, $typeOptions, true)) {
            $type = 'all';
        }
        $tag = $this->media->sanitizeTag((string)($_GET['tag'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 20);
        if (!in_array($perPage, $perPageOptions, true)) {
            $perPage = 20;
        }
        $storedView = strtolower((string)($_SESSION['admin_media_view'] ?? 'list'));
        if (!in_array($storedView, $viewOptions, true)) {
            $storedView = 'list';
        }
        $queryView = strtolower(trim((string)($_GET['view'] ?? '')));
        if ($queryView !== '' && in_array($queryView, $viewOptions, true)) {
            $view = $queryView;
            $_SESSION['admin_media_view'] = $queryView;
        } else {
            $view = $storedView;
        }

        $stateFrom = function (array $source) use ($typeOptions, $viewOptions, $perPageOptions, $type, $tag, $q, $view, $perPage, $page): array {
            $stateType = strtolower(trim((string)($source['_state_type'] ?? $type)));
            if (!in_array($stateType, $typeOptions, true)) {
                $stateType = 'all';
            }
            $stateView = strtolower(trim((string)($source['_state_view'] ?? $view)));
            if (!in_array($stateView, $viewOptions, true)) {
                $stateView = 'list';
            }
            $stateTag = $this->media->sanitizeTag((string)($source['_state_tag'] ?? $tag));
            $stateQ = trim((string)($source['_state_q'] ?? $q));
            $statePerPage = (int)($source['_state_per_page'] ?? $perPage);
            if (!in_array($statePerPage, $perPageOptions, true)) {
                $statePerPage = 20;
            }
            $statePage = max(1, (int)($source['_state_page'] ?? $page));
            return [
                'type' => $stateType,
                'tag' => $stateTag,
                'q' => $stateQ,
                'view' => $stateView,
                'per_page' => $statePerPage,
                'page' => $statePage,
            ];
        };

        $redirectMedia = function (array $params) use ($stateFrom): void {
            $state = $stateFrom($_POST);
            $query = [
                'type' => $state['type'],
                'view' => $state['view'],
                'per_page' => $state['per_page'],
                'page' => $state['page'],
            ];
            if ($state['tag'] !== '') {
                $query['tag'] = $state['tag'];
            }
            if ($state['q'] !== '') {
                $query['q'] = $state['q'];
            }
            foreach ($params as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $query[$key] = $value;
            }
            $this->redirect('/admin/media?' . http_build_query($query));
        };

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = trim((string)($_POST['media_action'] ?? ''));
            if ($action === '' && isset($_POST['delete'])) {
                $action = 'delete';
            }
            if ($action === '' && (isset($_FILES['asset']) || isset($_FILES['upload_file']))) {
                $action = 'upload';
            }

            if ($action === 'upload') {
                $rawUpload = $_FILES['upload_file'] ?? ($_FILES['asset'] ?? null);
                $uploads = $this->media->normalizeUploads($rawUpload);
                if ($uploads === []) {
                    $redirectMedia(['error' => 'No file uploaded.']);
                    return;
                }
                $tagsCsv = trim((string)($_POST['upload_tags'] ?? ''));
                $uploadedCount = 0;
                $failedCount = 0;
                $lastError = '';
                foreach ($uploads as $upload) {
                    $errorCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($errorCode === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    try {
                        $uploaded = $this->media->upload($upload, $tagsCsv, $this->currentUsername(), $this->maxUploadBytes());
                        $uploadedCount++;
                        $this->logActivity('media.upload', 'info', 'media', (string)($uploaded['id'] ?? ''), 'Media uploaded.', [
                            'filename' => (string)($uploaded['filename'] ?? ''),
                            'kind' => (string)($uploaded['kind'] ?? ''),
                        ]);
                    } catch (\Throwable $e) {
                        $failedCount++;
                        $lastError = $e->getMessage();
                    }
                }

                if ($uploadedCount > 0 && $failedCount === 0) {
                    $redirectMedia([
                        'page' => 1,
                        'success' => $uploadedCount === 1 ? 'Upload complete.' : 'Uploaded ' . $uploadedCount . ' files.',
                    ]);
                    return;
                }
                if ($uploadedCount > 0) {
                    $redirectMedia([
                        'page' => 1,
                        'success' => 'Uploaded ' . $uploadedCount . ' files.',
                        'error' => $failedCount . ' uploads failed' . ($lastError !== '' ? ': ' . $lastError : '.'),
                    ]);
                    return;
                }
                $redirectMedia(['error' => $lastError !== '' ? 'Upload failed: ' . $lastError : 'Upload failed.']);
                return;
            }

            if ($action === 'save_tags') {
                $id = $this->media->sanitizeId((string)($_POST['id'] ?? ''));
                if ($id === '') {
                    $redirectMedia(['error' => 'Invalid media item.']);
                    return;
                }
                $alt = isset($_POST['alt']) ? (string)$_POST['alt'] : null;
                if (!$this->media->updateTags($id, (string)($_POST['tags'] ?? ''), $alt)) {
                    $redirectMedia(['error' => 'Media item not found.']);
                    return;
                }
                $this->logActivity('media.tags_update', 'info', 'media', $id, 'Media details updated.');
                $redirectMedia(['success' => 'Saved.']);
                return;
            }

            if ($action === 'delete') {
                $id = $this->media->sanitizeId((string)($_POST['id'] ?? ''));
                if ($id === '') {
                    $legacyFilename = $this->sanitizeFilename((string)($_POST['filename'] ?? ''));
                    if ($legacyFilename !== '') {
                        $legacy = $this->media->findByFilename($legacyFilename);
                        $id = $legacy['id'] ?? '';
                    }
                }
                if ($id === '') {
                    $redirectMedia(['error' => 'Invalid media item.']);
                    return;
                }
                if (!$this->media->delete($id)) {
                    $redirectMedia(['error' => 'Media item not found.']);
                    return;
                }
                $this->logActivity('media.delete', 'warning', 'media', $id, 'Media item deleted.');
                $redirectMedia(['success' => 'Media item deleted.']);
                return;
            }

            if ($action === 'bulk_tags' || $action === 'bulk_delete') {
                $selectedIds = $this->media->collectIds($_POST['selected_ids'] ?? []);
                $applyAllFiltered = ((string)($_POST['apply_all_filtered'] ?? '0')) === '1';
                if ($applyAllFiltered) {
                    $filterType = strtolower(trim((string)($_POST['_filter_type'] ?? 'all')));
                    if (!in_array($filterType, $typeOptions, true)) {
                        $filterType = 'all';
                    }
                    $filterTag = $this->media->sanitizeTag((string)($_POST['_filter_tag'] ?? ''));
                    $filterQ = trim((string)($_POST['_filter_q'] ?? ''));
                    $filtered = $this->media->list([
                        'type' => $filterType,
                        'tag' => $filterTag,
                        'q' => $filterQ,
                    ]);
                    $selectedIds = array_values(array_unique(array_map(
                        fn(array $item): string => (string)($item['id'] ?? ''),
                        $filtered
                    )));
                    $selectedIds = array_values(array_filter($selectedIds, fn(string $id): bool => $id !== ''));
                }

                if ($selectedIds === []) {
                    $redirectMedia(['error' => 'Select at least one media item.']);
                    return;
                }

                if ($action === 'bulk_tags') {
                    $bulkTags = trim((string)($_POST['tags'] ?? ''));
                    $updated = 0;
                    foreach ($selectedIds as $id) {
                        $item = $this->media->find($id);
                        if ($item === null) {
                            continue;
                        }
                        $existing = implode(',', is_array($item['tags'] ?? null) ? $item['tags'] : []);
                        $merged = trim($existing . ',' . $bulkTags, ', ');
                        if ($this->media->updateTags($id, $merged)) {
                            $updated++;
                        }
                    }
                    if ($updated === 0) {
                        $redirectMedia(['error' => 'No media items were updated.']);
                        return;
                    }
                    $this->logActivity('media.bulk_tags_update', 'info', 'media', 'bulk', 'Bulk media tags updated.', [
                        'count' => $updated,
                    ]);
                    $redirectMedia(['success' => 'Updated tags for ' . $updated . ' items.']);
                    return;
                }

                $deletedCount = 0;
                foreach ($selectedIds as $id) {
                    if ($this->media->delete($id)) {
                        $deletedCount++;
                    }
                }
                if ($deletedCount === 0) {
                    $redirectMedia(['error' => 'No media items were deleted.']);
                    return;
                }
                $this->logActivity('media.bulk_delete', 'warning', 'media', 'bulk', 'Bulk media items deleted.', [
                    'count' => $deletedCount,
                ]);
                $redirectMedia(['success' => 'Deleted ' . $deletedCount . ' items.']);
                return;
            }
        }

        $allItems = $this->media->list([
            'type' => $type,
            'tag' => $tag,
            'q' => $q,
        ]);
        $totalItems = count($allItems);
        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($allItems, $offset, $perPage);

        $this->render('@admin/media.twig', [
            'items' => $items,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'page' => $page,
            'per_page' => $perPage,
            'per_page_options' => $perPageOptions,
            'type' => $type,
            'types_available' => $typeOptions,
            'tag' => $tag,
            'q' => $q,
            'view_mode' => $view,
            'available_tags' => $this->media->availableTags(),
            'max_upload_mb' => max(1, (int)($this->settings['media']['max_upload_mb'] ?? 20)),
            'success' => trim((string)($_GET['success'] ?? '')),
            'error' => trim((string)($_GET['error'] ?? '')),
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'media',
        ]);
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

        $submissions = $this->loadFormSubmissionsRaw($slug);
        $headers = ['id', 'submitted_at', 'site_title', 'form_title', 'form', 'lang', 'translation_id', 'ip', 'user_agent'];
        $fieldKeys = [];
        foreach ($submissions as $submission) {
            foreach (array_keys($submission['fields'] ?? []) as $key) {
                if (!in_array($key, $fieldKeys, true)) {
                    $fieldKeys[] = $key;
                }
            }
        }
        sort($fieldKeys);
        $headers = array_merge($headers, $fieldKeys);

        $siteName = (string)($this->settings['title'] ?? 'site');
        $siteSlug = $this->slugify($siteName);
        if ($siteSlug === '') {
            $siteSlug = 'site';
        }
        $formTitle = (string)($form->meta['title'] ?? $form->slug);
        $formSlug = $this->slugify($formTitle);
        if ($formSlug === '') {
            $formSlug = $form->slug;
        }
        $filename = $siteSlug . '-' . $formSlug . '-submissions.csv';
        $this->logActivity('forms.export', 'info', 'forms', $slug . ':' . $lang, 'Form submissions exported.', [
            'slug' => $slug,
            'lang' => $lang,
            'submissions' => count($submissions),
            'filename' => $filename,
        ]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }
        $siteTitle = (string)($this->settings['title'] ?? '');
        fputcsv($output, $headers, ',', '"', '');
        foreach ($submissions as $submission) {
            $row = [];
            foreach ($headers as $header) {
                if (in_array($header, ['id', 'submitted_at', 'form', 'lang', 'translation_id', 'ip', 'user_agent'], true)) {
                    $row[] = $submission[$header] ?? '';
                    continue;
                }
                if ($header === 'site_title') {
                    $row[] = $siteTitle;
                    continue;
                }
                if ($header === 'form_title') {
                    $row[] = $formTitle;
                    continue;
                }
                $value = $submission['fields'][$header] ?? '';
                $row[] = $this->stringifySubmissionValue($value);
            }
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

        $storage = $this->buildStorageSummary();
        $checks = $this->buildDashboardSystemChecks($storage);
        $extensions = [];
        foreach (['pdo_sqlite' => 'System database', 'zip' => 'Backups', 'curl' => 'Remote backups, Google sign-in, SES', 'mbstring' => 'Text handling', 'fileinfo' => 'Upload type detection', 'openssl' => 'SMTP TLS, HTTPS', 'simplexml' => 'S3 listings', 'intl' => 'Optional'] as $extension => $purpose) {
            $loaded = extension_loaded($extension);
            $extensions[] = [
                'name' => $extension,
                'purpose' => $purpose,
                'loaded' => $loaded,
                'status' => $loaded ? 'ok' : ($extension === 'intl' ? 'warning' : 'error'),
            ];
        }
        $sqliteVersion = '';
        try {
            $sqliteVersion = $this->systemDatabase->isAvailable() ? (string)$this->systemDatabase->connection()->query('SELECT sqlite_version()')->fetchColumn() : '';
        } catch (\Throwable) {
        }
        $auto = is_array($this->settings['backup']['auto'] ?? null) ? $this->settings['backup']['auto'] : [];
        $update = $this->updates()->cachedStatus();

        $this->render('@admin/system.twig', [
            'title' => 'System',
            'checks' => $checks,
            'system_status' => $this->summarizeSystemStatus($checks),
            'index' => $this->contentIndex->status($this->content->getTypes()),
            'extensions' => $extensions,
            'environment' => [
                'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
                'SQLite' => $sqliteVersion ?: 'unavailable',
                'FarosCMS' => $this->updates()->currentVersion() . ($this->updates()->currentGitCommit() !== '' ? ' @ ' . $this->updates()->currentGitCommit() : ''),
                'Memory limit' => (string)ini_get('memory_limit'),
                'Upload max / post max' => ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'),
                'Max execution time' => (string)ini_get('max_execution_time') . 's',
                'Timezone' => date_default_timezone_get(),
                'OPcache' => function_exists('opcache_get_status') && is_array(@opcache_get_status(false)) ? 'enabled' : 'disabled',
                'HTTPS' => $this->isHttpsRequest() ? 'yes' : 'no',
            ],
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
        $sort = (string)($_GET['sort'] ?? 'updated');
        if (!in_array($sort, ['updated', 'submissions', 'name'], true)) {
            $sort = 'updated';
        }

        $groups = [];
        foreach ($this->content->getItems('forms', null, true, false) as $item) {
            $groups[$item->slug][$item->lang] = $item;
        }

        $rows = [];
        $totals = ['forms' => 0, 'published' => 0, 'submissions' => 0, 'recent' => 0];
        foreach ($groups as $slug => $versions) {
            $primary = $versions[$defaultLang] ?? reset($versions);
            $stats = $this->formSubmissions->stats((string)$slug);
            $notifications = is_array($primary->meta['notifications'] ?? null) ? $primary->meta['notifications'] : [];
            $status = (string)($primary->meta['status'] ?? 'published');
            $rows[] = [
                'slug' => (string)$slug,
                'title' => (string)($primary->meta['title'] ?? $slug),
                'status' => $status,
                'lang' => $primary->lang,
                'field_count' => count($this->normalizeFormFields($primary->meta['fields'] ?? [])),
                'languages' => array_keys($versions),
                'missing_languages' => array_values(array_diff($languages, array_keys($versions))),
                'submissions' => $stats['total'],
                'recent' => $stats['last_7_days'],
                'latest_submission' => $stats['latest'] !== '' ? $this->formatSubmissionDate($stats['latest']) : '',
                'updated' => max(array_map(static fn(ContentItem $version): int => $version->mtime, $versions)),
                'notifications' => $this->isTruthy($notifications['enabled'] ?? false),
                'stores' => $this->isTruthy($primary->meta['store_submissions'] ?? ($this->settings['forms']['store_submissions'] ?? true)),
                'shortcode' => '[form slug="' . $slug . '"]',
            ];
            $totals['forms']++;
            $totals['published'] += $status === 'published' ? 1 : 0;
            $totals['submissions'] += $stats['total'];
            $totals['recent'] += $stats['last_7_days'];
        }

        if ($filters['q'] !== '') {
            $needle = mb_strtolower($filters['q']);
            $rows = array_values(array_filter($rows, static fn(array $row): bool => str_contains(mb_strtolower($row['title'] . ' ' . $row['slug']), $needle)));
        }
        if ($filters['status'] !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === $filters['status']));
        }
        usort($rows, static fn(array $a, array $b): int => match ($sort) {
            'submissions' => $b['submissions'] <=> $a['submissions'],
            'name' => strcasecmp($a['title'], $b['title']),
            default => $b['updated'] <=> $a['updated'],
        });

        $this->render('@admin/forms-list.twig', [
            'title' => 'Forms',
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $filters,
            'sort' => $sort,
            'filters_active' => $filters['q'] !== '' || $filters['status'] !== '' || $sort !== 'updated',
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
        $versions = [];
        foreach ($this->content->getItems('forms', null, true, false) as $item) {
            if ($item->slug === $slug) {
                $versions[$item->lang] = $item;
            }
        }
        if ($slug === '' || $versions === []) {
            $this->redirect('/admin/forms');
            return;
        }
        $form = $versions[$defaultLang] ?? reset($versions);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)($_POST['submission_action'] ?? '');
            $ids = $action === 'delete'
                ? [(string)($_POST['id'] ?? '')]
                : (is_array($_POST['selected_ids'] ?? null) ? array_map('strval', $_POST['selected_ids']) : []);
            $deleted = 0;
            if (in_array($action, ['delete', 'bulk_delete'], true)) {
                foreach ($ids as $id) {
                    if ($this->formSubmissions->delete($slug, $id)) {
                        $deleted++;
                    }
                }
                if ($deleted > 0) {
                    $this->logActivity('forms.submission_delete', 'warning', 'forms', $slug, $deleted === 1 ? 'Form submission deleted.' : 'Form submissions deleted.', [
                        'slug' => $slug,
                        'count' => $deleted,
                        'ids' => array_slice($ids, 0, 50),
                    ]);
                }
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

        $filters = [
            'lang' => $this->slugify((string)($_GET['lang'] ?? '')),
            'q' => trim((string)($_GET['q'] ?? '')),
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : '',
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : '',
        ];
        $perPage = (int)($_GET['per_page'] ?? 25);
        if (!in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }
        $all = $this->formSubmissions->all($slug);
        $filtered = $this->formSubmissions->filter($all, $filters);
        $total = count($filtered);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);

        $labels = [];
        $formFields = $this->normalizeFormFields($form->meta['fields'] ?? []);
        foreach ($formFields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name !== '') {
                $labels[$name] = (string)($field['label'] ?? $this->titleFromSlug($name));
            }
        }
        $rows = [];
        foreach (array_slice($filtered, ($page - 1) * $perPage, $perPage) as $entry) {
            $fields = [];
            foreach ($entry['fields'] as $key => $value) {
                $fields[] = [
                    'key' => (string)$key,
                    'label' => $labels[(string)$key] ?? $this->titleFromSlug((string)$key),
                    'value' => $this->stringifySubmissionValue($value),
                ];
            }
            $summaryParts = [];
            foreach ($fields as $field) {
                if (in_array($field['key'], ['name', 'full_name', 'email'], true) || $field['value'] === '') {
                    continue;
                }
                $summaryParts[] = $field['value'];
                if (count($summaryParts) >= 2) {
                    break;
                }
            }
            $rows[] = [
                'id' => $entry['id'],
                'submitted_at' => $this->formatSubmissionDate((string)$entry['submitted_at']),
                'lang' => (string)$entry['lang'],
                'title' => $this->submissionTitle($entry['fields']),
                'email' => $this->findReplyToEmail($entry['fields'], $formFields, ''),
                'summary' => mb_strimwidth(implode(' · ', $summaryParts), 0, 120, '…'),
                'fields' => $fields,
                'ip' => (string)$entry['ip'],
                'user_agent' => (string)$entry['user_agent'],
            ];
        }

        $stats = $this->formSubmissions->stats($slug);
        $this->render('@admin/form-submissions.twig', [
            'title' => 'Submissions',
            'form' => [
                'slug' => $slug,
                'title' => (string)($form->meta['title'] ?? $slug),
                'lang' => $form->lang,
                'languages' => array_keys($versions),
            ],
            'rows' => $rows,
            'filters' => $filters,
            'filters_active' => array_filter($filters) !== [],
            'total_all' => count($all),
            'total' => $total,
            'recent' => $stats['last_7_days'],
            'page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'per_page_options' => [25, 50, 100],
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

    private function handleContentExport(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        $types = $this->content->getTypes();
        if (!in_array($type, $types, true) || $type === 'forms') {
            $this->redirect('/admin/content?type=' . urlencode($type));
            return;
        }

        $items = $this->content->getItems($type, null, true, false);
        $metaHeaders = [];
        $reservedMetaHeaders = [
            'meta.slug',
            'meta.type',
            'meta.lang',
            'meta.title',
            'meta.status',
            'meta.visible',
            'meta.date',
            'meta.author',
            'meta.tags',
            'meta.categories',
            'meta.translation_id',
            'meta.main_image',
            'meta.excerpt',
        ];

        $flatMetaRows = [];
        foreach ($items as $item) {
            $flatMeta = $this->flattenMetaForCsv($item->meta, 'meta');
            $flatMetaRows[$item->slug . '|' . $item->lang] = $flatMeta;
            foreach (array_keys($flatMeta) as $key) {
                if (in_array($key, $reservedMetaHeaders, true)) {
                    continue;
                }
                if (!in_array($key, $metaHeaders, true)) {
                    $metaHeaders[] = $key;
                }
            }
        }
        sort($metaHeaders);

        $headers = array_merge([
            'site_title',
            'content_type',
            'language',
            'slug',
            'title',
            'status',
            'visible',
            'date',
            'author',
            'tags',
            'categories',
            'translation_id',
            'main_image',
            'excerpt',
            'updated_at',
            'body',
        ], $metaHeaders);

        $siteName = (string)($this->settings['title'] ?? 'site');
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

        fputcsv($output, $headers, ',', '"', '');
        foreach ($items as $item) {
            $key = $item->slug . '|' . $item->lang;
            $flatMeta = $flatMetaRows[$key] ?? [];
            $row = [
                $siteName,
                $type,
                $item->lang,
                $item->slug,
                (string)($item->meta['title'] ?? ''),
                (string)($item->meta['status'] ?? ''),
                $this->isTruthy($item->meta['visible'] ?? true) ? 'true' : 'false',
                (string)($item->meta['date'] ?? ''),
                (string)($item->meta['author'] ?? ''),
                implode(', ', $this->normalizeMetaList($item->meta['tags'] ?? null)),
                implode(', ', $this->normalizeMetaList($item->meta['categories'] ?? null)),
                (string)($item->meta['translation_id'] ?? ''),
                (string)($item->meta['main_image'] ?? ''),
                (string)($item->meta['excerpt'] ?? ''),
                date('c', $item->mtime),
                $item->markdown,
            ];

            foreach ($metaHeaders as $metaHeader) {
                $row[] = (string)($flatMeta[$metaHeader] ?? '');
            }
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
                    $applyResult = $this->applyContentImportBatch($type, $entries);
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
                            $parse = $this->parseContentImportCsv($tmpPath);
                            if (($parse['ok'] ?? false) !== true) {
                                $error = (string)($parse['error'] ?? 'Could not parse CSV.');
                            } else {
                                $preview = $this->buildContentImportPreview($type, (array)($parse['rows'] ?? []), (array)($parse['headers'] ?? []));
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

        // Theme strings ship with the theme and are replaced by updates; edits made here are
        // stored as overrides in custom/lang/<lang>.yaml, which updates never touch.
        $inherited = $this->theme->inheritedTranslations($lang, $defaultLang);
        $overrides = $this->theme->customTranslations($lang);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $keys = is_array($_POST['keys'] ?? null) ? $_POST['keys'] : [];
            $values = is_array($_POST['values'] ?? null) ? $_POST['values'] : [];
            $reset = array_map('strval', is_array($_POST['reset'] ?? null) ? $_POST['reset'] : []);
            $next = $overrides;

            foreach ($keys as $index => $key) {
                $key = trim((string)$key);
                if ($key === '' || $this->isHiddenTranslationKey($key)) {
                    continue;
                }
                $value = (string)($values[$index] ?? '');
                if (in_array($key, $reset, true) || (array_key_exists($key, $inherited) && $inherited[$key] === $value)) {
                    unset($next[$key]);
                    continue;
                }
                $next[$key] = $value;
            }

            if (!$this->theme->writeCustomTranslations($lang, $next)) {
                $this->redirect('/admin/translations?lang=' . urlencode($lang) . '&error=write');
                return;
            }
            $this->logActivity('translations.update', 'info', 'translation', $lang, 'Translations updated.', [
                'lang' => $lang,
                'overrides' => count($next),
            ]);
            $this->redirect('/admin/translations?lang=' . urlencode($lang) . '&saved=1');
            return;
        }

        $visible = $this->filterVisibleTranslationKeys(array_replace($inherited, $overrides));
        $defaults = $this->filterVisibleTranslationKeys($this->theme->themeTranslations($defaultLang));
        ksort($visible);

        $this->render('@admin/translations.twig', [
            'lang' => $lang,
            'languages' => $available,
            'translations' => $visible,
            'defaults' => $defaults,
            'customized' => array_keys($this->filterVisibleTranslationKeys($overrides)),
            'custom_file' => 'custom/lang/' . $lang . '.yaml',
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

        $twig->addFunction(new TwigFunction('json_ld', function (array $graph): string {
            return $this->jsonLdScript($graph);
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
                $defaults['admin_storage_summary'] = $this->buildStorageSummary();
            }
            if ($syncNotifications) {
                $defaults['admin_notifications'] = $this->notifications->recent(6);
                $defaults['admin_notification_unread_count'] = $this->notifications->unreadCount();
                $defaults['admin_current_url'] = $this->currentRequestPath();
            }
        }

        if (!str_starts_with($template, '@admin/') && !array_key_exists('structured_data', $data)) {
            $defaults['structured_data'] = $this->baseStructuredData();
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

    private function notifyBackupResult(array $result, bool $scheduled): void
    {
        try {
            $status = $this->backupResultStatus($result);
            $ok = $status !== 'failed';
            $this->notifications->createIfMissing([
                'type' => match ($status) {
                    'warning' => 'backup.remote_failed',
                    'success' => 'backup.success',
                    default => 'backup.failed',
                },
                'title' => match ($status) {
                    'warning' => 'Backup saved locally, remote upload failed',
                    'success' => 'Backup completed',
                    default => 'Backup failed',
                },
                'body' => (string)($result['message'] ?? ($ok ? 'Backup completed.' : 'Backup failed.')),
                'severity' => match ($status) {
                    'warning' => 'warning',
                    'success' => 'success',
                    default => 'error',
                },
                'target_url' => '/admin/backups',
                'context' => [
                    'scheduled' => $scheduled,
                    'filename' => (string)($result['filename'] ?? ''),
                    'result' => $result,
                ],
            ]);
        } catch (\Throwable) {
            // Notifications must never block backup creation.
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
            $storage = $this->buildStorageSummary();
            foreach ($this->buildDashboardSystemChecks($storage) as $check) {
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

    private function render404(): void
    {
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

    private function loadSettings(): array
    {
        $defaults = $this->defaultSettings();
        $raw = $this->loadSettingsRaw('site_settings', $defaults);
        if ($raw === '') {
            return $defaults;
        }

        try {
            $data = Yaml::parse($raw);
        } catch (\Throwable) {
            return $defaults;
        }
        if (!is_array($data)) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $data);
    }

    private function defaultSettings(): array
    {
        return [
            'title' => 'FarosCMS',
            'tagline' => 'A flat-file CMS powered by Markdown and Twig.',
            'base_url' => '',
            'theme' => 'default',
            'home_page' => 'index',
            'date_format' => 'd/m/Y',
            'languages' => [
                'default' => 'el',
                'available' => ['el', 'en'],
            ],
            'content_types' => ['pages', 'posts', 'projects', 'forms'],
            'forms' => [
                'store_submissions' => true,
                'notifications' => [
                    'driver' => 'smtp',
                    'from' => '',
                    'from_name' => '',
                    'smtp' => [
                        'host' => '',
                        'port' => '',
                        'username' => '',
                        'password' => '',
                        'encryption' => '',
                    ],
                    'ses' => [
                        'key' => '',
                        'secret' => '',
                        'region' => '',
                    ],
                ],
                'antispam' => [
                    'honeypot' => 'website',
                    'rate_limit_seconds' => 20,
                ],
            ],
            'menu_locations' => [
                'header' => 'main',
                'footer' => 'footer',
            ],
            'backup' => [
                'auto' => [
                    'enabled' => false,
                    'schedule' => 'daily',
                    'last_run' => '',
                ],
                'local' => [
                    'keep' => 20,
                ],
                'remote' => [
                    'enabled' => false,
                    'provider' => 'custom',
                    'endpoint' => '',
                    'region' => '',
                    'bucket' => '',
                    'access_key' => '',
                    'secret_key' => '',
                    'prefix' => '',
                    'keep' => 20,
                    'path_style' => false,
                ],
            ],
            'updates' => [
                'channel' => 'stable',
                'repository' => 'chiotis/faroscms',
                'branch' => 'main',
                'version_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/main/VERSION',
                'changelog_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/main/CHANGELOG.md',
                'package_url' => 'https://github.com/chiotis/faroscms/archive/refs/heads/main.zip',
                'github_token' => '',
                'latest_version' => '',
            ],
        ];
    }

    private function loadThemeSettings(): array
    {
        $raw = $this->loadSettingsRaw('theme_settings', $this->defaultThemeSettings());
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

    private function loadSettingsRaw(string $key, array $defaults): string
    {
        $legacyPath = $this->legacySettingsPath($key);
        $legacyRaw = $legacyPath !== '' && is_file($legacyPath) ? (string)file_get_contents($legacyPath) : '';

        if ($this->systemDatabase->isAvailable()) {
            $raw = $this->getSystemMeta($key);
            if ($raw !== null) {
                return $raw;
            }

            // Installs upgraded from the YAML settings era keep their values on first load.
            $raw = trim($legacyRaw) !== '' ? $legacyRaw : Yaml::dump($defaults, 4, 2);
            $this->setSystemMeta($key, $raw);
            return $raw;
        }

        return trim($legacyRaw) !== '' ? $legacyRaw : Yaml::dump($defaults, 4, 2);
    }

    /** @return array<string, mixed> */
    private function parseSettingsYaml(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        try {
            $data = Yaml::parse($raw);
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    private function legacySettingsPath(string $key): string
    {
        return match ($key) {
            'site_settings' => $this->contentDir . '/settings/site.yaml',
            'theme_settings' => $this->contentDir . '/settings/theme.yaml',
            default => '',
        };
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

    private function ensureDefaultMenus(): void
    {
        $dir = $this->menuDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $defaultLang = (string)($this->settings['languages']['default'] ?? 'el');
        $mainPath = $this->menuWritePath('main', $defaultLang);
        $footerPath = $this->menuWritePath('footer', $defaultLang);

        if (!is_file($mainPath)) {
            $legacyHeader = $this->settings['menu']['header'] ?? [];
            $mainItems = [];
            if (is_array($legacyHeader) && !empty($legacyHeader)) {
                foreach ($legacyHeader as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $url = trim((string)($row['url'] ?? ''));
                    $label = trim((string)($row['label'] ?? ''));
                    $labelKey = trim((string)($row['label_key'] ?? ''));
                    $class = trim((string)($row['class'] ?? ''));
                    $target = trim((string)($row['target'] ?? ''));
                    if ($url === '' && $label === '' && $labelKey === '') {
                        continue;
                    }
                    $item = ['url' => $url];
                    if ($label !== '') {
                        $item['label'] = $label;
                    }
                    if ($labelKey !== '') {
                        $item['label_key'] = $labelKey;
                    }
                    if ($class !== '') {
                        $item['class'] = $class;
                    }
                    if ($target !== '') {
                        $item['target'] = $target;
                    }
                    $mainItems[] = $item;
                }
            }
            if (empty($mainItems)) {
                $mainItems = [
                    ['label_key' => 'nav.main.home', 'url' => ''],
                    ['label_key' => 'nav.main.about', 'url' => 'about'],
                    [
                        'label_key' => 'nav.main.services',
                        'url' => 'services',
                        'children' => [
                            ['label_key' => 'nav.main.services.workplace_strategy', 'url' => 'workplace-strategy'],
                            ['label_key' => 'nav.main.services.design_build', 'url' => 'design-build'],
                            ['label_key' => 'nav.main.services.project_management', 'url' => 'project-management'],
                        ],
                    ],
                    ['label_key' => 'nav.main.projects', 'url' => 'projects'],
                    ['label_key' => 'nav.main.news', 'url' => 'category/news'],
                    ['label_key' => 'nav.main.contact', 'url' => 'contact', 'class' => 'nav-cta'],
                ];
            }
            $this->writeMenuDefinition('main', $defaultLang, [
                'title' => 'Main Menu',
                'items' => $mainItems,
            ]);
        }

        if (!is_file($footerPath)) {
            $footerItems = [
                ['label_key' => 'nav.footer.about', 'url' => 'about'],
                ['label_key' => 'nav.footer.careers', 'url' => 'careers'],
                ['label_key' => 'nav.footer.faq', 'url' => 'faq'],
                ['label_key' => 'nav.footer.privacy', 'url' => 'privacy-policy'],
                ['label_key' => 'nav.footer.terms', 'url' => 'terms'],
                ['label_key' => 'nav.footer.cookies', 'url' => 'cookies'],
                ['label_key' => 'nav.footer.contact', 'url' => 'contact'],
            ];
            $this->writeMenuDefinition('footer', $defaultLang, [
                'title' => 'Footer Menu',
                'items' => $footerItems,
            ]);
        }
    }

    private function taxonomyDir(): string
    {
        return $this->contentDir . '/taxonomies';
    }

    private function ensureDefaultTaxonomies(): void
    {
        $dir = $this->taxonomyDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $defaultTaxonomies = [
            'tags' => [
                'title' => 'Tags',
                'terms' => [
                    ['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']],
                    ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']],
                ],
            ],
            'categories' => [
                'title' => 'Categories',
                'terms' => [
                    ['id' => 'announcements', 'slug' => 'announcements', 'labels' => ['el' => 'Ανακοινώσεις', 'en' => 'Announcements']],
                    ['id' => 'insights', 'slug' => 'insights', 'labels' => ['el' => 'Ιδέες', 'en' => 'Insights']],
                ],
            ],
        ];

        foreach ($defaultTaxonomies as $name => $payload) {
            $path = $dir . '/' . $name . '.yaml';
            if (is_file($path)) {
                continue;
            }
            $title = (string)($payload['title'] ?? $this->titleFromSlug($name));
            $terms = $payload['terms'] ?? [];
            $this->saveTaxonomy($name, $title, is_array($terms) ? $terms : []);
        }
    }

    /** @return string[] */
    private function listTaxonomyNames(): array
    {
        $names = ['tags', 'categories'];
        foreach (glob($this->taxonomyDir() . '/*.yaml') ?: [] as $path) {
            $name = $this->slugify(basename($path, '.yaml'));
            if ($name === '') {
                continue;
            }
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** @return array<int, array{name: string, title: string, terms: array<int, array{id: string, slug: string, labels: array<string, string>}>}> */
    private function listTaxonomiesForAdmin(): array
    {
        $rows = [];
        foreach ($this->listTaxonomyNames() as $name) {
            $taxonomy = $this->loadTaxonomy($name);
            $rows[] = [
                'name' => $name,
                'title' => (string)$taxonomy['title'],
                'terms' => $taxonomy['terms'],
            ];
        }
        return $rows;
    }

    /** @return array{title: string, terms: array<int, array{id: string, slug: string, labels: array<string, string>}>} */
    private function loadTaxonomy(string $name): array
    {
        $name = $this->slugify($name);
        if ($name === '') {
            $name = 'tags';
        }
        if (isset($this->taxonomiesCache[$name])) {
            return $this->taxonomiesCache[$name];
        }

        $path = $this->taxonomyDir() . '/' . $name . '.yaml';
        $data = [];
        if (is_file($path)) {
            $parsed = Yaml::parseFile($path);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        $title = trim((string)($data['title'] ?? $this->titleFromSlug($name)));
        if ($title === '') {
            $title = $this->titleFromSlug($name);
        }
        $terms = $this->normalizeTaxonomyTerms($data['terms'] ?? []);
        $taxonomy = [
            'title' => $title,
            'terms' => $terms,
        ];
        $this->taxonomiesCache[$name] = $taxonomy;
        return $taxonomy;
    }

    /** @return array<int, array{id: string, slug: string, labels: array<string, string>}> */
    private function normalizeTaxonomyTerms(mixed $terms): array
    {
        if (!is_array($terms)) {
            return [];
        }
        $languages = $this->settings['languages']['available'] ?? [(string)($this->settings['languages']['default'] ?? 'el')];
        $rows = [];
        $seenIds = [];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $id = $this->slugify((string)($term['id'] ?? ''));
            $slug = $this->slugify((string)($term['slug'] ?? ''));
            if ($id === '' && $slug !== '') {
                $id = $slug;
            }
            if ($slug === '' && $id !== '') {
                $slug = $id;
            }
            if ($id === '' || $slug === '' || in_array($id, $seenIds, true)) {
                continue;
            }
            $labels = [];
            $sourceLabels = is_array($term['labels'] ?? null) ? $term['labels'] : [];
            foreach ($languages as $langCode) {
                $langCode = (string)$langCode;
                $labels[$langCode] = trim((string)($sourceLabels[$langCode] ?? ''));
            }
            $rows[] = [
                'id' => $id,
                'slug' => $slug,
                'labels' => $labels,
            ];
            $seenIds[] = $id;
        }
        return $rows;
    }

    /** @param array<int, array{id: string, slug: string, labels: array<string, string>}> $terms */
    private function saveTaxonomy(string $name, string $title, array $terms): void
    {
        $name = $this->slugify($name);
        if ($name === '') {
            return;
        }
        $dir = $this->taxonomyDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $payload = [
            'title' => trim($title) !== '' ? trim($title) : $this->titleFromSlug($name),
            'terms' => $this->normalizeTaxonomyTerms($terms),
        ];
        file_put_contents($dir . '/' . $name . '.yaml', Yaml::dump($payload, 6, 2));
        $this->taxonomiesCache = [];
    }

    /** @return array{id: string, slug: string, labels: array<string, string>}|null */
    private function findTaxonomyTermBySlug(string $taxonomy, string $slug): ?array
    {
        $slug = $this->slugify($slug);
        if ($slug === '') {
            return null;
        }
        $terms = $this->loadTaxonomy($taxonomy)['terms'] ?? [];
        foreach ($terms as $term) {
            if ($this->slugify((string)($term['slug'] ?? '')) === $slug) {
                return $term;
            }
        }
        return null;
    }

    private function taxonomyTermLabel(string $taxonomy, string $termId, ?string $lang = null): string
    {
        $termId = $this->slugify($termId);
        if ($termId === '') {
            return '';
        }
        if ($lang === null || $lang === '') {
            $lang = $this->currentLang;
        }
        $terms = $this->loadTaxonomy($taxonomy)['terms'] ?? [];
        foreach ($terms as $term) {
            if ((string)($term['id'] ?? '') !== $termId) {
                continue;
            }
            $labels = is_array($term['labels'] ?? null) ? $term['labels'] : [];
            $label = trim((string)($labels[$lang] ?? ''));
            if ($label !== '') {
                return $label;
            }
            foreach ($labels as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            return $this->titleFromSlug($termId);
        }
        return $this->titleFromSlug($termId);
    }

    private function taxonomyTermSlug(string $taxonomy, string $termId): string
    {
        $termId = $this->slugify($termId);
        if ($termId === '') {
            return '';
        }
        $terms = $this->loadTaxonomy($taxonomy)['terms'] ?? [];
        foreach ($terms as $term) {
            if ((string)($term['id'] ?? '') !== $termId) {
                continue;
            }
            $slug = $this->slugify((string)($term['slug'] ?? ''));
            return $slug !== '' ? $slug : $termId;
        }
        return $termId;
    }

    private function menuDir(): string
    {
        return $this->contentDir . '/menus';
    }

    private function menuWritePath(string $key, string $lang): string
    {
        $key = $this->slugify($key);
        if ($key === '') {
            $key = 'menu';
        }
        return $this->menuDir() . '/' . $key . '.yaml';
    }

    /** @return string[] */
    private function listMenuKeys(): array
    {
        $keys = ['main', 'footer'];
        foreach (glob($this->menuDir() . '/*.yaml') ?: [] as $path) {
            $filename = basename($path, '.yaml');
            if ($filename === '' || preg_match('/\.[a-z]{2}$/', $filename) === 1) {
                continue;
            }
            $key = $this->slugify($filename);
            if ($key === '' || in_array($key, $keys, true)) {
                continue;
            }
            $keys[] = $key;
        }
        sort($keys);
        return $keys;
    }

    /** @return array<int, array{key: string, title: string, updated: string}> */
    private function listMenusForAdmin(): array
    {
        $rows = [];
        foreach ($this->listMenuKeys() as $key) {
            $path = $this->menuWritePath($key, '');
            $menu = $this->loadMenuDefinition($key, '');
            $mtime = is_file($path) ? (int)filemtime($path) : 0;
            $rows[] = [
                'key' => $key,
                'title' => (string)($menu['title'] ?? $this->titleFromSlug($key)),
                'updated' => $mtime > 0 ? date('Y-m-d H:i', $mtime) : '',
            ];
        }
        return $rows;
    }

    /** @return array{path: string, lang: string} */
    private function resolveMenuSource(string $key, string $lang): array
    {
        $path = $this->menuWritePath($key, '');
        return ['path' => $path, 'lang' => (string)($this->settings['languages']['default'] ?? 'el')];
    }

    /** @return array{title: string, items: array<int, array<string, mixed>>} */
    private function loadMenuDefinition(string $key, string $lang): array
    {
        $cacheKey = $key . '|single';
        if (isset($this->menusCache[$cacheKey])) {
            return $this->menusCache[$cacheKey];
        }

        $menu = [
            'title' => $this->titleFromSlug($key),
            'items' => [],
        ];

        $source = $this->resolveMenuSource($key, '');
        if ($source['path'] !== '' && is_file($source['path'])) {
            $data = Yaml::parseFile($source['path']);
            if (is_array($data)) {
                $title = trim((string)($data['title'] ?? ''));
                if ($title !== '') {
                    $menu['title'] = $title;
                }
                $menu['items'] = $this->normalizeMenuItems($data['items'] ?? []);
            }
        }

        $this->menusCache[$cacheKey] = $menu;
        return $menu;
    }

    private function writeMenuDefinition(string $key, string $lang, array $menu): void
    {
        $dir = $this->menuDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $this->menuWritePath($key, $lang);
        $title = trim((string)($menu['title'] ?? ''));
        if ($title === '') {
            $title = $this->titleFromSlug($key);
        }
        $items = $this->normalizeMenuItems($menu['items'] ?? []);
        $payload = [
            'title' => $title,
            'items' => $items,
        ];
        file_put_contents($path, Yaml::dump($payload, 4, 2));
        $this->menusCache = [];
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeMenuItems(mixed $items, int $depth = 1): array
    {
        if (!is_array($items)) {
            return [];
        }
        $depth = max(1, min(3, $depth));
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'el');
        $languages = $this->settings['languages']['available'] ?? [$defaultLang];
        $rows = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $labelKey = trim((string)($row['label_key'] ?? ''));
            $sourceLabels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
            $labels = [];
            foreach ($languages as $language) {
                $language = (string)$language;
                $labels[$language] = trim((string)($sourceLabels[$language] ?? ''));
            }
            if ($label !== '' && ($labels[$defaultLang] ?? '') === '') {
                $labels[$defaultLang] = $label;
            }
            $url = trim((string)($row['url'] ?? ''));
            $class = trim((string)($row['class'] ?? ''));
            $target = trim((string)($row['target'] ?? ''));
            $children = [];
            if ($depth < 3) {
                $children = $this->normalizeMenuItems($row['children'] ?? [], $depth + 1);
            }
            $hasLabels = false;
            foreach ($labels as $value) {
                if ($value !== '') {
                    $hasLabels = true;
                    break;
                }
            }
            if ($label === '' && $labelKey === '' && !$hasLabels && $url === '' && empty($children)) {
                continue;
            }
            $item = [
                'label' => $label,
                'label_key' => $labelKey,
                'labels' => $labels,
                'url' => $url,
                'class' => $class,
                'target' => $target,
            ];
            if (!empty($children)) {
                $item['children'] = $children;
            }
            $rows[] = $item;
        }
        return $rows;
    }

    /** @return array<int, array{depth: string, label_key: string, labels: array<string, string>, url: string, class: string, target: string}> */
    private function flattenMenuItemsForAdmin(mixed $items, array $languages, int $depth = 1): array
    {
        $depth = max(1, min(3, $depth));
        $rows = [];
        foreach ($this->normalizeMenuItems($items, $depth) as $item) {
            $rowLabels = [];
            $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];
            foreach ($languages as $language) {
                $language = (string)$language;
                $rowLabels[$language] = trim((string)($labels[$language] ?? ''));
            }
            $rows[] = [
                'depth' => (string)$depth,
                'label_key' => trim((string)($item['label_key'] ?? '')),
                'labels' => $rowLabels,
                'url' => trim((string)($item['url'] ?? '')),
                'class' => trim((string)($item['class'] ?? '')),
                'target' => trim((string)($item['target'] ?? '')),
            ];
            if ($depth < 3 && isset($item['children']) && is_array($item['children'])) {
                $rows = array_merge($rows, $this->flattenMenuItemsForAdmin($item['children'], $languages, $depth + 1));
            }
        }
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildMenuItemsFromAdminRows(mixed $labelKeys, mixed $labelLangs, mixed $urls, mixed $classes, mixed $targets, mixed $depths, array $languages): array
    {
        $labelKeys = is_array($labelKeys) ? $labelKeys : [];
        $labelLangs = is_array($labelLangs) ? $labelLangs : [];
        $urls = is_array($urls) ? $urls : [];
        $classes = is_array($classes) ? $classes : [];
        $targets = is_array($targets) ? $targets : [];
        $depths = is_array($depths) ? $depths : [];

        $count = max(count($labelKeys), count($urls), count($classes), count($targets), count($depths));
        $roots = [];
        $stack = [];

        for ($i = 0; $i < $count; $i++) {
            $labelKey = trim((string)($labelKeys[$i] ?? ''));
            $labels = [];
            $hasLabels = false;
            foreach ($languages as $language) {
                $language = (string)$language;
                $langRows = is_array($labelLangs[$language] ?? null) ? $labelLangs[$language] : [];
                $value = trim((string)($langRows[$i] ?? ''));
                $labels[$language] = $value;
                if ($value !== '') {
                    $hasLabels = true;
                }
            }
            $url = trim((string)($urls[$i] ?? ''));
            $class = trim((string)($classes[$i] ?? ''));
            $target = trim((string)($targets[$i] ?? ''));
            if ($labelKey === '' && !$hasLabels && $url === '') {
                continue;
            }

            $depth = (int)($depths[$i] ?? 1);
            $depth = max(1, min(3, $depth));
            while ($depth > 1 && !isset($stack[$depth - 1])) {
                $depth--;
            }

            $item = [
                'label_key' => $labelKey,
                'labels' => $labels,
                'url' => $url,
                'class' => $class,
                'target' => $target,
            ];

            if ($depth === 1) {
                $roots[] = $item;
                $stack = [1 => count($roots) - 1];
                continue;
            }

            $parentDepth = $depth - 1;
            $parent = &$this->menuNodeByStack($roots, $stack, $parentDepth);
            if (!isset($parent['children']) || !is_array($parent['children'])) {
                $parent['children'] = [];
            }
            $parent['children'][] = $item;
            $stack[$depth] = count($parent['children']) - 1;
            for ($d = $depth + 1; $d <= 3; $d++) {
                unset($stack[$d]);
            }
            unset($parent);
        }

        return $this->normalizeMenuItems($roots);
    }

    /** @param array<int, int> $stack */
    private function &menuNodeByStack(array &$roots, array $stack, int $depth): array
    {
        $ref = &$roots[(int)$stack[1]];
        for ($d = 2; $d <= $depth; $d++) {
            if (!isset($ref['children']) || !is_array($ref['children'])) {
                $ref['children'] = [];
            }
            $ref = &$ref['children'][(int)$stack[$d]];
        }
        return $ref;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function resolveThemeMenus(string $lang, string $currentPath): array
    {
        $locations = $this->settings['menu_locations'] ?? [];
        if (!is_array($locations)) {
            $locations = [];
        }
        foreach ($this->theme->menuLocations() + ['header' => ['default' => 'main'], 'footer' => ['default' => 'footer']] as $location => $definition) {
            if (!isset($locations[$location]) || trim((string)$locations[$location]) === '') {
                $locations[$location] = $definition['default'];
            }
        }

        $resolved = [];
        foreach ($locations as $location => $key) {
            $location = $this->slugify((string)$location);
            $key = $this->slugify((string)$key);
            if ($location === '' || $key === '') {
                continue;
            }
            $menu = $this->loadMenuDefinition($key, $lang);
            $activeItems = $this->applyMenuActiveState($menu['items'] ?? [], $this->normalizeMenuPath($currentPath));
            $resolved[$location] = $this->localizeMenuItems($activeItems, $lang);
        }

        if (empty($resolved['header'] ?? [])) {
            $legacyHeader = $this->settings['menu']['header'] ?? [];
            if (is_array($legacyHeader)) {
                $activeItems = $this->applyMenuActiveState($this->normalizeMenuItems($legacyHeader), $this->normalizeMenuPath($currentPath));
                $resolved['header'] = $this->localizeMenuItems($activeItems, $lang);
            }
        }

        return $resolved;
    }

    /** @param array<int, array<string, mixed>> $items
     *  @return array<int, array<string, mixed>>
     */
    private function localizeMenuItems(array $items, string $lang): array
    {
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'el');
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];
            $label = trim((string)($labels[$lang] ?? ''));
            if ($label === '') {
                $labelKey = trim((string)($item['label_key'] ?? ''));
                if ($labelKey !== '') {
                    $label = $this->translate($labelKey, $labelKey);
                }
            }
            if ($label === '' && $defaultLang !== $lang) {
                $label = trim((string)($labels[$defaultLang] ?? ''));
            }
            if ($label === '') {
                $label = trim((string)($item['label'] ?? ''));
            }
            $item['label'] = $label;
            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->localizeMenuItems($item['children'], $lang);
            }
            $rows[] = $item;
        }
        return $rows;
    }

    /** @param array<int, array<string, mixed>> $items
     *  @return array<int, array<string, mixed>>
     */
    private function applyMenuActiveState(array $items, string $currentPath): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $children = [];
            $hasChildActive = false;
            if (isset($item['children']) && is_array($item['children'])) {
                $children = $this->applyMenuActiveState($item['children'], $currentPath);
                foreach ($children as $child) {
                    if (!is_array($child)) {
                        continue;
                    }
                    if (($child['is_active'] ?? false) || ($child['is_trail'] ?? false)) {
                        $hasChildActive = true;
                        break;
                    }
                }
            }

            $isActive = $this->isMenuUrlActive((string)($item['url'] ?? ''), $currentPath);
            $item['is_active'] = $isActive;
            $item['is_trail'] = $hasChildActive;
            if (!empty($children)) {
                $item['children'] = $children;
            } else {
                unset($item['children']);
            }
            $rows[] = $item;
        }
        return $rows;
    }

    private function isMenuUrlActive(string $url, string $currentPath): bool
    {
        $targetPath = $this->menuTargetPath($url);
        if ($targetPath === null) {
            return false;
        }
        if ($targetPath === '') {
            return $currentPath === '';
        }
        if ($currentPath === $targetPath) {
            return true;
        }
        return str_starts_with($currentPath, $targetPath . '/');
    }

    private function menuTargetPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || $url === '/') {
            return '';
        }

        if (str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $url) === 1) {
            $targetHost = (string)(parse_url($url, PHP_URL_HOST) ?? '');
            $baseHost = (string)(parse_url((string)($this->settings['base_url'] ?? ''), PHP_URL_HOST) ?? '');
            if ($targetHost === '' || $baseHost === '' || strcasecmp($targetHost, $baseHost) !== 0) {
                return null;
            }
            $url = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        }

        return $this->normalizeMenuPath($url);
    }

    private function normalizeMenuPath(string $path): string
    {
        $raw = trim($path);
        if ($raw === '') {
            return '';
        }

        $parsed = parse_url($raw);
        if (is_array($parsed) && array_key_exists('path', $parsed)) {
            $path = trim((string)$parsed['path']);
        } else {
            $path = $raw;
        }

        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $available = $this->settings['languages']['available'] ?? [];
        if (isset($segments[0]) && in_array($segments[0], $available, true)) {
            array_shift($segments);
        }

        if (($segments[0] ?? '') === 'pages') {
            array_shift($segments);
        }

        $homeSlug = $this->slugify((string)($this->settings['home_page'] ?? 'index'));
        $normalized = trim(implode('/', $segments), '/');
        if ($normalized === $homeSlug) {
            return '';
        }

        return $normalized;
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
        $sitemap = $this->buildAbsoluteUrl('sitemap.xml');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Sitemap: " . $sitemap . "\n";
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
        $term = $this->findTaxonomyTermBySlug($key, $slug);
        if ($term === null) {
            $this->render404();
            return;
        }
        $termId = (string)($term['id'] ?? '');
        $termSlug = (string)($term['slug'] ?? $slug);
        $titlePrefix = $kind === 'category'
            ? $this->translate('taxonomy.category', 'Category')
            : $this->translate('taxonomy.tag', 'Tag');
        $label = $this->taxonomyTermLabel($key, $termId, $lang);

        $items = [];
        foreach ($this->content->getTypes() as $type) {
            if ($type === 'pages') {
                continue;
            }
            foreach ($this->content->getItems($type, $lang, false, false) as $item) {
                $values = $this->normalizeMetaList($item->meta[$key] ?? null);
                if (in_array($termId, $values, true)) {
                    $items[] = $item;
                }
            }
        }

        $alternates = $this->buildAlternateUrlsForTaxonomy($kind, $termSlug);
        $this->render($this->resolveTaxonomyTemplate($kind, $slug), [
            'items' => $items,
            'type' => $key,
            'archive_title' => $titlePrefix . ': ' . $label,
            'archive_subtitle' => '',
            'alternate_urls' => $alternates['urls'],
            'alternate_default' => $alternates['default'],
        ] + $viewDefaults);
    }

    private function buildContentPath(string $type, string $slug, string $lang, string $homeSlug, string $defaultLang): string
    {
        $prefix = $lang === $defaultLang ? '' : $lang . '/';

        if ($type === 'pages') {
            if ($slug === $homeSlug) {
                return rtrim($prefix, '/');
            }
            return $prefix . $slug;
        }

        return $prefix . $type . '/' . $slug;
    }

    private function buildArchivePath(string $type, string $lang, string $defaultLang): string
    {
        $prefix = $lang === $defaultLang ? '' : $lang . '/';
        return $prefix . $type;
    }

    private function buildTaxonomyPath(string $taxonomy, string $termId, string $prefix = ''): string
    {
        $kind = $taxonomy === 'categories' ? 'category' : 'tag';
        $slug = $this->taxonomyTermSlug($taxonomy, $termId);
        if ($slug === '') {
            return '';
        }
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return $kind . '/' . $slug;
        }
        return $prefix . '/' . $kind . '/' . $slug;
    }

    /** @return array<string, string> */
    private function buildLanguageLinksForItem(string $type, ContentItem $item): array
    {
        $available = $this->settings['languages']['available'] ?? [];
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        $homeSlug = $this->settings['home_page'] ?? 'index';
        if ($homeSlug === '') {
            $homeSlug = 'index';
        }

        $translationId = (string)($item->meta['translation_id'] ?? '');
        $all = $this->content->getItems($type, null, true, false);
        $matches = [];
        foreach ($all as $candidate) {
            if ($translationId !== '' && (string)($candidate->meta['translation_id'] ?? '') === $translationId) {
                $matches[$candidate->lang] = $candidate;
            }
        }

        $links = [];
        foreach ($available as $lang) {
            $target = $matches[$lang] ?? null;
            if ($target === null && $translationId === '') {
                foreach ($all as $candidate) {
                    if ($candidate->lang === $lang && $candidate->slug === $item->slug) {
                        $target = $candidate;
                        break;
                    }
                }
            }
            if ($target) {
                $path = $this->buildContentPath($type, $target->slug, $lang, $homeSlug, $defaultLang);
                $links[$lang] = $path;
            }
        }

        return $links;
    }

    /** @return array{urls: array<string, string>, default: string} */
    private function buildAlternateUrlsForItem(string $type, string $slug): array
    {
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        $available = $this->settings['languages']['available'] ?? [$defaultLang];
        $homeSlug = $this->settings['home_page'] ?? 'index';
        if ($homeSlug === '') {
            $homeSlug = 'index';
        }

        $urls = [];
        foreach ($available as $lang) {
            $item = $this->content->find($type, $slug, $lang, false);
            if (!$item) {
                continue;
            }
            $path = $this->buildContentPath($type, $slug, $lang, $homeSlug, $defaultLang);
            $urls[$lang] = $this->buildAbsoluteUrl($path);
        }

        return [
            'urls' => $urls,
            'default' => $urls[$defaultLang] ?? '',
        ];
    }

    /** @return array{urls: array<string, string>, default: string} */
    private function buildAlternateUrlsForArchive(string $type): array
    {
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        $available = $this->settings['languages']['available'] ?? [$defaultLang];

        $urls = [];
        foreach ($available as $lang) {
            $items = $this->content->getItems($type, $lang, false);
            if (empty($items)) {
                continue;
            }
            $path = $this->buildArchivePath($type, $lang, $defaultLang);
            $urls[$lang] = $this->buildAbsoluteUrl($path);
        }

        return [
            'urls' => $urls,
            'default' => $urls[$defaultLang] ?? '',
        ];
    }

    /** @return array{urls: array<string, string>, default: string} */
    private function buildAlternateUrlsForTaxonomy(string $kind, string $slug): array
    {
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        $available = $this->settings['languages']['available'] ?? [$defaultLang];
        $slug = $this->slugify($slug);
        $pathKind = $kind === 'category' ? 'category' : 'tag';
        $key = $kind === 'category' ? 'categories' : 'tags';

        $urls = [];
        foreach ($available as $lang) {
            if (!$this->hasTaxonomyItems($key, $slug, $lang)) {
                continue;
            }
            $prefix = $lang === $defaultLang ? '' : $lang . '/';
            $path = $prefix . $pathKind . '/' . $slug;
            $urls[$lang] = $this->buildAbsoluteUrl($path);
        }

        return [
            'urls' => $urls,
            'default' => $urls[$defaultLang] ?? '',
        ];
    }

    private function hasTaxonomyItems(string $key, string $slug, string $lang): bool
    {
        $term = $this->findTaxonomyTermBySlug($key, $slug);
        if ($term === null) {
            return false;
        }
        $termId = (string)($term['id'] ?? '');
        if ($termId === '') {
            return false;
        }
        foreach ($this->content->getTypes() as $type) {
            if ($type === 'pages') {
                continue;
            }
            foreach ($this->content->getItems($type, $lang, false) as $item) {
                $values = $this->normalizeMetaList($item->meta[$key] ?? null);
                if (in_array($termId, $values, true)) {
                    return true;
                }
            }
        }
        return false;
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
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        $value = (string)$value;
        if (str_contains($value, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }
        return [$value];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value > 0;
        }
        if (is_numeric($value)) {
            return (int)$value > 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private function isReservedFrontmatterKey(string $key): bool
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
        foreach ($this->listTaxonomyNames() as $taxonomyName) {
            $reserved[] = $taxonomyName;
        }
        return in_array($key, $reserved, true);
    }

    private function extractCustomFields(array $meta): array
    {
        $custom = [];
        $customFields = $meta['custom_fields'] ?? [];
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                $key = (string)$key;
                if ($key === '' || $this->isReservedFrontmatterKey($key)) {
                    continue;
                }
                $custom[$key] = $this->stringifyCustomValue($value);
            }
        }

        foreach ($meta as $key => $value) {
            $key = (string)$key;
            if ($this->isReservedFrontmatterKey($key) || array_key_exists($key, $custom)) {
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

    private function generateTranslationId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function findTranslationIdBySlug(string $type, string $slug): string
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

    /** @return array<int, array{lang: string, exists: bool, slug: string, title: string, edit_url: string, create_url: string}> */
    private function buildTranslationLinks(string $type, string $slug, string $translationId): array
    {
        $available = $this->settings['languages']['available'] ?? [];
        $items = $this->content->getItems($type, null, true);
        $matches = [];
        $fallback = [];

        foreach ($items as $item) {
            $itemId = (string)($item->meta['translation_id'] ?? '');
            if ($translationId !== '' && $itemId !== '' && $itemId === $translationId) {
                $matches[$item->lang] = $item;
            } elseif ($itemId === '' && $item->slug === $slug) {
                $fallback[$item->lang] = $item;
            }
        }

        $rows = [];
        foreach ($available as $lang) {
            $item = $matches[$lang] ?? $fallback[$lang] ?? null;
            $exists = $item !== null;
            $targetSlug = $item ? $item->slug : $slug;
            $rows[] = [
                'lang' => $lang,
                'exists' => $exists,
                'slug' => $targetSlug,
                'title' => $item ? (string)($item->meta['title'] ?? '') : '',
                'edit_url' => $exists
                    ? '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($targetSlug) . '&lang=' . urlencode($lang)
                    : '',
                'create_url' => $exists
                    ? ''
                    : '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . '&translation_id=' . urlencode($translationId),
            ];
        }

        return $rows;
    }

    /** @param ContentItem[] $items */
    private function buildTranslationLangMatrix(string $type, array $items): array
    {
        $all = $this->content->getItems($type, null, true);
        $langsByKey = [];
        $slugToId = [];
        foreach ($all as $item) {
            $id = (string)($item->meta['translation_id'] ?? '');
            if ($id !== '' && !isset($slugToId[$item->slug])) {
                $slugToId[$item->slug] = $id;
            }
        }
        foreach ($all as $item) {
            $key = $this->translationGroupKey($item, $slugToId);
            $langsByKey[$key] ??= [];
            if (!in_array($item->lang, $langsByKey[$key], true)) {
                $langsByKey[$key][] = $item->lang;
            }
        }

        $matrix = [];
        foreach ($items as $item) {
            $key = $this->translationGroupKey($item, $slugToId);
            $langs = $langsByKey[$key] ?? [];
            $other = array_values(array_filter($langs, fn($lang) => $lang !== $item->lang));
            $matrix[$item->slug . '|' . $item->lang] = $other;
        }

        return $matrix;
    }

    /** @param array<string, string> $slugToId */
    private function translationGroupKey(ContentItem $item, array $slugToId): string
    {
        $id = (string)($item->meta['translation_id'] ?? '');
        if ($id !== '') {
            return 'id:' . $id;
        }
        $fallbackId = $slugToId[$item->slug] ?? '';
        if ($fallbackId !== '') {
            return 'id:' . $fallbackId;
        }
        return 'slug:' . $item->slug;
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

    private function parseCustomValue(string $value): mixed
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

    private function parseCommaList(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $items = array_map('trim', explode(',', $value));
        return array_values(array_filter($items, fn($item) => $item !== ''));
    }

    private function normalizeLanguageList(string $value): array
    {
        $items = $this->parseCommaList($value);
        $items = array_map(fn($item) => $this->slugify((string)$item), $items);
        $items = array_values(array_filter($items, fn($item) => $item !== ''));
        return array_values(array_unique($items));
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

    private function isHiddenTranslationKey(string $key): bool
    {
        return str_starts_with($key, 'nav.');
    }

    private function filterVisibleTranslationKeys(array $translations): array
    {
        $rows = [];
        foreach ($translations as $key => $value) {
            $key = (string)$key;
            if ($this->isHiddenTranslationKey($key)) {
                continue;
            }
            $rows[$key] = (string)$value;
        }
        return $rows;
    }

    private function extractSettingsForm(array $parsed): array
    {
        $defaults = $this->loadSettings();
        $merged = array_replace_recursive($defaults, $parsed);
        $locationsRaw = $merged['menu_locations'] ?? [];
        if (!is_array($locationsRaw)) {
            $locationsRaw = [];
        }
        $menuLocations = [];
        foreach ($locationsRaw as $key => $value) {
            $key = $this->slugify((string)$key);
            $value = $this->slugify((string)$value);
            if ($key === '' || $value === '') {
                continue;
            }
            $menuLocations[$key] = $value;
        }
        if (!isset($menuLocations['header'])) {
            $menuLocations['header'] = 'main';
        }
        if (!isset($menuLocations['footer'])) {
            $menuLocations['footer'] = 'footer';
        }
        $extraLocationKeys = [];
        $extraLocationValues = [];
        foreach ($menuLocations as $key => $value) {
            if (in_array($key, ['header', 'footer'], true)) {
                continue;
            }
            $extraLocationKeys[] = $key;
            $extraLocationValues[] = $value;
        }
        $backupSchedule = (string)($merged['backup']['auto']['schedule'] ?? 'daily');
        if (!in_array($backupSchedule, ['daily', 'weekly', 'monthly'], true)) {
            $backupSchedule = 'daily';
        }
        $backupKeep = (int)($merged['backup']['local']['keep'] ?? 20);
        if ($backupKeep < 1) {
            $backupKeep = 1;
        }
        $remoteProvider = (string)($merged['backup']['remote']['provider'] ?? 'custom');
        if (!in_array($remoteProvider, $this->backupRemoteProviders(), true)) {
            $remoteProvider = 'custom';
        }
        $remoteKeep = (int)($merged['backup']['remote']['keep'] ?? 20);
        if ($remoteKeep < 1) {
            $remoteKeep = 1;
        }

        return [
            'title' => (string)($merged['title'] ?? ''),
            'tagline' => (string)($merged['tagline'] ?? ''),
            'base_url' => (string)($merged['base_url'] ?? ''),
            'theme' => (string)($merged['theme'] ?? ''),
            'home_page' => (string)($merged['home_page'] ?? ''),
            'date_format' => (string)($merged['date_format'] ?? ''),
            'languages_default' => (string)($merged['languages']['default'] ?? 'el'),
            'languages_available' => implode(', ', $merged['languages']['available'] ?? []),
            'mail_driver' => (string)($merged['forms']['notifications']['driver'] ?? 'smtp'),
            'mail_from' => (string)($merged['forms']['notifications']['from'] ?? ''),
            'mail_from_name' => (string)($merged['forms']['notifications']['from_name'] ?? ''),
            'smtp_host' => (string)($merged['forms']['notifications']['smtp']['host'] ?? ''),
            'smtp_port' => (string)($merged['forms']['notifications']['smtp']['port'] ?? ''),
            'smtp_user' => (string)($merged['forms']['notifications']['smtp']['username'] ?? ''),
            'smtp_encryption' => (string)($merged['forms']['notifications']['smtp']['encryption'] ?? ''),
            'ses_key' => (string)($merged['forms']['notifications']['ses']['key'] ?? ''),
            'ses_region' => (string)($merged['forms']['notifications']['ses']['region'] ?? ''),
            'menu_location_header' => (string)($menuLocations['header'] ?? 'main'),
            'menu_location_footer' => (string)($menuLocations['footer'] ?? 'footer'),
            'menu_location_keys' => $extraLocationKeys,
            'menu_location_values' => $extraLocationValues,
            'backup_auto_enabled' => $this->isTruthy($merged['backup']['auto']['enabled'] ?? false),
            'backup_schedule' => $backupSchedule,
            'backup_last_run' => (string)($merged['backup']['auto']['last_run'] ?? ''),
            'backup_keep_local' => (string)$backupKeep,
            'backup_remote_enabled' => $this->isTruthy($merged['backup']['remote']['enabled'] ?? false),
            'backup_remote_provider' => $remoteProvider,
            'backup_remote_endpoint' => (string)($merged['backup']['remote']['endpoint'] ?? ''),
            'backup_remote_region' => (string)($merged['backup']['remote']['region'] ?? ''),
            'backup_remote_bucket' => (string)($merged['backup']['remote']['bucket'] ?? ''),
            'backup_remote_access_key' => (string)($merged['backup']['remote']['access_key'] ?? ''),
            'backup_remote_prefix' => (string)($merged['backup']['remote']['prefix'] ?? ''),
            'backup_remote_keep' => (string)$remoteKeep,
            'backup_remote_path_style' => $this->isTruthy($merged['backup']['remote']['path_style'] ?? false),
            'google_enabled' => $this->isTruthy($merged['auth']['google']['enabled'] ?? false),
            'google_client_id' => (string)($merged['auth']['google']['client_id'] ?? ''),
            'google_allowed_domain' => (string)($merged['auth']['google']['allowed_domain'] ?? ''),
            'update_repository' => (string)($merged['updates']['repository'] ?? 'chiotis/faroscms'),
            'update_branch' => (string)($merged['updates']['branch'] ?? 'main'),
            'update_version_url' => (string)($merged['updates']['version_url'] ?? ''),
            'update_changelog_url' => (string)($merged['updates']['changelog_url'] ?? ''),
            'update_package_url' => (string)($merged['updates']['package_url'] ?? ''),
        ] + $this->maskedSettingsSecrets($merged);
    }

    /**
     * Secrets never travel back to the browser; the form only learns whether one is stored.
     *
     * @return array<string, string|bool>
     */
    private function maskedSettingsSecrets(array $settings): array
    {
        $form = [];
        foreach ($this->settingsSecretPaths() as $field => $path) {
            $form[$field] = '';
            $form[$field . '_set'] = trim((string)$this->readArrayPath($settings, $path)) !== '';
        }
        return $form;
    }

    /** @return array<string, array<int, string>> form field => settings path */
    private function settingsSecretPaths(): array
    {
        return [
            'smtp_pass' => ['forms', 'notifications', 'smtp', 'password'],
            'ses_secret' => ['forms', 'notifications', 'ses', 'secret'],
            'google_client_secret' => ['auth', 'google', 'client_secret'],
            'backup_remote_secret_key' => ['backup', 'remote', 'secret_key'],
            'update_github_token' => ['updates', 'github_token'],
        ];
    }

    private function readArrayPath(array $source, array $path): mixed
    {
        $node = $source;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    private function saveSettings(string $raw, array $form): bool
    {
        if (!$this->systemDatabase->isAvailable()) {
            return false;
        }

        foreach ($form as $key => $value) {
            if (is_array($value)) {
                $form[$key] = array_map(fn($item) => trim((string)$item), $value);
            } else {
                $form[$key] = trim((string)$value);
            }
        }
        $data = $this->parseSettingsYaml($raw);
        $existingSecrets = [];
        foreach ($this->settingsSecretPaths() as $field => $path) {
            $existingSecrets[$field] = (string)($this->readArrayPath($data, $path) ?? '');
        }

        $data['title'] = $form['title'] !== '' ? $form['title'] : ($data['title'] ?? 'FarosCMS');
        $data['tagline'] = $form['tagline'] !== '' ? $form['tagline'] : ($data['tagline'] ?? '');
        $data['base_url'] = $form['base_url'];
        $data['theme'] = $form['theme'] !== '' ? $form['theme'] : ($data['theme'] ?? 'default');
        $data['home_page'] = $form['home_page'] !== '' ? $form['home_page'] : ($data['home_page'] ?? 'index');
        $data['date_format'] = $form['date_format'] !== '' ? $form['date_format'] : ($data['date_format'] ?? 'd/m/Y');

        $available = $this->normalizeLanguageList($form['languages_available']);
        if (empty($available) && isset($data['languages']['available']) && is_array($data['languages']['available'])) {
            $available = array_values(array_filter(array_map('strval', $data['languages']['available'])));
        }
        if (empty($available)) {
            $available = ['el', 'en'];
        }

        $defaultLang = $this->slugify($form['languages_default']);
        if ($defaultLang === '' && isset($data['languages']['default'])) {
            $defaultLang = (string)$data['languages']['default'];
        }
        if ($defaultLang === '') {
            $defaultLang = $available[0] ?? 'el';
        }
        if (!in_array($defaultLang, $available, true)) {
            array_unshift($available, $defaultLang);
            $available = array_values(array_unique($available));
        }

        $data['languages'] = [
            'default' => $defaultLang,
            'available' => $available,
        ];

        $data['forms']['notifications'] = $data['forms']['notifications'] ?? [];
        $driver = $form['mail_driver'] !== '' ? $form['mail_driver'] : ($data['forms']['notifications']['driver'] ?? 'smtp');
        if (!in_array($driver, ['smtp', 'ses'], true)) {
            $driver = 'smtp';
        }
        $data['forms']['notifications']['driver'] = $driver;
        $data['forms']['notifications']['from'] = $form['mail_from'];
        $data['forms']['notifications']['from_name'] = $form['mail_from_name'];
        $data['forms']['notifications']['smtp'] = [
            'host' => $form['smtp_host'],
            'port' => $form['smtp_port'] !== '' ? (int)$form['smtp_port'] : '',
            'username' => $form['smtp_user'],
            'password' => $form['smtp_pass'],
            'encryption' => $form['smtp_encryption'],
        ];
        $data['forms']['notifications']['ses'] = [
            'key' => $form['ses_key'],
            'secret' => $form['ses_secret'],
            'region' => $form['ses_region'],
        ];

        $locations = [];
        $headerMenu = $this->slugify((string)($form['menu_location_header'] ?? ''));
        $footerMenu = $this->slugify((string)($form['menu_location_footer'] ?? ''));
        $locations['header'] = $headerMenu !== '' ? $headerMenu : 'main';
        $locations['footer'] = $footerMenu !== '' ? $footerMenu : 'footer';
        $extraKeys = $form['menu_location_keys'] ?? [];
        $extraValues = $form['menu_location_values'] ?? [];
        if (is_array($extraKeys) && is_array($extraValues)) {
            foreach ($extraKeys as $index => $key) {
                $k = $this->slugify((string)$key);
                $v = $this->slugify((string)($extraValues[$index] ?? ''));
                if ($k === '' || $v === '' || in_array($k, ['header', 'footer'], true)) {
                    continue;
                }
                $locations[$k] = $v;
            }
        }
        $data['menu_locations'] = $locations;
        unset($data['menu']);

        $backupSchedule = strtolower((string)($form['backup_schedule'] ?? 'daily'));
        if (!in_array($backupSchedule, ['daily', 'weekly', 'monthly'], true)) {
            $backupSchedule = 'daily';
        }
        $backupAutoEnabled = $this->isTruthy($form['backup_auto_enabled'] ?? false);
        $backupKeep = (int)($form['backup_keep_local'] ?? 20);
        if ($backupKeep < 1) {
            $backupKeep = 1;
        }
        $backupLastRun = (string)($data['backup']['auto']['last_run'] ?? '');
        $remoteProvider = strtolower((string)($form['backup_remote_provider'] ?? 'custom'));
        if (!in_array($remoteProvider, $this->backupRemoteProviders(), true)) {
            $remoteProvider = 'custom';
        }
        $remoteKeep = (int)($form['backup_remote_keep'] ?? 20);
        if ($remoteKeep < 1) {
            $remoteKeep = 1;
        }

        $data['backup'] = [
            'auto' => [
                'enabled' => $backupAutoEnabled,
                'schedule' => $backupSchedule,
                'last_run' => $backupLastRun,
            ],
            'local' => [
                'keep' => $backupKeep,
            ],
            'remote' => [
                'enabled' => $this->isTruthy($form['backup_remote_enabled'] ?? false),
                'provider' => $remoteProvider,
                'endpoint' => (string)($form['backup_remote_endpoint'] ?? ''),
                'region' => (string)($form['backup_remote_region'] ?? ''),
                'bucket' => (string)($form['backup_remote_bucket'] ?? ''),
                'access_key' => (string)($form['backup_remote_access_key'] ?? ''),
                'secret_key' => (string)($form['backup_remote_secret_key'] ?? ''),
                'prefix' => trim((string)($form['backup_remote_prefix'] ?? ''), '/'),
                'keep' => $remoteKeep,
                'path_style' => $this->isTruthy($form['backup_remote_path_style'] ?? false),
            ],
        ];

        $data['auth']['google'] = [
            'enabled' => $this->isTruthy($form['google_enabled'] ?? false),
            'client_id' => (string)($form['google_client_id'] ?? ''),
            'client_secret' => (string)($form['google_client_secret'] ?? ''),
            'allowed_domain' => strtolower(trim((string)($form['google_allowed_domain'] ?? ''))),
        ];

        $existingUpdates = is_array($data['updates'] ?? null) ? $data['updates'] : [];
        $repository = trim((string)($form['update_repository'] ?? ''));
        if ($repository === '') {
            $repository = 'chiotis/faroscms';
        }
        $branch = trim((string)($form['update_branch'] ?? ''));
        if ($branch === '') {
            $branch = 'main';
        }
        $data['updates'] = [
            'channel' => (string)($existingUpdates['channel'] ?? 'stable'),
            'repository' => $repository,
            'branch' => $branch,
            'version_url' => (string)($form['update_version_url'] ?? ''),
            'changelog_url' => (string)($form['update_changelog_url'] ?? ''),
            'package_url' => (string)($form['update_package_url'] ?? ''),
            'github_token' => '',
            'latest_version' => (string)($existingUpdates['latest_version'] ?? ''),
        ];

        // Blank secret inputs keep the stored value; only an explicit "remove" clears it.
        $clear = is_array($form['clear_secrets'] ?? null) ? $form['clear_secrets'] : [];
        foreach ($this->settingsSecretPaths() as $field => $path) {
            $submitted = (string)($form[$field] ?? '');
            if (in_array($field, $clear, true)) {
                $value = '';
            } elseif ($submitted !== '') {
                $value = $submitted;
            } else {
                $value = $existingSecrets[$field] ?? '';
            }
            $this->setArrayPath($data, $path, $value);
        }

        $this->setSystemMeta('site_settings', Yaml::dump($data, 4, 2));
        return true;
    }

    /** @return string[] */
    private function backupRemoteProviders(): array
    {
        return ['custom', 'aws_s3', 'backblaze_b2', 'cloudflare_r2', 'wasabi', 'digitalocean_spaces', 'minio'];
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

    /** @return array<string, mixed> */
    private function googleAuthSettings(): array
    {
        $config = $this->settings['auth']['google'] ?? [];
        if (!is_array($config)) {
            $config = [];
        }
        $clientId = trim((string)($config['client_id'] ?? ''));
        $clientSecret = trim((string)($config['client_secret'] ?? ''));
        $enabled = $this->isTruthy($config['enabled'] ?? false);
        $allowedDomain = strtolower(trim((string)($config['allowed_domain'] ?? '')));
        return [
            'enabled' => $enabled,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'allowed_domain' => $allowedDomain,
            'redirect_uri' => $this->buildAbsoluteUrl('/admin/google-callback'),
            'ready' => $enabled && $clientId !== '' && $clientSecret !== '',
        ];
    }

    /** @param array<string, mixed> $google */
    private function googleTokenRequest(string $code, array $google): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'PHP cURL is required for Google Sign-In.'];
        }
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => (string)$google['client_id'],
                'client_secret' => (string)$google['client_secret'],
                'redirect_uri' => (string)$google['redirect_uri'],
                'grant_type' => 'authorization_code',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            return ['ok' => false, 'message' => 'Google token exchange failed.' . ($error !== '' ? ' ' . $error : '')];
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return ['ok' => false, 'message' => 'Google token response was invalid.'];
        }
        return ['ok' => true, 'access_token' => (string)$data['access_token']];
    }

    private function googleUserInfo(string $accessToken): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'PHP cURL is required for Google Sign-In.'];
        }
        $ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            return ['ok' => false, 'message' => 'Google profile lookup failed.'];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Google profile response was invalid.'];
        }
        $data['ok'] = true;
        return $data;
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
        $value = $this->transliterateGreek(trim($value));
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\-\_]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value;
    }

    private function transliterateGreek(string $value): string
    {
        $replacements = [
            'Ά' => 'a', 'Έ' => 'e', 'Ή' => 'i', 'Ί' => 'i', 'Ό' => 'o', 'Ύ' => 'y', 'Ώ' => 'o', 'Ϊ' => 'i', 'Ϋ' => 'y',
            'Α' => 'a', 'Β' => 'v', 'Γ' => 'g', 'Δ' => 'd', 'Ε' => 'e', 'Ζ' => 'z', 'Η' => 'i', 'Θ' => 'th', 'Ι' => 'i',
            'Κ' => 'k', 'Λ' => 'l', 'Μ' => 'm', 'Ν' => 'n', 'Ξ' => 'x', 'Ο' => 'o', 'Π' => 'p', 'Ρ' => 'r', 'Σ' => 's',
            'Τ' => 't', 'Υ' => 'y', 'Φ' => 'f', 'Χ' => 'ch', 'Ψ' => 'ps', 'Ω' => 'o',
            'ά' => 'a', 'έ' => 'e', 'ή' => 'i', 'ί' => 'i', 'ό' => 'o', 'ύ' => 'y', 'ώ' => 'o', 'ϊ' => 'i', 'ϋ' => 'y', 'ΐ' => 'i', 'ΰ' => 'y',
            'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i', 'θ' => 'th', 'ι' => 'i',
            'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's',
            'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o',
        ];

        return strtr($value, $replacements);
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
        return trim(ucwords(str_replace(['-', '_'], ' ', $slug)));
    }

    private function sanitizeFilename(string $name): string
    {
        $name = $this->transliterateGreek(trim($name));
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9\\.\\-_]+/', '-', $name) ?? '';
        $name = trim($name, '-');
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $base = (string)pathinfo($name, PATHINFO_FILENAME);
        if ($base === '' || $base === '.') {
            $base = 'file';
        }
        return $base . ($ext !== '' ? '.' . $ext : '');
    }

    private function sanitizeSettingsTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        $allowed = ['basics', 'menus', 'apis', 'theme', 'smtp', 'auth', 'backup', 'updates'];
        if (!in_array($tab, $allowed, true)) {
            return 'basics';
        }
        return $tab;
    }

    private function backupDirectory(): string
    {
        return $this->backups->directory();
    }

    private function maybeRunScheduledBackup(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        if (!$this->systemDatabase->isAvailable()) {
            // Without SQLite the last run cannot be persisted, so every request would start a new backup.
            return;
        }
        $auto = $this->settings['backup']['auto'] ?? [];
        if (!is_array($auto) || !$this->isTruthy($auto['enabled'] ?? false)) {
            return;
        }
        $schedule = strtolower((string)($auto['schedule'] ?? 'daily'));
        if (!in_array($schedule, ['daily', 'weekly', 'monthly'], true)) {
            return;
        }

        $lastRun = trim((string)($auto['last_run'] ?? ''));
        $lastTimestamp = $lastRun !== '' ? (int)strtotime($lastRun) : 0;
        if (!$this->isBackupDue($lastTimestamp, $schedule)) {
            return;
        }

        $this->backups->ensureDirectory();
        $lockPath = $this->backupDirectory() . '/.auto-backup.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if (!$lockHandle) {
            return;
        }
        if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return;
        }

        $result = $this->createBackupSnapshot('scheduled');
        if (($result['ok'] ?? false) === true) {
            $this->updateBackupLastRun(date('c'));
        }
        $this->recordBackupRun($result);
        $this->notifyBackupResult($result, true);

        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    private function isBackupDue(int $lastTimestamp, string $schedule): bool
    {
        if ($lastTimestamp <= 0) {
            return true;
        }
        $interval = match ($schedule) {
            'daily' => 86400,
            'weekly' => 604800,
            'monthly' => 2592000,
            default => PHP_INT_MAX,
        };
        return (time() - $lastTimestamp) >= $interval;
    }

    /**
     * Full snapshot + local retention + optional remote upload.
     * Pre-restore safety snapshots skip pruning so the archive being restored is never deleted.
     *
     * @return array{ok: bool, message: string, filename?: string, status?: string}
     */
    private function createBackupSnapshot(string $reason = 'manual', bool $prune = true, bool $uploadRemote = true): array
    {
        $result = $this->backups->createFullSnapshot($this->backupSiteSlug(), $this->backupMeta($reason));
        return $this->finishBackup($result, $prune, $uploadRemote);
    }

    /** @return array{ok: bool, message: string, filename?: string, status?: string} */
    private function createDatabaseBackupSnapshot(string $reason = 'manual'): array
    {
        $result = $this->backups->createDatabaseSnapshot($this->backupSiteSlug(), $this->backupMeta($reason));
        return $this->finishBackup($result, true, true);
    }

    private function finishBackup(array $result, bool $prune, bool $uploadRemote): array
    {
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }
        if ($prune) {
            $this->backups->prune($this->localBackupKeep());
        }
        $remote = $uploadRemote ? $this->uploadBackupToRemote((string)$result['path'], (string)$result['filename']) : null;
        return $this->buildBackupResult((string)$result['message'], (string)$result['filename'], $remote);
    }

    private function backupSiteSlug(): string
    {
        $slug = $this->slugify((string)($this->settings['title'] ?? 'site'));
        return $slug !== '' ? $slug : 'site';
    }

    /** @return array{reason: string, version: string, commit: string} */
    private function backupMeta(string $reason): array
    {
        $updates = $this->updates();
        return [
            'reason' => $reason,
            'version' => $updates->currentVersion(),
            'commit' => $updates->currentGitCommit(),
        ];
    }

    private function localBackupKeep(): int
    {
        return max(1, (int)($this->settings['backup']['local']['keep'] ?? 20));
    }

    private function recordBackupRun(array $result): void
    {
        try {
            $filename = (string)($result['filename'] ?? '');
            $size = 0;
            if ($filename !== '') {
                $path = $this->backups->pathFor($filename);
                $size = $path !== null ? (int)(filesize($path) ?: 0) : 0;
            }
            $this->backupRuns->record([
                'filename' => $filename,
                'status' => $this->backupResultStatus($result),
                'size_bytes' => $size,
                'message' => (string)($result['message'] ?? ''),
            ]);
        } catch (\Throwable) {
            // Backup run history must never block the backup workflow.
        }
    }

    /** @return array{ok: bool, message: string, object_key?: string, pruned?: int}|null */
    private function uploadBackupToRemote(string $path, string $filename): ?array
    {
        $remoteSettings = $this->settings['backup']['remote'] ?? [];
        if (!is_array($remoteSettings) || !$this->isTruthy($remoteSettings['enabled'] ?? false)) {
            return null;
        }

        $storage = new S3BackupStorage($remoteSettings);
        $upload = $storage->upload($path, $filename);
        if (($upload['ok'] ?? false) === true) {
            $keep = (int)($remoteSettings['keep'] ?? 20);
            $prune = $storage->prune($keep > 0 ? $keep : 20);
            if (($prune['ok'] ?? false) === true) {
                $upload['pruned'] = (int)($prune['deleted'] ?? 0);
            }
        }

        return $upload;
    }

    /**
     * The local archive decides `ok`; a failed remote upload only downgrades the run to a warning,
     * so schedules still advance and the local snapshot is not reported as lost.
     *
     * @param array<string, mixed>|null $remote
     * @return array{ok: bool, status: string, message: string, filename: string, remote: array<string, mixed>|null}
     */
    private function buildBackupResult(string $message, string $filename, ?array $remote): array
    {
        $remoteFailed = $remote !== null && (($remote['ok'] ?? false) !== true);
        return [
            'ok' => true,
            'status' => $remoteFailed ? 'warning' : 'success',
            'message' => $message . $this->remoteBackupMessage($remote),
            'filename' => $filename,
            'remote' => $remote,
        ];
    }

    private function backupLogLevel(array $result): string
    {
        return match ($this->backupResultStatus($result)) {
            'warning' => 'warning',
            'success' => 'info',
            default => 'error',
        };
    }

    private function backupResultQueryStatus(array $result): string
    {
        return match ($this->backupResultStatus($result)) {
            'warning' => 'warn',
            'success' => 'ok',
            default => 'fail',
        };
    }

    private function backupResultStatus(array $result): string
    {
        if (($result['ok'] ?? false) !== true) {
            return 'failed';
        }
        return (string)($result['status'] ?? 'success') === 'warning' ? 'warning' : 'success';
    }

    /** @param array<string, mixed>|null $remote */
    private function remoteBackupMessage(?array $remote): string
    {
        if ($remote === null) {
            return '';
        }
        if (($remote['ok'] ?? false) === true) {
            $suffix = ' Remote upload completed.';
            if (isset($remote['object_key']) && trim((string)$remote['object_key']) !== '') {
                $suffix .= ' Object: ' . (string)$remote['object_key'] . '.';
            }
            if (isset($remote['pruned']) && (int)$remote['pruned'] > 0) {
                $suffix .= ' Pruned ' . (int)$remote['pruned'] . ' remote backup(s).';
            }
            return $suffix;
        }

        return ' Saved locally, but remote upload failed: ' . (string)($remote['message'] ?? 'Remote storage error.');
    }

    /** @return array{ok: bool, message: string} */
    private function testRemoteBackupStorage(): array
    {
        $remoteSettings = $this->settings['backup']['remote'] ?? [];
        if (!is_array($remoteSettings)) {
            return ['ok' => false, 'message' => 'Remote backup settings are missing.'];
        }

        return (new S3BackupStorage($remoteSettings))->testConnection();
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

    private function maxUploadBytes(): int
    {
        return max(0, (int)($this->settings['media']['max_upload_mb'] ?? 20)) * 1024 * 1024;
    }

    private function formatFileSize(int $bytes): string
    {
        return Format::bytes($bytes);
    }

    private function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $size += (int)$fileInfo->getSize();
            }
        }
        return $size;
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
        $raw = $this->loadSettingsRaw('site_settings', $this->defaultSettings());
        $data = $this->parseSettingsYaml($raw);
        $data['backup']['auto']['last_run'] = $isoDate;
        $yaml = Yaml::dump($data, 4, 2);
        if ($this->systemDatabase->isAvailable()) {
            $this->setSystemMeta('site_settings', $yaml);
        }
        $this->settings = $this->loadSettings();
    }

    /** @return string[] */
    private function formFieldTypes(): array
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

    /** @return array<int, array<string, mixed>> */
    private function normalizeFormFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $normalized = [];
        $allowedTypes = $this->formFieldTypes();
        foreach ($fields as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $name = $this->sanitizeFormFieldName((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                $label = $this->titleFromSlug($name);
            }
            $field = [
                'type' => $type,
                'name' => $name,
                'label' => $label,
                'required' => $this->isTruthy($row['required'] ?? false),
                'placeholder' => (string)($row['placeholder'] ?? ''),
                'help' => (string)($row['help'] ?? ''),
                'default' => $row['default'] ?? '',
                'options' => $this->normalizeFormOptions($row['options'] ?? []),
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
    private function normalizeFormFieldsForAdmin(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $normalized = [];
        $allowedTypes = $this->formFieldTypes();
        foreach ($fields as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $name = $this->sanitizeFormFieldName((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                $label = $this->titleFromSlug($name);
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
                'required' => $this->isTruthy($row['required'] ?? false),
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

    /** @return array<int, array<string, mixed>> */
    private function parseFormFieldsInput(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $fields = [];
        $allowedTypes = $this->formFieldTypes();
        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $name = $this->sanitizeFormFieldName((string)($row['name'] ?? ''));
            if ($name === '' || str_starts_with($name, 'form_')) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                $label = $this->titleFromSlug($name);
            }
            $field = [
                'type' => $type,
                'name' => $name,
                'label' => $label,
            ];
            if ($this->isTruthy($row['required'] ?? false)) {
                $field['required'] = true;
            }
            $placeholder = trim((string)($row['placeholder'] ?? ''));
            if ($placeholder !== '') {
                $field['placeholder'] = $placeholder;
            }
            $options = $this->parseFormOptions((string)($row['options'] ?? ''));
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

    private function sanitizeFormFieldName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace(' ', '-', $value);
        return $this->slugify($value);
    }

    /** @return string[] */
    private function parseFormOptions(string $value): array
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

    /** @return array<int, array{value: string, label: string}> */
    private function normalizeFormOptions(mixed $value): array
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

    /** @return array{fields: array, values: array, errors: array, success: bool, message: string, action: string, honeypot: string, redirect: string} */
    private function handleFormRequest(ContentItem $form, string $lang, string $currentPath): array
    {
        $fields = $this->normalizeFormFields($form->meta['fields'] ?? []);
        $values = $this->defaultFormValues($fields);
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

        $values = $this->collectFormValues($fields, $_POST, $errors);
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
            $this->storeFormSubmission($form, $values);
        }

        $this->sendFormNotifications($form, $values, $fields);
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

    /** @return array<string, mixed> */
    private function defaultFormValues(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $default = $field['default'] ?? '';
            if (($field['type'] ?? '') === 'checkboxes') {
                if (is_array($default)) {
                    $values[$name] = $default;
                } else {
                    $values[$name] = $this->parseFormOptions((string)$default);
                }
                continue;
            }
            if (($field['type'] ?? '') === 'checkbox') {
                $values[$name] = $this->isTruthy($default) ? '1' : '';
                continue;
            }
            $values[$name] = $default;
        }
        return $values;
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function collectFormValues(array $fields, array $payload, array &$errors): array
    {
        $values = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = (string)($field['type'] ?? 'text');
            $required = (bool)($field['required'] ?? false);
            $options = $field['options'] ?? [];
            $optionValues = array_map(fn ($opt) => $opt['value'], is_array($options) ? $options : []);

            if ($type === 'checkboxes') {
                $raw = $payload[$name] ?? [];
                $rawValues = is_array($raw) ? $raw : [];
                $clean = [];
                foreach ($rawValues as $value) {
                    $value = trim((string)$value);
                    if ($value === '') {
                        continue;
                    }
                    if (!empty($optionValues) && !in_array($value, $optionValues, true)) {
                        continue;
                    }
                    $clean[] = $value;
                }
                $values[$name] = $clean;
                if ($required && empty($clean)) {
                    $errors[$name] = $this->translate('form.error.required', 'This field is required.');
                }
                continue;
            }

            if ($type === 'checkbox') {
                $checked = isset($payload[$name]) && (string)($payload[$name]) !== '';
                $values[$name] = $checked ? '1' : '';
                if ($required && !$checked) {
                    $errors[$name] = $this->translate('form.error.required', 'This field is required.');
                }
                continue;
            }

            $value = trim((string)($payload[$name] ?? ''));
            if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$name] = $this->translate('form.error.email', 'Please enter a valid email.');
            }
            if ($type === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                $errors[$name] = $this->translate('form.error.url', 'Please enter a valid URL.');
            }
            if (in_array($type, ['number', 'range'], true) && $value !== '' && !is_numeric($value)) {
                $errors[$name] = $this->translate('form.error.numeric', 'Please enter a numeric value.');
            }
            if (in_array($type, ['select', 'radio'], true) && $value !== '' && !empty($optionValues) && !in_array($value, $optionValues, true)) {
                $errors[$name] = $this->translate('form.error.option', 'Please select a valid option.');
            }
            if ($required && $value === '') {
                $errors[$name] = $this->translate('form.error.required', 'This field is required.');
            }
            $values[$name] = $value;
        }
        return $values;
    }

    private function storeFormSubmission(ContentItem $form, array $values): void
    {
        $this->formSubmissions->store($form->slug, [
            'form' => $form->slug,
            'lang' => $form->lang,
            'translation_id' => (string)($form->meta['translation_id'] ?? ''),
            'submitted_at' => date('c'),
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'fields' => $values,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function listFormSubmissions(string $slug): array
    {
        $rawEntries = $this->loadFormSubmissionsRaw($slug);
        $entries = [];
        foreach ($rawEntries as $data) {
            $fields = [];
            foreach (($data['fields'] ?? []) as $key => $value) {
                $fields[] = [
                    'label' => $this->titleFromSlug((string)$key),
                    'value' => $this->stringifySubmissionValue($value),
                ];
            }
            $entries[] = [
                'id' => (string)($data['id'] ?? ''),
                'submitted_at' => $this->formatSubmissionDate((string)($data['submitted_at'] ?? '')),
                'title' => $this->submissionTitle($data['fields'] ?? []),
                'fields' => $fields,
            ];
        }
        usort($entries, function (array $a, array $b): int {
            return strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? ''));
        });
        return $entries;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadFormSubmissionsRaw(string $slug): array
    {
        return $this->formSubmissions->all($slug);
    }

    private function submissionTitle(mixed $fields): string
    {
        if (is_array($fields)) {
            foreach (['name', 'full_name', 'email'] as $key) {
                if (isset($fields[$key]) && trim((string)$fields[$key]) !== '') {
                    return trim((string)$fields[$key]);
                }
            }
        }
        return 'Submission';
    }

    private function formatSubmissionDate(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }
        return date('Y-m-d H:i', $timestamp);
    }

    /** @return array<string, string> */
    private function flattenMetaForCsv(array $meta, string $prefix = ''): array
    {
        $flat = [];
        foreach ($meta as $key => $value) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if ($this->isAssocArray($value)) {
                    $flat += $this->flattenMetaForCsv($value, $path);
                } else {
                    $flat[$path] = json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                }
                continue;
            }
            if (is_bool($value)) {
                $flat[$path] = $value ? 'true' : 'false';
                continue;
            }
            if ($value === null) {
                $flat[$path] = '';
                continue;
            }
            if (is_scalar($value)) {
                $flat[$path] = (string)$value;
                continue;
            }
            $flat[$path] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return $flat;
    }

    private function isAssocArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /** @return array{ok: bool, error?: string, headers?: array<int, string>, rows?: array<int, array<string, string>>} */
    private function parseContentImportCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Could not open CSV file.'];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV is empty.'];
        }
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (!is_array($headers) || empty($headers)) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV headers are invalid.'];
        }

        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            $header = (string)$header;
            if ($index === 0) {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            }
            $normalizedHeaders[] = $this->normalizeCsvHeader($header);
        }

        if (!in_array('language', $normalizedHeaders, true)) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV must contain a language column.'];
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $row = [];
            foreach ($normalizedHeaders as $i => $header) {
                if (!isset($line[$i])) {
                    $row[$header] = '';
                    continue;
                }
                $value = (string)$line[$i];
                $row[$header] = $header === 'body' ? $value : trim($value);
            }
            $isEmpty = true;
            foreach ($row as $value) {
                if ($value !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if (!$isEmpty) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return [
            'ok' => true,
            'headers' => $normalizedHeaders,
            'rows' => $rows,
        ];
    }

    private function detectCsvDelimiter(string $line): string
    {
        $comma = substr_count($line, ',');
        $semi = substr_count($line, ';');
        return $semi > $comma ? ';' : ',';
    }

    private function normalizeCsvHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(' ', '_', $value);
        return preg_replace('/[^a-z0-9._-]/', '', $value) ?? '';
    }

    /** @param array<int, array<string, string>> $rows @param array<int, string> $headers */
    private function buildContentImportPreview(string $type, array $rows, array $headers): array
    {
        $availableLanguages = $this->settings['languages']['available'] ?? [];
        $existingItems = $this->content->getItems($type, null, true, false);
        $bySlugLang = [];
        $byTranslationLang = [];
        foreach ($existingItems as $item) {
            $slugKey = $item->slug . '|' . $item->lang;
            $bySlugLang[$slugKey] = $item;
            $translationId = trim((string)($item->meta['translation_id'] ?? ''));
            if ($translationId !== '') {
                $byTranslationLang[$translationId . '|' . $item->lang] = $item;
            }
        }

        $rowsOut = [];
        $entries = [];
        $summary = [
            'create' => 0,
            'update' => 0,
            'skip' => 0,
            'error' => 0,
        ];
        $seenTargets = [];

        foreach ($rows as $index => $row) {
            $lineNo = $index + 2;
            $csvType = $this->sanitizeType((string)($row['content_type'] ?? $type));
            if ($csvType !== $type) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => (string)($row['slug'] ?? ''),
                    'lang' => (string)($row['language'] ?? ''),
                    'title' => (string)($row['title'] ?? ''),
                    'message' => 'content_type mismatch: expected ' . $type . ', got ' . ($csvType ?: '(empty)') . '.',
                ];
                $summary['error']++;
                continue;
            }

            $lang = strtolower(trim((string)($row['language'] ?? '')));
            if (!in_array($lang, $availableLanguages, true)) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => (string)($row['slug'] ?? ''),
                    'lang' => $lang,
                    'title' => (string)($row['title'] ?? ''),
                    'message' => 'Invalid language.',
                ];
                $summary['error']++;
                continue;
            }

            $slug = $this->slugify((string)($row['slug'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if ($slug === '' && $title !== '') {
                $slug = $this->slugify($title);
            }
            if ($slug === '') {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => '',
                    'lang' => $lang,
                    'title' => $title,
                    'message' => 'Missing slug/title.',
                ];
                $summary['error']++;
                continue;
            }

            $translationId = trim((string)($row['translation_id'] ?? ''));
            $matchByTranslation = null;
            if ($translationId !== '') {
                $matchByTranslation = $byTranslationLang[$translationId . '|' . $lang] ?? null;
            }
            $matchBySlug = $bySlugLang[$slug . '|' . $lang] ?? null;

            if ($matchByTranslation !== null && $matchBySlug !== null && $matchByTranslation->filePath !== $matchBySlug->filePath) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => $slug,
                    'lang' => $lang,
                    'title' => $title,
                    'message' => 'Conflict: translation_id and slug point to different items.',
                ];
                $summary['error']++;
                continue;
            }

            $matched = $matchByTranslation ?? $matchBySlug;
            $oldSlug = $matched?->slug ?? $slug;
            $oldPath = $matched?->filePath ?? '';
            $newPath = $this->contentDir . '/' . $type . '/' . $this->buildFilename($slug, $lang);
            $targetKey = $slug . '|' . $lang;
            if (isset($seenTargets[$targetKey])) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => $slug,
                    'lang' => $lang,
                    'title' => $title,
                    'message' => 'Duplicate target slug+language in CSV.',
                ];
                $summary['error']++;
                continue;
            }
            $seenTargets[$targetKey] = true;

            $payload = $this->buildImportPayload($type, $row, $headers, $matched, $slug, $lang, $title);
            if (($payload['ok'] ?? false) !== true) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => $slug,
                    'lang' => $lang,
                    'title' => $title,
                    'message' => (string)($payload['error'] ?? 'Invalid row data.'),
                ];
                $summary['error']++;
                continue;
            }

            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $body = (string)($payload['body'] ?? '');
            $newContent = $this->buildMarkdownPayload($data, $body);

            $action = 'create';
            if ($matched !== null) {
                $action = 'update';
                if ($oldPath === $newPath && is_file($oldPath)) {
                    $oldContent = (string)file_get_contents($oldPath);
                    if ($oldContent === $newContent) {
                        $action = 'skip';
                    }
                }
            }

            $rowsOut[] = [
                'line' => $lineNo,
                'action' => $action,
                'slug' => $slug,
                'lang' => $lang,
                'title' => (string)($data['title'] ?? $title ?: $this->titleFromSlug($slug)),
                'message' => $action === 'skip' ? 'No changes detected.' : '',
            ];
            $summary[$action]++;

            if ($action === 'skip') {
                continue;
            }

            $entries[] = [
                'line' => $lineNo,
                'action' => $action,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'old_slug' => $oldSlug,
                'slug' => $slug,
                'lang' => $lang,
                'old_mtime' => $matched?->mtime ?? 0,
                'data' => $data,
                'body' => $body,
            ];
        }

        return [
            'rows' => $rowsOut,
            'entries' => $entries,
            'summary' => $summary,
        ];
    }

    /** @param array<string, string> $row @param array<int, string> $headers */
    private function buildImportPayload(string $type, array $row, array $headers, ?ContentItem $existing, string $slug, string $lang, string $title): array
    {
        $data = $existing ? $existing->meta : [];
        unset($data['slug'], $data['type'], $data['lang']);

        $has = fn (string $header): bool => in_array($header, $headers, true);

        if ($has('title')) {
            $data['title'] = $title !== '' ? $title : $this->titleFromSlug($slug);
        } elseif (!isset($data['title']) || (string)$data['title'] === '') {
            $data['title'] = $this->titleFromSlug($slug);
        }

        if ($has('status')) {
            $status = trim((string)($row['status'] ?? ''));
            $data['status'] = $status !== '' ? $status : 'published';
        } elseif (!isset($data['status'])) {
            $data['status'] = 'published';
        }

        if ($has('visible')) {
            $data['visible'] = $this->parseCsvBool((string)($row['visible'] ?? ''), true);
        } elseif (!isset($data['visible'])) {
            $data['visible'] = true;
        }

        if ($has('date')) {
            $date = trim((string)($row['date'] ?? ''));
            if ($date !== '') {
                $data['date'] = $this->normalizeDateForStorage($date);
            } else {
                unset($data['date']);
            }
        }

        if ($has('author')) {
            $author = trim((string)($row['author'] ?? ''));
            if ($author !== '') {
                $data['author'] = $author;
            } else {
                unset($data['author']);
            }
        }

        if ($has('tags')) {
            $tags = array_values(array_filter(array_map(fn($value) => $this->slugify((string)$value), $this->parseCommaList((string)($row['tags'] ?? '')))));
            if (!empty($tags) && $type !== 'pages' && $type !== 'forms') {
                $data['tags'] = $tags;
            } else {
                unset($data['tags']);
            }
        }

        if ($has('categories')) {
            $categories = array_values(array_filter(array_map(fn($value) => $this->slugify((string)$value), $this->parseCommaList((string)($row['categories'] ?? '')))));
            if (!empty($categories) && $type !== 'pages' && $type !== 'forms') {
                $data['categories'] = $categories;
            } else {
                unset($data['categories']);
            }
        }

        if ($has('translation_id')) {
            $translationId = trim((string)($row['translation_id'] ?? ''));
            if ($translationId !== '') {
                $data['translation_id'] = $translationId;
            } elseif (!isset($data['translation_id'])) {
                $data['translation_id'] = $this->generateTranslationId();
            }
        } elseif (!isset($data['translation_id']) || (string)$data['translation_id'] === '') {
            $data['translation_id'] = $this->generateTranslationId();
        }

        if ($has('main_image')) {
            $mainImage = trim((string)($row['main_image'] ?? ''));
            if ($mainImage !== '') {
                $data['main_image'] = $mainImage;
            } else {
                unset($data['main_image']);
            }
        }

        if ($has('excerpt')) {
            $excerpt = trim((string)($row['excerpt'] ?? ''));
            if ($excerpt !== '') {
                $data['excerpt'] = $excerpt;
            } else {
                unset($data['excerpt']);
            }
        }

        $metaSet = [];
        $metaUnset = [];
        foreach ($headers as $header) {
            if (!str_starts_with($header, 'meta.')) {
                continue;
            }
            $raw = (string)($row[$header] ?? '');
            $path = substr($header, 5);
            if ($path === '' || in_array($path, ['slug', 'type', 'lang'], true)) {
                continue;
            }
            if ($raw === '') {
                $metaUnset[] = $path;
                continue;
            }
            $metaSet[$path] = $this->parseCsvImportValue($raw);
        }

        foreach ($metaUnset as $path) {
            $this->unsetArrayPath($data, explode('.', $path));
        }
        foreach ($metaSet as $path => $value) {
            $this->setArrayPath($data, explode('.', $path), $value);
        }

        foreach (array_keys($data) as $key) {
            if ($key === 'summary') {
                unset($data[$key]);
            }
        }

        $body = $has('body') ? (string)($row['body'] ?? '') : ($existing?->markdown ?? '');
        return [
            'ok' => true,
            'data' => $data,
            'body' => $body,
        ];
    }

    private function parseCsvBool(string $value, bool $default): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $default;
        }
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private function parseCsvImportValue(string $value): mixed
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $first = $value[0] ?? '';
        if ($first === '[' || $first === '{') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        return $value;
    }

    private function setArrayPath(array &$target, array $path, mixed $value): void
    {
        if (empty($path)) {
            return;
        }
        $node = &$target;
        foreach ($path as $index => $segment) {
            $segment = (string)$segment;
            if ($segment === '') {
                return;
            }
            $isLeaf = $index === count($path) - 1;
            if ($isLeaf) {
                $node[$segment] = $value;
                return;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
    }

    private function unsetArrayPath(array &$target, array $path): void
    {
        if (empty($path)) {
            return;
        }
        $node = &$target;
        $last = count($path) - 1;
        foreach ($path as $index => $segment) {
            $segment = (string)$segment;
            if ($segment === '') {
                return;
            }
            if ($index === $last) {
                unset($node[$segment]);
                return;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                return;
            }
            $node = &$node[$segment];
        }
    }

    private function buildMarkdownPayload(array $data, string $body): string
    {
        $frontmatter = trim(Yaml::dump($data, 4, 2));
        $body = rtrim($body);
        return "---\n" . $frontmatter . "\n---\n\n" . $body . "\n";
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function applyContentImportBatch(string $type, array $entries): array
    {
        $writable = array_values(array_filter($entries, function (array $entry): bool {
            return in_array((string)($entry['action'] ?? ''), ['create', 'update'], true);
        }));
        if (empty($writable)) {
            return ['ok' => false, 'error' => 'Nothing to import.'];
        }

        foreach ($writable as $entry) {
            $oldPath = (string)($entry['old_path'] ?? '');
            $oldMtime = (int)($entry['old_mtime'] ?? 0);
            if ($oldPath !== '' && file_exists($oldPath) && $oldMtime > 0) {
                $current = (int)filemtime($oldPath);
                if ($current !== $oldMtime) {
                    return ['ok' => false, 'error' => 'Content changed since dry-run. Please rerun preview.'];
                }
            }
        }

        $touched = [];
        foreach ($writable as $entry) {
            $oldPath = (string)($entry['old_path'] ?? '');
            $newPath = (string)($entry['new_path'] ?? '');
            if ($oldPath !== '') {
                $touched[$oldPath] = true;
            }
            if ($newPath !== '') {
                $touched[$newPath] = true;
            }
        }
        $touchedPaths = array_keys($touched);
        $originalExists = [];
        foreach ($touchedPaths as $path) {
            $originalExists[$path] = file_exists($path);
        }

        $backupToken = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $backupDir = $this->contentDir . '/.import-backups/' . $type . '-' . $backupToken;
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            return ['ok' => false, 'error' => 'Could not create import backup directory.'];
        }

        foreach ($touchedPaths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $relative = ltrim(str_replace($this->contentDir, '', $path), '/');
            $target = $backupDir . '/' . $relative;
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }
            if (!copy($path, $target)) {
                return ['ok' => false, 'error' => 'Failed to create backup copy before import.'];
            }
        }

        $temps = [];
        foreach ($writable as $entry) {
            $newPath = (string)($entry['new_path'] ?? '');
            $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            $body = (string)($entry['body'] ?? '');
            $dir = dirname($newPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $tmpPath = $dir . '/.' . basename($newPath) . '.tmp-import-' . bin2hex(random_bytes(4));
            $payload = $this->buildMarkdownPayload($data, $body);
            if (file_put_contents($tmpPath, $payload) === false) {
                foreach ($temps as $temp) {
                    @unlink($temp['tmp']);
                }
                $this->restoreImportBackup($backupDir, $touchedPaths, $originalExists);
                return ['ok' => false, 'error' => 'Failed while preparing import files.'];
            }
            $temps[] = [
                'tmp' => $tmpPath,
                'new' => $newPath,
                'old' => (string)($entry['old_path'] ?? ''),
            ];
        }

        foreach ($temps as $temp) {
            if (!@rename($temp['tmp'], $temp['new'])) {
                foreach ($temps as $cleanup) {
                    @unlink($cleanup['tmp']);
                }
                $this->restoreImportBackup($backupDir, $touchedPaths, $originalExists);
                return ['ok' => false, 'error' => 'Failed while writing imported content.'];
            }
        }

        foreach ($temps as $temp) {
            $oldPath = $temp['old'];
            $newPath = $temp['new'];
            if ($oldPath !== '' && $oldPath !== $newPath && file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        return ['ok' => true];
    }

    /** @param string[] $paths @param array<string, bool> $originalExists */
    private function restoreImportBackup(string $backupDir, array $paths, array $originalExists): void
    {
        foreach ($paths as $path) {
            $relative = ltrim(str_replace($this->contentDir, '', $path), '/');
            $backupPath = $backupDir . '/' . $relative;
            $existed = (bool)($originalExists[$path] ?? false);
            if ($existed) {
                if (file_exists($backupPath)) {
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                    @copy($backupPath, $path);
                }
            } else {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
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

    private function stringifySubmissionValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        return trim((string)$value);
    }

    private function sendFormNotifications(ContentItem $form, array $values, array $fields): void
    {
        $notifications = $form->meta['notifications'] ?? [];
        if (!is_array($notifications)) {
            $notifications = [];
        }
        $enabled = $this->isTruthy($notifications['enabled'] ?? false);
        $to = trim((string)($notifications['to'] ?? ''));
        $subject = trim((string)($notifications['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'New submission: ' . (string)($form->meta['title'] ?? $form->slug);
        }
        $body = $this->buildFormEmailBody($form, $values, $fields);

        $from = trim((string)($this->settings['forms']['notifications']['from'] ?? ''));
        $fromName = trim((string)($this->settings['forms']['notifications']['from_name'] ?? ''));
        if ($from === '') {
            $from = 'noreply@localhost';
        }
        $fromHeader = $fromName !== '' ? $fromName . ' <' . $from . '>' : $from;
        $headers = [
            'From' => $fromHeader,
        ];
        $replyToField = trim((string)($notifications['reply_to_field'] ?? ''));
        $replyEmail = $this->findReplyToEmail($values, $fields, $replyToField);
        if ($replyEmail !== '') {
            $headers['Reply-To'] = $replyEmail;
        }
        $cc = trim((string)($notifications['cc'] ?? ''));
        if ($cc !== '') {
            $headers['Cc'] = $cc;
        }
        $bcc = trim((string)($notifications['bcc'] ?? ''));
        if ($bcc !== '') {
            $headers['Bcc'] = $bcc;
        }

        if ($enabled && $to !== '') {
            $this->sendEmailMessage($to, $subject, $body, $headers);
        }

        if ($this->isTruthy($notifications['auto_reply'] ?? false) && $replyEmail !== '') {
            $autoSubject = trim((string)($notifications['auto_reply_subject'] ?? ''));
            if ($autoSubject === '') {
                $autoSubject = 'Thanks for your message';
            }
            $autoMessage = trim((string)($notifications['auto_reply_message'] ?? ''));
            if ($autoMessage === '') {
                $autoMessage = "Thanks for contacting us.\n\nWe received your submission and will get back to you soon.";
            }
            if ($this->isTruthy($notifications['auto_reply_include'] ?? false)) {
                $autoMessage .= "\n\n---\n\n" . $body;
            }
            $this->sendEmailMessage($replyEmail, $autoSubject, $autoMessage, [
                'From' => $fromHeader,
            ]);
        }
    }

    private function buildFormEmailBody(ContentItem $form, array $values, array $fields): string
    {
        $lines = [];
        $lines[] = 'Form: ' . (string)($form->meta['title'] ?? $form->slug);
        $lines[] = 'URL: ' . $this->buildAbsoluteUrl($this->buildContentPath('forms', $form->slug, $form->lang, $this->settings['home_page'] ?? 'index', $this->settings['languages']['default'] ?? 'en'));
        $lines[] = '';
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $label = (string)($field['label'] ?? $name);
            $value = $values[$name] ?? '';
            $lines[] = $label . ': ' . $this->stringifySubmissionValue($value);
        }
        return implode("\n", $lines);
    }

    private function findReplyToEmail(array $values, array $fields, string $replyToField): string
    {
        if ($replyToField !== '' && isset($values[$replyToField])) {
            $candidate = trim((string)$values[$replyToField]);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'email') {
                $name = (string)($field['name'] ?? '');
                $candidate = trim((string)($values[$name] ?? ''));
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    return $candidate;
                }
            }
        }
        if (isset($values['email']) && filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            return (string)$values['email'];
        }
        return '';
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
        $fields = $this->normalizeFormFields($form->meta['fields'] ?? []);
        $values = $this->defaultFormValues($fields);
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
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        if ($lang === $defaultLang || $lang === '') {
            return $slug . '.md';
        }
        return $slug . '.' . $lang . '.md';
    }

    /** @return array{0: string, 1: string} */
    private function splitFrontMatter(string $raw): array
    {
        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R(.*)\z/s', $raw, $matches)) {
            return [$matches[1], $matches[2]];
        }
        return ['', $raw];
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}

<?php

declare(strict_types=1);

namespace FlatCMS;

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
        $this->settings = $this->loadSettings();
        $this->themeSettings = $this->loadThemeSettings();
        $this->ensureDefaultMenus();
        $this->ensureDefaultTaxonomies();

        $environment = new Environment(['renderer' => ['soft_break' => "<br />\n"]]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $markdown = new MarkdownConverter($environment);

        $this->content = new ContentRepository($this->contentDir, $markdown, $this->settings);
        $this->auth = new Auth($this->contentDir . '/users/users.yaml');

        $this->twig = $this->initTwig();
        $this->currentLang = $this->settings['languages']['default'] ?? 'en';
        $this->translations = $this->loadTranslations($this->currentLang);
    }

    public function handle(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = trim($path, '/');

        if (str_starts_with($path, 'admin')) {
            $this->handleAdmin($path);
            return;
        }

        $this->handleFront($path);
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
            $this->render('search.twig', [
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
            ] + $viewDefaults);
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
        $template = $slug === $homeSlug ? 'home.twig' : $this->resolveItemTemplate($page);
        $homeData = [];
        if ($template === 'home.twig') {
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
        ] + $homeData + $viewDefaults);
    }

    private function handleAdmin(string $path): void
    {
        $segments = explode('/', $path);
        $action = $segments[1] ?? 'index';

        if ($action === 'login') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if ($this->auth->attempt($username, $password)) {
                    $this->redirect('/admin');
                    return;
                }
                $this->render('@admin/login.twig', [
                    'error' => 'Invalid credentials.',
                ]);
                return;
            }

            $this->render('@admin/login.twig');
            return;
        }

        if ($action === 'logout') {
            $this->auth->logout();
            $this->redirect('/admin/login');
            return;
        }

        if (!$this->auth->check()) {
            $this->redirect('/admin/login');
            return;
        }

        $this->maybeRunScheduledBackup();

        if ($action === 'settings') {
            $this->handleSettings();
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
            $this->redirect('/admin');
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

    private function handleAdminList(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        $lang = $this->slugify((string)($_GET['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $deleted = isset($_GET['deleted']);
        $types = $this->content->getTypes();
        if (!in_array($type, $types, true)) {
            $type = $types[0] ?? 'pages';
        }
        $items = $this->content->getItems($type, $lang, true, false);
        $translationLangs = $this->buildTranslationLangMatrix($type, $items);

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
        ]);
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
        if ($type === 'forms' && $slug !== '') {
            $formSubmissions = $this->listFormSubmissions($slug);
        }
        if ($type !== 'forms') {
            $this->ensureMediaLibraryDirectories();
            $this->migrateLegacyMediaLibraryItems();
            $mediaPickerImages = array_slice($this->listMediaItems([
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
            'admin_section' => 'content',
            'current_type' => $type,
            'form_fields' => $formFields,
            'form_notifications' => $formNotifications,
            'form_settings' => $formSettings,
            'form_submissions' => $formSubmissions,
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
        $mainImageUploads = $this->normalizeMediaUploads($mainImageUploadRaw);
        if ($mainImageUploads !== []) {
            $upload = $mainImageUploads[0];
            $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_OK) {
                try {
                    $this->ensureMediaLibraryDirectories();
                    $uploaded = $this->uploadMediaItem($upload);
                    if ((string)($uploaded['kind'] ?? '') === 'image') {
                        $mainImage = (string)($uploaded['direct_url'] ?? $mainImage);
                    } else {
                        $uploadedId = (string)($uploaded['id'] ?? '');
                        if ($uploadedId !== '') {
                            $this->deleteMediaItem($uploadedId);
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

        $frontmatter = trim(Yaml::dump($data, 4, 2));
        $payload = "---\n" . $frontmatter . "\n---\n\n" . $body . "\n";
        file_put_contents($path, $payload);

        if ($oldPath !== '' && $oldPath !== $path && file_exists($oldPath)) {
            unlink($oldPath);
        }

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
            $this->redirect('/admin?type=' . urlencode($type) . '&lang=' . urlencode($lang));
            return;
        }

        if ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang) {
            $this->redirect('/admin?type=' . urlencode($type) . '&lang=' . urlencode($lang));
            return;
        }

        $path = $this->contentDir . '/' . $type . '/' . $this->buildFilename($slug, $lang);
        if (file_exists($path)) {
            unlink($path);
        }

        $this->redirect('/admin?type=' . urlencode($type) . '&lang=' . urlencode($lang) . '&deleted=1');
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
        $settingsPath = $this->contentDir . '/settings/site.yaml';
        $themePath = $this->contentDir . '/settings/theme.yaml';
        $saved = isset($_GET['saved']);
        $testStatus = (string)($_GET['test'] ?? '');
        $backupStatus = (string)($_GET['backup'] ?? '');
        $backupMessage = trim((string)($_GET['backup_msg'] ?? ''));
        $themeStatus = (string)($_GET['theme'] ?? '');
        $themeMessage = trim((string)($_GET['theme_msg'] ?? ''));
        $activeTab = $this->sanitizeSettingsTab((string)($_GET['tab'] ?? 'basics'));

        $downloadBackup = trim((string)($_GET['download_backup'] ?? ''));
        if ($downloadBackup !== '') {
            $this->downloadBackupSnapshot($downloadBackup);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $activeTab = $this->sanitizeSettingsTab((string)($_POST['active_tab'] ?? $activeTab));
            $raw = (string)($_POST['settings'] ?? '');
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
            ];
            $this->saveSettings($settingsPath, $raw, $form);
            $this->settings = $this->loadSettings();
            $themeRaw = (string)($_POST['theme_settings'] ?? '');
            $themeSave = $this->saveThemeSettingsRaw($themePath, $themeRaw);
            $this->themeSettings = $this->loadThemeSettings();
            if (($themeSave['ok'] ?? false) !== true) {
                $msg = urlencode((string)($themeSave['message'] ?? 'Invalid theme YAML.'));
                $this->redirect('/admin/settings?saved=1&tab=theme&theme=fail&theme_msg=' . $msg);
                return;
            }
            if (isset($_POST['send_test'])) {
                $testTo = trim((string)($_POST['test_email_to'] ?? ''));
                if ($testTo === '') {
                    $this->redirect('/admin/settings?saved=1&tab=smtp&test=missing');
                    return;
                }
                $siteTitle = trim((string)($this->settings['title'] ?? 'PicolinoCMS'));
                if ($siteTitle === '') {
                    $siteTitle = 'PicolinoCMS';
                }
                $testSubject = $siteTitle . ' - email test';
                $from = trim((string)($this->settings['forms']['notifications']['from'] ?? ''));
                $fromName = trim((string)($this->settings['forms']['notifications']['from_name'] ?? ''));
                if ($from === '') {
                    $from = 'noreply@localhost';
                }
                $fromHeader = $fromName !== '' ? $fromName . ' <' . $from . '>' : $from;
                $ok = $this->sendEmailMessage($testTo, $testSubject, "This is a test email from PicolinoCMS.", [
                    'From' => $fromHeader,
                ]);
                $this->redirect('/admin/settings?saved=1&tab=smtp&test=' . ($ok ? 'ok' : 'fail'));
                return;
            }
            if (isset($_POST['create_backup'])) {
                $result = $this->createBackupSnapshot();
                if (($result['ok'] ?? false) === true) {
                    $this->updateBackupLastRun(date('c'));
                }
                $query = [
                    'saved' => '1',
                    'tab' => 'backup',
                    'backup' => (($result['ok'] ?? false) ? 'ok' : 'fail'),
                    'backup_msg' => (string)($result['message'] ?? ''),
                ];
                $this->redirect('/admin/settings?' . http_build_query($query));
                return;
            }
            $this->redirect('/admin/settings?saved=1&tab=' . urlencode($activeTab));
            return;
        }

        $raw = file_exists($settingsPath) ? (string)file_get_contents($settingsPath) : '';
        $themeRaw = file_exists($themePath) ? (string)file_get_contents($themePath) : Yaml::dump($this->themeSettings, 4, 2);
        $parsed = [];
        if ($raw !== '') {
            $parsed = Yaml::parse($raw) ?: [];
        }
        $this->render('@admin/settings.twig', [
            'settings' => $raw,
            'theme_settings_raw' => $themeRaw,
            'user' => $this->auth->user(),
            'saved' => $saved,
            'test_status' => $testStatus,
            'types' => $this->content->getTypes(),
            'admin_section' => 'settings',
            'settings_form' => $this->extractSettingsForm($parsed),
            'backup_snapshots' => $this->listBackupSnapshots(),
            'backup_status' => $backupStatus,
            'backup_message' => $backupMessage,
            'theme_status' => $themeStatus,
            'theme_message' => $themeMessage,
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
        $this->ensureMediaLibraryDirectories();
        $this->migrateLegacyMediaLibraryItems();

        $typeOptions = $this->mediaTypeOptions();
        $viewOptions = ['list', 'thumbs'];
        $perPageOptions = [20, 50, 100];

        $type = strtolower(trim((string)($_GET['type'] ?? 'all')));
        if (!in_array($type, $typeOptions, true)) {
            $type = 'all';
        }
        $tag = $this->sanitizeMediaTag((string)($_GET['tag'] ?? ''));
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
            $stateTag = $this->sanitizeMediaTag((string)($source['_state_tag'] ?? $tag));
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
                $uploads = $this->normalizeMediaUploads($rawUpload);
                if ($uploads === []) {
                    $redirectMedia(['error' => 'No file uploaded.']);
                    return;
                }
                $tagsCsv = trim((string)($_POST['upload_tags'] ?? ''));
                $uploadedCount = 0;
                $failedCount = 0;
                foreach ($uploads as $upload) {
                    $errorCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($errorCode === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    try {
                        $this->uploadMediaItem($upload, $tagsCsv);
                        $uploadedCount++;
                    } catch (\Throwable) {
                        $failedCount++;
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
                        'error' => $failedCount . ' uploads failed.',
                    ]);
                    return;
                }
                $redirectMedia(['error' => 'Upload failed.']);
                return;
            }

            if ($action === 'save_tags') {
                $id = $this->sanitizeMediaId((string)($_POST['id'] ?? ''));
                if ($id === '') {
                    $redirectMedia(['error' => 'Invalid media item.']);
                    return;
                }
                if (!$this->updateMediaItemTags($id, (string)($_POST['tags'] ?? ''))) {
                    $redirectMedia(['error' => 'Media item not found.']);
                    return;
                }
                $redirectMedia(['success' => 'Tags updated.']);
                return;
            }

            if ($action === 'delete') {
                $id = $this->sanitizeMediaId((string)($_POST['id'] ?? ''));
                if ($id === '') {
                    $legacyFilename = $this->sanitizeFilename((string)($_POST['filename'] ?? ''));
                    if ($legacyFilename !== '') {
                        $legacy = $this->findMediaItemByFilename($legacyFilename);
                        $id = $legacy['id'] ?? '';
                    }
                }
                if ($id === '') {
                    $redirectMedia(['error' => 'Invalid media item.']);
                    return;
                }
                if (!$this->deleteMediaItem($id)) {
                    $redirectMedia(['error' => 'Media item not found.']);
                    return;
                }
                $redirectMedia(['success' => 'Media item deleted.']);
                return;
            }

            if ($action === 'bulk_tags' || $action === 'bulk_delete') {
                $selectedIds = $this->collectMediaIdsFromRequest($_POST['selected_ids'] ?? []);
                $applyAllFiltered = ((string)($_POST['apply_all_filtered'] ?? '0')) === '1';
                if ($applyAllFiltered) {
                    $filterType = strtolower(trim((string)($_POST['_filter_type'] ?? 'all')));
                    if (!in_array($filterType, $typeOptions, true)) {
                        $filterType = 'all';
                    }
                    $filterTag = $this->sanitizeMediaTag((string)($_POST['_filter_tag'] ?? ''));
                    $filterQ = trim((string)($_POST['_filter_q'] ?? ''));
                    $filtered = $this->listMediaItems([
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
                        $item = $this->findMediaItem($id);
                        if ($item === null) {
                            continue;
                        }
                        $existing = implode(',', is_array($item['tags'] ?? null) ? $item['tags'] : []);
                        $merged = trim($existing . ',' . $bulkTags, ', ');
                        if ($this->updateMediaItemTags($id, $merged)) {
                            $updated++;
                        }
                    }
                    if ($updated === 0) {
                        $redirectMedia(['error' => 'No media items were updated.']);
                        return;
                    }
                    $redirectMedia(['success' => 'Updated tags for ' . $updated . ' items.']);
                    return;
                }

                $deletedCount = 0;
                foreach ($selectedIds as $id) {
                    if ($this->deleteMediaItem($id)) {
                        $deletedCount++;
                    }
                }
                if ($deletedCount === 0) {
                    $redirectMedia(['error' => 'No media items were deleted.']);
                    return;
                }
                $redirectMedia(['success' => 'Deleted ' . $deletedCount . ' items.']);
                return;
            }
        }

        $allItems = $this->listMediaItems([
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
            'available_tags' => $this->mediaAvailableTags(),
            'max_upload_mb' => max(1, (int)($this->settings['media']['max_upload_mb'] ?? 20)),
            'success' => trim((string)($_GET['success'] ?? '')),
            'error' => trim((string)($_GET['error'] ?? '')),
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'media',
        ]);
    }

    private function handleFiles(): void
    {
        $uploadDir = $this->basePath . '/public/uploads/files';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $this->normalizeFileUploads($uploadDir);

        $saved = isset($_GET['saved']);
        $deleted = isset($_GET['deleted']);
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['delete'])) {
                $filename = (string)($_POST['filename'] ?? '');
                $filename = $this->sanitizeFilename($filename);
                if ($filename === '') {
                    $error = 'Invalid filename.';
                } else {
                    $path = $uploadDir . '/' . $filename;
                    if (is_file($path)) {
                        unlink($path);
                        $this->redirect('/admin/files?deleted=1');
                        return;
                    }
                    $error = 'File not found.';
                }
            } elseif (!isset($_FILES['asset'])) {
                $error = 'No file uploaded.';
            } else {
                $file = $_FILES['asset'];
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $error = 'Upload failed.';
                } else {
                    $tmpPath = $file['tmp_name'];
                    $original = (string)($file['name'] ?? '');
                    $targetName = $this->sanitizeFilename($original);
                    if (!$this->isAllowedFileUpload($tmpPath, $targetName)) {
                        $error = 'Invalid file type.';
                    } else {
                        $targetPath = $uploadDir . '/' . $targetName;
                        $targetPath = $this->uniqueFilePath($targetPath);
                        if (!move_uploaded_file($tmpPath, $targetPath)) {
                            $error = 'Could not save the uploaded file.';
                        } else {
                            $this->redirect('/admin/files?saved=1');
                            return;
                        }
                    }
                }
            }
        }

        $files = $this->listUploads($uploadDir, '/uploads/files');

        $this->render('@admin/files.twig', [
            'files' => $files,
            'saved' => $saved,
            'deleted' => $deleted,
            'error' => $error,
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'files',
        ]);
    }

    private function handleFormsExport(): void
    {
        $slug = $this->slugify((string)($_GET['slug'] ?? ''));
        $lang = $this->slugify((string)($_GET['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        if ($slug === '') {
            $this->redirect('/admin?type=forms');
            return;
        }

        $form = $this->content->find('forms', $slug, $lang, true, false);
        if (!$form) {
            $this->redirect('/admin?type=forms&lang=' . urlencode($lang));
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
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }
        $siteTitle = (string)($this->settings['title'] ?? '');
        fputcsv($output, $headers);
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
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    private function handleContentExport(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        $types = $this->content->getTypes();
        if (!in_array($type, $types, true) || $type === 'forms') {
            $this->redirect('/admin?type=' . urlencode($type));
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

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }

        fputcsv($output, $headers);
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
            fputcsv($output, $row);
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
            $this->redirect('/admin?type=forms');
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
        $theme = $this->settings['theme'] ?? 'default';
        $available = $this->settings['languages']['available'] ?? [];
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        if (empty($available)) {
            $available = [$defaultLang];
        }

        $lang = $this->slugify((string)($_GET['lang'] ?? $_POST['lang'] ?? $defaultLang));
        if (!in_array($lang, $available, true)) {
            $lang = $defaultLang;
        }

        $langDir = $this->basePath . '/themes/' . $theme . '/lang';
        $filePath = $langDir . '/' . $lang . '.php';
        $saved = isset($_GET['saved']);
        $defaults = $this->loadTranslationFile($langDir, $defaultLang);
        $visibleDefaults = $this->filterVisibleTranslationKeys($defaults);
        $existing = $this->loadTranslationFile($langDir, $lang);
        $hiddenExisting = $this->extractHiddenTranslationKeys($existing);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $keys = $_POST['keys'] ?? [];
            $values = $_POST['values'] ?? [];
            $translations = $existing;

            foreach ($keys as $index => $key) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }
                if ($this->isHiddenTranslationKey($key)) {
                    continue;
                }
                $value = (string)($values[$index] ?? '');
                $translations[$key] = $value;
            }

            if ($lang !== $defaultLang) {
                foreach ($visibleDefaults as $key => $value) {
                    if (!array_key_exists($key, $translations)) {
                        $translations[$key] = $value;
                    }
                }
            }

            // Keep hidden/system keys (e.g. nav.*) untouched by this screen.
            foreach ($hiddenExisting as $key => $value) {
                $translations[$key] = $value;
            }

            ksort($translations);
            $this->writeTranslationFile($filePath, $translations);
            $this->redirect('/admin/translations?lang=' . urlencode($lang) . '&saved=1');
            return;
        }

        $visibleTranslations = $this->filterVisibleTranslationKeys($existing);
        $merged = $lang === $defaultLang ? $visibleDefaults : array_replace($visibleDefaults, $visibleTranslations);
        ksort($merged);
        ksort($visibleDefaults);

        $this->render('@admin/translations.twig', [
            'lang' => $lang,
            'languages' => $available,
            'translations' => $merged,
            'defaults' => $visibleDefaults,
            'saved' => $saved,
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'default_lang' => $defaultLang,
            'admin_section' => 'translations',
        ]);
    }


    private function initTwig(): TwigEnvironment
    {
        $theme = $this->settings['theme'] ?? 'default';
        $themeDir = $this->basePath . '/themes/' . $theme;
        $adminDir = $this->basePath . '/admin/templates';

        $loader = new FilesystemLoader();
        $loader->addPath($themeDir);
        $loader->addPath($adminDir, 'admin');

        $twig = new TwigEnvironment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);

        $baseUrl = rtrim((string)($this->settings['base_url'] ?? ''), '/');
        $twig->addFunction(new TwigFunction('asset', function (string $path) use ($baseUrl): string {
            return $baseUrl . '/assets/' . ltrim($path, '/');
        }));

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

        $twig->addGlobal('site', $this->settings);
        $twig->addGlobal('theme_settings', $this->themeSettings);
        $twig->addGlobal('is_admin', $this->auth->check());

        return $twig;
    }

    private function render(string $template, array $data = []): void
    {
        $data = array_merge([
            'is_admin' => $this->auth->check(),
        ], $data);
        echo $this->twig->render($template, $data);
    }

    private function render404(): void
    {
        http_response_code(404);
        $lang = $this->currentLang ?: ($this->settings['languages']['default'] ?? 'en');
        $themeRoot = $this->basePath . '/themes/' . ($this->settings['theme'] ?? 'default') . '/';
        if (file_exists($themeRoot . '404.twig')) {
            $this->render('404.twig', [
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
        $defaults = [
            'title' => 'PicolinoCMS',
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
            ],
        ];

        $settingsPath = $this->contentDir . '/settings/site.yaml';
        if (!file_exists($settingsPath)) {
            return $defaults;
        }

        $data = Yaml::parseFile($settingsPath);
        if (!is_array($data)) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $data);
    }

    private function loadThemeSettings(): array
    {
        $defaults = [
            'appearance' => [
                'mode' => 'system',
                'palette' => 'slate',
                'font' => 'sans',
            ],
            'hero_layouts' => [
                'default' => 'default',
                'pages' => 'default',
                'posts' => 'default',
                'projects' => 'default',
                'forms' => 'default',
            ],
            'home' => [
                'show_latest_projects' => true,
                'projects_limit' => 3,
                'show_latest_posts' => true,
                'posts_limit' => 3,
            ],
            'footer' => [
                'summary' => '',
                'email' => '',
                'phone' => '',
                'address' => '',
            ],
        ];

        $path = $this->contentDir . '/settings/theme.yaml';
        if (!file_exists($path)) {
            return $defaults;
        }
        try {
            $data = Yaml::parseFile($path);
        } catch (\Throwable) {
            return $defaults;
        }
        if (!is_array($data)) {
            return $defaults;
        }
        return array_replace_recursive($defaults, $data);
    }

    /** @return array{ok: bool, message?: string} */
    private function saveThemeSettingsRaw(string $path, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            file_put_contents($path, Yaml::dump($this->loadThemeSettings(), 4, 2));
            return ['ok' => true];
        }
        try {
            $parsed = Yaml::parse($raw);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Theme YAML parse error: ' . $e->getMessage()];
        }
        if (!is_array($parsed)) {
            return ['ok' => false, 'message' => 'Theme YAML must contain a top-level mapping.'];
        }
        file_put_contents($path, $raw . "\n");
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

    /** @return array{key: string, lang: string} */
    private function parseMenuFilename(string $filename): array
    {
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'el');
        if (preg_match('/^(.*)\.([a-z]{2})$/', $filename, $match)) {
            return [
                'key' => $this->slugify((string)$match[1]),
                'lang' => $this->slugify((string)$match[2]),
            ];
        }
        return [
            'key' => $this->slugify($filename),
            'lang' => $defaultLang,
        ];
    }

    /** @return array<int, array{key: string, lang: string, path: string, title: string, items: array<int, array<string, mixed>>, translation_id: string}> */
    private function listMenuFileEntries(): array
    {
        $rows = [];
        foreach (glob($this->menuDir() . '/*.yaml') ?: [] as $path) {
            $filename = basename($path, '.yaml');
            if ($filename === '') {
                continue;
            }
            $meta = $this->parseMenuFilename($filename);
            $key = $meta['key'];
            $lang = $meta['lang'];
            if ($key === '' || $lang === '') {
                continue;
            }
            $data = Yaml::parseFile($path);
            if (!is_array($data)) {
                $data = [];
            }
            $rows[] = [
                'key' => $key,
                'lang' => $lang,
                'path' => $path,
                'title' => trim((string)($data['title'] ?? '')),
                'items' => $this->normalizeMenuItems($data['items'] ?? []),
                'translation_id' => trim((string)($data['translation_id'] ?? '')),
            ];
        }
        return $rows;
    }

    private function ensureMenuTranslationIds(): void
    {
        $entries = $this->listMenuFileEntries();
        if (empty($entries)) {
            return;
        }

        $idsByKey = [];
        foreach ($entries as $entry) {
            if ($entry['translation_id'] !== '' && !isset($idsByKey[$entry['key']])) {
                $idsByKey[$entry['key']] = $entry['translation_id'];
            }
        }
        foreach ($entries as $entry) {
            $key = $entry['key'];
            if (!isset($idsByKey[$key])) {
                $idsByKey[$key] = $this->generateTranslationId();
            }
        }

        foreach ($entries as $entry) {
            $expected = $idsByKey[$entry['key']] ?? '';
            if ($expected === '' || $entry['translation_id'] === $expected) {
                continue;
            }
            $payload = [
                'title' => $entry['title'] !== '' ? $entry['title'] : $this->titleFromSlug($entry['key']),
                'translation_id' => $expected,
                'items' => $entry['items'],
            ];
            file_put_contents($entry['path'], Yaml::dump($payload, 4, 2));
        }
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

    /** @param array<int, array{key: string, title: string, source_lang: string, updated: string, translation_id: string}> $rows */
    private function buildMenuTranslationLangMatrix(array $rows): array
    {
        $entries = $this->listMenuFileEntries();
        $langsByGroup = [];
        foreach ($entries as $entry) {
            $group = $entry['translation_id'] !== '' ? 'id:' . $entry['translation_id'] : 'key:' . $entry['key'];
            $langsByGroup[$group] ??= [];
            if (!in_array($entry['lang'], $langsByGroup[$group], true)) {
                $langsByGroup[$group][] = $entry['lang'];
            }
        }

        $matrix = [];
        foreach ($rows as $row) {
            $sourceLang = $this->slugify((string)($row['source_lang'] ?? ''));
            if ($sourceLang === '') {
                continue;
            }
            $translationId = (string)($row['translation_id'] ?? '');
            $group = $translationId !== '' ? 'id:' . $translationId : 'key:' . (string)($row['key'] ?? '');
            $langs = $langsByGroup[$group] ?? [];
            $other = array_values(array_filter($langs, fn($lang) => $lang !== $sourceLang));
            $matrix[(string)($row['key'] ?? '') . '|' . $sourceLang] = $other;
        }

        return $matrix;
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

    private function findMenuTranslationIdByKey(string $key): string
    {
        foreach ($this->listMenuFileEntries() as $entry) {
            if ($entry['key'] === $key && $entry['translation_id'] !== '') {
                return $entry['translation_id'];
            }
        }
        return '';
    }

    /** @return array<int, array{lang: string, exists: bool, key: string, title: string, edit_url: string, create_url: string}> */
    private function buildMenuTranslationLinks(string $key, string $translationId, string $sourceLang): array
    {
        $available = $this->settings['languages']['available'] ?? [];
        if (!in_array($sourceLang, $available, true)) {
            $sourceLang = (string)($this->settings['languages']['default'] ?? 'el');
        }
        if ($translationId === '') {
            $translationId = $this->findMenuTranslationIdByKey($key);
        }
        $entries = $this->listMenuFileEntries();
        $byTranslation = [];
        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[$entry['key'] . '|' . $entry['lang']] = $entry;
            if ($translationId !== '' && $entry['translation_id'] === $translationId) {
                $byTranslation[$entry['lang']] = $entry;
            }
        }

        $rows = [];
        foreach ($available as $lang) {
            $entry = $byTranslation[$lang] ?? ($byKey[$key . '|' . $lang] ?? null);
            $exists = $entry !== null;
            $targetKey = $exists ? (string)$entry['key'] : $key;
            $rows[] = [
                'lang' => $lang,
                'exists' => $exists,
                'key' => $targetKey,
                'title' => $exists ? (string)($entry['title'] ?: $this->titleFromSlug($targetKey)) : '',
                'edit_url' => $exists ? '/admin/menus-edit?key=' . urlencode($targetKey) . '&lang=' . urlencode($lang) : '',
                'create_url' => $exists
                    ? ''
                    : '/admin/menus-new?lang=' . urlencode($lang) . '&new_key=' . urlencode($key) . '&source_key=' . urlencode($key) . '&source_lang=' . urlencode($sourceLang) . '&translation_id=' . urlencode($translationId),
            ];
        }
        return $rows;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function resolveThemeMenus(string $lang, string $currentPath): array
    {
        $locations = $this->settings['menu_locations'] ?? [];
        if (!is_array($locations)) {
            $locations = [];
        }
        if (!isset($locations['header']) || trim((string)$locations['header']) === '') {
            $locations['header'] = 'main';
        }
        if (!isset($locations['footer']) || trim((string)$locations['footer']) === '') {
            $locations['footer'] = 'footer';
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
        $custom = $item->meta['template'] ?? null;
        if ($custom && file_exists($this->basePath . '/themes/' . $this->settings['theme'] . '/' . $custom)) {
            return $custom;
        }

        $themeRoot = $this->basePath . '/themes/' . $this->settings['theme'] . '/';
        $singular = $this->singularizeType($item->type);

        $candidates = [
            'single-' . $singular . '.twig',
            'single.twig',
            $item->type . '.twig',
        ];

        if ($item->type === 'pages') {
            $candidates[] = 'page.twig';
        } elseif ($item->type === 'posts') {
            $candidates[] = 'post.twig';
        }

        foreach ($candidates as $template) {
            if (file_exists($themeRoot . $template)) {
                return $template;
            }
        }

        return 'single.twig';
    }

    private function resolveTemplate(string $preferred, string $fallback): string
    {
        if (file_exists($this->basePath . '/themes/' . $this->settings['theme'] . '/' . $preferred)) {
            return $preferred;
        }
        return $fallback;
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
        $themeRoot = $this->basePath . '/themes/' . $this->settings['theme'] . '/';
        $safeSlug = $this->slugify($slug);
        $candidates = [
            'archive-' . $kind . '-' . $safeSlug . '.twig',
            'archive-' . $kind . '.twig',
            'archive.twig',
        ];

        foreach ($candidates as $template) {
            if (file_exists($themeRoot . $template)) {
                return $template;
            }
        }

        return 'archive.twig';
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
        $theme = $this->settings['theme'] ?? 'default';
        $themeDir = $this->basePath . '/themes/' . $theme . '/lang';
        $defaultLang = $this->settings['languages']['default'] ?? 'en';

        $translations = $this->loadTranslationFile($themeDir, $defaultLang);
        if ($lang !== $defaultLang) {
            $translations = array_replace($translations, $this->loadTranslationFile($themeDir, $lang));
        }

        return $translations;
    }

    private function loadTranslationFile(string $dir, string $lang): array
    {
        $path = $dir . '/' . $lang . '.php';
        if (!file_exists($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
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

    private function extractHiddenTranslationKeys(array $translations): array
    {
        $rows = [];
        foreach ($translations as $key => $value) {
            $key = (string)$key;
            if (!$this->isHiddenTranslationKey($key)) {
                continue;
            }
            $rows[$key] = (string)$value;
        }
        return $rows;
    }

    private function writeTranslationFile(string $path, array $translations): void
    {
        $lines = ["<?php", "", "return ["];
        foreach ($translations as $key => $value) {
            $lines[] = "    '" . $this->escapePhpString($key) . "' => '" . $this->escapePhpString($value) . "',";
        }
        $lines[] = "];";
        $payload = implode("\n", $lines) . "\n";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $payload);
    }

    private function escapePhpString(string $value): string
    {
        $value = str_replace("\\", "\\\\", $value);
        return str_replace("'", "\\'", $value);
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
            'smtp_pass' => (string)($merged['forms']['notifications']['smtp']['password'] ?? ''),
            'smtp_encryption' => (string)($merged['forms']['notifications']['smtp']['encryption'] ?? ''),
            'ses_key' => (string)($merged['forms']['notifications']['ses']['key'] ?? ''),
            'ses_secret' => (string)($merged['forms']['notifications']['ses']['secret'] ?? ''),
            'ses_region' => (string)($merged['forms']['notifications']['ses']['region'] ?? ''),
            'menu_location_header' => (string)($menuLocations['header'] ?? 'main'),
            'menu_location_footer' => (string)($menuLocations['footer'] ?? 'footer'),
            'menu_location_keys' => $extraLocationKeys,
            'menu_location_values' => $extraLocationValues,
            'backup_auto_enabled' => $this->isTruthy($merged['backup']['auto']['enabled'] ?? false),
            'backup_schedule' => $backupSchedule,
            'backup_last_run' => (string)($merged['backup']['auto']['last_run'] ?? ''),
            'backup_keep_local' => (string)$backupKeep,
        ];
    }

    private function saveSettings(string $path, string $raw, array $form): void
    {
        foreach ($form as $key => $value) {
            if (is_array($value)) {
                $form[$key] = array_map(fn($item) => trim((string)$item), $value);
            } else {
                $form[$key] = trim((string)$value);
            }
        }
        $data = [];
        if ($raw !== '') {
            $data = Yaml::parse($raw) ?: [];
        }
        if (!is_array($data)) {
            $data = [];
        }

        $data['title'] = $form['title'] !== '' ? $form['title'] : ($data['title'] ?? 'PicolinoCMS');
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

        $data['backup'] = [
            'auto' => [
                'enabled' => $backupAutoEnabled,
                'schedule' => $backupSchedule,
                'last_run' => $backupLastRun,
            ],
            'local' => [
                'keep' => $backupKeep,
            ],
        ];

        $yaml = Yaml::dump($data, 4, 2);
        file_put_contents($path, $yaml);
    }

    private function resolveArchiveTemplate(string $type): string
    {
        $themeRoot = $this->basePath . '/themes/' . $this->settings['theme'] . '/';
        $singular = $this->singularizeType($type);

        $candidates = [
            'archive-' . $type . '.twig',
            'archive-' . $singular . '.twig',
            $type . '_archive.twig',
            'archive.twig',
        ];

        foreach ($candidates as $template) {
            if (file_exists($themeRoot . $template)) {
                return $template;
            }
        }

        return 'archive.twig';
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

    private function escapeYaml(string $value): string
    {
        return str_replace('"', '\\"', $value);
    }

    private function upsertYamlScalar(string $frontmatter, string $key, string $value): string
    {
        $lines = preg_split('/\\R/', $frontmatter) ?: [];
        $found = false;
        $value = trim($value);

        foreach ($lines as $index => $line) {
            if (preg_match('/^' . preg_quote($key, '/') . '\\s*:/', $line)) {
                $found = true;
                if ($value === '') {
                    unset($lines[$index]);
                } else {
                    $lines[$index] = $key . ': "' . $this->escapeYaml($value) . '"';
                }
                break;
            }
        }

        if (!$found && $value !== '') {
            $lines[] = $key . ': "' . $this->escapeYaml($value) . '"';
        }

        $lines = array_values(array_filter($lines, fn($line) => $line !== null));
        return trim(implode("\n", $lines));
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

    private function uniqueFilePath(string $path): string
    {
        if (!file_exists($path)) {
            return $path;
        }

        $dir = dirname($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $counter = 1;

        do {
            $suffix = '-' . $counter;
            $candidate = $dir . '/' . $base . $suffix . ($ext ? '.' . $ext : '');
            $counter++;
        } while (file_exists($candidate));

        return $candidate;
    }

    private function isAllowedUpload(string $tmpPath, string $name): bool
    {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath) ?: '';
        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
        ];
        return in_array($mime, $allowedMimes, true);
    }

    private function listUploads(string $dir, string $urlPrefix = '/uploads'): array
    {
        $items = [];
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            $items[] = [
                'name' => basename($path),
                'size' => filesize($path) ?: 0,
                'mtime' => filemtime($path) ?: 0,
                'url' => rtrim($urlPrefix, '/') . '/' . basename($path),
                'ext' => $ext,
            ];
        }
        usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    private function sanitizeSettingsTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        $allowed = ['basics', 'menus', 'apis', 'theme', 'smtp', 'backup', 'advanced'];
        if (!in_array($tab, $allowed, true)) {
            return 'basics';
        }
        return $tab;
    }

    private function backupDirectory(): string
    {
        return $this->basePath . '/storage/backups';
    }

    private function ensureBackupDirectory(): void
    {
        $dir = $this->backupDirectory();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function maybeRunScheduledBackup(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
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

        $this->ensureBackupDirectory();
        $lockPath = $this->backupDirectory() . '/.auto-backup.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if (!$lockHandle) {
            return;
        }
        if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return;
        }

        $result = $this->createBackupSnapshot();
        if (($result['ok'] ?? false) === true) {
            $this->updateBackupLastRun(date('c'));
        }

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

    /** @return array{ok: bool, message: string, filename?: string} */
    private function createBackupSnapshot(): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'message' => 'Zip extension is not available on this server.'];
        }
        $this->ensureBackupDirectory();
        $siteSlug = $this->slugify((string)($this->settings['title'] ?? 'site'));
        if ($siteSlug === '') {
            $siteSlug = 'site';
        }
        $filename = $siteSlug . '-backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $snapshotPath = $this->backupDirectory() . '/' . $filename;

        $zip = new \ZipArchive();
        if ($zip->open($snapshotPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'message' => 'Could not create backup archive.'];
        }

        $basePrefix = rtrim($this->basePath, '/') . '/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $filesAdded = 0;
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $path = $fileInfo->getPathname();
            if (!str_starts_with($path, $basePrefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($path, strlen($basePrefix)));
            if ($relative === '' || $this->shouldExcludeBackupPath($relative)) {
                continue;
            }
            if ($zip->addFile($path, $relative)) {
                $filesAdded++;
            }
        }
        $zip->close();

        if ($filesAdded === 0) {
            @unlink($snapshotPath);
            return ['ok' => false, 'message' => 'Backup archive is empty.'];
        }

        $keep = (int)($this->settings['backup']['local']['keep'] ?? 20);
        if ($keep < 1) {
            $keep = 1;
        }
        $this->pruneBackupSnapshots($keep);

        return [
            'ok' => true,
            'message' => 'Snapshot created.',
            'filename' => $filename,
        ];
    }

    private function shouldExcludeBackupPath(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return true;
        }
        $basename = basename($relative);
        if ($basename === '.DS_Store') {
            return true;
        }
        $excludedPrefixes = [
            '.git/',
            '.codex/',
            'storage/cache/',
            'storage/backups/',
        ];
        foreach ($excludedPrefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array<string, string|int>> */
    private function listBackupSnapshots(): array
    {
        $this->ensureBackupDirectory();
        $items = [];
        foreach (glob($this->backupDirectory() . '/*.zip') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            $size = (int)(filesize($path) ?: 0);
            $mtime = (int)(filemtime($path) ?: 0);
            $items[] = [
                'filename' => $name,
                'size' => $size,
                'size_human' => $this->formatFileSize($size),
                'mtime' => $mtime,
                'created_at' => date('Y-m-d H:i', $mtime),
            ];
        }
        usort($items, fn($a, $b) => ((int)$b['mtime']) <=> ((int)$a['mtime']));
        return $items;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }
        return number_format($value, 1) . ' ' . $units[$unitIndex];
    }

    private function downloadBackupSnapshot(string $filename): void
    {
        $filename = $this->sanitizeBackupFilename($filename);
        if ($filename === '') {
            $this->redirect('/admin/settings?tab=backup&backup=fail&backup_msg=' . urlencode('Invalid backup file.'));
            return;
        }
        $path = $this->backupDirectory() . '/' . $filename;
        if (!is_file($path)) {
            $this->redirect('/admin/settings?tab=backup&backup=fail&backup_msg=' . urlencode('Backup file not found.'));
            return;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string)(filesize($path) ?: 0));
        readfile($path);
        exit;
    }

    private function sanitizeBackupFilename(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = basename($value);
        if (!preg_match('/^[a-z0-9._-]+$/i', $value)) {
            return '';
        }
        return $value;
    }

    private function updateBackupLastRun(string $isoDate): void
    {
        $path = $this->contentDir . '/settings/site.yaml';
        $data = file_exists($path) ? (Yaml::parseFile($path) ?: []) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $data['backup']['auto']['last_run'] = $isoDate;
        file_put_contents($path, Yaml::dump($data, 4, 2));
        $this->settings = $this->loadSettings();
    }

    private function pruneBackupSnapshots(int $keep): void
    {
        $items = $this->listBackupSnapshots();
        if ($keep < 1) {
            $keep = 1;
        }
        if (count($items) <= $keep) {
            return;
        }
        $remove = array_slice($items, $keep);
        foreach ($remove as $snapshot) {
            $filename = $this->sanitizeBackupFilename((string)($snapshot['filename'] ?? ''));
            if ($filename === '') {
                continue;
            }
            $path = $this->backupDirectory() . '/' . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function migrateImageUploads(): void
    {
        $root = $this->basePath . '/public/uploads';
        $imagesDir = $root . '/images';
        if (!is_dir($imagesDir)) {
            mkdir($imagesDir, 0775, true);
        }

        foreach (glob($root . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
                continue;
            }
            $target = $imagesDir . '/' . basename($path);
            $target = $this->uniqueFilePath($target);
            @rename($path, $target);
        }
    }

    private function migrateImageReferences(): void
    {
        $pattern = '/\\/uploads\\/(?!images\\/|files\\/)/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->contentDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }
            $path = $file->getPathname();
            $raw = (string)file_get_contents($path);
            $updated = preg_replace($pattern, '/uploads/images/', $raw);
            if ($updated !== null && $updated !== $raw) {
                file_put_contents($path, $updated);
            }
        }
    }

    private function normalizeFileUploads(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            $sanitized = $this->sanitizeFilename($name);
            if ($sanitized === '' || $sanitized === $name) {
                continue;
            }
            $target = $dir . '/' . $sanitized;
            $target = $this->uniqueFilePath($target);
            @rename($path, $target);
        }
    }

    /** @return string[] */
    private function mediaTypeOptions(): array
    {
        return ['all', 'image', 'video', 'audio', 'document', 'archive', 'other'];
    }

    private function mediaMetaDir(): string
    {
        return $this->contentDir . '/media';
    }

    private function mediaUploadsRootDir(): string
    {
        return $this->basePath . '/public/uploads';
    }

    private function mediaLibraryDir(): string
    {
        return $this->mediaUploadsRootDir() . '/media';
    }

    private function ensureMediaLibraryDirectories(): void
    {
        foreach ([$this->mediaMetaDir(), $this->mediaUploadsRootDir(), $this->mediaLibraryDir()] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    private function mediaMetaPath(string $id): string
    {
        return $this->mediaMetaDir() . '/' . $id . '.yaml';
    }

    private function sanitizeMediaId(string $id): string
    {
        $id = strtolower(trim($id));
        return preg_match('/^[a-f0-9]{16}$/', $id) === 1 ? $id : '';
    }

    private function sanitizeMediaTag(string $tag): string
    {
        $tags = $this->normalizeMediaTags($tag);
        return $tags[0] ?? '';
    }

    /** @return array<int, string> */
    private function normalizeMediaTags(mixed $tags): array
    {
        if (is_array($tags)) {
            $raw = [];
            foreach ($tags as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $raw[] = $value;
            }
            $tags = implode(',', $raw);
        }

        $csv = trim((string)$tags);
        if ($csv === '') {
            return [];
        }

        $parts = preg_split('/[,;]+/', $csv) ?: [];
        $normalized = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $part = $this->mbLower($part);
            $part = preg_replace('/[^\p{L}\p{N}_\- ]/u', '', $part) ?? '';
            $part = trim(preg_replace('/\s+/', ' ', $part) ?? '');
            if ($part === '') {
                continue;
            }
            $normalized[$part] = true;
        }
        $result = array_keys($normalized);
        sort($result);
        return $result;
    }

    private function sanitizeMediaRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'uploads/')) {
            $path = substr($path, strlen('uploads/'));
        }
        $path = preg_replace('#/+#', '/', $path) ?? '';
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        if (preg_match('#^(images|media|files)/[^/]+$#u', $path) !== 1) {
            return '';
        }
        return $path;
    }

    /** @param array<string, mixed> $meta @return array<string, mixed>|null */
    private function normalizeMediaMeta(array $meta, ?string $fallbackId = null): ?array
    {
        $id = $this->sanitizeMediaId((string)($meta['id'] ?? ($fallbackId ?? '')));
        if ($id === '' && $fallbackId !== null) {
            $id = $this->sanitizeMediaId($fallbackId);
        }
        if ($id === '') {
            return null;
        }

        $path = $this->sanitizeMediaRelativePath((string)($meta['path'] ?? ''));
        if ($path === '') {
            $storedName = basename((string)($meta['stored_name'] ?? ''));
            if ($storedName !== '') {
                $path = $this->sanitizeMediaRelativePath('media/' . $storedName);
            }
        }
        if ($path === '') {
            return null;
        }

        $absolutePath = $this->mediaUploadsRootDir() . '/' . $path;
        $exists = is_file($absolutePath);
        $extension = strtolower((string)($meta['extension'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
        $mimeType = trim((string)($meta['mime_type'] ?? ''));
        if ($mimeType === '') {
            $mimeType = $this->detectFileMimeType($absolutePath, 'application/octet-stream');
        }
        $kind = trim((string)($meta['kind'] ?? ''));
        if ($kind === '') {
            $kind = $this->mediaKindFromMimeAndExtension($mimeType, $extension);
        }

        $sizeBytes = (int)($meta['size_bytes'] ?? 0);
        if ($sizeBytes <= 0 && $exists) {
            $sizeBytes = (int)(filesize($absolutePath) ?: 0);
        }

        $fallbackTimestamp = $exists ? (int)(filemtime($absolutePath) ?: time()) : time();
        $createdAt = trim((string)($meta['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('c', $fallbackTimestamp);
        }
        $updatedAt = trim((string)($meta['updated_at'] ?? ''));
        if ($updatedAt === '') {
            $updatedAt = $createdAt;
        }
        $createdTimestamp = strtotime($createdAt) ?: $fallbackTimestamp;
        $updatedTimestamp = strtotime($updatedAt) ?: $fallbackTimestamp;

        $tags = $this->normalizeMediaTags($meta['tags'] ?? '');
        $originalName = trim((string)($meta['original_name'] ?? ''));
        if ($originalName === '') {
            $originalName = basename($path);
        }

        $url = '/uploads/' . $path;

        return [
            'id' => $id,
            'path' => $path,
            'stored_name' => basename($path),
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'kind' => $kind,
            'size_bytes' => max(0, $sizeBytes),
            'size_human' => $this->formatFileSize(max(0, $sizeBytes)),
            'tags' => $tags,
            'tags_csv' => implode(', ', $tags),
            'uploaded_by' => trim((string)($meta['uploaded_by'] ?? '')),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'created_ts' => (int)$createdTimestamp,
            'updated_ts' => (int)$updatedTimestamp,
            'created_at_display' => date('Y-m-d H:i', (int)$createdTimestamp),
            'updated_at_display' => date('Y-m-d H:i', (int)$updatedTimestamp),
            'direct_url' => $url,
            'thumbnail_url' => $kind === 'image' ? $url : '',
            'exists' => $exists,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function listMediaItems(array $filters = []): array
    {
        $this->ensureMediaLibraryDirectories();
        $items = [];
        foreach (glob($this->mediaMetaDir() . '/*.yaml') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $id = basename($path, '.yaml');
            $parsed = Yaml::parseFile($path) ?: [];
            if (!is_array($parsed)) {
                continue;
            }
            $item = $this->normalizeMediaMeta($parsed, $id);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
        }

        $type = strtolower(trim((string)($filters['type'] ?? 'all')));
        if (!in_array($type, $this->mediaTypeOptions(), true)) {
            $type = 'all';
        }
        $tag = $this->sanitizeMediaTag((string)($filters['tag'] ?? ''));
        $query = $this->mbLower(trim((string)($filters['q'] ?? '')));

        $items = array_values(array_filter($items, function (array $item) use ($type, $tag, $query): bool {
            if ($type !== 'all' && (string)($item['kind'] ?? 'other') !== $type) {
                return false;
            }
            if ($tag !== '') {
                $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
                if (!in_array($tag, $tags, true)) {
                    return false;
                }
            }
            if ($query !== '') {
                $haystack = $this->mbLower(
                    (string)($item['original_name'] ?? '') . ' ' .
                    (string)($item['mime_type'] ?? '') . ' ' .
                    (string)($item['tags_csv'] ?? '')
                );
                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }
            return true;
        }));

        usort($items, function (array $a, array $b): int {
            $aTs = (int)($a['created_ts'] ?? 0);
            $bTs = (int)($b['created_ts'] ?? 0);
            if ($aTs === $bTs) {
                return strcmp((string)($a['original_name'] ?? ''), (string)($b['original_name'] ?? ''));
            }
            return $bTs <=> $aTs;
        });

        return $items;
    }

    /** @return array<string, mixed>|null */
    private function findMediaItem(string $id): ?array
    {
        $id = $this->sanitizeMediaId($id);
        if ($id === '') {
            return null;
        }
        $path = $this->mediaMetaPath($id);
        if (!is_file($path)) {
            return null;
        }
        $parsed = Yaml::parseFile($path) ?: [];
        if (!is_array($parsed)) {
            return null;
        }
        return $this->normalizeMediaMeta($parsed, $id);
    }

    /** @param array<string, mixed> $meta */
    private function saveMediaMeta(array $meta): void
    {
        $id = $this->sanitizeMediaId((string)($meta['id'] ?? ''));
        if ($id === '') {
            return;
        }
        $path = $this->sanitizeMediaRelativePath((string)($meta['path'] ?? ''));
        if ($path === '') {
            return;
        }
        $tags = $this->normalizeMediaTags($meta['tags'] ?? '');
        $payload = [
            'id' => $id,
            'path' => $path,
            'stored_name' => basename($path),
            'original_name' => trim((string)($meta['original_name'] ?? basename($path))),
            'extension' => strtolower((string)($meta['extension'] ?? pathinfo($path, PATHINFO_EXTENSION))),
            'mime_type' => trim((string)($meta['mime_type'] ?? 'application/octet-stream')),
            'kind' => trim((string)($meta['kind'] ?? 'other')),
            'size_bytes' => max(0, (int)($meta['size_bytes'] ?? 0)),
            'tags' => implode(',', $tags),
            'uploaded_by' => trim((string)($meta['uploaded_by'] ?? '')),
            'created_at' => trim((string)($meta['created_at'] ?? gmdate('c'))),
            'updated_at' => trim((string)($meta['updated_at'] ?? gmdate('c'))),
        ];
        file_put_contents($this->mediaMetaPath($id), Yaml::dump($payload, 4, 2));
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeMediaUploads(mixed $rawUpload): array
    {
        if (!is_array($rawUpload) || !array_key_exists('name', $rawUpload)) {
            return [];
        }

        if (is_array($rawUpload['name'] ?? null)) {
            $files = [];
            $count = count($rawUpload['name']);
            for ($i = 0; $i < $count; $i++) {
                $files[] = [
                    'name' => (string)($rawUpload['name'][$i] ?? ''),
                    'type' => (string)($rawUpload['type'][$i] ?? ''),
                    'tmp_name' => (string)($rawUpload['tmp_name'][$i] ?? ''),
                    'error' => (int)($rawUpload['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($rawUpload['size'][$i] ?? 0),
                ];
            }
            return $files;
        }

        return [[
            'name' => (string)($rawUpload['name'] ?? ''),
            'type' => (string)($rawUpload['type'] ?? ''),
            'tmp_name' => (string)($rawUpload['tmp_name'] ?? ''),
            'error' => (int)($rawUpload['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($rawUpload['size'] ?? 0),
        ]];
    }

    /** @param array<string, mixed> $upload @return array<string, mixed> */
    private function uploadMediaItem(array $upload, string $tagsCsv = ''): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->mediaUploadErrorMessage($error));
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        $originalName = trim((string)($upload['name'] ?? ''));
        $sizeBytes = (int)($upload['size'] ?? 0);

        if ($tmpName === '' || !is_file($tmpName)) {
            throw new \RuntimeException('Uploaded file data is missing.');
        }
        if ($originalName === '') {
            throw new \RuntimeException('Uploaded file name is missing.');
        }
        if ($sizeBytes <= 0) {
            $sizeBytes = (int)(filesize($tmpName) ?: 0);
        }
        if ($sizeBytes <= 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }

        $maxBytes = (int)(max(0, (int)($this->settings['media']['max_upload_mb'] ?? 20)) * 1024 * 1024);
        if ($maxBytes > 0 && $sizeBytes > $maxBytes) {
            throw new \RuntimeException('Uploaded file exceeds the maximum allowed size.');
        }

        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
        if (strlen($extension) > 10) {
            $extension = '';
        }

        do {
            $id = bin2hex(random_bytes(8));
            $id = $this->sanitizeMediaId($id);
        } while ($id === '' || is_file($this->mediaMetaPath($id)));

        $storedName = $id . ($extension !== '' ? '.' . $extension : '');
        $relativePath = 'media/' . $storedName;
        $absolutePath = $this->mediaUploadsRootDir() . '/' . $relativePath;
        if (is_file($absolutePath)) {
            throw new \RuntimeException('Upload conflict.');
        }

        $moved = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $absolutePath)
            : rename($tmpName, $absolutePath);
        if (!$moved) {
            throw new \RuntimeException('Could not save the uploaded file.');
        }

        $mimeType = $this->detectFileMimeType($absolutePath, (string)($upload['type'] ?? 'application/octet-stream'));
        $kind = $this->mediaKindFromMimeAndExtension($mimeType, $extension);
        $now = gmdate('c');

        $meta = [
            'id' => $id,
            'path' => $relativePath,
            'stored_name' => $storedName,
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'kind' => $kind,
            'size_bytes' => (int)(filesize($absolutePath) ?: $sizeBytes),
            'tags' => $this->normalizeMediaTags($tagsCsv),
            'uploaded_by' => (string)($this->auth->user()['username'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->saveMediaMeta($meta);
        return $this->findMediaItem($id) ?? $meta;
    }

    private function updateMediaItemTags(string $id, string $tagsCsv): bool
    {
        $item = $this->findMediaItem($id);
        if ($item === null) {
            return false;
        }
        $item['tags'] = $this->normalizeMediaTags($tagsCsv);
        $item['updated_at'] = gmdate('c');
        $this->saveMediaMeta($item);
        return true;
    }

    private function deleteMediaItem(string $id): bool
    {
        $item = $this->findMediaItem($id);
        if ($item === null) {
            return false;
        }
        $relativePath = $this->sanitizeMediaRelativePath((string)($item['path'] ?? ''));
        if ($relativePath !== '') {
            $absolutePath = $this->mediaUploadsRootDir() . '/' . $relativePath;
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
        $metaPath = $this->mediaMetaPath($id);
        if (is_file($metaPath)) {
            @unlink($metaPath);
        }
        return true;
    }

    /** @return array<string, mixed>|null */
    private function findMediaItemByFilename(string $filename): ?array
    {
        $filename = $this->sanitizeFilename($filename);
        if ($filename === '') {
            return null;
        }
        foreach ($this->listMediaItems() as $item) {
            $base = basename((string)($item['path'] ?? ''));
            if ($base === $filename) {
                return $item;
            }
        }
        return null;
    }

    /** @return string[] */
    private function collectMediaIdsFromRequest(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $id = $this->sanitizeMediaId($value);
            if ($id === '') {
                continue;
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    /** @return string[] */
    private function mediaAvailableTags(): array
    {
        $tags = [];
        foreach ($this->listMediaItems() as $item) {
            foreach ((array)($item['tags'] ?? []) as $tag) {
                if (!is_string($tag) || $tag === '') {
                    continue;
                }
                $tags[$tag] = true;
            }
        }
        $result = array_keys($tags);
        sort($result);
        return $result;
    }

    private function migrateLegacyMediaLibraryItems(): void
    {
        $this->ensureMediaLibraryDirectories();
        $existing = [];
        foreach ($this->listMediaItems() as $item) {
            $path = (string)($item['path'] ?? '');
            if ($path !== '') {
                $existing[$path] = true;
            }
        }

        $scanDirs = [
            'images' => $this->mediaUploadsRootDir() . '/images',
            'media' => $this->mediaUploadsRootDir() . '/media',
            'files' => $this->mediaUploadsRootDir() . '/files',
        ];
        foreach ($scanDirs as $prefix => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $relativePath = $this->sanitizeMediaRelativePath($prefix . '/' . basename($path));
                if ($relativePath === '' || isset($existing[$relativePath])) {
                    continue;
                }
                do {
                    $id = $this->sanitizeMediaId(bin2hex(random_bytes(8)));
                } while ($id === '' || is_file($this->mediaMetaPath($id)));

                $mimeType = $this->detectFileMimeType($path, 'application/octet-stream');
                $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
                $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
                $kind = $prefix === 'images' ? 'image' : $this->mediaKindFromMimeAndExtension($mimeType, $extension);
                $timestamp = (int)(filemtime($path) ?: time());
                $now = gmdate('c', $timestamp);
                $meta = [
                    'id' => $id,
                    'path' => $relativePath,
                    'stored_name' => basename($relativePath),
                    'original_name' => basename($relativePath),
                    'extension' => $extension,
                    'mime_type' => $mimeType,
                    'kind' => $kind,
                    'size_bytes' => (int)(filesize($path) ?: 0),
                    'tags' => '',
                    'uploaded_by' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $this->saveMediaMeta($meta);
                $existing[$relativePath] = true;
            }
        }
    }

    private function detectFileMimeType(string $path, string $fallback = 'application/octet-stream'): string
    {
        if (!is_file($path)) {
            return $fallback;
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $value = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }
        return $fallback;
    }

    private function mediaKindFromMimeAndExtension(string $mimeType, string $extension): string
    {
        $mime = strtolower($mimeType);
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'md', 'rtf'];
        $archiveExtensions = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
        if (in_array($extension, $archiveExtensions, true)) {
            return 'archive';
        }
        if (str_starts_with($mime, 'text/') || in_array($extension, $documentExtensions, true)) {
            return 'document';
        }
        return 'other';
    }

    private function mediaUploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds allowed size.',
            UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially received.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by a PHP extension.',
            default => 'Unknown upload error.',
        };
    }

    private function mbLower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private function isAllowedFileUpload(string $tmpPath, string $name): bool
    {
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath) ?: '';
        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'application/zip',
            'application/x-zip-compressed',
        ];
        return in_array($mime, $allowedMimes, true);
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
        $dir = $this->contentDir . '/forms-submissions/' . $form->slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $payload = [
            'id' => $id,
            'form' => $form->slug,
            'lang' => $form->lang,
            'translation_id' => (string)($form->meta['translation_id'] ?? ''),
            'submitted_at' => date('c'),
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'fields' => $values,
        ];
        file_put_contents($dir . '/' . $id . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        $dir = $this->contentDir . '/forms-submissions/' . $slug;
        if (!is_dir($dir)) {
            return [];
        }
        $entries = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $raw = (string)file_get_contents($path);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $entries[] = [
                'id' => (string)($data['id'] ?? basename($path, '.json')),
                'submitted_at' => (string)($data['submitted_at'] ?? ''),
                'form' => (string)($data['form'] ?? $slug),
                'lang' => (string)($data['lang'] ?? ''),
                'translation_id' => (string)($data['translation_id'] ?? ''),
                'ip' => (string)($data['ip'] ?? ''),
                'user_agent' => (string)($data['user_agent'] ?? ''),
                'fields' => is_array($data['fields'] ?? null) ? $data['fields'] : [],
            ];
        }
        usort($entries, function (array $a, array $b): int {
            return strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? ''));
        });
        return $entries;
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

        $headers = fgetcsv($handle, 0, $delimiter);
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
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
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

    private function sendEmailMessage(string $to, string $subject, string $body, array $headers): bool
    {
        $driver = (string)($this->settings['forms']['notifications']['driver'] ?? '');
        if ($driver === 'ses') {
            if (!$this->shouldUseSes()) {
                return false;
            }
            return $this->sendViaSes($to, $subject, $body, $headers);
        }
        if ($driver === 'smtp') {
            if (!$this->shouldUseSmtp()) {
                return false;
            }
            return $this->sendViaSmtp($to, $subject, $body, $headers);
        }
        if ($this->shouldUseSes()) {
            return $this->sendViaSes($to, $subject, $body, $headers);
        }
        if ($this->shouldUseSmtp()) {
            return $this->sendViaSmtp($to, $subject, $body, $headers);
        }
        return false;
    }

    private function sendViaMail(string $to, string $subject, string $body, array $headers): bool
    {
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        return @mail($to, $subject, $body, implode("\r\n", $lines));
    }

    private function shouldUseSmtp(): bool
    {
        $smtp = $this->settings['forms']['notifications']['smtp'] ?? [];
        if (!is_array($smtp)) {
            return false;
        }
        $host = trim((string)($smtp['host'] ?? ''));
        $port = (int)($smtp['port'] ?? 0);
        return $host !== '' && $port > 0;
    }

    private function shouldUseSes(): bool
    {
        $ses = $this->settings['forms']['notifications']['ses'] ?? [];
        if (!is_array($ses)) {
            return false;
        }
        $key = trim((string)($ses['key'] ?? ''));
        $secret = trim((string)($ses['secret'] ?? ''));
        $region = trim((string)($ses['region'] ?? ''));
        return $key !== '' && $secret !== '' && $region !== '';
    }

    private function sendViaSmtp(string $to, string $subject, string $body, array $headers): bool
    {
        $smtp = $this->settings['forms']['notifications']['smtp'] ?? [];
        if (!is_array($smtp)) {
            $smtp = [];
        }
        $host = trim((string)($smtp['host'] ?? ''));
        $port = (int)($smtp['port'] ?? 0);
        if ($host === '' || $port === 0) {
            return false;
        }

        $username = (string)($smtp['username'] ?? '');
        $password = (string)($smtp['password'] ?? '');
        $encryption = (string)($smtp['encryption'] ?? '');
        $remote = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $fp = @fsockopen($remote, $port, $errno, $errstr, 10);
        if (!$fp) {
            return false;
        }

        $this->smtpRead($fp);
        $hostname = gethostname() ?: 'localhost';
        if (!$this->smtpCommand($fp, 'EHLO ' . $hostname, 250)) {
            fclose($fp);
            return false;
        }

        if ($encryption === 'tls') {
            if (!$this->smtpCommand($fp, 'STARTTLS', 220)) {
                fclose($fp);
                return false;
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return false;
            }
            if (!$this->smtpCommand($fp, 'EHLO ' . $hostname, 250)) {
                fclose($fp);
                return false;
            }
        }

        if ($username !== '') {
            if (!$this->smtpCommand($fp, 'AUTH LOGIN', 334)) {
                fclose($fp);
                return false;
            }
            if (!$this->smtpCommand($fp, base64_encode($username), 334)) {
                fclose($fp);
                return false;
            }
            if (!$this->smtpCommand($fp, base64_encode($password), 235)) {
                fclose($fp);
                return false;
            }
        }

        $from = $headers['From'] ?? '';
        $fromEmail = $this->extractEmailAddress($from);
        if ($fromEmail === '') {
            fclose($fp);
            return false;
        }
        if (!$this->smtpCommand($fp, 'MAIL FROM:<' . $fromEmail . '>', 250)) {
            fclose($fp);
            return false;
        }

        $recipients = $this->parseEmailList($to);
        $recipients = array_merge($recipients, $this->parseEmailList((string)($headers['Cc'] ?? '')), $this->parseEmailList((string)($headers['Bcc'] ?? '')));
        foreach ($recipients as $recipient) {
            if (!$this->smtpCommand($fp, 'RCPT TO:<' . $recipient . '>', 250)) {
                fclose($fp);
                return false;
            }
        }

        if (!$this->smtpCommand($fp, 'DATA', 354)) {
            fclose($fp);
            return false;
        }

        $lines = [];
        $lines[] = 'To: ' . $to;
        $lines[] = 'Subject: ' . $subject;
        foreach ($headers as $key => $value) {
            if (in_array($key, ['Cc', 'From', 'Reply-To'], true)) {
                $lines[] = $key . ': ' . $value;
            }
        }
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = '';
        $lines[] = $body;
        $data = implode("\r\n", $lines);
        $data = str_replace("\n.", "\n..", $data);
        fwrite($fp, $data . "\r\n.\r\n");
        $this->smtpRead($fp);
        $this->smtpCommand($fp, 'QUIT', 221);
        fclose($fp);
        return true;
    }

    private function smtpCommand($fp, string $command, int $expectCode): bool
    {
        fwrite($fp, $command . "\r\n");
        $response = $this->smtpRead($fp);
        if ($response === '') {
            return false;
        }
        $code = (int)substr($response, 0, 3);
        return $code === $expectCode || ($expectCode === 250 && $code >= 250 && $code < 260);
    }

    private function smtpRead($fp): string
    {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    private function sendViaSes(string $to, string $subject, string $body, array $headers): bool
    {
        $ses = $this->settings['forms']['notifications']['ses'] ?? [];
        if (!is_array($ses)) {
            $ses = [];
        }
        $accessKey = trim((string)($ses['key'] ?? ''));
        $secretKey = trim((string)($ses['secret'] ?? ''));
        $region = trim((string)($ses['region'] ?? ''));
        if ($accessKey === '' || $secretKey === '' || $region === '') {
            return false;
        }

        $from = $headers['From'] ?? '';
        $fromEmail = $this->extractEmailAddress($from);
        if ($fromEmail === '') {
            return false;
        }

        $payload = [
            'FromEmailAddress' => $fromEmail,
            'Destination' => [
                'ToAddresses' => $this->parseEmailList($to),
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => [
                        'Data' => $subject,
                        'Charset' => 'UTF-8',
                    ],
                    'Body' => [
                        'Text' => [
                            'Data' => $body,
                            'Charset' => 'UTF-8',
                        ],
                    ],
                ],
            ],
        ];
        $replyTo = $headers['Reply-To'] ?? '';
        if ($replyTo !== '') {
            $payload['ReplyToAddresses'] = $this->parseEmailList($replyTo);
        }
        $cc = $headers['Cc'] ?? '';
        if ($cc !== '') {
            $payload['Destination']['CcAddresses'] = $this->parseEmailList($cc);
        }
        $bcc = $headers['Bcc'] ?? '';
        if ($bcc !== '') {
            $payload['Destination']['BccAddresses'] = $this->parseEmailList($bcc);
        }

        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            return false;
        }

        $service = 'ses';
        $host = 'email.' . $region . '.amazonaws.com';
        $uri = '/v2/email/outbound-emails';
        $method = 'POST';
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = hash('sha256', $payloadJson);

        $canonicalHeaders = 'content-type:application/json' . "\n" . 'host:' . $host . "\n" . 'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $canonicalRequest = $method . "\n" . $uri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
        $credentialScope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = 'AWS4-HMAC-SHA256' . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->awsSign('AWS4' . $secretKey, $date);
        $signingKey = $this->awsSign($signingKey, $region);
        $signingKey = $this->awsSign($signingKey, $service);
        $signingKey = $this->awsSign($signingKey, 'aws4_request');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $headersOut = [
            'Content-Type: application/json',
            'Host: ' . $host,
            'X-Amz-Date: ' . $amzDate,
            'Authorization: ' . $authorization,
        ];

        $url = 'https://' . $host . $uri;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headersOut);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $status >= 200 && $status < 300;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headersOut),
                'content' => $payloadJson,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }
        return true;
    }

    private function awsSign(string $key, string $msg): string
    {
        return hash_hmac('sha256', $msg, $key, true);
    }

    /** @return string[] */
    private function parseEmailList(string $value): array
    {
        $list = [];
        foreach (preg_split('/[,;]+/', $value) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $email = $this->extractEmailAddress($item);
            if ($email !== '') {
                $list[] = $email;
            }
        }
        return array_values(array_unique($list));
    }

    private function extractEmailAddress(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            $value = $matches[1];
        }
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $value;
        }
        return '';
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
        return $this->twig->render('parts/form.twig', [
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

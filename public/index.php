<?php

declare(strict_types=1);

// PHP's built-in server (`php -S … -t public public/index.php`): let it serve files that exist.
if (PHP_SAPI === 'cli-server') {
    $requested = realpath(__DIR__ . rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)));
    if ($requested !== false && is_file($requested) && str_starts_with($requested, __DIR__ . DIRECTORY_SEPARATOR)
        && strtolower(pathinfo($requested, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
}

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require $vendorAutoload;
}

$smartObject = dirname(__DIR__) . '/vendor/nette/utils/src/SmartObject.php';
if (!trait_exists('Nette\\SmartObject') && file_exists($smartObject)) {
    require_once $smartObject;
}

// Theme and custom/ assets live outside public/; serve them before the application boots.
if (FarosCMS\ThemeAssets::handle(dirname(__DIR__), (string)($_SERVER['REQUEST_URI'] ?? '/'))) {
    return;
}
// Missing responsive image variants are generated once, then served statically by the web server.
if (FarosCMS\Images::handle(__DIR__, (string)($_SERVER['REQUEST_URI'] ?? '/'))) {
    return;
}

$app = new FarosCMS\App(dirname(__DIR__));
$app->handle();

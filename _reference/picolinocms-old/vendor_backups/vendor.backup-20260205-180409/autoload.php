<?php
$baseDir = __DIR__;
$files = [
    '/Users/christoschiotis/Documents/New project/vendor/twig/twig/src/Resources/core.php',
    '/Users/christoschiotis/Documents/New project/vendor/twig/twig/src/Resources/debug.php',
    '/Users/christoschiotis/Documents/New project/vendor/twig/twig/src/Resources/escaper.php',
    '/Users/christoschiotis/Documents/New project/vendor/twig/twig/src/Resources/string_loader.php',
    '/Users/christoschiotis/Documents/New project/vendor/symfony/deprecation-contracts/function.php',
    '/Users/christoschiotis/Documents/New project/vendor/symfony/polyfill-mbstring/bootstrap.php',
    '/Users/christoschiotis/Documents/New project/vendor/symfony/polyfill-ctype/bootstrap.php',
    '/Users/christoschiotis/Documents/New project/vendor/symfony/polyfill-php80/bootstrap.php',
];
foreach ($files as $file) { if (is_file($file)) { require_once $file; } }
$prefixes = [
    'Twig\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/twig/twig/src/',
    ],
    'League\\CommonMark\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/league/commonmark/src/',
    ],
    'Symfony\\Component\\Yaml\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/symfony/yaml/',
    ],
    'Symfony\\Polyfill\\Mbstring\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/symfony/polyfill-mbstring/',
    ],
    'Symfony\\Polyfill\\Ctype\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/symfony/polyfill-ctype/',
    ],
    'League\\Config\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/league/config/src/',
    ],
    'Psr\\EventDispatcher\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/psr/event-dispatcher/src/',
    ],
    'Symfony\\Polyfill\\Php80\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/symfony/polyfill-php80/',
    ],
    'Dflydev\\DotAccessData\\' => [
        '/Users/christoschiotis/Documents/New project/vendor/dflydev/dot-access-data/src/',
    ],
    'FlatCMS\\' => [
        '/Users/christoschiotis/Documents/New project/src/',
    ],
];
spl_autoload_register(function ($class) use ($prefixes) {
    foreach ($prefixes as $prefix => $dirs) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $relativePath = str_replace('\\', '/', $relative) . '.php';
        foreach ($dirs as $dir) {
            $file = $dir . $relativePath;
            if (is_file($file)) { require $file; return true; }
        }
    }
    return false;
});

<?php
$baseDir = __DIR__;
$files = [
    $baseDir . '/twig/twig/src/Resources/core.php',
    $baseDir . '/twig/twig/src/Resources/debug.php',
    $baseDir . '/twig/twig/src/Resources/escaper.php',
    $baseDir . '/twig/twig/src/Resources/string_loader.php',
    $baseDir . '/symfony/deprecation-contracts/function.php',
    $baseDir . '/symfony/polyfill-mbstring/bootstrap.php',
    $baseDir . '/symfony/polyfill-ctype/bootstrap.php',
    $baseDir . '/symfony/polyfill-php80/bootstrap.php',
];
$classmapFiles = [
    $baseDir . '/nette/utils/src/SmartObject.php',
    $baseDir . '/nette/utils/src/HtmlStringable.php',
    $baseDir . '/nette/utils/src/Iterators/CachingIterator.php',
    $baseDir . '/nette/utils/src/Iterators/Mapper.php',
    $baseDir . '/nette/utils/src/StaticClass.php',
    $baseDir . '/nette/utils/src/Translator.php',
    $baseDir . '/nette/utils/src/Utils/ArrayHash.php',
    $baseDir . '/nette/utils/src/Utils/ArrayList.php',
    $baseDir . '/nette/utils/src/Utils/Arrays.php',
    $baseDir . '/nette/utils/src/Utils/Callback.php',
    $baseDir . '/nette/utils/src/Utils/DateTime.php',
    $baseDir . '/nette/utils/src/Utils/FileInfo.php',
    $baseDir . '/nette/utils/src/Utils/FileSystem.php',
    $baseDir . '/nette/utils/src/Utils/Finder.php',
    $baseDir . '/nette/utils/src/Utils/Floats.php',
    $baseDir . '/nette/utils/src/Utils/Helpers.php',
    $baseDir . '/nette/utils/src/Utils/Html.php',
    $baseDir . '/nette/utils/src/Utils/Image.php',
    $baseDir . '/nette/utils/src/Utils/ImageColor.php',
    $baseDir . '/nette/utils/src/Utils/ImageType.php',
    $baseDir . '/nette/utils/src/Utils/Iterables.php',
    $baseDir . '/nette/utils/src/Utils/Json.php',
    $baseDir . '/nette/utils/src/Utils/ObjectHelpers.php',
    $baseDir . '/nette/utils/src/Utils/Paginator.php',
    $baseDir . '/nette/utils/src/Utils/Random.php',
    $baseDir . '/nette/utils/src/Utils/Reflection.php',
    $baseDir . '/nette/utils/src/Utils/ReflectionMethod.php',
    $baseDir . '/nette/utils/src/Utils/Strings.php',
    $baseDir . '/nette/utils/src/Utils/Type.php',
    $baseDir . '/nette/utils/src/Utils/Validators.php',
    $baseDir . '/nette/utils/src/Utils/exceptions.php',
    $baseDir . '/nette/utils/src/compatibility.php',
    $baseDir . '/nette/utils/src/exceptions.php',
    $baseDir . '/nette/schema/src/Schema/Context.php',
    $baseDir . '/nette/schema/src/Schema/DynamicParameter.php',
    $baseDir . '/nette/schema/src/Schema/Elements/AnyOf.php',
    $baseDir . '/nette/schema/src/Schema/Elements/Base.php',
    $baseDir . '/nette/schema/src/Schema/Elements/Structure.php',
    $baseDir . '/nette/schema/src/Schema/Elements/Type.php',
    $baseDir . '/nette/schema/src/Schema/Expect.php',
    $baseDir . '/nette/schema/src/Schema/Helpers.php',
    $baseDir . '/nette/schema/src/Schema/Message.php',
    $baseDir . '/nette/schema/src/Schema/Processor.php',
    $baseDir . '/nette/schema/src/Schema/Schema.php',
    $baseDir . '/nette/schema/src/Schema/ValidationException.php',
    $baseDir . '/symfony/polyfill-php80/Resources/stubs/Attribute.php',
    $baseDir . '/symfony/polyfill-php80/Resources/stubs/PhpToken.php',
    $baseDir . '/symfony/polyfill-php80/Resources/stubs/Stringable.php',
    $baseDir . '/symfony/polyfill-php80/Resources/stubs/UnhandledMatchError.php',
    $baseDir . '/symfony/polyfill-php80/Resources/stubs/ValueError.php',
];
foreach ($files as $file) { if (is_file($file)) { require_once $file; } }
foreach ($classmapFiles as $file) { if (is_file($file)) { require_once $file; } }
$prefixes = [
    'Twig\\' => [
        $baseDir . '/twig/twig/src/',
    ],
    'League\\CommonMark\\' => [
        $baseDir . '/league/commonmark/src/',
    ],
    'Symfony\\Component\\Yaml\\' => [
        $baseDir . '/symfony/yaml/',
    ],
    'Nette\\' => [
        $baseDir . '/nette/utils/src/',
    ],
    'Symfony\\Polyfill\\Mbstring\\' => [
        $baseDir . '/symfony/polyfill-mbstring/',
    ],
    'Symfony\\Polyfill\\Ctype\\' => [
        $baseDir . '/symfony/polyfill-ctype/',
    ],
    'League\\Config\\' => [
        $baseDir . '/league/config/src/',
    ],
    'Psr\\EventDispatcher\\' => [
        $baseDir . '/psr/event-dispatcher/src/',
    ],
    'Symfony\\Polyfill\\Php80\\' => [
        $baseDir . '/symfony/polyfill-php80/',
    ],
    'Dflydev\\DotAccessData\\' => [
        $baseDir . '/dflydev/dot-access-data/src/',
    ],
    'FlatCMS\\' => [
        $baseDir . '/../src/',
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

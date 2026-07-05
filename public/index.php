<?php

declare(strict_types=1);

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require $vendorAutoload;
}

$smartObject = dirname(__DIR__) . '/vendor/nette/utils/src/SmartObject.php';
if (!trait_exists('Nette\\SmartObject') && file_exists($smartObject)) {
    require_once $smartObject;
}

$app = new FarosCMS\App(dirname(__DIR__));
$app->handle();

<?php
declare(strict_types=1);

if (defined('APP_BOOTSTRAPPED')) {
    return;
}

define('APP_BOOTSTRAPPED', true);
define('ROOT_PATH', dirname(__DIR__));
define('APPLICATION_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'application');
define('APP_PATH', APPLICATION_PATH);
define('PUBLIC_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'public');
define('CONFIG_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'config');
define('STORAGE_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'storage');
define('DATABASE_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'database');
define('RESOURCES_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'resources');

/**
 * Absolute path under the application root (university-erp/).
 */
function root_path(string $relative = ''): string
{
    return $relative === ''
        ? ROOT_PATH
        : ROOT_PATH . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
}

/**
 * Absolute path under application/.
 */
function app_path(string $relative = ''): string
{
    return $relative === ''
        ? APP_PATH
        : APP_PATH . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
}

/**
 * Absolute path under public/.
 */
function public_path(string $relative = ''): string
{
    return $relative === ''
        ? PUBLIC_PATH
        : PUBLIC_PATH . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
}

/**
 * Absolute path under storage/.
 */
function storage_path(string $relative = ''): string
{
    return $relative === ''
        ? STORAGE_PATH
        : STORAGE_PATH . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
}

$autoload = root_path('vendor/autoload.php');
if (is_file($autoload)) {
    require_once $autoload;
}

require_once app_path('config.php');

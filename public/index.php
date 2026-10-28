<?php
declare(strict_types=1);

/**
 * Front controller for ERP modules under /application.
 * Dispatches extensionless ERP URLs while preserving SCRIPT_NAME for guards.
 */
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$requested = basename($requestPath);
if ($requested === '' || $requested === '/' || $requested === 'index.php') {
    $requested = 'index.php';
}
if (!str_ends_with($requested, '.php')) {
    $requested .= '.php';
}

$_SERVER['SCRIPT_NAME'] = '/' . $requested;
$_SERVER['PHP_SELF'] = '/' . $requested;

require dirname(__DIR__) . '/application/bootstrap.php';

$routes = require app_path('routes.php');
if (!isset($routes[$requested])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Page introuvable.';
    exit;
}

require app_path($routes[$requested]);

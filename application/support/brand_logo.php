<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    require_once dirname(__DIR__) . '/bootstrap.php';
}

$row = null;
try {
    institution_ensure();
    if (institution_logo_custom()) {
        $row = db()->query("SELECT mime, content FROM institution_logo WHERE asset_key = 'logo'")->fetch();
    }
} catch (Throwable $exception) {
    $row = null;
}

if ($row) {
    $row['content'] = pg_blob($row['content']);
}
if (!$row || $row['content'] === '') {
    $fallback = public_path('images/no_bg_logo.png');
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=300');
    readfile($fallback);
    exit;
}

header('Content-Type: ' . (string) $row['mime']);
header('Cache-Control: public, max-age=300');
echo $row['content'];

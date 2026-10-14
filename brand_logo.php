<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$row = null;
try {
    institution_ensure();
    if (institution_logo_custom()) {
        $row = db()->query("SELECT mime, content FROM institution_logo WHERE asset_key = 'logo'")->fetch();
    }
} catch (Throwable $exception) {
    $row = null;
}

if (!$row || $row['content'] === null || $row['content'] === '') {
    $fallback = __DIR__ . '/images/no_bg_logo.png';
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=300');
    readfile($fallback);
    exit;
}

header('Content-Type: ' . (string) $row['mime']);
header('Cache-Control: public, max-age=300');
echo $row['content'];

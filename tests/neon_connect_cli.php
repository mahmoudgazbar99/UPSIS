<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/dashboard.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';

$pdo = db();
$row = $pdo->query(
    'SELECT current_database() AS db, current_setting(\'server_version\') AS version, COUNT(*) AS students FROM etudiant'
)->fetch();
$host = parse_url((string) getenv('DATABASE_URL_UNPOOLED'), PHP_URL_HOST);
echo 'db ' . $row['db'] . PHP_EOL;
echo 'host ' . $host . PHP_EOL;
echo 'version ' . $row['version'] . PHP_EOL;
echo 'students ' . $row['students'] . PHP_EOL;
echo 'branch ' . getenv('NEON_BRANCH') . PHP_EOL;

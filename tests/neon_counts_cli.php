<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/dashboard.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';

if (getenv('CHECK_NEON') === '1') {
    putenv('RENDER=true');
    $_ENV['RENDER'] = 'true';
}

$pdo = db();
$host = getenv('RENDER') === 'true'
    ? (string) parse_url((string) getenv('DATABASE_URL_UNPOOLED'), PHP_URL_HOST)
    : ((getenv('DB_HOST') ?: 'local'));
echo 'target ' . $host . PHP_EOL;
foreach (['etudiant', 'etudiant_compte'] as $table) {
    echo $table . ' ' . $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() . PHP_EOL;
}

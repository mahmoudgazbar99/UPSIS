<?php
declare(strict_types=1);

$base = getenv('APP_URL') ?: 'http://127.0.0.1:8080';
$pages = ['login.php', 'accueil.php', 'index.php', 'connexion_etudiant.php'];
$failed = 0;
foreach ($pages as $page) {
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
    $html = file_get_contents(rtrim($base, '/') . '/' . $page, false, $context);
    $status = $http_response_header[0] ?? 'no status';
    $broken = $html === false || str_contains((string) $html, 'Fatal error') || str_contains((string) $html, 'SQLSTATE') || str_contains((string) $html, 'Uncaught');
    echo ($broken ? 'FAIL ' : 'ok   ') . $status . ' ' . $page . ' bytes=' . strlen((string) $html) . PHP_EOL;
    if ($broken) {
        $failed++;
        echo substr((string) $html, 0, 500) . PHP_EOL;
    }
}
exit($failed === 0 ? 0 : 1);

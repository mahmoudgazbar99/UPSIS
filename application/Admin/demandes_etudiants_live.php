<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/student_portal.php');
require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = db();
    echo json_encode([
        'count' => student_portal_pending_count($pdo),
        'stamp' => student_portal_pending_stamp($pdo),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['count' => 0, 'stamp' => '0'], JSON_UNESCAPED_UNICODE);
}

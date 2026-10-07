<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$studentId = filter_var($_SESSION['id_etudiant'] ?? null, FILTER_VALIDATE_INT);
if (!is_student() || $studentId === false || $studentId <= 0) {
    http_response_code(403);
    echo json_encode(['unread' => 0, 'stamp' => '0', 'messages' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    student_notifications_ensure_tables($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $notificationId = filter_input(INPUT_POST, 'id_notif', FILTER_VALIDATE_INT) ?: 0;
        student_notification_mark_read($pdo, $studentId, $notificationId);
    }

    echo json_encode(student_notifications_payload($pdo, $studentId), JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['unread' => 0, 'stamp' => '0', 'messages' => []], JSON_UNESCAPED_UNICODE);
}

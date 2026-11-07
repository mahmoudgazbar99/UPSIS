<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/student_portal.php');

$studentId = filter_var($_SESSION['id_etudiant'] ?? null, FILTER_VALIDATE_INT);
if (!is_student() || $studentId === false || $studentId <= 0) {
    http_response_code(403);
    exit;
}

student_photo_ensure(db());
$query = db()->prepare('SELECT photo_stored FROM etudiant WHERE id_etudiant = ? LIMIT 1');
$query->execute([(int) $studentId]);
$path = student_photo_path((string) $query->fetchColumn());
if ($path === null) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('Content-Length: ' . (string) filesize($path));
readfile($path);

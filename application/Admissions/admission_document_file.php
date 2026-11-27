<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
admissions_ensure();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = db()->prepare(
    'SELECT d.*, a.id_candidate, a.id_application
     FROM admission_document d
     JOIN admission_application a ON a.id_application = d.id_application
     WHERE d.id_document = ? LIMIT 1'
);
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) {
    http_response_code(404);
    exit('Document introuvable.');
}

$allowed = false;
if (is_admin() || (function_exists('is_scolarite') && is_scolarite() && role_can('admissions.view'))) {
    $allowed = true;
}
if (is_candidate() && current_candidate_id() === (int) $doc['id_candidate']) {
    $allowed = true;
}
if (!$allowed) {
    http_response_code(403);
    exit('Accès refusé.');
}

$path = admissions_document_path((string) $doc['stored_name']);
if ($path === null) {
    http_response_code(404);
    exit('Fichier manquant.');
}

$mime = (string) ($doc['mime'] ?: 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string) $doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

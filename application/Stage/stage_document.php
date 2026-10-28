<?php
declare(strict_types=1);
require_once app_path('bootstrap.php');
require_login();
access_ensure();

$versionId = (int) ($_GET['version'] ?? 0);
if ($versionId > 0) {
    stage_doc_send($versionId, (string) ($_GET['apercu'] ?? '') === '1');
}

if (!is_admin() && !is_stage() && !is_student()) {
    http_response_code(403);
    exit('Action non autorisée pour ce compte.');
}
if (is_stage() && !role_can('stage.view')) {
    http_response_code(403);
    exit('Action non autorisée pour ce compte.');
}

$id = (int) ($_GET['id'] ?? 0);
$query = db()->prepare(
    'SELECT d.titre, d.mime, d.contenu, d.id_placement, p.id_etudiant, p.id_stage_superviseur, p.created_by
     FROM stage_document d
     LEFT JOIN stage_placement p ON p.id_placement = d.id_placement
     WHERE d.id_document = ?'
);
$query->execute([$id]);
$row = $query->fetch();
$allowed = $row && (
    ($row['id_placement'] && stage_placement_visible($row) && (is_admin() || is_stage()))
    || (is_student() && (int) ($row['id_etudiant'] ?? 0) === (int) ($_SESSION['id_etudiant'] ?? 0))
);
if (!$allowed) {
    http_response_code(404);
    exit('Document introuvable.');
}
$name = stage_doc_filename((string) $row['titre']);
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Cache-Control: private, no-store');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
echo pg_blob($row['contenu']);

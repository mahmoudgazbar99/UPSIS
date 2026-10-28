<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/emploi_pdf.php');

$groupId = max(0, (int) ($_GET['id_groupe'] ?? 0));
$action = (string) ($_GET['action'] ?? 'view');
if (!in_array($action, ['view', 'download'], true)) {
    $action = 'view';
}

$meta = emploi_pdf_meta($groupId);
if (!$meta || !is_file((string) $meta['path'])) {
    http_response_code(404);
    exit('PDF introuvable.');
}

$allowed = false;
if (is_admin() || is_scolarite()) {
    $allowed = true;
} elseif (is_student()) {
    $allowed = emploi_pdf_student_may_access((int) ($_SESSION['id_etudiant'] ?? 0), $groupId);
}

if (!$allowed) {
    require_login();
    http_response_code(403);
    exit('Accès refusé.');
}

$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $meta['original_name']) ?: ('emploi-groupe-' . $groupId . '.pdf');
$disposition = $action === 'download' ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Length: ' . (string) filesize((string) $meta['path']));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile((string) $meta['path']);
exit;

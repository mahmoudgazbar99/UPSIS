<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/course_library.php');

if (!in_array((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Méthode non autorisée.');
}

$studentSpace = ($_GET['espace'] ?? '') === 'etudiant';
if (!logged_in()) {
    header('Location: ' . ($studentSpace ? 'connexion_etudiant.php' : 'login.php'));
    exit;
}

$fileId = (int) (filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
$action = ($_GET['action'] ?? 'view') === 'download' ? 'download' : 'view';

$meta = course_file_meta($fileId);
$allowed = false;
if ($meta !== null) {
    if (is_admin()) {
        $allowed = true;
    } elseif (is_teacher()) {
        $allowed = current_teacher_id() > 0 && $meta['id_enseignant'] === current_teacher_id();
    } elseif (is_student()) {
        $allowed = course_student_may_access_file((int) ($_SESSION['id_etudiant'] ?? 0), $fileId);
    }
}

if ($meta !== null && !$allowed) {
    require_login();
    http_response_code(403);
    exit('Accès refusé.');
}

$path = $meta !== null ? realpath($meta['path']) : false;
$root = realpath(course_storage_dir());
if ($meta === null || $path === false || $root === false || !is_file($path)
    || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

$name = $meta['nom'];
$ascii = $name;
if (function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if (is_string($converted) && $converted !== '') {
        $ascii = $converted;
    }
}
$ascii = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $ascii), ' ._');
if ($ascii === '' || !str_ends_with(strtolower($ascii), '.pdf')) {
    $ascii = ($ascii === '' ? 'cours-' . $meta['id'] : $ascii) . '.pdf';
}
$disposition = $action === 'download' ? 'attachment' : 'inline';

session_write_close();
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
readfile($path);
exit;

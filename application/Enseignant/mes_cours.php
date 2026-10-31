<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_once app_path('support/course_library.php');
require_once app_path('support/course_explorer_view.php');
require_login();

if (!is_teacher() || current_teacher_id() <= 0) {
    http_response_code(403);
    exit('Accès réservé aux enseignants : cet espace contient la bibliothèque de cours de chaque enseignant.');
}

$teacherId = current_teacher_id();
course_library_ensure(db());

function mes_cours_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $number = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function mes_cours_post_int(string $key): int
{
    return (int) (filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
}

function mes_cours_post_text(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

/**
 * @return array{0: ?int, 1: ?int} [id_cours, id_dossier] when the folder belongs to the teacher.
 */
function mes_cours_location(int $teacherId, int $folderId): array
{
    if ($folderId <= 0) {
        return [null, null];
    }
    $folder = course_folder_for_teacher($teacherId, $folderId);
    if (!$folder) {
        return [null, null];
    }
    return [(int) $folder['id_cours'], $folderId];
}

function mes_cours_redirect(?int $courseId, ?int $folderId): never
{
    $query = [];
    if (app_is_embedded()) {
        $query['embed'] = '1';
    }
    if ($courseId) {
        $query['cours'] = $courseId;
        if ($folderId) {
            $query['dossier'] = $folderId;
        }
    }
    header('Location: mes_cours.php' . ($query ? '?' . http_build_query($query) : ''), true, 303);
    exit;
}

if (($_GET['finder'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $slimFolder = static function (array $node) use (&$slimFolder): array {
        return [
            'id' => (int) ($node['id'] ?? 0),
            'name' => (string) ($node['nom'] ?? ''),
            'folders' => array_map($slimFolder, is_array($node['folders'] ?? null) ? $node['folders'] : []),
            'files' => array_map(static function (array $file): array {
                return [
                    'id' => (int) ($file['id'] ?? 0),
                    'name' => (string) ($file['nom'] ?? ''),
                    'size' => (string) ($file['size_label'] ?? ''),
                ];
            }, is_array($node['files'] ?? null) ? $node['files'] : []),
        ];
    };
    $courses = array_map($slimFolder, course_tree_for_teacher($teacherId));
    $subjects = array_map(static function (array $subject): array {
        return [
            'id' => (int) $subject['id_mat'],
            'label' => trim((string) $subject['semestre'] . ' · ' . (string) $subject['code_ecue'] . ' — ' . (string) $subject['description_ecue']),
        ];
    }, course_teacher_subjects($teacherId));
    echo json_encode(['courses' => $courses, 'subjects' => $subjects], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['finder'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $reply = static function (bool $ok, string $message) use ($teacherId): void {
        $slimFolder = static function (array $node) use (&$slimFolder): array {
            return [
                'id' => (int) ($node['id'] ?? 0),
                'name' => (string) ($node['nom'] ?? ''),
                'folders' => array_map($slimFolder, is_array($node['folders'] ?? null) ? $node['folders'] : []),
                'files' => array_map(static function (array $file): array {
                    return [
                        'id' => (int) ($file['id'] ?? 0),
                        'name' => (string) ($file['nom'] ?? ''),
                        'size' => (string) ($file['size_label'] ?? ''),
                    ];
                }, is_array($node['files'] ?? null) ? $node['files'] : []),
            ];
        };
        $subjects = array_map(static function (array $subject): array {
            return [
                'id' => (int) $subject['id_mat'],
                'label' => trim((string) $subject['semestre'] . ' · ' . (string) $subject['code_ecue'] . ' — ' . (string) $subject['description_ecue']),
            ];
        }, course_teacher_subjects($teacherId));
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
            'courses' => array_map($slimFolder, course_tree_for_teacher($teacherId)),
            'subjects' => $subjects,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    };
    try {
        verify_csrf();
        $action = mes_cours_post_text('action');
        switch ($action) {
            case 'create_course':
                course_create_root($teacherId, mes_cours_post_int('id_mat'), mes_cours_post_text('nom'));
                $reply(true, 'Cours créé.');
            case 'create_folder':
                course_create_folder($teacherId, mes_cours_post_int('id_parent'), mes_cours_post_text('nom'));
                $reply(true, 'Dossier créé.');
            case 'upload':
                $count = course_upload_files($teacherId, mes_cours_post_int('id_dossier'), (array) ($_FILES['pdfs'] ?? []));
                $reply(true, $count === 1 ? '1 PDF ajouté.' : $count . ' PDF ajoutés.');
            case 'rename_folder':
                course_rename_folder($teacherId, mes_cours_post_int('id_dossier'), mes_cours_post_text('nom'));
                $reply(true, 'Nom modifié.');
            case 'rename_file':
                course_rename_file($teacherId, mes_cours_post_int('id_fichier'), mes_cours_post_text('nom'));
                $reply(true, 'Nom modifié.');
            case 'delete_folder':
                course_delete_folder($teacherId, mes_cours_post_int('id_dossier'));
                $reply(true, 'Dossier supprimé.');
            case 'delete_file':
                course_delete_file($teacherId, mes_cours_post_int('id_fichier'));
                $reply(true, 'Fichier supprimé.');
            case 'move_file':
                course_move_file($teacherId, mes_cours_post_int('id_fichier'), mes_cours_post_int('id_dossier'));
                $reply(true, 'Fichier déplacé.');
            case 'move_folder':
                course_move_folder($teacherId, mes_cours_post_int('id_dossier'), mes_cours_post_int('id_parent'));
                $reply(true, 'Dossier déplacé.');
            default:
                $reply(false, 'Action inconnue.');
        }
    } catch (InvalidArgumentException $error) {
        $reply(false, $error->getMessage());
    } catch (Throwable $error) {
        error_log('mes_cours finder: ' . $error->getMessage());
        $reply(false, 'Action impossible. Réessayez.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postLimit = mes_cours_ini_bytes((string) ini_get('post_max_size'));
    if (!$_POST && !$_FILES && $postLimit > 0 && $contentLength > $postLimit) {
        flash('danger', 'Les fichiers dépassent la taille maximale autorisée par le serveur.');
        mes_cours_redirect(...mes_cours_location($teacherId, (int) filter_input(INPUT_GET, 'dossier', FILTER_VALIDATE_INT)));
    }

    verify_csrf();
    $action = mes_cours_post_text('action');
    $returnFolderId = mes_cours_post_int('return_dossier');
    [$redirectCourse, $redirectFolder] = mes_cours_location($teacherId, $returnFolderId);

    try {
        switch ($action) {
            case 'create_course':
                $rootId = course_create_root($teacherId, mes_cours_post_int('id_mat'), mes_cours_post_text('nom'));
                [$redirectCourse, $redirectFolder] = mes_cours_location($teacherId, $rootId);
                flash('success', 'Cours créé.');
                break;

            case 'create_folder':
                $newFolderId = course_create_folder($teacherId, mes_cours_post_int('id_parent'), mes_cours_post_text('nom'));
                [$redirectCourse, $redirectFolder] = mes_cours_location($teacherId, $newFolderId);
                flash('success', 'Dossier créé.');
                break;

            case 'upload':
                $folderId = mes_cours_post_int('id_dossier');
                $imported = course_upload_files($teacherId, $folderId, (array) ($_FILES['pdfs'] ?? []));
                [$redirectCourse, $redirectFolder] = mes_cours_location($teacherId, $folderId);
                if ($imported > 0) {
                    flash('success', $imported === 1 ? '1 PDF importé.' : $imported . ' PDF importés.');
                } else {
                    flash('danger', 'Aucun PDF n’a été importé.');
                }
                break;

            case 'rename_folder':
                course_rename_folder($teacherId, mes_cours_post_int('id_dossier'), mes_cours_post_text('nom'));
                flash('success', 'Nom modifié.');
                break;

            case 'rename_file':
                course_rename_file($teacherId, mes_cours_post_int('id_fichier'), mes_cours_post_text('nom'));
                flash('success', 'Nom modifié.');
                break;

            case 'delete_folder':
                $folderId = mes_cours_post_int('id_dossier');
                $folder = $folderId > 0 ? course_folder_for_teacher($teacherId, $folderId) : null;
                course_delete_folder($teacherId, $folderId);
                $parentId = (int) ($folder['id_parent'] ?? 0);
                [$redirectCourse, $redirectFolder] = $parentId > 0
                    ? mes_cours_location($teacherId, $parentId)
                    : [null, null];
                flash('success', 'Dossier supprimé.');
                break;

            case 'delete_file':
                $fileId = mes_cours_post_int('id_fichier');
                $file = course_file_meta($fileId);
                course_delete_file($teacherId, $fileId);
                if ($file !== null) {
                    [$redirectCourse, $redirectFolder] = mes_cours_location($teacherId, (int) $file['id_dossier']);
                }
                flash('success', 'Fichier supprimé.');
                break;

            default:
                flash('danger', 'Action inconnue.');
        }
    } catch (InvalidArgumentException $error) {
        flash('danger', $error->getMessage());
    } catch (Throwable $error) {
        error_log('mes_cours.php [' . $action . '] teacher ' . $teacherId . ': ' . $error);
        flash('danger', 'Action impossible. Réessayez.');
    }

    mes_cours_redirect($redirectCourse, $redirectFolder);
}

[$currentCourse, $currentFolder] = mes_cours_location($teacherId, (int) filter_input(INPUT_GET, 'dossier', FILTER_VALIDATE_INT));
if ($currentCourse === null) {
    $requestedCourse = (int) filter_input(INPUT_GET, 'cours', FILTER_VALIDATE_INT);
    [$rootCourse] = mes_cours_location($teacherId, $requestedCourse);
    if ($rootCourse !== null && $rootCourse === $requestedCourse) {
        [$currentCourse, $currentFolder] = [$rootCourse, $rootCourse];
    }
}
$courses = course_tree_for_teacher($teacherId);

$embedSuffix = app_is_embedded() ? '?embed=1' : '';
$pendingFlash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

page_head('Mes cours', 'courses', 'assets/course-explorer.css?v=2');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="eyebrow">Espace enseignant</p>
        <h1 class="display-6 fw-bold mb-1">Mes cours</h1>
        <p class="text-secondary mb-0">Créez un dossier pour chaque cours, puis importez vos PDF. Vos étudiants affectés les voient immédiatement.</p>
    </div>
</div>

<?php
if ($pendingFlash !== null) {
    $_SESSION['flash'] = $pendingFlash;
}
flash_show();

course_explorer_render($courses, 'teacher', [
    'course' => $currentCourse,
    'folder' => $currentFolder,
    'base_url' => 'mes_cours.php' . $embedSuffix,
    'download_suffix' => '',
    'csrf' => csrf_token(),
    'form_action' => 'mes_cours.php' . $embedSuffix,
    'subjects' => course_teacher_subjects($teacherId),
]);
?>

<script src="assets/course-explorer.js?v=2" defer></script>

<?php page_foot(); ?>

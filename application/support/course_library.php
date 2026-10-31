<?php
declare(strict_types=1);

const COURSE_MAX_DEPTH = 6;
const COURSE_MAX_FILE_BYTES = 25 * 1048576;
const COURSE_MAX_FILES_PER_UPLOAD = 20;
const COURSE_MAX_TOTAL_BYTES = 120 * 1048576;
const COURSE_MAX_FOLDERS_PER_COURSE = 300;
const COURSE_MAX_COURSES_PER_TEACHER = 200;
const COURSE_NOTIFICATION_LINK = 'dashboard.php?panel=cours';
const COURSE_NAME_FORBIDDEN_CHARS = '/[\p{Cc}\x{061C}\x{200E}\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

function course_library_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if (!db_table_exists($pdo, 'cours_dossier')) {
        $pdo->exec(
            "CREATE TABLE cours_dossier (
              id_dossier INT UNSIGNED NOT NULL AUTO_INCREMENT,
              id_cours INT UNSIGNED NULL DEFAULT NULL,
              id_parent INT UNSIGNED NULL DEFAULT NULL,
              id_enseignant INT UNSIGNED NOT NULL,
              id_mat INT UNSIGNED NOT NULL,
              semestre VARCHAR(12) NOT NULL,
              nom VARCHAR(120) NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id_dossier),
              CONSTRAINT fk_cours_dossier_enseignant FOREIGN KEY (id_enseignant)
                REFERENCES enseignant (id_enseignant) ON DELETE CASCADE,
              CONSTRAINT fk_cours_dossier_matiere FOREIGN KEY (id_mat)
                REFERENCES matiere (id_mat) ON DELETE CASCADE,
              CONSTRAINT fk_cours_dossier_parent FOREIGN KEY (id_parent)
                REFERENCES cours_dossier (id_dossier) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('ALTER TABLE cours_dossier ADD KEY idx_cours_dossier_enseignant (id_enseignant)');
        $pdo->exec('ALTER TABLE cours_dossier ADD KEY idx_cours_dossier_parent (id_parent)');
        $pdo->exec('ALTER TABLE cours_dossier ADD KEY idx_cours_dossier_cours (id_cours)');
        $pdo->exec('ALTER TABLE cours_dossier ADD KEY idx_cours_dossier_matiere (id_mat)');
    }
    if (!db_table_exists($pdo, 'cours_fichier')) {
        $pdo->exec(
            "CREATE TABLE cours_fichier (
              id_fichier INT UNSIGNED NOT NULL AUTO_INCREMENT,
              id_dossier INT UNSIGNED NOT NULL,
              id_cours INT UNSIGNED NOT NULL,
              nom VARCHAR(180) NOT NULL,
              stored_name VARCHAR(64) NOT NULL,
              file_size INT NOT NULL,
              uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id_fichier),
              UNIQUE KEY uq_cours_fichier_stored (stored_name),
              CONSTRAINT fk_cours_fichier_dossier FOREIGN KEY (id_dossier)
                REFERENCES cours_dossier (id_dossier) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('ALTER TABLE cours_fichier ADD KEY idx_cours_fichier_dossier (id_dossier)');
        $pdo->exec('ALTER TABLE cours_fichier ADD KEY idx_cours_fichier_cours (id_cours)');
    }
    if (db_table_exists($pdo, 'audit_log')) {
        $column = $pdo->query("SHOW COLUMNS FROM audit_log LIKE 'category'")->fetch();
        $type = (string) ($column['Type'] ?? '');
        if ($type !== '' && str_contains(strtolower($type), 'enum') && !str_contains($type, 'cours')) {
            $pdo->exec("ALTER TABLE audit_log MODIFY category ENUM('note','paiement','presence','compte','reglage','stage','cours') NOT NULL");
        }
    }
    student_notifications_ensure_tables($pdo);
    ensure_etudiant_compte_table($pdo);
    $ready = true;
}

function course_db(): PDO
{
    $pdo = db();
    course_library_ensure($pdo);
    return $pdo;
}

/**
 * Runs $work inside a transaction unless the caller already opened one.
 */
function course_transaction(PDO $pdo, callable $work): mixed
{
    if ($pdo->inTransaction()) {
        return $work();
    }
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function course_validate_name(string $name, int $max = 120): string
{
    if (!mb_check_encoding($name, 'UTF-8')) {
        throw new InvalidArgumentException('Le nom contient des caractères invalides.');
    }
    $name = trim((string) preg_replace('/[\p{Zs}\t]+/u', ' ', $name));
    if ($name === '') {
        throw new InvalidArgumentException('Le nom ne peut pas être vide.');
    }
    if (preg_match(COURSE_NAME_FORBIDDEN_CHARS, $name)) {
        throw new InvalidArgumentException('Le nom contient des caractères de contrôle non autorisés.');
    }
    if (str_contains($name, '/') || str_contains($name, '\\')) {
        throw new InvalidArgumentException('Le nom ne peut pas contenir « / » ni « \\ ».');
    }
    if ($name === '.' || $name === '..') {
        throw new InvalidArgumentException('Ce nom n’est pas autorisé.');
    }
    if (mb_strlen($name) > $max) {
        throw new InvalidArgumentException('Le nom ne peut pas dépasser ' . $max . ' caractères.');
    }
    return $name;
}

/**
 * Display name of a PDF: validated base name with a forced lowercase .pdf suffix.
 */
function course_pdf_display_name(string $name): string
{
    $base = preg_replace('/(?:\s*\.pdf)+\s*$/iu', '', trim($name)) ?? '';
    return course_validate_name($base, 176) . '.pdf';
}

/**
 * Removes control and bidirectional-override characters from a client-supplied file name.
 */
function course_strip_unsafe_chars(string $name): string
{
    if (!mb_check_encoding($name, 'UTF-8')) {
        return $name;
    }
    $name = (string) preg_replace('/[\t\r\n]+/', ' ', $name);
    return (string) preg_replace(COURSE_NAME_FORBIDDEN_CHARS, '', $name);
}

function course_upload_display_name(string $clientName): string
{
    $clientName = str_replace('\\', '/', course_strip_unsafe_chars($clientName));
    $slash = strrpos($clientName, '/');
    if ($slash !== false) {
        $clientName = substr($clientName, $slash + 1);
    }
    $base = preg_replace('/(?:\s*\.pdf)+\s*$/iu', '', trim($clientName)) ?? '';
    if (trim($base) === '') {
        $base = 'document';
    }
    return course_pdf_display_name($base);
}

function course_storage_dir(): string
{
    $configured = getenv('COURSE_LIBRARY_DIR');
    $dir = is_string($configured) && $configured !== ''
        ? rtrim($configured, '/\\')
        : storage_path('uploads/cours');
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Le dossier de stockage des cours est inaccessible.');
    }
    return $dir;
}

function course_stored_path(string $storedName): ?string
{
    if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $storedName)) {
        return null;
    }
    return course_storage_dir() . DIRECTORY_SEPARATOR . $storedName;
}

/**
 * @param list<string> $storedNames
 */
function course_unlink_stored(array $storedNames): void
{
    foreach ($storedNames as $storedName) {
        $path = course_stored_path((string) $storedName);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}

function course_size_label(int $bytes): string
{
    if ($bytes < 1024) {
        return max(0, $bytes) . ' o';
    }
    if ($bytes < 1048576) {
        return number_format(max(1, round($bytes / 1024)), 0, ',', ' ') . ' Ko';
    }
    return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
}

function course_date_label(mixed $value): string
{
    $stamp = strtotime((string) $value);
    return $stamp ? date('d/m/Y H:i', $stamp) : '';
}

function course_subject_label(array $row): string
{
    $code = trim((string) ($row['code_ecue'] ?? ''));
    $description = trim((string) ($row['description_ecue'] ?? ''));
    if ($code === '' || $description === '') {
        return $code . $description;
    }
    return $code . ' — ' . $description;
}

function course_audit(string $action, string $summary): void
{
    if (function_exists('audit_record')) {
        audit_record('cours', $action, $summary);
    }
}

function course_require_teacher(int $teacherId): void
{
    if ($teacherId <= 0) {
        throw new InvalidArgumentException('Compte enseignant non reconnu.');
    }
}

function course_teacher_subjects(int $teacherId): array
{
    if ($teacherId <= 0) {
        return [];
    }
    $query = course_db()->prepare(
        'SELECT DISTINCT m.id_mat, m.code_ecue, m.description_ecue, m.semestre
         FROM enseignant_affectation a
         JOIN matiere m ON m.id_mat = a.id_mat
         WHERE a.id_enseignant = ?
         ORDER BY m.semestre, m.code_ecue, m.id_mat'
    );
    $query->execute([$teacherId]);
    $subjects = [];
    foreach ($query->fetchAll() as $row) {
        $subjects[] = [
            'id_mat' => (int) $row['id_mat'],
            'code_ecue' => (string) $row['code_ecue'],
            'description_ecue' => (string) $row['description_ecue'],
            'semestre' => (string) $row['semestre'],
        ];
    }
    return $subjects;
}

function course_teacher_owns_subject(int $teacherId, int $subjectId): bool
{
    if ($teacherId <= 0 || $subjectId <= 0) {
        return false;
    }
    $query = course_db()->prepare(
        'SELECT 1 FROM enseignant_affectation WHERE id_enseignant = ? AND id_mat = ? LIMIT 1'
    );
    $query->execute([$teacherId, $subjectId]);
    return (bool) $query->fetchColumn();
}

function course_folder_row(array $row): array
{
    $row['id_dossier'] = (int) $row['id_dossier'];
    $row['id_cours'] = (int) $row['id_cours'];
    $row['id_parent'] = $row['id_parent'] === null ? null : (int) $row['id_parent'];
    $row['id_enseignant'] = (int) $row['id_enseignant'];
    $row['id_mat'] = (int) $row['id_mat'];
    $row['nom'] = (string) $row['nom'];
    $row['semestre'] = (string) $row['semestre'];
    return $row;
}

function course_folder_for_teacher(int $teacherId, int $folderId): ?array
{
    if ($teacherId <= 0 || $folderId <= 0) {
        return null;
    }
    $query = course_db()->prepare(
        'SELECT id_dossier, id_cours, id_parent, id_enseignant, id_mat, semestre, nom, created_at, updated_at
         FROM cours_dossier
         WHERE id_dossier = ? AND id_enseignant = ?
         LIMIT 1'
    );
    $query->execute([$folderId, $teacherId]);
    $row = $query->fetch();
    return $row ? course_folder_row($row) : null;
}

function course_owned_folder(int $teacherId, int $folderId): array
{
    course_require_teacher($teacherId);
    $folder = course_folder_for_teacher($teacherId, $folderId);
    if ($folder === null) {
        throw new InvalidArgumentException('Dossier introuvable ou non autorisé.');
    }
    return $folder;
}

function course_owned_file(int $teacherId, int $fileId): array
{
    course_require_teacher($teacherId);
    $meta = $fileId > 0 ? course_file_meta($fileId) : null;
    if ($meta === null || $meta['id_enseignant'] !== $teacherId) {
        throw new InvalidArgumentException('Fichier introuvable ou non autorisé.');
    }
    return $meta;
}

/**
 * @return array<int, int|null> id_dossier => id_parent for every folder of the course.
 */
function course_folder_parents(PDO $pdo, int $courseId): array
{
    $query = $pdo->prepare('SELECT id_dossier, id_parent FROM cours_dossier WHERE id_cours = ?');
    $query->execute([$courseId]);
    $parents = [];
    foreach ($query->fetchAll() as $row) {
        $parents[(int) $row['id_dossier']] = $row['id_parent'] === null ? null : (int) $row['id_parent'];
    }
    return $parents;
}

function course_folder_depth(array $parents, int $folderId): int
{
    $depth = 0;
    $current = $folderId;
    while ($current !== null && array_key_exists($current, $parents)) {
        $depth++;
        if ($depth > COURSE_MAX_DEPTH + 1) {
            break;
        }
        $current = $parents[$current];
    }
    return $depth;
}

/**
 * @return list<int> the folder itself followed by all its descendants.
 */
function course_folder_subtree(array $parents, int $folderId): array
{
    $children = [];
    foreach ($parents as $id => $parent) {
        if ($parent !== null) {
            $children[$parent][] = $id;
        }
    }
    $ids = [];
    $stack = [$folderId];
    while ($stack) {
        $id = array_pop($stack);
        if (in_array($id, $ids, true)) {
            continue;
        }
        $ids[] = $id;
        foreach ($children[$id] ?? [] as $child) {
            $stack[] = $child;
        }
    }
    return $ids;
}

function course_assert_unique_sibling(PDO $pdo, array $scope, string $name, int $exceptId = 0): void
{
    if (($scope['id_parent'] ?? null) === null) {
        $query = $pdo->prepare(
            'SELECT 1 FROM cours_dossier
             WHERE id_parent IS NULL AND id_enseignant = ? AND id_mat = ?
               AND LOWER(nom) = LOWER(?) AND id_dossier <> ?
             LIMIT 1'
        );
        $query->execute([(int) $scope['id_enseignant'], (int) $scope['id_mat'], $name, $exceptId]);
        if ($query->fetchColumn()) {
            throw new InvalidArgumentException('Un cours portant ce nom existe déjà pour cette matière.');
        }
        return;
    }
    $query = $pdo->prepare(
        'SELECT 1 FROM cours_dossier
         WHERE id_parent = ? AND LOWER(nom) = LOWER(?) AND id_dossier <> ?
         LIMIT 1'
    );
    $query->execute([(int) $scope['id_parent'], $name, $exceptId]);
    if ($query->fetchColumn()) {
        throw new InvalidArgumentException('Un dossier portant ce nom existe déjà à cet emplacement.');
    }
}

/**
 * @param list<int> $folderIds
 */
function course_touch(PDO $pdo, array $folderIds): void
{
    $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), static fn (int $id): bool => $id > 0)));
    if (!$folderIds) {
        return;
    }
    $marks = implode(',', array_fill(0, count($folderIds), '?'));
    $pdo->prepare('UPDATE cours_dossier SET updated_at = NOW() WHERE id_dossier IN (' . $marks . ')')->execute($folderIds);
}

function course_create_root(int $teacherId, int $subjectId, string $name): int
{
    course_require_teacher($teacherId);
    $pdo = course_db();
    if (!course_teacher_owns_subject($teacherId, $subjectId)) {
        throw new InvalidArgumentException('Cette matière ne vous est pas affectée.');
    }
    $name = course_validate_name($name);
    $subject = $pdo->prepare('SELECT semestre, code_ecue FROM matiere WHERE id_mat = ? LIMIT 1');
    $subject->execute([$subjectId]);
    $subjectRow = $subject->fetch();
    if (!$subjectRow) {
        throw new InvalidArgumentException('Matière introuvable.');
    }
    $semester = mb_substr(trim((string) $subjectRow['semestre']), 0, 12);
    $courseId = (int) course_transaction($pdo, static function () use ($pdo, $teacherId, $subjectId, $semester, $name): int {
        $owned = $pdo->prepare('SELECT COUNT(*) FROM cours_dossier WHERE id_enseignant = ? AND id_parent IS NULL');
        $owned->execute([$teacherId]);
        if ((int) $owned->fetchColumn() >= COURSE_MAX_COURSES_PER_TEACHER) {
            throw new InvalidArgumentException('Nombre maximal de cours atteint : ' . COURSE_MAX_COURSES_PER_TEACHER . ' cours par enseignant.');
        }
        course_assert_unique_sibling($pdo, ['id_parent' => null, 'id_enseignant' => $teacherId, 'id_mat' => $subjectId], $name);
        $pdo->prepare(
            'INSERT INTO cours_dossier (id_cours, id_parent, id_enseignant, id_mat, semestre, nom)
             VALUES (NULL, NULL, ?, ?, ?, ?)'
        )->execute([$teacherId, $subjectId, $semester, $name]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE cours_dossier SET id_cours = id_dossier WHERE id_dossier = ?')->execute([$id]);
        return $id;
    });
    course_audit('creation', 'Cours « ' . $name . ' » créé (' . (string) $subjectRow['code_ecue'] . ').');
    return $courseId;
}

function course_create_folder(int $teacherId, int $parentId, string $name): int
{
    $parent = course_owned_folder($teacherId, $parentId);
    $name = course_validate_name($name);
    $pdo = course_db();
    $folderId = (int) course_transaction($pdo, static function () use ($pdo, $parent, $name): int {
        $parents = course_folder_parents($pdo, $parent['id_cours']);
        $depth = course_folder_depth($parents, $parent['id_dossier']);
        if ($depth >= COURSE_MAX_DEPTH) {
            throw new InvalidArgumentException('Profondeur maximale atteinte : ' . COURSE_MAX_DEPTH . ' niveaux de dossiers.');
        }
        if (count($parents) >= COURSE_MAX_FOLDERS_PER_COURSE) {
            throw new InvalidArgumentException('Nombre maximal de dossiers atteint : ' . COURSE_MAX_FOLDERS_PER_COURSE . ' dossiers par cours.');
        }
        course_assert_unique_sibling($pdo, ['id_parent' => $parent['id_dossier']], $name);
        $pdo->prepare(
            'INSERT INTO cours_dossier (id_cours, id_parent, id_enseignant, id_mat, semestre, nom)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $parent['id_cours'],
            $parent['id_dossier'],
            $parent['id_enseignant'],
            $parent['id_mat'],
            $parent['semestre'],
            $name,
        ]);
        $id = (int) $pdo->lastInsertId();
        course_touch($pdo, [$parent['id_dossier'], $parent['id_cours']]);
        return $id;
    });
    course_audit('dossier', 'Dossier « ' . $name . ' » créé dans « ' . $parent['nom'] . ' ».');
    return $folderId;
}

function course_rename_folder(int $teacherId, int $folderId, string $name): void
{
    $folder = course_owned_folder($teacherId, $folderId);
    $name = course_validate_name($name);
    if ($name === $folder['nom']) {
        return;
    }
    $pdo = course_db();
    course_transaction($pdo, static function () use ($pdo, $folder, $name): void {
        course_assert_unique_sibling($pdo, $folder, $name, $folder['id_dossier']);
        $pdo->prepare('UPDATE cours_dossier SET nom = ?, updated_at = NOW() WHERE id_dossier = ? AND id_enseignant = ?')
            ->execute([$name, $folder['id_dossier'], $folder['id_enseignant']]);
        course_touch($pdo, [$folder['id_cours']]);
    });
    $kind = $folder['id_parent'] === null ? 'Cours' : 'Dossier';
    course_audit('renommage', $kind . ' « ' . $folder['nom'] . ' » renommé en « ' . $name . ' ».');
}

function course_delete_folder(int $teacherId, int $folderId): void
{
    $folder = course_owned_folder($teacherId, $folderId);
    $pdo = course_db();
    $storedNames = course_transaction($pdo, static function () use ($pdo, $folder): array {
        $ids = course_folder_subtree(course_folder_parents($pdo, $folder['id_cours']), $folder['id_dossier']);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $files = $pdo->prepare('SELECT stored_name FROM cours_fichier WHERE id_dossier IN (' . $marks . ')');
        $files->execute($ids);
        $stored = array_map('strval', $files->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare('DELETE FROM cours_fichier WHERE id_dossier IN (' . $marks . ')')->execute($ids);
        $pdo->prepare('DELETE FROM cours_dossier WHERE id_dossier = ? AND id_enseignant = ?')
            ->execute([$folder['id_dossier'], $folder['id_enseignant']]);
        if ($folder['id_parent'] !== null) {
            course_touch($pdo, [$folder['id_parent'], $folder['id_cours']]);
        }
        return $stored;
    });
    course_unlink_stored($storedNames);
    $kind = $folder['id_parent'] === null ? 'Cours' : 'Dossier';
    course_audit('suppression', $kind . ' « ' . $folder['nom'] . ' » supprimé (' . count($storedNames) . ' fichier(s)).');
}

function course_upload_error_message(int $error, string $label): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '« ' . $label . ' » dépasse la taille autorisée par le serveur.',
        UPLOAD_ERR_PARTIAL => '« ' . $label . ' » n’a été reçu que partiellement. Réessayez.',
        default => '« ' . $label . ' » n’a pas pu être reçu.',
    };
}

/**
 * Checks size, MIME type and PDF signature of a local file; returns its size.
 */
function course_check_pdf(string $path, string $label): int
{
    clearstatcache(true, $path);
    $size = is_file($path) ? (int) filesize($path) : 0;
    if ($size < 1) {
        throw new InvalidArgumentException('« ' . $label . ' » est vide.');
    }
    if ($size > COURSE_MAX_FILE_BYTES) {
        throw new InvalidArgumentException('« ' . $label . ' » dépasse la taille maximale de 25 Mo.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $head = (string) file_get_contents($path, false, null, 0, 5);
    if ($mime !== 'application/pdf' || $head !== '%PDF-') {
        throw new InvalidArgumentException('« ' . $label . ' » n’est pas un fichier PDF valide.');
    }
    return $size;
}

/**
 * @param list<array{source:string, nom:string, size:int}> $items
 * @param callable(string, string): bool $place copies or moves the source to the destination
 * @return list<int> new file ids
 */
function course_save_files(array $folder, array $items, callable $place): array
{
    if (!$items) {
        return [];
    }
    $pdo = course_db();
    $dir = course_storage_dir();
    $placed = [];
    $ids = [];
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO cours_fichier (id_dossier, id_cours, nom, stored_name, file_size)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $stored = bin2hex(random_bytes(16)) . '.pdf';
            $target = $dir . DIRECTORY_SEPARATOR . $stored;
            if (!$place((string) $item['source'], $target)) {
                throw new RuntimeException('Impossible d’enregistrer « ' . $item['nom'] . ' ».');
            }
            $placed[] = $target;
            @chmod($target, 0640);
            $insert->execute([$folder['id_dossier'], $folder['id_cours'], $item['nom'], $stored, (int) $item['size']]);
            $ids[] = (int) $pdo->lastInsertId();
        }
        course_touch($pdo, [$folder['id_dossier'], $folder['id_cours']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($placed as $target) {
            if (is_file($target)) {
                @unlink($target);
            }
        }
        throw $exception;
    }

    course_notify_upload($pdo, $folder, count($ids));
    course_audit('depot', count($ids) . ' fichier(s) PDF déposé(s) dans « ' . $folder['nom'] . ' ».');
    return $ids;
}

function course_notify_upload(PDO $pdo, array $folder, int $count): void
{
    if ($count < 1) {
        return;
    }
    try {
        course_notify_students($pdo, $folder, $count);
    } catch (Throwable $exception) {
        error_log('course_notify_upload: ' . $exception->getMessage());
    }
}

/**
 * Runs after the upload commit: a failure here must not surface as a failed upload.
 */
function course_notify_students(PDO $pdo, array $folder, int $count): void
{
    $courseId = (int) $folder['id_cours'];
    $studentIds = course_student_ids_for_course($courseId);
    if (!$studentIds) {
        return;
    }
    $info = $pdo->prepare(
        'SELECT c.nom, m.code_ecue, m.description_ecue, e.nom AS ens_nom, e.prenom AS ens_prenom
         FROM cours_dossier c
         JOIN matiere m ON m.id_mat = c.id_mat
         JOIN enseignant e ON e.id_enseignant = c.id_enseignant
         WHERE c.id_dossier = ?
         LIMIT 1'
    );
    $info->execute([$courseId]);
    $course = $info->fetch();
    if (!$course) {
        return;
    }
    $courseName = (string) $course['nom'];
    $teacher = trim((string) $course['ens_prenom'] . ' ' . (string) $course['ens_nom']) ?: 'Votre enseignant';
    $subject = mb_substr('Nouveau cours : ' . $courseName, 0, 180);
    $body = $teacher . ' a publié le cours « ' . $courseName . ' » (' . course_subject_label($course) . '). '
        . ($count === 1 ? '1 nouveau fichier PDF' : $count . ' nouveaux fichiers PDF')
        . ((int) $folder['id_dossier'] !== $courseId ? ' dans le dossier « ' . $folder['nom'] . ' »' : '')
        . '.';
    $link = COURSE_NOTIFICATION_LINK . '&cours=' . $courseId . '&dossier=' . (int) $folder['id_dossier'];
    $pending = $pdo->prepare('SELECT DISTINCT id_etudiant FROM etudiant_notifications WHERE lien = ? AND is_read = 0');
    $pending->execute([$link]);
    $alreadyNotified = array_flip(array_map('intval', $pending->fetchAll(PDO::FETCH_COLUMN)));
    course_transaction($pdo, static function () use ($pdo, $studentIds, $alreadyNotified, $subject, $body, $link): void {
        foreach ($studentIds as $studentId) {
            if (!isset($alreadyNotified[$studentId])) {
                student_notify($pdo, $studentId, $subject, $body, $link);
            }
        }
    });
}

/**
 * @param array<string, mixed> $files raw $_FILES entry (single or multi-file shape)
 * @return list<array{name:string, tmp_name:string, error:int, size:int}>
 */
function course_normalize_uploads(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }
    $fields = ['name', 'tmp_name', 'error', 'size'];
    if (!is_array($files['name'])) {
        $files = array_map(static fn (mixed $value): array => [$value], array_intersect_key($files, array_flip($fields)));
    }
    $list = [];
    foreach (array_keys((array) $files['name']) as $key) {
        $name = $files['name'][$key] ?? '';
        $tmp = $files['tmp_name'][$key] ?? '';
        if (!is_string($name) || !is_string($tmp)) {
            throw new InvalidArgumentException('Format d’envoi de fichiers invalide.');
        }
        $error = (int) ($files['error'][$key] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[] = ['name' => $name, 'tmp_name' => $tmp, 'error' => $error, 'size' => (int) ($files['size'][$key] ?? 0)];
    }
    return $list;
}

function course_upload_files(int $teacherId, int $folderId, array $files): int
{
    $folder = course_owned_folder($teacherId, $folderId);
    $uploads = course_normalize_uploads($files);
    if (!$uploads) {
        throw new InvalidArgumentException('Sélectionnez au moins un fichier PDF.');
    }
    if (count($uploads) > COURSE_MAX_FILES_PER_UPLOAD) {
        throw new InvalidArgumentException('Vous pouvez déposer au maximum ' . COURSE_MAX_FILES_PER_UPLOAD . ' fichiers à la fois.');
    }
    if (array_sum(array_map(static fn (array $upload): int => max(0, $upload['size']), $uploads)) > COURSE_MAX_TOTAL_BYTES) {
        throw new InvalidArgumentException('L’import dépasse 120 Mo au total. Importez vos fichiers en plusieurs fois.');
    }
    $items = [];
    $total = 0;
    foreach ($uploads as $upload) {
        $label = mb_check_encoding($upload['name'], 'UTF-8') ? mb_substr(basename(str_replace('\\', '/', course_strip_unsafe_chars($upload['name']))), 0, 80) : 'fichier';
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(course_upload_error_message($upload['error'], $label));
        }
        if ($upload['tmp_name'] === '' || !is_uploaded_file($upload['tmp_name'])) {
            throw new InvalidArgumentException(course_upload_error_message(UPLOAD_ERR_NO_FILE, $label));
        }
        $size = course_check_pdf($upload['tmp_name'], $label);
        $total += $size;
        if ($total > COURSE_MAX_TOTAL_BYTES) {
            throw new InvalidArgumentException('L’import dépasse 120 Mo au total. Importez vos fichiers en plusieurs fois.');
        }
        try {
            $displayName = course_upload_display_name($upload['name']);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('« ' . $label . ' » : ' . $exception->getMessage());
        }
        $items[] = ['source' => $upload['tmp_name'], 'nom' => $displayName, 'size' => $size];
    }
    $ids = course_save_files($folder, $items, static fn (string $from, string $to): bool => move_uploaded_file($from, $to));
    return count($ids);
}

/**
 * Stores a local PDF (copied, not moved) as if it had been uploaded. Used by CLI tests and imports;
 * applies the same ownership, size, MIME and signature checks as course_upload_files().
 */
function course_store_pdf_file(int $teacherId, int $folderId, string $path, string $name): int
{
    $folder = course_owned_folder($teacherId, $folderId);
    $displayName = course_upload_display_name($name);
    $size = course_check_pdf($path, $displayName);
    $ids = course_save_files($folder, [['source' => $path, 'nom' => $displayName, 'size' => $size]], static fn (string $from, string $to): bool => copy($from, $to));
    return $ids[0] ?? 0;
}

function course_rename_file(int $teacherId, int $fileId, string $name): void
{
    $file = course_owned_file($teacherId, $fileId);
    $name = course_pdf_display_name($name);
    if ($name === $file['nom']) {
        return;
    }
    $pdo = course_db();
    course_transaction($pdo, static function () use ($pdo, $file, $name): void {
        $pdo->prepare('UPDATE cours_fichier SET nom = ? WHERE id_fichier = ?')->execute([$name, $file['id']]);
        course_touch($pdo, [$file['id_dossier'], $file['id_cours']]);
    });
    course_audit('renommage', 'Fichier « ' . $file['nom'] . ' » renommé en « ' . $name . ' ».');
}

function course_delete_file(int $teacherId, int $fileId): void
{
    $file = course_owned_file($teacherId, $fileId);
    $pdo = course_db();
    course_transaction($pdo, static function () use ($pdo, $file): void {
        $pdo->prepare('DELETE FROM cours_fichier WHERE id_fichier = ?')->execute([$file['id']]);
        course_touch($pdo, [$file['id_dossier'], $file['id_cours']]);
    });
    course_unlink_stored([basename($file['path'])]);
    course_audit('suppression', 'Fichier « ' . $file['nom'] . ' » supprimé.');
}

function course_move_file(int $teacherId, int $fileId, int $destinationId): void
{
    $file = course_owned_file($teacherId, $fileId);
    $destination = course_owned_folder($teacherId, $destinationId);
    if ((int) $file['id_cours'] !== (int) $destination['id_cours']) {
        throw new InvalidArgumentException('Le fichier doit rester dans le même cours.');
    }
    if ((int) $file['id_dossier'] === (int) $destination['id_dossier']) {
        return;
    }
    $pdo = course_db();
    $duplicate = $pdo->prepare('SELECT 1 FROM cours_fichier WHERE id_dossier = ? AND nom = ? AND id_fichier <> ? LIMIT 1');
    $duplicate->execute([(int) $destination['id_dossier'], $file['nom'], (int) $file['id']]);
    if ($duplicate->fetchColumn()) {
        throw new InvalidArgumentException('Ce dossier contient déjà un fichier du même nom.');
    }
    course_transaction($pdo, static function () use ($pdo, $file, $destination): void {
        $pdo->prepare('UPDATE cours_fichier SET id_dossier = ? WHERE id_fichier = ?')->execute([(int) $destination['id_dossier'], (int) $file['id']]);
        course_touch($pdo, [(int) $file['id_dossier'], (int) $destination['id_dossier'], (int) $destination['id_cours']]);
    });
    course_audit('deplacement', 'Fichier « ' . $file['nom'] . ' » déplacé dans « ' . $destination['nom'] . ' ».');
}

function course_move_folder(int $teacherId, int $folderId, int $destinationId): void
{
    if ($folderId === $destinationId) {
        throw new InvalidArgumentException('Un dossier ne peut pas être déplacé dans lui-même.');
    }
    $folder = course_owned_folder($teacherId, $folderId);
    $destination = course_owned_folder($teacherId, $destinationId);
    if ($folder['id_parent'] === null) {
        throw new InvalidArgumentException('Glissez un dossier à l’intérieur d’un cours, pas le cours lui-même.');
    }
    if ((int) $folder['id_cours'] !== (int) $destination['id_cours']) {
        throw new InvalidArgumentException('Le dossier doit rester dans le même cours.');
    }
    $pdo = course_db();
    $parents = course_folder_parents($pdo, (int) $folder['id_cours']);
    $subtree = course_folder_subtree($parents, $folderId);
    if (in_array($destinationId, $subtree, true)) {
        throw new InvalidArgumentException('Un dossier ne peut pas être déplacé dans l’un de ses sous-dossiers.');
    }
    if (course_folder_depth($parents, $destinationId) >= COURSE_MAX_DEPTH) {
        throw new InvalidArgumentException('Profondeur maximale atteinte : ' . COURSE_MAX_DEPTH . ' niveaux de dossiers.');
    }
    course_transaction($pdo, static function () use ($pdo, $folder, $destination): void {
        course_assert_unique_sibling($pdo, ['id_parent' => $destination['id_dossier']], $folder['nom'], $folder['id_dossier']);
        $pdo->prepare('UPDATE cours_dossier SET id_parent = ?, updated_at = NOW() WHERE id_dossier = ? AND id_enseignant = ?')
            ->execute([(int) $destination['id_dossier'], $folder['id_dossier'], $folder['id_enseignant']]);
        course_touch($pdo, [$folder['id_cours']]);
    });
    course_audit('deplacement', 'Dossier « ' . $folder['nom'] . ' » déplacé dans « ' . $destination['nom'] . ' ».');
}

function course_file_meta(int $fileId): ?array
{
    if ($fileId <= 0) {
        return null;
    }
    $query = course_db()->prepare(
        'SELECT f.id_fichier, f.nom, f.stored_name, f.file_size, f.id_dossier, f.id_cours, d.id_enseignant
         FROM cours_fichier f
         JOIN cours_dossier d ON d.id_dossier = f.id_dossier
         WHERE f.id_fichier = ?
         LIMIT 1'
    );
    $query->execute([$fileId]);
    $row = $query->fetch();
    if (!$row) {
        return null;
    }
    $path = course_stored_path((string) $row['stored_name']);
    if ($path === null) {
        return null;
    }
    return [
        'id' => (int) $row['id_fichier'],
        'nom' => (string) $row['nom'],
        'path' => $path,
        'size' => (int) $row['file_size'],
        'id_dossier' => (int) $row['id_dossier'],
        'id_cours' => (int) $row['id_cours'],
        'id_enseignant' => (int) $row['id_enseignant'],
    ];
}

/**
 * Visible course roots for a student; $courseId > 0 restricts the check to one course.
 *
 * @return list<int>
 */
function course_student_course_ids(int $studentId, int $courseId = 0): array
{
    if ($studentId <= 0) {
        return [];
    }
    $sql = 'SELECT DISTINCT c.id_dossier
            FROM etudiant_compte ec
            JOIN groupe_etudiant ge ON ge.id_etudiant = ec.id_etudiant
            JOIN groupe g ON g.id_groupe = ge.id_groupe
            JOIN enseignant_affectation a ON a.id_groupe = g.id_groupe OR a.id_groupe = g.id_groupe_parent
            JOIN cours_dossier c ON c.id_enseignant = a.id_enseignant AND c.id_mat = a.id_mat AND c.id_parent IS NULL
            WHERE ec.id_etudiant = ? AND ec.active = 1';
    $params = [$studentId];
    if ($courseId > 0) {
        $sql .= ' AND c.id_dossier = ?';
        $params[] = $courseId;
    }
    $query = course_db()->prepare($sql);
    $query->execute($params);
    return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
}

function course_student_may_access_course(int $studentId, int $courseId): bool
{
    return $courseId > 0 && course_student_course_ids($studentId, $courseId) !== [];
}

function course_student_may_access_folder(int $studentId, int $folderId): bool
{
    if ($studentId <= 0 || $folderId <= 0) {
        return false;
    }
    $query = course_db()->prepare('SELECT id_cours FROM cours_dossier WHERE id_dossier = ? LIMIT 1');
    $query->execute([$folderId]);
    return course_student_may_access_course($studentId, (int) $query->fetchColumn());
}

function course_student_may_access_file(int $studentId, int $fileId): bool
{
    if ($studentId <= 0 || $fileId <= 0) {
        return false;
    }
    $query = course_db()->prepare(
        'SELECT d.id_cours
         FROM cours_fichier f
         JOIN cours_dossier d ON d.id_dossier = f.id_dossier
         WHERE f.id_fichier = ?
         LIMIT 1'
    );
    $query->execute([$fileId]);
    return course_student_may_access_course($studentId, (int) $query->fetchColumn());
}

function course_student_ids_for_course(int $courseId): array
{
    if ($courseId <= 0) {
        return [];
    }
    $query = course_db()->prepare(
        'SELECT DISTINCT ge.id_etudiant
         FROM cours_dossier c
         JOIN enseignant_affectation a ON a.id_enseignant = c.id_enseignant AND a.id_mat = c.id_mat
         JOIN groupe g ON g.id_groupe = a.id_groupe OR g.id_groupe_parent = a.id_groupe
         JOIN groupe_etudiant ge ON ge.id_groupe = g.id_groupe
         JOIN etudiant_compte ec ON ec.id_etudiant = ge.id_etudiant AND ec.active = 1
         WHERE c.id_dossier = ? AND c.id_parent IS NULL
         ORDER BY ge.id_etudiant'
    );
    $query->execute([$courseId]);
    return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
}

function course_tree_for_teacher(int $teacherId): array
{
    if ($teacherId <= 0) {
        return [];
    }
    $query = course_db()->prepare('SELECT id_dossier FROM cours_dossier WHERE id_enseignant = ? AND id_parent IS NULL');
    $query->execute([$teacherId]);
    return course_build_tree(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)));
}

function course_tree_for_student(int $studentId): array
{
    return course_build_tree(course_student_course_ids($studentId));
}

/**
 * Builds the explorer tree for the given course roots with three queries.
 *
 * @param list<int> $courseIds
 */
function course_build_tree(array $courseIds): array
{
    $courseIds = array_values(array_unique(array_filter(array_map('intval', $courseIds), static fn (int $id): bool => $id > 0)));
    if (!$courseIds) {
        return [];
    }
    $pdo = course_db();
    $marks = implode(',', array_fill(0, count($courseIds), '?'));

    $roots = $pdo->prepare(
        'SELECT c.id_dossier, c.nom, c.id_mat, c.semestre, c.updated_at,
                m.code_ecue, m.description_ecue, e.nom AS ens_nom, e.prenom AS ens_prenom
         FROM cours_dossier c
         JOIN matiere m ON m.id_mat = c.id_mat
         JOIN enseignant e ON e.id_enseignant = c.id_enseignant
         WHERE c.id_parent IS NULL AND c.id_dossier IN (' . $marks . ')
         ORDER BY c.semestre, m.code_ecue, LOWER(c.nom), c.id_dossier'
    );
    $roots->execute($courseIds);
    $rootRows = $roots->fetchAll();

    $folders = $pdo->prepare(
        'SELECT id_dossier, id_parent, nom, updated_at
         FROM cours_dossier
         WHERE id_parent IS NOT NULL AND id_cours IN (' . $marks . ')
         ORDER BY LOWER(nom), id_dossier'
    );
    $folders->execute($courseIds);
    $children = [];
    $folderRows = [];
    foreach ($folders->fetchAll() as $row) {
        $id = (int) $row['id_dossier'];
        $folderRows[$id] = $row;
        $children[(int) $row['id_parent']][] = $id;
    }

    $files = $pdo->prepare(
        'SELECT id_fichier, id_dossier, nom, file_size, uploaded_at
         FROM cours_fichier
         WHERE id_cours IN (' . $marks . ')
         ORDER BY LOWER(nom), id_fichier'
    );
    $files->execute($courseIds);
    $filesByFolder = [];
    foreach ($files->fetchAll() as $row) {
        $size = (int) $row['file_size'];
        $filesByFolder[(int) $row['id_dossier']][] = [
            'id' => (int) $row['id_fichier'],
            'nom' => (string) $row['nom'],
            'size' => $size,
            'size_label' => course_size_label($size),
            'uploaded_at' => course_date_label($row['uploaded_at']),
        ];
    }

    $build = static function (int $folderId, int $depth) use (&$build, $children, $folderRows, $filesByFolder): array {
        $nodes = [];
        if ($depth > COURSE_MAX_DEPTH) {
            return $nodes;
        }
        foreach ($children[$folderId] ?? [] as $childId) {
            $row = $folderRows[$childId];
            $nodes[] = [
                'id' => $childId,
                'nom' => (string) $row['nom'],
                'updated_at' => course_date_label($row['updated_at']),
                'folders' => $build($childId, $depth + 1),
                'files' => $filesByFolder[$childId] ?? [],
            ];
        }
        return $nodes;
    };

    $tree = [];
    foreach ($rootRows as $row) {
        $id = (int) $row['id_dossier'];
        $tree[] = [
            'id' => $id,
            'nom' => (string) $row['nom'],
            'id_mat' => (int) $row['id_mat'],
            'matiere' => course_subject_label($row),
            'semestre' => (string) $row['semestre'],
            'enseignant' => trim((string) $row['ens_prenom'] . ' ' . (string) $row['ens_nom']),
            'updated_at' => course_date_label($row['updated_at']),
            'folders' => $build($id, 2),
            'files' => $filesByFolder[$id] ?? [],
        ];
    }
    return $tree;
}

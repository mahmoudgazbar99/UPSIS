<?php
declare(strict_types=1);

function emploi_pdf_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS emploi_groupe_pdf (
              id_groupe INTEGER NOT NULL,
              original_name VARCHAR(255) NOT NULL DEFAULT '',
              stored_name VARCHAR(255) NOT NULL DEFAULT '',
              file_size INTEGER NOT NULL DEFAULT 0,
              uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id_groupe)
            )"
        );
    } catch (Throwable $exception) {
        /* Table creation is best-effort during page load. */
    }
}

function emploi_pdf_dir(): string
{
    $dir = storage_path('uploads/emploi');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function emploi_pdf_path(int $groupId): string
{
    return emploi_pdf_dir() . DIRECTORY_SEPARATOR . $groupId . '.pdf';
}

function emploi_pdf_signature_ok(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $head = (string) file_get_contents($path, false, null, 0, 5);
    return $mime === 'application/pdf' && $head === '%PDF-';
}

function emploi_pdf_meta(int $groupId): ?array
{
    if ($groupId <= 0) {
        return null;
    }
    emploi_pdf_ensure();
    $query = db()->prepare('SELECT * FROM emploi_groupe_pdf WHERE id_groupe = ? LIMIT 1');
    $query->execute([$groupId]);
    $row = $query->fetch() ?: null;
    if (!$row) {
        return null;
    }
    $path = emploi_pdf_path($groupId);
    if (!is_file($path)) {
        return null;
    }
    $row['path'] = $path;
    return $row;
}

function emploi_pdf_save(int $groupId, array $file): void
{
    if ($groupId <= 0) {
        throw new InvalidArgumentException('Choisissez un groupe.');
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Le fichier PDF n’a pas pu être reçu.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = (string) ($file['name'] ?? 'emploi.pdf');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Le fichier PDF n’a pas pu être reçu.');
    }
    if ($size <= 0 || $size > 12 * 1048576) {
        throw new InvalidArgumentException('Le PDF doit peser moins de 12 Mo.');
    }
    if (!emploi_pdf_signature_ok($tmp)) {
        throw new InvalidArgumentException('Seuls les fichiers PDF sont acceptés.');
    }
    $name = preg_replace('/[\x00-\x1F\x7F"]+/', '', $name) ?? '';
    if ($name === '') {
        $name = 'emploi.pdf';
    }

    $group = db()->prepare('SELECT id_groupe FROM groupe WHERE id_groupe = ? LIMIT 1');
    $group->execute([$groupId]);
    if (!$group->fetch()) {
        throw new InvalidArgumentException('Groupe introuvable.');
    }

    emploi_pdf_ensure();
    $target = emploi_pdf_path($groupId);
    $previous = emploi_pdf_meta($groupId);
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Impossible d’enregistrer le PDF.');
    }
    @chmod($target, 0640);
    if ($previous && is_file((string) $previous['path']) && realpath((string) $previous['path']) !== realpath($target)) {
        @unlink((string) $previous['path']);
    }

    $statement = db()->prepare(
        'INSERT INTO emploi_groupe_pdf (id_groupe, original_name, stored_name, file_size, uploaded_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           original_name = VALUES(original_name),
           stored_name = VALUES(stored_name),
           file_size = VALUES(file_size),
           uploaded_at = NOW()'
    );
    $statement->execute([$groupId, $name, $groupId . '.pdf', filesize($target) ?: $size]);
}

function emploi_pdf_delete(int $groupId): void
{
    if ($groupId <= 0) {
        return;
    }
    emploi_pdf_ensure();
    $path = emploi_pdf_path($groupId);
    if (is_file($path)) {
        @unlink($path);
    }
    db()->prepare('DELETE FROM emploi_groupe_pdf WHERE id_groupe = ?')->execute([$groupId]);
}

function emploi_pdf_student_may_access(int $studentId, int $groupId): bool
{
    if ($studentId <= 0 || $groupId <= 0) {
        return false;
    }
    $query = db()->prepare(
        'SELECT 1 FROM groupe_etudiant WHERE id_etudiant = ? AND id_groupe = ? LIMIT 1'
    );
    $query->execute([$studentId, $groupId]);
    return (bool) $query->fetchColumn();
}

/**
 * @return list<array<string,mixed>>
 */
function emploi_pdf_for_student(int $studentId): array
{
    if ($studentId <= 0) {
        return [];
    }
    emploi_pdf_ensure();
    $query = db()->prepare(
        'SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero,
                p.original_name, p.file_size, p.uploaded_at
         FROM groupe_etudiant ge
         JOIN groupe g ON g.id_groupe = ge.id_groupe
         JOIN emploi_groupe_pdf p ON p.id_groupe = g.id_groupe
         WHERE ge.id_etudiant = ?
         ORDER BY g.semestre, g.type_groupe, g.numero'
    );
    $query->execute([$studentId]);
    $rows = [];
    foreach ($query->fetchAll() as $row) {
        if (is_file(emploi_pdf_path((int) $row['id_groupe']))) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function emploi_pdf_groups(): array
{
    return db()->query(
        "SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero,
                p.original_name, p.file_size, p.uploaded_at
         FROM groupe g
         LEFT JOIN emploi_groupe_pdf p ON p.id_groupe = g.id_groupe
         ORDER BY g.semestre, FIELD(g.type_groupe, 'Cours', 'TD', 'TP'), g.numero"
    )->fetchAll();
}

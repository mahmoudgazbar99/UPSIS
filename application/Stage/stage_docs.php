<?php
declare(strict_types=1);

function stage_docs_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_doc_type (
          id_type INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(40) NOT NULL,
          libelle VARCHAR(160) NOT NULL,
          description VARCHAR(500) NOT NULL DEFAULT '',
          deposant VARCHAR(20) NOT NULL DEFAULT 'les_deux',
          validation_requise TINYINT(1) NOT NULL DEFAULT 1,
          visible_encadrant TINYINT(1) NOT NULL DEFAULT 0,
          actif TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_type),
          UNIQUE KEY uq_stage_doc_type (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_doc_exigence (
          id_exigence INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_type INT UNSIGNED NOT NULL,
          id_campagne INT UNSIGNED DEFAULT NULL,
          id_spec INT UNSIGNED DEFAULT NULL,
          niveau VARCHAR(10) NOT NULL DEFAULT '',
          obligatoire TINYINT(1) NOT NULL DEFAULT 0,
          deposant VARCHAR(20) NOT NULL DEFAULT 'les_deux',
          validation_requise TINYINT(1) NOT NULL DEFAULT 1,
          visible_encadrant TINYINT(1) NOT NULL DEFAULT 0,
          date_limite DATE DEFAULT NULL,
          actif TINYINT(1) NOT NULL DEFAULT 1,
          retire_at DATETIME DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_exigence),
          KEY idx_stage_doc_exigence (id_type, actif)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_doc_piece (
          id_piece INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_placement INT UNSIGNED NOT NULL,
          id_etudiant INT UNSIGNED NOT NULL,
          id_campagne INT UNSIGNED DEFAULT NULL,
          id_type INT UNSIGNED NOT NULL,
          id_exigence INT UNSIGNED DEFAULT NULL,
          statut VARCHAR(20) NOT NULL DEFAULT 'a_verifier',
          motif VARCHAR(500) NOT NULL DEFAULT '',
          revue_role VARCHAR(20) NOT NULL DEFAULT '',
          revue_id INT UNSIGNED DEFAULT NULL,
          revue_at DATETIME DEFAULT NULL,
          id_version INT UNSIGNED DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id_piece),
          KEY idx_stage_doc_piece_placement (id_placement, statut),
          KEY idx_stage_doc_piece_student (id_etudiant)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_doc_version (
          id_version INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_piece INT UNSIGNED NOT NULL,
          numero INT UNSIGNED NOT NULL,
          nom_original VARCHAR(180) NOT NULL,
          cle_stockage CHAR(64) NOT NULL,
          mime VARCHAR(80) NOT NULL,
          taille INT UNSIGNED NOT NULL,
          empreinte CHAR(64) NOT NULL,
          uploader_role VARCHAR(20) NOT NULL,
          uploader_id INT UNSIGNED NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_version),
          UNIQUE KEY uq_stage_doc_version (id_piece, numero),
          UNIQUE KEY uq_stage_doc_cle (cle_stockage),
          KEY idx_stage_doc_version_piece (id_piece)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $seeds = [
        ['convention', 'Convention de stage', 'Convention liant l’étudiant, l’établissement d’accueil et l’institution.', 'les_deux', 1, 0],
        ['assurance', 'Assurance ou couverture', 'Document de couverture applicable au stage.', 'etudiant', 1, 0],
        ['affectation', 'Fiche d’affectation', 'Fiche indiquant le lieu et la période d’affectation.', 'personnel', 1, 1],
        ['presence', 'Fiche de présence', 'Relevé de présence du stage.', 'les_deux', 1, 1],
        ['evaluation_doc', 'Fiche d’évaluation', 'Document d’évaluation, distinct des grilles saisies dans le suivi.', 'personnel', 0, 1],
        ['rapport', 'Rapport de stage', 'Rapport rédigé pour ce stage.', 'etudiant', 1, 1],
        ['administratif', 'Document administratif complémentaire', 'Pièce complémentaire qui ne figure pas dans une autre catégorie.', 'les_deux', 1, 0],
    ];
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO stage_doc_type (code, libelle, description, deposant, validation_requise, visible_encadrant)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($seeds as $seed) {
        $insert->execute($seed);
    }
    $settings = $pdo->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    institution_ensure();
    foreach ([
        'stage_doc_max_bytes' => '8388608',
        'stage_doc_extensions' => 'pdf,jpg,jpeg,png,doc,docx',
        'stage_doc_lock_closed' => '1',
    ] as $key => $value) {
        $settings->execute([$key, $value]);
    }
}

function stage_doc_statuses(): array
{
    return [
        'manquant' => 'Manquant',
        'a_verifier' => 'À vérifier',
        'valide' => 'Validé',
        'a_corriger' => 'À corriger',
        'archive' => 'Archivé',
        'retire' => 'Retiré',
    ];
}

function stage_doc_depositors(): array
{
    return [
        'etudiant' => 'Étudiant',
        'personnel' => 'Personnel des stages',
        'les_deux' => 'Étudiant ou personnel',
    ];
}

function stage_docs_root(): string
{
    $configured = getenv('STAGE_DOCS_DIR');
    $root = is_string($configured) && $configured !== ''
        ? $configured
        : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'stage-documents';
    $root = rtrim($root, "/\\");
    $web = realpath(__DIR__);
    if ($web !== false) {
        $left = strtolower(str_replace('\\', '/', $root));
        $right = strtolower(str_replace('\\', '/', $web));
        if ($left === $right || str_starts_with($left, $right . '/')) {
            throw new RuntimeException('Le stockage des documents de stage ne peut pas être publié avec le site.');
        }
    }
    return $root;
}

function stage_docs_root_prepare(): string
{
    $root = stage_docs_root();
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Le dossier de stockage des stages est inaccessible.');
    }
    $incoming = $root . DIRECTORY_SEPARATOR . '.incoming';
    if (!is_dir($incoming)) {
        mkdir($incoming, 0700, true);
    }
    $guard = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($guard)) {
        file_put_contents($guard, "Require all denied\nDeny from all\n");
    }
    foreach (glob($incoming . DIRECTORY_SEPARATOR . '*') ?: [] as $orphan) {
        if (is_file($orphan) && filemtime($orphan) < time() - 86400) {
            unlink($orphan);
        }
    }
    return $root;
}

function stage_doc_setting_int(string $key, int $default): int
{
    $value = (int) institution_setting($key);
    return $value > 0 ? $value : $default;
}

function stage_doc_max_bytes(): int
{
    return max(1, stage_doc_setting_int('stage_doc_max_bytes', 8388608));
}

function stage_doc_extensions(): array
{
    $catalog = stage_doc_catalog();
    $raw = strtolower(institution_setting('stage_doc_extensions'));
    if ($raw === '') {
        $raw = 'pdf,jpg,jpeg,png,doc,docx';
    }
    $picked = [];
    foreach (explode(',', $raw) as $part) {
        $ext = trim($part);
        if (isset($catalog[$ext])) {
            $picked[$ext] = $catalog[$ext];
        }
    }
    return $picked !== [] ? $picked : array_intersect_key($catalog, array_flip(['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']));
}

function stage_doc_catalog(): array
{
    return [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/msword', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];
}

function stage_doc_closed_locked(): bool
{
    return institution_setting('stage_doc_lock_closed') !== '0';
}

function stage_doc_placement(int $id): ?array
{
    stage_docs_ensure();
    $query = db()->prepare(
        'SELECT p.*, e.nom, e.prenom, e.num_inscription, e.niveau, e.id_spec,
                sp.nom_spec, o.nom AS organisme, sv.nom AS service,
                (SELECT GROUP_CONCAT(CONCAT(g.semestre, " ", g.type_groupe, " ", g.numero) ORDER BY g.semestre SEPARATOR ", ")
                   FROM groupe_etudiant ge JOIN groupe g ON g.id_groupe = ge.id_groupe
                  WHERE ge.id_etudiant = e.id_etudiant) AS groupes
         FROM stage_placement p
         JOIN etudiant e ON e.id_etudiant = p.id_etudiant
         LEFT JOIN specialite sp ON sp.id_spec = e.id_spec
         LEFT JOIN stage_organisme o ON o.id_organisme = p.id_organisme
         LEFT JOIN stage_service sv ON sv.id_service = p.id_service
         WHERE p.id_placement = ?'
    );
    $query->execute([$id]);
    $row = $query->fetch();
    return $row ?: null;
}

function stage_doc_may_open(array $placement): bool
{
    if (is_admin()) {
        return true;
    }
    if (is_student()) {
        return (int) ($_SESSION['id_etudiant'] ?? 0) === (int) $placement['id_etudiant'];
    }
    return is_stage() && role_can('stage.documents.view') && stage_placement_visible($placement);
}

function stage_doc_is_supervisor(): bool
{
    return is_stage() && stage_duty() === 'superviseur';
}

function stage_doc_may_review(): bool
{
    return is_admin() || (is_stage() && role_can('stage.documents.review'));
}

function stage_doc_may_configure(): bool
{
    return is_admin() || (is_stage() && role_can('stage.documents.configure'));
}

function stage_doc_placement_locked(array $placement): bool
{
    return stage_doc_closed_locked() && in_array((string) $placement['statut'], ['termine', 'annule'], true);
}

function stage_doc_type_visible(array $type, array $placement): bool
{
    if (!stage_doc_may_open($placement)) {
        return false;
    }
    if (stage_doc_is_supervisor()) {
        return (int) ($type['visible_encadrant'] ?? 0) === 1;
    }
    return true;
}

function stage_doc_rules_for(array $placement): array
{
    stage_docs_ensure();
    $rows = db()->query(
        'SELECT x.*, t.code, t.libelle, t.description, t.actif AS type_actif
         FROM stage_doc_exigence x
         JOIN stage_doc_type t ON t.id_type = x.id_type
         WHERE x.actif = 1 AND t.actif = 1
         ORDER BY x.id_exigence'
    )->fetchAll();
    $best = [];
    foreach ($rows as $row) {
        if ($row['id_campagne'] !== null && (int) $row['id_campagne'] !== (int) ($placement['id_campagne'] ?? 0)) {
            continue;
        }
        if ($row['id_spec'] !== null && (int) $row['id_spec'] !== (int) $placement['id_spec']) {
            continue;
        }
        if ((string) $row['niveau'] !== '' && (string) $row['niveau'] !== (string) $placement['niveau']) {
            continue;
        }
        $score = ($row['id_campagne'] !== null ? 4 : 0) + ($row['id_spec'] !== null ? 2 : 0) + ((string) $row['niveau'] !== '' ? 1 : 0);
        $typeId = (int) $row['id_type'];
        if (!isset($best[$typeId]) || $score >= (int) $best[$typeId]['_score']) {
            $row['_score'] = $score;
            $best[$typeId] = $row;
        }
    }
    return array_values($best);
}

function stage_doc_pieces(int $placementId): array
{
    stage_docs_ensure();
    $query = db()->prepare(
        'SELECT p.*, t.code, t.libelle, t.description, t.deposant AS type_deposant,
                t.validation_requise AS type_validation, t.visible_encadrant AS type_visible,
                v.numero, v.nom_original, v.mime, v.taille, v.uploader_role, v.uploader_id, v.created_at AS version_at
         FROM stage_doc_piece p
         JOIN stage_doc_type t ON t.id_type = p.id_type
         LEFT JOIN stage_doc_version v ON v.id_version = p.id_version
         WHERE p.id_placement = ?
         ORDER BY t.libelle, p.id_piece'
    );
    $query->execute([$placementId]);
    return $query->fetchAll();
}

function stage_doc_versions(int $pieceId): array
{
    $query = db()->prepare(
        'SELECT * FROM stage_doc_version WHERE id_piece = ? ORDER BY numero DESC'
    );
    $query->execute([$pieceId]);
    return $query->fetchAll();
}

function stage_doc_satisfied(array $rule, ?array $piece): bool
{
    if ($piece === null || (string) $piece['statut'] === 'retire') {
        return false;
    }
    if ((int) $rule['validation_requise'] === 1) {
        return in_array((string) $piece['statut'], ['valide', 'archive'], true);
    }
    return in_array((string) $piece['statut'], ['a_verifier', 'valide', 'archive'], true);
}

function stage_doc_piece_for_type(array $pieces, int $typeId): ?array
{
    $found = null;
    $rank = ['valide' => 5, 'archive' => 4, 'a_verifier' => 3, 'a_corriger' => 2];
    foreach ($pieces as $piece) {
        if ((int) $piece['id_type'] !== $typeId || (string) $piece['statut'] === 'retire') {
            continue;
        }
        if ($found === null || ($rank[(string) $piece['statut']] ?? 0) > ($rank[(string) $found['statut']] ?? 0)) {
            $found = $piece;
        }
    }
    return $found;
}

function stage_doc_dossier_state(array $placement): array
{
    $rules = stage_doc_rules_for($placement);
    $pieces = stage_doc_pieces((int) $placement['id_placement']);
    $missing = 0;
    $late = 0;
    $mandatory = 0;
    $done = 0;
    foreach ($rules as $rule) {
        if ((int) $rule['obligatoire'] !== 1) {
            continue;
        }
        $mandatory++;
        $piece = stage_doc_piece_for_type($pieces, (int) $rule['id_type']);
        if (stage_doc_satisfied($rule, $piece)) {
            $done++;
            continue;
        }
        $missing++;
        if (!empty($rule['date_limite']) && (string) $rule['date_limite'] < date('Y-m-d')) {
            $late++;
        }
    }
    return [
        'rules' => $rules,
        'pieces' => $pieces,
        'mandatory' => $mandatory,
        'done' => $done,
        'missing' => $missing,
        'late' => $late,
        'complete' => $mandatory > 0 && $missing === 0,
    ];
}

function stage_doc_may_upload(array $placement, array $rule, ?array $piece = null): bool
{
    if (!stage_doc_may_open($placement) || !stage_doc_type_visible($rule, $placement)) {
        return false;
    }
    if ($piece !== null && in_array((string) $piece['statut'], ['archive', 'retire'], true)) {
        return false;
    }
    if ($piece !== null && (string) $piece['statut'] === 'valide' && is_student()) {
        return false;
    }
    if (stage_doc_placement_locked($placement) && !stage_doc_may_review()) {
        return false;
    }
    $who = (string) ($rule['deposant'] ?? 'les_deux');
    if (is_student()) {
        return in_array($who, ['etudiant', 'les_deux'], true);
    }
    if (is_admin() || (is_stage() && role_can('stage.documents.upload'))) {
        return in_array($who, ['personnel', 'les_deux'], true);
    }
    return false;
}

function stage_doc_throttle(): void
{
    $now = time();
    $hits = array_values(array_filter(
        (array) ($_SESSION['stage_doc_hits'] ?? []),
        static fn ($stamp): bool => (int) $stamp > $now - 600
    ));
    if (count($hits) >= 20) {
        throw new InvalidArgumentException('Trop de dépôts. Réessayez dans quelques minutes.');
    }
    $hits[] = $now;
    $_SESSION['stage_doc_hits'] = $hits;
}

function stage_doc_filename(string $original): string
{
    $base = basename(str_replace('\\', '/', $original));
    $base = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $base) ?? '';
    $base = trim($base, '. ');
    return $base !== '' ? mb_substr($base, 0, 120) : 'document';
}

function stage_doc_dangerous(string $name): bool
{
    $blocked = ['php', 'phtml', 'php3', 'phar', 'exe', 'js', 'html', 'htm', 'svg', 'bat', 'cmd', 'ps1', 'sh', 'msi', 'com', 'jar', 'vbs', 'htaccess'];
    foreach (explode('.', strtolower($name)) as $part) {
        if (in_array($part, $blocked, true)) {
            return true;
        }
    }
    return false;
}

function stage_doc_inspect(string $path, string $original): array
{
    if (!is_file($path)) {
        throw new InvalidArgumentException('Le fichier n’a pas été reçu. Choisissez-le de nouveau.');
    }
    $size = filesize($path);
    if ($size === false || $size < 1) {
        throw new InvalidArgumentException('Le fichier est vide.');
    }
    if ($size > stage_doc_max_bytes()) {
        throw new InvalidArgumentException('Le fichier dépasse la taille autorisée.');
    }
    $name = stage_doc_filename($original);
    if (stage_doc_dangerous($original) || stage_doc_dangerous($name)) {
        throw new InvalidArgumentException('Ce format de fichier n’est pas accepté.');
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = stage_doc_extensions();
    if (!isset($allowed[$extension])) {
        throw new InvalidArgumentException('Formats acceptés : ' . implode(', ', array_keys($allowed)) . '.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    if (!in_array($mime, $allowed[$extension], true)) {
        throw new InvalidArgumentException('Le contenu du fichier ne correspond pas à son extension.');
    }
    $head = (string) file_get_contents($path, false, null, 0, 16);
    if (str_starts_with($head, '<?php') || str_starts_with($head, '#!') || str_starts_with($head, 'MZ') || stripos($head, '<script') !== false) {
        throw new InvalidArgumentException('Ce fichier ne peut pas être accepté.');
    }
    $signature = match ($extension) {
        'pdf' => str_starts_with($head, '%PDF'),
        'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
        'png' => str_starts_with($head, "\x89PNG"),
        'doc' => str_starts_with($head, "\xD0\xCF\x11\xE0"),
        'docx' => str_starts_with($head, "PK\x03\x04") && stage_doc_office_package($path),
        default => false,
    };
    if (!$signature) {
        throw new InvalidArgumentException('Le fichier est incomplet ou d’un format inattendu.');
    }
    return ['name' => $name, 'mime' => $mime, 'size' => $size, 'hash' => hash_file('sha256', $path) ?: ''];
}

function stage_doc_office_package(string $path): bool
{
    if (!class_exists(ZipArchive::class)) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $ok = $zip->locateName('[Content_Types].xml') !== false;
    $zip->close();
    return $ok;
}

function stage_doc_actor(): array
{
    if (is_student()) {
        return ['role' => 'etudiant', 'id' => (int) ($_SESSION['id_etudiant'] ?? 0)];
    }
    if (is_stage()) {
        return ['role' => 'stage', 'id' => current_stage_id()];
    }
    if (is_admin()) {
        return ['role' => 'admin', 'id' => 0];
    }
    return ['role' => 'inconnu', 'id' => 0];
}

function stage_doc_rule_for_type(array $placement, int $typeId): array
{
    foreach (stage_doc_rules_for($placement) as $rule) {
        if ((int) $rule['id_type'] === $typeId) {
            return $rule;
        }
    }
    $query = db()->prepare('SELECT * FROM stage_doc_type WHERE id_type = ? AND actif = 1');
    $query->execute([$typeId]);
    $type = $query->fetch();
    if (!$type) {
        throw new InvalidArgumentException('Cette catégorie de document n’est pas disponible.');
    }
    $type['id_exigence'] = null;
    $type['obligatoire'] = 0;
    $type['date_limite'] = null;
    return $type;
}

function stage_doc_import(int $placementId, int $typeId, string $path, string $original): int
{
    stage_doc_throttle();
    $placement = stage_doc_placement($placementId);
    if ($placement === null || !stage_doc_may_open($placement)) {
        throw new InvalidArgumentException('Dossier introuvable.');
    }
    $rule = stage_doc_rule_for_type($placement, $typeId);
    $pieces = stage_doc_pieces($placementId);
    $current = null;
    foreach ($pieces as $piece) {
        if ((int) $piece['id_type'] === $typeId && (string) $piece['statut'] !== 'retire') {
            $current = $piece;
            break;
        }
    }
    if (!stage_doc_may_upload($placement, $rule, $current)) {
        throw new InvalidArgumentException('Vous ne pouvez pas déposer cette pièce.');
    }
    $info = stage_doc_inspect($path, $original);
    $root = stage_docs_root_prepare();
    $key = bin2hex(random_bytes(32));
    $folder = $root . DIRECTORY_SEPARATOR . substr($key, 0, 2) . DIRECTORY_SEPARATOR . substr($key, 2, 2);
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('Le stockage du document a échoué.');
    }
    $destination = $folder . DIRECTORY_SEPARATOR . $key;
    if (!copy($path, $destination)) {
        throw new RuntimeException('Le stockage du document a échoué.');
    }
    $actor = stage_doc_actor();
    $needsReview = (int) ($rule['validation_requise'] ?? 1) === 1;
    $status = $needsReview ? 'a_verifier' : 'valide';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($current === null) {
            $insert = $pdo->prepare(
                'INSERT INTO stage_doc_piece (id_placement, id_etudiant, id_campagne, id_type, id_exigence, statut)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $placementId,
                (int) $placement['id_etudiant'],
                $placement['id_campagne'] !== null ? (int) $placement['id_campagne'] : null,
                $typeId,
                $rule['id_exigence'] ?? null,
                $status,
            ]);
            $pieceId = (int) $pdo->lastInsertId();
            $number = 1;
        } else {
            $pieceId = (int) $current['id_piece'];
            $number = (int) $pdo->query('SELECT COALESCE(MAX(numero), 0) + 1 FROM stage_doc_version WHERE id_piece = ' . $pieceId)->fetchColumn();
        }
        $version = $pdo->prepare(
            'INSERT INTO stage_doc_version (id_piece, numero, nom_original, cle_stockage, mime, taille, empreinte, uploader_role, uploader_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $version->execute([$pieceId, $number, $info['name'], $key, $info['mime'], $info['size'], $info['hash'], $actor['role'], $actor['id']]);
        $versionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE stage_doc_piece
             SET statut = ?, motif = "", revue_role = "", revue_id = NULL, revue_at = NULL, id_version = ?
             WHERE id_piece = ?'
        )->execute([$status, $versionId, $pieceId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_file($destination)) {
            unlink($destination);
        }
        throw $exception;
    }
    audit_record(
        'stage',
        $number > 1 ? 'remplacement' : 'depot',
        'Pièce #' . $pieceId . ' v' . $number . ' sur le stage #' . $placementId
    );
    return $versionId;
}

function stage_doc_receive_upload(int $placementId, int $typeId, array $file): int
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('Le fichier dépasse la taille autorisée.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Le dépôt a été interrompu. Vous pouvez le relancer.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Le fichier n’a pas été reçu. Choisissez-le de nouveau.');
    }
    return stage_doc_import($placementId, $typeId, $tmp, (string) ($file['name'] ?? 'document'));
}

function stage_doc_piece_row(int $pieceId): ?array
{
    stage_docs_ensure();
    $query = db()->prepare(
        'SELECT p.*, t.libelle, t.visible_encadrant, t.deposant, t.validation_requise
         FROM stage_doc_piece p
         JOIN stage_doc_type t ON t.id_type = p.id_type
         WHERE p.id_piece = ?'
    );
    $query->execute([$pieceId]);
    $row = $query->fetch();
    return $row ?: null;
}

function stage_doc_review(int $pieceId, string $decision, string $reason = ''): void
{
    if (!stage_doc_may_review()) {
        throw new InvalidArgumentException('La validation des documents est réservée au responsable des stages.');
    }
    $piece = stage_doc_piece_row($pieceId);
    $placement = $piece ? stage_doc_placement((int) $piece['id_placement']) : null;
    if ($piece === null || $placement === null || !stage_doc_may_open($placement) || !stage_doc_type_visible($piece, $placement)) {
        throw new InvalidArgumentException('Document introuvable.');
    }
    $reason = trim($reason);
    if ($decision === 'valide') {
        if ((string) $piece['statut'] !== 'a_verifier') {
            throw new InvalidArgumentException('Seule une pièce en attente peut être validée.');
        }
    } elseif ($decision === 'a_corriger') {
        if ($reason === '') {
            throw new InvalidArgumentException('Indiquez le motif de la correction.');
        }
        if (!in_array((string) $piece['statut'], ['a_verifier', 'valide'], true)) {
            throw new InvalidArgumentException('Cette pièce ne peut pas être renvoyée.');
        }
    } elseif ($decision === 'archive') {
        if ((string) $piece['statut'] === 'retire') {
            throw new InvalidArgumentException('Une pièce retirée ne s’archive pas.');
        }
    } elseif ($decision === 'retire') {
        if ($reason === '') {
            throw new InvalidArgumentException('Indiquez le motif du retrait.');
        }
    } else {
        throw new InvalidArgumentException('Action de document inconnue.');
    }
    $actor = stage_doc_actor();
    db()->prepare(
        'UPDATE stage_doc_piece
         SET statut = ?, motif = ?, revue_role = ?, revue_id = ?, revue_at = NOW()
         WHERE id_piece = ?'
    )->execute([$decision, $decision === 'valide' || $decision === 'archive' ? '' : $reason, $actor['role'], $actor['id'], $pieceId]);
    $action = ['valide' => 'valider_doc', 'a_corriger' => 'refuser_doc', 'archive' => 'archiver_doc', 'retire' => 'retirer_doc'][$decision];
    audit_record('stage', $action, 'Pièce #' . $pieceId . ' sur le stage #' . (int) $piece['id_placement']);
}

function stage_doc_version_row(int $versionId): ?array
{
    stage_docs_ensure();
    $query = db()->prepare(
        'SELECT v.*, p.id_placement, p.id_etudiant, p.statut, p.id_type, t.visible_encadrant, t.libelle
         FROM stage_doc_version v
         JOIN stage_doc_piece p ON p.id_piece = v.id_piece
         JOIN stage_doc_type t ON t.id_type = p.id_type
         WHERE v.id_version = ?'
    );
    $query->execute([$versionId]);
    $row = $query->fetch();
    return $row ?: null;
}

function stage_doc_can_read_version(array $version): bool
{
    $placement = stage_doc_placement((int) $version['id_placement']);
    return $placement !== null && stage_doc_type_visible($version, $placement);
}

function stage_doc_absolute(string $key): string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
        throw new InvalidArgumentException('Document introuvable.');
    }
    $root = realpath(stage_docs_root());
    $path = stage_docs_root() . DIRECTORY_SEPARATOR . substr($key, 0, 2) . DIRECTORY_SEPARATOR . substr($key, 2, 2) . DIRECTORY_SEPARATOR . $key;
    $real = realpath($path);
    if ($root === false || $real === false || stripos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        throw new InvalidArgumentException('Document introuvable.');
    }
    return $real;
}

function stage_doc_send(int $versionId, bool $inline = false): void
{
    $version = stage_doc_version_row($versionId);
    if ($version === null || !stage_doc_can_read_version($version)) {
        http_response_code(404);
        exit('Document introuvable.');
    }
    $path = stage_doc_absolute((string) $version['cle_stockage']);
    $hash = hash_file('sha256', $path);
    if ($hash === false || !hash_equals((string) $version['empreinte'], $hash)) {
        http_response_code(404);
        exit('Document introuvable.');
    }
    $preview = $inline && in_array((string) $version['mime'], ['image/jpeg', 'image/png'], true);
    $name = stage_doc_filename((string) $version['nom_original']);
    audit_record('stage', 'telecharger', 'Version #' . $versionId . ' du stage #' . (int) $version['id_placement']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, no-store');
    header('Content-Type: ' . ($preview ? (string) $version['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    readfile($path);
    exit;
}

function stage_doc_zip(array $versionIds): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new InvalidArgumentException('L’archive groupée n’est pas disponible sur ce serveur.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $versionIds), static fn (int $id): bool => $id > 0)));
    if ($ids === [] || count($ids) > 100) {
        throw new InvalidArgumentException('Choisissez entre un et cent documents.');
    }
    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'stagezip');
    if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('L’archive n’a pas pu être préparée.');
    }
    $added = 0;
    foreach ($ids as $id) {
        $version = stage_doc_version_row($id);
        if ($version === null || !stage_doc_can_read_version($version)) {
            continue;
        }
        $path = stage_doc_absolute((string) $version['cle_stockage']);
        $zip->addFile($path, $added . '-' . stage_doc_filename((string) $version['nom_original']));
        $added++;
    }
    $zip->close();
    if ($added === 0) {
        unlink($tmp);
        throw new InvalidArgumentException('Aucun document autorisé à télécharger.');
    }
    audit_record('stage', 'telecharger', 'Archive de ' . $added . ' document(s)');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="dossier-stage.zip"');
    header('Content-Length: ' . (string) filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function stage_doc_uploader_label(string $role, int $id): string
{
    if ($role === 'etudiant') {
        return is_student() && (int) ($_SESSION['id_etudiant'] ?? 0) === $id ? 'Vous' : 'Étudiant';
    }
    if ($role === 'admin') {
        return 'Administrateur';
    }
    if ($role === 'stage' && $id > 0) {
        $query = db()->prepare('SELECT nom, responsabilite FROM stage_user WHERE id_stage = ?');
        $query->execute([$id]);
        $user = $query->fetch();
        if ($user) {
            $duty = (string) $user['responsabilite'] === 'superviseur' ? 'Encadrant de stage' : 'Responsable des stages';
            $name = trim((string) $user['nom']);
            return $name !== '' ? $duty . ' · ' . $name : $duty;
        }
    }
    return 'Personnel des stages';
}

function stage_doc_indicators(): array
{
    stage_docs_ensure();
    $empty = [
        'etudiants_incomplets' => 0,
        'manquants' => 0,
        'a_verifier' => 0,
        'a_corriger' => 0,
        'complets' => 0,
        'archives' => 0,
        'retard' => 0,
        'visibles' => 0,
    ];
    if (!is_admin() && !(is_stage() && role_can('stage.documents.view'))) {
        return $empty;
    }
    $where = stage_doc_is_supervisor() ? ' AND p.id_stage_superviseur = ' . current_stage_id() : '';
    $placements = db()->query(
        'SELECT p.*, e.id_spec, e.niveau, e.id_etudiant
         FROM stage_placement p
         JOIN etudiant e ON e.id_etudiant = p.id_etudiant
         WHERE p.statut IN ("affecte","en_cours","revue","retour","valide","termine")' . $where
    )->fetchAll();
    $students = [];
    $archivedPlacements = [];
    foreach ($placements as $placement) {
        if (stage_doc_is_supervisor()) {
            $count = db()->prepare(
                'SELECT COUNT(*) FROM stage_doc_piece p
                 JOIN stage_doc_type t ON t.id_type = p.id_type
                 WHERE p.id_placement = ? AND p.statut <> "retire" AND t.visible_encadrant = 1'
            );
            $count->execute([(int) $placement['id_placement']]);
            $empty['visibles'] += (int) $count->fetchColumn();
            continue;
        }
        $state = stage_doc_dossier_state($placement);
        $empty['manquants'] += $state['missing'];
        $empty['retard'] += $state['late'];
        if ($state['mandatory'] > 0 && $state['complete']) {
            $empty['complets']++;
        } elseif ($state['mandatory'] > 0) {
            $students[(int) $placement['id_etudiant']] = true;
        }
        $pieces = $state['pieces'];
        foreach ($pieces as $piece) {
            if ((string) $piece['statut'] === 'a_verifier') {
                $empty['a_verifier']++;
            } elseif ((string) $piece['statut'] === 'a_corriger') {
                $empty['a_corriger']++;
            } elseif ((string) $piece['statut'] === 'archive') {
                $archivedPlacements[(int) $placement['id_placement']] = true;
            }
        }
        if ((string) $placement['statut'] === 'termine' && $pieces !== []) {
            $archivedPlacements[(int) $placement['id_placement']] = true;
        }
    }
    $empty['etudiants_incomplets'] = count($students);
    $empty['archives'] = count($archivedPlacements);
    return $empty;
}

function stage_doc_archive(array $filters): array
{
    stage_docs_ensure();
    if (!is_admin() && !(is_stage() && role_can('stage.documents.archive') || stage_doc_is_supervisor())) {
        return [];
    }
    $sql = 'SELECT v.id_version, v.nom_original, v.taille, v.created_at, v.uploader_role, v.uploader_id,
                   p.id_piece, p.statut, p.motif, p.id_placement, p.id_etudiant,
                   t.id_type, t.libelle, t.visible_encadrant,
                   pl.annee_label, pl.periode_code, pl.date_debut, pl.date_fin, pl.statut AS placement_statut,
                   pl.id_organisme, pl.id_service, pl.id_stage_superviseur, pl.id_campagne,
                   e.nom, e.prenom, e.num_inscription, e.id_spec, e.niveau,
                   o.nom AS organisme, sv.nom AS service
            FROM stage_doc_piece p
            JOIN stage_doc_version v ON v.id_version = p.id_version
            JOIN stage_doc_type t ON t.id_type = p.id_type
            JOIN stage_placement pl ON pl.id_placement = p.id_placement
            JOIN etudiant e ON e.id_etudiant = p.id_etudiant
            LEFT JOIN stage_organisme o ON o.id_organisme = pl.id_organisme
            LEFT JOIN stage_service sv ON sv.id_service = pl.id_service
            WHERE 1 = 1';
    $params = [];
    if ((string) ($filters['statut'] ?? '') !== '' && isset(stage_doc_statuses()[(string) $filters['statut']])) {
        $sql .= ' AND p.statut = ?';
        $params[] = (string) $filters['statut'];
    } else {
        $sql .= ' AND p.statut <> "retire"';
    }
    if ((string) ($filters['annee'] ?? '') !== '') {
        $sql .= ' AND pl.annee_label = ?';
        $params[] = (string) $filters['annee'];
    }
    if ((string) ($filters['periode'] ?? '') !== '') {
        $sql .= ' AND pl.periode_code = ?';
        $params[] = (string) $filters['periode'];
    }
    if ((int) ($filters['id_organisme'] ?? 0) > 0) {
        $sql .= ' AND pl.id_organisme = ?';
        $params[] = (int) $filters['id_organisme'];
    }
    if ((int) ($filters['id_service'] ?? 0) > 0) {
        $sql .= ' AND pl.id_service = ?';
        $params[] = (int) $filters['id_service'];
    }
    if ((int) ($filters['id_type'] ?? 0) > 0) {
        $sql .= ' AND p.id_type = ?';
        $params[] = (int) $filters['id_type'];
    }
    if ((int) ($filters['id_placement'] ?? 0) > 0) {
        $sql .= ' AND p.id_placement = ?';
        $params[] = (int) $filters['id_placement'];
    }
    if ((int) ($filters['id_spec'] ?? 0) > 0) {
        $sql .= ' AND e.id_spec = ?';
        $params[] = (int) $filters['id_spec'];
    }
    if ((string) ($filters['du'] ?? '') !== '') {
        $sql .= ' AND v.created_at >= ?';
        $params[] = (string) $filters['du'] . ' 00:00:00';
    }
    if ((string) ($filters['au'] ?? '') !== '') {
        $sql .= ' AND v.created_at <= ?';
        $params[] = (string) $filters['au'] . ' 23:59:59';
    }
    if ((string) ($filters['cloture'] ?? '') === '1') {
        $sql .= ' AND pl.statut = "termine"';
    }
    if (stage_doc_is_supervisor()) {
        $sql .= ' AND pl.id_stage_superviseur = ? AND t.visible_encadrant = 1';
        $params[] = current_stage_id();
    }
    $sql .= ' ORDER BY v.created_at DESC LIMIT 400';
    $query = db()->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll();
    $needle = trim((string) ($filters['q'] ?? ''));
    if ($needle !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
            $haystack = strtolower($row['prenom'] . ' ' . $row['nom'] . ' ' . $row['num_inscription']);
            return str_contains($haystack, strtolower($needle));
        }));
    }
    $shortcut = (string) ($filters['filtre'] ?? '');
    if ($shortcut === 'manquant' || $shortcut === 'incomplet') {
        return stage_doc_gap_rows($filters, $shortcut);
    }
    if ($shortcut === '') {
        return $rows;
    }
    return array_values(array_filter($rows, static function (array $row) use ($shortcut): bool {
        $placement = stage_doc_placement((int) $row['id_placement']);
        if ($placement === null) {
            return false;
        }
        $state = stage_doc_dossier_state($placement);
        return match ($shortcut) {
            'a_verifier' => (string) $row['statut'] === 'a_verifier',
            'a_corriger' => (string) $row['statut'] === 'a_corriger',
            'archive' => (string) $row['statut'] === 'archive' || (string) $row['placement_statut'] === 'termine',
            'complet' => $state['complete'],
            'incomplet' => $state['mandatory'] > 0 && !$state['complete'],
            'manquant' => $state['missing'] > 0,
            default => true,
        };
    }));
}

function stage_doc_gap_rows(array $filters, string $shortcut): array
{
    $where = stage_doc_is_supervisor() ? ' AND p.id_stage_superviseur = ' . current_stage_id() : '';
    $placements = db()->query(
        'SELECT p.id_placement, p.annee_label, p.periode_code, p.date_debut, p.date_fin, p.statut AS placement_statut,
                p.id_organisme, p.id_service, p.id_campagne, p.id_stage_superviseur,
                e.nom, e.prenom, e.num_inscription, e.id_spec, e.niveau, o.nom AS organisme, sv.nom AS service
         FROM stage_placement p
         JOIN etudiant e ON e.id_etudiant = p.id_etudiant
         LEFT JOIN stage_organisme o ON o.id_organisme = p.id_organisme
         LEFT JOIN stage_service sv ON sv.id_service = p.id_service
         WHERE p.statut IN ("affecte","en_cours","revue","retour","valide","termine")' . $where
    )->fetchAll();
    $rows = [];
    $needle = strtolower(trim((string) ($filters['q'] ?? '')));
    foreach ($placements as $placement) {
        if ($needle !== '' && !str_contains(strtolower($placement['prenom'] . ' ' . $placement['nom'] . ' ' . $placement['num_inscription']), $needle)) {
            continue;
        }
        if ((string) ($filters['annee'] ?? '') !== '' && (string) $placement['annee_label'] !== (string) $filters['annee']) {
            continue;
        }
        if ((int) ($filters['id_organisme'] ?? 0) > 0 && (int) $placement['id_organisme'] !== (int) $filters['id_organisme']) {
            continue;
        }
        if ((int) ($filters['id_service'] ?? 0) > 0 && (int) $placement['id_service'] !== (int) $filters['id_service']) {
            continue;
        }
        $full = stage_doc_placement((int) $placement['id_placement']);
        if ($full === null) {
            continue;
        }
        $state = stage_doc_dossier_state($full);
        if ($shortcut === 'incomplet' && !($state['mandatory'] > 0 && !$state['complete'])) {
            continue;
        }
        foreach ($state['rules'] as $rule) {
            if ((int) $rule['obligatoire'] !== 1 || !stage_doc_type_visible($rule, $full)) {
                continue;
            }
            $piece = stage_doc_piece_for_type($state['pieces'], (int) $rule['id_type']);
            if (stage_doc_satisfied($rule, $piece)) {
                continue;
            }
            if ((int) ($filters['id_type'] ?? 0) > 0 && (int) $rule['id_type'] !== (int) $filters['id_type']) {
                continue;
            }
            $rows[] = [
                'id_version' => (int) ($piece['id_version'] ?? 0),
                'id_placement' => (int) $placement['id_placement'],
                'prenom' => $placement['prenom'],
                'nom' => $placement['nom'],
                'num_inscription' => $placement['num_inscription'],
                'annee_label' => $placement['annee_label'],
                'periode_code' => $placement['periode_code'],
                'date_debut' => $placement['date_debut'],
                'organisme' => $placement['organisme'],
                'service' => $placement['service'],
                'libelle' => $rule['libelle'],
                'statut' => $piece['statut'] ?? 'manquant',
                'created_at' => (string) ($piece['version_at'] ?? ''),
            ];
            if ($shortcut === 'incomplet') {
                break;
            }
        }
    }
    return $rows;
}

function stage_doc_save_type(array $input): void
{
    if (!stage_doc_may_configure()) {
        throw new InvalidArgumentException('La configuration des documents est réservée au responsable des stages.');
    }
    $label = trim((string) ($input['libelle'] ?? ''));
    $code = strtolower(trim((string) ($input['code'] ?? '')));
    $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
    if ($label === '' || $code === '') {
        throw new InvalidArgumentException('Indiquez un nom et un code de catégorie.');
    }
    $depositor = (string) ($input['deposant'] ?? 'les_deux');
    if (!isset(stage_doc_depositors()[$depositor])) {
        $depositor = 'les_deux';
    }
    db()->prepare(
        'INSERT INTO stage_doc_type (code, libelle, description, deposant, validation_requise, visible_encadrant)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        mb_substr($code, 0, 40),
        mb_substr($label, 0, 160),
        mb_substr(trim((string) ($input['description'] ?? '')), 0, 500),
        $depositor,
        !empty($input['validation_requise']) ? 1 : 0,
        !empty($input['visible_encadrant']) ? 1 : 0,
    ]);
    audit_record('stage', 'config_doc', 'Catégorie ' . $code);
}

function stage_doc_deactivate_type(int $typeId): void
{
    if (!stage_doc_may_configure()) {
        throw new InvalidArgumentException('Action non autorisée.');
    }
    db()->prepare('UPDATE stage_doc_type SET actif = 0 WHERE id_type = ?')->execute([$typeId]);
    db()->prepare('UPDATE stage_doc_exigence SET actif = 0, retire_at = NOW() WHERE id_type = ? AND actif = 1')->execute([$typeId]);
    audit_record('stage', 'config_doc', 'Catégorie #' . $typeId . ' retirée');
}

function stage_doc_save_requirement(array $input): void
{
    if (!stage_doc_may_configure()) {
        throw new InvalidArgumentException('Action non autorisée.');
    }
    $typeId = (int) ($input['id_type'] ?? 0);
    $type = db()->prepare('SELECT id_type FROM stage_doc_type WHERE id_type = ? AND actif = 1');
    $type->execute([$typeId]);
    if (!$type->fetchColumn()) {
        throw new InvalidArgumentException('Choisissez une catégorie active.');
    }
    $depositor = (string) ($input['deposant'] ?? 'les_deux');
    if (!isset(stage_doc_depositors()[$depositor])) {
        $depositor = 'les_deux';
    }
    $deadline = trim((string) ($input['date_limite'] ?? ''));
    db()->prepare(
        'INSERT INTO stage_doc_exigence
         (id_type, id_campagne, id_spec, niveau, obligatoire, deposant, validation_requise, visible_encadrant, date_limite)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $typeId,
        (int) ($input['id_campagne'] ?? 0) > 0 ? (int) $input['id_campagne'] : null,
        (int) ($input['id_spec'] ?? 0) > 0 ? (int) $input['id_spec'] : null,
        mb_substr(trim((string) ($input['niveau'] ?? '')), 0, 10),
        !empty($input['obligatoire']) ? 1 : 0,
        $depositor,
        !empty($input['validation_requise']) ? 1 : 0,
        !empty($input['visible_encadrant']) ? 1 : 0,
        $deadline !== '' ? $deadline : null,
    ]);
    audit_record('stage', 'config_doc', 'Exigence pour la catégorie #' . $typeId);
}

function stage_doc_deactivate_requirement(int $requirementId): void
{
    if (!stage_doc_may_configure()) {
        throw new InvalidArgumentException('Action non autorisée.');
    }
    db()->prepare('UPDATE stage_doc_exigence SET actif = 0, retire_at = NOW() WHERE id_exigence = ? AND actif = 1')->execute([$requirementId]);
    audit_record('stage', 'config_doc', 'Exigence #' . $requirementId . ' retirée sans supprimer les fichiers');
}

function stage_doc_save_limits(array $input): void
{
    if (!stage_doc_may_configure()) {
        throw new InvalidArgumentException('Action non autorisée.');
    }
    $mega = (float) str_replace(',', '.', (string) ($input['mega'] ?? '8'));
    $bytes = (int) round($mega * 1048576);
    if ($bytes < 102400 || $bytes > 20971520) {
        throw new InvalidArgumentException('La taille doit rester entre 0,1 et 20 Mo.');
    }
    $extensions = [];
    foreach ((array) ($input['extensions'] ?? []) as $extension) {
        $extension = strtolower((string) $extension);
        if (isset(stage_doc_catalog()[$extension])) {
            $extensions[$extension] = $extension;
        }
    }
    if ($extensions === []) {
        throw new InvalidArgumentException('Gardez au moins un format accepté.');
    }
    institution_setting_put('stage_doc_max_bytes', (string) $bytes);
    institution_setting_put('stage_doc_extensions', implode(',', $extensions));
    institution_setting_put('stage_doc_lock_closed', !empty($input['lock_closed']) ? '1' : '0');
    audit_record('stage', 'config_doc', 'Limites de dépôt des documents de stage');
}

function stage_doc_wants_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function stage_doc_styles(): void
{
    echo '<style>
      .stage-drop{border:1.5px dashed var(--bs-border-color,rgba(138,170,190,.7));border-radius:16px;padding:1.25rem;text-align:center;background:transparent}
      .stage-drop.is-over{border-color:var(--bs-primary);background:rgba(13,110,253,.08)}
      .stage-drop progress{width:min(100%,28rem);height:.7rem}
      .stage-doc-meta{font-size:.92rem}
    </style>';
}

<?php
declare(strict_types=1);

require_once app_path('support/academic_policy.php');
require_once app_path('Stage/stage_allocation.php');

function stage_service_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    stage_module_ensure();
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_type (
          id_type INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(40) NOT NULL,
          libelle VARCHAR(120) NOT NULL,
          circuit VARCHAR(20) NOT NULL,
          active SMALLINT NOT NULL DEFAULT 1,
          PRIMARY KEY (id_type),
          UNIQUE (code)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_modele_categorie (
          id_categorie INT UNSIGNED NOT NULL AUTO_INCREMENT,
          libelle VARCHAR(160) NOT NULL,
          active SMALLINT NOT NULL DEFAULT 1,
          PRIMARY KEY (id_categorie),
          UNIQUE (libelle)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_demande (
          id_demande INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_campagne INT UNSIGNED NOT NULL,
          id_etudiant INT UNSIGNED NOT NULL,
          id_organisme INT UNSIGNED DEFAULT NULL,
          organisme_nom VARCHAR(180) NOT NULL DEFAULT '',
          ville VARCHAR(80) NOT NULL DEFAULT '',
          domaine VARCHAR(160) NOT NULL DEFAULT '',
          contact VARCHAR(180) NOT NULL DEFAULT '',
          date_debut DATE NOT NULL,
          date_fin DATE NOT NULL,
          missions VARCHAR(1000) NOT NULL DEFAULT '',
          statut VARCHAR(20) NOT NULL DEFAULT 'soumise',
          motif VARCHAR(500) NOT NULL DEFAULT '',
          stored_name VARCHAR(80) NOT NULL DEFAULT '',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_demande),
          UNIQUE (id_campagne, id_etudiant)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_modele (
          id_modele INT UNSIGNED NOT NULL AUTO_INCREMENT,
          nom VARCHAR(160) NOT NULL,
          description VARCHAR(500) NOT NULL DEFAULT '',
          id_type INT UNSIGNED DEFAULT NULL,
          id_program INT UNSIGNED DEFAULT NULL,
          id_spec INT UNSIGNED DEFAULT NULL,
          niveau VARCHAR(20) NOT NULL DEFAULT '',
          annee_label VARCHAR(20) NOT NULL DEFAULT '',
          id_categorie INT UNSIGNED DEFAULT NULL,
          stored_name VARCHAR(80) NOT NULL,
          file_size INT UNSIGNED NOT NULL DEFAULT 0,
          statut VARCHAR(20) NOT NULL DEFAULT 'actif',
          uploaded_by INT UNSIGNED DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_modele)
        )"
    );
    $columns = [
        'titre' => "ALTER TABLE stage_campagne ADD COLUMN titre VARCHAR(180) NOT NULL DEFAULT ''",
        'id_type' => 'ALTER TABLE stage_campagne ADD COLUMN id_type INT UNSIGNED DEFAULT NULL',
        'id_program' => 'ALTER TABLE stage_campagne ADD COLUMN id_program INT UNSIGNED DEFAULT NULL',
        'id_enseignant' => 'ALTER TABLE stage_campagne ADD COLUMN id_enseignant INT UNSIGNED DEFAULT NULL',
        'objectifs' => "ALTER TABLE stage_campagne ADD COLUMN objectifs VARCHAR(1000) NOT NULL DEFAULT ''",
    ];
    foreach ($columns as $name => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM stage_campagne LIKE '" . $name . "'")->fetch()) {
            $pdo->exec($sql);
        }
    }
    if (!$pdo->query("SHOW COLUMNS FROM stage_demande LIKE 'stored_name'")->fetch()) {
        $pdo->exec("ALTER TABLE stage_demande ADD COLUMN stored_name VARCHAR(80) NOT NULL DEFAULT ''");
    }
    $typeCount = (int) $pdo->query('SELECT COUNT(*) FROM stage_type')->fetchColumn();
    if ($typeCount === 0) {
        $insert = $pdo->prepare('INSERT INTO stage_type (code, libelle, circuit) VALUES (?, ?, ?)');
        $insert->execute(['obligatoire', 'Stage d’études obligatoire', 'faculte']);
        $insert->execute(['ete', 'Stage d’été', 'etudiant']);
    }
    $categoryCount = (int) $pdo->query('SELECT COUNT(*) FROM stage_modele_categorie')->fetchColumn();
    if ($categoryCount === 0) {
        $insert = $pdo->prepare('INSERT INTO stage_modele_categorie (libelle) VALUES (?)');
        foreach ([
            'Demande de stage',
            'Convention',
            'Lettre d’affectation',
            'Fiche de présence',
            'Grille d’évaluation',
            'Rapport de stage',
            'Attestation de stage',
            'Autre document',
        ] as $label) {
            $insert->execute([$label]);
        }
    }
}

function stage_service_types(): array
{
    stage_service_ensure();
    return db()->query('SELECT * FROM stage_type WHERE active = 1 ORDER BY libelle')->fetchAll();
}

function stage_service_type(int $id): ?array
{
    foreach (stage_service_types() as $type) {
        if ((int) $type['id_type'] === $id) {
            return $type;
        }
    }
    return null;
}

function stage_service_doc_categories(): array
{
    stage_service_ensure();
    return db()->query('SELECT * FROM stage_modele_categorie WHERE active = 1 ORDER BY libelle')->fetchAll();
}

function stage_service_years(): array
{
    stage_service_ensure();
    academic_policy_ensure();
    $rows = db()->query(
        "SELECT label FROM academic_year
         UNION
         SELECT annee_label FROM stage_campagne WHERE annee_label <> ''
         ORDER BY 1 DESC"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(array_map('strval', $rows)));
}

function stage_service_specialites(?int $programId = null): array
{
    stage_service_ensure();
    academic_policy_ensure();
    if ($programId) {
        $query = db()->prepare('SELECT * FROM specialite WHERE active = 1 AND (id_program = ? OR id_program IS NULL) ORDER BY nom_spec');
        $query->execute([$programId]);
        return $query->fetchAll();
    }
    return db()->query('SELECT * FROM specialite WHERE active = 1 ORDER BY nom_spec')->fetchAll();
}

function stage_service_teachers(): array
{
    return db()->query('SELECT id_enseignant, nom, prenom FROM enseignant WHERE active = 1 ORDER BY nom, prenom')->fetchAll();
}

function stage_service_hosts(): array
{
    stage_service_ensure();
    return db()->query(
        'SELECT o.*, c.nom AS categorie
         FROM stage_organisme o
         LEFT JOIN stage_categorie c ON c.id_categorie = o.id_categorie
         WHERE o.active = 1
         ORDER BY o.nom'
    )->fetchAll();
}

function stage_service_services(int $hostId = 0): array
{
    stage_service_ensure();
    if ($hostId > 0) {
        $query = db()->prepare('SELECT * FROM stage_service WHERE active = 1 AND id_organisme = ? ORDER BY nom');
        $query->execute([$hostId]);
        return $query->fetchAll();
    }
    return db()->query('SELECT * FROM stage_service WHERE active = 1 ORDER BY nom')->fetchAll();
}

/** @return array{rows:list<array>, total:int} */
function stage_service_students(int $specId, string $level, string $search, int $page, int $perPage = 20): array
{
    $where = ['e.statut = ?'];
    $params = ['actif'];
    if ($specId > 0) {
        $where[] = 'e.id_spec = ?';
        $params[] = $specId;
    }
    if ($level !== '') {
        $where[] = 'e.niveau = ?';
        $params[] = $level;
    }
    if ($search !== '') {
        $where[] = '(e.nom LIKE ? OR e.prenom LIKE ? OR e.cin_passeport LIKE ? OR e.num_inscription LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);
    $count = db()->prepare('SELECT COUNT(*) FROM etudiant e ' . $sqlWhere);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $offset = max(0, ($page - 1) * $perPage);
    $query = db()->prepare(
        'SELECT e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.num_inscription, e.niveau, s.nom_spec
         FROM etudiant e
         LEFT JOIN specialite s ON s.id_spec = e.id_spec
         ' . $sqlWhere . '
         ORDER BY e.nom, e.prenom
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $query->execute($params);
    return ['rows' => $query->fetchAll(), 'total' => $total];
}

function stage_service_dashboard(string $year, int $programId, int $specId): array
{
    stage_service_ensure();
    $where = ['1=1'];
    $params = [];
    if ($year !== '') {
        $where[] = 'c.annee_label = ?';
        $params[] = $year;
    }
    if ($programId > 0) {
        $where[] = 'c.id_program = ?';
        $params[] = $programId;
    }
    if ($specId > 0) {
        $where[] = 'c.id_spec = ?';
        $params[] = $specId;
    }
    $filter = implode(' AND ', $where);
    $campaigns = db()->prepare('SELECT COUNT(*) FROM stage_campagne c WHERE ' . $filter);
    $campaigns->execute($params);
    $byType = db()->prepare(
        'SELECT t.circuit, t.libelle, COUNT(p.id_placement) AS total
         FROM stage_type t
         LEFT JOIN stage_campagne c ON c.id_type = t.id_type AND ' . $filter . '
         LEFT JOIN stage_placement p ON p.id_campagne = c.id_campagne AND p.statut <> \'annule\'
         GROUP BY t.id_type, t.circuit, t.libelle
         ORDER BY t.libelle'
    );
    $byType->execute($params);
    $placements = db()->prepare(
        'SELECT p.statut, COUNT(*) AS total
         FROM stage_placement p
         LEFT JOIN stage_campagne c ON c.id_campagne = p.id_campagne
         WHERE ' . $filter . '
         GROUP BY p.statut'
    );
    $placements->execute($params);
    $hosts = (int) db()->query('SELECT COUNT(*) FROM stage_organisme WHERE active = 1')->fetchColumn();
    $models = (int) db()->query("SELECT COUNT(*) FROM stage_modele WHERE statut = 'actif'")->fetchColumn();
    $pending = (int) db()->query("SELECT COUNT(*) FROM stage_demande WHERE statut = 'soumise'")->fetchColumn();
    $students = db()->prepare(
        'SELECT COUNT(DISTINCT p.id_etudiant)
         FROM stage_placement p
         LEFT JOIN stage_campagne c ON c.id_campagne = p.id_campagne
         WHERE ' . $filter
    );
    $students->execute($params);
    $conventions = (int) db()->query('SELECT COUNT(*) FROM stage_organisme WHERE active = 1 AND (convention_fin IS NULL OR convention_fin >= CURRENT_DATE)')->fetchColumn();
    $statusRows = $placements->fetchAll();
    $byStatus = [];
    foreach ($statusRows as $row) {
        $byStatus[(string) $row['statut']] = (int) $row['total'];
    }
    $typeRows = $byType->fetchAll();
    $obligatoire = 0;
    $ete = 0;
    foreach ($typeRows as $row) {
        if ((string) $row['circuit'] === 'etudiant') {
            $ete += (int) $row['total'];
        } else {
            $obligatoire += (int) $row['total'];
        }
    }
    $totalStages = array_sum($byStatus);
    return [
        'campagnes' => (int) $campaigns->fetchColumn(),
        'types' => $typeRows,
        'statuts' => $statusRows,
        'stages' => $totalStages,
        'obligatoires' => $obligatoire,
        'ete' => $ete,
        'en_cours' => (int) ($byStatus['en_cours'] ?? 0) + (int) ($byStatus['affecte'] ?? 0),
        'termines' => (int) ($byStatus['termine'] ?? 0) + (int) ($byStatus['valide'] ?? 0),
        'etudiants' => (int) $students->fetchColumn(),
        'etablissements' => $hosts,
        'conventions' => $conventions,
        'documents' => $models,
        'demandes' => $pending,
    ];
}

function stage_service_save_campaign(array $input, int $actorId): int
{
    stage_service_ensure();
    $type = stage_service_type((int) ($input['id_type'] ?? 0));
    if ($type === null) {
        throw new InvalidArgumentException('Choisissez un type de stage enregistré.');
    }
    $title = trim((string) ($input['titre'] ?? ''));
    $year = trim((string) ($input['annee_label'] ?? ''));
    $start = (string) ($input['date_debut'] ?? '');
    $end = (string) ($input['date_fin'] ?? '');
    if ($title === '' || $year === '' || $start === '' || $end === '' || $end < $start) {
        throw new InvalidArgumentException('Indiquez l’intitulé, l’année et une période valide.');
    }
    $period = trim((string) ($input['periode_code'] ?? ''));
    if ($period !== '' && !academic_period_valid($period)) {
        throw new InvalidArgumentException('Ce semestre n’existe pas dans la structure académique.');
    }
    $id = (int) ($input['id_campagne'] ?? 0);
    $values = [
        $title,
        (int) $type['id_type'],
        $year,
        $period,
        $start,
        $end,
        (int) ($input['id_program'] ?? 0) ?: null,
        (int) ($input['id_spec'] ?? 0) ?: null,
        trim((string) ($input['niveau'] ?? '')),
        (int) ($input['id_enseignant'] ?? 0) ?: null,
        mb_substr(trim((string) ($input['objectifs'] ?? '')), 0, 1000),
        trim((string) ($input['statut'] ?? 'brouillon')),
        $actorId,
    ];
    if ($id > 0) {
        $values[] = $id;
        db()->prepare(
            'UPDATE stage_campagne
             SET titre = ?, id_type = ?, annee_label = ?, periode_code = ?, date_debut = ?, date_fin = ?,
                 id_program = ?, id_spec = ?, niveau = ?, id_enseignant = ?, objectifs = ?, statut = ?, created_by = COALESCE(created_by, ?)
             WHERE id_campagne = ?'
        )->execute($values);
        return $id;
    }
    db()->prepare(
        'INSERT INTO stage_campagne
         (titre, id_type, annee_label, periode_code, date_debut, date_fin, id_program, id_spec, niveau, id_enseignant, objectifs, statut, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute($values);
    return (int) db()->lastInsertId();
}

function stage_service_assign(int $campaignId, int $studentId, int $hostId, int $serviceId, int $teacherId, int $actorId): void
{
    stage_service_ensure();
    $campaign = db()->prepare('SELECT * FROM stage_campagne WHERE id_campagne = ?');
    $campaign->execute([$campaignId]);
    $campaignRow = $campaign->fetch();
    if (!$campaignRow) {
        throw new InvalidArgumentException('Campagne introuvable.');
    }
    $student = db()->prepare('SELECT id_etudiant, id_spec, niveau FROM etudiant WHERE id_etudiant = ? AND statut = ?');
    $student->execute([$studentId, 'actif']);
    if (!$student->fetch()) {
        throw new InvalidArgumentException('Cet étudiant n’est pas disponible pour une affectation.');
    }
    if ($serviceId > 0) {
        $capacity = db()->prepare('SELECT places FROM stage_capacite WHERE id_campagne = ? AND id_service = ?');
        $capacity->execute([$campaignId, $serviceId]);
        $places = $capacity->fetchColumn();
        if ($places !== false) {
            $used = db()->prepare('SELECT COUNT(*) FROM stage_placement WHERE id_campagne = ? AND id_service = ? AND statut <> ?');
            $used->execute([$campaignId, $serviceId, 'annule']);
            if ((int) $used->fetchColumn() >= (int) $places) {
                throw new InvalidArgumentException('La capacité d’accueil de ce service est atteinte.');
            }
        }
    }
    $exists = db()->prepare('SELECT 1 FROM stage_placement WHERE id_campagne = ? AND id_etudiant = ? AND statut <> ?');
    $exists->execute([$campaignId, $studentId, 'annule']);
    if ($exists->fetchColumn()) {
        throw new InvalidArgumentException('Cet étudiant est déjà affecté dans cette campagne.');
    }
    $type = stage_service_type((int) ($campaignRow['id_type'] ?? 0));
    $pdo = db();
    $pdo->beginTransaction();
    try {
    $pdo->prepare(
        'INSERT INTO stage_placement
         (id_etudiant, id_organisme, id_stage_superviseur, id_program, id_service, id_campagne, annee_label, periode_code, type_stage, date_debut, date_fin, objectifs, statut, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $studentId,
        $hostId,
        $teacherId ?: null,
        $campaignRow['id_program'] ?: null,
        $serviceId ?: null,
        $campaignId,
        $campaignRow['annee_label'],
        $campaignRow['periode_code'],
        $type['libelle'] ?? '',
        $campaignRow['date_debut'],
        $campaignRow['date_fin'],
        $campaignRow['objectifs'] ?? '',
        'affecte',
        $actorId,
    ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function stage_service_model_dir(): string
{
    $dir = storage_path('stage-modeles');
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Le stockage des modèles est inaccessible.');
    }
    return $dir;
}

function stage_service_store_pdf(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Le fichier PDF n’a pas pu être reçu.');
    }
    $max = 15 * 1048576;
    $setting = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $setting->execute(['stage_pdf_max_mb']);
    $configured = (int) $setting->fetchColumn();
    if ($configured > 0) {
        $max = $configured * 1048576;
    }
    $size = (int) filesize($tmp);
    if ($size < 1 || $size > $max) {
        throw new InvalidArgumentException('Le PDF dépasse la taille autorisée.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $head = (string) file_get_contents($tmp, false, null, 0, 5);
    if ($mime !== 'application/pdf' || $head !== '%PDF-') {
        throw new InvalidArgumentException('Seuls les fichiers PDF sont acceptés.');
    }
    $stored = bin2hex(random_bytes(16)) . '.pdf';
    $target = stage_service_model_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Le modèle n’a pas pu être enregistré.');
    }
    @chmod($target, 0640);
    return ['stored' => $stored, 'size' => $size];
}

function stage_service_model_path(string $stored): string
{
    if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $stored)) {
        throw new InvalidArgumentException('Document introuvable.');
    }
    $root = realpath(stage_service_model_dir());
    $path = realpath(stage_service_model_dir() . DIRECTORY_SEPARATOR . $stored);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        throw new InvalidArgumentException('Document introuvable.');
    }
    return $path;
}

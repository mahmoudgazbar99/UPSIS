<?php
declare(strict_types=1);

function access_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS scolarite_user (
          id_scolarite INT UNSIGNED NOT NULL AUTO_INCREMENT,
          username VARCHAR(80) NOT NULL,
          nom VARCHAR(120) NOT NULL DEFAULT '',
          password VARCHAR(255) NOT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          auth_generation INT UNSIGNED NOT NULL DEFAULT 1,
          PRIMARY KEY (id_scolarite),
          UNIQUE KEY uq_scolarite_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (db_table_exists($pdo, 'stage_user')) {
        $cin = $pdo->query("SHOW COLUMNS FROM stage_user LIKE 'cin'")->fetch();
        if ($cin && !str_contains(strtolower((string) $cin['Type']), 'varchar(40)')) {
            $pdo->exec('ALTER TABLE stage_user MODIFY cin VARCHAR(40) NOT NULL');
        }
        if (!$pdo->query("SHOW COLUMNS FROM stage_user LIKE 'responsabilite'")->fetch()) {
            $pdo->exec("ALTER TABLE stage_user ADD COLUMN responsabilite VARCHAR(20) NOT NULL DEFAULT 'officer' AFTER cin");
        }
        if (!$pdo->query("SHOW COLUMNS FROM stage_user LIKE 'nom'")->fetch()) {
            $pdo->exec("ALTER TABLE stage_user ADD COLUMN nom VARCHAR(120) NOT NULL DEFAULT '' AFTER cin");
        }
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_categorie (
          id_categorie INT UNSIGNED NOT NULL AUTO_INCREMENT,
          nom VARCHAR(80) NOT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id_categorie),
          UNIQUE KEY uq_stage_categorie (nom)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    foreach (['Hôpital', 'Clinique', 'Laboratoire', 'Entreprise', 'Administration', 'Autre'] as $name) {
        $pdo->prepare('INSERT IGNORE INTO stage_categorie (nom) VALUES (?)')->execute([$name]);
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_organisme (
          id_organisme INT UNSIGNED NOT NULL AUTO_INCREMENT,
          nom VARCHAR(180) NOT NULL,
          id_categorie INT UNSIGNED DEFAULT NULL,
          adresse VARCHAR(255) NOT NULL DEFAULT '',
          ville VARCHAR(80) NOT NULL DEFAULT '',
          contact_nom VARCHAR(120) NOT NULL DEFAULT '',
          email VARCHAR(150) NOT NULL DEFAULT '',
          telephone VARCHAR(40) NOT NULL DEFAULT '',
          domaines VARCHAR(255) NOT NULL DEFAULT '',
          capacite INT UNSIGNED NOT NULL DEFAULT 1,
          convention_debut DATE DEFAULT NULL,
          convention_fin DATE DEFAULT NULL,
          pieces_requises VARCHAR(255) NOT NULL DEFAULT '',
          notes TEXT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id_organisme),
          KEY idx_stage_org_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_placement (
          id_placement INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_etudiant INT UNSIGNED NOT NULL,
          id_organisme INT UNSIGNED NOT NULL,
          id_stage_superviseur INT UNSIGNED DEFAULT NULL,
          id_program INT UNSIGNED DEFAULT NULL,
          annee_label VARCHAR(20) NOT NULL DEFAULT '',
          periode_code VARCHAR(20) NOT NULL DEFAULT '',
          type_stage VARCHAR(80) NOT NULL DEFAULT '',
          service_nom VARCHAR(120) NOT NULL DEFAULT '',
          date_debut DATE NOT NULL,
          date_fin DATE NOT NULL,
          heures_prevues DECIMAL(6,1) NOT NULL DEFAULT 0,
          objectifs TEXT NULL,
          statut VARCHAR(20) NOT NULL DEFAULT 'brouillon',
          created_by INT UNSIGNED DEFAULT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id_placement),
          KEY idx_stage_student (id_etudiant, statut),
          KEY idx_stage_org (id_organisme, statut),
          KEY idx_stage_dates (date_debut, date_fin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_suivi (
          id_suivi INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_placement INT UNSIGNED NOT NULL,
          jour DATE NOT NULL,
          heures DECIMAL(5,1) NOT NULL DEFAULT 0,
          observation VARCHAR(500) NOT NULL,
          auteur_id INT UNSIGNED DEFAULT NULL,
          PRIMARY KEY (id_suivi),
          KEY idx_stage_suivi (id_placement, jour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_evaluation (
          id_evaluation INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_placement INT UNSIGNED NOT NULL,
          origine VARCHAR(20) NOT NULL,
          note_valeur DECIMAL(5,2) DEFAULT NULL,
          commentaire VARCHAR(1000) NOT NULL DEFAULT '',
          auteur_id INT UNSIGNED DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_evaluation),
          KEY idx_stage_eval (id_placement)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_document (
          id_document INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_placement INT UNSIGNED DEFAULT NULL,
          id_organisme INT UNSIGNED DEFAULT NULL,
          titre VARCHAR(160) NOT NULL,
          mime VARCHAR(80) NOT NULL,
          contenu LONGBLOB NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_document),
          KEY idx_stage_doc_placement (id_placement)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (db_table_exists($pdo, 'audit_log')) {
        $column = $pdo->query("SHOW COLUMNS FROM audit_log LIKE 'category'")->fetch();
        $type = (string) ($column['Type'] ?? '');
        if ($type !== '' && !str_contains($type, 'stage')) {
            $pdo->exec("ALTER TABLE audit_log MODIFY category ENUM('note','paiement','presence','compte','reglage','stage') NOT NULL");
        }
    }
}

function is_scolarite(): bool
{
    return ($_SESSION['user_role'] ?? '') === 'scolarite';
}

function current_scolarite_id(): int
{
    return (int) ($_SESSION['scolarite_id'] ?? 0);
}

function stage_duty(): string
{
    $duty = (string) ($_SESSION['stage_duty'] ?? 'officer');
    return in_array($duty, ['officer', 'coordinateur', 'superviseur'], true) ? $duty : 'officer';
}

function role_can(string $permission): bool
{
    if (is_admin()) {
        return true;
    }
    if (is_scolarite()) {
        return in_array($permission, [
            'students.view', 'students.create', 'students.edit', 'students.assign',
            'documents.issue', 'requests.manage', 'students.export',
        ], true);
    }
    if (is_stage()) {
        $granted = [
            'officer' => ['stage.view', 'stage.hosts', 'stage.place', 'stage.evaluate'],
            'coordinateur' => ['stage.view', 'stage.hosts', 'stage.place'],
            'superviseur' => ['stage.view', 'stage.evaluate'],
        ];
        return in_array($permission, $granted[stage_duty()] ?? [], true);
    }
    return false;
}

function require_permission(string $permission): void
{
    access_ensure();
    if (!logged_in()) {
        header('Location: login.php');
        exit;
    }
    if (is_scolarite()) {
        validate_scolarite_session();
    }
    if (is_stage()) {
        validate_stage_session();
    }
    if (!role_can($permission)) {
        http_response_code(403);
        exit('Action non autorisée pour ce compte.');
    }
}

function validate_scolarite_session(): void
{
    access_ensure();
    $statement = db()->prepare('SELECT auth_generation FROM scolarite_user WHERE id_scolarite = ? AND active = 1 LIMIT 1');
    $statement->execute([current_scolarite_id()]);
    $generation = $statement->fetchColumn();
    if ($generation === false || (int) $generation !== (int) ($_SESSION['scolarite_auth_generation'] ?? -1)) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

function stage_script_allowed(string $script): bool
{
    return in_array($script, [
        'stage_portal.php',
        'stage_organismes.php',
        'stage_placements.php',
        'stage_suivi.php',
        'stage_document.php',
        'logout.php',
    ], true);
}

function stage_statuses(): array
{
    return [
        'brouillon' => 'Brouillon',
        'en_attente' => 'En attente',
        'approuve' => 'Approuvé',
        'affecte' => 'Affecté',
        'en_cours' => 'En cours',
        'termine' => 'Terminé',
        'annule' => 'Annulé',
        'refuse' => 'Refusé',
    ];
}

function stage_status_allowed(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }
    $next = [
        'brouillon' => ['en_attente', 'annule'],
        'en_attente' => ['approuve', 'refuse', 'annule'],
        'approuve' => ['affecte', 'annule'],
        'affecte' => ['en_cours', 'annule'],
        'en_cours' => ['termine', 'annule'],
        'termine' => [],
        'annule' => [],
        'refuse' => [],
    ];
    return in_array($to, $next[$from] ?? [], true);
}

function stage_placement_visible(array $placement): bool
{
    if (!role_can('stage.view')) {
        return false;
    }
    if (stage_duty() !== 'superviseur') {
        return true;
    }
    return (int) ($placement['id_stage_superviseur'] ?? 0) === current_stage_id()
        || (int) ($placement['created_by'] ?? 0) === current_stage_id();
}

function stage_assert_placement(int $studentId, int $organismeId, string $start, string $end, int $ignoreId = 0): void
{
    if ($studentId < 1 || $organismeId < 1 || $start === '' || $end === '' || $end < $start) {
        throw new InvalidArgumentException('Choisissez un étudiant, un organisme et des dates valides.');
    }
    $student = db()->prepare('SELECT statut FROM etudiant WHERE id_etudiant = ?');
    $student->execute([$studentId]);
    if ((string) $student->fetchColumn() !== 'actif') {
        throw new InvalidArgumentException('Cet étudiant n’est pas en cours de scolarité.');
    }
    $org = db()->prepare('SELECT capacite, active, convention_fin FROM stage_organisme WHERE id_organisme = ?');
    $org->execute([$organismeId]);
    $host = $org->fetch();
    if (!$host || (int) $host['active'] !== 1) {
        throw new InvalidArgumentException('L’organisme d’accueil n’est pas actif.');
    }
    if (!empty($host['convention_fin']) && $start > (string) $host['convention_fin']) {
        throw new InvalidArgumentException('La convention de l’organisme n’est plus valable à la date de début.');
    }
    $count = db()->prepare(
        'SELECT COUNT(*) FROM stage_placement
         WHERE id_organisme = ? AND id_placement <> ? AND statut IN ("approuve","affecte","en_cours")'
    );
    $count->execute([$organismeId, $ignoreId]);
    if ((int) $count->fetchColumn() >= (int) $host['capacite']) {
        throw new InvalidArgumentException('Cet organisme n’a plus de place disponible.');
    }
    $overlap = db()->prepare(
        'SELECT COUNT(*) FROM stage_placement
         WHERE id_etudiant = ? AND id_placement <> ?
           AND statut IN ("approuve","affecte","en_cours")
           AND date_debut <= ? AND date_fin >= ?'
    );
    $overlap->execute([$studentId, $ignoreId, $end, $start]);
    if ((int) $overlap->fetchColumn() > 0) {
        throw new InvalidArgumentException('Cet étudiant a déjà un stage sur cette période.');
    }
}

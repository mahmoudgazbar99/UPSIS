<?php
declare(strict_types=1);

function stage_module_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_service (
          id_service INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_organisme INT UNSIGNED NOT NULL,
          nom VARCHAR(120) NOT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id_service),
          UNIQUE KEY uq_stage_service (id_organisme, nom),
          KEY idx_stage_service_org (id_organisme, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_campagne (
          id_campagne INT UNSIGNED NOT NULL AUTO_INCREMENT,
          annee_label VARCHAR(20) NOT NULL DEFAULT '',
          periode_code VARCHAR(20) NOT NULL DEFAULT '',
          date_debut DATE NOT NULL,
          date_fin DATE NOT NULL,
          id_spec INT UNSIGNED DEFAULT NULL,
          niveau VARCHAR(10) NOT NULL DEFAULT '',
          id_groupe INT UNSIGNED DEFAULT NULL,
          statut VARCHAR(20) NOT NULL DEFAULT 'brouillon',
          created_by INT UNSIGNED DEFAULT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id_campagne),
          KEY idx_stage_campagne_dates (date_debut, date_fin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_capacite (
          id_capacite INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_campagne INT UNSIGNED NOT NULL,
          id_service INT UNSIGNED NOT NULL,
          places INT UNSIGNED NOT NULL DEFAULT 0,
          id_stage_superviseur INT UNSIGNED DEFAULT NULL,
          PRIMARY KEY (id_capacite),
          UNIQUE KEY uq_stage_capacite (id_campagne, id_service)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_service_regle (
          id_regle INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_service INT UNSIGNED NOT NULL,
          id_spec INT UNSIGNED DEFAULT NULL,
          niveau VARCHAR(10) NOT NULL DEFAULT '',
          PRIMARY KEY (id_regle),
          KEY idx_stage_regle_service (id_service)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_proposition (
          id_proposition INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_campagne INT UNSIGNED NOT NULL,
          id_etudiant INT UNSIGNED NOT NULL,
          id_service INT UNSIGNED DEFAULT NULL,
          motif VARCHAR(255) NOT NULL DEFAULT '',
          statut VARCHAR(20) NOT NULL DEFAULT 'proposee',
          PRIMARY KEY (id_proposition),
          UNIQUE KEY uq_stage_proposition (id_campagne, id_etudiant)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_critere (
          id_critere INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(40) NOT NULL,
          libelle VARCHAR(180) NOT NULL,
          cible VARCHAR(20) NOT NULL,
          obligatoire TINYINT(1) NOT NULL DEFAULT 1,
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id_critere),
          UNIQUE KEY uq_stage_critere (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_eval_dossier (
          id_dossier INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_placement INT UNSIGNED NOT NULL,
          origine VARCHAR(20) NOT NULL,
          statut VARCHAR(20) NOT NULL DEFAULT 'brouillon',
          commentaire VARCHAR(1000) NOT NULL DEFAULT '',
          auteur_id INT UNSIGNED DEFAULT NULL,
          submitted_at DATETIME DEFAULT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id_dossier),
          UNIQUE KEY uq_stage_eval_dossier (id_placement, origine)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stage_eval_ligne (
          id_ligne INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_dossier INT UNSIGNED NOT NULL,
          id_critere INT UNSIGNED NOT NULL,
          appreciation VARCHAR(20) NOT NULL DEFAULT '',
          PRIMARY KEY (id_ligne),
          UNIQUE KEY uq_stage_eval_ligne (id_dossier, id_critere)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (db_table_exists($pdo, 'stage_placement') && !$pdo->query("SHOW COLUMNS FROM stage_placement LIKE 'id_service'")->fetch()) {
        $pdo->exec('ALTER TABLE stage_placement ADD COLUMN id_service INT UNSIGNED DEFAULT NULL AFTER id_organisme');
    }
    if (db_table_exists($pdo, 'stage_placement') && !$pdo->query("SHOW COLUMNS FROM stage_placement LIKE 'id_campagne'")->fetch()) {
        $pdo->exec('ALTER TABLE stage_placement ADD COLUMN id_campagne INT UNSIGNED DEFAULT NULL AFTER id_service');
    }
    $duplicateGuard = $pdo->prepare(
        "SELECT COUNT(*) FROM pg_indexes
         WHERE schemaname = current_schema() AND tablename = 'stage_placement'
           AND indexname IN ('uq_stage_campaign_student', 'stage_placement_uq_stage_campaign_student')"
    );
    $duplicateGuard->execute();
    if ((int) $duplicateGuard->fetchColumn() === 0 && db_table_exists($pdo, 'stage_placement')) {
        try {
            $pdo->exec('ALTER TABLE stage_placement ADD UNIQUE KEY uq_stage_campaign_student (id_campagne, id_etudiant)');
        } catch (Throwable $exception) {
            error_log('Stage campaign unique key skipped: ' . $exception->getMessage());
        }
    }
    $criteria = [
        ['pratique', 'Compétences pratiques', 'superviseur'],
        ['procedures', 'Application des instructions et des procédures', 'superviseur'],
        ['conduite', 'Conduite professionnelle', 'superviseur'],
        ['ponctualite', 'Ponctualité et assiduité', 'superviseur'],
        ['communication', 'Communication et travail en équipe', 'superviseur'],
        ['dossier', 'Dossier de stage et pièces requises', 'responsable'],
        ['suivi', 'Suivi du déroulement du stage', 'responsable'],
        ['decision', 'Appréciation institutionnelle', 'responsable'],
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO stage_critere (code, libelle, cible, obligatoire, active) VALUES (?, ?, ?, 1, 1)');
    foreach ($criteria as $row) {
        $insert->execute($row);
    }
}

function stage_is_manager(): bool
{
    return is_admin() || (is_stage() && stage_duty() !== 'superviseur');
}

function stage_occupying_statuses(): array
{
    return ['approuve', 'affecte', 'en_cours', 'revue', 'retour', 'valide', 'termine'];
}

function stage_service_matches_student(int $serviceId, int $specId, string $level): bool
{
    $rules = db()->prepare('SELECT id_spec, niveau FROM stage_service_regle WHERE id_service = ?');
    $rules->execute([$serviceId]);
    $rows = $rules->fetchAll();
    if (!$rows) {
        return true;
    }
    foreach ($rows as $rule) {
        $specOk = $rule['id_spec'] === null || (int) $rule['id_spec'] === $specId;
        $levelOk = (string) $rule['niveau'] === '' || (string) $rule['niveau'] === $level;
        if ($specOk && $levelOk) {
            return true;
        }
    }
    return false;
}

function stage_occupied_places(int $serviceId, string $start, string $end, int $ignorePlacement = 0): int
{
    $statuses = '"' . implode('","', stage_occupying_statuses()) . '"';
    $query = db()->prepare(
        "SELECT COUNT(*) FROM stage_placement
         WHERE id_service = ? AND id_placement <> ?
           AND statut IN ($statuses)
           AND date_debut <= ? AND date_fin >= ?"
    );
    $query->execute([$serviceId, $ignorePlacement, $end, $start]);
    return (int) $query->fetchColumn();
}

function stage_campaign_students(array $campaign): array
{
    $sql = 'SELECT e.id_etudiant, e.nom, e.prenom, e.num_inscription, e.id_spec, e.niveau, e.statut
            FROM etudiant e WHERE e.statut = "actif"';
    $params = [];
    if (!empty($campaign['id_spec'])) {
        $sql .= ' AND e.id_spec = ?';
        $params[] = (int) $campaign['id_spec'];
    }
    if ((string) ($campaign['niveau'] ?? '') !== '') {
        $sql .= ' AND e.niveau = ?';
        $params[] = (string) $campaign['niveau'];
    }
    if (!empty($campaign['id_groupe'])) {
        $sql .= ' AND EXISTS (SELECT 1 FROM groupe_etudiant ge WHERE ge.id_etudiant = e.id_etudiant AND ge.id_groupe = ?)';
        $params[] = (int) $campaign['id_groupe'];
    }
    $sql .= ' ORDER BY e.id_etudiant ASC';
    $query = db()->prepare($sql);
    $query->execute($params);
    return $query->fetchAll();
}

function stage_campaign_slots(array $campaign): array
{
    $query = db()->prepare(
        'SELECT c.id_service, c.places, c.id_stage_superviseur, s.nom AS service, s.id_organisme, o.nom AS organisme, o.capacite AS capacite_organisme, o.active
         FROM stage_capacite c
         JOIN stage_service s ON s.id_service = c.id_service AND s.active = 1
         JOIN stage_organisme o ON o.id_organisme = s.id_organisme AND o.active = 1
         WHERE c.id_campagne = ? AND c.places > 0
         ORDER BY c.id_service ASC'
    );
    $query->execute([(int) $campaign['id_campagne']]);
    $slots = [];
    foreach ($query->fetchAll() as $row) {
        $occupied = stage_occupied_places((int) $row['id_service'], (string) $campaign['date_debut'], (string) $campaign['date_fin']);
        $orgUsed = stage_organism_occupied((int) $row['id_organisme'], (string) $campaign['date_debut'], (string) $campaign['date_fin']);
        $departmentLeft = max(0, (int) $row['places'] - $occupied);
        $organismLeft = max(0, (int) $row['capacite_organisme'] - $orgUsed);
        $row['occupees'] = $occupied;
        $row['restantes'] = min($departmentLeft, $organismLeft);
        $slots[(int) $row['id_service']] = $row;
    }
    return $slots;
}

function stage_organism_occupied(int $organismeId, string $start, string $end, int $ignorePlacement = 0): int
{
    $statuses = '"' . implode('","', stage_occupying_statuses()) . '"';
    $query = db()->prepare(
        "SELECT COUNT(*) FROM stage_placement
         WHERE id_organisme = ? AND id_placement <> ?
           AND statut IN ($statuses)
           AND date_debut <= ? AND date_fin >= ?"
    );
    $query->execute([$organismeId, $ignorePlacement, $end, $start]);
    return (int) $query->fetchColumn();
}

function stage_student_overlap(int $studentId, string $start, string $end, int $ignorePlacement = 0): bool
{
    $statuses = '"' . implode('","', stage_occupying_statuses()) . '"';
    $query = db()->prepare(
        "SELECT COUNT(*) FROM stage_placement
         WHERE id_etudiant = ? AND id_placement <> ?
           AND statut IN ($statuses)
           AND date_debut <= ? AND date_fin >= ?"
    );
    $query->execute([$studentId, $ignorePlacement, $end, $start]);
    return (int) $query->fetchColumn() > 0;
}

/**
 * Proposition déterministe.
 * Les étudiants sont parcourus par id_etudiant croissant.
 * À chaque étudiant, le service compatible qui a le plus de places restantes est choisi.
 * À places égales, le plus petit id_service l’emporte.
 * Les étudiants sans place restent dans la proposition avec le motif.
 */
function stage_campaign_propose(int $campaignId, bool $keepPublished = false): array
{
    $campaign = stage_campaign($campaignId);
    if (!$campaign) {
        throw new InvalidArgumentException('Cette campagne est introuvable.');
    }
    if ($campaign['statut'] === 'publiee' && !$keepPublished) {
        throw new InvalidArgumentException('Cette campagne est déjà publiée. Confirmez une nouvelle proposition pour les étudiants encore sans affectation.');
    }
    if ($campaign['date_fin'] < $campaign['date_debut']) {
        throw new InvalidArgumentException('Les dates de la campagne sont invalides.');
    }
    $students = stage_campaign_students($campaign);
    $slots = stage_campaign_slots($campaign);
    $assigned = [];
    if ($keepPublished) {
        $existing = db()->prepare('SELECT id_etudiant FROM stage_placement WHERE id_campagne = ? AND statut NOT IN ("annule","refuse","brouillon","en_attente")');
        $existing->execute([$campaignId]);
        foreach ($existing->fetchAll() as $row) {
            $assigned[(int) $row['id_etudiant']] = true;
        }
    }
    $lines = [];
    foreach ($students as $student) {
        $studentId = (int) $student['id_etudiant'];
        if (isset($assigned[$studentId]) || stage_student_overlap($studentId, (string) $campaign['date_debut'], (string) $campaign['date_fin'])) {
            $lines[] = ['id_etudiant' => $studentId, 'id_service' => null, 'motif' => 'Déjà affecté sur une période qui chevauche ces dates.'];
            continue;
        }
        $compatible = [];
        $eligibleFull = false;
        foreach ($slots as $serviceId => $slot) {
            if (!stage_service_matches_student($serviceId, (int) $student['id_spec'], (string) $student['niveau'])) {
                continue;
            }
            $eligibleFull = true;
            if ((int) $slot['restantes'] > 0) {
                $compatible[$serviceId] = $slot;
            }
        }
        if (!$compatible) {
            $lines[] = [
                'id_etudiant' => $studentId,
                'id_service' => null,
                'motif' => $eligibleFull
                    ? 'Tous les services compatibles ont atteint leur capacité sur cette période.'
                    : 'Aucun service de cette campagne n’accepte la spécialité ou le niveau de l’étudiant.',
            ];
            continue;
        }
        uasort($compatible, static function (array $a, array $b): int {
            $left = (int) $b['restantes'] <=> (int) $a['restantes'];
            return $left !== 0 ? $left : ((int) $a['id_service'] <=> (int) $b['id_service']);
        });
        $chosen = (int) array_key_first($compatible);
        $slots[$chosen]['restantes']--;
        $lines[] = ['id_etudiant' => $studentId, 'id_service' => $chosen, 'motif' => ''];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM stage_proposition WHERE id_campagne = ? AND statut <> "publiee"')->execute([$campaignId]);
        $insert = $pdo->prepare('INSERT INTO stage_proposition (id_campagne, id_etudiant, id_service, motif, statut) VALUES (?, ?, ?, ?, "proposee")');
        foreach ($lines as $line) {
            if ($keepPublished && isset($assigned[(int) $line['id_etudiant']])) {
                continue;
            }
            $insert->execute([$campaignId, $line['id_etudiant'], $line['id_service'], $line['motif']]);
        }
        $pdo->prepare('UPDATE stage_campagne SET statut = "proposee" WHERE id_campagne = ? AND statut <> "publiee"')->execute([$campaignId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    audit_record('stage', 'proposition', 'Proposition de la campagne #' . $campaignId . ' · ' . count($lines) . ' étudiants');
    return stage_campaign_report($campaignId);
}

function stage_campaign(int $campaignId): ?array
{
    $query = db()->prepare('SELECT * FROM stage_campagne WHERE id_campagne = ?');
    $query->execute([$campaignId]);
    $row = $query->fetch();
    return $row ?: null;
}

function stage_campaign_report(int $campaignId): array
{
    $campaign = stage_campaign($campaignId);
    $students = $campaign ? stage_campaign_students($campaign) : [];
    $slots = $campaign ? stage_campaign_slots($campaign) : [];
    $query = db()->prepare(
        'SELECT p.*, e.nom, e.prenom, e.num_inscription, e.id_spec, e.niveau, s.nom AS service, o.nom AS organisme
         FROM stage_proposition p
         JOIN etudiant e ON e.id_etudiant = p.id_etudiant
         LEFT JOIN stage_service s ON s.id_service = p.id_service
         LEFT JOIN stage_organisme o ON o.id_organisme = s.id_organisme
         WHERE p.id_campagne = ?
         ORDER BY e.id_etudiant'
    );
    $query->execute([$campaignId]);
    $lines = $query->fetchAll();
    $allocated = 0;
    foreach ($lines as $line) {
        if (!empty($line['id_service']) && $line['motif'] === '') {
            $allocated++;
            if (isset($slots[(int) $line['id_service']])) {
                $slots[(int) $line['id_service']]['proposees'] = ($slots[(int) $line['id_service']]['proposees'] ?? 0) + 1;
            }
        }
    }
    return [
        'campagne' => $campaign,
        'selectionnes' => count($students),
        'eligibles' => count($students),
        'affectes' => $allocated,
        'non_affectes' => count($lines) - $allocated,
        'lignes' => $lines,
        'services' => array_values($slots),
    ];
}

function stage_campaign_publish(int $campaignId, int $actorId): array
{
    $campaign = stage_campaign($campaignId);
    if (!$campaign) {
        throw new InvalidArgumentException('Cette campagne est introuvable.');
    }
    $report = stage_campaign_report($campaignId);
    $proposed = array_values(array_filter($report['lignes'], static fn (array $line): bool => !empty($line['id_service']) && $line['motif'] === '' && $line['statut'] !== 'publiee'));
    if (!$proposed) {
        return ['publiees' => 0, 'bloques' => []];
    }
    $pdo = db();
    $pdo->beginTransaction();
    $published = 0;
    $blocked = [];
    try {
        $capacity = $pdo->prepare('SELECT id_capacite, places, id_service, id_stage_superviseur FROM stage_capacite WHERE id_campagne = ? AND id_service = ? FOR UPDATE');
        $service = $pdo->prepare('SELECT s.id_organisme, o.capacite, o.active, o.convention_fin, s.active AS service_active FROM stage_service s JOIN stage_organisme o ON o.id_organisme = s.id_organisme WHERE s.id_service = ?');
        $insert = $pdo->prepare(
            'INSERT INTO stage_placement (id_etudiant, id_organisme, id_service, id_campagne, id_stage_superviseur, annee_label, periode_code, date_debut, date_fin, statut, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "affecte", ?)'
        );
        $mark = $pdo->prepare('UPDATE stage_proposition SET statut = "publiee" WHERE id_proposition = ?');
        foreach ($proposed as $line) {
            $capacity->execute([$campaignId, (int) $line['id_service']]);
            $seat = $capacity->fetch();
            $service->execute([(int) $line['id_service']]);
            $host = $service->fetch();
            $student = $pdo->prepare('SELECT statut, id_spec, niveau FROM etudiant WHERE id_etudiant = ? FOR UPDATE');
            $student->execute([(int) $line['id_etudiant']]);
            $person = $student->fetch();
            $reason = '';
            if (!$seat || !$host || (int) $host['active'] !== 1 || (int) $host['service_active'] !== 1) {
                $reason = 'Le service n’est plus disponible.';
            } elseif ((string) ($person['statut'] ?? '') !== 'actif') {
                $reason = 'L’étudiant n’est plus en cours de scolarité.';
            } elseif (!stage_service_matches_student((int) $line['id_service'], (int) ($person['id_spec'] ?? 0), (string) ($person['niveau'] ?? ''))) {
                $reason = 'Le service n’accepte plus ce profil.';
            } elseif (!empty($host['convention_fin']) && (string) $campaign['date_debut'] > (string) $host['convention_fin']) {
                $reason = 'La convention de l’organisme n’est plus valable.';
            } elseif (stage_student_overlap((int) $line['id_etudiant'], (string) $campaign['date_debut'], (string) $campaign['date_fin'])) {
                $reason = 'L’étudiant a déjà un stage sur cette période.';
            } else {
                $used = stage_occupied_places((int) $line['id_service'], (string) $campaign['date_debut'], (string) $campaign['date_fin']);
                $orgUsed = stage_organism_occupied((int) $host['id_organisme'], (string) $campaign['date_debut'], (string) $campaign['date_fin']);
                if ($used >= (int) $seat['places'] || $orgUsed >= (int) $host['capacite']) {
                    $reason = 'La capacité a changé depuis la proposition.';
                }
            }
            if ($reason !== '') {
                $blocked[] = ['id_etudiant' => (int) $line['id_etudiant'], 'motif' => $reason];
                $pdo->prepare('UPDATE stage_proposition SET id_service = NULL, motif = ?, statut = "proposee" WHERE id_proposition = ?')
                    ->execute([$reason, (int) $line['id_proposition']]);
                continue;
            }
            $insert->execute([
                (int) $line['id_etudiant'],
                (int) $host['id_organisme'],
                (int) $line['id_service'],
                $campaignId,
                $seat['id_stage_superviseur'] ?: null,
                (string) $campaign['annee_label'],
                (string) $campaign['periode_code'],
                (string) $campaign['date_debut'],
                (string) $campaign['date_fin'],
                $actorId,
            ]);
            $mark->execute([(int) $line['id_proposition']]);
            $published++;
        }
        if ($published > 0) {
            $pdo->prepare('UPDATE stage_campagne SET statut = "publiee" WHERE id_campagne = ?')->execute([$campaignId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    audit_record('stage', 'publier', 'Campagne #' . $campaignId . ' · ' . $published . ' affectations publiées');
    return ['publiees' => $published, 'bloques' => $blocked];
}

function stage_move_placement(int $placementId, int $serviceId, string $reason, int $actorId): void
{
    if (trim($reason) === '') {
        throw new InvalidArgumentException('Indiquez le motif du changement.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $current = $pdo->prepare('SELECT * FROM stage_placement WHERE id_placement = ? FOR UPDATE');
        $current->execute([$placementId]);
        $placement = $current->fetch();
        if (!$placement || !in_array((string) $placement['statut'], ['affecte', 'en_cours', 'revue', 'retour'], true)) {
            throw new InvalidArgumentException('Cette affectation ne peut plus être déplacée.');
        }
        $seat = $pdo->prepare('SELECT places, id_stage_superviseur FROM stage_capacite WHERE id_campagne = ? AND id_service = ? FOR UPDATE');
        $seat->execute([(int) $placement['id_campagne'], $serviceId]);
        $capacity = $seat->fetch();
        $host = $pdo->prepare('SELECT s.id_organisme, s.active, o.active AS org_active, o.capacite, o.convention_fin FROM stage_service s JOIN stage_organisme o ON o.id_organisme = s.id_organisme WHERE s.id_service = ?');
        $host->execute([$serviceId]);
        $service = $host->fetch();
        $student = $pdo->prepare('SELECT statut, id_spec, niveau FROM etudiant WHERE id_etudiant = ?');
        $student->execute([(int) $placement['id_etudiant']]);
        $person = $student->fetch();
        if (!$capacity || !$service || (int) $service['active'] !== 1 || (int) $service['org_active'] !== 1 || (string) ($person['statut'] ?? '') !== 'actif') {
            throw new InvalidArgumentException('Le service ou l’étudiant n’est pas éligible.');
        }
        if (!stage_service_matches_student($serviceId, (int) $person['id_spec'], (string) $person['niveau'])) {
            throw new InvalidArgumentException('Ce service n’accepte pas le profil de l’étudiant.');
        }
        if (!empty($service['convention_fin']) && (string) $placement['date_debut'] > (string) $service['convention_fin']) {
            throw new InvalidArgumentException('La convention de l’organisme n’est plus valable.');
        }
        $used = stage_occupied_places($serviceId, (string) $placement['date_debut'], (string) $placement['date_fin'], $placementId);
        $orgUsed = stage_organism_occupied((int) $service['id_organisme'], (string) $placement['date_debut'], (string) $placement['date_fin'], $placementId);
        if ($used >= (int) $capacity['places'] || $orgUsed >= (int) $service['capacite']) {
            throw new InvalidArgumentException('Ce service n’a plus de place sur cette période.');
        }
        $pdo->prepare('UPDATE stage_placement SET id_service = ?, id_organisme = ?, id_stage_superviseur = ? WHERE id_placement = ?')
            ->execute([$serviceId, (int) $service['id_organisme'], $capacity['id_stage_superviseur'] ?: null, $placementId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    audit_record(
        'stage',
        'deplacer',
        'Stage #' . $placementId . ' étudiant #' . (int) $placement['id_etudiant']
        . ' service ' . (int) ($placement['id_service'] ?? 0) . ' → ' . $serviceId . ' · ' . trim($reason)
    );
}

function stage_criteria(string $target): array
{
    $query = db()->prepare('SELECT * FROM stage_critere WHERE cible = ? AND active = 1 ORDER BY id_critere');
    $query->execute([$target]);
    return $query->fetchAll();
}

function stage_eval_dossier(int $placementId, string $origin): ?array
{
    $query = db()->prepare('SELECT * FROM stage_eval_dossier WHERE id_placement = ? AND origine = ?');
    $query->execute([$placementId, $origin]);
    $row = $query->fetch();
    if (!$row) {
        return null;
    }
    $lines = db()->prepare('SELECT id_critere, appreciation FROM stage_eval_ligne WHERE id_dossier = ?');
    $lines->execute([(int) $row['id_dossier']]);
    $row['lignes'] = [];
    foreach ($lines->fetchAll() as $line) {
        $row['lignes'][(int) $line['id_critere']] = (string) $line['appreciation'];
    }
    return $row;
}

function stage_eval_save(int $placementId, string $origin, array $appreciations, string $comment, int $actorId, bool $submit): void
{
    if (!in_array($origin, ['superviseur', 'responsable'], true)) {
        throw new InvalidArgumentException('Origine d’évaluation inconnue.');
    }
    $allowed = ['acquis', 'en_cours', 'non_acquis'];
    $criteria = stage_criteria($origin);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = stage_eval_dossier($placementId, $origin);
        if ($existing && in_array((string) $existing['statut'], ['soumis', 'valide'], true)) {
            throw new InvalidArgumentException('Cette évaluation est verrouillée. Elle doit être renvoyée avant toute modification.');
        }
        if ($existing) {
            $dossierId = (int) $existing['id_dossier'];
            $pdo->prepare('UPDATE stage_eval_dossier SET commentaire = ?, auteur_id = ?, statut = ? WHERE id_dossier = ?')
                ->execute([mb_substr($comment, 0, 1000), $actorId, $submit ? 'soumis' : 'brouillon', $dossierId]);
        } else {
            $pdo->prepare('INSERT INTO stage_eval_dossier (id_placement, origine, statut, commentaire, auteur_id, submitted_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$placementId, $origin, $submit ? 'soumis' : 'brouillon', mb_substr($comment, 0, 1000), $actorId, $submit ? date('Y-m-d H:i:s') : null]);
            $dossierId = (int) $pdo->lastInsertId();
        }
        if ($submit && $existing) {
            $pdo->prepare('UPDATE stage_eval_dossier SET submitted_at = ? WHERE id_dossier = ?')->execute([date('Y-m-d H:i:s'), $dossierId]);
        }
        $write = $pdo->prepare('INSERT INTO stage_eval_ligne (id_dossier, id_critere, appreciation) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE appreciation = VALUES(appreciation)');
        foreach ($criteria as $criterion) {
            $value = (string) ($appreciations[(int) $criterion['id_critere']] ?? '');
            if ($value !== '' && !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException('Appréciation inconnue.');
            }
            if ($submit && (int) $criterion['obligatoire'] === 1 && $value === '') {
                throw new InvalidArgumentException('Renseignez tous les critères obligatoires avant l’envoi.');
            }
            if ($value !== '') {
                $write->execute([$dossierId, (int) $criterion['id_critere'], $value]);
            }
        }
        if ($submit && $origin === 'superviseur') {
            $pdo->prepare('UPDATE stage_placement SET statut = "revue" WHERE id_placement = ? AND statut IN ("affecte","en_cours","retour")')->execute([$placementId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    audit_record('stage', $submit ? 'evaluation_envoi' : 'evaluation', 'Évaluation ' . $origin . ' du stage #' . $placementId);
}

function stage_eval_return(int $placementId, int $actorId, string $comment): void
{
    $dossier = stage_eval_dossier($placementId, 'superviseur');
    if (!$dossier || (string) $dossier['statut'] !== 'soumis') {
        throw new InvalidArgumentException('Aucune évaluation d’encadrant n’est en attente de revue.');
    }
    db()->prepare('UPDATE stage_eval_dossier SET statut = "retourne", commentaire = ?, auteur_id = ? WHERE id_dossier = ?')
        ->execute([mb_substr($comment, 0, 1000), $actorId, (int) $dossier['id_dossier']]);
    db()->prepare('UPDATE stage_placement SET statut = "retour" WHERE id_placement = ? AND statut = "revue"')->execute([$placementId]);
    audit_record('stage', 'evaluation_retour', 'Stage #' . $placementId . ' renvoyé à l’encadrant');
}

function stage_eval_validate(int $placementId, int $actorId): void
{
    $supervisor = stage_eval_dossier($placementId, 'superviseur');
    $manager = stage_eval_dossier($placementId, 'responsable');
    if (!$supervisor || (string) $supervisor['statut'] !== 'soumis') {
        throw new InvalidArgumentException('L’évaluation de l’encadrant doit être envoyée avant la validation.');
    }
    if (!$manager || !in_array((string) $manager['statut'], ['soumis', 'brouillon'], true)) {
        throw new InvalidArgumentException('Le responsable doit d’abord enregistrer sa propre évaluation.');
    }
    foreach (stage_criteria('responsable') as $criterion) {
        if ((int) $criterion['obligatoire'] === 1 && empty($manager['lignes'][(int) $criterion['id_critere']])) {
            throw new InvalidArgumentException('L’évaluation du responsable est incomplète.');
        }
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE stage_eval_dossier SET statut = "soumis", submitted_at = COALESCE(submitted_at, NOW()), auteur_id = ? WHERE id_dossier = ?')
            ->execute([$actorId, (int) $manager['id_dossier']]);
        $pdo->prepare('UPDATE stage_eval_dossier SET statut = "valide" WHERE id_placement = ? AND origine = "superviseur"')->execute([$placementId]);
        $pdo->prepare('UPDATE stage_placement SET statut = "valide" WHERE id_placement = ? AND statut IN ("revue","en_cours","affecte","retour")')->execute([$placementId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    audit_record('stage', 'valider', 'Stage #' . $placementId . ' validé par le responsable');
}

function stage_student_published(int $studentId): array
{
    if (!db_table_exists(db(), 'stage_placement')) {
        return [];
    }
    stage_module_ensure();
    $query = db()->prepare(
        'SELECT p.id_placement, p.annee_label, p.periode_code, p.date_debut, p.date_fin, p.statut, o.nom AS organisme, s.nom AS service
         FROM stage_placement p
         JOIN stage_organisme o ON o.id_organisme = p.id_organisme
         LEFT JOIN stage_service s ON s.id_service = p.id_service
         WHERE p.id_etudiant = ? AND p.statut IN ("affecte","en_cours","revue","retour","valide","termine")
         ORDER BY p.date_debut DESC'
    );
    $query->execute([$studentId]);
    return $query->fetchAll();
}

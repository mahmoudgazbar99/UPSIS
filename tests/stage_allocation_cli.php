<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';
access_ensure();
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
};
$pdo = db();
$group = $pdo->query(
    'SELECT ge.id_groupe, COUNT(*) AS effectif
     FROM groupe_etudiant ge
     JOIN etudiant e ON e.id_etudiant = ge.id_etudiant AND e.statut = "actif"
     GROUP BY ge.id_groupe
     HAVING COUNT(*) >= 2
     ORDER BY effectif ASC
     LIMIT 1'
)->fetch();
if (!$group) {
    echo "FAIL no active group with two students\n";
    exit(1);
}
$groupId = (int) $group['id_groupe'];
$population = (int) $group['effectif'];
$marker = 'CLI stage ' . bin2hex(random_bytes(3));
$orgId = 0;
$serviceA = 0;
$serviceB = 0;
$campaignId = 0;
try {
    $pdo->prepare('INSERT INTO stage_organisme (nom, capacite, active) VALUES (?, ?, 1)')->execute([$marker, $population + 2]);
    $orgId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_service (id_organisme, nom) VALUES (?, "Soins A")')->execute([$orgId]);
    $serviceA = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_service (id_organisme, nom) VALUES (?, "Soins B")')->execute([$orgId]);
    $serviceB = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO stage_campagne (annee_label, date_debut, date_fin, id_groupe, statut) VALUES ("2099-2100", "2099-01-01", "2099-01-31", ?, "brouillon")'
    )->execute([$groupId]);
    $campaignId = (int) $pdo->lastInsertId();
    $half = intdiv($population, 2);
    $rest = $population - $half;
    $pdo->prepare('INSERT INTO stage_capacite (id_campagne, id_service, places) VALUES (?, ?, ?)')->execute([$campaignId, $serviceA, max(1, $half)]);
    $pdo->prepare('INSERT INTO stage_capacite (id_campagne, id_service, places) VALUES (?, ?, ?)')->execute([$campaignId, $serviceB, max(1, $rest)]);
    $report = stage_campaign_propose($campaignId);
    $check($report['affectes'] === $population && $report['non_affectes'] === 0, 'capacity covers the whole group');
    $counts = [];
    foreach ($report['lignes'] as $line) {
        $counts[(int) $line['id_service']] = ($counts[(int) $line['id_service']] ?? 0) + 1;
    }
    $check(count($counts) === 2, 'students are spread across both services');

    $pdo->prepare('UPDATE stage_capacite SET places = 1 WHERE id_campagne = ?')->execute([$campaignId]);
    $tight = stage_campaign_propose($campaignId);
    $check($tight['affectes'] === 2 && $tight['non_affectes'] === $population - 2, 'insufficient capacity leaves students unallocated');
    $reasons = array_filter(array_column($tight['lignes'], 'motif'));
    $check($reasons !== [] && !in_array('', $reasons, true), 'each unallocated student has a reason');

    $otherSpec = (int) $pdo->query('SELECT id_spec FROM specialite ORDER BY id_spec DESC LIMIT 1')->fetchColumn();
    $sample = $pdo->prepare('SELECT e.id_spec FROM etudiant e JOIN groupe_etudiant ge ON ge.id_etudiant = e.id_etudiant WHERE ge.id_groupe = ? AND e.statut = "actif" LIMIT 1');
    $sample->execute([$groupId]);
    $ownSpec = (int) $sample->fetchColumn();
    if ($otherSpec > 0 && $otherSpec !== $ownSpec) {
        $pdo->prepare('INSERT INTO stage_service_regle (id_service, id_spec) VALUES (?, ?)')->execute([$serviceA, $otherSpec]);
        $pdo->prepare('INSERT INTO stage_service_regle (id_service, id_spec) VALUES (?, ?)')->execute([$serviceB, $otherSpec]);
        $blocked = stage_campaign_propose($campaignId);
        $check($blocked['affectes'] === 0, 'ineligible specialty is not assigned');
        $pdo->prepare('DELETE FROM stage_service_regle WHERE id_service IN (?, ?)')->execute([$serviceA, $serviceB]);
    } else {
        $check(true, 'ineligible specialty is not assigned');
    }

    $pdo->prepare('UPDATE stage_capacite SET places = ? WHERE id_campagne = ? AND id_service = ?')->execute([$population, $campaignId, $serviceA]);
    $pdo->prepare('UPDATE stage_capacite SET places = 0 WHERE id_campagne = ? AND id_service = ?')->execute([$campaignId, $serviceB]);
    stage_campaign_propose($campaignId);
    $first = stage_campaign_publish($campaignId, 0);
    $second = stage_campaign_publish($campaignId, 0);
    $stored = $pdo->prepare('SELECT COUNT(*) FROM stage_placement WHERE id_campagne = ?');
    $stored->execute([$campaignId]);
    $check($first['publiees'] === $population && $second['publiees'] === 0 && (int) $stored->fetchColumn() === $population, 'publishing twice does not duplicate placements');

    $pdo->prepare(
        'INSERT INTO stage_campagne (annee_label, date_debut, date_fin, id_groupe, statut) VALUES ("2099-2100", "2099-01-10", "2099-02-10", ?, "brouillon")'
    )->execute([$groupId]);
    $overlapId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_capacite (id_campagne, id_service, places) VALUES (?, ?, ?)')->execute([$overlapId, $serviceA, $population]);
    $overlap = stage_campaign_propose($overlapId);
    $check($overlap['affectes'] === 0 && $overlap['non_affectes'] === $population, 'overlapping dates are not allocated again');

    $lockA = db_connect();
    $lockB = db_connect();
    $lockA->beginTransaction();
    $lockA->prepare('SELECT places FROM stage_capacite WHERE id_campagne = ? AND id_service = ? FOR UPDATE')->execute([$campaignId, $serviceA]);
    $lockB->exec("SET lock_timeout = '1s'");
    $blockedLock = false;
    try {
        $lockB->beginTransaction();
        $lockB->prepare('SELECT places FROM stage_capacite WHERE id_campagne = ? AND id_service = ? FOR UPDATE')->execute([$campaignId, $serviceA]);
    } catch (PDOException $exception) {
        $blockedLock = str_contains($exception->getMessage(), 'lock timeout');
    }
    $lockA->rollBack();
    if ($lockB->inTransaction()) {
        $lockB->rollBack();
    }
    $check($blockedLock, 'a second request waits on the capacity lock');

    $placement = $pdo->prepare('SELECT id_placement, id_etudiant FROM stage_placement WHERE id_campagne = ? ORDER BY id_placement LIMIT 1');
    $placement->execute([$campaignId]);
    $row = $placement->fetch();
    $pdo->prepare('UPDATE stage_capacite SET places = ? WHERE id_campagne = ? AND id_service = ?')->execute([1, $campaignId, $serviceB]);
    stage_move_placement((int) $row['id_placement'], $serviceB, 'Correction de service', 0);
    $moved = $pdo->prepare('SELECT id_service FROM stage_placement WHERE id_placement = ?');
    $moved->execute([(int) $row['id_placement']]);
    $audit = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE category = "stage" AND action_name = "deplacer" AND summary LIKE ?');
    $audit->execute(['%Stage #' . (int) $row['id_placement'] . '%']);
    $check((int) $moved->fetchColumn() === $serviceB && (int) $audit->fetchColumn() === 1, 'manual move keeps the student and writes history');
    $other = $pdo->prepare('SELECT id_placement FROM stage_placement WHERE id_campagne = ? AND id_placement <> ? ORDER BY id_placement LIMIT 1');
    $other->execute([$campaignId, (int) $row['id_placement']]);
    $otherId = (int) $other->fetchColumn();
    $full = false;
    try {
        stage_move_placement($otherId, $serviceB, 'Deuxième place', 0);
    } catch (InvalidArgumentException $exception) {
        $full = str_contains($exception->getMessage(), 'place');
    }
    $check($full, 'moving onto a full service is refused');

    $_SESSION['user_role'] = 'stage';
    $_SESSION['stage_duty'] = 'superviseur';
    $_SESSION['stage_id'] = 999999;
    $visible = stage_placement_visible(['id_stage_superviseur' => 999999, 'created_by' => 1]);
    $hidden = stage_placement_visible(['id_stage_superviseur' => 5, 'created_by' => 999999]);
    $check($visible && !$hidden && role_can('stage.evaluate') && !role_can('stage.place') && !role_can('stage.allocate'), 'supervisor sees only assigned students');
    $_SESSION['stage_duty'] = 'officer';
    $check(role_can('stage.allocate') && role_can('stage.review') && !role_can('stage.evaluate') && !role_can('students.export'), 'manager cannot grade, take finance rights, or fill the supervisor form');

    $target = (int) $row['id_placement'];
    stage_eval_save($target, 'superviseur', [], 'observation', 7, false);
    stage_eval_save($target, 'responsable', [], 'revue séparée', 3, false);
    $supervisorFile = stage_eval_dossier($target, 'superviseur');
    $managerFile = stage_eval_dossier($target, 'responsable');
    $check($supervisorFile && $managerFile && (int) $supervisorFile['id_dossier'] !== (int) $managerFile['id_dossier'], 'manager and supervisor evaluations stay separate');
    $refused = false;
    try {
        stage_eval_validate($target, 3);
    } catch (InvalidArgumentException $exception) {
        $refused = true;
    }
    $check($refused, 'final validation waits for the supervisor submission');
    $criteria = stage_criteria('superviseur');
    $values = [];
    foreach ($criteria as $criterion) {
        $values[(int) $criterion['id_critere']] = 'acquis';
    }
    stage_eval_save($target, 'superviseur', $values, 'observation envoyée', 7, true);
    $managerValues = [];
    foreach (stage_criteria('responsable') as $criterion) {
        $managerValues[(int) $criterion['id_critere']] = 'acquis';
    }
    stage_eval_save($target, 'responsable', $managerValues, 'décision', 3, false);
    stage_eval_validate($target, 3);
    $status = $pdo->prepare('SELECT statut FROM stage_placement WHERE id_placement = ?');
    $status->execute([$target]);
    $check((string) $status->fetchColumn() === 'valide', 'validated placement reaches valide');
    $locked = false;
    try {
        stage_eval_save($target, 'superviseur', $values, 'changement silencieux', 7, false);
    } catch (InvalidArgumentException $exception) {
        $locked = true;
    }
    $check($locked, 'submitted supervisor evaluation cannot be edited silently');
} finally {
    if ($campaignId > 0) {
        $pdo->prepare('DELETE FROM stage_eval_ligne AS l USING stage_eval_dossier AS d, stage_placement AS p WHERE d.id_dossier = l.id_dossier AND p.id_placement = d.id_placement AND (p.id_campagne = ? OR p.id_organisme = ?)')->execute([$campaignId, $orgId]);
        $pdo->prepare('DELETE FROM stage_eval_dossier AS d USING stage_placement AS p WHERE p.id_placement = d.id_placement AND (p.id_campagne = ? OR p.id_organisme = ?)')->execute([$campaignId, $orgId]);
        $pdo->prepare('DELETE FROM stage_placement WHERE id_organisme = ? OR id_campagne = ?')->execute([$orgId, $campaignId]);
        $pdo->prepare('DELETE FROM stage_proposition WHERE id_campagne = ?')->execute([$campaignId]);
        $pdo->prepare('DELETE FROM stage_capacite WHERE id_campagne = ?')->execute([$campaignId]);
        $pdo->prepare('DELETE FROM stage_campagne WHERE id_campagne = ? OR (id_groupe = ? AND annee_label = "2099-2100")')->execute([$campaignId, $groupId]);
    }
    if ($serviceA > 0) {
        $pdo->prepare('DELETE FROM stage_service_regle WHERE id_service IN (?, ?)')->execute([$serviceA, $serviceB]);
        $pdo->prepare('DELETE FROM stage_service WHERE id_service IN (?, ?)')->execute([$serviceA, $serviceB]);
    }
    if ($orgId > 0) {
        $pdo->prepare('DELETE FROM stage_organisme WHERE id_organisme = ?')->execute([$orgId]);
    }
}
echo $failures === [] ? "ALL PASSED\n" : 'FAILED ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);

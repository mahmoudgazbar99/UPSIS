<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_permission('stage.view');
access_ensure();
$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!role_can('stage.place')) {
        http_response_code(403);
        exit('Action non autorisée pour ce compte.');
    }
    if ((string) ($_POST['action'] ?? '') === 'deplacer') {
        try {
            stage_move_placement((int) ($_POST['id_placement'] ?? 0), (int) ($_POST['id_service'] ?? 0), trim((string) ($_POST['motif'] ?? '')), current_stage_id());
            $message = 'Affectation déplacée.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    } else {
    try {
        $studentId = (int) ($_POST['id_etudiant'] ?? 0);
        $organismeId = (int) ($_POST['id_organisme'] ?? 0);
        $start = (string) ($_POST['date_debut'] ?? '');
        $end = (string) ($_POST['date_fin'] ?? '');
        stage_assert_placement($studentId, $organismeId, $start, $end);
        $supervisor = (int) ($_POST['id_stage_superviseur'] ?? 0) ?: null;
        $period = strtoupper(trim((string) ($_POST['periode_code'] ?? '')));
        if ($period !== '' && !academic_period_valid($period)) {
            throw new InvalidArgumentException('Cette période n’existe pas dans la structure académique.');
        }
        db()->prepare(
            'INSERT INTO stage_placement (id_etudiant, id_organisme, id_stage_superviseur, id_program, annee_label, periode_code, type_stage, service_nom, date_debut, date_fin, heures_prevues, objectifs, statut, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "brouillon", ?)'
        )->execute([
            $studentId,
            $organismeId,
            $supervisor,
            (int) ($_POST['id_program'] ?? 0) ?: null,
            trim((string) ($_POST['annee_label'] ?? '')),
            $period,
            trim((string) ($_POST['type_stage'] ?? '')),
            trim((string) ($_POST['service_nom'] ?? '')),
            $start,
            $end,
            (float) str_replace(',', '.', (string) ($_POST['heures_prevues'] ?? '0')),
            trim((string) ($_POST['objectifs'] ?? '')),
            current_stage_id(),
        ]);
        audit_record('stage', 'creer', 'Stage étudiant #' . $studentId . ' du ' . $start . ' au ' . $end);
        $message = 'Stage créé en brouillon.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
    }
}
$where = stage_duty() === 'superviseur' ? 'WHERE p.id_stage_superviseur = ' . current_stage_id() : 'WHERE 1=1';
if ((string) ($_GET['annee'] ?? '') !== '') {
    $where .= ' AND p.annee_label = ' . db()->quote((string) $_GET['annee']);
}
if ((string) ($_GET['statut'] ?? '') !== '') {
    $where .= ' AND p.statut = ' . db()->quote((string) $_GET['statut']);
}
if ((int) ($_GET['id_organisme'] ?? 0) > 0) {
    $where .= ' AND p.id_organisme = ' . (int) $_GET['id_organisme'];
}
if ((int) ($_GET['id_service'] ?? 0) > 0) {
    $where .= ' AND p.id_service = ' . (int) $_GET['id_service'];
}
if ((int) ($_GET['id_superviseur'] ?? 0) > 0 && stage_duty() !== 'superviseur') {
    $where .= ' AND p.id_stage_superviseur = ' . (int) $_GET['id_superviseur'];
}
$rows = db()->query(
    'SELECT p.*, e.nom, e.prenom, e.num_inscription, o.nom AS organisme
     FROM stage_placement p
     JOIN etudiant e ON e.id_etudiant = p.id_etudiant
     JOIN stage_organisme o ON o.id_organisme = p.id_organisme
     ' . $where . '
     ORDER BY p.date_debut DESC LIMIT 80'
)->fetchAll();
$students = db()->query('SELECT id_etudiant, nom, prenom, num_inscription FROM etudiant WHERE statut = "actif" ORDER BY nom, prenom LIMIT 400')->fetchAll();
$hosts = db()->query('SELECT id_organisme, nom, capacite FROM stage_organisme WHERE active = 1 ORDER BY nom')->fetchAll();
$services = db()->query('SELECT s.id_service, s.nom, o.nom AS organisme FROM stage_service s JOIN stage_organisme o ON o.id_organisme = s.id_organisme WHERE s.active = 1 ORDER BY o.nom, s.nom')->fetchAll();
$supervisors = db()->query('SELECT id_stage, nom, cin FROM stage_user WHERE active = 1 ORDER BY nom, cin')->fetchAll();
$programs = academic_programs();
page_head('Placements de stage', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Stages</p>
  <h1 class="display-6 fw-bold mb-1">Placements</h1>
  <p class="text-secondary mb-0">Un stage lie un étudiant, un organisme et, si besoin, un superviseur. Les dates ne peuvent pas se chevaucher.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (role_can('stage.place')): ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="col-md-4"><label class="form-label">Étudiant *</label><select class="form-select" name="id_etudiant" required><option value="">Choisir</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id_etudiant'] ?>"><?= e($student['nom'] . ' ' . $student['prenom'] . ' · ' . $student['num_inscription']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Organisme *</label><select class="form-select" name="id_organisme" required><option value="">Choisir</option><?php foreach ($hosts as $host): ?><option value="<?= (int) $host['id_organisme'] ?>"><?= e($host['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Superviseur</label><select class="form-select" name="id_stage_superviseur"><option value="">Aucun</option><?php foreach ($supervisors as $supervisor): ?><option value="<?= (int) $supervisor['id_stage'] ?>"><?= e(trim($supervisor['nom'] . ' ' . $supervisor['cin'])) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Programme</label><select class="form-select" name="id_program"><option value="">—</option><?php foreach ($programs as $program): ?><option value="<?= (int) $program['id_program'] ?>"><?= e($program['code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Année</label><input class="form-control" name="annee_label" placeholder="2026-2027"></div>
    <div class="col-md-2"><label class="form-label">Période</label><select class="form-select" name="periode_code"><option value="">—</option><?php foreach (academic_periods() as $period): ?><option value="<?= e((string) $period['code']) ?>"><?= e((string) $period['code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Début *</label><input class="form-control" type="date" name="date_debut" required></div>
    <div class="col-md-2"><label class="form-label">Fin *</label><input class="form-control" type="date" name="date_fin" required></div>
    <div class="col-md-3"><label class="form-label">Type</label><input class="form-control" name="type_stage" placeholder="Observation, immersion…"></div>
    <div class="col-md-3"><label class="form-label">Service</label><input class="form-control" name="service_nom"></div>
    <div class="col-md-2"><label class="form-label">Heures prévues</label><input class="form-control" name="heures_prevues" inputmode="decimal" value="0"></div>
    <div class="col-12"><label class="form-label">Objectifs</label><textarea class="form-control" name="objectifs" rows="2"></textarea></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Créer le brouillon</button></div>
  </form>
</div></div>
<?php endif; ?>
<form method="get" class="row g-2 mb-3">
  <?php if (app_is_embedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
  <div class="col-md-2"><input class="form-control" name="annee" value="<?= e((string) ($_GET['annee'] ?? '')) ?>" placeholder="Année"></div>
  <div class="col-md-2"><select class="form-select" name="statut"><option value="">Statut</option><?php foreach (stage_statuses() as $code => $label): ?><option value="<?= e($code) ?>" <?= (($_GET['statut'] ?? '') === $code) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select class="form-select" name="id_organisme"><option value="">Établissement</option><?php foreach ($hosts as $host): ?><option value="<?= (int) $host['id_organisme'] ?>" <?= (int) ($_GET['id_organisme'] ?? 0) === (int) $host['id_organisme'] ? 'selected' : '' ?>><?= e($host['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select class="form-select" name="id_service"><option value="">Service</option><?php foreach ($services as $service): ?><option value="<?= (int) $service['id_service'] ?>" <?= (int) ($_GET['id_service'] ?? 0) === (int) $service['id_service'] ? 'selected' : '' ?>><?= e($service['organisme'] . ' · ' . $service['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><button class="btn btn-outline-light w-100" type="submit">Filtrer</button></div>
</form>
<div class="card app-card"><div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0">
  <thead><tr><th>Étudiant</th><th>Organisme</th><th>Dates</th><th>Statut</th><?php if (role_can('stage.place')): ?><th>Déplacer</th><?php endif; ?></tr></thead>
  <tbody><?php foreach ($rows as $row): ?><tr>
    <td><?= e($row['prenom'] . ' ' . $row['nom']) ?><br><small><?= e($row['num_inscription']) ?></small></td>
    <td><?= e($row['organisme']) ?></td>
    <td><?= e($row['date_debut'] . ' → ' . $row['date_fin']) ?></td>
    <td><a href="stage_suivi.php?id=<?= (int) $row['id_placement'] ?><?= app_is_embedded() ? '&embed=1' : '' ?>"><?= e(stage_statuses()[$row['statut']] ?? $row['statut']) ?></a><br><a href="stage_dossier.php?id=<?= (int) $row['id_placement'] ?><?= app_is_embedded() ? '&embed=1' : '' ?>">Dossier</a></td>
    <?php if (role_can('stage.place')): ?><td>
      <?php if (!empty($row['id_campagne'])): ?>
      <form method="post" class="d-flex flex-column gap-1">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="deplacer"><input type="hidden" name="id_placement" value="<?= (int) $row['id_placement'] ?>">
        <select class="form-select form-select-sm" name="id_service"><?php foreach ($services as $service): ?><option value="<?= (int) $service['id_service'] ?>"><?= e($service['nom']) ?></option><?php endforeach; ?></select>
        <input class="form-control form-control-sm" name="motif" placeholder="Motif" required>
        <button class="btn btn-sm btn-outline-light" type="submit">Déplacer</button>
      </form>
      <?php else: ?><span class="text-secondary">Hors campagne</span><?php endif; ?>
    </td><?php endif; ?>
  </tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="<?= role_can('stage.place') ? 5 : 4 ?>" class="text-center text-secondary py-4">Aucun placement.</td></tr><?php endif; ?></tbody>
</table></div></div>
<?php page_foot();

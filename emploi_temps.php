<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/tronc_commun.php';
require_admin();

$pdo = db();
if (($_GET['build'] ?? '') === '1') {
    tc_build($pdo);
    header('Location: emploi_temps.php?group=G1&week=1');
    exit;
}
$report = tc_load_report($pdo);
$groupLabel = (string) ($_GET['group'] ?? 'G1');
if (!in_array($groupLabel, ['G1', 'G2', 'G3', 'G4'], true)) {
    $groupLabel = 'G1';
}
$groupId = (int) ($report['groups'][$groupLabel] ?? 0);
$sessions = [];
$extras = [];
if ($groupId) {
    $query = $pdo->prepare('SELECT e.jour, e.heure_debut, e.heure_fin, e.type_seance, e.salle, m.code_ecue, m.description_ecue, en.prenom, en.nom, COUNT(DISTINCT e.semaine) AS weeks, GROUP_CONCAT(DISTINCT e.semaine ORDER BY e.semaine) AS week_list FROM emploi_temps e JOIN matiere m ON m.id_mat = e.id_mat JOIN enseignant en ON en.id_enseignant = e.id_enseignant WHERE e.id_groupe = ? GROUP BY e.jour, e.heure_debut, e.heure_fin, e.type_seance, e.salle, m.code_ecue, m.description_ecue, en.prenom, en.nom');
    $query->execute([$groupId]);
    foreach ($query as $row) {
        $sessions[$row['jour']][] = $row;
    }
}

page_head('Emploi du temps', 'schedule');
?>
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
  <div>
    <p class="eyebrow">Administration</p>
    <h1 class="display-6 fw-bold mb-1">Emploi du temps</h1>
    <p class="text-secondary mb-0">Un seul emploi par groupe, le même sur les 14 semaines. G1+G2 en cours A, G3+G4 en cours B.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-light" href="exports/tronc_commun_g1g4.html" target="_blank" rel="noopener">HTML</a>
    <a class="btn btn-outline-light" href="exports/tronc_commun_g1g4.xlsx">Excel</a>
    <a class="btn btn-danger" href="exports/tronc_commun_g1g4.pdf" target="_blank" rel="noopener">PDF</a>
  </div>
</div>
<?php if ($report): ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5">Ce que la feuille impose</h2>
  <ul class="mb-3">
    <?php foreach (tc_notes() as $note): ?><li><?= e($note) ?></li><?php endforeach; ?>
  </ul>
  <div class="d-flex flex-wrap gap-3">
    <?php foreach ($report['overlap'] as $pair => $info): ?>
      <div><strong><?= e($pair) ?></strong> <?= e((string) $info['pct']) ?> % commun</div>
    <?php endforeach; ?>
    <div><?= (int) $report['early'] ?> journées avant 17:00</div>
    <div><?= (int) $report['until18'] ?> journées à 18:00</div>
    <div><?= (int) $report['late_start'] ?> débuts après 08:15</div>
  </div>
</div></div>
<form method="get" class="card app-card mb-4"><div class="card-body p-4 row g-3 align-items-end">
  <div class="col-md-8"><label class="form-label">Groupe TD</label><select class="form-select" name="group"><?php foreach (['G1','G2','G3','G4'] as $label): ?><option value="<?= $label ?>" <?= $groupLabel === $label ? 'selected' : '' ?>><?= $label ?> · 25 étudiants<?= $label === 'G1' || $label === 'G2' ? ' · cours A' : ' · cours B' ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><button class="btn btn-primary w-100">Afficher</button></div>
</div></form>
<div class="card app-card"><div class="card-body p-4">
  <h2 class="h4 mb-3"><?= e($groupLabel) ?> — semaines 1 à 14</h2>
  <?= tc_render_timetable($sessions) ?>
  <?php if ($extras): ?>
    <h3 class="h6 mt-4">Séances qui ne tombent pas chaque semaine</h3>
    <ul class="mb-0">
      <?php foreach ($extras as $extra): ?>
        <li><?= e($extra['jour'] . ' ' . substr($extra['heure_debut'], 0, 5) . '–' . substr($extra['heure_fin'], 0, 5)) ?> · <?= e($extra['code_ecue'] . ' ' . $extra['type_seance']) ?> · semaines <?= e((string) $extra['week_list']) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div></div>
<?php else: ?>
<div class="alert alert-warning">Le planning du tronc commun n'est pas encore enregistré. <a href="emploi_temps.php?build=1">Le générer</a></div>
<?php endif; ?>
<?php page_foot();

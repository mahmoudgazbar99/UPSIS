<?php
declare(strict_types=1);
require_once app_path('layout.php');

if (!is_admin() && !is_teacher()) {
    require_login();
    flash('danger', 'Accès réservé aux enseignants.');
    header('Location: index.php');
    exit;
}

$teacherId = is_teacher() ? current_teacher_id() : max(0, (int) ($_GET['id_enseignant'] ?? 0));
if ($teacherId <= 0 && is_admin()) {
    $teachers = db()->query('SELECT id_enseignant, nom, prenom FROM enseignant WHERE active = 1 ORDER BY nom, prenom')->fetchAll();
} else {
    $teachers = [];
}

$sessions = [];
if ($teacherId > 0) {
    $q = db()->prepare(
        'SELECT et.jour, et.heure_debut, et.heure_fin, et.type_seance, et.salle, et.semaine,
                m.code_ecue, m.description_ecue, g.semestre, g.type_groupe, g.numero
         FROM emploi_temps et
         JOIN matiere m ON m.id_mat = et.id_mat
         JOIN groupe g ON g.id_groupe = et.id_groupe
         WHERE et.id_enseignant = ?
         ORDER BY FIELD(et.jour, "Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"),
                  et.heure_debut, et.semaine'
    );
    $q->execute([$teacherId]);
    $sessions = $q->fetchAll();
}

$byDay = [];
foreach ($sessions as $s) {
    $byDay[(string) $s['jour']][] = $s;
}

page_head('Mon emploi du temps', 'schedule');
?>
<div class="mb-4">
  <p class="eyebrow">Enseignant</p>
  <h1 class="display-6 fw-bold mb-1">Mon emploi du temps</h1>
  <p class="text-secondary mb-0">Séances publiées synchronisées depuis le générateur intelligent.</p>
</div>

<?php if (is_admin() && $teachers): ?>
<form method="get" class="card app-card mb-4"><div class="card-body p-3 row g-2 align-items-end">
  <div class="col-md-6">
    <label class="form-label" for="id_enseignant">Enseignant</label>
    <select class="form-select" id="id_enseignant" name="id_enseignant" data-app-list-prompt data-app-list-title="Choisir un enseignant" data-app-list-search="Nom…" data-app-list-placeholder="Choisir un enseignant…">
      <option value="">Choisir…</option>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= (int) $t['id_enseignant'] ?>" <?= $teacherId === (int) $t['id_enseignant'] ? 'selected' : '' ?>><?= e($t['prenom'] . ' ' . $t['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3"><button class="btn btn-primary" type="submit">Afficher</button></div>
</div></form>
<?php endif; ?>

<?php if ($teacherId <= 0): ?>
  <div class="alert alert-warning">Sélectionnez un enseignant.</div>
<?php elseif (!$sessions): ?>
  <div class="alert alert-secondary">Aucune séance publiée pour cet enseignant.</div>
<?php else: ?>
  <?php foreach ($byDay as $day => $rows): ?>
    <div class="card app-card mb-3"><div class="card-body p-4">
      <h2 class="h5 mb-3"><?= e($day) ?></h2>
      <div class="table-responsive">
        <table class="table table-dark table-sm align-middle mb-0">
          <thead><tr><th>Heure</th><th>Type</th><th>Matière</th><th>Groupe</th><th>Salle</th><th>Sem.</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $s): ?>
              <tr>
                <td><?= e(substr((string) $s['heure_debut'], 0, 5) . '–' . substr((string) $s['heure_fin'], 0, 5)) ?></td>
                <td><?= e((string) $s['type_seance']) ?></td>
                <td><?= e($s['code_ecue'] . ' — ' . $s['description_ecue']) ?></td>
                <td><?= e($s['semestre'] . ' · ' . $s['type_groupe'] . ' ' . $s['numero']) ?></td>
                <td><?= e((string) $s['salle']) ?></td>
                <td><?= (int) $s['semaine'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div></div>
  <?php endforeach; ?>
<?php endif; ?>
<?php page_foot();

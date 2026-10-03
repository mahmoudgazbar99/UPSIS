<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_admin();

$pdo = db();
$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $scope = (string) ($_POST['scope'] ?? '');
    $studentId = filter_input(INPUT_POST, 'id_etudiant', FILTER_VALIDATE_INT) ?: 0;
    $subjectId = filter_input(INPUT_POST, 'id_mat', FILTER_VALIDATE_INT) ?: 0;
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        if (!in_array($scope, ['matiere', 'semestre'], true) || $studentId < 1 || mb_strlen($reason) < 8) {
            throw new RuntimeException('Indiquez un motif d’au moins 8 caractères.');
        }
        if ($scope === 'matiere' && $subjectId < 1) {
            throw new RuntimeException('La matière à déroger est manquante.');
        }
        elimination_ensure_table($pdo);
        $studentQuery = $pdo->prepare('SELECT nom, prenom, cin_passeport FROM etudiant WHERE id_etudiant = ?');
        $studentQuery->execute([$studentId]);
        $student = $studentQuery->fetch();
        if (!$student) {
            throw new RuntimeException('Étudiant introuvable.');
        }
        $subjectLabel = PRESENCE_SEMESTER_CODE;
        if ($scope === 'matiere') {
            $subjectQuery = $pdo->prepare('SELECT code_ecue, description_ecue FROM matiere WHERE id_mat = ?');
            $subjectQuery->execute([$subjectId]);
            $subject = $subjectQuery->fetch();
            if (!$subject) {
                throw new RuntimeException('Matière introuvable.');
            }
            $subjectLabel = $subject['code_ecue'] . ' — ' . $subject['description_ecue'];
            $exists = $pdo->prepare('SELECT 1 FROM elimination_derogation WHERE scope = "matiere" AND id_etudiant = ? AND id_mat = ?');
            $exists->execute([$studentId, $subjectId]);
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM elimination_derogation WHERE scope = "semestre" AND id_etudiant = ? AND semestre = ?');
            $exists->execute([$studentId, PRESENCE_SEMESTER_CODE]);
        }
        if ($exists->fetchColumn()) {
            throw new RuntimeException('Une dérogation existe déjà pour cet étudiant.');
        }
        $insert = $pdo->prepare(
            'INSERT INTO elimination_derogation (scope, id_etudiant, id_mat, semestre, reason) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $scope,
            $studentId,
            $scope === 'matiere' ? $subjectId : null,
            $scope === 'matiere' ? null : PRESENCE_SEMESTER_CODE,
            mb_substr($reason, 0, 500),
        ]);
        audit_record(
            'presence',
            'deroger',
            trim($student['nom'] . ' ' . $student['prenom']) . ' (' . $student['cin_passeport'] . ') · '
            . ($scope === 'matiere' ? 'matière ' : 'semestre ') . $subjectLabel . ' · ' . $reason
        );
        $message = 'Dérogation enregistrée. L’alerte ne s’affiche plus.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}
$alerts = presence_elimination_alerts($pdo);
$derogations = $pdo->query(
    'SELECT d.scope, d.semestre, d.reason, d.created_at, e.nom, e.prenom, e.cin_passeport, m.code_ecue, m.description_ecue
     FROM elimination_derogation d
     JOIN etudiant e ON e.id_etudiant = d.id_etudiant
     LEFT JOIN matiere m ON m.id_mat = d.id_mat
     ORDER BY d.created_at DESC'
)->fetchAll();
$limits = $alerts['limits'];
$counts = $alerts['counts'];
$ratioPercent = (int) round(((float) $limits['semestre_ratio']) * 100);

page_head('Alertes d’élimination', 'elimination-alerts');
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 page-title-block">
  <div>
    <p class="eyebrow">Contrôle d’assiduité</p>
    <h1 class="display-6 fw-bold mb-1">Alertes d’élimination</h1>
    <p class="text-secondary mb-0">
      Calcul dynamique depuis les absences enregistrées.
      <?= (int) $limits['matiere_absences'] ?> absences dans une matière = élimination à l’examen.
      <?= $ratioPercent ?>% de <?= e((string) $limits['semestre_total_hours']) ?> h (<?= e(rtrim(rtrim(number_format((float) $limits['semestre_hours'], 1, ',', ' '), '0'), ',')) ?> h) en <?= e((string) $limits['semestre_code']) ?> = élimination du semestre.
    </p>
  </div>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="elimination-stats mb-4">
  <article class="elimination-stat-card">
    <span class="elimination-stat-label">Étudiants concernés</span>
    <strong class="elimination-stat-value"><?= (int) $counts['total'] ?></strong>
  </article>
  <article class="elimination-stat-card elimination-stat-matiere">
    <span class="elimination-stat-label">Éliminations matière</span>
    <strong class="elimination-stat-value"><?= (int) $counts['matiere'] ?></strong>
    <small>≥ <?= (int) $limits['matiere_absences'] ?> absences / matière</small>
  </article>
  <article class="elimination-stat-card elimination-stat-semestre">
    <span class="elimination-stat-label">Éliminations semestre <?= e((string) $limits['semestre_code']) ?></span>
    <strong class="elimination-stat-value"><?= (int) $counts['semestre'] ?></strong>
    <small>≥ <?= e(rtrim(rtrim(number_format((float) $limits['semestre_hours'], 1, ',', ' '), '0'), ',')) ?> h d’absence</small>
  </article>
</div>

<section class="card app-card mb-4 elimination-panel" aria-labelledby="elimination-matiere-title">
  <div class="card-body elimination-panel-head">
    <div>
      <p class="eyebrow mb-1">Règle 1</p>
      <h2 id="elimination-matiere-title" class="h4 mb-0">Élimination par matière</h2>
      <p class="text-secondary mb-0 mt-1">L’étudiant ne peut pas passer l’examen de la matière concernée.</p>
    </div>
    <span class="elimination-badge"><?= (int) $counts['matiere'] ?></span>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table align-middle mb-0 elimination-table">
      <thead>
        <tr>
          <th>Étudiant</th>
          <th>CIN</th>
          <th>Matière</th>
          <th>Semestre</th>
          <th>Absences</th>
          <th>Heures</th>
          <th>Décision</th>
          <th>Dérogation</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$alerts['matiere']): ?>
          <tr><td colspan="8" class="text-center text-secondary py-4">Aucune élimination matière pour le moment.</td></tr>
        <?php else: ?>
          <?php foreach ($alerts['matiere'] as $row): ?>
            <tr>
              <td>
                <strong><?= e($row['nom'] . ' ' . $row['prenom']) ?></strong>
                <small class="d-block text-secondary"><?= e((string) $row['num_inscription']) ?> · niveau <?= e(level_label((string) $row['niveau'])) ?></small>
              </td>
              <td><?= e((string) $row['cin_passeport']) ?></td>
              <td>
                <strong><?= e((string) $row['code_ecue']) ?></strong>
                <small class="d-block text-secondary"><?= e((string) $row['description_ecue']) ?></small>
              </td>
              <td><?= e((string) $row['semestre']) ?></td>
              <td><span class="elimination-count"><?= (int) $row['absences'] ?></span></td>
              <td><?= e(number_format((float) $row['heures_absentes'], 1, ',', ' ')) ?> h</td>
              <td><span class="elimination-tag elimination-tag-matiere">Éliminé examen</span></td>
              <td>
                <form method="post" class="elimination-override">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="scope" value="matiere">
                  <input type="hidden" name="id_etudiant" value="<?= (int) $row['id_etudiant'] ?>">
                  <input type="hidden" name="id_mat" value="<?= (int) $row['id_mat'] ?>">
                  <input class="form-control form-control-sm" name="reason" required minlength="8" maxlength="500" placeholder="Motif obligatoire">
                  <button class="btn btn-sm btn-outline-warning" type="submit">Lever</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card app-card elimination-panel" aria-labelledby="elimination-semestre-title">
  <div class="card-body elimination-panel-head">
    <div>
      <p class="eyebrow mb-1">Règle 2</p>
      <h2 id="elimination-semestre-title" class="h4 mb-0">Élimination semestre <?= e((string) $limits['semestre_code']) ?></h2>
      <p class="text-secondary mb-0 mt-1">
        Volume de référence : <?= e((string) $limits['semestre_total_hours']) ?> h / 14 semaines.
        Seuil : <?= $ratioPercent ?>% = <?= e(rtrim(rtrim(number_format((float) $limits['semestre_hours'], 1, ',', ' '), '0'), ',')) ?> h.
      </p>
    </div>
    <span class="elimination-badge elimination-badge-danger"><?= (int) $counts['semestre'] ?></span>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table align-middle mb-0 elimination-table">
      <thead>
        <tr>
          <th>Étudiant</th>
          <th>CIN</th>
          <th>Inscription</th>
          <th>Absences</th>
          <th>Heures absentes</th>
          <th>% du volume</th>
          <th>Décision</th>
          <th>Dérogation</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$alerts['semestre']): ?>
          <tr><td colspan="8" class="text-center text-secondary py-4">Aucune élimination semestre pour le moment.</td></tr>
        <?php else: ?>
          <?php foreach ($alerts['semestre'] as $row):
              $hours = (float) $row['heures_absentes'];
              $percent = PRESENCE_SEMESTER_TOTAL_HOURS > 0
                  ? round(($hours / PRESENCE_SEMESTER_TOTAL_HOURS) * 100, 1)
                  : 0.0;
              ?>
            <tr>
              <td>
                <strong><?= e($row['nom'] . ' ' . $row['prenom']) ?></strong>
                <small class="d-block text-secondary">niveau <?= e(level_label((string) $row['niveau'])) ?></small>
              </td>
              <td><?= e((string) $row['cin_passeport']) ?></td>
              <td><?= e((string) $row['num_inscription']) ?></td>
              <td><span class="elimination-count"><?= (int) $row['absences'] ?></span></td>
              <td><?= e(number_format($hours, 1, ',', ' ')) ?> h</td>
              <td>
                <div class="elimination-meter" aria-label="<?= e(number_format($percent, 1, ',', ' ')) ?> %">
                  <span style="width: <?= min(100, $percent) ?>%"></span>
                </div>
                <small class="text-secondary"><?= e(number_format($percent, 1, ',', ' ')) ?> %</small>
              </td>
              <td><span class="elimination-tag elimination-tag-semestre">Éliminé semestre</span></td>
              <td>
                <form method="post" class="elimination-override">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="scope" value="semestre">
                  <input type="hidden" name="id_etudiant" value="<?= (int) $row['id_etudiant'] ?>">
                  <input class="form-control form-control-sm" name="reason" required minlength="8" maxlength="500" placeholder="Motif obligatoire">
                  <button class="btn btn-sm btn-outline-warning" type="submit">Lever</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card app-card mt-4 elimination-panel" aria-labelledby="elimination-derogation-title">
  <div class="card-body elimination-panel-head">
    <div>
      <p class="eyebrow mb-1">Décisions</p>
      <h2 id="elimination-derogation-title" class="h4 mb-0">Dérogations</h2>
      <p class="text-secondary mb-0 mt-1">Chaque levée reste dans le journal d’audit avec son motif.</p>
    </div>
    <span class="elimination-badge"><?= count($derogations) ?></span>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table align-middle mb-0 elimination-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Étudiant</th>
          <th>Portée</th>
          <th>Motif</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$derogations): ?>
          <tr><td colspan="4" class="text-center text-secondary py-4">Aucune dérogation enregistrée.</td></tr>
        <?php else: ?>
          <?php foreach ($derogations as $derogation): ?>
            <tr>
              <td><?= e(date('d/m/Y H:i', strtotime((string) $derogation['created_at']))) ?></td>
              <td>
                <strong><?= e($derogation['nom'] . ' ' . $derogation['prenom']) ?></strong>
                <small class="d-block text-secondary"><?= e((string) $derogation['cin_passeport']) ?></small>
              </td>
              <td>
                <?php if ($derogation['scope'] === 'matiere'): ?>
                  <?= e((string) $derogation['code_ecue']) ?>
                  <small class="d-block text-secondary"><?= e((string) $derogation['description_ecue']) ?></small>
                <?php else: ?>
                  Semestre <?= e((string) $derogation['semestre']) ?>
                <?php endif; ?>
              </td>
              <td><?= e((string) $derogation['reason']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_foot(); ?>

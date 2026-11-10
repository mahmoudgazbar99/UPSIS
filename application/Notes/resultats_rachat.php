<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$year = result_active_year();
$threshold = result_pass_threshold();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $studentId = (int) ($_POST['id_etudiant'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        result_save_decision($studentId, $decision, $year);
        $message = 'Décision enregistrée : ' . result_decision_label($decision) . '.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('resultats_rachat: ' . $exception->getMessage());
        $error = 'L’enregistrement a échoué.';
    }
}

$pending = result_pending_rachat_students($year);
$decidedQuery = db()->prepare(
    'SELECT r.*, e.nom, e.prenom, e.cin_passeport, e.niveau, s.nom_spec
     FROM etudiant_resultat r
     JOIN etudiant e ON e.id_etudiant = r.id_etudiant
     JOIN specialite s ON s.id_spec = e.id_spec
     WHERE r.annee_scolaire = ? AND r.decision IN ("admis_par_rachat", "refusee")
     ORDER BY e.nom, e.prenom'
);
$decidedQuery->execute([$year]);
$decided = $decidedQuery->fetchAll();

page_head('Résultats sous le seuil', 'grades');
?>
<div class="mb-4">
  <p class="eyebrow">Notes · Jury</p>
  <h1 class="display-6 fw-bold mb-1">En attente — moyenne &lt; <?= e(number_format($threshold, 2, ',', '')) ?></h1>
  <p class="text-secondary mb-0">
    Année <?= e($year) ?>. Ces dossiers peuvent réussir par rachat (esʿāf). Choisissez
    <strong>Admis par rachat</strong> ou <strong>Refusée</strong> avant l’envoi des résultats aux profils étudiants.
  </p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card app-card mb-4">
  <div class="card-body p-0 table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Étudiant</th>
          <th>Spécialité</th>
          <th>Niveau</th>
          <th>Groupe</th>
          <th>Moyenne</th>
          <th class="text-end">Décision</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$pending): ?>
          <tr><td colspan="6" class="text-center text-secondary py-4">Aucun étudiant en attente de rachat.</td></tr>
        <?php endif; ?>
        <?php foreach ($pending as $row): ?>
          <tr>
            <td>
              <?= e($row['prenom'] . ' ' . $row['nom']) ?>
              <br><small class="text-secondary"><?= e($row['cin_passeport']) ?></small>
            </td>
            <td><?= e($row['nom_spec']) ?></td>
            <td><?= e(level_label($row['niveau'])) ?></td>
            <td><?= e($row['groupe']) ?></td>
            <td><strong><?= e(number_format((float) $row['moyenne'], 2, ',', '')) ?></strong></td>
            <td class="text-end">
              <form method="post" class="d-inline-flex flex-wrap gap-1 justify-content-end">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id_etudiant" value="<?= (int) $row['id_etudiant'] ?>">
                <button class="btn btn-sm btn-success" name="decision" value="admis_par_rachat" type="submit">Admis par rachat</button>
                <button class="btn btn-sm btn-outline-danger" name="decision" value="refusee" type="submit" onclick="return confirm('Confirmer le refus pour cet étudiant ?');">Refusée</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($decided): ?>
<div class="card app-card">
  <div class="card-body p-4">
    <h2 class="h5 mb-3">Décisions déjà prises (<?= e($year) ?>)</h2>
    <div class="table-responsive">
      <table class="table table-dark table-hover align-middle mb-0">
        <thead><tr><th>Étudiant</th><th>Spécialité</th><th>Moyenne</th><th>Décision</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($decided as $row): ?>
            <tr>
              <td><?= e($row['prenom'] . ' ' . $row['nom']) ?><br><small class="text-secondary"><?= e($row['cin_passeport']) ?></small></td>
              <td><?= e($row['nom_spec']) ?></td>
              <td><?= $row['moyenne'] !== null ? e(number_format((float) $row['moyenne'], 2, ',', '')) : '—' ?></td>
              <td><?= e(result_decision_label((string) $row['decision'])) ?></td>
              <td class="text-end">
                <form method="post" class="d-inline-flex gap-1">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id_etudiant" value="<?= (int) $row['id_etudiant'] ?>">
                  <?php if ((string) $row['decision'] !== 'admis_par_rachat'): ?>
                    <button class="btn btn-sm btn-outline-success" name="decision" value="admis_par_rachat" type="submit">Passer en rachat</button>
                  <?php endif; ?>
                  <?php if ((string) $row['decision'] !== 'refusee'): ?>
                    <button class="btn btn-sm btn-outline-danger" name="decision" value="refusee" type="submit">Passer en refus</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php page_foot();

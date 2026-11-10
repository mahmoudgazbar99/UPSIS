<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$year = result_active_year();
$campagne = result_campagne($year);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $result = result_publish_all($year);
        $campagne = result_campagne($year);
        $message = 'Résultats envoyés à ' . (int) $result['sent'] . ' profil(s) étudiant(s) pour ' . (string) $result['year'] . '.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('envoyer_resultats: ' . $exception->getMessage());
        $error = 'L’envoi a échoué.';
    }
}

$missing = result_students_missing_marks();
$undecided = result_undecided_below_threshold($year);
$ready = $missing === [] && $undecided === [];
$published = result_is_published($year);

page_head('Envoyer les résultats', 'grades');
?>
<div class="mb-4">
  <p class="eyebrow">Notes · Publication</p>
  <h1 class="display-6 fw-bold mb-1">Envoyer les résultats</h1>
  <p class="text-secondary mb-0">
    Année <?= e($year) ?>. Vérifie que chaque étudiant actif a ses notes, que les dossiers sous le seuil ont une décision,
    puis envoie le relevé et une notification sur chaque profil étudiant.
  </p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($published): ?>
  <div class="card app-card mb-4">
    <div class="card-body p-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
      <div>
        <h2 class="h5 mb-1">Résultats publiés</h2>
        <p class="text-secondary mb-0">Vous pouvez passer à la promotion / archivage, ou fermer cette étape.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="promotion.php?embed=1&amp;year=<?= rawurlencode($year) ?>">Passer à Promotion / archivage</a>
        <a class="btn btn-outline-light" href="index.php">Fermer</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card app-card h-100"><div class="card-body p-4">
      <h2 class="h6 text-secondary">Notes manquantes</h2>
      <p class="display-6 fw-bold mb-0"><?= count($missing) ?></p>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card app-card h-100"><div class="card-body p-4">
      <h2 class="h6 text-secondary">Rachat / refus en attente</h2>
      <p class="display-6 fw-bold mb-0"><?= count($undecided) ?></p>
      <?php if ($undecided): ?>
        <a class="small" href="resultats_rachat.php?embed=1">Ouvrir la liste →</a>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card app-card h-100"><div class="card-body p-4">
      <h2 class="h6 text-secondary">Campagne</h2>
      <p class="mb-0">
        <?php if ($published): ?>
          <span class="text-success">Publiée</span>
          <?php if (!empty($campagne['published_at'])): ?>
            <br><small class="text-secondary"><?= e((string) $campagne['published_at']) ?></small>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-warning">Pas encore envoyée</span>
        <?php endif; ?>
      </p>
    </div></div>
  </div>
</div>

<?php if ($missing): ?>
<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h5">Étudiants sans toutes les notes</h2>
    <p class="text-secondary small">Ouvrez la saisie note par note pour compléter le dossier.</p>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>CIN</th>
          <th>Nom</th>
          <th>Spécialité</th>
          <th>Niveau</th>
          <th>Groupe</th>
          <th>Matières manquantes</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($missing as $row): ?>
          <?php
            $firstMissing = $row['missing'][0] ?? null;
            $editHref = 'gestion_notes.php?embed=1&edit_search=' . rawurlencode((string) $row['cin_passeport']);
            if ($firstMissing) {
                $editHref = 'gestion_notes.php?embed=1&grid_group=' . (int) $firstMissing['id_groupe']
                    . '&grid_subject=' . (int) $firstMissing['id_mat'];
            }
            $missingLabels = array_map(
                static fn(array $m): string => $m['code_ecue'] . ' (' . $m['semestre'] . ')',
                $row['missing']
            );
          ?>
          <tr>
            <td><?= e($row['cin_passeport']) ?></td>
            <td><?= e($row['prenom'] . ' ' . $row['nom']) ?></td>
            <td><?= e($row['nom_spec']) ?></td>
            <td><?= e(level_label($row['niveau'])) ?></td>
            <td><?= e($row['groupe']) ?></td>
            <td><small><?= e(implode(', ', $missingLabels)) ?></small></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-info" href="<?= e($editHref) ?>">Éditer notes</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($undecided && !$missing): ?>
<div class="alert alert-warning">
  <?= count($undecided) ?> dossier(s) sous le seuil attendent une décision
  (<a href="resultats_rachat.php?embed=1">Admis par rachat</a> ou Refusée).
</div>
<?php endif; ?>

<?php if ($ready): ?>
<div class="card app-card">
  <div class="card-body p-4">
    <h2 class="h5">Tout est prêt</h2>
    <p class="text-secondary">
      Tous les étudiants actifs ont leurs notes. Les dossiers sous le seuil ont une décision.
      L’envoi publie le relevé sur chaque profil et envoie une notification.
    </p>
    <form method="post" onsubmit="return confirm('Envoyer les résultats à tous les profils étudiants ?');">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-primary btn-lg" type="submit"><?= $published ? 'Renvoyer les résultats' : 'Envoyer les résultats' ?></button>
    </form>
  </div>
</div>
<?php elseif (!$missing && !$undecided): ?>
<div class="alert alert-secondary mb-0">Aucun étudiant actif à traiter.</div>
<?php endif; ?>
<?php page_foot();

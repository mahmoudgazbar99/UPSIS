<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_once app_path('support/emploi_pdf.php');
require_admin();

$pdo = db();
emploi_pdf_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $uploadGroupId = max(0, (int) ($_POST['id_groupe'] ?? 0));
    try {
        if ($action === 'upload_pdf') {
            emploi_pdf_save($uploadGroupId, (array) ($_FILES['emploi_pdf'] ?? []));
            flash('success', 'Le PDF d’emploi du temps a été enregistré pour ce groupe (l’ancien fichier a été remplacé).');
        } elseif ($action === 'delete_pdf') {
            emploi_pdf_delete($uploadGroupId);
            flash('success', 'Le PDF d’emploi du temps a été retiré pour ce groupe.');
        }
    } catch (Throwable $error) {
        flash('danger', $error->getMessage());
    }
    header('Location: emploi_temps.php?id_groupe=' . $uploadGroupId);
    exit;
}

$pdfGroups = emploi_pdf_groups();
$selectedPdfGroup = max(0, (int) ($_GET['id_groupe'] ?? 0));
if ($selectedPdfGroup <= 0 && $pdfGroups) {
    $selectedPdfGroup = (int) $pdfGroups[0]['id_groupe'];
}
$selectedPdfMeta = $selectedPdfGroup > 0 ? emploi_pdf_meta($selectedPdfGroup) : null;

page_head('Emploi du temps', 'schedule');
?>
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
  <div>
    <p class="eyebrow">Administration</p>
    <h1 class="display-6 fw-bold mb-1">Emploi du temps</h1>
    <p class="text-secondary mb-0">Publiez un PDF par groupe. Les étudiants du groupe le voient dans leur espace (aperçu et téléchargement). Pour générer un planning, utilisez le générateur intelligent.</p>
  </div>
  <div>
    <a class="btn btn-outline-light" href="emploi_generateur.php">Générateur intelligent</a>
  </div>
</div>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h5 mb-3">PDF d’emploi par groupe</h2>
    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload_pdf">
      <div class="col-md-5">
        <label class="form-label" for="id_groupe">Groupe</label>
        <select class="form-select" id="id_groupe" name="id_groupe" required data-app-list-prompt data-app-list-title="Choisir un groupe" data-app-list-search="Semestre ou numéro…" data-app-list-placeholder="Choisir un groupe…">
          <option value="">Choisir un groupe</option>
          <?php foreach ($pdfGroups as $group): ?>
            <?php
              $label = $group['semestre'] . ' · ' . $group['type_groupe'] . ' ' . $group['numero'];
              $hasPdf = !empty($group['original_name']);
            ?>
            <option value="<?= (int) $group['id_groupe'] ?>" <?= $selectedPdfGroup === (int) $group['id_groupe'] ? 'selected' : '' ?>>
              <?= e($label) ?><?= $hasPdf ? ' · PDF publié' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label" for="emploi_pdf">Fichier PDF</label>
        <input class="form-control" id="emploi_pdf" name="emploi_pdf" type="file" accept="application/pdf,.pdf" required>
        <div class="form-text">PDF uniquement, 12 Mo max. Un nouvel envoi remplace l’ancien fichier du groupe.</div>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary w-100" type="submit">Publier</button>
      </div>
    </form>

    <?php if ($selectedPdfMeta): ?>
      <div class="d-flex flex-wrap gap-2 align-items-center mt-4">
        <span class="text-secondary">Fichier actuel : <?= e((string) $selectedPdfMeta['original_name']) ?> · <?= number_format(((int) $selectedPdfMeta['file_size']) / 1048576, 2, ',', ' ') ?> Mo</span>
        <a class="btn btn-outline-light btn-sm" href="emploi_pdf.php?id_groupe=<?= (int) $selectedPdfGroup ?>&action=view" target="_blank" rel="noopener">Aperçu</a>
        <a class="btn btn-outline-light btn-sm" href="emploi_pdf.php?id_groupe=<?= (int) $selectedPdfGroup ?>&action=download">Télécharger</a>
        <form method="post" class="d-inline" onsubmit="return confirm('Retirer le PDF de ce groupe ?');">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_pdf">
          <input type="hidden" name="id_groupe" value="<?= (int) $selectedPdfGroup ?>">
          <button class="btn btn-outline-danger btn-sm" type="submit">Supprimer</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($pdfGroups): ?>
      <div class="table-responsive mt-4">
        <table class="table table-dark table-sm align-middle mb-0">
          <thead><tr><th>Groupe</th><th>PDF</th><th>Mis à jour</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($pdfGroups as $group): ?>
              <tr>
                <td><?= e($group['semestre'] . ' · ' . $group['type_groupe'] . ' ' . $group['numero']) ?></td>
                <td><?= !empty($group['original_name']) ? e((string) $group['original_name']) : '—' ?></td>
                <td><?= !empty($group['uploaded_at']) ? e((string) $group['uploaded_at']) : '—' ?></td>
                <td class="text-end">
                  <?php if (!empty($group['original_name'])): ?>
                    <a class="btn btn-sm btn-outline-light" href="emploi_pdf.php?id_groupe=<?= (int) $group['id_groupe'] ?>&action=view" target="_blank" rel="noopener">Aperçu</a>
                    <a class="btn btn-sm btn-outline-light" href="emploi_pdf.php?id_groupe=<?= (int) $group['id_groupe'] ?>&action=download">PDF</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php page_foot();

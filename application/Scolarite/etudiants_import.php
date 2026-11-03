<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_once app_path('support/student_sheet.php');
require_permission('students.edit');

$mode = (string) ($_GET['modele'] ?? $_GET['export'] ?? '');
if ($mode === '1' || (string) ($_GET['export'] ?? '') === '1') {
    require_permission('students.export');
    $rows = (string) ($_GET['modele'] ?? '') === '1'
        ? [array_values(student_sheet_columns())]
        : student_sheet_export_rows();
    $name = (string) ($_GET['modele'] ?? '') === '1' ? 'modele-etudiants.xlsx' : 'etudiants.xlsx';
    student_sheet_send($name, student_sheet_xlsx($rows));
    exit;
}

$error = '';
$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $file = $_FILES['fichier'] ?? [];
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $original = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        $error = 'Le fichier n’a pas pu être reçu.';
    } elseif ($size < 1 || $size > 5 * 1048576) {
        $error = 'Le fichier dépasse 5 Mo.';
    } elseif (!in_array($extension, ['xlsx', 'csv'], true)) {
        $error = 'Utilisez un fichier Excel .xlsx ou un fichier .csv.';
    } else {
        try {
            $rows = student_sheet_read($tmp, $extension);
            $report = student_sheet_import($rows);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('student sheet: ' . $exception->getMessage());
            $error = 'Le fichier n’a pas pu être lu.';
        }
    }
}

$embed = app_is_embedded() ? '&embed=1' : '';
page_head('Importer des étudiants', 'register');
?>
<div class="mb-4">
  <p class="eyebrow">Scolarité</p>
  <h1 class="display-6 fw-bold mb-2">Liste des étudiants</h1>
  <p class="text-secondary mb-0">Un fichier incomplet n’efface personne. Les étudiants absents du fichier restent en place. Une case vide ne remplace pas une information déjà enregistrée. Un étudiant nouveau dont il manque une donnée obligatoire est ignoré, et son numéro de ligne est indiqué.</p>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="d-flex flex-wrap gap-2 mb-4">
  <a class="btn btn-outline-light" href="etudiants_import.php?export=1">Télécharger la liste Excel</a>
  <a class="btn btn-outline-light" href="etudiants_import.php?modele=1">Télécharger le modèle</a>
</div>
<form method="post" enctype="multipart/form-data" class="card app-card mb-4">
  <div class="card-body">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label class="form-label" for="fichier">Fichier Excel de l’université</label>
    <input class="form-control" id="fichier" type="file" name="fichier" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
    <p class="form-text">La première ligne contient les titres. Les colonnes absentes sont traitées comme vides. Spécialité et niveau doivent déjà exister dans l’application.</p>
    <button class="btn btn-primary" type="submit">Importer</button>
  </div>
</form>
<?php if (is_array($report)): ?>
<div class="card app-card">
  <div class="card-body">
    <p class="mb-2"><strong><?= (int) $report['created'] ?></strong> étudiant(s) créé(s), <strong><?= (int) $report['updated'] ?></strong> mis à jour, <strong><?= count($report['skipped']) ?></strong> ligne(s) ignorée(s).</p>
    <?php if ($report['warnings']): ?>
      <ul><?php foreach ($report['warnings'] as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($report['skipped']): ?>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Ligne</th><th>Motif</th></tr></thead><tbody>
        <?php foreach ($report['skipped'] as $skip): ?><tr><td><?= (int) $skip['line'] ?></td><td><?= e($skip['reason']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php
page_foot();

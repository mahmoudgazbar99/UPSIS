<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        academic_add_unity(
            (string) ($_POST['code_ue'] ?? ''),
            (string) ($_POST['description_ue'] ?? ''),
            (string) ($_POST['semestre'] ?? ''),
            (float) str_replace(',', '.', (string) ($_POST['coefficient_ue'] ?? ''))
        );
        $message = 'L’unité a été ajoutée.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = 'Cette unité existe déjà.';
    }
}

$unities = academic_unities();
page_head('Ajouter une unité', 'groups');
?>
<div class="mb-4">
  <p class="eyebrow">Pédagogie</p>
  <h1 class="display-6 fw-bold mb-1">Ajouter une unité</h1>
  <p class="text-secondary mb-0">Une unité regroupe des matières. Le code ne peut pas déjà exister.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4">
  <div class="card-body p-4">
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="col-md-4">
        <label class="form-label" for="code_ue">Code</label>
        <input class="form-control" id="code_ue" name="code_ue" maxlength="30" required placeholder="UEF150">
      </div>
      <div class="col-md-8">
        <label class="form-label" for="description_ue">Nom de l’unité</label>
        <input class="form-control" id="description_ue" name="description_ue" maxlength="255" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="semestre">Semestre</label>
        <select class="form-select" id="semestre" name="semestre" required>
          <?php foreach (academic_period_codes() as $semester): ?>
            <option value="<?= e($semester) ?>"><?= e($semester) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="coefficient_ue">Coefficient</label>
        <input class="form-control" id="coefficient_ue" name="coefficient_ue" inputmode="decimal" required value="1">
      </div>
      <div class="col-12">
        <button class="btn btn-primary" type="submit">Ajouter l’unité</button>
      </div>
    </form>
  </div>
</div>
<div class="card app-card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Code</th><th>Unité</th><th>Semestre</th><th>Coefficient</th></tr></thead>
      <tbody>
        <?php foreach ($unities as $unity): ?>
          <tr>
            <td><?= e((string) $unity['code_ue']) ?></td>
            <td><?= e((string) $unity['description_ue']) ?></td>
            <td><?= e((string) $unity['semestre']) ?></td>
            <td><?= e((string) $unity['coefficient_ue']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_foot();

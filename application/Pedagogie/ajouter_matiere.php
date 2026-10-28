<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        academic_add_subject($_POST);
        $message = 'La matière a été ajoutée.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = 'Cette matière existe déjà ou l’enregistrement a échoué.';
    }
}

$unities = academic_unities();
$recent = db()->query(
    'SELECT code_ecue, description_ecue, code_ue, coeff, semestre FROM matiere ORDER BY id_mat DESC LIMIT 8'
)->fetchAll();
page_head('Ajouter une matière', 'grades');
?>
<div class="mb-4">
  <p class="eyebrow">Pédagogie</p>
  <h1 class="display-6 fw-bold mb-1">Ajouter une matière</h1>
  <p class="text-secondary mb-0">Le code et le nom sont refusés s’ils existent déjà. Une unité doit être choisie, ou l’absence d’unité doit être cochée.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4">
  <div class="card-body p-4">
    <form method="post" class="row g-3" id="subject-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="col-md-4">
        <label class="form-label" for="code">Code</label>
        <input class="form-control" id="code" name="code" maxlength="30" required placeholder="UEF151">
      </div>
      <div class="col-md-8">
        <label class="form-label" for="name">Nom de la matière</label>
        <input class="form-control" id="name" name="name" maxlength="255" required>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="coefficient">Coefficient</label>
        <input class="form-control" id="coefficient" name="coefficient" inputmode="decimal" required value="1">
      </div>
      <div class="col-md-3">
        <label class="form-label" for="credit">Crédit</label>
        <input class="form-control" id="credit" name="credit" inputmode="decimal" value="0">
      </div>
      <div class="col-md-3">
        <label class="form-label" for="regime">Régime</label>
        <select class="form-select" id="regime" name="regime">
          <option value="Mixte">Mixte</option>
          <option value="C.C">C.C</option>
        </select>
      </div>
      <div class="col-md-3" id="semester-field" hidden>
        <label class="form-label" for="semestre">Semestre</label>
        <select class="form-select" id="semestre" name="semestre" disabled>
          <?php foreach (academic_period_codes() as $semester): ?>
            <option value="<?= e($semester) ?>"><?= e($semester) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label" for="code_ue">Unité existante</label>
        <select class="form-select" id="code_ue" name="code_ue">
          <option value="">Choisir une unité</option>
          <?php foreach ($unities as $unity): ?>
            <option value="<?= e((string) $unity['code_ue']) ?>"><?= e((string) $unity['code_ue'] . ' — ' . $unity['description_ue'] . ' (' . $unity['semestre'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="sans_unite" name="sans_unite" value="1">
          <label class="form-check-label" for="sans_unite">Cette matière n’a pas une unité</label>
        </div>
        <div class="form-text" id="subject-choice">Choisissez une unité ou cochez l’absence d’unité avant d’enregistrer.</div>
      </div>
      <div class="col-12">
        <button class="btn btn-primary" type="submit" id="subject-submit" disabled>Ajouter la matière</button>
      </div>
    </form>
  </div>
</div>
<div class="card app-card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Code</th><th>Matière</th><th>Unité</th><th>Coef</th><th>Semestre</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $subject): ?>
          <tr>
            <td><?= e((string) $subject['code_ecue']) ?></td>
            <td><?= e((string) $subject['description_ecue']) ?></td>
            <td><?= e((string) ($subject['code_ue'] !== '' ? $subject['code_ue'] : '—')) ?></td>
            <td><?= e((string) $subject['coeff']) ?></td>
            <td><?= e((string) $subject['semestre']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
(function () {
  const form = document.getElementById('subject-form');
  const unity = document.getElementById('code_ue');
  const checkbox = document.getElementById('sans_unite');
  const submit = document.getElementById('subject-submit');
  const semesterField = document.getElementById('semester-field');
  const semester = document.getElementById('semestre');
  const hint = document.getElementById('subject-choice');
  function refresh() {
    const without = checkbox.checked;
    unity.disabled = without;
    if (without) unity.value = '';
    semester.disabled = !without;
    semester.required = without;
    semesterField.hidden = !without;
    const ready = without || unity.value !== '';
    submit.disabled = !ready;
    hint.textContent = ready
      ? (without ? 'Cette matière sera enregistrée sans unité.' : 'Cette matière sera rattachée à l’unité choisie.')
      : 'Choisissez une unité ou cochez l’absence d’unité avant d’enregistrer.';
  }
  checkbox.addEventListener('change', refresh);
  unity.addEventListener('change', function () {
    if (unity.value !== '') checkbox.checked = false;
    refresh();
  });
  form.addEventListener('submit', function (event) {
    if (submit.disabled) {
      event.preventDefault();
    }
  });
  refresh();
})();
</script>
<?php page_foot();

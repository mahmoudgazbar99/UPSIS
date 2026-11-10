<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';
$policy = inscription_policy();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $requiredPosted = $_POST['required'] ?? [];
        if (!is_array($requiredPosted)) {
            $requiredPosted = [];
        }
        $requiredMap = [];
        foreach (array_keys(inscription_field_catalog()) as $key) {
            $requiredMap[$key] = isset($requiredPosted[$key]);
        }

        $labels = $_POST['doc_label'] ?? [];
        $codes = $_POST['doc_code'] ?? [];
        $docRequired = $_POST['doc_required'] ?? [];
        if (!is_array($labels)) {
            $labels = [];
        }
        if (!is_array($codes)) {
            $codes = [];
        }
        if (!is_array($docRequired)) {
            $docRequired = [];
        }
        $documents = [];
        foreach ($labels as $index => $label) {
            $documents[] = [
                'code' => (string) ($codes[$index] ?? ''),
                'label' => (string) $label,
                'required' => isset($docRequired[$index]),
            ];
        }

        inscription_save_policy(
            $requiredMap,
            $documents,
            (string) ($_POST['open_from'] ?? ''),
            (string) ($_POST['open_to'] ?? '')
        );
        $policy = inscription_policy();
        $message = 'Règles d’inscription enregistrées.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('inscription_regles: ' . $exception->getMessage());
        $error = 'L’enregistrement a échoué.';
    }
}

$catalog = inscription_field_catalog();
page_head('Règles d’inscription', 'enroll');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Inscription — champs, docs, dates</h1>
  <p class="text-secondary mb-0">Définit les champs obligatoires, la checklist de pièces, et la fenêtre d’ouverture des nouvelles inscriptions.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="row g-4">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

  <div class="col-12">
    <div class="card app-card">
      <div class="card-body p-4">
        <h2 class="h5">Fenêtre d’inscription</h2>
        <p class="text-secondary small mb-3">Laissez vide pour laisser les inscriptions ouvertes en permanence. Hors période : nouvelles inscriptions bloquées (modification d’un dossier existant toujours possible).</p>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label" for="open_from">Ouverture</label>
            <input class="form-control" type="date" id="open_from" name="open_from" value="<?= e($policy['open_from']) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="open_to">Fermeture</label>
            <input class="form-control" type="date" id="open_to" name="open_to" value="<?= e($policy['open_to']) ?>">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <p class="mb-2 small text-secondary"><?= e(inscription_window_message()) ?> <?= inscription_window_open() ? '· <span class="text-success">Ouvert aujourd’hui</span>' : '· <span class="text-danger">Fermé aujourd’hui</span>' ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card app-card h-100">
      <div class="card-body p-4">
        <h2 class="h5">Champs obligatoires</h2>
        <p class="text-secondary small">Les champs verrouillés restent toujours obligatoires.</p>
        <div class="row g-2">
          <?php foreach ($catalog as $key => $meta): ?>
            <?php
              $locked = !empty($meta['locked']);
              $checked = $locked || !empty($policy['required_fields'][$key]);
            ?>
            <div class="col-12">
              <label class="d-flex align-items-center gap-2 mb-0">
                <input type="checkbox" name="required[<?= e($key) ?>]" value="1" <?= $checked ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                <span><?= e($meta['label']) ?><?= $locked ? ' <span class="text-secondary">(toujours)</span>' : '' ?></span>
              </label>
              <?php if ($locked): ?><input type="hidden" name="required[<?= e($key) ?>]" value="1"><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card app-card h-100">
      <div class="card-body p-4">
        <h2 class="h5">Documents à fournir</h2>
        <p class="text-secondary small">Checklist affichée à l’inscription. Les pièces marquées obligatoires doivent être cochées pour un nouveau dossier.</p>
        <div id="docs-list" class="d-flex flex-column gap-2">
          <?php foreach ($policy['documents'] as $index => $doc): ?>
            <div class="border rounded p-2 doc-row">
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <input class="form-control form-control-sm" style="max-width:7.5rem" name="doc_code[]" value="<?= e($doc['code']) ?>" placeholder="code" maxlength="32" aria-label="Code">
                <input class="form-control form-control-sm flex-grow-1" name="doc_label[]" value="<?= e($doc['label']) ?>" placeholder="Libellé" maxlength="120" required aria-label="Libellé">
                <label class="small mb-0 d-flex align-items-center gap-1 text-nowrap">
                  <input type="checkbox" name="doc_required[<?= (int) $index ?>]" value="1" <?= !empty($doc['required']) ? 'checked' : '' ?>>
                  Obligatoire
                </label>
                <button type="button" class="btn btn-outline-danger btn-sm doc-remove" aria-label="Supprimer cette pièce" title="Supprimer">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M8 7l1 13h6l1-13"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="add-doc">Ajouter une pièce</button>
      </div>
    </div>
  </div>

  <div class="col-12">
    <button class="btn btn-primary" type="submit">Enregistrer</button>
  </div>
</form>

<script>
(function () {
  const list = document.getElementById('docs-list');
  const addBtn = document.getElementById('add-doc');
  if (!list || !addBtn) return;

  const trashSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M8 7l1 13h6l1-13"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';

  function reindexDocs() {
    const rows = list.querySelectorAll('.doc-row');
    rows.forEach(function (row, index) {
      const checkbox = row.querySelector('input[type="checkbox"]');
      if (checkbox) checkbox.name = 'doc_required[' + index + ']';
    });
    const onlyOne = rows.length <= 1;
    rows.forEach(function (row) {
      const btn = row.querySelector('.doc-remove');
      if (!btn) return;
      btn.disabled = onlyOne;
      btn.title = onlyOne ? 'Au moins une pièce est requise' : 'Supprimer';
      btn.setAttribute('aria-label', onlyOne ? 'Impossible de supprimer la dernière pièce' : 'Supprimer cette pièce');
    });
  }

  function buildRow(index) {
    const row = document.createElement('div');
    row.className = 'border rounded p-2 doc-row';
    row.innerHTML = '<div class="d-flex flex-wrap gap-2 align-items-center">'
      + '<input class="form-control form-control-sm" style="max-width:7.5rem" name="doc_code[]" placeholder="code" maxlength="32" aria-label="Code">'
      + '<input class="form-control form-control-sm flex-grow-1" name="doc_label[]" placeholder="Libellé" maxlength="120" required aria-label="Libellé">'
      + '<label class="small mb-0 d-flex align-items-center gap-1 text-nowrap"><input type="checkbox" name="doc_required[' + index + ']" value="1"> Obligatoire</label>'
      + '<button type="button" class="btn btn-outline-danger btn-sm doc-remove" aria-label="Supprimer cette pièce" title="Supprimer">' + trashSvg + '</button>'
      + '</div>';
    return row;
  }

  addBtn.addEventListener('click', function () {
    list.appendChild(buildRow(list.querySelectorAll('.doc-row').length));
    reindexDocs();
  });

  list.addEventListener('click', function (event) {
    const btn = event.target.closest('.doc-remove');
    if (!btn || !list.contains(btn) || btn.disabled) return;
    const row = btn.closest('.doc-row');
    if (!row) return;
    if (list.querySelectorAll('.doc-row').length <= 1) {
      reindexDocs();
      return;
    }
    if (!window.confirm('Retirer cette pièce de la liste ?')) return;
    row.remove();
    reindexDocs();
  });

  reindexDocs();
})();
</script>
<?php page_foot();

<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_admissions_permission('admissions.manage');
admissions_ensure();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'docs') {
            $labels = $_POST['label'] ?? [];
            $codes = $_POST['code'] ?? [];
            $required = $_POST['required'] ?? [];
            $active = $_POST['active'] ?? [];
            $ids = $_POST['id_doc_type'] ?? [];
            if (!is_array($labels)) {
                $labels = [];
            }
            foreach ($labels as $i => $label) {
                $id = (int) ($ids[$i] ?? 0);
                $code = trim((string) ($codes[$i] ?? ''));
                $label = trim((string) $label);
                if ($label === '' || $code === '') {
                    continue;
                }
                $req = isset($required[$i]) ? 1 : 0;
                $act = isset($active[$i]) ? 1 : 0;
                if ($id > 0) {
                    db()->prepare('UPDATE admission_doc_type SET code=?, label=?, required=?, active=?, sort_order=? WHERE id_doc_type=?')
                        ->execute([$code, $label, $req, $act, $i, $id]);
                } else {
                    db()->prepare('INSERT INTO admission_doc_type (code, label, required, active, sort_order) VALUES (?,?,?,?,?)')
                        ->execute([$code, $label, $req, $act, $i]);
                }
            }
            $message = 'Types de documents enregistrés.';
        }
        if ($action === 'settings') {
            admissions_save_setting('offer_accept_days', (string) max(1, (int) ($_POST['offer_accept_days'] ?? 14)));
            $message = 'Paramètres enregistrés.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$docs = admissions_doc_types(false);
$offerDays = admissions_setting('offer_accept_days', '14');

page_head('Config admissions', 'admissions', 'assets/admissions-lightswind.css?v=1');
?>
<div class="adm-lw-admin">
<div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="admissions.php?embed=1">← Admissions</a></div>
<p class="eyebrow">Admissions</p>
<h1 class="h3 fw-bold mb-3">Configuration</h1>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card app-card mb-4"><div class="card-body">
  <h2 class="h6">Numérotation</h2>
  <p class="small text-secondary mb-2">
    Références candidature : <code>CAND-AAAA-NNNNNN</code> (nouveau).<br>
    Numéro d’inscription étudiant à l’enrollment : <code>INS###/AAAA</code> — <strong>même format que l’inscription scolarité</strong> (non modifié).<br>
    Attestations <code>ATT-INS-</code> / <code>ATT-PRE-</code> / <code>ATT-REU-</code> inchangées.
  </p>
  <form method="post" class="row g-2 align-items-end">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="settings">
    <div class="col-md-3">
      <label class="form-label">Délai d’acceptation d’offre (jours)</label>
      <input class="form-control" type="number" min="1" name="offer_accept_days" value="<?= e($offerDays) ?>">
    </div>
    <div class="col-md-2"><button class="btn btn-primary">Enregistrer</button></div>
  </form>
</div></div>

<div class="card app-card"><div class="card-body">
  <h2 class="h6">Documents requis</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="docs">
    <?php foreach ($docs as $i => $doc): ?>
      <div class="row g-2 mb-2 align-items-center">
        <input type="hidden" name="id_doc_type[<?= $i ?>]" value="<?= (int) $doc['id_doc_type'] ?>">
        <div class="col-md-2"><input class="form-control form-control-sm" name="code[<?= $i ?>]" value="<?= e($doc['code']) ?>"></div>
        <div class="col-md-5"><input class="form-control form-control-sm" name="label[<?= $i ?>]" value="<?= e($doc['label']) ?>"></div>
        <div class="col-md-2 form-check ms-2">
          <input class="form-check-input" type="checkbox" name="required[<?= $i ?>]" <?= (int) $doc['required'] === 1 ? 'checked' : '' ?>>
          <label class="form-check-label">Obligatoire</label>
        </div>
        <div class="col-md-2 form-check">
          <input class="form-check-input" type="checkbox" name="active[<?= $i ?>]" <?= (int) $doc['active'] === 1 ? 'checked' : '' ?>>
          <label class="form-check-label">Actif</label>
        </div>
      </div>
    <?php endforeach; ?>
    <?php $n = count($docs); ?>
    <div class="row g-2 mb-3 align-items-center">
      <input type="hidden" name="id_doc_type[<?= $n ?>]" value="0">
      <div class="col-md-2"><input class="form-control form-control-sm" name="code[<?= $n ?>]" placeholder="code"></div>
      <div class="col-md-5"><input class="form-control form-control-sm" name="label[<?= $n ?>]" placeholder="Nouveau type"></div>
      <div class="col-md-2 form-check ms-2"><input class="form-check-input" type="checkbox" name="required[<?= $n ?>]" checked><label class="form-check-label">Obligatoire</label></div>
      <div class="col-md-2 form-check"><input class="form-check-input" type="checkbox" name="active[<?= $n ?>]" checked><label class="form-check-label">Actif</label></div>
    </div>
    <button class="btn btn-primary">Enregistrer les documents</button>
  </form>
</div></div>

</div>


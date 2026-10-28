<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_permission('stage.documents.configure');
access_ensure();
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'type') {
            stage_doc_save_type($_POST);
            $message = 'Catégorie enregistrée. Elle n’est pas obligatoire tant qu’une exigence ne le dit pas.';
        } elseif ($action === 'type_off') {
            stage_doc_deactivate_type((int) ($_POST['id_type'] ?? 0));
            $message = 'Catégorie retirée. Les fichiers déjà déposés restent en place.';
        } elseif ($action === 'exigence') {
            stage_doc_save_requirement($_POST);
            $message = 'Exigence enregistrée. Les documents déjà déposés ne sont pas modifiés.';
        } elseif ($action === 'exigence_off') {
            stage_doc_deactivate_requirement((int) ($_POST['id_exigence'] ?? 0));
            $message = 'Exigence retirée. L’historique et les fichiers sont conservés.';
        } elseif ($action === 'limites') {
            stage_doc_save_limits($_POST);
            $message = 'Limites de dépôt enregistrées.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'Cette catégorie existe déjà.';
    }
}
$types = db()->query('SELECT * FROM stage_doc_type ORDER BY actif DESC, libelle')->fetchAll();
$requirements = db()->query(
    'SELECT x.*, t.libelle FROM stage_doc_exigence x JOIN stage_doc_type t ON t.id_type = x.id_type ORDER BY x.actif DESC, x.id_exigence DESC'
)->fetchAll();
$campaigns = db()->query('SELECT id_campagne, annee_label, periode_code FROM stage_campagne ORDER BY date_debut DESC')->fetchAll();
$specialties = db()->query('SELECT id_spec, nom_spec FROM specialite ORDER BY nom_spec')->fetchAll();
$selected = array_fill_keys(array_keys(stage_doc_extensions()), true);
page_head('Catégories de documents de stage', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Stages</p>
  <h1 class="display-6 fw-bold mb-1">Documents de stage</h1>
  <p class="text-secondary mb-0">Les exemples présents au départ ne sont pas obligatoires. Une exigence précise pour qui, et pour quel stage, une pièce est demandée.</p>
</div>
<?php if ($message): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="row g-4">
  <div class="col-lg-6"><div class="card app-card h-100"><div class="card-body">
    <h2 class="h5">Nouvelle catégorie</h2>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="type">
      <div class="col-md-6"><label class="form-label">Nom</label><input class="form-control" name="libelle" required></div>
      <div class="col-md-6"><label class="form-label">Code</label><input class="form-control" name="code" required placeholder="rapport"></div>
      <div class="col-12"><label class="form-label">Instructions</label><textarea class="form-control" name="description" rows="2"></textarea></div>
      <div class="col-md-6"><label class="form-label">Déposé par</label><select class="form-select" name="deposant"><?php foreach (stage_doc_depositors() as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6 form-check mt-4"><input class="form-check-input" type="checkbox" name="validation_requise" value="1" id="val" checked><label class="form-check-label" for="val">Validation du responsable</label></div>
      <div class="col-12 form-check"><input class="form-check-input" type="checkbox" name="visible_encadrant" value="1" id="vis"><label class="form-check-label" for="vis">Visible par l’encadrant du stage</label></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>
  </div></div></div>
  <div class="col-lg-6"><div class="card app-card h-100"><div class="card-body">
    <h2 class="h5">Limites de dépôt</h2>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="limites">
      <div class="col-md-4"><label class="form-label">Taille max. (Mo)</label><input class="form-control" name="mega" value="<?= e(number_format(stage_doc_max_bytes() / 1048576, 1, '.', '')) ?>"></div>
      <div class="col-12"><?php foreach (stage_doc_catalog() as $extension => $mimes): ?><label class="me-3"><input type="checkbox" name="extensions[]" value="<?= e($extension) ?>" <?= isset($selected[$extension]) ? 'checked' : '' ?>> <?= e($extension) ?></label><?php endforeach; ?></div>
      <div class="col-12 form-check"><input class="form-check-input" type="checkbox" name="lock_closed" value="1" id="lock" <?= stage_doc_closed_locked() ? 'checked' : '' ?>><label class="form-check-label" for="lock">Interdire les nouveaux dépôts quand le stage est clos, sauf au responsable</label></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer les limites</button></div>
    </form>
  </div></div></div>
  <div class="col-12"><div class="card app-card"><div class="card-body">
    <h2 class="h5">Exiger une pièce</h2>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="exigence">
      <div class="col-md-3"><select class="form-select" name="id_type" required aria-label="Catégorie"><option value="">Catégorie</option><?php foreach ($types as $type): if (!(int) $type['actif']) continue; ?><option value="<?= (int) $type['id_type'] ?>"><?= e((string) $type['libelle']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><select class="form-select" name="id_campagne" aria-label="Campagne"><option value="">Toutes les campagnes</option><?php foreach ($campaigns as $campaign): ?><option value="<?= (int) $campaign['id_campagne'] ?>"><?= e(trim($campaign['annee_label'] . ' ' . $campaign['periode_code'])) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><select class="form-select" name="id_spec" aria-label="Spécialité"><option value="">Toutes les spécialités</option><?php foreach ($specialties as $specialty): ?><option value="<?= (int) $specialty['id_spec'] ?>"><?= e((string) $specialty['nom_spec']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-1"><input class="form-control" name="niveau" placeholder="Niveau" aria-label="Niveau"></div>
      <div class="col-md-2"><input class="form-control" type="date" name="date_limite" aria-label="Date limite"></div>
      <div class="col-md-3"><select class="form-select" name="deposant"><?php foreach (stage_doc_depositors() as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3 form-check mt-2"><input class="form-check-input" type="checkbox" name="obligatoire" value="1" id="obl"><label class="form-check-label" for="obl">Obligatoire</label></div>
      <div class="col-md-3 form-check mt-2"><input class="form-check-input" type="checkbox" name="validation_requise" value="1" id="val2" checked><label class="form-check-label" for="val2">Validation</label></div>
      <div class="col-md-3 form-check mt-2"><input class="form-check-input" type="checkbox" name="visible_encadrant" value="1" id="vis2"><label class="form-check-label" for="vis2">Visible encadrant</label></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Ajouter l’exigence</button></div>
    </form>
  </div></div></div>
</div>
<div class="card app-card mt-4"><div class="table-responsive"><table class="table table-dark align-middle mb-0">
  <thead><tr><th>Catégorie</th><th>Dépôt</th><th>Encadrant</th><th>État</th><th></th></tr></thead>
  <tbody><?php foreach ($types as $type): ?><tr>
    <td><?= e((string) $type['libelle']) ?><br><small><?= e((string) $type['description']) ?></small></td>
    <td><?= e(stage_doc_depositors()[(string) $type['deposant']] ?? (string) $type['deposant']) ?></td>
    <td><?= (int) $type['visible_encadrant'] === 1 ? 'Oui' : 'Non' ?></td>
    <td><?= (int) $type['actif'] === 1 ? 'Active' : 'Retirée' ?></td>
    <td><?php if ((int) $type['actif'] === 1): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="type_off"><input type="hidden" name="id_type" value="<?= (int) $type['id_type'] ?>"><button class="btn btn-sm btn-outline-light" type="submit">Retirer</button></form><?php endif; ?></td>
  </tr><?php endforeach; ?></tbody>
</table></div></div>
<div class="card app-card mt-4"><div class="table-responsive"><table class="table table-dark align-middle mb-0">
  <thead><tr><th>Exigence</th><th>Portée</th><th>Limite</th><th>État</th><th></th></tr></thead>
  <tbody><?php foreach ($requirements as $requirement): ?><tr>
    <td><?= e((string) $requirement['libelle']) ?> · <?= (int) $requirement['obligatoire'] === 1 ? 'obligatoire' : 'facultative' ?></td>
    <td><?= $requirement['id_campagne'] ? 'Campagne #' . (int) $requirement['id_campagne'] : 'Toutes campagnes' ?><?= $requirement['id_spec'] ? ' · spécialité #' . (int) $requirement['id_spec'] : '' ?><?= $requirement['niveau'] !== '' ? ' · niveau ' . e((string) $requirement['niveau']) : '' ?></td>
    <td><?= e((string) ($requirement['date_limite'] ?: '—')) ?></td>
    <td><?= (int) $requirement['actif'] === 1 ? 'Active' : 'Retirée' ?></td>
    <td><?php if ((int) $requirement['actif'] === 1): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="exigence_off"><input type="hidden" name="id_exigence" value="<?= (int) $requirement['id_exigence'] ?>"><button class="btn btn-sm btn-outline-light" type="submit">Retirer</button></form><?php endif; ?></td>
  </tr><?php endforeach; ?><?php if (!$requirements): ?><tr><td colspan="5" class="text-secondary text-center py-4">Aucune exigence configurée.</td></tr><?php endif; ?></tbody>
</table></div></div>
<?php page_foot();
<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_login();
access_ensure();
if (!is_admin() && !role_can('stage.documents.archive') && !stage_doc_is_supervisor()) {
    http_response_code(403);
    exit('Action non autorisée pour ce compte.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        stage_doc_zip((array) ($_POST['version'] ?? []));
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
}
$filters = [
    'annee' => (string) ($_GET['annee'] ?? ''),
    'q' => (string) ($_GET['q'] ?? ''),
    'id_spec' => (int) ($_GET['id_spec'] ?? 0),
    'periode' => (string) ($_GET['periode'] ?? ''),
    'id_organisme' => (int) ($_GET['id_organisme'] ?? 0),
    'id_service' => (int) ($_GET['id_service'] ?? 0),
    'id_type' => (int) ($_GET['id_type'] ?? 0),
    'statut' => (string) ($_GET['statut'] ?? ''),
    'du' => (string) ($_GET['du'] ?? ''),
    'au' => (string) ($_GET['au'] ?? ''),
    'cloture' => (string) ($_GET['cloture'] ?? ''),
    'filtre' => (string) ($_GET['filtre'] ?? ''),
    'id_placement' => (int) ($_GET['id_placement'] ?? 0),
];
$rows = stage_doc_archive($filters);
$types = db()->query('SELECT id_type, libelle FROM stage_doc_type WHERE actif = 1 ORDER BY libelle')->fetchAll();
$hosts = db()->query('SELECT id_organisme, nom FROM stage_organisme ORDER BY nom')->fetchAll();
$services = db()->query('SELECT s.id_service, s.nom, o.nom AS organisme FROM stage_service s JOIN stage_organisme o ON o.id_organisme = s.id_organisme ORDER BY o.nom, s.nom')->fetchAll();
$specialties = db()->query('SELECT id_spec, nom_spec FROM specialite ORDER BY nom_spec')->fetchAll();
$embed = app_is_embedded() ? 'embed=1' : '';
page_head('Archives des dossiers de stage', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Stages</p>
  <h1 class="display-6 fw-bold mb-1">Archives des dossiers de stage</h1>
  <p class="text-secondary mb-0">Documents liés à chaque affectation, y compris les stages clos. La clôture ne supprime rien.</p>
</div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="get" class="row g-2 mb-3">
  <?php if ($embed !== ''): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
  <div class="col-md-2"><input class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Nom ou identifiant" aria-label="Nom ou identifiant"></div>
  <div class="col-md-2"><input class="form-control" name="annee" value="<?= e($filters['annee']) ?>" placeholder="Année" aria-label="Année"></div>
  <div class="col-md-2"><select class="form-select" name="periode" aria-label="Période"><option value="">Période</option><?php foreach (academic_periods() as $period): ?><option value="<?= e((string) $period['code']) ?>" <?= $filters['periode'] === (string) $period['code'] ? 'selected' : '' ?>><?= e((string) $period['code']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select class="form-select" name="id_spec" aria-label="Spécialité"><option value="">Spécialité</option><?php foreach ($specialties as $specialty): ?><option value="<?= (int) $specialty['id_spec'] ?>" <?= $filters['id_spec'] === (int) $specialty['id_spec'] ? 'selected' : '' ?>><?= e((string) $specialty['nom_spec']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select class="form-select" name="id_organisme" aria-label="Établissement"><option value="">Établissement</option><?php foreach ($hosts as $host): ?><option value="<?= (int) $host['id_organisme'] ?>" <?= $filters['id_organisme'] === (int) $host['id_organisme'] ? 'selected' : '' ?>><?= e((string) $host['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select class="form-select" name="id_service" aria-label="Service"><option value="">Service</option><?php foreach ($services as $service): ?><option value="<?= (int) $service['id_service'] ?>" <?= $filters['id_service'] === (int) $service['id_service'] ? 'selected' : '' ?>><?= e($service['organisme'] . ' · ' . $service['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select class="form-select" name="id_type" aria-label="Catégorie"><option value="">Catégorie</option><?php foreach ($types as $type): ?><option value="<?= (int) $type['id_type'] ?>" <?= $filters['id_type'] === (int) $type['id_type'] ? 'selected' : '' ?>><?= e((string) $type['libelle']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select class="form-select" name="statut" aria-label="État"><option value="">État</option><?php foreach (stage_doc_statuses() as $code => $label): ?><option value="<?= e($code) ?>" <?= $filters['statut'] === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><input class="form-control" type="date" name="du" value="<?= e($filters['du']) ?>" aria-label="Déposé à partir du"></div>
  <div class="col-md-2"><input class="form-control" type="date" name="au" value="<?= e($filters['au']) ?>" aria-label="Déposé jusqu’au"></div>
  <div class="col-md-2"><select class="form-select" name="filtre" aria-label="Raccourci"><option value="">Tous les dossiers</option><?php foreach (['incomplet' => 'Dossiers incomplets', 'manquant' => 'Pièces manquantes', 'a_verifier' => 'En attente de validation', 'a_corriger' => 'À corriger', 'complet' => 'Dossiers complets', 'archive' => 'Archives'] as $code => $label): ?><option value="<?= e($code) ?>" <?= $filters['filtre'] === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 form-check ms-2 align-self-center"><input class="form-check-input" type="checkbox" name="cloture" value="1" id="cloture" <?= $filters['cloture'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="cloture">Stages clos</label></div>
  <div class="col-md-2"><button class="btn btn-outline-light w-100" type="submit">Filtrer</button></div>
</form>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <div class="d-flex justify-content-end mb-2"><button class="btn btn-primary" type="submit">Télécharger la sélection</button></div>
  <div class="card app-card"><div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0">
    <thead><tr><th></th><th>Étudiant</th><th>Stage</th><th>Catégorie</th><th>État</th><th>Dépôt</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?php if ((int) $row['id_version'] > 0): ?><input type="checkbox" name="version[]" value="<?= (int) $row['id_version'] ?>" aria-label="Sélectionner <?= e((string) $row['libelle']) ?>"><?php endif; ?></td>
        <td><?= e($row['prenom'] . ' ' . $row['nom']) ?><br><small><?= e((string) $row['num_inscription']) ?></small></td>
        <td><?= e((string) ($row['annee_label'] ?: $row['date_debut'])) ?> <?= e((string) $row['periode_code']) ?><br><small><?= e((string) $row['organisme']) ?><?= $row['service'] ? ' · ' . e((string) $row['service']) : '' ?></small></td>
        <td><?= e((string) $row['libelle']) ?></td>
        <td><?= e(stage_doc_statuses()[(string) $row['statut']] ?? (string) $row['statut']) ?></td>
        <td><?= e((string) $row['created_at']) ?></td>
        <td><?php if ((int) $row['id_version'] > 0): ?><a href="stage_document.php?version=<?= (int) $row['id_version'] ?>">Télécharger</a><br><?php endif; ?><a href="stage_dossier.php?id=<?= (int) $row['id_placement'] ?><?= $embed !== '' ? '&embed=1' : '' ?>">Ouvrir le dossier</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-secondary text-center py-4">Aucun document ne correspond à ces critères.</td></tr><?php endif; ?>
    </tbody>
  </table></div></div>
</form>
<?php page_foot();
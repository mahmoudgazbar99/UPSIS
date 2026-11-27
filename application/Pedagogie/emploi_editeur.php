<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_once app_path('support/emploi_smart.php');
require_admin();

emploi_smart_ensure();
$versionId = max(0, (int) ($_GET['id'] ?? $_POST['id_version'] ?? 0));
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $versionId = max(0, (int) ($_POST['id_version'] ?? $versionId));
    try {
        if ($action === 'update_session') {
            emploi_smart_update_session((int) ($_POST['id_session'] ?? 0), [
                'jour' => (string) ($_POST['jour'] ?? ''),
                'heure_debut' => (string) ($_POST['heure_debut'] ?? ''),
                'heure_fin' => (string) ($_POST['heure_fin'] ?? ''),
                'id_enseignant' => (int) ($_POST['id_enseignant'] ?? 0),
                'id_ressource' => (int) ($_POST['id_ressource'] ?? 0),
                'semaine' => (int) ($_POST['semaine'] ?? 1),
                'week_pattern' => (string) ($_POST['week_pattern'] ?? 'weekly'),
                'locked' => !empty($_POST['locked']) ? 1 : 0,
                'force' => !empty($_POST['force']),
            ]);
            $message = 'Séance mise à jour.';
        } elseif ($action === 'delete_session') {
            emploi_smart_delete_session((int) ($_POST['id_session'] ?? 0));
            $message = 'Séance supprimée.';
        } elseif ($action === 'publish') {
            emploi_smart_publish($versionId, !empty($_POST['force']));
            $message = 'Version publiée.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('emploi_editeur: ' . $exception->getMessage());
        $error = 'Modification refusée.';
    }
}

$version = $versionId > 0 ? emploi_smart_get_version($versionId) : null;
if (!$version) {
    flash('danger', 'Choisissez une version depuis le générateur.');
    header('Location: emploi_generateur.php');
    exit;
}

$filters = [
    'id_groupe' => (int) ($_GET['id_groupe'] ?? 0) ?: null,
    'id_enseignant' => (int) ($_GET['id_enseignant'] ?? 0) ?: null,
    'id_ressource' => (int) ($_GET['id_ressource'] ?? 0) ?: null,
    'id_mat' => (int) ($_GET['id_mat'] ?? 0) ?: null,
    'type_seance' => trim((string) ($_GET['type_seance'] ?? '')) ?: null,
];
$filters = array_filter($filters, static fn($v) => $v !== null && $v !== '');
$sessions = emploi_smart_list_sessions($versionId, $filters);
$allSessions = emploi_smart_list_sessions($versionId);
$config = (array) $version['config'];
$days = (array) ($config['working_days'] ?? ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']);
$resources = emploi_smart_list_resources(true);
$teachers = db()->query('SELECT id_enseignant, nom, prenom FROM enseignant WHERE active = 1 ORDER BY nom, prenom')->fetchAll();
$groups = emploi_smart_groups_for_semestre((string) $version['semestre']);
$matieres = emploi_smart_matieres_for_semestre((string) $version['semestre']);
$editId = max(0, (int) ($_GET['edit'] ?? 0));

page_head('Éditeur d’emploi du temps', 'schedule');
?>
<div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
  <div>
    <p class="eyebrow">Pédagogie</p>
    <h1 class="display-6 fw-bold mb-1">Éditeur grille</h1>
    <p class="text-secondary mb-0"><?= e((string) $version['label']) ?> · <?= e((string) $version['status']) ?> · <?= count($allSessions) ?> séances</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-light" href="emploi_generateur.php?id=<?= (int) $versionId ?>&step=6">Générateur</a>
    <a class="btn btn-outline-light" href="emploi_export.php?id=<?= (int) $versionId ?>&format=print" target="_blank">Imprimer</a>
    <form method="post" class="d-inline" onsubmit="return confirm('Publier cette version ?');">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id_version" value="<?= (int) $versionId ?>">
      <input type="hidden" name="action" value="publish">
      <button class="btn btn-primary" type="submit">Publier</button>
    </form>
  </div>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="get" class="card app-card mb-4"><div class="card-body p-3 row g-2 align-items-end">
  <input type="hidden" name="id" value="<?= (int) $versionId ?>">
  <div class="col-md-2">
    <label class="form-label">Groupe</label>
    <select class="form-select form-select-sm" name="id_groupe" data-app-list-prompt data-app-list-title="Groupe" data-app-list-search="Groupe…" data-app-list-placeholder="Tous les groupes">
      <option value="">Tous</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= (int) $g['id_groupe'] ?>" <?= ((int) ($_GET['id_groupe'] ?? 0) === (int) $g['id_groupe']) ? 'selected' : '' ?>><?= e($g['type_groupe'] . ' ' . $g['numero']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Enseignant</label>
    <select class="form-select form-select-sm" name="id_enseignant" data-app-list-prompt data-app-list-title="Enseignant" data-app-list-search="Nom…" data-app-list-placeholder="Tous les enseignants">
      <option value="">Tous</option>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= (int) $t['id_enseignant'] ?>" <?= ((int) ($_GET['id_enseignant'] ?? 0) === (int) $t['id_enseignant']) ? 'selected' : '' ?>><?= e($t['prenom'] . ' ' . $t['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Ressource</label>
    <select class="form-select form-select-sm" name="id_ressource" data-app-list-prompt data-app-list-title="Ressource" data-app-list-search="Salle…" data-app-list-placeholder="Toutes les ressources">
      <option value="">Toutes</option>
      <?php foreach ($resources as $r): ?>
        <option value="<?= (int) $r['id_ressource'] ?>" <?= ((int) ($_GET['id_ressource'] ?? 0) === (int) $r['id_ressource']) ? 'selected' : '' ?>><?= e((string) $r['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Type</label>
    <select class="form-select form-select-sm" name="type_seance">
      <option value="">Tous</option>
      <?php foreach (['Cours','TD','TP'] as $t): ?>
        <option value="<?= $t ?>" <?= (($_GET['type_seance'] ?? '') === $t) ? 'selected' : '' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Matière</label>
    <select class="form-select form-select-sm" name="id_mat" data-app-list-prompt data-app-list-title="Matière" data-app-list-search="Code ECUE…" data-app-list-placeholder="Toutes les matières">
      <option value="">Toutes</option>
      <?php foreach ($matieres as $m): ?>
        <option value="<?= (int) $m['id_mat'] ?>" <?= ((int) ($_GET['id_mat'] ?? 0) === (int) $m['id_mat']) ? 'selected' : '' ?>><?= e((string) $m['code_ecue']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-light btn-sm w-100" type="submit">Filtrer</button></div>
</div></form>

<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5 mb-3">Emploi du temps</h2>
  <?php if (!$sessions): ?>
    <p class="text-secondary mb-0">Aucune séance pour ces filtres.</p>
  <?php else: ?>
    <?= emploi_smart_render_timetable_html($sessions, [
      'days' => $days,
      'config' => $config,
      'editable' => false,
      'theme' => 'light',
    ]) ?>
  <?php endif; ?>
</div></div>

<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5 mb-3">Modifier une séance</h2>
  <p class="text-secondary">Choisissez une séance dans la liste. Les conflits enseignant / groupe / ressource exclusive sont refusés.</p>
  <form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="id" value="<?= (int) $versionId ?>">
    <?php foreach (['id_groupe','id_enseignant','id_ressource','id_mat','type_seance'] as $keep): ?>
      <?php if (!empty($_GET[$keep])): ?><input type="hidden" name="<?= e($keep) ?>" value="<?= e((string) $_GET[$keep]) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <div class="col-md-8">
      <label class="form-label" for="edit">Séance</label>
      <select class="form-select" id="edit" name="edit" onchange="this.form.submit()">
        <option value="">Choisir…</option>
        <?php foreach ($sessions as $s): ?>
          <option value="<?= (int) $s['id_session'] ?>" <?= $editId === (int) $s['id_session'] ? 'selected' : '' ?>>
            <?= e($s['jour'] . ' ' . substr((string) $s['heure_debut'], 0, 5) . '–' . substr((string) $s['heure_fin'], 0, 5) . ' · ' . $s['type_seance'] . ' · ' . $s['code_ecue'] . ' · ' . $s['type_groupe'] . ' ' . $s['numero']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php
    $editSession = null;
    foreach ($sessions as $s) {
        if ((int) $s['id_session'] === $editId) {
            $editSession = $s;
            break;
        }
    }
  ?>
  <?php if ($editSession): ?>
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id_version" value="<?= (int) $versionId ?>">
      <input type="hidden" name="id_session" value="<?= (int) $editSession['id_session'] ?>">
      <div class="col-md-3">
        <label class="form-label">Jour</label>
        <select class="form-select" name="jour">
          <?php foreach ($days as $d): ?>
            <option value="<?= e($d) ?>" <?= $editSession['jour'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">Début</label><input class="form-control" type="time" name="heure_debut" value="<?= e(substr((string) $editSession['heure_debut'], 0, 5)) ?>"></div>
      <div class="col-md-2"><label class="form-label">Fin</label><input class="form-control" type="time" name="heure_fin" value="<?= e(substr((string) $editSession['heure_fin'], 0, 5)) ?>"></div>
      <div class="col-md-2"><label class="form-label">Semaine</label><input class="form-control" type="number" name="semaine" value="<?= (int) $editSession['semaine'] ?>" min="1"></div>
      <div class="col-md-3">
        <label class="form-label">Motif</label>
        <select class="form-select" name="week_pattern">
          <?php foreach (['weekly','A','B','specific'] as $p): ?>
            <option value="<?= $p ?>" <?= $editSession['week_pattern'] === $p ? 'selected' : '' ?>><?= $p ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Enseignant</label>
        <select class="form-select" name="id_enseignant" data-app-list-prompt data-app-list-title="Enseignant" data-app-list-search="Nom…" data-app-list-placeholder="Choisir…">
          <?php foreach ($teachers as $t): ?>
            <option value="<?= (int) $t['id_enseignant'] ?>" <?= (int) $editSession['id_enseignant'] === (int) $t['id_enseignant'] ? 'selected' : '' ?>><?= e($t['prenom'] . ' ' . $t['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Ressource</label>
        <select class="form-select" name="id_ressource" data-app-list-prompt data-app-list-title="Ressource" data-app-list-search="Salle…" data-app-list-placeholder="Choisir…">
          <?php foreach ($resources as $r): ?>
            <option value="<?= (int) $r['id_ressource'] ?>" <?= (int) $editSession['id_ressource'] === (int) $r['id_ressource'] ? 'selected' : '' ?>><?= e((string) $r['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <label class="form-check"><input class="form-check-input" type="checkbox" name="locked" value="1" <?= !empty($editSession['locked']) ? 'checked' : '' ?>> <span class="form-check-label">Verrouiller (protégée à la régénération)</span></label>
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary" name="action" value="update_session" type="submit">Enregistrer</button>
        <button class="btn btn-outline-danger" name="action" value="delete_session" type="submit" onclick="return confirm('Supprimer cette séance ?');">Supprimer</button>
      </div>
    </form>
  <?php endif; ?>
</div></div>
<?php page_foot();

<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_once app_path('support/student_theme.php');
require_login();
access_ensure();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$placement = stage_doc_placement($id);
if ($placement === null || !stage_doc_may_open($placement)) {
    http_response_code(404);
    exit('Dossier introuvable.');
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'deposer') {
            stage_doc_receive_upload($id, (int) ($_POST['id_type'] ?? 0), (array) ($_FILES['fichier'] ?? []));
            $message = 'Document enregistré.';
        } elseif ($action === 'valider') {
            stage_doc_review((int) ($_POST['id_piece'] ?? 0), 'valide');
            $message = 'Document validé.';
        } elseif ($action === 'refuser') {
            stage_doc_review((int) ($_POST['id_piece'] ?? 0), 'a_corriger', (string) ($_POST['motif'] ?? ''));
            $message = 'Document renvoyé pour correction.';
        } elseif ($action === 'archiver') {
            stage_doc_review((int) ($_POST['id_piece'] ?? 0), 'archive');
            $message = 'Document archivé. Le fichier reste conservé.';
        } elseif ($action === 'retirer') {
            stage_doc_review((int) ($_POST['id_piece'] ?? 0), 'retire', (string) ($_POST['motif'] ?? ''));
            $message = 'Document retiré du dossier actif. L’historique est conservé.';
        } elseif ($action === 'zip') {
            $ids = [];
            foreach (stage_doc_pieces($id) as $piece) {
                if ((int) ($piece['id_version'] ?? 0) > 0 && stage_doc_type_visible($piece, $placement)) {
                    $ids[] = (int) $piece['id_version'];
                }
            }
            stage_doc_zip($ids);
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Stage document upload failed: ' . $exception->getMessage());
        $error = 'Le dépôt n’a pas abouti. Vous pouvez le relancer.';
    }
    if (stage_doc_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $error === '', 'message' => $error !== '' ? $error : $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$state = stage_doc_dossier_state($placement);
$types = db()->query('SELECT * FROM stage_doc_type WHERE actif = 1 ORDER BY libelle')->fetchAll();
$uploadTypes = [];
foreach ($types as $type) {
    $rule = stage_doc_rule_for_type($placement, (int) $type['id_type']);
    $piece = stage_doc_piece_for_type($state['pieces'], (int) $type['id_type']);
    if (stage_doc_may_upload($placement, $rule, $piece) && stage_doc_type_visible($rule, $placement)) {
        $uploadTypes[] = $rule;
    }
}
$embed = app_is_embedded() ? '&embed=1' : '';
$studentView = is_student();
$locked = stage_doc_placement_locked($placement);
$statuses = stage_doc_statuses();
$bytes = stage_doc_max_bytes();

if ($studentView) {
    $studentName = trim((string) $placement['prenom'] . ' ' . (string) $placement['nom']);
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php student_theme_head(); ?>
  <title>Dossier documentaire du stage</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/theme-tokens.css?v=1" rel="stylesheet">
  <link href="assets/motion.css?v=3" rel="stylesheet">
  <link href="assets/student-space.css?v=6" rel="stylesheet">
  <link href="assets/student-theme.css?v=11" rel="stylesheet">
  <?php stage_doc_styles(); ?>
</head>
<body class="student-space">
  <header class="student-space-bar">
    <div><p><?= e(institution_name_inline()) ?></p><strong><?= e($studentName) ?></strong></div>
    <div class="student-space-bar-actions"><?php student_theme_select(); ?><a href="dashboard.php">Retour</a></div>
  </header>
  <main class="student-space-main">
<?php
} else {
    page_head('Dossier documentaire du stage', 'presence');
    stage_doc_styles();
}
?>
<div class="mb-4">
  <p class="eyebrow">Stages</p>
  <h1 class="display-6 fw-bold mb-1">Dossier documentaire du stage</h1>
  <p class="text-secondary mb-0"><?= e($placement['prenom'] . ' ' . $placement['nom']) ?> · <?= e((string) $placement['num_inscription']) ?></p>
</div>
<?php if ($message): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4"><div class="card-body">
  <dl class="row mb-0 stage-doc-meta">
    <div class="col-md-6"><dt>Spécialité</dt><dd><?= e((string) ($placement['nom_spec'] ?: '—')) ?></dd></div>
    <div class="col-md-6"><dt>Groupe</dt><dd><?= e((string) ($placement['groupes'] ?: '—')) ?></dd></div>
    <div class="col-md-6"><dt>Année et période</dt><dd><?= e(trim((string) $placement['annee_label'] . ' ' . (string) $placement['periode_code']) ?: '—') ?> · <?= e($placement['date_debut'] . ' → ' . $placement['date_fin']) ?></dd></div>
    <div class="col-md-6"><dt>Établissement</dt><dd><?= e((string) ($placement['organisme'] ?: '—')) ?><?= $placement['service'] ? ' · ' . e((string) $placement['service']) : '' ?></dd></div>
    <div class="col-md-6"><dt>Statut du stage</dt><dd><?= e(stage_statuses()[(string) $placement['statut']] ?? (string) $placement['statut']) ?></dd></div>
    <div class="col-md-6"><dt>Dossier</dt><dd><?= $state['mandatory'] === 0 ? 'Aucune pièce obligatoire configurée.' : ((int) $state['done'] . ' / ' . (int) $state['mandatory'] . ' pièces obligatoires validées') ?></dd></div>
  </dl>
</div></div>
<?php if ($locked): ?><div class="alert alert-secondary">Ce stage est clos. Les documents restent consultables. Un nouveau dépôt est réservé au responsable des stages.</div><?php endif; ?>
<div class="d-flex flex-wrap gap-2 mb-4">
  <?php if (!$studentView): ?><a class="btn btn-outline-light" href="stage_suivi.php?id=<?= $id ?><?= e($embed) ?>">Suivi du stage</a><?php endif; ?>
  <?php if (!$studentView && (role_can('stage.documents.archive') || stage_doc_is_supervisor())): ?><a class="btn btn-outline-light" href="stage_archives.php?id_placement=<?= $id ?><?= e($embed) ?>">Archives des stages</a><?php endif; ?>
  <form method="post"><?= '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="zip">' ?><button class="btn btn-outline-light" type="submit">Télécharger le dossier</button></form>
</div>

<div class="card app-card mb-4"><div class="card-body">
  <h2 class="h5">Documents obligatoires</h2>
  <?php $required = array_filter($state['rules'], static fn (array $rule): bool => (int) $rule['obligatoire'] === 1 && stage_doc_type_visible($rule, $placement)); ?>
  <?php if (!$required): ?><p class="text-secondary mb-0">Aucune pièce n’est marquée obligatoire pour ce stage.</p><?php endif; ?>
  <ul class="mb-0">
  <?php foreach ($required as $rule):
      $piece = stage_doc_piece_for_type($state['pieces'], (int) $rule['id_type']);
      $status = $piece ? (string) $piece['statut'] : 'manquant';
      $late = $status !== 'valide' && $status !== 'archive' && !empty($rule['date_limite']) && (string) $rule['date_limite'] < date('Y-m-d');
  ?>
    <li><?= e((string) $rule['libelle']) ?> · <?= e($statuses[$status] ?? $status) ?><?= $late ? ' · en retard' : '' ?><?php if ($rule['description']): ?> — <?= e((string) $rule['description']) ?><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
</div></div>

<?php if ($uploadTypes): ?>
<div class="card app-card mb-4"><div class="card-body">
  <h2 class="h5">Ajouter un document</h2>
  <form method="post" enctype="multipart/form-data" id="stage-upload-form" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="deposer">
    <div class="col-md-6"><label class="form-label" for="id_type">Catégorie</label>
      <select class="form-select" name="id_type" id="id_type" required>
        <?php foreach ($uploadTypes as $type): ?><option value="<?= (int) $type['id_type'] ?>"><?= e((string) $type['libelle']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <div class="stage-drop" id="stage-drop">
        <p class="mb-2">Déposez le fichier ici, ou choisissez-le.</p>
        <input class="form-control" type="file" name="fichier" id="stage-file" required accept=".<?= e(implode(',.', array_keys(stage_doc_extensions()))) ?>">
        <p class="stage-doc-meta text-secondary mb-2" id="stage-file-meta">Taille maximale : <?= e(number_format($bytes / 1048576, 1, ',', '')) ?> Mo. Formats : <?= e(implode(', ', array_keys(stage_doc_extensions()))) ?>.</p>
        <progress id="stage-progress" max="100" value="0" hidden></progress>
        <p id="stage-upload-msg" role="status"></p>
        <button class="btn btn-primary" type="submit">Envoyer</button>
        <button class="btn btn-outline-light" type="button" id="stage-retry" hidden>Relancer</button>
      </div>
    </div>
  </form>
</div></div>
<?php endif; ?>

<div class="card app-card"><div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0">
  <thead><tr><th>Document</th><th>État</th><th>Dépôt</th><th>Historique des versions</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($state['pieces'] as $piece):
      if (!stage_doc_type_visible($piece, $placement)) {
          continue;
      }
      $versions = stage_doc_versions((int) $piece['id_piece']);
  ?>
    <tr>
      <td><?= e((string) $piece['libelle']) ?><?php if ($piece['nom_original']): ?><br><small><?= e((string) $piece['nom_original']) ?></small><?php endif; ?></td>
      <td><?= e($statuses[(string) $piece['statut']] ?? (string) $piece['statut']) ?><?php if ((string) $piece['motif'] !== ''): ?><br><small><?= e((string) $piece['motif']) ?></small><?php endif; ?></td>
      <td><?php if ($piece['version_at']): ?><?= e((string) $piece['version_at']) ?><br><small><?= e(stage_doc_uploader_label((string) $piece['uploader_role'], (int) $piece['uploader_id'])) ?></small><?php else: ?>—<?php endif; ?></td>
      <td><?php foreach ($versions as $version): ?><div><?= (int) $version['numero'] === (int) ($piece['numero'] ?? 0) ? 'Version actuelle · ' : '' ?>v<?= (int) $version['numero'] ?> · <a href="stage_document.php?version=<?= (int) $version['id_version'] ?><?= is_student() ? '&amp;espace=etudiant' : '' ?>">Télécharger</a><?php if (in_array((string) $version['mime'], ['image/jpeg', 'image/png'], true)): ?> · <a href="stage_document.php?version=<?= (int) $version['id_version'] ?>&amp;apercu=1<?= is_student() ? '&amp;espace=etudiant' : '' ?>">Aperçu</a><?php endif; ?></div><?php endforeach; ?></td>
      <td>
        <?php if (stage_doc_may_review() && (string) $piece['statut'] === 'a_verifier'): ?>
          <form method="post" class="d-flex flex-column gap-1">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="id_piece" value="<?= (int) $piece['id_piece'] ?>">
            <button class="btn btn-sm btn-primary" name="action" value="valider" type="submit">Valider</button>
            <input class="form-control form-control-sm" name="motif" placeholder="Motif si correction" aria-label="Motif de correction">
            <button class="btn btn-sm btn-outline-light" name="action" value="refuser" type="submit">À corriger</button>
          </form>
        <?php endif; ?>
        <?php if (stage_doc_may_review() && !in_array((string) $piece['statut'], ['archive', 'retire'], true)): ?>
          <form method="post" class="mt-1"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="id_piece" value="<?= (int) $piece['id_piece'] ?>"><button class="btn btn-sm btn-outline-light" name="action" value="archiver" type="submit">Archiver</button></form>
        <?php endif; ?>
        <?php if (stage_doc_may_review() && (string) $piece['statut'] !== 'retire'): ?>
          <form method="post" class="mt-1 d-flex flex-column gap-1"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="id_piece" value="<?= (int) $piece['id_piece'] ?>"><input class="form-control form-control-sm" name="motif" placeholder="Motif du retrait" aria-label="Motif du retrait"><button class="btn btn-sm btn-outline-light" name="action" value="retirer" type="submit">Retirer</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$state['pieces']): ?><tr><td colspan="5" class="text-secondary text-center py-4">Aucun document déposé pour ce stage.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<script>
(function () {
  var form = document.getElementById('stage-upload-form');
  if (!form) return;
  var drop = document.getElementById('stage-drop');
  var input = document.getElementById('stage-file');
  var meta = document.getElementById('stage-file-meta');
  var progress = document.getElementById('stage-progress');
  var msg = document.getElementById('stage-upload-msg');
  var retry = document.getElementById('stage-retry');
  function describe() {
    var file = input.files && input.files[0];
    if (!file) return;
    meta.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' Ko';
  }
  input.addEventListener('change', describe);
  ['dragenter', 'dragover'].forEach(function (name) {
    drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (name) {
    drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.remove('is-over'); });
  });
  drop.addEventListener('drop', function (event) {
    if (!event.dataTransfer || !event.dataTransfer.files.length) return;
    input.files = event.dataTransfer.files;
    describe();
  });
  function send() {
    var data = new FormData(form);
    var request = new XMLHttpRequest();
    progress.hidden = false;
    progress.value = 0;
    retry.hidden = true;
    msg.textContent = 'Envoi en cours…';
    request.open('POST', 'stage_dossier.php');
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.upload.onprogress = function (event) {
      if (event.lengthComputable) progress.value = Math.round(event.loaded * 100 / event.total);
    };
    request.onload = function () {
      var payload = {};
      try { payload = JSON.parse(request.responseText); } catch (error) {}
      if (request.status >= 200 && request.status < 300 && payload.ok) {
        window.location.reload();
        return;
      }
      msg.textContent = payload.message || 'Le dépôt a échoué. Vous pouvez le relancer.';
      retry.hidden = false;
    };
    request.onerror = function () {
      msg.textContent = 'La connexion a été interrompue. Vous pouvez le relancer.';
      retry.hidden = false;
    };
    request.send(data);
  }
  form.addEventListener('submit', function (event) { event.preventDefault(); send(); });
  retry.addEventListener('click', send);
})();
</script>
<?php
if ($studentView) {
    echo '<script src="assets/motion.js?v=3" defer></script></main></body></html>';
} else {
    page_foot();
}

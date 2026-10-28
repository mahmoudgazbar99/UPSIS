<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_permission('stage.view');
access_ensure();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$query = db()->prepare(
    'SELECT p.*, e.nom, e.prenom, e.num_inscription, o.nom AS organisme
     FROM stage_placement p
     JOIN etudiant e ON e.id_etudiant = p.id_etudiant
     JOIN stage_organisme o ON o.id_organisme = p.id_organisme
     WHERE p.id_placement = ?'
);
$query->execute([$id]);
$placement = $query->fetch();
if (!$placement || !stage_placement_visible($placement)) {
    http_response_code(404);
    exit('Stage introuvable.');
}
$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'statut') {
            if (!role_can('stage.place')) {
                throw new InvalidArgumentException('Vous ne pouvez pas changer ce statut.');
            }
            $next = (string) ($_POST['statut'] ?? '');
            if (!isset(stage_statuses()[$next]) || !stage_status_allowed((string) $placement['statut'], $next)) {
                throw new InvalidArgumentException('Ce changement de statut n’est pas permis.');
            }
            if ($next === 'termine' && (string) $placement['statut'] !== 'valide') {
                throw new InvalidArgumentException('Le stage doit être validé avant d’être marqué terminé.');
            }
            if (in_array($next, ['approuve', 'affecte', 'en_cours'], true)) {
                stage_assert_placement((int) $placement['id_etudiant'], (int) $placement['id_organisme'], (string) $placement['date_debut'], (string) $placement['date_fin'], $id);
            }
            db()->prepare('UPDATE stage_placement SET statut = ? WHERE id_placement = ?')->execute([$next, $id]);
            audit_record('stage', 'statut', 'Stage #' . $id . ' ' . $placement['statut'] . ' → ' . $next);
            $message = 'Statut mis à jour.';
            $placement['statut'] = $next;
        } elseif ($action === 'suivi') {
            $hours = (float) str_replace(',', '.', (string) ($_POST['heures'] ?? '0'));
            $note = trim((string) ($_POST['observation'] ?? ''));
            if ($note === '' || $hours < 0) {
                throw new InvalidArgumentException('Saisissez l’observation et des heures positives.');
            }
            db()->prepare('INSERT INTO stage_suivi (id_placement, jour, heures, observation, auteur_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, (string) ($_POST['jour'] ?? date('Y-m-d')), $hours, $note, current_stage_id()]);
            audit_record('stage', 'suivi', 'Suivi du stage #' . $id);
            $message = 'Suivi enregistré. Les notes académiques ne sont pas modifiées.';
        } elseif ($action === 'evaluation_superviseur') {
            if (!role_can('stage.evaluate') || (int) ($placement['id_stage_superviseur'] ?? 0) !== current_stage_id()) {
                throw new InvalidArgumentException('Seul l’encadrant affecté peut remplir cette évaluation.');
            }
            stage_eval_save($id, 'superviseur', (array) ($_POST['appreciation'] ?? []), trim((string) ($_POST['commentaire'] ?? '')), current_stage_id(), (string) ($_POST['envoi'] ?? '') === '1');
            $message = (string) ($_POST['envoi'] ?? '') === '1' ? 'Évaluation d’encadrant envoyée.' : 'Brouillon d’encadrant enregistré.';
        } elseif ($action === 'evaluation_responsable') {
            if (!role_can('stage.review')) {
                throw new InvalidArgumentException('Seul le responsable des stages peut remplir cette évaluation.');
            }
            stage_eval_save($id, 'responsable', (array) ($_POST['appreciation'] ?? []), trim((string) ($_POST['commentaire'] ?? '')), current_stage_id(), false);
            $message = 'Évaluation du responsable enregistrée. Elle ne remplace pas celle de l’encadrant.';
        } elseif ($action === 'retour') {
            if (!role_can('stage.review')) {
                throw new InvalidArgumentException('Seul le responsable peut renvoyer une évaluation.');
            }
            stage_eval_return($id, current_stage_id(), trim((string) ($_POST['commentaire'] ?? '')));
            $message = 'Évaluation renvoyée à l’encadrant.';
        } elseif ($action === 'valider') {
            if (!role_can('stage.review')) {
                throw new InvalidArgumentException('Seul le responsable peut valider le stage.');
            }
            stage_eval_validate($id, current_stage_id());
            $placement['statut'] = 'valide';
            $message = 'Stage validé. Les deux évaluations restent distinctes.';
        } elseif ($action === 'document') {
            if (!role_can('stage.place') && !role_can('stage.evaluate')) {
                throw new InvalidArgumentException('Dépôt de document non autorisé.');
            }
            $file = $_FILES['fichier'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 2_000_000) {
                throw new InvalidArgumentException('Le fichier doit peser moins de 2 Mo.');
            }
            $info = new finfo(FILEINFO_MIME_TYPE);
            $mime = $info->file((string) $file['tmp_name']) ?: '';
            if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                throw new InvalidArgumentException('Formats acceptés : PDF, JPG, PNG.');
            }
            $content = file_get_contents((string) $file['tmp_name']);
            $title = trim((string) ($_POST['titre'] ?? ''));
            if ($title === '' || $content === false) {
                throw new InvalidArgumentException('Donnez un titre au document.');
            }
            $statement = db()->prepare('INSERT INTO stage_document (id_placement, titre, mime, contenu) VALUES (?, ?, ?, ?)');
            $statement->bindValue(1, $id, PDO::PARAM_INT);
            $statement->bindValue(2, $title);
            $statement->bindValue(3, $mime);
            $statement->bindValue(4, $content, PDO::PARAM_LOB);
            $statement->execute();
            audit_record('stage', 'document', 'Document « ' . $title . ' » sur le stage #' . $id);
            $message = 'Document enregistré.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
    if ($error === '') {
        $query->execute([$id]);
        $placement = $query->fetch() ?: $placement;
    }
}
$followups = db()->prepare('SELECT * FROM stage_suivi WHERE id_placement = ? ORDER BY jour DESC');
$followups->execute([$id]);
$followups = $followups->fetchAll();
$legacyEvaluations = db()->prepare('SELECT origine, note_valeur, commentaire, created_at FROM stage_evaluation WHERE id_placement = ? ORDER BY id_evaluation DESC');
$legacyEvaluations->execute([$id]);
$legacyEvaluations = $legacyEvaluations->fetchAll();
$supervisorEval = stage_eval_dossier($id, 'superviseur');
$managerEval = role_can('stage.review') ? stage_eval_dossier($id, 'responsable') : null;
$supervisorCriteria = stage_criteria('superviseur');
$managerCriteria = stage_criteria('responsable');
$appreciationLabels = ['acquis' => 'Acquis', 'en_cours' => 'En cours d’acquisition', 'non_acquis' => 'Non acquis'];
$documents = db()->prepare('SELECT id_document, titre, mime, created_at FROM stage_document WHERE id_placement = ? ORDER BY id_document DESC');
$documents->execute([$id]);
$documents = $documents->fetchAll();
$hours = 0.0;
foreach ($followups as $line) {
    $hours += (float) $line['heures'];
}
page_head('Suivi de stage', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Stages</p>
  <h1 class="display-6 fw-bold mb-1"><?= e($placement['prenom'] . ' ' . $placement['nom']) ?></h1>
  <p class="text-secondary mb-0"><?= e($placement['organisme']) ?> · <?= e($placement['date_debut'] . ' → ' . $placement['date_fin']) ?> · <?= e(stage_statuses()[$placement['statut']] ?? $placement['statut']) ?> · <?= e(number_format($hours, 1, ',', '')) ?> h suivies / <?= e(number_format((float) $placement['heures_prevues'], 1, ',', '')) ?> h prévues</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($placement['objectifs'])): ?><div class="card app-card mb-4"><div class="card-body"><strong>Objectifs.</strong> <?= e((string) $placement['objectifs']) ?></div></div><?php endif; ?>
<div class="row g-4">
  <?php if (role_can('stage.place')): ?>
  <div class="col-lg-4"><div class="card app-card h-100"><div class="card-body">
    <h2 class="h5">Statut</h2>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="statut">
      <div class="col-12"><select class="form-select" name="statut"><?php foreach (stage_statuses() as $code => $label): ?><option value="<?= e($code) ?>" <?= $placement['statut'] === $code ? 'selected' : '' ?> <?= stage_status_allowed((string) $placement['statut'], $code) ? '' : 'disabled' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Appliquer</button></div>
    </form>
  </div></div></div>
  <?php endif; ?>
  <div class="col-lg-4"><div class="card app-card h-100"><div class="card-body">
    <h2 class="h5">Suivi</h2>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="suivi">
      <div class="col-6"><input class="form-control" type="date" name="jour" value="<?= e(date('Y-m-d')) ?>" required></div>
      <div class="col-6"><input class="form-control" name="heures" inputmode="decimal" placeholder="Heures" required></div>
      <div class="col-12"><textarea class="form-control" name="observation" rows="2" required placeholder="Observation"></textarea></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Ajouter</button></div>
    </form>
  </div></div></div>
  <?php if (role_can('stage.evaluate') && (int) ($placement['id_stage_superviseur'] ?? 0) === current_stage_id()): ?>
  <div class="col-lg-6"><div class="card app-card h-100"><div class="card-body">
    <h2 class="h5">Évaluation de l’encadrant</h2>
    <p class="text-secondary">Statut : <?= e((string) ($supervisorEval['statut'] ?? 'non commencée')) ?>. Cette fiche n’est pas celle du responsable.</p>
    <?php $locked = $supervisorEval && in_array((string) $supervisorEval['statut'], ['soumis', 'valide'], true); ?>
    <?php if ($locked): ?>
      <ul><?php foreach ($supervisorCriteria as $criterion): ?><li><?= e($criterion['libelle']) ?> · <?= e($appreciationLabels[$supervisorEval['lignes'][(int) $criterion['id_critere']] ?? ''] ?? '—') ?></li><?php endforeach; ?></ul>
      <p class="mb-0"><?= e((string) ($supervisorEval['commentaire'] ?? '')) ?></p>
    <?php else: ?>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="evaluation_superviseur">
      <?php foreach ($supervisorCriteria as $criterion): ?>
        <div class="col-12"><label class="form-label"><?= e($criterion['libelle']) ?></label>
          <select class="form-select" name="appreciation[<?= (int) $criterion['id_critere'] ?>]">
            <option value="">—</option>
            <?php foreach ($appreciationLabels as $code => $label): ?><option value="<?= e($code) ?>" <?= (($supervisorEval['lignes'][(int) $criterion['id_critere']] ?? '') === $code) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
      <div class="col-12"><textarea class="form-control" name="commentaire" rows="2" placeholder="Observations et recommandations"><?= e((string) ($supervisorEval['commentaire'] ?? '')) ?></textarea></div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-outline-light" type="submit">Brouillon</button><button class="btn btn-primary" name="envoi" value="1" type="submit">Envoyer</button></div>
    </form>
    <?php endif; ?>
  </div></div></div>
  <?php elseif (role_can('stage.evaluate')): ?>
  <div class="col-lg-6"><div class="card app-card"><div class="card-body"><p class="mb-0">Cette fiche n’est pas affectée à votre encadrement.</p></div></div></div>
  <?php endif; ?>
  <?php if (role_can('stage.review')): ?>
  <div class="col-lg-6"><div class="card app-card h-100"><div class="card-body">
    <h2 class="h5">Évaluation du responsable</h2>
    <p class="text-secondary">Évaluation de l’encadrant : <?= e((string) ($supervisorEval['statut'] ?? 'absente')) ?>.</p>
    <?php if ($supervisorEval): ?><ul><?php foreach ($supervisorCriteria as $criterion): ?><li><?= e($criterion['libelle']) ?> · <?= e($appreciationLabels[$supervisorEval['lignes'][(int) $criterion['id_critere']] ?? ''] ?? '—') ?></li><?php endforeach; ?></ul><p><?= e((string) $supervisorEval['commentaire']) ?></p><?php endif; ?>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="evaluation_responsable">
      <?php foreach ($managerCriteria as $criterion): ?>
        <div class="col-12"><label class="form-label"><?= e($criterion['libelle']) ?></label>
          <select class="form-select" name="appreciation[<?= (int) $criterion['id_critere'] ?>]"><option value="">—</option><?php foreach ($appreciationLabels as $code => $label): ?><option value="<?= e($code) ?>" <?= (($managerEval['lignes'][(int) $criterion['id_critere']] ?? '') === $code) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <?php endforeach; ?>
      <div class="col-12"><textarea class="form-control" name="commentaire" rows="2" placeholder="Appréciation du responsable"><?= e((string) ($managerEval['commentaire'] ?? '')) ?></textarea></div>
      <div class="col-12"><button class="btn btn-outline-light" type="submit">Enregistrer l’évaluation du responsable</button></div>
    </form>
    <form method="post" class="row g-2 mt-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="retour"><div class="col-12"><input class="form-control" name="commentaire" placeholder="Motif du renvoi" value="<?= e((string) ($supervisorEval['commentaire'] ?? '')) ?>"></div><div class="col-12 d-flex gap-2"><button class="btn btn-outline-light" type="submit">Renvoyer à l’encadrant</button></div></form>
    <form method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="valider"><button class="btn btn-primary" type="submit">Valider le stage</button></form>
  </div></div></div>
  <?php endif; ?>
  <div class="col-lg-6"><div class="card app-card"><div class="card-body">
    <h2 class="h5">Documents</h2>
    <p><a href="stage_dossier.php?id=<?= $id ?><?= app_is_embedded() ? '&embed=1' : '' ?>">Dossier documentaire du stage</a></p>
    <form method="post" enctype="multipart/form-data" class="row g-2 mb-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="document">
      <div class="col-md-5"><input class="form-control" name="titre" placeholder="Titre" required></div>
      <div class="col-md-5"><input class="form-control" type="file" name="fichier" accept="application/pdf,image/png,image/jpeg" required></div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Déposer</button></div>
    </form>
    <ul class="mb-0"><?php foreach ($documents as $document): ?><li><a href="stage_document.php?id=<?= (int) $document['id_document'] ?>"><?= e($document['titre']) ?></a></li><?php endforeach; ?><?php if (!$documents): ?><li class="text-secondary">Aucun document.</li><?php endif; ?></ul>
  </div></div></div>
  <div class="col-lg-6"><div class="card app-card"><div class="card-body">
    <h2 class="h5">Historique</h2>
    <ul><?php foreach ($followups as $line): ?><li><?= e($line['jour']) ?> · <?= e((string) $line['heures']) ?> h · <?= e($line['observation']) ?></li><?php endforeach; ?></ul>
    <ul class="mb-0"><?php foreach ($legacyEvaluations as $line): ?><li><?= e($line['origine']) ?> <?= $line['note_valeur'] !== null ? '· ' . e((string) $line['note_valeur']) : '' ?> · <?= e($line['commentaire']) ?></li><?php endforeach; ?></ul>
  </div></div></div>
</div>
<?php page_foot();

<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
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
        } elseif ($action === 'evaluation') {
            if (!role_can('stage.evaluate')) {
                throw new InvalidArgumentException('Vous ne pouvez pas évaluer ce stage.');
            }
            $origin = (string) ($_POST['origine'] ?? '');
            if (!in_array($origin, ['etudiant', 'organisme', 'superviseur'], true)) {
                throw new InvalidArgumentException('Origine d’évaluation inconnue.');
            }
            $grade = trim((string) ($_POST['note_valeur'] ?? ''));
            db()->prepare('INSERT INTO stage_evaluation (id_placement, origine, note_valeur, commentaire, auteur_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $origin, $grade === '' ? null : (float) str_replace(',', '.', $grade), trim((string) ($_POST['commentaire'] ?? '')), current_stage_id()]);
            audit_record('stage', 'evaluation', 'Évaluation ' . $origin . ' du stage #' . $id);
            $message = 'Évaluation enregistrée, sans écriture dans le relevé de notes.';
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
}
$followups = db()->prepare('SELECT * FROM stage_suivi WHERE id_placement = ? ORDER BY jour DESC');
$followups->execute([$id]);
$followups = $followups->fetchAll();
$evaluations = db()->prepare('SELECT origine, note_valeur, commentaire, created_at FROM stage_evaluation WHERE id_placement = ? ORDER BY id_evaluation DESC');
$evaluations->execute([$id]);
$evaluations = $evaluations->fetchAll();
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
  <?php if (role_can('stage.evaluate')): ?>
  <div class="col-lg-4"><div class="card app-card h-100"><div class="card-body">
    <h2 class="h5">Évaluation</h2>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="evaluation">
      <div class="col-6"><select class="form-select" name="origine"><option value="superviseur">Superviseur</option><option value="organisme">Organisme</option><option value="etudiant">Étudiant</option></select></div>
      <div class="col-6"><input class="form-control" name="note_valeur" inputmode="decimal" placeholder="Note facultative"></div>
      <div class="col-12"><textarea class="form-control" name="commentaire" rows="2" placeholder="Commentaire"></textarea></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>
  </div></div></div>
  <?php endif; ?>
  <div class="col-lg-6"><div class="card app-card"><div class="card-body">
    <h2 class="h5">Documents</h2>
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
    <ul class="mb-0"><?php foreach ($evaluations as $line): ?><li><?= e($line['origine']) ?> <?= $line['note_valeur'] !== null ? '· ' . e((string) $line['note_valeur']) : '' ?> · <?= e($line['commentaire']) ?></li><?php endforeach; ?></ul>
  </div></div></div>
</div>
<?php page_foot();

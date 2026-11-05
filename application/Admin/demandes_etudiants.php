<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_once app_path('support/student_portal.php');
require_admin();

$pdo = db();
student_portal_ensure($pdo);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $message = student_portal_admin_decide(
        $pdo,
        (string) ($_POST['kind'] ?? ''),
        (int) ($_POST['id'] ?? 0),
        (string) ($_POST['decision'] ?? ''),
        trim((string) ($_POST['commentaire'] ?? '')),
        (int) ($_SESSION['admin_id'] ?? 0)
    );
}

$groups = $pdo->query('SELECT r.*, e.nom, e.prenom FROM student_group_request r INNER JOIN etudiant e ON e.id_etudiant = r.id_etudiant WHERE r.statut = "en_attente" ORDER BY r.created_at')->fetchAll();
$appointments = $pdo->query('SELECT a.*, e.nom, e.prenom FROM student_appointment a INNER JOIN etudiant e ON e.id_etudiant = a.id_etudiant WHERE a.statut = "en_attente" ORDER BY a.created_at')->fetchAll();
$complaints = $pdo->query('SELECT c.*, e.nom, e.prenom FROM student_complaint c INNER JOIN etudiant e ON e.id_etudiant = c.id_etudiant WHERE c.statut = "en_attente" ORDER BY c.created_at')->fetchAll();

page_head('Demandes étudiantes');
?>
<h1 class="h3">Demandes étudiantes</h1>
<?php if ($message !== ''): ?><div class="alert alert-warning"><?= e($message) ?></div><?php endif; ?>
<h2 class="h5">Groupes</h2>
<?php foreach ($groups as $row): ?>
  <form method="post" class="card mb-2"><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="kind" value="groupe"><input type="hidden" name="id" value="<?= (int) $row['id_request'] ?>">
    <p><?= e($row['prenom'] . ' ' . $row['nom']) ?> · <?= e((string) $row['request_type']) ?> · groupe <?= (int) $row['id_groupe_actuel'] ?> vers <?= (int) $row['id_groupe_cible'] ?></p>
    <textarea class="form-control mb-2" name="commentaire" placeholder="Réponse"></textarea>
    <button class="btn btn-primary btn-sm" name="decision" value="validee">Confirmer la validation</button>
    <button class="btn btn-outline-danger btn-sm" name="decision" value="refusee">Refuser</button>
  </div></form>
<?php endforeach; ?>
<h2 class="h5">Rendez-vous</h2>
<?php foreach ($appointments as $row): ?>
  <form method="post" class="card mb-2"><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="kind" value="psy"><input type="hidden" name="id" value="<?= (int) $row['id_appointment'] ?>">
    <p><?= e($row['prenom'] . ' ' . $row['nom']) ?> · <?= e((string) $row['requested_date']) ?> <?= e(substr((string) $row['requested_time'], 0, 5)) ?></p>
    <p><?= e((string) $row['motif']) ?></p>
    <textarea class="form-control mb-2" name="commentaire" placeholder="Réponse ou nouvelle date"></textarea>
    <button class="btn btn-primary btn-sm" name="decision" value="validee">Confirmer</button>
    <button class="btn btn-outline-warning btn-sm" name="decision" value="reprogrammee">Reprogrammer</button>
    <button class="btn btn-outline-danger btn-sm" name="decision" value="refusee">Refuser</button>
  </div></form>
<?php endforeach; ?>
<h2 class="h5">Réclamations</h2>
<?php foreach ($complaints as $row): ?>
  <form method="post" class="card mb-2"><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="kind" value="reclamation"><input type="hidden" name="id" value="<?= (int) $row['id_complaint'] ?>">
    <p><?= e($row['prenom'] . ' ' . $row['nom']) ?> · <?= e((string) $row['categorie']) ?></p>
    <p><?= e((string) $row['description']) ?></p>
    <textarea class="form-control mb-2" name="commentaire" placeholder="Laisser vide pour le message automatique"></textarea>
    <button class="btn btn-primary btn-sm" name="decision" value="validee">Envoyer la réponse</button>
  </div></form>
<?php endforeach; ?>
<?php page_foot(); ?>

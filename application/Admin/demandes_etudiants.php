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
        (int) ($_SESSION['admin_id'] ?? 0),
        (string) ($_POST['response_date'] ?? ''),
        (string) ($_POST['response_time'] ?? '')
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
<?php if (!$groups): ?><p class="text-secondary">Aucune demande de groupe en attente.</p><?php endif; ?>
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
<?php if (!$appointments): ?><p class="text-secondary">Aucun rendez-vous en attente.</p><?php endif; ?>
<?php foreach ($appointments as $row): ?>
  <form method="post" class="card mb-2" data-psy-form><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="kind" value="psy">
    <input type="hidden" name="id" value="<?= (int) $row['id_appointment'] ?>">
    <input type="hidden" name="decision" value="" data-psy-decision>
    <input type="hidden" name="response_date" value="" data-psy-date>
    <input type="hidden" name="response_time" value="" data-psy-time>
    <p><?= e($row['prenom'] . ' ' . $row['nom']) ?> · <?= e((string) $row['requested_date']) ?> <?= e(substr((string) $row['requested_time'], 0, 5)) ?></p>
    <p><?= e((string) $row['motif']) ?></p>
    <textarea class="form-control mb-2" name="commentaire" placeholder="Réponse (optionnel)"></textarea>
    <button type="submit" class="btn btn-primary btn-sm" data-psy-action="validee">Confirmer</button>
    <button type="button" class="btn btn-outline-warning btn-sm" data-psy-reprogram>Reprogrammer</button>
    <button type="submit" class="btn btn-outline-danger btn-sm" data-psy-action="refusee">Refuser</button>
  </div></form>
<?php endforeach; ?>
<h2 class="h5">Réclamations</h2>
<?php if (!$complaints): ?><p class="text-secondary">Aucune réclamation en attente.</p><?php endif; ?>
<?php foreach ($complaints as $row): ?>
  <form method="post" class="card mb-2"><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="kind" value="reclamation"><input type="hidden" name="id" value="<?= (int) $row['id_complaint'] ?>">
    <p><?= e($row['prenom'] . ' ' . $row['nom']) ?> · <?= e((string) $row['categorie']) ?></p>
    <p><?= e((string) $row['description']) ?></p>
    <textarea class="form-control mb-2" name="commentaire" placeholder="Laisser vide pour le message automatique"></textarea>
    <button class="btn btn-primary btn-sm" name="decision" value="validee">Envoyer la réponse</button>
  </div></form>
<?php endforeach; ?>

<dialog class="psy-reprogram-dialog" data-psy-dialog>
  <form method="dialog" class="psy-reprogram-card">
    <h2 class="h5 mb-3">Reprogrammer le rendez-vous</h2>
    <p class="text-secondary small mb-3">Choisissez le nouveau créneau qui sera envoyé à l’étudiant.</p>
    <label class="form-label" for="psyRescheduleDate">Nouvelle date</label>
    <input class="form-control mb-3" type="date" id="psyRescheduleDate" data-psy-dialog-date required min="<?= e(date('Y-m-d')) ?>">
    <label class="form-label" for="psyRescheduleTime">Nouvelle heure</label>
    <input class="form-control mb-3" type="time" id="psyRescheduleTime" data-psy-dialog-time required step="300">
    <div class="d-flex gap-2 justify-content-end">
      <button type="button" class="btn btn-outline-secondary btn-sm" data-psy-dialog-cancel>Annuler</button>
      <button type="submit" class="btn btn-warning btn-sm" data-psy-dialog-confirm>Valider le créneau</button>
    </div>
  </form>
</dialog>

<style>
.psy-reprogram-dialog {
  border: 0;
  border-radius: 1rem;
  padding: 0;
  max-width: min(24rem, calc(100vw - 2rem));
  background: transparent;
  color: inherit;
}
.psy-reprogram-dialog::backdrop {
  background: rgba(0, 0, 0, 0.55);
}
.psy-reprogram-card {
  margin: 0;
  padding: 1.15rem 1.2rem 1.1rem;
  border-radius: 1rem;
  border: 1px solid rgba(255, 255, 255, 0.16);
  background: #1c1f26;
  color: #f4f1ea;
  box-shadow: 0 24px 48px rgba(0, 0, 0, 0.35);
}
</style>
<script>
(function () {
  var dialog = document.querySelector('[data-psy-dialog]');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  var dateInput = dialog.querySelector('[data-psy-dialog-date]');
  var timeInput = dialog.querySelector('[data-psy-dialog-time]');
  var cancelBtn = dialog.querySelector('[data-psy-dialog-cancel]');
  var activeForm = null;

  document.querySelectorAll('[data-psy-form]').forEach(function (form) {
    form.querySelectorAll('[data-psy-action]').forEach(function (button) {
      button.addEventListener('click', function () {
        var decision = form.querySelector('[data-psy-decision]');
        if (decision) decision.value = button.getAttribute('data-psy-action') || '';
      });
    });
    var reprogram = form.querySelector('[data-psy-reprogram]');
    if (!reprogram) return;
    reprogram.addEventListener('click', function () {
      activeForm = form;
      if (dateInput) dateInput.value = dateInput.getAttribute('min') || '';
      if (timeInput) timeInput.value = '09:00';
      dialog.showModal();
      if (dateInput) dateInput.focus();
    });
  });

  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      activeForm = null;
      dialog.close();
    });
  }

  dialog.addEventListener('close', function () {
    if (!activeForm || dialog.returnValue === 'cancel') {
      activeForm = null;
      return;
    }
  });

  dialog.querySelector('form').addEventListener('submit', function (event) {
    event.preventDefault();
    if (!activeForm || !dateInput || !timeInput) return;
    if (!dateInput.value || !timeInput.value) {
      dateInput.reportValidity();
      timeInput.reportValidity();
      return;
    }
    var decision = activeForm.querySelector('[data-psy-decision]');
    var dateField = activeForm.querySelector('[data-psy-date]');
    var timeField = activeForm.querySelector('[data-psy-time]');
    if (decision) decision.value = 'reprogrammee';
    if (dateField) dateField.value = dateInput.value;
    if (timeField) timeField.value = timeInput.value;
    dialog.close();
    activeForm.requestSubmit();
    activeForm = null;
  });
})();
</script>
<?php page_foot(); ?>

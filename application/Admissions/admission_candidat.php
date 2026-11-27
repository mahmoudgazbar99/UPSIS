<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_admissions_permission('admissions.view');
admissions_ensure();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$app = admissions_find_application($id);
if (!$app) {
    http_response_code(404);
    page_head('Candidat introuvable', 'admissions');
    echo '<div class="alert alert-danger">Candidature introuvable.</div>';
    exit;
}

$message = '';
$error = '';
$tab = (string) ($_GET['tab'] ?? 'overview');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!role_can('admissions.manage') && !role_can('admissions.review') && !is_admin()) {
        http_response_code(403);
        exit('Action non autorisée.');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'set_status' && (is_admin() || role_can('admissions.manage'))) {
            admissions_transition($id, (string) ($_POST['status'] ?? ''), trim((string) ($_POST['reason'] ?? '')));
            $message = 'Statut mis à jour.';
        }
        if ($action === 'verify_doc' && (is_admin() || role_can('admissions.review') || role_can('admissions.manage'))) {
            $docId = (int) ($_POST['id_document'] ?? 0);
            $docStatus = (string) ($_POST['doc_status'] ?? 'verified');
            $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
            db()->prepare(
                'UPDATE admission_document SET status = ?, rejection_reason = ?, verified_by = ?, verified_at = CURRENT_TIMESTAMP WHERE id_document = ? AND id_application = ?'
            )->execute([$docStatus, $reason, admissions_actor_label(), $docId, $id]);
            admissions_refresh_document_status($id);
            if ($docStatus === 'verified') {
                admissions_notify((int) $app['id_candidate'], 'document_approved', 'Document validé', '', $id);
            } elseif ($docStatus === 'rejected') {
                admissions_notify((int) $app['id_candidate'], 'document_rejected', 'Document refusé', $reason, $id);
            }
            $message = 'Document mis à jour.';
        }
        if ($action === 'assign_officer' && (is_admin() || role_can('admissions.manage'))) {
            db()->prepare('UPDATE admission_application SET assigned_officer = ?, updated_at = CURRENT_TIMESTAMP WHERE id_application = ?')
                ->execute([trim((string) ($_POST['assigned_officer'] ?? '')), $id]);
            $message = 'Chargé de dossier enregistré.';
        }
        if ($action === 'publish_decision' && (is_admin() || role_can('admissions.decide'))) {
            if (empty($_POST['confirm_publish'])) {
                throw new InvalidArgumentException('Confirmez la publication de la décision.');
            }
            admissions_publish_decision(
                $id,
                (string) ($_POST['decision'] ?? ''),
                trim((string) ($_POST['comments'] ?? '')),
                trim((string) ($_POST['reason'] ?? '')),
                trim((string) ($_POST['committee_label'] ?? ''))
            );
            $message = 'Décision publiée.';
        }
        if ($action === 'toggle_check' && (is_admin() || role_can('admissions.enroll') || role_can('admissions.manage'))) {
            $checkId = (int) ($_POST['id_check'] ?? 0);
            $done = isset($_POST['completed']) ? 1 : 0;
            db()->prepare(
                'UPDATE admission_enrollment_checklist SET completed = ?, completed_at = ' . ($done ? 'CURRENT_TIMESTAMP' : 'NULL') . ', completed_by = ? WHERE id_check = ? AND id_application = ?'
            )->execute([$done, $done ? admissions_actor_label() : '', $checkId, $id]);
            if ((string) $app['status'] === 'offer_accepted') {
                admissions_transition($id, 'registration_pending', 'Dossier d’inscription ouvert');
            }
            $message = 'Checklist mise à jour.';
        }
        if ($action === 'record_payment' && (is_admin() || is_finance() || role_can('admissions.manage'))) {
            $kind = trim((string) ($_POST['payment_kind'] ?? 'registration'));
            if (!isset(admissions_payment_kinds()[$kind])) {
                $kind = 'registration';
            }
            $amount = (float) ($_POST['amount'] ?? 0);
            if ($kind === 'test_fee' && $amount <= 0) {
                $amount = (float) admissions_setting('test_fee_amount', '150');
            }
            db()->prepare(
                'INSERT INTO admission_payment (id_application, amount, currency_code, payment_date, method, reference_no, status, verified_by, verified_at, notes, payment_kind)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)'
            )->execute([
                $id,
                $amount,
                (string) ($_POST['currency_code'] ?? 'DT'),
                (string) ($_POST['payment_date'] ?? date('Y-m-d')),
                trim((string) ($_POST['method'] ?? '')),
                trim((string) ($_POST['reference_no'] ?? '')),
                (string) ($_POST['payment_status'] ?? 'paid'),
                admissions_actor_label(),
                trim((string) ($_POST['notes'] ?? '')),
                $kind,
            ]);
            admissions_transition($id, (string) ($_POST['payment_status'] ?? 'paid'), 'Paiement', 'payment_status');
            $stepCode = $kind === 'test_fee' ? 'test_fee_paid' : 'payment_ok';
            db()->prepare(
                'UPDATE admission_enrollment_checklist SET completed = 1, completed_at = CURRENT_TIMESTAMP, completed_by = ?
                 WHERE id_application = ? AND step_code = ?'
            )->execute([admissions_actor_label(), $id, $stepCode]);
            if ($kind !== 'test_fee') {
                db()->prepare(
                    'UPDATE admission_enrollment_checklist SET completed = 1, completed_at = CURRENT_TIMESTAMP, completed_by = ?
                     WHERE id_application = ? AND step_code = "payment_ok"'
                )->execute([admissions_actor_label(), $id]);
            }
            admissions_notify((int) $app['id_candidate'], 'payment_recorded', 'Paiement enregistré', admissions_payment_kinds()[$kind] ?? '', $id);
            $message = 'Paiement enregistré.';
        }
        if ($action === 'set_grace' && (is_admin() || role_can('admissions.manage'))) {
            $days = max(1, (int) admissions_setting('docs_grace_days', '15'));
            $until = trim((string) ($_POST['docs_grace_until'] ?? ''));
            if ($until === '') {
                $until = date('Y-m-d', strtotime('+' . $days . ' days'));
            }
            db()->prepare('UPDATE admission_application SET docs_grace_until = ?, updated_at = CURRENT_TIMESTAMP WHERE id_application = ?')
                ->execute([$until, $id]);
            $message = 'Délai documents fixé au ' . $until . '.';
        }
        if ($action === 'finalize' && (is_admin() || role_can('admissions.enroll'))) {
            if (empty($_POST['confirm_finalize'])) {
                throw new InvalidArgumentException('Confirmez la validation finale.');
            }
            $result = admissions_finalize_enrollment($id);
            $message = 'Inscription validée — N° ' . $result['num_inscription'] . '.';
        }
        $app = admissions_find_application($id);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $app = admissions_find_application($id) ?: $app;
    }
}

$docs = db()->prepare(
    'SELECT d.*, t.label, t.code FROM admission_document d
     JOIN admission_doc_type t ON t.id_doc_type = d.id_doc_type
     WHERE d.id_application = ? AND d.status <> "replaced" ORDER BY t.sort_order, d.id_document DESC'
);
$docs->execute([$id]);
$documents = $docs->fetchAll();
$history = db()->prepare('SELECT * FROM admission_status_history WHERE id_application = ? ORDER BY created_at DESC LIMIT 50');
$history->execute([$id]);
$historyRows = $history->fetchAll();
$tests = db()->prepare(
    'SELECT s.*, ta.* FROM admission_test_assignment ta
     JOIN admission_test_session s ON s.id_session = ta.id_session
     WHERE ta.id_application = ? ORDER BY s.session_date'
);
$tests->execute([$id]);
$testRows = $tests->fetchAll();
$interviews = db()->prepare('SELECT * FROM admission_interview WHERE id_application = ? ORDER BY interview_date');
$interviews->execute([$id]);
$interviewRows = $interviews->fetchAll();
$decisions = db()->prepare('SELECT * FROM admission_decision WHERE id_application = ? ORDER BY id_decision DESC');
$decisions->execute([$id]);
$decisionRows = $decisions->fetchAll();
$payments = db()->prepare('SELECT * FROM admission_payment WHERE id_application = ? ORDER BY id_payment DESC');
$payments->execute([$id]);
$paymentRows = $payments->fetchAll();
admissions_seed_checklist(db(), $id);
$checklist = db()->prepare('SELECT * FROM admission_enrollment_checklist WHERE id_application = ? ORDER BY id_check');
$checklist->execute([$id]);
$checkRows = $checklist->fetchAll();
$checklistSplit = admissions_checklist_by_channel($id);
$paymentKinds = admissions_payment_kinds();
$statuses = admissions_statuses();
$embed = ($_GET['embed'] ?? '') === '1' ? '&embed=1' : '';

page_head('Candidat ' . $app['application_number'], 'admissions', 'assets/admissions-lightswind.css?v=1');
?>
<div class="adm-lw-admin">
<div class="mb-3">
  <a href="admissions.php?embed=1" class="btn btn-sm btn-outline-secondary">← Liste</a>
</div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <p class="eyebrow mb-1"><?= e($app['application_number']) ?></p>
    <h1 class="h3 fw-bold mb-1"><?= e(trim($app['prenom'] . ' ' . $app['nom'])) ?></h1>
    <p class="text-secondary mb-0"><?= e($app['campaign_label']) ?> · <?= e((string) ($app['nom_spec'] ?? '')) ?> · <span class="badge text-bg-info"><?= e($statuses[$app['status']] ?? $app['status']) ?></span></p>
  </div>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<ul class="nav nav-pills mb-3 flex-wrap gap-1">
  <?php
  $tabs = [
    'overview' => 'Vue d’ensemble',
    'identity' => 'Identité',
    'academic' => 'Scolarité',
    'documents' => 'Documents',
    'tests' => 'Tests',
    'interviews' => 'Entretiens',
    'decision' => 'Décision',
    'payments' => 'Paiements',
    'enrollment' => 'Inscription',
    'history' => 'Historique',
  ];
  foreach ($tabs as $key => $label): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=<?= e($key) . $embed ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
  <div class="row g-3">
    <div class="col-md-6">
      <div class="card app-card"><div class="card-body">
        <h2 class="h6">Coordonnées</h2>
        <p class="mb-1"><?= e($app['email']) ?></p>
        <p class="mb-1"><?= e($app['num_tel']) ?></p>
        <p class="mb-0"><?= e($app['cin_passeport']) ?></p>
      </div></div>
    </div>
    <div class="col-md-6">
      <div class="card app-card"><div class="card-body">
        <h2 class="h6">Statuts</h2>
        <ul class="mb-0 small">
          <li>Application : <?= e($app['status']) ?></li>
          <li>Documents : <?= e($app['document_status']) ?></li>
          <li>Tests : <?= e($app['test_status']) ?></li>
          <li>Entretien : <?= e($app['interview_status']) ?></li>
          <li>Décision : <?= e($app['decision_status']) ?></li>
          <li>Paiement : <?= e($app['payment_status']) ?></li>
          <li>Inscription : <?= e($app['enrollment_status']) ?></li>
          <li>Compte : <?= e($app['account_status']) ?></li>
        </ul>
      </div></div>
    </div>
    <?php if (is_admin() || role_can('admissions.manage')): ?>
    <div class="col-12">
      <div class="card app-card"><div class="card-body">
        <h2 class="h6">Changer le statut (transitions contrôlées)</h2>
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="set_status">
          <div class="col-md-4">
            <select class="form-select" name="status" required>
              <?php foreach (admissions_transitions()[$app['status']] ?? [] as $next): ?>
                <option value="<?= e($next) ?>"><?= e($statuses[$next] ?? $next) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5"><input class="form-control" name="reason" placeholder="Motif"></div>
          <div class="col-md-3"><button class="btn btn-primary w-100">Appliquer</button></div>
        </form>
        <form method="post" class="row g-2 align-items-end mt-2">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="assign_officer">
          <div class="col-md-8"><input class="form-control" name="assigned_officer" value="<?= e((string) $app['assigned_officer']) ?>" placeholder="Chargé de dossier"></div>
          <div class="col-md-4"><button class="btn btn-outline-light w-100">Enregistrer</button></div>
        </form>
      </div></div>
    </div>
    <?php endif; ?>
  </div>
<?php elseif ($tab === 'identity'): ?>
  <div class="card app-card"><div class="card-body row g-2">
    <div class="col-md-4"><strong>Nom</strong><div><?= e($app['nom']) ?></div></div>
    <div class="col-md-4"><strong>Prénom</strong><div><?= e($app['prenom']) ?></div></div>
    <div class="col-md-4"><strong>Naissance</strong><div><?= e((string) $app['date_naissance']) ?> — <?= e((string) $app['lieu_naissance']) ?></div></div>
    <div class="col-md-4"><strong>Sexe</strong><div><?= e((string) $app['sexe']) ?></div></div>
    <div class="col-md-4"><strong>Nationalité</strong><div><?= e((string) $app['nationalite']) ?></div></div>
    <div class="col-md-4"><strong>Adresse</strong><div><?= e(trim($app['adresse'] . ', ' . $app['ville'] . ', ' . $app['pays'])) ?></div></div>
  </div></div>
<?php elseif ($tab === 'academic'): ?>
  <div class="card app-card"><div class="card-body row g-2">
    <div class="col-md-4"><strong>Établissement</strong><div><?= e((string) $app['etablissement_origine']) ?></div></div>
    <div class="col-md-2"><strong>Année bac</strong><div><?= e((string) $app['annee_bac']) ?></div></div>
    <div class="col-md-3"><strong>Section</strong><div><?= e((string) $app['specialite_bac']) ?></div></div>
    <div class="col-md-3"><strong>Moyenne</strong><div><?= e((string) $app['moyenne_bac']) ?></div></div>
    <div class="col-12"><strong>Études antérieures</strong><div><?= nl2br(e((string) $app['etudes_anterieures'])) ?></div></div>
  </div></div>
<?php elseif ($tab === 'documents'): ?>
  <div class="card app-card"><div class="table-responsive"><table class="table table-dark mb-0">
    <thead><tr><th>Type</th><th>Fichier</th><th>Statut</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($documents as $doc): ?>
      <tr>
        <td><?= e($doc['label']) ?></td>
        <td><a href="admission_document_file.php?id=<?= (int) $doc['id_document'] ?>" target="_blank"><?= e($doc['original_name']) ?></a></td>
        <td><?= e($doc['status']) ?><?php if ($doc['rejection_reason']): ?><div class="small text-warning"><?= e($doc['rejection_reason']) ?></div><?php endif; ?></td>
        <td>
          <form method="post" class="d-flex flex-wrap gap-1">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="verify_doc">
            <input type="hidden" name="id_document" value="<?= (int) $doc['id_document'] ?>">
            <select name="doc_status" class="form-select form-select-sm" style="width:auto">
              <option value="verified">Validé</option>
              <option value="rejected">Refusé</option>
              <option value="under_verification">En cours</option>
              <option value="replacement_requested">À remplacer</option>
            </select>
            <input class="form-control form-control-sm" name="rejection_reason" placeholder="Motif refus" style="width:10rem">
            <button class="btn btn-sm btn-primary">OK</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$documents): ?><tr><td colspan="4" class="text-secondary">Aucun document.</td></tr><?php endif; ?>
    </tbody>
  </table></div></div>
<?php elseif ($tab === 'tests'): ?>
  <div class="card app-card"><div class="card-body">
    <?php if (!$testRows): ?><p class="text-secondary mb-0">Aucun test. Planifiez via <a href="admission_tests.php?embed=1">Tests</a>.</p><?php endif; ?>
    <?php foreach ($testRows as $t): ?>
      <div class="border-bottom py-2">
        <strong><?= e($t['name']) ?></strong> — <?= e($t['session_date'] . ' ' . substr((string) $t['session_time'], 0, 5)) ?>
        <div class="small">Présence <?= e($t['attendance']) ?> · Score <?= e((string) ($t['score'] ?? '—')) ?></div>
      </div>
    <?php endforeach; ?>
  </div></div>
<?php elseif ($tab === 'interviews'): ?>
  <div class="card app-card"><div class="card-body">
    <?php if (!$interviewRows): ?><p class="text-secondary mb-0">Aucun entretien. Voir <a href="admission_entretiens.php?embed=1">Entretiens</a>.</p><?php endif; ?>
    <?php foreach ($interviewRows as $i): ?>
      <div class="border-bottom py-2">
        <strong><?= e($i['interview_date'] . ' ' . substr((string) $i['interview_time'], 0, 5)) ?></strong>
        <div class="small"><?= e($i['location']) ?> · <?= e($i['status']) ?> · <?= e((string) ($i['recommendation'] ?? '')) ?></div>
      </div>
    <?php endforeach; ?>
  </div></div>
<?php elseif ($tab === 'decision'): ?>
  <div class="card app-card mb-3"><div class="card-body">
    <h2 class="h6">Publier une décision</h2>
    <?php if (is_admin() || role_can('admissions.decide')): ?>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="publish_decision">
      <div class="col-md-3">
        <select class="form-select" name="decision" required>
          <option value="admitted">Admis</option>
          <option value="waitlisted">Liste d’attente</option>
          <option value="rejected">Refusé</option>
          <option value="additional_evaluation">Évaluation complémentaire</option>
        </select>
      </div>
      <div class="col-md-4"><input class="form-control" name="committee_label" placeholder="Comité / décideur"></div>
      <div class="col-md-5"><input class="form-control" name="reason" placeholder="Motif (si refus)"></div>
      <div class="col-12"><textarea class="form-control" name="comments" rows="2" placeholder="Commentaires"></textarea></div>
      <div class="col-12 form-check ms-1">
        <input class="form-check-input" type="checkbox" name="confirm_publish" value="1" id="confirm_publish" required>
        <label class="form-check-label" for="confirm_publish">Je confirme la publication et la notification du candidat</label>
      </div>
      <div class="col-12"><button class="btn btn-warning">Publier</button></div>
    </form>
    <?php else: ?><p class="text-secondary mb-0">Permission comité requise.</p><?php endif; ?>
  </div></div>
  <?php foreach ($decisionRows as $d): ?>
    <div class="card app-card mb-2"><div class="card-body py-3">
      <strong><?= e($d['decision']) ?></strong> — <?= e((string) $d['decision_date']) ?>
      <?= (int) $d['published'] === 1 ? '<span class="badge text-bg-success">publié</span>' : '' ?>
      <div class="small text-secondary"><?= e((string) $d['committee_label']) ?> · <?= e((string) $d['reason']) ?></div>
    </div></div>
  <?php endforeach; ?>
<?php elseif ($tab === 'payments'): ?>
  <div class="card app-card mb-3"><div class="card-body">
    <p class="small text-secondary">Paiements hors ligne : saisie / vérification scolarité-finance. Pas de passerelle bancaire ici.</p>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="record_payment">
      <div class="col-md-3">
        <select class="form-select" name="payment_kind" required>
          <?php foreach ($paymentKinds as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $code === 'test_fee' ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><input class="form-control" name="amount" placeholder="Montant" value="<?= e(admissions_setting('test_fee_amount', '150')) ?>" required></div>
      <div class="col-md-2"><input class="form-control" name="payment_date" type="date" value="<?= e(date('Y-m-d')) ?>"></div>
      <div class="col-md-2"><input class="form-control" name="method" placeholder="Mode (espèces / virement)"></div>
      <div class="col-md-2"><input class="form-control" name="reference_no" placeholder="N° quittance"></div>
      <div class="col-md-2">
        <select class="form-select" name="payment_status">
          <option value="paid">Payé</option>
          <option value="partially_paid">Partiel</option>
          <option value="waived">Exonéré</option>
          <option value="unpaid">Impayé</option>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100">Enregistrer</button></div>
    </form>
  </div></div>
  <?php foreach ($paymentRows as $p): ?>
    <div class="small border-bottom py-2">
      <?= e(($p['payment_kind'] ?? 'registration') . ' · ' . $p['payment_date'] . ' · ' . $p['amount'] . ' ' . $p['currency_code'] . ' · ' . $p['status'] . ' · ' . $p['reference_no']) ?>
    </div>
  <?php endforeach; ?>
<?php elseif ($tab === 'enrollment'): ?>
  <div class="card app-card mb-3"><div class="card-body">
    <h2 class="h6">Délai documents manquants (15 jours post-rentrée)</h2>
    <form method="post" class="row g-2 align-items-end mb-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="set_grace">
      <div class="col-md-4">
        <label class="form-label">Échéance</label>
        <input class="form-control" type="date" name="docs_grace_until" value="<?= e((string) ($app['docs_grace_until'] ?? '')) ?>">
      </div>
      <div class="col-md-3"><button class="btn btn-outline-primary">Enregistrer le délai</button></div>
    </form>
    <h2 class="h6">Checklist — sur l’application</h2>
    <?php foreach ($checklistSplit['app'] as $c): ?>
      <form method="post" class="d-flex align-items-center gap-2 mb-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle_check">
        <input type="hidden" name="id_check" value="<?= (int) $c['id_check'] ?>">
        <input type="checkbox" name="completed" value="1" <?= (int) $c['completed'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
        <span><?= e($c['step_label']) ?><?= (int) $c['required'] === 1 ? ' *' : '' ?></span>
      </form>
    <?php endforeach; ?>
    <h2 class="h6 mt-4">Checklist — administration (hors app)</h2>
    <p class="small text-secondary">Commission, légalisation, originaux, carte / badge : validation manuelle ici après dépôt physique.</p>
    <?php foreach ($checklistSplit['admin'] as $c): ?>
      <form method="post" class="d-flex align-items-center gap-2 mb-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle_check">
        <input type="hidden" name="id_check" value="<?= (int) $c['id_check'] ?>">
        <input type="checkbox" name="completed" value="1" <?= (int) $c['completed'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
        <span><?= e($c['step_label']) ?><?= (int) $c['required'] === 1 ? ' *' : '' ?></span>
      </form>
    <?php endforeach; ?>
    <?php if ((string) $app['id_etudiant']): ?>
      <div class="alert alert-success mt-3 mb-0">Étudiant lié #<?= (int) $app['id_etudiant'] ?> — statut <?= e($app['status']) ?></div>
    <?php elseif (is_admin() || role_can('admissions.enroll')): ?>
      <form method="post" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="finalize">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="confirm_finalize" value="1" id="confirm_finalize" required>
          <label class="form-check-label" for="confirm_finalize">Confirmer la validation finale (crée l’étudiant avec N° INS###/AAAA, active le compte)</label>
        </div>
        <button class="btn btn-danger" onclick="return confirm('Valider l’inscription finale ? Cette action est idempotente.')">Valider l’inscription finale</button>
      </form>
    <?php endif; ?>
  </div></div>
<?php else: ?>
  <div class="card app-card"><div class="card-body">
    <?php foreach ($historyRows as $h): ?>
      <div class="small border-bottom py-2">
        <strong><?= e($h['field_name']) ?></strong>
        <?= e($h['previous_status']) ?> → <?= e($h['new_status']) ?>
        <span class="text-secondary">· <?= e($h['changed_by_label']) ?> · <?= e(substr((string) $h['created_at'], 0, 16)) ?></span>
        <?php if ($h['reason']): ?><div><?= e($h['reason']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div></div>
<?php endif; ?>
</div>

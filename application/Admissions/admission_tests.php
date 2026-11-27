<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_admissions_permission('admissions.manage');
admissions_ensure();

$message = '';
$error = '';
$types = admissions_test_types();
$campaigns = db()->query('SELECT id_campaign, label, academic_year_label FROM admission_campaign ORDER BY id_campaign DESC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create_session') {
            db()->prepare(
                'INSERT INTO admission_test_session
                 (id_campaign, name, test_type, description, duration_minutes, session_date, session_time, location, room, capacity, instructions, materials, evaluators)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                (int) $_POST['id_campaign'],
                trim((string) $_POST['name']),
                (string) ($_POST['test_type'] ?? 'psychometric'),
                trim((string) ($_POST['description'] ?? '')),
                (int) ($_POST['duration_minutes'] ?? 60),
                (string) $_POST['session_date'],
                (string) $_POST['session_time'],
                trim((string) ($_POST['location'] ?? '')),
                trim((string) ($_POST['room'] ?? '')),
                (int) ($_POST['capacity'] ?? 30),
                trim((string) ($_POST['instructions'] ?? '')),
                trim((string) ($_POST['materials'] ?? '')),
                trim((string) ($_POST['evaluators'] ?? '')),
            ]);
            $message = 'Session créée.';
        }
        if ($action === 'assign') {
            $sessionId = (int) ($_POST['id_session'] ?? 0);
            $appId = (int) ($_POST['id_application'] ?? 0);
            $cap = db()->prepare('SELECT capacity, id_campaign FROM admission_test_session WHERE id_session = ?');
            $cap->execute([$sessionId]);
            $session = $cap->fetch();
            if (!$session) {
                throw new InvalidArgumentException('Session introuvable.');
            }
            $count = db()->prepare('SELECT COUNT(*) FROM admission_test_assignment WHERE id_session = ?');
            $count->execute([$sessionId]);
            if ((int) $count->fetchColumn() >= (int) $session['capacity']) {
                throw new InvalidArgumentException('Capacité de salle atteinte.');
            }
            $overlap = db()->prepare(
                'SELECT COUNT(*) FROM admission_test_assignment ta
                 JOIN admission_test_session s ON s.id_session = ta.id_session
                 JOIN admission_test_session s2 ON s2.id_session = ?
                 WHERE ta.id_application = ?
                   AND s.session_date = s2.session_date
                   AND s.session_time = s2.session_time'
            );
            $overlap->execute([$sessionId, $appId]);
            if ((int) $overlap->fetchColumn() > 0) {
                throw new InvalidArgumentException('Chevauchement d’horaire pour ce candidat.');
            }
            db()->prepare('INSERT IGNORE INTO admission_test_assignment (id_session, id_application) VALUES (?, ?)')
                ->execute([$sessionId, $appId]);
            if (admissions_can_transition((string) (admissions_find_application($appId)['status'] ?? ''), 'test_scheduled')) {
                admissions_transition($appId, 'test_scheduled', 'Convocation test');
            }
            admissions_transition($appId, 'scheduled', 'Test', 'test_status');
            $app = admissions_find_application($appId);
            if ($app) {
                admissions_notify((int) $app['id_candidate'], 'test_scheduled', 'Test planifié', 'Consultez votre espace candidat.', $appId);
            }
            $message = 'Candidat affecté.';
        }
        if ($action === 'attendance') {
            db()->prepare('UPDATE admission_test_assignment SET attendance = ? WHERE id_assignment = ?')
                ->execute([(string) $_POST['attendance'], (int) $_POST['id_assignment']]);
            $message = 'Présence mise à jour.';
        }
        if ($action === 'score') {
            db()->prepare(
                'UPDATE admission_test_assignment SET score = ?, comments = ?, recommendation = ?, evaluator_label = ?, evaluated_at = CURRENT_TIMESTAMP, criteria_json = ?
                 WHERE id_assignment = ?'
            )->execute([
                $_POST['score'] !== '' ? (float) $_POST['score'] : null,
                trim((string) ($_POST['comments'] ?? '')),
                trim((string) ($_POST['recommendation'] ?? '')),
                admissions_actor_label(),
                trim((string) ($_POST['criteria_json'] ?? '')),
                (int) $_POST['id_assignment'],
            ]);
            $appId = (int) ($_POST['id_application'] ?? 0);
            if ($appId > 0 && admissions_can_transition((string) (admissions_find_application($appId)['status'] ?? ''), 'test_completed')) {
                admissions_transition($appId, 'test_completed', 'Résultat test saisi');
            }
            admissions_transition($appId, 'completed', 'Test', 'test_status');
            $message = 'Résultat enregistré (ne déclenche pas de refus automatique).';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$sessions = db()->query(
    'SELECT s.*, c.label AS campaign_label,
            (SELECT COUNT(*) FROM admission_test_assignment ta WHERE ta.id_session = s.id_session) AS assigned
     FROM admission_test_session s
     JOIN admission_campaign c ON c.id_campaign = s.id_campaign
     ORDER BY s.session_date DESC, s.session_time DESC'
)->fetchAll();
$sessionId = filter_input(INPUT_GET, 'session', FILTER_VALIDATE_INT) ?: (int) ($sessions[0]['id_session'] ?? 0);
$assignments = [];
$candidates = [];
if ($sessionId > 0) {
    $assignments = db()->prepare(
        'SELECT ta.*, a.application_number, cand.nom, cand.prenom
         FROM admission_test_assignment ta
         JOIN admission_application a ON a.id_application = ta.id_application
         JOIN admission_candidate cand ON cand.id_candidate = a.id_candidate
         WHERE ta.id_session = ?
         ORDER BY cand.nom, cand.prenom'
    );
    $assignments->execute([$sessionId]);
    $assignments = $assignments->fetchAll();
    $sess = null;
    foreach ($sessions as $s) {
        if ((int) $s['id_session'] === $sessionId) {
            $sess = $s;
            break;
        }
    }
    if ($sess) {
        $candidates = db()->prepare(
            'SELECT a.id_application, a.application_number, c.nom, c.prenom
             FROM admission_application a
             JOIN admission_candidate c ON c.id_candidate = a.id_candidate
             WHERE a.id_campaign = ? AND a.status NOT IN ("rejected","withdrawn","enrolled","expired")
             ORDER BY a.application_number'
        );
        $candidates->execute([(int) $sess['id_campaign']]);
        $candidates = $candidates->fetchAll();
    }
}

page_head('Tests d’admission', 'admissions', 'assets/admissions-lightswind.css?v=1');
?>
<div class="adm-lw-admin">
<div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="admissions.php?embed=1">← Admissions</a></div>
<p class="eyebrow">Admissions</p>
<h1 class="h3 fw-bold mb-3">Sessions de test</h1>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card app-card mb-4"><div class="card-body">
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_session">
    <div class="col-md-3">
      <select class="form-select" name="id_campaign" required>
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= (int) $c['id_campaign'] ?>"><?= e($c['label'] . ' (' . $c['academic_year_label'] . ')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><input class="form-control" name="name" placeholder="Nom du test *" required></div>
    <div class="col-md-2">
      <select class="form-select" name="test_type">
        <?php foreach ($types as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><input class="form-control" type="date" name="session_date" required></div>
    <div class="col-md-2"><input class="form-control" type="time" name="session_time" required></div>
    <div class="col-md-2"><input class="form-control" name="location" placeholder="Lieu"></div>
    <div class="col-md-2"><input class="form-control" name="room" placeholder="Salle"></div>
    <div class="col-md-1"><input class="form-control" type="number" name="capacity" value="30"></div>
    <div class="col-md-1"><input class="form-control" type="number" name="duration_minutes" value="60"></div>
    <div class="col-md-3"><input class="form-control" name="evaluators" placeholder="Évaluateurs"></div>
    <div class="col-md-3"><input class="form-control" name="instructions" placeholder="Consignes"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Créer</button></div>
  </form>
</div></div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card app-card"><div class="list-group list-group-flush">
      <?php foreach ($sessions as $s): ?>
        <a class="list-group-item list-group-item-action <?= (int) $s['id_session'] === $sessionId ? 'active' : '' ?>" href="?session=<?= (int) $s['id_session'] ?>&embed=1">
          <strong><?= e($s['name']) ?></strong>
          <div class="small"><?= e($s['session_date'] . ' ' . substr((string) $s['session_time'], 0, 5)) ?> · <?= (int) $s['assigned'] ?>/<?= (int) $s['capacity'] ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$sessions): ?><div class="p-3 text-secondary">Aucune session.</div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-8">
    <?php if ($sessionId > 0): ?>
      <div class="card app-card mb-3"><div class="card-body">
        <h2 class="h6">Affecter un candidat</h2>
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="id_session" value="<?= $sessionId ?>">
          <div class="col-md-9">
            <select class="form-select" name="id_application" required>
              <?php foreach ($candidates as $c): ?>
                <option value="<?= (int) $c['id_application'] ?>"><?= e($c['application_number'] . ' — ' . $c['prenom'] . ' ' . $c['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><button class="btn btn-primary w-100">Affecter</button></div>
        </form>
      </div></div>
      <div class="card app-card"><div class="table-responsive"><table class="table table-dark mb-0">
        <thead><tr><th>Candidat</th><th>Présence</th><th>Résultat</th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $a): ?>
          <tr>
            <td><?= e($a['application_number'] . ' — ' . $a['prenom'] . ' ' . $a['nom']) ?></td>
            <td>
              <form method="post" class="d-flex gap-1">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="attendance">
                <input type="hidden" name="id_assignment" value="<?= (int) $a['id_assignment'] ?>">
                <select name="attendance" class="form-select form-select-sm" onchange="this.form.submit()">
                  <?php foreach (['scheduled','present','absent','rescheduled'] as $att): ?>
                    <option value="<?= $att ?>" <?= $a['attendance'] === $att ? 'selected' : '' ?>><?= $att ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td>
              <form method="post" class="row g-1">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="score">
                <input type="hidden" name="id_assignment" value="<?= (int) $a['id_assignment'] ?>">
                <input type="hidden" name="id_application" value="<?= (int) $a['id_application'] ?>">
                <div class="col-4"><input class="form-control form-control-sm" name="score" placeholder="Score" value="<?= e((string) ($a['score'] ?? '')) ?>"></div>
                <div class="col-5"><input class="form-control form-control-sm" name="recommendation" placeholder="Avis" value="<?= e((string) $a['recommendation']) ?>"></div>
                <div class="col-3"><button class="btn btn-sm btn-outline-light w-100">OK</button></div>
                <div class="col-12"><input class="form-control form-control-sm" name="comments" placeholder="Commentaire" value="<?= e((string) ($a['comments'] ?? '')) ?>"></div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div></div>
    <?php endif; ?>
  </div>
</div>

</div>


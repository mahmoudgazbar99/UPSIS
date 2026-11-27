<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_admissions_permission('admissions.manage');
admissions_ensure();

$message = '';
$error = '';
$campaigns = db()->query('SELECT id_campaign, label FROM admission_campaign ORDER BY id_campaign DESC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'schedule') {
            $appId = (int) ($_POST['id_application'] ?? 0);
            $app = admissions_find_application($appId);
            if (!$app) {
                throw new InvalidArgumentException('Candidature introuvable.');
            }
            db()->prepare(
                'INSERT INTO admission_interview
                 (id_application, id_campaign, interview_date, interview_time, location, room, jury_members, status)
                 VALUES (?,?,?,?,?,?,?,"scheduled")'
            )->execute([
                $appId,
                (int) ($_POST['id_campaign'] ?? $app['id_campaign']),
                (string) $_POST['interview_date'],
                (string) $_POST['interview_time'],
                trim((string) ($_POST['location'] ?? '')),
                trim((string) ($_POST['room'] ?? '')),
                trim((string) ($_POST['jury_members'] ?? '')),
            ]);
            if (admissions_can_transition((string) $app['status'], 'interview_scheduled')) {
                admissions_transition($appId, 'interview_scheduled', 'Entretien planifié');
            }
            admissions_transition($appId, 'scheduled', 'Entretien', 'interview_status');
            admissions_notify((int) $app['id_candidate'], 'interview_scheduled', 'Entretien planifié', 'Consultez date et lieu dans votre espace.', $appId);
            $message = 'Entretien planifié.';
        }
        if ($action === 'evaluate') {
            $interviewId = (int) ($_POST['id_interview'] ?? 0);
            db()->prepare(
                'UPDATE admission_interview SET score=?, criteria_json=?, comments=?, recommendation=?, status=?, evaluated_by=?, evaluated_at=CURRENT_TIMESTAMP
                 WHERE id_interview=?'
            )->execute([
                $_POST['score'] !== '' ? (float) $_POST['score'] : null,
                trim((string) ($_POST['criteria_json'] ?? '')),
                trim((string) ($_POST['comments'] ?? '')),
                trim((string) ($_POST['recommendation'] ?? '')),
                (string) ($_POST['status'] ?? 'completed'),
                admissions_actor_label(),
                $interviewId,
            ]);
            $row = db()->prepare('SELECT id_application FROM admission_interview WHERE id_interview = ?');
            $row->execute([$interviewId]);
            $appId = (int) $row->fetchColumn();
            if ($appId > 0) {
                admissions_transition($appId, 'completed', 'Entretien', 'interview_status');
                if (admissions_can_transition((string) (admissions_find_application($appId)['status'] ?? ''), 'admission_pending')) {
                    admissions_transition($appId, 'admission_pending', 'Dossier prêt pour le comité');
                }
            }
            $message = 'Évaluation enregistrée.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$apps = db()->query(
    'SELECT a.id_application, a.application_number, a.id_campaign, c.nom, c.prenom, camp.label
     FROM admission_application a
     JOIN admission_candidate c ON c.id_candidate = a.id_candidate
     JOIN admission_campaign camp ON camp.id_campaign = a.id_campaign
     WHERE a.status NOT IN ("rejected","withdrawn","enrolled","expired")
     ORDER BY a.application_number DESC
     LIMIT 300'
)->fetchAll();
$rows = db()->query(
    'SELECT i.*, a.application_number, c.nom, c.prenom
     FROM admission_interview i
     JOIN admission_application a ON a.id_application = i.id_application
     JOIN admission_candidate c ON c.id_candidate = a.id_candidate
     ORDER BY i.interview_date DESC, i.interview_time DESC'
)->fetchAll();

page_head('Entretiens d’admission', 'admissions', 'assets/admissions-lightswind.css?v=1');
?>
<div class="adm-lw-admin">
<div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="admissions.php?embed=1">← Admissions</a></div>
<p class="eyebrow">Admissions</p>
<h1 class="h3 fw-bold mb-3">Entretiens</h1>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card app-card mb-4"><div class="card-body">
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="schedule">
    <div class="col-md-4">
      <select class="form-select" name="id_application" required>
        <?php foreach ($apps as $a): ?>
          <option value="<?= (int) $a['id_application'] ?>"><?= e($a['application_number'] . ' — ' . $a['prenom'] . ' ' . $a['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><input class="form-control" type="date" name="interview_date" required></div>
    <div class="col-md-2"><input class="form-control" type="time" name="interview_time" required></div>
    <div class="col-md-2"><input class="form-control" name="location" placeholder="Lieu"></div>
    <div class="col-md-2"><input class="form-control" name="room" placeholder="Salle"></div>
    <div class="col-md-4"><input class="form-control" name="jury_members" placeholder="Jury"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Planifier</button></div>
  </form>
</div></div>

<?php foreach ($rows as $r): ?>
  <div class="card app-card mb-2"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <div>
        <strong><?= e($r['application_number'] . ' — ' . $r['prenom'] . ' ' . $r['nom']) ?></strong>
        <div class="small"><?= e($r['interview_date'] . ' ' . substr((string) $r['interview_time'], 0, 5)) ?> · <?= e($r['location']) ?> <?= e($r['room']) ?></div>
        <div class="small text-secondary">Jury : <?= e((string) $r['jury_members']) ?></div>
      </div>
      <span class="badge text-bg-secondary"><?= e($r['status']) ?></span>
    </div>
    <form method="post" class="row g-2 mt-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="evaluate">
      <input type="hidden" name="id_interview" value="<?= (int) $r['id_interview'] ?>">
      <div class="col-md-2"><input class="form-control form-control-sm" name="score" placeholder="Score" value="<?= e((string) ($r['score'] ?? '')) ?>"></div>
      <div class="col-md-2">
        <select class="form-select form-select-sm" name="status">
          <?php foreach (['scheduled','completed','no_show','rescheduled'] as $st): ?>
            <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><input class="form-control form-control-sm" name="recommendation" placeholder="Recommandation" value="<?= e((string) $r['recommendation']) ?>"></div>
      <div class="col-md-3"><input class="form-control form-control-sm" name="comments" placeholder="Commentaires" value="<?= e((string) ($r['comments'] ?? '')) ?>"></div>
      <div class="col-md-2"><button class="btn btn-sm btn-outline-light w-100">Enregistrer</button></div>
    </form>
  </div></div>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="text-secondary">Aucun entretien.</p><?php endif; ?>

</div>


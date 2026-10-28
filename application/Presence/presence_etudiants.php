<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_teacher_or_admin();

$pdo = db();
presence_ensure_table($pdo);

$message = '';
$error = '';
$isTeacherAccount = is_teacher();
$today = date('Y-m-d');

$teacherId = $isTeacherAccount
    ? current_teacher_id()
    : max(0, (int) ($_REQUEST['id_enseignant'] ?? 0));
$subjectId = max(0, (int) ($_REQUEST['id_mat'] ?? 0));
$groupId = max(0, (int) ($_REQUEST['id_groupe'] ?? 0));

if ($isTeacherAccount) {
    $sessionDate = $today;
} else {
    $sessionDate = trim((string) ($_REQUEST['date_seance'] ?? $today));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
        $sessionDate = $today;
    }
}

$embedded = (($_GET['embed'] ?? $_POST['embed'] ?? '') === '1');

if (is_admin()) {
    $teachers = $pdo->query(
        'SELECT id_enseignant, cin, nom, prenom FROM enseignant ORDER BY nom, prenom'
    )->fetchAll();
} else {
    $teacherStmt = $pdo->prepare(
        'SELECT id_enseignant, cin, nom, prenom FROM enseignant WHERE id_enseignant = ?'
    );
    $teacherStmt->execute([$teacherId]);
    $teachers = $teacherStmt->fetchAll();
}
$teacherName = $teachers
    ? trim((string) $teachers[0]['nom'] . ' ' . (string) $teachers[0]['prenom'])
    : 'Enseignant';

$subjects = [];
$groups = [];
if ($teacherId > 0) {
    $subjectsQuery = $pdo->prepare(
        'SELECT DISTINCT m.id_mat, m.semestre, m.code_ecue, m.description_ecue
         FROM enseignant_affectation a
         JOIN matiere m ON m.id_mat = a.id_mat
         WHERE a.id_enseignant = ?
         ORDER BY m.semestre, m.code_ecue, m.description_ecue'
    );
    $subjectsQuery->execute([$teacherId]);
    $subjects = $subjectsQuery->fetchAll();
}

$allowedSubjectIds = array_map(static fn(array $row): int => (int) $row['id_mat'], $subjects);
if ($subjectId > 0 && !in_array($subjectId, $allowedSubjectIds, true)) {
    $subjectId = 0;
    $groupId = 0;
    $error = 'Matière non autorisée pour cet enseignant.';
}

if ($teacherId > 0 && $subjectId > 0) {
    $groupsQuery = $pdo->prepare(
        'SELECT DISTINCT g.id_groupe, g.semestre, g.type_groupe, g.numero,
                parent.numero AS parent_numero,
                MOD(g.numero - 1, 2) + 1 AS td_numero
         FROM enseignant_affectation a
         JOIN groupe g ON g.id_groupe = a.id_groupe
         LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
         WHERE a.id_enseignant = ? AND a.id_mat = ?
         ORDER BY g.semestre, g.type_groupe, g.numero'
    );
    $groupsQuery->execute([$teacherId, $subjectId]);
    $groups = $groupsQuery->fetchAll();
}

$allowedGroupIds = array_map(static fn(array $row): int => (int) $row['id_groupe'], $groups);
if ($groupId > 0 && !in_array($groupId, $allowedGroupIds, true)) {
    $groupId = 0;
    if ($error === '') {
        $error = 'Groupe non affecté à cet enseignant pour cette matière.';
    }
}

$assignmentOk = $teacherId > 0
    && $subjectId > 0
    && $groupId > 0
    && in_array($subjectId, $allowedSubjectIds, true)
    && in_array($groupId, $allowedGroupIds, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_presence') {
    verify_csrf();
    $teacherId = $isTeacherAccount
        ? current_teacher_id()
        : max(0, (int) ($_POST['id_enseignant'] ?? 0));
    $subjectId = max(0, (int) ($_POST['id_mat'] ?? 0));
    $groupId = max(0, (int) ($_POST['id_groupe'] ?? 0));
    $sessionDate = $isTeacherAccount
        ? $today
        : trim((string) ($_POST['date_seance'] ?? $today));
    $statuses = (array) ($_POST['statut'] ?? []);

    try {
        $check = $pdo->prepare(
            'SELECT 1 FROM enseignant_affectation
             WHERE id_enseignant = ? AND id_mat = ? AND id_groupe = ?
             LIMIT 1'
        );
        $check->execute([$teacherId, $subjectId, $groupId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Affectation enseignant / matière / groupe invalide.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            throw new RuntimeException('Date de séance invalide.');
        }

        $membersQuery = $pdo->prepare(
            'SELECT e.id_etudiant
             FROM groupe_etudiant ge
             JOIN etudiant e ON e.id_etudiant = ge.id_etudiant
             WHERE ge.id_groupe = ?'
        );
        $membersQuery->execute([$groupId]);
        $memberIds = array_map('intval', $membersQuery->fetchAll(PDO::FETCH_COLUMN));
        if (!$memberIds) {
            throw new RuntimeException('Ce groupe ne contient aucun étudiant.');
        }

        $sessionHours = presence_resolve_session_hours($pdo, $groupId, $subjectId);
        $upsert = $pdo->prepare(
            'INSERT INTO presence_etudiant
                (id_groupe, id_mat, id_etudiant, date_seance, statut, duree_heures, id_enseignant, recorded_by_role)
             VALUES
                (:id_groupe, :id_mat, :id_etudiant, :date_seance, :statut, :duree_heures, :id_enseignant, :recorded_by_role)
             ON DUPLICATE KEY UPDATE
                statut = VALUES(statut),
                duree_heures = VALUES(duree_heures),
                id_enseignant = VALUES(id_enseignant),
                recorded_by_role = VALUES(recorded_by_role),
                updated_at = CURRENT_TIMESTAMP'
        );

        $pdo->beginTransaction();
        $saved = 0;
        $presentCount = 0;
        $absentCount = 0;
        foreach ($memberIds as $studentId) {
            $raw = (string) ($statuses[$studentId] ?? 'present');
            $statut = $raw === 'absent' ? 'absent' : 'present';
            if ($statut === 'absent') {
                $absentCount++;
            } else {
                $presentCount++;
            }
            $upsert->execute([
                'id_groupe' => $groupId,
                'id_mat' => $subjectId,
                'id_etudiant' => $studentId,
                'date_seance' => $sessionDate,
                'statut' => $statut,
                'duree_heures' => $sessionHours,
                'id_enseignant' => $teacherId,
                'recorded_by_role' => $isTeacherAccount ? 'enseignant' : 'admin',
            ]);
            $saved++;
        }
        $pdo->commit();
        $sessionLabelQuery = $pdo->prepare(
            'SELECT m.code_ecue, g.type_groupe, g.numero
             FROM matiere m, groupe g
             WHERE m.id_mat = ? AND g.id_groupe = ?'
        );
        $sessionLabelQuery->execute([$subjectId, $groupId]);
        $sessionLabel = $sessionLabelQuery->fetch() ?: [];
        audit_record(
            'presence',
            'enregistrer',
            trim((string) ($sessionLabel['code_ecue'] ?? 'Matière'))
            . ' · ' . trim((string) (($sessionLabel['type_groupe'] ?? 'Groupe') . ' ' . ($sessionLabel['numero'] ?? '')))
            . ' · ' . date('d/m/Y', strtotime($sessionDate))
            . ' · ' . $presentCount . ' présent' . ($presentCount > 1 ? 's' : '')
            . ', ' . $absentCount . ' absent' . ($absentCount > 1 ? 's' : '')
        );
        $message = $saved . ' présence' . ($saved > 1 ? 's' : '') . ' enregistrée' . ($saved > 1 ? 's' : '')
            . ' pour le ' . date('d/m/Y', strtotime($sessionDate)) . '.';
        $assignmentOk = true;

        $subjectsQuery = $pdo->prepare(
            'SELECT DISTINCT m.id_mat, m.semestre, m.code_ecue, m.description_ecue
             FROM enseignant_affectation a
             JOIN matiere m ON m.id_mat = a.id_mat
             WHERE a.id_enseignant = ?
             ORDER BY m.semestre, m.code_ecue, m.description_ecue'
        );
        $subjectsQuery->execute([$teacherId]);
        $subjects = $subjectsQuery->fetchAll();
        $allowedSubjectIds = array_map(static fn(array $row): int => (int) $row['id_mat'], $subjects);

        $groupsQuery = $pdo->prepare(
            'SELECT DISTINCT g.id_groupe, g.semestre, g.type_groupe, g.numero,
                    parent.numero AS parent_numero,
                    MOD(g.numero - 1, 2) + 1 AS td_numero
             FROM enseignant_affectation a
             JOIN groupe g ON g.id_groupe = a.id_groupe
             LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
             WHERE a.id_enseignant = ? AND a.id_mat = ?
             ORDER BY g.semestre, g.type_groupe, g.numero'
        );
        $groupsQuery->execute([$teacherId, $subjectId]);
        $groups = $groupsQuery->fetchAll();
        $allowedGroupIds = array_map(static fn(array $row): int => (int) $row['id_groupe'], $groups);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
        $assignmentOk = false;
    }
}

$students = [];
$existingPresence = [];
$selectedGroup = null;
$selectedSubject = null;
if ($assignmentOk) {
    foreach ($groups as $group) {
        if ((int) $group['id_groupe'] === $groupId) {
            $selectedGroup = $group;
            break;
        }
    }
    foreach ($subjects as $subject) {
        if ((int) $subject['id_mat'] === $subjectId) {
            $selectedSubject = $subject;
            break;
        }
    }

    $studentsQuery = $pdo->prepare(
        'SELECT e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.num_inscription
         FROM groupe_etudiant ge
         JOIN etudiant e ON e.id_etudiant = ge.id_etudiant
         WHERE ge.id_groupe = ?
         ORDER BY e.nom, e.prenom, e.id_etudiant'
    );
    $studentsQuery->execute([$groupId]);
    $students = $studentsQuery->fetchAll();

    if ($students) {
        $presenceQuery = $pdo->prepare(
            'SELECT id_etudiant, statut
             FROM presence_etudiant
             WHERE id_groupe = ? AND id_mat = ? AND date_seance = ?'
        );
        $presenceQuery->execute([$groupId, $subjectId, $sessionDate]);
        foreach ($presenceQuery->fetchAll() as $row) {
            $existingPresence[(int) $row['id_etudiant']] = (string) $row['statut'];
        }
    }
}

page_head('Présence des étudiants', 'presence');
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 page-title-block">
  <div>
    <p class="eyebrow"><?= $isTeacherAccount ? 'Espace enseignant' : 'Scolarité' ?></p>
    <h1 class="display-6 fw-bold mb-1">Présence des étudiants</h1>
    <p class="text-secondary mb-0">
      <?= $isTeacherAccount
        ? 'Choisissez votre matière et votre groupe / TD. La date du jour est appliquée automatiquement.'
        : 'Enseignant, matière, groupe affecté, puis pointage présent / absent.' ?>
    </p>
  </div>
  <?php if ($isTeacherAccount): ?>
    <div class="presence-date-pill" aria-label="Date du jour">
      <span class="presence-date-pill-label">Date du jour</span>
      <strong><?= e(date('d/m/Y')) ?></strong>
    </div>
  <?php endif; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="get" class="card app-card mb-4 presence-filter-card" id="presence-filter-form">
  <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
  <div class="card-body p-4">
    <div class="row g-3 align-items-end">
      <?php if (is_admin()): ?>
        <div class="col-lg-3 col-md-6">
          <label class="form-label" for="id_enseignant">Enseignant</label>
          <select class="form-select" id="id_enseignant" name="id_enseignant" required onchange="this.form.submit()">
            <option value="">Choisir</option>
            <?php foreach ($teachers as $teacher): ?>
              <option value="<?= (int) $teacher['id_enseignant'] ?>" <?= $teacherId === (int) $teacher['id_enseignant'] ? 'selected' : '' ?>>
                <?= e($teacher['nom'] . ' ' . $teacher['prenom'] . ' — ' . $teacher['cin']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="id_enseignant" value="<?= (int) $teacherId ?>">
      <?php endif; ?>

      <div class="<?= is_admin() ? 'col-lg-3 col-md-6' : 'col-lg-4 col-md-6' ?>">
        <label class="form-label" for="id_mat">Matière</label>
        <select class="form-select" id="id_mat" name="id_mat" <?= $teacherId <= 0 || !$subjects ? 'disabled' : '' ?> required onchange="this.form.submit()">
          <option value="">Choisir</option>
          <?php foreach ($subjects as $subject): ?>
            <option value="<?= (int) $subject['id_mat'] ?>" <?= $subjectId === (int) $subject['id_mat'] ? 'selected' : '' ?>>
              <?= e($subject['semestre'] . ' · ' . $subject['code_ecue'] . ' — ' . $subject['description_ecue']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="<?= is_admin() ? 'col-lg-3 col-md-6' : 'col-lg-4 col-md-6' ?>">
        <label class="form-label" for="id_groupe">Groupe / TD affecté</label>
        <select class="form-select" id="id_groupe" name="id_groupe" <?= $subjectId <= 0 || !$groups ? 'disabled' : '' ?> required>
          <option value="">Choisir</option>
          <?php foreach ($groups as $group): ?>
            <option value="<?= (int) $group['id_groupe'] ?>" <?= $groupId === (int) $group['id_groupe'] ? 'selected' : '' ?>>
              <?= e(presence_group_label($group)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if (is_admin()): ?>
        <div class="col-lg-2 col-md-6">
          <label class="form-label" for="date_seance">Date</label>
          <input class="form-control" type="date" id="date_seance" name="date_seance" value="<?= e($sessionDate) ?>" required>
        </div>
      <?php endif; ?>

      <div class="<?= is_admin() ? 'col-lg-1 col-md-6' : 'col-lg-4 col-md-12' ?>">
        <button class="btn btn-primary w-100" type="submit" <?= $teacherId <= 0 ? 'disabled' : '' ?>>Afficher</button>
      </div>
    </div>

    <?php if ($teacherId > 0 && !$subjects): ?>
      <div class="alert alert-warning mt-3 mb-0">Aucune matière affectée<?= $isTeacherAccount ? ' à votre compte' : ' pour cet enseignant' ?>.</div>
    <?php elseif ($subjectId > 0 && !$groups): ?>
      <div class="alert alert-warning mt-3 mb-0">Aucun groupe / TD affecté pour cette matière.</div>
    <?php endif; ?>
  </div>
</form>

<?php if ($assignmentOk && $selectedGroup && $selectedSubject): ?>
<form method="post" class="presence-sheet" id="presence-form">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save_presence">
  <input type="hidden" name="id_enseignant" value="<?= (int) $teacherId ?>">
  <input type="hidden" name="id_mat" value="<?= (int) $subjectId ?>">
  <input type="hidden" name="id_groupe" value="<?= (int) $groupId ?>">
  <input type="hidden" name="date_seance" value="<?= e($sessionDate) ?>">
  <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>

  <div class="card app-card presence-roster-card">
    <div class="card-body presence-roster-toolbar">
      <div>
        <p class="eyebrow mb-1">Séance du <?= e(date('d/m/Y', strtotime($sessionDate))) ?><?= $isTeacherAccount ? ' (automatique)' : '' ?></p>
        <h2 class="h5 mb-0"><?= e($selectedSubject['code_ecue'] . ' — ' . $selectedSubject['description_ecue']) ?></h2>
        <p class="text-secondary mb-0 mt-1">
          <?= e(presence_group_label($selectedGroup)) ?>
          · <?= e($teacherName) ?>
          · <?= count($students) ?> étudiant<?= count($students) > 1 ? 's' : '' ?>
        </p>
      </div>
      <div class="presence-bulk-actions">
        <button type="button" class="btn btn-sm btn-outline-success" data-presence-bulk="present">Tous présents</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-presence-bulk="absent">Tous absents</button>
      </div>
    </div>

    <?php if (!$students): ?>
      <div class="card-body border-top text-secondary">Aucun étudiant dans ce groupe.</div>
    <?php else: ?>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table align-middle mb-0 presence-table">
            <thead>
              <tr>
                <th scope="col">#</th>
                <th scope="col">Étudiant</th>
                <th scope="col">CIN / passeport</th>
                <th scope="col">Inscription</th>
                <th scope="col" class="text-end">Statut</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $index => $student):
                  $studentId = (int) $student['id_etudiant'];
                  $status = $existingPresence[$studentId] ?? 'present';
                  ?>
                <tr class="presence-row" data-status="<?= e($status) ?>">
                  <td class="text-secondary"><?= $index + 1 ?></td>
                  <td><strong><?= e($student['nom'] . ' ' . $student['prenom']) ?></strong></td>
                  <td><?= e($student['cin_passeport']) ?></td>
                  <td><?= e($student['num_inscription']) ?></td>
                  <td>
                    <div class="presence-status-toggle" role="group" aria-label="Statut de <?= e($student['nom'] . ' ' . $student['prenom']) ?>">
                      <input type="radio" class="btn-check" name="statut[<?= $studentId ?>]" id="present-<?= $studentId ?>" value="present" <?= $status === 'present' ? 'checked' : '' ?> autocomplete="off">
                      <label class="presence-chip presence-chip-present" for="present-<?= $studentId ?>">Présent</label>
                      <input type="radio" class="btn-check" name="statut[<?= $studentId ?>]" id="absent-<?= $studentId ?>" value="absent" <?= $status === 'absent' ? 'checked' : '' ?> autocomplete="off">
                      <label class="presence-chip presence-chip-absent" for="absent-<?= $studentId ?>">Absent</label>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-body presence-submit-bar border-top">
        <div class="presence-summary text-secondary">
          <span data-presence-count-present>0</span> présent(s) ·
          <span data-presence-count-absent>0</span> absent(s)
        </div>
        <button class="btn btn-primary" type="submit">Enregistrer la présence</button>
      </div>
    <?php endif; ?>
  </div>
</form>
<?php elseif ($teacherId > 0 && $subjectId > 0 && $groupId <= 0 && $groups): ?>
  <div class="card app-card"><div class="card-body text-secondary">Choisissez le groupe / TD affecté<?= is_admin() ? ' et la date' : '' ?>, puis cliquez sur Afficher.</div></div>
<?php endif; ?>

<script>
(function () {
  const subjectSelect = document.getElementById('id_mat');
  const groupSelect = document.getElementById('id_groupe');
  subjectSelect?.addEventListener('change', function () {
    if (groupSelect) groupSelect.value = '';
  });

  const form = document.getElementById('presence-form');
  if (!form) return;

  function refreshCounts() {
    const present = form.querySelectorAll('input[value="present"]:checked').length;
    const absent = form.querySelectorAll('input[value="absent"]:checked').length;
    const presentLabel = form.querySelector('[data-presence-count-present]');
    const absentLabel = form.querySelector('[data-presence-count-absent]');
    if (presentLabel) presentLabel.textContent = String(present);
    if (absentLabel) absentLabel.textContent = String(absent);
    form.querySelectorAll('.presence-row').forEach(function (row) {
      const checked = row.querySelector('input[type="radio"]:checked');
      row.dataset.status = checked ? checked.value : 'present';
    });
  }

  form.querySelectorAll('[data-presence-bulk]').forEach(function (button) {
    button.addEventListener('click', function () {
      const status = button.getAttribute('data-presence-bulk');
      form.querySelectorAll('input[type="radio"][value="' + status + '"]').forEach(function (input) {
        input.checked = true;
      });
      refreshCounts();
    });
  });

  form.addEventListener('change', function (event) {
    if (event.target && event.target.matches('input[type="radio"]')) refreshCounts();
  });

  refreshCounts();
})();
</script>
<?php page_foot(); ?>

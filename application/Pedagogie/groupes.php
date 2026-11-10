<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_permission('students.assign');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$search = trim((string) ($_GET['search'] ?? ''));
$manualMessage = ($_GET['moved'] ?? '') === '1' ? 'Le groupe de l’étudiant a été modifié. Les effectifs ont été actualisés.' : '';
$student = null;
$groups = [];
$error = '';

if (($_GET['ajax'] ?? '') === 'group_students') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $groupId = filter_input(INPUT_GET, 'id_groupe', FILTER_VALIDATE_INT) ?: 0;
    if ($groupId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Groupe invalide.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $groupMeta = db()->prepare(
            'SELECT id_groupe, semestre, type_groupe, numero
             FROM groupe
             WHERE id_groupe = ? AND type_groupe = "Cours"
             LIMIT 1'
        );
        $groupMeta->execute([$groupId]);
        $meta = $groupMeta->fetch();
        if (!$meta) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Groupe Cours introuvable.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $studentsQuery = db()->prepare(
            'SELECT e.num_inscription, e.nom, e.prenom, e.cin_passeport,
                    (
                        SELECT CASE
                            WHEN parent.numero IS NOT NULL
                                THEN CONCAT("Cours ", parent.numero, " · TD ", ((td.numero - 1) % 2) + 1)
                            ELSE CONCAT("TD ", td.numero)
                        END
                        FROM groupe_etudiant ge_td
                        JOIN groupe td ON td.id_groupe = ge_td.id_groupe
                            AND td.type_groupe = "TD"
                            AND td.semestre = g.semestre
                        LEFT JOIN groupe parent ON parent.id_groupe = td.id_groupe_parent
                        WHERE ge_td.id_etudiant = e.id_etudiant
                        ORDER BY td.numero
                        LIMIT 1
                    ) AS groupe_td,
                    (
                        SELECT CONCAT("TP ", tp.numero)
                        FROM groupe_etudiant ge_tp
                        JOIN groupe tp ON tp.id_groupe = ge_tp.id_groupe
                            AND tp.type_groupe = "TP"
                            AND tp.semestre = g.semestre
                        WHERE ge_tp.id_etudiant = e.id_etudiant
                        ORDER BY tp.numero
                        LIMIT 1
                    ) AS groupe_tp
             FROM groupe_etudiant ge
             JOIN etudiant e ON e.id_etudiant = ge.id_etudiant
             JOIN groupe g ON g.id_groupe = ge.id_groupe
             WHERE ge.id_groupe = ?
             ORDER BY e.nom, e.prenom, e.num_inscription'
        );
        $studentsQuery->execute([$groupId]);
        $rows = $studentsQuery->fetchAll();
        echo json_encode([
            'ok' => true,
            'group' => [
                'id_groupe' => (int) $meta['id_groupe'],
                'semestre' => (string) $meta['semestre'],
                'numero' => (int) $meta['numero'],
                'label' => 'Groupe ' . (int) $meta['numero'] . ' · ' . (string) $meta['semestre'],
            ],
            'students' => array_map(static function (array $row): array {
                return [
                    'num_inscription' => (string) ($row['num_inscription'] ?? ''),
                    'nom' => (string) ($row['nom'] ?? ''),
                    'prenom' => (string) ($row['prenom'] ?? ''),
                    'cin_passeport' => (string) ($row['cin_passeport'] ?? ''),
                    'groupe_td' => (string) ($row['groupe_td'] ?? ''),
                    'groupe_tp' => (string) ($row['groupe_tp'] ?? ''),
                ];
            }, $rows),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Impossible de charger les étudiants.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_group') {
    verify_csrf();
    $studentId = filter_input(INPUT_POST, 'id_etudiant', FILTER_VALIDATE_INT) ?: 0;
    $groupId = filter_input(INPUT_POST, 'id_groupe', FILTER_VALIDATE_INT) ?: 0;
    if (!$studentId || !$groupId) {
        $error = 'Veuillez sélectionner un étudiant et un groupe.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $groupQuery = $pdo->prepare(
                'SELECT cible.semestre, cible.type_groupe, cible.id_groupe_parent,
                        parent.id_groupe AS parent_id, parent.type_groupe AS parent_type
                 FROM groupe cible
                 LEFT JOIN groupe parent ON parent.id_groupe = cible.id_groupe_parent
                 WHERE cible.id_groupe = ?'
            );
            $groupQuery->execute([$groupId]);
            $targetGroup = $groupQuery->fetch();
            if (!$targetGroup) {
                throw new RuntimeException('Le groupe sélectionné est introuvable.');
            }
            if ($targetGroup['type_groupe'] === 'TD') {
                if (!$targetGroup['parent_id'] || $targetGroup['parent_type'] !== 'Cours') {
                    throw new RuntimeException('Le groupe TD sélectionné n’est lié à aucun groupe Cours.');
                }
                $courseGroupId = (int) $targetGroup['parent_id'];
                $tdGroupId = $groupId;
            } elseif ($targetGroup['type_groupe'] === 'Cours') {
                $childrenQuery = $pdo->prepare(
                    'SELECT id_groupe FROM groupe
                     WHERE id_groupe_parent = ? AND type_groupe = "TD"
                     ORDER BY numero'
                );
                $childrenQuery->execute([$groupId]);
                $tdGroupIds = array_map('intval', $childrenQuery->fetchAll(PDO::FETCH_COLUMN));
                if (count($tdGroupIds) !== 2) {
                    throw new RuntimeException('Chaque groupe Cours doit avoir exactement deux groupes TD.');
                }
                $courseGroupId = $groupId;
                $tdGroupId = $tdGroupIds[random_int(0, 1)];
            } else {
                throw new RuntimeException('Type de groupe non pris en charge.');
            }
            $studentQuery = $pdo->prepare('SELECT cin_passeport FROM etudiant WHERE id_etudiant = ?');
            $studentQuery->execute([$studentId]);
            $studentCin = $studentQuery->fetchColumn();
            if ($studentCin === false) {
                throw new RuntimeException('Étudiant introuvable.');
            }
            $remove = $pdo->prepare(
                'DELETE FROM groupe_etudiant AS ge
                 USING groupe AS old_group
                 WHERE old_group.id_groupe = ge.id_groupe
                   AND ge.id_etudiant = ? AND old_group.semestre = ?
                   AND old_group.type_groupe IN ("Cours", "TD")'
            );
            $remove->execute([$studentId, $targetGroup['semestre']]);
            $attach = $pdo->prepare('INSERT INTO groupe_etudiant (id_groupe, id_etudiant) VALUES (?, ?)');
            $attach->execute([$courseGroupId, $studentId]);
            $attach->execute([$tdGroupId, $studentId]);
            $pdo->commit();
            $studentNameQuery = $pdo->prepare('SELECT prenom, nom FROM etudiant WHERE id_etudiant = ?');
            $studentNameQuery->execute([$studentId]);
            $studentName = $studentNameQuery->fetch() ?: [];
            flash('success', 'Le groupe de ' . trim((string) (($studentName['prenom'] ?? '') . ' ' . ($studentName['nom'] ?? 'l’étudiant'))) . ' a été modifié. Les effectifs ont été actualisés.');
            header('Location: groupes.php?search=' . urlencode((string) $studentCin));
            exit;
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $error = $exception->getMessage();
        }
    }
}

if ($search !== '') {
    $studentQuery = db()->prepare(
        'SELECT e.id_etudiant, e.nom, e.prenom, e.nom_ar, e.prenom_ar, e.cin_passeport,
                e.num_inscription, s.nom_spec, s.nom_spec_ar
         FROM etudiant e
         LEFT JOIN specialite s ON s.id_spec = e.id_spec
         WHERE e.cin_passeport = :search
         LIMIT 1'
    );
    $studentQuery->execute(['search' => $search]);
    $student = $studentQuery->fetch();

    if (!$student) {
        $error = 'Aucun étudiant ne correspond à ce CIN / passeport.';
    } else {
        $groupQuery = db()->prepare(
            'SELECT g.semestre, g.type_groupe, g.numero, g.capacite,
                    parent.numero AS parent_numero,
                    CASE WHEN g.type_groupe = "TD" THEN MOD(g.numero - 1, 2) + 1 END AS td_numero,
                    (SELECT COUNT(*) FROM groupe_etudiant members WHERE members.id_groupe = g.id_groupe) AS effectif
             FROM groupe g
             LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
             JOIN groupe_etudiant ge ON ge.id_groupe = g.id_groupe
             WHERE ge.id_etudiant = :student_id AND g.type_groupe IN ("Cours", "TD")
             ORDER BY FIELD(g.semestre, "S1", "S2", "S3", "S4", "S5", "S6"),
                      FIELD(g.type_groupe, "Cours", "TD"), g.numero'
        );
        $groupQuery->execute(['student_id' => $student['id_etudiant']]);
        $groups = $groupQuery->fetchAll();
    }
}

page_head('Groupes étudiants', 'groups');
$availableGroups = db()->query(
    'SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero,
            parent.numero AS parent_numero,
            CASE WHEN g.type_groupe = "TD" THEN MOD(g.numero - 1, 2) + 1 END AS td_numero,
            (SELECT COUNT(*) FROM groupe_etudiant members WHERE members.id_groupe = g.id_groupe) AS effectif
     FROM groupe g
     LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
     WHERE g.type_groupe IN ("Cours", "TD")
     ORDER BY FIELD(g.semestre, "S1", "S2", "S3", "S4", "S5", "S6"),
              FIELD(g.type_groupe, "Cours", "TD"), g.numero'
)->fetchAll();
$courseGroups = db()->query(
    'SELECT g.id_groupe, g.semestre, g.numero, g.capacite,
            (SELECT COUNT(*) FROM groupe_etudiant members WHERE members.id_groupe = g.id_groupe) AS effectif
     FROM groupe g
     WHERE g.type_groupe = "Cours"
     ORDER BY FIELD(g.semestre, "S1", "S2", "S3", "S4", "S5", "S6"), g.numero'
)->fetchAll();
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
  <div>
    <p class="eyebrow">Scolarité</p>
    <h1 class="display-6 fw-bold mb-1">Groupes étudiants</h1>
    <p class="text-secondary mb-0">Recherchez un étudiant pour consulter sa spécialité et ses groupes.</p>
  </div>
  
</div>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-9">
        <label class="form-label" for="search">CIN / passeport</label>
        <input class="form-control form-control-lg" id="search" name="search" value="<?= e($search) ?>" placeholder="Saisissez le CIN ou le numéro de passeport" required>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary btn-lg w-100">Rechercher</button>
      </div>
    </form>
    <?php if ($manualMessage): ?><div class="alert alert-success mt-3 mb-0"><?= e($manualMessage) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger mt-3 mb-0"><?= e($error) ?></div><?php endif; ?>
  </div>
</div>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h4">Modifier manuellement un groupe</h2>
    <p class="text-secondary small">Choisissez un groupe Cours ou un sous-groupe TD. Un déplacement vers Cours attribue automatiquement l’un de ses deux TD.</p>
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_group">
      <div class="col-md-5">
        <label class="form-label" for="id_etudiant">Étudiant</label>
        <select class="form-select" id="id_etudiant" name="id_etudiant" required>
          <option value="">Choisir un étudiant</option>
          <?php foreach (db()->query('SELECT id_etudiant, nom, prenom, cin_passeport FROM etudiant ORDER BY nom, prenom') as $option): ?>
            <option value="<?= (int) $option['id_etudiant'] ?>"><?= e($option['nom'] . ' ' . $option['prenom'] . ' — ' . $option['cin_passeport']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label" for="id_groupe">Groupe</label>
        <select class="form-select" id="id_groupe" name="id_groupe" required>
          <option value="">Choisir un groupe</option>
          <?php foreach ($availableGroups as $option): ?>
            <option value="<?= (int) $option['id_groupe'] ?>">
              <?= e($option['semestre'] . ' · ' . ($option['type_groupe'] === 'TD'
                  ? 'Cours ' . $option['parent_numero'] . ' · TD ' . $option['td_numero']
                  : 'Cours ' . $option['numero']) . ' (' . $option['effectif'] . ' étudiants)') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100">Affecter</button></div>
    </form>
  </div>
</div>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h4">Groupes Cours</h2>
    <p class="text-secondary small">Effectif actuel de chaque groupe Cours.</p>
    <div class="table-responsive">
      <table class="table table-dark table-hover align-middle mb-0">
        <thead><tr><th>Semestre</th><th>Groupe</th><th>Nombre actuel d’étudiants</th><th>Capacité de référence</th></tr></thead>
        <tbody>
        <?php foreach ($courseGroups as $courseGroup): ?>
          <tr>
            <td><?= e($courseGroup['semestre']) ?></td>
            <td>
              <button
                type="button"
                class="btn btn-link p-0 fw-bold text-decoration-underline group-roster-trigger"
                data-group-id="<?= (int) $courseGroup['id_groupe'] ?>"
                data-group-label="Groupe <?= (int) $courseGroup['numero'] ?> · <?= e((string) $courseGroup['semestre']) ?>"
              >Groupe <?= (int) $courseGroup['numero'] ?></button>
            </td>
            <td><?= (int) $courseGroup['effectif'] ?> étudiant<?= (int) $courseGroup['effectif'] > 1 ? 's' : '' ?></td>
            <td><?= (int) $courseGroup['capacite'] ?> étudiants</td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$courseGroups): ?><tr><td colspan="4" class="text-center text-secondary py-4">Aucun groupe Cours disponible.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="groupRosterModal" tabindex="-1" aria-labelledby="groupRosterModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light border-secondary">
      <div class="modal-header border-secondary">
        <h2 class="modal-title h5 mb-0" id="groupRosterModalLabel">Étudiants du groupe</h2>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <p class="text-secondary small mb-3" data-group-roster-meta>Sélectionnez un groupe pour afficher la liste.</p>
        <div class="table-responsive">
          <table class="table table-dark table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>N° inscription</th>
                <th>Nom</th>
                <th>Prénom</th>
                <th>CIN / passeport</th>
                <th>Groupe TD</th>
                <th>Groupe TP</th>
              </tr>
            </thead>
            <tbody data-group-roster-body>
              <tr><td colspan="6" class="text-center text-secondary py-4">Chargement…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var modalEl = document.getElementById('groupRosterModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;
  var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  var titleEl = document.getElementById('groupRosterModalLabel');
  var metaEl = modalEl.querySelector('[data-group-roster-meta]');
  var bodyEl = modalEl.querySelector('[data-group-roster-body]');
  var requestId = 0;

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function setBusy(message) {
    if (metaEl) metaEl.textContent = message || '';
    if (bodyEl) {
      bodyEl.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-4">' + escapeHtml(message || 'Chargement…') + '</td></tr>';
    }
  }

  function renderRows(students) {
    if (!bodyEl) return;
    if (!students.length) {
      bodyEl.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-4">Aucun étudiant dans ce groupe.</td></tr>';
      return;
    }
    bodyEl.innerHTML = students.map(function (row) {
      return '<tr>'
        + '<td>' + escapeHtml(row.num_inscription || '—') + '</td>'
        + '<td>' + escapeHtml(row.nom || '—') + '</td>'
        + '<td>' + escapeHtml(row.prenom || '—') + '</td>'
        + '<td>' + escapeHtml(row.cin_passeport || '—') + '</td>'
        + '<td>' + escapeHtml(row.groupe_td || '—') + '</td>'
        + '<td>' + escapeHtml(row.groupe_tp || '—') + '</td>'
        + '</tr>';
    }).join('');
  }

  document.querySelectorAll('.group-roster-trigger').forEach(function (button) {
    button.addEventListener('click', function () {
      var groupId = button.getAttribute('data-group-id') || '';
      var label = button.getAttribute('data-group-label') || 'Groupe';
      var current = ++requestId;
      if (titleEl) titleEl.textContent = label;
      setBusy('Chargement des étudiants…');
      modal.show();
      fetch('groupes.php?ajax=group_students&id_groupe=' + encodeURIComponent(groupId), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function (response) {
        return response.json().then(function (payload) {
          return { ok: response.ok, payload: payload };
        });
      }).then(function (result) {
        if (current !== requestId) return;
        if (!result.ok || !result.payload || !result.payload.ok) {
          setBusy((result.payload && result.payload.error) || 'Impossible de charger les étudiants.');
          return;
        }
        if (metaEl) {
          metaEl.textContent = (result.payload.students || []).length + ' étudiant(s) — ' + label;
        }
        renderRows(result.payload.students || []);
      }).catch(function () {
        if (current !== requestId) return;
        setBusy('Impossible de charger les étudiants.');
      });
    });
  });
})();
</script>

<?php if ($student): ?>
<div class="card app-card mb-4">
  <div class="card-body p-4">
    <div class="row g-3">
      <div class="col-md-6"><span class="text-secondary d-block">Étudiant</span><strong class="fs-5"><?= e($student['nom'] . ' ' . $student['prenom']) ?></strong><?php if (($student['nom_ar'] ?? '') !== ($student['nom'] ?? '') || ($student['prenom_ar'] ?? '') !== ($student['prenom'] ?? '')): ?><small class="text-secondary d-block"><?= e($student['nom_ar'] . ' ' . $student['prenom_ar']) ?></small><?php endif; ?></div>
      <div class="col-md-3"><span class="text-secondary d-block">CIN / Passeport</span><strong><?= e($student['cin_passeport']) ?></strong></div>
      <div class="col-md-3"><span class="text-secondary d-block">N° inscription</span><strong><?= e($student['num_inscription']) ?></strong></div>
      <div class="col-md-6"><span class="text-secondary d-block">Spécialité</span><strong><?= e($student['nom_spec'] ?? '—') ?></strong><?php if (($student['nom_spec_ar'] ?? '') !== ($student['nom_spec'] ?? '')): ?><small class="text-secondary d-block"><?= e($student['nom_spec_ar'] ?? '') ?></small><?php endif; ?></div>
    </div>
  </div>
</div>

<div class="card app-card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th>Semestre</th><th>Type</th><th>Groupe</th><th>Effectif</th><th>Capacité maximale</th></tr></thead>
      <tbody>
      <?php foreach ($groups as $group): ?>
        <tr>
          <td><?= e($group['semestre']) ?></td>
          <td><?= e($group['type_groupe']) ?></td>
          <td><strong><?= $group['type_groupe'] === 'TD'
              ? 'Cours ' . (int) $group['parent_numero'] . ' · TD ' . (int) $group['td_numero']
              : 'Cours ' . (int) $group['numero'] ?></strong></td>
          <td><?= (int) $group['effectif'] ?> étudiant<?= (int) $group['effectif'] > 1 ? 's' : '' ?></td>
          <td><?= (int) $group['capacite'] ?> étudiants</td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$groups): ?><tr><td colspan="5" class="text-center text-secondary py-5">Aucun groupe n’est encore affecté à cet étudiant.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php page_foot();

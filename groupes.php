<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$search = trim((string) ($_GET['search'] ?? ''));
$manualMessage = ($_GET['moved'] ?? '') === '1' ? 'Le groupe de l’étudiant a été modifié. Les effectifs ont été actualisés.' : '';
$student = null;
$groups = [];
$error = '';

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
                'DELETE ge FROM groupe_etudiant ge
                 JOIN groupe old_group ON old_group.id_groupe = ge.id_groupe
                 WHERE ge.id_etudiant = ? AND old_group.semestre = ?
                   AND old_group.type_groupe IN ("Cours", "TD")'
            );
            $remove->execute([$studentId, $targetGroup['semestre']]);
            $attach = $pdo->prepare('INSERT INTO groupe_etudiant (id_groupe, id_etudiant) VALUES (?, ?)');
            $attach->execute([$courseGroupId, $studentId]);
            $attach->execute([$tdGroupId, $studentId]);
            $pdo->commit();
            header('Location: groupes.php?search=' . urlencode((string) $studentCin) . '&moved=1');
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
    'SELECT g.semestre, g.numero, g.capacite,
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
            <td><strong>Groupe <?= (int) $courseGroup['numero'] ?></strong></td>
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

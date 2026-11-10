<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$pdo = db();
$defaultYear = academic_active_year_label();
$year = trim((string) ($_GET['year'] ?? ($_POST['year'] ?? $defaultYear)));
$search = trim((string) ($_GET['search'] ?? ($_POST['search'] ?? '')));
$groupId = max(0, (int) ($_GET['group_id'] ?? ($_POST['group_id'] ?? 0)));
$message = '';
$error = '';
$rows = [];

function promotion_decision_label(string $decision): string
{
    return match ($decision) {
        'admis' => 'Admis',
        'admis_par_rachat' => 'Admis par rachat',
        'refusee' => 'Refusée',
        'redouble' => 'Redouble',
        default => result_decision_label($decision),
    };
}

function promotion_rows(PDO $pdo, string $search, int $groupId, string $year): array
{
    $where = '';
    $params = [];
    if ($groupId > 0) {
        $where = ' WHERE EXISTS (SELECT 1 FROM groupe_etudiant ge WHERE ge.id_etudiant = e.id_etudiant AND ge.id_groupe = :group_id)';
        $params['group_id'] = $groupId;
    }
    if ($search !== '') {
        $where .= ($where ? ' AND ' : ' WHERE ') . '(e.nom LIKE :q OR e.prenom LIKE :q OR e.cin_passeport LIKE :q OR e.num_inscription LIKE :q)';
        $params['q'] = '%' . $search . '%';
    }
    $query = $pdo->prepare(
        'SELECT e.*, s.nom_spec, s.id_program, COALESCE(fs.total_paye, 0) AS total_paye
         FROM etudiant e JOIN specialite s ON s.id_spec = e.id_spec
         LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant
         ' . ($where ? $where . ' AND ' : ' WHERE ') . 'e.statut = "actif"
         ORDER BY e.niveau, e.nom, e.prenom'
    );
    $query->execute($params);
    $result = [];
    foreach ($query->fetchAll() as $student) {
        $id = (int) $student['id_etudiant'];
        $programId = isset($student['id_program']) ? (int) $student['id_program'] : null;
        $average = result_student_average($id, $programId);
        $published = result_row_for_student($id, $year);
        $juryDecision = (string) ($published['decision'] ?? '');
        if ($average !== null && isset($published['moyenne']) && $published['moyenne'] !== null) {
            $average = (float) $published['moyenne'];
        }
        $plan = result_progression_plan((string) $student['niveau'], $average, $programId, $juryDecision);
        $student['moyenne_generale'] = $average;
        $student['decision'] = (string) $plan['decision'];
        $student['jury_decision'] = (string) ($plan['jury_decision'] ?? $plan['decision']);
        $student['next_level'] = $plan['next_level'];
        $student['next_statut'] = $plan['statut'];
        $student['threshold'] = $plan['threshold'];
        $student['advances'] = in_array((string) $plan['decision'], ['admis', 'admis_par_rachat'], true);
        $result[] = $student;
    }
    return $result;
}

$blockers = promotion_prerequisites($year);
$canPromote = $blockers === [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'preview') {
        $rows = promotion_rows($pdo, $search, $groupId, $year);
    } elseif (($_POST['action'] ?? '') === 'commit') {
        $password = (string) ($_POST['admin_password'] ?? '');
        $confirm = (string) ($_POST['confirmation'] ?? '');
        $blockers = promotion_prerequisites($year);
        $canPromote = $blockers === [];
        if (!$canPromote) {
            $error = 'Promotion bloquée : ' . implode(' ', $blockers);
        } elseif (!preg_match('/^\d{4}-\d{4}$/', $year)) {
            $error = 'L’année scolaire doit respecter le format AAAA-AAAA.';
        } elseif (!password_verify($password, admin_password_hash()) || $confirm !== 'PROMOUVOIR') {
            $error = 'Mot de passe administrateur ou confirmation invalide.';
        } else {
            $selected = array_values(array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? []))));
            if (!$selected) {
                $error = 'Sélectionnez au moins un étudiant.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $studentSql = 'SELECT e.*, s.nom_spec, s.id_program, COALESCE(fs.total_paye, 0) AS total_paye FROM etudiant e JOIN specialite s ON s.id_spec = e.id_spec LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant WHERE e.id_etudiant = ? AND e.statut = "actif"';
                    if ($groupId > 0) {
                        $studentSql .= ' AND EXISTS (SELECT 1 FROM groupe_etudiant ge WHERE ge.id_etudiant = e.id_etudiant AND ge.id_groupe = ' . $groupId . ')';
                    }
                    $studentSql .= ' FOR UPDATE';
                    $studentQuery = $pdo->prepare($studentSql);
                    $noteQuery = $pdo->prepare('SELECT n.id_mat, n.semestre, n.note, n.note_cours, n.note_td, n.note_tp, n.note_rattrapage, m.code_ue, m.description_ue, m.code_ecue, m.description_ecue, m.coeff, m.credit, m.regime, m.volume_tp FROM note n JOIN matiere m ON m.id_mat = n.id_mat WHERE n.id_etudiant = ? ORDER BY n.semestre, m.code_ue, m.code_ecue');
                    $archive = $pdo->prepare('INSERT INTO etudiant_archive (id_etudiant, annee_scolaire, niveau, id_spec, nom_spec, num_inscription, cin_passeport, decision, moyenne_generale, student_payload, transcript_payload, finance_total_paye, frais_reference, archived_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $rollover = $pdo->prepare('INSERT INTO finance_rollover (id_etudiant, annee_scolaire, total_paye, remaining_balance, created_by) VALUES (?, ?, ?, ?, ?)');
                    $gradeArchive = $pdo->prepare('INSERT INTO student_grades_archive (id_archive, id_etudiant, annee_scolaire, id_mat, semestre, note, note_rattrapage, coeff, credit, subject_payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $transcriptArchive = $pdo->prepare('INSERT INTO student_transcripts_archive (id_archive, id_etudiant, annee_scolaire, transcript_payload) VALUES (?, ?, ?, ?)');
                    $feeQuery = $pdo->prepare('SELECT id_finance_paiement, mode_paiement, montant_paye, created_at, updated_at, id_finance_user FROM finance WHERE id_etudiant = ? ORDER BY created_at, id_finance_paiement');
                    $feeArchive = $pdo->prepare('INSERT INTO student_fees_archive (id_archive, id_etudiant, annee_scolaire, source_payment_id, mode_paiement, montant_paye, payment_created_at, payment_updated_at, id_finance_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $preserveIncompletePayment = $pdo->prepare('UPDATE finance_solde SET total_paye = ? WHERE id_etudiant = ?');
                    $advance = $pdo->prepare('UPDATE etudiant SET niveau = ?, statut = ? WHERE id_etudiant = ?');
                    foreach ($selected as $id) {
                        $studentQuery->execute([$id]);
                        $student = $studentQuery->fetch();
                        if (!$student) {
                            continue;
                        }
                        $programId = isset($student['id_program']) ? (int) $student['id_program'] : null;
                        $published = result_row_for_student($id, $year);
                        $juryDecision = (string) ($published['decision'] ?? '');
                        if (!in_array($juryDecision, ['admis', 'admis_par_rachat', 'refusee'], true)) {
                            throw new RuntimeException(
                                'Décision jury manquante pour '
                                . trim((string) $student['prenom'] . ' ' . (string) $student['nom'])
                                . '. Envoyez d’abord les résultats.'
                            );
                        }
                        $noteQuery->execute([$id]);
                        $notes = $noteQuery->fetchAll();
                        foreach ($notes as &$note) {
                            $note['note'] = calculated_note($note);
                        }
                        unset($note);
                        $average = $published['moyenne'] !== null
                            ? (float) $published['moyenne']
                            : result_student_average($id, $programId);
                        $plan = result_progression_plan((string) $student['niveau'], $average, $programId, $juryDecision);
                        $decision = (string) $plan['decision'];
                        $newLevel = (string) $plan['next_level'];
                        $feeAmount = institution_fee_amount($programId);
                        $archive->execute([
                            $id,
                            $year,
                            $student['niveau'],
                            $student['id_spec'],
                            $student['nom_spec'],
                            $student['num_inscription'],
                            $student['cin_passeport'],
                            $decision,
                            $average,
                            json_encode($student, JSON_THROW_ON_ERROR),
                            json_encode($notes, JSON_THROW_ON_ERROR),
                            $student['total_paye'],
                            $feeAmount,
                            (int) ($_SESSION['admin_id'] ?? 0) ?: null,
                        ]);
                        $archiveId = (int) $pdo->lastInsertId();
                        foreach ($notes as $note) {
                            $gradeArchive->execute([
                                $archiveId,
                                $id,
                                $year,
                                $note['id_mat'] ?? null,
                                $note['semestre'],
                                $note['note'],
                                $note['note_rattrapage'] ?? null,
                                $note['coeff'],
                                $note['credit'],
                                json_encode($note, JSON_THROW_ON_ERROR),
                            ]);
                        }
                        $transcriptArchive->execute([$archiveId, $id, $year, json_encode($notes, JSON_THROW_ON_ERROR)]);
                        $feeQuery->execute([$id]);
                        foreach ($feeQuery->fetchAll() as $fee) {
                            $feeArchive->execute([
                                $archiveId,
                                $id,
                                $year,
                                $fee['id_finance_paiement'],
                                $fee['mode_paiement'],
                                $fee['montant_paye'],
                                $fee['created_at'],
                                $fee['updated_at'],
                                $fee['id_finance_user'],
                            ]);
                        }
                        $remainingBalance = max(0.0, $feeAmount - (float) $student['total_paye']);
                        if ($remainingBalance > 0.00001) {
                            $rollover->execute([
                                $id,
                                $year,
                                $student['total_paye'],
                                $remainingBalance,
                                (int) ($_SESSION['admin_id'] ?? 0) ?: null,
                            ]);
                        }
                        if ($remainingBalance <= 0.00001) {
                            $preserveIncompletePayment->execute([0, $id]);
                        } else {
                            $preserveIncompletePayment->execute([(float) $student['total_paye'], $id]);
                        }
                        $advance->execute([$newLevel, $plan['statut'], $id]);
                    }
                    $pdo->commit();
                    $message = 'Promotion, archivage et rollover financier effectués (décisions jury respectées).';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Opération impossible : ' . $exception->getMessage();
                }
            }
        }
    }
}
if (!$rows) {
    $rows = promotion_rows($pdo, $search, $groupId, $year);
}
$groupsQuery = $pdo->query("SELECT id_groupe, semestre, type_groupe, numero FROM groupe WHERE type_groupe = 'Cours' ORDER BY semestre, numero");
$promotionGroups = $groupsQuery->fetchAll();
$blockers = promotion_prerequisites($year);
$canPromote = $blockers === [];

page_head('Promotion et archivage', 'promotion');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <p class="eyebrow">Administration</p>
    <h1 class="display-6 fw-bold mb-1">Promotion / archivage</h1>
    <p class="text-secondary mb-0">
      Politique n° <?= (int) progression_policy()['id_policy'] ?> · seuil
      <?= e(number_format((float) progression_policy()['pass_threshold'], 2, ',', '')) ?>/20.
      Les décisions viennent du jury publié (admis / admis par rachat / refusée).
    </p>
  </div>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if (!$canPromote): ?>
  <div class="alert alert-warning">
    <strong>Promotion bloquée.</strong>
    <ul class="mb-2 mt-2">
      <?php foreach ($blockers as $blocker): ?>
        <li><?= e($blocker) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-outline-light" href="envoyer_resultats.php?embed=1">Envoyer les résultats</a>
      <a class="btn btn-sm btn-outline-light" href="resultats_rachat.php?embed=1">Résultats sous le seuil</a>
      <a class="btn btn-sm btn-outline-light" href="gestion_notes.php?embed=1">Notes</a>
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-success">
    Résultats publiés pour <?= e($year) ?>. Les décisions jury seront appliquées à la promotion.
  </div>
<?php endif; ?>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="preview">
      <div class="col-md-3">
        <label class="form-label">Année archivée</label>
        <input class="form-control" name="year" value="<?= e($year) ?>" pattern="\d{4}-\d{4}" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Groupe à traiter</label>
        <select class="form-select" name="group_id">
          <option value="0">Tous les groupes Cours</option>
          <?php foreach ($promotionGroups as $group): ?>
            <option value="<?= (int) $group['id_groupe'] ?>" <?= $groupId === (int) $group['id_groupe'] ? 'selected' : '' ?>>
              <?= e($group['semestre'] . ' — ' . $group['type_groupe'] . ' ' . $group['numero']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Recherche étudiant</label>
        <input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Nom, CIN ou inscription">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Prévisualiser</button>
      </div>
    </form>
  </div>
</div>

<form method="post" onsubmit="return confirmerPromotion(this)">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="commit">
  <input type="hidden" name="year" value="<?= e($year) ?>">
  <input type="hidden" name="search" value="<?= e($search) ?>">
  <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
  <div class="card app-card">
    <div class="card-body p-0 table-responsive">
      <table class="table table-dark table-hover align-middle mb-0">
        <thead>
          <tr>
            <th></th>
            <th>Étudiant</th>
            <th>Niveau</th>
            <th>Moyenne</th>
            <th>Décision jury</th>
            <th>Payé avant rollover</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $decision = (string) $row['decision'];
              $decisionText = promotion_decision_label($decision);
              if (!empty($row['advances'])) {
                  $decisionText .= ' → ' . level_label((string) $row['next_level']);
                  if (($row['next_statut'] ?? '') === 'diplome') {
                      $decisionText .= ' (diplôme)';
                  }
              } elseif ($decision === 'refusee') {
                  $decisionText .= ' (redouble)';
              }
            ?>
            <tr>
              <td><input class="form-check-input" type="checkbox" name="student_ids[]" value="<?= (int) $row['id_etudiant'] ?>" <?= $canPromote ? 'checked' : 'disabled' ?>></td>
              <td><?= e($row['nom'] . ' ' . $row['prenom']) ?><br><small><?= e($row['cin_passeport']) ?> · <?= e($row['num_inscription']) ?></small></td>
              <td><?= e(level_label($row['niveau'])) ?></td>
              <td><?= $row['moyenne_generale'] === null ? '—' : e(number_format((float) $row['moyenne_generale'], 2, ',', ' ')) ?></td>
              <td><?= e($decisionText) ?></td>
              <td><?= e(institution_money((float) $row['total_paye'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-secondary py-4">Aucun étudiant trouvé.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body border-top">
      <div class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Confirmation</label>
          <input class="form-control" name="confirmation" placeholder="PROMOUVOIR" <?= $canPromote ? 'required' : 'disabled' ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label">Mot de passe admin</label>
          <input class="form-control" type="password" name="admin_password" <?= $canPromote ? 'required' : 'disabled' ?>>
        </div>
        <div class="col-md-4">
          <button class="btn btn-danger w-100" <?= (!$rows || !$canPromote) ? 'disabled' : '' ?>>Valider l'opération</button>
        </div>
      </div>
      <small class="text-secondary d-block mt-2">Les notes, l’identité et le solde de l’année sont conservés dans l’archive. Admis et admis par rachat avancent ; refusée = redoublement.</small>
    </div>
  </div>
</form>
<script>
function confirmerPromotion(form) {
  if (!window.confirm('Êtes-vous sûr de vouloir promouvoir / archiver selon les décisions jury ?')) return false;
  const password = window.prompt('Saisissez le mot de passe administrateur pour confirmer :');
  if (password === null || password === '') return false;
  form.elements.admin_password.value = password;
  return true;
}
</script>
<?php page_foot();

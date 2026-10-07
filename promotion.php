<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$pdo = db();
$defaultYear = ((int) date('Y') - 1) . '-' . date('Y');
$year = trim((string) ($_GET['year'] ?? ($_POST['year'] ?? $defaultYear)));
$search = trim((string) ($_GET['search'] ?? ($_POST['search'] ?? '')));
$groupId = max(0, (int) ($_GET['group_id'] ?? ($_POST['group_id'] ?? 0)));
$message = '';
$error = '';
$rows = [];

function promotion_rows(PDO $pdo, string $search, int $groupId = 0): array {
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
    $query = $pdo->prepare('SELECT e.*, s.nom_spec, COALESCE(fs.total_paye, 0) AS total_paye
        FROM etudiant e JOIN specialite s ON s.id_spec = e.id_spec
        LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant
        ' . ($where ? $where . ' AND ' : ' WHERE ') . 'e.statut = "actif" ORDER BY e.niveau, e.nom, e.prenom');
    $query->execute($params);
    $result = [];
    $marks = $pdo->prepare('SELECT n.id_mat, n.semestre, n.note, n.note_cours, n.note_td, n.note_tp, n.note_rattrapage, m.coeff, m.volume_tp FROM note n JOIN matiere m ON m.id_mat = n.id_mat WHERE n.id_etudiant = ?');
    foreach ($query->fetchAll() as $student) {
        $marks->execute([(int) $student['id_etudiant']]);
        $weighted = $coeff = 0.0;
        foreach ($marks->fetchAll() as $mark) {
            $note = calculated_note($mark);
            if ($note === null) continue;
            $weighted += (float) $note * (float) $mark['coeff'];
            $coeff += (float) $mark['coeff'];
        }
        $average = $coeff > 0 ? round($weighted / $coeff, 2) : null;
        $student['moyenne_generale'] = $average;
        $student['decision'] = $average !== null && $average >= 10 ? 'admis' : 'redouble';
        $result[] = $student;
    }
    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'preview') {
        $rows = promotion_rows($pdo, $search, $groupId);
    } elseif (($_POST['action'] ?? '') === 'commit') {
        $password = (string) ($_POST['admin_password'] ?? '');
        $confirm = (string) ($_POST['confirmation'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}$/', $year)) {
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
                    $studentSql = 'SELECT e.*, s.nom_spec, COALESCE(fs.total_paye, 0) AS total_paye FROM etudiant e JOIN specialite s ON s.id_spec = e.id_spec LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant WHERE e.id_etudiant = ? AND e.statut = "actif"';
                    if ($groupId > 0) $studentSql .= ' AND EXISTS (SELECT 1 FROM groupe_etudiant ge WHERE ge.id_etudiant = e.id_etudiant AND ge.id_groupe = ' . $groupId . ')';
                    $studentSql .= ' FOR UPDATE';
                    $studentQuery = $pdo->prepare($studentSql);
                    $noteQuery = $pdo->prepare('SELECT n.id_mat, n.semestre, n.note, n.note_cours, n.note_td, n.note_tp, n.note_rattrapage, m.code_ue, m.description_ue, m.code_ecue, m.description_ecue, m.coeff, m.credit, m.regime, m.volume_tp FROM note n JOIN matiere m ON m.id_mat = n.id_mat WHERE n.id_etudiant = ? ORDER BY n.semestre, m.code_ue, m.code_ecue');
                    $archive = $pdo->prepare('INSERT INTO etudiant_archive (id_etudiant, annee_scolaire, niveau, id_spec, nom_spec, num_inscription, cin_passeport, decision, moyenne_generale, student_payload, transcript_payload, finance_total_paye, archived_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
                        if (!$student) continue;
                        $noteQuery->execute([$id]);
                        $notes = $noteQuery->fetchAll();
                        $weighted = $coeff = 0.0;
                        foreach ($notes as $note) {
                            $value = calculated_note($note);
                            $note['note'] = $value;
                            if ($value !== null) { $weighted += (float) $value * (float) $note['coeff']; $coeff += (float) $note['coeff']; }
                        }
                        $average = $coeff > 0 ? round($weighted / $coeff, 2) : null;
                        $decision = $average !== null && $average >= 10 ? 'admis' : 'redouble';
                        $newLevel = $decision === 'admis' ? (string) min(3, (int) $student['niveau'] + 1) : (string) $student['niveau'];
                        $archive->execute([$id, $year, $student['niveau'], $student['id_spec'], $student['nom_spec'], $student['num_inscription'], $student['cin_passeport'], $decision, $average, json_encode($student, JSON_THROW_ON_ERROR), json_encode($notes, JSON_THROW_ON_ERROR), $student['total_paye'], (int) ($_SESSION['admin_id'] ?? 0) ?: null]);
                        $archiveId = (int) $pdo->lastInsertId();
                        foreach ($notes as $note) {
                            $gradeArchive->execute([$archiveId, $id, $year, $note['id_mat'] ?? null, $note['semestre'], $note['note'], $note['note_rattrapage'], $note['coeff'], $note['credit'], json_encode($note, JSON_THROW_ON_ERROR)]);
                        }
                        $transcriptArchive->execute([$archiveId, $id, $year, json_encode($notes, JSON_THROW_ON_ERROR)]);
                        $feeQuery->execute([$id]);
                        foreach ($feeQuery->fetchAll() as $fee) {
                            $feeArchive->execute([$archiveId, $id, $year, $fee['id_finance_paiement'], $fee['mode_paiement'], $fee['montant_paye'], $fee['created_at'], $fee['updated_at'], $fee['id_finance_user']]);
                        }
                        $remainingBalance = max(0.0, 9800.0 - (float) $student['total_paye']);
                        if ($remainingBalance > 0.00001) {
                            $rollover->execute([$id, $year, $student['total_paye'], $remainingBalance, (int) ($_SESSION['admin_id'] ?? 0) ?: null]);
                        }
                        if ($remainingBalance <= 0.00001) {
                            $preserveIncompletePayment->execute([0, $id]);
                        } else {
                            $preserveIncompletePayment->execute([(float) $student['total_paye'], $id]);
                        }
                        $advance->execute([$newLevel, $newLevel === '3' && $decision === 'admis' ? 'diplome' : 'actif', $id]);
                    }
                    $pdo->commit();
                    $message = 'Promotion, archivage et rollover financier effectués.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Opération impossible : ' . $exception->getMessage();
                }
            }
        }
    }
}
if (!$rows) $rows = promotion_rows($pdo, $search, $groupId);
$groupsQuery = $pdo->query("SELECT id_groupe, semestre, type_groupe, numero FROM groupe WHERE type_groupe = 'Cours' ORDER BY semestre, numero");
$promotionGroups = $groupsQuery->fetchAll();

page_head('Promotion et archivage', 'promotion');
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="eyebrow">Administration</p><h1 class="display-6 fw-bold mb-1">Promotion / archivage</h1><p class="text-secondary mb-0">Prévisualisez puis validez la fin d’année.</p></div></div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4"><div class="card-body p-4"><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="preview"><div class="col-md-3"><label class="form-label">Année archivée</label><input class="form-control" name="year" value="<?= e($year) ?>" pattern="\d{4}-\d{4}" required></div><div class="col-md-4"><label class="form-label">Groupe à traiter</label><select class="form-select" name="group_id"><option value="0">Tous les groupes Cours</option><?php foreach ($promotionGroups as $group): ?><option value="<?= (int) $group['id_groupe'] ?>" <?= $groupId === (int) $group['id_groupe'] ? 'selected' : '' ?>><?= e($group['semestre'] . ' — ' . $group['type_groupe'] . ' ' . $group['numero']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Recherche étudiant</label><input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Nom, CIN ou inscription"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Prévisualiser</button></div></form></div></div>
<form method="post" onsubmit="return confirmerPromotion(this)"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="commit"><input type="hidden" name="year" value="<?= e($year) ?>"><input type="hidden" name="search" value="<?= e($search) ?>"><input type="hidden" name="group_id" value="<?= (int) $groupId ?>"><div class="card app-card"><div class="card-body p-0 table-responsive"><table class="table table-dark table-hover align-middle mb-0"><thead><tr><th></th><th>Étudiant</th><th>Niveau</th><th>Moyenne</th><th>Décision</th><th>Payé avant rollover</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><input class="form-check-input" type="checkbox" name="student_ids[]" value="<?= (int) $row['id_etudiant'] ?>" checked></td><td><?= e($row['nom'] . ' ' . $row['prenom']) ?><br><small><?= e($row['cin_passeport']) ?> · <?= e($row['num_inscription']) ?></small></td><td><?= e(level_label($row['niveau'])) ?></td><td><?= $row['moyenne_generale'] === null ? '—' : e(number_format((float) $row['moyenne_generale'], 2, ',', ' ')) ?></td><td><?= $row['decision'] === 'admis' ? 'Admis → niveau suivant' : 'Redouble' ?></td><td><?= e(number_format((float) $row['total_paye'], 2, ',', ' ')) ?> DT</td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun étudiant trouvé.</td></tr><?php endif; ?></tbody></table></div><div class="card-body border-top"><div class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label">Confirmation</label><input class="form-control" name="confirmation" placeholder="PROMOUVOIR" required></div><div class="col-md-4"><label class="form-label">Mot de passe admin</label><input class="form-control" type="password" name="admin_password" required></div><div class="col-md-4"><button class="btn btn-danger w-100" <?= !$rows ? 'disabled' : '' ?>>Valider l'opération</button></div></div><small class="text-secondary d-block mt-2">Les notes, l’identité et le solde de l’année sont conservés dans l’archive. Les paiements incomplets restent visibles dans le compte finance et leur solde est conservé pour la suite.</small></div></div></form>
<script>
function confirmerPromotion(form) {
  if (!window.confirm('Êtes-vous sûr de vouloir auto-diplômer et archiver cette année ?')) return false;
  const password = window.prompt('Saisissez le mot de passe administrateur pour confirmer :');
  if (password === null || password === '') return false;
  form.elements.admin_password.value = password;
  return true;
}
</script>
<?php page_foot(); ?>



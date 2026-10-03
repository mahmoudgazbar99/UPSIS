<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$pdo = db();
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $deleteId = filter_input(INPUT_POST, 'delete_id', FILTER_VALIDATE_INT) ?: 0;
    if ($deleteId) {
        try {
            $delete = $pdo->prepare('DELETE FROM enseignant_affectation WHERE id_affectation = ?');
            $delete->execute([$deleteId]);
            $message = $delete->rowCount() ? 'Affectation supprimée.' : 'Affectation introuvable.';
        } catch (PDOException $exception) {
            $error = 'Suppression impossible.';
        }
    }
    if (!$deleteId) {
        $teacherId = filter_input(INPUT_POST, 'id_enseignant', FILTER_VALIDATE_INT) ?: 0;
        $subjectId = filter_input(INPUT_POST, 'id_mat', FILTER_VALIDATE_INT) ?: 0;
        $groupId = filter_input(INPUT_POST, 'id_groupe', FILTER_VALIDATE_INT) ?: 0;
        if (!$teacherId || !$subjectId || !$groupId) {
            $error = 'Sélectionnez un enseignant, une matière et un groupe.';
        } else {
            try {
                $validation = $pdo->prepare(
                    'SELECT e.type_enseignant, m.semestre AS matiere_semestre,
                            m.volume_cours, m.volume_td, m.volume_tp,
                            g.semestre AS groupe_semestre, g.type_groupe
                     FROM enseignant e
                     JOIN matiere m ON m.id_mat = ?
                     JOIN groupe g ON g.id_groupe = ?
                     WHERE e.id_enseignant = ?'
                );
                $validation->execute([$subjectId, $groupId, $teacherId]);
                $selection = $validation->fetch();
                if (!$selection) {
                    throw new RuntimeException('L’enseignant, la matière ou le groupe sélectionné est introuvable.');
                }
                if ($selection['matiere_semestre'] !== $selection['groupe_semestre']) {
                    throw new RuntimeException('La matière et le groupe doivent appartenir au même semestre.');
                }
                $validModality = match ($selection['type_enseignant']) {
                    'Cours' => $selection['type_groupe'] === 'Cours' && (float) $selection['volume_cours'] > 0,
                    'TD' => $selection['type_groupe'] === 'TD' && (float) $selection['volume_td'] > 0,
                    'TP' => $selection['type_groupe'] === 'Cours' && (float) $selection['volume_tp'] > 0,
                    default => false,
                };
                if (!$validModality) {
                    throw new RuntimeException('Le type de l’enseignant, la modalité de la matière et le groupe sélectionné ne correspondent pas.');
                }
                $pdo->prepare('INSERT INTO enseignant_affectation (id_enseignant, id_mat, id_groupe) VALUES (?, ?, ?)')->execute([$teacherId, $subjectId, $groupId]);
                $message = 'Affectation enregistrée.';
            } catch (Throwable $exception) {
                $error = $exception instanceof PDOException
                    ? 'Cette affectation existe déjà ou est invalide.'
                    : $exception->getMessage();
            }
        }
    }
}
$teachers = $pdo->query('SELECT id_enseignant, cin, nom, prenom, type_enseignant FROM enseignant ORDER BY nom, prenom')->fetchAll();
$subjects = $pdo->query('SELECT id_mat, semestre, code_ecue, description_ecue FROM matiere ORDER BY semestre, code_ecue')->fetchAll();
$groups = $pdo->query(
    'SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero,
            parent.numero AS parent_numero,
            MOD(g.numero - 1, 2) + 1 AS td_numero
     FROM groupe g
     LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
     ORDER BY g.semestre, g.type_groupe, g.numero'
)->fetchAll();
$assignments = $pdo->query(
    'SELECT a.id_affectation, e.nom, e.prenom, e.type_enseignant,
            m.semestre, m.code_ecue, m.description_ecue,
            g.type_groupe, g.numero, parent.numero AS parent_numero,
            MOD(g.numero - 1, 2) + 1 AS td_numero
     FROM enseignant_affectation a
     JOIN enseignant e ON e.id_enseignant = a.id_enseignant
     JOIN matiere m ON m.id_mat = a.id_mat
     JOIN groupe g ON g.id_groupe = a.id_groupe
     LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
     ORDER BY e.nom, m.semestre, m.code_ecue, g.type_groupe, g.numero'
)->fetchAll();
page_head('Affecter Enseignant', 'assignments');
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="eyebrow">Scolarité</p><h1 class="display-6 fw-bold mb-1">Affecter Enseignant</h1><p class="text-secondary mb-0">Affectez manuellement chaque enseignant à une matière et un groupe.</p></div></div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4"><div class="card-body p-4"><form method="post" class="row g-3 align-items-end"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="col-md-4"><label class="form-label">Enseignant *</label><select class="form-select" name="id_enseignant" required><option value="">Choisir</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id_enseignant'] ?>"><?= e($teacher['nom'] . ' ' . $teacher['prenom'] . ' — ' . $teacher['cin'] . ' · ' . $teacher['type_enseignant']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Matière / ECUE *</label><select class="form-select" name="id_mat" required><option value="">Choisir</option><?php foreach ($subjects as $subject): ?><option value="<?= (int) $subject['id_mat'] ?>"><?= e($subject['semestre'] . ' · ' . $subject['code_ecue'] . ' — ' . $subject['description_ecue']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Groupe *</label><select class="form-select" name="id_groupe" required><option value="">Choisir</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id_groupe'] ?>"><?= e($group['semestre'] . ' · ' . ($group['type_groupe'] === 'TD' ? 'Cours ' . $group['parent_numero'] . ' · TD ' . $group['td_numero'] : 'Cours ' . $group['numero'])) ?></option><?php endforeach; ?></select></div><div class="col-md-1"><button class="btn btn-primary w-100">+</button></div></form></div></div>
<div class="card app-card"><div class="card-body p-0 table-responsive"><table class="table table-dark table-hover mb-0"><thead><tr><th>Enseignant</th><th>Matière</th><th>Groupe</th><th class="text-end">Action</th></tr></thead><tbody><?php foreach ($assignments as $assignment): ?><tr><td><?= e($assignment['nom'] . ' ' . $assignment['prenom'] . ' · ' . $assignment['type_enseignant']) ?></td><td><?= e($assignment['semestre'] . ' · ' . $assignment['code_ecue'] . ' — ' . $assignment['description_ecue']) ?></td><td><?= e($assignment['type_groupe'] === 'TD' ? 'Cours ' . $assignment['parent_numero'] . ' · TD ' . $assignment['td_numero'] : 'Cours ' . $assignment['numero']) ?></td><td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('Effacer cette affectation ?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="delete_id" value="<?= (int) $assignment['id_affectation'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Effacer l’affectation</button></form></td></tr><?php endforeach; ?><?php if (!$assignments): ?><tr><td colspan="4" class="text-center text-secondary py-4">Aucune affectation.</td></tr><?php endif; ?></tbody></table></div></div>
<?php page_foot();

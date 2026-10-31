<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_teacher_or_admin();

$pdo = db();
$message = '';
$error = '';
$editNoteId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
if (is_teacher() && $editNoteId) {
    http_response_code(403);
    exit('Les enseignants ne peuvent pas modifier les notes.');
}
$editNote = null;
$editSearch = trim((string) ($_GET['edit_search'] ?? ''));
$teacherSearch = trim((string) ($_GET['teacher_search'] ?? ''));
$teacherGroupId = filter_input(INPUT_GET, 'teacher_group', FILTER_VALIDATE_INT) ?: 0;
$editStudent = null;
$editStudentNotes = [];
$teacherStudent = null;
$teacherStudentNotes = [];
$gridGroupId = filter_input(INPUT_GET, 'grid_group', FILTER_VALIDATE_INT) ?: 0;
$gridSubjectId = filter_input(INPUT_GET, 'grid_subject', FILTER_VALIDATE_INT) ?: 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_note') {
            if (is_teacher() && !empty($_POST['id_note'])) {
                throw new RuntimeException('Un enseignant ne peut pas modifier une note existante.');
            }
            $noteId = filter_input(INPUT_POST, 'id_note', FILTER_VALIDATE_INT) ?: 0;
            $studentId = filter_input(INPUT_POST, 'id_etudiant', FILTER_VALIDATE_INT);
            $subjectId = filter_input(INPUT_POST, 'id_mat', FILTER_VALIDATE_INT);
            $groupId = filter_input(INPUT_POST, 'id_groupe', FILTER_VALIDATE_INT) ?: 0;
            $postedSemester = (string) ($_POST['semestre'] ?? '');
            $subjectSemester = '';
            if (is_int($subjectId) && $subjectId > 0) {
                $subjectRow = $pdo->prepare('SELECT semestre FROM matiere WHERE id_mat = ?');
                $subjectRow->execute([$subjectId]);
                $subjectSemester = (string) $subjectRow->fetchColumn();
            }
            $semester = $subjectSemester;
            if ($subjectSemester !== '' && $postedSemester !== '' && $postedSemester !== $semester) {
                throw new RuntimeException('Le semestre doit être celui de la matière.');
            }
            $mainMark = str_replace(',', '.', trim((string) ($_POST['note'] ?? '')));
            $retakeMark = str_replace(',', '.', trim((string) ($_POST['note_rattrapage'] ?? '')));
            $componentMarks = [];
            foreach (['note_cours', 'note_td', 'note_tp'] as $component) {
                $value = str_replace(',', '.', trim((string) ($_POST[$component] ?? '')));
                if ($value !== '' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 20)) {
                    throw new RuntimeException('Chaque note Cours, TD ou TP doit être comprise entre 0 et 20.');
                }
                $componentMarks[$component] = $value === '' ? null : $value;
            }
            if (!$studentId || !$subjectId || !academic_period_valid($semester) || ($mainMark === '' && !array_filter($componentMarks, static fn($value): bool => $value !== null)) || ($mainMark !== '' && (!is_numeric($mainMark) || (float) $mainMark < 0 || (float) $mainMark > 20)) || ($retakeMark !== '' && (!is_numeric($retakeMark) || (float) $retakeMark < 0 || (float) $retakeMark > 20))) throw new RuntimeException('Sélectionnez l’étudiant, la matière et saisissez au moins une note Cours, TD, TP ou une note finale manuelle.');
            if ($groupId) {
                $groupTypeQuery = $pdo->prepare('SELECT type_groupe, semestre FROM groupe WHERE id_groupe = ?');
                $groupTypeQuery->execute([$groupId]);
                $groupRow = $groupTypeQuery->fetch();
                if (!$groupRow || $groupRow['type_groupe'] !== 'Cours') throw new RuntimeException('La saisie des notes est disponible uniquement pour les groupes Cours.');
                if ((string) $groupRow['semestre'] !== $semester) throw new RuntimeException('Ce groupe n’appartient pas au semestre de la matière.');
            }
            if ($groupId) {
                $membership = $pdo->prepare('SELECT 1 FROM groupe_etudiant WHERE id_groupe = ? AND id_etudiant = ?');
                $membership->execute([$groupId, $studentId]);
                if (!$membership->fetchColumn()) throw new RuntimeException('L’étudiant sélectionné ne fait pas partie de ce groupe.');
            }
            if (is_teacher()) {
                if (!$groupId) throw new RuntimeException('Un groupe affecté est obligatoire.');
                $assignment = $pdo->prepare('SELECT 1 FROM enseignant_affectation WHERE id_enseignant = ? AND id_mat = ? AND id_groupe = ?');
                $assignment->execute([current_teacher_id(), $subjectId, $groupId]);
                if (!$assignment->fetchColumn()) throw new RuntimeException('Vous ne pouvez saisir des notes que pour vos matières et groupes affectés.');
            }
            $params = ['id_etudiant' => $studentId, 'id_mat' => $subjectId, 'semestre' => $semester, 'note' => $mainMark === '' ? null : $mainMark, 'note_cours' => $componentMarks['note_cours'], 'note_td' => $componentMarks['note_td'], 'note_tp' => $componentMarks['note_tp'], 'note_rattrapage' => $retakeMark === '' ? null : $retakeMark];
            if ($noteId) {
                $existingNote = $pdo->prepare('SELECT id_etudiant, id_mat, id_groupe, semestre FROM note WHERE id_note = ?');
                $existingNote->execute([$noteId]);
                $existingRow = $existingNote->fetch();
                if (!$existingRow) {
                    throw new RuntimeException('Cette note est introuvable.');
                }
                if ((int) $existingRow['id_etudiant'] !== $studentId
                    || (int) $existingRow['id_mat'] !== $subjectId
                    || (string) $existingRow['semestre'] !== $semester
                ) {
                    throw new RuntimeException('Une note existante ne peut pas être déplacée vers un autre étudiant, une autre matière ou un autre semestre.');
                }
                $params['id_note'] = $noteId;
                $params['id_groupe'] = $groupId ?: ($existingRow['id_groupe'] ?: null);
                unset($params['id_etudiant'], $params['id_mat'], $params['semestre']);
                $pdo->prepare('UPDATE note SET id_groupe=:id_groupe, note=:note, note_cours=:note_cours, note_td=:note_td, note_tp=:note_tp, note_rattrapage=:note_rattrapage WHERE id_note=:id_note')->execute($params);
                $message = 'Note modifiée avec succès.';
                $noteAction = 'modifier';
            } else {
                $existingNoteQuery = $pdo->prepare('SELECT id_note FROM note WHERE id_etudiant = ? AND id_mat = ? AND semestre = ? LIMIT 1');
                $existingNoteQuery->execute([$studentId, $subjectId, $semester]);
                if ($existingNoteQuery->fetchColumn()) {
                    throw new RuntimeException('Une note existe déjà pour cet étudiant dans cette matière et ce semestre. Utilisez la modification depuis le compte administrateur.');
                }
                $params['id_groupe'] = $groupId ?: null;
                $pdo->prepare('INSERT INTO note (id_etudiant, id_mat, id_groupe, semestre, note, note_cours, note_td, note_tp, note_rattrapage) VALUES (:id_etudiant, :id_mat, :id_groupe, :semestre, :note, :note_cours, :note_td, :note_tp, :note_rattrapage)')->execute($params);
                $message = 'Note ajoutée avec succès.';
                $noteAction = 'ajouter';
            }
            $noteLabel = $pdo->prepare(
                'SELECT e.nom, e.prenom, e.cin_passeport, m.code_ecue
                 FROM etudiant e, matiere m
                 WHERE e.id_etudiant = ? AND m.id_mat = ?'
            );
            $noteLabel->execute([$studentId, $subjectId]);
            $noteMeta = $noteLabel->fetch() ?: [];
            audit_record(
                'note',
                $noteAction,
                trim((string) (($noteMeta['nom'] ?? '') . ' ' . ($noteMeta['prenom'] ?? '')))
                . ' (' . (string) ($noteMeta['cin_passeport'] ?? '') . ') · '
                . (string) ($noteMeta['code_ecue'] ?? 'matière') . ' ' . $semester
                . ' · CC ' . ($componentMarks['note_cours'] ?? '—')
                . ' / TD ' . ($componentMarks['note_td'] ?? '—')
                . ' / TP ' . ($componentMarks['note_tp'] ?? '—')
                . ' / finale ' . ($mainMark === '' ? '—' : $mainMark)
            );
            $editNoteId = 0;
        } elseif ($action === 'save_grid') {
            $groupId = filter_input(INPUT_POST, 'grid_group', FILTER_VALIDATE_INT) ?: 0;
            $subjectId = filter_input(INPUT_POST, 'grid_subject', FILTER_VALIDATE_INT) ?: 0;
            $gridGroupId = $groupId;
            $gridSubjectId = $subjectId;
            $postedRows = (array) ($_POST['grid'] ?? []);
            if (!$groupId || !$subjectId) {
                throw new RuntimeException('Choisissez un groupe et une matière.');
            }
            $groupQuery = $pdo->prepare('SELECT semestre, type_groupe, numero FROM groupe WHERE id_groupe = ?');
            $groupQuery->execute([$groupId]);
            $gridGroup = $groupQuery->fetch();
            if (!$gridGroup || $gridGroup['type_groupe'] !== 'Cours') {
                throw new RuntimeException('La grille est disponible uniquement pour un groupe Cours.');
            }
            $subjectQuery = $pdo->prepare('SELECT code_ecue, description_ecue, semestre FROM matiere WHERE id_mat = ?');
            $subjectQuery->execute([$subjectId]);
            $gridSubject = $subjectQuery->fetch();
            if (!$gridSubject) {
                throw new RuntimeException('Matière introuvable.');
            }
            if ($gridSubject['semestre'] !== $gridGroup['semestre']) {
                throw new RuntimeException('Cette matière n’appartient pas au semestre du groupe.');
            }
            if (is_teacher()) {
                $assignment = $pdo->prepare('SELECT 1 FROM enseignant_affectation WHERE id_enseignant = ? AND id_mat = ? AND id_groupe = ?');
                $assignment->execute([current_teacher_id(), $subjectId, $groupId]);
                if (!$assignment->fetchColumn()) {
                    throw new RuntimeException('Vous ne pouvez saisir des notes que pour vos matières et groupes affectés.');
                }
            }
            $memberQuery = $pdo->prepare('SELECT e.id_etudiant, e.nom, e.prenom, e.cin_passeport FROM groupe_etudiant ge JOIN etudiant e ON e.id_etudiant = ge.id_etudiant WHERE ge.id_groupe = ?');
            $memberQuery->execute([$groupId]);
            $members = [];
            foreach ($memberQuery->fetchAll() as $member) {
                $members[(int) $member['id_etudiant']] = $member;
            }
            $existingQuery = $pdo->prepare('SELECT * FROM note WHERE id_etudiant = ? AND id_mat = ? AND semestre = ? LIMIT 1');
            $insert = $pdo->prepare('INSERT INTO note (id_etudiant, id_mat, id_groupe, semestre, note, note_cours, note_td, note_tp, note_rattrapage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $update = $pdo->prepare('UPDATE note SET id_groupe = ?, note = ?, note_cours = ?, note_td = ?, note_tp = ?, note_rattrapage = ? WHERE id_note = ?');
            $normalize = static function (string $raw, string $studentName): ?string {
                $value = str_replace(',', '.', trim($raw));
                if ($value === '') {
                    return null;
                }
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > 20) {
                    throw new RuntimeException('Chaque note de ' . $studentName . ' doit être comprise entre 0 et 20.');
                }
                return number_format((float) $value, 2, '.', '');
            };
            $same = static function ($left, $right): bool {
                $left = $left === null || $left === '' ? null : number_format((float) $left, 2, '.', '');
                $right = $right === null || $right === '' ? null : number_format((float) $right, 2, '.', '');
                return $left === $right;
            };
            $added = 0;
            $changed = 0;
            $auditLines = [];
            $pdo->beginTransaction();
            foreach ($members as $studentId => $member) {
                $row = (array) ($postedRows[$studentId] ?? $postedRows[(string) $studentId] ?? []);
                $studentName = trim($member['nom'] . ' ' . $member['prenom']);
                $marks = [
                    'note_cours' => $normalize((string) ($row['note_cours'] ?? ''), $studentName),
                    'note_td' => $normalize((string) ($row['note_td'] ?? ''), $studentName),
                    'note_tp' => $normalize((string) ($row['note_tp'] ?? ''), $studentName),
                    'note' => $normalize((string) ($row['note'] ?? ''), $studentName),
                    'note_rattrapage' => $normalize((string) ($row['note_rattrapage'] ?? ''), $studentName),
                ];
                $existingQuery->execute([$studentId, $subjectId, $gridSubject['semestre']]);
                $existing = $existingQuery->fetch();
                $hasMark = array_filter($marks, static fn ($value): bool => $value !== null) !== [];
                if ($existing && is_teacher()) {
                    continue;
                }
                if (!$existing && !$hasMark) {
                    continue;
                }
                if ($existing) {
                    $unchanged = true;
                    foreach ($marks as $field => $value) {
                        if (!$same($existing[$field] ?? null, $value)) {
                            $unchanged = false;
                            break;
                        }
                    }
                    if ($unchanged) {
                        continue;
                    }
                    $update->execute([$groupId, $marks['note'], $marks['note_cours'], $marks['note_td'], $marks['note_tp'], $marks['note_rattrapage'], $existing['id_note']]);
                    $changed++;
                    $noteAction = 'modifier';
                } else {
                    $insert->execute([$studentId, $subjectId, $groupId, $gridSubject['semestre'], $marks['note'], $marks['note_cours'], $marks['note_td'], $marks['note_tp'], $marks['note_rattrapage']]);
                    $added++;
                    $noteAction = 'ajouter';
                }
                $auditLines[] = [
                    $noteAction,
                    $studentName . ' (' . $member['cin_passeport'] . ') · ' . $gridSubject['code_ecue'] . ' ' . $gridSubject['semestre']
                    . ' · CC ' . ($marks['note_cours'] ?? '—')
                    . ' / Examen ' . ($marks['note_td'] ?? '—')
                    . ' / TP ' . ($marks['note_tp'] ?? '—')
                    . ' / finale ' . ($marks['note'] ?? '—'),
                ];
            }
            $pdo->commit();
            foreach ($auditLines as [$noteAction, $summary]) {
                audit_record('note', $noteAction, $summary);
            }
            if ($added === 0 && $changed === 0) {
                $message = 'Aucune note nouvelle à enregistrer.';
            } else {
                $message = 'Grille enregistrée : ' . $added . ' ajoutée' . ($added > 1 ? 's' : '') . ', ' . $changed . ' modifiée' . ($changed > 1 ? 's' : '') . '.';
            }
        }
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Enregistrement impossible. Cette matière ou cette note existe peut-être déjà pour ce semestre.';
    }
}

if ($editNoteId) {
    $query = $pdo->prepare('SELECT * FROM note WHERE id_note = ?');
    $query->execute([$editNoteId]);
    $editNote = $query->fetch();
    if (!$editNote) $error = 'Note introuvable.';
}

$groupsQuery = is_teacher()
    ? $pdo->prepare('SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero FROM groupe g JOIN enseignant_affectation a ON a.id_groupe = g.id_groupe WHERE a.id_enseignant = ? AND g.type_groupe = "Cours" GROUP BY g.id_groupe ORDER BY g.semestre, g.numero')
    : $pdo->query('SELECT id_groupe, semestre, type_groupe, numero FROM groupe WHERE type_groupe = "Cours" ORDER BY FIELD(semestre, "S1", "S2", "S3", "S4", "S5", "S6"), numero');
if (is_teacher()) $groupsQuery->execute([current_teacher_id()]);
$groups = $groupsQuery->fetchAll();
$groupMembers = [];
$memberQuery = $pdo->query('SELECT ge.id_groupe, e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.num_inscription FROM groupe_etudiant ge JOIN etudiant e ON e.id_etudiant = ge.id_etudiant ORDER BY e.nom, e.prenom');
foreach ($memberQuery as $member) $groupMembers[(int) $member['id_groupe']][] = $member;
$students = $pdo->query('SELECT id_etudiant, nom, prenom, cin_passeport, num_inscription FROM etudiant ORDER BY nom, prenom')->fetchAll();
$subjectsQuery = is_teacher()
    ? $pdo->prepare('SELECT DISTINCT m.id_mat, m.code_ue, m.description_ue, m.code_ecue, m.description_ecue, m.semestre, m.coeff, m.credit, m.regime FROM matiere m JOIN enseignant_affectation a ON a.id_mat = m.id_mat WHERE a.id_enseignant = ? ORDER BY m.semestre, m.code_ue, m.code_ecue')
    : $pdo->query('SELECT id_mat, code_ue, description_ue, code_ecue, description_ecue, semestre, coeff, credit, regime FROM matiere ORDER BY semestre, code_ue, code_ecue');
if (is_teacher()) $subjectsQuery->execute([current_teacher_id()]);
$subjects = $subjectsQuery->fetchAll();
$teacherAssignments = [];
$teacherSubjectGroups = [];
if (is_teacher()) {
    $assignmentQuery = $pdo->prepare('SELECT id_mat, id_groupe FROM enseignant_affectation WHERE id_enseignant = ?');
    $assignmentQuery->execute([current_teacher_id()]);
    foreach ($assignmentQuery->fetchAll() as $assignment) {
        $teacherAssignments[(int) $assignment['id_groupe']][] = (int) $assignment['id_mat'];
        $teacherSubjectGroups[(int) $assignment['id_mat']][] = (int) $assignment['id_groupe'];
    }
}
$recentMarksQuery = is_teacher()
    ? $pdo->prepare('SELECT DISTINCT n.*, e.nom, e.prenom, e.cin_passeport, m.code_ecue, m.description_ecue, COALESCE(ng.semestre, ag.semestre) AS groupe_semestre, COALESCE(ng.type_groupe, ag.type_groupe) AS type_groupe, COALESCE(ng.numero, ag.numero) AS numero FROM note n JOIN etudiant e ON e.id_etudiant = n.id_etudiant JOIN matiere m ON m.id_mat = n.id_mat JOIN groupe_etudiant ge ON ge.id_etudiant = n.id_etudiant JOIN enseignant_affectation a ON a.id_mat = n.id_mat AND a.id_groupe = ge.id_groupe JOIN groupe ag ON ag.id_groupe = ge.id_groupe LEFT JOIN groupe ng ON ng.id_groupe = n.id_groupe     WHERE a.id_enseignant = ? AND n.id_groupe = ge.id_groupe ORDER BY n.id_note DESC')
    : $pdo->query('SELECT n.*, e.nom, e.prenom, e.cin_passeport, m.code_ecue, m.description_ecue, g.semestre AS groupe_semestre, g.type_groupe, g.numero FROM note n JOIN etudiant e ON e.id_etudiant = n.id_etudiant JOIN matiere m ON m.id_mat = n.id_mat LEFT JOIN groupe g ON g.id_groupe = n.id_groupe ORDER BY n.id_note DESC');
if (is_teacher()) $recentMarksQuery->execute([current_teacher_id()]);
$recentMarks = $recentMarksQuery->fetchAll();
$gridGroup = null;
$gridSubject = null;
$gridRows = [];
if ($gridGroupId && $gridSubjectId) {
    foreach ($groups as $group) {
        if ((int) $group['id_groupe'] === $gridGroupId) {
            $gridGroup = $group;
            break;
        }
    }
    foreach ($subjects as $subject) {
        if ((int) $subject['id_mat'] === $gridSubjectId && $gridGroup && $subject['semestre'] === $gridGroup['semestre']) {
            $allowed = !is_teacher() || in_array($gridSubjectId, $teacherAssignments[$gridGroupId] ?? [], true);
            if ($allowed) {
                $gridSubject = $subject;
            }
            break;
        }
    }
    if ($gridGroup && $gridSubject) {
        $existingNotes = $pdo->prepare('SELECT * FROM note WHERE id_mat = ? AND semestre = ?');
        $existingNotes->execute([$gridSubjectId, $gridSubject['semestre']]);
        $notesByStudent = [];
        foreach ($existingNotes->fetchAll() as $existingNote) {
            $notesByStudent[(int) $existingNote['id_etudiant']] = $existingNote;
        }
        foreach ($groupMembers[$gridGroupId] ?? [] as $member) {
            $gridRows[] = [
                'student' => $member,
                'note' => $notesByStudent[(int) $member['id_etudiant']] ?? null,
            ];
        }
    } elseif ($error === '') {
        $error = 'Ce groupe ou cette matière n’est pas disponible pour votre compte.';
    }
}
$marksByGroupAndSubject = [];
foreach ($recentMarks as $mark) {
    $groupName = $mark['groupe_semestre'] ? $mark['groupe_semestre'] . ' · ' . $mark['type_groupe'] . ' ' . $mark['numero'] : 'Groupe non renseigné';
    $subjectName = $mark['code_ecue'] . ' — ' . $mark['description_ecue'];
    $marksByGroupAndSubject[$groupName][$subjectName][] = $mark;
}

if ($editSearch !== '') {
    $editStudentQuery = $pdo->prepare('SELECT id_etudiant, nom, prenom, cin_passeport FROM etudiant WHERE cin_passeport = ? LIMIT 1');
    $editStudentQuery->execute([$editSearch]);
    $editStudent = $editStudentQuery->fetch();
    if ($editStudent) {
        $editNotesQuery = $pdo->prepare(
            'SELECT n.id_note, n.semestre, n.note, n.note_cours, n.note_td, n.note_tp,
                    m.code_ecue, m.description_ecue
             FROM note n
             JOIN matiere m ON m.id_mat = n.id_mat
             WHERE n.id_etudiant = ?
             ORDER BY n.semestre, m.code_ecue'
        );
        $editNotesQuery->execute([$editStudent['id_etudiant']]);
        $editStudentNotes = $editNotesQuery->fetchAll();
    }

}

if (is_teacher() && $teacherSearch !== '') {
    $teacherStudentQuery = $pdo->prepare('SELECT id_etudiant, nom, prenom, cin_passeport, num_inscription FROM etudiant WHERE cin_passeport = ? LIMIT 1');
    $teacherStudentQuery->execute([$teacherSearch]);
    $teacherStudent = $teacherStudentQuery->fetch();
    if ($teacherStudent) {
        $teacherNotesQuery = $pdo->prepare(
            'SELECT DISTINCT n.id_note, n.semestre, n.note, n.note_cours, n.note_td, n.note_tp,
                    m.code_ecue, m.description_ecue, g.id_groupe, g.semestre AS groupe_semestre,
                    g.type_groupe, g.numero
             FROM note n
             JOIN matiere m ON m.id_mat = n.id_mat
             JOIN groupe_etudiant ge ON ge.id_etudiant = n.id_etudiant
             JOIN enseignant_affectation a ON a.id_mat = n.id_mat AND a.id_groupe = ge.id_groupe
             JOIN groupe g ON g.id_groupe = ge.id_groupe
             WHERE n.id_etudiant = ? AND a.id_enseignant = ?
               AND n.id_groupe = ge.id_groupe
             ORDER BY n.semestre, m.code_ecue, g.type_groupe, g.numero'
        );
        $teacherNotesQuery->execute([$teacherStudent['id_etudiant'], current_teacher_id()]);
        $teacherStudentNotes = $teacherNotesQuery->fetchAll();
    }
}

$teacherGroupNotes = [];
if (is_teacher() && $teacherGroupId) {
    $teacherGroupQuery = $pdo->prepare(
        'SELECT DISTINCT n.id_note, e.nom, e.prenom, e.cin_passeport,
                n.semestre, n.note, n.note_cours, n.note_td, n.note_tp,
                m.code_ecue, m.description_ecue
         FROM note n
         JOIN etudiant e ON e.id_etudiant = n.id_etudiant
         JOIN matiere m ON m.id_mat = n.id_mat
         JOIN groupe_etudiant ge ON ge.id_etudiant = n.id_etudiant
         JOIN enseignant_affectation a ON a.id_mat = n.id_mat AND a.id_groupe = ge.id_groupe
         WHERE ge.id_groupe = ? AND a.id_enseignant = ?
           AND n.id_groupe = ge.id_groupe
         ORDER BY e.nom, e.prenom, m.code_ecue'
    );
    $teacherGroupQuery->execute([$teacherGroupId, current_teacher_id()]);
    $teacherGroupNotes = $teacherGroupQuery->fetchAll();
}

page_head('Gestion des notes', 'grades');
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4"><div><p class="eyebrow">Scolarité</p><h1 class="display-6 fw-bold mb-1">Gestion des notes</h1><p class="text-secondary mb-0">Saisissez les notes de tout un groupe dans une seule grille, puis enregistrez.</p></div></div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h4">Grille du groupe</h2>
    <p class="text-secondary">Les étudiants sont en lignes. CC, Examen, TP, finale et rattrapage sont en colonnes. Un enseignant remplit les lignes vides. Une note déjà enregistrée se modifie depuis le compte administrateur.</p>
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-5">
        <label class="form-label" for="grid_group">Groupe</label>
        <select class="form-select" id="grid_group" name="grid_group" required>
          <option value="">Choisir un groupe</option>
          <?php foreach ($groups as $group): ?>
            <option value="<?= (int) $group['id_groupe'] ?>" data-semester="<?= e($group['semestre']) ?>" <?= $gridGroupId === (int) $group['id_groupe'] ? 'selected' : '' ?>><?= e($group['semestre'] . ' · ' . $group['type_groupe'] . ' ' . $group['numero']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label" for="grid_subject">Matière</label>
        <select class="form-select" id="grid_subject" name="grid_subject" required>
          <option value="">Choisir une matière</option>
          <?php foreach ($subjects as $subject): ?>
            <?php $subjectGroups = is_teacher() ? ($teacherSubjectGroups[(int) $subject['id_mat']] ?? []) : array_map(static fn (array $group): int => (int) $group['id_groupe'], $groups); ?>
            <option value="<?= (int) $subject['id_mat'] ?>" data-semester="<?= e($subject['semestre']) ?>" data-groups="<?= e(implode(',', $subjectGroups)) ?>" <?= $gridSubjectId === (int) $subject['id_mat'] ? 'selected' : '' ?>><?= e($subject['semestre'] . ' · ' . $subject['code_ecue'] . ' — ' . $subject['description_ecue']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100">Afficher</button></div>
    </form>
    <?php if ($gridRows): ?>
      <form method="post" class="mt-4">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_grid">
        <input type="hidden" name="grid_group" value="<?= (int) $gridGroupId ?>">
        <input type="hidden" name="grid_subject" value="<?= (int) $gridSubjectId ?>">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
          <div>
            <strong><?= e($gridSubject['code_ecue'] . ' — ' . $gridSubject['description_ecue']) ?></strong>
            <span class="d-block text-secondary small"><?= e($gridGroup['semestre'] . ' · ' . $gridGroup['type_groupe'] . ' ' . $gridGroup['numero']) ?> · <?= count($gridRows) ?> étudiant<?= count($gridRows) > 1 ? 's' : '' ?></span>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-light" href="grille_notes.php?group=<?= (int) $gridGroupId ?>&amp;subject=<?= (int) $gridSubjectId ?>" target="_blank" rel="noopener">Imprimer la feuille</a>
            <button class="btn btn-primary">Enregistrer la grille</button>
          </div>
        </div>
        <div class="grade-grid-wrap">
          <table class="table table-dark table-hover align-middle mb-0 grade-grid-table">
            <thead>
              <tr>
                <th>Étudiant</th>
                <th>CC /20</th>
                <th>Examen /20</th>
                <th>TP /20</th>
                <th>Finale /20</th>
                <th>Rattrapage /20</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($gridRows as $gridRow): ?>
                <?php
                  $studentId = (int) $gridRow['student']['id_etudiant'];
                  $savedNote = $gridRow['note'];
                  $locked = is_teacher() && $savedNote;
                  $cell = static function (?array $savedNote, string $field) use ($locked): string {
                      if (!$savedNote || $savedNote[$field] === null || $savedNote[$field] === '') {
                          return '';
                      }
                      return (string) $savedNote[$field];
                  };
                ?>
                <tr class="<?= $locked ? 'is-locked' : '' ?>">
                  <td>
                    <strong><?= e($gridRow['student']['nom'] . ' ' . $gridRow['student']['prenom']) ?></strong>
                    <small class="d-block text-secondary"><?= e($gridRow['student']['cin_passeport']) ?></small>
                    <?php if ($locked): ?><small class="text-warning">Déjà enregistrée</small><?php endif; ?>
                  </td>
                  <?php foreach (['note_cours', 'note_td', 'note_tp', 'note', 'note_rattrapage'] as $field): ?>
                    <td>
                      <input class="form-control grade-grid-input" name="grid[<?= $studentId ?>][<?= $field ?>]" type="number" min="0" max="20" step="0.01" inputmode="decimal" value="<?= e($cell($savedNote, $field)) ?>" <?= $locked ? 'readonly tabindex="-1"' : '' ?> aria-label="<?= e($field) ?>">
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </form>
    <?php elseif ($gridGroupId && $gridSubjectId && $error === ''): ?>
      <div class="alert alert-warning mt-3 mb-0">Ce groupe ne contient aucun étudiant.</div>
    <?php endif; ?>
  </div>
</div>
<?php if (is_admin()): ?><div class="card app-card mb-4"><div class="card-body p-4"><h2 class="h4">Modifier une note par CIN / passeport</h2><form method="get" class="row g-3 align-items-end"><div class="col-md-9"><label class="form-label" for="edit_search">CIN / passeport</label><input class="form-control" id="edit_search" name="edit_search" value="<?= e($editSearch) ?>" placeholder="Rechercher l’étudiant avant de modifier une note"></div><div class="col-md-3"><button class="btn btn-outline-info w-100">Rechercher les notes</button></div></form><?php if ($editSearch !== '' && !$editStudent): ?><div class="alert alert-warning mt-3 mb-0">Aucun étudiant trouvé avec ce CIN / passeport.</div><?php elseif ($editStudent): ?><div class="table-responsive mt-3"><table class="table table-dark table-hover align-middle mb-0"><thead><tr><th>Semestre</th><th>ECUE</th><th>Notes</th><th></th></tr></thead><tbody><?php foreach ($editStudentNotes as $studentNote): ?><tr><td><?= e($studentNote['semestre']) ?></td><td><?= e($studentNote['code_ecue'] . ' — ' . $studentNote['description_ecue']) ?></td><td>CC: <?= $studentNote['note_cours'] === null ? '—' : e((string) $studentNote['note_cours']) ?> · Examen: <?= $studentNote['note_td'] === null ? '—' : e((string) $studentNote['note_td']) ?> · TP: <?= $studentNote['note_tp'] === null ? '—' : e((string) $studentNote['note_tp']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-light" href="gestion_notes.php?edit=<?= (int) $studentNote['id_note'] ?>">Modifier</a></td></tr><?php endforeach; ?><?php if (!$editStudentNotes): ?><tr><td colspan="4" class="text-center text-secondary">Aucune note pour cet étudiant.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?></div></div><?php endif; ?>
<?php if (is_teacher()): ?><div class="card app-card mb-4"><div class="card-body p-4"><h2 class="h4">Rechercher les notes d’un étudiant</h2><form method="get" class="row g-3 align-items-end"><div class="col-md-9"><label class="form-label" for="teacher_search">CIN / passeport</label><input class="form-control" id="teacher_search" name="teacher_search" value="<?= e($teacherSearch) ?>" placeholder="Saisissez le CIN ou le passeport"></div><div class="col-md-3"><button class="btn btn-outline-info w-100">Rechercher</button></div></form><div class="d-flex flex-wrap gap-2 mt-3"><span class="align-self-center text-secondary small">Mes groupes :</span><?php foreach ($groups as $group): $groupName = $group['semestre'] . ' · ' . $group['type_groupe'] . ' ' . $group['numero']; ?><a class="btn btn-sm <?= $teacherGroupId === (int) $group['id_groupe'] ? 'btn-info' : 'btn-outline-info' ?>" href="gestion_notes.php?teacher_group=<?= (int) $group['id_groupe'] ?>"><?= e($groupName) ?></a><?php endforeach; ?></div><?php if ($teacherSearch !== '' && !$teacherStudent): ?><div class="alert alert-warning mt-3 mb-0">Aucun étudiant trouvé avec ce CIN / passeport.</div><?php elseif ($teacherStudent): ?><a class="alert alert-info mt-3 mb-0 d-block text-decoration-none" href="#teacher-notes">Étudiant : <strong><?= e($teacherStudent['nom'] . ' ' . $teacherStudent['prenom']) ?></strong> — <?= e($teacherStudent['cin_passeport']) ?><span class="d-block small mt-1">Cliquer pour afficher ses notes</span></a><?php endif; ?></div></div><?php endif; ?>
<div class="row g-4">
  <div class="col-12"><div class="card app-card"><div class="card-body p-4"><h2 class="h4"><?= $editNote ? 'Modifier une note' : 'Saisir les notes manuellement' ?></h2><?php if (!$subjects): ?><div class="alert alert-warning mb-0">Aucune matière n’est disponible dans la base de données.</div><?php else: ?><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_note"><?php if ($editNote): ?><input type="hidden" name="id_note" value="<?= (int) $editNote['id_note'] ?>"><input type="hidden" name="id_etudiant" value="<?= (int) $editNote['id_etudiant'] ?>"><?php else: ?><div class="col-md-6"><label class="form-label">Groupe *</label><select class="form-select" id="id_groupe" name="id_groupe" required><option value="">Choisir un groupe</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id_groupe'] ?>"><?= e($group['semestre'] . ' · ' . $group['type_groupe'] . ' ' . $group['numero']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Étudiant *</label><select class="form-select" id="id_etudiant" name="id_etudiant" required><option value="">Choisir un groupe d’abord</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id_etudiant'] ?>"><?= e($student['nom'] . ' ' . $student['prenom'] . ' — ' . $student['cin_passeport']) ?></option><?php endforeach; ?></select></div><?php endif; ?><div class="col-md-6"><label class="form-label">Matière / ECUE *</label><select class="form-select" id="id_mat" name="id_mat" required><option value="">Choisir</option><?php foreach ($subjects as $subject): ?><option value="<?= (int) $subject['id_mat'] ?>" data-semester="<?= e($subject['semestre']) ?>" <?= $editNote && (int) $editNote['id_mat'] === (int) $subject['id_mat'] ? 'selected' : '' ?>><?= e($subject['semestre'] . ' · ' . $subject['code_ecue'] . ' — ' . $subject['description_ecue']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Semestre *</label><select class="form-select" name="semestre" required><?php foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $semester): ?><option value="<?= $semester ?>" <?= ($editNote['semestre'] ?? 'S1') === $semester ? 'selected' : '' ?>><?= $semester ?></option><?php endforeach; ?></select>  </div><div class="col-md-3"><label class="form-label">CC /20</label><input class="form-control" name="note_cours" type="number" min="0" max="20" step="0.01" value="<?= e((string) ($editNote['note_cours'] ?? '')) ?>"></div><div class="col-md-3"><label class="form-label">Examen /20</label><input class="form-control" name="note_td" type="number" min="0" max="20" step="0.01" value="<?= e((string) ($editNote['note_td'] ?? '')) ?>"></div><div class="col-md-3"><label class="form-label">TP /20</label><input class="form-control" name="note_tp" type="number" min="0" max="20" step="0.01" value="<?= e((string) ($editNote['note_tp'] ?? '')) ?>"></div><div class="col-md-3"><label class="form-label">Note finale manuelle</label><input class="form-control" name="note" type="number" min="0" max="20" step="0.01" value="<?= e((string) ($editNote['note'] ?? '')) ?>" placeholder="Calcul plus tard"></div><div class="col-md-3"><label class="form-label">Rattrapage</label><input class="form-control" name="note_rattrapage" type="number" min="0" max="20" step="0.01" value="<?= e((string) ($editNote['note_rattrapage'] ?? '')) ?>" placeholder="Facultatif"></div><div class="col-12"><small class="text-secondary">Les notes CC, Examen et TP sont enregistrées séparément. Aucun calcul automatique n’est appliqué.</small></div><div class="col-12 d-flex justify-content-between gap-2"><?php if ($editNote): ?><a href="gestion_notes.php" class="btn btn-outline-light">Annuler</a><?php else: ?><span></span><?php endif; ?><button class="btn btn-primary"><?= $editNote ? 'Enregistrer les modifications' : 'Enregistrer les notes' ?></button></div></form><?php endif; ?></div></div></div>
</div>
<?php if (is_admin()): ?><?php foreach ($marksByGroupAndSubject as $groupName => $subjectsMarks): ?>
  <?php foreach ($subjectsMarks as $subjectName => $marks): ?>
    <div class="card app-card mt-4">
      <div class="card-body p-0 table-responsive">
        <div class="px-4 py-3 border-bottom"><h2 class="h5 mb-1"><?= e($groupName) ?></h2><p class="text-secondary mb-0"><?= e($subjectName) ?></p></div>
        <table class="table table-dark table-hover align-middle mb-0">
          <thead><tr><th>Étudiant</th><th>Semestre</th><th>Cours</th><th>TD</th><th>TP</th><th>Finale</th><?php if (is_admin()): ?><th class="text-end">Action</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($marks as $mark): ?>
            <tr><td><strong><?= e($mark['nom'] . ' ' . $mark['prenom']) ?></strong><br><small class="text-secondary"><?= e($mark['cin_passeport']) ?></small></td><td><?= e($mark['semestre']) ?></td><td><?= $mark['note_cours'] === null ? '—' : e((string) $mark['note_cours']) . '/20' ?></td><td><?= $mark['note_td'] === null ? '—' : e((string) $mark['note_td']) . '/20' ?></td><td><?= $mark['note_tp'] === null ? '—' : e((string) $mark['note_tp']) . '/20' ?></td><td><?= $mark['note'] === null ? '—' : e((string) $mark['note']) . '/20' ?></td><?php if (is_admin()): ?><td class="text-end"><a class="btn btn-sm btn-outline-light" href="gestion_notes.php?edit=<?= (int) $mark['id_note'] ?>">Modifier</a></td><?php endif; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>
<?php if (!$recentMarks): ?><div class="card app-card mt-4"><div class="card-body text-center text-secondary py-5">Aucune note enregistrée.</div></div><?php endif; ?>
<?php else: ?>
  <?php if ($teacherStudent): ?><div id="teacher-notes" class="card app-card mt-4"><div class="card-body p-0 table-responsive"><div class="px-4 py-3 border-bottom"><h2 class="h5 mb-1">Notes autorisées pour cet étudiant</h2><p class="text-secondary mb-0"><?= e($teacherStudent['nom'] . ' ' . $teacherStudent['prenom']) ?></p></div><table class="table table-dark table-hover align-middle mb-0"><thead><tr><th>Matière</th><th>Groupe</th><th>Semestre</th><th>CC</th><th>Examen</th><th>TP</th><th>Finale</th></tr></thead><tbody><?php foreach ($teacherStudentNotes as $mark): ?><tr><td><?= e($mark['code_ecue'] . ' — ' . $mark['description_ecue']) ?></td><td><?= e($mark['groupe_semestre'] . ' · ' . $mark['type_groupe'] . ' ' . $mark['numero']) ?></td><td><?= e($mark['semestre']) ?></td><td><?= $mark['note_cours'] === null ? '—' : e((string) $mark['note_cours']) . '/20' ?></td><td><?= $mark['note_td'] === null ? '—' : e((string) $mark['note_td']) . '/20' ?></td><td><?= $mark['note_tp'] === null ? '—' : e((string) $mark['note_tp']) . '/20' ?></td><td><?= $mark['note'] === null ? '—' : e((string) $mark['note']) . '/20' ?></td></tr><?php endforeach; ?><?php if (!$teacherStudentNotes): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucune note dans vos matières et groupes affectés.</td></tr><?php endif; ?></tbody></table></div></div><?php endif; ?>
  <?php if ($teacherGroupId): ?><div class="card app-card mt-4"><div class="card-body p-0 table-responsive"><div class="px-4 py-3 border-bottom"><h2 class="h5 mb-1">Étudiants notés dans le groupe sélectionné</h2><p class="text-secondary mb-0">Matières et groupe affectés uniquement</p></div><table class="table table-dark table-hover align-middle mb-0"><thead><tr><th>Étudiant</th><th>Matière</th><th>Semestre</th><th>CC</th><th>Examen</th><th>TP</th><th>Finale</th></tr></thead><tbody><?php foreach ($teacherGroupNotes as $mark): ?><tr><td><strong><?= e($mark['nom'] . ' ' . $mark['prenom']) ?></strong><br><small class="text-secondary"><?= e($mark['cin_passeport']) ?></small></td><td><?= e($mark['code_ecue'] . ' — ' . $mark['description_ecue']) ?></td><td><?= e($mark['semestre']) ?></td><td><?= $mark['note_cours'] === null ? '—' : e((string) $mark['note_cours']) . '/20' ?></td><td><?= $mark['note_td'] === null ? '—' : e((string) $mark['note_td']) . '/20' ?></td><td><?= $mark['note_tp'] === null ? '—' : e((string) $mark['note_tp']) . '/20' ?></td><td><?= $mark['note'] === null ? '—' : e((string) $mark['note']) . '/20' ?></td></tr><?php endforeach; ?><?php if (!$teacherGroupNotes): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucune note dans ce groupe pour vos affectations.</td></tr><?php endif; ?></tbody></table></div></div><?php endif; ?>
<?php endif; ?>
<script>
(() => {
  const groupSelect = document.getElementById('id_groupe');
  const studentSelect = document.getElementById('id_etudiant');
  const subjectSelect = document.getElementById('id_mat');
  const members = <?= json_encode($groupMembers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (!groupSelect || !studentSelect) return;
  function refreshStudents() {
    const groupId = groupSelect.value;
    const selected = studentSelect.value;
    studentSelect.innerHTML = '<option value="">Choisir un étudiant</option>';
    (members[groupId] || []).forEach((student) => {
      const label = `${student.nom} ${student.prenom} — ${student.cin_passeport}`;
      const option = new Option(label, student.id_etudiant);
      if (String(student.id_etudiant) === selected) option.selected = true;
      studentSelect.add(option);
    });
  }
  function refreshSubjects() {
    if (!subjectSelect) return;
    Array.from(subjectSelect.options).forEach(function (option) {
      if (!option.value) return;
      option.hidden = false;
    });
  }
  function syncGroupToSubject() {
    if (!subjectSelect || <?= json_encode(!is_teacher()) ?>) return;
    const groups = <?= json_encode($teacherSubjectGroups, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>[subjectSelect.value] || [];
    if (!groups.includes(Number(groupSelect.value))) {
      const currentStudent = studentSelect.value;
      const matchingGroup = groups.find((groupId) => (members[groupId] || []).some((student) => String(student.id_etudiant) === currentStudent));
      if (matchingGroup) {
        groupSelect.value = matchingGroup;
        refreshStudents();
      } else if (groups.length) {
        groupSelect.value = groups[0];
        refreshStudents();
      }
    }
  }
  groupSelect.addEventListener('change', refreshStudents);
  groupSelect.addEventListener('change', refreshSubjects);
  if (subjectSelect) subjectSelect.addEventListener('change', syncGroupToSubject);
  refreshSubjects();
})();
(() => {
  const groupSelect = document.getElementById('grid_group');
  const subjectSelect = document.getElementById('grid_subject');
  if (!groupSelect || !subjectSelect) return;
  const options = Array.from(subjectSelect.options);
  function refreshGridSubjects() {
    const semester = groupSelect.selectedOptions[0]?.dataset.semester || '';
    const groupId = groupSelect.value;
    let selectedStillVisible = false;
    options.forEach((option) => {
      if (!option.value) return;
      const groups = (option.dataset.groups || '').split(',').filter(Boolean);
      const visible = (!semester || option.dataset.semester === semester) && (!groupId || groups.includes(groupId));
      option.hidden = !visible;
      option.disabled = !visible;
      if (visible && option.value === subjectSelect.value) selectedStillVisible = true;
    });
    if (!selectedStillVisible) subjectSelect.value = '';
  }
  groupSelect.addEventListener('change', refreshGridSubjects);
  refreshGridSubjects();
  document.querySelectorAll('.grade-grid-input:not([readonly])').forEach((input) => {
    input.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      const inputs = Array.from(document.querySelectorAll('.grade-grid-input:not([readonly])'));
      const next = inputs[inputs.indexOf(input) + 1];
      if (next) next.focus();
    });
  });
})();
</script>
<?php page_foot();



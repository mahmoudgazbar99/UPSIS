<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_teacher_or_admin();

$pdo = db();

$teacherId = is_teacher() 
    ? current_teacher_id() 
    : (int) (filter_input(INPUT_GET, 'id_enseignant', FILTER_VALIDATE_INT) ?: 0);

$groupId = (int) (filter_input(INPUT_GET, 'id_groupe', FILTER_VALIDATE_INT) ?: 0);
$subjectId = (int) (filter_input(INPUT_GET, 'id_mat', FILTER_VALIDATE_INT) ?: 0);

if (is_admin()) {
    $teachers = $pdo->query('SELECT id_enseignant, cin, nom, prenom FROM enseignant ORDER BY nom, prenom')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT id_enseignant, cin, nom, prenom FROM enseignant WHERE id_enseignant = ?');
    $stmt->execute([$teacherId]);
    $teachers = $stmt->fetchAll();
}

$teacherName = $teachers ? $teachers[0]['nom'] . ' ' . $teachers[0]['prenom'] : 'Enseignant';

$groups = [];
$subjects = [];

if ($teacherId > 0) {
    $query = $pdo->prepare(
        'SELECT DISTINCT g.id_groupe, g.semestre, g.type_groupe, g.numero,
                parent.numero AS parent_numero,
                MOD(g.numero - 1, 2) + 1 AS td_numero
         FROM enseignant_affectation a
         JOIN groupe g ON g.id_groupe = a.id_groupe
         LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
         WHERE a.id_enseignant = ? AND g.type_groupe IN ("Cours", "TD")
         ORDER BY g.semestre, g.type_groupe, g.numero'
    );
    $query->execute([$teacherId]);
    $groups = $query->fetchAll();
}

if ($teacherId > 0 && $groupId > 0) {
    $query = $pdo->prepare(
        'SELECT m.id_mat, m.semestre, m.code_ecue, m.description_ecue
         FROM enseignant_affectation a
         JOIN matiere m ON m.id_mat = a.id_mat
         WHERE a.id_enseignant = ? AND a.id_groupe = ?
         ORDER BY m.semestre, m.code_ecue'
    );
    $query->execute([$teacherId, $groupId]);
    $subjects = $query->fetchAll();
}

$previewReady = $teacherId > 0
    && $groupId > 0
    && $subjectId > 0
    && (bool) array_filter(
        $subjects,
        static fn (array $subject): bool => (int) $subject['id_mat'] === $subjectId
    );
$documentQuery = http_build_query([
    'id_enseignant' => $teacherId,
    'id_groupe' => $groupId,
    'id_mat' => $subjectId,
]);

page_head('Feuille de présence', 'attendance');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="eyebrow">Scolarité</p>
        <h1 class="display-6 fw-bold mb-1">Feuille de présence</h1>
        <p class="text-secondary mb-0">Générez une feuille uniquement pour les groupes et matières affectés.</p>
    </div>
</div>

<div class="card app-card">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <?php if (is_admin()): ?>
                <div class="col-md-4">
                    <label class="form-label">Enseignant *</label>
                    <select class="form-select" name="id_enseignant" onchange="this.form.submit()" required>
                        <option value="">Choisir</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= (int) $teacher['id_enseignant'] ?>" <?= $teacherId === (int) $teacher['id_enseignant'] ? 'selected' : '' ?>>
                                <?= e($teacher['nom'] . ' ' . $teacher['prenom'] . ' — ' . $teacher['cin']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="id_enseignant" value="<?= $teacherId ?>">
                <div class="col-md-4">
                    <label class="form-label">Enseignant</label>
                    <input class="form-control" value="<?= e($teacherName) ?>" disabled>
                </div>
            <?php endif; ?>

            <div class="col-md-4">
                <label class="form-label">Groupe affecté *</label>
                <select class="form-select" name="id_groupe" onchange="this.form.submit()" <?= empty($groups) ? 'disabled' : '' ?> required>
                    <option value="">Choisir</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id_groupe'] ?>" <?= $groupId === (int) $group['id_groupe'] ? 'selected' : '' ?>>
                            <?= e($group['semestre'] . ' · ' . ($group['type_groupe'] === 'TD'
                                ? 'Cours ' . $group['parent_numero'] . ' · TD ' . $group['td_numero']
                                : 'Cours ' . $group['numero'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Matière affectée *</label>
                <select class="form-select" name="id_mat" <?= empty($subjects) ? 'disabled' : '' ?> required>
                    <option value="">Choisir</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= (int) $subject['id_mat'] ?>" <?= $subjectId === (int) $subject['id_mat'] ? 'selected' : '' ?>>
                            <?= e($subject['semestre'] . ' · ' . $subject['code_ecue'] . ' — ' . $subject['description_ecue']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <button class="btn btn-primary">
                    Aperçu de la feuille de présence
                </button>
            </div>
        </form>

        <?php if ($teacherId > 0 && empty($groups)): ?>
            <div class="alert alert-warning mt-3 mb-0">Cet enseignant n’a encore aucun groupe affecté.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($previewReady): ?>
    <section class="mt-4" aria-labelledby="attendance-preview-title">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div>
                <p class="eyebrow mb-1">Vérification avant téléchargement</p>
                <h2 id="attendance-preview-title" class="h4 mb-0">Aperçu du document</h2>
            </div>
            <a class="btn btn-primary" href="generate_feuille_presence.php?<?= e($documentQuery) ?>">Télécharger le PDF</a>
        </div>
        <div class="card app-card">
            <div class="card-body p-2">
                <iframe
                    src="generate_feuille_presence.php?<?= e($documentQuery) ?>&amp;pdf=preview"
                    title="Aperçu PDF de la feuille de présence"
                    style="display:block;width:100%;height:78vh;border:0"
                ></iframe>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php page_foot(); ?>
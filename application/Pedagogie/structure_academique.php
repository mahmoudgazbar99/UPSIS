<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'program') {
            academic_add_program(
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['nom'] ?? ''),
                (string) ($_POST['nom_ar'] ?? ''),
                (string) ($_POST['award_title'] ?? ''),
                (string) ($_POST['award_title_ar'] ?? '')
            );
            $message = 'Le programme a été ajouté. Il n’a pas encore de niveaux : ajoutez-les ci-dessous.';
        } elseif ($action === 'period') {
            academic_add_period(
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                (string) ($_POST['label_ar'] ?? ''),
                (int) ($_POST['sequence'] ?? 0),
                (string) ($_POST['period_type'] ?? 'semester')
            );
            $message = 'La période a été ajoutée.';
        } elseif ($action === 'update_period') {
            academic_update_period(
                (int) ($_POST['id_period'] ?? 0),
                (string) ($_POST['label'] ?? ''),
                (string) ($_POST['label_ar'] ?? ''),
                (int) ($_POST['sequence'] ?? 0),
                (string) ($_POST['period_type'] ?? '')
            );
            $message = 'La période a été mise à jour.';
        } elseif ($action === 'level') {
            academic_add_level(
                (int) ($_POST['id_program'] ?? 0),
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                (string) ($_POST['label_ar'] ?? ''),
                (int) ($_POST['sequence'] ?? 0),
                !empty($_POST['is_final']),
                (string) ($_POST['period_codes'] ?? '')
            );
            $message = 'Le niveau a été ajouté. Les étudiants déjà inscrits gardent leur niveau actuel.';
        } elseif ($action === 'update_level') {
            academic_update_level(
                (int) ($_POST['id_level'] ?? 0),
                (string) ($_POST['label'] ?? ''),
                (string) ($_POST['label_ar'] ?? ''),
                (int) ($_POST['sequence'] ?? 0),
                !empty($_POST['is_final']),
                (string) ($_POST['period_codes'] ?? '')
            );
            $message = 'Le niveau a été mis à jour.';
        } elseif ($action === 'award') {
            $programId = (int) ($_POST['id_program'] ?? 0);
            $award = trim((string) ($_POST['award_title'] ?? ''));
            $awardAr = trim((string) ($_POST['award_title_ar'] ?? ''));
            if ($programId < 1 || $award === '' || $awardAr === '' || !preg_match('/\p{Arabic}/u', $awardAr)) {
                throw new InvalidArgumentException('Saisissez l’intitulé du diplôme en français et en arabe.');
            }
            db()->prepare('UPDATE program SET award_title = ?, award_title_ar = ? WHERE id_program = ?')->execute([$award, $awardAr, $programId]);
            academic_policy_note('diplome', 'Intitulé du diplôme mis à jour pour le programme ' . $programId);
            $message = 'L’intitulé du diplôme est enregistré. Les archives déjà produites ne sont pas réécrites.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = 'Ce code existe déjà.';
    }
}

$programs = academic_programs();
$periods = academic_periods();
$levels = db()->query('SELECT l.*, p.code AS program_code FROM study_level l LEFT JOIN program p ON p.id_program = l.id_program WHERE l.active = 1 ORDER BY p.code, l.sequence')->fetchAll();
page_head('Structure académique', 'groups');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Structure académique</h1>
  <p class="text-secondary mb-0">Programmes, niveaux et périodes. Les semestres S1 à S6 et les trois années actuelles restent en place.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h5">Nouveau programme</h2>
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="program">
      <div class="col-md-2"><label class="form-label">Code</label><input class="form-control" name="code" maxlength="12" required placeholder="MASTER"></div>
      <div class="col-md-5"><label class="form-label">Nom</label><input class="form-control" name="nom" required></div>
      <div class="col-md-5"><label class="form-label">Nom arabe</label><input class="form-control text-end" dir="rtl" lang="ar" name="nom_ar"></div>
      <div class="col-md-6"><label class="form-label">Diplôme</label><input class="form-control" name="award_title" required></div>
      <div class="col-md-6"><label class="form-label">Diplôme en arabe</label><input class="form-control text-end" dir="rtl" lang="ar" name="award_title_ar" required></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Ajouter le programme</button></div>
    </form>
  </div>
</div>
<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h5">Intitulé du diplôme</h2>
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="award">
      <div class="col-md-4"><label class="form-label">Programme</label><select class="form-select" name="id_program" required><?php foreach ($programs as $program): ?><option value="<?= (int) $program['id_program'] ?>"><?= e($program['code'] . ' — ' . $program['nom']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Diplôme</label><input class="form-control" name="award_title" value="<?= e((string) ($programs[0]['award_title'] ?? '')) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Diplôme en arabe</label><input class="form-control text-end" dir="rtl" lang="ar" name="award_title_ar" value="<?= e((string) ($programs[0]['award_title_ar'] ?? '')) ?>" required></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer l’intitulé</button></div>
    </form>
  </div>
</div>
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card app-card h-100"><div class="card-body p-4">
      <h2 class="h5">Période</h2>
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="period">
        <div class="col-md-2"><label class="form-label">Code</label><input class="form-control" name="code" maxlength="12" required placeholder="T1"></div>
        <div class="col-md-3"><label class="form-label">Libellé</label><input class="form-control" name="label" required></div>
        <div class="col-md-3"><label class="form-label">Libellé arabe</label><input class="form-control text-end" dir="rtl" lang="ar" name="label_ar" required></div>
        <div class="col-md-2"><label class="form-label">Ordre</label><input class="form-control" name="sequence" type="number" min="1" max="40" required></div>
        <div class="col-md-2"><label class="form-label">Type</label><select class="form-select" name="period_type"><option value="semester">Semestre</option><option value="trimester">Trimestre</option><option value="quarter">Quadrimestre</option><option value="custom">Autre</option></select></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Ajouter la période</button></div>
      </form>
      <ul class="list-unstyled mt-3 mb-0"><?php foreach ($periods as $period): ?><li class="d-flex align-items-center justify-content-between gap-2 py-1"><span><strong><?= e((string) $period['code']) ?></strong> — <?= e((string) $period['label']) ?><br><small class="text-secondary" dir="rtl" lang="ar"><?= e((string) ($period['label_ar'] ?? '')) ?></small></span><?php if (isset($period['id_period'])): ?><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#editPeriodModal" data-id="<?= (int) $period['id_period'] ?>" data-code="<?= e((string) $period['code']) ?>" data-label="<?= e((string) $period['label']) ?>" data-label-ar="<?= e((string) ($period['label_ar'] ?? '')) ?>" data-sequence="<?= (int) ($period['sequence'] ?? 1) ?>" data-type="<?= e((string) ($period['period_type'] ?? 'semester')) ?>">Modifier</button><?php endif; ?></li><?php endforeach; ?></ul>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card app-card h-100"><div class="card-body p-4">
      <h2 class="h5">Niveau</h2>
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="level">
        <div class="col-md-6"><label class="form-label">Programme</label><select class="form-select" name="id_program" required><?php foreach ($programs as $program): ?><option value="<?= (int) $program['id_program'] ?>"><?= e($program['code']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Code</label><input class="form-control" name="code" maxlength="12" required placeholder="4"></div>
        <div class="col-md-3"><label class="form-label">Ordre</label><input class="form-control" name="sequence" type="number" min="1" max="20" required></div>
        <div class="col-md-6"><label class="form-label">Libellé</label><input class="form-control" name="label" required placeholder="4ème"></div>
        <div class="col-md-6"><label class="form-label">Libellé arabe</label><input class="form-control text-end" dir="rtl" lang="ar" name="label_ar" required></div>
        <div class="col-md-8"><label class="form-label">Périodes</label><input class="form-control" name="period_codes" required placeholder="S7,S8"></div>
        <div class="col-md-4 d-flex align-items-end"><label class="form-check-label"><input class="form-check-input" type="checkbox" name="is_final" value="1"> Niveau final</label></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Ajouter le niveau</button></div>
      </form>
      <ul class="list-unstyled mt-3 mb-0"><?php foreach ($levels as $level): ?><li class="d-flex align-items-center justify-content-between gap-2 py-1"><span><?= e(($level['program_code'] ?? '—') . ' · ' . $level['code'] . ' ' . $level['label'] . ' · ' . $level['period_codes'] . ((int) $level['is_final'] === 1 ? ' · final' : '')) ?><br><small class="text-secondary" dir="rtl" lang="ar"><?= e((string) $level['label_ar']) ?></small></span><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#editLevelModal" data-id="<?= (int) $level['id_level'] ?>" data-program="<?= e((string) ($level['program_code'] ?? '—')) ?>" data-code="<?= e((string) $level['code']) ?>" data-label="<?= e((string) $level['label']) ?>" data-label-ar="<?= e((string) $level['label_ar']) ?>" data-sequence="<?= (int) $level['sequence'] ?>" data-periods="<?= e((string) $level['period_codes']) ?>" data-final="<?= (int) $level['is_final'] ?>">Modifier</button></li><?php endforeach; ?></ul>
    </div></div>
  </div>
</div>
<div class="modal fade" id="editPeriodModal" tabindex="-1" aria-labelledby="editPeriodModalLabel" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content app-card">
    <form method="post" class="row g-3 p-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_period">
      <input type="hidden" name="id_period">
      <div class="col-12"><h2 class="h5 mb-0" id="editPeriodModalLabel">Modifier la période <span data-period-code></span></h2></div>
      <div class="col-md-6"><label class="form-label">Libellé</label><input class="form-control" name="label" required maxlength="80"></div>
      <div class="col-md-6"><label class="form-label">Libellé arabe</label><input class="form-control text-end" dir="rtl" lang="ar" name="label_ar" required maxlength="80"></div>
      <div class="col-md-6"><label class="form-label">Ordre</label><input class="form-control" name="sequence" type="number" min="1" max="40" required></div>
      <div class="col-md-6"><label class="form-label">Type</label><select class="form-select" name="period_type"><option value="semester">Semestre</option><option value="trimester">Trimestre</option><option value="quarter">Quadrimestre</option><option value="custom">Autre</option></select></div>
      <div class="col-12 d-flex justify-content-end gap-2"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>
  </div></div>
</div>
<div class="modal fade" id="editLevelModal" tabindex="-1" aria-labelledby="editLevelModalLabel" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content app-card">
    <form method="post" class="row g-3 p-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_level">
      <input type="hidden" name="id_level">
      <div class="col-12"><h2 class="h5 mb-0" id="editLevelModalLabel">Modifier le niveau <span data-level-code></span> · <span data-level-program></span></h2><small class="text-secondary">Le code et le programme sont conservés pour protéger les inscriptions existantes.</small></div>
      <div class="col-md-6"><label class="form-label">Libellé</label><input class="form-control" name="label" required maxlength="80"></div>
      <div class="col-md-6"><label class="form-label">Libellé arabe</label><input class="form-control text-end" dir="rtl" lang="ar" name="label_ar" required maxlength="80"></div>
      <div class="col-md-4"><label class="form-label">Ordre</label><input class="form-control" name="sequence" type="number" min="1" max="20" required></div>
      <div class="col-md-5"><label class="form-label">Périodes</label><input class="form-control" name="period_codes" required placeholder="S7,S8"></div>
      <div class="col-md-3 d-flex align-items-end"><label class="form-check-label"><input class="form-check-input" type="checkbox" name="is_final" value="1"> Niveau final</label></div>
      <div class="col-12 d-flex justify-content-end gap-2"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>
  </div></div>
</div>
<script>
document.body.appendChild(document.getElementById('editPeriodModal'));
document.body.appendChild(document.getElementById('editLevelModal'));

document.getElementById('editPeriodModal').addEventListener('show.bs.modal', function (event) {
  const button = event.relatedTarget;
  const form = this.querySelector('form');
  form.elements.id_period.value = button.dataset.id;
  form.elements.label.value = button.dataset.label;
  form.elements.label_ar.value = button.dataset.labelAr;
  form.elements.sequence.value = button.dataset.sequence;
  form.elements.period_type.value = button.dataset.type;
  this.querySelector('[data-period-code]').textContent = button.dataset.code;
});
document.getElementById('editLevelModal').addEventListener('show.bs.modal', function (event) {
  const button = event.relatedTarget;
  const form = this.querySelector('form');
  form.elements.id_level.value = button.dataset.id;
  form.elements.label.value = button.dataset.label;
  form.elements.label_ar.value = button.dataset.labelAr;
  form.elements.sequence.value = button.dataset.sequence;
  form.elements.period_codes.value = button.dataset.periods;
  form.elements.is_final.checked = button.dataset.final === '1';
  this.querySelector('[data-level-code]').textContent = button.dataset.code;
  this.querySelector('[data-level-program]').textContent = button.dataset.program;
});
</script>
<?php page_foot();

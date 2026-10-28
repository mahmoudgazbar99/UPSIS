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
                (int) ($_POST['sequence'] ?? 0),
                (string) ($_POST['period_type'] ?? 'semester')
            );
            $message = 'La période a été ajoutée.';
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
$levels = db()->query('SELECT l.*, p.code AS program_code FROM study_level l LEFT JOIN program p ON p.id_program = l.id_program ORDER BY p.code, l.sequence')->fetchAll();
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
        <div class="col-md-3"><label class="form-label">Code</label><input class="form-control" name="code" maxlength="12" required placeholder="T1"></div>
        <div class="col-md-5"><label class="form-label">Libellé</label><input class="form-control" name="label" required></div>
        <div class="col-md-2"><label class="form-label">Ordre</label><input class="form-control" name="sequence" type="number" min="1" max="40" required></div>
        <div class="col-md-2"><label class="form-label">Type</label><select class="form-select" name="period_type"><option value="semester">Semestre</option><option value="trimester">Trimestre</option><option value="quarter">Quadrimestre</option><option value="custom">Autre</option></select></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Ajouter la période</button></div>
      </form>
      <ul class="mt-3 mb-0"><?php foreach ($periods as $period): ?><li><?= e($period['code'] . ' — ' . $period['label']) ?></li><?php endforeach; ?></ul>
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
      <ul class="mt-3 mb-0"><?php foreach ($levels as $level): ?><li><?= e(($level['program_code'] ?? '—') . ' · ' . $level['code'] . ' ' . $level['label'] . ' · ' . $level['period_codes'] . ((int) $level['is_final'] === 1 ? ' · final' : '')) ?></li><?php endforeach; ?></ul>
    </div></div>
  </div>
</div>
<?php page_foot();

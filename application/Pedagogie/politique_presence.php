<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';
$policy = attendance_policy();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if ((string) ($_POST['confirmation'] ?? '') !== 'PUBLIER') {
            throw new InvalidArgumentException('Saisissez PUBLIER pour appliquer cette politique aux alertes.');
        }
        $absences = (int) ($_POST['max_absences_subject'] ?? 0);
        $percent = (float) str_replace(',', '.', (string) ($_POST['missed_percent'] ?? ''));
        $hours = (float) str_replace(',', '.', (string) ($_POST['expected_hours'] ?? ''));
        $session = (float) str_replace(',', '.', (string) ($_POST['session_hours'] ?? ''));
        $semester = strtoupper(trim((string) ($_POST['semester_code'] ?? '')));
        if ($absences < 1 || $absences > 100 || $percent <= 0 || $percent > 100 || $hours <= 0 || $session <= 0 || $session > 12) {
            throw new InvalidArgumentException('Vérifiez les seuils d’absence, le pourcentage, le volume et la durée de séance.');
        }
        if (!academic_period_valid($semester)) {
            throw new InvalidArgumentException('La période du seuil horaire doit exister dans la structure.');
        }
        academic_publish_replace('attendance_policy', null, [
            'max_absences_subject' => $absences,
            'missed_percent' => number_format($percent, 2, '.', ''),
            'expected_hours' => number_format($hours, 2, '.', ''),
            'session_hours' => number_format($session, 2, '.', ''),
            'semester_code' => $semester,
        ]);
        academic_policy_note('presence', 'Politique de présence publiée : ' . $absences . ' absences, ' . $percent . '% de ' . $hours . ' h');
        $policy = attendance_policy();
        $message = 'Politique publiée. Les pointages déjà saisis ne changent pas. Les dérogations restent valables. Les alertes utilisent maintenant ces seuils.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'La publication a échoué.';
    }
}
$limit = (float) $policy['expected_hours'] * ((float) $policy['missed_percent'] / 100);
page_head('Politique de présence', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Présence</h1>
  <p class="text-secondary mb-0">Seuils des alertes d’élimination. Portée : établissement.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card"><div class="card-body p-4">
  <p>Aperçu : <?= (int) $policy['max_absences_subject'] ?> absences par matière, ou <?= e(number_format($limit, 2, ',', '')) ?> h sur <?= e((string) $policy['semester_code']) ?> (<?= e(number_format((float) $policy['missed_percent'], 2, ',', '')) ?> % de <?= e(number_format((float) $policy['expected_hours'], 2, ',', '')) ?> h).</p>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="col-md-4"><label class="form-label">Absences max. par matière</label><input class="form-control" name="max_absences_subject" type="number" min="1" max="100" value="<?= (int) $policy['max_absences_subject'] ?>" required></div>
    <div class="col-md-4"><label class="form-label">Pourcentage d’heures</label><input class="form-control" name="missed_percent" inputmode="decimal" value="<?= e(number_format((float) $policy['missed_percent'], 2, '.', '')) ?>" required></div>
    <div class="col-md-4"><label class="form-label">Volume de référence (h)</label><input class="form-control" name="expected_hours" inputmode="decimal" value="<?= e(number_format((float) $policy['expected_hours'], 2, '.', '')) ?>" required></div>
    <div class="col-md-4"><label class="form-label">Durée de séance par défaut (h)</label><input class="form-control" name="session_hours" inputmode="decimal" value="<?= e(number_format((float) $policy['session_hours'], 2, '.', '')) ?>" required></div>
    <div class="col-md-4"><label class="form-label">Période du seuil horaire</label><select class="form-select" name="semester_code"><?php foreach (academic_periods() as $period): ?><option value="<?= e((string) $period['code']) ?>" <?= (string) $policy['semester_code'] === (string) $period['code'] ? 'selected' : '' ?>><?= e((string) $period['code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Confirmation</label><input class="form-control" name="confirmation" placeholder="PUBLIER" required></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Publier</button></div>
  </form>
</div></div>
<?php page_foot();

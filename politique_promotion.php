<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$error = '';
$message = '';
$policy = progression_policy();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $threshold = (float) str_replace(',', '.', (string) ($_POST['pass_threshold'] ?? ''));
    $sample = (float) str_replace(',', '.', (string) ($_POST['sample'] ?? '10'));
    try {
        if ((string) ($_POST['confirmation'] ?? '') !== 'PUBLIER') {
            throw new InvalidArgumentException('Saisissez PUBLIER pour remplacer la politique publiée.');
        }
        if ($threshold < 0 || $threshold > 20) {
            throw new InvalidArgumentException('Le seuil doit être compris entre 0 et 20.');
        }
        academic_publish_replace('progression_policy', null, [
            'pass_threshold' => number_format($threshold, 2, '.', ''),
            'graduate_status' => 'diplome',
        ]);
        academic_policy_note('promotion', 'Seuil de promotion publié : ' . number_format($threshold, 2, '.', ''));
        $policy = progression_policy();
        $plan = progression_plan('2', $sample);
        $message = 'Politique publiée. Exemple : moyenne ' . number_format($sample, 2, ',', '') . ' en 2ème année → ' . $plan['decision'] . ', niveau ' . $plan['next_level'] . '.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'La publication a échoué.';
    }
}
$planLow = progression_plan('2', (float) $policy['pass_threshold'] - 0.01);
$planOk = progression_plan('2', (float) $policy['pass_threshold']);
page_head('Politique de promotion', 'promotion');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Promotion</h1>
  <p class="text-secondary mb-0">Seuil d’admission de l’établissement. La promotion demande toujours le mot de passe et le mot PROMOUVOIR.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card"><div class="card-body p-4">
  <p>Politique n° <?= (int) $policy['id_policy'] ?> · seuil <strong><?= e(number_format((float) $policy['pass_threshold'], 2, ',', '')) ?></strong>/20.</p>
  <p class="text-secondary">Aperçu pour une 2ème année : <?= e(number_format((float) $policy['pass_threshold'] - 0.01, 2, ',', '')) ?> → <?= e($planLow['decision']) ?> ; <?= e(number_format((float) $policy['pass_threshold'], 2, ',', '')) ?> → <?= e($planOk['decision']) ?> vers <?= e(level_label($planOk['next_level'])) ?>.</p>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="col-md-4"><label class="form-label" for="pass_threshold">Nouveau seuil</label><input class="form-control" id="pass_threshold" name="pass_threshold" inputmode="decimal" value="<?= e(number_format((float) $policy['pass_threshold'], 2, '.', '')) ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="sample">Moyenne d’essai</label><input class="form-control" id="sample" name="sample" inputmode="decimal" value="10"></div>
    <div class="col-md-4"><label class="form-label" for="confirmation">Confirmation</label><input class="form-control" id="confirmation" name="confirmation" placeholder="PUBLIER" required></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Publier</button></div>
  </form>
  <p class="form-text mb-0 mt-3">Les décisions déjà archivées ne sont pas recalculées.</p>
</div></div>
<?php page_foot();

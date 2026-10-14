<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$posts = [
    'secretaire' => [
        'key' => 'sec_generale',
        'title' => 'Secrétaire général',
        'placeholder' => '${sec_generale}',
        'placeholder_ar' => '${sec_generale_ar}',
        'lead' => 'Le nom français et le nom arabe remplacent le secrétaire général dans les attestations.',
    ],
    'directeur' => [
        'key' => 'directeur',
        'title' => 'Directeur',
        'placeholder' => '${directeur}',
        'placeholder_ar' => '${directeur_ar}',
        'lead' => 'Le nom français et le nom arabe remplacent le directeur dans les attestations.',
    ],
];
$poste = (string) ($_GET['poste'] ?? $_POST['poste'] ?? '');
if (!isset($posts[$poste])) {
    $poste = 'secretaire';
}
$current = $posts[$poste];
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $name = institution_person_name((string) ($_POST['nom'] ?? ''));
        $nameAr = institution_arabic_name((string) ($_POST['nom_ar'] ?? ''));
        institution_setting_put($current['key'], $name);
        institution_setting_put($current['key'] . '_ar', $nameAr);
        $message = $current['title'] . ' enregistré.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'L’enregistrement a échoué.';
    }
}

$brand = institution_brand();
$saved = $brand[$current['key']];
$savedAr = $brand[$current['key'] . '_ar'];
page_head($current['title'], 'accounts');
?>
<div class="mb-4">
  <p class="eyebrow">Direction</p>
  <h1 class="display-6 fw-bold mb-1"><?= e($current['title']) ?></h1>
  <p class="text-secondary mb-0"><?= e($current['lead']) ?></p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card">
  <div class="card-body p-4">
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="poste" value="<?= e($poste) ?>">
      <div class="col-12 col-lg-8">
        <label class="form-label" for="nom">Nom affiché</label>
        <input class="form-control form-control-lg" id="nom" name="nom" value="<?= e($saved) ?>" maxlength="120" required autocomplete="off">
        <div class="form-text">Attestations en français : <code><?= e($current['placeholder']) ?></code></div>
      </div>
      <div class="col-12 col-lg-8">
        <label class="form-label" for="nom_ar">Nom en arabe</label>
        <input class="form-control form-control-lg text-end" id="nom_ar" name="nom_ar" dir="rtl" lang="ar" value="<?= e($savedAr) ?>" maxlength="120" required autocomplete="off">
        <div class="form-text">Attestations en arabe : <code><?= e($current['placeholder_ar']) ?></code></div>
      </div>
      <div class="col-12">
        <button class="btn btn-primary" type="submit">Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php page_foot();

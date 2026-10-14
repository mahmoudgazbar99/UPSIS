<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if ((string) ($_POST['action'] ?? '') === 'reset_logo') {
            institution_reset_logo();
            $message = 'Le logo d’origine est rétabli.';
        } else {
            institution_save_university_name((string) ($_POST['university_name'] ?? ''));
            if (isset($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                institution_save_logo($_FILES['logo']);
            }
            $message = 'Les informations de l’université sont enregistrées.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'L’enregistrement a échoué.';
    }
}

$brand = institution_brand();
page_head('Université', 'home');
?>
<div class="mb-4">
  <p class="eyebrow">Paramètres</p>
  <h1 class="display-6 fw-bold mb-1">Nom et logo</h1>
  <p class="text-secondary mb-0">Ce nom et ce logo s’affichent dans toutes les pages et tous les thèmes.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card">
  <div class="card-body p-4">
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="col-12">
        <label class="form-label" for="university_name">Nom de l’université</label>
        <textarea class="form-control" id="university_name" name="university_name" rows="3" maxlength="180" required><?= e($brand['name']) ?></textarea>
        <div class="form-text">Une ligne par ligne affichée dans la barre du haut. Trois lignes maximum.</div>
      </div>
      <div class="col-12">
        <p class="form-label mb-2">Logo actuel</p>
        <img src="<?= e($brand['logo']) ?>" alt="Logo de l’université" class="brand-logo-preview">
      </div>
      <div class="col-12 col-lg-8">
        <label class="form-label" for="logo">Nouveau logo</label>
        <input class="form-control" id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
        <div class="form-text">PNG, JPG, GIF ou WebP, 2 Mo maximum. Laissez vide pour garder le logo actuel.</div>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <button class="btn btn-primary" type="submit">Enregistrer</button>
        <?php if (institution_logo_custom()): ?>
          <button class="btn btn-outline-secondary" type="submit" name="action" value="reset_logo">Logo d’origine</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php page_foot();

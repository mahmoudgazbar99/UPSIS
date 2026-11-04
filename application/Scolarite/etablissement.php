<?php
declare(strict_types=1);
require_once app_path('layout.php');
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
            institution_save_establishment_names(
                (string) ($_POST['establishment_fr'] ?? ''),
                (string) ($_POST['establishment_ar'] ?? '')
            );
            institution_save_place(
                (string) ($_POST['institution_city'] ?? ''),
                (string) ($_POST['institution_city_ar'] ?? ''),
                (string) ($_POST['institution_address'] ?? ''),
                (string) ($_POST['institution_country'] ?? '')
            );
            if (isset($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                institution_save_logo($_FILES['logo']);
            }
            $message = 'Les informations de l’établissement sont enregistrées.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'L’enregistrement a échoué.';
    }
}

$brand = institution_brand();
page_head('Nom de l’établissement', 'home');
?>
<div class="mb-4">
  <p class="eyebrow">Paramètres</p>
  <h1 class="display-6 fw-bold mb-1">Nom de l’établissement</h1>
  <p class="text-secondary mb-0">Ces noms sont utilisés dans l’application et remplacent les champs d’établissement des attestations.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card">
  <div class="card-body p-4">
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="col-md-6">
        <label class="form-label" for="establishment_fr">Nom en français</label>
        <input class="form-control" id="establishment_fr" name="establishment_fr" value="<?= e($brand['establishment_fr']) ?>" maxlength="180" required>
        <div class="form-text">Remplace le champ <code>${establishment_fr}</code> des modèles Word.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="establishment_ar">Nom en arabe</label>
        <input class="form-control text-end" id="establishment_ar" name="establishment_ar" dir="rtl" lang="ar" value="<?= e($brand['establishment_ar']) ?>" maxlength="180" required>
        <div class="form-text">Remplace le champ <code>${establishment_ar}</code> des modèles Word.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="institution_city">Ville d’émission</label>
        <input class="form-control" id="institution_city" name="institution_city" value="<?= e(institution_city()) ?>" maxlength="80" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="institution_city_ar">Ville en arabe</label>
        <input class="form-control text-end" id="institution_city_ar" name="institution_city_ar" dir="rtl" lang="ar" value="<?= e(institution_city_ar()) ?>" maxlength="80" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="institution_country">Pays</label>
        <input class="form-control" id="institution_country" name="institution_country" value="<?= e(institution_country()) ?>" maxlength="80" required>
      </div>
      <div class="col-12">
        <label class="form-label" for="institution_address">Adresse</label>
        <input class="form-control" id="institution_address" name="institution_address" value="<?= e(institution_address()) ?>" maxlength="255">
        <div class="form-text">Exemple de date sur les documents : <?= e(institution_place_fr()) ?></div>
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

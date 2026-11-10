<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'reset_cachet') {
            certificate_reset_cachet();
            $message = 'Le cachet a été retiré. Les prochaines attestations n’auront pas de tampon.';
        } elseif ($action === 'save_cachet') {
            if (isset($_FILES['cachet']) && (int) ($_FILES['cachet']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                certificate_save_cachet($_FILES['cachet']);
                $message = 'Cachet enregistré. Il apparaîtra sur les Word/PDF générés.';
            } else {
                throw new InvalidArgumentException('Choisissez une image de cachet.');
            }
        } elseif ($action === 'save_numbering') {
            foreach (array_keys(certificate_types()) as $type) {
                certificate_save_numbering(
                    $type,
                    (string) ($_POST['prefix_' . $type] ?? ''),
                    (int) ($_POST['next_' . $type] ?? 1)
                );
            }
            $message = 'Numérotation enregistrée.';
        } else {
            throw new InvalidArgumentException('Action non reconnue.');
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('attestations_config: ' . $exception->getMessage());
        $error = 'L’enregistrement a échoué.';
    }
}

page_head('Attestations', 'certificates');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Cachet et numérotation</h1>
  <p class="text-secondary mb-0">Tampon optionnel et numéro officiel (N° ATT-INS-00041…) sur les attestations Word/PDF. Les textes du modèle ne sont pas modifiés ici.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h5">Cachet</h2>
    <p class="text-secondary small">Image ajoutée en bas à droite du document, en plus du logo. Laissez vide = aucun tampon.</p>
    <?php if (certificate_cachet_custom()): ?>
      <p class="form-label mb-2">Cachet actuel</p>
      <img src="<?= e(certificate_cachet_src()) ?>" alt="Cachet" class="brand-logo-preview mb-3" style="max-height:120px;width:auto;">
    <?php else: ?>
      <p class="text-secondary">Aucun cachet configuré.</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_cachet">
      <div class="col-12 col-lg-8">
        <label class="form-label" for="cachet">Nouveau cachet</label>
        <input class="form-control" id="cachet" name="cachet" type="file" accept="image/png,image/jpeg,image/gif,image/webp" required>
        <div class="form-text">PNG, JPG, GIF ou WebP, 2 Mo maximum.</div>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <button class="btn btn-primary" type="submit">Enregistrer le cachet</button>
      </div>
    </form>
    <?php if (certificate_cachet_custom()): ?>
      <form method="post" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_cachet">
        <button class="btn btn-outline-secondary" type="submit">Retirer le cachet</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card app-card">
  <div class="card-body p-4">
    <h2 class="h5">Numérotation</h2>
    <p class="text-secondary small">Chaque téléchargement Word/PDF consomme le prochain numéro. L’aperçu affiche le prochain numéro sans l’incrémenter.</p>
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_numbering">
      <?php foreach (certificate_types() as $type => $label): ?>
        <?php $cfg = certificate_numbering($type); ?>
        <div class="col-12">
          <h3 class="h6 mb-2"><?= e($label) ?></h3>
        </div>
        <div class="col-md-5">
          <label class="form-label" for="prefix_<?= e($type) ?>">Préfixe</label>
          <input class="form-control" id="prefix_<?= e($type) ?>" name="prefix_<?= e($type) ?>" value="<?= e($cfg['prefix']) ?>" maxlength="24" required>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="next_<?= e($type) ?>">Prochain n°</label>
          <input class="form-control" id="next_<?= e($type) ?>" name="next_<?= e($type) ?>" type="number" min="1" max="999999" value="<?= (int) $cfg['next'] ?>" required>
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <p class="mb-2 text-secondary small">Exemple : <strong><?= e(certificate_format_number($cfg['prefix'], $cfg['next'])) ?></strong></p>
        </div>
      <?php endforeach; ?>
      <div class="col-12">
        <button class="btn btn-primary" type="submit">Enregistrer la numérotation</button>
      </div>
    </form>
  </div>
</div>
<?php page_foot();

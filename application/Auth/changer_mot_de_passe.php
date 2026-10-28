<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($currentPassword, admin_password_hash())) {
        $error = 'Le mot de passe actuel est incorrect.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Les deux nouveaux mots de passe ne correspondent pas.';
    } elseif (password_verify($newPassword, admin_password_hash())) {
        $error = 'Le nouveau mot de passe doit être différent de l’ancien.';
    } else {
        $statement = db()->prepare('UPDATE admin_user SET password_hash = ? WHERE username = ?');
        $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), 'admin']);
        $message = 'Mot de passe administrateur modifié avec succès.';
        audit_record('compte', 'modifier', 'Mot de passe administrateur modifié.');
    }
}

page_head('Changer le mot de passe', 'settings');
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
  <div><p class="eyebrow">Réglages</p><h1 class="display-6 fw-bold mb-1">Changer le mot de passe</h1><p class="text-secondary mb-0">Sécurisez l’accès administrateur de l’application.</p></div>
  
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card"><div class="card-body p-4">
  <form method="post" class="row g-3" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="col-12"><label class="form-label" for="current_password">Mot de passe actuel *</label><input class="form-control" id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
    <div class="col-md-6"><label class="form-label" for="new_password">Nouveau mot de passe *</label><input class="form-control" id="new_password" name="new_password" type="password" minlength="8" required autocomplete="new-password"></div>
    <div class="col-md-6"><label class="form-label" for="confirm_password">Confirmer le nouveau mot de passe *</label><input class="form-control" id="confirm_password" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
    <div class="col-12"><small class="text-secondary">Le nouveau mot de passe doit contenir au moins 8 caractères.</small></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Modifier le mot de passe</button></div>
  </form>
</div></div>
<?php page_foot();



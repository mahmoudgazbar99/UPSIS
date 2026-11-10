<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';
$policy = security_policy();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        security_save_policy(
            (int) ($_POST['session_idle_minutes'] ?? 60),
            (int) ($_POST['password_min_length'] ?? 8),
            isset($_POST['password_require_upper']),
            isset($_POST['password_require_lower']),
            isset($_POST['password_require_digit']),
            isset($_POST['password_require_special'])
        );
        $policy = security_policy();
        $message = 'Règles de sécurité enregistrées.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('securite_session: ' . $exception->getMessage());
        $error = 'L’enregistrement a échoué.';
    }
}

$idle = (int) $policy['session_idle_minutes'];
page_head('Sécurité — session', 'accounts');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Sécurité — session et mot de passe</h1>
  <p class="text-secondary mb-0">Durée d’inactivité et règles de complexité. Pas de double authentification.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="row g-4">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

  <div class="col-lg-6">
    <div class="card app-card h-100">
      <div class="card-body p-4">
        <h2 class="h5">Session</h2>
        <p class="text-secondary small">Après cette durée sans activité, la session est fermée et l’utilisateur doit se reconnecter. S’applique au personnel et à l’espace étudiant.</p>
        <label class="form-label" for="session_idle_minutes">Inactivité maximale (minutes)</label>
        <input class="form-control" type="number" id="session_idle_minutes" name="session_idle_minutes" min="0" max="1440" step="1" value="<?= $idle ?>" required>
        <p class="small text-secondary mt-2 mb-0">
          <?php if ($idle === 0): ?>
            Actuellement : pas de déconnexion automatique (fermeture du navigateur seulement).
          <?php else: ?>
            Actuellement : déconnexion après <strong><?= $idle ?></strong> minute<?= $idle > 1 ? 's' : '' ?> d’inactivité.
          <?php endif; ?>
          · <span class="text-secondary">0 = désactiver le délai</span>
        </p>
        <p class="small text-secondary mt-2 mb-0">Exemples : 30 (court), 60 (défaut), 480 (journée de travail).</p>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card app-card h-100">
      <div class="card-body p-4">
        <h2 class="h5">Mot de passe</h2>
        <p class="text-secondary small">Appliqué à la création et au changement de mot de passe (admin, comptes, espace étudiant). Les mots de passe déjà enregistrés ne sont pas invalidés.</p>
        <label class="form-label" for="password_min_length">Longueur minimale</label>
        <input class="form-control mb-3" type="number" id="password_min_length" name="password_min_length" min="6" max="72" step="1" value="<?= (int) $policy['password_min_length'] ?>" required>
        <div class="d-flex flex-column gap-2">
          <label class="d-flex align-items-center gap-2 mb-0">
            <input type="checkbox" name="password_require_upper" value="1" <?= !empty($policy['password_require_upper']) ? 'checked' : '' ?>>
            <span>Exiger une majuscule</span>
          </label>
          <label class="d-flex align-items-center gap-2 mb-0">
            <input type="checkbox" name="password_require_lower" value="1" <?= !empty($policy['password_require_lower']) ? 'checked' : '' ?>>
            <span>Exiger une minuscule</span>
          </label>
          <label class="d-flex align-items-center gap-2 mb-0">
            <input type="checkbox" name="password_require_digit" value="1" <?= !empty($policy['password_require_digit']) ? 'checked' : '' ?>>
            <span>Exiger un chiffre</span>
          </label>
          <label class="d-flex align-items-center gap-2 mb-0">
            <input type="checkbox" name="password_require_special" value="1" <?= !empty($policy['password_require_special']) ? 'checked' : '' ?>>
            <span>Exiger un caractère spécial</span>
          </label>
        </div>
        <p class="small text-secondary mt-3 mb-0"><?= e(password_policy_hint()) ?></p>
      </div>
    </div>
  </div>

  <div class="col-12">
    <button class="btn btn-primary" type="submit">Enregistrer</button>
  </div>
</form>
<?php page_foot();

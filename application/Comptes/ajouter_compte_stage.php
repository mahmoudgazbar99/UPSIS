<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $cin = trim((string) ($_POST['cin'] ?? ''));
    $nom = trim((string) ($_POST['nom'] ?? ''));
    $duty = (string) ($_POST['responsabilite'] ?? 'officer');
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirm'] ?? '');

    if (!preg_match('/^\d{8}$/', $cin)) {
        $error = 'Le CIN doit contenir exactement 8 chiffres.';
    } elseif ($nom === '' || mb_strlen($nom) > 120) {
        $error = 'Indiquez le nom de la personne.';
    } elseif (!in_array($duty, ['officer', 'superviseur'], true)) {
        $error = 'Choisissez Responsable des stages ou Encadrant de stage.';
    } elseif (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $confirmation) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } elseif (!stage_accounts_table_exists()) {
        $error = 'La table des comptes stage est absente. Sauvegardez la base puis exécutez stage_accounts_migration.sql sur la base utilisée par cette application.';
    } else {
        try {
            access_ensure();
            db()->prepare('INSERT INTO stage_user (cin, nom, password, responsabilite) VALUES (?, ?, ?, ?)')
                ->execute([$cin, $nom, password_hash($password, PASSWORD_DEFAULT), $duty]);
            $message = 'Compte stage créé avec succès.';
            audit_record('compte', 'creer', 'Compte stage · ' . ($duty === 'superviseur' ? 'encadrant' : 'responsable') . ' · CIN ' . $cin);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $error = 'Ce CIN est déjà associé à un compte stage.';
            } else {
                error_log('Stage account creation failed: ' . $exception->getMessage());
                $error = 'Le compte stage n’a pas pu être créé. Vérifiez la configuration de la base et les droits SQL.';
            }
        }
    }
}

page_head('Ajouter compte du service des stages', 'stage-accounts');
?>
<div class="mb-4">
  <p class="eyebrow">Administration · Accès utilisateur</p>
  <div class="stage-account-heading">
    <span class="stage-account-mark" aria-hidden="true">S</span>
    <div><span class="stage-account-kicker">Service des stages</span><h1 class="display-6 fw-bold mb-1">Créer un compte stage</h1></div>
  </div>
  <p class="text-secondary mb-0">Un accès dédié, identifié par CIN, pour le futur espace des stages.</p>
</div>
<?php if ($message): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<style>
  .stage-account-heading { display:flex; align-items:center; gap:1rem; margin:.25rem 0 .65rem; }
  .stage-account-mark { display:grid; place-items:center; flex:0 0 3.25rem; height:3.25rem; border:1px solid rgba(240,184,75,.55); border-radius:.75rem; background:rgba(240,184,75,.12); color:#f0b84b; font-size:1.35rem; font-weight:800; }
  .stage-account-kicker { display:block; margin-bottom:.15rem; color:#f0b84b; font-size:.78rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; }
  .stage-account-note { border-left:3px solid #f0b84b; padding:.85rem 1rem; background:rgba(240,184,75,.08); }
</style>
<section class="card app-card stage-account-card" aria-labelledby="stage-account-form-title">
  <div class="card-body p-4">
    <div class="stage-account-note mb-4"><strong>Identifiant de connexion : CIN</strong><span class="d-block text-secondary small mt-1">Utilisez les 8 chiffres du CIN. Le mot de passe sera enregistré de façon sécurisée.</span></div>
    <h2 id="stage-account-form-title" class="h5 mb-3">Identifiants du compte</h2>
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="col-md-4">
        <label class="form-label" for="nom">Nom *</label>
        <input class="form-control" id="nom" name="nom" maxlength="120" required value="<?= e((string) ($_POST['nom'] ?? '')) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="responsabilite">Rôle *</label>
        <select class="form-select" id="responsabilite" name="responsabilite">
          <option value="officer" <?= (($_POST['responsabilite'] ?? '') === 'superviseur') ? '' : 'selected' ?>>Responsable des stages</option>
          <option value="superviseur" <?= (($_POST['responsabilite'] ?? '') === 'superviseur') ? 'selected' : '' ?>>Encadrant de stage</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="cin">CIN *</label>
        <input class="form-control" id="cin" name="cin" inputmode="numeric" autocomplete="off" minlength="8" maxlength="8" pattern="[0-9]{8}" required value="<?= e((string) ($_POST['cin'] ?? '')) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="password">Mot de passe * <span class="text-secondary">(8 caractères minimum)</span></label>
        <input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="password_confirm">Retaper le mot de passe *</label>
        <input class="form-control" id="password_confirm" name="password_confirm" type="password" minlength="8" autocomplete="new-password" required>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <button class="btn btn-primary" type="submit">Créer le compte du service des stages</button>
        <a class="btn btn-outline-light" href="modifier_comptes.php">Gérer les comptes</a>
      </div>
    </form>
  </div>
</section>
<?php page_foot(); ?>

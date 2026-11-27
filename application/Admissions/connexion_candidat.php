<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/public_theme.php');
admissions_ensure();

if (is_candidate() && current_candidate_id() > 0) {
    header('Location: espace_candidat.php');
    exit;
}
if (is_student()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $bucket = 'candidat:' . $email;
    if (function_exists('auth_rate_limited') && auth_rate_limited($bucket)) {
        $error = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } else {
        $cand = admissions_find_candidate_by_email($email);
        if ($cand && (int) $cand['active'] === 1 && (string) $cand['password'] !== '' && password_verify($password, (string) $cand['password'])) {
            if ((int) ($cand['id_etudiant'] ?? 0) > 0) {
                ensure_etudiant_compte_table(db());
                $acct = db()->prepare('SELECT password, active, auth_generation FROM etudiant_compte WHERE id_etudiant = ?');
                $acct->execute([(int) $cand['id_etudiant']]);
                $account = $acct->fetch();
                if ($account && (int) $account['active'] === 1) {
                    if (function_exists('auth_rate_clear')) {
                        auth_rate_clear($bucket);
                    }
                    app_enter_role_session('etudiant', [
                        'id_etudiant' => (int) $cand['id_etudiant'],
                        'student_auth_generation' => (int) $account['auth_generation'],
                    ]);
                    header('Location: dashboard.php');
                    exit;
                }
            }
            if (function_exists('auth_rate_clear')) {
                auth_rate_clear($bucket);
            }
            app_enter_role_session('candidat', [
                'candidate_id' => (int) $cand['id_candidate'],
                'candidate_auth_generation' => (int) $cand['auth_generation'],
            ]);
            header('Location: espace_candidat.php');
            exit;
        }
        if (function_exists('auth_rate_fail')) {
            auth_rate_fail($bucket);
        }
        $error = 'Identifiants incorrects.';
    }
}
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php public_theme_boot_script(); ?>
  <title>Espace candidat | <?= e(institution_name_inline()) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/admissions-lightswind.css?v=3" rel="stylesheet">
</head>
<body class="adm-lw-page adm-lw">
  <?php public_theme_atmosphere_markup(); ?>
  <main class="adm-lw-gate">
    <div class="adm-lw-card adm-lw-gate-card">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <p class="adm-lw-kicker mb-0">Admissions</p>
        <?php public_theme_toggle_button(); ?>
      </div>
      <h1>Espace candidat</h1>
      <p class="text-secondary mb-3"><?= e(institution_name_inline()) ?></p>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="mb-3">
          <label class="form-label fw-semibold" for="email">E-mail</label>
          <input class="form-control" type="email" id="email" name="email" required value="<?= e($email) ?>" autocomplete="username">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="password">Mot de passe</label>
          <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Entrer</button>
      </form>
      <p class="mt-3 mb-0 small">
        <a href="preinscription.php">Préinscription</a> ·
        <a href="connexion_etudiant.php">Espace étudiant</a> ·
        <a href="accueil.php">Accueil</a>
      </p>
    </div>
  </main>
  <?php public_theme_footer_script(); ?>
</body>
</html>

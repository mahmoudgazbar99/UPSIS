<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/student_theme.php');

if (logged_in()) {
    header('Location: ' . (is_student() ? 'dashboard' : 'index'));
    exit;
}

$error = (($_GET['error'] ?? '') === 'suspended')
    ? 'Votre compte est suspendu. Contactez l’administrateur.'
    : '';
$cin = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $error = '';
    $cin = student_normalize_cin((string) ($_POST['cin'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $loginBucket = 'etudiant:' . strtolower($cin);
    if (auth_rate_limited($loginBucket)) {
        $error = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } else {
    try {
        ensure_etudiant_compte_table(db());
        $row = student_find_by_cin($cin);
        if ($row) {
            $stmt = db()->prepare(
                'SELECT password, active, auth_generation FROM etudiant_compte WHERE id_etudiant = ? LIMIT 1'
            );
            $stmt->execute([(int) $row['id_etudiant']]);
            $account = $stmt->fetch();
            if ($account && (int) $account['active'] === 1 && password_verify($password, (string) $account['password'])) {
                auth_rate_clear($loginBucket);
                session_regenerate_id(true);
                $_SESSION['user_role'] = 'etudiant';
                $_SESSION['id_etudiant'] = (int) $row['id_etudiant'];
                $_SESSION['student_auth_generation'] = (int) $account['auth_generation'];
                header('Location: dashboard');
                exit;
            }
        }
    } catch (PDOException $exception) {
        error_log('student login failed: ' . $exception->getMessage());
        $error = 'Erreur lors de la récupération des données';
    }
    if ($error === '') {
        auth_rate_fail($loginBucket);
        $error = 'Identifiants incorrects.';
    }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php student_theme_head(); ?>
  <title>Espace étudiant | <?= e(institution_name_inline()) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,500;6..72,600&display=swap" rel="stylesheet">
  <link href="assets/theme-tokens.css?v=1" rel="stylesheet">
  <link href="assets/motion.css?v=3" rel="stylesheet">
  <link href="assets/student-theme.css?v=16" rel="stylesheet">
  <link href="assets/student-lightswind.css?v=1" rel="stylesheet">
  <link href="assets/lw-theme-toggle.css?v=1" rel="stylesheet">
  <link href="assets/student-login.css?v=7" rel="stylesheet">
  <link href="assets/login-fail-lottie.css?v=5" rel="stylesheet">
</head>
<body class="student-gate">
  <?php student_theme_select(); ?>
  <main class="student-gate-card">
    <img src="<?= e(institution_logo_src()) ?>" class="student-gate-logo" alt="Logo de l'école">
    <h1>Espace étudiant</h1>
    <p class="student-gate-school"><?php foreach (institution_name_lines() as $index => $institutionLine): ?><?= $index > 0 ? '<br>' : '' ?><?= e($institutionLine) ?><?php endforeach; ?></p>
    <?php if ($error !== ''): ?><div class="alert" role="alert" data-login-fail="1"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="connexion_etudiant" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label for="cin">CIN / passeport</label>
      <input id="cin" name="cin" value="<?= e($cin) ?>" maxlength="40" required autofocus autocomplete="username">
      <label for="password">Mot de passe</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
      <button type="submit">Entrer</button>
    </form>
    <a class="student-gate-home" href="accueil.php">Accueil</a>
    <p class="student-gate-links">
      <a href="creer_espace_etudiant.php">Créer mon espace</a>
      <a href="login.php">Personnel</a>
    </p>
  </main>
<script type="module" src="assets/login-fail-lottie.js?v=5"></script>
<script src="assets/motion.js?v=3" defer></script>
</body>
</html>

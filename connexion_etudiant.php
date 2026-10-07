<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (logged_in()) {
    header('Location: ' . (is_student() ? 'dashboard.php' : 'index.php'));
    exit;
}

$error = '';
$cin = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cin = student_normalize_cin((string) ($_POST['cin'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    try {
        ensure_etudiant_compte_table(db());
        $row = student_find_by_cin($cin);
        if ($row) {
            $stmt = db()->prepare(
                'SELECT password, active FROM etudiant_compte WHERE id_etudiant = ? LIMIT 1'
            );
            $stmt->execute([(int) $row['id_etudiant']]);
            $account = $stmt->fetch();
            if ($account && (int) $account['active'] === 1 && password_verify($password, (string) $account['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_role'] = 'etudiant';
                $_SESSION['id_etudiant'] = (int) $row['id_etudiant'];
                header('Location: dashboard.php');
                exit;
            }
        }
    } catch (PDOException $exception) {
        $error = 'Erreur lors de la récupération des données';
    }
    if ($error === '') {
        $error = 'Identifiants incorrects.';
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Espace étudiant</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,650&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="assets/student-login.css?v=4" rel="stylesheet">
</head>
<body class="student-gate">
  <main class="student-gate-card">
    <img src="images/no_bg_logo.png?v=2" class="student-gate-logo" alt="Logo de l'école">
    <h1>Espace étudiant</h1>
    <p class="student-gate-school">École de Santé Gabès</p>
    <?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="connexion_etudiant.php" autocomplete="on">
      <label for="cin">CIN / passeport</label>
      <input id="cin" name="cin" value="<?= e($cin) ?>" maxlength="40" required autofocus autocomplete="username">
      <label for="password">Mot de passe</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
      <button type="submit">Entrer</button>
    </form>
    <p class="student-gate-links">
      <a href="creer_espace_etudiant.php">Créer mon espace</a>
      <a href="login.php">Personnel</a>
    </p>
  </main>
</body>
</html>

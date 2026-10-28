<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');

if (logged_in()) {
    header('Location: ' . (is_student() ? 'dashboard.php' : 'index.php'));
    exit;
}

$error = '';
$cin = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $cin = student_normalize_cin((string) ($_POST['cin'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($cin === '' || mb_strlen($cin) > 40) {
        $error = 'Indiquez le CIN ou le passeport utilisé lors de l’inscription.';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $error = 'Le mot de passe doit contenir entre 8 et 72 caractères.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        try {
            $pdo = db();
            ensure_etudiant_compte_table($pdo);

            // Seul un dossier déjà présent dans etudiant peut obtenir un espace.
            $student = student_find_by_cin($cin);

            if (!$student) {
                $error = 'Aucun étudiant inscrit ne correspond à ce CIN.';
            } else {
                $existing = $pdo->prepare('SELECT 1 FROM etudiant_compte WHERE id_etudiant = ? LIMIT 1');
                $existing->execute([(int) $student['id_etudiant']]);
                if ($existing->fetchColumn()) {
                    $error = 'Un espace existe déjà pour ce CIN. Connectez-vous.';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO etudiant_compte (id_etudiant, password) VALUES (?, ?)'
                    );
                    $insert->execute([
                        (int) $student['id_etudiant'],
                        password_hash($password, PASSWORD_DEFAULT),
                    ]);

                    session_regenerate_id(true);
                    $_SESSION['user_role'] = 'etudiant';
                    $_SESSION['id_etudiant'] = (int) $student['id_etudiant'];
                    $generation = $pdo->prepare('SELECT auth_generation FROM etudiant_compte WHERE id_etudiant = ? LIMIT 1');
                    $generation->execute([(int) $student['id_etudiant']]);
                    $_SESSION['student_auth_generation'] = (int) $generation->fetchColumn();
                    audit_record(
                        'compte',
                        'creer',
                        'Création de l’espace étudiant de ' . trim($student['prenom'] . ' ' . $student['nom'])
                    );
                    flash('success', 'Votre espace étudiant est ouvert.');
                    header('Location: dashboard.php');
                    exit;
                }
            }
        } catch (PDOException $exception) {
            $error = 'Erreur lors de la récupération des données';
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Créer un espace étudiant | <?= e(institution_name_inline()) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,650&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="assets/theme-tokens.css?v=1" rel="stylesheet">
  <link href="assets/student-login.css?v=4" rel="stylesheet">
  <link href="assets/student-theme.css?v=4" rel="stylesheet">
</head>
<body class="student-gate">
  <main class="student-gate-card">
    <img src="<?= e(institution_logo_src()) ?>" class="student-gate-logo" alt="Logo de l'école">
    <h1>Créer mon espace</h1>
    <p class="student-gate-school">Réservé aux CIN déjà inscrits</p>
    <?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="creer_espace_etudiant.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label for="cin">CIN / passeport</label>
      <input id="cin" name="cin" value="<?= e($cin) ?>" maxlength="40" required autofocus>
      <label for="password">Mot de passe</label>
      <input id="password" name="password" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
      <label for="password_confirm">Confirmer le mot de passe</label>
      <input id="password_confirm" name="password_confirm" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
      <button type="submit">Créer mon espace</button>
    </form>
    <p class="student-gate-links">
      <a href="connexion_etudiant.php">Retour à la connexion</a>
    </p>
  </main>
</body>
</html>

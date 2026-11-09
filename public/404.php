<?php
declare(strict_types=1);

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$home = '/accueil';
$login = '/login';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>404 · Page introuvable</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="/assets/not-found-lottie.css?v=1" rel="stylesheet">
</head>
<body class="not-found-page">
  <main class="not-found-card">
    <canvas class="not-found-lottie" data-not-found-lottie width="640" height="640" aria-hidden="true"></canvas>
    <p class="not-found-code">404</p>
    <h1 class="not-found-title">Page introuvable</h1>
    <p class="not-found-copy">Cette adresse n’existe pas ou a été déplacée.</p>
    <div class="not-found-actions">
      <a class="is-primary" href="<?= htmlspecialchars($home, ENT_QUOTES, 'UTF-8') ?>">Accueil</a>
      <a class="is-ghost" href="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8') ?>">Connexion</a>
    </div>
  </main>
  <script type="module" src="/assets/not-found-lottie.js?v=1"></script>
</body>
</html>

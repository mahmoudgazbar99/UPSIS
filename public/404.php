<?php
declare(strict_types=1);

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>404 · Page introuvable</title>
  <link href="/assets/not-found-lottie.css?v=2" rel="stylesheet">
</head>
<body class="not-found-page">
  <main class="not-found-card" aria-label="Page introuvable">
    <canvas class="not-found-lottie" data-not-found-lottie width="640" height="640" role="img" aria-label="404 page introuvable"></canvas>
  </main>
  <script type="module" src="/assets/not-found-lottie.js?v=2"></script>
</body>
</html>

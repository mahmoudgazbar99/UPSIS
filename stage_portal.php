<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_stage();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Service des stages | École de Santé Gabès</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/style.css?v=49" rel="stylesheet">
  <style>
    .stage-placeholder { min-height: 100vh; display: grid; place-content: center; padding: 2rem; text-align: center; }
    .stage-placeholder h1 { margin: 0; font-size: clamp(3rem, 12vw, 8rem); font-weight: 900; line-height: .95; letter-spacing: -.055em; color: #f0b84b; }
    .stage-placeholder p { max-width: 38rem; margin: 1.5rem auto 0; font-size: clamp(1rem, 2.5vw, 1.3rem); }
    .stage-placeholder a { margin-top: 2rem; justify-self: center; }
    body.theme-atlas .stage-placeholder h1 { font-family: "Fraunces", Georgia, serif; color: #e08a45; font-weight: 700; }
    body.theme-atlas.theme-light .stage-placeholder h1 { color: #c45c26; }
  </style>
  <script>
  (function () {
    try {
      var theme = localStorage.getItem('app-theme') || 'dark';
      var style = localStorage.getItem('app-style') || 'original';
      if (style === 'alternate') { style = 'atlas'; localStorage.setItem('app-style', 'atlas'); }
      document.documentElement.dataset.appTheme = theme === 'light' ? 'light' : 'dark';
      document.documentElement.dataset.appStyle = style === 'atlas' ? 'atlas' : 'original';
      document.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
    } catch (error) {}
  })();
  </script>
</head>
<body class="app-body theme-dark">
  <script>
  (function () {
    var body = document.body;
    var theme = document.documentElement.dataset.appTheme || 'dark';
    var style = document.documentElement.dataset.appStyle || 'original';
    body.classList.toggle('theme-light', theme === 'light');
    body.classList.toggle('theme-dark', theme !== 'light');
    body.classList.remove('theme-alternate');
    body.classList.toggle('theme-atlas', style === 'atlas');
  })();
  </script>
  <main class="stage-placeholder">
    <div>
      <p class="eyebrow mb-3">Service des stages</p>
      <h1>UNDER<br>CONSTRUCTION</h1>
      <p class="text-secondary">Cet espace est en cours de préparation. Aucun service n’est disponible pour le moment.</p>
      <a class="btn btn-outline-light" href="logout.php">Déconnexion</a>
    </div>
  </main>
  <script>
  (function () {
    const body = document.body;
    let style = localStorage.getItem('app-style') || 'original';
    if (style === 'alternate') { style = 'atlas'; localStorage.setItem('app-style', 'atlas'); }
    const theme = localStorage.getItem('app-theme') || 'dark';
    body.classList.toggle('theme-light', theme === 'light');
    body.classList.toggle('theme-dark', theme !== 'light');
    body.classList.remove('theme-alternate');
    body.classList.toggle('theme-atlas', style === 'atlas');
  })();
  </script>
</body>
</html>

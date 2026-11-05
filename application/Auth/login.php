<?php
require_once app_path('bootstrap.php');
$requestedRole = (string) ($_GET['role'] ?? '');
if (logged_in() && $requestedRole === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . (is_student() ? 'dashboard' : 'index'));
    exit;
}

$error = match ((string) ($_GET['error'] ?? '')) {
    'stage_setup' => 'Le compte stage est temporairement indisponible. Contactez l’administrateur pour appliquer la migration des comptes stage.',
    'suspended' => 'Votre compte est suspendu. Contactez l’administrateur.',
    default => '',
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $error = '';
    $role = (string) ($_POST['role'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $loginName = $role === 'enseignant' || $role === 'stage'
        ? strtolower(trim((string) ($_POST['cin'] ?? '')))
        : ($role === 'admin' ? 'admin' : strtolower(trim((string) ($_POST['username'] ?? ''))));
    $loginBucket = 'staff:' . $role . ':' . $loginName;
    $loginFailed = false;
    if (auth_rate_limited($loginBucket)) {
        $error = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } elseif ($role === 'admin' && password_verify($password, admin_password_hash())) {
        auth_rate_clear($loginBucket);
        app_enter_role_session('admin', [
            'admin_auth_generation' => admin_auth_generation(),
        ]);
        header('Location: index');
        exit;
    }
    if ($error === '' && $role === 'enseignant') {
        ensure_teacher_active(db());
        $stmt = db()->prepare('SELECT id_enseignant, password, active, auth_generation FROM enseignant WHERE cin = ? LIMIT 1');
        $stmt->execute([trim((string) ($_POST['cin'] ?? ''))]);
        $teacher = $stmt->fetch();
        if ($teacher && (int) ($teacher['active'] ?? 1) !== 1) {
            $teacher = false;
        }
        $validTeacherPassword = $teacher && password_verify($password, (string) $teacher['password']);
        if ($teacher && !$validTeacherPassword && preg_match('/^[a-f0-9]{32}$/i', (string) $teacher['password'])) {
            $validTeacherPassword = hash_equals((string) $teacher['password'], md5($password));
            if ($validTeacherPassword) {
                $upgrade = db()->prepare('UPDATE enseignant SET password = ? WHERE id_enseignant = ?');
                $upgrade->execute([password_hash($password, PASSWORD_DEFAULT), $teacher['id_enseignant']]);
            }
        }
        if ($validTeacherPassword) {
            auth_rate_clear($loginBucket);
            app_enter_role_session('enseignant', [
                'teacher_id' => (int) $teacher['id_enseignant'],
                'teacher_auth_generation' => (int) $teacher['auth_generation'],
            ]);
            header('Location: index');
            exit;
        }
    }
    if ($error === '' && $role === 'stage') {
        if (!stage_accounts_table_exists()) {
            $error = 'Le compte stage est temporairement indisponible. Contactez l’administrateur pour appliquer stage_accounts_migration.sql sur la base utilisée par cette application.';
        } else {
            access_ensure();
            $stmt = db()->prepare('SELECT id_stage, password, auth_generation, responsabilite FROM stage_user WHERE cin = ? AND active = 1 LIMIT 1');
            $stmt->execute([trim((string) ($_POST['cin'] ?? ''))]);
            $stage = $stmt->fetch();
            if ($stage && password_verify($password, (string) $stage['password'])) {
                auth_rate_clear($loginBucket);
                app_enter_role_session('stage', [
                    'stage_id' => (int) $stage['id_stage'],
                    'stage_auth_generation' => (int) $stage['auth_generation'],
                    'stage_duty' => (string) ($stage['responsabilite'] ?? 'officer'),
                ]);
                header('Location: stage_portal');
                exit;
            }
        }
    }
    if ($error === '' && $role === 'finance') {
        $stmt = db()->prepare('SELECT id_finance, password, auth_generation FROM finance_user WHERE username = ? AND active = 1 LIMIT 1');
        $stmt->execute([trim((string) ($_POST['username'] ?? ''))]);
        $finance = $stmt->fetch();
        if ($finance && password_verify($password, (string) $finance['password'])) {
            auth_rate_clear($loginBucket);
            app_enter_role_session('finance', [
                'finance_id' => (int) $finance['id_finance'],
                'finance_auth_generation' => (int) $finance['auth_generation'],
            ]);
            header('Location: index');
            exit;
        }
    }
    if ($error === '' && $role === 'scolarite') {
        access_ensure();
        $stmt = db()->prepare('SELECT id_scolarite, password, auth_generation FROM scolarite_user WHERE username = ? AND active = 1 LIMIT 1');
        $stmt->execute([trim((string) ($_POST['username'] ?? ''))]);
        $office = $stmt->fetch();
        if ($office && password_verify($password, (string) $office['password'])) {
            auth_rate_clear($loginBucket);
            app_enter_role_session('scolarite', [
                'scolarite_id' => (int) $office['id_scolarite'],
                'scolarite_auth_generation' => (int) $office['auth_generation'],
            ]);
            header('Location: index');
            exit;
        }
    }
    if ($error === '') {
        $loginFailed = true;
    }
    if ($loginFailed) {
        auth_rate_fail($loginBucket);
        $error = 'Identifiants incorrects.';
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Connexion</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Inter:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/theme-tokens.css?v=1" rel="stylesheet">
  <link href="assets/motion.css?v=3" rel="stylesheet">
  <link href="assets/style.css?v=91" rel="stylesheet">
  <link href="assets/institut.css?v=3" rel="stylesheet">
  <link href="assets/macos.css?v=52" rel="stylesheet">
  <link href="assets/clarity.css?v=22" rel="stylesheet">
  <script>
  (function () {
    try {
      var owner = <?= json_encode(app_theme_owner(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      var themeKey = 'app-theme:' + owner;
      var styleKey = 'app-style:' + owner;
      var theme = localStorage.getItem(themeKey) || 'dark';
      var style = localStorage.getItem(styleKey) || 'original';
      if (style === 'alternate' || style === 'atlas') { style = 'original'; localStorage.setItem(styleKey, 'original'); }
      if (style !== 'glass' && style !== 'macos' && style !== 'institut' && style !== 'clarity') style = 'original';
      document.documentElement.dataset.appTheme = theme === 'light' ? 'light' : 'dark';
      document.documentElement.dataset.appStyle = style;
      document.documentElement.dataset.themeOwner = owner;
      document.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
    } catch (error) {}
  })();
  </script>
</head>
<body class="app-body login-page theme-dark">
  <script>
  (function () {
    var body = document.body;
    var theme = document.documentElement.dataset.appTheme || 'dark';
    var style = document.documentElement.dataset.appStyle || 'original';
    body.classList.toggle('theme-light', theme === 'light');
    body.classList.toggle('theme-dark', theme !== 'light');
    body.classList.remove('theme-alternate', 'theme-atlas');
    body.classList.toggle('theme-glass', style === 'glass');
    body.classList.toggle('theme-macos', style === 'macos');
    body.classList.toggle('theme-clarity', style === 'clarity');
    body.classList.toggle('theme-institut', style === 'institut');
    document.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
  })();
  </script>
  <div class="login-theme-controls">
    <label class="theme-style-select-wrap">
      <span class="visually-hidden">Thème visuel</span>
      <select class="theme-style-select" data-style-select aria-label="Choisir le thème" title="Choisir le thème">
        <option value="original">Original</option>
        <option value="glass">Glass</option>
        <option value="macos">Mac OS</option>
        <option value="clarity">Mac bureau</option>
        <option value="institut">Institut</option>
      </select>
    </label>
    <time class="clarity-menu-clock" data-clarity-clock datetime=""></time>
    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Activer le mode clair" title="Activer le mode clair">
      <span class="theme-toggle-track" aria-hidden="true">
        <svg class="theme-toggle-icon theme-toggle-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16.6 13.2A6.6 6.6 0 1 1 10.8 5.4 5.2 5.2 0 0 0 16.6 13.2z"/></svg>
        <svg class="theme-toggle-icon theme-toggle-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M5 12H3M21 12h-2M6.2 6.2l1.4 1.4M16.4 16.4l1.4 1.4M17.8 6.2l-1.4 1.4M7.6 16.4 6.2 17.8"/></svg>
        <span class="theme-toggle-knob"></span>
      </span>
      <span data-theme-icon class="visually-hidden">☀</span>
      <span data-theme-label class="visually-hidden">Clair</span>
    </button>
  </div>
  <div class="macos-window" data-macos-window>
    <header class="macos-titlebar no-print" data-macos-titlebar>
      <div class="macos-traffic">
        <button type="button" class="macos-dot macos-dot-close" data-macos-action="close" aria-label="Fermer la fenêtre" title="Fermer"></button>
        <button type="button" class="macos-dot macos-dot-min" data-macos-action="minimize" aria-label="Réduire la fenêtre" title="Réduire"></button>
        <button type="button" class="macos-dot macos-dot-max" data-macos-action="maximize" aria-label="Agrandir la fenêtre" title="Agrandir"></button>
      </div>
      <p class="macos-titlebar-title">Connexion</p>
    </header>
    <div class="macos-window-body">
  <div class="login-shell">
    <aside class="login-brand-pane">
      <div class="login-logo-fit"><img src="<?= e(institution_logo_src()) ?>" class="login-logo" alt="Logo"></div>
      <h1 class="login-brand-title">Connexion</h1>
      <p class="login-brand-school mb-0"><?php foreach (institution_name_lines() as $index => $institutionLine): ?><?= $index > 0 ? '<br>' : '' ?><?= e($institutionLine) ?><?php endforeach; ?></p>
    </aside>
    <div class="card shadow-lg login-card">
      <div class="card-body p-5">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="login">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <label class="form-label" for="role">Se connecter en tant que</label>
          <?php
          $loginRoles = ['admin' => 'Administrateur', 'scolarite' => 'Scolarité', 'enseignant' => 'Enseignant', 'stage' => 'Service des stages', 'finance' => 'Service finance'];
          $loginRole = (string) ($_POST['role'] ?? $_GET['role'] ?? 'admin');
          if (!isset($loginRoles[$loginRole])) {
              $loginRole = 'admin';
          }
          ?>
          <select class="form-select form-select-lg mb-3" id="role" name="role" required><?php foreach ($loginRoles as $roleValue => $roleLabel): ?><option value="<?= e($roleValue) ?>"<?= $roleValue === $loginRole ? ' selected' : '' ?>><?= e($roleLabel) ?></option><?php endforeach; ?></select>
          <div id="cin-field" hidden><label class="form-label" id="cin-label" for="cin">CIN enseignant</label><input class="form-control form-control-lg mb-3" id="cin" name="cin" inputmode="numeric" maxlength="<?= (int) identity_rule('cin')['max_length'] ?>" pattern="<?= e(identity_pattern('cin')) ?>" disabled></div>
          <div id="username-field" hidden><label class="form-label" id="username-label" for="username">Identifiant</label><input class="form-control form-control-lg mb-3" id="username" name="username" disabled></div>
          <label class="form-label" for="password">Mot de passe</label>
          <div class="password-field mb-4">
            <input class="form-control form-control-lg" id="password" name="password" type="password" required autofocus autocomplete="current-password">
            <button type="button" class="password-toggle" data-password-toggle aria-label="Afficher le mot de passe" aria-pressed="false" title="Afficher le mot de passe">
              <svg class="password-icon-show" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="password-icon-hide" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" hidden><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.94 10.94 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
            </button>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">Se connecter</button>
        </form>
        <p class="small text-secondary text-center mt-3 mb-0">Accès personnel</p>
      </div>
    </div>
  </div>
    </div>
  </div>
  <button type="button" class="macos-restore-hint no-print" data-macos-restore>Restaurer la fenêtre</button>
  <script>
  (function () {
    var clock = document.querySelector('[data-clarity-clock]');
    if (!clock) return;
    function paint() {
      if (!document.body.classList.contains('theme-clarity')) return;
      var now = new Date();
      var day = now.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' });
      var time = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
      clock.textContent = day + '  ' + time;
      clock.dateTime = now.toISOString();
    }
    paint();
    setInterval(paint, 15000);
    new MutationObserver(paint).observe(document.body, { attributes: true, attributeFilter: ['class'] });
  })();
  </script>
  <script>
  (function () {
    const body = document.body, toggle = document.querySelector('[data-theme-toggle]'), styleSelect = document.querySelector('[data-style-select]');
    const role = document.querySelector('#role'), cinField = document.querySelector('#cin-field'), cin = document.querySelector('#cin'), cinLabel = document.querySelector('#cin-label'), usernameField = document.querySelector('#username-field'), username = document.querySelector('#username');
    function applyTheme(value) {
      const light = value === 'light';
      body.classList.toggle('theme-light', light); body.classList.toggle('theme-dark', !light);
      document.documentElement.setAttribute('data-bs-theme', light ? 'light' : 'dark');
      toggle.setAttribute('aria-label', light ? 'Activer le mode sombre' : 'Activer le mode clair');
      toggle.querySelector('[data-theme-icon]').textContent = light ? '☾' : '☀';
      toggle.querySelector('[data-theme-label]').textContent = light ? 'Sombre' : 'Clair';
    }
    function updateRole() {
      const cinLogin = role.value === 'enseignant' || role.value === 'stage', named = role.value === 'finance' || role.value === 'scolarite';
      cinField.hidden = !cinLogin; cin.disabled = !cinLogin; cin.required = cinLogin;
      cinLabel.textContent = role.value === 'stage' ? 'CIN du compte stage' : 'CIN enseignant';
      cin.maxLength = <?= (int) identity_rule('cin')['max_length'] ?>;
      cin.pattern = <?= json_encode(identity_pattern('cin'), JSON_THROW_ON_ERROR) ?>;
      cin.inputMode = 'numeric';
      usernameField.hidden = !named; username.disabled = !named; username.required = named;
      document.querySelector('#username-label').textContent = role.value === 'scolarite' ? 'Identifiant scolarité' : 'Identifiant finance';
      if (!cinLogin) cin.value = '';
      if (!named) username.value = '';
    }
    function normalizeStyle(value) {
      if (value === 'atlas' || value === 'alternate') return 'original';
      if (value === 'glass') return 'glass';
      if (value === 'macos' || value === 'mac' || value === 'mac-os') return 'macos';
      if (value === 'clarity' || value === 'mac-clair') return 'clarity';
      if (value === 'institut' || value === 'accueil') return 'institut';
      return 'original';
    }
    const themeOwner = document.documentElement.dataset.themeOwner || 'invite';
    const themeKey = 'app-theme:' + themeOwner;
    const styleKey = 'app-style:' + themeOwner;
    function applyStyle(value) {
      const style = normalizeStyle(value);
      if (localStorage.getItem(styleKey) === 'alternate' || localStorage.getItem(styleKey) === 'atlas') localStorage.setItem(styleKey, 'original');
      body.classList.remove('theme-alternate', 'theme-atlas');
      body.classList.toggle('theme-glass', style === 'glass');
      body.classList.toggle('theme-macos', style === 'macos');
      body.classList.toggle('theme-clarity', style === 'clarity');
      body.classList.toggle('theme-institut', style === 'institut');
      document.documentElement.dataset.appStyle = style;
      if (styleSelect && styleSelect.value !== style) styleSelect.value = style;
    }
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    applyStyle(localStorage.getItem(styleKey) || 'original');
    updateRole();
    role.addEventListener('change', updateRole);
    const password = document.querySelector('#password');
    const passwordToggle = document.querySelector('[data-password-toggle]');
    if (password && passwordToggle) {
      passwordToggle.addEventListener('click', function () {
        const visible = password.type === 'text';
        password.type = visible ? 'password' : 'text';
        passwordToggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
        passwordToggle.setAttribute('aria-label', visible ? 'Afficher le mot de passe' : 'Masquer le mot de passe');
        passwordToggle.title = visible ? 'Afficher le mot de passe' : 'Masquer le mot de passe';
        passwordToggle.querySelector('.password-icon-show').hidden = !visible;
        passwordToggle.querySelector('.password-icon-hide').hidden = visible;
        password.focus();
      });
    }
    toggle.addEventListener('click', function () { const next = body.classList.contains('theme-light') ? 'dark' : 'light'; localStorage.setItem(themeKey, next); applyTheme(next); });
    styleSelect.addEventListener('change', function () {
      const next = normalizeStyle(styleSelect.value);
      localStorage.setItem(styleKey, next);
      applyStyle(next);
    });
    const win = document.querySelector('[data-macos-window]');
    const restoreBtn = document.querySelector('[data-macos-restore]');
    function restoreWindow() {
      if (!win) return;
      win.classList.remove('is-minimized', 'is-closed', 'is-maximized');
    }
    if (restoreBtn) restoreBtn.addEventListener('click', restoreWindow);
    document.querySelectorAll('[data-macos-action]').forEach(function (btn) {
      btn.addEventListener('click', function (event) {
        event.preventDefault();
        if (!(body.classList.contains('theme-macos') || body.classList.contains('theme-clarity')) || !win) return;
        const action = btn.getAttribute('data-macos-action');
        if (action === 'close' || action === 'minimize') {
          win.classList.remove('is-maximized');
          win.classList.add('is-minimized');
        } else if (action === 'maximize') {
          win.classList.remove('is-minimized');
          win.classList.toggle('is-maximized');
        }
      });
    });
    const titlebar = document.querySelector('[data-macos-titlebar]');
    if (titlebar && win) {
      titlebar.addEventListener('dblclick', function () {
        if (!(body.classList.contains('theme-macos') || body.classList.contains('theme-clarity'))) return;
        win.classList.toggle('is-maximized');
      });
    }
  })();
  </script>
  <?php if ($error === 'Votre compte est suspendu. Contactez l’administrateur.'): ?>
  <script>
    if (window.top !== window.self) {
      window.top.location.replace('login.php?error=suspended');
    }
  </script>
  <?php endif; ?>
<script src="assets/motion.js?v=3" defer></script>
</body>
</html>

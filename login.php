<?php
require_once __DIR__ . '/config.php';
if (logged_in()) {
    header('Location: ' . (is_student() ? 'dashboard.php' : 'index.php'));
    exit;
}

$error = (($_GET['error'] ?? '') === 'stage_setup')
    ? 'Le compte stage est temporairement indisponible. Contactez l’administrateur pour appliquer la migration des comptes stage.'
    : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = '';
    $role = (string) ($_POST['role'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if ($role === 'admin' && password_verify($password, admin_password_hash())) {
        session_regenerate_id(true);
        $_SESSION['user_role'] = 'admin';
        unset($_SESSION['teacher_id'], $_SESSION['stage_id'], $_SESSION['stage_auth_generation']);
        unset($_SESSION['finance_id'], $_SESSION['finance_auth_generation'], $_SESSION['id_etudiant']);
        header('Location: index.php');
        exit;
    }
    if ($role === 'enseignant') {
        ensure_teacher_active(db());
        $stmt = db()->prepare('SELECT id_enseignant, password, active FROM enseignant WHERE cin = ? LIMIT 1');
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
            session_regenerate_id(true);
            $_SESSION['user_role'] = 'enseignant';
            $_SESSION['teacher_id'] = (int) $teacher['id_enseignant'];
            unset($_SESSION['stage_id'], $_SESSION['stage_auth_generation']);
            unset($_SESSION['finance_id'], $_SESSION['finance_auth_generation'], $_SESSION['id_etudiant']);
            header('Location: index.php');
            exit;
        }
    }
    if ($role === 'stage') {
        if (!stage_accounts_table_exists()) {
            $error = 'Le compte stage est temporairement indisponible. Contactez l’administrateur pour appliquer stage_accounts_migration.sql sur la base utilisée par cette application.';
        } else {
            $stmt = db()->prepare('SELECT id_stage, password, auth_generation FROM stage_user WHERE cin = ? AND active = 1 LIMIT 1');
            $stmt->execute([trim((string) ($_POST['cin'] ?? ''))]);
            $stage = $stmt->fetch();
            if ($stage && password_verify($password, (string) $stage['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_role'] = 'stage';
                $_SESSION['stage_id'] = (int) $stage['id_stage'];
                $_SESSION['stage_auth_generation'] = (int) $stage['auth_generation'];
                unset($_SESSION['teacher_id'], $_SESSION['finance_id'], $_SESSION['finance_auth_generation'], $_SESSION['id_etudiant']);
                header('Location: stage_portal.php');
                exit;
            }
        }
    }
    if ($role === 'finance') {
        $stmt = db()->prepare('SELECT id_finance, password, auth_generation FROM finance_user WHERE username = ? AND active = 1 LIMIT 1');
        $stmt->execute([trim((string) ($_POST['username'] ?? ''))]);
        $finance = $stmt->fetch();
        if ($finance && password_verify($password, (string) $finance['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_role'] = 'finance';
            $_SESSION['finance_id'] = (int) $finance['id_finance'];
            $_SESSION['finance_auth_generation'] = (int) $finance['auth_generation'];
            unset($_SESSION['teacher_id'], $_SESSION['stage_id'], $_SESSION['stage_auth_generation'], $_SESSION['id_etudiant']);
            header('Location: index.php');
            exit;
        }
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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Connexion</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/style.css?v=75" rel="stylesheet">
  <link href="assets/macos.css?v=32" rel="stylesheet">
  <script>
  (function () {
    try {
      var theme = localStorage.getItem('app-theme') || 'dark';
      var style = localStorage.getItem('app-style') || 'original';
      if (style === 'alternate') { style = 'atlas'; localStorage.setItem('app-style', 'atlas'); }
      if (style !== 'atlas' && style !== 'glass' && style !== 'macos') style = 'original';
      document.documentElement.dataset.appTheme = theme === 'light' ? 'light' : 'dark';
      document.documentElement.dataset.appStyle = style;
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
    body.classList.remove('theme-alternate');
    body.classList.toggle('theme-atlas', style === 'atlas');
    body.classList.toggle('theme-glass', style === 'glass');
    body.classList.toggle('theme-macos', style === 'macos');
    document.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
  })();
  </script>
  <div class="login-theme-controls">
    <label class="theme-style-select-wrap">
      <span class="visually-hidden">Thème visuel</span>
      <select class="theme-style-select" data-style-select aria-label="Choisir le thème" title="Choisir le thème">
        <option value="original">Original</option>
        <option value="atlas">Atlas</option>
        <option value="glass">Glass</option>
        <option value="macos">Mac OS</option>
      </select>
    </label>
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
      <img src="<?= e(institution_logo_src()) ?>" class="login-logo" alt="Logo">
      <h1 class="login-brand-title">Connexion</h1>
      <p class="login-brand-school mb-0"><?php foreach (institution_name_lines() as $index => $institutionLine): ?><?= $index > 0 ? '<br>' : '' ?><?= e($institutionLine) ?><?php endforeach; ?></p>
    </aside>
    <div class="card shadow-lg login-card">
      <div class="card-body p-5">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="login.php">
          <label class="form-label" for="role">Se connecter en tant que</label>
          <select class="form-select form-select-lg mb-3" id="role" name="role" required><option value="admin">Administrateur</option><option value="enseignant">Enseignant</option><option value="stage">Service des stages</option><option value="finance">Service finance</option></select>
          <div id="cin-field" hidden><label class="form-label" id="cin-label" for="cin">CIN enseignant</label><input class="form-control form-control-lg mb-3" id="cin" name="cin" inputmode="numeric" maxlength="8" pattern="[0-9]{8}" disabled></div>
          <div id="username-field" hidden><label class="form-label" for="username">Identifiant finance</label><input class="form-control form-control-lg mb-3" id="username" name="username" disabled></div>
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
      const cinLogin = role.value === 'enseignant' || role.value === 'stage', finance = role.value === 'finance';
      cinField.hidden = !cinLogin; cin.disabled = !cinLogin; cin.required = cinLogin;
      cinLabel.textContent = role.value === 'stage' ? 'CIN du compte stage' : 'CIN enseignant';
      cin.maxLength = 8;
      cin.pattern = '[0-9]{8}';
      cin.inputMode = 'numeric';
      usernameField.hidden = !finance; username.disabled = !finance; username.required = finance;
      if (!cinLogin) cin.value = '';
      if (!finance) username.value = '';
    }
    function normalizeStyle(value) {
      if (value === 'atlas' || value === 'alternate') return 'atlas';
      if (value === 'glass') return 'glass';
      if (value === 'macos' || value === 'mac' || value === 'mac-os') return 'macos';
      return 'original';
    }
    function applyStyle(value) {
      const style = normalizeStyle(value);
      if (localStorage.getItem('app-style') === 'alternate') localStorage.setItem('app-style', 'atlas');
      body.classList.remove('theme-alternate');
      body.classList.toggle('theme-atlas', style === 'atlas');
      body.classList.toggle('theme-glass', style === 'glass');
      body.classList.toggle('theme-macos', style === 'macos');
      document.documentElement.dataset.appStyle = style;
      if (styleSelect && styleSelect.value !== style) styleSelect.value = style;
    }
    applyTheme(localStorage.getItem('app-theme') || 'dark');
    applyStyle(localStorage.getItem('app-style') || 'original');
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
    toggle.addEventListener('click', function () { const next = body.classList.contains('theme-light') ? 'dark' : 'light'; localStorage.setItem('app-theme', next); applyTheme(next); });
    styleSelect.addEventListener('change', function () {
      const next = normalizeStyle(styleSelect.value);
      localStorage.setItem('app-style', next);
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
        if (!body.classList.contains('theme-macos') || !win) return;
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
        if (!body.classList.contains('theme-macos')) return;
        win.classList.toggle('is-maximized');
      });
    }
  })();
  </script>
</body>
</html>

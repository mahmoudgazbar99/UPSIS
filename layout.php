<?php
/*
require_once __DIR__ . '/config.php';
function page_head(string $title, string $active = ''): void { ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | École de Santé Gabès</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"></head>
<body class="bg-dark text-light"><nav class="navbar navbar-expand-lg navbar-dark border-bottom border-secondary sticky-top no-print"><div class="container"><a class="navbar-brand fw-bold" href="index.php">École de Santé Gabès</a><button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="nav"><ul class="navbar-nav ms-auto align-items-lg-center"><li class="nav-item"><a class="nav-link <?= $active==='home'?'active':'' ?>" href="index.php">Tableau de bord</a></li><li class="nav-item"><a class="nav-link <?= $active==='register'?'active':'' ?>" href="inscription.php">Inscrire</a></li><li class="nav-item"><a class="nav-link <?= $active==='grades'?'active':'' ?>" href="gestion_notes.php">Notes</a></li><li class="nav-item"><a class="nav-link <?= $active==='certificates'?'active':'' ?>" href="attestation_inscription.php">Attestations</a></li><li class="nav-item ms-lg-2"><a class="btn btn-outline-light btn-sm" href="logout.php">Déconnexion</a></li></ul></div></div></nav><main class="container py-5">
<?php }
function page_foot(): void { ?>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
<?php }*/

require_once __DIR__ . '/config.php';

function app_is_embedded(): bool {
    return ($_GET['embed'] ?? '') === '1'
        || (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe')
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
}

function app_user_identity(): array {
    $name = 'Session';
    $role = 'Utilisateur';
    if (logged_in()) {
        if (is_admin()) {
            $name = 'Administration';
            $role = 'Administrateur';
        } elseif (is_teacher()) {
            $role = 'Enseignant';
            try {
                $query = db()->prepare('SELECT nom, prenom FROM enseignant WHERE id_enseignant = ? LIMIT 1');
                $query->execute([current_teacher_id()]);
                $row = $query->fetch() ?: [];
                $name = trim((string) ($row['prenom'] ?? '') . ' ' . (string) ($row['nom'] ?? '')) ?: 'Enseignant';
            } catch (Throwable $error) {
                $name = 'Enseignant';
            }
        } elseif (is_finance()) {
            $role = 'Service finance';
            try {
                $query = db()->prepare('SELECT username FROM finance_user WHERE id_finance = ? LIMIT 1');
                $query->execute([(int) ($_SESSION['finance_id'] ?? 0)]);
                $name = (string) ($query->fetchColumn() ?: 'Finance');
            } catch (Throwable $error) {
                $name = 'Finance';
            }
        } elseif (is_stage()) {
            $name = 'Service des stages';
            $role = 'Stages';
        }
    }
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = strtoupper(substr((string) ($parts[0] ?? 'U'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1));
    return ['name' => $name, 'role' => $role, 'initials' => $initials !== '' ? $initials : 'U'];
}

function macos_dock(): void {
    if (app_is_embedded() || !logged_in() || is_student()) {
        return;
    }
    $icon = static function (string $path): string {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    };
    ?>
        <nav class="macos-dock no-print" data-macos-dock aria-label="Raccourcis">
            <a class="macos-dock-item" href="index.php" data-dock="home" title="Tableau de bord" aria-label="Tableau de bord"><?= $icon('<path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z"/>') ?></a>
            <?php if (is_admin()): ?>
            <a class="macos-dock-item" href="inscription.php" data-dock="enroll" title="Inscrire" aria-label="Inscrire"><?= $icon('<path d="M12 5v14M5 12h14"/><circle cx="12" cy="12" r="9"/>') ?></a>
            <?php endif; ?>
            <?php if (is_admin() || is_teacher()): ?>
            <a class="macos-dock-item" href="gestion_notes.php" data-dock="grades" title="Notes" aria-label="Notes"><?= $icon('<path d="M7 4h10v16H7z"/><path d="M10 8h4M10 12h4M10 16h2"/>') ?></a>
            <a class="macos-dock-item" href="presence_etudiants.php" data-dock="presence" title="Présence" aria-label="Présence"><?= $icon('<path d="M8 12.5 10.5 15 16 9"/><rect x="4" y="4" width="16" height="16" rx="3"/>') ?></a>
            <?php endif; ?>
            <?php if (is_finance()): ?>
            <a class="macos-dock-item" href="finance.php" data-dock="finance" title="Finance" aria-label="Suivi des paiements"><?= $icon('<circle cx="12" cy="12" r="8"/><path d="M12 8v8M9.5 10.5c.7-1 4.3-1 5 1s-1.5 2.5-2.5 2.5"/>') ?></a>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <a class="macos-dock-item" href="demandes_presence.php" data-dock="attestation" title="Demandes de présence" aria-label="Demandes de présence"><?= $icon('<path d="M7 4h10a2 2 0 0 1 2 2v14l-7-3-7 3V6a2 2 0 0 1 2-2z"/>') ?></a>
            <span class="macos-dock-sep" aria-hidden="true"></span>
            <a class="macos-dock-item" href="changer_mot_de_passe.php" data-dock="settings" title="Réglages" aria-label="Réglages"><?= $icon('<circle cx="12" cy="12" r="3"/><path d="M12 3v2M12 19v2M5 12H3M21 12h-2M6.2 6.2l1.4 1.4M16.4 16.4l1.4 1.4M17.8 6.2l-1.4 1.4M7.6 16.4 6.2 17.8"/>') ?></a>
            <?php endif; ?>
        </nav>
        <button type="button" class="macos-restore-hint no-print" data-macos-restore>Restaurer la fenêtre</button>
        <div class="macos-command-overlay no-print" data-macos-command hidden>
            <div class="macos-command-sheet" role="dialog" aria-modal="true" aria-label="Recherche">
                <input type="search" data-macos-command-input placeholder="Rechercher un module…" aria-label="Rechercher un module">
                <ul data-macos-command-list></ul>
            </div>
        </div>
    <?php
}

function page_head(string $title, string $active = ''): void { 
    $embedded = app_is_embedded();
    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title><?= e($title) ?> | École de Santé Gabès</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="assets/style.css?v=73" rel="stylesheet">
        <link href="assets/macos.css?v=28" rel="stylesheet">
        <script>
        (function () {
            try {
                var theme = localStorage.getItem('app-theme') || 'dark';
                var style = localStorage.getItem('app-style') || 'original';
                if (style === 'alternate') style = 'atlas';
                if (style !== 'atlas' && style !== 'glass' && style !== 'macos') style = 'original';
                document.documentElement.classList.add('theme-boot');
                document.documentElement.dataset.appTheme = theme === 'light' ? 'light' : 'dark';
                document.documentElement.dataset.appStyle = style;
                document.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
                if (localStorage.getItem('app-style') === 'alternate') localStorage.setItem('app-style', 'atlas');
            } catch (error) {}
        })();
        </script>
    </head>
    <body class="app-body theme-dark<?= $embedded ? ' embedded-view' : '' ?>">
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
        <?php if (!$embedded):
            $identity = logged_in() ? app_user_identity() : ['name' => '', 'role' => '', 'initials' => ''];
            $notifyCount = 0;
            if (logged_in() && is_admin()) {
                try {
                    $notifyCount = attestation_pending_count(db());
                    $notifyCount += (int) (presence_elimination_alerts(db())['counts']['total'] ?? 0);
                } catch (Throwable $error) {
                    $notifyCount = 0;
                }
            }
        ?>
        <nav class="navbar navbar-expand-md border-bottom sticky-top no-print app-chrome">
            <div class="container app-chrome-inner">
                <div class="macos-toolbar-lead">
                    <div class="macos-traffic macos-toolbar-traffic macos-only-control" aria-label="Contrôles de fenêtre">
                        <button type="button" class="macos-dot macos-dot-close" data-macos-action="close" aria-label="Fermer la fenêtre" title="Fermer"></button>
                        <button type="button" class="macos-dot macos-dot-min" data-macos-action="minimize" aria-label="Réduire la fenêtre" title="Réduire"></button>
                        <button type="button" class="macos-dot macos-dot-max" data-macos-action="maximize" aria-label="Agrandir la fenêtre" title="Agrandir"></button>
                    </div>
                    <a class="navbar-brand navbar-institution" href="index.php" title="Tableau de bord">
                        <img src="images/no_bg_logo.png?v=2" alt="Logo" class="navbar-logo">
                        <span class="navbar-institution-name"><span>Université privée des sciences infirmières et de santé</span><span>de Gabès</span></span>
                    </a>
                </div>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Ouvrir le menu">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="nav">
                    <div class="macos-toolbar-search macos-only-control">
                        <button type="button" class="macos-search-hit" data-macos-search>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16.5 20.5 21"/></svg>
                            <span>Rechercher une page, un étudiant, un enseignant…</span>
                            <kbd><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6a3 3 0 1 0-3-3v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V3a3 3 0 1 0-3 3z"/></svg>K</kbd>
                        </button>
                    </div>
                    <ul class="navbar-nav ms-auto align-items-lg-center app-chrome-actions">
                        <?php if (is_teacher()): ?>
                            <li class="nav-item me-lg-3 app-chrome-welcome">
                                <span class="navbar-text teacher-welcome">Bienvenue <?= e($identity['name']) ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if (logged_in()): ?>
                        <li class="nav-item macos-only-control">
                            <div class="dropdown">
                                <button class="macos-toolbar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications" title="Notifications">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 9a6 6 0 1 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                                    <?php if ($notifyCount > 0): ?><span class="macos-notify-dot" aria-hidden="true"></span><?php endif; ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <?php if (is_admin()): ?>
                                    <li><a class="dropdown-item" href="demandes_presence.php">Demandes de présence</a></li>
                                    <li><a class="dropdown-item" href="alertes_elimination.php">Alertes d’élimination</a></li>
                                    <?php else: ?>
                                    <li><span class="dropdown-item-text">Aucune notification</span></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item macos-bar-theme-select">
                            <label class="theme-style-select-wrap">
                                <span class="visually-hidden">Thème visuel</span>
                                <select class="theme-style-select" data-style-select aria-label="Choisir le thème" title="Choisir le thème">
                                    <option value="original">Original</option>
                                    <option value="atlas">Atlas</option>
                                    <option value="glass">Glass</option>
                                    <option value="macos">Mac OS</option>
                                </select>
                            </label>
                        </li>
                        <li class="nav-item">
                            <button type="button" class="theme-toggle" data-theme-toggle aria-label="Activer le mode clair" title="Activer le mode clair">
                                <span class="theme-toggle-track" aria-hidden="true">
                                    <svg class="theme-toggle-icon theme-toggle-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16.6 13.2A6.6 6.6 0 1 1 10.8 5.4 5.2 5.2 0 0 0 16.6 13.2z"/></svg>
                                    <svg class="theme-toggle-icon theme-toggle-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M5 12H3M21 12h-2M6.2 6.2l1.4 1.4M16.4 16.4l1.4 1.4M17.8 6.2l-1.4 1.4M7.6 16.4 6.2 17.8"/></svg>
                                    <span class="theme-toggle-knob"></span>
                                </span>
                                <span data-theme-icon class="visually-hidden">☀</span>
                                <span data-theme-label class="visually-hidden">Clair</span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <div class="dropdown" data-settings-dropdown>
                                <button class="btn btn-outline-light btn-sm settings-button" type="button" data-bs-toggle="dropdown" data-bs-popper="static" aria-expanded="false" aria-label="Ouvrir les réglages" title="Réglages">
                                    <span aria-hidden="true">⚙</span>
                                    <svg class="macos-tool-icon macos-icon-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.2 2h-.4a2 2 0 0 0-2 2v.2a2 2 0 0 1-1 1.7l-.4.3a2 2 0 0 1-2 0l-.2-.1a2 2 0 0 0-2.7.7l-.2.4a2 2 0 0 0 .7 2.7l.2.1a2 2 0 0 1 1 1.7v.5a2 2 0 0 1-1 1.7l-.2.1a2 2 0 0 0-.7 2.7l.2.4a2 2 0 0 0 2.7.7l.2-.1a2 2 0 0 1 2 0l.4.3a2 2 0 0 1 1 1.7V20a2 2 0 0 0 2 2h.4a2 2 0 0 0 2-2v-.2a2 2 0 0 1 1-1.7l.4-.3a2 2 0 0 1 2 0l.2.1a2 2 0 0 0 2.7-.7l.2-.4a2 2 0 0 0-.7-2.7l-.2-.1a2 2 0 0 1-1-1.7v-.5a2 2 0 0 1 1-1.7l.2-.1a2 2 0 0 0 .7-2.7l-.2-.4a2 2 0 0 0-2.7-.7l-.2.1a2 2 0 0 1-2 0l-.4-.3a2 2 0 0 1-1-1.7V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <span class="visually-hidden">Réglages</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li class="macos-only-control px-3 py-2">
                                        <label class="small mb-1 d-block" for="macos-style-select">Apparence</label>
                                        <select class="theme-style-select" id="macos-style-select" data-style-select aria-label="Choisir le thème">
                                            <option value="original">Original</option>
                                            <option value="atlas">Atlas</option>
                                            <option value="glass">Glass</option>
                                            <option value="macos">Mac OS</option>
                                        </select>
                                    </li>
                                    <li class="macos-only-control"><hr class="dropdown-divider"></li>
                                    <?php if (is_admin()): ?><li><a class="dropdown-item" href="changer_mot_de_passe.php">Changer le mot de passe</a></li><li><hr class="dropdown-divider"></li><?php endif; ?>
                                    <li><a class="dropdown-item text-danger" href="logout.php">Déconnexion</a></li>
                                </ul>
                            </div>
                        </li>
                        <?php if ($identity['name'] !== ''): ?>
                        <li class="nav-item macos-only-control">
                            <div class="dropdown">
                                <button class="macos-profile" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e($identity['name'] . ' · ' . $identity['role']) ?>">
                                    <span class="macos-profile-avatar" aria-hidden="true"><?= e($identity['initials']) ?></span>
                                    <span class="macos-profile-copy">
                                        <strong><?= e($identity['name']) ?></strong>
                                        <small><?= e($identity['role']) ?></small>
                                    </span>
                                    <svg class="macos-profile-caret" viewBox="0 0 12 8" aria-hidden="true"><path d="M2 2.2 6 6.1 10 2.2" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <?php if (is_admin()): ?><li><a class="dropdown-item" href="changer_mot_de_passe.php">Changer le mot de passe</a></li><?php endif; ?>
                                    <li><a class="dropdown-item text-danger" href="logout.php">Déconnexion</a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </nav>
        <?php endif; ?>
        <div class="macos-window" data-macos-window>
            <header class="macos-titlebar no-print" data-macos-titlebar>
                <div class="macos-traffic">
                    <button type="button" class="macos-dot macos-dot-close" data-macos-action="close" aria-label="Fermer la fenêtre" title="Fermer"></button>
                    <button type="button" class="macos-dot macos-dot-min" data-macos-action="minimize" aria-label="Réduire la fenêtre" title="Réduire"></button>
                    <button type="button" class="macos-dot macos-dot-max" data-macos-action="maximize" aria-label="Agrandir la fenêtre" title="Agrandir"></button>
                </div>
                <p class="macos-titlebar-title" data-macos-title><?= e($title) ?></p>
            </header>
            <div class="macos-window-body">
        <main class="container app-sheet <?= $embedded ? 'py-3' : 'py-5' ?>">
        <?php flash_show(); ?>
    <?php 
}

function page_foot(): void { 
    ?>
        </main>
            </div>
        </div>
        <?php macos_dock(); ?>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
        (function () {
            const body = document.body;
            const toggle = document.querySelector('[data-theme-toggle]');
            const styleSelects = document.querySelectorAll('[data-style-select]');
            const navToggle = document.querySelector('.app-chrome .navbar-toggler');
            const navCollapse = document.querySelector('#nav');
            const savedTheme = localStorage.getItem('app-theme') || 'dark';
            const savedStyle = localStorage.getItem('app-style') || 'original';

            function applyTheme(theme) {
                const light = theme === 'light';
                body.classList.toggle('theme-light', light);
                body.classList.toggle('theme-dark', !light);
                document.documentElement.setAttribute('data-bs-theme', light ? 'light' : 'dark');
                if (toggle) {
                    const themeLabel = light ? 'Activer le mode sombre' : 'Activer le mode clair';
                    toggle.setAttribute('aria-label', themeLabel);
                    toggle.title = themeLabel;
                    toggle.querySelector('[data-theme-icon]').textContent = light ? '☾' : '☀';
                    toggle.querySelector('[data-theme-label]').textContent = light ? 'Sombre' : 'Clair';
                }
            }

            applyTheme(savedTheme);
            function normalizeStyle(style) {
                if (style === 'atlas' || style === 'alternate') return 'atlas';
                if (style === 'glass') return 'glass';
                if (style === 'macos' || style === 'mac' || style === 'mac-os') return 'macos';
                return 'original';
            }
            function syncSettingsDropdownDirection() {
                const settingsDrop = document.querySelector('[data-settings-dropdown]');
                if (!settingsDrop) return;
                settingsDrop.classList.remove('dropup');
            }

            function applyStyle(style) {
                const normalized = normalizeStyle(style);
                if (localStorage.getItem('app-style') === 'alternate') {
                    localStorage.setItem('app-style', 'atlas');
                }
                body.classList.remove('theme-alternate');
                body.classList.toggle('theme-atlas', normalized === 'atlas');
                body.classList.toggle('theme-glass', normalized === 'glass');
                body.classList.toggle('theme-macos', normalized === 'macos');
                document.documentElement.dataset.appStyle = normalized;
                styleSelects.forEach(function (el) {
                    if (el.value !== normalized) el.value = normalized;
                });
                syncSettingsDropdownDirection();
            }

            function currentStyle() {
                if (body.classList.contains('theme-macos')) return 'macos';
                if (body.classList.contains('theme-glass')) return 'glass';
                if (body.classList.contains('theme-atlas')) return 'atlas';
                return 'original';
            }

            function syncThemeToFrames(theme, style) {
                document.querySelectorAll('iframe').forEach(function (frame) {
                    try {
                        const frameBody = frame.contentDocument && frame.contentDocument.body;
                        if (frameBody) {
                            frameBody.classList.toggle('theme-light', theme === 'light');
                            frameBody.classList.toggle('theme-dark', theme !== 'light');
                            frameBody.classList.remove('theme-alternate');
                            frameBody.classList.toggle('theme-atlas', style === 'atlas');
                            frameBody.classList.toggle('theme-glass', style === 'glass');
                            frameBody.classList.toggle('theme-macos', style === 'macos');
                        }
                        if (frame.contentDocument && frame.contentDocument.documentElement) {
                            frame.contentDocument.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
                            frame.contentDocument.documentElement.dataset.appStyle = style;
                            frame.contentDocument.documentElement.dataset.appTheme = theme;
                        }
                        if (frame.contentWindow) {
                            frame.contentWindow.postMessage({
                                type: 'app-theme-sync',
                                theme: theme,
                                style: style
                            }, window.location.origin);
                        }
                    } catch (error) {
                        /* Cross-origin frames are ignored. */
                    }
                });
            }

            function broadcastAppearance() {
                const theme = body.classList.contains('theme-light') ? 'light' : 'dark';
                const style = currentStyle();
                syncThemeToFrames(theme, style);
            }

            applyStyle(savedStyle);
            function syncBrowserFullscreen() {
                const fullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement)
                    || (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches);
                document.documentElement.classList.toggle('is-browser-fullscreen', fullscreen);
            }
            syncBrowserFullscreen();
            document.addEventListener('fullscreenchange', syncBrowserFullscreen);
            document.addEventListener('webkitfullscreenchange', syncBrowserFullscreen);
            window.addEventListener('resize', syncBrowserFullscreen);
            if (navToggle && navCollapse) {
                navCollapse.addEventListener('shown.bs.collapse', function () {
                    navToggle.setAttribute('aria-label', 'Fermer le menu');
                });
                navCollapse.addEventListener('hidden.bs.collapse', function () {
                    navToggle.setAttribute('aria-label', 'Ouvrir le menu');
                });
            }
            window.addEventListener('resize', syncSettingsDropdownDirection);
            window.addEventListener('message', function (event) {
                if (event.origin !== window.location.origin || !event.data || event.data.type !== 'app-theme-sync') return;
                if (event.data.theme) applyTheme(event.data.theme);
                if (event.data.style) applyStyle(event.data.style);
            });
            window.addEventListener('storage', function (event) {
                if (event.key === 'app-theme' && event.newValue) applyTheme(event.newValue);
                if (event.key === 'app-style' && event.newValue) applyStyle(event.newValue);
            });
            if (toggle) {
                toggle.addEventListener('click', function () {
                    const nextTheme = body.classList.contains('theme-light') ? 'dark' : 'light';
                    localStorage.setItem('app-theme', nextTheme);
                    applyTheme(nextTheme);
                    broadcastAppearance();
                });
            }
            styleSelects.forEach(function (el) {
                el.addEventListener('change', function () {
                    const nextStyle = normalizeStyle(el.value);
                    localStorage.setItem('app-style', nextStyle);
                    applyStyle(nextStyle);
                    broadcastAppearance();
                });
            });
            if (!body.classList.contains('embedded-view')) {
                document.querySelectorAll('iframe').forEach(function (frame) {
                    frame.addEventListener('load', broadcastAppearance);
                });
            }

            if (body.classList.contains('embedded-view')) {
                function preserveEmbeddedView(form) {
                    let marker = form.querySelector('input[name="embed"]');
                    if (!marker) {
                        marker = document.createElement('input');
                        marker.type = 'hidden';
                        marker.name = 'embed';
                        form.appendChild(marker);
                    }
                    marker.value = '1';
                }

                document.querySelectorAll('form').forEach(preserveEmbeddedView);
                document.addEventListener('submit', function (event) {
                    preserveEmbeddedView(event.target);
                }, true);

                function preserveEmbeddedLink(link) {
                    if (link.hasAttribute('download') || !link.href) {
                        return;
                    }

                    const destination = new URL(link.href, window.location.href);
                    if (destination.origin !== window.location.origin) {
                        return;
                    }

                    destination.searchParams.set('embed', '1');
                    link.href = destination.href;
                }

                document.querySelectorAll('a[href]').forEach(preserveEmbeddedLink);
                document.addEventListener('click', function (event) {
                    const link = event.target.closest('a[href]');
                    if (link) {
                        preserveEmbeddedLink(link);
                    }
                }, true);
            }

            function enhanceTablesForMobile() {
                var mobile = window.matchMedia('(max-width: 767.98px)').matches;
                document.documentElement.classList.toggle('is-mobile-tables', mobile);

                document.querySelectorAll('.table-responsive > table.table, table.table.presence-table, table.table.elimination-table').forEach(function (table) {
                    table.classList.toggle('mobile-card-table', mobile);

                    var headers = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
                        return (th.textContent || '').replace(/\s+/g, ' ').trim();
                    });

                    table.querySelectorAll('tbody tr').forEach(function (row) {
                        var cells = row.querySelectorAll(':scope > td');
                        if (cells.length === 1 && cells[0].hasAttribute('colspan')) {
                            row.classList.add('table-empty-row');
                            return;
                        }

                        cells.forEach(function (cell, index) {
                            if (!cell.getAttribute('data-label') && headers[index]) {
                                cell.setAttribute('data-label', headers[index]);
                            }

                            var value = cell.querySelector(':scope > .table-cell-value');
                            if (!value) {
                                value = document.createElement('div');
                                value.className = 'table-cell-value';
                                while (cell.firstChild) {
                                    value.appendChild(cell.firstChild);
                                }
                                cell.appendChild(value);
                            }

                            var hasButtons = value.querySelector('.btn, button, .presence-status-toggle');
                            var checkboxOnly = value.querySelector('input[type="checkbox"]')
                                && !value.querySelector('.btn')
                                && !(value.textContent || '').trim();

                            cell.classList.toggle('table-actions-cell', !!(hasButtons && !checkboxOnly));
                            value.querySelectorAll('.table-action-grid').forEach(function (grid) {
                                grid.classList.remove('table-action-grid');
                            });
                            if (hasButtons && !checkboxOnly) {
                                var buttons = Array.prototype.slice.call(value.querySelectorAll('.btn'));
                                if (buttons.length) {
                                    var topChildren = buttons.map(function (button) {
                                        var child = button;
                                        while (child.parentElement && child.parentElement !== value) {
                                            child = child.parentElement;
                                        }
                                        return child;
                                    });
                                    var singleOwner = topChildren.every(function (child) {
                                        return child === topChildren[0];
                                    });
                                    var gridOwner = singleOwner && !topChildren[0].classList.contains('btn')
                                        ? topChildren[0]
                                        : value;
                                    gridOwner.classList.add('table-action-grid');
                                }
                            }

                            var label = (headers[index] || '').toLowerCase();
                            var isPrimary = label.indexOf('étudiant') !== -1
                                || label.indexOf('etudiant') !== -1
                                || (label.indexOf('nom') !== -1 && label.indexOf('enseigne') === -1)
                                || (!!value.querySelector('strong') && !hasButtons && index <= 1);
                            cell.classList.toggle('table-primary-cell', isPrimary);
                        });
                    });
                });
            }

            enhanceTablesForMobile();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', enhanceTablesForMobile);
            }
            window.addEventListener('resize', enhanceTablesForMobile);
            window.matchMedia('(max-width: 767.98px)').addEventListener('change', enhanceTablesForMobile);

            function resizeDashboardFrame(frame, scrollPanel) {
                try {
                    frame.style.height = '100%';
                    frame.style.minHeight = '0';
                    frame.style.overflow = 'auto';
                    frame.removeAttribute('scrolling');
                    var frameDocument = frame.contentDocument;
                    if (!frameDocument) return;
                    var frameRoot = frameDocument.documentElement;
                    var frameBody = frameDocument.body;
                    if (frameRoot) {
                        frameRoot.style.setProperty('height', '100%');
                        frameRoot.style.setProperty('overflow', 'hidden');
                    }
                    if (frameBody) {
                        frameBody.style.setProperty('height', '100%');
                        frameBody.style.setProperty('min-height', '100%');
                        frameBody.style.setProperty('overflow-x', 'hidden');
                        frameBody.style.setProperty('overflow-y', 'auto');
                        frameBody.style.setProperty('overscroll-behavior', 'contain');
                        frameBody.style.setProperty('-webkit-overflow-scrolling', 'touch');
                    }
                    if (frame._dashboardResizeObserver) {
                        frame._dashboardResizeObserver.disconnect();
                        frame._dashboardResizeObserver = null;
                    }
                    if (frame._wheelRelayDoc !== frameDocument) {
                        frame._wheelRelayDoc = frameDocument;
                        frameDocument.addEventListener('wheel', function (event) {
                            var scroller = frameDocument.body || frameDocument.documentElement;
                            if (!scroller) return;
                            var goingUp = event.deltaY < 0;
                            var atTop = scroller.scrollTop <= 0;
                            var atBottom = scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 1;
                            if ((goingUp && !atTop) || (!goingUp && !atBottom)) return;
                            var panel = frame.closest('.dashboard-content-panel');
                            if (panel && panel.scrollHeight > panel.clientHeight + 1) {
                                panel.scrollTop += event.deltaY;
                                return;
                            }
                            window.scrollBy(0, event.deltaY);
                        }, { passive: true });
                    }
                    if (scrollPanel) {
                        var panel = frame.closest('.dashboard-content-panel');
                        if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                } catch (error) {
                    frame.style.height = '100%';
                    frame.style.minHeight = '70vh';
                    frame.removeAttribute('scrolling');
                }
            }

            document.querySelectorAll('.dashboard-content-panel iframe').forEach(function (frame) {
                frame.addEventListener('load', function () {
                    window.requestAnimationFrame(function () {
                        resizeDashboardFrame(frame, true);
                    });
                });
                if (frame.contentDocument && frame.contentDocument.readyState === 'complete') {
                    resizeDashboardFrame(frame, false);
                }
            });
            window.addEventListener('resize', function () {
                document.querySelectorAll('.dashboard-content-panel iframe').forEach(function (frame) {
                    resizeDashboardFrame(frame, false);
                });
            });
        })();

        (function () {
            const body = document.body;
            const win = document.querySelector('[data-macos-window]');
            const titlebar = document.querySelector('[data-macos-titlebar]');
            const titleEl = document.querySelector('[data-macos-title]');
            const command = document.querySelector('[data-macos-command]');
            const commandInput = document.querySelector('[data-macos-command-input]');
            const commandList = document.querySelector('[data-macos-command-list]');
            const restoreBtn = document.querySelector('[data-macos-restore]');
            const defaultTitle = titleEl ? titleEl.textContent : '';
            let drag = null;

            function isMacos() {
                return body.classList.contains('theme-macos');
            }

            function clearWindowInline() {
                if (!win) return;
                win.style.left = '';
                win.style.top = '';
                win.style.position = '';
                win.style.width = '';
            }

            function restoreWindow() {
                if (!win) return;
                win.classList.remove('is-minimized', 'is-closed', 'is-dragging', 'is-maximized');
                clearWindowInline();
            }

            function setTitle(text) {
                if (titleEl && text) titleEl.textContent = text;
            }

            document.querySelectorAll('[data-macos-action]').forEach(function (btn) {
                btn.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (!isMacos() || !win) return;
                    const action = btn.getAttribute('data-macos-action');
                    if (action === 'close') {
                        const home = document.querySelector('[data-dashboard-home]');
                        const frame = document.querySelector('.dashboard-content-panel iframe');
                        if (home && frame && !frame.hidden) {
                            home.click();
                            setTitle(defaultTitle);
                            return;
                        }
                        if (!document.querySelector('.dashboard-workspace') && window.history.length > 1) {
                            window.history.back();
                            return;
                        }
                        win.classList.remove('is-maximized', 'is-minimized');
                        win.classList.add('is-closed');
                    } else if (action === 'minimize') {
                        win.classList.remove('is-maximized');
                        clearWindowInline();
                        win.classList.toggle('is-minimized');
                    } else if (action === 'maximize') {
                        win.classList.remove('is-minimized', 'is-closed');
                        clearWindowInline();
                        win.classList.toggle('is-maximized');
                    }
                });
            });

            if (restoreBtn) {
                restoreBtn.addEventListener('click', restoreWindow);
            }
            document.querySelectorAll('[data-macos-dock] a').forEach(function (link) {
                const href = link.getAttribute('href') || '';
                const path = window.location.pathname.replace(/\\/g, '/');
                if (href && (path.endsWith('/' + href) || (href === 'index.php' && /\/(index\.php)?$/.test(path)))) {
                    link.classList.add('is-active');
                }
                link.addEventListener('click', function () {
                    restoreWindow();
                });
            });

            if (titlebar && win) {
                titlebar.addEventListener('dblclick', function (event) {
                    if (!isMacos() || event.target.closest('[data-macos-action]')) return;
                    win.classList.remove('is-minimized', 'is-closed');
                    clearWindowInline();
                    win.classList.toggle('is-maximized');
                });
                titlebar.addEventListener('pointerdown', function (event) {
                    if (!isMacos() || win.classList.contains('is-maximized')) return;
                    if (event.target.closest('[data-macos-action]')) return;
                    const rect = win.getBoundingClientRect();
                    drag = {
                        offsetX: event.clientX - rect.left,
                        offsetY: event.clientY - rect.top
                    };
                    titlebar.classList.add('is-grabbing');
                    win.classList.add('is-dragging');
                    win.style.position = 'fixed';
                    win.style.left = rect.left + 'px';
                    win.style.top = rect.top + 'px';
                    win.style.width = rect.width + 'px';
                    titlebar.setPointerCapture(event.pointerId);
                });
                titlebar.addEventListener('pointermove', function (event) {
                    if (!drag) return;
                    win.style.left = Math.max(8, event.clientX - drag.offsetX) + 'px';
                    win.style.top = Math.max(8, event.clientY - drag.offsetY) + 'px';
                });
                function endDrag() {
                    drag = null;
                    titlebar.classList.remove('is-grabbing');
                    if (win) win.classList.remove('is-dragging');
                }
                titlebar.addEventListener('pointerup', endDrag);
                titlebar.addEventListener('pointercancel', endDrag);
            }

            document.querySelectorAll('.dashboard-content-panel iframe').forEach(function (frame) {
                frame.addEventListener('load', function () {
                    try {
                        const frameTitle = frame.contentDocument && frame.contentDocument.title;
                        if (frameTitle) setTitle(frameTitle.replace(/\s*\|\s*École de Santé Gabès\s*$/, ''));
                    } catch (error) {}
                });
            });
            document.querySelectorAll('[data-dashboard-home]').forEach(function (link) {
                link.addEventListener('click', function () {
                    setTitle(defaultTitle);
                });
            });

            function collectCommands() {
                const items = [];
                document.querySelectorAll('.dashboard-menu-item[href]').forEach(function (link) {
                    if (link.hasAttribute('data-accounts-toggle')) return;
                    items.push({
                        href: link.getAttribute('href'),
                        label: ((link.querySelector('strong') && link.querySelector('strong').textContent) || link.title || '').trim(),
                        target: link.getAttribute('target') || ''
                    });
                });
                document.querySelectorAll('[data-macos-dock] a').forEach(function (link) {
                    items.push({ href: link.getAttribute('href'), label: link.getAttribute('title') || '', target: '' });
                });
                const seen = {};
                return items.filter(function (item) {
                    if (!item.href || !item.label || seen[item.href + item.label]) return false;
                    seen[item.href + item.label] = true;
                    return true;
                });
            }

            function renderCommands(query) {
                if (!commandList) return;
                const q = (query || '').toLowerCase();
                commandList.innerHTML = '';
                collectCommands().filter(function (item) {
                    return !q || item.label.toLowerCase().indexOf(q) !== -1;
                }).slice(0, 12).forEach(function (item) {
                    const li = document.createElement('li');
                    const a = document.createElement('a');
                    a.href = item.href;
                    a.textContent = item.label;
                    if (item.target) a.target = item.target;
                    a.addEventListener('click', function () {
                        closeCommand();
                    });
                    li.appendChild(a);
                    commandList.appendChild(li);
                });
            }

            function openCommand() {
                if (!isMacos() || !command) return;
                command.hidden = false;
                renderCommands('');
                if (commandInput) {
                    commandInput.value = '';
                    commandInput.focus();
                }
            }

            function closeCommand() {
                if (command) command.hidden = true;
            }

            document.querySelectorAll('[data-macos-search]').forEach(function (btn) {
                btn.addEventListener('click', openCommand);
            });
            if (command) {
                command.addEventListener('click', function (event) {
                    if (event.target === command) closeCommand();
                });
            }
            if (commandInput) {
                commandInput.addEventListener('input', function () {
                    renderCommands(commandInput.value);
                });
            }
            document.addEventListener('keydown', function (event) {
                if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                    if (!isMacos()) return;
                    event.preventDefault();
                    if (command && !command.hidden) closeCommand();
                    else openCommand();
                }
                if (event.key === 'Escape') {
                    closeCommand();
                }
            });
        })();
        </script>
    </body>
    </html>
    <?php 
} ?>

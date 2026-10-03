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

function page_head(string $title, string $active = ''): void { 
    $embedded = ($_GET['embed'] ?? '') === '1'
        || (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe')
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | École de Santé Gabès</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="assets/style.css?v=49" rel="stylesheet">
        <script>
        (function () {
            try {
                var theme = localStorage.getItem('app-theme') || 'dark';
                var style = localStorage.getItem('app-style') || 'original';
                if (style === 'alternate') style = 'atlas';
                document.documentElement.classList.add('theme-boot');
                document.documentElement.dataset.appTheme = theme === 'light' ? 'light' : 'dark';
                document.documentElement.dataset.appStyle = style === 'atlas' ? 'atlas' : 'original';
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
            document.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
        })();
        </script>
        <?php if (!$embedded): ?>
        <nav class="navbar navbar-expand-md border-bottom sticky-top no-print app-chrome">
            <div class="container app-chrome-inner">
                <a class="navbar-brand navbar-institution" href="index.php" title="Tableau de bord">
                    <img src="images/no_bg_logo.png?v=2" alt="Logo" class="navbar-logo">
                    <span class="navbar-institution-name">Université privée des sciences infirmières et de santé de Gabès</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Ouvrir le menu">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="nav">
                    <ul class="navbar-nav ms-auto align-items-lg-center app-chrome-actions">
                        <?php if (is_teacher()): ?>
                            <?php
                            $teacherWelcomeQuery = db()->prepare('SELECT nom, prenom FROM enseignant WHERE id_enseignant = ? LIMIT 1');
                            $teacherWelcomeQuery->execute([current_teacher_id()]);
                            $teacherWelcome = $teacherWelcomeQuery->fetch();
                            ?>
                            <li class="nav-item me-lg-3 app-chrome-welcome">
                                <span class="navbar-text teacher-welcome">Bienvenue <?= e(($teacherWelcome['prenom'] ?? '') . ' ' . ($teacherWelcome['nom'] ?? 'Enseignant')) ?></span>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item ms-lg-2">
                            <button type="button" class="theme-toggle theme-style-toggle" data-style-toggle aria-label="Utiliser le thème Atlas" title="Utiliser le thème Atlas">
                                <span aria-hidden="true">◆</span>
                                <span data-style-label>Thème Atlas</span>
                            </button>
                        </li>
                        <li class="nav-item ms-lg-2">
                            <button type="button" class="theme-toggle" data-theme-toggle aria-label="Activer le mode clair" title="Activer le mode clair">
                                <span data-theme-icon>☀</span>
                                <span data-theme-label>Clair</span>
                            </button>
                        </li>
                        <li class="nav-item ms-lg-2">
                            <div class="dropdown" data-settings-dropdown>
                                <button class="btn btn-outline-light btn-sm settings-button" type="button" data-bs-toggle="dropdown" data-bs-popper="static" aria-expanded="false" aria-label="Ouvrir les réglages" title="Réglages">
                                    <span aria-hidden="true">⚙</span><span class="visually-hidden">Réglages</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <?php if (is_admin()): ?><li><a class="dropdown-item" href="changer_mot_de_passe.php">Changer le mot de passe</a></li><li><hr class="dropdown-divider"></li><?php endif; ?>
                                    <li><a class="dropdown-item text-danger" href="logout.php">Déconnexion</a></li>
                                </ul>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        <?php endif; ?>
        <main class="container app-sheet <?= $embedded ? 'py-3' : 'py-5' ?>">
    <?php 
}

function page_foot(): void { 
    ?>
        </main>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
        (function () {
            const body = document.body;
            const toggle = document.querySelector('[data-theme-toggle]');
            const styleToggle = document.querySelector('[data-style-toggle]');
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
                if (style === 'atlas') return 'atlas';
                if (style === 'alternate') return 'atlas';
                return 'original';
            }
            function syncSettingsDropdownDirection() {
                const settingsDrop = document.querySelector('[data-settings-dropdown]');
                if (!settingsDrop) return;
                settingsDrop.classList.remove('dropup');
            }

            function applyStyle(style) {
                const normalized = normalizeStyle(style);
                if (normalized === 'atlas' && localStorage.getItem('app-style') === 'alternate') {
                    localStorage.setItem('app-style', 'atlas');
                }
                body.classList.remove('theme-alternate');
                body.classList.toggle('theme-atlas', normalized === 'atlas');
                if (styleToggle) {
                    const nextMeta = {
                        original: { label: 'Thème Atlas', aria: 'Utiliser le thème Atlas' },
                        atlas: { label: 'Thème original', aria: 'Revenir au thème original' }
                    }[normalized];
                    styleToggle.setAttribute('aria-label', nextMeta.aria);
                    styleToggle.title = nextMeta.aria;
                    styleToggle.querySelector('[data-style-label]').textContent = nextMeta.label;
                }
                syncSettingsDropdownDirection();
            }

            function currentStyle() {
                return body.classList.contains('theme-atlas') ? 'atlas' : 'original';
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
                        }
                        if (frame.contentDocument && frame.contentDocument.documentElement) {
                            frame.contentDocument.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
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
            if (styleToggle) {
                styleToggle.addEventListener('click', function () {
                    const nextStyle = currentStyle() === 'original' ? 'atlas' : 'original';
                    localStorage.setItem('app-style', nextStyle);
                    applyStyle(nextStyle);
                    broadcastAppearance();
                });
            }
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
                    var frameDocument = frame.contentDocument;
                    if (!frameDocument) return;
                    var frameRoot = frameDocument.documentElement;
                    var frameBody = frameDocument.body;
                    if (frameRoot) {
                        frameRoot.style.setProperty('overflow-x', 'hidden');
                        frameRoot.style.setProperty('overflow-y', 'auto');
                    }
                    if (frameBody) {
                        frameBody.style.setProperty('overflow-x', 'hidden');
                        frameBody.style.setProperty('overflow-y', 'auto');
                    }

                    var contentHeight = Math.max(
                        frameRoot ? frameRoot.scrollHeight : 0,
                        frameRoot ? frameRoot.offsetHeight : 0,
                        frameBody ? frameBody.scrollHeight : 0,
                        frameBody ? frameBody.offsetHeight : 0,
                        Math.round(window.innerHeight * 0.7)
                    );
                    if (Math.abs((parseInt(frame.style.height, 10) || 0) - contentHeight) > 8) {
                        frame.style.height = contentHeight + 'px';
                    }
                    frame.removeAttribute('scrolling');

                    if (!frame._dashboardResizeObserver && 'ResizeObserver' in window && frameRoot) {
                        frame._dashboardResizeObserver = new ResizeObserver(function () {
                            window.requestAnimationFrame(function () {
                                resizeDashboardFrame(frame, false);
                            });
                        });
                        frame._dashboardResizeObserver.observe(frameRoot);
                        if (frameBody) frame._dashboardResizeObserver.observe(frameBody);
                    }

                    if (scrollPanel) {
                        var panel = frame.closest('.dashboard-content-panel');
                        if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                } catch (error) {
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
        </script>
    </body>
    </html>
    <?php 
} ?>

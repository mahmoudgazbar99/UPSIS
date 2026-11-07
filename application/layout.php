<?php
/*
require_once __DIR__ . '/bootstrap.php';
function page_head(string $title, string $active = ''): void { ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | École de Santé Gabès</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css?v=89" rel="stylesheet"></head>
<body class="bg-dark text-light"><nav class="navbar navbar-expand-lg navbar-dark border-bottom border-secondary sticky-top no-print"><div class="container"><a class="navbar-brand fw-bold" href="index.php">École de Santé Gabès</a><button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="nav"><ul class="navbar-nav ms-auto align-items-lg-center"><li class="nav-item"><a class="nav-link <?= $active==='home'?'active':'' ?>" href="index.php">Tableau de bord</a></li><li class="nav-item"><a class="nav-link <?= $active==='register'?'active':'' ?>" href="inscription.php">Inscrire</a></li><li class="nav-item"><a class="nav-link <?= $active==='grades'?'active':'' ?>" href="gestion_notes.php">Notes</a></li><li class="nav-item"><a class="nav-link <?= $active==='certificates'?'active':'' ?>" href="attestation_inscription.php">Attestations</a></li><li class="nav-item ms-lg-2"><a class="btn btn-outline-light btn-sm" href="logout.php">Déconnexion</a></li></ul></div></div></nav><main class="container py-5">
<?php }
function page_foot(): void { ?>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
<?php }*/

require_once __DIR__ . '/bootstrap.php';

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
        } elseif (is_scolarite()) {
            $role = 'Scolarité';
            try {
                $query = db()->prepare('SELECT nom, username FROM scolarite_user WHERE id_scolarite = ? LIMIT 1');
                $query->execute([current_scolarite_id()]);
                $row = $query->fetch() ?: [];
                $name = trim((string) ($row['nom'] ?? '')) ?: (string) ($row['username'] ?? 'Scolarité');
            } catch (Throwable $error) {
                $name = 'Scolarité';
            }
        } elseif (is_stage()) {
            $role = stage_duty() === 'superviseur' ? 'Encadrant de stage' : 'Responsable des stages';
            try {
                $query = db()->prepare('SELECT nom, cin FROM stage_user WHERE id_stage = ? LIMIT 1');
                $query->execute([current_stage_id()]);
                $row = $query->fetch() ?: [];
                $name = trim((string) ($row['nom'] ?? '')) ?: (string) ($row['cin'] ?? $role);
            } catch (Throwable $error) {
                $name = $role;
            }
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
            <a class="macos-dock-item" href="index.php" data-dashboard-home data-dock="home" title="Tableau de bord" aria-label="Tableau de bord"><?= $icon('<path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z"/>') ?></a>
            <?php if (is_admin() || is_scolarite()): ?>
            <a class="macos-dock-item" href="inscription.php?embed=1" target="dashboard-content" data-dock="enroll" title="Inscrire" aria-label="Inscrire"><?= $icon('<path d="M12 5v14M5 12h14"/><circle cx="12" cy="12" r="9"/>') ?></a>
            <?php endif; ?>
            <?php if (is_teacher()): ?>
            <button type="button" class="macos-dock-item" data-dock="courses" data-course-finder-open title="Cours" aria-label="Ouvrir le dossier des cours" aria-haspopup="dialog" aria-controls="courseFinderWindow"><?= $icon('<path d="M3.5 8.2A2.2 2.2 0 0 1 5.7 6h3.1l1.6 1.8h7.9A2.2 2.2 0 0 1 20.5 10v7.2a2.2 2.2 0 0 1-2.2 2.2H5.7a2.2 2.2 0 0 1-2.2-2.2z"/>') ?></button>
            <?php endif; ?>
            <?php if (is_admin() || is_teacher()): ?>
            <a class="macos-dock-item" href="gestion_notes.php?embed=1" target="dashboard-content" data-dock="grades" title="Notes" aria-label="Notes"><?= $icon('<path d="M7 4h10v16H7z"/><path d="M10 8h4M10 12h4M10 16h2"/>') ?></a>
            <a class="macos-dock-item" href="presence_etudiants.php?embed=1" target="dashboard-content" data-dock="presence" title="Présence" aria-label="Présence"><?= $icon('<path d="M8 12.5 10.5 15 16 9"/><rect x="4" y="4" width="16" height="16" rx="3"/>') ?></a>
            <?php endif; ?>
            <?php if (is_finance()): ?>
            <a class="macos-dock-item" href="finance.php?embed=1" target="dashboard-content" data-dock="finance" title="Finance" aria-label="Suivi des paiements"><?= $icon('<circle cx="12" cy="12" r="8"/><path d="M12 8v8M9.5 10.5c.7-1 4.3-1 5 1s-1.5 2.5-2.5 2.5"/>') ?></a>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <a class="macos-dock-item" href="demandes_presence.php?embed=1" target="dashboard-content" data-dock="attestation" title="Demandes de présence" aria-label="Demandes de présence"><?= $icon('<path d="M7 4h10a2 2 0 0 1 2 2v14l-7-3-7 3V6a2 2 0 0 1 2-2z"/>') ?></a>
            <span class="macos-dock-sep" aria-hidden="true"></span>
            <a class="macos-dock-item" href="changer_mot_de_passe.php?embed=1" target="dashboard-content" data-dock="settings" title="Réglages" aria-label="Réglages"><?= $icon('<circle cx="12" cy="12" r="3"/><path d="M12 3v2M12 19v2M5 12H3M21 12h-2M6.2 6.2l1.4 1.4M16.4 16.4l1.4 1.4M17.8 6.2l-1.4 1.4M7.6 16.4 6.2 17.8"/>') ?></a>
            <?php endif; ?>
        </nav>
        <?php if (is_teacher()): ?>
        <div class="course-finder-layer no-print" id="courseFinderLayer" data-csrf="<?= e(csrf_token()) ?>" hidden>
            <div class="course-finder-window" id="courseFinderWindow" role="dialog" aria-modal="true" aria-labelledby="courseFinderTitle">
                <header class="course-finder-titlebar">
                    <div class="macos-traffic">
                        <button type="button" class="macos-dot macos-dot-close" data-course-finder-close aria-label="Fermer le dossier des cours"></button>
                        <span class="macos-dot macos-dot-min" aria-hidden="true"></span>
                        <span class="macos-dot macos-dot-max" aria-hidden="true"></span>
                    </div>
                    <button type="button" class="course-finder-back" data-course-finder-back hidden aria-label="Dossier précédent">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M15 6 9 12l6 6"/></svg>
                    </button>
                    <h2 id="courseFinderTitle">Cours</h2>
                    <div class="course-finder-toolbar">
                        <button type="button" data-course-finder-course>Ajouter un cours</button>
                        <button type="button" data-course-finder-new>Nouveau dossier</button>
                        <button type="button" data-course-finder-add>Ajouter des PDF</button>
                        <input type="file" data-course-finder-files accept="application/pdf,.pdf" multiple hidden>
                    </div>
                </header>
                <p class="course-finder-status" data-course-finder-status role="status"></p>
                <div class="course-finder-body" data-course-finder-body tabindex="0"></div>
                <div class="course-finder-menu" data-course-finder-menu hidden role="menu"></div>
                <form class="course-finder-sheet" data-course-finder-sheet hidden>
                    <p data-course-finder-sheet-text></p>
                    <label data-course-finder-subject-label hidden>Matière
                        <select data-course-finder-subject></select>
                    </label>
                    <label data-course-finder-sheet-label>Nom
                        <input data-course-finder-sheet-name maxlength="120" autocomplete="off">
                    </label>
                    <div class="course-finder-sheet-actions">
                        <button type="button" data-course-finder-sheet-cancel>Annuler</button>
                        <button type="submit" data-course-finder-sheet-ok>Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <button type="button" class="macos-restore-hint no-print" data-macos-restore>Restaurer la fenêtre</button>
        <div class="macos-command-overlay no-print" data-macos-command hidden>
            <div class="macos-command-sheet" role="dialog" aria-modal="true" aria-label="Recherche">
                <input type="search" data-macos-command-input placeholder="Rechercher un module…" aria-label="Rechercher un module">
                <ul data-macos-command-list></ul>
            </div>
        </div>
        <?php if (is_teacher()): ?>
        <script>
        (function () {
            var openButton = document.querySelector('[data-course-finder-open]');
            var layer = document.getElementById('courseFinderLayer');
            var body = document.querySelector('[data-course-finder-body]');
            var title = document.getElementById('courseFinderTitle');
            var back = document.querySelector('[data-course-finder-back]');
            if (!openButton || !layer || !body || !title || !back) return;
            var courses = null;
            var subjects = [];
            var stack = [];
            var loading = false;
            var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var phoneQuery = window.matchMedia('(max-width: 767.98px)');

            function glyph(markup) {
                var holder = document.createElement('span');
                holder.className = 'course-finder-glyph';
                holder.innerHTML = markup;
                return holder;
            }
            function folderIcon() {
                return glyph('<svg viewBox="0 0 64 52" aria-hidden="true"><path fill="#7ec8ff" d="M4 14a6 6 0 0 1 6-6h14l6 7h24a6 6 0 0 1 6 6v22a6 6 0 0 1-6 6H10a6 6 0 0 1-6-6z"/><path fill="#b9e2ff" d="M4 22h56v21a6 6 0 0 1-6 6H10a6 6 0 0 1-6-6z"/></svg>');
            }
            function fileIcon() {
                return glyph('<svg viewBox="0 0 48 60" aria-hidden="true"><path fill="#fff" stroke="#d0d5dd" d="M8 4h22l12 12v38a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8a4 4 0 0 1 4-4z"/><path fill="#e11d48" d="M6 36h36v10H6z"/><text x="24" y="44" text-anchor="middle" font-size="8" font-family="Inter,sans-serif" fill="#fff">PDF</text></svg>');
            }
            function current() {
                return stack.length ? stack[stack.length - 1] : null;
            }
            function render() {
                var node = current();
                var folders = node ? node.folders : (courses || []);
                var files = node ? node.files : [];
                title.textContent = node ? node.name : 'Cours';
                back.hidden = stack.length === 0;
                body.replaceChildren();
                body.classList.remove('is-opening');
                if (!courses) {
                    body.appendChild(message('Chargement des dossiers…'));
                    return;
                }
                if (!folders.length && !files.length) {
                    body.appendChild(message(node ? 'Ce dossier est vide.' : 'Aucun cours pour le moment. Créez un dossier depuis Mes cours.'));
                    return;
                }
                var grid = document.createElement('div');
                grid.className = 'course-finder-grid';
                folders.forEach(function (folder) { grid.appendChild(item(folder, true)); });
                files.forEach(function (file) { grid.appendChild(item(file, false)); });
                body.appendChild(grid);
                if (!reduced) {
                    window.requestAnimationFrame(function () { body.classList.add('is-opening'); });
                }
            }
            function message(text) {
                var p = document.createElement('p');
                p.className = 'course-finder-empty';
                p.textContent = text;
                return p;
            }
            function item(entry, isFolder) {
                var card = document.createElement('div');
                card.className = 'course-finder-item' + (isFolder ? ' is-folder' : ' is-file');
                card.draggable = true;
                var open = document.createElement('button');
                open.type = 'button';
                open.className = 'course-finder-open';
                open.appendChild(isFolder ? folderIcon() : fileIcon());
                var name = document.createElement('span');
                name.textContent = entry.name || (isFolder ? 'Dossier' : 'Fichier');
                open.appendChild(name);
                if (!isFolder && entry.size) {
                    var size = document.createElement('small');
                    size.textContent = entry.size;
                    open.appendChild(size);
                }
                open.addEventListener('click', function () {
                    if (phoneQuery.matches) openEntry(entry, isFolder);
                });
                open.addEventListener('dblclick', function () {
                    if (!phoneQuery.matches) openEntry(entry, isFolder);
                });
                open.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        openEntry(entry, isFolder);
                    }
                    if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
                        event.preventDefault();
                        openMenu(entry, isFolder, event, open);
                    }
                });
                var actions = document.createElement('button');
                actions.type = 'button';
                actions.className = 'course-finder-more';
                actions.textContent = 'Actions';
                actions.setAttribute('aria-label', 'Actions pour ' + (entry.name || 'cet élément'));
                actions.addEventListener('click', function (event) {
                    event.stopPropagation();
                    openMenu(entry, isFolder, event);
                });
                card.appendChild(open);
                card.appendChild(actions);
                card.addEventListener('contextmenu', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    openMenu(entry, isFolder, event);
                });
                card.addEventListener('dragstart', function (event) {
                    event.dataTransfer.setData('application/x-upsis-course', JSON.stringify({ kind: isFolder ? 'folder' : 'file', id: entry.id }));
                    event.dataTransfer.effectAllowed = 'move';
                });
                if (isFolder) {
                    card.addEventListener('dragover', function (event) {
                        if (!canDrop(event)) return;
                        event.preventDefault();
                        card.classList.add('is-drop');
                    });
                    card.addEventListener('dragleave', function () { card.classList.remove('is-drop'); });
                    card.addEventListener('drop', function (event) {
                        card.classList.remove('is-drop');
                        event.preventDefault();
                        event.stopPropagation();
                        receiveDrop(event, entry.id);
                    });
                }
                return card;
            }
            function openEntry(entry, isFolder) {
                if (isFolder) {
                    stack.push(entry);
                    render();
                    return;
                }
                if (!entry.id) return;
                var frame = document.createElement('iframe');
                frame.className = 'course-finder-preview';
                frame.title = entry.name || 'Aperçu PDF';
                frame.src = 'cours_fichier.php?id=' + encodeURIComponent(entry.id) + '&action=view';
                body.replaceChildren(frame);
                title.textContent = entry.name || 'PDF';
                back.hidden = false;
                stack.push({ name: entry.name, preview: true, folders: [], files: [] });
            }
            function closeFinder() {
                layer.hidden = true;
                openButton.setAttribute('aria-expanded', 'false');
                openButton.focus();
            }
            function showFinder() {
                layer.hidden = false;
                openButton.setAttribute('aria-expanded', 'true');
                var dialog = layer.querySelector('.course-finder-window');
                if (dialog) {
                    dialog.classList.remove('is-in');
                    if (!reduced) window.requestAnimationFrame(function () { dialog.classList.add('is-in'); });
                }
                back.focus();
                if (courses === null && !loading) {
                    loading = true;
                    render();
                    fetch('mes_cours.php?finder=1', { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
                        .then(function (response) { if (!response.ok) throw new Error('cours'); return response.json(); })
                        .then(function (data) {
                            courses = data && data.courses ? data.courses : [];
                            subjects = data && data.subjects ? data.subjects : [];
                            stack = [];
                            render();
                        })
                        .catch(function () {
                            courses = [];
                            body.replaceChildren(message('Les dossiers n’ont pas pu être ouverts.'));
                        })
                        .finally(function () { loading = false; });
                }
            }
            var status = document.querySelector('[data-course-finder-status]');
            var menu = document.querySelector('[data-course-finder-menu]');
            var sheet = document.querySelector('[data-course-finder-sheet]');
            var sheetText = document.querySelector('[data-course-finder-sheet-text]');
            var sheetName = document.querySelector('[data-course-finder-sheet-name]');
            var sheetLabel = document.querySelector('[data-course-finder-sheet-label]');
            var subjectLabel = document.querySelector('[data-course-finder-subject-label]');
            var subjectSelect = document.querySelector('[data-course-finder-subject]');
            var newCourse = document.querySelector('[data-course-finder-course]');
            var newFolder = document.querySelector('[data-course-finder-new]');
            var addFiles = document.querySelector('[data-course-finder-add]');
            var fileInput = document.querySelector('[data-course-finder-files]');
            var sheetMode = null;
            function setStatus(text) {
                if (status) status.textContent = text || '';
            }
            function pathIds() {
                return stack.filter(function (node) { return node && node.id && !node.preview; }).map(function (node) { return node.id; });
            }
            function restorePath(ids) {
                stack = [];
                var level = courses || [];
                ids.forEach(function (id) {
                    var found = null;
                    level.forEach(function (node) { if (node.id === id) found = node; });
                    if (!found) return;
                    stack.push(found);
                    level = found.folders || [];
                });
            }
            function postFinder(fields, fileList) {
                var form = new FormData();
                form.append('csrf_token', layer.getAttribute('data-csrf') || '');
                form.append('finder', '1');
                Object.keys(fields).forEach(function (key) { form.append(key, fields[key]); });
                Array.prototype.forEach.call(fileList || [], function (file) { form.append('pdfs[]', file); });
                var keep = fields.action === 'create_course' ? [] : pathIds();
                setStatus('Enregistrement…');
                layer.setAttribute('aria-busy', 'true');
                return fetch('mes_cours.php', { method: 'POST', body: form, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.courses) {
                            courses = data.courses;
                            if (data.subjects) subjects = data.subjects;
                            restorePath(keep);
                            render();
                        }
                        setStatus(data && data.message ? data.message : '');
                        if (!data || data.ok === false) throw new Error(data && data.message ? data.message : 'Action impossible.');
                    })
                    .catch(function (error) {
                        setStatus(error && error.message ? error.message : 'Action impossible.');
                    })
                    .finally(function () { layer.removeAttribute('aria-busy'); });
            }
            function hideMenu() {
                if (!menu) return;
                menu.hidden = true;
                menu.replaceChildren();
            }
            function hideSheet() {
                if (!sheet) return;
                sheet.hidden = true;
                sheetMode = null;
            }
            function openSheet(mode, entry, isFolder) {
                hideMenu();
                sheetMode = { mode: mode, entry: entry, isFolder: isFolder };
                var removing = mode === 'delete';
                var creatingCourse = mode === 'course';
                sheetText.textContent = removing
                    ? 'Supprimer « ' + (entry.name || 'cet élément') + ' » ? Cette action est définitive.'
                    : (creatingCourse ? 'Nom du nouveau cours' : (mode === 'create' ? 'Nom du nouveau dossier' : 'Nouveau nom'));
                sheetLabel.hidden = removing;
                if (subjectLabel) subjectLabel.hidden = !creatingCourse;
                if (creatingCourse && subjectSelect) {
                    subjectSelect.replaceChildren();
                    subjects.forEach(function (subject) {
                        var option = document.createElement('option');
                        option.value = String(subject.id);
                        option.textContent = subject.label;
                        subjectSelect.appendChild(option);
                    });
                }
                sheetName.value = mode === 'rename' ? (entry.name || '').replace(/\.pdf$/i, '') : '';
                sheet.querySelector('[data-course-finder-sheet-ok]').textContent = removing ? 'Supprimer' : 'Enregistrer';
                sheet.hidden = false;
                (removing ? sheet.querySelector('[data-course-finder-sheet-ok]') : sheetName).focus();
            }
            function openMenu(entry, isFolder, event, anchor) {
                hideSheet();
                if (menu.parentElement !== document.body) document.body.appendChild(menu);
                menu.replaceChildren();
                function add(label, run, danger) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.setAttribute('role', 'menuitem');
                    button.textContent = label;
                    if (danger) button.className = 'is-danger';
                    button.addEventListener('click', function () { hideMenu(); run(); });
                    menu.appendChild(button);
                }
                function rule() {
                    var line = document.createElement('div');
                    line.className = 'course-finder-menu-rule';
                    line.setAttribute('role', 'separator');
                    menu.appendChild(line);
                }
                add('Actualiser', reloadCourses);
                rule();
                add('Ouvrir', function () { openEntry(entry, isFolder); });
                if (!isFolder) add('Télécharger', function () {
                    window.location.assign('cours_fichier.php?id=' + encodeURIComponent(entry.id) + '&action=download');
                });
                add('Renommer', function () { openSheet('rename', entry, isFolder); });
                var here = current();
                var destinations = (here ? here.folders : (courses || [])).filter(function (folder) { return folder.id !== entry.id; });
                if (destinations.length) {
                    rule();
                    destinations.forEach(function (folder) {
                        add('Déplacer dans « ' + folder.name + ' »', function () {
                            postFinder(isFolder
                                ? { action: 'move_folder', id_dossier: String(entry.id), id_parent: String(folder.id) }
                                : { action: 'move_file', id_fichier: String(entry.id), id_dossier: String(folder.id) });
                        });
                    });
                }
                rule();
                add('Supprimer', function () { openSheet('delete', entry, isFolder); }, true);
                var pointerX = event && typeof event.clientX === 'number' ? event.clientX : 0;
                var pointerY = event && typeof event.clientY === 'number' ? event.clientY : 0;
                if (!pointerX && !pointerY && anchor && anchor.getBoundingClientRect) {
                    var rect = anchor.getBoundingClientRect();
                    pointerX = rect.left;
                    pointerY = rect.bottom;
                }
                menu.hidden = false;
                menu.classList.remove('is-in');
                var bounds = menu.getBoundingClientRect();
                var left = Math.max(8, Math.min(pointerX, window.innerWidth - bounds.width - 8));
                var top = Math.max(8, Math.min(pointerY, window.innerHeight - bounds.height - 8));
                menu.style.left = left + 'px';
                menu.style.top = top + 'px';
                if (!reduced) window.requestAnimationFrame(function () { menu.classList.add('is-in'); });
            }
            function hasOsFiles(event) {
                var types = event.dataTransfer ? event.dataTransfer.types : [];
                for (var i = 0; i < types.length; i += 1) if (types[i] === 'Files') return true;
                return false;
            }
            function canDrop(event) {
                return hasOsFiles(event) || (event.dataTransfer && event.dataTransfer.types && Array.prototype.indexOf.call(event.dataTransfer.types, 'application/x-upsis-course') !== -1);
            }
            function receiveDrop(event, destinationId) {
                if (hasOsFiles(event)) {
                    if (!destinationId) {
                        setStatus('Ouvrez un cours avant de déposer un PDF.');
                        return;
                    }
                    postFinder({ action: 'upload', id_dossier: String(destinationId) }, event.dataTransfer.files);
                    return;
                }
                var raw = event.dataTransfer.getData('application/x-upsis-course');
                if (!raw || !destinationId) return;
                var payload = null;
                try { payload = JSON.parse(raw); } catch (error) { return; }
                if (!payload || payload.id === destinationId) return;
                postFinder(payload.kind === 'folder'
                    ? { action: 'move_folder', id_dossier: String(payload.id), id_parent: String(destinationId) }
                    : { action: 'move_file', id_fichier: String(payload.id), id_dossier: String(destinationId) });
            }
            function reloadCourses() {
                var keep = pathIds();
                setStatus('Actualisation…');
                fetch('mes_cours.php?finder=1', { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
                    .then(function (response) { if (!response.ok) throw new Error('cours'); return response.json(); })
                    .then(function (data) {
                        courses = data && data.courses ? data.courses : [];
                        subjects = data && data.subjects ? data.subjects : [];
                        restorePath(keep);
                        render();
                        setStatus('Dossiers à jour.');
                    })
                    .catch(function () { setStatus('Les dossiers n’ont pas pu être actualisés.'); });
            }
            function openRefreshMenu(event) {
                hideSheet();
                if (menu.parentElement !== document.body) document.body.appendChild(menu);
                menu.replaceChildren();
                var button = document.createElement('button');
                button.type = 'button';
                button.setAttribute('role', 'menuitem');
                button.textContent = 'Actualiser';
                button.addEventListener('click', function () { hideMenu(); reloadCourses(); });
                menu.appendChild(button);
                menu.hidden = false;
                menu.classList.remove('is-in');
                var bounds = menu.getBoundingClientRect();
                menu.style.left = Math.max(8, Math.min(event.clientX, window.innerWidth - bounds.width - 8)) + 'px';
                menu.style.top = Math.max(8, Math.min(event.clientY, window.innerHeight - bounds.height - 8)) + 'px';
                if (!reduced) window.requestAnimationFrame(function () { menu.classList.add('is-in'); });
            }
            if (newCourse) newCourse.addEventListener('click', function () {
                if (!subjects.length) {
                    setStatus('Aucune matière ne vous est affectée. Le cours ne peut pas être créé.');
                    return;
                }
                openSheet('course', { name: '' }, true);
            });
            if (newFolder) newFolder.addEventListener('click', function () {
                var node = current();
                if (!node || !node.id) {
                    setStatus('Ouvrez un cours, puis créez le dossier à l’intérieur.');
                    return;
                }
                openSheet('create', node, true);
            });
            if (addFiles && fileInput) {
                addFiles.addEventListener('click', function () {
                    if (!current() || !current().id) {
                        setStatus('Ouvrez un cours avant d’ajouter un PDF.');
                        return;
                    }
                    fileInput.click();
                });
                fileInput.addEventListener('change', function () {
                    var node = current();
                    if (node && node.id && fileInput.files && fileInput.files.length) {
                        postFinder({ action: 'upload', id_dossier: String(node.id) }, fileInput.files);
                    }
                    fileInput.value = '';
                });
            }
            body.addEventListener('contextmenu', function (event) {
                if (event.target.closest && event.target.closest('.course-finder-item')) return;
                event.preventDefault();
                openRefreshMenu(event);
            });
            body.addEventListener('dragover', function (event) {
                if (!canDrop(event)) return;
                event.preventDefault();
                body.classList.add('is-drop');
            });
            body.addEventListener('dragleave', function (event) {
                if (event.target === body) body.classList.remove('is-drop');
            });
            body.addEventListener('drop', function (event) {
                body.classList.remove('is-drop');
                if (!canDrop(event)) return;
                event.preventDefault();
                var node = current();
                receiveDrop(event, node ? node.id : 0);
            });
            if (sheet) sheet.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!sheetMode) return;
                var entry = sheetMode.entry;
                var name = sheetName.value.trim();
                if (sheetMode.mode === 'delete') {
                    postFinder(sheetMode.isFolder
                        ? { action: 'delete_folder', id_dossier: String(entry.id) }
                        : { action: 'delete_file', id_fichier: String(entry.id) });
                } else if (sheetMode.mode === 'course') {
                    if (!name || !subjectSelect || !subjectSelect.value) return;
                    postFinder({ action: 'create_course', id_mat: subjectSelect.value, nom: name });
                } else if (sheetMode.mode === 'create') {
                    if (!name) return;
                    postFinder({ action: 'create_folder', id_parent: String(entry.id), nom: name });
                } else if (name) {
                    postFinder(sheetMode.isFolder
                        ? { action: 'rename_folder', id_dossier: String(entry.id), nom: name }
                        : { action: 'rename_file', id_fichier: String(entry.id), nom: name });
                }
                hideSheet();
            });
            var cancelSheet = document.querySelector('[data-course-finder-sheet-cancel]');
            if (cancelSheet) cancelSheet.addEventListener('click', hideSheet);
            document.addEventListener('click', function (event) {
                if (menu && !menu.hidden && !(event.target.closest && event.target.closest('[data-course-finder-menu], .course-finder-more'))) hideMenu();
            });
            openButton.addEventListener('click', function () {
                if (layer.hidden) showFinder();
                else closeFinder();
            });
            back.addEventListener('click', function () {
                stack.pop();
                render();
            });
            layer.addEventListener('click', function (event) {
                if (event.target === layer || (event.target.closest && event.target.closest('[data-course-finder-close]'))) closeFinder();
            });
            document.addEventListener('keydown', function (event) {
                if (layer.hidden || event.key !== 'Escape') return;
                event.preventDefault();
                if (menu && !menu.hidden) { hideMenu(); return; }
                if (sheet && !sheet.hidden) { hideSheet(); return; }
                if (stack.length) {
                    stack.pop();
                    render();
                } else {
                    closeFinder();
                }
            });
        })();
        </script>
        <?php endif; ?>
    <?php
}

function page_head(string $title, string $active = '', string $pageStylesheet = ''): void {
    $embedded = app_is_embedded();
    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title><?= e($title) ?> | <?= e(institution_name_inline()) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Inter:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="assets/theme-tokens.css?v=1" rel="stylesheet">
        <link href="assets/motion.css?v=3" rel="stylesheet">
        <link href="assets/style.css?v=92" rel="stylesheet">
        <link href="assets/institut.css?v=3" rel="stylesheet">
        <link href="assets/macos.css?v=51" rel="stylesheet">
        <link href="assets/clarity.css?v=19" rel="stylesheet">
        <?php if ($pageStylesheet !== ''): ?><link href="<?= e($pageStylesheet) ?>" rel="stylesheet"><?php endif; ?>
        <script>
        (function () {
            try {
                var themeScope = <?= json_encode(app_theme_key(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var themeKey = 'app-theme:' + themeScope;
                var styleKey = 'app-style:' + themeScope;
                var theme = localStorage.getItem(themeKey);
                var style = localStorage.getItem(styleKey);
                var accountScope = themeScope.slice(0, Math.max(themeScope.lastIndexOf(':'), 0));
                if (!theme) {
                    theme = localStorage.getItem('app-theme:' + accountScope) || localStorage.getItem('app-theme') || 'dark';
                    localStorage.setItem(themeKey, theme);
                }
                if (!style) {
                    style = localStorage.getItem('app-style:' + accountScope) || localStorage.getItem('app-style') || 'original';
                    localStorage.setItem(styleKey, style);
                }
                if (style === 'alternate' || style === 'atlas') style = 'original';
                if (style !== 'glass' && style !== 'macos' && style !== 'institut' && style !== 'clarity') style = 'original';
                document.documentElement.classList.add('theme-boot');
                document.documentElement.dataset.appTheme = theme === 'light' ? 'light' : 'dark';
                document.documentElement.dataset.appStyle = style;
                document.documentElement.dataset.themeScope = themeScope;
                document.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
                if (localStorage.getItem(styleKey) === 'alternate' || localStorage.getItem(styleKey) === 'atlas') localStorage.setItem(styleKey, 'original');
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
            body.classList.remove('theme-atlas');
            body.classList.toggle('theme-glass', style === 'glass');
            body.classList.toggle('theme-macos', style === 'macos');
            body.classList.toggle('theme-clarity', style === 'clarity');
            body.classList.toggle('theme-institut', style === 'institut');
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
                        <img src="<?= e(institution_logo_src()) ?>" alt="Logo" class="navbar-logo">
                        <span class="navbar-institution-name"><?php foreach (institution_name_lines() as $institutionLine): ?><span><?= e($institutionLine) ?></span><?php endforeach; ?></span>
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
                                    <li><a class="dropdown-item" href="demandes_presence.php?embed=1" target="dashboard-content">Demandes de présence</a></li>
                                    <li><a class="dropdown-item" href="alertes_elimination.php?embed=1" target="dashboard-content">Alertes d’élimination</a></li>
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
                                    <option value="glass">Glass</option>
                                    <option value="macos">Mac OS</option>
                                    <option value="clarity">Mac bureau</option>
                                    <option value="institut">Institut</option>
                                </select>
                            </label>
                        </li>
                        <li class="nav-item">
                            <time class="clarity-menu-clock" data-clarity-clock datetime=""></time>
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
                                            <option value="glass">Glass</option>
                                            <option value="macos">Mac OS</option>
                                            <option value="clarity">Mac bureau</option>
                                            <option value="institut">Institut</option>
                                        </select>
                                    </li>
                                    <li class="macos-only-control"><hr class="dropdown-divider"></li>
                                    <?php if (is_admin()): ?><li><a class="dropdown-item" href="etablissement.php?embed=1" target="dashboard-content">Nom de l’établissement</a></li><li><a class="dropdown-item" href="changer_mot_de_passe.php?embed=1" target="dashboard-content">Changer le mot de passe</a></li><li><hr class="dropdown-divider"></li><?php endif; ?>
                                    <li><a class="dropdown-item text-danger" href="logout.php?desk=<?= e(strtok(app_theme_owner(), ':') ?: 'admin') ?>">Déconnexion</a></li>
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
                                    <?php if (is_admin()): ?><li><a class="dropdown-item" href="changer_mot_de_passe.php?embed=1" target="dashboard-content">Changer le mot de passe</a></li><?php endif; ?>
                                    <li><a class="dropdown-item text-danger" href="logout.php?desk=<?= e(strtok(app_theme_owner(), ':') ?: 'admin') ?>">Déconnexion</a></li>
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
        (function () {
            var name = document.querySelector('.navbar-institution-name');
            var box = name ? name.closest('.navbar-institution') : null;
            if (!name || !box) return;
            function fit() {
                name.classList.remove('is-marquee');
                if (!document.body.classList.contains('theme-clarity')) return;
                var overflow = name.scrollWidth - box.clientWidth;
                if (overflow > 8) {
                    name.style.setProperty('--shift', (-overflow - 16) + 'px');
                    name.classList.add('is-marquee');
                }
            }
            fit();
            window.addEventListener('resize', fit);
            new MutationObserver(fit).observe(document.body, { attributes: true, attributeFilter: ['class'] });
        })();
        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="assets/motion.js?v=3" defer></script>
        <?php if (logged_in() && !is_admin() && !is_student()): ?>
        <script src="assets/account-session.js?v=2"></script>
        <?php endif; ?>
        <script>
        (function () {
            const body = document.body;
            const toggle = document.querySelector('[data-theme-toggle]');
            const styleSelects = document.querySelectorAll('[data-style-select]');
            const navToggle = document.querySelector('.app-chrome .navbar-toggler');
            const navCollapse = document.querySelector('#nav');
            const themeScope = document.documentElement.dataset.themeScope || <?= json_encode(app_theme_key(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const themeKey = 'app-theme:' + themeScope;
            const styleKey = 'app-style:' + themeScope;
            const savedTheme = localStorage.getItem(themeKey) || 'dark';
            const savedStyle = localStorage.getItem(styleKey) || 'original';

            function applyTheme(theme) {
                const light = theme === 'light';
                body.classList.toggle('theme-light', light);
                body.classList.toggle('theme-dark', !light);
                document.documentElement.dataset.appTheme = light ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', light ? 'light' : 'dark');
                if (!toggle) return;
                const themeLabel = light ? 'Activer le mode sombre' : 'Activer le mode clair';
                toggle.setAttribute('aria-label', themeLabel);
                toggle.title = themeLabel;
                const icon = toggle.querySelector('[data-theme-icon]');
                const label = toggle.querySelector('[data-theme-label]');
                if (icon) icon.textContent = light ? '☾' : '☀';
                if (label) label.textContent = light ? 'Sombre' : 'Clair';
            }

            applyTheme(savedTheme);
            function normalizeStyle(style) {
                if (style === 'atlas' || style === 'alternate') return 'original';
                if (style === 'glass') return 'glass';
                if (style === 'macos' || style === 'mac' || style === 'mac-os') return 'macos';
                if (style === 'clarity' || style === 'mac-clair') return 'clarity';
                if (style === 'institut' || style === 'accueil') return 'institut';
                return 'original';
            }
            function syncSettingsDropdownDirection() {
                const settingsDrop = document.querySelector('[data-settings-dropdown]');
                if (!settingsDrop) return;
                settingsDrop.classList.remove('dropup');
            }

            function applyStyle(style) {
                const normalized = normalizeStyle(style);
                if (localStorage.getItem(styleKey) === 'alternate' || localStorage.getItem(styleKey) === 'atlas') {
                    localStorage.setItem(styleKey, 'original');
                }
                body.classList.remove('theme-alternate', 'theme-atlas');
                body.classList.toggle('theme-glass', normalized === 'glass');
                body.classList.toggle('theme-macos', normalized === 'macos');
                body.classList.toggle('theme-clarity', normalized === 'clarity');
                body.classList.toggle('theme-institut', normalized === 'institut');
                document.documentElement.dataset.appStyle = normalized;
                styleSelects.forEach(function (el) {
                    if (el.value !== normalized) el.value = normalized;
                });
                syncSettingsDropdownDirection();
            }

            function currentStyle() {
                if (body.classList.contains('theme-macos')) return 'macos';
                if (body.classList.contains('theme-clarity')) return 'clarity';
                if (body.classList.contains('theme-glass')) return 'glass';
                if (body.classList.contains('theme-institut')) return 'institut';
                return 'original';
            }

            function syncThemeToFrames(theme, style) {
                document.querySelectorAll('iframe').forEach(function (frame) {
                    try {
                        if (!frame.contentWindow) return;
                        frame.contentWindow.postMessage({
                            type: 'app-theme-sync',
                            scope: themeScope,
                            theme: theme,
                            style: style
                        }, window.location.origin);
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
                if (event.data.scope && event.data.scope !== themeScope) return;
                if (event.data.theme) applyTheme(event.data.theme);
                if (event.data.style) applyStyle(event.data.style);
            });
            window.addEventListener('storage', function (event) {
                if (event.key === themeKey && event.newValue) applyTheme(event.newValue);
                if (event.key === styleKey && event.newValue) applyStyle(event.newValue);
            });
            if (toggle) {
                toggle.addEventListener('click', function () {
                    const nextTheme = body.classList.contains('theme-light') ? 'dark' : 'light';
                    localStorage.setItem(themeKey, nextTheme);
                    applyTheme(nextTheme);
                    broadcastAppearance();
                });
            }
            styleSelects.forEach(function (el) {
                el.addEventListener('change', function () {
                    const nextStyle = normalizeStyle(el.value);
                    localStorage.setItem(styleKey, nextStyle);
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
                    if (link.hasAttribute('download') || link.hasAttribute('data-file-download') || !link.href) {
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
                return body.classList.contains('theme-macos') || body.classList.contains('theme-clarity');
            }

            function clearWindowInline() {
                if (!win) return;
                win.style.left = '';
                win.style.top = '';
                win.style.right = '';
                win.style.bottom = '';
                win.style.position = '';
                win.style.width = '';
                win.style.height = '';
                win.style.margin = '';
            }

            function lockWindowBox() {
                if (!win) return;
                var rect = win.getBoundingClientRect();
                win.classList.remove('is-maximized');
                win.style.margin = '0';
                win.style.position = 'fixed';
                win.style.left = rect.left + 'px';
                win.style.top = rect.top + 'px';
                win.style.width = rect.width + 'px';
                win.style.height = rect.height + 'px';
            }

            function restoreWindow() {
                if (!win) return;
                win.classList.remove('is-minimized', 'is-closed', 'is-dragging', 'is-maximized');
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
                        win.classList.remove('is-minimized');
                        lockWindowBox();
                        win.classList.add('is-closed');
                    } else if (action === 'minimize') {
                        if (win.classList.contains('is-minimized')) {
                            restoreWindow();
                            return;
                        }
                        win.classList.remove('is-closed');
                        lockWindowBox();
                        window.requestAnimationFrame(function () {
                            win.classList.add('is-minimized');
                        });
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
            document.querySelectorAll('[data-macos-dock] a, [data-macos-dock] button').forEach(function (link) {
                const href = link.getAttribute('href') || '';
                const path = window.location.pathname.replace(/\\/g, '/');
                if (href && (path.endsWith('/' + href) || (href === 'index.php' && /\/(index\.php)?$/.test(path)))) {
                    link.classList.add('is-active');
                }
                link.addEventListener('click', function () {
                    if (body.classList.contains('theme-clarity')) {
                        if (win) win.classList.remove('is-closed');
                        return;
                    }
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
                        offsetY: event.clientY - rect.top,
                        width: rect.width,
                        height: rect.height
                    };
                    titlebar.classList.add('is-grabbing');
                    win.classList.add('is-dragging');
                    win.style.margin = '0';
                    win.style.position = 'fixed';
                    win.style.left = rect.left + 'px';
                    win.style.top = rect.top + 'px';
                    win.style.width = rect.width + 'px';
                    win.style.height = rect.height + 'px';
                    titlebar.setPointerCapture(event.pointerId);
                });
                titlebar.addEventListener('pointermove', function (event) {
                    if (!drag) return;
                    var maxX = Math.max(8, window.innerWidth - Math.min(drag.width, 160));
                    var maxY = Math.max(36, window.innerHeight - 48);
                    var x = Math.min(Math.max(8 - drag.width + 120, event.clientX - drag.offsetX), maxX);
                    var y = Math.min(Math.max(36, event.clientY - drag.offsetY), maxY);
                    win.style.left = x + 'px';
                    win.style.top = y + 'px';
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
                        if (frameTitle) setTitle(frameTitle.replace(/\s*\|[^|]*$/, ''));
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
                        target: link.getAttribute('target') || '',
                        download: link.hasAttribute('data-file-download'),
                        question: link.getAttribute('data-download-question') || ''
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

            function activateMenuForHref(href) {
                var match = null;
                document.querySelectorAll('.dashboard-menu-item').forEach(function (el) {
                    el.classList.remove('is-active');
                    el.removeAttribute('aria-current');
                    if (el.getAttribute('href') === href) match = el;
                });
                if (!match) return;
                match.classList.add('is-active');
                match.setAttribute('aria-current', 'page');
                var group = match.closest('[data-accounts-group]');
                if (group) {
                    group.classList.add('is-open');
                    var toggle = group.querySelector('[data-accounts-toggle]');
                    var sub = group.querySelector('.dashboard-menu-sub');
                    if (toggle) toggle.setAttribute('aria-expanded', 'true');
                    if (sub) sub.hidden = false;
                }
            }

            function openCommandTarget(item) {
                closeCommand();
                if (body.classList.contains('theme-clarity')) {
                    if (win) win.classList.remove('is-closed');
                } else {
                    restoreWindow();
                }
                if (!item || !item.href) return;
                if (item.download && typeof window.appAskDownload === 'function') {
                    window.appAskDownload(item.href, item.question);
                    return;
                }
                var frame = document.querySelector('iframe[name="dashboard-content"]');
                var homePanel = document.querySelector('.dashboard-home-panel');
                var inDashboard = !!(frame && (item.target === 'dashboard-content' || item.href.indexOf('embed=1') !== -1));
                if (inDashboard) {
                    document.body.classList.add('module-open');
                    document.querySelectorAll('#dashboard-content > :not(iframe)').forEach(function (piece) {
                        piece.hidden = true;
                    });
                    frame.hidden = false;
                    frame.src = item.href;
                    setTitle(item.label);
                    activateMenuForHref(item.href);
                    return;
                }
                window.location.assign(item.href);
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
                    a.addEventListener('click', function (event) {
                        event.preventDefault();
                        openCommandTarget(item);
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
                commandInput.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter') return;
                    event.preventDefault();
                    var first = commandList && commandList.querySelector('a');
                    if (first) first.click();
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
        <div class="attestation-accept-overlay" id="downloadConfirmOverlay" hidden>
            <div class="attestation-accept-dialog" role="dialog" aria-modal="true" aria-labelledby="downloadConfirmTitle">
                <div class="attestation-accept-header" data-dialog-drag>
                    <div class="macos-traffic macos-only-control">
                        <button type="button" class="macos-dot macos-dot-close" data-download-answer="no" aria-label="Non" title="Non"></button>
                        <span class="macos-dot macos-dot-min" aria-hidden="true"></span>
                        <span class="macos-dot macos-dot-max" aria-hidden="true"></span>
                    </div>
                    <h2 class="h5 mb-0" id="downloadConfirmTitle">Téléchargement</h2>
                    <button type="button" class="btn-close" data-download-answer="no" aria-label="Fermer"></button>
                </div>
                <div class="attestation-accept-body">
                    <table class="download-confirm-table">
                        <tbody>
                            <tr>
                                <th scope="row">Question</th>
                                <td id="downloadConfirmQuestion">Voulez-vous télécharger ce fichier ?</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="download-confirm-status" id="downloadConfirmStatus" hidden></p>
                </div>
                <div class="attestation-accept-footer">
                    <button type="button" class="btn btn-outline-secondary macos-chip macos-chip-neutral" id="downloadConfirmNo" data-download-answer="no">Non</button>
                    <button type="button" class="btn btn-primary macos-chip macos-chip-ok" id="downloadConfirmYes" data-download-answer="yes">Oui</button>
                </div>
            </div>
        </div>
        <script>
        (function () {
            const overlay = document.getElementById('downloadConfirmOverlay');
            const dialog = overlay ? overlay.querySelector('.attestation-accept-dialog') : null;
            const questionEl = document.getElementById('downloadConfirmQuestion');
            const statusEl = document.getElementById('downloadConfirmStatus');
            const yesBtn = document.getElementById('downloadConfirmYes');
            const noBtn = document.getElementById('downloadConfirmNo');
            if (!overlay || !dialog || !questionEl || !yesBtn) return;

            let pendingUrl = '';
            let busy = false;
            let controller = null;

            function resetPosition() {
                dialog.classList.remove('is-moved', 'is-dragging');
                dialog.style.left = '';
                dialog.style.top = '';
                dialog.style.transform = '';
            }

            function closePrompt() {
                if (busy) {
                    if (controller) controller.abort();
                    return;
                }
                overlay.hidden = true;
                pendingUrl = '';
                resetPosition();
            }

            function fileNameFrom(disposition, blobType) {
                const encoded = /filename\*=UTF-8''([^;]+)/i.exec(disposition || '');
                if (encoded) {
                    try { return decodeURIComponent(encoded[1].replace(/["']/g, '')); } catch (error) { /* keep looking */ }
                }
                const plain = /filename="?([^";]+)"?/i.exec(disposition || '');
                if (plain) return plain[1];
                return blobType.indexOf('pdf') !== -1 ? 'attestation.pdf' : 'attestation.docx';
            }

            async function saveFile(url, signal) {
                const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: signal });
                const blob = await response.blob();
                const head = new Uint8Array(await blob.slice(0, 5).arrayBuffer());
                const signature = Array.from(head).map(function (byte) { return String.fromCharCode(byte); }).join('');
                const isFile = signature.indexOf('%PDF') === 0 || signature.indexOf('PK') === 0;
                if (!response.ok || !isFile) {
                    const text = (await blob.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                    throw new Error(text.slice(0, 280) || 'Le téléchargement a échoué.');
                }
                const objectUrl = URL.createObjectURL(blob);
                const anchor = document.createElement('a');
                anchor.href = objectUrl;
                anchor.download = fileNameFrom(response.headers.get('content-disposition') || '', blob.type || '');
                document.body.appendChild(anchor);
                anchor.click();
                anchor.remove();
                setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 1500);
            }

            window.appAskDownload = function (url, question) {
                if (!url || busy) return;
                pendingUrl = url;
                questionEl.textContent = question || 'Voulez-vous télécharger ce fichier ?';
                statusEl.hidden = true;
                statusEl.textContent = '';
                statusEl.classList.remove('is-error');
                yesBtn.disabled = false;
                yesBtn.textContent = 'Oui';
                if (noBtn) noBtn.disabled = false;
                resetPosition();
                overlay.hidden = false;
            };

            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) closePrompt();
            });
            overlay.querySelectorAll('[data-download-answer="no"]').forEach(function (button) {
                button.addEventListener('click', closePrompt);
            });
            yesBtn.addEventListener('click', async function () {
                if (!pendingUrl || busy) return;
                busy = true;
                yesBtn.disabled = true;
                yesBtn.textContent = 'Préparation…';
                if (noBtn) noBtn.disabled = true;
                statusEl.hidden = false;
                statusEl.classList.remove('is-error');
                statusEl.textContent = 'Préparation du fichier…';
                try {
                    controller = new AbortController();
                    await saveFile(pendingUrl, controller.signal);
                    busy = false;
                    controller = null;
                    if (noBtn) noBtn.disabled = false;
                    closePrompt();
                } catch (error) {
                    busy = false;
                    controller = null;
                    if (error && error.name === 'AbortError') {
                        if (noBtn) noBtn.disabled = false;
                        closePrompt();
                        return;
                    }
                    yesBtn.disabled = false;
                    yesBtn.textContent = 'Oui';
                    if (noBtn) noBtn.disabled = false;
                    statusEl.hidden = false;
                    statusEl.classList.add('is-error');
                    statusEl.textContent = error && error.message ? error.message : 'Le téléchargement a échoué.';
                }
            });

            const handle = overlay.querySelector('[data-dialog-drag]');
            if (handle) {
                let drag = null;
                handle.addEventListener('pointerdown', function (event) {
                    if (event.button !== 0 || event.target.closest('button, a, input')) return;
                    const bounds = overlay.getBoundingClientRect();
                    const rect = dialog.getBoundingClientRect();
                    dialog.classList.add('is-moved', 'is-dragging');
                    dialog.style.left = (rect.left - bounds.left) + 'px';
                    dialog.style.top = (rect.top - bounds.top) + 'px';
                    dialog.style.transform = 'none';
                    drag = {
                        pointerId: event.pointerId,
                        offsetX: event.clientX - rect.left,
                        offsetY: event.clientY - rect.top
                    };
                    handle.setPointerCapture(event.pointerId);
                });
                handle.addEventListener('pointermove', function (event) {
                    if (!drag || drag.pointerId !== event.pointerId) return;
                    const bounds = overlay.getBoundingClientRect();
                    const width = dialog.offsetWidth;
                    const height = dialog.offsetHeight;
                    const left = Math.min(Math.max(0, event.clientX - drag.offsetX - bounds.left), Math.max(0, bounds.width - width));
                    const top = Math.min(Math.max(0, event.clientY - drag.offsetY - bounds.top), Math.max(0, bounds.height - height));
                    dialog.style.left = left + 'px';
                    dialog.style.top = top + 'px';
                });
                function endDrag(event) {
                    if (!drag || (event && drag.pointerId !== event.pointerId)) return;
                    drag = null;
                    dialog.classList.remove('is-dragging');
                }
                handle.addEventListener('pointerup', endDrag);
                handle.addEventListener('pointercancel', endDrag);
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !overlay.hidden) closePrompt();
            });

            function isDownloadLink(link) {
                if (!link || link.hasAttribute('data-dashboard-home')) return false;
                if (link.hasAttribute('data-file-download')) return true;
                const href = link.getAttribute('href') || '';
                return /(?:^|\/)(?:download_all_inscriptions|generate_word|generate_presence|generate_reussite|generate_feuille_presence|recu_paiement|etudiants_export)\.php(?:\?|#|$)/.test(href)
                    || /[?&](?:export|modele)=1(?:&|#|$)/.test(href);
            }

            document.addEventListener('click', function (event) {
                const link = event.target.closest('a[href]');
                if (!isDownloadLink(link)) return;
                event.preventDefault();
                event.stopPropagation();
                const host = window.parent && window.parent !== window && typeof window.parent.appAskDownload === 'function'
                    ? window.parent
                    : window;
                let question = link.getAttribute('data-download-question') || '';
                if (!question) {
                    const label = (link.textContent || link.getAttribute('title') || '').replace(/\s+/g, ' ').trim();
                    question = label ? ('Voulez-vous télécharger « ' + label + ' » ?') : 'Voulez-vous télécharger ce fichier ?';
                }
                host.appAskDownload(link.href, question);
            }, true);
        })();
        </script>
    </body>
    </html>
    <?php 
} ?>

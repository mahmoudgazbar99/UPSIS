<?php
declare(strict_types=1);

/*
 * Shared course-library file explorer (teacher page + student space).
 * Assets: public/assets/course-explorer.css and public/assets/course-explorer.js.
 */

if (!defined('COURSE_EXPLORER_MAX_FILES')) {
    define('COURSE_EXPLORER_MAX_FILES', 20);
}
if (!defined('COURSE_EXPLORER_MAX_BYTES')) {
    define('COURSE_EXPLORER_MAX_BYTES', 26214400);
}
if (!defined('COURSE_EXPLORER_MAX_TOTAL_BYTES')) {
    define('COURSE_EXPLORER_MAX_TOTAL_BYTES', 120 * 1048576);
}

if (!function_exists('course_explorer_icon')) {
    function course_explorer_icon(string $name, string $class = ''): string
    {
        static $paths = [
            'folder' => '<path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h3.2l2 2.3H18a2.5 2.5 0 0 1 2.5 2.5v7.7A2.5 2.5 0 0 1 18 20H6a2.5 2.5 0 0 1-2.5-2.5z"/>',
            'folder-open' => '<path d="M3.5 17.5v-10A2.5 2.5 0 0 1 6 5h3.2l2 2.3H17a2.5 2.5 0 0 1 2.5 2.5V11"/><path d="M3.6 18.6 6.1 12.4A2 2 0 0 1 8 11.1h12a1.4 1.4 0 0 1 1.3 1.9l-2.2 5.7a2 2 0 0 1-1.9 1.3H5a1.5 1.5 0 0 1-1.4-1.4z"/>',
            'pdf' => '<path d="M14 3.5H7.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V8z"/><path d="M14 3.5V8h4.5"/><path d="M9 13h6M9 16.5h4"/>',
            'upload' => '<path d="M12 15V4.5M7.5 9 12 4.5 16.5 9"/><path d="M4.5 15v3a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3"/>',
            'download' => '<path d="M12 4.5V15M7.5 10.5 12 15l4.5-4.5"/><path d="M4.5 15v3a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3"/>',
            'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
            'pencil' => '<path d="M15.2 4.8a2 2 0 0 1 2.8 0l1.2 1.2a2 2 0 0 1 0 2.8L9 19H5v-4z"/><path d="M13.5 6.5l4 4"/>',
            'trash' => '<path d="M4.5 7h15"/><path d="M9.5 7V5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2"/><path d="M6.5 7l.8 11.2a2 2 0 0 0 2 1.8h5.4a2 2 0 0 0 2-1.8L17.5 7"/><path d="M10.2 11v5.5M13.8 11v5.5"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'chevron' => '<path d="m9.5 6 6 6-6 6"/>',
            'home' => '<path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"/>',
            'grid' => '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>',
            'list' => '<path d="M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01"/>',
            'more' => '<path d="M5.5 12h.01M12 12h.01M18.5 12h.01" stroke-width="3"/>',
            'tree' => '<path d="M5 4.5v13a2 2 0 0 0 2 2h2.5M5 10h4.5"/><rect x="11.5" y="7.5" width="8" height="5" rx="1.5"/><rect x="11.5" y="16.5" width="8" height="5" rx="1.5"/>',
            'alert' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.8v5M12 16.2h.01"/>',
        ];
        if (!isset($paths[$name])) {
            return '';
        }
        $classes = trim('ce-icon ce-icon-' . $name . ' ' . $class);

        return '<svg class="' . e($classes) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
    }
}

if (!function_exists('course_explorer_url')) {
    function course_explorer_url(string $baseUrl, ?int $courseId = null, ?int $folderId = null): string
    {
        if ($courseId === null) {
            return $baseUrl;
        }
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . 'cours=' . $courseId . '&dossier=' . ($folderId ?? $courseId);
    }

    function course_explorer_file_url(int $fileId, string $action, string $suffix): string
    {
        return 'cours_fichier.php?id=' . $fileId . '&action=' . ($action === 'download' ? 'download' : 'view') . $suffix;
    }

    function course_explorer_positive_id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /** @return list<array> */
    function course_explorer_children(array $node, string $key): array
    {
        $items = $node[$key] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn ($item): bool => is_array($item) && (int) ($item['id'] ?? 0) > 0));
    }

    /** @return list<array>|null Nodes from $node down to the folder with id $folderId. */
    function course_explorer_find_path(array $node, int $folderId, int $depth = 0): ?array
    {
        if ((int) ($node['id'] ?? 0) === $folderId) {
            return [$node];
        }
        if ($depth > 64) {
            return null;
        }
        foreach (course_explorer_children($node, 'folders') as $child) {
            $path = course_explorer_find_path($child, $folderId, $depth + 1);
            if ($path !== null) {
                array_unshift($path, $node);

                return $path;
            }
        }

        return null;
    }

    function course_explorer_count_label(int $count): string
    {
        if ($count === 0) {
            return 'Vide';
        }

        return $count . ' élément' . ($count > 1 ? 's' : '');
    }

    function course_explorer_summary_label(int $folders, int $files): string
    {
        $parts = [];
        if ($folders > 0) {
            $parts[] = $folders . ' dossier' . ($folders > 1 ? 's' : '');
        }
        if ($files > 0) {
            $parts[] = $files . ' fichier' . ($files > 1 ? 's' : '');
        }

        return $parts === [] ? 'Aucun élément' : implode(' · ', $parts);
    }

    function course_explorer_size_label(array $file): string
    {
        $label = trim((string) ($file['size_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        $bytes = max(0, (int) ($file['size'] ?? 0));
        if ($bytes < 1024) {
            return $bytes . ' o';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 0, ',', ' ') . ' Ko';
        }

        return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
    }

    function course_explorer_tree_items(array $node, array $ctx, int $level, int $courseId): void
    {
        $id = (int) $node['id'];
        $children = course_explorer_children($node, 'folders');
        $hasChildren = $children !== [];
        $isCurrent = $ctx['current'] === $id;
        $expanded = $hasChildren && in_array($id, $ctx['open'], true);
        $name = (string) ($node['nom'] ?? '');
        $groupId = 'course-tree-group-' . $id;
        ?>
        <li class="course-tree-item<?= $isCurrent ? ' is-current' : '' ?>" role="treeitem" aria-level="<?= $level ?>" aria-selected="<?= $isCurrent ? 'true' : 'false' ?>"<?= $hasChildren ? ' aria-expanded="' . ($expanded ? 'true' : 'false') . '"' : '' ?>>
            <div class="course-tree-row">
                <?php if ($hasChildren): ?>
                <button type="button" class="course-tree-toggle" data-course-tree-toggle aria-controls="<?= e($groupId) ?>" aria-label="<?= e(($expanded ? 'Replier' : 'Déplier') . ' « ' . $name . ' »') ?>"><?= course_explorer_icon('chevron') ?></button>
                <?php else: ?>
                <span class="course-tree-spacer" aria-hidden="true"></span>
                <?php endif; ?>
                <a class="course-tree-link" href="<?= e(course_explorer_url($ctx['base'], $courseId, $id)) ?>" title="<?= e($name) ?>" data-course-tree-link data-course-nav<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                    <?= course_explorer_icon($isCurrent || $expanded ? 'folder-open' : 'folder', 'course-tree-icon') ?>
                    <span class="course-tree-label" dir="auto"><?= e($name) ?></span>
                </a>
            </div>
            <?php if ($hasChildren): ?>
            <ul class="course-tree-group" role="group" id="<?= e($groupId) ?>"<?= $expanded ? '' : ' hidden' ?>>
                <?php foreach ($children as $child) {
                    course_explorer_tree_items($child, $ctx, $level + 1, $courseId);
                } ?>
            </ul>
            <?php endif; ?>
        </li>
        <?php
    }

    function course_explorer_action_button(string $icon, string $label, array $data, string $extraClass = ''): string
    {
        $attributes = '';
        foreach ($data as $key => $value) {
            $attributes .= ' data-' . e((string) $key) . '="' . e((string) $value) . '"';
        }

        return '<button type="button" class="course-icon-btn ' . e($extraClass) . '"' . $attributes . ' aria-label="' . e($label) . '" title="' . e($label) . '">' . course_explorer_icon($icon) . '</button>';
    }

    function course_explorer_course_tile(array $course, array $ctx): void
    {
        $id = (int) $course['id'];
        $name = (string) ($course['nom'] ?? '');
        $count = count(course_explorer_children($course, 'folders')) + count(course_explorer_children($course, 'files'));
        $meta = array_values(array_filter([
            trim((string) ($course['semestre'] ?? '')),
            course_explorer_count_label($count),
            $ctx['student'] ? trim((string) ($course['enseignant'] ?? '')) : '',
        ], static fn (string $part): bool => $part !== ''));
        ?>
        <li class="course-item course-item-folder course-item-course">
            <a class="course-item-link" href="<?= e(course_explorer_url($ctx['base'], $id, $id)) ?>" title="<?= e($name) ?>" data-course-nav>
                <span class="course-item-icon"><?= course_explorer_icon('folder') ?></span>
                <span class="course-item-body">
                    <span class="course-item-name" dir="auto"><?= e($name) ?></span>
                    <?php if (trim((string) ($course['matiere'] ?? '')) !== ''): ?>
                    <span class="course-item-sub" dir="auto"><?= e((string) $course['matiere']) ?></span>
                    <?php endif; ?>
                    <span class="course-item-meta"><?= e(implode(' · ', $meta)) ?></span>
                </span>
                <?php if (trim((string) ($course['updated_at'] ?? '')) !== ''): ?>
                <span class="course-item-date"><span class="visually-hidden">Modifié le </span><?= e((string) $course['updated_at']) ?></span>
                <?php endif; ?>
            </a>
            <?php if ($ctx['teacher']): ?>
            <div class="course-item-actions">
                <?= course_explorer_action_button('pencil', 'Renommer le cours « ' . $name . ' »', ['course-action' => 'rename', 'kind' => 'course', 'id' => $id, 'name' => $name]) ?>
                <?= course_explorer_action_button('trash', 'Supprimer le cours « ' . $name . ' »', ['course-action' => 'delete', 'kind' => 'course', 'id' => $id, 'name' => $name, 'return' => 0], 'is-danger') ?>
            </div>
            <?php endif; ?>
        </li>
        <?php
    }

    function course_explorer_render_modals(array $ctx, array $state, int $currentFolder, bool $atRoot): void
    {
        $action = (string) ($state['form_action'] ?? '');
        $csrf = (string) ($state['csrf'] ?? '');
        $subjects = is_array($state['subjects'] ?? null) ? $state['subjects'] : [];
        $hidden = static function (string $name, string $value, string $extra = '') : string {
            return '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '"' . $extra . '>';
        };
        $chrome = static function (string $titleId, string $title, bool $danger = false): void {
            ?>
            <div class="modal-header course-modal-header">
                <div class="macos-traffic macos-only-control">
                    <button type="button" class="macos-dot macos-dot-close" data-bs-dismiss="modal" aria-label="Fermer" title="Fermer"></button>
                    <span class="macos-dot macos-dot-min" aria-hidden="true"></span>
                    <span class="macos-dot macos-dot-max" aria-hidden="true"></span>
                </div>
                <h2 class="modal-title h5<?= $danger ? ' course-modal-title-danger' : '' ?>" id="<?= e($titleId) ?>" data-course-modal-title><?= e($title) ?></h2>
                <button type="button" class="btn-close course-modal-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <?php
        };
        ?>
        <div class="modal fade course-modal" id="courseCreateModal" tabindex="-1" aria-labelledby="courseCreateModalLabel" aria-hidden="true" data-course-modal>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content app-card">
                    <form method="post" action="<?= e($action) ?>" data-course-form autocomplete="off">
                        <?php $chrome('courseCreateModalLabel', 'Nouveau cours'); ?>
                        <div class="modal-body">
                            <?= $hidden('csrf_token', $csrf) ?>
                            <?= $hidden('action', 'create_course') ?>
                            <?= $hidden('return_dossier', '0') ?>
                            <?php if ($subjects === []): ?>
                            <p class="course-modal-note mb-0">Aucune matière ne vous est affectée pour le moment. Contactez la scolarité pour créer un cours.</p>
                            <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label" for="courseCreateSubject">Matière</label>
                                <select class="form-select" id="courseCreateSubject" name="id_mat" required>
                                    <option value="">Choisir une matière…</option>
                                    <?php foreach ($subjects as $subject): if (!is_array($subject)) { continue; } ?>
                                    <option value="<?= (int) ($subject['id_mat'] ?? 0) ?>"><?= e(trim((string) ($subject['code_ecue'] ?? '') . ' — ' . (string) ($subject['description_ecue'] ?? '')) . (trim((string) ($subject['semestre'] ?? '')) !== '' ? ' (' . (string) $subject['semestre'] . ')' : '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="courseCreateName">Nom du cours</label>
                                <input class="form-control" id="courseCreateName" name="nom" maxlength="120" required autocomplete="off" placeholder="Ex. Chapitre 1 — Introduction…" data-course-autofocus>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer course-modal-footer">
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-primary" data-busy-label="Création…"<?= $subjects === [] ? ' disabled' : '' ?>>Créer le cours</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!$atRoot): ?>
        <div class="modal fade course-modal" id="folderCreateModal" tabindex="-1" aria-labelledby="folderCreateModalLabel" aria-hidden="true" data-course-modal>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content app-card">
                    <form method="post" action="<?= e($action) ?>" data-course-form autocomplete="off">
                        <?php $chrome('folderCreateModalLabel', 'Nouveau dossier'); ?>
                        <div class="modal-body">
                            <?= $hidden('csrf_token', $csrf) ?>
                            <?= $hidden('action', 'create_folder') ?>
                            <?= $hidden('id_parent', (string) $currentFolder) ?>
                            <?= $hidden('return_dossier', (string) $currentFolder) ?>
                            <p class="course-modal-note">Le dossier sera créé dans « <span dir="auto"><?= e($ctx['current_name']) ?></span> ».</p>
                            <label class="form-label" for="folderCreateName">Nom du dossier</label>
                            <input class="form-control" id="folderCreateName" name="nom" maxlength="120" required autocomplete="off" placeholder="Ex. Travaux dirigés…" data-course-autofocus>
                        </div>
                        <div class="modal-footer course-modal-footer">
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-primary" data-busy-label="Création…">Créer le dossier</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="modal fade course-modal" id="courseRenameModal" tabindex="-1" aria-labelledby="courseRenameModalLabel" aria-hidden="true" data-course-modal>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content app-card">
                    <form method="post" action="<?= e($action) ?>" data-course-form autocomplete="off">
                        <?php $chrome('courseRenameModalLabel', 'Renommer'); ?>
                        <div class="modal-body">
                            <?= $hidden('csrf_token', $csrf) ?>
                            <?= $hidden('action', 'rename_folder', ' data-course-field="action"') ?>
                            <?= $hidden('id_dossier', '', ' data-course-field="id"') ?>
                            <?= $hidden('return_dossier', (string) $currentFolder, ' data-course-field="return"') ?>
                            <label class="form-label" for="courseRenameName">Nouveau nom</label>
                            <input class="form-control" id="courseRenameName" name="nom" maxlength="120" required autocomplete="off" data-course-field="name" data-course-autofocus>
                        </div>
                        <div class="modal-footer course-modal-footer">
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-primary" data-busy-label="Enregistrement…">Renommer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade course-modal" id="courseDeleteModal" tabindex="-1" aria-labelledby="courseDeleteModalLabel" aria-describedby="courseDeleteModalText" aria-hidden="true" data-course-modal>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content app-card">
                    <form method="post" action="<?= e($action) ?>" data-course-form>
                        <?php $chrome('courseDeleteModalLabel', 'Confirmer la suppression', true); ?>
                        <div class="modal-body">
                            <?= $hidden('csrf_token', $csrf) ?>
                            <?= $hidden('action', 'delete_folder', ' data-course-field="action"') ?>
                            <?= $hidden('id_dossier', '', ' data-course-field="id"') ?>
                            <?= $hidden('return_dossier', (string) $currentFolder, ' data-course-field="return"') ?>
                            <div class="course-delete-message">
                                <span class="course-delete-icon"><?= course_explorer_icon('alert') ?></span>
                                <div>
                                    <p class="mb-1 fw-semibold" id="courseDeleteModalText" data-course-field="message" dir="auto">Supprimer cet élément ?</p>
                                    <p class="course-modal-note mb-0">Cette action est définitive.</p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer course-modal-footer">
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal" data-course-autofocus>Annuler</button>
                            <button type="submit" class="btn course-btn-danger" data-busy-label="Suppression…"><?= course_explorer_icon('trash') ?><span>Supprimer</span></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    function course_explorer_render_upload(array $state, int $currentFolder): void
    {
        $inputId = 'courseUploadInput';
        ?>
        <form class="course-upload" method="post" action="<?= e((string) ($state['form_action'] ?? '')) ?>" enctype="multipart/form-data" data-course-upload data-max-files="<?= COURSE_EXPLORER_MAX_FILES ?>" data-max-bytes="<?= COURSE_EXPLORER_MAX_BYTES ?>" data-max-total="<?= COURSE_EXPLORER_MAX_TOTAL_BYTES ?>">
            <input type="hidden" name="csrf_token" value="<?= e((string) ($state['csrf'] ?? '')) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="id_dossier" value="<?= $currentFolder ?>">
            <input type="hidden" name="return_dossier" value="<?= $currentFolder ?>">
            <label class="course-dropzone" for="<?= $inputId ?>" data-course-dropzone>
                <input class="course-dropzone-input" type="file" id="<?= $inputId ?>" name="pdfs[]" multiple accept="application/pdf,.pdf" data-course-upload-input aria-describedby="courseUploadHint courseUploadFeedback">
                <span class="course-dropzone-icon"><?= course_explorer_icon('upload') ?></span>
                <span class="course-dropzone-copy">
                    <span class="course-dropzone-title">Glissez vos PDF ici ou <span class="course-dropzone-link">parcourez vos fichiers</span></span>
                    <span class="course-dropzone-hint" id="courseUploadHint">PDF uniquement · 25 Mo max par fichier · <?= COURSE_EXPLORER_MAX_FILES ?> fichiers et 120 Mo max par import</span>
                </span>
            </label>
            <div class="course-upload-actions">
                <button type="button" class="btn btn-outline-light" data-course-upload-clear hidden>Effacer la sélection</button>
                <button type="submit" class="btn btn-primary course-btn" data-course-upload-submit data-busy-label="Import en cours…"><?= course_explorer_icon('upload') ?><span>Importer</span></button>
            </div>
            <div class="course-upload-feedback" id="courseUploadFeedback" aria-live="polite">
                <p class="course-upload-summary" data-course-upload-summary hidden></p>
                <ul class="course-upload-list" data-course-upload-list hidden></ul>
                <ul class="course-upload-errors" data-course-upload-errors hidden></ul>
            </div>
        </form>
        <?php
    }
}

if (!function_exists('course_explorer_render')) {
    function course_explorer_render(array $courses, string $mode, array $state): void
    {
        $mode = $mode === 'teacher' ? 'teacher' : 'student';
        $teacher = $mode === 'teacher';
        $baseUrl = (string) ($state['base_url'] ?? ($teacher ? 'mes_cours.php?embed=1' : 'dashboard.php?panel=cours'));
        $suffix = (string) ($state['download_suffix'] ?? '');
        $courses = array_values(array_filter($courses, static fn ($course): bool => is_array($course) && (int) ($course['id'] ?? 0) > 0));

        $course = null;
        $path = [];
        $courseId = course_explorer_positive_id($state['course'] ?? null);
        if ($courseId !== null) {
            foreach ($courses as $candidate) {
                if ((int) $candidate['id'] === $courseId) {
                    $course = $candidate;
                    break;
                }
            }
        }
        if ($course !== null) {
            $folderId = course_explorer_positive_id($state['folder'] ?? null) ?? (int) $course['id'];
            $path = course_explorer_find_path($course, $folderId) ?? [];
            if ($path === []) {
                $course = null;
            }
        }
        $atRoot = $course === null;
        $folder = $atRoot ? null : $path[count($path) - 1];
        $currentFolder = $atRoot ? 0 : (int) $folder['id'];
        $courseRootId = $atRoot ? 0 : (int) $course['id'];

        $ctx = [
            'base' => $baseUrl,
            'teacher' => $teacher,
            'student' => !$teacher,
            'current' => $currentFolder,
            'open' => array_map(static fn (array $node): int => (int) $node['id'], $path),
            'current_name' => $atRoot ? 'Mes cours' : (string) ($folder['nom'] ?? ''),
        ];
        $paneClass = $teacher ? ' app-card' : '';
        $subFolders = $atRoot ? [] : course_explorer_children($folder, 'folders');
        $files = $atRoot ? [] : course_explorer_children($folder, 'files');
        ?>
        <div class="course-explorer<?= $teacher ? '' : ' is-entering' ?>" data-course-explorer data-mode="<?= e($mode) ?>" data-current-folder="<?= $currentFolder ?>">
            <div class="course-explorer-layout">
                <aside class="course-pane course-tree-pane<?= $paneClass ?>" aria-label="Arborescence des cours">
                    <details class="course-tree-details" open data-course-tree-details>
                        <summary class="course-tree-summary">
                            <?= course_explorer_icon('tree') ?>
                            <span>Arborescence</span>
                            <?= course_explorer_icon('chevron', 'course-tree-summary-chevron') ?>
                        </summary>
                        <div class="course-tree-scroll">
                            <a class="course-tree-root" href="<?= e($baseUrl) ?>" data-course-tree-link data-course-nav<?= $atRoot ? ' aria-current="page"' : '' ?>>
                                <?= course_explorer_icon('home') ?>
                                <span>Mes cours</span>
                            </a>
                            <?php if ($courses === []): ?>
                            <p class="course-tree-empty">Aucun cours.</p>
                            <?php else: ?>
                            <ul class="course-tree" role="tree" aria-label="Cours et dossiers" data-course-tree>
                                <?php foreach ($courses as $treeCourse) {
                                    course_explorer_tree_items($treeCourse, $ctx, 1, (int) $treeCourse['id']);
                                } ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </details>
                </aside>

                <section class="course-pane course-explorer-pane<?= $paneClass ?>" aria-labelledby="courseExplorerTitle" data-course-pane>
                    <div class="course-explorer-pane-body">
                    <nav class="course-breadcrumb-nav" aria-label="Fil d’Ariane">
                        <ol class="course-breadcrumb">
                            <li class="course-crumb">
                                <?php if ($atRoot): ?>
                                <span class="course-crumb-current" aria-current="page"><?= course_explorer_icon('home') ?><span>Mes cours</span></span>
                                <?php else: ?>
                                <a class="course-crumb-link" href="<?= e($baseUrl) ?>" data-course-nav><?= course_explorer_icon('home') ?><span>Mes cours</span></a>
                                <?php endif; ?>
                            </li>
                            <?php foreach ($path as $index => $node): $nodeName = (string) ($node['nom'] ?? ''); $last = $index === count($path) - 1; ?>
                            <li class="course-crumb">
                                <?= course_explorer_icon('chevron', 'course-crumb-sep') ?>
                                <?php if ($last): ?>
                                <span class="course-crumb-current" aria-current="page" title="<?= e($nodeName) ?>" dir="auto"><?= e($nodeName) ?></span>
                                <?php else: ?>
                                <a class="course-crumb-link" href="<?= e(course_explorer_url($baseUrl, $courseRootId, (int) $node['id'])) ?>" title="<?= e($nodeName) ?>" dir="auto" data-course-nav><?= e($nodeName) ?></a>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ol>
                    </nav>

                    <header class="course-explorer-head">
                        <div class="course-explorer-heading">
                            <span class="course-explorer-heading-icon"><?= course_explorer_icon($atRoot ? 'home' : 'folder-open') ?></span>
                            <h2 class="course-explorer-title" id="courseExplorerTitle" dir="auto" title="<?= e($ctx['current_name']) ?>"><?= e($ctx['current_name']) ?></h2>
                            <?php if ($teacher && !$atRoot):
                                $isCourseRoot = $currentFolder === $courseRootId;
                                $kind = $isCourseRoot ? 'course' : 'folder';
                                $parentId = $isCourseRoot ? 0 : (int) $path[count($path) - 2]['id'];
                                $noun = $isCourseRoot ? 'le cours' : 'le dossier';
                            ?>
                            <div class="course-explorer-heading-actions">
                                <?= course_explorer_action_button('pencil', 'Renommer ' . $noun . ' « ' . $ctx['current_name'] . ' »', ['course-action' => 'rename', 'kind' => $kind, 'id' => $currentFolder, 'name' => $ctx['current_name'], 'return' => $currentFolder]) ?>
                                <?= course_explorer_action_button('trash', 'Supprimer ' . $noun . ' « ' . $ctx['current_name'] . ' »', ['course-action' => 'delete', 'kind' => $kind, 'id' => $currentFolder, 'name' => $ctx['current_name'], 'return' => $parentId], 'is-danger') ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($atRoot): ?>
                        <p class="course-explorer-lead"><?= $teacher ? 'Organisez vos supports par cours, en dossiers et sous-dossiers.' : 'Supports publiés par vos enseignants, classés par cours.' ?></p>
                        <?php else: ?>
                        <ul class="course-chips" aria-label="Informations du cours">
                            <?php if (trim((string) ($course['matiere'] ?? '')) !== ''): ?>
                            <li class="course-chip"><span class="course-chip-label">Matière</span><span class="course-chip-value" dir="auto" title="<?= e((string) $course['matiere']) ?>"><?= e((string) $course['matiere']) ?></span></li>
                            <?php endif; ?>
                            <?php if (trim((string) ($course['semestre'] ?? '')) !== ''): ?>
                            <li class="course-chip"><span class="course-chip-label">Semestre</span><span class="course-chip-value"><?= e((string) $course['semestre']) ?></span></li>
                            <?php endif; ?>
                            <?php if (!$teacher && trim((string) ($course['enseignant'] ?? '')) !== ''): ?>
                            <li class="course-chip"><span class="course-chip-label">Enseignant</span><span class="course-chip-value" dir="auto"><?= e((string) $course['enseignant']) ?></span></li>
                            <?php endif; ?>
                        </ul>
                        <?php endif; ?>
                    </header>

                    <div class="course-toolbar" role="toolbar" aria-label="Actions du dossier">
                        <div class="course-toolbar-actions">
                            <?php if ($teacher && $atRoot): ?>
                            <button type="button" class="btn btn-primary course-btn" data-course-open="courseCreateModal"><?= course_explorer_icon('plus') ?><span>Nouveau cours</span></button>
                            <?php elseif ($teacher): ?>
                            <button type="button" class="btn btn-primary course-btn" data-course-upload-trigger hidden><?= course_explorer_icon('upload') ?><span>Importer des PDF</span></button>
                            <button type="button" class="btn btn-outline-light course-btn" data-course-open="folderCreateModal"><?= course_explorer_icon('plus') ?><span>Nouveau dossier</span></button>
                            <?php endif; ?>
                            <span class="course-toolbar-summary"><?= e($atRoot ? ($courses === [] ? 'Aucun cours' : count($courses) . ' cours') : course_explorer_summary_label(count($subFolders), count($files))) ?></span>
                        </div>
                        <?php $hasItems = $atRoot ? $courses !== [] : ($subFolders !== [] || $files !== []); ?>
                        <div class="course-view-toggle" role="group" aria-label="Affichage"<?= $hasItems ? '' : ' hidden' ?>>
                            <button type="button" class="course-icon-btn" data-course-view="grid" aria-pressed="true" aria-label="Affichage en grille" title="Grille"><?= course_explorer_icon('grid') ?></button>
                            <button type="button" class="course-icon-btn" data-course-view="list" aria-pressed="false" aria-label="Affichage en liste" title="Liste"><?= course_explorer_icon('list') ?></button>
                        </div>
                    </div>

                    <?php if ($teacher && !$atRoot) {
                        course_explorer_render_upload($state, $currentFolder);
                    } ?>

                    <?php if ($atRoot): ?>
                        <?php if ($courses === []): ?>
                        <div class="course-empty">
                            <span class="course-empty-icon"><?= course_explorer_icon('folder') ?></span>
                            <?php if ($teacher): ?>
                            <p class="course-empty-text">Aucun cours. Créez votre premier cours puis importez des PDF.</p>
                            <button type="button" class="btn btn-primary course-btn" data-course-open="courseCreateModal"><?= course_explorer_icon('plus') ?><span>Créer un cours</span></button>
                            <?php else: ?>
                            <p class="course-empty-text">Aucun cours publié pour vos matières pour le moment.</p>
                            <?php endif; ?>
                        </div>
                        <?php elseif ($teacher): ?>
                        <ul class="course-grid" data-course-grid data-view="grid">
                            <?php foreach ($courses as $rootCourse) {
                                course_explorer_course_tile($rootCourse, $ctx);
                            } ?>
                        </ul>
                        <?php else:
                            $bySemester = [];
                            foreach ($courses as $rootCourse) {
                                $semester = trim((string) ($rootCourse['semestre'] ?? ''));
                                $bySemester[$semester === '' ? "\u{10FFFF}" : $semester][] = $rootCourse;
                            }
                            ksort($bySemester, SORT_NATURAL);
                            foreach ($bySemester as $semester => $semesterCourses): ?>
                        <section class="course-group" aria-labelledby="courseGroup<?= md5((string) $semester) ?>">
                            <h3 class="course-group-title" id="courseGroup<?= md5((string) $semester) ?>"><?= $semester === "\u{10FFFF}" ? 'Autres cours' : 'Semestre ' . e((string) $semester) ?></h3>
                            <ul class="course-grid" data-course-grid data-view="grid">
                                <?php foreach ($semesterCourses as $rootCourse) {
                                    course_explorer_course_tile($rootCourse, $ctx);
                                } ?>
                            </ul>
                        </section>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php elseif ($subFolders === [] && $files === []): ?>
                        <div class="course-empty">
                            <span class="course-empty-icon"><?= course_explorer_icon('folder-open') ?></span>
                            <?php if ($teacher): ?>
                            <p class="course-empty-text">Ce dossier est vide. Créez un sous-dossier ou importez des PDF.</p>
                            <button type="button" class="btn btn-outline-light course-btn" data-course-open="folderCreateModal"><?= course_explorer_icon('plus') ?><span>Nouveau dossier</span></button>
                            <?php else: ?>
                            <p class="course-empty-text">Ce dossier ne contient encore aucun fichier.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <ul class="course-grid" data-course-grid data-view="grid">
                            <?php foreach ($subFolders as $sub):
                                $subId = (int) $sub['id'];
                                $subName = (string) ($sub['nom'] ?? '');
                                $subCount = count(course_explorer_children($sub, 'folders')) + count(course_explorer_children($sub, 'files'));
                            ?>
                            <li class="course-item course-item-folder">
                                <a class="course-item-link" href="<?= e(course_explorer_url($baseUrl, $courseRootId, $subId)) ?>" title="<?= e($subName) ?>" data-course-nav>
                                    <span class="course-item-icon"><?= course_explorer_icon('folder') ?></span>
                                    <span class="course-item-body">
                                        <span class="course-item-name" dir="auto"><?= e($subName) ?></span>
                                        <span class="course-item-meta"><?= e(course_explorer_count_label($subCount)) ?></span>
                                    </span>
                                    <?php if (trim((string) ($sub['updated_at'] ?? '')) !== ''): ?>
                                    <span class="course-item-date"><span class="visually-hidden">Modifié le </span><?= e((string) $sub['updated_at']) ?></span>
                                    <?php endif; ?>
                                </a>
                                <?php if ($teacher): ?>
                                <div class="course-item-actions">
                                    <?= course_explorer_action_button('pencil', 'Renommer le dossier « ' . $subName . ' »', ['course-action' => 'rename', 'kind' => 'folder', 'id' => $subId, 'name' => $subName]) ?>
                                    <?= course_explorer_action_button('trash', 'Supprimer le dossier « ' . $subName . ' »', ['course-action' => 'delete', 'kind' => 'folder', 'id' => $subId, 'name' => $subName], 'is-danger') ?>
                                </div>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                            <?php foreach ($files as $file):
                                $fileId = (int) $file['id'];
                                $fileName = (string) ($file['nom'] ?? '');
                                $viewUrl = course_explorer_file_url($fileId, 'view', $suffix);
                                $downloadUrl = course_explorer_file_url($fileId, 'download', $suffix);
                            ?>
                            <li class="course-item course-item-file">
                                <a class="course-item-link" href="<?= e($viewUrl) ?>" data-course-preview data-course-preview-title="<?= e($fileName) ?>" title="<?= e($fileName) ?>">
                                    <span class="course-item-icon"><?= course_explorer_icon('pdf') ?><span class="course-item-badge" aria-hidden="true">PDF</span></span>
                                    <span class="course-item-body">
                                        <span class="course-item-name" dir="auto"><?= e($fileName) ?></span>
                                        <span class="visually-hidden"> — aperçu</span>
                                        <span class="course-item-meta"><span class="visually-hidden">Taille : </span><?= e(course_explorer_size_label($file)) ?></span>
                                    </span>
                                    <?php if (trim((string) ($file['uploaded_at'] ?? '')) !== ''): ?>
                                    <span class="course-item-date"><span class="visually-hidden">Importé le </span><?= e((string) $file['uploaded_at']) ?></span>
                                    <?php endif; ?>
                                </a>
                                <div class="course-item-actions">
                                    <a class="course-icon-btn" href="<?= e($viewUrl) ?>" data-course-preview data-course-preview-title="<?= e($fileName) ?>" aria-label="<?= e('Aperçu de « ' . $fileName . ' »') ?>" title="Aperçu"><?= course_explorer_icon('eye') ?></a>
                                    <a class="course-icon-btn" href="<?= e($downloadUrl) ?>" download aria-label="<?= e('Télécharger « ' . $fileName . ' »') ?>" title="Télécharger"><?= course_explorer_icon('download') ?></a>
                                    <?php if ($teacher): ?>
                                    <?= course_explorer_action_button('pencil', 'Renommer le fichier « ' . $fileName . ' »', ['course-action' => 'rename', 'kind' => 'file', 'id' => $fileId, 'name' => $fileName]) ?>
                                    <?= course_explorer_action_button('trash', 'Supprimer le fichier « ' . $fileName . ' »', ['course-action' => 'delete', 'kind' => 'file', 'id' => $fileId, 'name' => $fileName], 'is-danger') ?>
                                    <?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    </div>
                </section>
            </div>
            <?php if ($teacher) {
                course_explorer_render_modals($ctx, $state, $currentFolder, $atRoot);
            } ?>
        </div>
        <?php
    }
}

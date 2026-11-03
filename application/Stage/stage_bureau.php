<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_once app_path('support/stage_service.php');
require_permission('stage.view');
stage_service_ensure();

$section = (string) ($_GET['section'] ?? 'tableau');
$sections = [
    'tableau' => 'Tableau de bord',
    'campagnes' => 'Campagnes de stage',
    'obligatoires' => 'Stages obligatoires',
    'ete' => 'Stages d’été',
    'etudiants' => 'Étudiants en stage',
    'etablissements' => 'Établissements d’accueil',
    'affectations' => 'Affectations',
    'presences' => 'Présences et absences',
    'modeles' => 'Documents et modèles PDF',
    'evaluations' => 'Évaluations',
    'rapports' => 'Rapports et statistiques',
    'parametres' => 'Paramètres du service',
];
if (!isset($sections[$section])) {
    $section = 'tableau';
}
$embed = app_is_embedded() ? '&embed=1' : '';
$base = 'stage_bureau.php?section=';
$canWrite = role_can('stage.allocate') || is_admin();
$message = '';
$error = '';
$actor = is_stage() ? current_stage_id() : (is_teacher() ? current_teacher_id() : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) {
        http_response_code(403);
        exit('Action non autorisée pour ce compte.');
    }
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'campagne') {
            stage_service_save_campaign($_POST, $actor);
            $message = 'La campagne est enregistrée.';
            $section = 'campagnes';
        } elseif ($action === 'affectation') {
            stage_service_assign(
                (int) ($_POST['id_campagne'] ?? 0),
                (int) ($_POST['id_etudiant'] ?? 0),
                (int) ($_POST['id_organisme'] ?? 0),
                (int) ($_POST['id_service'] ?? 0),
                (int) ($_POST['id_enseignant'] ?? 0),
                $actor
            );
            $message = 'L’étudiant est affecté.';
            $section = 'affectations';
        } elseif ($action === 'presence') {
            db()->prepare(
                'INSERT INTO stage_suivi (id_placement, jour, heures, observation, auteur_id) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                (int) ($_POST['id_placement'] ?? 0),
                (string) ($_POST['jour'] ?? ''),
                (float) str_replace(',', '.', (string) ($_POST['heures'] ?? '0')),
                mb_substr(trim((string) ($_POST['observation'] ?? '')), 0, 500),
                $actor,
            ]);
            $message = 'La présence est enregistrée.';
            $section = 'presences';
        } elseif ($action === 'demande') {
            $status = (string) ($_POST['statut'] ?? '');
            if (!in_array($status, ['acceptee', 'refusee', 'complement'], true)) {
                throw new InvalidArgumentException('Statut de demande inconnu.');
            }
            db()->prepare('UPDATE stage_demande SET statut = ?, motif = ? WHERE id_demande = ?')
                ->execute([$status, mb_substr(trim((string) ($_POST['motif'] ?? '')), 0, 500), (int) ($_POST['id_demande'] ?? 0)]);
            $message = 'La demande est mise à jour.';
            $section = 'ete';
        } elseif ($action === 'modele') {
            $stored = stage_service_store_pdf((array) ($_FILES['pdf'] ?? []));
            db()->prepare(
                'INSERT INTO stage_modele (nom, description, id_type, id_program, id_spec, niveau, annee_label, id_categorie, stored_name, file_size, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                mb_substr(trim((string) ($_POST['nom'] ?? '')), 0, 160),
                mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500),
                (int) ($_POST['id_type'] ?? 0) ?: null,
                (int) ($_POST['id_program'] ?? 0) ?: null,
                (int) ($_POST['id_spec'] ?? 0) ?: null,
                trim((string) ($_POST['niveau'] ?? '')),
                trim((string) ($_POST['annee_label'] ?? '')),
                (int) ($_POST['id_categorie'] ?? 0) ?: null,
                $stored['stored'],
                $stored['size'],
                $actor,
            ]);
            $message = 'Le modèle PDF est enregistré.';
            $section = 'modeles';
        } elseif ($action === 'remplacer_modele') {
            $id = (int) ($_POST['id_modele'] ?? 0);
            $current = db()->prepare('SELECT * FROM stage_modele WHERE id_modele = ?');
            $current->execute([$id]);
            $currentRow = $current->fetch();
            if (!$currentRow) {
                throw new InvalidArgumentException('Modèle introuvable.');
            }
            $stored = stage_service_store_pdf((array) ($_FILES['pdf'] ?? []));
            db()->prepare("UPDATE stage_modele SET statut = 'archive' WHERE id_modele = ?")->execute([$id]);
            db()->prepare(
                'INSERT INTO stage_modele (nom, description, id_type, id_program, id_spec, niveau, annee_label, id_categorie, stored_name, file_size, statut, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $currentRow['nom'],
                $currentRow['description'],
                $currentRow['id_type'],
                $currentRow['id_program'],
                $currentRow['id_spec'],
                $currentRow['niveau'],
                $currentRow['annee_label'],
                $currentRow['id_categorie'],
                $stored['stored'],
                $stored['size'],
                'actif',
                $actor,
            ]);
            $message = 'Une nouvelle version du modèle est enregistrée. L’ancienne reste archivée.';
            $section = 'modeles';
        } elseif ($action === 'supprimer_modele') {
            $id = (int) ($_POST['id_modele'] ?? 0);
            $current = db()->prepare('SELECT stored_name FROM stage_modele WHERE id_modele = ?');
            $current->execute([$id]);
            $storedName = (string) $current->fetchColumn();
            if ($storedName === '') {
                throw new InvalidArgumentException('Modèle introuvable.');
            }
            $path = stage_service_model_path($storedName);
            db()->prepare('DELETE FROM stage_modele WHERE id_modele = ?')->execute([$id]);
            if (is_file($path)) {
                unlink($path);
            }
            $message = 'Le modèle est supprimé.';
            $section = 'modeles';
        } elseif ($action === 'evaluation') {
            $appreciations = [];
            foreach ((array) ($_POST['critere'] ?? []) as $criterionId => $value) {
                $appreciations[(int) $criterionId] = (string) $value;
            }
            stage_eval_save(
                (int) ($_POST['id_placement'] ?? 0),
                'responsable',
                $appreciations,
                trim((string) ($_POST['commentaire'] ?? '')),
                $actor,
                true
            );
            $message = 'L’évaluation est enregistrée.';
            $section = 'evaluations';
        } elseif ($action === 'taille_pdf') {
            $mb = (int) ($_POST['stage_pdf_max_mb'] ?? 0);
            if ($mb < 1 || $mb > 40) {
                throw new InvalidArgumentException('Indiquez une taille entre 1 et 40 Mo.');
            }
            $existing = db()->prepare('SELECT setting_key FROM system_settings WHERE setting_key = ?');
            $existing->execute(['stage_pdf_max_mb']);
            if ($existing->fetchColumn()) {
                db()->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([(string) $mb, 'stage_pdf_max_mb']);
            } else {
                db()->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['stage_pdf_max_mb', (string) $mb]);
            }
            $message = 'La taille maximale des PDF est enregistrée.';
            $section = 'parametres';
        } elseif ($action === 'archiver_modele') {
            db()->prepare("UPDATE stage_modele SET statut = 'archive' WHERE id_modele = ?")->execute([(int) ($_POST['id_modele'] ?? 0)]);
            $message = 'Le modèle est archivé.';
            $section = 'modeles';
        } elseif ($action === 'critere') {
            $label = trim((string) ($_POST['libelle'] ?? ''));
            $code = trim((string) ($_POST['code'] ?? ''));
            if ($label === '' || !preg_match('/^[a-z0-9_]{2,40}$/', $code)) {
                throw new InvalidArgumentException('Indiquez un code technique et un libellé.');
            }
            db()->prepare('INSERT INTO stage_critere (code, libelle, cible, obligatoire, active) VALUES (?, ?, ?, 0, 1)')
                ->execute([$code, mb_substr($label, 0, 180), 'responsable']);
            $message = 'Le critère d’évaluation est ajouté.';
            $section = 'parametres';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('stage_bureau: ' . $exception->getMessage());
        $error = 'L’enregistrement n’a pas pu aboutir.';
    }
}

if ((int) ($_GET['justificatif'] ?? 0) > 0) {
    $piece = db()->prepare('SELECT stored_name FROM stage_demande WHERE id_demande = ?');
    $piece->execute([(int) $_GET['justificatif']]);
    $storedName = (string) $piece->fetchColumn();
    if ($storedName === '') {
        http_response_code(404);
        exit('Document introuvable.');
    }
    $path = stage_service_model_path($storedName);
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="justificatif-stage.pdf"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

if (($_GET['telecharger'] ?? '') !== '') {
    $model = db()->prepare('SELECT * FROM stage_modele WHERE id_modele = ?');
    $model->execute([(int) $_GET['telecharger']]);
    $row = $model->fetch();
    if (!$row) {
        http_response_code(404);
        exit('Document introuvable.');
    }
    $path = stage_service_model_path((string) $row['stored_name']);
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ((($_GET['apercu'] ?? '') === '1') ? 'inline' : 'attachment') . '; filename="modele-stage.pdf"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

$year = trim((string) ($_GET['annee'] ?? ''));
$programId = (int) ($_GET['programme'] ?? 0);
$specId = (int) ($_GET['specialite'] ?? 0);
$programs = academic_programs();
$specialites = stage_service_specialites($programId > 0 ? $programId : null);
$levels = study_levels($programId > 0 ? $programId : null);
$types = stage_service_types();
$teachers = stage_service_teachers();
$periods = academic_periods();
$years = stage_service_years();
$hosts = stage_service_hosts();
$page = max(1, (int) ($_GET['page'] ?? 1));

page_head($sections[$section], 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Service des stages</p>
  <h1 class="display-6 fw-bold mb-1"><?= e($sections[$section]) ?></h1>
  <p class="text-secondary mb-0">Les listes proviennent des étudiants, enseignants, programmes, spécialités et niveaux déjà enregistrés. Cette installation n’a pas de tables faculté ou département séparées.</p>
</div>
<?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Service des stages">
  <?php foreach ($sections as $key => $label): ?>
    <a class="btn btn-sm <?= $section === $key ? 'btn-primary' : 'btn-outline-light' ?>" href="<?= e($base . $key . $embed) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<form method="get" class="row g-2 mb-4">
  <input type="hidden" name="section" value="<?= e($section) ?>">
  <?php if (app_is_embedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
  <div class="col-md-3"><label class="form-label">Année</label><select class="form-select" name="annee"><option value="">Toutes</option><?php foreach ($years as $item): ?><option value="<?= e($item) ?>" <?= $year === $item ? 'selected' : '' ?>><?= e($item) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Programme</label><select class="form-select" name="programme" onchange="this.form.submit()"><option value="0">Tous</option><?php foreach ($programs as $program): ?><option value="<?= (int) $program['id_program'] ?>" <?= $programId === (int) $program['id_program'] ? 'selected' : '' ?>><?= e((string) $program['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Spécialité</label><select class="form-select" name="specialite"><option value="0">Toutes</option><?php foreach ($specialites as $spec): ?><option value="<?= (int) $spec['id_spec'] ?>" <?= $specId === (int) $spec['id_spec'] ? 'selected' : '' ?>><?= e((string) $spec['nom_spec']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-light w-100">Filtrer</button></div>
</form>
<?php if ($section === 'tableau' || $section === 'rapports'):
    $stats = stage_service_dashboard($year, $programId, $specId);
    $max = 1;
    foreach ($stats['statuts'] as $row) { $max = max($max, (int) $row['total']); }
?>
<div class="row g-3 mb-4">
  <?php foreach ([
      'Stages' => $stats['stages'],
      'Stages obligatoires' => $stats['obligatoires'],
      'Stages d’été' => $stats['ete'],
      'Stages en cours' => $stats['en_cours'],
      'Stages terminés' => $stats['termines'],
      'Demandes en attente' => $stats['demandes'],
      'Étudiants en stage' => $stats['etudiants'],
      'Établissements' => $stats['etablissements'],
      'Conventions actives' => $stats['conventions'],
      'Modèles PDF' => $stats['documents'],
  ] as $label => $value): ?>
    <div class="col-6 col-lg-4"><div class="card app-card"><div class="card-body"><span class="text-secondary"><?= e($label) ?></span><strong class="d-block fs-3"><?= (int) $value ?></strong></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card app-card"><div class="card-body"><h2 class="h5">Par type de stage</h2><?php foreach ($stats['types'] as $row): ?><p class="mb-1"><?= e((string) $row['libelle']) ?> · <?= (int) $row['total'] ?></p><?php endforeach; ?></div></div></div>
  <div class="col-lg-6"><div class="card app-card"><div class="card-body"><h2 class="h5">Par statut</h2><?php foreach ($stats['statuts'] as $row): ?><div class="d-flex align-items-center gap-2 mb-2"><span style="width:7rem"><?= e((string) $row['statut']) ?></span><span style="height:.7rem;width:<?= (int) round(((int) $row['total'] / $max) * 100) ?>%;background:var(--ux-primary,#6200ee);border-radius:99px"></span><strong><?= (int) $row['total'] ?></strong></div><?php endforeach; ?><?php if (!$stats['statuts']): ?><p class="text-secondary mb-0">Aucune affectation pour ce filtre.</p><?php endif; ?></div></div></div>
</div>
<?php elseif ($section === 'campagnes' || $section === 'obligatoires'): ?>
<?php if ($canWrite): ?>
<form method="post" class="card app-card mb-4"><div class="card-body row g-3">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="campagne">
  <div class="col-md-4"><label class="form-label">Intitulé</label><input class="form-control" name="titre" required maxlength="180"></div>
  <div class="col-md-4"><label class="form-label">Type</label><select class="form-select" name="id_type" required><?php foreach ($types as $type): ?><option value="<?= (int) $type['id_type'] ?>"><?= e((string) $type['libelle']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">Année</label><input class="form-control" name="annee_label" required maxlength="20" placeholder="2026-2027"></div>
  <div class="col-md-2"><label class="form-label">Semestre</label><select class="form-select" name="periode_code"><option value="">—</option><?php foreach ($periods as $period): ?><option value="<?= e((string) $period['code']) ?>"><?= e((string) $period['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Programme</label><select class="form-select" name="id_program"><option value="0">—</option><?php foreach ($programs as $program): ?><option value="<?= (int) $program['id_program'] ?>"><?= e((string) $program['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Spécialité</label><select class="form-select" name="id_spec"><option value="0">—</option><?php foreach ($specialites as $spec): ?><option value="<?= (int) $spec['id_spec'] ?>"><?= e((string) $spec['nom_spec']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">Niveau</label><select class="form-select" name="niveau"><option value="">—</option><?php foreach ($levels as $level): ?><option value="<?= e((string) $level['code']) ?>"><?= e((string) $level['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Enseignant responsable</label><select class="form-select" name="id_enseignant"><option value="0">—</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id_enseignant'] ?>"><?= e($teacher['prenom'] . ' ' . $teacher['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">Début</label><input class="form-control" type="date" name="date_debut" required></div>
  <div class="col-md-2"><label class="form-label">Fin</label><input class="form-control" type="date" name="date_fin" required></div>
  <div class="col-md-2"><label class="form-label">Statut</label><select class="form-select" name="statut"><option value="brouillon">Brouillon</option><option value="ouverte">Ouverte</option><option value="archivee">Archivée</option></select></div>
  <div class="col-12"><label class="form-label">Objectifs pédagogiques</label><textarea class="form-control" name="objectifs" rows="2"></textarea></div>
  <div class="col-12"><button class="btn btn-primary">Enregistrer la campagne</button></div>
</div></form>
<?php endif; ?>
<div class="card app-card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Campagne</th><th>Type</th><th>Année</th><th>Période</th><th>Statut</th></tr></thead><tbody>
<?php
$campaigns = db()->query('SELECT c.*, t.libelle AS type_libelle FROM stage_campagne c LEFT JOIN stage_type t ON t.id_type = c.id_type ORDER BY c.date_debut DESC LIMIT 50')->fetchAll();
foreach ($campaigns as $row): ?>
<tr><td><?= e((string) ($row['titre'] !== '' ? $row['titre'] : 'Campagne #' . $row['id_campagne'])) ?></td><td><?= e((string) ($row['type_libelle'] ?? '')) ?></td><td><?= e((string) $row['annee_label']) ?></td><td><?= e($row['date_debut'] . ' → ' . $row['date_fin']) ?></td><td><?= e((string) $row['statut']) ?></td></tr>
<?php endforeach; if (!$campaigns): ?><tr><td colspan="5" class="text-secondary text-center py-4">Aucune campagne.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php elseif ($section === 'affectations'): ?>
<?php if ($canWrite): ?>
<form method="post" class="card app-card mb-4"><div class="card-body row g-3">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="affectation">
  <div class="col-md-4"><label class="form-label">Campagne</label><select class="form-select" name="id_campagne" required><?php foreach (db()->query('SELECT id_campagne, titre, annee_label FROM stage_campagne ORDER BY date_debut DESC') as $row): ?><option value="<?= (int) $row['id_campagne'] ?>"><?= e(($row['titre'] !== '' ? $row['titre'] : 'Campagne #' . $row['id_campagne']) . ' · ' . $row['annee_label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Étudiant</label><select class="form-select" name="id_etudiant" required><option value="">Choisir</option><?php foreach (stage_service_students($specId, '', '', 1, 200)['rows'] as $student): ?><option value="<?= (int) $student['id_etudiant'] ?>"><?= e($student['nom'] . ' ' . $student['prenom'] . ' · ' . $student['num_inscription']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Établissement</label><select class="form-select" name="id_organisme" required><?php foreach ($hosts as $host): ?><option value="<?= (int) $host['id_organisme'] ?>"><?= e((string) $host['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Service</label><select class="form-select" name="id_service"><option value="0">—</option><?php foreach (stage_service_services() as $service): ?><option value="<?= (int) $service['id_service'] ?>"><?= e((string) $service['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Enseignant</label><select class="form-select" name="id_enseignant"><option value="0">—</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id_enseignant'] ?>"><?= e($teacher['prenom'] . ' ' . $teacher['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><button class="btn btn-primary">Affecter</button></div>
</div></form>
<?php endif; ?>
<?php elseif ($section === 'etudiants' || $section === 'presences'):
    $search = trim((string) ($_GET['q'] ?? ''));
    $found = stage_service_students($specId, trim((string) ($_GET['niveau'] ?? '')), $search, $page);
?>
<form method="get" class="row g-2 mb-3"><input type="hidden" name="section" value="<?= e($section) ?>"><?php if (app_is_embedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?><div class="col-md-6"><input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Nom, CIN ou inscription"></div><div class="col-md-2"><button class="btn btn-outline-light">Rechercher</button></div></form>
<div class="card app-card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Étudiant</th><th>Spécialité</th><th>Niveau</th><th>Inscription</th></tr></thead><tbody>
<?php foreach ($found['rows'] as $student): ?><tr><td><?= e($student['prenom'] . ' ' . $student['nom']) ?></td><td><?= e((string) $student['nom_spec']) ?></td><td><?= e((string) $student['niveau']) ?></td><td><?= e((string) $student['num_inscription']) ?></td></tr><?php endforeach; ?>
<?php if (!$found['rows']): ?><tr><td colspan="4" class="text-center text-secondary py-4">Aucun étudiant pour ce filtre.</td></tr><?php endif; ?>
</tbody></table></div></div>
<p class="mt-2 text-secondary"><?= (int) $found['total'] ?> étudiant(s)
<?php if ($page > 1): ?><a href="<?= e($base . $section . '&page=' . ($page - 1) . '&q=' . urlencode($search) . $embed) ?>">Précédent</a><?php endif; ?>
<?php if ($page * 20 < $found['total']): ?><a href="<?= e($base . $section . '&page=' . ($page + 1) . '&q=' . urlencode($search) . $embed) ?>">Suivant</a><?php endif; ?>
</p>
<?php if ($section === 'presences' && $canWrite): ?>
<form method="post" class="card app-card mt-3"><div class="card-body row g-3">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="presence">
  <div class="col-md-4"><label class="form-label">Affectation</label><select class="form-select" name="id_placement" required><?php foreach (db()->query('SELECT p.id_placement, e.nom, e.prenom FROM stage_placement p JOIN etudiant e ON e.id_etudiant = p.id_etudiant ORDER BY p.date_debut DESC LIMIT 200') as $row): ?><option value="<?= (int) $row['id_placement'] ?>"><?= e($row['prenom'] . ' ' . $row['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">Jour</label><input class="form-control" type="date" name="jour" required></div>
  <div class="col-md-2"><label class="form-label">Heures</label><input class="form-control" name="heures" value="0"></div>
  <div class="col-md-4"><label class="form-label">Observation</label><input class="form-control" name="observation" maxlength="500"></div>
  <div class="col-12"><button class="btn btn-primary">Enregistrer la séance</button></div>
</div></form>
<?php endif; ?>
<?php elseif ($section === 'etablissements'): ?>
<div class="card app-card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Organisme</th><th>Catégorie</th><th>Ville</th><th>Capacité</th><th>Convention</th></tr></thead><tbody>
<?php foreach ($hosts as $host): ?><tr><td><?= e((string) $host['nom']) ?></td><td><?= e((string) ($host['categorie'] ?? '')) ?></td><td><?= e((string) $host['ville']) ?></td><td><?= (int) $host['capacite'] ?></td><td><?= e((string) (($host['convention_debut'] ?? '') . ' → ' . ($host['convention_fin'] ?? ''))) ?></td></tr><?php endforeach; ?>
<?php if (!$hosts): ?><tr><td colspan="5" class="text-center text-secondary py-4">Aucun établissement actif.</td></tr><?php endif; ?>
</tbody></table></div></div>
<p class="mt-3"><a href="stage_organismes.php<?= app_is_embedded() ? '?embed=1' : '' ?>">Gérer les organismes et leurs conventions</a></p>
<?php elseif ($section === 'ete'): ?>
<div class="card app-card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Étudiant</th><th>Organisme</th><th>Période</th><th>Statut</th><?php if ($canWrite): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach (db()->query('SELECT d.*, e.nom, e.prenom FROM stage_demande d JOIN etudiant e ON e.id_etudiant = d.id_etudiant ORDER BY d.created_at DESC LIMIT 100') as $row): ?>
<tr><td><?= e($row['prenom'] . ' ' . $row['nom']) ?></td><td><?= e((string) $row['organisme_nom']) ?></td><td><?= e($row['date_debut'] . ' → ' . $row['date_fin']) ?></td><td><?= e((string) $row['statut']) ?><?php if ((string) ($row['stored_name'] ?? '') !== ''): ?> · <a href="stage_bureau.php?section=ete&justificatif=<?= (int) $row['id_demande'] ?><?= e($embed) ?>">Justificatif</a><?php endif; ?></td>
<?php if ($canWrite): ?><td><form method="post" class="d-flex gap-1"><?= '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">' ?><input type="hidden" name="action" value="demande"><input type="hidden" name="id_demande" value="<?= (int) $row['id_demande'] ?>"><select class="form-select form-select-sm" name="statut"><option value="acceptee">Accepter</option><option value="refusee">Refuser</option><option value="complement">Complément</option></select><button class="btn btn-sm btn-primary">OK</button></form></td><?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php elseif ($section === 'modeles'): ?>
<?php if ($canWrite): ?>
<form method="post" enctype="multipart/form-data" class="card app-card mb-4"><div class="card-body row g-3">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="modele">
  <div class="col-md-4"><label class="form-label">Nom du document</label><input class="form-control" name="nom" required maxlength="160"></div>
  <div class="col-md-4"><label class="form-label">Catégorie</label><select class="form-select" name="id_categorie"><?php foreach (stage_service_doc_categories() as $category): ?><option value="<?= (int) $category['id_categorie'] ?>"><?= e((string) $category['libelle']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Type de stage</label><select class="form-select" name="id_type"><option value="0">Tous</option><?php foreach ($types as $type): ?><option value="<?= (int) $type['id_type'] ?>"><?= e((string) $type['libelle']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Programme</label><select class="form-select" name="id_program"><option value="0">Tous</option><?php foreach ($programs as $program): ?><option value="<?= (int) $program['id_program'] ?>"><?= e((string) $program['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Spécialité</label><select class="form-select" name="id_spec"><option value="0">Toutes</option><?php foreach ($specialites as $spec): ?><option value="<?= (int) $spec['id_spec'] ?>"><?= e((string) $spec['nom_spec']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">PDF</label><input class="form-control" type="file" name="pdf" accept="application/pdf,.pdf" required></div>
  <div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="500"></div>
  <div class="col-12"><button class="btn btn-primary">Ajouter un modèle PDF</button></div>
</div></form>
<?php endif; ?>
<div class="card app-card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Document</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach (db()->query('SELECT * FROM stage_modele ORDER BY created_at DESC') as $row): ?>
<tr><td><?= e((string) $row['nom']) ?><br><small class="text-secondary"><?= e((string) $row['description']) ?></small></td><td><?= e((string) $row['statut']) ?></td><td class="text-end"><a href="stage_bureau.php?section=modeles&telecharger=<?= (int) $row['id_modele'] ?>&apercu=1<?= e($embed) ?>">Aperçu</a> · <a href="stage_bureau.php?section=modeles&telecharger=<?= (int) $row['id_modele'] ?><?= e($embed) ?>">Télécharger</a><?php if ($canWrite): ?> · <form method="post" enctype="multipart/form-data" class="d-inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remplacer_modele"><input type="hidden" name="id_modele" value="<?= (int) $row['id_modele'] ?>"><input type="file" name="pdf" accept="application/pdf,.pdf" required aria-label="Remplacer le PDF"><button class="btn btn-link btn-sm p-0">Remplacer</button></form> · <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce modèle ?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="supprimer_modele"><input type="hidden" name="id_modele" value="<?= (int) $row['id_modele'] ?>"><button class="btn btn-link btn-sm p-0">Supprimer</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php elseif ($section === 'evaluations' || $section === 'parametres'): ?>
<div class="card app-card mb-3"><div class="card-body"><h2 class="h5">Critères enregistrés</h2><ul class="mb-0"><?php foreach (db()->query('SELECT * FROM stage_critere WHERE active = 1 ORDER BY libelle') as $row): ?><li><?= e((string) $row['libelle']) ?> <span class="text-secondary">(<?= e((string) $row['cible']) ?>)</span></li><?php endforeach; ?></ul></div></div>
<?php if ($canWrite && $section === 'evaluations'): ?>
<form method="post" class="card app-card mb-3"><div class="card-body row g-3">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="evaluation">
  <div class="col-md-6"><label class="form-label">Affectation</label><select class="form-select" name="id_placement" required><?php foreach (db()->query('SELECT p.id_placement, e.nom, e.prenom FROM stage_placement p JOIN etudiant e ON e.id_etudiant = p.id_etudiant ORDER BY p.date_debut DESC LIMIT 200') as $row): ?><option value="<?= (int) $row['id_placement'] ?>"><?= e($row['prenom'] . ' ' . $row['nom']) ?></option><?php endforeach; ?></select></div>
  <?php foreach (stage_criteria('responsable') as $criterion): ?>
    <div class="col-md-4"><label class="form-label"><?= e((string) $criterion['libelle']) ?></label><select class="form-select" name="critere[<?= (int) $criterion['id_critere'] ?>]"><option value="">—</option><option value="acquis">Acquis</option><option value="en_cours">En cours</option><option value="non_acquis">Non acquis</option></select></div>
  <?php endforeach; ?>
  <div class="col-12"><label class="form-label">Commentaire</label><textarea class="form-control" name="commentaire" rows="2" maxlength="1000"></textarea></div>
  <div class="col-12"><button class="btn btn-primary">Enregistrer l’évaluation</button></div>
</div></form>
<?php endif; ?>
<?php if ($canWrite && $section === 'parametres'): ?>
<form method="post" class="card app-card"><div class="card-body row g-3">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="critere">
  <div class="col-md-4"><label class="form-label">Code</label><input class="form-control" name="code" pattern="[a-z0-9_]{2,40}" required></div>
  <div class="col-md-6"><label class="form-label">Libellé</label><input class="form-control" name="libelle" required maxlength="180"></div>
  <div class="col-12"><button class="btn btn-primary">Ajouter le critère</button></div>
</div></form>
<form method="post" class="card app-card mt-3"><div class="card-body row g-3">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="taille_pdf">
  <div class="col-md-4"><label class="form-label">Taille maximale d’un PDF (Mo)</label><input class="form-control" type="number" name="stage_pdf_max_mb" min="1" max="40" value="15" required></div>
  <div class="col-12"><button class="btn btn-outline-light">Enregistrer</button></div>
</div></form>
<?php endif; ?>
<?php endif; ?>
<?php page_foot();

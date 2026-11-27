<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_once app_path('support/emploi_smart.php');
require_once app_path('support/emploi_scheduler.php');
require_admin();

emploi_smart_ensure();
$pdo = db();
$error = '';
$message = '';
$versionId = max(0, (int) ($_GET['id'] ?? $_POST['id_version'] ?? 0));
$step = max(1, min(6, (int) ($_GET['step'] ?? 1)));

if (($_GET['ajax'] ?? '') === 'groups') {
    header('Content-Type: application/json; charset=utf-8');
    $semestre = trim((string) ($_GET['semestre'] ?? 'S1'));
    $groups = emploi_smart_groups_for_semestre($semestre);
    echo json_encode(['ok' => true, 'groups' => array_map(static function (array $g): array {
        return [
            'id_groupe' => (int) $g['id_groupe'],
            'label' => $g['semestre'] . ' · ' . $g['type_groupe'] . ' ' . $g['numero'],
            'type_groupe' => $g['type_groupe'],
            'numero' => (int) $g['numero'],
            'capacite' => (int) $g['capacite'],
            'id_groupe_parent' => $g['id_groupe_parent'] !== null ? (int) $g['id_groupe_parent'] : null,
        ];
    }, $groups)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create_version') {
            $semestre = trim((string) ($_POST['semestre'] ?? 'S1'));
            $scopeType = (string) ($_POST['scope_type'] ?? 'selected');
            $groupIds = array_values(array_filter(array_map('intval', (array) ($_POST['group_ids'] ?? []))));
            if ($scopeType === 'all_level' || $scopeType === 'speciality') {
                $groupIds = array_map(static fn(array $g): int => (int) $g['id_groupe'], emploi_smart_groups_for_semestre($semestre));
            }
            if ($groupIds === []) {
                throw new InvalidArgumentException('Sélectionnez au moins un groupe.');
            }
            $config = emploi_smart_get_config();
            $versionId = emploi_smart_create_version([
                'label' => trim((string) ($_POST['label'] ?? '')) ?: ('Emploi ' . $semestre . ' · ' . date('Y-m-d H:i')),
                'status' => 'draft',
                'id_year' => (int) ($_POST['id_year'] ?? 0) ?: null,
                'semestre' => $semestre,
                'id_level' => (int) ($_POST['id_level'] ?? 0) ?: null,
                'id_spec' => (int) ($_POST['id_spec'] ?? 0) ?: null,
                'scope' => ['type' => $scopeType, 'group_ids' => $groupIds],
                'config' => $config,
                'created_by' => (string) ($_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'admin'),
            ]);
            // Seed composantes from matiere volumes + groups
            $matieres = emploi_smart_matieres_for_semestre($semestre);
            $rows = [];
            $cfg = $config;
            foreach ($groupIds as $gid) {
                $gMeta = $pdo->prepare('SELECT * FROM groupe WHERE id_groupe = ?');
                $gMeta->execute([$gid]);
                $group = $gMeta->fetch();
                if (!$group) {
                    continue;
                }
                $type = (string) $group['type_groupe'];
                $students = emploi_smart_group_student_count($gid);
                foreach ($matieres as $mat) {
                    $wanted = match ($type) {
                        'Cours' => ((float) $mat['volume_cours'] > 0 ? ['Cours'] : []),
                        'TD' => ((float) $mat['volume_td'] > 0 ? ['TD'] : []),
                        'TP' => ((float) $mat['volume_tp'] > 0 ? ['TP'] : []),
                        default => [],
                    };
                    foreach ($wanted as $seance) {
                        $teachers = emploi_smart_teachers_for_matiere_groupe((int) $mat['id_mat'], $gid);
                        $teacherId = $teachers ? (int) $teachers[0]['id_enseignant'] : 0;
                        if ($teacherId <= 0) {
                            continue;
                        }
                        $duration = match ($seance) {
                            'Cours' => (int) $cfg['default_cours_minutes'],
                            'TD' => (int) $cfg['default_td_minutes'],
                            'TP' => (int) $cfg['default_tp_minutes'],
                            default => 90,
                        };
                        $volume = match ($seance) {
                            'Cours' => (float) $mat['volume_cours'],
                            'TD' => (float) $mat['volume_td'],
                            'TP' => (float) $mat['volume_tp'],
                            default => 0,
                        };
                        $hoursPerSession = $duration / 60;
                        $sessions = $hoursPerSession > 0 ? max(0.25, round(($volume / max(1, (int) $cfg['semester_weeks'])) / $hoursPerSession, 2)) : 1;
                        $rows[] = [
                            'id_mat' => (int) $mat['id_mat'],
                            'type_seance' => $seance,
                            'id_enseignant' => $teacherId,
                            'id_groupe' => $gid,
                            'duration_minutes' => $duration,
                            'sessions_per_week' => $sessions,
                            'frequency' => 'weekly',
                            'lab_category' => $seance === 'TP' ? 'Informatique' : '',
                            'students_count' => $students > 0 ? $students : (int) $group['capacite'],
                            'preferred_resource_type' => emploi_smart_default_resource_type($seance),
                            'notes' => '',
                        ];
                    }
                }
            }
            if ($rows) {
                emploi_smart_replace_composantes($versionId, $rows);
            }
            header('Location: emploi_generateur.php?id=' . $versionId . '&step=2');
            exit;
        }

        $versionId = max(0, (int) ($_POST['id_version'] ?? $versionId));
        $version = $versionId > 0 ? emploi_smart_get_version($versionId) : null;
        if (!$version && $action !== 'create_version') {
            throw new InvalidArgumentException('Version introuvable.');
        }

        if ($action === 'save_resources') {
            $bulkType = (string) ($_POST['bulk_type'] ?? '');
            $bulkQty = (int) ($_POST['bulk_qty'] ?? 0);
            $bulkPrefix = trim((string) ($_POST['bulk_prefix'] ?? ''));
            $bulkCap = max(1, (int) ($_POST['bulk_capacity'] ?? 30));
            $bulkLab = trim((string) ($_POST['bulk_lab_category'] ?? ''));
            if ($bulkType !== '' && $bulkQty > 0) {
                emploi_smart_bulk_create_resources($bulkType, $bulkQty, $bulkPrefix !== '' ? $bulkPrefix : ucfirst($bulkType), $bulkCap, $bulkLab);
            }
            $noms = (array) ($_POST['res_nom'] ?? []);
            $types = (array) ($_POST['res_type'] ?? []);
            $caps = (array) ($_POST['res_cap'] ?? []);
            $labs = (array) ($_POST['res_lab'] ?? []);
            $ids = (array) ($_POST['res_id'] ?? []);
            $actives = (array) ($_POST['res_active'] ?? []);
            foreach ($ids as $idx => $rid) {
                $rid = (int) $rid;
                if ($rid <= 0) {
                    continue;
                }
                emploi_smart_save_resource([
                    'nom' => (string) ($noms[$idx] ?? ''),
                    'type_ressource' => (string) ($types[$idx] ?? 'salle'),
                    'capacite' => (int) ($caps[$idx] ?? 30),
                    'lab_category' => (string) ($labs[$idx] ?? ''),
                    'active' => isset($actives[$rid]) ? 1 : 0,
                    'code' => '',
                ], $rid);
            }
            $message = 'Ressources enregistrées.';
            $step = 2;
        } elseif ($action === 'delete_resource') {
            emploi_smart_delete_resource((int) ($_POST['id_ressource'] ?? 0));
            $message = 'Ressource supprimée.';
            $step = 2;
        } elseif ($action === 'save_composantes') {
            $ids = (array) ($_POST['comp_id'] ?? []);
            $rows = [];
            foreach ($ids as $idx => $cid) {
                $rows[] = [
                    'id_mat' => (int) ($_POST['comp_mat'][$idx] ?? 0),
                    'type_seance' => (string) ($_POST['comp_type'][$idx] ?? ''),
                    'id_enseignant' => (int) ($_POST['comp_ens'][$idx] ?? 0),
                    'id_groupe' => (int) ($_POST['comp_groupe'][$idx] ?? 0),
                    'duration_minutes' => (int) ($_POST['comp_duration'][$idx] ?? 90),
                    'sessions_per_week' => (float) ($_POST['comp_sessions'][$idx] ?? 1),
                    'frequency' => (string) ($_POST['comp_freq'][$idx] ?? 'weekly'),
                    'lab_category' => (string) ($_POST['comp_lab'][$idx] ?? ''),
                    'students_count' => (int) ($_POST['comp_students'][$idx] ?? 0),
                    'preferred_resource_type' => (string) ($_POST['comp_res'][$idx] ?? ''),
                    'notes' => '',
                ];
            }
            // Optional new row
            if (!empty($_POST['add_mat']) && (int) $_POST['add_mat'] > 0) {
                $rows[] = [
                    'id_mat' => (int) $_POST['add_mat'],
                    'type_seance' => (string) ($_POST['add_type'] ?? 'Cours'),
                    'id_enseignant' => (int) ($_POST['add_ens'] ?? 0),
                    'id_groupe' => (int) ($_POST['add_groupe'] ?? 0),
                    'duration_minutes' => (int) ($_POST['add_duration'] ?? 90),
                    'sessions_per_week' => (float) ($_POST['add_sessions'] ?? 1),
                    'frequency' => (string) ($_POST['add_freq'] ?? 'weekly'),
                    'lab_category' => (string) ($_POST['add_lab'] ?? ''),
                    'students_count' => (int) ($_POST['add_students'] ?? 0),
                    'preferred_resource_type' => (string) ($_POST['add_res'] ?? emploi_smart_default_resource_type((string) ($_POST['add_type'] ?? 'Cours'))),
                    'notes' => '',
                ];
            }
            emploi_smart_replace_composantes($versionId, $rows);
            $message = 'Composantes enregistrées.';
            $step = 3;
        } elseif ($action === 'save_config') {
            $days = (array) ($_POST['working_days'] ?? []);
            $config = [
                'label' => 'version',
                'working_days' => $days,
                'morning_start' => (string) ($_POST['morning_start'] ?? '08:15'),
                'morning_end' => (string) ($_POST['morning_end'] ?? '13:00'),
                'afternoon_start' => (string) ($_POST['afternoon_start'] ?? '15:00'),
                'afternoon_end' => (string) ($_POST['afternoon_end'] ?? '18:00'),
                'break_start' => (string) ($_POST['break_start'] ?? '13:00'),
                'break_end' => (string) ($_POST['break_end'] ?? '15:00'),
                'slot_minutes' => (int) ($_POST['slot_minutes'] ?? 15),
                'default_cours_minutes' => (int) ($_POST['default_cours_minutes'] ?? 90),
                'default_td_minutes' => (int) ($_POST['default_td_minutes'] ?? 90),
                'default_tp_minutes' => (int) ($_POST['default_tp_minutes'] ?? 180),
                'max_group_hours_day' => (float) ($_POST['max_group_hours_day'] ?? 8),
                'max_teacher_hours_day' => (float) ($_POST['max_teacher_hours_day'] ?? 8),
                'max_consecutive_minutes' => (int) ($_POST['max_consecutive_minutes'] ?? 180),
                'min_break_minutes' => (int) ($_POST['min_break_minutes'] ?? 0),
                'semester_weeks' => (int) ($_POST['semester_weeks'] ?? 14),
                'soft_weights' => emploi_smart_default_weights(),
                'holidays' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['holidays'] ?? ''))))),
            ];
            emploi_smart_save_config($config);
            emploi_smart_update_version($versionId, ['config' => $config]);
            $message = 'Configuration horaire enregistrée.';
            $step = 5;
        } elseif ($action === 'generate') {
            $only = ($_POST['only_unscheduled'] ?? '') === '1';
            $result = emploi_scheduler_run_for_version(
                $versionId,
                (string) ($_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'admin'),
                $only
            );
            $message = 'Génération terminée : ' . (int) $result['scheduled_count'] . ' planifiées, ' . (int) $result['unscheduled_count'] . ' non planifiées.';
            header('Location: emploi_generateur.php?id=' . $versionId . '&step=6');
            exit;
        } elseif ($action === 'set_status') {
            $status = (string) ($_POST['status'] ?? 'draft');
            if ($status === 'published') {
                emploi_smart_publish($versionId, !empty($_POST['force']));
                $message = 'Emploi publié et synchronisé vers emploi_temps.';
            } else {
                emploi_smart_update_version($versionId, ['status' => $status]);
                $message = 'Statut mis à jour.';
            }
            $step = 6;
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('emploi_generateur: ' . $exception->getMessage());
        $error = 'Opération impossible : ' . $exception->getMessage();
    }
}

$versions = emploi_smart_list_versions();
$version = $versionId > 0 ? emploi_smart_get_version($versionId) : null;
if ($version) {
    $step = max(1, min(6, (int) ($_GET['step'] ?? $step)));
}
$years = academic_years();
$levels = study_levels();
$periods = academic_periods();
$specialites = $pdo->query('SELECT id_spec, nom_spec FROM specialite WHERE active = 1 ORDER BY nom_spec')->fetchAll();
$resources = emploi_smart_list_resources();
$config = $version ? (array) $version['config'] : emploi_smart_get_config();
$composantes = $version ? emploi_smart_list_composantes($versionId) : [];
$run = $version ? emploi_smart_latest_run($versionId) : null;
$sessions = $version ? emploi_smart_list_sessions($versionId) : [];
$teachers = $pdo->query('SELECT id_enseignant, nom, prenom FROM enseignant WHERE active = 1 ORDER BY nom, prenom')->fetchAll();
$matieres = $version ? emploi_smart_matieres_for_semestre((string) $version['semestre']) : [];
$groups = $version ? emploi_smart_groups_for_semestre((string) $version['semestre']) : [];
$dayOptions = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

page_head('Générateur d’emploi du temps', 'schedule');
?>
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
  <div>
    <p class="eyebrow">Pédagogie</p>
    <h1 class="display-6 fw-bold mb-1">Générateur intelligent</h1>
    <p class="text-secondary mb-0">Assistant guidé : sélection académique, ressources, composantes, règles, puis génération contrainte.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-light" href="emploi_temps.php">PDF par groupe</a>
    <?php if ($version): ?>
      <a class="btn btn-outline-light" href="emploi_editeur.php?id=<?= (int) $versionId ?>">Éditeur grille</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if ($version): ?>
  <?php
    $steps = [1 => 'Sélection', 2 => 'Ressources', 3 => 'Composantes', 4 => 'Sous-groupes', 5 => 'Horaires', 6 => 'Résultat'];
  ?>
  <div class="card app-card mb-4"><div class="card-body p-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <?php foreach ($steps as $num => $label): ?>
        <a class="btn btn-sm <?= $step === $num ? 'btn-primary' : 'btn-outline-light' ?>" href="emploi_generateur.php?id=<?= (int) $versionId ?>&step=<?= $num ?>"><?= $num ?>. <?= e($label) ?></a>
      <?php endforeach; ?>
      <span class="ms-auto text-secondary small"><?= e((string) $version['label']) ?> · <?= e((string) $version['status']) ?> · score <?= e((string) $version['quality_score']) ?></span>
    </div>
  </div></div>
<?php endif; ?>

<?php if (!$version): ?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card app-card"><div class="card-body p-4">
      <h2 class="h5 mb-3">1. Nouvelle génération — sélection académique</h2>
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_version">
        <div class="col-md-8">
          <label class="form-label" for="label">Libellé</label>
          <input class="form-control" id="label" name="label" placeholder="Emploi S1 · tronc commun">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="id_year">Année scolaire</label>
          <select class="form-select" id="id_year" name="id_year">
            <option value="">—</option>
            <?php foreach ($years as $year): ?>
              <option value="<?= (int) $year['id_year'] ?>" <?= (($year['status'] ?? '') === 'active') ? 'selected' : '' ?>><?= e((string) $year['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="semestre">Semestre</label>
          <select class="form-select" id="semestre" name="semestre" required>
            <?php foreach ($periods as $period): ?>
              <option value="<?= e((string) $period['code']) ?>"><?= e((string) $period['label']) ?> (<?= e((string) $period['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="id_level">Niveau</label>
          <select class="form-select" id="id_level" name="id_level">
            <option value="">—</option>
            <?php foreach ($levels as $level): ?>
              <option value="<?= (int) $level['id_level'] ?>"><?= e((string) $level['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="id_spec">Spécialité</label>
          <select class="form-select" id="id_spec" name="id_spec">
            <option value="">—</option>
            <?php foreach ($specialites as $spec): ?>
              <option value="<?= (int) $spec['id_spec'] ?>"><?= e((string) $spec['nom_spec']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="scope_type">Portée</label>
          <select class="form-select" id="scope_type" name="scope_type">
            <option value="one">Un groupe</option>
            <option value="selected" selected>Groupes sélectionnés</option>
            <option value="all_level">Tous les groupes du semestre</option>
            <option value="speciality">Toute la spécialité (groupes du semestre)</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Groupes</label>
          <div id="group-boxes" class="row g-2"></div>
          <div class="form-text">Les groupes TD/TP du semestre sont inclus pour les composantes correspondantes.</div>
        </div>
        <div class="col-12">
          <button class="btn btn-primary" type="submit">Créer le brouillon et continuer</button>
        </div>
      </form>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card app-card"><div class="card-body p-4">
      <h2 class="h5 mb-3">Versions existantes</h2>
      <?php if (!$versions): ?>
        <p class="text-secondary mb-0">Aucune version pour l’instant.</p>
      <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($versions as $v): ?>
            <a class="list-group-item list-group-item-action bg-transparent text-light border-secondary" href="emploi_generateur.php?id=<?= (int) $v['id_version'] ?>&step=6">
              <strong><?= e((string) $v['label']) ?></strong>
              <div class="small text-secondary"><?= e((string) $v['semestre']) ?> · <?= e((string) $v['status']) ?> · <?= e((string) $v['created_at']) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<script>
(function () {
  const semestre = document.getElementById('semestre');
  const box = document.getElementById('group-boxes');
  async function loadGroups() {
    box.innerHTML = '<div class="col-12 text-secondary">Chargement…</div>';
    const res = await fetch('emploi_generateur.php?ajax=groups&semestre=' + encodeURIComponent(semestre.value));
    const data = await res.json();
    box.innerHTML = '';
    (data.groups || []).forEach(function (g) {
      const col = document.createElement('div');
      col.className = 'col-md-6';
      col.innerHTML = '<label class="form-check"><input class="form-check-input" type="checkbox" name="group_ids[]" value="' + g.id_groupe + '"> <span class="form-check-label">' + g.label + ' · cap. ' + g.capacite + '</span></label>';
      box.appendChild(col);
    });
  }
  semestre.addEventListener('change', loadGroups);
  loadGroups();
})();
</script>
<?php elseif ($step === 2): ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5 mb-3">2. Ressources (salles, amphithéâtres, laboratoires)</h2>
  <p class="text-secondary">Chaque ressource est identifiable et exclusivité stricte : une ressource = un groupe à la fois.</p>
  <form method="post" class="row g-3 mb-4">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_resources">
    <input type="hidden" name="id_version" value="<?= (int) $versionId ?>">
    <div class="col-md-2">
      <label class="form-label">Type</label>
      <select class="form-select" name="bulk_type">
        <option value="salle">Salle</option>
        <option value="amphi">Amphithéâtre</option>
        <option value="labo">Laboratoire</option>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label">Quantité</label><input class="form-control" type="number" name="bulk_qty" min="0" max="30" value="0"></div>
    <div class="col-md-3"><label class="form-label">Préfixe</label><input class="form-control" name="bulk_prefix" placeholder="Salle / Amphi / Labo Inf"></div>
    <div class="col-md-2"><label class="form-label">Capacité</label><input class="form-control" type="number" name="bulk_capacity" value="30" min="1"></div>
    <div class="col-md-3"><label class="form-label">Catégorie labo</label><input class="form-control" name="bulk_lab_category" placeholder="Informatique / Laboratoire infirmière"></div>
    <?php if ($resources): ?>
      <div class="col-12 table-responsive">
        <table class="table table-dark table-sm align-middle">
          <thead><tr><th>Actif</th><th>Nom</th><th>Type</th><th>Labo</th><th>Capacité</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($resources as $i => $res): ?>
              <tr>
                <td>
                  <input type="hidden" name="res_id[]" value="<?= (int) $res['id_ressource'] ?>">
                  <input class="form-check-input" type="checkbox" name="res_active[<?= (int) $res['id_ressource'] ?>]" value="1" <?= !empty($res['active']) ? 'checked' : '' ?>>
                </td>
                <td><input class="form-control form-control-sm" name="res_nom[]" value="<?= e((string) $res['nom']) ?>"></td>
                <td>
                  <select class="form-select form-select-sm" name="res_type[]">
                    <?php foreach (['salle','amphi','labo'] as $t): ?>
                      <option value="<?= $t ?>" <?= $res['type_ressource'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input class="form-control form-control-sm" name="res_lab[]" value="<?= e((string) $res['lab_category']) ?>"></td>
                <td><input class="form-control form-control-sm" type="number" name="res_cap[]" value="<?= (int) $res['capacite'] ?>" min="1"></td>
                <td>
                  <button class="btn btn-sm btn-outline-danger" formaction="emploi_generateur.php?id=<?= (int) $versionId ?>&step=2" formmethod="post" name="action" value="delete_resource" onclick="this.form.id_ressource.value=<?= (int) $res['id_ressource'] ?>; return confirm('Supprimer ?');">×</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <input type="hidden" name="id_ressource" value="0">
      </div>
    <?php endif; ?>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Enregistrer</button>
      <a class="btn btn-outline-light" href="emploi_generateur.php?id=<?= (int) $versionId ?>&step=3">Étape suivante</a>
    </div>
  </form>
</div></div>

<?php elseif ($step === 3 || $step === 4): ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5 mb-3"><?= $step === 3 ? '3. Composantes Cours / TD / TP' : '4. Sous-groupes et affectations' ?></h2>
  <p class="text-secondary">Une matière peut avoir Cours + TD + TP. Les sous-groupes sont les groupes TD/TP existants. Exclusivité ressource : pas de co-réservation multi-groupes.</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_composantes">
    <input type="hidden" name="id_version" value="<?= (int) $versionId ?>">
    <div class="table-responsive">
      <table class="table table-dark table-sm align-middle">
        <thead>
          <tr>
            <th>Matière</th><th>Type</th><th>Groupe</th><th>Enseignant</th><th>Durée</th><th>/sem</th><th>Fréq.</th><th>Ressource</th><th>Labo</th><th>Étudiants</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($composantes as $i => $c): ?>
            <tr>
              <td>
                <input type="hidden" name="comp_id[]" value="<?= (int) $c['id_composante'] ?>">
                <input type="hidden" name="comp_mat[]" value="<?= (int) $c['id_mat'] ?>">
                <?= e($c['code_ecue']) ?>
              </td>
              <td>
                <select class="form-select form-select-sm" name="comp_type[]">
                  <?php foreach (['Cours','TD','TP'] as $t): ?>
                    <option value="<?= $t ?>" <?= $c['type_seance'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select class="form-select form-select-sm" name="comp_groupe[]">
                  <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id_groupe'] ?>" <?= (int) $c['id_groupe'] === (int) $g['id_groupe'] ? 'selected' : '' ?>><?= e($g['type_groupe'] . ' ' . $g['numero']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select class="form-select form-select-sm" name="comp_ens[]">
                  <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int) $t['id_enseignant'] ?>" <?= (int) $c['id_enseignant'] === (int) $t['id_enseignant'] ? 'selected' : '' ?>><?= e($t['prenom'] . ' ' . $t['nom']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input class="form-control form-control-sm" style="width:5rem" type="number" name="comp_duration[]" value="<?= (int) $c['duration_minutes'] ?>" min="15" step="15"></td>
              <td><input class="form-control form-control-sm" style="width:4rem" type="number" step="0.25" name="comp_sessions[]" value="<?= e((string) $c['sessions_per_week']) ?>"></td>
              <td>
                <select class="form-select form-select-sm" name="comp_freq[]">
                  <?php foreach (['weekly' => 'Chaque semaine', 'biweekly' => 'Toutes les 2 sem.', 'fortnight_a' => 'Semaine A', 'fortnight_b' => 'Semaine B'] as $k => $lab): ?>
                    <option value="<?= $k ?>" <?= $c['frequency'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select class="form-select form-select-sm" name="comp_res[]">
                  <?php foreach (['amphi','salle','labo'] as $t): ?>
                    <option value="<?= $t ?>" <?= ($c['preferred_resource_type'] ?: emploi_smart_default_resource_type($c['type_seance'])) === $t ? 'selected' : '' ?>><?= $t ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input class="form-control form-control-sm" name="comp_lab[]" value="<?= e((string) $c['lab_category']) ?>"></td>
              <td><input class="form-control form-control-sm" style="width:4rem" type="number" name="comp_students[]" value="<?= (int) $c['students_count'] ?>" min="0"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="border-top border-secondary pt-3 mt-3">
      <h3 class="h6">Ajouter une composante</h3>
      <div class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Matière</label>
          <select class="form-select form-select-sm" name="add_mat" data-app-list-prompt data-app-list-title="Matière" data-app-list-search="Code ECUE…" data-app-list-placeholder="Choisir…">
            <option value="">—</option>
            <?php foreach ($matieres as $m): ?>
              <option value="<?= (int) $m['id_mat'] ?>"><?= e($m['code_ecue']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1"><label class="form-label">Type</label><select class="form-select form-select-sm" name="add_type"><option>Cours</option><option>TD</option><option>TP</option></select></div>
        <div class="col-md-2"><label class="form-label">Groupe</label><select class="form-select form-select-sm" name="add_groupe" data-app-list-prompt data-app-list-title="Groupe" data-app-list-search="Groupe…" data-app-list-placeholder="Choisir…"><?php foreach ($groups as $g): ?><option value="<?= (int) $g['id_groupe'] ?>"><?= e($g['type_groupe'].' '.$g['numero']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Enseignant</label><select class="form-select form-select-sm" name="add_ens" data-app-list-prompt data-app-list-title="Enseignant" data-app-list-search="Nom…" data-app-list-placeholder="Choisir…"><?php foreach ($teachers as $t): ?><option value="<?= (int) $t['id_enseignant'] ?>"><?= e($t['prenom'].' '.$t['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label">Min</label><input class="form-control form-control-sm" type="number" name="add_duration" value="90"></div>
        <div class="col-md-1"><label class="form-label">/sem</label><input class="form-control form-control-sm" type="number" step="0.25" name="add_sessions" value="1"></div>
        <div class="col-md-1"><label class="form-label">Ress.</label><select class="form-select form-select-sm" name="add_res"><option>amphi</option><option selected>salle</option><option>labo</option></select></div>
        <div class="col-md-1"><label class="form-label">Étud.</label><input class="form-control form-control-sm" type="number" name="add_students" value="25"></div>
        <div class="col-md-1"><label class="form-label">Labo</label><input class="form-control form-control-sm" name="add_lab"></div>
      </div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-primary" type="submit">Enregistrer</button>
      <a class="btn btn-outline-light" href="emploi_generateur.php?id=<?= (int) $versionId ?>&step=<?= $step === 3 ? 4 : 5 ?>">Étape suivante</a>
    </div>
  </form>
</div></div>

<?php elseif ($step === 5): ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5 mb-3">5. Configuration horaire</h2>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_config">
    <input type="hidden" name="id_version" value="<?= (int) $versionId ?>">
    <div class="col-12">
      <label class="form-label">Jours ouvrés</label>
      <div class="d-flex flex-wrap gap-3">
        <?php foreach ($dayOptions as $day): ?>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="working_days[]" value="<?= e($day) ?>" <?= in_array($day, (array) $config['working_days'], true) ? 'checked' : '' ?>> <span class="form-check-label"><?= e($day) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
      $fields = [
        'morning_start' => 'Début matin', 'morning_end' => 'Fin matin',
        'afternoon_start' => 'Début après-midi', 'afternoon_end' => 'Fin après-midi',
        'break_start' => 'Début pause', 'break_end' => 'Fin pause',
      ];
      foreach ($fields as $name => $label):
    ?>
      <div class="col-md-2"><label class="form-label"><?= e($label) ?></label><input class="form-control" type="time" name="<?= e($name) ?>" value="<?= e((string) $config[$name]) ?>"></div>
    <?php endforeach; ?>
    <div class="col-md-2"><label class="form-label">Granularité (min)</label><input class="form-control" type="number" name="slot_minutes" value="<?= (int) $config['slot_minutes'] ?>"></div>
    <div class="col-md-2"><label class="form-label">Cours défaut</label><input class="form-control" type="number" name="default_cours_minutes" value="<?= (int) $config['default_cours_minutes'] ?>"></div>
    <div class="col-md-2"><label class="form-label">TD défaut</label><input class="form-control" type="number" name="default_td_minutes" value="<?= (int) $config['default_td_minutes'] ?>"></div>
    <div class="col-md-2"><label class="form-label">TP défaut</label><input class="form-control" type="number" name="default_tp_minutes" value="<?= (int) $config['default_tp_minutes'] ?>"></div>
    <div class="col-md-2"><label class="form-label">Max h/groupe/j</label><input class="form-control" type="number" step="0.5" name="max_group_hours_day" value="<?= e((string) $config['max_group_hours_day']) ?>"></div>
    <div class="col-md-2"><label class="form-label">Max h/ens/j</label><input class="form-control" type="number" step="0.5" name="max_teacher_hours_day" value="<?= e((string) $config['max_teacher_hours_day']) ?>"></div>
    <div class="col-md-2"><label class="form-label">Semaines semestre</label><input class="form-control" type="number" name="semester_weeks" value="<?= (int) $config['semester_weeks'] ?>"></div>
    <div class="col-md-6"><label class="form-label">Jours fériés (dates YYYY-MM-DD, séparées par virgule)</label><input class="form-control" name="holidays" value="<?= e(implode(',', (array) ($config['holidays'] ?? []))) ?>"></div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Enregistrer la configuration</button>
      <a class="btn btn-outline-light" href="emploi_generateur.php?id=<?= (int) $versionId ?>&step=6">Voir le résultat</a>
    </div>
  </form>
</div></div>

<?php else: ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
    <div>
      <h2 class="h5 mb-1">Résultat — grille horaire</h2>
      <p class="text-secondary mb-0">Tableau classique (jours × créneaux) avec barre de défilement horizontale.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-start">
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id_version" value="<?= (int) $versionId ?>">
        <input type="hidden" name="only_unscheduled" value="0">
        <button class="btn btn-primary btn-sm" name="action" value="generate" type="submit">Générer</button>
        <button class="btn btn-outline-light btn-sm" name="action" value="generate" type="submit" onclick="this.form.only_unscheduled.value='1'">Régénérer non planifiées</button>
      </form>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id_version" value="<?= (int) $versionId ?>">
        <input type="hidden" name="action" value="set_status">
        <button class="btn btn-outline-light btn-sm" name="status" value="draft">Brouillon</button>
        <button class="btn btn-outline-light btn-sm" name="status" value="review">Sous revue</button>
        <button class="btn btn-outline-light btn-sm" name="status" value="approved">Approuver</button>
        <button class="btn btn-success btn-sm" name="status" value="published" onclick="return confirm('Publier et synchroniser vers emploi_temps ?');">Publier</button>
      </form>
      <a class="btn btn-outline-light btn-sm" href="emploi_editeur.php?id=<?= (int) $versionId ?>">Éditeur</a>
      <a class="btn btn-outline-light btn-sm" href="emploi_export.php?id=<?= (int) $versionId ?>&format=csv">CSV</a>
      <a class="btn btn-outline-light btn-sm" href="emploi_export.php?id=<?= (int) $versionId ?>&format=print" target="_blank">Imprimer</a>
    </div>
  </div>
  <?php if ($run): ?>
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3"><div class="border border-secondary rounded p-3"><div class="small text-secondary">Demandées</div><div class="h4 mb-0"><?= (int) $run['requested_count'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="border border-secondary rounded p-3"><div class="small text-secondary">Planifiées</div><div class="h4 mb-0"><?= (int) $run['scheduled_count'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="border border-secondary rounded p-3"><div class="small text-secondary">Non planifiées</div><div class="h4 mb-0"><?= (int) $run['unscheduled_count'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="border border-secondary rounded p-3"><div class="small text-secondary">Score</div><div class="h4 mb-0"><?= e((string) $run['quality_score']) ?></div></div></div>
    </div>
    <?php foreach ((array) ($run['warnings'] ?? []) as $w): ?>
      <div class="alert alert-warning py-2"><?= e((string) $w) ?></div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="text-secondary">Aucune génération encore. Cliquez sur Générer.</p>
  <?php endif; ?>

  <?php if ($sessions): ?>
    <?= emploi_smart_render_timetable_html($sessions, [
      'days' => (array) ($config['working_days'] ?? ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']),
      'config' => $config,
      'editable' => false,
      'theme' => 'light',
    ]) ?>
  <?php endif; ?>

  <?php if ($run && !empty($run['unscheduled'])): ?>
    <h3 class="h6 mt-4">Séances non planifiées</h3>
    <div class="table-responsive">
      <table class="table table-dark table-sm">
        <thead><tr><th>Type</th><th>Matière</th><th>Groupe</th><th>Durée</th><th>Ressource</th><th>Raison</th><th>Suggestions</th></tr></thead>
        <tbody>
          <?php foreach ($run['unscheduled'] as $u): ?>
            <?php $suggestions = is_string($u['suggestions'] ?? null) ? emploi_smart_json_decode((string) $u['suggestions'], []) : (array) ($u['suggestions'] ?? []); ?>
            <tr>
              <td><?= e((string) $u['type_seance']) ?></td>
              <td><?= (int) $u['id_mat'] ?></td>
              <td><?= (int) $u['id_groupe'] ?></td>
              <td><?= (int) $u['duration_minutes'] ?> min</td>
              <td><?= e((string) $u['required_resource']) ?></td>
              <td><?= e((string) $u['reason']) ?></td>
              <td><ul class="mb-0 small"><?php foreach ($suggestions as $s): ?><li><?= e((string) $s) ?></li><?php endforeach; ?></ul></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div></div>
<?php endif; ?>
<?php page_foot();

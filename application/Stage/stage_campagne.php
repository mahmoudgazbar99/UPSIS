<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_permission('stage.allocate');
access_ensure();
$error = '';
$message = '';
$campaignId = (int) ($_GET['id'] ?? $_POST['id_campagne'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'creer') {
            $start = (string) ($_POST['date_debut'] ?? '');
            $end = (string) ($_POST['date_fin'] ?? '');
            $year = trim((string) ($_POST['annee_label'] ?? ''));
            $period = strtoupper(trim((string) ($_POST['periode_code'] ?? '')));
            if ($start === '' || $end === '' || $end < $start || $year === '') {
                throw new InvalidArgumentException('Indiquez l’année et une période de dates valide.');
            }
            if ($period !== '' && !academic_period_valid($period)) {
                throw new InvalidArgumentException('Cette période n’existe pas dans la structure académique.');
            }
            db()->prepare(
                'INSERT INTO stage_campagne (annee_label, periode_code, date_debut, date_fin, id_spec, niveau, id_groupe, statut, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "brouillon", ?)'
            )->execute([
                $year,
                $period,
                $start,
                $end,
                (int) ($_POST['id_spec'] ?? 0) ?: null,
                trim((string) ($_POST['niveau'] ?? '')),
                (int) ($_POST['id_groupe'] ?? 0) ?: null,
                current_stage_id(),
            ]);
            $campaignId = (int) db()->lastInsertId();
            audit_record('stage', 'campagne', 'Campagne #' . $campaignId . ' ' . $year);
            $message = 'Campagne créée. Définissez ensuite les places de chaque service.';
        } elseif ($action === 'capacite') {
            $campaign = stage_campaign($campaignId);
            if (!$campaign) {
                throw new InvalidArgumentException('Campagne introuvable.');
            }
            $places = $_POST['places'] ?? [];
            $supervisors = $_POST['superviseur'] ?? [];
            if (!is_array($places)) {
                throw new InvalidArgumentException('Capacités invalides.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            $totals = [];
            $limits = [];
            foreach ($places as $serviceId => $value) {
                $serviceId = (int) $serviceId;
                $count = (int) $value;
                if ($count < 0 || $count > 500) {
                    throw new InvalidArgumentException('Chaque capacité doit être comprise entre 0 et 500.');
                }
                $host = $pdo->prepare('SELECT id_organisme, capacite FROM stage_organisme o JOIN stage_service s ON s.id_organisme = o.id_organisme WHERE s.id_service = ?');
                $host->execute([$serviceId]);
                $row = $host->fetch();
                if (!$row) {
                    throw new InvalidArgumentException('Service inconnu.');
                }
                $orgId = (int) $row['id_organisme'];
                $limits[$orgId] = (int) $row['capacite'];
                $totals[$orgId] = ($totals[$orgId] ?? 0) + $count;
                $supervisor = (int) ($supervisors[$serviceId] ?? 0) ?: null;
                if ($supervisor) {
                    $check = $pdo->prepare('SELECT responsabilite FROM stage_user WHERE id_stage = ? AND active = 1');
                    $check->execute([$supervisor]);
                    if ((string) $check->fetchColumn() !== 'superviseur') {
                        throw new InvalidArgumentException('L’encadrant choisi n’a pas le rôle Encadrant de stage.');
                    }
                }
                $pdo->prepare(
                    'INSERT INTO stage_capacite (id_campagne, id_service, places, id_stage_superviseur) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE places = VALUES(places), id_stage_superviseur = VALUES(id_stage_superviseur)'
                )->execute([$campaignId, $serviceId, $count, $supervisor]);
            }
            foreach ($totals as $orgId => $total) {
                if ($total > ($limits[$orgId] ?? 0)) {
                    throw new InvalidArgumentException('La somme des places des services dépasse la capacité de l’établissement.');
                }
            }
            $pdo->commit();
            audit_record('stage', 'capacite', 'Capacités de la campagne #' . $campaignId);
            $message = 'Capacités enregistrées.';
        } elseif ($action === 'regle') {
            $serviceId = (int) ($_POST['id_service'] ?? 0);
            $spec = (int) ($_POST['id_spec'] ?? 0) ?: null;
            $level = trim((string) ($_POST['niveau'] ?? ''));
            if ($serviceId < 1) {
                throw new InvalidArgumentException('Choisissez un service.');
            }
            db()->prepare('INSERT INTO stage_service_regle (id_service, id_spec, niveau) VALUES (?, ?, ?)')->execute([$serviceId, $spec, $level]);
            audit_record('stage', 'eligibilite', 'Règle du service #' . $serviceId);
            $message = 'Règle d’éligibilité ajoutée. Sans règle, le service accepte toute la population de la campagne.';
        } elseif ($action === 'retirer_regle') {
            db()->prepare('DELETE FROM stage_service_regle WHERE id_regle = ?')->execute([(int) ($_POST['id_regle'] ?? 0)]);
            $message = 'Règle retirée.';
        } elseif ($action === 'proposer') {
            $keep = (string) ($_POST['conserver'] ?? '') === '1';
            stage_campaign_propose($campaignId, $keep);
            $message = 'Proposition générée à partir des places disponibles.';
        } elseif ($action === 'publier') {
            $result = stage_campaign_publish($campaignId, current_stage_id());
            $message = $result['publiees'] . ' affectation(s) publiée(s).';
            if ($result['bloques']) {
                $message .= ' ' . count($result['bloques']) . ' ligne(s) ont été retenues parce que la capacité ou l’éligibilité a changé.';
            }
        }
    } catch (InvalidArgumentException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('Stage campaign failed: ' . $exception->getMessage());
        $error = 'L’opération n’a pas abouti.';
    }
}

$campaigns = db()->query('SELECT * FROM stage_campagne ORDER BY date_debut DESC, id_campagne DESC')->fetchAll();
$campaign = $campaignId ? stage_campaign($campaignId) : null;
$report = $campaign ? stage_campaign_report($campaignId) : null;
$services = db()->query(
    'SELECT s.id_service, s.nom, o.nom AS organisme, o.capacite
     FROM stage_service s JOIN stage_organisme o ON o.id_organisme = s.id_organisme
     WHERE s.active = 1 AND o.active = 1 ORDER BY o.nom, s.nom'
)->fetchAll();
$saved = [];
if ($campaign) {
    $rows = db()->prepare('SELECT id_service, places, id_stage_superviseur FROM stage_capacite WHERE id_campagne = ?');
    $rows->execute([$campaignId]);
    foreach ($rows->fetchAll() as $row) {
        $saved[(int) $row['id_service']] = $row;
    }
}
$rules = db()->query(
    'SELECT r.*, s.nom AS service, sp.nom_spec
     FROM stage_service_regle r
     JOIN stage_service s ON s.id_service = r.id_service
     LEFT JOIN specialite sp ON sp.id_spec = r.id_spec
     ORDER BY s.nom, r.id_regle'
)->fetchAll();
$specialties = db()->query('SELECT id_spec, nom_spec FROM specialite ORDER BY nom_spec')->fetchAll();
$groups = db()->query('SELECT id_groupe, semestre, type_groupe, numero FROM groupe ORDER BY semestre, type_groupe, numero')->fetchAll();
$supervisors = db()->query('SELECT id_stage, nom, cin FROM stage_user WHERE active = 1 AND responsabilite = "superviseur" ORDER BY nom, cin')->fetchAll();
$embed = app_is_embedded() ? 'embed=1' : '';
page_head('Campagnes de stage', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Responsable des stages</p>
  <h1 class="display-6 fw-bold mb-1">Campagnes et affectation</h1>
  <p class="text-secondary mb-0">La proposition remplit d’abord les services qui ont le plus de places libres. À égalité, le service le plus ancien est choisi. Les étudiants sont traités par identifiant croissant. Personne n’est retiré en silence.</p>
</div>
<?php if ($message): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5">Nouvelle campagne</h2>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="creer">
    <div class="col-md-3"><label class="form-label">Année *</label><input class="form-control" name="annee_label" value="<?= e(academic_active_year_label()) ?>" placeholder="2026-2027" required></div>
    <div class="col-md-2"><label class="form-label">Période</label><select class="form-select" name="periode_code"><option value="">—</option><?php foreach (academic_periods() as $period): ?><option value="<?= e((string) $period['code']) ?>" <?= strtoupper((string) $period['code']) === academic_active_period_code() ? 'selected' : '' ?>><?= e((string) $period['code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Début *</label><input class="form-control" type="date" name="date_debut" required></div>
    <div class="col-md-2"><label class="form-label">Fin *</label><input class="form-control" type="date" name="date_fin" required></div>
    <div class="col-md-3"><label class="form-label">Spécialité</label><select class="form-select" name="id_spec"><option value="">Toutes</option><?php foreach ($specialties as $specialty): ?><option value="<?= (int) $specialty['id_spec'] ?>"><?= e($specialty['nom_spec']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Niveau</label><input class="form-control" name="niveau" placeholder="1, 2 ou 3"></div>
    <div class="col-md-4"><label class="form-label">Groupe</label><select class="form-select" name="id_groupe"><option value="">Tous</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id_groupe'] ?>"><?= e($group['semestre'] . ' · ' . $group['type_groupe'] . ' ' . $group['numero']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Créer la campagne</button></div>
  </form>
</div></div>
<div class="card app-card mb-4"><div class="table-responsive"><table class="table table-dark table-hover mb-0">
  <thead><tr><th>Année</th><th>Dates</th><th>Statut</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($campaigns as $row): ?>
    <tr>
      <td><?= e($row['annee_label'] . ' ' . $row['periode_code']) ?></td>
      <td><?= e($row['date_debut'] . ' → ' . $row['date_fin']) ?></td>
      <td><?= e((string) $row['statut']) ?></td>
      <td><a href="stage_campagne.php?id=<?= (int) $row['id_campagne'] ?><?= $embed !== '' ? '&' . $embed : '' ?>">Ouvrir</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$campaigns): ?><tr><td colspan="4" class="text-secondary text-center py-4">Aucune campagne.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php if ($campaign && $report): ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5">Places par service · <?= e($campaign['annee_label']) ?></h2>
  <p class="text-secondary">La capacité de l’établissement reste le plafond. Les places d’un service ne peuvent pas, additionnées, la dépasser.</p>
  <?php if (!$services): ?><p class="mb-0">Ajoutez d’abord un service dans les organismes d’accueil.</p><?php else: ?>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="capacite"><input type="hidden" name="id_campagne" value="<?= (int) $campaign['id_campagne'] ?>">
    <?php foreach ($services as $service): $current = $saved[(int) $service['id_service']] ?? null; ?>
      <div class="col-lg-6"><div class="border rounded p-3 h-100">
        <strong><?= e($service['organisme'] . ' · ' . $service['nom']) ?></strong>
        <span class="d-block text-secondary small mb-2">Plafond de l’établissement : <?= (int) $service['capacite'] ?></span>
        <div class="row g-2">
          <div class="col-4"><label class="form-label">Places</label><input class="form-control" type="number" min="0" name="places[<?= (int) $service['id_service'] ?>]" value="<?= (int) ($current['places'] ?? 0) ?>"></div>
          <div class="col-8"><label class="form-label">Encadrant</label><select class="form-select" name="superviseur[<?= (int) $service['id_service'] ?>]"><option value="">Aucun</option><?php foreach ($supervisors as $supervisor): ?><option value="<?= (int) $supervisor['id_stage'] ?>" <?= (int) ($current['id_stage_superviseur'] ?? 0) === (int) $supervisor['id_stage'] ? 'selected' : '' ?>><?= e(trim($supervisor['nom'] . ' ' . $supervisor['cin'])) ?></option><?php endforeach; ?></select></div>
        </div>
      </div></div>
    <?php endforeach; ?>
    <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer les places</button></div>
  </form>
  <?php endif; ?>
</div></div>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h5">Éligibilité d’un service</h2>
  <form method="post" class="row g-2 align-items-end">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="regle"><input type="hidden" name="id_campagne" value="<?= (int) $campaign['id_campagne'] ?>">
    <div class="col-md-4"><label class="form-label">Service</label><select class="form-select" name="id_service"><?php foreach ($services as $service): ?><option value="<?= (int) $service['id_service'] ?>"><?= e($service['organisme'] . ' · ' . $service['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Spécialité</label><select class="form-select" name="id_spec"><option value="">Toutes</option><?php foreach ($specialties as $specialty): ?><option value="<?= (int) $specialty['id_spec'] ?>"><?= e($specialty['nom_spec']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Niveau</label><input class="form-control" name="niveau" placeholder="Tous si vide"></div>
    <div class="col-md-3"><button class="btn btn-outline-light" type="submit">Ajouter la règle</button></div>
  </form>
  <ul class="mt-3 mb-0"><?php foreach ($rules as $rule): ?><li><?= e($rule['service']) ?> · <?= e((string) ($rule['nom_spec'] ?: 'Toute spécialité')) ?> · <?= e($rule['niveau'] !== '' ? 'niveau ' . $rule['niveau'] : 'tout niveau') ?>
    <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="retirer_regle"><input type="hidden" name="id_campagne" value="<?= (int) $campaign['id_campagne'] ?>"><input type="hidden" name="id_regle" value="<?= (int) $rule['id_regle'] ?>"><button class="btn btn-link btn-sm" type="submit">Retirer</button></form>
  </li><?php endforeach; ?><?php if (!$rules): ?><li class="text-secondary">Aucune restriction : chaque service ouvert accepte la population de la campagne.</li><?php endif; ?></ul>
</div></div>
<div class="d-flex flex-wrap gap-2 mb-3">
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="proposer"><input type="hidden" name="id_campagne" value="<?= (int) $campaign['id_campagne'] ?>"><button class="btn btn-primary" type="submit">Générer la proposition</button></form>
  <?php if ($campaign['statut'] === 'publiee'): ?>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="proposer"><input type="hidden" name="conserver" value="1"><input type="hidden" name="id_campagne" value="<?= (int) $campaign['id_campagne'] ?>"><button class="btn btn-outline-light" type="submit">Proposer seulement les étudiants encore libres</button></form>
  <?php endif; ?>
  <form method="post" onsubmit="return confirm('Publier les affectations proposées ? Les lignes déjà publiées ne sont pas écrasées.');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="publier"><input type="hidden" name="id_campagne" value="<?= (int) $campaign['id_campagne'] ?>"><button class="btn btn-outline-light" type="submit">Publier</button></form>
</div>
<div class="row g-3 mb-4">
  <?php foreach ([
      'Étudiants sélectionnés' => $report['selectionnes'],
      'Éligibles' => $report['eligibles'],
      'Proposés' => $report['affectes'],
      'Non affectés' => $report['non_affectes'],
  ] as $label => $value): ?>
    <div class="col-6 col-lg-3"><div class="card app-card"><div class="card-body"><span class="text-secondary d-block"><?= e($label) ?></span><strong class="fs-3"><?= (int) $value ?></strong></div></div></div>
  <?php endforeach; ?>
</div>
<div class="card app-card mb-4"><div class="table-responsive"><table class="table table-dark table-hover mb-0">
  <thead><tr><th>Service</th><th>Capacité</th><th>Déjà occupées</th><th>Restantes avant proposition</th><th>Proposées</th></tr></thead>
  <tbody><?php foreach ($report['services'] as $slot): ?><tr>
    <td><?= e($slot['organisme'] . ' · ' . $slot['service']) ?></td>
    <td><?= (int) $slot['places'] ?></td>
    <td><?= (int) $slot['occupees'] ?></td>
    <td><?= (int) $slot['restantes'] ?></td>
    <td><?= (int) ($slot['proposees'] ?? 0) ?></td>
  </tr><?php endforeach; ?><?php if (!$report['services']): ?><tr><td colspan="5" class="text-secondary text-center py-4">Aucune place ouverte.</td></tr><?php endif; ?></tbody>
</table></div></div>
<div class="card app-card"><div class="table-responsive"><table class="table table-dark table-hover mb-0">
  <thead><tr><th>Étudiant</th><th>Proposition</th><th>Motif si non affecté</th></tr></thead>
  <tbody><?php foreach ($report['lignes'] as $line): ?><tr>
    <td><?= e($line['prenom'] . ' ' . $line['nom']) ?><br><small><?= e($line['num_inscription']) ?></small></td>
    <td><?= $line['service'] ? e($line['organisme'] . ' · ' . $line['service']) : '—' ?></td>
    <td><?= e((string) $line['motif']) ?></td>
  </tr><?php endforeach; ?><?php if (!$report['lignes']): ?><tr><td colspan="3" class="text-secondary text-center py-4">Générez une proposition pour voir le résultat.</td></tr><?php endif; ?></tbody>
</table></div></div>
<?php endif; ?>
<?php page_foot();

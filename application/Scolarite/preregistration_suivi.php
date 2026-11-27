<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_once app_path('support/preregistration.php');
require_permission('admissions.view');
prereg_ensure();

$campaigns = db()->query('SELECT * FROM prereg_campaign ORDER BY id_campaign DESC')->fetchAll();
$campaignId = filter_input(INPUT_GET, 'campaign', FILTER_VALIDATE_INT) ?: (int) ($campaigns[0]['id_campaign'] ?? 0);
$level = trim((string) ($_GET['level'] ?? ''));
$tab = trim((string) ($_GET['tab'] ?? 'sa02'));
if (!in_array($tab, ['sa02', 'sa03', 'campaigns'], true)) {
    $tab = 'sa02';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'verify_advance' && (is_admin() || is_finance() || is_scolarite())) {
            prereg_verify_advance((int) ($_POST['id_commitment'] ?? 0), $_POST, trim((string) ($_SESSION['user_name'] ?? 'Finance')));
            $message = 'Suivi acompte mis à jour (SA-02).';
        }
        if ($action === 'mark_affairs' && (is_admin() || is_scolarite())) {
            prereg_mark_affairs((int) ($_POST['id_commitment'] ?? 0), trim((string) ($_SESSION['user_name'] ?? 'Affaires')), trim((string) ($_POST['staff_notes'] ?? '')));
            $message = 'Visa affaires étudiants enregistré.';
        }
        if ($action === 'save_campaign' && (is_admin() || role_can('admissions.manage'))) {
            $id = (int) ($_POST['id_campaign'] ?? 0);
            $fields = [
                trim((string) ($_POST['label'] ?? '')),
                trim((string) ($_POST['current_year_label'] ?? '')),
                trim((string) ($_POST['next_year_label'] ?? '')),
                (string) ($_POST['opens_at'] ?? '') ?: null,
                (string) ($_POST['closes_at'] ?? '') ?: null,
                (float) ($_POST['advance_amount'] ?? 200),
                (string) ($_POST['status'] ?? 'draft'),
                trim((string) ($_POST['notes'] ?? '')),
            ];
            if ($fields[0] === '') {
                throw new InvalidArgumentException('Libellé obligatoire.');
            }
            if ($id > 0) {
                db()->prepare(
                    'UPDATE prereg_campaign SET label=?, current_year_label=?, next_year_label=?, opens_at=?, closes_at=?, advance_amount=?, status=?, notes=? WHERE id_campaign=?'
                )->execute([...$fields, $id]);
            } else {
                db()->prepare(
                    'INSERT INTO prereg_campaign (label, current_year_label, next_year_label, opens_at, closes_at, advance_amount, status, notes)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute($fields);
            }
            $campaigns = db()->query('SELECT * FROM prereg_campaign ORDER BY id_campaign DESC')->fetchAll();
            $message = 'Campagne enregistrée.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$rows = $campaignId > 0 ? prereg_sa02_rows($campaignId, $level) : [];
$summary = $campaignId > 0 ? prereg_sa03_summary($campaignId) : null;
$levels = [];
foreach ($rows as $r) {
    $levels[(string) $r['current_level']] = true;
}
ksort($levels);

page_head('Préinscription PA-SAE-01', 'admissions', 'assets/admissions-lightswind.css?v=2');
?>
<div class="adm-lw-admin">
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <p class="eyebrow">PA-SAE-01</p>
    <h1 class="h3 fw-bold mb-1">Préinscription annuelle (étudiants continus)</h1>
    <p class="text-secondary mb-0">SA-01 engagement · SA-02 registre · SA-03 état prévisionnel · acompte 200 DT</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-light" href="admissions.php?embed=1">Admissions (nouveaux)</a>
    <a class="btn btn-sm btn-outline-light" href="?tab=sa02&amp;campaign=<?= (int) $campaignId ?>">SA-02</a>
    <a class="btn btn-sm btn-outline-light" href="?tab=sa03&amp;campaign=<?= (int) $campaignId ?>">SA-03</a>
    <a class="btn btn-sm btn-outline-light" href="?tab=campaigns">Campagnes</a>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if ($tab !== 'campaigns' && $campaigns): ?>
  <form class="row g-2 mb-3" method="get">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="col-md-5">
      <select class="form-select" name="campaign" onchange="this.form.submit()">
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= (int) $c['id_campaign'] ?>" <?= (int) $c['id_campaign'] === $campaignId ? 'selected' : '' ?>>
            <?= e($c['label'] . ' (' . $c['status'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($tab === 'sa02'): ?>
      <div class="col-md-3">
        <select class="form-select" name="level" onchange="this.form.submit()">
          <option value="">Tous les niveaux</option>
          <?php foreach (array_keys($levels) as $lv): ?>
            <option value="<?= e($lv) ?>" <?= $level === $lv ? 'selected' : '' ?>><?= e($lv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php if ($tab === 'sa03' && $summary): ?>
  <div class="card app-card mb-3"><div class="card-body">
    <h2 class="h6">SA-03 — État numérique et financier</h2>
    <p class="small text-secondary mb-3">
      <?= e((string) ($summary['campaign']['current_year_label'] ?? '')) ?>
      → <?= e((string) ($summary['campaign']['next_year_label'] ?? '')) ?>
      · Préparé le <?= e(date('d/m/Y')) ?>
    </p>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Niveau</th><th>Engagements</th><th>Acomptes payés</th><th>Non payés</th></tr></thead>
        <tbody>
          <?php foreach ($summary['levels'] as $lv): ?>
            <tr>
              <td><?= e($lv['level']) ?></td>
              <td><?= (int) $lv['commitments'] ?></td>
              <td><?= (int) $lv['paid'] ?></td>
              <td><?= (int) $lv['unpaid'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <ul class="mb-0">
      <li>Engagements reçus : <strong><?= (int) $summary['total_commitments'] ?></strong></li>
      <li>Acomptes payés : <strong><?= (int) $summary['total_paid'] ?></strong></li>
      <li>Non payés : <strong><?= (int) $summary['total_unpaid'] ?></strong></li>
      <li>Valeur unitaire : <strong><?= e(number_format((float) $summary['advance_unit'], 2, ',', ' ')) ?> DT</strong></li>
      <li>Montant encaissé : <strong><?= e(number_format((float) $summary['amount_collected'], 2, ',', ' ')) ?> DT</strong></li>
      <li>Montant restant : <strong><?= e(number_format((float) $summary['amount_outstanding'], 2, ',', ' ')) ?> DT</strong></li>
    </ul>
    <p class="small text-secondary mt-3 mb-0">Visas papier (affaires / finance / SG / directeur) restent hors application — exporter ou imprimer cette page pour archivage.</p>
  </div></div>

<?php elseif ($tab === 'campaigns'): ?>
  <div class="card app-card mb-3"><div class="card-body">
    <h2 class="h6">Nouvelle / modifier campagne</h2>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_campaign">
      <input type="hidden" name="id_campaign" value="0">
      <div class="col-md-4"><input class="form-control" name="label" placeholder="Libellé" required></div>
      <div class="col-md-2"><input class="form-control" name="current_year_label" placeholder="Année courante"></div>
      <div class="col-md-2"><input class="form-control" name="next_year_label" placeholder="Année suivante"></div>
      <div class="col-md-2"><input class="form-control" type="date" name="opens_at"></div>
      <div class="col-md-2"><input class="form-control" type="date" name="closes_at"></div>
      <div class="col-md-2"><input class="form-control" name="advance_amount" value="200"></div>
      <div class="col-md-2">
        <select class="form-select" name="status">
          <option value="draft">draft</option>
          <option value="open" selected>open</option>
          <option value="closed">closed</option>
        </select>
      </div>
      <div class="col-md-6"><input class="form-control" name="notes" placeholder="Notes"></div>
      <div class="col-md-2"><button class="btn btn-primary w-100">Créer</button></div>
    </form>
  </div></div>
  <?php foreach ($campaigns as $c): ?>
    <div class="card app-card mb-2"><div class="card-body py-3">
      <strong><?= e($c['label']) ?></strong>
      <span class="badge text-bg-secondary"><?= e($c['status']) ?></span>
      <div class="small text-secondary">
        <?= e($c['current_year_label']) ?> → <?= e($c['next_year_label']) ?>
        · <?= e((string) $c['opens_at']) ?> – <?= e((string) $c['closes_at']) ?>
        · acompte <?= e((string) $c['advance_amount']) ?> DT
      </div>
    </div></div>
  <?php endforeach; ?>

<?php else: ?>
  <div class="card app-card"><div class="card-body">
    <h2 class="h6 mb-3">SA-02 — Registre de suivi</h2>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Étudiant</th>
            <th>N° insc.</th>
            <th>Niveau / groupe</th>
            <th>Acompte</th>
            <th>Quittance</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $r): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><?= e(substr((string) ($r['submitted_at'] ?? ''), 0, 10)) ?></td>
              <td><?= e(trim($r['prenom'] . ' ' . $r['nom'])) ?></td>
              <td><?= e($r['num_inscription']) ?></td>
              <td><?= e($r['current_level'] . ' / ' . $r['current_group']) ?></td>
              <td><?= (int) $r['advance_paid'] === 1 ? 'Oui' : 'Non' ?></td>
              <td><?= e((string) $r['advance_receipt_no']) ?></td>
              <td style="min-width:220px">
                <form method="post" class="row g-1">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="verify_advance">
                  <input type="hidden" name="id_commitment" value="<?= (int) $r['id_commitment'] ?>">
                  <div class="col-12 form-check">
                    <input class="form-check-input" type="checkbox" name="advance_paid" value="1" id="paid<?= (int) $r['id_commitment'] ?>" <?= (int) $r['advance_paid'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="paid<?= (int) $r['id_commitment'] ?>">Payé</label>
                  </div>
                  <div class="col-6"><input class="form-control form-control-sm" type="date" name="advance_paid_at" value="<?= e((string) ($r['advance_paid_at'] ?? date('Y-m-d'))) ?>"></div>
                  <div class="col-6"><input class="form-control form-control-sm" name="advance_method" placeholder="Mode" value="<?= e((string) $r['advance_method']) ?>"></div>
                  <div class="col-12"><input class="form-control form-control-sm" name="advance_receipt_no" placeholder="N° quittance" value="<?= e((string) $r['advance_receipt_no']) ?>"></div>
                  <div class="col-12"><input class="form-control form-control-sm" name="staff_notes" placeholder="Notes" value="<?= e((string) $r['staff_notes']) ?>"></div>
                  <div class="col-12"><button class="btn btn-sm btn-primary">Enregistrer</button></div>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-secondary">Aucun engagement pour cette campagne.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    $paidN = 0;
    foreach ($rows as $r) {
        if ((int) $r['advance_paid'] === 1) {
            $paidN++;
        }
    }
    $unit = (float) (($summary['advance_unit'] ?? 200));
    ?>
    <div class="small text-secondary mt-2">
      Engagements : <?= count($rows) ?> · Payés : <?= $paidN ?> · Non payés : <?= count($rows) - $paidN ?>
      · Encaissé (<?= e(number_format($unit, 0, ',', ' ')) ?> DT × payés) :
      <?= e(number_format($paidN * $unit, 2, ',', ' ')) ?> DT
    </div>
  </div></div>
<?php endif; ?>
</div>
<?php page_foot(); ?>

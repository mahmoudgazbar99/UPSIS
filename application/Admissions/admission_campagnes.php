<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_admissions_permission('admissions.manage');
admissions_ensure();

$message = '';
$error = '';
$specs = db()->query('SELECT id_spec, nom_spec FROM specialite WHERE active = 1 ORDER BY nom_spec')->fetchAll();
$years = [];
try {
    $years = db()->query('SELECT label FROM academic_year ORDER BY label DESC')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ignored) {
    $years = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id_campaign'] ?? 0);
            $payload = [
                trim((string) ($_POST['label'] ?? '')),
                trim((string) ($_POST['academic_year_label'] ?? '')),
                (int) ($_POST['id_spec'] ?? 0) ?: null,
                (string) ($_POST['opens_at'] ?? '') ?: null,
                (string) ($_POST['closes_at'] ?? '') ?: null,
                (int) ($_POST['offer_days'] ?? 14),
                (int) ($_POST['capacity'] ?? 0),
                (string) ($_POST['status'] ?? 'draft'),
                trim((string) ($_POST['notes'] ?? '')),
            ];
            if ($payload[0] === '' || $payload[1] === '') {
                throw new InvalidArgumentException('Libellé et année scolaire obligatoires.');
            }
            if ($id > 0) {
                db()->prepare(
                    'UPDATE admission_campaign SET label=?, academic_year_label=?, id_spec=?, opens_at=?, closes_at=?, offer_days=?, capacity=?, status=?, notes=? WHERE id_campaign=?'
                )->execute([...$payload, $id]);
            } else {
                db()->prepare(
                    'INSERT INTO admission_campaign (label, academic_year_label, id_spec, opens_at, closes_at, offer_days, capacity, status, notes)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute($payload);
            }
            $message = 'Campagne enregistrée.';
        }
        if ($action === 'delete') {
            $id = (int) ($_POST['id_campaign'] ?? 0);
            $used = db()->prepare('SELECT COUNT(*) FROM admission_application WHERE id_campaign = ?');
            $used->execute([$id]);
            if ((int) $used->fetchColumn() > 0) {
                throw new InvalidArgumentException('Campagne déjà utilisée — archivez-la plutôt.');
            }
            db()->prepare('DELETE FROM admission_campaign WHERE id_campaign = ?')->execute([$id]);
            $message = 'Campagne supprimée.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$rows = db()->query(
    'SELECT c.*, s.nom_spec,
            (SELECT COUNT(*) FROM admission_application a WHERE a.id_campaign = c.id_campaign) AS apps
     FROM admission_campaign c
     LEFT JOIN specialite s ON s.id_spec = c.id_spec
     ORDER BY c.academic_year_label DESC, c.id_campaign DESC'
)->fetchAll();

page_head('Campagnes d’admission', 'admissions', 'assets/admissions-lightswind.css?v=1');
?>
<div class="adm-lw-admin">
<div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="admissions.php?embed=1">← Admissions</a></div>
<p class="eyebrow">Admissions</p>
<h1 class="h3 fw-bold mb-3">Campagnes</h1>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card app-card mb-4"><div class="card-body">
  <h2 class="h6">Nouvelle campagne</h2>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <div class="col-md-4"><input class="form-control" name="label" placeholder="Libellé *" required></div>
    <div class="col-md-2">
      <?php if ($years): ?>
        <select class="form-select" name="academic_year_label" required>
          <?php foreach ($years as $y): ?><option value="<?= e($y) ?>"><?= e($y) ?></option><?php endforeach; ?>
        </select>
      <?php else: ?>
        <input class="form-control" name="academic_year_label" placeholder="2026-2027" required>
      <?php endif; ?>
    </div>
    <div class="col-md-3">
      <select class="form-select" name="id_spec">
        <option value="">Spécialité (optionnel)</option>
        <?php foreach ($specs as $s): ?><option value="<?= (int) $s['id_spec'] ?>"><?= e($s['nom_spec']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1"><input class="form-control" name="capacity" type="number" min="0" value="0" title="Capacité"></div>
    <div class="col-md-2">
      <select class="form-select" name="status">
        <option value="draft">Brouillon</option>
        <option value="open">Ouverte</option>
        <option value="closed">Fermée</option>
        <option value="archived">Archivée</option>
      </select>
    </div>
    <div class="col-md-3"><input class="form-control" type="date" name="opens_at" title="Ouverture"></div>
    <div class="col-md-3"><input class="form-control" type="date" name="closes_at" title="Clôture"></div>
    <div class="col-md-2"><input class="form-control" type="number" name="offer_days" value="14" title="Jours offre"></div>
    <div class="col-md-4"><input class="form-control" name="notes" placeholder="Notes"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Créer</button></div>
  </form>
</div></div>

<div class="card app-card"><div class="table-responsive"><table class="table table-dark mb-0 align-middle">
  <thead><tr><th>Campagne</th><th>Année</th><th>Spécialité</th><th>Période</th><th>Statut</th><th>Candidatures</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['label']) ?></td>
      <td><?= e($r['academic_year_label']) ?></td>
      <td><?= e((string) ($r['nom_spec'] ?? '—')) ?></td>
      <td class="small"><?= e(trim(($r['opens_at'] ?? '') . ' → ' . ($r['closes_at'] ?? ''))) ?></td>
      <td><?= e($r['status']) ?></td>
      <td><?= (int) $r['apps'] ?></td>
      <td>
        <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ?')">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id_campaign" value="<?= (int) $r['id_campaign'] ?>">
          <button class="btn btn-sm btn-outline-danger">Suppr.</button>
        </form>
      </td>
    </tr>
    <tr>
      <td colspan="7" class="bg-black bg-opacity-25">
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id_campaign" value="<?= (int) $r['id_campaign'] ?>">
          <div class="col-md-3"><input class="form-control form-control-sm" name="label" value="<?= e($r['label']) ?>"></div>
          <div class="col-md-2"><input class="form-control form-control-sm" name="academic_year_label" value="<?= e($r['academic_year_label']) ?>"></div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="id_spec">
              <option value="">—</option>
              <?php foreach ($specs as $s): ?>
                <option value="<?= (int) $s['id_spec'] ?>" <?= (int) ($r['id_spec'] ?? 0) === (int) $s['id_spec'] ? 'selected' : '' ?>><?= e($s['nom_spec']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-1"><input class="form-control form-control-sm" type="date" name="opens_at" value="<?= e((string) $r['opens_at']) ?>"></div>
          <div class="col-md-1"><input class="form-control form-control-sm" type="date" name="closes_at" value="<?= e((string) $r['closes_at']) ?>"></div>
          <div class="col-md-1"><input class="form-control form-control-sm" type="number" name="offer_days" value="<?= (int) $r['offer_days'] ?>"></div>
          <div class="col-md-1"><input class="form-control form-control-sm" type="number" name="capacity" value="<?= (int) $r['capacity'] ?>"></div>
          <div class="col-md-1">
            <select class="form-select form-select-sm" name="status">
              <?php foreach (['draft','open','closed','archived'] as $st): ?>
                <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-12"><input class="form-control form-control-sm" name="notes" value="<?= e((string) $r['notes']) ?>"></div>
          <div class="col-md-2"><button class="btn btn-sm btn-primary">Mettre à jour</button></div>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

</div>


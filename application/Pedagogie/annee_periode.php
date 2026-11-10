<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add_year') {
            academic_add_year(
                (string) ($_POST['label'] ?? ''),
                (string) ($_POST['date_debut'] ?? ''),
                (string) ($_POST['date_fin'] ?? '')
            );
            $message = 'Année scolaire ajoutée (brouillon). Activez-la ci-dessous pour l’utiliser.';
        } elseif ($action === 'activate_year') {
            academic_set_active_year((int) ($_POST['id_year'] ?? 0));
            $message = 'Année scolaire active mise à jour.';
        } elseif ($action === 'activate_period') {
            academic_set_active_period((string) ($_POST['period_code'] ?? ''));
            $message = 'Période active mise à jour.';
        } else {
            throw new InvalidArgumentException('Action non reconnue.');
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('annee_periode: ' . $exception->getMessage());
        $error = 'L’enregistrement a échoué.';
    }
}

$years = academic_years();
$periods = academic_periods();
$activeYear = academic_year_active();
$activePeriodCode = academic_active_period_code();
$activePeriod = academic_active_period();
$suggested = ((int) date('Y')) . '-' . ((int) date('Y') + 1);

page_head('Année et période', 'schedule');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Année / période active</h1>
  <p class="text-secondary mb-0">Fixe l’année scolaire courante et la période ouverte (ex. S1) pour la promotion, les stages et les écrans qui en dépendent.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card app-card h-100">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Année active</h2>
        <p class="display-6 fw-bold mb-1"><?= e(academic_active_year_label()) ?></p>
        <p class="text-secondary mb-0 small">
          <?php if ($activeYear && !empty($activeYear['date_debut']) && !empty($activeYear['date_fin'])): ?>
            <?= e((string) $activeYear['date_debut']) ?> → <?= e((string) $activeYear['date_fin']) ?>
          <?php else: ?>
            Dates optionnelles non renseignées
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card app-card h-100">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Période active</h2>
        <p class="display-6 fw-bold mb-1"><?= e($activePeriodCode) ?></p>
        <p class="text-secondary mb-0 small"><?= e((string) ($activePeriod['label'] ?? 'Période')) ?></p>
      </div>
    </div>
  </div>
</div>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h5">Choisir la période ouverte</h2>
    <p class="text-secondary small">Les périodes viennent de <strong>Structure académique</strong> (S1–S6, etc.).</p>
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="activate_period">
      <div class="col-md-6">
        <label class="form-label" for="period_code">Période active</label>
        <select class="form-select" id="period_code" name="period_code" required>
          <?php foreach ($periods as $period): ?>
            <option value="<?= e((string) $period['code']) ?>" <?= strtoupper((string) $period['code']) === $activePeriodCode ? 'selected' : '' ?>>
              <?= e((string) $period['code'] . ' — ' . (string) $period['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary" type="submit">Activer la période</button>
      </div>
    </form>
  </div>
</div>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h5">Années scolaires</h2>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th>Année</th>
            <th>Début</th>
            <th>Fin</th>
            <th>Statut</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$years): ?>
            <tr><td colspan="5" class="text-secondary">Aucune année enregistrée.</td></tr>
          <?php endif; ?>
          <?php foreach ($years as $year): ?>
            <?php $isActive = (string) ($year['status'] ?? '') === 'active'; ?>
            <tr class="<?= $isActive ? 'table-active' : '' ?>">
              <td><strong><?= e((string) $year['label']) ?></strong></td>
              <td><?= e((string) ($year['date_debut'] ?: '—')) ?></td>
              <td><?= e((string) ($year['date_fin'] ?: '—')) ?></td>
              <td>
                <?php if ($isActive): ?>
                  <span class="badge text-bg-success">active</span>
                <?php elseif ((string) $year['status'] === 'draft'): ?>
                  <span class="badge text-bg-secondary">brouillon</span>
                <?php else: ?>
                  <span class="badge text-bg-dark">clôturée</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <?php if (!$isActive): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="activate_year">
                    <input type="hidden" name="id_year" value="<?= (int) $year['id_year'] ?>">
                    <button class="btn btn-sm btn-outline-primary" type="submit">Activer</button>
                  </form>
                <?php else: ?>
                  <span class="text-secondary small">En cours</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card app-card">
  <div class="card-body p-4">
    <h2 class="h5">Ajouter une année</h2>
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_year">
      <div class="col-md-3">
        <label class="form-label" for="label">Libellé</label>
        <input class="form-control" id="label" name="label" value="<?= e($suggested) ?>" pattern="\d{4}-\d{4}" required placeholder="2025-2026">
      </div>
      <div class="col-md-3">
        <label class="form-label" for="date_debut">Début (optionnel)</label>
        <input class="form-control" id="date_debut" name="date_debut" type="date">
      </div>
      <div class="col-md-3">
        <label class="form-label" for="date_fin">Fin (optionnel)</label>
        <input class="form-control" id="date_fin" name="date_fin" type="date">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-primary w-100" type="submit">Ajouter</button>
      </div>
    </form>
  </div>
</div>
<?php page_foot();

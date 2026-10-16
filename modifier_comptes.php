<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$types = [
    'enseignant' => 'Enseignant',
    'stage' => 'Stage',
    'scolarite' => 'Scolarité',
    'finance' => 'Finance',
];
$type = (string) ($_GET['type'] ?? $_POST['type'] ?? '');
$error = '';
$message = '';
$editAccount = null;
$search = trim((string) ($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type = (string) ($_POST['type'] ?? '');
    $accountId = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT) ?: 0;
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirm'] ?? '');

    if ($type === 'stage' && !stage_accounts_table_exists()) {
        $error = 'La table des comptes stage est absente. Sauvegardez la base puis exécutez stage_accounts_migration.sql sur la base utilisée par cette application.';
    } elseif (!isset($types[$type]) || $accountId < 1) {
        $error = 'Type ou compte invalide.';
    } elseif ($login === '') {
        $error = 'Veuillez saisir l’identifiant du compte.';
    } elseif (in_array($type, ['enseignant', 'stage'], true) && !identity_matches('cin', $login)) {
        $error = identity_error('cin');
    } elseif (in_array($type, ['finance', 'scolarite'], true) && mb_strlen($login) > 80) {
        $error = 'L’identifiant finance ne peut pas dépasser 80 caractères.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $confirmation) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } elseif ($password !== '' && !password_verify((string) ($_POST['admin_password'] ?? ''), admin_password_hash())) {
        $error = 'Le mot de passe administrateur est incorrect.';
    } else {
        try {
            if ($type === 'enseignant') {
                $query = 'UPDATE enseignant SET cin = ?';
                $parameters = [$login];
                if ($password !== '') {
                    $query .= ', password = ?';
                    $parameters[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $query .= ' WHERE id_enseignant = ?';
                $parameters[] = $accountId;
            } elseif ($type === 'stage') {
                access_ensure();
                $query = 'UPDATE stage_user SET cin = ?, auth_generation = auth_generation + 1';
                $parameters = [$login];
                if ($password !== '') {
                    $query .= ', password = ?';
                    $parameters[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $duty = (string) ($_POST['responsabilite'] ?? '');
                if (in_array($duty, ['officer', 'coordinateur', 'superviseur'], true)) {
                    $query .= ', responsabilite = ?';
                    $parameters[] = $duty;
                }
                $query .= ' WHERE id_stage = ?';
                $parameters[] = $accountId;
            } elseif ($type === 'scolarite') {
                access_ensure();
                $query = 'UPDATE scolarite_user SET username = ?, auth_generation = auth_generation + 1';
                $parameters = [$login];
                if ($password !== '') {
                    $query .= ', password = ?';
                    $parameters[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $query .= ' WHERE id_scolarite = ?';
                $parameters[] = $accountId;
            } else {
                $query = 'UPDATE finance_user SET username = ?, auth_generation = auth_generation + 1';
                $parameters = [$login];
                if ($password !== '') {
                    $query .= ', password = ?';
                    $parameters[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $query .= ' WHERE id_finance = ?';
                $parameters[] = $accountId;
            }
            $statement = db()->prepare($query);
            $statement->execute($parameters);
            if ($statement->rowCount() === 0) {
                $existsSql = [
                    'enseignant' => 'SELECT 1 FROM enseignant WHERE id_enseignant = ?',
                    'stage' => 'SELECT 1 FROM stage_user WHERE id_stage = ?',
                    'scolarite' => 'SELECT 1 FROM scolarite_user WHERE id_scolarite = ?',
                    'finance' => 'SELECT 1 FROM finance_user WHERE id_finance = ?',
                ][$type];
                $exists = db()->prepare($existsSql);
                $exists->execute([$accountId]);
                if (!$exists->fetchColumn()) {
                    throw new RuntimeException('Compte introuvable.');
                }
            }
            $message = 'Identifiants modifiés avec succès. Les affectations et les autres données du compte sont conservées.';
            audit_record('compte', 'modifier', $types[$type] . ' #' . $accountId . ' · identifiant ' . $login . ($password !== '' ? ' · mot de passe changé' : ''));
        } catch (PDOException $exception) {
            $error = 'Cet identifiant est déjà utilisé ou le compte n’a pas pu être modifié.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
        if ($error === '') {
            $search = '';
        }
    }
}

$accounts = [];
$stageTableAvailable = $type !== 'stage' || stage_accounts_table_exists();
if ($type === 'stage' && !$stageTableAvailable) {
    $error = 'La table des comptes stage est absente. Sauvegardez la base puis exécutez stage_accounts_migration.sql sur la base utilisée par cette application.';
}
if (isset($types[$type]) && $stageTableAvailable) {
    if ($type === 'enseignant') {
        $sql = 'SELECT id_enseignant AS account_id, cin AS login, CONCAT(prenom, " ", nom) AS detail FROM enseignant';
        $order = ' ORDER BY nom, prenom';
        $loginColumn = 'cin';
    } elseif ($type === 'stage') {
        access_ensure();
        $sql = 'SELECT id_stage AS account_id, cin AS login, CONCAT(nom, " · ", responsabilite) AS detail FROM stage_user';
        $order = ' ORDER BY cin';
        $loginColumn = 'cin';
    } elseif ($type === 'scolarite') {
        access_ensure();
        $sql = 'SELECT id_scolarite AS account_id, username AS login, nom AS detail FROM scolarite_user';
        $order = ' ORDER BY username';
        $loginColumn = 'username';
    } else {
        $sql = 'SELECT id_finance AS account_id, username AS login, IF(active = 1, "Actif", "Inactif") AS detail FROM finance_user';
        $order = ' ORDER BY username';
        $loginColumn = 'username';
    }
    $parameters = [];
    if ($search !== '') {
        $sql .= ' WHERE ' . $loginColumn . ' LIKE ?';
        $parameters[] = '%' . $search . '%';
    }
    $query = db()->prepare($sql . $order);
    $query->execute($parameters);
    $accounts = $query->fetchAll();

    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
    if ($editId < 1 && $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $editId = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT) ?: 0;
    }
    if ($editId > 0) {
        $editSql = [
            'enseignant' => 'SELECT id_enseignant AS account_id, cin AS login FROM enseignant WHERE id_enseignant = ?',
            'stage' => 'SELECT id_stage AS account_id, cin AS login, responsabilite FROM stage_user WHERE id_stage = ?',
            'scolarite' => 'SELECT id_scolarite AS account_id, username AS login FROM scolarite_user WHERE id_scolarite = ?',
            'finance' => 'SELECT id_finance AS account_id, username AS login FROM finance_user WHERE id_finance = ?',
        ][$type];
        $editQuery = db()->prepare($editSql);
        $editQuery->execute([$editId]);
        $editAccount = $editQuery->fetch() ?: null;
    }
}

page_head('Modifier comptes', 'accounts');

function account_password_field(string $id, string $name, string $label, string $hint = ''): void
{
    ?>
    <label class="form-label" for="<?= e($id) ?>"><?= $label ?><?php if ($hint !== ''): ?> <span class="text-secondary"><?= e($hint) ?></span><?php endif; ?></label>
    <div class="password-field">
      <input class="form-control" id="<?= e($id) ?>" name="<?= e($name) ?>" type="password" minlength="8" autocomplete="new-password">
      <button type="button" class="password-toggle" data-password-toggle aria-label="Afficher le mot de passe" aria-pressed="false" title="Afficher le mot de passe">
        <svg class="password-icon-show" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
        <svg class="password-icon-hide" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" hidden><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.94 10.94 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
      </button>
    </div>
    <?php
}
?>
<div class="mb-4">
  <p class="eyebrow">Gestion des comptes</p>
  <h1 class="display-6 fw-bold mb-1">Modifier les comptes</h1>
  <p class="text-secondary mb-0">Choisissez un type, puis mettez à jour l’identifiant ou le mot de passe.</p>
</div>
<div class="account-type-grid mb-4">
  <?php foreach ($types as $key => $label): ?>
    <a class="account-type-card <?= $type === $key ? 'is-selected' : '' ?>" href="modifier_comptes.php?type=<?= e($key) ?>">
      <span><?= e($label) ?></span>
      <strong><?= in_array($key, ['finance', 'scolarite'], true) ? 'Identifiant' : 'CIN' ?></strong>
    </a>
  <?php endforeach; ?>
</div>
<?php if ($message): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($types[$type])): ?>
  <?php if ($editAccount): ?>
  <section class="card app-card mb-4" aria-labelledby="edit-account-title">
    <div class="card-body p-4">
      <h2 id="edit-account-title" class="h5 mb-3">Modifier le compte <?= e(strtolower($types[$type])) ?></h2>
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="account_id" value="<?= (int) $editAccount['account_id'] ?>">
        <div class="col-md-4">
          <label class="form-label" for="login"><?= in_array($type, ['finance', 'scolarite'], true) ? 'Identifiant' : 'CIN' ?> *</label>
          <input class="form-control" id="login" name="login" value="<?= e($editAccount['login']) ?>" <?= in_array($type, ['finance', 'scolarite'], true) ? 'maxlength="80"' : 'maxlength="' . (int) identity_rule('cin')['max_length'] . '" pattern="' . e(identity_pattern('cin')) . '"' ?> required>
        </div>
        <?php if ($type === 'stage'): ?>
        <div class="col-md-4">
          <label class="form-label" for="responsabilite">Responsabilité</label>
          <select class="form-select" id="responsabilite" name="responsabilite">
            <?php foreach (['officer' => 'Responsable des stages', 'coordinateur' => 'Coordinateur', 'superviseur' => 'Superviseur'] as $duty => $dutyLabel): ?>
              <option value="<?= e($duty) ?>" <?= ($editAccount['responsabilite'] ?? '') === $duty ? 'selected' : '' ?>><?= e($dutyLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-md-4">
          <?php account_password_field('password', 'password', 'Nouveau mot de passe', '(facultatif)'); ?>
        </div>
        <div class="col-md-4">
          <?php account_password_field('password_confirm', 'password_confirm', 'Confirmation du mot de passe'); ?>
        </div>
        <input type="hidden" name="admin_password" id="adminPasswordField" value="">
        <div class="col-12">
          <p class="text-secondary small mb-2">Le mot de passe enregistré est chiffré et ne peut pas être relu. L’œil affiche ou masque le nouveau mot de passe. L’enregistrement d’un mot de passe demande le vôtre.</p>
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <a class="btn btn-outline-light" href="modifier_comptes.php?type=<?= urlencode($type) ?>&amp;embed=1">Annuler</a>
        </div>
      </form>
    </div>
  </section>
  <?php endif; ?>
  <section class="card app-card" aria-labelledby="account-list-title">
    <div class="card-body p-4 pb-3">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
        <div><h2 id="account-list-title" class="h5 mb-1">Comptes <?= e(strtolower($types[$type])) ?></h2><p class="text-secondary mb-0">Les mots de passe enregistrés restent chiffrés.</p></div>
        <form method="get" class="d-flex gap-2">
          <input type="hidden" name="type" value="<?= e($type) ?>">
          <label class="visually-hidden" for="search">Rechercher par identifiant</label>
          <input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="<?= $type === 'finance' ? 'Identifiant finance' : 'CIN' ?>">
          <button class="btn btn-outline-light" type="submit">Rechercher</button>
        </form>
      </div>
    </div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-dark table-hover align-middle mb-0">
        <thead><tr><th>Identifiant</th><th>Compte</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          <?php foreach ($accounts as $account): ?>
          <tr><td><?= e($account['login']) ?></td><td><?= e($account['detail'] ?? '') ?></td><td class="text-end"><a class="btn btn-sm btn-outline-info" href="modifier_comptes.php?type=<?= urlencode($type) ?>&amp;edit=<?= (int) $account['account_id'] ?>&amp;embed=1">Modifier</a></td></tr>
          <?php endforeach; ?>
          <?php if (!$accounts): ?><tr><td colspan="3" class="text-center text-secondary py-4">Aucun compte ne correspond à cette recherche.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>
<div class="attestation-accept-overlay" id="adminPasswordOverlay" hidden>
  <div class="attestation-accept-dialog" role="dialog" aria-modal="true" aria-labelledby="adminPasswordTitle">
    <div class="attestation-accept-header" data-dialog-drag>
      <div class="macos-traffic macos-only-control">
        <button type="button" class="macos-dot macos-dot-close" data-admin-close aria-label="Annuler" title="Annuler"></button>
        <span class="macos-dot macos-dot-min" aria-hidden="true"></span>
        <span class="macos-dot macos-dot-max" aria-hidden="true"></span>
      </div>
      <h2 class="h5 mb-0" id="adminPasswordTitle">Mot de passe administrateur</h2>
      <button type="button" class="btn-close" data-admin-close aria-label="Fermer"></button>
    </div>
    <div class="attestation-accept-body">
      <table class="download-confirm-table mb-3">
        <tbody>
          <tr>
            <th scope="row">Question</th>
            <td>Confirmez votre mot de passe administrateur pour enregistrer ce nouveau mot de passe.</td>
          </tr>
        </tbody>
      </table>
      <label class="form-label" for="adminPasswordInput">Votre mot de passe</label>
      <div class="password-field">
        <input class="form-control" id="adminPasswordInput" type="password" autocomplete="current-password">
        <button type="button" class="password-toggle" data-password-toggle aria-label="Afficher le mot de passe" aria-pressed="false" title="Afficher le mot de passe">
          <svg class="password-icon-show" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="password-icon-hide" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" hidden><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.94 10.94 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
        </button>
      </div>
      <p class="download-confirm-status is-error" id="adminPasswordStatus" hidden>Saisissez votre mot de passe administrateur.</p>
    </div>
    <div class="attestation-accept-footer">
      <button type="button" class="btn btn-outline-secondary macos-chip macos-chip-neutral" data-admin-close>Annuler</button>
      <button type="button" class="btn btn-primary macos-chip macos-chip-ok" id="adminPasswordConfirm">Confirmer</button>
    </div>
  </div>
</div>
<script>
(function () {
  document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    const field = button.parentElement.querySelector('input');
    if (!field) return;
    button.addEventListener('click', function () {
      const visible = field.type === 'text';
      field.type = visible ? 'password' : 'text';
      button.setAttribute('aria-pressed', visible ? 'false' : 'true');
      const label = visible ? 'Afficher le mot de passe' : 'Masquer le mot de passe';
      button.setAttribute('aria-label', label);
      button.title = label;
      const openIcon = button.querySelector('.password-icon-show');
      const closedIcon = button.querySelector('.password-icon-hide');
      if (openIcon) openIcon.hidden = !visible;
      if (closedIcon) closedIcon.hidden = visible;
      field.focus();
    });
  });

  const form = document.querySelector('form.row');
  const overlay = document.getElementById('adminPasswordOverlay');
  if (overlay && overlay.parentElement !== document.body) {
    document.body.appendChild(overlay);
  }
  const dialog = overlay ? overlay.querySelector('.attestation-accept-dialog') : null;
  const adminInput = document.getElementById('adminPasswordInput');
  const adminField = document.getElementById('adminPasswordField');
  const status = document.getElementById('adminPasswordStatus');
  if (!form || !overlay || !dialog || !adminInput || !adminField) return;

  let allowSubmit = false;
  function resetPosition() {
    dialog.classList.remove('is-moved', 'is-dragging');
    dialog.style.left = '';
    dialog.style.top = '';
    dialog.style.transform = '';
  }
  function closePrompt() {
    overlay.hidden = true;
    adminInput.value = '';
    if (status) status.hidden = true;
    resetPosition();
  }
  form.addEventListener('submit', function (event) {
    const password = document.getElementById('password');
    if (allowSubmit || !password || password.value === '') return;
    event.preventDefault();
    adminInput.value = '';
    if (status) status.hidden = true;
    resetPosition();
    overlay.hidden = false;
    adminInput.focus();
  });
  overlay.querySelectorAll('[data-admin-close]').forEach(function (button) {
    button.addEventListener('click', closePrompt);
  });
  overlay.addEventListener('click', function (event) {
    if (event.target === overlay) closePrompt();
  });
  document.getElementById('adminPasswordConfirm').addEventListener('click', function () {
    if (adminInput.value === '') {
      if (status) status.hidden = false;
      adminInput.focus();
      return;
    }
    adminField.value = adminInput.value;
    allowSubmit = true;
    form.requestSubmit();
  });
  adminInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      document.getElementById('adminPasswordConfirm').click();
    }
  });
  const handle = overlay.querySelector('[data-dialog-drag]');
  if (!handle) return;
  let drag = null;
  handle.addEventListener('pointerdown', function (event) {
    if (event.button !== 0 || event.target.closest('button, a, input')) return;
    const bounds = overlay.getBoundingClientRect();
    const rect = dialog.getBoundingClientRect();
    dialog.classList.add('is-moved', 'is-dragging');
    dialog.style.left = (rect.left - bounds.left) + 'px';
    dialog.style.top = (rect.top - bounds.top) + 'px';
    dialog.style.transform = 'none';
    drag = { pointerId: event.pointerId, offsetX: event.clientX - rect.left, offsetY: event.clientY - rect.top };
    handle.setPointerCapture(event.pointerId);
  });
  handle.addEventListener('pointermove', function (event) {
    if (!drag || drag.pointerId !== event.pointerId) return;
    const bounds = overlay.getBoundingClientRect();
    const left = Math.min(Math.max(0, event.clientX - drag.offsetX - bounds.left), Math.max(0, bounds.width - dialog.offsetWidth));
    const top = Math.min(Math.max(0, event.clientY - drag.offsetY - bounds.top), Math.max(0, bounds.height - dialog.offsetHeight));
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
})();
</script>
<?php page_foot(); ?>

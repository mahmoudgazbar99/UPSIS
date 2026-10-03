<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$types = [
    'enseignant' => 'Enseignant',
    'stage' => 'Stage',
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
    } elseif (in_array($type, ['enseignant', 'stage'], true) && !preg_match('/^\d{8}$/', $login)) {
        $error = 'Le CIN doit contenir exactement 8 chiffres.';
    } elseif ($type === 'finance' && mb_strlen($login) > 80) {
        $error = 'L’identifiant finance ne peut pas dépasser 80 caractères.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $confirmation) {
        $error = 'Les deux mots de passe ne correspondent pas.';
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
                $query = 'UPDATE stage_user SET cin = ?, auth_generation = auth_generation + 1';
                $parameters = [$login];
                if ($password !== '') {
                    $query .= ', password = ?';
                    $parameters[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $query .= ' WHERE id_stage = ?';
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
        $sql = 'SELECT id_stage AS account_id, cin AS login, IF(active = 1, "Actif", "Inactif") AS detail FROM stage_user';
        $order = ' ORDER BY cin';
        $loginColumn = 'cin';
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
    if ($editId > 0) {
        $editSql = [
            'enseignant' => 'SELECT id_enseignant AS account_id, cin AS login FROM enseignant WHERE id_enseignant = ?',
            'stage' => 'SELECT id_stage AS account_id, cin AS login FROM stage_user WHERE id_stage = ?',
            'finance' => 'SELECT id_finance AS account_id, username AS login FROM finance_user WHERE id_finance = ?',
        ][$type];
        $editQuery = db()->prepare($editSql);
        $editQuery->execute([$editId]);
        $editAccount = $editQuery->fetch() ?: null;
    }
}

page_head('Modifier comptes', 'accounts');
?>
<div class="mb-4">
  <p class="eyebrow">Gestion des comptes</p>
  <h1 class="display-6 fw-bold mb-1">Modifier les comptes</h1>
  <p class="text-secondary mb-0">Choisissez un type, puis mettez à jour l’identifiant ou le mot de passe.</p>
</div>
<div class="account-type-grid mb-4">
  <?php foreach (['enseignant' => 'Enseignant', 'finance' => 'Finance', 'stage' => 'Stage'] as $key => $label): ?>
    <a class="account-type-card <?= $type === $key ? 'is-selected' : '' ?>" href="modifier_comptes.php?type=<?= e($key) ?>">
      <span><?= e($label) ?></span>
      <strong><?= $key === 'finance' ? 'Identifiant' : 'CIN' ?></strong>
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
          <label class="form-label" for="login"><?= $type === 'finance' ? 'Identifiant finance' : 'CIN' ?> *</label>
          <input class="form-control" id="login" name="login" value="<?= e($editAccount['login']) ?>" <?= $type === 'finance' ? 'maxlength="80"' : 'inputmode="numeric" minlength="8" maxlength="8" pattern="[0-9]{8}"' ?> required>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="password">Nouveau mot de passe <span class="text-secondary">(facultatif)</span></label>
          <input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="password_confirm">Confirmation du mot de passe</label>
          <input class="form-control" id="password_confirm" name="password_confirm" type="password" minlength="8" autocomplete="new-password">
        </div>
        <div class="col-12">
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
        <div><h2 id="account-list-title" class="h5 mb-1">Comptes <?= e(strtolower($types[$type])) ?></h2><p class="text-secondary mb-0">Aucun mot de passe n’est affiché.</p></div>
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
<?php page_foot(); ?>

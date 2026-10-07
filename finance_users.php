<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$message = '';
$error = '';
$editAccount = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'create');
    $accountId = filter_input(INPUT_POST, 'id_finance', FILTER_VALIDATE_INT) ?: 0;
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    if ($action === 'toggle_active' && $accountId) {
        $active = filter_input(INPUT_POST, 'active', FILTER_VALIDATE_INT);
        if (!in_array($active, [0, 1], true)) {
            $error = 'État de compte invalide.';
        } else {
            try {
                db()->prepare('UPDATE finance_user SET active = ?, auth_generation = auth_generation + 1 WHERE id_finance = ?')->execute([$active, $accountId]);
                $message = $active === 1 ? 'Compte finance activé.' : 'Compte finance désactivé.';
                audit_record('compte', $active === 1 ? 'activer' : 'desactiver', 'Compte finance #' . $accountId);
            } catch (PDOException $exception) {
                $error = 'Impossible de modifier l’état du compte.';
            }
        }
    } elseif ($username === '') {
        $error = 'Veuillez saisir un identifiant.';
    } elseif ($action === 'create' && $password === '') {
        $error = 'Veuillez saisir un mot de passe.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $confirm) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        try {
            if ($action === 'update' && $accountId) {
                $statement = db()->prepare('UPDATE finance_user SET username = ?' . ($password !== '' ? ', password = ?, auth_generation = auth_generation + 1' : '') . ' WHERE id_finance = ?');
                $parameters = [$username];
                if ($password !== '') $parameters[] = password_hash($password, PASSWORD_DEFAULT);
                $parameters[] = $accountId;
                $statement->execute($parameters);
                $message = 'Compte finance modifié avec succès.';
                audit_record('compte', 'modifier', 'Compte finance ' . $username . ($password !== '' ? ' · mot de passe changé' : ''));
            } elseif ($action === 'create') {
                db()->prepare('INSERT INTO finance_user (username, password) VALUES (?, ?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                $message = 'Compte finance créé avec succès.';
                audit_record('compte', 'creer', 'Compte finance ' . $username);
            } else {
                $error = 'Opération invalide.';
            }
        } catch (PDOException $exception) {
            $error = 'Cet identifiant finance existe déjà ou le compte est introuvable.';
        }
    }
}
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$accounts = db()->query('SELECT id_finance, username, active FROM finance_user ORDER BY username')->fetchAll();
if ($editId) {
    $editQuery = db()->prepare('SELECT id_finance, username FROM finance_user WHERE id_finance = ?');
    $editQuery->execute([$editId]);
    $editAccount = $editQuery->fetch() ?: null;
}
page_head('Comptes finance', 'finance');
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="eyebrow">Administration</p><h1 class="display-6 fw-bold mb-1">Comptes du service finance</h1><p class="text-secondary mb-0">Créez les identifiants utilisés sur la page de connexion.</p></div></div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4"><div class="card-body p-4"><h2 class="h5"><?= $editAccount ? 'Modifier le compte finance' : 'Créer un compte finance' ?></h2><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $editAccount ? 'update' : 'create' ?>"><?php if ($editAccount): ?><input type="hidden" name="id_finance" value="<?= (int) $editAccount['id_finance'] ?>"><?php endif; ?><div class="col-md-4"><label class="form-label">Identifiant *</label><input class="form-control" name="username" value="<?= e($editAccount['username'] ?? '') ?>" required></div><div class="col-md-4"><label class="form-label">Nouveau mot de passe <?= $editAccount ? '(facultatif)' : '*' ?></label><input class="form-control" name="password" type="password" minlength="8" <?= $editAccount ? '' : 'required' ?>></div><div class="col-md-4"><label class="form-label">Confirmation <?= $editAccount ? '(si mot de passe modifié)' : '*' ?></label><input class="form-control" name="password_confirm" type="password" minlength="8" <?= $editAccount ? '' : 'required' ?>></div><div class="col-12"><button class="btn btn-primary"><?= $editAccount ? 'Enregistrer les modifications' : 'Créer le compte finance' ?></button><?php if ($editAccount): ?> <a class="btn btn-outline-light" href="finance_users.php">Annuler</a><?php endif; ?></div></form></div></div>
<div class="card app-card"><div class="card-body p-0 table-responsive"><table class="table table-dark table-hover mb-0"><thead><tr><th>Identifiant</th><th>État</th><th>Action</th></tr></thead><tbody><?php foreach ($accounts as $account): ?><tr><td><?= e($account['username']) ?></td><td><span class="<?= (int) $account['active'] === 1 ? 'text-success' : 'text-secondary' ?>"><?= (int) $account['active'] === 1 ? 'Actif' : 'Inactif' ?></span> <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id_finance" value="<?= (int) $account['id_finance'] ?>"><input type="hidden" name="active" value="<?= (int) $account['active'] === 1 ? '0' : '1' ?>"><button class="btn btn-sm <?= (int) $account['active'] === 1 ? 'btn-outline-warning' : 'btn-outline-success' ?>" type="submit"><?= (int) $account['active'] === 1 ? 'Désactiver' : 'Activer' ?></button></form></td><td><a class="btn btn-sm btn-outline-info" href="finance_users.php?edit=<?= (int) $account['id_finance'] ?>">Modifier</a></td></tr><?php endforeach; ?><?php if (!$accounts): ?><tr><td colspan="3" class="text-center text-secondary py-4">Aucun compte finance.</td></tr><?php endif; ?></tbody></table></div></div>
<?php page_foot();



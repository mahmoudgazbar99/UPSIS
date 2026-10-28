<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();
ensure_teacher_active(db());

$types = [
    'etudiant' => 'Étudiant',
    'enseignant' => 'Enseignant',
    'finance' => 'Finance',
    'stage' => 'Stage',
    'scolarite' => 'Scolarité',
];
$type = (string) ($_GET['type'] ?? $_POST['type'] ?? '');
if (!isset($types[$type])) {
    $type = '';
}
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $type !== '') {
    verify_csrf();
    $accountId = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT) ?: 0;
    $active = (int) ($_POST['active'] ?? -1);
    if ($accountId < 1 || !in_array($active, [0, 1], true)) {
        $error = 'Compte invalide.';
    } else {
        try {
            if ($type === 'etudiant') {
                ensure_etudiant_compte_table(db());
                db()->prepare('UPDATE etudiant_compte SET active = ?, auth_generation = auth_generation + 1 WHERE id_compte = ?')->execute([$active, $accountId]);
                $label = 'Étudiant #' . $accountId;
            } elseif ($type === 'enseignant') {
                db()->prepare('UPDATE enseignant SET active = ?, auth_generation = auth_generation + 1 WHERE id_enseignant = ?')->execute([$active, $accountId]);
                $label = 'Enseignant #' . $accountId;
            } elseif ($type === 'finance') {
                db()->prepare('UPDATE finance_user SET active = ?, auth_generation = auth_generation + 1 WHERE id_finance = ?')->execute([$active, $accountId]);
                $label = 'Finance #' . $accountId;
            } elseif ($type === 'scolarite') {
                access_ensure();
                db()->prepare('UPDATE scolarite_user SET active = ?, auth_generation = auth_generation + 1 WHERE id_scolarite = ?')->execute([$active, $accountId]);
                $label = 'Scolarité #' . $accountId;
            } else {
                access_ensure();
                db()->prepare('UPDATE stage_user SET active = ?, auth_generation = auth_generation + 1 WHERE id_stage = ?')->execute([$active, $accountId]);
                $label = 'Stage #' . $accountId;
            }
            audit_record('compte', $active === 1 ? 'activer' : 'desactiver', $label);
            $message = $active === 1 ? 'Compte activé.' : 'Compte désactivé.';
        } catch (PDOException $exception) {
            $error = 'Impossible de changer l’état du compte.';
        }
    }
}

$accounts = [];
if ($type === 'etudiant') {
    ensure_etudiant_compte_table(db());
    $accounts = db()->query('SELECT c.id_compte AS id, CONCAT(e.prenom, " ", e.nom) AS label, e.cin_passeport AS login, c.active FROM etudiant_compte c INNER JOIN etudiant e ON e.id_etudiant = c.id_etudiant ORDER BY e.nom, e.prenom')->fetchAll();
} elseif ($type === 'enseignant') {
    $accounts = db()->query('SELECT id_enseignant AS id, CONCAT(prenom, " ", nom) AS label, cin AS login, active FROM enseignant ORDER BY nom, prenom')->fetchAll();
} elseif ($type === 'finance') {
    $accounts = db()->query('SELECT id_finance AS id, username AS label, username AS login, active FROM finance_user ORDER BY username')->fetchAll();
} elseif ($type === 'scolarite') {
    access_ensure();
    $accounts = db()->query('SELECT id_scolarite AS id, nom AS label, username AS login, active FROM scolarite_user ORDER BY username')->fetchAll();
} elseif ($type === 'stage' && stage_accounts_table_exists()) {
    $accounts = db()->query('SELECT id_stage AS id, cin AS label, cin AS login, active FROM stage_user ORDER BY cin')->fetchAll();
}

page_head('Activer ou désactiver', 'accounts');
?>
<div class="mb-4">
  <p class="eyebrow">Gestion des comptes</p>
  <h1 class="display-6 fw-bold mb-1">Activer / désactiver</h1>
  <p class="text-secondary mb-0">Choisissez un type de compte, puis changez son état.</p>
</div>
<div class="account-type-grid mb-4">
  <?php foreach ($types as $key => $label): ?>
    <a class="account-type-card <?= $type === $key ? 'is-selected' : '' ?>" href="activer_comptes.php?type=<?= e($key) ?>">
      <span><?= e($label) ?></span>
      <strong><?= $type === $key ? 'Sélectionné' : 'Ouvrir' ?></strong>
    </a>
  <?php endforeach; ?>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($type !== ''): ?>
<div class="card app-card"><div class="table-responsive"><table class="table table-dark align-middle mb-0">
  <thead><tr><th>Compte</th><th>Identifiant</th><th>État</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($accounts as $account): ?>
    <tr>
      <td><?= e((string) $account['label']) ?></td>
      <td><?= e((string) $account['login']) ?></td>
      <td class="<?= (int) $account['active'] === 1 ? 'text-success' : 'text-secondary' ?>"><?= (int) $account['active'] === 1 ? 'Actif' : 'Inactif' ?></td>
      <td>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="type" value="<?= e($type) ?>">
          <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
          <input type="hidden" name="active" value="<?= (int) $account['active'] === 1 ? '0' : '1' ?>">
          <button class="btn btn-sm <?= (int) $account['active'] === 1 ? 'btn-outline-warning' : 'btn-outline-success' ?>" type="submit"><?= (int) $account['active'] === 1 ? 'Désactiver' : 'Activer' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$accounts): ?><tr><td colspan="4" class="text-center text-secondary py-4">Aucun compte <?= e($types[$type]) ?>.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
<?php page_foot();

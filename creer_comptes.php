<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$types = [
    'enseignant' => 'Enseignant',
    'finance' => 'Finance',
    'stage' => 'Stage',
];
$type = (string) ($_GET['type'] ?? $_POST['type'] ?? '');
if (!isset($types[$type])) {
    $type = '';
}
$message = '';
$error = '';
ensure_teacher_active(db());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    try {
        if ($type === 'enseignant') {
            $data = [
                'cin' => trim((string) ($_POST['cin'] ?? '')),
                'nom' => trim((string) ($_POST['nom'] ?? '')),
                'prenom' => trim((string) ($_POST['prenom'] ?? '')),
                'num_tel' => trim((string) ($_POST['num_tel'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'adresse' => trim((string) ($_POST['adresse'] ?? '')),
            ];
            if (in_array('', $data, true) || $password === '') {
                throw new RuntimeException('Veuillez remplir tous les champs.');
            }
            if (!preg_match('/^\d{8}$/', $data['cin'])) {
                throw new RuntimeException('Le CIN doit contenir exactement 8 chiffres.');
            }
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Veuillez saisir une adresse e-mail valide.');
            }
            if (strlen($password) < 8 || $password !== $confirm) {
                throw new RuntimeException('Le mot de passe doit contenir au moins 8 caractères et être confirmé.');
            }
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            db()->prepare('INSERT INTO enseignant (cin, nom, prenom, num_tel, email, adresse, password) VALUES (:cin, :nom, :prenom, :num_tel, :email, :adresse, :password)')->execute($data);
            audit_record('compte', 'creer', 'Enseignant ' . $data['prenom'] . ' ' . $data['nom'] . ' · CIN ' . $data['cin']);
            $message = 'Compte enseignant créé.';
        } elseif ($type === 'finance') {
            $username = trim((string) ($_POST['username'] ?? ''));
            if ($username === '' || strlen($password) < 8 || $password !== $confirm) {
                throw new RuntimeException('Saisissez un identifiant et un mot de passe d’au moins 8 caractères.');
            }
            db()->prepare('INSERT INTO finance_user (username, password) VALUES (?, ?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            audit_record('compte', 'creer', 'Compte finance ' . $username);
            $message = 'Compte finance créé.';
        } elseif ($type === 'stage') {
            $cin = trim((string) ($_POST['cin'] ?? ''));
            if (!preg_match('/^\d{8}$/', $cin) || strlen($password) < 8 || $password !== $confirm) {
                throw new RuntimeException('Le CIN doit contenir 8 chiffres et le mot de passe au moins 8 caractères.');
            }
            if (!stage_accounts_table_exists()) {
                throw new RuntimeException('La table des comptes stage est absente.');
            }
            db()->prepare('INSERT INTO stage_user (cin, password) VALUES (?, ?)')->execute([$cin, password_hash($password, PASSWORD_DEFAULT)]);
            audit_record('compte', 'creer', 'Compte stage · CIN ' . $cin);
            $message = 'Compte stage créé.';
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = 'Ce compte existe déjà ou l’enregistrement a échoué.';
    }
}

page_head('Créer des comptes', 'accounts');
?>
<div class="mb-4">
  <p class="eyebrow">Gestion des comptes</p>
  <h1 class="display-6 fw-bold mb-1">Créer un compte</h1>
  <p class="text-secondary mb-0">Choisissez le type, puis remplissez le formulaire.</p>
</div>
<div class="account-type-grid mb-4">
  <?php foreach ($types as $key => $label): ?>
    <a class="account-type-card <?= $type === $key ? 'is-selected' : '' ?>" href="creer_comptes.php?type=<?= e($key) ?>">
      <span><?= e($label) ?></span>
      <strong><?= $key === 'enseignant' ? 'CIN et fiche' : ($key === 'finance' ? 'Identifiant' : 'CIN') ?></strong>
    </a>
  <?php endforeach; ?>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($type !== ''): ?>
<div class="card app-card"><div class="card-body p-4">
  <h2 class="h5 mb-3">Nouveau compte <?= e($types[$type]) ?></h2>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if ($type === 'enseignant'): ?>
      <div class="col-md-4"><label class="form-label">CIN *</label><input class="form-control" name="cin" inputmode="numeric" minlength="8" maxlength="8" pattern="[0-9]{8}" required></div>
      <div class="col-md-4"><label class="form-label">Nom *</label><input class="form-control" name="nom" required></div>
      <div class="col-md-4"><label class="form-label">Prénom *</label><input class="form-control" name="prenom" required></div>
      <div class="col-md-4"><label class="form-label">Téléphone *</label><input class="form-control" name="num_tel" required></div>
      <div class="col-md-4"><label class="form-label">E-mail *</label><input class="form-control" type="email" name="email" required></div>
      <div class="col-md-4"><label class="form-label">Adresse *</label><input class="form-control" name="adresse" required></div>
    <?php elseif ($type === 'finance'): ?>
      <div class="col-md-4"><label class="form-label">Identifiant *</label><input class="form-control" name="username" required></div>
    <?php else: ?>
      <div class="col-md-4"><label class="form-label">CIN *</label><input class="form-control" name="cin" inputmode="numeric" minlength="8" maxlength="8" pattern="[0-9]{8}" required></div>
    <?php endif; ?>
    <div class="col-md-4"><label class="form-label">Mot de passe *</label><input class="form-control" type="password" name="password" minlength="8" required></div>
    <div class="col-md-4"><label class="form-label">Confirmation *</label><input class="form-control" type="password" name="password_confirm" minlength="8" required></div>
    <div class="col-12"><button class="btn btn-primary">Créer le compte</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php page_foot();

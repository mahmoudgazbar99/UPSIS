<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

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
ensure_teacher_active(db());
access_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    try {
        if ($type === 'etudiant') {
            ensure_etudiant_compte_table(db());
            $cin = student_normalize_cin((string) ($_POST['cin'] ?? ''));
            if ($cin === '' || mb_strlen($cin) > 40 || strlen($password) < 8 || strlen($password) > 72 || $password !== $confirm) {
                throw new RuntimeException('Indiquez le CIN ou le passeport d’un étudiant inscrit et un mot de passe de 8 à 72 caractères.');
            }
            $student = student_find_by_cin($cin);
            if (!$student) {
                throw new RuntimeException('Aucun étudiant inscrit ne correspond à ce CIN.');
            }
            $existing = db()->prepare('SELECT 1 FROM etudiant_compte WHERE id_etudiant = ? LIMIT 1');
            $existing->execute([(int) $student['id_etudiant']]);
            if ($existing->fetchColumn()) {
                throw new RuntimeException('Un espace existe déjà pour cet étudiant. Modifiez-le ou activez-le.');
            }
            db()->prepare('INSERT INTO etudiant_compte (id_etudiant, password, active) VALUES (?, ?, 1)')->execute([
                (int) $student['id_etudiant'],
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            audit_record('compte', 'creer', 'Espace étudiant de ' . trim((string) $student['prenom'] . ' ' . (string) $student['nom']) . ' · ' . $cin);
            $message = 'Compte étudiant créé.';
        } elseif ($type === 'enseignant') {
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
            if (!identity_matches('cin', $data['cin'])) {
                throw new RuntimeException(identity_error('cin'));
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
            if (!identity_matches('cin', $cin) || strlen($password) < 8 || $password !== $confirm) {
                throw new RuntimeException(identity_error('cin') . ' Le mot de passe doit contenir au moins 8 caractères.');
            }
            if (!stage_accounts_table_exists()) {
                throw new RuntimeException('La table des comptes stage est absente.');
            }
            db()->prepare('INSERT INTO stage_user (cin, nom, responsabilite, password) VALUES (?, ?, ?, ?)')->execute([
                $cin,
                trim((string) ($_POST['nom'] ?? '')),
                in_array((string) ($_POST['responsabilite'] ?? ''), ['officer', 'coordinateur', 'superviseur'], true) ? (string) $_POST['responsabilite'] : 'officer',
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            audit_record('compte', 'creer', 'Compte stage · CIN ' . $cin);
            $message = 'Compte stage créé.';
        } elseif ($type === 'scolarite') {
            access_ensure();
            $username = trim((string) ($_POST['username'] ?? ''));
            $nom = trim((string) ($_POST['nom'] ?? ''));
            if ($username === '' || $nom === '' || strlen($password) < 8 || $password !== $confirm) {
                throw new RuntimeException('Saisissez le nom, l’identifiant et un mot de passe d’au moins 8 caractères.');
            }
            db()->prepare('INSERT INTO scolarite_user (username, nom, password) VALUES (?, ?, ?)')->execute([$username, $nom, password_hash($password, PASSWORD_DEFAULT)]);
            audit_record('compte', 'creer', 'Compte scolarité ' . $username);
            $message = 'Compte scolarité créé.';
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
      <strong><?= $key === 'etudiant' ? 'CIN d’un inscrit' : ($key === 'enseignant' ? 'CIN et fiche' : (in_array($key, ['finance', 'scolarite'], true) ? 'Identifiant' : 'CIN')) ?></strong>
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
    <?php if ($type === 'etudiant'): ?>
      <div class="col-md-6"><label class="form-label">CIN / passeport *</label><input class="form-control" name="cin" maxlength="40" required></div>
    <?php elseif ($type === 'enseignant'): ?>
      <div class="col-md-4"><label class="form-label">CIN *</label><input class="form-control" name="cin" inputmode="numeric" minlength="<?= (int) identity_rule('cin')['min_length'] ?>" maxlength="<?= (int) identity_rule('cin')['max_length'] ?>" pattern="<?= e(identity_pattern('cin')) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Nom *</label><input class="form-control" name="nom" required></div>
      <div class="col-md-4"><label class="form-label">Prénom *</label><input class="form-control" name="prenom" required></div>
      <div class="col-md-4"><label class="form-label">Téléphone *</label><input class="form-control" name="num_tel" required></div>
      <div class="col-md-4"><label class="form-label">E-mail *</label><input class="form-control" type="email" name="email" required></div>
      <div class="col-md-4"><label class="form-label">Adresse *</label><input class="form-control" name="adresse" required></div>
    <?php elseif ($type === 'finance' || $type === 'scolarite'): ?>
      <div class="col-md-4"><label class="form-label">Identifiant *</label><input class="form-control" name="username" required></div>
      <?php if ($type === 'scolarite'): ?><div class="col-md-4"><label class="form-label">Nom affiché *</label><input class="form-control" name="nom" required></div><?php endif; ?>
    <?php else: ?>
      <div class="col-md-4"><label class="form-label">CIN *</label><input class="form-control" name="cin" inputmode="numeric" minlength="<?= (int) identity_rule('cin')['min_length'] ?>" maxlength="<?= (int) identity_rule('cin')['max_length'] ?>" pattern="<?= e(identity_pattern('cin')) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Nom</label><input class="form-control" name="nom"></div>
      <div class="col-md-4"><label class="form-label">Responsabilité</label><select class="form-select" name="responsabilite"><option value="officer">Responsable des stages</option><option value="coordinateur">Coordinateur des affectations</option><option value="superviseur">Superviseur</option></select></div>
    <?php endif; ?>
    <div class="col-md-4"><label class="form-label">Mot de passe *</label><input class="form-control" type="password" name="password" minlength="8" required></div>
    <div class="col-md-4"><label class="form-label">Confirmation *</label><input class="form-control" type="password" name="password_confirm" minlength="8" required></div>
    <div class="col-12"><button class="btn btn-primary">Créer le compte</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php page_foot();

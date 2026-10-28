<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$message = '';
$error = '';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $data = [
        'cin' => trim((string) ($_POST['cin'] ?? '')),
        'nom' => trim((string) ($_POST['nom'] ?? '')),
        'prenom' => trim((string) ($_POST['prenom'] ?? '')),
        'num_tel' => trim((string) ($_POST['num_tel'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'adresse' => trim((string) ($_POST['adresse'] ?? '')),
        'password' => (string) ($_POST['password'] ?? ''),
        'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
    ];
    if (in_array('', $data, true)) {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (!preg_match('/^\d{8}$/', $data['cin'])) {
        $error = 'Le CIN de l’enseignant doit contenir exactement 8 chiffres.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Veuillez saisir une adresse e-mail valide.';
    } elseif ($data['password'] !== $data['password_confirm']) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        try {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password_confirm']);
            $pdo->prepare('INSERT INTO enseignant (cin, nom, prenom, num_tel, email, adresse, password) VALUES (:cin, :nom, :prenom, :num_tel, :email, :adresse, :password)')->execute($data);
            $message = 'Enseignant(e) ajouté(e) avec succès.';
            audit_record('compte', 'creer', 'Enseignant ' . $data['prenom'] . ' ' . $data['nom'] . ' · CIN ' . $data['cin']);
        } catch (PDOException $exception) {
            $error = 'Enregistrement impossible : le CIN ou l’e-mail existe déjà.';
        }
    }
}
$teachers = $pdo->query('SELECT * FROM enseignant ORDER BY nom, prenom')->fetchAll();
page_head('Ajouter Enseignant(e)', 'teachers');
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="eyebrow">Scolarité</p><h1 class="display-6 fw-bold mb-1">Ajouter Enseignant(e)</h1><p class="text-secondary mb-0">Enregistrez les enseignants dans la base de données.</p></div></div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4"><div class="card-body p-4"><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="col-md-4"><label class="form-label">CIN *</label><input class="form-control" name="cin" inputmode="numeric" minlength="8" maxlength="8" pattern="[0-9]{8}" required></div><div class="col-md-4"><label class="form-label">Nom *</label><input class="form-control" name="nom" required></div><div class="col-md-4"><label class="form-label">Prénom *</label><input class="form-control" name="prenom" required></div><div class="col-md-4"><label class="form-label">Téléphone *</label><input class="form-control" name="num_tel" required></div><div class="col-md-4"><label class="form-label">E-mail *</label><input class="form-control" type="email" name="email" required></div><div class="col-md-4"><label class="form-label">Adresse *</label><input class="form-control" name="adresse" required></div><div class="col-md-6"><label class="form-label">Mot de passe *</label><input class="form-control" type="password" name="password" minlength="4" required></div><div class="col-md-6"><label class="form-label">Confirmer le mot de passe *</label><input class="form-control" type="password" name="password_confirm" minlength="4" required></div><div class="col-12"><button class="btn btn-primary">Enregistrer l’enseignant(e)</button></div></form></div></div>
<div class="card app-card"><div class="card-body p-0 table-responsive"><table class="table table-dark table-hover mb-0"><thead><tr><th>CIN</th><th>Nom et prénom</th><th>Téléphone</th><th>E-mail</th><th>Adresse</th></tr></thead><tbody><?php foreach ($teachers as $teacher): ?><tr><td><?= e($teacher['cin']) ?></td><td><?= e($teacher['nom'] . ' ' . $teacher['prenom']) ?></td><td><?= e($teacher['num_tel']) ?></td><td><?= e($teacher['email']) ?></td><td><?= e($teacher['adresse']) ?></td></tr><?php endforeach; ?><?php if (!$teachers): ?><tr><td colspan="5" class="text-center text-secondary py-4">Aucun enseignant enregistré.</td></tr><?php endif; ?></tbody></table></div></div>
<?php page_foot();



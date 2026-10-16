<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_permission('stage.hosts');
access_ensure();
$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $capacite = (int) ($_POST['capacite'] ?? 1);
        if ($nom === '' || mb_strlen($nom) > 180 || $capacite < 1 || $capacite > 500) {
            throw new InvalidArgumentException('Saisissez le nom et une capacité entre 1 et 500.');
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('L’adresse e-mail est invalide.');
        }
        db()->prepare(
            'INSERT INTO stage_organisme (nom, id_categorie, adresse, ville, contact_nom, email, telephone, domaines, capacite, convention_debut, convention_fin, pieces_requises, notes, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $nom,
            (int) ($_POST['id_categorie'] ?? 0) ?: null,
            trim((string) ($_POST['adresse'] ?? '')),
            trim((string) ($_POST['ville'] ?? '')),
            trim((string) ($_POST['contact_nom'] ?? '')),
            $email,
            trim((string) ($_POST['telephone'] ?? '')),
            trim((string) ($_POST['domaines'] ?? '')),
            $capacite,
            ($_POST['convention_debut'] ?? '') !== '' ? $_POST['convention_debut'] : null,
            ($_POST['convention_fin'] ?? '') !== '' ? $_POST['convention_fin'] : null,
            trim((string) ($_POST['pieces_requises'] ?? '')),
            trim((string) ($_POST['notes'] ?? '')),
        ]);
        audit_record('stage', 'organisme', 'Organisme ' . $nom);
        $message = 'Organisme enregistré.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
}
$categories = db()->query('SELECT * FROM stage_categorie WHERE active = 1 ORDER BY nom')->fetchAll();
$rows = db()->query(
    'SELECT o.*, c.nom AS categorie,
            (SELECT COUNT(*) FROM stage_placement p WHERE p.id_organisme = o.id_organisme AND p.statut IN ("approuve","affecte","en_cours")) AS occupes
     FROM stage_organisme o LEFT JOIN stage_categorie c ON c.id_categorie = o.id_categorie
     ORDER BY o.active DESC, o.nom'
)->fetchAll();
page_head('Organismes de stage', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Stages</p>
  <h1 class="display-6 fw-bold mb-1">Organismes d’accueil</h1>
  <p class="text-secondary mb-0">Hôpitaux, entreprises, administrations ou tout autre lieu. La capacité limite les stages en cours.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="col-md-4"><label class="form-label">Nom *</label><input class="form-control" name="nom" required></div>
    <div class="col-md-4"><label class="form-label">Catégorie</label><select class="form-select" name="id_categorie"><option value="">Choisir</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id_categorie'] ?>"><?= e($category['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Capacité *</label><input class="form-control" name="capacite" type="number" min="1" value="1" required></div>
    <div class="col-md-2"><label class="form-label">Ville</label><input class="form-control" name="ville"></div>
    <div class="col-md-6"><label class="form-label">Adresse</label><input class="form-control" name="adresse"></div>
    <div class="col-md-3"><label class="form-label">Contact</label><input class="form-control" name="contact_nom"></div>
    <div class="col-md-3"><label class="form-label">Téléphone</label><input class="form-control" name="telephone"></div>
    <div class="col-md-4"><label class="form-label">E-mail</label><input class="form-control" type="email" name="email"></div>
    <div class="col-md-4"><label class="form-label">Domaines</label><input class="form-control" name="domaines" placeholder="Soins, laboratoire, gestion…"></div>
    <div class="col-md-2"><label class="form-label">Convention du</label><input class="form-control" type="date" name="convention_debut"></div>
    <div class="col-md-2"><label class="form-label">Au</label><input class="form-control" type="date" name="convention_fin"></div>
    <div class="col-md-6"><label class="form-label">Pièces demandées</label><input class="form-control" name="pieces_requises"></div>
    <div class="col-md-6"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer</button> <a class="btn btn-outline-light" href="stage_portal.php<?= app_is_embedded() ? '?embed=1' : '' ?>">Retour</a></div>
  </form>
</div></div>
<div class="card app-card"><div class="table-responsive"><table class="table table-dark table-hover mb-0">
  <thead><tr><th>Organisme</th><th>Catégorie</th><th>Places</th><th>Convention</th></tr></thead>
  <tbody><?php foreach ($rows as $row): ?><tr>
    <td><?= e($row['nom']) ?><br><small class="text-secondary"><?= e($row['ville'] . ' · ' . $row['contact_nom']) ?></small></td>
    <td><?= e((string) ($row['categorie'] ?? '—')) ?></td>
    <td><?= (int) $row['occupes'] ?> / <?= (int) $row['capacite'] ?></td>
    <td><?= e(trim((string) $row['convention_debut'] . ' ' . (string) $row['convention_fin'])) ?: '—' ?></td>
  </tr><?php endforeach; ?></tbody>
</table></div></div>
<?php page_foot();

<?php
/*
require_once __DIR__ . '/layout.php';
require_login();

$fields = ['nom', 'prenom', 'nom_ar', 'prenom_ar', 'date_naissance', 'lieu_naissance', 'lieu_naissance_ar', 'sexe', 'nationalite', 'etat_civil', 'cin_passeport', 'num_tel', 'adresse', 'email', 'date_inscrit', 'id_spec', 'niveau', 'annee_bac', 'specialite_bac'];
$specs = db()->query('SELECT * FROM specialite ORDER BY nom_spec')->fetchAll();
$data = array_fill_keys($fields, '');
$data['date_inscrit'] = date('Y-m-d');
$data['niveau'] = '1';
$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$editing = $studentId > 0;
$step = 1;
$error = '';
$registrationNumber = '';

if ($editing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = db()->prepare('SELECT * FROM etudiant WHERE id_etudiant = ?');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        http_response_code(404);
        $error = 'Étudiant introuvable.';
        $editing = false;
        $studentId = 0;
    } else {
        foreach ($fields as $field) $data[$field] = (string) $student[$field];
        $registrationNumber = $student['num_inscription'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = filter_input(INPUT_POST, 'id_etudiant', FILTER_VALIDATE_INT) ?: 0;
    $editing = $studentId > 0;
    foreach ($fields as $field) $data[$field] = trim((string) ($_POST[$field] ?? ''));
    $missing = array_filter($fields, fn(string $field): bool => $data[$field] === '');
    $action = $_POST['action'] ?? '';

    if ($action === 'verify') {
        $error = $missing ? 'Veuillez remplir tous les champs obligatoires.' : '';
        $step = $error ? 1 : 2;
    }
    if ($action === 'submit') {
        if ($missing) {
            $error = 'Veuillez remplir tous les champs obligatoires.';
        } else {
            try {
                if ($editing) {
                    $sql = 'UPDATE etudiant SET nom=:nom, prenom=:prenom, nom_ar=:nom_ar, prenom_ar=:prenom_ar, date_naissance=:date_naissance, lieu_naissance=:lieu_naissance, lieu_naissance_ar=:lieu_naissance_ar, sexe=:sexe, nationalite=:nationalite, etat_civil=:etat_civil, cin_passeport=:cin_passeport, num_tel=:num_tel, adresse=:adresse, email=:email, date_inscrit=:date_inscrit, id_spec=:id_spec, niveau=:niveau, annee_bac=:annee_bac, specialite_bac=:specialite_bac WHERE id_etudiant=:id_etudiant';
                    $data['id_etudiant'] = $studentId;
                    db()->prepare($sql)->execute($data);
                } else {
                    $pdo = db();
                    $pdo->beginTransaction();
                    $lastNumber = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(num_inscription, 4) AS UNSIGNED)), 0) FROM etudiant WHERE num_inscription REGEXP '^INS[0-9]+$'")->fetchColumn();
                    $data['num_inscription'] = 'INS' . str_pad((string) ($lastNumber + 1), 6, '0', STR_PAD_LEFT);
                    $sql = 'INSERT INTO etudiant (nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance, lieu_naissance_ar, sexe, nationalite, etat_civil, cin_passeport, num_tel, adresse, email, num_inscription, date_inscrit, id_spec, niveau, annee_bac, specialite_bac) VALUES (:nom, :prenom, :nom_ar, :prenom_ar, :date_naissance, :lieu_naissance, :lieu_naissance_ar, :sexe, :nationalite, :etat_civil, :cin_passeport, :num_tel, :adresse, :email, :num_inscription, :date_inscrit, :id_spec, :niveau, :annee_bac, :specialite_bac)';
                    $pdo->prepare($sql)->execute($data);
                    $pdo->commit();
                }
                header('Location: index.php?saved=1');
                exit;
            } catch (PDOException $exception) {
                if (db()->inTransaction()) db()->rollBack();
                $error = 'Enregistrement impossible : le CIN, l’e-mail ou le numéro d’inscription existe déjà.';
                $step = 1;
            }
        }
    }
}

if ($editing && $registrationNumber === '') {
    $stmt = db()->prepare('SELECT num_inscription FROM etudiant WHERE id_etudiant = ?');
    $stmt->execute([$studentId]);
    $registrationNumber = (string) $stmt->fetchColumn();
}

page_head($editing ? 'Modifier un étudiant' : 'Inscription étudiant', 'register');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div><p class="eyebrow"><?= $editing ? 'Mise à jour du dossier' : 'Nouveau dossier' ?></p><h1 class="display-6 fw-bold"><?= $editing ? 'Modifier un étudiant' : 'Inscription étudiant' ?></h1></div>
  
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card"><div class="card-body p-4 p-lg-5">
<?php if ($step === 1): ?>
<form method="post" class="row g-3">
  <?php if ($editing): ?><input type="hidden" name="id_etudiant" value="<?= $studentId ?>"><?php endif; ?>
  <h2 class="h5 text-info border-bottom border-secondary pb-2">Identité — Français</h2>
  <div class="col-md-6"><label class="form-label">Nom *</label><input class="form-control" name="nom" value="<?= e($data['nom']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Prénom *</label><input class="form-control" name="prenom" value="<?= e($data['prenom']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Date de naissance *</label><input class="form-control" type="date" name="date_naissance" value="<?= e($data['date_naissance']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Lieu de naissance *</label><input class="form-control" name="lieu_naissance" value="<?= e($data['lieu_naissance']) ?>" required></div>
  <h2 class="h5 text-info border-bottom border-secondary pb-2 mt-5">الهوية — العربية</h2>
  <div class="col-md-6"><label class="form-label">اللقب *</label><input class="form-control text-end" dir="rtl" name="nom_ar" value="<?= e($data['nom_ar']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">الاسم *</label><input class="form-control text-end" dir="rtl" name="prenom_ar" value="<?= e($data['prenom_ar']) ?>" required></div>
  <div class="col-md-12"><label class="form-label">مكان الولادة *</label><input class="form-control text-end" dir="rtl" name="lieu_naissance_ar" value="<?= e($data['lieu_naissance_ar']) ?>" required></div>
  <h2 class="h5 text-info border-bottom border-secondary pb-2 mt-5">Coordonnées et scolarité</h2>
  <div class="col-md-4"><label class="form-label">Sexe *</label><select class="form-select" name="sexe" required><option value="">Choisir</option><?php foreach (['Femme', 'Homme'] as $value): ?><option value="<?= $value ?>" <?= $data['sexe'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Nationalité *</label><input class="form-control" name="nationalite" value="<?= e($data['nationalite']) ?>" required></div>
  <div class="col-md-4"><label class="form-label">État civil *</label><select class="form-select" name="etat_civil" required><option value="">Choisir</option><?php foreach (['Célibataire', 'Marié(e)', 'Divorcé(e)', 'Veuf/Veuve'] as $value): ?><option value="<?= $value ?>" <?= $data['etat_civil'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">CIN / Passeport *</label><input class="form-control" name="cin_passeport" value="<?= e($data['cin_passeport']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Téléphone *</label><input class="form-control" name="num_tel" value="<?= e($data['num_tel']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">E-mail *</label><input class="form-control" type="email" name="email" value="<?= e($data['email']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Adresse *</label><input class="form-control" name="adresse" value="<?= e($data['adresse']) ?>" required></div>
  <div class="col-md-4"><label class="form-label">N° inscription</label><input class="form-control" value="<?= e($editing ? $registrationNumber : 'Généré automatiquement à l’enregistrement') ?>" readonly></div>
  <div class="col-md-4"><label class="form-label">Date d’inscription *</label><input class="form-control" type="date" name="date_inscrit" value="<?= e($data['date_inscrit']) ?>" required></div>
  <div class="col-md-4"><label class="form-label">Niveau *</label><select class="form-select" name="niveau" required><?php foreach (['1' => '1ère année', '2' => '2ème année', '3' => '3ème année'] as $value => $label): ?><option value="<?= $value ?>" <?= $data['niveau'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Spécialité *</label><select class="form-select" name="id_spec" required><option value="">Choisir</option><?php foreach ($specs as $spec): ?><option value="<?= $spec['id_spec'] ?>" <?= $data['id_spec'] == $spec['id_spec'] ? 'selected' : '' ?>><?= e($spec['nom_spec']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Année du bac *</label><input class="form-control" type="number" min="1950" max="<?= date('Y') ?>" name="annee_bac" value="<?= e($data['annee_bac']) ?>" required></div>
  <div class="col-md-3"><label class="form-label">Spécialité bac *</label><select class="form-select" name="specialite_bac" required><option value="">Choisir</option><?php foreach (['Science', 'Math', 'Lettre', 'Sport'] as $value): ?><option value="<?= $value ?>" <?= $data['specialite_bac'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
  <div class="col-12 mt-4 d-flex justify-content-end"><button name="action" value="verify" class="btn btn-primary px-4">Suivant : vérifier →</button></div>
</form>
<?php else: ?>
<h2 class="h4 mb-4">Vérification du dossier</h2><p class="text-secondary">Vérifiez les informations avant l’enregistrement définitif.</p>
<div class="review-grid mb-4"><?php foreach ($data as $key => $value): ?><div><span><?= e(str_replace('_', ' ', ucfirst($key))) ?></span><strong><?= e($key === 'niveau' ? level_label($value) : $value) ?></strong></div><?php endforeach; ?><?php if (!$editing): ?><div><span>N° inscription</span><strong>Généré automatiquement</strong></div><?php endif; ?></div>
<form method="post"><?php if ($editing): ?><input type="hidden" name="id_etudiant" value="<?= $studentId ?>"><?php endif; ?><?php foreach ($data as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?><a class="btn btn-outline-light" href="<?= $editing ? 'inscription.php?id=' . $studentId : 'inscription.php' ?>">← Modifier</a><button name="action" value="submit" class="btn btn-success float-end"><?= $editing ? 'Confirmer les modifications' : 'Confirmer et enregistrer' ?></button></form>
<?php endif; ?>
</div></div>
<?php page_foot();*/
require_once __DIR__ . '/layout.php';
require_login();

$fields = ['nom', 'prenom', 'nom_ar', 'prenom_ar', 'date_naissance', 'lieu_naissance', 'lieu_naissance_ar', 'sexe', 'nationalite', 'etat_civil', 'cin_passeport', 'num_tel', 'adresse', 'email', 'date_inscrit', 'id_spec', 'niveau', 'annee_bac', 'specialite_bac'];
$specs = db()->query('SELECT * FROM specialite ORDER BY nom_spec')->fetchAll();
$data = array_fill_keys($fields, '');
$data['date_inscrit'] = date('Y-m-d');
$data['niveau'] = '1';
$troncCommunForced = false;
$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$editing = $studentId > 0;
$step = 1;
$error = '';
$registrationNumber = '';

$detectTroncCommun = static function (string $specialiteName): bool {
    return stripos($specialiteName, 'tronc commun') !== false;
};

if ($editing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = db()->prepare('SELECT * FROM etudiant WHERE id_etudiant = ?');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        http_response_code(404);
        $error = 'Étudiant introuvable.';
        $editing = false;
        $studentId = 0;
    } else {
        foreach ($fields as $field) $data[$field] = (string) $student[$field];
        $registrationNumber = $student['num_inscription'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = filter_input(INPUT_POST, 'id_etudiant', FILTER_VALIDATE_INT) ?: 0;
    $editing = $studentId > 0;
    foreach ($fields as $field) $data[$field] = trim((string) ($_POST[$field] ?? ''));
    $missing = array_filter($fields, fn(string $field): bool => $data[$field] === '');
    $action = $_POST['action'] ?? '';

    if ($action === 'verify') {
        $error = $missing ? 'Veuillez remplir tous les champs obligatoires.' : '';
        $step = $error ? 1 : 2;
    }
    if ($action === 'submit') {
        if ($missing) {
            $error = 'Veuillez remplir tous les champs obligatoires.';
        } else {
            try {
                if ($editing) {
                    $sql = 'UPDATE etudiant SET nom=:nom, prenom=:prenom, nom_ar=:nom_ar, prenom_ar=:prenom_ar, date_naissance=:date_naissance, lieu_naissance=:lieu_naissance, lieu_naissance_ar=:lieu_naissance_ar, sexe=:sexe, nationalite=:nationalite, etat_civil=:etat_civil, cin_passeport=:cin_passeport, num_tel=:num_tel, adresse=:adresse, email=:email, date_inscrit=:date_inscrit, id_spec=:id_spec, niveau=:niveau, annee_bac=:annee_bac, specialite_bac=:specialite_bac WHERE id_etudiant=:id_etudiant';
                    $data['id_etudiant'] = $studentId;
                    db()->prepare($sql)->execute($data);
                } else {
                    $pdo = db();
                    $pdo->beginTransaction();
                    
                    // --- NOUVELLE LOGIQUE DE GÉNÉRATION (INS***/Année) ---
                    $currentYear = date('Y');
                    // On cherche le numéro le plus élevé pour l'année en cours (extrait entre 'INS' et '/')
                    $query = "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING(num_inscription, 4), '/', 1) AS UNSIGNED)), 0) 
                              FROM etudiant 
                              WHERE num_inscription LIKE 'INS%/$currentYear'";
                    $lastNumber = (int) $pdo->query($query)->fetchColumn();
                    
                    // Formatage : INS + numéro formaté sur 3 chiffres + / + Année
                    $data['num_inscription'] = 'INS' . str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT) . '/' . $currentYear;
                    // -----------------------------------------------------

                    $sql = 'INSERT INTO etudiant (nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance, lieu_naissance_ar, sexe, nationalite, etat_civil, cin_passeport, num_tel, adresse, email, num_inscription, date_inscrit, id_spec, niveau, annee_bac, specialite_bac) VALUES (:nom, :prenom, :nom_ar, :prenom_ar, :date_naissance, :lieu_naissance, :lieu_naissance_ar, :sexe, :nationalite, :etat_civil, :cin_passeport, :num_tel, :adresse, :email, :num_inscription, :date_inscrit, :id_spec, :niveau, :annee_bac, :specialite_bac)';
                    $pdo->prepare($sql)->execute($data);
                    $pdo->commit();
                }
                header('Location: index.php?saved=1');
                exit;
            } catch (PDOException $exception) {
                if (db()->inTransaction()) db()->rollBack();
                $error = 'Enregistrement impossible : le CIN, l’e-mail ou le numéro d’inscription existe déjà.';
                $step = 1;
            }
        }
    }
}

if ($editing && $registrationNumber === '') {
    $stmt = db()->prepare('SELECT num_inscription FROM etudiant WHERE id_etudiant = ?');
    $stmt->execute([$studentId]);
    $registrationNumber = (string) $stmt->fetchColumn();
}

if (!empty($data['id_spec'])) {
    foreach ($specs as $spec) {
        if ((int) $data['id_spec'] === (int) $spec['id_spec'] && $detectTroncCommun((string) $spec['nom_spec'])) {
            $data['niveau'] = '1';
            $troncCommunForced = true;
            break;
        }
    }
}

page_head($editing ? 'Modifier un étudiant' : 'Inscription étudiant', 'register');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div><p class="eyebrow"><?= $editing ? 'Mise à jour du dossier' : 'Nouveau dossier' ?></p><h1 class="display-6 fw-bold"><?= $editing ? 'Modifier un étudiant' : 'Inscription étudiant' ?></h1></div>
  
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card"><div class="card-body p-4 p-lg-5">
<?php if ($step === 1): ?>
<form method="post" class="row g-3">
  <?php if ($editing): ?><input type="hidden" name="id_etudiant" value="<?= $studentId ?>"><?php endif; ?>
  <h2 class="h5 text-info border-bottom border-secondary pb-2">Identité — Français</h2>
  <div class="col-md-6"><label class="form-label">Nom *</label><input class="form-control" name="nom" value="<?= e($data['nom']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Prénom *</label><input class="form-control" name="prenom" value="<?= e($data['prenom']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Date de naissance *</label><input class="form-control" type="date" name="date_naissance" value="<?= e($data['date_naissance']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Lieu de naissance *</label><input class="form-control" name="lieu_naissance" value="<?= e($data['lieu_naissance']) ?>" required></div>
  <h2 class="h5 text-info border-bottom border-secondary pb-2 mt-5">الهوية — العربية</h2>
  <div class="col-md-6"><label class="form-label">اللقب *</label><input class="form-control text-end" dir="rtl" name="nom_ar" value="<?= e($data['nom_ar']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">الاسم *</label><input class="form-control text-end" dir="rtl" name="prenom_ar" value="<?= e($data['prenom_ar']) ?>" required></div>
  <div class="col-md-12"><label class="form-label">مكان الولادة *</label><input class="form-control text-end" dir="rtl" name="lieu_naissance_ar" value="<?= e($data['lieu_naissance_ar']) ?>" required></div>
  <h2 class="h5 text-info border-bottom border-secondary pb-2 mt-5">Coordonnées et scolarité</h2>
  <div class="col-md-4"><label class="form-label">Sexe *</label><select class="form-select" name="sexe" required><option value="">Choisir</option><?php foreach (['Femme', 'Homme'] as $value): ?><option value="<?= $value ?>" <?= $data['sexe'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Nationalité *</label><input class="form-control" name="nationalite" value="<?= e($data['nationalite']) ?>" required></div>
  <div class="col-md-4"><label class="form-label">État civil *</label><select class="form-select" name="etat_civil" required><option value="">Choisir</option><?php foreach (['Célibataire', 'Marié(e)', 'Divorcé(e)', 'Veuf/Veuve'] as $value): ?><option value="<?= $value ?>" <?= $data['etat_civil'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">CIN / Passeport *</label><input class="form-control" name="cin_passeport" value="<?= e($data['cin_passeport']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Téléphone *</label><input class="form-control" name="num_tel" value="<?= e($data['num_tel']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">E-mail *</label><input class="form-control" type="email" name="email" value="<?= e($data['email']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Adresse *</label><input class="form-control" name="adresse" value="<?= e($data['adresse']) ?>" required></div>
  <div class="col-md-4"><label class="form-label">N° inscription</label><input class="form-control" value="<?= e($editing ? $registrationNumber : 'Généré automatiquement à l’enregistrement') ?>" readonly></div>
  <div class="col-md-4"><label class="form-label">Date d’inscription *</label><input class="form-control" type="date" name="date_inscrit" value="<?= e($data['date_inscrit']) ?>" required></div>
  <div class="col-md-4" id="niveau-wrapper"><label class="form-label">Niveau *</label><select id="niveau-select" class="form-select" name="niveau" required><?php foreach (['1' => '1er', '2' => '2�me', '3' => '3�me'] as $value => $label): ?><option value="<?= $value ?>" <?= $data['niveau'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><input type="hidden" id="niveau-hidden" name="niveau" value="1" disabled></div>
  <div class="col-md-6"><label class="form-label">Sp�cialit� *</label><select class="form-select" name="id_spec" required><option value="">Choisir</option><?php foreach ($specs as $spec): ?><option value="<?= $spec['id_spec'] ?>" <?= $data['id_spec'] == $spec['id_spec'] ? 'selected' : '' ?>><?= e($spec['nom_spec']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Année du bac *</label><input class="form-control" type="number" min="1950" max="<?= date('Y') ?>" name="annee_bac" value="<?= e($data['annee_bac']) ?>" required></div>
  <div class="col-md-3"><label class="form-label">Spécialité bac *</label><select class="form-select" name="specialite_bac" required><option value="">Choisir</option><?php foreach (['Science', 'Math', 'Lettre', 'Sport'] as $value): ?><option value="<?= $value ?>" <?= $data['specialite_bac'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
  <div class="col-12 mt-4 d-flex justify-content-end"><button name="action" value="verify" class="btn btn-primary px-4">Suivant : vérifier →</button></div>
</form>
<?php else: ?>
<h2 class="h4 mb-4">Vérification du dossier</h2><p class="text-secondary">Vérifiez les informations avant l’enregistrement définitif.</p>
<div class="review-grid mb-4"><?php foreach ($data as $key => $value): ?><div><span><?= e(str_replace('_', ' ', ucfirst($key))) ?></span><strong><?= e($key === 'niveau' ? level_label($value) : $value) ?></strong></div><?php endforeach; ?><?php if (!$editing): ?><div><span>N° inscription</span><strong>Généré automatiquement</strong></div><?php endif; ?></div>
<form method="post"><?php if ($editing): ?><input type="hidden" name="id_etudiant" value="<?= $studentId ?>"><?php endif; ?><?php foreach ($data as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?><a class="btn btn-outline-light" href="<?= $editing ? 'inscription.php?id=' . $studentId : 'inscription.php' ?>">← Modifier</a><button name="action" value="submit" class="btn btn-success float-end"><?= $editing ? 'Confirmer les modifications' : 'Confirmer et enregistrer' ?></button></form>
<?php endif; ?>
</div></div>
<script>
(function () {
    const specSelect = document.querySelector('select[name="id_spec"]');
    const niveauSelect = document.getElementById('niveau-select');
    const niveauHidden = document.getElementById('niveau-hidden');

    if (!specSelect || !niveauSelect || !niveauHidden) {
        return;
    }

    const isTroncCommun = (label) => label.toLowerCase().includes('tronc commun');

    function applyLevelState() {
        const selectedText = specSelect.options[specSelect.selectedIndex]?.textContent?.trim() ?? '';
        const shouldForce = isTroncCommun(selectedText);

        niveauSelect.value = shouldForce ? '1' : niveauSelect.value || '1';
        niveauSelect.disabled = shouldForce;
        niveauHidden.disabled = !shouldForce;
        niveauHidden.value = shouldForce ? '1' : niveauSelect.value;
    }

    specSelect.addEventListener('change', applyLevelState);
    applyLevelState();
})();
</script><?php page_foot();



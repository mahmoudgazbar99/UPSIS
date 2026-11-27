<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/public_theme.php');
admissions_ensure();

$campaigns = admissions_open_campaigns();
$specs = [];
try {
    $specs = db()->query('SELECT id_spec, nom_spec FROM specialite WHERE active = 1 ORDER BY nom_spec')->fetchAll();
} catch (Throwable $ignored) {
    $specs = [];
}
$types = admissions_application_types();
$error = '';
$result = null;
$data = [
    'nom' => '', 'prenom' => '', 'nom_ar' => '', 'prenom_ar' => '',
    'date_naissance' => '', 'lieu_naissance' => '', 'sexe' => '',
    'nationalite' => 'Tunisienne', 'cin_passeport' => '', 'num_tel' => '',
    'email' => '', 'adresse' => '', 'ville' => '', 'pays' => 'Tunisie',
    'etablissement_origine' => '', 'annee_bac' => '', 'specialite_bac' => '',
    'moyenne_bac' => '', 'diplome_info' => '', 'etudes_anterieures' => '',
    'id_campaign' => '', 'id_spec' => '', 'application_type' => 'initial',
    'notes_candidat' => '', 'consent' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($data as $key => $_) {
        if ($key === 'consent') {
            continue;
        }
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    if (empty($_POST['consent'])) {
        $error = 'Vous devez accepter la notice de confidentialité.';
    } else {
        try {
            $files = [];
            foreach (admissions_doc_types(true) as $type) {
                $code = (string) $type['code'];
                if (!empty($_FILES['doc']['name'][$code])) {
                    $files[$code] = [
                        'name' => $_FILES['doc']['name'][$code],
                        'type' => $_FILES['doc']['type'][$code] ?? '',
                        'tmp_name' => $_FILES['doc']['tmp_name'][$code] ?? '',
                        'error' => $_FILES['doc']['error'][$code] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['doc']['size'][$code] ?? 0,
                    ];
                }
            }
            $result = admissions_submit_preregistration($data, $files);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$school = institution_name_inline();
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php public_theme_boot_script(); ?>
  <title>Préinscription | <?= e($school) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/theme-tokens.css?v=1" rel="stylesheet">
  <link href="assets/admissions-lightswind.css?v=3" rel="stylesheet">
</head>
<body class="adm-lw-page adm-lw">
  <?php public_theme_atmosphere_markup(); ?>
  <div class="adm-lw-wrap">
    <div class="adm-lw-toolbar"><?php public_theme_toggle_button(); ?></div>
    <p class="adm-lw-kicker">Admissions</p>
    <h1 class="adm-lw-brand"><?= e($school) ?></h1>
    <p class="adm-lw-lead">Candidature en ligne pour <strong>nouveaux étudiants</strong> (بلاغ d’inscription). Après envoi : référence, espace candidat, documents, test 150&nbsp;DT, puis inscription finale. Les étudiants déjà inscrits utilisent la <a href="connexion_etudiant.php">préinscription annuelle PA-SAE-01</a>.</p>

    <?php if ($result): ?>
      <div class="adm-lw-card">
        <span class="adm-lw-badge mb-2">Dossier reçu</span>
        <h2 class="h4 mb-3">Candidature enregistrée</h2>
        <p class="mb-1 text-secondary">Référence</p>
        <p class="adm-lw-ref mb-3"><?= e($result['application_number']) ?></p>
        <p class="mb-1 text-secondary">E-mail</p>
        <p class="fw-semibold"><?= e($data['email']) ?></p>
        <?php if ($result['temp_password'] !== ''): ?>
          <div class="alert alert-warning mt-3 mb-0">
            Mot de passe temporaire (à conserver) :
            <strong><?= e($result['temp_password']) ?></strong>
          </div>
        <?php endif; ?>
        <p class="text-secondary mt-3 mb-4">Prochaines étapes : connectez-vous, complétez les documents, puis suivez les convocations.</p>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-primary btn-lg" href="connexion_candidat.php">Accéder à l’espace candidat</a>
          <a class="btn btn-ghost btn-lg" href="accueil.php">Retour à l’accueil</a>
        </div>
      </div>
    <?php elseif (!$campaigns): ?>
      <div class="adm-lw-card">
        <h2 class="h5">Aucune campagne ouverte</h2>
        <p class="text-secondary mb-3">Les préinscriptions en ligne sont fermées pour le moment.</p>
        <a class="btn btn-ghost btn-lg" href="accueil.php">Accueil</a>
      </div>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="adm-lw-card" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="adm-lw-section">Campagne et filière</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="id_campaign">Campagne *</label>
            <select class="form-select" id="id_campaign" name="id_campaign" required>
              <option value="">Choisir…</option>
              <?php foreach ($campaigns as $c): ?>
                <option value="<?= (int) $c['id_campaign'] ?>" <?= (string) $data['id_campaign'] === (string) $c['id_campaign'] ? 'selected' : '' ?>>
                  <?= e($c['label'] . ' (' . $c['academic_year_label'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="application_type">Type de candidature</label>
            <select class="form-select" id="application_type" name="application_type">
              <?php foreach ($types as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $data['application_type'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="id_spec">Programme / spécialité *</label>
            <select class="form-select" id="id_spec" name="id_spec" required>
              <option value="">Choisir…</option>
              <?php foreach ($specs as $s): ?>
                <option value="<?= (int) $s['id_spec'] ?>" <?= (string) $data['id_spec'] === (string) $s['id_spec'] ? 'selected' : '' ?>><?= e($s['nom_spec']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="adm-lw-section">Identité</div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label fw-semibold" for="prenom">Prénom *</label><input class="form-control" id="prenom" name="prenom" required value="<?= e($data['prenom']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="nom">Nom *</label><input class="form-control" id="nom" name="nom" required value="<?= e($data['nom']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="prenom_ar">الاسم</label><input class="form-control" id="prenom_ar" name="prenom_ar" dir="rtl" value="<?= e($data['prenom_ar']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="nom_ar">اللقب</label><input class="form-control" id="nom_ar" name="nom_ar" dir="rtl" value="<?= e($data['nom_ar']) ?>"></div>
          <div class="col-md-4"><label class="form-label fw-semibold" for="date_naissance">Date de naissance *</label><input type="date" class="form-control" id="date_naissance" name="date_naissance" required value="<?= e($data['date_naissance']) ?>"></div>
          <div class="col-md-4"><label class="form-label fw-semibold" for="lieu_naissance">Lieu de naissance</label><input class="form-control" id="lieu_naissance" name="lieu_naissance" value="<?= e($data['lieu_naissance']) ?>"></div>
          <div class="col-md-4"><label class="form-label fw-semibold" for="sexe">Sexe</label>
            <select class="form-select" id="sexe" name="sexe">
              <option value="">—</option>
              <option value="M" <?= $data['sexe'] === 'M' ? 'selected' : '' ?>>Masculin</option>
              <option value="F" <?= $data['sexe'] === 'F' ? 'selected' : '' ?>>Féminin</option>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label fw-semibold" for="cin_passeport">CIN / passeport *</label><input class="form-control" id="cin_passeport" name="cin_passeport" required value="<?= e($data['cin_passeport']) ?>"></div>
          <div class="col-md-4"><label class="form-label fw-semibold" for="nationalite">Nationalité</label><input class="form-control" id="nationalite" name="nationalite" value="<?= e($data['nationalite']) ?>"></div>
          <div class="col-md-4"><label class="form-label fw-semibold" for="num_tel">Téléphone *</label><input class="form-control" id="num_tel" name="num_tel" required value="<?= e($data['num_tel']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="email">E-mail *</label><input type="email" class="form-control" id="email" name="email" required value="<?= e($data['email']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="adresse">Adresse</label><input class="form-control" id="adresse" name="adresse" value="<?= e($data['adresse']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="ville">Ville</label><input class="form-control" id="ville" name="ville" value="<?= e($data['ville']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="pays">Pays</label><input class="form-control" id="pays" name="pays" value="<?= e($data['pays']) ?>"></div>
        </div>

        <div class="adm-lw-section">Parcours scolaire</div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label fw-semibold" for="etablissement_origine">Établissement d’origine</label><input class="form-control" id="etablissement_origine" name="etablissement_origine" value="<?= e($data['etablissement_origine']) ?>"></div>
          <div class="col-md-3"><label class="form-label fw-semibold" for="annee_bac">Année du bac</label><input class="form-control" id="annee_bac" name="annee_bac" value="<?= e($data['annee_bac']) ?>"></div>
          <div class="col-md-3"><label class="form-label fw-semibold" for="moyenne_bac">Moyenne bac</label><input class="form-control" id="moyenne_bac" name="moyenne_bac" inputmode="decimal" value="<?= e($data['moyenne_bac']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="specialite_bac">Section bac</label><input class="form-control" id="specialite_bac" name="specialite_bac" value="<?= e($data['specialite_bac']) ?>"></div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="diplome_info">Diplôme</label><input class="form-control" id="diplome_info" name="diplome_info" value="<?= e($data['diplome_info']) ?>"></div>
          <div class="col-12"><label class="form-label fw-semibold" for="etudes_anterieures">Études universitaires antérieures</label><textarea class="form-control" id="etudes_anterieures" name="etudes_anterieures" rows="2"><?= e($data['etudes_anterieures']) ?></textarea></div>
          <div class="col-12"><label class="form-label fw-semibold" for="notes_candidat">Informations complémentaires</label><textarea class="form-control" id="notes_candidat" name="notes_candidat" rows="2"><?= e($data['notes_candidat']) ?></textarea></div>
        </div>

        <div class="adm-lw-section">Documents (PDF / JPG / PNG)</div>
        <div class="row g-3">
          <?php foreach (admissions_doc_types(true) as $type): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="doc_<?= e($type['code']) ?>">
                <?= e($type['label']) ?><?= (int) $type['required'] === 1 ? ' *' : '' ?>
              </label>
              <input class="form-control" type="file" id="doc_<?= e($type['code']) ?>" name="doc[<?= e($type['code']) ?>]" accept=".pdf,.jpg,.jpeg,.png,.webp">
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" value="1" id="consent" name="consent" required>
          <label class="form-check-label" for="consent">J’accepte le traitement de mes données personnelles pour la procédure d’admission de l’établissement.</label>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-4">
          <button type="submit" class="btn btn-primary btn-lg">Envoyer ma préinscription</button>
          <a class="btn btn-ghost btn-lg" href="accueil.php">Annuler</a>
          <a class="btn btn-link" href="connexion_candidat.php">Déjà candidat ?</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
  <?php public_theme_footer_script(); ?>
</body>
</html>

<?php
require_once app_path('layout.php');
require_permission('students.edit');

$fields = ['nom', 'prenom', 'nom_ar', 'prenom_ar', 'date_naissance', 'lieu_naissance', 'lieu_naissance_ar', 'sexe', 'nationalite', 'etat_civil', 'cin_passeport', 'num_tel', 'adresse', 'email', 'date_inscrit', 'id_spec', 'niveau', 'annee_bac', 'specialite_bac'];
$specs = db()->query('SELECT * FROM specialite WHERE active = 1 ORDER BY nom_spec')->fetchAll();
$data = array_fill_keys($fields, '');
$data['date_inscrit'] = date('Y-m-d');
$data['niveau'] = '1';
$identityType = 'cin';
$troncCommunForced = false;
$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$editing = $studentId > 0;
$step = 1;
$error = '';
$registrationNumber = '';
$embedded = ($_GET['embed'] ?? '') === '1';
$inscriptionDocs = inscription_documents();
$checkedDocs = [];
$inscriptionOpen = inscription_window_open();
$req = static function (string $field): string {
    return inscription_field_required($field) ? ' required' : '';
};
$star = static function (string $field): string {
    return inscription_field_required($field) ? ' *' : '';
};

$detectTroncCommun = static function (string $specialiteName): bool {
    return stripos($specialiteName, 'commun') !== false || stripos($specialiteName, 'tronc') !== false;
};

if (!$editing && $_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['draft'] ?? '') === '1' && !empty($_SESSION['inscription_draft']['data'])) {
    $draft = $_SESSION['inscription_draft'];
    foreach ($fields as $field) {
        if (isset($draft['data'][$field])) {
            $data[$field] = (string) $draft['data'][$field];
        }
    }
    $identityType = ($draft['identity_type'] ?? 'cin') === 'passport' ? 'passport' : 'cin';
    $studentId = (int) ($draft['student_id'] ?? 0);
    $editing = $studentId > 0;
    $registrationNumber = (string) ($draft['registration_number'] ?? '');
    $checkedDocs = is_array($draft['docs'] ?? null) ? array_map('strval', $draft['docs']) : [];
}

if ($editing && $_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['draft'] ?? '') !== '1') {
    $stmt = db()->prepare('SELECT * FROM etudiant WHERE id_etudiant = ?');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        http_response_code(404);
        $error = 'Étudiant introuvable.';
        $editing = false;
        $studentId = 0;
    } else {
        foreach ($fields as $field) {
            $data[$field] = (string) $student[$field];
        }
        $identityType = preg_match('/^\d{8}$/', $data['cin_passeport']) ? 'cin' : 'passport';
        $registrationNumber = $student['num_inscription'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $studentId = filter_input(INPUT_POST, 'id_etudiant', FILTER_VALIDATE_INT) ?: 0;
    $editing = $studentId > 0;

    $identityType = ($_POST['identity_type'] ?? 'cin') === 'passport' ? 'passport' : 'cin';
    $cinValue = trim((string) ($_POST['cin_value'] ?? ($identityType === 'cin' ? ($_POST['cin_passeport'] ?? '') : '')));
    $passportValue = trim((string) ($_POST['passport_value'] ?? ($identityType === 'passport' ? ($_POST['cin_passeport'] ?? '') : '')));
    $data['cin_passeport'] = $identityType === 'cin' ? $cinValue : $passportValue;
    foreach ($fields as $field) {
        if ($field === 'cin_passeport') {
            continue;
        }
        $data[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $checkedDocs = array_keys(inscription_docs_checked($_POST['docs'] ?? []));

    if (!$editing && !inscription_window_open()) {
        $error = 'Les nouvelles inscriptions sont fermées. ' . inscription_window_message();
    } elseif ($identityType === 'cin' && $cinValue !== '' && !identity_matches('cin', $cinValue)) {
        $error = identity_error('cin');
    } elseif ($identityType === 'passport' && $passportValue !== '' && !identity_matches('passeport', $passportValue)) {
        $error = identity_error('passeport');
    } elseif ($identityType === 'cin' && inscription_field_required('cin_passeport') && $cinValue === '') {
        $error = 'Saisissez le CIN.';
    } elseif ($identityType === 'passport' && inscription_field_required('cin_passeport') && $passportValue === '') {
        $error = 'Saisissez le passeport.';
    }

    foreach ($specs as $spec) {
        if ((string) $data['id_spec'] === (string) $spec['id_spec'] && $detectTroncCommun((string) $spec['nom_spec'])) {
            $data['niveau'] = '1';
            break;
        }
    }

    $missing = inscription_missing_required($data);
    $missingDocs = (!$editing) ? inscription_missing_documents($checkedDocs) : [];
    $action = $_POST['action'] ?? '';

    if ($action === 'verify' && $error === '') {
        if ($missing) {
            $labels = array_map(static fn(string $key): string => inscription_field_catalog()[$key]['label'] ?? $key, $missing);
            $error = 'Champs obligatoires manquants : ' . implode(', ', $labels) . '.';
        } elseif ($missingDocs) {
            $error = 'Documents obligatoires non cochés : ' . implode(', ', array_map(static fn(array $d): string => $d['label'], $missingDocs)) . '.';
        }
        $step = $error ? 1 : 2;
        if ($step === 2) {
            $_SESSION['inscription_draft'] = [
                'data' => $data,
                'identity_type' => $identityType,
                'student_id' => $studentId,
                'registration_number' => $registrationNumber,
                'docs' => $checkedDocs,
            ];
        }
    }

    if ($action === 'submit') {
        if ($error !== '') {
            $step = 1;
        } elseif ($missing) {
            $labels = array_map(static fn(string $key): string => inscription_field_catalog()[$key]['label'] ?? $key, $missing);
            $error = 'Champs obligatoires manquants : ' . implode(', ', $labels) . '.';
            $step = 1;
        } elseif ($missingDocs) {
            $error = 'Documents obligatoires non cochés : ' . implode(', ', array_map(static fn(array $d): string => $d['label'], $missingDocs)) . '.';
            $step = 1;
        } else {
            try {
                if ($editing) {
                    $sql = 'UPDATE etudiant SET nom=:nom, prenom=:prenom, nom_ar=:nom_ar, prenom_ar=:prenom_ar, date_naissance=:date_naissance, lieu_naissance=:lieu_naissance, lieu_naissance_ar=:lieu_naissance_ar, sexe=:sexe, nationalite=:nationalite, etat_civil=:etat_civil, cin_passeport=:cin_passeport, num_tel=:num_tel, adresse=:adresse, email=:email, date_inscrit=:date_inscrit, id_spec=:id_spec, niveau=:niveau, annee_bac=:annee_bac, specialite_bac=:specialite_bac WHERE id_etudiant=:id_etudiant';
                    $data['id_etudiant'] = $studentId;
                    db()->prepare($sql)->execute($data);
                } else {
                    $pdo = db();
                    $pdo->beginTransaction();

                    $currentYear = date('Y');
                    $query = "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING(num_inscription, 4), '/', 1) AS UNSIGNED)), 0) FROM etudiant WHERE num_inscription LIKE 'INS%/$currentYear'";
                    $lastNumber = (int) $pdo->query($query)->fetchColumn();
                    $data['num_inscription'] = 'INS' . str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT) . '/' . $currentYear;

                    $sql = 'INSERT INTO etudiant (nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance, lieu_naissance_ar, sexe, nationalite, etat_civil, cin_passeport, num_tel, adresse, email, num_inscription, date_inscrit, id_spec, niveau, annee_bac, specialite_bac) VALUES (:nom, :prenom, :nom_ar, :prenom_ar, :date_naissance, :lieu_naissance, :lieu_naissance_ar, :sexe, :nationalite, :etat_civil, :cin_passeport, :num_tel, :adresse, :email, :num_inscription, :date_inscrit, :id_spec, :niveau, :annee_bac, :specialite_bac)';
                    $pdo->prepare($sql)->execute($data);
                    $newStudentId = (int) $pdo->lastInsertId();
                    $selectedSpeciality = $pdo->prepare('SELECT nom_spec FROM specialite WHERE id_spec = ?');
                    $selectedSpeciality->execute([$data['id_spec']]);
                    if ($detectTroncCommun((string) $selectedSpeciality->fetchColumn())) {
                        assign_tronc_commun_groups($pdo, $newStudentId);
                    } else {
                        assign_student_to_academic_groups($pdo, $newStudentId, (string) $data['niveau']);
                    }
                    $pdo->commit();
                }

                unset($_SESSION['inscription_draft']);
                $studentName = trim($data['prenom'] . ' ' . $data['nom']);
                if ($editing) {
                    flash('success', 'Le dossier de ' . $studentName . ' a été mis à jour.');
                } else {
                    flash('success', $studentName . ' est inscrit sous le numéro ' . $data['num_inscription'] . '.');
                }
                if (($_GET['embed'] ?? '') === '1') {
                    echo '<script>window.top.location.href = "index.php?saved=1";</script>';
                } else {
                    if ($embedded) {
                        echo '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><title>Inscription enregistrée</title></head><body><script>window.top.location.replace("index.php?saved=1");</script></body></html>';
                        exit;
                    }
                    header('Location: index.php?saved=1');
                }
                exit;
            } catch (Throwable $exception) {
                $pdo = db();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $exception instanceof PDOException
                    ? 'Enregistrement impossible : le CIN, l’e-mail ou le numéro d’inscription existe déjà.'
                    : $exception->getMessage();
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
  <div>
    <p class="eyebrow"><?= $editing ? 'Mise à jour du dossier' : 'Nouveau dossier' ?></p>
    <h1 class="display-6 fw-bold"><?= $editing ? 'Modifier un étudiant' : 'Inscription étudiant' ?></h1>
  </div>
  
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (!$editing): ?>
  <div class="alert <?= $inscriptionOpen ? 'alert-info' : 'alert-warning' ?>"><?= e(inscription_window_message()) ?><?= $inscriptionOpen ? '' : ' Les nouvelles inscriptions sont bloquées.' ?></div>
<?php endif; ?>
<div class="card app-card"><div class="card-body p-4 p-lg-5">
<?php if ($step === 1): ?>
<?php if (!$editing && !$inscriptionOpen): ?>
  <p class="mb-0 text-secondary">Réouvrez la période dans <strong>Configuration → Règles d’inscription</strong>, ou modifiez un dossier existant.</p>
<?php else: ?>
<form method="post" class="row g-3">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <?php if ($editing): ?><input type="hidden" name="id_etudiant" value="<?= $studentId ?>"><?php endif; ?>

  <h2 class="h5 text-info border-bottom border-secondary pb-2">Identité — Français</h2>
  <div class="col-md-6"><label class="form-label">Nom<?= $star('nom') ?></label><input class="form-control" name="nom" value="<?= e($data['nom']) ?>"<?= $req('nom') ?>></div>
  <div class="col-md-6"><label class="form-label">Prénom<?= $star('prenom') ?></label><input class="form-control" name="prenom" value="<?= e($data['prenom']) ?>"<?= $req('prenom') ?>></div>
  <div class="col-md-6"><label class="form-label">Date de naissance<?= $star('date_naissance') ?></label><input class="form-control" type="date" name="date_naissance" value="<?= e($data['date_naissance']) ?>"<?= $req('date_naissance') ?>></div>
  <div class="col-md-6"><label class="form-label">Lieu de naissance<?= $star('lieu_naissance') ?></label><input class="form-control" name="lieu_naissance" value="<?= e($data['lieu_naissance']) ?>"<?= $req('lieu_naissance') ?>></div>

  <h2 class="h5 text-info border-bottom border-secondary pb-2 mt-5">الهوية — العربية</h2>
  <div class="col-md-6"><label class="form-label">اللقب<?= $star('nom_ar') ?></label><input class="form-control text-end" dir="rtl" name="nom_ar" value="<?= e($data['nom_ar']) ?>"<?= $req('nom_ar') ?>></div>
  <div class="col-md-6"><label class="form-label">الاسم<?= $star('prenom_ar') ?></label><input class="form-control text-end" dir="rtl" name="prenom_ar" value="<?= e($data['prenom_ar']) ?>"<?= $req('prenom_ar') ?>></div>
  <div class="col-md-12"><label class="form-label">مكان الولادة<?= $star('lieu_naissance_ar') ?></label><input class="form-control text-end" dir="rtl" name="lieu_naissance_ar" value="<?= e($data['lieu_naissance_ar']) ?>"<?= $req('lieu_naissance_ar') ?>></div>

  <h2 class="h5 text-info border-bottom border-secondary pb-2 mt-5">Coordonnées et scolarité</h2>
  <div class="col-md-4"><label class="form-label">Sexe<?= $star('sexe') ?></label><select class="form-select" name="sexe"<?= $req('sexe') ?>><option value="">Choisir</option><?php foreach (['Femme', 'Homme'] as $value): ?><option value="<?= $value ?>" <?= $data['sexe'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Nationalité<?= $star('nationalite') ?></label><input class="form-control" name="nationalite" value="<?= e($data['nationalite']) ?>"<?= $req('nationalite') ?>></div>
  <div class="col-md-4"><label class="form-label">État civil<?= $star('etat_civil') ?></label><select class="form-select" name="etat_civil"<?= $req('etat_civil') ?>><option value="">Choisir</option><?php foreach (['Célibataire', 'Marié(e)', 'Divorcé(e)', 'Veuf/Veuve'] as $value): ?><option value="<?= $value ?>" <?= $data['etat_civil'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>

  <div class="col-md-6">
    <label class="form-label">Type d’identifiant<?= $star('cin_passeport') ?></label>
    <div class="d-flex gap-3 mb-2">
      <label><input type="radio" name="identity_type" value="cin" <?= $identityType === 'cin' ? 'checked' : '' ?>> CIN</label>
      <label><input type="radio" name="identity_type" value="passport" <?= $identityType === 'passport' ? 'checked' : '' ?>> Passeport</label>
    </div>
    <input class="form-control mb-2" id="cin-value" name="cin_value" data-identity-required="<?= inscription_field_required('cin_passeport') ? '1' : '0' ?>" value="<?= $identityType === 'cin' ? e($data['cin_passeport']) : '' ?>" inputmode="numeric" minlength="<?= (int) identity_rule('cin')['min_length'] ?>" maxlength="<?= (int) identity_rule('cin')['max_length'] ?>" pattern="<?= e(identity_pattern('cin')) ?>" placeholder="<?= e(identity_error('cin')) ?>" <?= $identityType === 'cin' && inscription_field_required('cin_passeport') ? 'required' : '' ?> <?= $identityType === 'cin' ? '' : 'disabled' ?>>
    <input class="form-control" id="passport-value" name="passport_value" data-identity-required="<?= inscription_field_required('cin_passeport') ? '1' : '0' ?>" value="<?= $identityType === 'passport' ? e($data['cin_passeport']) : '' ?>" minlength="<?= (int) identity_rule('passeport')['min_length'] ?>" maxlength="<?= (int) identity_rule('passeport')['max_length'] ?>" pattern="<?= e(identity_pattern('passeport')) ?>" placeholder="<?= e(identity_error('passeport')) ?>" <?= $identityType === 'passport' && inscription_field_required('cin_passeport') ? 'required' : '' ?> <?= $identityType === 'passport' ? '' : 'disabled' ?>>
    <input type="hidden" name="cin_passeport" value="<?= e($data['cin_passeport']) ?>">
  </div>
  <div class="col-md-6"><label class="form-label">Téléphone<?= $star('num_tel') ?></label><input class="form-control" name="num_tel" value="<?= e($data['num_tel']) ?>"<?= $req('num_tel') ?>></div>
  <div class="col-md-6"><label class="form-label">E-mail<?= $star('email') ?></label><input class="form-control" type="email" name="email" value="<?= e($data['email']) ?>"<?= $req('email') ?>></div>
  <div class="col-md-6"><label class="form-label">Adresse<?= $star('adresse') ?></label><input class="form-control" name="adresse" value="<?= e($data['adresse']) ?>"<?= $req('adresse') ?>></div>

  <div class="col-md-4"><label class="form-label">N° inscription</label><input class="form-control" value="<?= e($editing ? $registrationNumber : 'Généré automatiquement à l’enregistrement') ?>" readonly></div>
  <div class="col-md-4"><label class="form-label">Date d’inscription<?= $star('date_inscrit') ?></label><input class="form-control" type="date" name="date_inscrit" value="<?= e($data['date_inscrit']) ?>"<?= $req('date_inscrit') ?>></div>

  <div class="col-md-4">
    <label class="form-label">Niveau<?= $star('niveau') ?></label>
    <select id="niveau-select" class="form-select" name="<?= $troncCommunForced ? '' : 'niveau' ?>"<?= $req('niveau') ?> <?= $troncCommunForced ? 'disabled aria-disabled="true"' : '' ?>>
      <?php foreach (study_levels() as $levelOption): $value = (string) $levelOption['code']; $label = (string) $levelOption['label']; ?>
        <option value="<?= $value ?>" <?= $data['niveau'] === $value ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" id="niveau-hidden" name="<?= $troncCommunForced ? 'niveau' : '' ?>" value="<?= $troncCommunForced ? '1' : '' ?>" <?= $troncCommunForced ? '' : 'disabled' ?>>
  </div>

  <div class="col-md-6"><label class="form-label">Spécialité<?= $star('id_spec') ?></label><select class="form-select" name="id_spec"<?= $req('id_spec') ?>><option value="">Choisir</option><?php foreach ($specs as $spec): ?><option value="<?= $spec['id_spec'] ?>" <?= $data['id_spec'] == $spec['id_spec'] ? 'selected' : '' ?>><?= e($spec['nom_spec']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Année du bac<?= $star('annee_bac') ?></label><input class="form-control" type="number" min="1950" max="<?= date('Y') ?>" name="annee_bac" value="<?= e($data['annee_bac']) ?>"<?= $req('annee_bac') ?>></div>
  <div class="col-md-3"><label class="form-label">Spécialité bac<?= $star('specialite_bac') ?></label><select class="form-select" name="specialite_bac"<?= $req('specialite_bac') ?>><option value="">Choisir</option><?php foreach (['Science', 'Math', 'Lettre', 'Sport'] as $value): ?><option value="<?= $value ?>" <?= $data['specialite_bac'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>

  <?php if (!$editing && $inscriptionDocs): ?>
  <div class="col-12 mt-3">
    <h2 class="h5 text-info border-bottom border-secondary pb-2">Documents fournis</h2>
    <p class="text-secondary small">Cochez les pièces remises. Les pièces marquées obligatoires sont exigées pour valider l’inscription.</p>
    <div class="row g-2">
      <?php foreach ($inscriptionDocs as $doc): ?>
        <div class="col-md-6">
          <label class="d-flex align-items-center gap-2 mb-0">
            <input type="checkbox" name="docs[]" value="<?= e($doc['code']) ?>" <?= in_array($doc['code'], $checkedDocs, true) ? 'checked' : '' ?>>
            <span><?= e($doc['label']) ?><?= !empty($doc['required']) ? ' *' : '' ?></span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-12 mt-4 d-flex justify-content-end">
    <button name="action" value="verify" class="btn btn-primary px-4">Suivant : vérifier →</button>
  </div>
</form>
<?php endif; ?>
<?php else: ?>
<h2 class="h4 mb-4">Vérification du dossier</h2>
<p class="text-secondary">Vérifiez les informations avant l’enregistrement définitif.</p>
<div class="review-grid mb-4"><?php foreach ($data as $key => $value): ?><div><span><?= e(str_replace('_', ' ', ucfirst($key))) ?></span><strong><?= e($key === 'niveau' ? level_label($value) : $value) ?></strong></div><?php endforeach; ?><?php if (!$editing): ?><div><span>N° inscription</span><strong>Généré automatiquement</strong></div><?php endif; ?></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?php if ($editing): ?><input type="hidden" name="id_etudiant" value="<?= $studentId ?>"><?php endif; ?><input type="hidden" name="identity_type" value="<?= e($identityType) ?>"><input type="hidden" name="cin_passeport" value="<?= e($data['cin_passeport']) ?>"><?php foreach ($data as $key => $value): ?><?php if ($key !== 'cin_passeport'): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endif; ?><?php endforeach; ?><?php foreach ($checkedDocs as $docCode): ?><input type="hidden" name="docs[]" value="<?= e($docCode) ?>"><?php endforeach; ?><a class="btn btn-outline-light" href="inscription.php?draft=1<?= $embedded ? '&amp;embed=1' : '' ?>">← Modifier</a><button name="action" value="submit" class="btn btn-success float-end"><?= $editing ? 'Confirmer les modifications' : 'Confirmer et enregistrer' ?></button></form>
<?php endif; ?>
</div></div>

<script>
(function () {
    const specSelect = document.querySelector('select[name="id_spec"]');
    const niveauSelect = document.getElementById('niveau-select');
    const niveauHidden = document.getElementById('niveau-hidden');
    const cinInput = document.getElementById('cin-value');
    const passportInput = document.getElementById('passport-value');
    const identityRadios = document.querySelectorAll('input[name="identity_type"]');
    const identityHidden = document.querySelector('input[type="hidden"][name="cin_passeport"]');

    if (!specSelect || !niveauSelect || !niveauHidden || !cinInput || !passportInput || !identityHidden) {
        return;
    }

    const isTroncCommun = (label) => label.toLowerCase().includes('tronc commun');

    function applyLevelState() {
        const selectedText = specSelect.selectedIndex >= 0
            ? specSelect.options[specSelect.selectedIndex].textContent.trim()
            : '';
        const shouldForce = isTroncCommun(selectedText);

        if (shouldForce) {
            niveauSelect.disabled = true;
            niveauSelect.removeAttribute('name');
            niveauSelect.value = '1';
            niveauHidden.disabled = false;
            niveauHidden.name = 'niveau';
            niveauHidden.value = '1';
            return;
        }

        niveauSelect.disabled = false;
        niveauSelect.setAttribute('name', 'niveau');
        niveauHidden.disabled = true;
        niveauHidden.removeAttribute('name');
    }

    specSelect.addEventListener('change', applyLevelState);
    applyLevelState();

    function applyIdentityState() {
        const type = document.querySelector('input[name="identity_type"]:checked')?.value || 'cin';
        const isCin = type === 'cin';
        const must = (cinInput.dataset.identityRequired || '1') === '1';
        cinInput.disabled = !isCin;
        cinInput.required = isCin && must;
        passportInput.disabled = isCin;
        passportInput.required = !isCin && must;
        identityHidden.value = isCin ? cinInput.value : passportInput.value;
    }

    cinInput.addEventListener('input', () => { identityHidden.value = cinInput.value; });
    passportInput.addEventListener('input', () => { identityHidden.value = passportInput.value; });
    identityRadios.forEach((radio) => radio.addEventListener('change', applyIdentityState));
    applyIdentityState();
})();
</script>
<?php page_foot();

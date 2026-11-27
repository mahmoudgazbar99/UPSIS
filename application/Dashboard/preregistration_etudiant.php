<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/student_theme.php');
require_once app_path('support/preregistration.php');
prereg_ensure();

$studentId = filter_var($_SESSION['id_etudiant'] ?? null, FILTER_VALIDATE_INT);
if ($studentId === false || $studentId <= 0 || !is_student()) {
    header('Location: connexion_etudiant.php');
    exit;
}

$pdo = db();
$profileStmt = $pdo->prepare(
    'SELECT e.*, g.nom_groupe
     FROM etudiant e
     LEFT JOIN groupe_etudiant ge ON ge.id_etudiant = e.id_etudiant
     LEFT JOIN groupe g ON g.id_groupe = ge.id_groupe
     WHERE e.id_etudiant = ?
     LIMIT 1'
);
$profileStmt->execute([$studentId]);
$profile = $profileStmt->fetch() ?: [];
if ($profile) {
    $profile['groupe'] = (string) ($profile['nom_groupe'] ?? '');
}

$campaigns = prereg_open_campaigns();
$campaignId = filter_input(INPUT_GET, 'campaign', FILTER_VALIDATE_INT)
    ?: (int) ($campaigns[0]['id_campaign'] ?? 0);
$campaign = $campaignId > 0 ? prereg_find_campaign($campaignId) : null;
$commitment = ($campaign && $studentId) ? prereg_student_commitment($studentId, $campaignId) : null;

$message = '';
$error = '';
$disclaimer = '';
try {
    $d = $pdo->prepare('SELECT setting_value FROM prereg_setting WHERE setting_key = ?');
    $d->execute(['disclaimer_fr']);
    $disclaimer = (string) ($d->fetchColumn() ?: '');
} catch (Throwable $ignored) {
}

if ($campaign && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $file = (!empty($_FILES['receipt']) && is_array($_FILES['receipt'])) ? $_FILES['receipt'] : null;
        prereg_submit_sa01($studentId, $profile, $_POST, $file);
        $commitment = prereg_student_commitment($studentId, $campaignId);
        $message = 'Engagement SA-01 enregistré. L’acompte 200 DT se règle hors ligne (caisse / virement) ; joignez la quittance si disponible.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$school = institution_name_inline();
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Préinscription annuelle | <?= e($school) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/espace-etudiant-lw.css?v=1" rel="stylesheet">
  <link href="assets/student-lightswind.css?v=1" rel="stylesheet">
  <style>
    .prereg-wrap { max-width: 720px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
    .prereg-card { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 16px; padding: 1.25rem 1.4rem; margin-bottom: 1rem; }
    .prereg-warn { border-left: 3px solid #f0a000; padding: .75rem 1rem; background: rgba(240,160,0,.08); border-radius: 8px; }
    .prereg-ok { border-left: 3px solid #2db87a; padding: .75rem 1rem; background: rgba(45,184,122,.08); border-radius: 8px; }
  </style>
</head>
<body class="student-ec-page">
  <div class="prereg-wrap">
    <p class="small text-secondary mb-1"><a href="dashboard.php">← Espace étudiant</a> · PA-SAE-01</p>
    <h1 class="h3 mb-2">Préinscription annuelle</h1>
    <p class="text-secondary">Engagement individuel (SA-01) + acompte <?= e((string) ($campaign['advance_amount'] ?? '200')) ?> DT. Distinct de l’inscription finale de septembre.</p>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$campaigns): ?>
      <div class="prereg-card">Aucune campagne de préinscription ouverte pour le moment.</div>
    <?php else: ?>
      <?php if (count($campaigns) > 1): ?>
        <div class="prereg-card">
          <label class="form-label" for="camp">Campagne</label>
          <select id="camp" class="form-select" onchange="location.href='preregistration_etudiant.php?campaign='+this.value">
            <?php foreach ($campaigns as $c): ?>
              <option value="<?= (int) $c['id_campaign'] ?>" <?= (int) $c['id_campaign'] === $campaignId ? 'selected' : '' ?>>
                <?= e($c['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="prereg-warn mb-3"><?= e($disclaimer) ?></div>

      <?php if ($commitment): ?>
        <div class="prereg-ok mb-3">
          Engagement soumis le <?= e(substr((string) ($commitment['submitted_at'] ?? ''), 0, 16)) ?>.
          Acompte : <?= (int) $commitment['advance_paid'] === 1
            ? 'vérifié le ' . e((string) $commitment['advance_paid_at']) . ' (quittance ' . e((string) $commitment['advance_receipt_no']) . ')'
            : 'en attente de vérification finance / dépôt caisse' ?>.
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="prereg-card">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id_campaign" value="<?= (int) $campaignId ?>">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Nom</label>
            <input class="form-control" value="<?= e((string) ($profile['nom'] ?? '')) ?>" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label">Prénom</label>
            <input class="form-control" value="<?= e((string) ($profile['prenom'] ?? '')) ?>" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label">N° inscription</label>
            <input class="form-control" value="<?= e((string) ($profile['num_inscription'] ?? '')) ?>" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label">Niveau actuel</label>
            <input class="form-control" name="current_level" value="<?= e((string) ($commitment['current_level'] ?? $profile['niveau'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Groupe</label>
            <input class="form-control" name="current_group" value="<?= e((string) ($commitment['current_group'] ?? $profile['groupe'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Niveau visé (année prochaine)</label>
            <input class="form-control" name="intended_level" required value="<?= e((string) ($commitment['intended_level'] ?? '')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Quittance acompte <?= e((string) ($campaign['advance_amount'] ?? '200')) ?> DT (optionnel si paiement caisse)</label>
            <input class="form-control" type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp">
            <div class="form-text">Hors application : paiement caisse / virement. L’administration valide le numéro de quittance (SA-01 §4).</div>
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="2"><?= e((string) ($commitment['notes'] ?? '')) ?></textarea>
          </div>
          <div class="col-12 form-check">
            <input class="form-check-input" type="checkbox" name="accept_commitment" value="1" id="accept" required <?= $commitment ? 'checked' : '' ?>>
            <label class="form-check-label" for="accept">
              J’exprime mon intention de poursuivre mes études, je m’engage à verser l’acompte de
              <?= e((string) ($campaign['advance_amount'] ?? '200')) ?> DT et à finaliser la réinscription dans les délais.
              Je comprends que la préinscription n’est pas une inscription finale.
            </label>
          </div>
          <div class="col-12">
            <button class="btn btn-primary" type="submit"><?= $commitment ? 'Mettre à jour l’engagement' : 'Soumettre SA-01' ?></button>
          </div>
        </div>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>

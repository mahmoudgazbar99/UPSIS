<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/stage_service.php');
require_once app_path('support/student_theme.php');

$studentSpace = ($_GET['espace'] ?? '') === 'etudiant';
if ($studentSpace) {
    if (!is_student()) {
        header('Location: connexion_etudiant.php');
        exit;
    }
} else {
    require_once app_path('layout.php');
    require_permission('stage.view');
}

stage_service_ensure();
$studentId = $studentSpace ? (int) ($_SESSION['id_etudiant'] ?? 0) : 0;
$error = '';
$message = '';

if ($studentSpace && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $campaignId = (int) ($_POST['id_campagne'] ?? 0);
        $campaign = db()->prepare(
            'SELECT c.id_campagne FROM stage_campagne c
             JOIN stage_type t ON t.id_type = c.id_type
             WHERE c.id_campagne = ? AND c.statut = ? AND t.circuit = ?'
        );
        $campaign->execute([$campaignId, 'ouverte', 'etudiant']);
        if (!$campaign->fetch()) {
            throw new InvalidArgumentException('Cette campagne de stage d’été n’est pas ouverte.');
        }
        $storedName = '';
        if ((int) (($_FILES['justificatif']['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_NO_FILE) {
            $storedName = stage_service_store_pdf((array) $_FILES['justificatif'])['stored'];
        }
        db()->prepare(
            'INSERT INTO stage_demande (id_campagne, id_etudiant, organisme_nom, ville, domaine, contact, date_debut, date_fin, missions, stored_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $campaignId,
            $studentId,
            mb_substr(trim((string) ($_POST['organisme_nom'] ?? '')), 0, 180),
            mb_substr(trim((string) ($_POST['ville'] ?? '')), 0, 80),
            mb_substr(trim((string) ($_POST['domaine'] ?? '')), 0, 160),
            mb_substr(trim((string) ($_POST['contact'] ?? '')), 0, 180),
            (string) ($_POST['date_debut'] ?? ''),
            (string) ($_POST['date_fin'] ?? ''),
            mb_substr(trim((string) ($_POST['missions'] ?? '')), 0, 1000),
            $storedName,
        ]);
        $message = 'Votre demande est envoyée.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('stage_demande: ' . $exception->getMessage());
        $error = 'La demande n’a pas pu être enregistrée.';
    }
}

$open = db()->query(
    "SELECT c.id_campagne, c.titre, c.annee_label, c.date_debut, c.date_fin
     FROM stage_campagne c
     JOIN stage_type t ON t.id_type = c.id_type
     WHERE c.statut = 'ouverte' AND t.circuit = 'etudiant'
     ORDER BY c.date_debut"
)->fetchAll();
if ($studentId > 0 && (int) ($_GET['piece'] ?? 0) > 0) {
    $piece = db()->prepare('SELECT stored_name FROM stage_demande WHERE id_demande = ? AND id_etudiant = ?');
    $piece->execute([(int) $_GET['piece'], $studentId]);
    $storedName = (string) $piece->fetchColumn();
    if ($storedName === '') {
        http_response_code(404);
        exit('Document introuvable.');
    }
    $path = stage_service_model_path($storedName);
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="justificatif-stage.pdf"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

$mine = [];
if ($studentId > 0) {
    $query = db()->prepare('SELECT * FROM stage_demande WHERE id_etudiant = ? ORDER BY created_at DESC');
    $query->execute([$studentId]);
    $mine = $query->fetchAll();
}

if (!$studentSpace) {
    header('Location: stage_bureau.php?section=ete' . (app_is_embedded() ? '&embed=1' : ''));
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php student_theme_head(); ?>
  <title>Stage d’été</title>
  <link href="assets/theme-tokens.css?v=1" rel="stylesheet">
  <link href="assets/motion.css?v=3" rel="stylesheet">
  <link href="assets/student-space.css?v=17" rel="stylesheet">
  <link href="assets/student-portal-shell.css?v=10" rel="stylesheet">
  <link href="assets/student-theme.css?v=15" rel="stylesheet">
  <link href="assets/student-lightswind.css?v=1" rel="stylesheet">
  <link href="assets/lw-theme-toggle.css?v=1" rel="stylesheet">
  <link href="assets/success-lottie.css?v=3" rel="stylesheet">
</head>
<body class="student-space">
<div class="portal-stage">
<div class="portal-phone">
<header class="student-space-bar">
  <img src="<?= e(institution_logo_src()) ?>" alt="Logo de l'école">
  <div>
    <p><?= e(institution_name_inline()) ?></p>
    <strong>Stage d’été</strong>
  </div>
  <div class="student-space-bar-actions">
    <a href="dashboard.php">Retour</a>
  </div>
</header>
<main class="student-space-main">
  <section class="student-space-sheet is-entering">
  <h1>Demande de stage d’été</h1>
  <?php if ($message !== ''): ?><div class="student-space-alert is-success"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="student-space-alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($open): ?>
  <form method="post" enctype="multipart/form-data" class="student-space-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label for="id_campagne">Campagne ouverte</label>
    <select id="id_campagne" name="id_campagne" required><?php foreach ($open as $row): ?><option value="<?= (int) $row['id_campagne'] ?>"><?= e(($row['titre'] !== '' ? $row['titre'] : 'Campagne') . ' · ' . $row['annee_label']) ?></option><?php endforeach; ?></select>
    <label for="organisme_nom">Établissement d’accueil</label>
    <input id="organisme_nom" name="organisme_nom" required maxlength="180">
    <label for="ville">Ville</label>
    <input id="ville" name="ville" maxlength="80">
    <label for="domaine">Domaine</label>
    <input id="domaine" name="domaine" maxlength="160">
    <label for="contact">Contact de l’organisme</label>
    <input id="contact" name="contact" maxlength="180">
    <label for="date_debut">Début</label>
    <input id="date_debut" name="date_debut" type="date" required>
    <label for="date_fin">Fin</label>
    <input id="date_fin" name="date_fin" type="date" required>
    <label for="missions">Missions prévues</label>
    <textarea id="missions" name="missions" rows="4" maxlength="1000"></textarea>
    <label for="justificatif">Lettre d’acceptation (PDF)</label>
    <input id="justificatif" name="justificatif" type="file" accept="application/pdf,.pdf">
    <button type="submit">Envoyer la demande</button>
  </form>
  <?php else: ?>
  <p class="student-space-lead">Aucune campagne de stage d’été n’est ouverte.</p>
  <?php endif; ?>
  <?php if ($mine): ?>
  <h2>Mes demandes</h2>
  <ul class="student-contact-list"><?php foreach ($mine as $row): ?><li class="student-contact-card"><div><strong><?= e((string) $row['organisme_nom']) ?></strong><span><?= e((string) $row['statut']) ?></span><?php if ((string) $row['stored_name'] !== ''): ?><a href="stage_demande.php?espace=etudiant&amp;piece=<?= (int) $row['id_demande'] ?>">Justificatif</a><?php endif; ?></div></li><?php endforeach; ?></ul>
  <?php endif; ?>
  </section>
</main>
</div>
</div>
<script src="assets/motion.js?v=3" defer></script>
<script type="module" src="assets/success-lottie.js?v=6"></script>
</body>
</html>

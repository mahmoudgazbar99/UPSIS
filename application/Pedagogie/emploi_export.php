<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_once app_path('support/emploi_smart.php');
require_admin();

emploi_smart_ensure();
$versionId = max(0, (int) ($_GET['id'] ?? 0));
$format = (string) ($_GET['format'] ?? 'csv');
$version = $versionId > 0 ? emploi_smart_get_version($versionId) : null;
if (!$version) {
    flash('danger', 'Version introuvable.');
    header('Location: emploi_generateur.php');
    exit;
}

$view = (string) ($_GET['view'] ?? 'all');
$filters = [];
if ($view === 'group' && (int) ($_GET['id_groupe'] ?? 0) > 0) {
    $filters['id_groupe'] = (int) $_GET['id_groupe'];
}
if ($view === 'teacher' && (int) ($_GET['id_enseignant'] ?? 0) > 0) {
    $filters['id_enseignant'] = (int) $_GET['id_enseignant'];
}
if ($view === 'resource' && (int) ($_GET['id_ressource'] ?? 0) > 0) {
    $filters['id_ressource'] = (int) $_GET['id_ressource'];
}

$sessions = emploi_smart_list_sessions($versionId, $filters);
$config = (array) $version['config'];
$days = (array) ($config['working_days'] ?? ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']);

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="emploi_version_' . $versionId . '.csv"');
    echo "\xEF\xBB\xBF";
    echo emploi_smart_export_csv($versionId);
    exit;
}

$institution = function_exists('institution_name_fr') ? institution_name_fr() : 'Établissement';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Emploi du temps — <?= htmlspecialchars((string) $version['label'], ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: Georgia, "Times New Roman", serif; color: #111; margin: 16px; background: #fff; }
    .header { text-align: center; margin-bottom: 12px; }
    .header h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
    .meta { color: #555; margin-bottom: 8px; font-size: .95rem; }
    .no-print { margin-bottom: 12px; }
    @media print { .no-print { display: none; } body { margin: 0; } }
  </style>
</head>
<body>
  <p class="no-print"><button type="button" onclick="window.print()">Imprimer / PDF</button></p>
  <div class="header">
    <h1><?= htmlspecialchars((string) $institution, ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="meta"><?= htmlspecialchars((string) $version['label'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $version['semestre'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $version['status'], ENT_QUOTES, 'UTF-8') ?></div>
  </div>
  <?= emploi_smart_render_timetable_html($sessions, [
      'days' => $days,
      'config' => $config,
      'editable' => false,
      'theme' => 'light',
  ]) ?>
</body>
</html>

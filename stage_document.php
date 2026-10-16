<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_permission('stage.view');
access_ensure();
$id = (int) ($_GET['id'] ?? 0);
$query = db()->prepare(
    'SELECT d.titre, d.mime, d.contenu, d.id_placement, p.id_stage_superviseur, p.created_by
     FROM stage_document d
     LEFT JOIN stage_placement p ON p.id_placement = d.id_placement
     WHERE d.id_document = ?'
);
$query->execute([$id]);
$row = $query->fetch();
if (!$row || ($row['id_placement'] && !stage_placement_visible($row))) {
    http_response_code(404);
    exit('Document introuvable.');
}
header('Content-Type: ' . $row['mime']);
header('Content-Disposition: attachment; filename="' . rawurlencode((string) $row['titre']) . '"');
echo $row['contenu'];

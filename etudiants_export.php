<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_permission('students.export');
$rows = db()->query(
    'SELECT e.num_inscription, e.nom, e.prenom, e.cin_passeport, e.email, e.niveau, e.statut, s.nom_spec
     FROM etudiant e LEFT JOIN specialite s ON s.id_spec = e.id_spec
     ORDER BY e.nom, e.prenom'
)->fetchAll();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="etudiants.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'wb');
fputcsv($out, ['inscription', 'nom', 'prenom', 'identifiant', 'email', 'niveau', 'statut', 'specialite'], ';');
foreach ($rows as $row) {
    fputcsv($out, [$row['num_inscription'], $row['nom'], $row['prenom'], $row['cin_passeport'], $row['email'], level_label((string) $row['niveau']), $row['statut'], $row['nom_spec']], ';');
}
fclose($out);

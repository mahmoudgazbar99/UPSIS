<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_permission('requests.manage');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$requests = [];
try {
    attestation_ensure_tables(db());
    $rows = db()->query(
        'SELECT d.id_demande, d.cin, d.motif, d.date_demande, e.nom, e.prenom
         FROM demande_attestation d
         JOIN etudiant e ON e.id_etudiant = d.id_etudiant
         WHERE d.statut = "en_attente"
         ORDER BY d.date_demande ASC, d.id_demande ASC'
    )->fetchAll();
    foreach ($rows as $row) {
        $requests[] = [
            'id' => (int) $row['id_demande'],
            'name' => trim((string) $row['prenom'] . ' ' . (string) $row['nom']),
            'cin' => (string) $row['cin'],
            'motif' => (string) $row['motif'],
            'date' => date('d/m/Y H:i', strtotime((string) $row['date_demande'])),
        ];
    }
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['count' => 0, 'stamp' => '0', 'requests' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$count = count($requests);
$stamp = $count === 0 ? '0' : ($count . ':' . (string) $requests[$count - 1]['id']);
echo json_encode([
    'count' => $count,
    'stamp' => $stamp,
    'requests' => $requests,
], JSON_UNESCAPED_UNICODE);

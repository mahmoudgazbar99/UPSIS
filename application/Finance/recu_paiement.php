<?php
declare(strict_types=1);
require_once app_path('bootstrap.php');
require_once root_path('vendor/autoload.php');

if (!logged_in() || (!is_finance() && !is_admin())) {
    http_response_code(403);
    exit('Accès réservé au service finance.');
}
if (is_finance()) {
    require_finance();
}

$receiptTotal = institution_fee_amount();
$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($paymentId < 1) {
    http_response_code(400);
    exit('Paiement introuvable.');
}

$query = db()->prepare(
    'SELECT f.id_finance_paiement, f.mode_paiement, f.montant_paye, f.created_at,
            e.nom, e.prenom, e.cin_passeport, e.num_inscription,
            COALESCE(fs.total_paye, 0) AS total_paye,
            fu.username
     FROM finance f
     JOIN etudiant e ON e.id_etudiant = f.id_etudiant
     LEFT JOIN finance_solde fs ON fs.id_etudiant = f.id_etudiant
     LEFT JOIN finance_user fu ON fu.id_finance = f.id_finance_user
     WHERE f.id_finance_paiement = ?'
);
$query->execute([$paymentId]);
$payment = $query->fetch();
if (!$payment) {
    http_response_code(404);
    exit('Paiement introuvable.');
}

$paid = (float) $payment['total_paye'];
$remaining = max(0, $receiptTotal - $paid);
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn (float $value): string => institution_money($value);
$method = $payment['mode_paiement'] === 'virement' ? 'Virement' : 'Espèce';
$status = $remaining <= 0 ? 'Paiement terminé' : 'Paiement partiel';

$html = '<html><head><meta charset="UTF-8"><style>
  body { font-family: DejaVu Sans, sans-serif; color: #17233d; font-size: 13px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .muted { color: #5c6b83; }
  .box { border: 1px solid #cbd5e3; border-radius: 8px; padding: 16px; margin-top: 18px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  td { padding: 7px 0; border-bottom: 1px solid #e6edf5; }
  td:first-child { color: #5c6b83; width: 42%; }
  .total { font-size: 18px; font-weight: bold; }
</style></head><body>
  <p class="muted">' . $escape(institution_name_inline()) . '</p>
  <h1>Reçu de paiement</h1>
  <p class="muted">N° ' . $escape((string) $payment['id_finance_paiement']) . ' · ' . $escape(date('d/m/Y H:i', strtotime((string) $payment['created_at']))) . '</p>
  <div class="box">
    <table>
      <tr><td>Étudiant</td><td>' . $escape(trim($payment['nom'] . ' ' . $payment['prenom'])) . '</td></tr>
      <tr><td>CIN / passeport</td><td>' . $escape((string) $payment['cin_passeport']) . '</td></tr>
      <tr><td>Inscription</td><td>' . $escape((string) $payment['num_inscription']) . '</td></tr>
      <tr><td>Mode</td><td>' . $escape($method) . '</td></tr>
      <tr><td>Montant de ce versement</td><td class="total">' . $escape($money((float) $payment['montant_paye'])) . '</td></tr>
      <tr><td>Total payé</td><td>' . $escape($money($paid)) . '</td></tr>
      <tr><td>Reste à payer</td><td>' . $escape($money($remaining)) . '</td></tr>
      <tr><td>Statut</td><td>' . $escape($status) . '</td></tr>
      <tr><td>Enregistré par</td><td>' . $escape((string) ($payment['username'] ?: 'Service finance')) . '</td></tr>
    </table>
  </div>
</body></html>';

$dompdf = new \Dompdf\Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A5', 'portrait');
$dompdf->render();
$dompdf->stream('recu_' . $payment['id_finance_paiement'] . '.pdf', ['Attachment' => false]);
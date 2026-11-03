<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_finance();

$tuitionCap = institution_fee_amount();
$error = '';
$message = '';
$receiptPaymentId = 0;
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = (string) ($_GET['statut'] ?? 'tous');
if (!in_array($statusFilter, ['tous', 'paye', 'partiel', 'impaye'], true)) {
    $statusFilter = 'tous';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 5;
$editPaymentId = filter_input(INPUT_GET, 'edit_payment', FILTER_VALIDATE_INT) ?: 0;
$historyStudentId = filter_input(INPUT_GET, 'history', FILTER_VALIDATE_INT) ?: 0;
$view = (string) ($_GET['vue'] ?? '');
if (!in_array($view, ['debiteurs', 'historique', 'statut', 'recu', 'corriger'], true)) {
    $view = '';
}
$etat = (($_GET['etat'] ?? 'non') === 'termine') ? 'termine' : 'non';
$cinSearch = trim((string) ($_GET['cin'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((string) ($_POST['vue'] ?? '') === 'corriger') {
        $view = 'corriger';
        $cinSearch = trim((string) ($_POST['cin'] ?? ''));
    }
    $action = (string) ($_POST['action'] ?? 'create_payment');
    $studentId = filter_input(INPUT_POST, 'id_etudiant', FILTER_VALIDATE_INT) ?: 0;
    $method = (string) ($_POST['mode_paiement'] ?? '');
    $amount = str_replace(',', '.', trim((string) ($_POST['montant_paye'] ?? '')));
    $totalCorrection = str_replace(',', '.', trim((string) ($_POST['total_paye_corrige'] ?? '')));
    $editingPayment = $action === 'edit_payment';
    $removingPayment = $action === 'remove_line';
    $amountToValidate = $editingPayment ? $totalCorrection : $amount;
    if (!$studentId || ($editingPayment && ($amountToValidate === '' || !is_numeric($amountToValidate) || (float) $amountToValidate < 0 || (float) $amountToValidate > $tuitionCap)) || (!$editingPayment && !$removingPayment && (!in_array($method, ['virement', 'espece'], true) || $amountToValidate === '' || !is_numeric($amountToValidate) || (float) $amountToValidate <= 0))) {
        $error = $editingPayment
            ? 'Veuillez saisir un total payé corrigé entre 0 et ' . number_format($tuitionCap, 2, ',', ' ') . ' ' . institution_currency() . '.'
            : 'Veuillez sélectionner un mode de paiement et saisir un montant supérieur à 0.';
        $search = trim((string) ($_POST['search'] ?? ''));
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $studentLock = $pdo->prepare('SELECT id_etudiant FROM etudiant WHERE id_etudiant = ? FOR UPDATE');
            $studentLock->execute([$studentId]);
            if (!$studentLock->fetchColumn()) {
                throw new RuntimeException('Étudiant introuvable.');
            }
            $balanceQuery = $pdo->prepare('SELECT total_paye FROM finance_solde WHERE id_etudiant = ? FOR UPDATE');
            $balanceQuery->execute([$studentId]);
            $paid = $balanceQuery->fetchColumn();
            if ($paid === false) {
                $paid = 0;
                $pdo->prepare('INSERT INTO finance_solde (id_etudiant, total_paye) VALUES (?, 0)')->execute([$studentId]);
            }
            $paid = (float) $paid;
            if ($action === 'correct_line') {
                $paymentId = filter_input(INPUT_POST, 'id_finance_paiement', FILTER_VALIDATE_INT) ?: 0;
                $newAmount = (float) $amount;
                $line = $pdo->prepare('SELECT id_finance_paiement, montant_paye FROM finance WHERE id_finance_paiement = ? AND id_etudiant = ? FOR UPDATE');
                $line->execute([$paymentId, $studentId]);
                $currentLine = $line->fetch();
                if (!$currentLine) {
                    throw new RuntimeException('Paiement introuvable.');
                }
                $previousAmount = (float) $currentLine['montant_paye'];
                $newTotal = $paid - $previousAmount + $newAmount;
                if ($newAmount > $previousAmount + 0.00001 && $newTotal > $tuitionCap + 0.00001) {
                    throw new RuntimeException('Le montant corrigé dépasse le plafond de ' . number_format($tuitionCap, 2, ',', ' ') . ' ' . institution_currency() . '.');
                }
                if ($newTotal < 0) {
                    $newTotal = 0;
                }
                $pdo->prepare('UPDATE finance SET mode_paiement = ?, montant_paye = ?, updated_at = CURRENT_TIMESTAMP WHERE id_finance_paiement = ? AND id_etudiant = ?')
                    ->execute([$method, $newAmount, $paymentId, $studentId]);
                $pdo->prepare('UPDATE finance_solde SET total_paye = ? WHERE id_etudiant = ?')->execute([$newTotal, $studentId]);
                $message = 'Le versement de ' . number_format($previousAmount, 2, ',', ' ') . ' ' . institution_currency() . ' est maintenant ' . number_format($newAmount, 2, ',', ' ') . ' ' . institution_currency() . '. Le total payé a été recalculé.';
                $receiptPaymentId = 0;
            } elseif ($action === 'remove_line') {
                $paymentId = filter_input(INPUT_POST, 'id_finance_paiement', FILTER_VALIDATE_INT) ?: 0;
                $line = $pdo->prepare('SELECT montant_paye FROM finance WHERE id_finance_paiement = ? AND id_etudiant = ? FOR UPDATE');
                $line->execute([$paymentId, $studentId]);
                $removed = $line->fetchColumn();
                if ($removed === false) {
                    throw new RuntimeException('Paiement introuvable.');
                }
                $removedAmount = (float) $removed;
                $pdo->prepare('DELETE FROM finance WHERE id_finance_paiement = ? AND id_etudiant = ?')->execute([$paymentId, $studentId]);
                $newTotal = $paid >= $removedAmount - 0.00001 ? $paid - $removedAmount : $paid;
                $pdo->prepare('UPDATE finance_solde SET total_paye = ? WHERE id_etudiant = ?')->execute([max(0, $newTotal), $studentId]);
                $message = 'Le versement de ' . number_format($removedAmount, 2, ',', ' ') . ' ' . institution_currency() . ' a été retiré.';
                $receiptPaymentId = 0;
            } elseif ($action === 'edit_payment') {
                $paymentId = filter_input(INPUT_POST, 'id_finance_paiement', FILTER_VALIDATE_INT) ?: 0;
                $paymentQuery = $pdo->prepare(
                    'SELECT id_finance_paiement, montant_paye
                     FROM finance
                     WHERE id_etudiant = ?
                     ORDER BY id_finance_paiement DESC
                     FOR UPDATE'
                );
                $paymentQuery->execute([$studentId]);
                $payments = $paymentQuery->fetchAll();
                $selectedPaymentExists = false;
                foreach ($payments as $existingPayment) {
                    if ((int) $existingPayment['id_finance_paiement'] === $paymentId) {
                        $selectedPaymentExists = true;
                        break;
                    }
                }
                if (!$selectedPaymentExists) {
                    throw new RuntimeException('Paiement introuvable.');
                }
                $newTotal = (float) $totalCorrection;
                $remainingToAllocate = $newTotal;
                $updatePayment = $pdo->prepare('UPDATE finance SET montant_paye = ?, updated_at = CURRENT_TIMESTAMP WHERE id_finance_paiement = ? AND id_etudiant = ?');
                $deletePayment = $pdo->prepare('DELETE FROM finance WHERE id_finance_paiement = ? AND id_etudiant = ?');
                foreach ($payments as $existingPayment) {
                    $existingId = (int) $existingPayment['id_finance_paiement'];
                    $currentAmount = (float) $existingPayment['montant_paye'];
                    $correctedAmount = min($currentAmount, max(0.0, $remainingToAllocate));
                    $remainingToAllocate -= $correctedAmount;
                    if ($correctedAmount <= 0.00001) {
                        $deletePayment->execute([$existingId, $studentId]);
                    } elseif (abs($correctedAmount - $currentAmount) > 0.00001) {
                        $updatePayment->execute([$correctedAmount, $existingId, $studentId]);
                    }
                }
                if ($remainingToAllocate > 0.00001) {
                    throw new RuntimeException('Le total corrigé n’a pas pu être réparti sur les paiements existants.');
                }
                $methodUpdate = $pdo->prepare(
                    'UPDATE finance
                     SET mode_paiement = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id_finance_paiement = ? AND id_etudiant = ?'
                );
                $methodUpdate->execute([$method, $paymentId, $studentId]);
                $balanceUpdate = $pdo->prepare('UPDATE finance_solde SET total_paye = ? WHERE id_etudiant = ?');
                $balanceUpdate->execute([$newTotal, $studentId]);
                $message = 'Paiement corrigé et solde recalculé.';
            } else {
                $remaining = $tuitionCap - $paid;
                if ((float) $amount > $remaining) {
                    throw new RuntimeException('Le montant ne peut pas dépasser le reste à payer (' . number_format($remaining, 2, ',', ' ') . ' DT).');
                }
                $statement = $pdo->prepare('INSERT INTO finance (id_etudiant, mode_paiement, montant_paye, id_finance_user) VALUES (?, ?, ?, ?)');
                $statement->execute([$studentId, $method, $amount, (int) ($_SESSION['finance_id'] ?? 0)]);
                $receiptPaymentId = (int) $pdo->lastInsertId();
                $balanceUpdate = $pdo->prepare('UPDATE finance_solde SET total_paye = total_paye + ? WHERE id_etudiant = ? AND total_paye + ? <= ?');
                $balanceUpdate->execute([$amount, $studentId, $amount, $tuitionCap]);
            }
            if (!in_array($action, ['edit_payment', 'correct_line', 'remove_line'], true) && $balanceUpdate->rowCount() !== 1) {
                throw new RuntimeException('Le reste à payer a changé. Veuillez rechercher à nouveau cet étudiant.');
            }
            $pdo->commit();
            $payerQuery = $pdo->prepare('SELECT nom, prenom, cin_passeport FROM etudiant WHERE id_etudiant = ?');
            $payerQuery->execute([$studentId]);
            $payer = $payerQuery->fetch() ?: [];
            $payerLabel = trim((string) (($payer['nom'] ?? '') . ' ' . ($payer['prenom'] ?? ''))) . ' (' . (string) ($payer['cin_passeport'] ?? '') . ')';
            if ($action === 'edit_payment') {
                audit_record('paiement', 'corriger', $payerLabel . ' · total corrigé ' . number_format((float) $totalCorrection, 2, ',', ' ') . ' DT');
                $receiptPaymentId = 0;
            } elseif ($action === 'correct_line') {
                audit_record('paiement', 'corriger', $payerLabel . ' · versement corrigé ' . number_format((float) $amount, 2, ',', ' ') . ' DT · ' . ($method === 'virement' ? 'virement' : 'espèce'));
            } elseif ($action === 'remove_line') {
                audit_record('paiement', 'corriger', $payerLabel . ' · versement retiré');
            } else {
                audit_record('paiement', 'versement', $payerLabel . ' · ' . number_format((float) $amount, 2, ',', ' ') . ' DT · ' . ($method === 'virement' ? 'virement' : 'espèce'));
            }
            $search = trim((string) ($_POST['search'] ?? $search));
            if (!in_array($action, ['edit_payment', 'correct_line', 'remove_line'], true)) {
                $message = (float) $amount === $remaining ? 'Paiement enregistré. Paiement terminé.' : 'Versement enregistré. Le reste à payer a été recalculé.';
            }
        } catch (Throwable $exception) {
            $receiptPaymentId = 0;
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Enregistrement du paiement impossible.';
            $search = trim((string) ($_POST['search'] ?? ''));
        }
    }
}

$student = null;
$payment = null;
$studentPayments = [];
$statusSql = match ($statusFilter) {
    'paye' => 'WHERE COALESCE(fs.total_paye, 0) >= ' . $tuitionCap,
    'partiel' => 'WHERE COALESCE(fs.total_paye, 0) > 0 AND COALESCE(fs.total_paye, 0) < ' . $tuitionCap,
    'impaye' => 'WHERE COALESCE(fs.total_paye, 0) <= 0',
    default => '',
};
$totalStudents = (int) db()->query(
    'SELECT COUNT(*) FROM etudiant e LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant ' . $statusSql
)->fetchColumn();
$totalPages = max(1, (int) ceil($totalStudents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$studentsQuery = db()->prepare(
    'SELECT e.id_etudiant, e.cin_passeport, e.nom, e.prenom, e.num_tel,
            COALESCE(fs.total_paye, 0) AS total_paye,
            fp.id_finance_paiement AS latest_payment_id,
            (SELECT COUNT(*) FROM finance f3 WHERE f3.id_etudiant = e.id_etudiant) AS payment_count
     FROM etudiant e
     LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant
     LEFT JOIN finance fp ON fp.id_finance_paiement = (
         SELECT f2.id_finance_paiement FROM finance f2
         WHERE f2.id_etudiant = e.id_etudiant
         ORDER BY f2.created_at DESC, f2.id_finance_paiement DESC LIMIT 1
     )
     ' . $statusSql . '
     ORDER BY e.nom, e.prenom
     LIMIT :limit OFFSET :offset'
);
$studentsQuery->bindValue(':limit', $perPage, PDO::PARAM_INT);
$studentsQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
$studentsQuery->execute();
$allStudents = $studentsQuery->fetchAll();
$completedPaymentsQuery = db()->query(
    'SELECT e.id_etudiant, e.cin_passeport, e.nom, e.prenom, e.num_tel,
            fs.total_paye
     FROM etudiant e
     JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant
     WHERE fs.total_paye >= ' . $tuitionCap . '
     ORDER BY e.nom, e.prenom'
);
$completedPayments = $completedPaymentsQuery->fetchAll();
$incompletePaymentsQuery = db()->query(
    'SELECT e.id_etudiant, e.cin_passeport, e.nom, e.prenom, e.num_tel,
            COALESCE(fs.total_paye, 0) AS total_paye
     FROM etudiant e
     LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant
     WHERE COALESCE(fs.total_paye, 0) < ' . $tuitionCap . '
     ORDER BY e.nom, e.prenom'
);
$incompletePayments = $incompletePaymentsQuery->fetchAll();
if ($search !== '') {
    $studentQuery = db()->prepare('SELECT id_etudiant, cin_passeport, nom, prenom, num_tel FROM etudiant WHERE cin_passeport = ? OR nom LIKE ? OR prenom LIKE ? OR num_inscription = ? LIMIT 1');
    $studentQuery->execute([$search, '%' . $search . '%', '%' . $search . '%', $search]);
    $student = $studentQuery->fetch();
    if ($student) {
        $paymentQuery = db()->prepare('SELECT total_paye FROM finance_solde WHERE id_etudiant = ?');
        $paymentQuery->execute([$student['id_etudiant']]);
        $payment = $paymentQuery->fetch();
        $studentPayQuery = db()->prepare(
            'SELECT f.id_finance_paiement, f.mode_paiement, f.montant_paye, f.created_at
             FROM finance f
             WHERE f.id_etudiant = ?
             ORDER BY f.created_at DESC, f.id_finance_paiement DESC'
        );
        $studentPayQuery->execute([(int) $student['id_etudiant']]);
        $studentPayments = $studentPayQuery->fetchAll();
    }
}

$editPayment = null;
if ($editPaymentId) {
    $editQuery = db()->prepare(
        'SELECT f.id_finance_paiement, f.id_etudiant, f.mode_paiement, f.montant_paye,
                COALESCE(fs.total_paye, 0) AS total_paye,
                e.cin_passeport, e.nom, e.prenom
         FROM finance f
         JOIN etudiant e ON e.id_etudiant = f.id_etudiant
         LEFT JOIN finance_solde fs ON fs.id_etudiant = f.id_etudiant
         WHERE f.id_finance_paiement = ?'
    );
    $editQuery->execute([$editPaymentId]);
    $editPayment = $editQuery->fetch();
}

$historyStudent = null;
$paymentHistory = [];
if ($historyStudentId) {
    $historyStudentQuery = db()->prepare('SELECT id_etudiant, cin_passeport, nom, prenom FROM etudiant WHERE id_etudiant = ?');
    $historyStudentQuery->execute([$historyStudentId]);
    $historyStudent = $historyStudentQuery->fetch();
    if ($historyStudent) {
        $historyQuery = db()->prepare(
            'SELECT f.id_finance_paiement, f.mode_paiement, f.montant_paye,
                    f.created_at, f.updated_at, fu.username
             FROM finance f
             LEFT JOIN finance_user fu ON fu.id_finance = f.id_finance_user
             WHERE f.id_etudiant = ?
             ORDER BY f.created_at DESC, f.id_finance_paiement DESC'
        );
        $historyQuery->execute([$historyStudentId]);
        $paymentHistory = $historyQuery->fetchAll();
    }
}

$money = static fn (float $value): string => institution_money($value);
$cinStudent = null;
$cinPayments = [];
if (in_array($view, ['historique', 'recu', 'corriger'], true) && $cinSearch !== '') {
    $cinQuery = db()->prepare(
        'SELECT id_etudiant, cin_passeport, nom, prenom, num_inscription
         FROM etudiant
         WHERE cin_passeport = ? OR num_inscription = ? OR cin_passeport LIKE ? OR num_inscription LIKE ?
         ORDER BY CASE WHEN cin_passeport = ? OR num_inscription = ? THEN 0 ELSE 1 END
         LIMIT 1'
    );
    $cinQuery->execute([$cinSearch, $cinSearch, '%' . $cinSearch . '%', '%' . $cinSearch . '%', $cinSearch, $cinSearch]);
    $cinStudent = $cinQuery->fetch() ?: null;
    if ($cinStudent) {
        $cinPayQuery = db()->prepare(
            'SELECT f.id_finance_paiement, f.mode_paiement, f.montant_paye, f.created_at, f.updated_at, fu.username
             FROM finance f
             LEFT JOIN finance_user fu ON fu.id_finance = f.id_finance_user
             WHERE f.id_etudiant = ?
             ORDER BY f.created_at DESC, f.id_finance_paiement DESC'
        );
        $cinPayQuery->execute([(int) $cinStudent['id_etudiant']]);
        $cinPayments = $cinPayQuery->fetchAll();
    }
}
$hubCounts = ['debiteurs' => count($incompletePayments), 'termines' => count($completedPayments)];

page_head('Service finance', 'finance');
?>
<div class="finance-page">
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
  <div>
    <p class="eyebrow">Service finance</p>
    <h1 class="display-6 fw-bold mb-1">Suivi des paiements</h1>
    <p class="text-secondary mb-0">Choisissez une carte pour ouvrir le tableau correspondant.</p>
  </div>
  <?php if ($view !== '' || $search !== '' || $editPayment || $historyStudent): ?><a class="btn btn-outline-light" href="finance.php">Retour aux cartes</a><?php endif; ?>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?><?php if ($receiptPaymentId): ?> <a href="recu_paiement.php?id=<?= $receiptPaymentId ?>">Télécharger le reçu</a><?php endif; ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($view === '' && $search === '' && !$editPayment && !$historyStudent): ?>
<div class="finance-hub">
  <a class="finance-hub-card" href="finance.php?vue=debiteurs">
    <span>01</span>
    <strong>Étudiants encore débiteurs</strong>
    <small><?= (int) $hubCounts['debiteurs'] ?> étudiant<?= $hubCounts['debiteurs'] > 1 ? 's' : '' ?> avec un reste à payer</small>
  </a>
  <a class="finance-hub-card" href="finance.php?vue=historique">
    <span>02</span>
    <strong>Historique des paiements</strong>
    <small>Recherche par CIN, puis le détail des versements</small>
  </a>
  <a class="finance-hub-card" href="finance.php?vue=statut&amp;etat=non">
    <span>03</span>
    <strong>Terminé / non terminé</strong>
    <small><?= (int) $hubCounts['termines'] ?> terminé<?= $hubCounts['termines'] > 1 ? 's' : '' ?> · <?= (int) $hubCounts['debiteurs'] ?> non terminé<?= $hubCounts['debiteurs'] > 1 ? 's' : '' ?></small>
  </a>
  <a class="finance-hub-card" href="finance.php?vue=recu">
    <span>04</span>
    <strong>Reçu</strong>
    <small>Recherche par CIN, puis téléchargement du reçu</small>
  </a>
  <a class="finance-hub-card" href="finance.php?vue=corriger">
    <span>05</span>
    <strong>Corriger une saisie</strong>
    <small>Modifier un montant ou un mode de paiement enregistré par erreur</small>
  </a>
</div>
<?php endif; ?>
<?php if ($search !== '' && !$student): ?><div class="alert alert-warning">Aucun étudiant trouvé avec ce CIN / passeport.</div><?php elseif ($search !== '' && $student): ?>
<div class="card app-card mb-4 border-primary"><div class="card-body p-4"><h2 class="h4 mb-3">Paiement de <?= e($student['nom'] . ' ' . $student['prenom']) ?></h2>
<form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id_etudiant" value="<?= (int) $student['id_etudiant'] ?>"><input type="hidden" name="search" value="<?= e($search) ?>">
<div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0"><thead><tr><th>CIN / passeport</th><th>Nom</th><th>Prénom</th><th>Téléphone</th><th>Mode de paiement</th><th>Versement</th><th>Total payé</th><th>Reste à payer</th></tr></thead><tbody><tr><td><?= e($student['cin_passeport']) ?></td><td><?= e($student['nom']) ?></td><td><?= e($student['prenom']) ?></td><td><?= e($student['num_tel']) ?></td><td><div class="d-flex gap-3"><label><input type="radio" name="mode_paiement" value="virement" required> Virement</label><label><input type="radio" name="mode_paiement" value="espece"> Par espèce</label></div></td><td><input class="form-control" name="montant_paye" type="number" min="0.01" max="<?= $tuitionCap ?>" step="0.01" required></td><td><?= e(number_format((float) ($payment['total_paye'] ?? 0), 2, ',', ' ')) ?> DT</td><td><?php $remaining = max(0, $tuitionCap - (float) ($payment['total_paye'] ?? 0)); ?><?php if ($remaining <= 0): ?><span class="text-success fw-bold">Paiement terminé</span><?php else: ?><?= e(number_format($remaining, 2, ',', ' ')) ?> DT<?php endif; ?></td></tr></tbody></table></div><div class="col-12 mt-3"><button class="btn btn-primary" type="submit" <?= $remaining <= 0 ? 'disabled' : '' ?>>Enregistrer le paiement</button></div></form>
<?php if ($studentPayments): ?>
<h3 class="h5 mt-4">Versements déjà saisis</h3>
<p class="text-secondary">Un montant saisi par erreur se corrige ici. Par exemple, 2500 devient 2000. Seul ce versement change.</p>
<div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0"><thead><tr><th>Date</th><th>Mode</th><th>Montant saisi</th><th></th></tr></thead><tbody>
<?php foreach ($studentPayments as $savedPayment): ?>
<tr>
  <td><?= e(date('d/m/Y H:i', strtotime((string) $savedPayment['created_at']))) ?></td>
  <td colspan="3">
    <form method="post" class="d-flex flex-wrap gap-2 align-items-center mb-0">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="correct_line">
      <input type="hidden" name="id_etudiant" value="<?= (int) $student['id_etudiant'] ?>">
      <input type="hidden" name="id_finance_paiement" value="<?= (int) $savedPayment['id_finance_paiement'] ?>">
      <input type="hidden" name="search" value="<?= e($search) ?>">
      <select class="form-select" name="mode_paiement" aria-label="Mode de paiement" style="max-width:11rem">
        <option value="virement" <?= $savedPayment['mode_paiement'] === 'virement' ? 'selected' : '' ?>>Virement</option>
        <option value="espece" <?= $savedPayment['mode_paiement'] === 'espece' ? 'selected' : '' ?>>Par espèce</option>
      </select>
      <input class="form-control" name="montant_paye" type="number" min="0.01" max="<?= e((string) $tuitionCap) ?>" step="0.01" value="<?= e((string) $savedPayment['montant_paye']) ?>" aria-label="Montant du versement" style="max-width:10rem" required>
      <button class="btn btn-warning" type="submit">Corriger ce versement</button>
      <button class="btn btn-outline-light" type="submit" name="action" value="remove_line" onclick="return confirm('Retirer ce versement ?');">Retirer</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div></div>
<?php endif; ?>
<?php if ($view === 'corriger' && $editPayment): ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h4">Corriger la saisie de <?= e($editPayment['nom'] . ' ' . $editPayment['prenom']) ?></h2>
  <p class="text-secondary">CIN / passeport : <?= e($editPayment['cin_passeport']) ?>. Le montant actuel de ce versement est <?= e($money((float) $editPayment['montant_paye'])) ?>. Les autres versements ne sont pas modifiés.</p>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="correct_line">
    <input type="hidden" name="vue" value="corriger">
    <input type="hidden" name="cin" value="<?= e($editPayment['cin_passeport']) ?>">
    <input type="hidden" name="id_finance_paiement" value="<?= (int) $editPayment['id_finance_paiement'] ?>">
    <input type="hidden" name="id_etudiant" value="<?= (int) $editPayment['id_etudiant'] ?>">
    <div class="col-md-4"><label class="form-label" for="mode_corrige">Mode de paiement</label><select class="form-select" id="mode_corrige" name="mode_paiement" required><option value="virement" <?= $editPayment['mode_paiement'] === 'virement' ? 'selected' : '' ?>>Virement</option><option value="espece" <?= $editPayment['mode_paiement'] === 'espece' ? 'selected' : '' ?>>Par espèce</option></select></div>
    <div class="col-md-4"><label class="form-label" for="montant_corrige">Montant corrigé</label><input class="form-control" id="montant_corrige" name="montant_paye" type="number" min="0.01" max="<?= e((string) $tuitionCap) ?>" step="0.01" value="<?= e((string) $editPayment['montant_paye']) ?>" required></div>
    <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-warning" type="submit">Enregistrer la correction</button><a class="btn btn-outline-light" href="finance.php?vue=corriger&amp;cin=<?= urlencode((string) $editPayment['cin_passeport']) ?>">Annuler</a></div>
  </form>
</div></div>
<?php endif; ?>
<?php if ($view === 'historique' || $view === 'recu' || $view === 'corriger'): ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <h2 class="h4 mb-3"><?= $view === 'recu' ? 'Reçu' : ($view === 'corriger' ? 'Corriger une saisie' : 'Historique des paiements') ?></h2>
  <form method="get" class="row g-3 align-items-end">
    <input type="hidden" name="vue" value="<?= e($view) ?>">
    <div class="col-md-9"><label class="form-label" for="cin">CIN, passeport ou numéro d’inscription</label><input class="form-control" id="cin" name="cin" value="<?= e($cinSearch) ?>" required placeholder="CIN, passeport ou inscription"></div>
    <div class="col-md-3"><button class="btn btn-primary w-100">Rechercher</button></div>
  </form>
</div></div>
<?php if ($cinSearch !== '' && !$cinStudent): ?><div class="alert alert-warning">Aucun étudiant ne correspond à ce CIN ou à ce numéro d’inscription.</div><?php elseif ($cinStudent && !$cinPayments): ?><div class="alert alert-warning">Aucun paiement pour <?= e($cinStudent['nom'] . ' ' . $cinStudent['prenom']) ?>.</div><?php elseif ($cinStudent): ?>
<div class="card app-card mb-4"><div class="card-body p-4 pb-0"><h2 class="h5 mb-1"><?= e($cinStudent['nom'] . ' ' . $cinStudent['prenom']) ?></h2><p class="text-secondary"><?= e($cinStudent['num_inscription'] . ' · ' . $cinStudent['cin_passeport']) ?></p></div>
<div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0 finance-sheet">
  <thead><tr><th>Date</th><th>Mode</th><th>Montant</th><th>Enregistré par</th><th>Statut</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($cinPayments as $cinPayment): ?>
    <tr>
      <td class="finance-money"><?= e(date('d/m/Y H:i', strtotime((string) $cinPayment['created_at']))) ?></td>
      <td><?php if ($view === 'corriger'): ?>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-center mb-0">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="correct_line">
          <input type="hidden" name="vue" value="corriger">
          <input type="hidden" name="cin" value="<?= e((string) $cinStudent['cin_passeport']) ?>">
          <input type="hidden" name="id_etudiant" value="<?= (int) $cinStudent['id_etudiant'] ?>">
          <input type="hidden" name="id_finance_paiement" value="<?= (int) $cinPayment['id_finance_paiement'] ?>">
          <select class="form-select form-select-sm" name="mode_paiement" aria-label="Mode de paiement" style="max-width:9rem">
            <option value="virement" <?= $cinPayment['mode_paiement'] === 'virement' ? 'selected' : '' ?>>Virement</option>
            <option value="espece" <?= $cinPayment['mode_paiement'] === 'espece' ? 'selected' : '' ?>>Espèce</option>
          </select>
          <input class="form-control form-control-sm" name="montant_paye" type="number" min="0.01" max="<?= e((string) $tuitionCap) ?>" step="0.01" value="<?= e((string) $cinPayment['montant_paye']) ?>" aria-label="Montant du versement" style="max-width:8rem" required>
          <button class="btn btn-sm btn-warning" type="submit">Enregistrer</button>
          <button class="btn btn-sm btn-outline-light" type="submit" name="action" value="remove_line" onclick="return confirm('Retirer ce versement ?');">Retirer</button>
        </form>
      <?php else: ?><?= $cinPayment['mode_paiement'] === 'virement' ? 'Virement' : 'Espèce' ?><?php endif; ?></td>
      <td class="finance-money"><?php if ($view !== 'corriger'): ?><?= e($money((float) $cinPayment['montant_paye'])) ?><?php endif; ?></td>
      <td><?= e($cinPayment['username'] ?? '—') ?></td>
      <td class="finance-status"><?php if ($cinPayment['updated_at']): ?><span class="text-warning fw-bold">Modifié</span><?php else: ?><span class="text-success fw-bold">Original</span><?php endif; ?></td>
      <td><div class="finance-actions"><a class="btn btn-sm btn-outline-success" href="recu_paiement.php?id=<?= (int) $cinPayment['id_finance_paiement'] ?>" target="_blank" rel="noopener">Reçu</a></div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
<?php endif; ?>
<?php if ($historyStudent && $view !== 'historique'): ?><div class="card app-card mb-4"><div class="card-body p-4"><h2 class="h4">Historique des paiements</h2><p class="text-secondary mb-3"><?= e($historyStudent['nom'] . ' ' . $historyStudent['prenom']) ?> — <?= e($historyStudent['cin_passeport']) ?></p><div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0 finance-sheet"><thead><tr><th>Date du paiement</th><th>Mode</th><th>Montant</th><th>Enregistré par</th><th>Statut</th><th>Actions</th></tr></thead><tbody><?php foreach ($paymentHistory as $history): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($history['created_at']))) ?></td><td><?= $history['mode_paiement'] === 'virement' ? 'Virement' : 'Espèce' ?></td><td class="finance-money"><?= e($money((float) $history['montant_paye'])) ?></td><td><?= e($history['username'] ?? '—') ?></td><td><?php if ($history['updated_at']): ?><span class="text-warning fw-bold">Modifié</span><?php else: ?><span class="text-success fw-bold">Original</span><?php endif; ?></td><td><div class="finance-actions finance-actions-single"><a class="btn btn-sm btn-outline-success" href="recu_paiement.php?id=<?= (int) $history['id_finance_paiement'] ?>" target="_blank" rel="noopener">Reçu</a></div></td></tr><?php endforeach; ?><?php if (!$paymentHistory): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun versement.</td></tr><?php endif; ?></tbody></table></div></div></div><?php elseif ($historyStudentId && !$historyStudent): ?><div class="alert alert-warning">Aucun historique de paiement trouvé.</div><?php endif; ?>
<?php if ($editPayment && $view !== 'corriger'): ?><div class="card app-card mb-4"><div class="card-body p-4"><h2 class="h4">Corriger le total payé de <?= e($editPayment['nom'] . ' ' . $editPayment['prenom']) ?></h2><p class="text-secondary">CIN / passeport : <?= e($editPayment['cin_passeport']) ?>. Saisissez le total réellement payé, par exemple <strong>8800</strong> si le reste dû est 1000 DT.</p><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="edit_payment"><input type="hidden" name="id_finance_paiement" value="<?= (int) $editPayment['id_finance_paiement'] ?>"><input type="hidden" name="id_etudiant" value="<?= (int) $editPayment['id_etudiant'] ?>"><input type="hidden" name="search" value="<?= e($search) ?>"><div class="col-md-4"><label class="form-label">Mode de paiement du dernier versement</label><select class="form-select" name="mode_paiement" required><option value="virement" <?= $editPayment['mode_paiement'] === 'virement' ? 'selected' : '' ?>>Virement</option><option value="espece" <?= $editPayment['mode_paiement'] === 'espece' ? 'selected' : '' ?>>Par espèce</option></select></div><div class="col-md-4"><label class="form-label">Total payé corrigé</label><input class="form-control" name="total_paye_corrige" type="number" min="0" max="<?= $tuitionCap ?>" step="0.01" value="<?= e((string) $editPayment['total_paye']) ?>" required></div><div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-warning" type="submit">Enregistrer la correction</button><a class="btn btn-outline-light" href="finance.php">Annuler</a></div></form></div></div><?php endif; ?>
<?php if ($view === 'statut'): ?>
<div class="finance-filters mb-4">
  <a class="btn <?= $etat === 'termine' ? 'btn-primary' : 'btn-outline-light' ?>" href="finance.php?vue=statut&amp;etat=termine">Terminés</a>
  <a class="btn <?= $etat === 'non' ? 'btn-primary' : 'btn-outline-light' ?>" href="finance.php?vue=statut&amp;etat=non">Non terminés</a>
</div>
<?php endif; ?>
<?php if ($view === 'statut' && $etat === 'termine'): ?>
<div class="card app-card mb-4"><div class="card-body pb-0"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h4 mb-1">Paiements terminés</h2><p class="text-secondary mb-0">Étudiants ayant payé la totalité des <?= e(institution_money($tuitionCap)) ?>.</p></div><span class="badge text-bg-success"><?= count($completedPayments) ?> terminé<?= count($completedPayments) > 1 ? 's' : '' ?></span></div></div><div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0 finance-sheet">
  <thead><tr><th>Étudiant</th><th>Total payé</th><th>Statut</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($completedPayments as $completedStudent): ?>
    <tr>
      <td class="finance-student"><strong><?= e($completedStudent['nom'] . ' ' . $completedStudent['prenom']) ?></strong><small><?= e($completedStudent['cin_passeport']) ?> · <?= e($completedStudent['num_tel']) ?></small></td>
      <td class="finance-money"><?= e($money((float) $completedStudent['total_paye'])) ?></td>
      <td class="finance-status"><span class="text-success fw-bold">Paiement terminé</span></td>
      <td><div class="finance-actions finance-actions-single"><a class="btn btn-sm btn-outline-info" href="finance.php?search=<?= urlencode($completedStudent['cin_passeport']) ?>">Ouvrir</a></div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$completedPayments): ?><tr><td colspan="4" class="text-center text-secondary py-4">Aucun paiement terminé.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
<?php if ($view === 'debiteurs' || ($view === 'statut' && $etat === 'non')): ?>
<div class="card app-card mb-4 <?= $view === 'debiteurs' ? '' : 'border-danger' ?>"><div class="card-body pb-0"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h4 mb-1 <?= $view === 'debiteurs' ? '' : 'text-danger' ?>"><?= $view === 'debiteurs' ? 'Étudiants encore débiteurs' : 'Paiements non terminés' ?></h2><p class="text-secondary mb-0"><?= $view === 'debiteurs' ? 'Étudiants dont il reste un montant à encaisser.' : 'Étudiants dont le paiement est inférieur à ' . e(institution_money($tuitionCap)) . '.' ?></p></div><span class="badge <?= $view === 'debiteurs' ? 'text-bg-warning' : 'text-bg-danger' ?>"><?= count($incompletePayments) ?></span></div></div><div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0 finance-sheet">
  <thead><tr><th>Étudiant</th><th>Total payé</th><th>Reste à payer</th><th>Statut</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($incompletePayments as $incompleteStudent): ?>
    <tr>
      <td class="finance-student"><strong><?= e($incompleteStudent['nom'] . ' ' . $incompleteStudent['prenom']) ?></strong><small><?= e($incompleteStudent['cin_passeport']) ?> · <?= e($incompleteStudent['num_tel']) ?></small></td>
      <td class="finance-money"><?= e($money((float) $incompleteStudent['total_paye'])) ?></td>
      <td class="finance-money"><?= e($money($tuitionCap - (float) $incompleteStudent['total_paye'])) ?></td>
      <td class="finance-status"><span class="text-danger fw-bold">Paiement non terminé</span></td>
      <td><div class="finance-actions finance-actions-single"><a class="btn btn-sm btn-outline-danger" href="finance.php?search=<?= urlencode($incompleteStudent['cin_passeport']) ?>">Ouvrir</a></div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$incompletePayments): ?><tr><td colspan="5" class="text-center text-secondary py-4">Tous les paiements sont terminés.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
</div>
<?php page_foot();

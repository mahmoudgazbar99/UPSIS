<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';
$current = institution_fee_amount();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $confirm = (string) ($_POST['confirmation'] ?? '');
    $amount = (float) str_replace(',', '.', (string) ($_POST['total_amount'] ?? ''));
    $currency = strtoupper(trim((string) ($_POST['currency_code'] ?? 'DT')));
    try {
        if ($confirm !== 'PUBLIER') {
            throw new InvalidArgumentException('Saisissez PUBLIER pour confirmer. Les archives déjà closes ne changent pas.');
        }
        if ($amount <= 0 || $amount > 99999999) {
            throw new InvalidArgumentException('Saisissez un montant de frais valide.');
        }
        if (!preg_match('/^[A-Z]{2,8}$/', $currency)) {
            throw new InvalidArgumentException('La devise s’écrit en 2 à 8 lettres, par exemple DT.');
        }
        academic_publish_replace('fee_plan', null, [
            'label' => 'Frais de scolarité',
            'total_amount' => number_format($amount, 2, '.', ''),
            'currency_code' => $currency,
        ]);
        institution_setting_put('currency_code', $currency);
        academic_policy_note('frais', 'Frais publiés ' . number_format($amount, 2, '.', '') . ' ' . $currency);
        $message = 'Le nouveau barème est publié pour les soldes en cours. Les archives gardent le montant qui était en vigueur.';
        $current = institution_fee_amount();
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'La publication a échoué.';
    }
}
page_head('Frais de scolarité', 'finance');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Frais de scolarité</h1>
  <p class="text-secondary mb-0">Montant utilisé par la finance, les reçus et le rollover. Portée : établissement.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card"><div class="card-body p-4">
  <p>Barème publié : <strong><?= e(institution_money($current)) ?></strong></p>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="col-md-4"><label class="form-label" for="total_amount">Nouveau total</label><input class="form-control" id="total_amount" name="total_amount" inputmode="decimal" value="<?= e(number_format($current, 2, '.', '')) ?>" required></div>
    <div class="col-md-3"><label class="form-label" for="currency_code">Devise</label><input class="form-control" id="currency_code" name="currency_code" value="<?= e(institution_currency()) ?>" maxlength="8" required></div>
    <div class="col-md-5"><label class="form-label" for="confirmation">Confirmation</label><input class="form-control" id="confirmation" name="confirmation" placeholder="PUBLIER" required></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Publier le barème</button></div>
  </form>
  <p class="form-text mb-0 mt-3">Un paiement déjà enregistré n’est pas modifié. Le reste à payer des étudiants actifs est recalculé avec ce montant.</p>
</div></div>
<?php page_foot();

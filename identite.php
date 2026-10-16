<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $code = (string) ($_POST['code'] ?? '');
    $min = (int) ($_POST['min_length'] ?? 0);
    $max = (int) ($_POST['max_length'] ?? 0);
    $kind = (string) ($_POST['charset_kind'] ?? '');
    try {
        if (!in_array($code, ['cin', 'passeport'], true) || !in_array($kind, ['digits', 'alnum'], true)) {
            throw new InvalidArgumentException('Type de pièce inconnu.');
        }
        if ($min < 1 || $max > 40 || $min > $max) {
            throw new InvalidArgumentException('La longueur doit être comprise entre 1 et 40.');
        }
        db()->prepare('UPDATE identity_document_type SET min_length = ?, max_length = ?, charset_kind = ? WHERE code = ?')
            ->execute([$min, $max, $kind, $code]);
        academic_policy_note('identite', $code . ' : ' . $min . '-' . $max . ' ' . $kind);
        $message = 'La règle est enregistrée. Les identifiants déjà enregistrés ne sont pas modifiés.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
}
academic_policy_ensure();
$rules = db()->query('SELECT * FROM identity_document_type ORDER BY id_type')->fetchAll();
page_head('Pièces d’identité', 'accounts');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Pièces d’identité</h1>
  <p class="text-secondary mb-0">Le CIN du personnel et le CIN ou le passeport des étudiants suivent ces longueurs.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-4">
  <?php foreach ($rules as $rule): ?>
    <div class="col-lg-6"><div class="card app-card h-100"><div class="card-body p-4">
      <h2 class="h5"><?= e($rule['label']) ?></h2>
      <p class="text-secondary">Règle actuelle : <?= e(identity_error((string) $rule['code'])) ?></p>
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="code" value="<?= e((string) $rule['code']) ?>">
        <div class="col-md-4"><label class="form-label">Minimum</label><input class="form-control" name="min_length" type="number" min="1" max="40" value="<?= (int) $rule['min_length'] ?>" required></div>
        <div class="col-md-4"><label class="form-label">Maximum</label><input class="form-control" name="max_length" type="number" min="1" max="40" value="<?= (int) $rule['max_length'] ?>" required></div>
        <div class="col-md-4"><label class="form-label">Caractères</label><select class="form-select" name="charset_kind"><option value="digits" <?= $rule['charset_kind'] === 'digits' ? 'selected' : '' ?>>Chiffres</option><option value="alnum" <?= $rule['charset_kind'] === 'alnum' ? 'selected' : '' ?>>Lettres et chiffres</option></select></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
      </form>
    </div></div></div>
  <?php endforeach; ?>
</div>
<?php page_foot();

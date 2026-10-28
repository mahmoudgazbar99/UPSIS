<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

audit_ensure_table(db());

$categories = [
    'tous' => 'Tout',
    'note' => 'Notes',
    'paiement' => 'Paiements',
    'presence' => 'Présences',
    'compte' => 'Comptes',
];
$category = (string) ($_GET['categorie'] ?? 'tous');
if (!isset($categories[$category])) {
    $category = 'tous';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$where = $category === 'tous' ? '' : ' WHERE category = :category';
$count = db()->prepare('SELECT COUNT(*) FROM audit_log' . $where);
if ($category !== 'tous') {
    $count->bindValue(':category', $category);
}
$count->execute();
$total = (int) $count->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$query = db()->prepare(
    'SELECT actor_role, actor_label, category, action_name, summary, created_at
     FROM audit_log' . $where . '
     ORDER BY created_at DESC, id_audit DESC
     LIMIT :limit OFFSET :offset'
);
if ($category !== 'tous') {
    $query->bindValue(':category', $category);
}
$query->bindValue(':limit', $perPage, PDO::PARAM_INT);
$query->bindValue(':offset', $offset, PDO::PARAM_INT);
$query->execute();
$entries = $query->fetchAll();

$roleLabels = [
    'admin' => 'Administrateur',
    'enseignant' => 'Enseignant',
    'finance' => 'Finance',
];

page_head('Journal d’audit', 'audit');
?>
<div class="mb-4">
  <p class="eyebrow">Administration</p>
  <h1 class="display-6 fw-bold mb-1">Journal d’audit</h1>
  <p class="text-secondary mb-0">Qui a modifié une note, un paiement, une présence ou un compte. Les mots de passe ne sont jamais enregistrés.</p>
</div>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($categories as $key => $label): ?>
    <a class="btn btn-sm <?= $category === $key ? 'btn-primary' : 'btn-outline-light' ?>" href="journal_audit.php?categorie=<?= e($key) ?><?= ($_GET['embed'] ?? '') === '1' ? '&embed=1' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<div class="card app-card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Date</th>
          <th>Qui</th>
          <th>Type</th>
          <th>Action</th>
          <th>Détail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <tr>
            <td><?= e(date('d/m/Y H:i', strtotime((string) $entry['created_at']))) ?></td>
            <td>
              <strong><?= e($entry['actor_label']) ?></strong>
              <small class="d-block text-secondary"><?= e($roleLabels[$entry['actor_role']] ?? $entry['actor_role']) ?></small>
            </td>
            <td><?= e($categories[$entry['category']] ?? $entry['category']) ?></td>
            <td><?= e($entry['action_name']) ?></td>
            <td><?= e($entry['summary']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?>
          <tr><td colspan="5" class="text-center text-secondary py-4">Aucune action enregistrée pour ce filtre.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($total > 0): ?>
  <div class="d-flex justify-content-between align-items-center mt-3">
    <span class="text-secondary"><?= $total ?> action<?= $total > 1 ? 's' : '' ?> — page <?= $page ?> / <?= $totalPages ?></span>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light <?= $page <= 1 ? 'disabled' : '' ?>" href="journal_audit.php?categorie=<?= e($category) ?>&page=<?= $page - 1 ?>">Précédent</a>
      <a class="btn btn-outline-light <?= $page >= $totalPages ? 'disabled' : '' ?>" href="journal_audit.php?categorie=<?= e($category) ?>&page=<?= $page + 1 ?>">Suivant</a>
    </div>
  </div>
<?php endif; ?>
<?php page_foot();
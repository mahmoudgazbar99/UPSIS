<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_admin();

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        $pdo = db();
        if ($action === 'type_default') {
            $type = (string) ($_POST['type_groupe'] ?? '');
            $capacity = (int) ($_POST['capacite'] ?? 0);
            if (!in_array($type, ['Cours', 'TD', 'TP'], true)) {
                throw new InvalidArgumentException('Choisissez Cours, TD ou TP.');
            }
            if ($capacity < 1 || $capacity > 500) {
                throw new InvalidArgumentException('La capacité doit être comprise entre 1 et 500.');
            }
            $stmt = $pdo->prepare('UPDATE groupe SET capacite = ? WHERE type_groupe = ?');
            $stmt->execute([$capacity, $type]);
            $updated = $stmt->rowCount();
            academic_policy_note('capacite_groupes', 'Capacité ' . $type . ' fixée à ' . $capacity);
            $message = 'Capacité « ' . $type . ' » appliquée à ' . $updated . ' groupe(s) : ' . $capacity . ' places.';
        } elseif ($action === 'group_one') {
            $groupId = (int) ($_POST['id_groupe'] ?? 0);
            $capacity = (int) ($_POST['capacite'] ?? 0);
            if ($groupId < 1) {
                throw new InvalidArgumentException('Groupe invalide.');
            }
            if ($capacity < 1 || $capacity > 500) {
                throw new InvalidArgumentException('La capacité doit être comprise entre 1 et 500.');
            }
            $exists = $pdo->prepare('SELECT id_groupe, type_groupe, semestre, numero FROM groupe WHERE id_groupe = ?');
            $exists->execute([$groupId]);
            $group = $exists->fetch();
            if (!$group) {
                throw new InvalidArgumentException('Groupe introuvable.');
            }
            $pdo->prepare('UPDATE groupe SET capacite = ? WHERE id_groupe = ?')->execute([$capacity, $groupId]);
            academic_policy_note(
                'capacite_groupes',
                'Capacité ' . $group['type_groupe'] . ' ' . $group['numero'] . ' (' . $group['semestre'] . ') = ' . $capacity
            );
            $message = 'Capacité mise à jour pour ' . $group['semestre'] . ' · ' . $group['type_groupe'] . ' ' . $group['numero'] . '.';
        } else {
            throw new InvalidArgumentException('Action non reconnue.');
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('capacite_groupes: ' . $exception->getMessage());
        $error = 'L’enregistrement a échoué.';
    }
}

$groups = db()->query(
    'SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero, g.capacite,
            parent.numero AS parent_numero,
            (SELECT COUNT(*) FROM groupe_etudiant members WHERE members.id_groupe = g.id_groupe) AS effectif
     FROM groupe g
     LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
     WHERE g.type_groupe IN ("Cours", "TD", "TP")
     ORDER BY FIELD(g.semestre, "S1", "S2", "S3", "S4", "S5", "S6"),
              FIELD(g.type_groupe, "Cours", "TD", "TP"),
              g.numero'
)->fetchAll();

$typeDefaults = ['Cours' => 50, 'TD' => 25, 'TP' => 25];
foreach ($groups as $row) {
    $type = (string) $row['type_groupe'];
    if (isset($typeDefaults[$type])) {
        $typeDefaults[$type] = (int) $row['capacite'];
    }
}

page_head('Capacité maximale des groupes', 'groups');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Capacité maximale</h1>
  <p class="text-secondary mb-0">Fixez le plafond des groupes Cours, TD et TP. Les nouvelles affectations ne peuvent pas dépasser cette limite.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card app-card mb-4">
  <div class="card-body p-4">
    <h2 class="h5 mb-3">Appliquer une capacité par type</h2>
    <p class="text-secondary small">Met à jour tous les groupes du type choisi.</p>
    <div class="row g-3">
      <?php foreach (['Cours' => 'Groupe Cours', 'TD' => 'Groupe TD', 'TP' => 'Groupe TP'] as $type => $label): ?>
      <div class="col-md-4">
        <form method="post" class="border rounded-4 p-3 h-100" style="border-color: var(--app-border) !important;">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="type_default">
          <input type="hidden" name="type_groupe" value="<?= e($type) ?>">
          <label class="form-label" for="cap_<?= e($type) ?>"><?= e($label) ?></label>
          <div class="input-group">
            <input class="form-control" id="cap_<?= e($type) ?>" name="capacite" type="number" min="1" max="500" value="<?= (int) $typeDefaults[$type] ?>" required>
            <button class="btn btn-primary" type="submit">Appliquer</button>
          </div>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card app-card">
  <div class="card-body p-4 pb-2">
    <h2 class="h5 mb-1">Capacité par groupe</h2>
    <p class="text-secondary small mb-3">Les lignes en alerte dépassent déjà le plafond ; aucun nouvel étudiant ne pourra y être ajouté.</p>
  </div>
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Semestre</th>
          <th>Type</th>
          <th>Groupe</th>
          <th>Effectif</th>
          <th>Capacité max.</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($groups as $group): ?>
        <?php
          $effectif = (int) $group['effectif'];
          $capacite = (int) $group['capacite'];
          $over = $effectif > $capacite;
          $label = $group['type_groupe'] === 'TD' && $group['parent_numero'] !== null
              ? 'Cours ' . (int) $group['parent_numero'] . ' · TD ' . (int) $group['numero']
              : $group['type_groupe'] . ' ' . (int) $group['numero'];
        ?>
        <tr class="<?= $over ? 'table-warning' : '' ?>">
          <td><?= e((string) $group['semestre']) ?></td>
          <td><?= e((string) $group['type_groupe']) ?></td>
          <td><strong><?= e($label) ?></strong><?php if ($over): ?><br><small class="text-warning">Dépassement : <?= $effectif ?> / <?= $capacite ?></small><?php endif; ?></td>
          <td><?= $effectif ?></td>
          <td colspan="2">
            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="group_one">
              <input type="hidden" name="id_groupe" value="<?= (int) $group['id_groupe'] ?>">
              <input class="form-control" style="max-width:7rem" name="capacite" type="number" min="1" max="500" value="<?= $capacite ?>" required>
              <button class="btn btn-outline-primary btn-sm" type="submit">Enregistrer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$groups): ?>
        <tr><td colspan="6" class="text-center text-secondary py-4">Aucun groupe disponible.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_foot();

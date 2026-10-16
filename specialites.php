<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if ((string) ($_POST['action'] ?? '') === 'toggle') {
            $id = (int) ($_POST['id_spec'] ?? 0);
            db()->prepare('UPDATE specialite SET active = IF(active = 1, 0, 1) WHERE id_spec = ?')->execute([$id]);
            academic_policy_note('specialite', 'Activation changée pour la spécialité ' . $id);
            $message = 'La spécialité a été mise à jour. Les dossiers déjà inscrits restent liés.';
        } else {
            $name = trim((string) ($_POST['nom_spec'] ?? ''));
            $nameAr = trim((string) ($_POST['nom_spec_ar'] ?? ''));
            $programId = (int) ($_POST['id_program'] ?? 0);
            if ($name === '' || mb_strlen($name) > 120 || $nameAr === '' || !preg_match('/\p{Arabic}/u', $nameAr) || $programId < 1) {
                throw new InvalidArgumentException('Saisissez le nom français, le nom arabe et le programme.');
            }
            $exists = db()->prepare('SELECT 1 FROM specialite WHERE LOWER(nom_spec) = LOWER(?)');
            $exists->execute([$name]);
            if ($exists->fetchColumn()) {
                throw new InvalidArgumentException('Cette spécialité existe déjà.');
            }
            db()->prepare('INSERT INTO specialite (nom_spec, nom_spec_ar, id_program, active) VALUES (?, ?, ?, 1)')->execute([$name, $nameAr, $programId]);
            academic_policy_note('specialite', 'Spécialité ' . $name . ' créée');
            $message = 'La spécialité a été ajoutée.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = 'L’enregistrement a échoué.';
    }
}

$programs = academic_programs();
$rows = db()->query('SELECT s.*, p.code AS program_code FROM specialite s LEFT JOIN program p ON p.id_program = s.id_program ORDER BY s.active DESC, s.nom_spec')->fetchAll();
page_head('Spécialités', 'groups');
?>
<div class="mb-4">
  <p class="eyebrow">Configuration</p>
  <h1 class="display-6 fw-bold mb-1">Spécialités</h1>
  <p class="text-secondary mb-0">Une spécialité appartient à un programme. La désactiver la retire des nouvelles inscriptions.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4"><div class="card-body p-4">
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="col-md-4"><label class="form-label">Nom</label><input class="form-control" name="nom_spec" maxlength="120" required></div>
    <div class="col-md-4"><label class="form-label">Nom arabe</label><input class="form-control text-end" dir="rtl" lang="ar" name="nom_spec_ar" required></div>
    <div class="col-md-4"><label class="form-label">Programme</label><select class="form-select" name="id_program" required><?php foreach ($programs as $program): ?><option value="<?= (int) $program['id_program'] ?>"><?= e($program['code'] . ' — ' . $program['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Ajouter</button></div>
  </form>
</div></div>
<div class="card app-card"><div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0">
  <thead><tr><th>Spécialité</th><th>Programme</th><th>État</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= e($row['nom_spec']) ?><br><small dir="rtl" lang="ar"><?= e($row['nom_spec_ar']) ?></small></td>
      <td><?= e((string) ($row['program_code'] ?? '—')) ?></td>
      <td><?= (int) $row['active'] === 1 ? 'Active' : 'Inactive' ?></td>
      <td>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id_spec" value="<?= (int) $row['id_spec'] ?>">
          <button class="btn btn-outline-secondary btn-sm" type="submit"><?= (int) $row['active'] === 1 ? 'Désactiver' : 'Activer' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php page_foot();

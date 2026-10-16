<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_permission('stage.view');

access_ensure();
$pdo = db();
$dutyFilter = stage_duty() === 'superviseur' ? ' AND (p.id_stage_superviseur = ' . current_stage_id() . ' OR p.created_by = ' . current_stage_id() . ')' : '';
$counts = [
    'actifs' => (int) $pdo->query('SELECT COUNT(*) FROM stage_placement p WHERE p.statut = "en_cours"' . $dutyFilter)->fetchColumn(),
    'a_venir' => (int) $pdo->query('SELECT COUNT(*) FROM stage_placement p WHERE p.statut IN ("approuve","affecte") AND p.date_debut >= CURDATE()' . $dutyFilter)->fetchColumn(),
    'attente' => (int) $pdo->query('SELECT COUNT(*) FROM stage_placement p WHERE p.statut = "en_attente"' . $dutyFilter)->fetchColumn(),
    'sans_stage' => (int) $pdo->query('SELECT COUNT(*) FROM etudiant e WHERE e.statut = "actif" AND NOT EXISTS (SELECT 1 FROM stage_placement p WHERE p.id_etudiant = e.id_etudiant AND p.statut IN ("approuve","affecte","en_cours"))')->fetchColumn(),
    'organismes' => (int) $pdo->query('SELECT COUNT(*) FROM stage_organisme WHERE active = 1')->fetchColumn(),
    'echeances' => (int) $pdo->query('SELECT COUNT(*) FROM stage_placement p WHERE p.statut = "en_cours" AND p.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)' . $dutyFilter)->fetchColumn(),
];
$recent = $pdo->query(
    'SELECT p.id_placement, p.statut, p.date_debut, p.date_fin, e.nom, e.prenom, o.nom AS organisme
     FROM stage_placement p
     JOIN etudiant e ON e.id_etudiant = p.id_etudiant
     JOIN stage_organisme o ON o.id_organisme = p.id_organisme
     WHERE 1=1 ' . $dutyFilter . '
     ORDER BY p.updated_at DESC LIMIT 8'
)->fetchAll();
page_head('Stages', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow">Service des stages</p>
  <h1 class="display-6 fw-bold mb-1">Tableau de bord</h1>
  <p class="text-secondary mb-0"><?= stage_duty() === 'superviseur' ? 'Vos stages supervisés.' : 'Suivi des affectations et des organismes.' ?></p>
</div>
<div class="row g-3 mb-4">
  <?php foreach ([
      'Stages en cours' => $counts['actifs'],
      'À venir' => $counts['a_venir'],
      'En attente' => $counts['attente'],
      'Étudiants sans stage' => $counts['sans_stage'],
      'Organismes actifs' => $counts['organismes'],
      'Fins sous 14 jours' => $counts['echeances'],
  ] as $label => $value): ?>
    <div class="col-6 col-lg-4"><div class="card app-card"><div class="card-body"><span class="text-secondary d-block"><?= e($label) ?></span><strong class="fs-3"><?= (int) $value ?></strong></div></div></div>
  <?php endforeach; ?>
</div>
<div class="d-flex flex-wrap gap-2 mb-4">
  <?php if (role_can('stage.hosts')): ?><a class="btn btn-primary" href="stage_organismes.php<?= app_is_embedded() ? '?embed=1' : '' ?>">Organismes</a><?php endif; ?>
  <?php if (role_can('stage.place') || role_can('stage.view')): ?><a class="btn btn-outline-light" href="stage_placements.php<?= app_is_embedded() ? '?embed=1' : '' ?>">Stages</a><?php endif; ?>
  <a class="btn btn-outline-light" href="logout.php">Déconnexion</a>
</div>
<div class="card app-card"><div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0">
  <thead><tr><th>Étudiant</th><th>Organisme</th><th>Période</th><th>Statut</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $row): ?>
    <tr>
      <td><?= e($row['prenom'] . ' ' . $row['nom']) ?></td>
      <td><?= e($row['organisme']) ?></td>
      <td><?= e($row['date_debut'] . ' → ' . $row['date_fin']) ?></td>
      <td><a href="stage_suivi.php?id=<?= (int) $row['id_placement'] ?><?= app_is_embedded() ? '&embed=1' : '' ?>"><?= e(stage_statuses()[$row['statut']] ?? $row['statut']) ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$recent): ?><tr><td colspan="4" class="text-secondary text-center py-4">Aucun stage enregistré.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php page_foot();

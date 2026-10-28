<?php
declare(strict_types=1);
require_once app_path('layout.php');
require_permission('stage.view');
access_ensure();
$pdo = db();
$manager = stage_is_manager();
$mine = stage_duty() === 'superviseur' ? current_stage_id() : 0;
$counts = [
    'campagnes' => $manager ? (int) $pdo->query('SELECT COUNT(*) FROM stage_campagne WHERE statut <> "annule" AND date_fin >= CURDATE()')->fetchColumn() : 0,
    'a_venir' => $manager ? (int) $pdo->query('SELECT COUNT(*) FROM stage_campagne WHERE date_debut >= CURDATE()')->fetchColumn() : 0,
    'sans_affectation' => $manager ? (int) $pdo->query('SELECT COUNT(*) FROM stage_proposition WHERE id_service IS NULL AND statut = "proposee"')->fetchColumn() : 0,
    'affectes' => (int) $pdo->query('SELECT COUNT(*) FROM stage_placement p WHERE p.statut IN ("affecte","en_cours","revue","retour","valide")' . ($mine ? ' AND p.id_stage_superviseur = ' . $mine : ''))->fetchColumn(),
    'places' => $manager ? (int) $pdo->query('SELECT COALESCE(SUM(places),0) FROM stage_capacite c JOIN stage_campagne g ON g.id_campagne = c.id_campagne WHERE g.date_fin >= CURDATE()')->fetchColumn() : 0,
    'presque_pleins' => $manager ? (int) $pdo->query(
        'SELECT COUNT(*) FROM stage_capacite c
         JOIN stage_campagne g ON g.id_campagne = c.id_campagne
         WHERE g.date_fin >= CURDATE() AND c.places > 0
           AND (SELECT COUNT(*) FROM stage_placement p WHERE p.id_service = c.id_service AND p.id_campagne = c.id_campagne AND p.statut IN ("affecte","en_cours","revue","retour","valide","termine")) * 100 >= c.places * 80'
    )->fetchColumn() : 0,
    'encadrants' => $manager ? (int) $pdo->query('SELECT COUNT(*) FROM stage_user WHERE active = 1 AND responsabilite = "superviseur"')->fetchColumn() : 0,
    'revues' => $manager ? (int) $pdo->query('SELECT COUNT(*) FROM stage_eval_dossier WHERE origine = "superviseur" AND statut = "soumis"')->fetchColumn() : 0,
    'a_cloturer' => $manager ? (int) $pdo->query('SELECT COUNT(*) FROM stage_placement WHERE statut = "valide"')->fetchColumn() : 0,
    'brouillons' => $mine ? (int) $pdo->query('SELECT COUNT(*) FROM stage_eval_dossier d JOIN stage_placement p ON p.id_placement = d.id_placement WHERE d.origine = "superviseur" AND d.statut IN ("brouillon","retourne") AND p.id_stage_superviseur = ' . $mine)->fetchColumn() : 0,
    'a_envoyer' => $mine ? (int) $pdo->query('SELECT COUNT(*) FROM stage_placement p WHERE p.id_stage_superviseur = ' . $mine . ' AND p.statut IN ("affecte","en_cours","retour") AND NOT EXISTS (SELECT 1 FROM stage_eval_dossier d WHERE d.id_placement = p.id_placement AND d.origine = "superviseur" AND d.statut = "soumis")')->fetchColumn() : 0,
    'renvois' => $mine ? (int) $pdo->query('SELECT COUNT(*) FROM stage_eval_dossier d JOIN stage_placement p ON p.id_placement = d.id_placement WHERE d.origine = "superviseur" AND d.statut = "retourne" AND p.id_stage_superviseur = ' . $mine)->fetchColumn() : 0,
    'envoyees' => $mine ? (int) $pdo->query('SELECT COUNT(*) FROM stage_eval_dossier d JOIN stage_placement p ON p.id_placement = d.id_placement WHERE d.origine = "superviseur" AND d.statut IN ("soumis","valide") AND p.id_stage_superviseur = ' . $mine)->fetchColumn() : 0,
];
$filter = $mine ? 'WHERE p.id_stage_superviseur = ' . $mine : 'WHERE 1=1';
$recent = $pdo->query(
    'SELECT p.id_placement, p.statut, p.date_debut, p.date_fin, e.nom, e.prenom, o.nom AS organisme
     FROM stage_placement p
     JOIN etudiant e ON e.id_etudiant = p.id_etudiant
     JOIN stage_organisme o ON o.id_organisme = p.id_organisme
     ' . $filter . '
     ORDER BY p.updated_at DESC LIMIT 8'
)->fetchAll();
$cards = $manager
    ? [
        'Campagnes en cours' => $counts['campagnes'],
        'Périodes à venir' => $counts['a_venir'],
        'Étudiants non affectés' => $counts['sans_affectation'],
        'Affectations actives' => $counts['affectes'],
        'Places ouvertes' => $counts['places'],
        'Services presque pleins' => $counts['presque_pleins'],
        'Encadrants actifs' => $counts['encadrants'],
        'Évaluations à revoir' => $counts['revues'],
        'Stages à clôturer' => $counts['a_cloturer'],
    ]
    : [
        'Étudiants suivis' => $counts['affectes'],
        'Évaluations à envoyer' => $counts['a_envoyer'],
        'Brouillons et corrections' => $counts['brouillons'],
        'Renvoyées pour correction' => $counts['renvois'],
        'Évaluations envoyées' => $counts['envoyees'],
    ];
$documents = stage_doc_indicators();
$embed = app_is_embedded() ? '?embed=1' : '';
$docCards = $manager
    ? [
        'Étudiants au dossier incomplet' => [$documents['etudiants_incomplets'], 'stage_archives.php' . $embed . ($embed === '' ? '?' : '&') . 'filtre=incomplet'],
        'Pièces obligatoires manquantes' => [$documents['manquants'], 'stage_archives.php' . $embed . ($embed === '' ? '?' : '&') . 'filtre=manquant'],
        'En attente de validation' => [$documents['a_verifier'], 'stage_archives.php' . $embed . ($embed === '' ? '?' : '&') . 'filtre=a_verifier'],
        'À corriger' => [$documents['a_corriger'], 'stage_archives.php' . $embed . ($embed === '' ? '?' : '&') . 'filtre=a_corriger'],
        'Dossiers complets' => [$documents['complets'], 'stage_archives.php' . $embed . ($embed === '' ? '?' : '&') . 'filtre=complet'],
        'Dossiers archivés' => [$documents['archives'], 'stage_archives.php' . $embed . ($embed === '' ? '?' : '&') . 'filtre=archive'],
    ]
    : [
        'Documents de suivi' => [$documents['visibles'], 'stage_archives.php' . $embed],
    ];
page_head('Stages', 'presence');
?>
<div class="mb-4">
  <p class="eyebrow"><?= $manager ? 'Responsable des stages' : 'Encadrant de stage' ?></p>
  <h1 class="display-6 fw-bold mb-1">Tableau de bord</h1>
  <p class="text-secondary mb-0"><?= $manager ? 'Campagnes, places et validations.' : 'Uniquement les étudiants qui vous sont affectés.' ?></p>
</div>
<div class="row g-3 mb-4">
  <?php foreach ($cards as $label => $value): ?>
    <div class="col-6 col-lg-4"><div class="card app-card"><div class="card-body"><span class="text-secondary d-block"><?= e($label) ?></span><strong class="fs-3"><?= (int) $value ?></strong></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3 mb-4">
  <?php foreach ($docCards as $label => $item): ?>
    <div class="col-6 col-lg-4"><a class="card app-card text-decoration-none" href="<?= e($item[1]) ?>"><div class="card-body"><span class="text-secondary d-block"><?= e($label) ?></span><strong class="fs-3"><?= (int) $item[0] ?></strong></div></a></div>
  <?php endforeach; ?>
</div>
<div class="d-flex flex-wrap gap-2 mb-4">
  <?php if (role_can('stage.allocate')): ?><a class="btn btn-primary" href="stage_campagne.php<?= e($embed) ?>">Campagnes</a><?php endif; ?>
  <?php if (role_can('stage.hosts')): ?><a class="btn btn-outline-light" href="stage_organismes.php<?= e($embed) ?>">Établissements</a><?php endif; ?>
  <a class="btn btn-outline-light" href="stage_placements.php<?= e($embed) ?>">Affectations</a>
  <a class="btn btn-outline-light" href="stage_archives.php<?= e($embed) ?>">Archives des stages</a>
  <?php if (role_can('stage.documents.configure')): ?><a class="btn btn-outline-light" href="stage_doc_types.php<?= e($embed) ?>">Documents</a><?php endif; ?>
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
  <?php if (!$recent): ?><tr><td colspan="4" class="text-secondary text-center py-4">Aucune affectation.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php page_foot();

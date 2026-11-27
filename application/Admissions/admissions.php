<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_admissions_permission('admissions.view');
admissions_ensure();

$stats = admissions_stats();
$statuses = admissions_statuses();
$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(a.application_number LIKE ? OR c.nom LIKE ? OR c.prenom LIKE ? OR c.email LIKE ? OR c.cin_passeport LIKE ? OR c.num_tel LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($status !== '' && isset($statuses[$status])) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
$sqlWhere = implode(' AND ', $where);
$countStmt = db()->prepare(
    "SELECT COUNT(*) FROM admission_application a JOIN admission_candidate c ON c.id_candidate = a.id_candidate WHERE $sqlWhere"
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$list = db()->prepare(
    "SELECT a.id_application, a.application_number, a.status, a.document_status, a.test_status, a.decision_status,
            a.academic_year_label, a.submitted_at, c.nom, c.prenom, c.email, c.num_tel, s.nom_spec, camp.label AS campaign_label
     FROM admission_application a
     JOIN admission_candidate c ON c.id_candidate = a.id_candidate
     JOIN admission_campaign camp ON camp.id_campaign = a.id_campaign
     LEFT JOIN specialite s ON s.id_spec = a.id_spec
     WHERE $sqlWhere
     ORDER BY a.id_application DESC
     LIMIT $perPage OFFSET $offset"
);
$list->execute($params);
$rows = $list->fetchAll();
$pages = max(1, (int) ceil($total / $perPage));

page_head('Admissions', 'admissions', 'assets/admissions-lightswind.css?v=2');
?>
<div class="adm-lw-admin">
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
  <div>
    <p class="eyebrow">Admissions</p>
    <h1 class="display-6 fw-bold mb-1">Tableau de bord admissions</h1>
    <p class="text-secondary mb-0">Nouveaux candidats (بلاغ + نظام داخلي). Pour les étudiants déjà inscrits : préinscription PA-SAE-01.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-light btn-sm" href="admission_campagnes.php?embed=1">Campagnes</a>
    <a class="btn btn-outline-light btn-sm" href="admission_tests.php?embed=1">Tests</a>
    <a class="btn btn-outline-light btn-sm" href="admission_entretiens.php?embed=1">Entretiens</a>
    <a class="btn btn-outline-light btn-sm" href="admission_config.php?embed=1">Config</a>
    <a class="btn btn-warning btn-sm" href="preregistration_suivi.php?embed=1">PA-SAE-01 continus</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php
  $cards = [
    ['total', 'Candidatures'],
    ['submitted', 'Nouvelles'],
    ['under_review', 'En examen'],
    ['documents_incomplete', 'Docs incomplets'],
    ['eligible', 'Éligibles'],
    ['test_scheduled', 'Tests planifiés'],
    ['interview_scheduled', 'Entretiens'],
    ['admission_pending', 'Comité'],
    ['admitted', 'Admis'],
    ['waitlisted', 'Liste d’attente'],
    ['rejected', 'Refusés'],
    ['registration_pending', 'Inscription'],
    ['enrolled', 'Inscrits'],
  ];
  foreach ($cards as [$key, $label]): ?>
    <div class="col-6 col-md-4 col-xl-3">
      <div class="card app-card adm-stat h-100">
        <div class="card-body py-3">
          <div class="text-secondary small"><?= e($label) ?></div>
          <div class="fs-3 fw-bold"><?= (int) ($stats[$key] ?? 0) ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<form class="row g-2 mb-3" method="get">
  <?php if (($_GET['embed'] ?? '') === '1'): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
  <div class="col-md-5"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Recherche (réf., nom, e-mail, CIN, tél.)"></div>
  <div class="col-md-3">
    <select class="form-select" name="status">
      <option value="">Tous les statuts</option>
      <?php foreach ($statuses as $code => $label): ?>
        <option value="<?= e($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-primary w-100">Filtrer</button></div>
</form>

<div class="card app-card">
  <div class="table-responsive">
    <table class="table table-dark table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Référence</th>
          <th>Candidat</th>
          <th>Contact</th>
          <th>Programme</th>
          <th>Statut</th>
          <th>Docs</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-secondary">Aucune candidature.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><code><?= e($row['application_number']) ?></code></td>
            <td><?= e(trim($row['prenom'] . ' ' . $row['nom'])) ?></td>
            <td class="small"><?= e($row['email']) ?><br><?= e($row['num_tel']) ?></td>
            <td class="small"><?= e((string) ($row['nom_spec'] ?? '—')) ?><br><?= e((string) $row['academic_year_label']) ?></td>
            <td><span class="badge text-bg-secondary"><?= e($statuses[$row['status']] ?? $row['status']) ?></span></td>
            <td class="small"><?= e((string) $row['document_status']) ?></td>
            <td><a class="btn btn-sm btn-outline-info" href="admission_candidat.php?id=<?= (int) $row['id_application'] ?>&embed=1">Ouvrir</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($pages > 1): ?>
  <nav class="mt-3"><ul class="pagination pagination-sm">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>">
        <a class="page-link" href="?<?= e(http_build_query(['q' => $q, 'status' => $status, 'page' => $i, 'embed' => ($_GET['embed'] ?? '')])) ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
  </ul></nav>
<?php endif; ?>
</div>

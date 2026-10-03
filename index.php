<?php
require_once __DIR__ . '/layout.php';
require_login();

$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$where = '';
$params = [];
if ($search !== '') {
    $where = ' WHERE e.cin_passeport LIKE :search_cin OR e.nom LIKE :search_nom OR e.prenom LIKE :search_prenom OR e.num_inscription LIKE :search_registration';
    $params = [
        'search_cin' => '%' . $search . '%',
        'search_nom' => '%' . $search . '%',
        'search_prenom' => '%' . $search . '%',
        'search_registration' => '%' . $search . '%',
    ];
    $studentOrder = 'ORDER BY e.date_inscrit DESC, e.id_etudiant DESC';
} else {
    $studentOrder = 'ORDER BY e.date_inscrit DESC, e.id_etudiant DESC';
}

$count = db()->prepare('SELECT COUNT(*) FROM etudiant e' . $where);
$count->execute($params);
$totalStudents = (int) $count->fetchColumn();
$totalPages = max(1, (int) ceil($totalStudents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$studentsQuery = db()->prepare('SELECT e.*, s.nom_spec FROM etudiant e LEFT JOIN specialite s ON s.id_spec = e.id_spec' . $where . ' ' . $studentOrder . ' LIMIT :limit OFFSET :offset');
foreach ($params as $name => $value) $studentsQuery->bindValue(':' . $name, $value, PDO::PARAM_STR);
$studentsQuery->bindValue(':limit', $perPage, PDO::PARAM_INT);
$studentsQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
$studentsQuery->execute();
$students = $studentsQuery->fetchAll();
$teacherToday = [];
$teacherMissing = [];
$teacherOpenPresence = [];
if (is_teacher()) {
    $weekdays = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
    $todayName = $weekdays[(int) date('N')];
    $teacherId = current_teacher_id();
    $todayQuery = db()->prepare(
        'SELECT et.heure_debut, et.heure_fin, et.salle, et.type_seance, et.id_groupe, et.id_mat,
                g.semestre, g.type_groupe, g.numero, m.code_ecue, m.description_ecue
         FROM emploi_temps et
         JOIN groupe g ON g.id_groupe = et.id_groupe
         JOIN matiere m ON m.id_mat = et.id_mat
         WHERE et.id_enseignant = ? AND et.jour = ?
         ORDER BY et.heure_debut'
    );
    $todayQuery->execute([$teacherId, $todayName]);
    $teacherToday = $todayQuery->fetchAll();
    presence_ensure_table(db());
    $filledQuery = db()->query('SELECT DISTINCT id_groupe, id_mat FROM presence_etudiant WHERE date_seance = CURDATE()');
    $filledKeys = [];
    foreach ($filledQuery->fetchAll() as $filled) {
        $filledKeys[(int) $filled['id_groupe'] . ':' . (int) $filled['id_mat']] = true;
    }
    foreach ($teacherToday as $lesson) {
        $key = (int) $lesson['id_groupe'] . ':' . (int) $lesson['id_mat'];
        if (!isset($filledKeys[$key])) {
            $teacherOpenPresence[] = $lesson;
        }
    }
    $missingQuery = db()->prepare(
        'SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero,
                m.id_mat, m.code_ecue, m.description_ecue,
                COUNT(DISTINCT ge.id_etudiant) AS students,
                COUNT(DISTINCT CASE WHEN n.id_note IS NOT NULL THEN ge.id_etudiant END) AS graded
         FROM enseignant_affectation a
         JOIN groupe g ON g.id_groupe = a.id_groupe AND g.type_groupe = "Cours"
         JOIN matiere m ON m.id_mat = a.id_mat AND m.semestre = g.semestre
         JOIN groupe_etudiant ge ON ge.id_groupe = g.id_groupe
         LEFT JOIN note n ON n.id_etudiant = ge.id_etudiant AND n.id_mat = m.id_mat AND n.semestre = m.semestre
           AND (n.note IS NOT NULL OR n.note_cours IS NOT NULL OR n.note_td IS NOT NULL OR n.note_tp IS NOT NULL OR n.note_rattrapage IS NOT NULL)
         WHERE a.id_enseignant = ?
         GROUP BY g.id_groupe, g.semestre, g.type_groupe, g.numero, m.id_mat, m.code_ecue, m.description_ecue
         HAVING graded < students
         ORDER BY g.semestre, g.numero, m.code_ecue
         LIMIT 12'
    );
    $missingQuery->execute([$teacherId]);
    $teacherMissing = $missingQuery->fetchAll();
}
$financeCollected = 0.0;
$financeMonthCount = 0;
$financeRemaining = 0.0;
$financeLate = 0;
$financeStudentCount = 0;
if (is_finance()) {
    $monthStats = db()->prepare('SELECT COALESCE(SUM(montant_paye), 0) AS total, COUNT(*) AS payments FROM finance WHERE created_at >= ?');
    $monthStats->execute([date('Y-m-01') . ' 00:00:00']);
    $monthRow = $monthStats->fetch() ?: [];
    $financeCollected = (float) ($monthRow['total'] ?? 0);
    $financeMonthCount = (int) ($monthRow['payments'] ?? 0);
    $financeStudentCount = (int) db()->query('SELECT COUNT(*) FROM etudiant')->fetchColumn();
    $financeRemaining = (float) db()->query(
        'SELECT COALESCE(SUM(GREATEST(9800 - COALESCE(fs.total_paye, 0), 0)), 0)
         FROM etudiant e LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant'
    )->fetchColumn();
    $financeLate = (int) db()->query(
        'SELECT COUNT(*) FROM etudiant e LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant WHERE COALESCE(fs.total_paye, 0) < 9800'
    )->fetchColumn();
}

function dashboard_url(int $page, string $search): string {
    $query = ['page' => $page];
    if ($search !== '') $query['search'] = $search;
    return 'index.php?' . http_build_query($query);
}

function dashboard_menu_item(
    string $href,
    string $title,
    string $subtitle,
    string $icon,
    string $number,
    bool $accent = false,
    int $badgeCount = 0
): void {
    $isHome = str_starts_with($href, '#');
    $classes = 'dashboard-menu-item' . ($accent ? ' dashboard-menu-item-accent' : '') . ($isHome ? ' is-active' : '');
    $target = $isHome ? '' : ' target="dashboard-content"';
    $homeAttr = $isHome ? ' data-dashboard-home aria-current="page"' : '';
    ?>
    <a class="<?= e($classes) ?>" href="<?= e($href) ?>" title="<?= e($title) ?>"<?= $target ?><?= $homeAttr ?> data-menu-icon="<?= e($icon) ?>"<?= $badgeCount > 0 ? ' data-alert-count="' . $badgeCount . '"' : '' ?>>
      <span class="dashboard-menu-icon" aria-hidden="true"></span>
      <?php if ($number !== ''): ?><span class="dashboard-menu-number"><?= e($number) ?></span><?php endif; ?>
      <span class="dashboard-menu-copy">
        <strong><?= e($title) ?><?php if ($badgeCount > 0): ?> <span class="dashboard-alert-count"><?= $badgeCount ?></span><?php endif; ?></strong>
        <small><?= e($subtitle) ?></small>
      </span>
    </a>
    <?php
}

function dashboard_menu(): void {
  $n = 0;
  $num = static function () use (&$n): string {
    $n++;
    return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
  };
?>
<section class="app-card dashboard-menu mb-4" aria-labelledby="dashboard-menu-title">
  <div class="dashboard-menu-header">
    <div><p class="eyebrow mb-1">Navigation rapide</p><h2 id="dashboard-menu-title" class="h4 mb-0">Menu principal</h2></div>
    <span class="dashboard-menu-orbit" aria-hidden="true"></span>
  </div>
  <div class="dashboard-menu-list dashboard-menu-rail">
    <?php
    dashboard_menu_item('#dashboard-content', 'Tableau de bord', 'Vue générale des étudiants', 'home', $num());
    if (is_admin()) {
      dashboard_menu_item('inscription.php?embed=1', 'Inscrire', 'Ajouter ou modifier un étudiant', 'enroll', $num());
      dashboard_menu_item('groupes.php?embed=1', 'Groupes', 'Consulter et organiser les groupes', 'groups', $num());
    }
    if (is_admin() || is_teacher()) {
      dashboard_menu_item('gestion_notes.php?embed=1', 'Notes', 'Saisir les notes autorisées', 'grades', $num());
    }
    if (is_finance()) {
      dashboard_menu_item('finance.php?embed=1', 'Suivi des paiements', 'Rechercher un étudiant et enregistrer son paiement', 'finance', $num(), true);
    }
    if (is_admin()) {
      dashboard_menu_item('affecter_enseignant.php?embed=1', 'Affecter Enseignant', 'Associer matières et groupes', 'assign', $num());
      ?>
      <div class="dashboard-menu-group" data-accounts-group>
        <button type="button" class="dashboard-menu-item dashboard-menu-parent" data-accounts-toggle data-menu-icon="accounts" aria-expanded="false">
          <span class="dashboard-menu-icon" aria-hidden="true"></span>
          <span class="dashboard-menu-number"><?= e($num()) ?></span>
          <span class="dashboard-menu-copy">
            <strong>Gestion des comptes</strong>
            <small>Créer, modifier, activer</small>
          </span>
        </button>
        <div class="dashboard-menu-sub" hidden>
          <?php
          dashboard_menu_item('modifier_comptes.php?embed=1', 'Modifier les comptes', 'Enseignant, finance, stage', 'accounts', '');
          dashboard_menu_item('creer_comptes.php?embed=1', 'Créer des comptes', 'Choisir le type sur la page', 'enroll', '');
          dashboard_menu_item('activer_comptes.php?embed=1', 'Activer / désactiver', 'Finance, enseignant, stage', 'alert', '');
          ?>
        </div>
      </div>
      <?php
      dashboard_menu_item('promotion.php?embed=1', 'Promotion / archivage', 'Valider la fin d\'année et le rollover finance', 'promo', $num(), true);
      dashboard_menu_item('archive_export.php?embed=1', 'Exporter les archives', 'Télécharger les données choisies en CSV ou PDF', 'archive', $num());
      dashboard_menu_item('emploi_temps.php?embed=1', 'Emploi du temps', 'Choisir un groupe et télécharger son planning PDF', 'schedule', $num());
    }
    if (is_admin() || is_teacher()) {
      dashboard_menu_item(
        'presence_etudiants.php?embed=1',
        'Présence des étudiants',
        is_teacher() ? 'Votre matière, groupe/TD, date du jour auto' : 'Enseignant, matière, groupe puis pointage',
        'presence',
        $num()
      );
      dashboard_menu_item('feuille_presence.php?embed=1', 'Feuille de présence', 'Vos groupes et matières affectés', 'sheet', $num());
    }
    if (is_admin()) {
      $eliminationTotal = (int) presence_elimination_alerts(db())['counts']['total'];
      dashboard_menu_item(
        'alertes_elimination.php?embed=1',
        'Alertes d’élimination',
        'Absences matière (≥4) et semestre S1 (≥10%)',
        'alert',
        $num(),
        true,
        $eliminationTotal
      );
      dashboard_menu_item(
        'journal_audit.php?embed=1',
        'Journal d’audit',
        'Notes, paiements, présences et comptes',
        'archive',
        $num()
      );
      dashboard_menu_item('download_all_inscriptions.php', 'Toutes les attestations', 'Télécharger un PDF unique pour l\'impression', 'docs', $num(), true);
    }
    ?>
  </div>
</section>
<?php }

page_head('Tableau de bord', 'home');
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 page-title-block">
  <div><p class="eyebrow"><?= is_finance() ? 'Service finance' : (is_teacher() ? 'Espace enseignant' : 'Administration') ?></p><h1 class="display-6 fw-bold mb-1">Tableau de bord</h1><p class="text-secondary mb-0"><?= is_finance() ? 'Situation des encaissements.' : 'Étudiants enregistrés et accès rapide aux services.' ?></p></div>
</div>
<div class="dashboard-workspace">
<?php dashboard_menu(); ?>
<section id="dashboard-content" class="dashboard-content-panel" aria-label="Contenu de la page sélectionnée">
  <?php if (is_admin()): ?>
  <div class="dashboard-home-panel">
    <div class="card app-card mb-4"><div class="card-body p-3">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-9"><label class="form-label mb-1" for="search">Rechercher un étudiant</label><input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Nom, prénom, CIN, passeport ou inscription"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Rechercher</button><?php if ($search !== ''): ?><a class="btn btn-outline-light" href="index.php">Effacer</a><?php endif; ?></div>
      </form>
    </div></div>
    <div class="card app-card"><div class="card-body p-0 table-responsive"><table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th>Inscription</th><th>Étudiant</th><th>Spécialité</th><th>Niveau</th><th>CIN / Passeport</th><th class="text-end">Documents</th></tr></thead>
      <tbody>
      <?php foreach ($students as $student): ?><tr>
        <td><?= e($student['num_inscription']) ?></td>
        <td>
          <strong><?= e($student['nom'] . ' ' . $student['prenom']) ?></strong>
          <span class="d-block text-secondary text-start" dir="rtl" lang="ar"><?= e(trim(($student['nom_ar'] ?? '') . ' ' . ($student['prenom_ar'] ?? ''))) ?></span>
          <small class="text-secondary"><?= e($student['email']) ?></small>
        </td>
        <td><?= e($student['nom_spec'] ?? '—') ?></td><td><?= e(level_label($student['niveau'])) ?></td><td><?= e($student['cin_passeport']) ?></td>
        <td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-1"><a class="btn btn-sm btn-outline-info" href="attestation_inscription.php?cin=<?= urlencode($student['cin_passeport']) ?>">Inscription</a><a class="btn btn-sm btn-outline-warning" href="attestation_presence.php?cin=<?= urlencode($student['cin_passeport']) ?>">Présence</a><a class="btn btn-sm btn-outline-success" href="attestation_reussite.php?cin=<?= urlencode($student['cin_passeport']) ?>">Réussite</a><a class="btn btn-sm btn-outline-primary" href="releve_notes.php?cin=<?= urlencode($student['cin_passeport']) ?>">Relevé</a><a class="btn btn-sm btn-outline-light" href="inscription.php?id=<?= (int) $student['id_etudiant'] ?>">Modifier</a></div></td>
      </tr><?php endforeach; ?>
      <?php if (!$students): ?><tr><td colspan="6" class="text-center text-secondary py-5">Aucun étudiant ne correspond à cette recherche.</td></tr><?php endif; ?>
      </tbody>
    </table></div></div>
    <?php if ($totalStudents > 0): ?><div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mt-3"><p class="text-secondary mb-0"><?= $totalStudents ?> étudiant<?= $totalStudents > 1 ? 's' : '' ?><?= $search !== '' ? ' trouvé' . ($totalStudents > 1 ? 's' : '') : '' ?> — page <?= $page ?> sur <?= $totalPages ?></p><nav aria-label="Pagination"><ul class="pagination mb-0"><li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(dashboard_url($page - 1, $search)) ?>">← Précédent</a></li><li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li><li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(dashboard_url($page + 1, $search)) ?>">Suivant →</a></li></ul></nav></div><?php endif; ?>
  </div>
  <?php elseif (is_teacher()): ?>
  <div class="dashboard-home-panel teacher-home">
    <div class="teacher-home-grid">
      <article class="card app-card teacher-home-card">
        <p class="eyebrow mb-1">Aujourd’hui · <?= e($weekdays[(int) date('N')] ?? '') ?></p>
        <h2 class="h5">Cours du jour</h2>
        <?php if (!$teacherToday): ?>
          <p class="text-secondary mb-0">Aucun cours dans l’emploi du temps pour aujourd’hui.</p>
        <?php else: ?>
          <ul class="teacher-home-list">
            <?php foreach ($teacherToday as $lesson): ?>
              <li>
                <strong><?= e(substr((string) $lesson['heure_debut'], 0, 5) . ' – ' . substr((string) $lesson['heure_fin'], 0, 5)) ?></strong>
                <span><?= e($lesson['code_ecue'] . ' — ' . $lesson['description_ecue']) ?></span>
                <small><?= e($lesson['semestre'] . ' · ' . $lesson['type_groupe'] . ' ' . $lesson['numero']) ?><?= $lesson['salle'] ? ' · ' . e((string) $lesson['salle']) : '' ?></small>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
      <article class="card app-card teacher-home-card">
        <p class="eyebrow mb-1">Notes</p>
        <h2 class="h5">Groupes incomplets</h2>
        <?php if (!$teacherMissing): ?>
          <p class="text-secondary mb-0">Chaque étudiant affecté a au moins une note enregistrée.</p>
        <?php else: ?>
          <ul class="teacher-home-list">
            <?php foreach ($teacherMissing as $gap): ?>
              <li>
                <a target="dashboard-content" href="gestion_notes.php?embed=1&amp;grid_group=<?= (int) $gap['id_groupe'] ?>&amp;grid_subject=<?= (int) $gap['id_mat'] ?>">
                  <strong><?= e($gap['code_ecue']) ?></strong>
                  <span><?= e($gap['semestre'] . ' · Cours ' . $gap['numero']) ?></span>
                  <small><?= (int) $gap['graded'] ?> / <?= (int) $gap['students'] ?> étudiants notés</small>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
      <article class="card app-card teacher-home-card">
        <p class="eyebrow mb-1">Présence</p>
        <h2 class="h5">Feuilles du jour</h2>
        <?php if (!$teacherToday): ?>
          <p class="text-secondary mb-0">Pas de séance à pointer aujourd’hui.</p>
        <?php elseif (!$teacherOpenPresence): ?>
          <p class="text-secondary mb-0">Toutes les séances d’aujourd’hui ont une feuille enregistrée.</p>
        <?php else: ?>
          <ul class="teacher-home-list">
            <?php foreach ($teacherOpenPresence as $lesson): ?>
              <li>
                <a target="dashboard-content" href="presence_etudiants.php?embed=1">
                  <strong><?= e($lesson['code_ecue']) ?></strong>
                  <span><?= e(substr((string) $lesson['heure_debut'], 0, 5) . ' · ' . $lesson['semestre'] . ' · ' . $lesson['type_groupe'] . ' ' . $lesson['numero']) ?></span>
                  <small>Présence non enregistrée</small>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
    </div>
  </div>
  <?php elseif (is_finance()): ?>
  <?php
    $financeExpected = max(1, $financeStudentCount * 9800);
    $financePaidPct = (int) max(0, min(100, round((($financeExpected - $financeRemaining) / $financeExpected) * 100)));
    $financeLatePct = $financeStudentCount > 0 ? (int) min(100, round(($financeLate / $financeStudentCount) * 100)) : 0;
    $financeMonthLabel = (['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][(int) date('n') - 1]) . ' ' . date('Y');
    $financeMoney = static fn (float $value): string => number_format($value, 2, ',', ' ');
  ?>
  <div class="dashboard-home-panel finance-dashboard">
    <div class="finance-rings">
      <article class="finance-ring">
        <div class="finance-ring-dial" style="--pct: <?= $financeCollected > 0 ? 100 : 0 ?>; --ring: #3dd6a5">
          <span><strong><?= e($financeMoney($financeCollected)) ?></strong><small>DT</small></span>
        </div>
        <h2>Encaissé ce mois</h2>
        <p><?= e($financeMonthLabel) ?> · <?= $financeMonthCount ?> versement<?= $financeMonthCount > 1 ? 's' : '' ?></p>
      </article>
      <article class="finance-ring">
        <div class="finance-ring-dial" style="--pct: <?= (int) max(0, min(100, round(($financeRemaining / $financeExpected) * 100))) ?>; --ring: #f0a36b">
          <span><strong><?= e($financeMoney($financeRemaining)) ?></strong><small>DT</small></span>
        </div>
        <h2>Reste à encaisser</h2>
        <p><?= $financePaidPct ?> % déjà encaissé sur l’ensemble</p>
      </article>
      <article class="finance-ring">
        <div class="finance-ring-dial" style="--pct: <?= $financeLatePct ?>; --ring: #ff6b6b">
          <span><strong><?= $financeLate ?></strong><small>étudiants</small></span>
        </div>
        <h2>Paiements en retard</h2>
        <p><?= $financeLatePct ?> % des étudiants sous 9 800 DT</p>
      </article>
    </div>
  </div>
  <?php else: ?>
  <div class="dashboard-content-hint">
    <p class="mb-0 text-center">Sélectionnez un module dans le menu pour commencer.</p>
  </div>
  <?php endif; ?>
  <?php if (!is_admin() && !is_teacher() && !is_finance()): ?>
  <iframe name="dashboard-content" title="Contenu du module" loading="lazy"></iframe>
  <?php endif; ?>
</section>
</div>
<?php
// Keep the home panel able to open modules in-panel when menu items are clicked.
if (is_admin() || is_teacher() || is_finance()): ?>
<script>
(function () {
  const panel = document.getElementById('dashboard-content');
  if (!panel) return;
  const homePanel = panel.querySelector('.dashboard-home-panel');
  let frame = panel.querySelector('iframe[name="dashboard-content"]');
  if (!frame) {
    frame = document.createElement('iframe');
    frame.name = 'dashboard-content';
    frame.title = 'Contenu du module';
    frame.loading = 'lazy';
    frame.hidden = true;
    panel.appendChild(frame);
  }
  document.querySelectorAll('.dashboard-menu-item[target="dashboard-content"], .teacher-home a[target="dashboard-content"]').forEach(function (link) {
    link.addEventListener('click', function () {
      if (homePanel) homePanel.hidden = true;
      frame.hidden = false;
    });
  });
  document.querySelectorAll('[data-dashboard-home]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      if (homePanel) homePanel.hidden = false;
      frame.hidden = true;
      frame.removeAttribute('src');
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();
</script>
<?php endif; ?>
<script>
(function () {
  const items = document.querySelectorAll('.dashboard-menu-item');
  items.forEach(function (link) {
    link.addEventListener('click', function () {
      if (link.hasAttribute('data-accounts-toggle')) return;
      items.forEach(function (item) {
        item.classList.remove('is-active');
        item.removeAttribute('aria-current');
      });
      link.classList.add('is-active');
      link.setAttribute('aria-current', 'page');
    });
  });
})();
document.querySelectorAll('[data-accounts-toggle]').forEach(function (button) {
  button.addEventListener('click', function () {
    const group = button.closest('[data-accounts-group]');
    const sub = group.querySelector('.dashboard-menu-sub');
    const open = group.classList.toggle('is-open');
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    sub.hidden = !open;
  });
});
</script>
<?php page_foot(); ?>

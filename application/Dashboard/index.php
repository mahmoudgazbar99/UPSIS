<?php
require_once app_path('layout.php');
if (!logged_in()) {
    header('Location: accueil.php');
    exit;
}
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
$macosIdentity = app_user_identity();
$macosTeacherCount = 0;
$macosGroupCount = 0;
$macosPendingPresence = 0;
$macosFeatureActive = false;
$macosNiveaux = [];
$macosPendingRows = [];
$macosActivity = [];
if (is_admin() || is_scolarite()) {
    try {
        $macosTeacherCount = (int) db()->query('SELECT COUNT(*) FROM enseignant')->fetchColumn();
        $macosGroupCount = (int) db()->query('SELECT COUNT(*) FROM groupe')->fetchColumn();
        $macosPendingPresence = attestation_pending_count(db());
        $macosFeatureActive = attestation_feature_active(db());
        $macosNiveaux = db()->query('SELECT niveau, COUNT(*) AS total FROM etudiant GROUP BY niveau ORDER BY niveau')->fetchAll();
        $macosPendingRows = db()->query(
            'SELECT d.id_demande, d.cin, d.motif, d.date_demande, e.nom, e.prenom
             FROM demande_attestation d
             JOIN etudiant e ON e.id_etudiant = d.id_etudiant
             WHERE d.statut = "en_attente"
             ORDER BY d.date_demande ASC
             LIMIT 6'
        )->fetchAll();
        audit_ensure_table(db());
        $macosActivity = db()->query(
            'SELECT actor_label, category, action_name, summary, created_at
             FROM audit_log
             ORDER BY created_at DESC, id_audit DESC
             LIMIT 5'
        )->fetchAll();
    } catch (Throwable $error) {
        /* Overview widgets stay empty if optional tables are unavailable. */
    }
}
$weekdaysFull = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
$monthsFull = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
$macosDateLabel = ($weekdaysFull[(int) date('N')] ?? '') . ' ' . (int) date('j') . ' ' . ($monthsFull[(int) date('n')] ?? '') . ' ' . date('Y');
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
         HAVING COUNT(DISTINCT CASE WHEN n.id_note IS NOT NULL THEN ge.id_etudiant END) < COUNT(DISTINCT ge.id_etudiant)
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
        'SELECT COALESCE(SUM(GREATEST(' . institution_fee_amount() . ' - COALESCE(fs.total_paye, 0), 0)), 0)
         FROM etudiant e LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant'
    )->fetchColumn();
    $financeLate = (int) db()->query(
        'SELECT COUNT(*) FROM etudiant e LEFT JOIN finance_solde fs ON fs.id_etudiant = e.id_etudiant WHERE COALESCE(fs.total_paye, 0) < ' . institution_fee_amount()
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
    int $badgeCount = 0,
    bool $download = false,
    string $downloadQuestion = ''
): void {
    $isHome = str_starts_with($href, '#');
    $classes = 'dashboard-menu-item' . ($accent ? ' dashboard-menu-item-accent' : '') . ($isHome ? ' is-active' : '');
    $target = ($isHome || $download) ? '' : ' target="dashboard-content"';
    $homeAttr = $isHome ? ' data-dashboard-home aria-current="page"' : '';
    $downloadAttr = $download
        ? ' data-file-download="1" data-download-question="' . e($downloadQuestion !== '' ? $downloadQuestion : 'Voulez-vous télécharger ce fichier ?') . '"'
        : '';
    ?>
    <a class="<?= e($classes) ?>" href="<?= e($href) ?>" title="<?= e($title) ?>"<?= $target ?><?= $homeAttr ?><?= $downloadAttr ?> data-menu-icon="<?= e($icon) ?>"<?= $badgeCount > 0 ? ' data-alert-count="' . $badgeCount . '"' : '' ?>>
      <span class="dashboard-menu-icon" aria-hidden="true"></span>
      <?php if ($number !== ''): ?><span class="dashboard-menu-number"><?= e($number) ?></span><?php endif; ?>
      <span class="dashboard-menu-copy">
        <strong class="dashboard-menu-title-row">
          <span class="dashboard-menu-title-text"><?= e($title) ?></span>
          <?php if ($badgeCount > 0): ?><span class="dashboard-alert-count" aria-label="<?= $badgeCount ?> en attente"><?= $badgeCount ?></span><?php endif; ?>
        </strong>
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
<section class="app-card dashboard-menu mb-4" id="dashboard-nav" aria-labelledby="dashboard-menu-title">
  <div class="dashboard-menu-header">
    <div><p class="eyebrow mb-1">Navigation rapide</p><h2 id="dashboard-menu-title" class="h4 mb-0"><span class="dashboard-menu-title-default">Menu principal</span><span class="dashboard-menu-title-macos">Université</span></h2></div>
    <button type="button" class="clarity-nav-toggle" data-clarity-nav-toggle aria-expanded="true" aria-controls="dashboard-nav" title="Réduire la navigation">
      <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M10 3.5 5.5 8 10 12.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span class="visually-hidden">Réduire la navigation</span>
    </button>
    <span class="dashboard-menu-orbit" aria-hidden="true"></span>
  </div>
  <div class="dashboard-menu-list dashboard-menu-rail">
    <?php
    dashboard_menu_item('#dashboard-content', 'Tableau de bord', 'Vue générale des étudiants', 'home', $num());
    if (is_admin() || is_scolarite()) {
      dashboard_menu_item('inscription.php?embed=1', 'Inscrire', 'Ajouter ou modifier un étudiant', 'enroll', $num());
      dashboard_menu_item('etudiants_import.php?embed=1', 'Importer des étudiants', 'Fichier Excel, lignes incomplètes signalées', 'archive', $num());
      dashboard_menu_item('groupes.php?embed=1', 'Groupes', 'Consulter et organiser les groupes', 'groups', $num());
    }
    if (is_admin() || is_teacher()) {
      dashboard_menu_item('gestion_notes.php?embed=1', 'Notes', 'Saisir les notes autorisées', 'grades', $num());
    }
    if (is_scolarite()) {
      dashboard_menu_item('demandes_presence.php?embed=1', 'Demandes de présence', 'Attestations demandées par les étudiants', 'attestation', $num());
      dashboard_menu_item('releve_notes.php?embed=1', 'Relevé de notes', 'Consulter un dossier', 'grades', $num());
      dashboard_menu_item('etudiants_export.php', 'Exporter les étudiants', 'Liste Excel du dossier actuel', 'archive', $num());
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
          dashboard_menu_item('modifier_comptes.php?embed=1', 'Modifier les comptes', 'Étudiant, enseignant, finance, stage', 'accounts', '');
          dashboard_menu_item('creer_comptes.php?embed=1', 'Créer des comptes', 'Étudiant, enseignant, finance, stage', 'enroll', '');
          dashboard_menu_item('activer_comptes.php?embed=1', 'Activer / désactiver', 'Étudiant, finance, enseignant, stage', 'alert', '');
          ?>
        </div>
      </div>
      <?php dashboard_menu_item('ajouter_matiere.php?embed=1', 'Ajouter matière', 'Coefficient et unité, sans doublon', 'subject', $num()); ?>
      <div class="dashboard-menu-group" data-accounts-group>
        <button type="button" class="dashboard-menu-item dashboard-menu-parent" data-accounts-toggle data-menu-icon="accounts" aria-expanded="false">
          <span class="dashboard-menu-icon" aria-hidden="true"></span>
          <span class="dashboard-menu-number"><?= e($num()) ?></span>
          <span class="dashboard-menu-copy">
            <strong>Configuration</strong>
            <small>Structure, frais, règles</small>
          </span>
        </button>
        <div class="dashboard-menu-sub" hidden>
          <?php
          dashboard_menu_item('structure_academique.php?embed=1', 'Structure académique', 'Programmes, niveaux, périodes', 'groups', '');
          dashboard_menu_item('etablissement.php?embed=1', 'Nom de l’établissement', 'Modifier les noms français et arabe des attestations', 'accounts', '');
          dashboard_menu_item('specialites.php?embed=1', 'Spécialités', 'Créer ou désactiver', 'enroll', '');
          dashboard_menu_item('frais_scolarite.php?embed=1', 'Frais de scolarité', 'Montant et devise', 'finance', '');
          dashboard_menu_item('politique_promotion.php?embed=1', 'Seuil de promotion', 'Admission et niveau final', 'promo', '');
          dashboard_menu_item('formule_calcul.php?embed=1', 'Formule de calcul', 'Moyenne, moyenne générale et crédits', 'formula', '');
          dashboard_menu_item('politique_presence.php?embed=1', 'Règles de présence', 'Absences et volume horaire', 'presence', '');
          dashboard_menu_item('identite.php?embed=1', 'Pièces d’identité', 'CIN et passeport', 'accounts', '');
          dashboard_menu_item('ajouter_unite.php?embed=1', 'Ajouter unité', 'Créer une unité d’enseignement', 'unity', '');
          dashboard_menu_item('responsables.php?poste=directeur&embed=1', 'Directeur', 'Nom français et arabe des attestations', 'dir', '');
          dashboard_menu_item('responsables.php?poste=secretaire&embed=1', 'Secrétaire général', 'Nom français et arabe des attestations', 'sec', '');
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
      if (is_teacher()) {
        dashboard_menu_item('mes_cours.php?embed=1', 'Mes cours', 'Dossiers et PDF pour vos étudiants', 'docs', $num());
      }
    }
    if (is_admin()) {
      $eliminationTotal = (int) presence_elimination_alerts(db())['counts']['total'];
      dashboard_menu_item(
        'alertes_elimination.php?embed=1',
        'Alertes d’élimination',
        'Absences selon la politique de présence publiée',
        'alert',
        $num(),
        true,
        $eliminationTotal
      );
      dashboard_menu_item(
        'demandes_presence.php?embed=1',
        'Demandes de présence',
        'Attestations demandées par les étudiants',
        'attestation',
        $num(),
        true,
        attestation_pending_count(db())
      );
      require_once app_path('support/student_portal.php');
      dashboard_menu_item(
        'demandes_etudiants.php?embed=1',
        'Demandes étudiantes',
        'Groupes, rendez-vous et réclamations',
        'groups',
        $num(),
        true,
        student_portal_pending_count(db())
      );
      dashboard_menu_item(
        'journal_audit.php?embed=1',
        'Journal d’audit',
        'Notes, paiements, présences et comptes',
        'archive',
        $num()
      );
      dashboard_menu_item(
        'download_all_inscriptions.php',
        'Toutes les attestations',
        'Télécharger un PDF unique pour l\'impression',
        'docs',
        $num(),
        true,
        0,
        true,
        'Voulez-vous télécharger toutes les attestations d’inscription dans un seul fichier PDF ?'
      );
    }
    ?>
  </div>
  <div class="macos-sidebar-foot macos-only-control">
    <span class="macos-online-dot" aria-hidden="true"></span>
    <span>Système en ligne</span>
  </div>
  <span class="clarity-nav-edge" data-clarity-nav-edge aria-hidden="true"></span>
</section>
<?php }

$dashboardLayoutRole = (string) ($_SESSION['user_role'] ?? '');
$dashboardLayoutUserId = match ($dashboardLayoutRole) {
    'admin' => (int) ($_SESSION['admin_id'] ?? 0),
    'enseignant' => (int) ($_SESSION['teacher_id'] ?? 0),
    'finance' => (int) ($_SESSION['finance_id'] ?? 0),
    'scolarite' => (int) ($_SESSION['scolarite_id'] ?? 0),
    'stage' => (int) ($_SESSION['stage_id'] ?? 0),
    default => 0,
};
$dashboardRoleLabel = is_finance() ? 'Service finance' : (is_teacher() ? 'Espace enseignant' : (is_scolarite() ? 'Scolarité' : 'Administration'));
$dashboardIntro = is_finance()
    ? 'Encaissements du mois, soldes et retards de paiement.'
    : (is_teacher()
        ? 'Vos cours du jour, feuilles de présence et notes à compléter.'
        : 'Effectifs, demandes en attente et activité récente de l’établissement.');
page_head('Tableau de bord', 'home', 'assets/dashboard-editor.css?v=6');
?>
<div class="dashboard-page-intro d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 page-title-block">
  <div><p class="eyebrow"><?= e($dashboardRoleLabel) ?></p><h1 class="display-6 fw-bold mb-1">Tableau de bord</h1><p class="text-secondary mb-0"><?= e($dashboardIntro) ?></p></div>
</div>
<div class="dashboard-workspace is-nav-collapsed">
<?php dashboard_menu(); ?>
<section id="dashboard-content" class="dashboard-content-panel" aria-label="Contenu de la page sélectionnée" data-dashboard-user="<?= $dashboardLayoutUserId ?>" data-dashboard-role="<?= e($dashboardLayoutRole) ?>">
  <div class="dashboard-layout-toolbar no-print" data-dashboard-layout-toolbar role="region" aria-label="Réorganisation du tableau de bord" hidden>
    <div class="dashboard-layout-toolbar-copy">
      <strong>Personnaliser le tableau de bord</strong>
      <span data-dashboard-layout-status role="status" aria-live="polite">Déplacez les blocs avec la poignée ou les boutons.</span>
    </div>
    <div class="dashboard-layout-actions">
      <button type="button" class="btn btn-outline-light btn-sm" data-dashboard-layout-edit>Réorganiser</button>
      <button type="button" class="btn btn-outline-light btn-sm" data-dashboard-layout-reset hidden>Ordre par défaut</button>
      <button type="button" class="btn btn-outline-light btn-sm" data-dashboard-layout-cancel hidden>Annuler</button>
      <button type="button" class="btn btn-primary btn-sm" data-dashboard-layout-save hidden>Enregistrer</button>
    </div>
  </div>
  <header class="macos-inner-titlebar no-print">
    <div class="macos-traffic">
      <button type="button" class="macos-dot macos-dot-close" data-macos-action="close" aria-label="Fermer la fenêtre" title="Fermer"></button>
      <button type="button" class="macos-dot macos-dot-min" data-macos-action="minimize" aria-label="Réduire la fenêtre" title="Réduire"></button>
      <button type="button" class="macos-dot macos-dot-max" data-macos-action="maximize" aria-label="Agrandir la fenêtre" title="Agrandir"></button>
    </div>
    <p class="macos-window-name" data-macos-title>Tableau de bord</p>
    <div class="macos-window-tools">
      <button type="button" class="macos-tool" data-macos-action="minimize" aria-label="Réduire la fenêtre" title="Réduire">–</button>
      <button type="button" class="macos-tool" data-macos-action="maximize" aria-label="Agrandir la fenêtre" title="Agrandir">⤢</button>
      <button type="button" class="macos-tool" data-macos-action="close" aria-label="Fermer la fenêtre" title="Fermer">×</button>
    </div>
  </header>
  <p class="macos-crumb macos-only-control"><span>Accueil</span><span data-macos-title>Tableau de bord</span></p>
  <?php if (is_admin() || is_scolarite()): ?>
  <?php if (is_scolarite()): ?>
  <div class="row g-3 mb-4" data-dashboard-layout="office-summary">
    <?php
      $bySpec = db()->query('SELECT COALESCE(s.nom_spec, "Sans spécialité") AS nom, COUNT(*) AS total FROM etudiant e LEFT JOIN specialite s ON s.id_spec = e.id_spec GROUP BY s.nom_spec ORDER BY total DESC')->fetchAll();
      $recentOffice = db()->query('SELECT nom, prenom FROM etudiant ORDER BY date_inscrit DESC, id_etudiant DESC LIMIT 1')->fetch();
    ?>
    <div class="col-md-4" data-dashboard-widget="students"><div class="card app-card"><div class="card-body"><span class="text-secondary">Étudiants inscrits</span><strong class="d-block fs-3"><?= (int) $totalStudents ?></strong></div></div></div>
    <div class="col-md-4" data-dashboard-widget="requests"><div class="card app-card"><div class="card-body"><span class="text-secondary">Demandes en attente</span><strong class="d-block fs-3"><?= (int) $macosPendingPresence ?></strong></div></div></div>
    <div class="col-md-4" data-dashboard-widget="latest-student"><div class="card app-card"><div class="card-body"><span class="text-secondary">Dernière inscription</span><strong class="d-block"><?= $recentOffice ? e($recentOffice['prenom'] . ' ' . $recentOffice['nom']) : '—' ?></strong></div></div></div>
    <div class="col-12" data-dashboard-widget="specialties"><div class="card app-card"><div class="card-body"><h2 class="h6">Par spécialité</h2><ul class="mb-0"><?php foreach ($bySpec as $specRow): ?><li><?= e((string) $specRow['nom']) ?> · <?= (int) $specRow['total'] ?></li><?php endforeach; ?></ul></div></div></div>
  </div>
  <?php endif; ?>
  <div class="dashboard-home-panel admin-dashboard-grid" data-dashboard-layout="admin-home">
    <?php
      $niveauTotal = 0;
      foreach ($macosNiveaux as $row) {
          $niveauTotal += (int) $row['total'];
      }
      $distributionColors = ['#2563eb', '#7c3aed', '#0e7490', '#b45309', '#be123c', '#047857'];
      $distributionSlices = [];
      $distributionCursor = 0;
      foreach ($macosNiveaux as $index => $row) {
          $count = max(0, (int) $row['total']);
          $distributionSlices[] = [
              'label' => level_label((string) $row['niveau']),
              'count' => $count,
              'start' => $distributionCursor,
              'percent' => $niveauTotal > 0 ? ($count / $niveauTotal) * 100 : 0,
              'color' => $distributionColors[$index % count($distributionColors)],
          ];
          $distributionCursor += $count;
      }
      $chartPathLength = max(1, $niveauTotal);
    ?>
      <article class="macos-hero" data-dashboard-widget="hero">
        <div class="macos-hero-photo" aria-hidden="true"></div>
        <div class="macos-hero-shade" aria-hidden="true"></div>
        <div class="macos-hero-copy">
          <span class="macos-hero-icon" aria-hidden="true">
            <svg viewBox="0 0 32 32" focusable="false"><path d="M4 12 16 5l12 7M7 13h18M9 14v10m7-10v10m7-10v10M5 25h22M3 28h26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          <div>
            <h2>Bienvenue, <?= e($macosIdentity['name']) ?></h2>
            <p><?= e($dashboardIntro) ?></p>
            <?php if (is_admin() || is_scolarite()): ?><a class="btn btn-primary btn-sm mt-2" href="inscription.php?embed=1" target="dashboard-content">Inscrire un étudiant</a><?php endif; ?>
          </div>
        </div>
        <aside class="macos-hero-date">
          <span class="macos-hero-cal" aria-hidden="true">
            <svg viewBox="0 0 20 20" focusable="false"><rect x="2.5" y="4.5" width="15" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M6 2.5v4M14 2.5v4M2.5 8h15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
          </span>
          <strong><?= e($macosDateLabel) ?></strong>
          <span><?= e(date('H:i')) ?></span>
        </aside>
      </article>
        <article class="macos-stat macos-stat-blue" data-dashboard-widget="stat-students">
          <span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H7.4A3.4 3.4 0 0 0 4 18.4V20m11-12.5a3 3 0 1 1 0 6m5 6.5v-1.6a3.4 3.4 0 0 0-2.8-3.35M10 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          <div><small>Étudiants au registre</small><strong><?= (int) $totalStudents ?></strong></div>
        </article>
        <article class="macos-stat macos-stat-green" data-dashboard-widget="stat-teachers">
          <span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="9" cy="7" r="3" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3.5 20v-1.7a4.5 4.5 0 0 1 9 0V20M14 5h6v5h-6zM17 10v3m-2 0h4m-2 0v7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          <div><small>Enseignants</small><strong><?= (int) $macosTeacherCount ?></strong></div>
        </article>
        <article class="macos-stat macos-stat-purple" data-dashboard-widget="stat-groups">
          <span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="3.5" y="4" width="7" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="13.5" y="4" width="7" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="3.5" y="14" width="7" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="13.5" y="14" width="7" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></span>
          <div><small>Groupes</small><strong><?= (int) $macosGroupCount ?></strong></div>
        </article>
        <article class="macos-stat macos-stat-orange<?= $macosPendingPresence > 0 ? ' is-attention' : '' ?>" data-dashboard-widget="stat-requests">
          <span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M7 3.5h7l4 4V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Zm7 0v4h4M9 12h6m-6 3.5h6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          <div><small>Demandes en attente</small><strong><?= (int) $macosPendingPresence ?></strong></div>
        </article>
        <article class="macos-panel dashboard-admin-requests" data-dashboard-widget="requests">
          <div class="macos-panel-head">
            <div>
              <h3>Les demandes de présences</h3>
              <p><?= (int) $macosPendingPresence ?> demande<?= $macosPendingPresence > 1 ? 's' : '' ?> en attente.</p>
            </div>
            <a class="macos-status-pill <?= $macosFeatureActive ? 'is-on' : 'is-off' ?>" href="demandes_presence.php?embed=1" target="dashboard-content"><?= $macosFeatureActive ? 'Service activé' : 'Service désactivé' ?></a>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead><tr><th scope="col">Étudiant</th><th scope="col">CIN</th><th scope="col">Date</th><th scope="col">Motif</th><th scope="col" class="text-end">Actions</th></tr></thead>
              <tbody>
              <?php foreach ($macosPendingRows as $demande): ?>
                <tr>
                  <td><?= e(trim($demande['prenom'] . ' ' . $demande['nom'])) ?></td>
                  <td><?= e((string) $demande['cin']) ?></td>
                  <td><?= e(date('d/m/Y H:i', strtotime((string) $demande['date_demande']))) ?></td>
                  <td><?= e((string) $demande['motif']) ?></td>
                  <td class="text-end"><div class="macos-row-actions"><a class="macos-chip macos-chip-ok" href="demandes_presence.php?embed=1" target="dashboard-content">Accepter</a><a class="macos-chip macos-chip-no" href="demandes_presence.php?embed=1" target="dashboard-content">Refuser</a></div></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$macosPendingRows): ?>
                <tr><td colspan="5" class="text-secondary text-center py-4">Aucune demande en attente.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>
        <article class="macos-panel macos-chart-panel dashboard-distribution-panel" data-dashboard-widget="distribution">
          <div class="dashboard-chart-heading">
            <div><h3 id="student-distribution-title">Répartition des étudiants</h3><p>Effectifs par niveau</p></div>
            <span class="dashboard-chart-kicker">Répartition</span>
          </div>
          <div class="dashboard-distribution-chart" data-dashboard-distribution data-count-target="<?= (int) $niveauTotal ?>" data-count-final="<?= e(number_format($niveauTotal, 0, ',', ' ')) ?>">
            <p class="visually-hidden" id="student-distribution-details">Les effectifs et pourcentages par niveau sont détaillés dans la liste qui suit le diagramme.</p>
            <div class="dashboard-chart-layout">
              <div class="dashboard-donut" role="img" aria-label="Diagramme en anneau, total : <?= (int) $niveauTotal ?> étudiant<?= $niveauTotal > 1 ? 's' : '' ?>." aria-describedby="student-distribution-details">
                <svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
                  <circle class="dashboard-donut-track" cx="60" cy="60" r="47" pathLength="<?= $chartPathLength ?>" stroke-dasharray="<?= $chartPathLength ?> <?= $chartPathLength ?>"></circle>
                  <?php foreach ($distributionSlices as $index => $slice): ?>
                    <circle
                      class="dashboard-donut-segment"
                      cx="60" cy="60" r="47"
                      pathLength="<?= $chartPathLength ?>"
                      stroke-dasharray="<?= $slice['count'] ?> <?= $chartPathLength ?>"
                      stroke-dashoffset="-<?= $slice['start'] ?>"
                      data-chart-segment="<?= $index ?>"
                      style="--chart-color: <?= e($slice['color']) ?>; --chart-count: <?= $slice['count'] ?>; --chart-total: <?= $chartPathLength ?>; --chart-start: <?= $slice['start'] ?>; --chart-index: <?= min($index, 5) ?>;"
                    ></circle>
                  <?php endforeach; ?>
                </svg>
                <span class="dashboard-chart-hub" aria-hidden="true"><strong data-dashboard-count><?= e(number_format($niveauTotal, 0, ',', ' ')) ?></strong><small>étudiants</small></span>
              </div>
              <ul class="dashboard-distribution-list" aria-label="Effectifs et parts par niveau">
                <?php foreach ($distributionSlices as $index => $slice): ?>
                  <?php $shareLabel = number_format($slice['percent'], 1, ',', ' ') . ' %'; ?>
                  <li tabindex="0" class="dashboard-distribution-item" data-chart-category="<?= $index ?>" style="--chart-color: <?= e($slice['color']) ?>" aria-label="<?= e($slice['label'] . ' : ' . $slice['count'] . ' étudiant' . ($slice['count'] > 1 ? 's' : '') . ', ' . $shareLabel . '.') ?>">
                    <span class="dashboard-distribution-marker" style="--chart-color: <?= e($slice['color']) ?>" aria-hidden="true"></span>
                    <span class="dashboard-distribution-copy"><span><?= e($slice['label']) ?></span><small><?= e($shareLabel) ?></small></span>
                    <strong><?= (int) $slice['count'] ?></strong>
                  </li>
                <?php endforeach; ?>
                <?php if (!$distributionSlices): ?><li class="dashboard-distribution-empty">Aucune donnée disponible.</li><?php endif; ?>
              </ul>
            </div>
          </div>
        </article>
        <article class="macos-panel dashboard-admin-activity" data-dashboard-widget="activity">
          <div class="macos-panel-head">
            <h3>Dernières activités</h3>
            <a href="journal_audit.php?embed=1" target="dashboard-content">Voir tout</a>
          </div>
          <ul class="macos-activity">
            <?php foreach ($macosActivity as $entry): ?>
              <li>
                <strong><?= e((string) $entry['action_name']) ?></strong>
                <span><?= e((string) $entry['summary']) ?></span>
                <small><?= e(date('d/m H:i', strtotime((string) $entry['created_at']))) ?></small>
              </li>
            <?php endforeach; ?>
            <?php if (!$macosActivity): ?><li class="text-secondary">Aucune activité récente.</li><?php endif; ?>
          </ul>
        </article>
      <article class="card app-card dashboard-student-search" data-dashboard-widget="student-search" aria-label="Recherche d’un étudiant"><div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-9"><label class="form-label mb-1" for="search">Rechercher un étudiant</label><input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Nom, prénom, CIN, passeport ou inscription"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Rechercher</button><?php if ($search !== ''): ?><a class="btn btn-outline-light" href="index.php">Effacer</a><?php endif; ?></div>
      </form>
    </div></article>
    <article class="card app-card dashboard-student-list" data-dashboard-widget="student-list" aria-label="Liste des étudiants"><div class="card-body p-0 table-responsive"><table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th scope="col">Inscription</th><th scope="col">Étudiant</th><th scope="col">Spécialité</th><th scope="col">Niveau</th><th scope="col">CIN / Passeport</th><th scope="col" class="text-end">Documents</th></tr></thead>
      <tbody>
      <?php foreach ($students as $student): ?><tr>
        <td><?= e($student['num_inscription']) ?></td>
        <td>
          <strong><?= e($student['nom'] . ' ' . $student['prenom']) ?></strong>
          <span class="d-block text-secondary text-start" dir="rtl" lang="ar"><?= e(trim(($student['nom_ar'] ?? '') . ' ' . ($student['prenom_ar'] ?? ''))) ?></span>
          <small class="text-secondary"><?= e($student['email']) ?></small>
        </td>
        <td><?= e($student['nom_spec'] ?? '—') ?></td><td><?= e(level_label($student['niveau'])) ?></td><td><?= e($student['cin_passeport']) ?></td>
        <td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-1"><a class="btn btn-sm btn-outline-info" target="dashboard-content" href="attestation_inscription.php?embed=1&amp;cin=<?= urlencode($student['cin_passeport']) ?>">Inscription</a><a class="btn btn-sm btn-outline-warning" target="dashboard-content" href="attestation_presence.php?embed=1&amp;cin=<?= urlencode($student['cin_passeport']) ?>">Présence</a><a class="btn btn-sm btn-outline-success" target="dashboard-content" href="attestation_reussite.php?embed=1&amp;cin=<?= urlencode($student['cin_passeport']) ?>">Réussite</a><a class="btn btn-sm btn-outline-primary" target="dashboard-content" href="releve_notes.php?embed=1&amp;cin=<?= urlencode($student['cin_passeport']) ?>">Relevé</a><a class="btn btn-sm btn-outline-light" target="dashboard-content" href="inscription.php?embed=1&amp;id=<?= (int) $student['id_etudiant'] ?>">Modifier</a></div></td>
      </tr><?php endforeach; ?>
      <?php if (!$students): ?><tr><td colspan="6" class="text-center text-secondary py-5"><?= $search !== '' ? 'Aucun étudiant ne correspond à cette recherche.' : 'Aucun étudiant enregistré.' ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <div class="card-body pt-3">
    <?php if ($search !== ''): ?><p class="dashboard-search-feedback" aria-live="polite"><?= $totalStudents ?> résultat<?= $totalStudents > 1 ? 's' : '' ?> pour « <?= e($search) ?> »</p><?php endif; ?>
    <?php if ($totalStudents > 0): ?><div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mt-3"><p class="text-secondary mb-0"><?= $totalStudents ?> étudiant<?= $totalStudents > 1 ? 's' : '' ?><?= $search !== '' ? ' trouvé' . ($totalStudents > 1 ? 's' : '') : '' ?> — page <?= $page ?> sur <?= $totalPages ?></p><nav aria-label="Pagination"><ul class="pagination mb-0"><li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(dashboard_url($page - 1, $search)) ?>">← Précédent</a></li><li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li><li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(dashboard_url($page + 1, $search)) ?>">Suivant →</a></li></ul></nav></div><?php endif; ?>
    </div></article>
  </div>
  <?php elseif (is_teacher()): ?>
  <div class="dashboard-home-panel teacher-home">
    <div class="teacher-home-grid" data-dashboard-layout="teacher-home">
      <article class="card app-card teacher-home-card" data-dashboard-widget="today-lessons">
        <p class="eyebrow mb-1">Aujourd’hui · <?= e($weekdays[(int) date('N')] ?? '') ?></p>
        <h2 class="h5">Cours du jour</h2>
        <?php if (!$teacherToday): ?>
          <p class="text-secondary mb-0">Aucun cours dans l’emploi du temps pour aujourd’hui.</p>
        <?php else: ?>
          <ul class="teacher-home-list">
            <?php foreach ($teacherToday as $lesson): ?>
              <li class="teacher-schedule-row">
                <strong><?= e(substr((string) $lesson['heure_debut'], 0, 5) . ' – ' . substr((string) $lesson['heure_fin'], 0, 5)) ?></strong>
                <span><?= e($lesson['code_ecue'] . ' — ' . $lesson['description_ecue']) ?></span>
                <small><?= e($lesson['semestre'] . ' · ' . $lesson['type_groupe'] . ' ' . $lesson['numero']) ?><?= $lesson['salle'] ? ' · ' . e((string) $lesson['salle']) : '' ?></small>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
      <article class="card app-card teacher-home-card" data-dashboard-widget="missing-grades">
        <p class="eyebrow mb-1">Notes</p>
        <h2 class="h5">Groupes incomplets</h2>
        <?php if (!$teacherMissing): ?>
          <p class="text-secondary mb-0">Chaque étudiant affecté a au moins une note enregistrée.</p>
        <?php else: ?>
          <ul class="teacher-home-list">
            <?php foreach ($teacherMissing as $gap): ?>
              <li class="teacher-grade-row">
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
      <article class="card app-card teacher-home-card" data-dashboard-widget="attendance-sheets">
        <p class="eyebrow mb-1">Présence</p>
        <h2 class="h5">Feuilles du jour</h2>
        <?php if (!$teacherToday): ?>
          <p class="text-secondary mb-0">Pas de séance à pointer aujourd’hui.</p>
        <?php elseif (!$teacherOpenPresence): ?>
          <p class="text-secondary mb-0">Toutes les séances d’aujourd’hui ont une feuille enregistrée.</p>
        <?php else: ?>
          <ul class="teacher-home-list">
            <?php foreach ($teacherOpenPresence as $lesson): ?>
              <li class="teacher-attendance-row">
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
    $financeExpected = max(1, $financeStudentCount * institution_fee_amount());
    $financePaidPct = $financeStudentCount > 0
        ? (int) max(0, min(100, round((($financeExpected - $financeRemaining) / $financeExpected) * 100)))
        : 0;
    $financeRemainingPct = $financeStudentCount > 0
        ? max(0, min(100, ($financeRemaining / $financeExpected) * 100))
        : 0.0;
    $financeLatePct = $financeStudentCount > 0
        ? min(100, ($financeLate / $financeStudentCount) * 100)
        : 0.0;
    $financeLatePctLabel = (int) round($financeLatePct);
    $financeMonthLabel = (['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][(int) date('n') - 1]) . ' ' . date('Y');
    $financeMoney = static fn (float $value): string => number_format($value, 2, ',', ' ');
  ?>
  <div class="dashboard-home-panel finance-dashboard">
    <header class="dashboard-finance-heading">
      <div><p class="eyebrow mb-1">Analyse financière</p><h2>Indicateurs de paiement</h2></div>
      <p>Encaissements du mois et soldes de scolarité.</p>
    </header>
    <div class="finance-rings" data-dashboard-layout="finance-home">
      <article class="finance-ring finance-ring--mint" data-dashboard-widget="collected">
        <div class="finance-ring-dial finance-ring-dial--stat">
          <span><strong><?= e($financeMoney($financeCollected)) ?></strong><small>DT</small></span>
        </div>
        <h2>Encaissé ce mois</h2>
        <p><?= e($financeMonthLabel) ?> · <?= $financeMonthCount ?> versement<?= $financeMonthCount > 1 ? 's' : '' ?></p>
      </article>
      <article class="finance-ring finance-ring--amber" data-dashboard-widget="remaining">
        <div class="finance-ring-dial" style="--pct: <?= e(number_format($financeRemainingPct, 8, '.', '')) ?>">
          <svg viewBox="0 0 120 120" aria-hidden="true">
            <circle class="finance-ring-track" cx="60" cy="60" r="52"></circle>
            <circle class="finance-ring-value" cx="60" cy="60" r="52"></circle>
          </svg>
          <span><strong><?= e($financeMoney($financeRemaining)) ?></strong><small>DT</small></span>
        </div>
        <h2>Reste à encaisser</h2>
        <p><?= $financePaidPct ?> % déjà encaissé sur l’ensemble</p>
      </article>
      <article class="finance-ring finance-ring--rose" data-dashboard-widget="late-payments">
        <div class="finance-ring-dial" style="--pct: <?= e(number_format($financeLatePct, 8, '.', '')) ?>">
          <svg viewBox="0 0 120 120" aria-hidden="true">
            <circle class="finance-ring-track" cx="60" cy="60" r="52"></circle>
            <circle class="finance-ring-value" cx="60" cy="60" r="52"></circle>
          </svg>
          <span><strong><?= $financeLate ?></strong><small>étudiants</small></span>
        </div>
        <h2>Paiements en retard</h2>
        <p><?= $financeLatePctLabel ?> % des étudiants sous <?= e(institution_money(institution_fee_amount())) ?></p>
      </article>
    </div>
  </div>
  <?php else: ?>
  <div class="dashboard-content-hint">
    <p class="mb-0 text-center">Sélectionnez un module dans le menu pour commencer.</p>
  </div>
  <?php endif; ?>
  <div class="app-skeleton-stage" data-module-skeleton hidden aria-busy="true" aria-label="Chargement du module">
    <span class="sk sk-line is-mid"></span>
    <span class="sk sk-line is-short"></span>
    <div class="sk-card">
      <span class="sk sk-line"></span>
      <span class="sk sk-line"></span>
      <span class="sk sk-line is-short"></span>
      <span class="sk sk-btn"></span>
    </div>
    <div class="sk-card">
      <span class="sk sk-line"></span>
      <span class="sk sk-line is-mid"></span>
    </div>
  </div>
  <iframe name="dashboard-content" title="Contenu du module" hidden></iframe>
</section>
</div>
<?php
// Keep the home panel able to open modules in-panel when menu items are clicked.
if (is_admin() || is_teacher() || is_finance() || is_scolarite()): ?>
<script>
(function () {
  const panel = document.getElementById('dashboard-content');
  if (!panel) return;
  let frame = panel.querySelector('iframe[name="dashboard-content"]');
  if (!frame) frame = document.querySelector('iframe[name="dashboard-content"]');
  if (!frame) return;
  if (frame.parentElement !== panel) panel.appendChild(frame);
  function modulePieces() {
    return panel.querySelectorAll(':scope > :not(iframe)');
  }
  const skeleton = panel.querySelector('[data-module-skeleton]');
  function showModule() {
    modulePieces().forEach(function (piece) {
      if (piece === skeleton) return;
      piece.hidden = true;
    });
    if (skeleton) skeleton.hidden = false;
    frame.hidden = true;
    frame.classList.remove('is-ready');
    document.body.classList.add('module-open');
  }
  function revealModule() {
    if (skeleton) skeleton.hidden = true;
    frame.hidden = false;
    frame.classList.add('is-ready');
  }
  frame.addEventListener('load', function () {
    if (!frame.getAttribute('src')) return;
    revealModule();
  });
  function showHome() {
    modulePieces().forEach(function (piece) {
      if (piece === skeleton) {
        piece.hidden = true;
        return;
      }
      piece.hidden = false;
    });
    frame.hidden = true;
    frame.classList.remove('is-ready');
    frame.removeAttribute('src');
    document.body.classList.remove('module-open');
  }
  document.addEventListener('click', function (event) {
    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('data-accounts-toggle') || link.hasAttribute('download') || link.hasAttribute('data-file-download')) return;
    if (link.getAttribute('target') === '_blank') return;
    if (link.hasAttribute('data-dashboard-home')) {
      event.preventDefault();
      showHome();
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    const opensModule = link.getAttribute('target') === 'dashboard-content'
      || (link.getAttribute('href') || '').indexOf('embed=1') !== -1
      || (!!link.closest('.macos-dock') && (link.getAttribute('href') || '').indexOf('index.php') === -1);
    if (!opensModule || !frame) return;
    if ((link.getAttribute('href') || '').indexOf('logout.php') !== -1) return;
    event.preventDefault();
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return;
    url.searchParams.set('embed', '1');
    var current = '';
    try { current = frame.contentWindow.location.href; } catch (error) { current = frame.getAttribute('src') || ''; }
    if (current && new URL(current, window.location.href).href === url.href) {
      modulePieces().forEach(function (piece) {
        if (piece !== skeleton) piece.hidden = true;
      });
      revealModule();
      document.body.classList.add('module-open');
      return;
    }
    frame.src = url.href;
    showModule();
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
<?php if (is_admin()): ?>
<script>
(function () {
  const item = document.querySelector('.dashboard-menu-item[href^="demandes_presence.php"]');
  const studentItem = document.querySelector('.dashboard-menu-item[href^="demandes_etudiants.php"]');
  if (!item && !studentItem) return;
  let stamp = '';
  function paint(target, count) {
    if (!target) return;
    const row = target.querySelector('.dashboard-menu-title-row') || target.querySelector('.dashboard-menu-copy strong');
    if (!row) return;
    let badge = row.querySelector('.dashboard-alert-count');
    if (count > 0) {
      target.setAttribute('data-alert-count', String(count));
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'dashboard-alert-count';
        row.appendChild(badge);
      }
      badge.setAttribute('aria-label', String(count) + ' en attente');
      badge.textContent = String(count);
    } else {
      target.removeAttribute('data-alert-count');
      if (badge) badge.remove();
    }
  }
  function tickStudent() {
    if (!studentItem) return;
    fetch('demandes_etudiants_live.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data) return;
        paint(studentItem, Number(data.count) || 0);
      })
      .catch(function () {});
  }
  function tick() {
    tickStudent();
    if (!item) return;
    fetch('attestation_live.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data) return;
        paint(item, Number(data.count) || 0);
        if (stamp && data.stamp !== stamp) {
          const frame = document.querySelector('iframe[name="dashboard-content"]');
          try {
            if (frame && !frame.hidden && frame.contentWindow && frame.contentWindow.attestationLiveReload) {
              frame.contentWindow.attestationLiveReload(data);
            }
          } catch (error) {}
        }
        stamp = String(data.stamp || '');
      })
      .catch(function () {});
  }
  tick();
  setInterval(tick, 2000);
})();
</script>
<?php endif; ?>
<script>
(function () {
  var workspace = document.querySelector('.dashboard-workspace');
  var toggle = document.querySelector('[data-clarity-nav-toggle]');
  var edge = document.querySelector('[data-clarity-nav-edge]');
  if (!workspace || !toggle) return;
  var key = 'clarity-nav-collapsed';
  function collapsed() { return workspace.classList.contains('is-nav-collapsed'); }
  function setCollapsed(on) {
    workspace.classList.toggle('is-nav-collapsed', on);
    toggle.setAttribute('aria-expanded', on ? 'false' : 'true');
    var label = on ? 'Ouvrir la navigation' : 'Réduire la navigation';
    toggle.title = label;
    var hidden = toggle.querySelector('.visually-hidden');
    if (hidden) hidden.textContent = label;
    try { localStorage.setItem(key, on ? '1' : '0'); } catch (error) {}
  }
  try { setCollapsed(localStorage.getItem(key) !== '0'); } catch (error) { setCollapsed(true); }
  toggle.addEventListener('click', function () { setCollapsed(!collapsed()); });
  var startX = 0;
  var startY = 0;
  var tracking = false;
  function begin(event) {
    if (!document.body.classList.contains('theme-clarity')) return;
    if (event.button !== undefined && event.button !== 0) return;
    tracking = true;
    startX = event.clientX;
    startY = event.clientY;
  }
  if (edge) edge.addEventListener('pointerdown', begin);
  workspace.addEventListener('pointerdown', function (event) {
    if (!document.body.classList.contains('theme-clarity') || tracking) return;
    var menu = workspace.querySelector('.dashboard-menu');
    if (!menu) return;
    var rect = menu.getBoundingClientRect();
    var inside = event.clientX >= rect.left && event.clientX <= rect.right;
    if (!inside) return;
    if (!collapsed() && event.target.closest('a, button, input, select, textarea')) return;
    begin(event);
  });
  window.addEventListener('pointerup', function (event) {
    if (!tracking) return;
    tracking = false;
    var dx = event.clientX - startX;
    var dy = event.clientY - startY;
    if (Math.abs(dx) < 56 || Math.abs(dx) < Math.abs(dy) * 1.2) return;
    setCollapsed(dx < 0);
  });
  window.addEventListener('pointercancel', function () { tracking = false; });
})();
</script>
<script src="assets/dashboard-editor.js?v=5" defer></script>
<?php page_foot(); ?>

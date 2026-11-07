<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/student_theme.php');
require_once app_path('support/emploi_pdf.php');
require_once app_path('support/course_library.php');
require_once app_path('support/course_explorer_view.php');
require_once app_path('support/student_portal.php');
require_once app_path('support/stage_service.php');

/**
 * Ferme une session qui n'est pas une session étudiante valide.
 * Un compte personnel (admin, enseignant, finance, stage) est renvoyé vers son espace
 * sans être détruit : ouvrir cette page par erreur ne doit pas le déconnecter.
 */
function dashboard_refuse_session(): never
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: connexion_etudiant.php');
    exit;
}

function dashboard_heure(?string $time): string
{
    if ($time === null || $time === '') {
        return '';
    }

    return e(substr($time, 0, 5));
}

function dashboard_note(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return e(number_format((float) $value, 2, ',', ' '));
}

function dashboard_course_link(string $link): bool
{
    return preg_match('/^dashboard\.php\?panel=cours(?:&cours=\d{1,10}(?:&dossier=\d{1,10})?)?\z/', $link) === 1;
}

/**
 * @param array<int, array<string, mixed>> $folders
 */
function dashboard_folder_in_tree(array $folders, int $folderId): bool
{
    foreach ($folders as $folder) {
        if ((int) ($folder['id'] ?? 0) === $folderId
            || dashboard_folder_in_tree((array) ($folder['folders'] ?? []), $folderId)) {
            return true;
        }
    }

    return false;
}

// Étape 1 — La session doit porter l'identifiant de l'étudiant connecté.
$studentId = filter_var($_SESSION['id_etudiant'] ?? null, FILTER_VALIDATE_INT);
if ($studentId === false || $studentId <= 0 || !is_student()) {
    dashboard_refuse_session();
}

$profile = null;
$timetable = [];
$grades = [];
$emploiPdfs = [];
$courses = [];
$dbError = '';
$attestationOpen = false;
$attestationCin = '';
$attestationMotif = '';
$attestationQueued = false;
$attestationBoard = ['state' => 'none', 'message' => ''];
$inbox = ['unread' => 0, 'stamp' => '0', 'messages' => []];
$portalPlan = [];
$portalAbsences = [];
$portalGroup = null;
$portalTargets = [];
$portalSwapGroups = [];
$portalPartners = [];
$portalContacts = [];
$portalMine = ['groups' => [], 'appointments' => [], 'complaints' => []];
$summerBoard = ['open' => [], 'mine' => []];
$explorerFragment = false;

try {
    $pdo = db();
    ensure_etudiant_compte_table($pdo);

    // Étape 2 — Profil : le compte doit rester actif et lié à un dossier inscrit.
    $profileQuery = $pdo->prepare(
        'SELECT e.nom, e.prenom, e.niveau, e.email, e.cin_passeport, e.id_spec, e.num_tel, e.adresse,
                e.date_naissance, e.nationalite, e.lieu_naissance, s.nom_spec
         FROM etudiant e
         INNER JOIN specialite s ON s.id_spec = e.id_spec
         INNER JOIN etudiant_compte c ON c.id_etudiant = e.id_etudiant AND c.active = 1
         WHERE e.id_etudiant = ?
         LIMIT 1'
    );
    $profileQuery->execute([$studentId]);
    $profile = $profileQuery->fetch() ?: null;
    if ($profile === null) {
        dashboard_refuse_session();
    }

    $explorerFragment = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && (string) ($_GET['fragment'] ?? '') === 'explorer';

    if ($explorerFragment) {
        try {
            course_library_ensure($pdo);
            $courses = course_tree_for_student((int) $studentId);
        } catch (Throwable $exception) {
            $courses = [];
            error_log('dashboard: course library unavailable: ' . $exception->getMessage());
        }
    } else {
    $inbox = student_notifications_payload($pdo, $studentId);
    $attestationOpen = attestation_feature_active($pdo);
    $attestationCin = '';
    $attestationMotif = '';
    if ($attestationOpen && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'demande_attestation') {
        verify_csrf();
        $attestationCin = trim((string) ($_POST['cin'] ?? ''));
        $attestationMotif = trim((string) ($_POST['motif'] ?? ''));
        if ($attestationCin === '' || $attestationMotif === '') {
            flash('danger', 'Indiquez votre CIN et le motif de la demande.');
        } elseif (mb_strlen($attestationMotif) > 500) {
            flash('danger', 'Le motif ne peut pas dépasser 500 caractères.');
        } elseif (!hash_equals((string) $profile['cin_passeport'], $attestationCin)) {
            flash('danger', 'Le CIN saisi ne correspond pas à votre dossier.');
        } else {
            try {
                $existing = $pdo->prepare(
                    'SELECT statut FROM demande_attestation WHERE id_etudiant = ? ORDER BY date_demande DESC LIMIT 1'
                );
                $existing->execute([$studentId]);
                $current = $existing->fetch();
                if ($current) {
                    $board = student_attestation_board($pdo, $studentId);
                    flash('danger', $board['message'] !== '' ? $board['message'] : 'Déjà envoyée et en file d’attente.');
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO demande_attestation (id_etudiant, cin, motif, statut) VALUES (?, ?, ?, "en_attente")'
                    );
                    $insert->execute([$studentId, $attestationCin, $attestationMotif]);
                    flash('success', 'Votre demande a été envoyée avec succès');
                    header('Location: dashboard.php');
                    exit;
                }
            } catch (PDOException $exception) {
                flash('danger', 'Erreur lors de la récupération des données');
            }
        }
    }

    student_portal_ensure($pdo);
    $portalAction = (string) ($_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with($portalAction, 'portal_')) {
        verify_csrf();
        $portalError = match ($portalAction) {
            'portal_profile' => student_portal_update_profile($pdo, $studentId, trim((string) ($_POST['email'] ?? '')), trim((string) ($_POST['telephone'] ?? '')), trim((string) ($_POST['adresse'] ?? '')), (array) ($_FILES['photo'] ?? [])),
            'portal_group' => student_portal_submit_group($pdo, $studentId, (int) $profile['id_spec'], (string) ($_POST['request_type'] ?? ''), (int) ($_POST['id_groupe'] ?? 0), (int) ($_POST['id_partenaire'] ?? 0), trim((string) ($_POST['commentaire'] ?? ''))),
            'portal_psy' => student_portal_submit_appointment($pdo, $studentId, (string) ($_POST['jour'] ?? ''), (string) ($_POST['heure'] ?? ''), trim((string) ($_POST['motif'] ?? ''))),
            'portal_complaint' => student_portal_submit_complaint($pdo, $studentId, (string) ($_POST['categorie'] ?? ''), trim((string) ($_POST['description'] ?? ''))),
            'portal_stage' => stage_student_submit_summer($studentId),
            default => 'Action inconnue.',
        };
        $back = match ($portalAction) {
            'portal_profile' => 'profil',
            'portal_group' => 'groupe',
            'portal_psy' => 'psy',
            'portal_stage' => 'stage',
            default => 'reclamation',
        };
        $portalOk = $portalAction === 'portal_stage' ? 'Votre demande est envoyée.' : 'Enregistré.';
        flash($portalError === '' ? 'success' : 'danger', $portalError === '' ? $portalOk : $portalError);
        header('Location: dashboard.php?panel=' . $back);
        exit;
    }
    $attestationBoard = student_attestation_board($pdo, $studentId);
    $attestationQueued = $attestationBoard['state'] !== 'none';

    // Étape 3 — Une seule semaine type, limitée aux semestres du niveau.
    // Le groupe TD conserve un emploi différent chaque semaine ; tout afficher
    // produisait plus de 200 lignes dès l'ouverture de la session.
    $levelSemesters = study_level_periods((string) $profile['niveau']);
    if ($levelSemesters === []) {
        $levelSemesters = ['S1', 'S2'];
    }
    $semesterMarks = implode(',', array_fill(0, count($levelSemesters), '?'));
    $timetableQuery = $pdo->prepare(
        'SELECT et.jour, et.heure_debut, et.heure_fin, et.type_seance,
                m.code_ecue, m.description_ecue,
                ens.prenom AS prof_prenom, ens.nom AS prof_nom,
                g.semestre
         FROM groupe_etudiant ge
         INNER JOIN emploi_temps et ON et.id_groupe = ge.id_groupe AND et.semaine = 1
         INNER JOIN matiere m ON m.id_mat = et.id_mat
         INNER JOIN enseignant ens ON ens.id_enseignant = et.id_enseignant
         INNER JOIN groupe g ON g.id_groupe = et.id_groupe
         WHERE ge.id_etudiant = ? AND g.semestre IN (' . $semesterMarks . ')
         GROUP BY et.jour, et.heure_debut, et.heure_fin, et.type_seance,
                  m.id_mat, m.code_ecue, m.description_ecue,
                  ens.id_enseignant, ens.prenom, ens.nom,
                  g.semestre
         ORDER BY FIELD(g.semestre, "S1", "S2", "S3", "S4", "S5", "S6"),
                  FIELD(et.jour, "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi"),
                  et.heure_debut, et.heure_fin, m.code_ecue'
    );
    $timetableQuery->execute(array_merge([$studentId], $levelSemesters));
    $timetable = $timetableQuery->fetchAll();

    // Étape 4 — Notes du dossier, avec la description ECUE de chaque matière.
    $gradesQuery = $pdo->prepare(
        'SELECT m.code_ecue, m.description_ecue, m.semestre, m.volume_tp,
                n.note, n.note_cours, n.note_td, n.note_tp, n.note_rattrapage
         FROM note n
         INNER JOIN matiere m ON m.id_mat = n.id_mat
         WHERE n.id_etudiant = ?
         ORDER BY FIELD(m.semestre, "S1", "S2", "S3", "S4", "S5", "S6"), m.code_ecue'
    );
    $gradesQuery->execute([$studentId]);
    $grades = $gradesQuery->fetchAll();
    $emploiPdfs = emploi_pdf_for_student($studentId);
    $portalPlan = student_portal_plan($pdo, $studentId);
    $portalAbsences = student_portal_absences($pdo, $studentId);
    $portalGroup = student_portal_current_group($pdo, $studentId);
    $portalTargets = $portalGroup ? student_portal_target_groups($pdo, $studentId, (int) $profile['id_spec'], (int) $portalGroup['id_groupe'], true) : [];
    $portalSwapGroups = $portalGroup ? student_portal_target_groups($pdo, $studentId, (int) $profile['id_spec'], (int) $portalGroup['id_groupe'], false) : [];
    $portalPartners = $portalGroup ? student_portal_swap_students($pdo, array_column($portalSwapGroups, 'id_groupe'), (int) $profile['id_spec'], $studentId) : [];
    $portalContacts = student_portal_contacts($pdo, $studentId);
    $portalMine = student_portal_requests($pdo, $studentId);
    try {
        $summerBoard = stage_student_summer_board((int) $studentId);
    } catch (Throwable $exception) {
        error_log('dashboard: summer stage unavailable: ' . $exception->getMessage());
    }

    // Étape 5 — Cours publiés : une panne de la bibliothèque ne doit pas bloquer l’espace.
    try {
        course_library_ensure($pdo);
        $courses = course_tree_for_student((int) $studentId);
    } catch (Throwable $exception) {
        $courses = [];
        error_log('dashboard: course library unavailable: ' . $exception->getMessage());
    }
    } // requêtes hors fragment explorateur
} catch (PDOException $exception) {
    $dbError = 'Erreur lors de la récupération des données';
}

$studentName = trim((string) (($profile['prenom'] ?? '') . ' ' . ($profile['nom'] ?? '')));
$studentInitials = mb_strtoupper(mb_substr((string) ($profile['prenom'] ?? ''), 0, 1) . mb_substr((string) ($profile['nom'] ?? ''), 0, 1));
if ($studentInitials === '') {
    $studentInitials = 'ET';
}
$studentPhoto = '';
try {
    $studentPhoto = student_photo_src(db(), (int) $studentId);
} catch (Throwable $exception) {
    $studentPhoto = '';
}
$timetableBySemester = [];
foreach ($timetable as $session) {
    $timetableBySemester[(string) $session['semestre']][(string) $session['jour']][] = $session;
}
$gradesBySemester = [];
foreach ($grades as $grade) {
    $gradesBySemester[(string) $grade['semestre']][] = $grade;
}
$panels = ['profil', 'emploi', 'cours', 'notes', 'plan', 'absences', 'groupe', 'psy', 'contacts', 'reclamation', 'stage'];
if ($attestationOpen) {
    $panels[] = 'attestation';
}
$requestedPanel = is_string($_GET['panel'] ?? null) ? $_GET['panel'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'demande_attestation' && $attestationOpen) {
    $openPanel = 'attestation';
} elseif (in_array($requestedPanel, $panels, true)) {
    $openPanel = $requestedPanel;
} else {
    $openPanel = 'profil';
}
if (!empty($explorerFragment)) {
    $openPanel = 'cours';
}

// Lien profond : ?panel=cours&cours=<id>&dossier=<id>. Un identifiant non visible est ignoré.
$courseState = [
    'course' => null,
    'folder' => null,
    'base_url' => 'dashboard.php?panel=cours',
    'download_suffix' => '&espace=etudiant',
];
if ($openPanel === 'cours' && $courses !== []) {
    $visibleCourses = [];
    foreach ($courses as $course) {
        $visibleCourses[(int) $course['id']] = $course;
    }
    $wantedCourse = filter_var($_GET['cours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $wantedFolder = filter_var($_GET['dossier'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (is_int($wantedCourse) && isset($visibleCourses[$wantedCourse])) {
        $courseState['course'] = $wantedCourse;
    }
    if (is_int($wantedFolder)) {
        try {
            $folderAllowed = course_student_may_access_folder((int) $studentId, $wantedFolder);
        } catch (Throwable $exception) {
            $folderAllowed = false;
            error_log('dashboard: course folder check failed: ' . $exception->getMessage());
        }
        if ($folderAllowed) {
            $candidates = $courseState['course'] !== null
                ? [$courseState['course'] => $visibleCourses[$courseState['course']]]
                : $visibleCourses;
            foreach ($candidates as $candidateId => $candidate) {
                if (dashboard_folder_in_tree((array) ($candidate['folders'] ?? []), $wantedFolder)) {
                    $courseState['course'] = $candidateId;
                    $courseState['folder'] = $wantedFolder;
                    break;
                }
            }
            if ($courseState['folder'] === null && isset($candidates[$wantedFolder])) {
                $courseState['course'] = $wantedFolder;
            }
        }
    }
}
$courseCount = count($courses);
if (!empty($explorerFragment)) {
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    course_explorer_render($courses, 'student', $courseState);
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php student_theme_head(); ?>
  <title>Espace étudiant | <?= e(institution_name_inline()) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,650&family=Outfit:wght@400;600;700&family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/theme-tokens.css?v=1" rel="stylesheet">
  <link href="assets/motion.css?v=3" rel="stylesheet">
  <link href="assets/student-space.css?v=30" rel="stylesheet">
  <link href="assets/student-portal-shell.css?v=16" rel="stylesheet">
  <link href="assets/student-theme.css?v=18" rel="stylesheet">
  <link href="assets/student-lightswind.css?v=3" rel="stylesheet">
  <link href="assets/lw-theme-toggle.css?v=1" rel="stylesheet">
  <link href="assets/skeleton.css?v=3" rel="stylesheet">
  <link href="assets/success-lottie.css?v=3" rel="stylesheet">
  <link href="assets/course-explorer.css?v=2" rel="stylesheet">
  <script src="assets/course-explorer.js?v=2" defer></script>
</head>
<body class="student-space">
  <div class="portal-stage">
  <div class="portal-phone">
  <header class="student-space-bar">
    <button type="button" class="portal-menu-btn" data-portal-open aria-controls="portalDrawer" aria-expanded="false" aria-label="Ouvrir le menu">
      <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <img src="<?= e(institution_logo_src()) ?>" alt="Logo de l'école">
    <div>
      <p><?= e(institution_name_inline()) ?></p>
      <strong><?= e($studentName !== '' ? $studentName : 'Étudiant') ?></strong>
    </div>
    <div class="student-space-bar-actions">
      <?php student_theme_select(); ?>
      <div class="dropdown student-inbox">
        <button class="student-inbox-toggle dropdown-toggle" type="button" id="studentInboxToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Boîte de réception">
          <i class="bi bi-bell-fill" aria-hidden="true"></i>
          <span class="student-inbox-badge" id="studentInboxBadge"<?= ((int) $inbox['unread'] > 0) ? '' : ' hidden' ?>><?= (int) $inbox['unread'] > 99 ? '99+' : (int) $inbox['unread'] ?></span>
        </button>
        <div class="dropdown-menu dropdown-menu-end student-inbox-menu" aria-labelledby="studentInboxToggle">
          <div class="student-inbox-head">
            <p class="student-inbox-title">Messages</p>
            <button type="button" class="student-inbox-clear" id="studentInboxClear"<?= $inbox['messages'] ? '' : ' hidden' ?>>Effacer tout</button>
          </div>
          <p class="student-inbox-error" id="studentInboxError" hidden role="alert"></p>
          <div id="studentInboxList">
            <?php if (!$inbox['messages']): ?>
              <p class="student-inbox-empty">No notifications yet</p>
            <?php else: ?>
              <?php foreach ($inbox['messages'] as $note): ?>
                <?php $noteLink = is_string($note['link'] ?? null) ? $note['link'] : ''; ?>
                <div class="student-inbox-row" data-notif-id="<?= (int) $note['id'] ?>">
                  <button type="button" class="student-inbox-delete" data-notif-delete="<?= (int) $note['id'] ?>" aria-label="Supprimer ce message"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M8 7l1 13h6l1-13"/></svg><span>Supprimer</span></button>
                  <button type="button" class="student-inbox-item<?= ((int) $note['is_read'] === 1) ? ' is-read' : ' is-unread' ?>" data-notif-id="<?= (int) $note['id'] ?>"<?= $noteLink !== '' ? ' data-notif-link="' . e($noteLink) . '"' : '' ?>>
                    <strong><?php if (dashboard_course_link($noteLink)): ?><i class="bi bi-folder2-open me-1" aria-hidden="true"></i><?php endif; ?><?= e($note['sujet']) ?></strong>
                    <span><?= e($note['date']) ?></span>
                  </button>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </header>
  <div class="portal-edge" data-portal-edge aria-hidden="true"></div>
  <div class="portal-backdrop" data-portal-close></div>
  <nav class="portal-drawer" id="portalDrawer" aria-label="Navigation étudiante" aria-hidden="true" inert>
    <div class="portal-drawer-head">
      <span class="lw-avatar" aria-hidden="true">
        <?php if ($studentPhoto !== ''): ?>
          <img src="<?= e($studentPhoto) ?>" alt="">
        <?php else: ?>
          <span class="lw-avatar-fallback"><?= e($studentInitials) ?></span>
        <?php endif; ?>
      </span>
      <div class="student-bar-identity">
        <div>
          <p class="portal-drawer-kicker">Espace étudiant</p>
          <strong class="portal-drawer-name"><?= e($studentName !== '' ? $studentName : 'Étudiant') ?></strong>
        </div>
        <img class="student-bar-logo" src="<?= e(institution_logo_src()) ?>" alt="" width="28" height="28">
      </div>
    </div>
    <button type="button" data-panel="profil"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3"/><path d="M6 19c1-3 3-4.5 6-4.5s5 1.5 6 4.5"/></svg><span>Profil</span></button>
    <button type="button" data-panel="emploi"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3.5V7M16 3.5V7M4 10h16"/></svg><span>Emploi du temps</span></button>
    <button type="button" data-panel="cours"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h8l3 3v13H7z"/><path d="M15 4v3h3"/></svg><span>Cours</span></button>
    <button type="button" data-panel="plan"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h12v16H6z"/><path d="M9 8h6M9 12h6"/></svg><span>Plan d’étude</span></button>
    <button type="button" data-panel="absences"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v16H8z"/><path d="M10 15l2 2 3-4"/></svg><span>Absences</span></button>
    <button type="button" data-panel="notes"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v16H8z"/><path d="M10 9h4M10 13h4"/></svg><span>Résultats et suivi</span></button>
    <button type="button" data-panel="groupe"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="9" r="2.4"/><circle cx="16" cy="9" r="2.4"/><path d="M4 18c.6-2 2-3 4-3s3.4 1 4 3M12 18c.6-2 2-3 4-3s3.4 1 4 3"/></svg><span>Gestion des demandes</span></button>
    <button type="button" data-panel="psy"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg><span>Psychologue</span></button>
    <button type="button" data-panel="reclamation"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5M12 16h.01"/></svg><span>Réclamation</span></button>
    <button type="button" data-panel="contacts"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg><span>Contacts et e-mails</span></button>
    <?php if ($attestationOpen): ?>
    <button type="button" data-panel="attestation"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10v16l-5-2.4L7 20z"/></svg><span>Demandes</span></button>
    <?php endif; ?>
    <button type="button" data-panel="stage"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19V9l7-4 7 4v10"/><path d="M9 19v-5h6v5"/></svg><span>Stage d’été</span></button>
    <a class="portal-logout" href="logout.php?espace=etudiant">Déconnexion</a>
  </nav>
  <p class="visually-hidden" id="studentLive" aria-live="polite"></p>
  <main class="student-space-main">
    <?php if ($dbError !== ''): ?><div class="student-space-alert" role="alert"><?= e($dbError) ?></div><?php endif; ?>
    <?php flash_show(); ?>
    <div class="student-space-cards" role="tablist">
      <button type="button" data-panel="profil" aria-pressed="<?= $openPanel === 'profil' ? 'true' : 'false' ?>">
        <span>01</span>
        <strong>Profil</strong>
        <small><?= $profile ? 'Niveau ' . e(level_label((string) $profile['niveau'])) : 'Identité' ?></small>
      </button>
      <button type="button" data-panel="emploi" aria-pressed="<?= $openPanel === 'emploi' ? 'true' : 'false' ?>">
        <span>02</span>
        <strong>Emploi du temps</strong>
        <small><?= count($emploiPdfs) > 0 ? (count($emploiPdfs) . ' PDF') : (count($timetable) . ' séance' . (count($timetable) > 1 ? 's' : '')) ?></small>
      </button>
      <button type="button" data-panel="cours" aria-pressed="<?= $openPanel === 'cours' ? 'true' : 'false' ?>">
        <span>03</span>
        <strong>Cours</strong>
        <small><?= $courseCount > 0 ? $courseCount . ' cours' : 'Aucun cours' ?></small>
      </button>
      <button type="button" data-panel="notes" aria-pressed="<?= $openPanel === 'notes' ? 'true' : 'false' ?>">
        <span>04</span>
        <strong>Résultats et suivi</strong>
        <small><?= count($grades) ?> matière<?= count($grades) > 1 ? 's' : '' ?></small>
      </button>
      <button type="button" data-panel="plan" aria-pressed="<?= $openPanel === 'plan' ? 'true' : 'false' ?>">
        <span>05</span>
        <strong>Plan d’étude</strong>
        <small><?= count($portalPlan) ?> matière<?= count($portalPlan) > 1 ? 's' : '' ?></small>
      </button>
      <button type="button" data-panel="absences" aria-pressed="<?= $openPanel === 'absences' ? 'true' : 'false' ?>">
        <span>06</span>
        <strong>Absences</strong>
        <small><?php $portalMissed = 0.0; foreach ($portalAbsences as $portalAbsence) { $portalMissed += (float) $portalAbsence['duree_heures']; } ?><?= e(number_format($portalMissed, 1, ',', ' ')) ?> h</small>
      </button>
      <button type="button" data-panel="groupe" aria-pressed="<?= $openPanel === 'groupe' ? 'true' : 'false' ?>">
        <span>07</span>
        <strong>Gestion des demandes</strong>
        <small><?= $portalGroup ? e($portalGroup['type_groupe'] . ' ' . $portalGroup['numero']) : 'Groupe' ?></small>
      </button>
      <button type="button" data-panel="psy" aria-pressed="<?= $openPanel === 'psy' ? 'true' : 'false' ?>">
        <span>08</span>
        <strong>Psychologue</strong>
        <small>Rendez-vous</small>
      </button>
      <button type="button" data-panel="reclamation" aria-pressed="<?= $openPanel === 'reclamation' ? 'true' : 'false' ?>">
        <span>09</span>
        <strong>Réclamation</strong>
        <small><?= count($portalMine['complaints']) ?> envoyée<?= count($portalMine['complaints']) > 1 ? 's' : '' ?></small>
      </button>
      <button type="button" data-panel="contacts" aria-pressed="<?= $openPanel === 'contacts' ? 'true' : 'false' ?>">
        <span>10</span>
        <strong>Contacts et e-mails</strong>
        <small><?= count($portalContacts) ?> enseignant<?= count($portalContacts) > 1 ? 's' : '' ?></small>
      </button>
      <button type="button" data-panel="stage" aria-pressed="<?= $openPanel === 'stage' ? 'true' : 'false' ?>">
        <span>11</span>
        <strong>Stage d’été</strong>
        <small><?= count($summerBoard['mine']) ?> demande<?= count($summerBoard['mine']) > 1 ? 's' : '' ?></small>
      </button>
      <?php if ($attestationOpen): ?>
      <button type="button" data-panel="attestation" aria-pressed="<?= $openPanel === 'attestation' ? 'true' : 'false' ?>">
        <span>12</span>
        <strong>Attestation</strong>
        <small>Demande de présence</small>
      </button>
      <?php endif; ?>
    </div>

    <section class="student-space-sheet<?= $openPanel === 'profil' ? ' is-entering' : '' ?>" data-sheet="profil"<?= $openPanel === 'profil' ? '' : ' hidden' ?>>
      <h1>Profil</h1>
      <?php if ($profile !== null): ?>
        <?php
          $publishedStages = stage_student_published((int) $studentId);
          $profileSlides = [
            ['Nom', (string) $profile['nom']],
            ['Prénom', (string) $profile['prenom']],
            ['Niveau', level_label((string) $profile['niveau']) . ' année'],
            ['E-mail', (string) $profile['email']],
            ['Téléphone', (string) ($profile['num_tel'] ?? '')],
            ['Adresse', (string) ($profile['adresse'] ?? '')],
            ['Spécialité', (string) ($profile['nom_spec'] ?? '')],
            ['Date de naissance', (string) ($profile['date_naissance'] ?? '')],
            ['Nationalité', (string) ($profile['nationalite'] ?? '')],
            ['Lieu de naissance', (string) ($profile['lieu_naissance'] ?? '')],
          ];
        ?>
        <p class="student-space-lead student-profile-hint">Glissez horizontalement pour parcourir les infos.</p>
        <div class="student-profile-rail" role="list" aria-label="Informations du profil">
          <?php foreach ($profileSlides as $slide): ?>
            <article class="student-profile-slide" role="listitem">
              <p class="student-profile-label"><?= e($slide[0]) ?></p>
              <p class="student-profile-value"><?= e($slide[1] !== '' ? $slide[1] : '—') ?></p>
            </article>
          <?php endforeach; ?>
          <article class="student-profile-slide student-profile-slide-wide" role="listitem">
            <p class="student-profile-label">Stage publié</p>
            <div class="student-profile-value">
              <?php if (!$publishedStages): ?>
                <p>Aucun stage publié.</p>
              <?php else: foreach ($publishedStages as $stage): ?>
                <p><?= e($stage['organisme']) ?><?= $stage['service'] ? ' · ' . e((string) $stage['service']) : '' ?> · <?= e($stage['date_debut'] . ' → ' . $stage['date_fin']) ?> · <?= e(stage_statuses()[(string) $stage['statut']] ?? (string) $stage['statut']) ?> · <a href="stage_dossier.php?id=<?= (int) $stage['id_placement'] ?>&amp;espace=etudiant">Dossier documentaire du stage</a></p>
              <?php endforeach; endif; ?>
              <p><a href="dashboard.php?panel=stage">Demande de stage d’été</a></p>
            </div>
          </article>
        </div>
        <form method="post" enctype="multipart/form-data" class="student-space-form" data-profile-form>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="portal_profile">
          <label class="student-photo-field">Photo de profil
            <span class="lw-avatar lw-avatar-lg" data-photo-preview>
              <?php if ($studentPhoto !== ''): ?>
                <img src="<?= e($studentPhoto) ?>" alt="">
              <?php else: ?>
                <span class="lw-avatar-fallback"><?= e($studentInitials) ?></span>
              <?php endif; ?>
            </span>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-photo-input>
          </label>
          <div class="student-photo-crop" data-photo-crop hidden>
            <p class="student-space-lead">Glissez et redimensionnez. Seul l’intérieur du cercle est enregistré.</p>
            <div class="student-photo-stage" data-photo-stage>
              <img alt="" data-photo-image draggable="false">
              <span class="student-photo-ring" aria-hidden="true"></span>
            </div>
            <label>Taille
              <input type="range" min="1" max="3" step="0.01" value="1" data-photo-zoom>
            </label>
            <button type="button" data-photo-apply>Appliquer le cadre</button>
          </div>
          <label>E-mail<input type="email" name="email" value="<?= e((string) $profile['email']) ?>" required></label>
          <label>Téléphone<input name="telephone" value="<?= e((string) $profile['num_tel']) ?>" required></label>
          <label>Adresse<textarea name="adresse" required><?= e((string) $profile['adresse']) ?></textarea></label>
          <button type="submit">Enregistrer les coordonnées</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="student-space-sheet<?= $openPanel === 'emploi' ? ' is-entering' : '' ?>" data-sheet="emploi"<?= $openPanel === 'emploi' ? '' : ' hidden' ?>>
      <h1>Emploi du temps</h1>
      <?php if ($emploiPdfs !== []): ?>
        <p class="student-space-lead">PDF publié pour votre groupe. Vous pouvez l’ouvrir en aperçu ou le télécharger.</p>
        <?php foreach ($emploiPdfs as $pdf): ?>
          <?php $groupLabel = $pdf['semestre'] . ' · ' . $pdf['type_groupe'] . ' ' . $pdf['numero']; ?>
          <article class="student-space-table-wrap" style="margin-bottom:1.25rem;padding:1rem;">
            <h2 style="margin-top:0;"><?= e($groupLabel) ?></h2>
            <p class="student-space-lead"><?= e((string) $pdf['original_name']) ?></p>
            <div style="display:flex;flex-wrap:wrap;gap:.6rem;">
              <a class="btn btn-primary btn-sm" href="emploi_pdf.php?id_groupe=<?= (int) $pdf['id_groupe'] ?>&amp;action=view&amp;espace=etudiant" data-course-preview data-course-preview-title="Emploi du temps <?= e($groupLabel) ?>">Aperçu PDF</a>
              <a class="btn btn-outline-primary btn-sm" href="emploi_pdf.php?id_groupe=<?= (int) $pdf['id_groupe'] ?>&amp;action=download&amp;espace=etudiant">Télécharger PDF</a>
            </div>
          </article>
        <?php endforeach; ?>
      <?php elseif ($timetableBySemester === []): ?>
        <p class="student-space-lead">Aucun emploi du temps PDF n’est encore publié pour vos groupes.</p>
      <?php else: ?>
        <p class="student-space-lead">Semaine type de votre niveau.</p>
        <?php foreach ($timetableBySemester as $semester => $days): ?>
          <h2><?= e($semester) ?></h2>
          <?php foreach ($days as $day => $sessions): ?>
            <h3><?= e($day) ?></h3>
            <div class="student-space-table-wrap">
              <table>
                <thead>
                  <tr><th>Heure</th><th>Matière</th><th>Professeur</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($sessions as $session): ?>
                    <tr>
                      <td><?= dashboard_heure((string) $session['heure_debut']) ?> – <?= dashboard_heure((string) $session['heure_fin']) ?></td>
                      <td><?= e((string) $session['code_ecue']) ?> <span class="student-unite-name">— <?= e((string) $session['description_ecue']) ?></span> <span>(<?= e((string) $session['type_seance']) ?>)</span></td>
                      <td><?= e(trim((string) $session['prof_prenom'] . ' ' . (string) $session['prof_nom'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <section class="student-space-sheet" data-sheet="plan"<?= $openPanel === 'plan' ? '' : ' hidden' ?>>
      <h1>Plan d’étude</h1>
      <p class="student-space-lead">Matières publiées dans l’emploi de vos groupes.</p>
      <?php if ($portalPlan === []): ?><p class="student-space-lead">Aucune matière n’est encore liée à vos groupes.</p><?php else: ?>
      <div class="student-space-table-wrap"><table><thead><tr><th>Semestre</th><th>Module</th><th>Matière</th><th>Enseignant</th><th>Coefficient</th><th>Charge</th></tr></thead><tbody>
      <?php $load = 0.0; $coeff = 0.0; foreach ($portalPlan as $row): $hours = (float) $row['volume_cours'] + (float) $row['volume_td'] + (float) $row['volume_tp']; $load += $hours; $coeff += (float) $row['coefficient_ecue']; ?>
        <tr><td><?= e((string) $row['semestre']) ?></td><td><?= e((string) $row['code_ue']) ?></td><td><?= e((string) $row['code_ecue'] . ' — ' . $row['description_ecue']) ?></td><td><?= e(implode(', ', $row['teachers'] ?? [])) ?></td><td><?= e((string) $row['coefficient_ecue']) ?></td><td><?= e(number_format($hours, 1, ',', ' ')) ?> h</td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <p class="student-space-lead">Charge totale <?= e(number_format($load, 1, ',', ' ')) ?> h · coefficient <?= e(number_format($coeff, 2, ',', ' ')) ?></p>
      <?php endif; ?>
    </section>
    <section class="student-space-sheet" data-sheet="absences"<?= $openPanel === 'absences' ? '' : ' hidden' ?>>
      <h1>Absences</h1>
      <?php $missed = 0.0; $modules = []; foreach ($portalAbsences as $absence) { $missed += (float) $absence['duree_heures']; $modules[(string) ($absence['code_ue'] ?? '')] = true; } ?>
      <p class="student-space-lead"><?= count($portalAbsences) ?> absence<?= count($portalAbsences) > 1 ? 's' : '' ?> · <?= e(number_format($missed, 2, ',', ' ')) ?> h manquées · <?= count(array_filter(array_keys($modules))) ?> module<?= count($modules) > 1 ? 's' : '' ?></p>
      <?php if ($portalAbsences === []): ?><p class="student-space-lead">Aucune absence enregistrée.</p><?php else: ?>
      <div class="student-space-table-wrap"><table><thead><tr><th>Date</th><th>Module</th><th>Matière</th><th>Enseignant</th><th>Heures</th><th>Type</th></tr></thead><tbody>
      <?php foreach ($portalAbsences as $absence): ?><tr><td><?= e((string) $absence['date_seance']) ?></td><td><?= e((string) ($absence['code_ue'] ?? '')) ?></td><td><?= e((string) ($absence['description_ecue'] ?? '')) ?></td><td><?= e(trim((string) ($absence['prof_prenom'] ?? '') . ' ' . (string) ($absence['prof_nom'] ?? ''))) ?></td><td><?= e((string) $absence['duree_heures']) ?></td><td><?= e((string) $absence['statut']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
    <section class="student-space-sheet" data-sheet="groupe"<?= $openPanel === 'groupe' ? '' : ' hidden' ?>>
      <h1>Groupe</h1>
      <p class="student-space-lead">Groupe actuel : <?= $portalGroup ? e($portalGroup['semestre'] . ' · ' . $portalGroup['type_groupe'] . ' ' . $portalGroup['numero']) : 'aucun' ?></p>
      <form method="post" class="student-space-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="portal_group">
        <label>Type<select name="request_type" data-group-type><option value="changement">Changement de groupe</option><option value="permutation">Permutation</option></select></label>
        <label>Groupe souhaité<select name="id_groupe" data-group-target required><option value="">Choisir</option><?php
          $portalGroupOptions = [];
          foreach ($portalTargets as $group) {
              $portalGroupOptions[(int) $group['id_groupe']] = $group + ['kind' => 'changement'];
          }
          foreach ($portalSwapGroups as $group) {
              $id = (int) $group['id_groupe'];
              if (isset($portalGroupOptions[$id])) {
                  $portalGroupOptions[$id]['kind'] = 'both';
              } else {
                  $portalGroupOptions[$id] = $group + ['kind' => 'permutation'];
              }
          }
          foreach ($portalGroupOptions as $group): ?>
          <option value="<?= (int) $group['id_groupe'] ?>" data-kind="<?= e((string) $group['kind']) ?>"><?= e($group['semestre'] . ' · ' . $group['type_groupe'] . ' ' . $group['numero'] . ' · ' . $group['inscrits'] . '/' . $group['capacite']) ?></option>
        <?php endforeach; ?></select></label>
        <label data-partner-field>Étudiant pour la permutation<select name="id_partenaire" data-group-partner><option value="">Choisir</option><?php foreach ($portalPartners as $person): ?><option value="<?= (int) $person['id_etudiant'] ?>" data-groupe="<?= (int) $person['id_groupe'] ?>"><?= e($person['prenom'] . ' ' . $person['nom']) ?></option><?php endforeach; ?></select></label>
        <label>Commentaire<textarea name="commentaire" maxlength="1000"></textarea></label>
        <button type="submit">Soumettre la demande</button>
      </form>
      <button type="button" class="student-demand-toggle" data-demand-toggle aria-expanded="false" aria-controls="groupDemandList">Voir mes demandes</button>
      <ul class="student-contact-list student-demand-list" id="groupDemandList" hidden>
        <?php if ($portalMine['groups'] === []): ?>
          <li class="student-contact-card"><div><strong>Aucune demande</strong><span>Vos demandes de groupe apparaîtront ici.</span></div></li>
        <?php else: foreach ($portalMine['groups'] as $request): ?>
          <li class="student-contact-card"><div><strong><?= e((string) $request['request_type']) ?></strong><span><?= e(student_portal_status_label((string) $request['statut'])) ?> · <?= e((string) $request['created_at']) ?><?= $request['admin_comment'] ? ' · ' . e((string) $request['admin_comment']) : '' ?></span></div></li>
        <?php endforeach; endif; ?>
      </ul>
    </section>
    <section class="student-space-sheet" data-sheet="psy"<?= $openPanel === 'psy' ? '' : ' hidden' ?>>
      <h1>Consultation psychologique</h1>
      <p class="student-space-lead">Le motif n’est pas montré aux enseignants.</p>
      <form method="post" class="student-space-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="portal_psy">
        <button type="button" data-slot-open>Réserver un rendez-vous</button>
        <p class="student-space-lead" data-slot-summary>Aucune date choisie.</p>
        <input type="hidden" name="jour" data-slot-date value="">
        <input type="hidden" name="heure" data-slot-time value="">
        <label>Motif<textarea name="motif" required minlength="5" maxlength="2000"></textarea></label>
        <button type="submit">Soumettre la demande de rendez-vous</button>
      </form>
      <button type="button" class="student-demand-toggle" data-demand-toggle aria-expanded="false" aria-controls="psyDemandList">Voir mes demandes</button>
      <ul class="student-contact-list student-demand-list" id="psyDemandList" hidden>
        <?php if ($portalMine['appointments'] === []): ?>
          <li class="student-contact-card"><div><strong>Aucune demande</strong><span>Vos rendez-vous apparaîtront ici.</span></div></li>
        <?php else: foreach ($portalMine['appointments'] as $appointment): ?>
          <?php
            $slotDate = (string) ($appointment['response_date'] ?: $appointment['requested_date']);
            $slotTime = substr((string) ($appointment['response_time'] ?: $appointment['requested_time']), 0, 5);
          ?>
          <li class="student-contact-card"><div><strong><?= e($slotDate) ?> <?= e($slotTime) ?></strong><span><?= e(student_portal_status_label((string) $appointment['statut'])) ?><?= $appointment['admin_comment'] ? ' · ' . e((string) $appointment['admin_comment']) : '' ?></span></div></li>
        <?php endforeach; endif; ?>
      </ul>
    </section>
    <section class="student-space-sheet" data-sheet="reclamation"<?= $openPanel === 'reclamation' ? '' : ' hidden' ?>>
      <h1>Réclamation</h1>
      <form method="post" class="student-space-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="portal_complaint">
        <label>Type<select name="categorie"><option value="infrastructure">Infrastructure</option><option value="absence">Absence</option><option value="note">Note</option><option value="module">Module</option></select></label>
        <label>Description<textarea name="description" required minlength="5"></textarea></label>
        <button type="submit">Envoyer la réclamation</button>
      </form>
      <button type="button" class="student-demand-toggle" data-demand-toggle aria-expanded="false" aria-controls="claimDemandList">Voir mes demandes</button>
      <ul class="student-contact-list student-demand-list" id="claimDemandList" hidden>
        <?php if ($portalMine['complaints'] === []): ?>
          <li class="student-contact-card"><div><strong>Aucune demande</strong><span>Vos réclamations apparaîtront ici.</span></div></li>
        <?php else: foreach ($portalMine['complaints'] as $complaint): ?>
          <li class="student-contact-card"><div><strong><?= e((string) $complaint['categorie']) ?></strong><span><?= e(student_portal_status_label((string) $complaint['statut'])) ?> · <?= e((string) $complaint['created_at']) ?><?= $complaint['admin_response'] ? ' · ' . e((string) $complaint['admin_response']) : '' ?></span></div></li>
        <?php endforeach; endif; ?>
      </ul>
    </section>
    <section class="student-space-sheet" data-sheet="contacts"<?= $openPanel === 'contacts' ? '' : ' hidden' ?>>
      <h1>Contacts</h1>
      <p class="student-space-lead">Enseignants affectés à vos groupes seulement.</p>
      <?php if ($portalContacts === []): ?>
        <p class="student-space-lead">Aucun enseignant affecté.</p>
      <?php else: ?>
        <ul class="student-contact-list">
          <?php foreach ($portalContacts as $contact): ?>
            <?php
              $contactName = trim((string) $contact['prenom'] . ' ' . (string) $contact['nom']);
              $contactInitial = mb_strtoupper(mb_substr($contactName !== '' ? $contactName : '?', 0, 1));
            ?>
            <li class="student-contact-card">
              <span class="student-contact-avatar" aria-hidden="true"><?= e($contactInitial) ?></span>
              <div>
                <strong><?= e($contactName !== '' ? $contactName : 'Enseignant') ?></strong>
                <span><?= e((string) $contact['description_ecue']) ?></span>
                <a href="mailto:<?= e((string) $contact['email']) ?>"><?= e((string) $contact['email']) ?></a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <section class="student-space-sheet<?= $openPanel === 'stage' ? ' is-entering' : '' ?>" data-sheet="stage"<?= $openPanel === 'stage' ? '' : ' hidden' ?>>
      <h1>Stage d’été</h1>
      <?php if ($summerBoard['open']): ?>
      <form method="post" enctype="multipart/form-data" action="dashboard.php?panel=stage" class="student-space-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="portal_stage">
        <label for="id_campagne">Campagne ouverte</label>
        <select id="id_campagne" name="id_campagne" required>
          <?php foreach ($summerBoard['open'] as $row): ?>
            <option value="<?= (int) $row['id_campagne'] ?>"><?= e(($row['titre'] !== '' ? $row['titre'] : 'Campagne') . ' · ' . $row['annee_label']) ?></option>
          <?php endforeach; ?>
        </select>
        <label for="organisme_nom">Établissement d’accueil</label>
        <input id="organisme_nom" name="organisme_nom" required maxlength="180">
        <label for="ville">Ville</label>
        <input id="ville" name="ville" maxlength="80">
        <label for="domaine">Domaine</label>
        <input id="domaine" name="domaine" maxlength="160">
        <label for="contact">Contact de l’organisme</label>
        <input id="contact" name="contact" maxlength="180">
        <label for="date_debut">Début</label>
        <input id="date_debut" name="date_debut" type="date" required>
        <label for="date_fin">Fin</label>
        <input id="date_fin" name="date_fin" type="date" required>
        <label for="missions">Missions prévues</label>
        <textarea id="missions" name="missions" rows="4" maxlength="1000"></textarea>
        <label for="justificatif">Lettre d’acceptation (PDF)</label>
        <input id="justificatif" name="justificatif" type="file" accept="application/pdf,.pdf">
        <button type="submit">Envoyer la demande</button>
      </form>
      <?php else: ?>
      <p class="student-space-lead">Aucune campagne de stage d’été n’est ouverte.</p>
      <?php endif; ?>
      <?php if ($summerBoard['mine']): ?>
      <h2>Mes demandes</h2>
      <ul class="student-contact-list">
        <?php foreach ($summerBoard['mine'] as $row): ?>
          <li class="student-contact-card">
            <div>
              <strong><?= e((string) $row['organisme_nom']) ?></strong>
              <span><?= e((string) $row['statut']) ?></span>
              <?php if ((string) $row['stored_name'] !== ''): ?>
                <a href="stage_demande.php?espace=etudiant&amp;piece=<?= (int) $row['id_demande'] ?>">Justificatif</a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

    <section class="student-space-sheet<?= $openPanel === 'cours' ? ' is-entering' : '' ?>" data-sheet="cours"<?= $openPanel === 'cours' ? '' : ' hidden' ?>>
      <h1>Mes cours</h1>
      <p class="student-space-lead">Les cours publiés par vos enseignants pour vos matières.</p>
      <p class="student-space-alert" id="studentCourseFresh" role="status" hidden>
        Nouveau cours disponible — <a href="dashboard.php?panel=cours" id="studentCourseRefresh">Actualiser</a>
      </p>
      <?php course_explorer_render($courses, 'student', $courseState); ?>
    </section>

    <section class="student-space-sheet<?= $openPanel === 'notes' ? ' is-entering' : '' ?>" data-sheet="notes"<?= $openPanel === 'notes' ? '' : ' hidden' ?>>
      <h1>Notes du semestre</h1>
      <?php if ($gradesBySemester === []): ?>
        <p class="student-space-lead">Aucune note n’est enregistrée pour le moment.</p>
      <?php else: ?>
        <?php foreach ($gradesBySemester as $semester => $semesterGrades): ?>
          <h2><?= e($semester) ?></h2>
          <div class="student-space-table-wrap">
            <table>
              <thead>
                <tr><th>Matière</th><th>CC</th><th>Examen</th><th>TP</th><th>Résultat</th></tr>
              </thead>
              <tbody>
                <?php foreach ($semesterGrades as $grade): ?>
                  <?php $result = calculated_note($grade); ?>
                  <tr>
                    <td><?= e((string) $grade['code_ecue']) ?> — <?= e((string) $grade['description_ecue']) ?></td>
                    <td><?= dashboard_note($grade['note_cours']) ?></td>
                    <td><?= dashboard_note($grade['note_td']) ?></td>
                    <td><?= dashboard_note($grade['note_tp']) ?></td>
                    <td><?= $result === null ? '—' : dashboard_note($result) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <?php if ($attestationOpen): ?>
    <section class="student-space-sheet<?= $openPanel === 'attestation' ? ' is-entering' : '' ?>" data-sheet="attestation"<?= $openPanel === 'attestation' ? '' : ' hidden' ?>>
      <h1>Demander une attestation de présence</h1>
      <div id="attestationBoard">
      <?php if ($attestationBoard['state'] === 'en_attente'): ?>
        <div class="student-space-alert" role="status"><?= e($attestationBoard['message']) ?></div>
      <?php elseif ($attestationBoard['state'] === 'acceptee'): ?>
        <div class="student-space-alert is-success" role="status"><?= e($attestationBoard['message']) ?></div>
      <?php else: ?>
        <p class="student-space-lead">Le CIN doit être celui de votre dossier d’inscription.</p>
        <form method="post" action="dashboard.php" class="student-space-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="demande_attestation">
          <label for="cin">CIN</label>
          <input id="cin" name="cin" maxlength="40" required value="<?= e($attestationCin) ?>">
          <label for="motif">Motif</label>
          <textarea id="motif" name="motif" rows="4" maxlength="500" required><?= e($attestationMotif) ?></textarea>
          <button type="submit">Envoyer la demande</button>
        </form>
      <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </main>
  </div>
  </div>
  <div class="portal-picker" data-portal-picker hidden data-today="<?= e(date('Y-m-d')) ?>">
    <div class="portal-picker-card" role="dialog" aria-modal="true" aria-labelledby="portalPickerTitle">
      <p class="portal-picker-kicker" id="portalPickerTitle">Date et heure</p>
      <button type="button" class="portal-picker-date" data-picker-date>5 nov.</button>
      <div class="portal-picker-clockline">
        <button type="button" data-picker-edit="hour" aria-pressed="true" aria-label="Modifier l’heure">11</button>
        <span aria-hidden="true">:</span>
        <button type="button" data-picker-edit="minute" aria-pressed="false" aria-label="Modifier les minutes">00</button>
        <div class="portal-picker-period" role="group" aria-label="AM ou PM">
          <button type="button" data-period="AM" aria-pressed="true">AM</button>
          <button type="button" data-period="PM" aria-pressed="false">PM</button>
        </div>
      </div>
      <div class="portal-picker-tabs" role="group" aria-label="Calendrier ou horloge">
        <button type="button" data-picker-view="date" aria-pressed="false" aria-label="Calendrier"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3.5V7M16 3.5V7M4 10h16"/></svg></button>
        <button type="button" data-picker-view="time" aria-pressed="true" aria-label="Horloge"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg></button>
      </div>
      <div class="portal-cal-wrap" data-picker-calendar hidden>
        <div class="portal-cal-head">
          <button type="button" data-cal-prev aria-label="Mois précédent"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6l-6 6 6 6"/></svg></button>
          <strong data-cal-label></strong>
          <button type="button" data-cal-next aria-label="Mois suivant"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 6l6 6-6 6"/></svg></button>
        </div>
        <div class="portal-cal" data-cal-grid></div>
      </div>
      <div class="portal-dial" data-picker-clock>
        <div class="portal-dial-face" data-picker-face>
          <span class="portal-dial-hand" data-picker-hand><span class="portal-dial-knob"></span></span>
          <span class="portal-dial-hub" aria-hidden="true"></span>
          <div class="portal-dial-numbers" data-picker-numbers></div>
        </div>
      </div>
      <div class="portal-picker-actions">
        <button type="button" data-picker-cancel>Annuler</button>
        <button type="button" data-picker-ok>OK</button>
      </div>
    </div>
  </div>

  <div class="modal fade student-mail-modal" id="studentMailModal" tabindex="-1" aria-labelledby="studentMailSubject" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="student-mail-ribbon">Bureau des affaires étudiantes</div>
        <div class="modal-header">
          <div>
            <p class="student-mail-meta" id="studentMailDate"></p>
            <h2 class="modal-title" id="studentMailSubject"></h2>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
        </div>
        <div class="modal-body">
          <dl class="student-mail-headers">
            <div><dt>De</dt><dd>Bureau des affaires étudiantes</dd></div>
            <div><dt>À</dt><dd><?= e($studentName !== '' ? $studentName : 'Étudiant') ?></dd></div>
          </dl>
          <p class="student-mail-body" id="studentMailBody"></p>
        </div>
      </div>
    </div>
  </div>

  <script>
  (function () {
    var form = document.querySelector('[data-profile-form]');
    var input = document.querySelector('[data-photo-input]');
    var crop = document.querySelector('[data-photo-crop]');
    var stage = document.querySelector('[data-photo-stage]');
    var image = document.querySelector('[data-photo-image]');
    var zoom = document.querySelector('[data-photo-zoom]');
    var apply = document.querySelector('[data-photo-apply]');
    var preview = document.querySelector('[data-photo-preview]');
    if (!form || !input || !crop || !stage || !image || !zoom || !apply) return;
    var tx = 0;
    var ty = 0;
    var scale = 1;
    var ready = false;
    function place() {
      var box = stage.clientWidth || 256;
      var cover = Math.max(box / image.naturalWidth, box / image.naturalHeight);
      var width = image.naturalWidth * cover * scale;
      var height = image.naturalHeight * cover * scale;
      var maxX = 0;
      var maxY = 0;
      var minX = box - width;
      var minY = box - height;
      tx = Math.min(maxX, Math.max(minX, tx));
      ty = Math.min(maxY, Math.max(minY, ty));
      image.style.width = width + 'px';
      image.style.height = height + 'px';
      image.style.left = tx + 'px';
      image.style.top = ty + 'px';
    }
    function exportFrame() {
      return new Promise(function (resolve, reject) {
        if (!ready) {
          resolve(null);
          return;
        }
        var box = stage.clientWidth || 256;
        var inset = 8;
        var side = box - inset * 2;
        var cover = Math.max(box / image.naturalWidth, box / image.naturalHeight);
        var drawn = cover * scale;
        var canvas = document.createElement('canvas');
        canvas.width = 512;
        canvas.height = 512;
        var context = canvas.getContext('2d');
        context.drawImage(
          image,
          (inset - tx) / drawn,
          (inset - ty) / drawn,
          side / drawn,
          side / drawn,
          0,
          0,
          512,
          512
        );
        canvas.toBlob(function (blob) {
          if (!blob) {
            reject(new Error('crop'));
            return;
          }
          var file = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
          var transfer = new DataTransfer();
          transfer.items.add(file);
          input.files = transfer.files;
          if (preview) {
            var url = URL.createObjectURL(blob);
            preview.innerHTML = '<img alt="" src="' + url + '">';
          }
          crop.hidden = true;
          ready = false;
          resolve(file);
        }, 'image/jpeg', 0.92);
      });
    }
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function () {
        image.onload = function () {
          scale = 1;
          zoom.value = '1';
          var box = stage.clientWidth || 256;
          var cover = Math.max(box / image.naturalWidth, box / image.naturalHeight);
          tx = (box - image.naturalWidth * cover) / 2;
          ty = (box - image.naturalHeight * cover) / 2;
          ready = true;
          crop.hidden = false;
          place();
        };
        image.src = String(reader.result || '');
      };
      reader.readAsDataURL(file);
    });
    zoom.addEventListener('input', function () {
      scale = Number(zoom.value) || 1;
      place();
    });
    var drag = null;
    stage.addEventListener('pointerdown', function (event) {
      if (!ready) return;
      drag = { x: event.clientX, y: event.clientY, tx: tx, ty: ty };
      stage.setPointerCapture(event.pointerId);
    });
    stage.addEventListener('pointermove', function (event) {
      if (!drag) return;
      tx = drag.tx + (event.clientX - drag.x);
      ty = drag.ty + (event.clientY - drag.y);
      place();
    });
    function stopDrag() { drag = null; }
    stage.addEventListener('pointerup', stopDrag);
    stage.addEventListener('pointercancel', stopDrag);
    apply.addEventListener('click', function () {
      exportFrame().catch(function () {});
    });
    form.addEventListener('submit', function (event) {
      if (!ready) return;
      event.preventDefault();
      exportFrame().then(function () { form.submit(); }).catch(function () { form.submit(); });
    });
  })();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/motion.js?v=3" defer></script>
  <script>
  (function () {
    var COURSE_LINK = /^dashboard\.php\?panel=cours(?:&cours=\d{1,10}(?:&dossier=\d{1,10})?)?$/;
    document.querySelectorAll('[data-demand-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        var list = document.getElementById(button.getAttribute('aria-controls') || '');
        if (!list) return;
        var open = list.hasAttribute('hidden');
        if (open) list.removeAttribute('hidden');
        else list.setAttribute('hidden', '');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.textContent = open ? 'Masquer mes demandes' : 'Voir mes demandes';
      });
    });
    var buttons = document.querySelectorAll('.portal-drawer [data-panel], .student-space-cards > [data-panel]');
    var portal = document.getElementById('portalDrawer');
    var backdrop = document.querySelector('[data-portal-close]');
    var portalOpen = document.querySelector('[data-portal-open]');
    var phone = document.querySelector('.portal-phone');
    var edge = document.querySelector('[data-portal-edge]');
    function drawerOpen() {
      return !!(phone && phone.classList.contains('is-drawer-open'));
    }
    function isPhoneShell() {
      return window.matchMedia('(max-width: 767.98px)').matches;
    }
    function syncDesktopSidebar() {
      if (!portal || !phone || isPhoneShell()) return;
      phone.classList.remove('is-drawer-open');
      portal.removeAttribute('inert');
      portal.setAttribute('aria-hidden', 'false');
      if (portalOpen) portalOpen.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('portal-lock');
    }
    function setPortal(open) {
      if (!portal || !phone) return;
      if (!isPhoneShell()) {
        syncDesktopSidebar();
        return;
      }
      phone.classList.toggle('is-drawer-open', open);
      portal.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (open) portal.removeAttribute('inert');
      else portal.setAttribute('inert', '');
      if (portalOpen) portalOpen.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.classList.toggle('portal-lock', open);
    }
    if (portalOpen) portalOpen.addEventListener('click', function () {
      if (!isPhoneShell()) return;
      setPortal(!drawerOpen());
    });
    syncDesktopSidebar();
    window.addEventListener('resize', function () {
      if (!isPhoneShell()) syncDesktopSidebar();
      else if (!drawerOpen()) setPortal(false);
    });
    if (backdrop) backdrop.addEventListener('click', function () { setPortal(false); });
    var drag = null;
    function clearDragStyles() {
      portal.style.transition = '';
      portal.style.transform = '';
      if (backdrop) {
        backdrop.style.transition = '';
        backdrop.style.opacity = '';
      }
    }
    function beginDrag(event, fromEdge) {
      if (!isPhoneShell() || !portal || event.pointerType === 'mouse' && event.button !== 0) return;
      drag = { id: event.pointerId, x: event.clientX, y: event.clientY, open: drawerOpen(), fromEdge: fromEdge, axis: '' };
    }
    function moveDrag(event) {
      if (!drag || event.pointerId !== drag.id) return;
      var dx = event.clientX - drag.x;
      var dy = event.clientY - drag.y;
      if (!drag.axis) {
        if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
        drag.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
        if (drag.axis !== 'x') {
          drag = null;
          return;
        }
      }
      var width = portal.getBoundingClientRect().width || 280;
      var next = drag.open ? Math.max(-width, Math.min(0, dx)) : Math.max(-width, Math.min(0, -width + Math.max(0, dx)));
      portal.style.transition = 'none';
      portal.style.transform = 'translateX(' + next + 'px)';
      if (backdrop) {
        backdrop.style.transition = 'none';
        backdrop.style.opacity = String(Math.max(0, Math.min(1, 1 + next / width)));
      }
    }
    function endDrag(event) {
      if (!drag || event.pointerId !== drag.id) return;
      var dx = event.clientX - drag.x;
      var wasOpen = drag.open;
      var moved = drag.axis === 'x' && Math.abs(dx) > 8;
      clearDragStyles();
      drag = null;
      if (!moved) return;
      var blockClick = function (clickEvent) {
        clickEvent.preventDefault();
        clickEvent.stopPropagation();
        document.removeEventListener('click', blockClick, true);
      };
      document.addEventListener('click', blockClick, true);
      setTimeout(function () { document.removeEventListener('click', blockClick, true); }, 400);
      setPortal(wasOpen ? dx > -72 : dx > 48);
    }
    if (edge) {
      edge.addEventListener('pointerdown', function (event) { beginDrag(event, true); });
    }
    if (portal) {
      portal.addEventListener('pointerdown', function (event) {
        if (event.target.closest('button, a, input, select, textarea')) return;
        beginDrag(event, false);
      });
    }
    document.addEventListener('pointermove', moveDrag);
    document.addEventListener('pointerup', endDrag);
    document.addEventListener('pointercancel', endDrag);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') setPortal(false);
    });
    var sheets = document.querySelectorAll('.student-space-main > [data-sheet]');
    var liveEl = document.getElementById('studentLive');
    function announce(message) {
      if (!liveEl) return;
      liveEl.textContent = '';
      liveEl.textContent = message;
    }
    function rememberPanel(panel) {
      if (!window.history || typeof window.history.replaceState !== 'function') return;
      var params = new URLSearchParams(window.location.search);
      if (params.get('panel') === panel) return;
      params.set('panel', panel);
      if (panel !== 'cours') {
        params.delete('cours');
        params.delete('dossier');
      }
      window.history.replaceState(window.history.state, '', window.location.pathname + '?' + params.toString() + window.location.hash);
    }
    function openPanel(panel, options) {
      var opts = options || {};
      if (!panel) return;
      buttons.forEach(function (item) {
        item.setAttribute('aria-pressed', item.getAttribute('data-panel') === panel ? 'true' : 'false');
      });
      sheets.forEach(function (sheet) {
        var match = sheet.getAttribute('data-sheet') === panel;
        if (match) {
          var wasHidden = sheet.hidden;
          sheet.hidden = false;
          sheet.classList.remove('is-entering');
          if (wasHidden) {
            void sheet.offsetWidth;
            sheet.classList.add('is-entering');
          }
        } else {
          sheet.hidden = true;
          sheet.classList.remove('is-entering');
        }
      });
      if (!opts.silent) rememberPanel(panel);
    }
    var groupType = document.querySelector('[data-group-type]');
    var groupTarget = document.querySelector('[data-group-target]');
    var partnerField = document.querySelector('[data-partner-field]');
    var groupPartner = document.querySelector('[data-group-partner]');
    function syncPartners() {
      if (!groupPartner || !groupTarget) return;
      Array.prototype.forEach.call(groupPartner.options, function (option) {
        if (!option.getAttribute('data-groupe')) return;
        var show = option.getAttribute('data-groupe') === groupTarget.value;
        option.hidden = !show;
        option.disabled = !show;
      });
      if (groupPartner.selectedOptions[0] && groupPartner.selectedOptions[0].disabled) groupPartner.value = '';
    }
    function syncGroups() {
      if (!groupType || !groupTarget) return;
      if (partnerField) partnerField.hidden = groupType.value !== 'permutation';
      Array.prototype.forEach.call(groupTarget.options, function (option) {
        if (!option.getAttribute('data-kind')) return;
        var kind = option.getAttribute('data-kind');
        var show = kind === groupType.value || kind === 'both';
        option.hidden = !show;
        option.disabled = !show;
      });
      if (groupTarget.selectedOptions[0] && groupTarget.selectedOptions[0].disabled) groupTarget.value = '';
      syncPartners();
    }
    if (groupType) groupType.addEventListener('change', syncGroups);
    if (groupTarget) groupTarget.addEventListener('change', syncPartners);
    syncGroups();
    var slotOpen = document.querySelector('[data-slot-open]');
    var picker = document.querySelector('[data-portal-picker]');
    if (slotOpen && picker) {
      var slotForm = slotOpen.closest('form');
      var slotDate = slotForm.querySelector('[data-slot-date]');
      var slotTime = slotForm.querySelector('[data-slot-time]');
      var slotSummary = slotForm.querySelector('[data-slot-summary]');
      var months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
      var monthsFull = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
      var todayIso = picker.getAttribute('data-today');
      var todayParts = todayIso.split('-').map(Number);
      var selected = new Date(todayParts[0], todayParts[1] - 1, todayParts[2]);
      var shown = new Date(selected.getFullYear(), selected.getMonth(), 1);
      var hour12 = 11;
      var minute = 0;
      var period = 'AM';
      var mode = 'hour';
      var view = 'time';
      var numbersKey = '';
      var dragging = false;
      var face = picker.querySelector('[data-picker-face]');
      var hand = picker.querySelector('[data-picker-hand]');
      var numbers = picker.querySelector('[data-picker-numbers]');
      function iso(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
      }
      function to24() {
        var hour = period === 'AM' ? (hour12 === 12 ? 0 : hour12) : (hour12 === 12 ? 12 : hour12 + 12);
        return String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0');
      }
      function paintHand() {
        var angle = mode === 'hour' ? (hour12 % 12) * 30 : minute * 6;
        if (hand) hand.style.setProperty('--hand-angle', angle + 'deg');
      }
      function paintReadout() {
        var hourButton = picker.querySelector('[data-picker-edit="hour"]');
        var minuteButton = picker.querySelector('[data-picker-edit="minute"]');
        var dateButton = picker.querySelector('[data-picker-date]');
        hourButton.textContent = String(hour12);
        minuteButton.textContent = String(minute).padStart(2, '0');
        hourButton.setAttribute('aria-pressed', mode === 'hour' && view === 'time' ? 'true' : 'false');
        minuteButton.setAttribute('aria-pressed', mode === 'minute' && view === 'time' ? 'true' : 'false');
        dateButton.textContent = selected.getDate() + ' ' + months[selected.getMonth()];
        picker.querySelectorAll('[data-period]').forEach(function (button) {
          button.setAttribute('aria-pressed', button.getAttribute('data-period') === period ? 'true' : 'false');
        });
        picker.querySelectorAll('[data-picker-view]').forEach(function (button) {
          button.setAttribute('aria-pressed', button.getAttribute('data-picker-view') === view ? 'true' : 'false');
        });
      }
      function markSelected() {
        numbers.querySelectorAll('[data-value]').forEach(function (node) {
          var value = Number(node.getAttribute('data-value'));
          var selectedValue = mode === 'hour' ? hour12 : minute;
          node.classList.toggle('is-selected', value === selectedValue);
        });
      }
      function renderNumbers() {
        var key = mode;
        if (key === numbersKey && numbers.childElementCount) {
          markSelected();
          return;
        }
        numbersKey = key;
        numbers.replaceChildren();
        var values = mode === 'hour' ? [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] : [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];
        values.forEach(function (value, index) {
          var node = document.createElement('span');
          node.className = 'portal-dial-num';
          node.style.setProperty('--angle', (index * 30) + 'deg');
          node.setAttribute('data-value', String(value));
          node.textContent = mode === 'hour' ? String(value) : String(value).padStart(2, '0');
          numbers.appendChild(node);
        });
        markSelected();
      }
      function renderCalendar() {
        var label = picker.querySelector('[data-cal-label]');
        var grid = picker.querySelector('[data-cal-grid]');
        label.textContent = monthsFull[shown.getMonth()] + ' ' + shown.getFullYear();
        var first = new Date(shown.getFullYear(), shown.getMonth(), 1);
        var offset = (first.getDay() + 6) % 7;
        var count = new Date(shown.getFullYear(), shown.getMonth() + 1, 0).getDate();
        grid.replaceChildren();
        ['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach(function (day) {
          var head = document.createElement('span');
          head.className = 'portal-cal-dow';
          head.textContent = day;
          grid.appendChild(head);
        });
        for (var blank = 0; blank < offset; blank += 1) {
          var gap = document.createElement('span');
          grid.appendChild(gap);
        }
        for (var day = 1; day <= count; day += 1) {
          var date = new Date(shown.getFullYear(), shown.getMonth(), day);
          var button = document.createElement('button');
          button.type = 'button';
          button.textContent = String(day);
          button.disabled = iso(date) < todayIso;
          if (iso(date) === iso(selected)) button.setAttribute('aria-pressed', 'true');
          button.addEventListener('click', function (event) {
            var chosen = Number(event.currentTarget.textContent);
            selected = new Date(shown.getFullYear(), shown.getMonth(), chosen);
            renderCalendar();
            paintReadout();
          });
          grid.appendChild(button);
        }
        var earliest = new Date(todayParts[0], todayParts[1] - 1, 1);
        picker.querySelector('[data-cal-prev]').disabled = shown <= earliest;
      }
      function paint() {
        picker.querySelector('[data-picker-calendar]').hidden = view !== 'date';
        picker.querySelector('[data-picker-clock]').hidden = view !== 'time';
        paintReadout();
        if (view === 'date') renderCalendar();
        else {
          renderNumbers();
          paintHand();
        }
      }
      function degrees(event) {
        var rect = face.getBoundingClientRect();
        var dx = event.clientX - (rect.left + rect.width / 2);
        var dy = event.clientY - (rect.top + rect.height / 2);
        return (Math.atan2(dx, -dy) * 180 / Math.PI + 360) % 360;
      }
      function applyPointer(event) {
        var angle = degrees(event);
        if (mode === 'hour') {
          var next = Math.round(angle / 30) % 12;
          hour12 = next === 0 ? 12 : next;
        } else {
          minute = Math.round(angle / 6) % 60;
        }
        paintReadout();
        markSelected();
        paintHand();
      }
      function openPicker() {
        if (slotDate.value && slotTime.value) {
          var dateParts = slotDate.value.split('-').map(Number);
          var timeParts = slotTime.value.split(':').map(Number);
          selected = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
          shown = new Date(selected.getFullYear(), selected.getMonth(), 1);
          minute = timeParts[1];
          period = timeParts[0] >= 12 ? 'PM' : 'AM';
          hour12 = timeParts[0] % 12;
          if (hour12 === 0) hour12 = 12;
        }
        mode = 'hour';
        view = 'time';
        numbersKey = '';
        picker.hidden = false;
        document.body.classList.add('portal-lock');
        paint();
        var ok = picker.querySelector('[data-picker-ok]');
        if (ok) ok.focus();
      }
      function closePicker() {
        picker.hidden = true;
        document.body.classList.remove('portal-lock');
      }
      slotOpen.addEventListener('click', openPicker);
      picker.addEventListener('click', function (event) {
        if (event.target === picker) closePicker();
      });
      picker.querySelector('[data-picker-cancel]').addEventListener('click', closePicker);
      picker.querySelector('[data-picker-ok]').addEventListener('click', function () {
        slotDate.value = iso(selected);
        slotTime.value = to24();
        slotSummary.textContent = selected.getDate() + ' ' + months[selected.getMonth()] + ' · ' + to24();
        closePicker();
      });
      picker.querySelectorAll('[data-picker-view]').forEach(function (button) {
        button.addEventListener('click', function () {
          view = button.getAttribute('data-picker-view');
          paint();
        });
      });
      picker.querySelectorAll('[data-picker-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
          mode = button.getAttribute('data-picker-edit');
          view = 'time';
          numbersKey = '';
          paint();
        });
      });
      picker.querySelector('[data-picker-date]').addEventListener('click', function () {
        view = 'date';
        paint();
      });
      picker.querySelectorAll('[data-period]').forEach(function (button) {
        button.addEventListener('click', function () {
          period = button.getAttribute('data-period');
          paintReadout();
        });
      });
      picker.querySelector('[data-cal-prev]').addEventListener('click', function () {
        shown = new Date(shown.getFullYear(), shown.getMonth() - 1, 1);
        renderCalendar();
      });
      picker.querySelector('[data-cal-next]').addEventListener('click', function () {
        shown = new Date(shown.getFullYear(), shown.getMonth() + 1, 1);
        renderCalendar();
      });
      face.addEventListener('pointerdown', function (event) {
        if (view !== 'time') return;
        dragging = true;
        face.setPointerCapture(event.pointerId);
        applyPointer(event);
        event.preventDefault();
      });
      face.addEventListener('pointermove', function (event) {
        if (!dragging) return;
        applyPointer(event);
      });
      function endDrag(event) {
        if (!dragging) return;
        dragging = false;
        applyPointer(event);
        if (mode === 'hour') {
          mode = 'minute';
          numbersKey = '';
          paint();
        }
      }
      face.addEventListener('pointerup', endDrag);
      face.addEventListener('pointercancel', endDrag);
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !picker.hidden) closePicker();
      });
      if (slotForm) {
        slotForm.addEventListener('submit', function (event) {
          if (slotDate.value && slotTime.value) return;
          event.preventDefault();
          openPicker();
        });
      }
    }
    window.studentSpace = {
      openPanel: openPanel,
      announce: announce
    };
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        openPanel(button.getAttribute('data-panel'));
        setPortal(false);
      });
    });
    var courseRefresh = document.getElementById('studentCourseRefresh');
    if (courseRefresh) {
      courseRefresh.addEventListener('click', function (event) {
        event.preventDefault();
        if (window.courseExplorer && typeof window.courseExplorer.reload === 'function') {
          window.courseExplorer.reload();
        } else {
          window.location.assign(courseRefresh.getAttribute('href') || 'dashboard.php?panel=cours');
        }
      });
    }

    var csrf = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
    var inbox = <?= json_encode($inbox, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var listEl = document.getElementById('studentInboxList');
    var clearEl = document.getElementById('studentInboxClear');
    var badgeEl = document.getElementById('studentInboxBadge');
    var toggleEl = document.getElementById('studentInboxToggle');
    var modalEl = document.getElementById('studentMailModal');
    var subjectEl = document.getElementById('studentMailSubject');
    var dateEl = document.getElementById('studentMailDate');
    var bodyEl = document.getElementById('studentMailBody');
    var stamp = inbox && inbox.stamp ? String(inbox.stamp) : '';

    function escapeText(value) {
      var node = document.createElement('div');
      node.textContent = value == null ? '' : String(value);
      return node.innerHTML;
    }
    function setBadge(count) {
      if (!badgeEl || !toggleEl) return;
      var unread = Number(count) || 0;
      badgeEl.hidden = unread < 1;
      badgeEl.textContent = unread > 99 ? '99+' : String(unread);
      toggleEl.setAttribute(
        'aria-label',
        unread < 1 ? 'Boîte de réception, aucun message non lu' : 'Boîte de réception, ' + unread + ' message' + (unread > 1 ? 's' : '') + ' non lu' + (unread > 1 ? 's' : '')
      );
    }
    function noteLink(note) {
      return note && typeof note.link === 'string' ? note.link : '';
    }
    var swiping = false;
    function setClearVisible(hasMessages) {
      if (!clearEl) return;
      clearEl.hidden = !hasMessages;
    }
    function renderList(messages) {
      if (!listEl) return;
      if (swiping) return;
      if (!messages || !messages.length) {
        listEl.innerHTML = '<p class="student-inbox-empty">No notifications yet</p>';
        setClearVisible(false);
        return;
      }
      setClearVisible(true);
      var fragment = document.createDocumentFragment();
      messages.forEach(function (note) {
        var unread = Number(note.is_read) !== 1;
        var link = noteLink(note);
        var id = String(Number(note.id));
        var row = document.createElement('div');
        row.className = 'student-inbox-row';
        row.setAttribute('data-notif-id', id);
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'student-inbox-delete';
        remove.setAttribute('data-notif-delete', id);
        remove.setAttribute('aria-label', 'Supprimer ce message');
        remove.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M8 7l1 13h6l1-13"/></svg><span>Supprimer</span>';
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'student-inbox-item' + (unread ? ' is-unread' : ' is-read');
        item.setAttribute('data-notif-id', id);
        if (link !== '') item.setAttribute('data-notif-link', link);
        var title = document.createElement('strong');
        if (COURSE_LINK.test(link)) {
          var icon = document.createElement('i');
          icon.className = 'bi bi-folder2-open me-1';
          icon.setAttribute('aria-hidden', 'true');
          title.appendChild(icon);
        }
        title.appendChild(document.createTextNode(note.sujet == null ? '' : String(note.sujet)));
        var date = document.createElement('span');
        date.textContent = note.date == null ? '' : String(note.date);
        item.appendChild(title);
        item.appendChild(date);
        row.appendChild(remove);
        row.appendChild(item);
        fragment.appendChild(row);
      });
      listEl.textContent = '';
      listEl.appendChild(fragment);
    }
    var knownIds = {};
    function rememberIds(messages) {
      (messages || []).forEach(function (note) { knownIds[Number(note.id)] = true; });
    }
    function announceNewCourse(messages) {
      var fresh = (messages || []).some(function (note) {
        return !knownIds[Number(note.id)] && Number(note.is_read) !== 1 && COURSE_LINK.test(noteLink(note));
      });
      var sheet = document.querySelector('.student-space-main > [data-sheet="cours"]');
      var hint = document.getElementById('studentCourseFresh');
      if (!fresh || !sheet || sheet.hidden || !hint) return;
      var refresh = document.getElementById('studentCourseRefresh');
      if (refresh) refresh.setAttribute('href', window.location.href);
      hint.hidden = false;
    }
    function applyInbox(data) {
      if (!data) return;
      inbox = data;
      stamp = String(data.stamp || '');
      setBadge(data.unread);
      renderList(data.messages || []);
      renderAttestationBoard(data.attestation || null);
      rememberIds(data.messages);
    }
    function renderAttestationBoard(board) {
      var holder = document.getElementById('attestationBoard');
      if (!holder || !board) return;
      if (board.state === 'en_attente') {
        holder.innerHTML = '<div class="student-space-alert" role="status">' + escapeText(board.message) + '</div>';
        return;
      }
      if (board.state === 'acceptee') {
        holder.innerHTML = '<div class="student-space-alert is-success" role="status">' + escapeText(board.message) + '</div>';
      }
    }
    function findMessage(id) {
      var messages = (inbox && inbox.messages) || [];
      for (var i = 0; i < messages.length; i++) {
        if (Number(messages[i].id) === Number(id)) return messages[i];
      }
      return null;
    }
    function inboxPost(fields) {
      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      Object.keys(fields).forEach(function (key) { body.set(key, String(fields[key])); });
      return fetch('etudiant_inbox.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) { if (data) applyInbox(data); return data; })
        .catch(function () { return null; });
    }
    function markRead(id) {
      return inboxPost({ id_notif: String(id) });
    }
    function dropLocal(id) {
      var key = String(id);
      if (inbox && inbox.messages) {
        inbox.messages = inbox.messages.filter(function (note) { return String(note.id) !== key; });
        inbox.unread = inbox.messages.filter(function (note) { return Number(note.is_read) !== 1; }).length;
        inbox.stamp = String(inbox.unread) + ':local:' + inbox.messages.length;
        stamp = inbox.stamp;
      }
      if (listEl) {
        var row = listEl.querySelector('.student-inbox-row[data-notif-id="' + key + '"]');
        if (row) row.remove();
        if (!listEl.querySelector('.student-inbox-row')) {
          listEl.innerHTML = '<p class="student-inbox-empty">No notifications yet</p>';
          setClearVisible(false);
        }
      }
      setBadge(inbox ? inbox.unread : 0);
    }
    var deletingIds = {};
    function deleteNote(id) {
      var key = String(id);
      if (deletingIds[key]) return Promise.resolve(false);
      deletingIds[key] = true;
      return inboxPost({ action: 'delete', id_notif: key }).then(function (data) {
        delete deletingIds[key];
        return !!data;
      }, function () {
        delete deletingIds[key];
        return false;
      });
    }
    function clearNotes() {
      return inboxPost({ action: 'clear' });
    }
    function openMessage(id) {
      var note = findMessage(id);
      if (!note || !modalEl || typeof bootstrap === 'undefined') return;
      subjectEl.textContent = note.sujet || '';
      dateEl.textContent = note.date || '';
      bodyEl.textContent = note.message_body || '';
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      if (Number(note.is_read) === 1) return;
      markRead(id);
    }
    function closeInbox() {
      if (!toggleEl || typeof bootstrap === 'undefined' || !bootstrap.Dropdown) return;
      var dropdown = bootstrap.Dropdown.getInstance(toggleEl);
      if (dropdown) dropdown.hide();
    }
    function followCourseLink(id, link) {
      var note = findMessage(id);
      closeInbox();
      openPanel('cours');
      if (window.courseExplorer && typeof window.courseExplorer.navigate === 'function') {
        window.courseExplorer.navigate(link, { push: true });
      } else {
        window.location.assign(link);
        return;
      }
      if (!note || Number(note.is_read) === 1) return;
      markRead(id);
    }
    if (clearEl) {
      clearEl.addEventListener('click', function () {
        if (!inbox || !inbox.messages || !inbox.messages.length) return;
        if (!window.confirm('Effacer tous les messages ?')) return;
        clearNotes();
      });
    }
    if (listEl) {
      var drag = null;
      var errorEl = document.getElementById('studentInboxError');
      var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      function showInboxError(message) {
        if (!errorEl) return;
        errorEl.hidden = !message;
        errorEl.textContent = message || '';
      }
      function dragPoint(event) {
        if (event.touches && event.touches[0]) return event.touches[0];
        if (event.changedTouches && event.changedTouches[0]) return event.changedTouches[0];
        return event;
      }
      function restoreRow(row) {
        var face = row.querySelector('.student-inbox-item');
        var action = row.querySelector('.student-inbox-delete');
        row.classList.remove('is-dragging', 'is-armed', 'is-leaving');
        row.style.height = '';
        row.style.marginBottom = '';
        row.style.transition = '';
        row.style.transform = '';
        row.style.opacity = '';
        if (face) {
          face.dataset.swiped = '';
          face.style.transform = '';
          face.style.opacity = '';
          face.style.transition = '';
        }
        if (action) {
          action.style.opacity = '';
          action.style.width = '';
          action.style.transform = '';
          action.style.transition = '';
        }
        swiping = false;
        if (listEl && !listEl.querySelector('.student-inbox-row.is-leaving')) {
          listEl.style.overflow = '';
          var menu = listEl.closest('.student-inbox-menu');
          if (menu) menu.style.overflow = '';
        }
      }
      function finishRemoval(row) {
        var id = row.getAttribute('data-notif-id');
        if (inbox && inbox.messages) {
          inbox.messages = inbox.messages.filter(function (note) { return String(note.id) !== String(id); });
          inbox.unread = inbox.messages.filter(function (note) { return Number(note.is_read) !== 1; }).length;
          setBadge(inbox.unread);
        }
        row.remove();
        if (!listEl.querySelector('.student-inbox-row')) {
          listEl.innerHTML = '<p class="student-inbox-empty">No notifications yet</p>';
          setClearVisible(false);
        }
        swiping = false;
        if (!listEl.querySelector('.student-inbox-row.is-leaving')) {
          listEl.style.overflow = '';
          var menu = listEl.closest('.student-inbox-menu');
          if (menu) menu.style.overflow = '';
        }
      }
      function commitDelete(row, fromX) {
        if (!row || row.dataset.deleting === '1') return;
        var id = row.getAttribute('data-notif-id');
        var face = row.querySelector('.student-inbox-item');
        var action = row.querySelector('.student-inbox-delete');
        var from = typeof fromX === 'number' ? fromX : 0;
        row.dataset.deleting = '1';
        if (face) face.dataset.swiped = '1';
        swiping = true;
        showInboxError('');
        function afterMotion() {
          return deleteNote(id).then(function (ok) {
            row.dataset.deleting = '';
            if (ok) {
              finishRemoval(row);
              return;
            }
            restoreRow(row);
            showInboxError('Impossible de supprimer ce message. Réessayez.');
          });
        }
        if (reduceMotion) {
          afterMotion();
          return;
        }
        var height = row.getBoundingClientRect().height;
        var offLeft = -(row.getBoundingClientRect().right + 32);
        row.classList.add('is-leaving', 'is-armed');
        row.classList.remove('is-dragging');
        row.style.overflow = 'visible';
        row.style.zIndex = '4';
        listEl.style.overflow = 'visible';
        var menu = listEl.closest('.student-inbox-menu');
        if (menu) menu.style.overflow = 'visible';
        if (face) {
          face.style.transition = 'none';
          face.style.transform = 'translate3d(' + from + 'px,0,0)';
          face.style.opacity = '1';
        }
        if (action) {
          action.style.transition = 'none';
          action.style.opacity = '1';
        }
        window.requestAnimationFrame(function () {
          var travel = 'transform .52s cubic-bezier(.4,0,.15,1), opacity .52s linear';
          if (face) {
            face.style.transition = travel;
            face.style.transform = 'translate3d(' + offLeft + 'px,0,0) scale(.96)';
            face.style.opacity = '0';
          }
          if (action) {
            action.style.transition = travel;
            action.style.transform = 'translate3d(' + offLeft + 'px,0,0)';
            action.style.opacity = '0';
          }
        });
        window.setTimeout(function () {
          if (!row.isConnected) return;
          row.style.height = height + 'px';
          row.style.transition = 'height .28s ease, margin-bottom .28s ease';
          window.requestAnimationFrame(function () {
            row.style.height = '0px';
            row.style.marginBottom = '0px';
          });
          window.setTimeout(afterMotion, 280);
        }, 520);
      }
      function dragStart(event) {
        if (drag || event.target.closest('[data-notif-delete]')) return;
        if (event.type === 'mousedown' && event.button !== 0) return;
        var face = event.target.closest('.student-inbox-item');
        var row = face && face.closest('.student-inbox-row');
        if (!face || !row || row.dataset.deleting === '1') return;
        var point = dragPoint(event);
        drag = {
          face: face,
          row: row,
          action: row.querySelector('.student-inbox-delete'),
          id: row.getAttribute('data-notif-id'),
          x: point.clientX,
          y: point.clientY,
          dx: 0,
          axis: '',
          lastX: point.clientX,
          lastT: performance.now(),
          velocity: 0
        };
      }
      function dragMove(event) {
        if (!drag) return;
        var point = dragPoint(event);
        var nextX = point.clientX - drag.x;
        var nextY = point.clientY - drag.y;
        var now = performance.now();
        var dt = Math.max(16, now - drag.lastT);
        drag.velocity = (point.clientX - drag.lastX) / dt;
        drag.lastX = point.clientX;
        drag.lastT = now;
        if (!drag.axis) {
          if (Math.abs(nextX) < 8 && Math.abs(nextY) < 8) return;
          drag.axis = Math.abs(nextX) > Math.abs(nextY) + 4 ? 'x' : 'y';
          if (drag.axis === 'y') {
            drag = null;
            return;
          }
          drag.row.classList.add('is-dragging');
          drag.face.style.transition = 'none';
          if (drag.action) drag.action.style.transition = 'none';
          swiping = true;
        }
        if (event.cancelable) event.preventDefault();
        var width = drag.row.clientWidth || 1;
        drag.dx = Math.max(-width, Math.min(0, nextX));
        drag.face.style.transform = 'translate3d(' + drag.dx + 'px,0,0)';
        if (drag.action) drag.action.style.width = Math.abs(drag.dx) + 'px';
        drag.row.classList.toggle('is-armed', drag.dx <= -width * 0.9);
      }
      function dragEnd() {
        if (!drag) return;
        var face = drag.face;
        var row = drag.row;
        var action = drag.action;
        var dx = drag.dx;
        var velocity = drag.velocity;
        var width = row.clientWidth || 1;
        var moved = drag.axis === 'x';
        var commit = moved && dx <= -width * 0.9;
        drag = null;
        row.classList.remove('is-dragging');
        if (!moved) {
          swiping = false;
          return;
        }
        face.dataset.swiped = '1';
        if (commit) {
          commitDelete(row, dx);
          return;
        }
        face.style.transition = '';
        face.style.transform = 'translate3d(0,0,0)';
        if (action) {
          action.style.transition = '';
          action.style.width = '0px';
        }
        row.classList.remove('is-armed');
        window.setTimeout(function () {
          if (row.dataset.deleting === '1') return;
          face.dataset.swiped = '';
          face.style.transform = '';
          swiping = false;
        }, 480);
      }
      listEl.addEventListener('touchstart', dragStart, { passive: true });
      listEl.addEventListener('touchmove', dragMove, { passive: false });
      listEl.addEventListener('touchend', dragEnd);
      listEl.addEventListener('touchcancel', dragEnd);
      listEl.addEventListener('mousedown', function (event) {
        dragStart(event);
        if (!drag) return;
        function moving(ev) { dragMove(ev); }
        function ending() {
          window.removeEventListener('mousemove', moving);
          window.removeEventListener('mouseup', ending);
          dragEnd();
        }
        window.addEventListener('mousemove', moving);
        window.addEventListener('mouseup', ending);
      });
      listEl.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-notif-delete]');
        if (remove) {
          event.preventDefault();
          event.stopPropagation();
          commitDelete(remove.closest('.student-inbox-row'));
          return;
        }
        var item = event.target.closest('.student-inbox-item[data-notif-id]');
        if (!item || item.dataset.swiped === '1') return;
        var id = item.getAttribute('data-notif-id');
        var link = item.getAttribute('data-notif-link') || '';
        if (COURSE_LINK.test(link)) {
          followCourseLink(id, link);
          return;
        }
        openMessage(id);
      });
      listEl.addEventListener('focusin', function (event) {
        var remove = event.target.closest('[data-notif-delete]');
        if (!remove) return;
        var row = remove.closest('.student-inbox-row');
        var face = row && row.querySelector('.student-inbox-item');
        if (!face || row.dataset.deleting === '1') return;
        face.style.transform = 'translate3d(-8.5rem,0,0)';
        remove.style.opacity = '1';
      });
      listEl.addEventListener('focusout', function (event) {
        var remove = event.target.closest('[data-notif-delete]');
        if (!remove) return;
        var row = remove.closest('.student-inbox-row');
        if (!row || row.classList.contains('is-dragging') || row.dataset.deleting === '1') return;
        window.setTimeout(function () {
          if (row.contains(document.activeElement)) return;
          restoreRow(row);
        }, 40);
      });
    }
    function tick() {
      fetch('etudiant_inbox.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
          if (!data) return;
          if (data.suspended) {
            window.location.replace('connexion_etudiant.php?error=suspended');
            return;
          }
          if (data.authenticated === false) {
            window.location.replace('connexion_etudiant.php');
            return;
          }
          if (String(data.stamp || '') === stamp) return;
          announceNewCourse(data.messages);
          applyInbox(data);
        })
        .catch(function () {});
    }
    rememberIds(inbox.messages);
    setBadge(inbox.unread);
    setInterval(tick, 2000);
  })();
  </script>
<script type="module" src="assets/success-lottie.js?v=6"></script>
</body>
</html>

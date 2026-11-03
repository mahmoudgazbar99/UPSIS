<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/student_theme.php');
require_once app_path('support/emploi_pdf.php');
require_once app_path('support/course_library.php');
require_once app_path('support/course_explorer_view.php');

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
$explorerFragment = false;

try {
    $pdo = db();
    ensure_etudiant_compte_table($pdo);

    // Étape 2 — Profil : le compte doit rester actif et lié à un dossier inscrit.
    $profileQuery = $pdo->prepare(
        'SELECT e.nom, e.prenom, e.niveau, e.email, e.cin_passeport
         FROM etudiant e
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
$timetableBySemester = [];
foreach ($timetable as $session) {
    $timetableBySemester[(string) $session['semestre']][(string) $session['jour']][] = $session;
}
$gradesBySemester = [];
foreach ($grades as $grade) {
    $gradesBySemester[(string) $grade['semestre']][] = $grade;
}
$panels = ['profil', 'emploi', 'cours', 'notes'];
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
  <link href="assets/student-space.css?v=7" rel="stylesheet">
  <link href="assets/student-theme.css?v=5" rel="stylesheet">
  <link href="assets/course-explorer.css?v=2" rel="stylesheet">
  <script src="assets/course-explorer.js?v=2" defer></script>
</head>
<body class="student-space">
  <header class="student-space-bar">
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
          <p class="student-inbox-title">Messages</p>
          <div id="studentInboxList">
            <?php if (!$inbox['messages']): ?>
              <p class="student-inbox-empty">Aucun message</p>
            <?php else: ?>
              <?php foreach ($inbox['messages'] as $note): ?>
                <?php $noteLink = is_string($note['link'] ?? null) ? $note['link'] : ''; ?>
                <button type="button" class="student-inbox-item<?= ((int) $note['is_read'] === 1) ? ' is-read' : ' is-unread' ?>" data-notif-id="<?= (int) $note['id'] ?>"<?= $noteLink !== '' ? ' data-notif-link="' . e($noteLink) . '"' : '' ?>>
                  <strong><?php if (dashboard_course_link($noteLink)): ?><i class="bi bi-folder2-open me-1" aria-hidden="true"></i><?php endif; ?><?= e($note['sujet']) ?></strong>
                  <span><?= e($note['date']) ?></span>
                </button>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <a href="logout.php?espace=etudiant">Déconnexion</a>
    </div>
  </header>
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
        <strong>Notes</strong>
        <small><?= count($grades) ?> matière<?= count($grades) > 1 ? 's' : '' ?></small>
      </button>
      <?php if ($attestationOpen): ?>
      <button type="button" data-panel="attestation" aria-pressed="<?= $openPanel === 'attestation' ? 'true' : 'false' ?>">
        <span>05</span>
        <strong>Attestation</strong>
        <small>Demande de présence</small>
      </button>
      <?php endif; ?>
    </div>

    <section class="student-space-sheet<?= $openPanel === 'profil' ? ' is-entering' : '' ?>" data-sheet="profil"<?= $openPanel === 'profil' ? '' : ' hidden' ?>>
      <h1>Profil</h1>
      <?php if ($profile !== null): ?>
        <dl>
          <div><dt>Nom</dt><dd><?= e((string) $profile['nom']) ?></dd></div>
          <div><dt>Prénom</dt><dd><?= e((string) $profile['prenom']) ?></dd></div>
          <div><dt>Niveau</dt><dd><?= e(level_label((string) $profile['niveau'])) ?> année</dd></div>
          <div><dt>E-mail</dt><dd><?= e((string) $profile['email']) ?></dd></div>
          <?php $publishedStages = stage_student_published((int) $studentId); ?>
          <div><dt>Stage publié</dt><dd><?php if (!$publishedStages): ?>Aucun stage publié.<?php else: foreach ($publishedStages as $stage): ?><?= e($stage['organisme']) ?><?= $stage['service'] ? ' · ' . e((string) $stage['service']) : '' ?> · <?= e($stage['date_debut'] . ' → ' . $stage['date_fin']) ?> · <?= e(stage_statuses()[(string) $stage['statut']] ?? (string) $stage['statut']) ?> · <a href="stage_dossier.php?id=<?= (int) $stage['id_placement'] ?>&amp;espace=etudiant">Dossier documentaire du stage</a><br><?php endforeach; endif; ?> <a href="stage_demande.php?espace=etudiant">Demande de stage d’été</a></dd></div>
        </dl>
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
                      <td><?= e((string) $session['code_ecue']) ?> — <?= e((string) $session['description_ecue']) ?> <span>(<?= e((string) $session['type_seance']) ?>)</span></td>
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/motion.js?v=3" defer></script>
  <script>
  (function () {
    var COURSE_LINK = /^dashboard\.php\?panel=cours(?:&cours=\d{1,10}(?:&dossier=\d{1,10})?)?$/;
    var buttons = document.querySelectorAll('.student-space-cards > [data-panel]');
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
    window.studentSpace = {
      openPanel: openPanel,
      announce: announce
    };
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        openPanel(button.getAttribute('data-panel'));
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
    function renderList(messages) {
      if (!listEl) return;
      if (!messages || !messages.length) {
        listEl.innerHTML = '<p class="student-inbox-empty">Aucun message</p>';
        return;
      }
      var fragment = document.createDocumentFragment();
      messages.forEach(function (note) {
        var unread = Number(note.is_read) !== 1;
        var link = noteLink(note);
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'student-inbox-item' + (unread ? ' is-unread' : ' is-read');
        item.setAttribute('data-notif-id', String(Number(note.id)));
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
        fragment.appendChild(item);
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
    function markRead(id) {
      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('id_notif', String(id));
      return fetch('etudiant_inbox.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) { if (data) applyInbox(data); })
        .catch(function () {});
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
    if (listEl) {
      listEl.addEventListener('click', function (event) {
        var item = event.target.closest('[data-notif-id]');
        if (!item) return;
        var id = item.getAttribute('data-notif-id');
        var link = item.getAttribute('data-notif-link') || '';
        if (COURSE_LINK.test(link)) {
          followCourseLink(id, link);
          return;
        }
        openMessage(id);
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
</body>
</html>

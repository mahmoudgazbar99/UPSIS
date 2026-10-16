<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

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

// Étape 1 — La session doit porter l'identifiant de l'étudiant connecté.
$studentId = filter_var($_SESSION['id_etudiant'] ?? null, FILTER_VALIDATE_INT);
if ($studentId === false || $studentId <= 0 || !is_student()) {
    dashboard_refuse_session();
}

$profile = null;
$timetable = [];
$grades = [];
$dbError = '';
$attestationOpen = false;
$attestationCin = '';
$attestationMotif = '';
$attestationQueued = false;
$attestationBoard = ['state' => 'none', 'message' => ''];
$inbox = ['unread' => 0, 'stamp' => '0', 'messages' => []];

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
$openPanel = ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'demande_attestation' && $attestationOpen)
    ? 'attestation'
    : 'profil';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Espace étudiant | <?= e(institution_name_inline()) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,650&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="assets/student-space.css?v=6" rel="stylesheet">
</head>
<body class="student-space">
  <header class="student-space-bar">
    <img src="<?= e(institution_logo_src()) ?>" alt="Logo de l'école">
    <div>
      <p><?= e(institution_name_inline()) ?></p>
      <strong><?= e($studentName !== '' ? $studentName : 'Étudiant') ?></strong>
    </div>
    <div class="student-space-bar-actions">
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
                <button type="button" class="student-inbox-item<?= ((int) $note['is_read'] === 1) ? ' is-read' : ' is-unread' ?>" data-notif-id="<?= (int) $note['id'] ?>">
                  <strong><?= e($note['sujet']) ?></strong>
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
  <main class="student-space-main">
    <?php if ($dbError !== ''): ?><div class="student-space-alert" role="alert"><?= e($dbError) ?></div><?php endif; ?>
    <?php flash_show(); ?>
    <div class="student-space-cards" role="tablist">
      <button type="button" data-panel="profil" aria-pressed="<?= $openPanel === 'profil' ? 'true' : 'false' ?>">
        <span>01</span>
        <strong>Profil</strong>
        <small><?= $profile ? 'Niveau ' . e(level_label((string) $profile['niveau'])) : 'Identité' ?></small>
      </button>
      <button type="button" data-panel="emploi" aria-pressed="false">
        <span>02</span>
        <strong>Emploi du temps</strong>
        <small><?= count($timetable) ?> séance<?= count($timetable) > 1 ? 's' : '' ?></small>
      </button>
      <button type="button" data-panel="notes" aria-pressed="false">
        <span>03</span>
        <strong>Notes</strong>
        <small><?= count($grades) ?> matière<?= count($grades) > 1 ? 's' : '' ?></small>
      </button>
      <?php if ($attestationOpen): ?>
      <button type="button" data-panel="attestation" aria-pressed="<?= $openPanel === 'attestation' ? 'true' : 'false' ?>">
        <span>04</span>
        <strong>Attestation</strong>
        <small>Demande de présence</small>
      </button>
      <?php endif; ?>
    </div>

    <section class="student-space-sheet" data-sheet="profil"<?= $openPanel === 'profil' ? '' : ' hidden' ?>>
      <h1>Profil</h1>
      <?php if ($profile !== null): ?>
        <dl>
          <div><dt>Nom</dt><dd><?= e((string) $profile['nom']) ?></dd></div>
          <div><dt>Prénom</dt><dd><?= e((string) $profile['prenom']) ?></dd></div>
          <div><dt>Niveau</dt><dd><?= e(level_label((string) $profile['niveau'])) ?> année</dd></div>
          <div><dt>E-mail</dt><dd><?= e((string) $profile['email']) ?></dd></div>
        </dl>
      <?php endif; ?>
    </section>

    <section class="student-space-sheet" data-sheet="emploi" hidden>
      <h1>Emploi du temps</h1>
      <p class="student-space-lead">Semaine type de votre niveau.</p>
      <?php if ($timetableBySemester === []): ?>
        <p class="student-space-lead">Aucun cours n’est planifié pour vos groupes.</p>
      <?php else: ?>
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

    <section class="student-space-sheet" data-sheet="notes" hidden>
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
    <section class="student-space-sheet" data-sheet="attestation"<?= $openPanel === 'attestation' ? '' : ' hidden' ?>>
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
  <script>
  (function () {
    var buttons = document.querySelectorAll('[data-panel]');
    var sheets = document.querySelectorAll('[data-sheet]');
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        buttons.forEach(function (item) { item.setAttribute('aria-pressed', 'false'); });
        button.setAttribute('aria-pressed', 'true');
        sheets.forEach(function (sheet) {
          sheet.hidden = sheet.getAttribute('data-sheet') !== button.getAttribute('data-panel');
        });
      });
    });

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
    function renderList(messages) {
      if (!listEl) return;
      if (!messages || !messages.length) {
        listEl.innerHTML = '<p class="student-inbox-empty">Aucun message</p>';
        return;
      }
      listEl.innerHTML = messages.map(function (note) {
        var unread = Number(note.is_read) !== 1;
        return '<button type="button" class="student-inbox-item' + (unread ? ' is-unread' : ' is-read') + '" data-notif-id="' + Number(note.id) + '">'
          + '<strong>' + escapeText(note.sujet) + '</strong>'
          + '<span>' + escapeText(note.date) + '</span>'
          + '</button>';
      }).join('');
    }
    function applyInbox(data) {
      if (!data) return;
      inbox = data;
      stamp = String(data.stamp || '');
      setBadge(data.unread);
      renderList(data.messages || []);
      renderAttestationBoard(data.attestation || null);
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
    function openMessage(id) {
      var note = findMessage(id);
      if (!note || !modalEl || typeof bootstrap === 'undefined') return;
      subjectEl.textContent = note.sujet || '';
      dateEl.textContent = note.date || '';
      bodyEl.textContent = note.message_body || '';
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      if (Number(note.is_read) === 1) return;
      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('id_notif', String(id));
      fetch('etudiant_inbox.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) { if (data) applyInbox(data); })
        .catch(function () {});
    }
    if (listEl) {
      listEl.addEventListener('click', function (event) {
        var item = event.target.closest('[data-notif-id]');
        if (!item) return;
        openMessage(item.getAttribute('data-notif-id'));
      });
    }
    function tick() {
      fetch('etudiant_inbox.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
          if (!data) return;
          if (String(data.stamp || '') === stamp) return;
          applyInbox(data);
        })
        .catch(function () {});
    }
    setBadge(inbox.unread);
    setInterval(tick, 2000);
  })();
  </script>
</body>
</html>

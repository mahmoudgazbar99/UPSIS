<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/public_theme.php');
admissions_ensure();
require_candidate();

$candidateId = current_candidate_id();
$cand = db()->prepare('SELECT * FROM admission_candidate WHERE id_candidate = ?');
$cand->execute([$candidateId]);
$candidate = $cand->fetch();
if (!$candidate) {
    header('Location: connexion_candidat.php');
    exit;
}

$apps = db()->prepare(
    'SELECT a.*, camp.label AS campaign_label, s.nom_spec
     FROM admission_application a
     JOIN admission_campaign camp ON camp.id_campaign = a.id_campaign
     LEFT JOIN specialite s ON s.id_spec = a.id_spec
     WHERE a.id_candidate = ?
     ORDER BY a.submitted_at DESC, a.id_application DESC'
);
$apps->execute([$candidateId]);
$applications = $apps->fetchAll();
$appId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: (int) ($applications[0]['id_application'] ?? 0);
$app = null;
foreach ($applications as $row) {
    if ((int) $row['id_application'] === $appId) {
        $app = $row;
        break;
    }
}
if (!$app && $applications) {
    $app = $applications[0];
    $appId = (int) $app['id_application'];
}

$message = '';
$error = '';

if ($app && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'upload' && !empty($_POST['id_doc_type'])) {
            $typeId = (int) $_POST['id_doc_type'];
            if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
                throw new InvalidArgumentException('Choisissez un fichier.');
            }
            admissions_store_document(db(), $appId, $typeId, $_FILES['file']);
            admissions_refresh_document_status($appId);
            admissions_notify($candidateId, 'document_uploaded', 'Document envoyé', 'Votre document a été reçu.', $appId);
            $message = 'Document envoyé.';
        }
        if ($action === 'accept_offer') {
            $dec = db()->prepare(
                'SELECT * FROM admission_decision WHERE id_application = ? AND published = 1 AND decision = "admitted" ORDER BY id_decision DESC LIMIT 1'
            );
            $dec->execute([$appId]);
            $decision = $dec->fetch();
            if (!$decision) {
                throw new InvalidArgumentException('Aucune offre d’admission publiée.');
            }
            if (!empty($decision['offer_deadline']) && (string) $decision['offer_deadline'] < date('Y-m-d')) {
                throw new InvalidArgumentException('Le délai d’acceptation est dépassé.');
            }
            admissions_transition($appId, 'offer_accepted', 'Offre acceptée par le candidat');
            db()->prepare(
                'UPDATE admission_enrollment_checklist SET completed = 1, completed_at = CURRENT_TIMESTAMP, completed_by = ?
                 WHERE id_application = ? AND step_code IN ("decision_confirmed","offer_accepted")'
            )->execute([admissions_actor_label(), $appId]);
            admissions_notify($candidateId, 'offer_accepted', 'Offre acceptée', 'Merci. Suivez les étapes d’inscription finale.', $appId);
            $message = 'Offre acceptée.';
        }
        if ($action === 'decline_offer') {
            admissions_transition($appId, 'withdrawn', 'Offre déclinée par le candidat');
            $message = 'Candidature retirée.';
        }
        if ($action === 'mark_read') {
            db()->prepare('UPDATE admission_notification SET is_read = 1 WHERE id_candidate = ?')->execute([$candidateId]);
            $message = 'Notifications marquées comme lues.';
        }
        $apps->execute([$candidateId]);
        $applications = $apps->fetchAll();
        foreach ($applications as $row) {
            if ((int) $row['id_application'] === $appId) {
                $app = $row;
                break;
            }
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$docs = [];
$tests = [];
$interviews = [];
$decision = null;
$notifs = [];
$checklist = [];
$checklistSplit = ['app' => [], 'admin' => []];
$progress = [];
$printables = admissions_printable_forms();
$graceUntil = null;
if ($app) {
    $progress = admissions_progress_steps($app);
    $graceUntil = $app['docs_grace_until'] ?? null;
    $typeCodes = admissions_doc_types_for_application((string) ($app['application_type'] ?? 'initial'), true);
    $typeIds = array_map(static fn(array $t): int => (int) $t['id_doc_type'], $typeCodes);
    if ($typeIds) {
        $marks = implode(',', array_fill(0, count($typeIds), '?'));
        $d = db()->prepare(
            "SELECT t.id_doc_type, t.label, t.code, t.required, t.requires_legalization, t.channel, t.printable_code, t.hint_fr,
                    d.id_document, d.status, d.rejection_reason, d.original_name, d.uploaded_at
             FROM admission_doc_type t
             LEFT JOIN admission_document d ON d.id_doc_type = t.id_doc_type AND d.id_application = ? AND d.status <> \"replaced\"
             WHERE t.active = 1 AND t.id_doc_type IN ($marks)
             ORDER BY t.sort_order, t.id_doc_type"
        );
        $d->execute(array_merge([$appId], $typeIds));
        $docs = $d->fetchAll();
    }
    $testsQ = db()->prepare(
        'SELECT s.*, ta.attendance, ta.score
         FROM admission_test_assignment ta
         JOIN admission_test_session s ON s.id_session = ta.id_session
         WHERE ta.id_application = ?
         ORDER BY s.session_date, s.session_time'
    );
    $testsQ->execute([$appId]);
    $tests = $testsQ->fetchAll();
    $interviewsQ = db()->prepare('SELECT * FROM admission_interview WHERE id_application = ? ORDER BY interview_date, interview_time');
    $interviewsQ->execute([$appId]);
    $interviews = $interviewsQ->fetchAll();
    $dec = db()->prepare('SELECT * FROM admission_decision WHERE id_application = ? AND published = 1 ORDER BY id_decision DESC LIMIT 1');
    $dec->execute([$appId]);
    $decision = $dec->fetch() ?: null;
    $checklistSplit = admissions_checklist_by_channel($appId);
    $checklist = array_merge($checklistSplit['app'], $checklistSplit['admin']);
}
$n = db()->prepare('SELECT * FROM admission_notification WHERE id_candidate = ? ORDER BY created_at DESC LIMIT 30');
$n->execute([$candidateId]);
$notifs = $n->fetchAll();
$statuses = admissions_statuses();
$stateLabels = [
    'not_started' => 'À venir',
    'in_progress' => 'En cours',
    'completed' => 'Terminé',
    'action_required' => 'Action requise',
    'rejected' => 'Refusé',
];

$docStatusMeta = static function (?string $status): array {
    $status = (string) ($status ?: 'missing');
    return match ($status) {
        'validated', 'approved', 'accepted' => ['label' => 'Validé', 'class' => 'is-ok'],
        'uploaded', 'pending', 'under_review' => ['label' => 'En contrôle', 'class' => 'is-warn'],
        'rejected' => ['label' => 'À remplacer', 'class' => 'is-bad'],
        default => ['label' => 'Manquant', 'class' => 'is-miss'],
    };
};

$statusTone = 'is-waiting';
$statusHint = 'Votre dossier est en cours de traitement. Nous vous notifierons à chaque étape.';
$isWaiting = true;
$progressPct = 8;
$timelineFill = 8;
if ($app) {
    $statusKey = (string) ($app['status'] ?? '');
    $waitingStatuses = [
        'submitted', 'under_review', 'eligible', 'test_scheduled', 'test_completed',
        'interview_scheduled', 'admission_pending', 'waitlisted', 'registration_pending',
    ];
    $actionStatuses = ['documents_incomplete'];
    $successStatuses = ['admitted', 'offer_accepted', 'enrolled'];
    $dangerStatuses = ['rejected', 'withdrawn', 'expired'];

    if (in_array($statusKey, $actionStatuses, true) || ($app['document_status'] ?? '') === 'replacement_requested') {
        $statusTone = 'is-action';
        $statusHint = 'Une action de votre part est requise — complétez ou remplacez les documents manquants.';
    } elseif (in_array($statusKey, $successStatuses, true)) {
        $statusTone = 'is-success';
        $statusHint = $statusKey === 'enrolled'
            ? 'Inscription validée. Vous pouvez accéder à l’espace étudiant avec votre CIN.'
            : 'Bonne nouvelle — suivez les prochaines étapes ci-dessous.';
    } elseif (in_array($statusKey, $dangerStatuses, true)) {
        $statusTone = 'is-danger';
        $statusHint = 'Cette candidature n’est plus active. Contactez la scolarité si besoin.';
    }
    $isWaiting = $statusTone === 'is-waiting';

    $done = 0;
    $current = 0;
    $total = max(count($progress), 1);
    $i = 0;
    foreach ($progress as $step) {
        $i++;
        if (($step['state'] ?? '') === 'completed') {
            $done++;
        } elseif (in_array($step['state'] ?? '', ['in_progress', 'action_required', 'rejected'], true) && $current === 0) {
            $current = $i;
        }
    }
    if ($current === 0) {
        $current = min($done + 1, $total);
    }
    $progressPct = (int) round((($done + (($current > $done) ? 0.45 : 0)) / $total) * 100);
    $progressPct = max(8, min(100, $progressPct));
    $timelineFill = (int) round(($current / $total) * 100);
}

$unreadCount = 0;
foreach ($notifs as $nRow) {
    if ((int) $nRow['is_read'] !== 1) {
        $unreadCount++;
    }
}

$ico = static function (string $inner): string {
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $inner . '</svg>';
};
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php public_theme_boot_script(); ?>
  <title>Espace candidat | <?= e(institution_name_inline()) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/admissions-lightswind.css?v=5" rel="stylesheet">
  <link href="assets/espace-candidat-lw.css?v=1" rel="stylesheet">
</head>
<body class="adm-lw-page adm-lw">
  <?php public_theme_atmosphere_markup(); ?>
  <?php if ($isWaiting && $app): ?><div class="adm-ec-topline" aria-hidden="true"></div><?php endif; ?>

  <div class="adm-ec">
    <header class="adm-ec-top adm-ec-reveal">
      <div>
        <p class="adm-ec-kicker">Espace candidat</p>
        <h1 class="adm-ec-name"><?= e(trim($candidate['prenom'] . ' ' . $candidate['nom'])) ?></h1>
      </div>
      <div class="adm-ec-actions">
        <?php public_theme_toggle_button(); ?>
        <a class="btn btn-sm btn-shine" href="preinscription.php">Nouvelle candidature</a>
        <a class="btn btn-sm btn-ghost" href="logout.php?espace=candidat">Déconnexion</a>
      </div>
    </header>

    <div class="adm-ec-stack adm-ec-glow-zone">
      <?php if ($message): ?><div class="alert alert-success mb-0 adm-ec-reveal"><?= e($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger mb-0 adm-ec-reveal"><?= e($error) ?></div><?php endif; ?>

      <?php if (!$app): ?>
        <section class="adm-ec-card adm-ec-reveal" data-glow>
          <span class="adm-ec-beam" aria-hidden="true"></span>
          <h2 class="adm-ec-card-title">
            <span class="adm-ec-ico"><?= $ico('<path d="M12 5v14"/><path d="M5 12h14"/>') ?></span>
            Aucune candidature
          </h2>
          <p class="adm-ec-empty mb-3">Déposez une préinscription pour suivre votre parcours ici.</p>
          <a class="btn btn-shine" href="preinscription.php">Commencer</a>
        </section>
      <?php else: ?>
        <?php if (count($applications) > 1): ?>
          <section class="adm-ec-card adm-ec-reveal" data-glow>
            <label class="form-label fw-semibold" for="app-switch">Candidature</label>
            <select class="form-select" id="app-switch" onchange="location.href='espace_candidat.php?id='+this.value">
              <?php foreach ($applications as $row): ?>
                <option value="<?= (int) $row['id_application'] ?>" <?= (int) $row['id_application'] === $appId ? 'selected' : '' ?>>
                  <?= e($row['application_number'] . ' — ' . $row['campaign_label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </section>
        <?php endif; ?>

        <section class="adm-ec-hero <?= e($statusTone) ?> adm-ec-reveal" data-glow style="--progress: <?= (int) $progressPct ?>%; --timeline-fill: <?= (int) $timelineFill ?>%;">
          <span class="adm-ec-beam" aria-hidden="true"></span>
          <?php if ($isWaiting): ?><div class="adm-ec-hero-shimmer" aria-hidden="true"></div><?php endif; ?>
          <div class="adm-ec-hero-inner">
            <div class="adm-ec-hero-row">
              <div>
                <div class="small text-secondary">Référence</div>
                <div class="adm-ec-ref"><?= e($app['application_number']) ?></div>
                <p class="mb-0 mt-2"><strong><?= e($app['campaign_label']) ?></strong> · <?= e((string) $app['academic_year_label']) ?></p>
                <p class="adm-ec-hint mb-0 mt-1">
                  <?= e((string) ($app['nom_spec'] ?? '—')) ?>
                  · déposée le <?= e(substr((string) ($app['submitted_at'] ?? ''), 0, 10)) ?>
                </p>
              </div>
              <div class="text-end">
                <?php if ($isWaiting): ?>
                  <span class="adm-ec-wait" role="status">
                    <span class="adm-ec-ripple" aria-hidden="true"></span>
                    En attente
                    <span class="adm-ec-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                  </span>
                <?php endif; ?>
                <div class="mt-2">
                  <span class="adm-lw-badge"><?= e($statuses[$app['status']] ?? $app['status']) ?></span>
                </div>
              </div>
            </div>

            <p class="adm-ec-hint"><?= e($statusHint) ?></p>

            <div class="adm-ec-meter" aria-label="Avancement du dossier">
              <div class="adm-ec-meter-label">
                <span>Parcours</span>
                <span><?= (int) $progressPct ?>%</span>
              </div>
              <div class="adm-ec-meter-track">
                <div class="adm-ec-meter-fill" style="--progress: <?= (int) $progressPct ?>%"></div>
              </div>
            </div>

            <div class="adm-ec-timeline" style="--timeline-fill: <?= (int) $timelineFill ?>%" aria-label="Étapes de candidature">
              <div class="adm-ec-timeline-fill" aria-hidden="true"></div>
              <?php foreach ($progress as $step): ?>
                <div class="adm-ec-tl-item <?= e($step['state']) ?>">
                  <div>
                    <strong><?= e($step['label']) ?></strong>
                    <span><?= e($stateLabels[$step['state']] ?? $step['state']) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <section class="adm-ec-card adm-ec-reveal" data-glow>
          <span class="adm-ec-beam" aria-hidden="true"></span>
          <h2 class="adm-ec-card-title">
            <span class="adm-ec-ico"><?= $ico('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>') ?></span>
            Formulaires à imprimer
          </h2>
          <p class="adm-ec-hint mb-3">Certaines pièces nécessitent une signature (éventuellement légalisée) hors application, puis un scan ici ou un dépôt à l’administration.</p>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($printables as $code => $meta): ?>
              <a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="admission_formulaire.php?type=<?= e(urlencode($code)) ?>&amp;id=<?= (int) $appId ?>">
                <?= e($meta['title']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="adm-ec-card adm-ec-reveal" data-glow>
          <span class="adm-ec-beam" aria-hidden="true"></span>
          <h2 class="adm-ec-card-title">
            <span class="adm-ec-ico"><?= $ico('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h8"/><path d="M8 9h2"/>') ?></span>
            Documents (dossier électronique)
          </h2>
          <?php if ($graceUntil): ?>
            <p class="adm-ec-hint">Délai pour pièces manquantes : avant le <?= e((string) $graceUntil) ?> (15 jours après la rentrée selon le règlement).</p>
          <?php endif; ?>
          <?php foreach ($docs as $doc): ?>
            <?php $meta = $docStatusMeta(isset($doc['status']) ? (string) $doc['status'] : null); ?>
            <div class="adm-ec-doc">
              <div>
                <strong><?= e($doc['label']) ?></strong>
                <?php if ((int) $doc['required'] === 1): ?><span class="text-danger">*</span><?php endif; ?>
                <div class="mt-1">
                  <span class="adm-ec-chip <?= e($meta['class']) ?>"><?= e($meta['label']) ?></span>
                  <?php if ((int) ($doc['requires_legalization'] ?? 0) === 1): ?>
                    <span class="adm-ec-chip is-warn">Signature légalisée → admin</span>
                  <?php endif; ?>
                  <?php if (($doc['channel'] ?? '') === 'both'): ?>
                    <span class="adm-ec-chip">App + administration</span>
                  <?php endif; ?>
                  <?php if (($doc['status'] ?? '') === 'rejected' && ($doc['rejection_reason'] ?? '') !== ''): ?>
                    <span class="small text-secondary ms-1"><?= e($doc['rejection_reason']) ?></span>
                  <?php endif; ?>
                </div>
                <?php if (trim((string) ($doc['hint_fr'] ?? '')) !== ''): ?>
                  <div class="small text-secondary mt-1"><?= e((string) $doc['hint_fr']) ?></div>
                <?php endif; ?>
                <?php if (trim((string) ($doc['printable_code'] ?? '')) !== ''): ?>
                  <div class="mt-1">
                    <a class="small" target="_blank" rel="noopener" href="admission_formulaire.php?type=<?= e(urlencode((string) $doc['printable_code'])) ?>&amp;id=<?= (int) $appId ?>">Télécharger le modèle</a>
                  </div>
                <?php endif; ?>
              </div>
              <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center flex-wrap">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="id_doc_type" value="<?= (int) $doc['id_doc_type'] ?>">
                <input type="file" name="file" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png,.webp">
                <button class="btn btn-sm btn-shine" type="submit">Envoyer</button>
              </form>
            </div>
          <?php endforeach; ?>
        </section>

        <div class="adm-ec-grid-2">
          <section class="adm-ec-card adm-ec-reveal" data-glow>
            <h2 class="adm-ec-card-title">
              <span class="adm-ec-ico"><?= $ico('<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>') ?></span>
              Tests
            </h2>
            <?php if (!$tests): ?>
              <p class="adm-ec-empty">Aucun test planifié — restez à l’écoute.</p>
            <?php endif; ?>
            <?php foreach ($tests as $t): ?>
              <div class="adm-ec-event">
                <strong><?= e($t['name']) ?></strong>
                <div class="small mt-1"><?= e($t['session_date'] . ' · ' . substr((string) $t['session_time'], 0, 5)) ?></div>
                <div class="small text-secondary"><?= e(trim($t['location'] . ' ' . $t['room'])) ?></div>
                <div class="mt-2"><span class="adm-ec-chip"><?= e($t['attendance']) ?></span></div>
              </div>
            <?php endforeach; ?>
          </section>

          <section class="adm-ec-card adm-ec-reveal" data-glow>
            <h2 class="adm-ec-card-title">
              <span class="adm-ec-ico"><?= $ico('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') ?></span>
              Entretiens
            </h2>
            <?php if (!$interviews): ?>
              <p class="adm-ec-empty">Aucun entretien planifié pour le moment.</p>
            <?php endif; ?>
            <?php foreach ($interviews as $i): ?>
              <div class="adm-ec-event">
                <strong><?= e($i['interview_date'] . ' · ' . substr((string) $i['interview_time'], 0, 5)) ?></strong>
                <div class="small text-secondary mt-1"><?= e(trim($i['location'] . ' ' . $i['room'])) ?></div>
                <div class="mt-2"><span class="adm-ec-chip is-warn"><?= e($i['status']) ?></span></div>
              </div>
            <?php endforeach; ?>
          </section>
        </div>

        <section class="adm-ec-card adm-ec-reveal" data-glow>
          <span class="adm-ec-beam" aria-hidden="true"></span>
          <h2 class="adm-ec-card-title">
            <span class="adm-ec-ico"><?= $ico('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/>') ?></span>
            Décision
          </h2>
          <?php if (!$decision): ?>
            <div class="adm-ec-event">
              <span class="adm-ec-wait" role="status">
                <span class="adm-ec-ripple" aria-hidden="true"></span>
                Décision en attente
                <span class="adm-ec-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
              </span>
              <p class="adm-ec-empty mt-3 mb-0">Aucune décision n’a encore été publiée.</p>
            </div>
          <?php else: ?>
            <p class="mb-1"><strong><?= e($decision['decision']) ?></strong> — <?= e((string) $decision['decision_date']) ?></p>
            <?php if ($decision['decision'] === 'admitted' && in_array($app['status'], ['admitted'], true)): ?>
              <p class="small text-secondary">Délai d’acceptation : <?= e((string) ($decision['offer_deadline'] ?? '—')) ?></p>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="accept_offer">
                  <button class="btn btn-shine btn-sm" type="submit" onclick="return confirm('Accepter l’offre d’admission ?')">Accepter l’offre</button>
                </form>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="decline_offer">
                  <button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('Décliner et retirer la candidature ?')">Décliner</button>
                </form>
              </div>
            <?php elseif ($app['status'] === 'offer_accepted' || $app['status'] === 'registration_pending'): ?>
              <p class="mb-3" style="color:var(--adm-accent)">Offre acceptée — inscription finale en cours.</p>
              <h3 class="h6 mt-3">Sur l’application</h3>
              <ul class="adm-ec-check">
                <?php foreach ($checklistSplit['app'] as $c): ?>
                  <li class="<?= (int) $c['completed'] === 1 ? 'is-done' : '' ?>">
                    <span class="mark" aria-hidden="true"><?= (int) $c['completed'] === 1 ? '✓' : '' ?></span>
                    <?= e($c['step_label']) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
              <h3 class="h6 mt-3">À l’administration (hors application)</h3>
              <p class="adm-ec-hint">Test devant commission, signature légalisée, originaux, carte étudiant / badge.</p>
              <ul class="adm-ec-check">
                <?php foreach ($checklistSplit['admin'] as $c): ?>
                  <li class="<?= (int) $c['completed'] === 1 ? 'is-done' : '' ?>">
                    <span class="mark" aria-hidden="true"><?= (int) $c['completed'] === 1 ? '✓' : '' ?></span>
                    <?= e($c['step_label']) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php elseif ($app['status'] === 'enrolled'): ?>
              <p class="mb-0">Inscription validée. Connectez-vous à l’<a href="connexion_etudiant.php">espace étudiant</a> avec votre CIN. La carte / le badge se retirent à l’administration.</p>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section class="adm-ec-card adm-ec-reveal" data-glow>
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="adm-ec-card-title mb-0">
              <span class="adm-ec-ico"><?= $ico('<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>') ?></span>
              Notifications
              <?php if ($unreadCount > 0): ?>
                <span class="adm-lw-badge"><?= (int) $unreadCount ?></span>
              <?php endif; ?>
            </h2>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="mark_read">
              <button class="btn btn-sm btn-ghost" type="submit">Tout marquer lu</button>
            </form>
          </div>
          <?php if (!$notifs): ?>
            <p class="adm-ec-empty">Aucune notification.</p>
          <?php endif; ?>
          <?php foreach ($notifs as $nRow): ?>
            <div class="adm-ec-notif <?= (int) $nRow['is_read'] === 1 ? 'is-read' : 'is-unread' ?>">
              <strong><?= e($nRow['title']) ?></strong>
              <div class="small text-secondary"><?= e(substr((string) $nRow['created_at'], 0, 16)) ?> · <?= e($nRow['body']) ?></div>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </div>
  </div>

  <?php public_theme_footer_script(); ?>
  <script src="assets/espace-candidat-lw.js?v=1" defer></script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_admin();

$pdo = db();
attestation_ensure_tables($pdo);
$message = '';
$error = '';
$downloadCin = '';
$downloadName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'toggle') {
            $active = (string) ($_POST['attestation_feature_active'] ?? '0') === '1' ? '1' : '0';
            $pdo->beginTransaction();
            if ($active === '0') {
                $pdo->exec('DELETE FROM demande_attestation');
            }
            $update = $pdo->prepare(
                'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $update->execute(['attestation_feature_active', $active]);
            $pdo->commit();
            $message = $active === '1'
                ? 'Les étudiants peuvent demander une attestation de présence.'
                : 'La demande d’attestation de présence est désactivée. Toutes les demandes ont été supprimées.';
        } elseif ($action === 'acceptee') {
            $demandeId = filter_input(INPUT_POST, 'id_demande', FILTER_VALIDATE_INT) ?: 0;
            $pickupLabel = attestation_pickup_from_request();
            if ($demandeId < 1 || $pickupLabel === null) {
                $error = 'Indiquez une date et une heure de retrait valides.';
            } else {
                $pdo->beginTransaction();
                $select = $pdo->prepare(
                    'SELECT id_etudiant, cin FROM demande_attestation
                     WHERE id_demande = ? AND statut = "en_attente"
                     LIMIT 1
                     FOR UPDATE'
                );
                $select->execute([$demandeId]);
                $demande = $select->fetch();
                if (!$demande) {
                    $pdo->rollBack();
                    $error = 'Cette demande n’est plus en attente.';
                } else {
                    $studentRow = $pdo->prepare(
                        'SELECT prenom, nom, cin_passeport FROM etudiant WHERE id_etudiant = ? LIMIT 1'
                    );
                    $studentRow->execute([(int) $demande['id_etudiant']]);
                    $studentInfo = $studentRow->fetch() ?: [];
                    $update = $pdo->prepare(
                        'UPDATE demande_attestation SET statut = "acceptee", date_acceptation = NOW()
                         WHERE id_demande = ? AND statut = "en_attente"'
                    );
                    $update->execute([$demandeId]);
                    student_notify(
                        $pdo,
                        (int) $demande['id_etudiant'],
                        'Attestation de présence',
                        'Votre attestation est prête. Veuillez passer au bureau des affaires étudiantes le '
                        . $pickupLabel
                        . ' pour la récupérer.'
                    );
                    $pdo->commit();
                    $downloadCin = (string) (($studentInfo['cin_passeport'] ?? '') ?: $demande['cin']);
                    $downloadName = trim((string) ($studentInfo['prenom'] ?? '') . ' ' . (string) ($studentInfo['nom'] ?? ''));
                    $message = 'Demande acceptée. L’étudiant a été notifié.';
                    audit_record(
                        'presence',
                        'accepter',
                        'Demande d’attestation #' . $demandeId . ' — retrait ' . $pickupLabel
                    );
                }
            }
        } elseif ($action === 'refusee') {
            $demandeId = filter_input(INPUT_POST, 'id_demande', FILTER_VALIDATE_INT) ?: 0;
            $pdo->beginTransaction();
            $select = $pdo->prepare(
                'SELECT id_etudiant FROM demande_attestation
                 WHERE id_demande = ? AND statut = "en_attente"
                 LIMIT 1
                 FOR UPDATE'
            );
            $select->execute([$demandeId]);
            $demande = $select->fetch();
            if (!$demande) {
                $pdo->rollBack();
                $error = 'Cette demande n’est plus en attente.';
            } else {
                $delete = $pdo->prepare(
                    'DELETE FROM demande_attestation WHERE id_demande = ? AND statut = "en_attente"'
                );
                $delete->execute([$demandeId]);
                student_notify(
                    $pdo,
                    (int) $demande['id_etudiant'],
                    'Demande d’attestation',
                    'Votre demande d\'attestation de présence a été refusée.'
                );
                $pdo->commit();
                $message = 'Demande refusée et supprimée. L’étudiant a été notifié.';
                audit_record(
                    'presence',
                    'refuser',
                    'Demande d’attestation #' . $demandeId . ' supprimée'
                );
            }
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Erreur lors de la récupération des données';
    }
}

$featureActive = attestation_feature_active($pdo);
$pending = [];
try {
    $pending = $pdo->query(
        'SELECT d.id_demande, d.cin, d.motif, d.date_demande, e.nom, e.prenom, e.cin_passeport
         FROM demande_attestation d
         JOIN etudiant e ON e.id_etudiant = d.id_etudiant
         WHERE d.statut = "en_attente"
         ORDER BY d.date_demande ASC'
    )->fetchAll();
} catch (PDOException $exception) {
    $error = 'Erreur lors de la récupération des données';
}

page_head('Demandes de présence', 'attestation');
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
  <div>
    <p class="eyebrow">Documents</p>
    <h1 class="display-6 fw-bold mb-1">Les demandes de présences</h1>
    <p class="text-secondary mb-0">Activez le service, puis acceptez ou refusez chaque demande étudiante.</p>
  </div>
  <form method="post" class="attestation-toggle-form" id="attestationToggleForm">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="toggle">
    <input type="hidden" name="attestation_feature_active" value="0">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch" id="attestation_feature_active" name="attestation_feature_active" value="1" <?= $featureActive ? 'checked' : '' ?>>
      <label class="form-check-label" for="attestation_feature_active"><?= $featureActive ? 'Service activé' : 'Service désactivé' ?></label>
    </div>
  </form>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card">
  <div class="card-body p-4 pb-3">
    <h2 class="h4 mb-1">Les demandes de présences</h2>
    <p class="text-secondary mb-0" id="pendingSummary"><?= count($pending) ?> demande<?= count($pending) > 1 ? 's' : '' ?> en attente.</p>
  </div>
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0" id="demandesTable">
      <thead>
        <tr>
          <th>Étudiant</th>
          <th>CIN</th>
          <th>Date</th>
          <th>Motif</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pending as $row): ?>
        <tr>
          <td><?= e(trim($row['prenom'] . ' ' . $row['nom'])) ?></td>
          <td><?= e((string) $row['cin']) ?></td>
          <td><?= e(date('d/m/Y H:i', strtotime((string) $row['date_demande']))) ?></td>
          <td><?= e((string) $row['motif']) ?></td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-success" data-accept-demande="<?= (int) $row['id_demande'] ?>">Accepter</button>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id_demande" value="<?= (int) $row['id_demande'] ?>">
              <button class="btn btn-sm btn-danger" name="action" value="refusee">Refuser</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pending): ?>
        <tr><td colspan="5" class="text-center text-secondary py-5">Aucune demande en attente.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="attestation-accept-overlay" id="acceptAttestationModal" hidden>
  <div class="attestation-accept-dialog" role="dialog" aria-modal="true" aria-labelledby="acceptAttestationTitle">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="acceptee">
      <input type="hidden" name="id_demande" id="accept_id_demande" value="">
      <div class="attestation-accept-header">
        <h2 class="h5 mb-0" id="acceptAttestationTitle">Accepter la demande</h2>
        <button type="button" class="btn-close btn-close-white" data-accept-close aria-label="Fermer"></button>
      </div>
      <div class="attestation-accept-body">
        <p class="text-secondary mb-3">Choisissez la date et l’heure auxquelles l’étudiant pourra retirer son attestation.</p>
        <div class="attestation-accept-datetime">
          <div>
            <label class="form-label" for="date_retrait_jour">Date de retrait</label>
            <input class="form-control" type="date" id="date_retrait_jour" name="date_retrait_jour" required autocomplete="off">
          </div>
          <div>
            <label class="form-label" for="date_retrait_heure">Heure de retrait</label>
            <input class="form-control" type="time" id="date_retrait_heure" name="date_retrait_heure" step="60" required autocomplete="off">
          </div>
        </div>
      </div>
      <div class="attestation-accept-footer">
        <button type="button" class="btn btn-outline-secondary" data-accept-close>Annuler</button>
        <button type="submit" class="btn btn-success">Confirmer l’acceptation</button>
      </div>
    </form>
  </div>
</div>
<div class="attestation-accept-overlay" id="downloadPresenceModal"<?= $downloadCin !== '' ? '' : ' hidden' ?>>
  <div class="attestation-accept-dialog" role="dialog" aria-modal="true" aria-labelledby="downloadPresenceTitle">
    <div class="attestation-accept-header">
      <h2 class="h5 mb-0" id="downloadPresenceTitle">Attestation de présence</h2>
      <button type="button" class="btn-close btn-close-white" data-download-close aria-label="Fermer"></button>
    </div>
    <div class="attestation-accept-body">
      <p class="text-secondary mb-0">
        <?= $downloadName !== ''
          ? 'La demande de ' . e($downloadName) . ' a été acceptée. Téléchargez son attestation de présence.'
          : 'Téléchargez l’attestation de présence de l’étudiant.' ?>
      </p>
    </div>
    <div class="attestation-accept-footer">
      <button type="button" class="btn btn-outline-secondary" data-download-close>Fermer</button>
      <?php if ($downloadCin !== ''): ?>
        <a class="btn btn-success" href="generate_presence.php?cin=<?= urlencode($downloadCin) ?>" target="_blank" rel="noopener">Télécharger l’attestation de présence</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  const csrf = <?= json_encode(csrf_token()) ?>;
  const tbody = document.querySelector('#demandesTable tbody');
  const summary = document.getElementById('pendingSummary');
  const modalEl = document.getElementById('acceptAttestationModal');
  const idField = document.getElementById('accept_id_demande');
  const dateField = document.getElementById('date_retrait_jour');
  const timeField = document.getElementById('date_retrait_heure');
  const toggleForm = document.getElementById('attestationToggleForm');
  const toggle = document.getElementById('attestation_feature_active');
  let stamp = '';

  function escapeText(value) {
    const node = document.createElement('div');
    node.textContent = value;
    return node.innerHTML;
  }
  function pad(value) {
    return String(value).padStart(2, '0');
  }
  function resetDialogPosition(overlay) {
    const dialog = overlay && overlay.querySelector('.attestation-accept-dialog');
    if (!dialog) return;
    dialog.classList.remove('is-moved', 'is-dragging');
    dialog.style.left = '';
    dialog.style.top = '';
    dialog.style.transform = '';
  }
  function enableDialogDrag(overlay) {
    if (!overlay) return;
    const dialog = overlay.querySelector('.attestation-accept-dialog');
    const handle = overlay.querySelector('.attestation-accept-header');
    if (!dialog || !handle || handle.dataset.dragReady === '1') return;
    handle.dataset.dragReady = '1';
    let drag = null;
    handle.addEventListener('pointerdown', function (event) {
      if (event.button !== 0) return;
      if (event.target.closest('button, a, input, label, select, textarea')) return;
      const overlayRect = overlay.getBoundingClientRect();
      const rect = dialog.getBoundingClientRect();
      dialog.classList.add('is-moved', 'is-dragging');
      dialog.style.left = (rect.left - overlayRect.left) + 'px';
      dialog.style.top = (rect.top - overlayRect.top) + 'px';
      dialog.style.transform = 'none';
      drag = {
        pointerId: event.pointerId,
        offsetX: event.clientX - rect.left,
        offsetY: event.clientY - rect.top
      };
      handle.setPointerCapture(event.pointerId);
      event.preventDefault();
    });
    handle.addEventListener('pointermove', function (event) {
      if (!drag || event.pointerId !== drag.pointerId) return;
      const maxLeft = Math.max(0, overlay.clientWidth - dialog.offsetWidth);
      const maxTop = Math.max(0, overlay.clientHeight - dialog.offsetHeight);
      const left = Math.min(Math.max(0, event.clientX - overlay.getBoundingClientRect().left - drag.offsetX), maxLeft);
      const top = Math.min(Math.max(0, event.clientY - overlay.getBoundingClientRect().top - drag.offsetY), maxTop);
      dialog.style.left = left + 'px';
      dialog.style.top = top + 'px';
    });
    function endDrag(event) {
      if (!drag || event.pointerId !== drag.pointerId) return;
      drag = null;
      dialog.classList.remove('is-dragging');
      try { handle.releasePointerCapture(event.pointerId); } catch (error) {}
    }
    handle.addEventListener('pointerup', endDrag);
    handle.addEventListener('pointercancel', endDrag);
  }
  function closeAcceptModal() {
    if (!modalEl) return;
    modalEl.hidden = true;
    resetDialogPosition(modalEl);
  }
  function openAcceptModal(demandeId) {
    if (!modalEl || !idField) return;
    idField.value = String(demandeId);
    const now = new Date();
    if (dateField) {
      dateField.value = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    }
    if (timeField) {
      timeField.value = pad(now.getHours()) + ':' + pad(now.getMinutes());
    }
    resetDialogPosition(modalEl);
    modalEl.hidden = false;
  }
  function actionCell(id) {
    return '<button type="button" class="btn btn-sm btn-success" data-accept-demande="' + Number(id) + '">Accepter</button> '
      + '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' + escapeText(csrf) + '"><input type="hidden" name="id_demande" value="' + Number(id) + '"><button class="btn btn-sm btn-danger" name="action" value="refusee">Refuser</button></form>';
  }
  function draw(data) {
    if (!tbody) return;
    const rows = data.requests || [];
    if (summary) {
      summary.textContent = rows.length + ' demande' + (rows.length > 1 ? 's' : '') + ' en attente.';
    }
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-secondary py-5">Aucune demande en attente.</td></tr>';
      closeAcceptModal();
      return;
    }
    tbody.innerHTML = rows.map(function (row) {
      return '<tr>'
        + '<td>' + escapeText(row.name) + '</td>'
        + '<td>' + escapeText(row.cin) + '</td>'
        + '<td>' + escapeText(row.date) + '</td>'
        + '<td>' + escapeText(row.motif) + '</td>'
        + '<td class="text-end">' + actionCell(row.id) + '</td></tr>';
    }).join('');
  }
  window.attestationLiveReload = draw;
  if (tbody) {
    tbody.addEventListener('click', function (event) {
      const button = event.target.closest('[data-accept-demande]');
      if (!button) return;
      event.preventDefault();
      openAcceptModal(button.getAttribute('data-accept-demande'));
    });
  }
  if (modalEl) {
    modalEl.querySelectorAll('[data-accept-close]').forEach(function (button) {
      button.addEventListener('click', closeAcceptModal);
    });
  }
  function openNativePicker(input) {
    if (!input || typeof input.showPicker !== 'function') return;
    try {
      input.showPicker();
    } catch (error) {
      input.focus();
    }
  }
  [dateField, timeField].forEach(function (input) {
    if (!input) return;
    input.addEventListener('click', function () {
      openNativePicker(input);
    });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openNativePicker(input);
      }
    });
  });
  const downloadEl = document.getElementById('downloadPresenceModal');
  if (downloadEl) {
    downloadEl.querySelectorAll('[data-download-close]').forEach(function (button) {
      button.addEventListener('click', function () {
        downloadEl.hidden = true;
        resetDialogPosition(downloadEl);
      });
    });
  }
  enableDialogDrag(modalEl);
  enableDialogDrag(downloadEl);
  if (toggleForm && toggle) {
    toggle.addEventListener('change', function () {
      if (!toggle.checked && !confirm('Désactiver le service effacera toutes les demandes d’attestation. Continuer ?')) {
        toggle.checked = true;
        return;
      }
      toggleForm.submit();
    });
  }
  function tick() {
    fetch('attestation_live.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data) return;
        if (!stamp) {
          stamp = String(data.stamp || '');
          return;
        }
        if (data.stamp === stamp) return;
        stamp = String(data.stamp || '');
        draw(data);
      })
      .catch(function () {});
  }
  tick();
  setInterval(tick, 2000);
})();
</script>
<?php page_foot();

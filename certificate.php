<?php
require_once __DIR__ . '/layout.php'; 
require_admin();

function certificate_page(string $type, string $title, bool $arabic = false): void {
    $cin = trim($_GET['cin'] ?? '');
    $student = null;
    $error = '';

    if ($cin !== '') {
        $stmt = db()->prepare('SELECT e.*, s.nom_spec, s.nom_spec_ar FROM etudiant e JOIN specialite s ON s.id_spec=e.id_spec WHERE e.cin_passeport=?');
        $stmt->execute([$cin]);
        $student = $stmt->fetch();
        if (!$student) {
            $error = 'Aucun étudiant ne correspond à ce CIN / passeport.';
        }
    }

    page_head($title, 'certificates');
    ?>
    
    <div class="card app-card no-print mb-4">
        <div class="card-body p-4">
            <form class="row g-3 align-items-end" method="get">
                <div class="col-md-9">
                    <label class="form-label">Rechercher par CIN / passeport</label>
                    <input class="form-control form-control-lg" name="cin" value="<?= e($cin) ?>" placeholder="Saisissez le CIN ou le passeport" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100 btn-lg">Générer</button>
                </div>
            </form>
            <?php if ($error): ?>
                <div class="alert alert-danger mt-3 mb-0"><?= e($error) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($student): ?>
        <?php
        $docxGenerator = match ($type) {
            'success' => 'generate_reussite.php',
            'presence' => 'generate_presence.php',
            default => 'generate_word.php',
        };
        $docxTitle = match ($type) {
            'success' => 'Aperçu de l’attestation de réussite',
            'presence' => 'Aperçu de l’attestation de présence',
            default => 'Aperçu de l’attestation d’inscription',
        };
        ?>
        <section class="docx-preview-shell mb-4">
            <div class="d-flex justify-content-between align-items-center gap-3 mb-3 flex-wrap">
                <h2 class="h5 mb-0"><?= e($docxTitle) ?></h2>
                <div class="certificate-download-actions d-flex flex-wrap gap-2">
                    <a href="<?= e($docxGenerator) ?>?cin=<?= urlencode($cin) ?>" class="btn btn-primary">
                        Télécharger Word
                    </a>
                    <a href="<?= e($docxGenerator) ?>?cin=<?= urlencode($cin) ?>&amp;format=pdf" class="btn btn-outline-danger">
                        Télécharger PDF
                    </a>
                </div>
            </div>
            <div id="docx-preview" class="docx-preview" aria-live="polite">
                Chargement de l’aperçu du document…
            </div>
        </section>
        <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
        <script src="https://unpkg.com/docx-preview@0.3.3/dist/docx-preview.min.js?v=2"></script>
        <script>
        (async function () {
            const preview = document.getElementById('docx-preview');
            try {
                const response = await fetch('<?= e($docxGenerator) ?>?cin=<?= rawurlencode($cin) ?>&preview=1', {
                    headers: { 'Accept': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }
                });
                if (!response.ok) {
                    throw new Error('Impossible de générer le document.');
                }
                const blob = await response.blob();
                preview.replaceChildren();
                await docx.renderAsync(blob, preview, null, {
                    className: 'docx',
                    inWrapper: true,
                    breakPages: true
                });
            } catch (error) {
                preview.textContent = 'Impossible de charger l’aperçu. Vous pouvez télécharger le document Word.';
            }
        })();
        </script>
    <?php endif; ?>

    <?php 
    page_foot();
}

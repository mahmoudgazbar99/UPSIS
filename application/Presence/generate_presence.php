<?php
require_once app_path('bootstrap.php');
require_permission('documents.issue');
require_once app_path('support/certificate_export.php');
require_once root_path('vendor/autoload.php');

$cin = trim($_GET['cin'] ?? '');
if ($cin === '') {
    http_response_code(400);
    exit('Erreur : CIN non fourni.');
}

$stmt = db()->prepare('
    SELECT e.*, s.nom_spec, s.nom_spec_ar, s.id_program
    FROM etudiant e
    LEFT JOIN specialite s ON s.id_spec = e.id_spec
    WHERE e.cin_passeport = ?
');
$stmt->execute([$cin]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    exit('Erreur : Étudiant introuvable dans la base de données.');
}

$templatePath = root_path('templates/template_presence_2.docx');
if (!is_file($templatePath)) {
    http_response_code(500);
    exit('Erreur : Le modèle Word de présence est introuvable.');
}

$dateNaissance = '';
if (!empty($student['date_naissance'])) {
    $timestamp = strtotime($student['date_naissance']);
    $dateNaissance = $timestamp ? date('Y/m/d', $timestamp) : (string) $student['date_naissance'];
}

$currentYear = (int) date('Y');
$fileBase = 'Attestation_Presence_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $student['cin_passeport']);
$docxPath = null;
$logoFile = null;
$cachetFile = null;

try {
    $institution = institution_brand();
    $logoFile = institution_logo_file();
    $cachetFile = certificate_cachet_file();
    $niveau = (string) ($student['niveau_etude'] ?? $student['niveau'] ?? '');
    $programId = (int) ($student['id_program'] ?? 0);
    $programId = $programId > 0 ? $programId : null;
    $docxPath = institution_copy_docx_template($templatePath);
    institution_replace_docx_placeholders($docxPath, [
        'annee_univ' => institution_ltr_text(($currentYear + 1) . '/' . $currentYear),
        'prenom_ar' => institution_rtl_text((string) ($student['prenom_ar'] ?? '')),
        'nom_ar' => institution_rtl_text((string) ($student['nom_ar'] ?? '')),
        'lieu_naissance_ar' => institution_rtl_text((string) ($student['lieu_naissance_ar'] ?? '')),
        'date_naissance' => institution_ltr_text($dateNaissance),
        'cin' => institution_ltr_text((string) ($student['cin_passeport'] ?? '')),
        'specialite_ar' => institution_rtl_text((string) ($student['nom_spec_ar'] ?? $student['nom_spec'] ?? '')),
        'logo' => $logoFile['path'],
        'المستوى' => study_level_label_ar($niveau, $programId),
        'الاجازة' => institution_award_title_ar($programId),
        'inscription' => institution_ltr_text((string) ($student['num_inscription'] ?? '')),
        'date_du_jour' => institution_ltr_text(date('Y/m/d')),
        'sec_generale' => institution_rtl_text($institution['sec_generale_ar']),
        'establishment_ar' => institution_rtl_text($institution['establishment_ar']),
        'establishment_fr' => $institution['establishment_fr'],
    ]);

    $preview = ($_GET['preview'] ?? '') === '1';
    $numero = certificate_allocate_number('presence', !$preview);
    certificate_docx_apply_extras($docxPath, $numero, $cachetFile['path'] ?? null);
    $asPdf = !$preview && certificate_wants_pdf();
    certificate_send_generated_document($docxPath, $fileBase, $asPdf, $preview, true);
} catch (Throwable $exception) {
    error_log('generate_presence: ' . $exception->getMessage());
    http_response_code(500);
    echo 'Erreur lors de la génération du document.';
} finally {
    if (is_string($docxPath) && is_file($docxPath)) {
        unlink($docxPath);
    }
    if (is_array($logoFile) && !empty($logoFile['temporary']) && is_file($logoFile['path'])) {
        unlink($logoFile['path']);
    }
    if (is_array($cachetFile) && !empty($cachetFile['temporary']) && is_file($cachetFile['path'])) {
        unlink($cachetFile['path']);
    }
}

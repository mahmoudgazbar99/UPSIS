<?php
// C:\xampp\htdocs\webapp\generate_word.php

require_once app_path('bootstrap.php');
require_once app_path('support/certificate_export.php');
require_once root_path('vendor/autoload.php');
require_permission('documents.issue');

// 1. Récupération du CIN transmis via l'URL
$cin = trim($_GET['cin'] ?? '');
if ($cin === '') {
    exit('Erreur : CIN non fourni.');
}

$docxPath = null;
$logoFile = null;

try {
    // 2. Requête SQL pour obtenir toutes les infos de l'étudiant
    $stmt = db()->prepare('
        SELECT 
            e.*, 
            s.nom_spec,
            s.id_program
        FROM etudiant e 
        LEFT JOIN specialite s ON s.id_spec = e.id_spec 
        WHERE e.cin_passeport = ?
    ');
    $stmt->execute([$cin]);
    $student = $stmt->fetch();

    if (!$student) {
        exit('Erreur : Étudiant introuvable dans la base de données.');
    }

    // 3. Chemin vers le modèle Word
    $templatePath = root_path('templates/template_inscription_3.docx');
    if (!file_exists($templatePath)) {
        exit('Erreur : Le modèle Word "templates/template_inscription_3.docx" est introuvable.');
    }

    // 4. Formate la date de naissance (au format jj/mm/aaaa si elle est stockée aaaa-mm-jj)
    $dateNaissanceFormatted = '';
    if (!empty($student['date_naissance'])) {
        $timestamp = strtotime($student['date_naissance']);
        $dateNaissanceFormatted = $timestamp ? date('d/m/Y', $timestamp) : $student['date_naissance'];
    }

    $institution = institution_brand();
    $logoFile = institution_logo_file();
    $niveau = (string) ($student['niveau_etude'] ?? $student['niveau'] ?? '');
    $programId = (int) ($student['id_program'] ?? 0);
    $programId = $programId > 0 ? $programId : null;
    $fileBase = 'Certificat_Inscription_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $student['cin_passeport']);
    $docxPath = institution_copy_docx_template($templatePath);
    institution_replace_docx_placeholders($docxPath, [
        'nom' => (string) ($student['nom'] ?? ''),
        'prenom' => (string) ($student['prenom'] ?? ''),
        'date_naissance' => institution_ltr_text($dateNaissanceFormatted),
        'lieu_naissance' => institution_ltr_text((string) ($student['lieu_naissance'] ?? '')),
        'cin' => (string) ($student['cin_passeport'] ?? ''),
        'specialite' => (string) ($student['nom_spec'] ?? ''),
        'niveau' => level_label($niveau),
        'DIPLOME_FR' => institution_award_title($programId),
        'logo' => $logoFile['path'],
        'inscription' => institution_ltr_text((string) ($student['num_inscription'] ?? '')),
        'date_du_jour' => date('d/m/Y'),
        'sec_generale' => $institution['sec_generale'],
        'establishment_ar' => institution_rtl_text($institution['establishment_ar']),
        'establishment_fr' => $institution['establishment_fr'],
    ]);

    $preview = ($_GET['preview'] ?? '') === '1';
    $asPdf = !$preview && certificate_wants_pdf();
    certificate_send_generated_document($docxPath, $fileBase, $asPdf, $preview, true);

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur lors de la génération du document : ' . $e->getMessage();
} finally {
    if (is_string($docxPath) && is_file($docxPath)) {
        unlink($docxPath);
    }
    if (is_array($logoFile) && !empty($logoFile['temporary']) && is_file($logoFile['path'])) {
        unlink($logoFile['path']);
    }
}

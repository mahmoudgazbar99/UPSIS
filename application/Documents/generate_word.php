<?php
// C:\xampp\htdocs\webapp\generate_word.php

require_once app_path('bootstrap.php');
require_once app_path('support/certificate_export.php');
require_once root_path('vendor/autoload.php');
require_permission('documents.issue');

use PhpOffice\PhpWord\TemplateProcessor;

// 1. Récupération du CIN transmis via l'URL
$cin = trim($_GET['cin'] ?? '');
if ($cin === '') {
    exit('Erreur : CIN non fourni.');
}

try {
    // 2. Requête SQL pour obtenir toutes les infos de l'étudiant
    $stmt = db()->prepare('
        SELECT 
            e.*, 
            s.nom_spec 
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
    $templatePath = root_path('templates/template_inscription_2.docx');
    if (!file_exists($templatePath)) {
        exit('Erreur : Le modèle Word "templates/template_inscription_2.docx" est introuvable.');
    }

    $brandedTemplate = institution_personalize_docx($templatePath);
    $templateProcessor = new TemplateProcessor($brandedTemplate);

    // 4. Formate la date de naissance (au format jj/mm/aaaa si elle est stockée aaaa-mm-jj)
    $dateNaissanceFormatted = '';
    if (!empty($student['date_naissance'])) {
        $timestamp = strtotime($student['date_naissance']);
        $dateNaissanceFormatted = $timestamp ? date('d/m/Y', $timestamp) : $student['date_naissance'];
    }

    // 5. Association des variables de la base aux placeholders du document Word
    $templateProcessor->setValue('nom', $student['nom'] ?? '');
    $templateProcessor->setValue('prenom', $student['prenom'] ?? '');
    $templateProcessor->setValue('date_naissance', $dateNaissanceFormatted);
    $templateProcessor->setValue('lieu_naissance', $student['lieu_naissance'] ?? '');
    $templateProcessor->setValue('cin', $student['cin_passeport'] ?? '');
    $templateProcessor->setValue('specialite', $student['nom_spec'] ?? '');
    $niveau = (string) ($student['niveau_etude'] ?? $student['niveau'] ?? '');
    $templateProcessor->setValue('niveau', level_label($niveau));
    $templateProcessor->setValue('inscription', $student['num_inscription'] ?? '');

    // Année universitaire (ex: 2026/2027) et date d'émission
    $currentYear = (int)date('Y');
    $templateProcessor->setValue('annee_univ', $currentYear . '/' . ($currentYear + 1));
    $templateProcessor->setValue('date_du_jour', date('d/m/Y'));
    institution_apply_signatories($templateProcessor);
    $templateProcessor->setValue('diplome', institution_award_title());
    $templateProcessor->setValue('universite', institution_name_inline());

    $fileBase = 'Certificat_Inscription_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $student['cin_passeport']);
    $tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
    if ($tempFile === false) {
        exit('Erreur : impossible de créer le fichier temporaire.');
    }
    $docxPath = $tempFile . '.docx';
    unlink($tempFile);
    $templateProcessor->saveAs($docxPath);

    $preview = ($_GET['preview'] ?? '') === '1';
    $asPdf = !$preview && certificate_wants_pdf();
    try {
        certificate_send_generated_document($docxPath, $fileBase, $asPdf, $preview);
    } finally {
        if (is_file($docxPath)) {
            unlink($docxPath);
        }
    }
    exit;

} catch (Exception $e) {
    exit('Erreur lors de la génération du document : ' . $e->getMessage());
}

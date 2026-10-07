<?php
// C:\xampp\htdocs\webapp\generate_word.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_admin();

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
    $templatePath = __DIR__ . '/templates/template_inscription_2.docx';
    if (!file_exists($templatePath)) {
        exit('Erreur : Le modèle Word "templates/template_inscription_2.docx" est introuvable.');
    }

    $templateProcessor = new TemplateProcessor($templatePath);

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

    // 6. Génération et téléchargement du fichier Word
    $fileName = 'Certificat_Inscription_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $student['cin_passeport']) . '.docx';
    $tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
    $templateProcessor->saveAs($tempFile);

    $preview = ($_GET['preview'] ?? '') === '1';

    // Use the same generated DOCX for the embedded preview and final download.
    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . $fileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Length: ' . filesize($tempFile));

    readfile($tempFile);
    unlink($tempFile);
    exit;

} catch (Exception $e) {
    exit('Erreur lors de la génération du document : ' . $e->getMessage());
}

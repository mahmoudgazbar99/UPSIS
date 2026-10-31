<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/certificate_export.php');
require_once root_path('vendor/autoload.php');

function repair_export_text(string $value): string
{
    if (!preg_match('/(?:Ã.|Â.)/u', $value)) {
        return $value;
    }

    $repaired = iconv('UTF-8', 'ISO-8859-1', $value);
    return $repaired === false ? $value : $repaired;
}

function prepare_certificate_page(string $docPath): void
{
    certificate_center_landscape_cut($docPath);
}

if (!defined('INSCRIPTION_EXPORT_AS_LIBRARY')) {
require_admin();
set_time_limit(300);

$templatePath = root_path('templates/template_inscription_3.docx');
if (!is_file($templatePath)) {
    http_response_code(500);
    exit('Erreur : le modèle d’inscription est introuvable.');
}
$students = db()->query(
    'SELECT e.*, s.nom_spec, s.id_program
     FROM etudiant e
     LEFT JOIN specialite s ON s.id_spec = e.id_spec
     ORDER BY e.nom, e.prenom, e.id_etudiant'
)->fetchAll();
if (!$students) {
    http_response_code(404);
    exit('Aucun étudiant à traiter.');
}

$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inscriptions-' . bin2hex(random_bytes(8));
if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) {
    http_response_code(500);
    exit('Erreur : impossible de créer le dossier temporaire.');
}

$pdfPath = $workDir . DIRECTORY_SEPARATOR . 'Attestations_Inscription_Tous_Etudiants.pdf';
$logoFile = null;

try {
    $logoFile = institution_logo_file();
    foreach ($students as $student) {
        $dateNaissance = '';
        if (!empty($student['date_naissance'])) {
            $timestamp = strtotime($student['date_naissance']);
            $dateNaissance = $timestamp ? date('d/m/Y', $timestamp) : (string) $student['date_naissance'];
        }

        $safeCin = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $student['cin_passeport']);
        $docPath = $workDir . DIRECTORY_SEPARATOR . 'Certificat_Inscription_' . $safeCin . '.docx';
        if (!copy($templatePath, $docPath)) {
            throw new RuntimeException('Impossible de copier le modèle pour le CIN ' . $safeCin . '.');
        }

        $institution = institution_brand();
        $currentYear = (int) date('Y');
        $programId = (int) ($student['id_program'] ?? 0);
        $programId = $programId > 0 ? $programId : null;
        $niveau = (string) ($student['niveau_etude'] ?? $student['niveau'] ?? '');
        institution_replace_docx_placeholders($docPath, [
            'nom' => repair_export_text((string) ($student['nom'] ?? '')),
            'prenom' => repair_export_text((string) ($student['prenom'] ?? '')),
            'date_naissance' => institution_ltr_text($dateNaissance),
            'lieu_naissance' => institution_ltr_text(repair_export_text((string) ($student['lieu_naissance'] ?? ''))),
            'cin' => (string) ($student['cin_passeport'] ?? ''),
            'specialite' => repair_export_text((string) ($student['nom_spec'] ?? '')),
            'niveau' => level_label($niveau),
            'DIPLOME_FR' => institution_award_title($programId),
            'logo' => $logoFile['path'],
            'inscription' => institution_ltr_text((string) ($student['num_inscription'] ?? '')),
            'date_du_jour' => date('d/m/Y'),
            'sec_generale' => $institution['sec_generale'],
            'establishment_ar' => institution_rtl_text($institution['establishment_ar']),
            'establishment_fr' => $institution['establishment_fr'],
        ]);

        prepare_certificate_page($docPath);
    }

    $docPaths = glob($workDir . DIRECTORY_SEPARATOR . '*.docx') ?: [];
    sort($docPaths, SORT_STRING);
    if (!$docPaths) {
        throw new RuntimeException('Aucune attestation n’a été générée.');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $converterPath = __DIR__ . DIRECTORY_SEPARATOR . 'convert_inscriptions_to_pdf.ps1';
        if (!is_file($converterPath)) {
            throw new RuntimeException('Le convertisseur PDF Windows est introuvable.');
        }
        $command = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File '
            . escapeshellarg($converterPath)
            . ' -WorkDir ' . escapeshellarg($workDir)
            . ' -OutputPdf ' . escapeshellarg($pdfPath)
            . ' 2>&1';
    } else {
        $command = 'libreoffice -env:UserInstallation='
            . escapeshellarg('file://' . $workDir . DIRECTORY_SEPARATOR . 'lo-profile')
            . ' --headless --convert-to pdf --outdir ' . escapeshellarg($workDir);
        foreach ($docPaths as $docPath) {
            $command .= ' ' . escapeshellarg($docPath);
        }
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            error_log('LibreOffice conversion failed: ' . implode("\n", $output));
            throw new RuntimeException('La conversion des attestations en PDF a échoué.');
        }

        $convertedPdfs = array_map(
            static fn(string $docPath): string => $workDir . DIRECTORY_SEPARATOR . pathinfo($docPath, PATHINFO_FILENAME) . '.pdf',
            $docPaths
        );
        foreach ($convertedPdfs as $convertedPdf) {
            if (!is_file($convertedPdf) || filesize($convertedPdf) === 0) {
                throw new RuntimeException('La conversion d’une attestation en PDF a échoué.');
            }
        }

        $command = 'pdfunite ' . implode(' ', array_map('escapeshellarg', $convertedPdfs))
            . ' ' . escapeshellarg($pdfPath);
    }

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        error_log('PDF conversion failed: ' . implode("\n", $output));
        throw new RuntimeException('La conversion des attestations en PDF a échoué.');
    }
    if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
        throw new RuntimeException('Le PDF regroupé n’a pas pu être généré.');
    }

    $fileName = 'Attestations_Inscription_Tous_Etudiants_' . date('Y-m-d') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($pdfPath);
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    error_log('download_all_inscriptions: ' . $exception->getMessage());
    echo 'Erreur lors de la génération du document.';
} finally {
    if (is_dir($workDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if ($path->isDir() && !$path->isLink()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
    }
    if (is_dir($workDir)) {
        rmdir($workDir);
    }
    if (is_array($logoFile) && !empty($logoFile['temporary']) && is_file($logoFile['path'])) {
        unlink($logoFile['path']);
    }
}
}

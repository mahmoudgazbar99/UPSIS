<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/certificate_export.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

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
    $archive = new ZipArchive();
    if ($archive->open($docPath) !== true) {
        throw new RuntimeException('Impossible de préparer la mise en page d’une attestation.');
    }

    $documentXml = $archive->getFromName('word/document.xml');
    if ($documentXml === false) {
        $archive->close();
        throw new RuntimeException('La structure du modèle d’attestation est invalide.');
    }

    $document = new DOMDocument();
    if (!$document->loadXML($documentXml)) {
        $archive->close();
        throw new RuntimeException('La mise en page du modèle d’attestation est invalide.');
    }

    $xpath = new DOMXPath($document);
    $wordNamespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xpath->registerNamespace('w', $wordNamespace);
    $certificateTable = $xpath->query('/w:document/w:body/w:tbl')->item(0);
    $certificateRow = $certificateTable instanceof DOMElement
        ? $xpath->query('./w:tr[1]', $certificateTable)->item(0)
        : null;
    if (!$certificateTable instanceof DOMElement || !$certificateRow instanceof DOMElement) {
        $archive->close();
        throw new RuntimeException('La structure du certificat est invalide.');
    }

    foreach ($xpath->query('//w:tblpPr') as $float) {
        $float->parentNode?->removeChild($float);
    }
    certificate_compact_inscription_body($xpath, $wordNamespace);

    foreach ($xpath->query('//w:noWrap') as $noWrap) {
        $noWrap->parentNode?->removeChild($noWrap);
    }

    $pageWidth = 16838;
    $margin = 420;
    foreach ($xpath->query('//w:sectPr') as $section) {
        $pageSize = $xpath->query('./w:pgSz', $section)->item(0);
        if ($pageSize instanceof DOMElement) {
            $pageSize->setAttributeNS($wordNamespace, 'w:w', (string) $pageWidth);
            $pageSize->setAttributeNS($wordNamespace, 'w:h', '11906');
            $pageSize->setAttributeNS($wordNamespace, 'w:orient', 'landscape');
        }
        $pageMargins = $xpath->query('./w:pgMar', $section)->item(0);
        if ($pageMargins instanceof DOMElement) {
            foreach (['top', 'bottom', 'left', 'right'] as $side) {
                $pageMargins->setAttributeNS($wordNamespace, 'w:' . $side, (string) $margin);
            }
        }
        $columns = $xpath->query('./w:cols', $section)->item(0);
        if ($columns instanceof DOMElement) {
            $columns->setAttributeNS($wordNamespace, 'w:num', '1');
        }
    }

    certificate_resize_table($xpath, $wordNamespace, $certificateTable, $pageWidth - (2 * $margin));

    $body = $xpath->query('/w:document/w:body')->item(0);
    if ($body instanceof DOMElement) {
        $afterCertificateTable = false;
        foreach (iterator_to_array($body->childNodes) as $child) {
            if ($child->isSameNode($certificateTable)) {
                $afterCertificateTable = true;
                continue;
            }
            if ($afterCertificateTable && $child->namespaceURI === $wordNamespace && $child->localName === 'p' && trim($child->textContent) === '') {
                $body->removeChild($child);
            }
        }
    }

    if (!$archive->deleteName('word/document.xml') || !$archive->addFromString('word/document.xml', $document->saveXML())) {
        $archive->close();
        throw new RuntimeException('Impossible d’enregistrer la mise en page de l’attestation.');
    }
    $archive->close();
}

if (!defined('INSCRIPTION_EXPORT_AS_LIBRARY')) {
require_admin();
set_time_limit(300);

$templatePath = getenv('INSCRIPTION_TEMPLATE') ?: __DIR__ . '/templates/template_inscription_2.docx';
$templatePath = is_file($templatePath) ? institution_personalize_docx($templatePath) : $templatePath;
if (!is_file($templatePath)) {
    http_response_code(500);
    exit('Erreur : le modèle d’inscription est introuvable.');
}
$students = db()->query(
    'SELECT e.*, s.nom_spec
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

try {
    foreach ($students as $student) {
        $dateNaissance = '';
        if (!empty($student['date_naissance'])) {
            $timestamp = strtotime($student['date_naissance']);
            $dateNaissance = $timestamp ? date('d/m/Y', $timestamp) : (string) $student['date_naissance'];
        }

        $processor = new TemplateProcessor($templatePath);
        $processor->setValue('nom', repair_export_text((string) ($student['nom'] ?? '')));
        $processor->setValue('prenom', repair_export_text((string) ($student['prenom'] ?? '')));
        $processor->setValue('date_naissance', $dateNaissance);
        $processor->setValue('lieu_naissance', repair_export_text((string) ($student['lieu_naissance'] ?? '')));
        $processor->setValue('cin', $student['cin_passeport'] ?? '');
        $processor->setValue('specialite', repair_export_text((string) ($student['nom_spec'] ?? '')));
        $processor->setValue('niveau', level_label((string) ($student['niveau_etude'] ?? $student['niveau'] ?? '')));
        $processor->setValue('inscription', $student['num_inscription'] ?? '');
        $currentYear = (int) date('Y');
        $processor->setValue('annee_univ', $currentYear . '/' . ($currentYear + 1));
        $processor->setValue('date_du_jour', date('d/m/Y'));
        institution_apply_signatories($processor);
        $processor->setValue('diplome', institution_award_title());
        $processor->setValue('universite', institution_name_inline());

        $safeCin = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $student['cin_passeport']);
        $docPath = $workDir . DIRECTORY_SEPARATOR . 'Certificat_Inscription_' . $safeCin . '.docx';
        $processor->saveAs($docPath);
        if (PHP_OS_FAMILY !== 'Windows') {
            prepare_certificate_page($docPath);
        }
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
    echo $exception->getMessage();
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
}
}


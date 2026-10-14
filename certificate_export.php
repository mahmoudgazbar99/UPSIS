<?php
declare(strict_types=1);

function certificate_wants_pdf(): bool
{
    $format = strtolower(trim((string) ($_GET['format'] ?? '')));
    return $format === 'pdf' || (string) ($_GET['pdf'] ?? '') === '1';
}

function certificate_find_soffice(): ?string
{
    $candidates = [
        (string) (getenv('LIBREOFFICE_PATH') ?: ''),
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/lib/libreoffice/program/soffice',
    ];
    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }
    return null;
}

function certificate_remove_dir(string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

function certificate_run_shell(string $command): array
{
    $output = [];
    $code = 0;
    exec($command, $output, $code);
    return [$code, trim(implode("\n", $output))];
}

function certificate_convert_with_word(string $docxPath, string $pdfPath): bool
{
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'convert_docx_to_pdf.ps1';
    if (!is_file($script)) {
        return false;
    }
    $powershell = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if (!is_file($powershell)) {
        $powershell = 'powershell.exe';
    }
    $command = escapeshellarg($powershell)
        . ' -STA -NoProfile -ExecutionPolicy Bypass -File '
        . escapeshellarg($script)
        . ' -Docx ' . escapeshellarg($docxPath)
        . ' -Pdf ' . escapeshellarg($pdfPath)
        . ' 2>&1';
    [$code, $output] = certificate_run_shell($command);
    if ($code === 0 && is_file($pdfPath) && filesize($pdfPath) > 0) {
        return true;
    }
    if ($output !== '') {
        error_log('DOCX to PDF (Word) failed: ' . $output);
    }
    return false;
}

function certificate_word_attr(DOMElement $element, string $namespace, string $localName, string $value): void
{
    $element->setAttributeNS($namespace, 'w:' . $localName, $value);
}

function certificate_table_width(DOMXPath $xpath, DOMElement $table): int
{
    $width = $xpath->query('./w:tblPr/w:tblW', $table)->item(0);
    if ($width instanceof DOMElement) {
        $value = (int) $width->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w');
        if ($value > 0) {
            return $value;
        }
    }
    $total = 0;
    foreach ($xpath->query('./w:tblGrid/w:gridCol', $table) as $column) {
        $total += (int) $column->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w');
    }
    return $total;
}

function certificate_resize_table(DOMXPath $xpath, string $namespace, DOMElement $table, int $targetWidth): void
{
    $originalWidth = certificate_table_width($xpath, $table);
    if ($originalWidth <= 0 || $targetWidth <= 0) {
        return;
    }

    $properties = $xpath->query('./w:tblPr', $table)->item(0);
    if (!$properties instanceof DOMElement) {
        $properties = $table->ownerDocument->createElementNS($namespace, 'w:tblPr');
        $table->insertBefore($properties, $table->firstChild);
    }

    foreach ($xpath->query('./w:tblInd', $properties) as $indent) {
        $properties->removeChild($indent);
    }

    $tableWidth = $xpath->query('./w:tblW', $properties)->item(0);
    if (!$tableWidth instanceof DOMElement) {
        $tableWidth = $table->ownerDocument->createElementNS($namespace, 'w:tblW');
        $properties->appendChild($tableWidth);
    }
    certificate_word_attr($tableWidth, $namespace, 'w', (string) $targetWidth);
    certificate_word_attr($tableWidth, $namespace, 'type', 'dxa');

    $layout = $xpath->query('./w:tblLayout', $properties)->item(0);
    if (!$layout instanceof DOMElement) {
        $layout = $table->ownerDocument->createElementNS($namespace, 'w:tblLayout');
        $properties->appendChild($layout);
    }
    certificate_word_attr($layout, $namespace, 'type', 'fixed');

    $gridColumns = $xpath->query('./w:tblGrid/w:gridCol', $table);
    $columnWidths = [];
    $remaining = $targetWidth;
    foreach ($gridColumns as $index => $gridColumn) {
        $source = (int) $gridColumn->getAttributeNS($namespace, 'w');
        $columnWidth = $index === $gridColumns->length - 1
            ? $remaining
            : (int) max(200, round($targetWidth * $source / $originalWidth));
        $remaining -= $columnWidth;
        $columnWidths[] = $columnWidth;
        certificate_word_attr($gridColumn, $namespace, 'w', (string) $columnWidth);
    }

    foreach ($xpath->query('./w:tr', $table) as $row) {
        foreach ($xpath->query('./w:tc', $row) as $index => $cell) {
            if (!isset($columnWidths[$index])) {
                continue;
            }
            $cellWidth = $xpath->query('./w:tcPr/w:tcW', $cell)->item(0);
            if ($cellWidth instanceof DOMElement) {
                certificate_word_attr($cellWidth, $namespace, 'w', (string) $columnWidths[$index]);
                certificate_word_attr($cellWidth, $namespace, 'type', 'dxa');
            }
            foreach ($xpath->query('./w:tbl', $cell) as $nested) {
                if ($nested instanceof DOMElement) {
                    certificate_resize_table($xpath, $namespace, $nested, $columnWidths[$index] - 80);
                }
            }
        }
    }
}

function certificate_prepare_docx_for_libreoffice(string $docxPath): void
{
    $archive = new ZipArchive();
    if ($archive->open($docxPath) !== true) {
        throw new RuntimeException('Impossible d’ouvrir le document pour la conversion PDF.');
    }

    $xml = $archive->getFromName('word/document.xml');
    if ($xml === false) {
        $archive->close();
        throw new RuntimeException('Le contenu du document est introuvable.');
    }

    // Floating Word tables overlap in LibreOffice. Keep them in the page flow.
    $xml = preg_replace('/<w:tblpPr\b[^>]*\/>/', '', $xml) ?? $xml;

    $document = new DOMDocument();
    if (!$document->loadXML($xml)) {
        $archive->close();
        throw new RuntimeException('La mise en page du document est invalide.');
    }

    $xpath = new DOMXPath($document);
    $wordNamespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xpath->registerNamespace('w', $wordNamespace);

    $pageWidth = 16838;
    $pageHeight = 14500;
    $margin = 360;

    foreach ($xpath->query('//w:sectPr') as $section) {
        $pageSize = $xpath->query('./w:pgSz', $section)->item(0);
        if ($pageSize instanceof DOMElement) {
            certificate_word_attr($pageSize, $wordNamespace, 'w', (string) $pageWidth);
            certificate_word_attr($pageSize, $wordNamespace, 'h', (string) $pageHeight);
            certificate_word_attr($pageSize, $wordNamespace, 'orient', 'landscape');
        }
        $pageMargins = $xpath->query('./w:pgMar', $section)->item(0);
        if ($pageMargins instanceof DOMElement) {
            foreach (['top', 'bottom', 'left', 'right'] as $side) {
                certificate_word_attr($pageMargins, $wordNamespace, $side, (string) $margin);
            }
        }
        $columns = $xpath->query('./w:cols', $section)->item(0);
        if ($columns instanceof DOMElement) {
            certificate_word_attr($columns, $wordNamespace, 'num', '1');
        }
    }

    $body = $xpath->query('/w:document/w:body')->item(0);
    $certificateTable = $xpath->query('/w:document/w:body/w:tbl')->item(0);
    if ($body instanceof DOMElement && $certificateTable instanceof DOMElement) {
        $afterTable = false;
        foreach (iterator_to_array($body->childNodes) as $child) {
            if ($child->isSameNode($certificateTable)) {
                $afterTable = true;
                continue;
            }
            if (
                $afterTable
                && $child instanceof DOMElement
                && $child->localName === 'p'
                && trim($child->textContent) === ''
            ) {
                $body->removeChild($child);
            }
        }
        certificate_resize_table($xpath, $wordNamespace, $certificateTable, $pageWidth - (2 * $margin));
    }

    $preparedXml = $document->saveXML();
    if (!is_string($preparedXml) || $preparedXml === '') {
        $archive->close();
        throw new RuntimeException('Impossible d’enregistrer la mise en page PDF.');
    }

    $archive->addFromString('word/document.xml', $preparedXml);
    $archive->close();
}

function certificate_convert_with_libreoffice(string $docxPath, string $pdfPath): bool
{
    $soffice = certificate_find_soffice();
    if ($soffice === null) {
        return false;
    }

    certificate_prepare_docx_for_libreoffice($docxPath);

    $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lo-cert-' . bin2hex(random_bytes(8));
    $profileDir = $workDir . DIRECTORY_SEPARATOR . 'profile';
    $outDir = $workDir . DIRECTORY_SEPARATOR . 'out';
    if (!mkdir($profileDir, 0700, true) || !mkdir($outDir, 0700, true)) {
        certificate_remove_dir($workDir);
        return false;
    }

    $profileUri = 'file://' . str_replace('\\', '/', $profileDir);
    $command = 'HOME=' . escapeshellarg($workDir)
        . ' TMPDIR=' . escapeshellarg($workDir)
        . ' ' . escapeshellarg($soffice)
        . ' -env:UserInstallation=' . escapeshellarg($profileUri)
        . ' --headless --norestore --nolockcheck --convert-to pdf --outdir '
        . escapeshellarg($outDir)
        . ' ' . escapeshellarg($docxPath)
        . ' 2>&1';

    try {
        [$code, $output] = certificate_run_shell($command);
        $generated = $outDir . DIRECTORY_SEPARATOR . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
        if ($code === 0 && is_file($generated) && filesize($generated) > 0) {
            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }
            return rename($generated, $pdfPath);
        }
        error_log('DOCX to PDF (LibreOffice) failed: ' . $output);
        return false;
    } finally {
        certificate_remove_dir($workDir);
    }
}

function certificate_convert_docx_to_pdf(string $docxPath, string $pdfPath): void
{
    $resolvedDocx = realpath($docxPath);
    if ($resolvedDocx === false || !is_file($resolvedDocx)) {
        throw new RuntimeException('Le document Word à convertir est introuvable.');
    }

    $pdfDirectory = dirname($pdfPath);
    if (!is_dir($pdfDirectory) && !mkdir($pdfDirectory, 0700, true) && !is_dir($pdfDirectory)) {
        throw new RuntimeException('Impossible de préparer le dossier PDF.');
    }

    set_time_limit(120);

    $converted = PHP_OS_FAMILY === 'Windows'
        ? certificate_convert_with_word($resolvedDocx, $pdfPath)
        : false;
    if (!$converted) {
        $converted = certificate_convert_with_libreoffice($resolvedDocx, $pdfPath);
    }

    if ($converted && is_file($pdfPath) && filesize($pdfPath) > 0) {
        return;
    }

    throw new RuntimeException('La conversion PDF a échoué. Réessayez dans un instant.');
}

function certificate_send_generated_document(string $docxPath, string $baseName, bool $asPdf, bool $inline = false): void
{
    $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseName) ?: 'attestation';
    if ($asPdf) {
        $pdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cert-pdf-' . bin2hex(random_bytes(8)) . '.pdf';
        try {
            certificate_convert_docx_to_pdf($docxPath, $pdfPath);
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $baseName . '.pdf"');
            header('Content-Length: ' . (string) filesize($pdfPath));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            readfile($pdfPath);
        } finally {
            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }
        }
        return;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $baseName . '.docx"');
    header('Content-Length: ' . (string) filesize($docxPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($docxPath);
}

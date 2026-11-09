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

function certificate_half_column_count(DOMXPath $xpath, DOMElement $table, string $namespace, int $gridCount): int
{
    foreach ($xpath->query('./w:tr', $table) as $row) {
        $cells = $xpath->query('./w:tc', $row);
        if ($cells->length !== 2) {
            continue;
        }
        $left = certificate_grid_span($xpath, $cells->item(0), $namespace);
        $right = certificate_grid_span($xpath, $cells->item(1), $namespace);
        if ($left + $right === $gridCount) {
            return $left;
        }
    }

    return $gridCount === 2 ? 1 : 0;
}

function certificate_grid_span(DOMXPath $xpath, ?DOMNode $cell, string $namespace): int
{
    if (!$cell instanceof DOMElement) {
        return 1;
    }
    $span = $xpath->query('./w:tcPr/w:gridSpan', $cell)->item(0);
    if (!$span instanceof DOMElement) {
        return 1;
    }
    return max(1, (int) $span->getAttributeNS($namespace, 'val'));
}

function certificate_set_cut_border(DOMXPath $xpath, DOMElement $cell, string $namespace): void
{
    $properties = $xpath->query('./w:tcPr', $cell)->item(0);
    if (!$properties instanceof DOMElement) {
        $properties = $cell->ownerDocument->createElementNS($namespace, 'w:tcPr');
        $cell->insertBefore($properties, $cell->firstChild);
    }

    $borders = $xpath->query('./w:tcBorders', $properties)->item(0);
    if (!$borders instanceof DOMElement) {
        $borders = $cell->ownerDocument->createElementNS($namespace, 'w:tcBorders');
        $insertBefore = null;
        foreach (['shd', 'noWrap', 'tcMar', 'textDirection', 'tcFitText', 'vAlign', 'hideMark', 'headers', 'cellIns', 'cellDel', 'cellMerge', 'tcPrChange'] as $localName) {
            $candidate = $xpath->query('./w:' . $localName, $properties)->item(0);
            if ($candidate instanceof DOMElement) {
                $insertBefore = $candidate;
                break;
            }
        }
        $properties->insertBefore($borders, $insertBefore);
    }

    $right = $xpath->query('./w:right', $borders)->item(0);
    if (!$right instanceof DOMElement) {
        $right = $cell->ownerDocument->createElementNS($namespace, 'w:right');
        $borders->appendChild($right);
    }
    certificate_word_attr($right, $namespace, 'val', 'dashed');
    certificate_word_attr($right, $namespace, 'sz', '6');
    certificate_word_attr($right, $namespace, 'space', '0');
    certificate_word_attr($right, $namespace, 'color', '808080');
}

function certificate_scale_widths(array $widths, int $target): array
{
    $count = count($widths);
    if ($count === 0 || $target <= 0) {
        return $widths;
    }

    $original = array_sum($widths);
    $scaled = [];
    $used = 0;
    foreach ($widths as $index => $width) {
        if ($index === $count - 1) {
            $scaled[] = $target - $used;
            break;
        }
        $share = $original > 0 ? (int) round($target * max(0, $width) / $original) : intdiv($target, $count);
        $share = max(1, $share);
        $minimumRemaining = $count - $index - 1;
        if ($used + $share > $target - $minimumRemaining) {
            $share = max(1, $target - $minimumRemaining - $used);
        }
        $scaled[] = $share;
        $used += $share;
    }

    return $scaled;
}

function certificate_loosen_paragraph(DOMXPath $xpath, DOMElement $paragraph, string $namespace): void
{
    if (trim($paragraph->textContent) === '') {
        return;
    }
    $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
    if (!$properties instanceof DOMElement) {
        $properties = $paragraph->ownerDocument->createElementNS($namespace, 'w:pPr');
        $paragraph->insertBefore($properties, $paragraph->firstChild);
    }
    $spacing = $xpath->query('./w:spacing', $properties)->item(0);
    if (!$spacing instanceof DOMElement) {
        $spacing = $paragraph->ownerDocument->createElementNS($namespace, 'w:spacing');
        $properties->appendChild($spacing);
    }
    certificate_word_attr($spacing, $namespace, 'before', '0');
    certificate_word_attr($spacing, $namespace, 'after', '0');
    certificate_word_attr($spacing, $namespace, 'line', '300');
    certificate_word_attr($spacing, $namespace, 'lineRule', 'auto');
}

function certificate_raise_landscape_content(DOMXPath $xpath, string $namespace): void
{
    foreach ($xpath->query('//w:pgMar') as $margin) {
        if (!$margin instanceof DOMElement) {
            continue;
        }
        certificate_word_attr($margin, $namespace, 'top', '227');
        if ((int) $margin->getAttributeNS($namespace, 'header') >= 227) {
            certificate_word_attr($margin, $namespace, 'header', '113');
        }
    }
    foreach ($xpath->query('//w:tblpPr') as $float) {
        if ($float instanceof DOMElement && $float->hasAttributeNS($namespace, 'tblpY')) {
            certificate_word_attr($float, $namespace, 'tblpY', '0');
        }
    }
    foreach ($xpath->query('//w:framePr') as $frame) {
        if ($frame instanceof DOMElement && (int) $frame->getAttributeNS($namespace, 'y') > 0) {
            certificate_word_attr($frame, $namespace, 'y', '0');
        }
    }

    $table = $xpath->query('/w:document/w:body/w:tbl')->item(0);
    if (!$table instanceof DOMElement) {
        return;
    }
    foreach ($xpath->query('./w:tr/w:tc', $table) as $cell) {
        if (!$cell instanceof DOMElement) {
            continue;
        }
        foreach (iterator_to_array($cell->childNodes) as $child) {
            if (!$child instanceof DOMElement || $child->localName !== 'p') {
                break;
            }
            if (trim($child->textContent) !== '' || $xpath->query('.//w:drawing|.//w:pict|.//w:framePr', $child)->length > 0) {
                break;
            }
            $cell->removeChild($child);
        }
        foreach ($xpath->query('./w:p', $cell) as $paragraph) {
            if ($paragraph instanceof DOMElement) {
                certificate_loosen_paragraph($xpath, $paragraph, $namespace);
            }
        }
    }
}

function certificate_apply_centered_halves(DOMXPath $xpath, string $namespace): void
{
    $pageWidth = 16838;
    $pageHeight = 11906;
    $margin = 720;
    $table = $xpath->query('/w:document/w:body/w:tbl')->item(0);
    if (!$table instanceof DOMElement) {
        return;
    }

    $gridColumns = [];
    foreach ($xpath->query('./w:tblGrid/w:gridCol', $table) as $column) {
        if ($column instanceof DOMElement) {
            $gridColumns[] = $column;
        }
    }
    $gridCount = count($gridColumns);
    $leftCount = certificate_half_column_count($xpath, $table, $namespace, $gridCount);
    if ($leftCount < 1 || $leftCount >= $gridCount) {
        return;
    }

    foreach ($xpath->query('//w:sectPr') as $section) {
        if (!$section instanceof DOMElement) {
            continue;
        }
        $pageSize = $xpath->query('./w:pgSz', $section)->item(0);
        if ($pageSize instanceof DOMElement) {
            certificate_word_attr($pageSize, $namespace, 'w', (string) $pageWidth);
            certificate_word_attr($pageSize, $namespace, 'h', (string) $pageHeight);
            certificate_word_attr($pageSize, $namespace, 'orient', 'landscape');
        }
        $pageMargins = $xpath->query('./w:pgMar', $section)->item(0);
        if ($pageMargins instanceof DOMElement) {
            foreach (['top', 'bottom', 'left', 'right'] as $side) {
                certificate_word_attr($pageMargins, $namespace, $side, (string) $margin);
            }
            certificate_word_attr($pageMargins, $namespace, 'gutter', '0');
        }
        foreach ($xpath->query('./w:cols', $section) as $columns) {
            if ($columns instanceof DOMElement) {
                certificate_word_attr($columns, $namespace, 'num', '1');
                certificate_word_attr($columns, $namespace, 'space', '0');
            }
        }
    }

    $contentWidth = $pageWidth - (2 * $margin);
    $halfWidth = intdiv($contentWidth, 2);
    $sourceWidths = [];
    foreach ($gridColumns as $column) {
        $sourceWidths[] = (int) $column->getAttributeNS($namespace, 'w');
    }
    $columnWidths = array_merge(
        certificate_scale_widths(array_slice($sourceWidths, 0, $leftCount), $halfWidth),
        certificate_scale_widths(array_slice($sourceWidths, $leftCount), $contentWidth - $halfWidth)
    );
    foreach ($gridColumns as $index => $column) {
        certificate_word_attr($column, $namespace, 'w', (string) $columnWidths[$index]);
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
    certificate_word_attr($tableWidth, $namespace, 'w', (string) $contentWidth);
    certificate_word_attr($tableWidth, $namespace, 'type', 'dxa');
    $alignment = $xpath->query('./w:jc', $properties)->item(0);
    if (!$alignment instanceof DOMElement) {
        $alignment = $table->ownerDocument->createElementNS($namespace, 'w:jc');
        $properties->appendChild($alignment);
    }
    certificate_word_attr($alignment, $namespace, 'val', 'center');
    $layout = $xpath->query('./w:tblLayout', $properties)->item(0);
    if (!$layout instanceof DOMElement) {
        $layout = $table->ownerDocument->createElementNS($namespace, 'w:tblLayout');
        $properties->appendChild($layout);
    }
    certificate_word_attr($layout, $namespace, 'type', 'fixed');

    foreach ($xpath->query('./w:tr', $table) as $row) {
        if (!$row instanceof DOMElement) {
            continue;
        }
        $cursor = 0;
        foreach ($xpath->query('./w:tc', $row) as $cell) {
            if (!$cell instanceof DOMElement) {
                continue;
            }
            $span = certificate_grid_span($xpath, $cell, $namespace);
            if ($cursor + $span === $leftCount) {
                certificate_set_cut_border($xpath, $cell, $namespace);
            }
            $cellWidthValue = 0;
            for ($offset = 0; $offset < $span && isset($columnWidths[$cursor + $offset]); $offset++) {
                $cellWidthValue += $columnWidths[$cursor + $offset];
            }
            $cellProperties = $xpath->query('./w:tcPr', $cell)->item(0);
            if (!$cellProperties instanceof DOMElement) {
                $cellProperties = $cell->ownerDocument->createElementNS($namespace, 'w:tcPr');
                $cell->insertBefore($cellProperties, $cell->firstChild);
            }
            $cellWidth = $xpath->query('./w:tcW', $cellProperties)->item(0);
            if (!$cellWidth instanceof DOMElement) {
                $cellWidth = $cell->ownerDocument->createElementNS($namespace, 'w:tcW');
                $cellProperties->appendChild($cellWidth);
            }
            certificate_word_attr($cellWidth, $namespace, 'w', (string) $cellWidthValue);
            certificate_word_attr($cellWidth, $namespace, 'type', 'dxa');
            foreach ($xpath->query('./w:tbl', $cell) as $nested) {
                if ($nested instanceof DOMElement) {
                    certificate_resize_table($xpath, $namespace, $nested, max(200, $cellWidthValue - 80));
                }
            }
            $cursor += $span;
        }
    }

    certificate_raise_landscape_content($xpath, $namespace);
}

function certificate_center_landscape_cut(string $docxPath): void
{
    $archive = new ZipArchive();
    if ($archive->open($docxPath) !== true) {
        throw new RuntimeException('Impossible de centrer le découpage de l’attestation.');
    }

    try {
        $xml = $archive->getFromName('word/document.xml');
        if (!is_string($xml)) {
            throw new RuntimeException('Le contenu du document est introuvable.');
        }
        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('La mise en page du document est invalide.');
        }
        $xpath = new DOMXPath($document);
        $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xpath->registerNamespace('w', $namespace);
        certificate_apply_centered_halves($xpath, $namespace);
        $updated = $document->saveXML();
        if (!is_string($updated) || !$archive->addFromString('word/document.xml', $updated)) {
            throw new RuntimeException('Impossible d’enregistrer le découpage centré.');
        }
    } finally {
        $archive->close();
    }
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

function certificate_convert_with_libreoffice(string $docxPath, string $pdfPath): bool
{
    $soffice = certificate_find_soffice();
    if ($soffice === null) {
        return false;
    }

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

function certificate_send_generated_document(
    string $docxPath,
    string $baseName,
    bool $asPdf,
    bool $inline = false,
    bool $centerTwoUpCut = false
): void
{
    if ($centerTwoUpCut) {
        certificate_center_landscape_cut($docxPath);
    }
    $baseName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?: 'attestation';
    if ($asPdf) {
        $pdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cert-pdf-' . bin2hex(random_bytes(8)) . '.pdf';
        try {
            certificate_convert_docx_to_pdf($docxPath, $pdfPath);
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $baseName . '.pdf"');
            header('Content-Length: ' . (string) filesize($pdfPath));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            readfile($pdfPath);
            return;
        } catch (Throwable $exception) {
            // Free hosts may OOM LibreOffice; still deliver the filled Word file.
            error_log('certificate PDF fallback to DOCX: ' . $exception->getMessage());
        } finally {
            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $baseName . '.docx"');
    header('Content-Length: ' . (string) filesize($docxPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($docxPath);
}

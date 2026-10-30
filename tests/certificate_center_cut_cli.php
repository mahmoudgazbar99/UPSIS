<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/application/support/certificate_export.php';

function center_cut_metrics(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open ' . $path);
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!is_string($xml)) {
        throw new RuntimeException('Missing document.xml');
    }
    $document = new DOMDocument();
    $document->loadXML($xml);
    $xpath = new DOMXPath($document);
    $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xpath->registerNamespace('w', $namespace);
    $page = $xpath->query('//w:pgSz')->item(0);
    $margin = $xpath->query('//w:pgMar')->item(0);
    $columns = [];
    foreach ($xpath->query('/w:document/w:body/w:tbl/w:tblGrid/w:gridCol') as $column) {
        $columns[] = (int) $column->getAttributeNS($namespace, 'w');
    }
    $table = $xpath->query('/w:document/w:body/w:tbl')->item(0);
    $leftCount = $table instanceof DOMElement
        ? certificate_half_column_count($xpath, $table, $namespace, count($columns))
        : 0;
    $cutRows = 0;
    if ($table instanceof DOMElement) {
        foreach ($xpath->query('./w:tr', $table) as $row) {
            $cursor = 0;
            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $span = certificate_grid_span($xpath, $cell, $namespace);
                if ($cursor + $span === $leftCount) {
                    $border = $xpath->query('./w:tcPr/w:tcBorders/w:right[@w:val="dashed" and @w:sz="6" and @w:color="808080"]', $cell)->item(0);
                    if ($border instanceof DOMElement) {
                        $cutRows++;
                    }
                }
                $cursor += $span;
            }
        }
    }

    return [
        'page_w' => $page instanceof DOMElement ? (int) $page->getAttributeNS($namespace, 'w') : 0,
        'page_h' => $page instanceof DOMElement ? (int) $page->getAttributeNS($namespace, 'h') : 0,
        'orient' => $page instanceof DOMElement ? $page->getAttributeNS($namespace, 'orient') : '',
        'left' => $margin instanceof DOMElement ? (int) $margin->getAttributeNS($namespace, 'left') : 0,
        'right' => $margin instanceof DOMElement ? (int) $margin->getAttributeNS($namespace, 'right') : 0,
        'gutter' => $margin instanceof DOMElement ? (int) $margin->getAttributeNS($namespace, 'gutter') : -1,
        'columns' => $columns,
        'left_count' => $leftCount,
        'cut_rows' => $cutRows,
        'table_rows' => $table instanceof DOMElement ? $xpath->query('./w:tr', $table)->length : 0,
    ];
}

$failed = 0;
foreach (['template_inscription_3.docx', 'template_presence_2.docx'] as $name) {
    $source = dirname(__DIR__) . '/templates/' . $name;
    $sourceHash = hash_file('sha256', $source);
    $copy = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'center-cut-' . $name;
    if (!copy($source, $copy)) {
        throw new RuntimeException('Unable to copy ' . $name);
    }
    try {
        certificate_center_landscape_cut($copy);
        if (hash_file('sha256', $source) !== $sourceHash) {
            echo "fail  {$name} source template was modified\n";
            $failed++;
            continue;
        }
        $metrics = center_cut_metrics($copy);
        $leftWidth = array_sum(array_slice($metrics['columns'], 0, $metrics['left_count']));
        $rightWidth = array_sum(array_slice($metrics['columns'], $metrics['left_count']));
        $cut = $metrics['left'] + $leftWidth;
        $center = intdiv($metrics['page_w'], 2);
        $ok = $metrics['page_w'] === 16838
            && $metrics['page_h'] === 11906
            && $metrics['orient'] === 'landscape'
            && $metrics['left'] === 720
            && $metrics['right'] === 720
            && $metrics['gutter'] === 0
            && $leftWidth === $rightWidth
            && $cut === $center
            && $metrics['cut_rows'] === $metrics['table_rows'];
        echo ($ok ? 'ok    ' : 'fail  ') . $name
            . " cut={$cut} center={$center} halves={$leftWidth}/{$rightWidth}"
            . " cut-guide-rows={$metrics['cut_rows']}/{$metrics['table_rows']}\n";
        if (!$ok) {
            $failed++;
        }
    } finally {
        if (is_file($copy)) {
            unlink($copy);
        }
    }
}

exit($failed === 0 ? 0 : 1);

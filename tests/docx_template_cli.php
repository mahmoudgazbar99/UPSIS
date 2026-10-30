<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/application/bootstrap.php';

function docx_template_word_parts(string $path): array
{
    $archive = new ZipArchive();
    if ($archive->open($path) !== true) {
        throw new RuntimeException('Unable to open DOCX: ' . $path);
    }

    $parts = [];
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = $archive->getNameIndex($index);
        if (!is_string($name) || !preg_match('~^word/(?:document|header\d*|footer\d*|footnotes|endnotes)\.xml$~', $name)) {
            continue;
        }

        $xml = $archive->getFromName($name);
        if (!is_string($xml)) {
            $archive->close();
            throw new RuntimeException('Unable to read DOCX part: ' . $name);
        }

        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            $archive->close();
            throw new RuntimeException('Invalid XML in DOCX part: ' . $name);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];
        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t', $paragraph) as $textNode) {
                $text .= $textNode->nodeValue;
            }
            $paragraphs[] = $text;
        }
        $parts[$name] = ['paragraphs' => $paragraphs, 'xml' => $xml];
    }

    if (!$archive->close()) {
        throw new RuntimeException('Unable to close DOCX: ' . $path);
    }

    return $parts;
}

$leftToRight = institution_ltr_text('2026/2027');
$rightToLeft = institution_rtl_text('University test');
$logoPath = public_path('images/no_bg_logo.png');
if (!is_file($logoPath)) {
    throw new RuntimeException('Fallback logo is unavailable for the DOCX test.');
}
$testCases = [
    'templates/template_presence_2.docx' => [
        'logo' => $logoPath,
        'establishment_ar' => $rightToLeft,
        'establishment_fr' => 'Establishment test',
        'annee_univ' => $leftToRight,
        'prenom_ar' => $rightToLeft,
        'nom_ar' => $rightToLeft,
        'lieu_naissance_ar' => $rightToLeft,
        'date_naissance' => $leftToRight,
        'cin' => institution_ltr_text('12345678'),
        'specialite_ar' => $rightToLeft,
        'المستوى' => 'السنة الأولى',
        'الاجازة' => 'الإجازة في علوم التمريض',
        'inscription' => institution_ltr_text('INS005/2026'),
        'date_du_jour' => institution_ltr_text('2026/10/29'),
        'sec_generale' => $rightToLeft,
    ],
    'templates/template_inscription_3.docx' => [
        'logo' => $logoPath,
        'establishment_ar' => $rightToLeft,
        'establishment_fr' => 'Establishment test',
        'prenom' => 'Test',
        'nom' => 'Student',
        'date_naissance' => $leftToRight,
        'lieu_naissance' => institution_ltr_text('Gabes'),
        'cin' => '12345678',
        'specialite' => 'Test',
        'niveau' => '1er',
        'DIPLOME_FR' => 'Licence test',
        'inscription' => institution_ltr_text('INS005/2026'),
        'date_du_jour' => '29/10/2026',
        'sec_generale' => 'Secretary',
    ],
];

foreach ($testCases as $relativePath => $values) {
    $sourcePath = root_path($relativePath);
    $sourceHash = hash_file('sha256', $sourcePath);
    $sourceParts = docx_template_word_parts($sourcePath);
    $copyPath = institution_copy_docx_template($sourcePath);

    try {
        institution_replace_docx_placeholders($copyPath, $values);
        $generatedParts = docx_template_word_parts($copyPath);

        if (hash_file('sha256', $sourcePath) !== $sourceHash) {
            throw new RuntimeException('Source template was modified: ' . $relativePath);
        }
        if (array_keys($generatedParts) !== array_keys($sourceParts)) {
            throw new RuntimeException('DOCX parts changed: ' . $relativePath);
        }

        foreach ($sourceParts as $partName => $sourcePart) {
            $expectedParagraphs = array_map(
                static function (string $text) use ($values): string {
                    foreach ($values as $key => $value) {
            if ($key === 'logo') {
                $text = preg_replace('/\$?\{logo\}/i', '', $text) ?? $text;
                continue;
            }
            $text = str_replace('${' . $key . '}', $value, $text);
            $text = str_replace('{' . $key . '}', $value, $text);
                    }
                    return $text;
                },
                $sourcePart['paragraphs']
            );
            $generatedPart = $generatedParts[$partName];
            if ($generatedPart['paragraphs'] !== $expectedParagraphs) {
                throw new RuntimeException('Placeholder text or order changed in ' . $partName);
            }
            if ($relativePath === 'templates/template_presence_2.docx'
                && $partName === 'word/document.xml'
                && !in_array('المرسم بالسنة الأولى من الإجازة في علوم التمريض', $generatedPart['paragraphs'], true)) {
                throw new RuntimeException('Arabic enrollment phrase is not in the expected order.');
            }
            foreach (['<w:pPr', '<w:rPr', '<w:rtl', '<w:rFonts', '<w:bidi'] as $property) {
                $sourceCount = substr_count($sourcePart['xml'], $property);
                $generatedCount = substr_count($generatedPart['xml'], $property);
                if ($generatedCount !== $sourceCount) {
                    throw new RuntimeException('Word formatting changed in ' . $partName . ': ' . $property);
                }
            }
            foreach ($generatedPart['paragraphs'] as $paragraphText) {
                if (preg_match('/(?:\$\{[^}]+\}|\{[\p{L}\p{N}_]+\})/u', $paragraphText)) {
                    throw new RuntimeException('Unreplaced text placeholder in ' . $relativePath . ' ' . $partName);
                }
            }
        }

        $generatedArchive = new ZipArchive();
        if ($generatedArchive->open($copyPath) !== true) {
            throw new RuntimeException('Unable to verify generated logo archive: ' . $relativePath);
        }
        $embeddedLogo = $generatedArchive->getFromName('word/media/institution-logo.png');
        $contentTypesXml = $generatedArchive->getFromName('[Content_Types].xml');
        $logoReferences = 0;
        $logoRelationshipId = '';
        $logoXml = '';
        $relationshipsOk = false;
        $validLogoOffsets = 0;
        for ($partIndex = 0; $partIndex < $generatedArchive->numFiles; $partIndex++) {
            $partName = $generatedArchive->getNameIndex($partIndex);
            if (!is_string($partName) || !preg_match('~^word/(?:document|header\d*|footer\d*)\.xml$~', $partName)) {
                continue;
            }
            $partXml = $generatedArchive->getFromName($partName);
            if (!is_string($partXml)) {
                continue;
            }
            $matches = [];
            $partReferences = preg_match_all('/r:embed="(rIdInstitutionLogo\d+)"/', $partXml, $matches);
            if ($partReferences > 0) {
                $logoReferences += $partReferences;
                $logoRelationshipId = $matches[1][0] ?? $logoRelationshipId;
                $logoXml .= $partXml;
                $drawingDocument = new DOMDocument();
                if (!$drawingDocument->loadXML($partXml, LIBXML_NONET)) {
                    $generatedArchive->close();
                    throw new RuntimeException('Invalid image markup in ' . $partName);
                }
                $drawingXpath = new DOMXPath($drawingDocument);
                $drawingXpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $validLogoOffsets += $drawingXpath->query('//a:xfrm/a:off[@x="0" and @y="0"]')->length;
                $relationshipsXml = $generatedArchive->getFromName('word/_rels/' . basename($partName) . '.rels');
                if (is_string($relationshipsXml)
                    && str_contains($relationshipsXml, 'media/institution-logo.png')
                    && str_contains($relationshipsXml, 'Id="' . ($matches[1][0] ?? '') . '"')) {
                    $relationshipsOk = true;
                }
            }
        }
        if ($logoReferences !== 2 || !$relationshipsOk || $logoRelationshipId === ''
            || !is_string($embeddedLogo) || $embeddedLogo === ''
            || !is_string($contentTypesXml) || !str_contains($contentTypesXml, 'image/png')) {
            $generatedArchive->close();
            throw new RuntimeException('Logo image, relationship, or content type was not embedded: ' . $relativePath);
        }
        if (!preg_match('/<wp:extent cx="(\d+)" cy="(\d+)"/', $logoXml, $logoExtent)
            || (int) $logoExtent[1] < 1_300_000
            || (int) $logoExtent[2] < 1_200_000) {
            $generatedArchive->close();
            throw new RuntimeException('Logo is not embedded at the expected visible size: ' . $relativePath);
        }
        if ($validLogoOffsets !== $logoReferences) {
            $generatedArchive->close();
            throw new RuntimeException('Logo transform offsets are invalid: ' . $relativePath);
        }
        $generatedArchive->close();

        echo 'ok  ' . $relativePath . " copy, split-run replacement, and formatting\n";
    } finally {
        if (is_file($copyPath)) {
            unlink($copyPath);
        }
    }
}

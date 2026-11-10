<?php
declare(strict_types=1);

/**
 * Attestation cachet + numbering (no custom body texts).
 */

function certificate_types(): array
{
    return [
        'inscription' => 'Attestation d’inscription',
        'presence' => 'Attestation de présence',
        'reussite' => 'Attestation de réussite',
    ];
}

function certificate_config_ensure(): void
{
    institution_ensure();
    $insert = db()->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        'attestation_cachet_custom' => '0',
        'attestation_prefix_inscription' => 'ATT-INS-',
        'attestation_next_inscription' => '1',
        'attestation_prefix_presence' => 'ATT-PRE-',
        'attestation_next_presence' => '1',
        'attestation_prefix_reussite' => 'ATT-REU-',
        'attestation_next_reussite' => '1',
    ] as $key => $value) {
        $insert->execute([$key, $value]);
    }
}

function certificate_cachet_custom(): bool
{
    certificate_config_ensure();
    return institution_setting('attestation_cachet_custom') === '1';
}

function certificate_cachet_src(): string
{
    if (!certificate_cachet_custom()) {
        return '';
    }
    try {
        $stamp = (int) db()->query("SELECT UNIX_TIMESTAMP(updated_at) FROM institution_logo WHERE asset_key = 'cachet'")->fetchColumn();
    } catch (Throwable $exception) {
        $stamp = time();
    }
    return '/brand_logo?asset=cachet&v=' . max(1, $stamp);
}

function certificate_cachet_file(): ?array
{
    if (!certificate_cachet_custom()) {
        return null;
    }
    try {
        $row = db()->query("SELECT mime, content FROM institution_logo WHERE asset_key = 'cachet'")->fetch();
    } catch (Throwable $exception) {
        return null;
    }
    $binary = $row ? pg_blob($row['content']) : '';
    if (!$row || $binary === '') {
        return null;
    }
    $extension = match ((string) $row['mime']) {
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg',
    };
    $path = tempnam(sys_get_temp_dir(), 'cachet');
    if ($path === false) {
        return null;
    }
    $file = $path . '.' . $extension;
    file_put_contents($file, $binary);
    @unlink($path);
    return ['path' => $file, 'temporary' => true, 'mime' => (string) $row['mime']];
}

function certificate_save_cachet(array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Le fichier du cachet n’a pas pu être reçu.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > 2_000_000) {
        throw new InvalidArgumentException('Le cachet doit peser moins de 2 Mo.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $types = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];
    $type = (int) ($info[2] ?? 0);
    if (!isset($types[$type])) {
        throw new InvalidArgumentException('Le cachet doit être une image PNG, JPG, GIF ou WebP.');
    }
    $content = file_get_contents($tmp);
    if ($content === false || $content === '') {
        throw new InvalidArgumentException('Le cachet est vide.');
    }
    certificate_config_ensure();
    $statement = db()->prepare(
        'INSERT INTO institution_logo (asset_key, mime, content) VALUES ("cachet", ?, ?)
         ON DUPLICATE KEY UPDATE mime = VALUES(mime), content = VALUES(content)'
    );
    $statement->bindValue(1, $types[$type]);
    $statement->bindValue(2, $content, PDO::PARAM_LOB);
    $statement->execute();
    institution_setting_put('attestation_cachet_custom', '1');
    academic_policy_note('attestation_cachet', 'Cachet d’attestation mis à jour');
}

function certificate_reset_cachet(): void
{
    certificate_config_ensure();
    db()->exec("DELETE FROM institution_logo WHERE asset_key = 'cachet'");
    institution_setting_put('attestation_cachet_custom', '0');
    academic_policy_note('attestation_cachet', 'Cachet d’attestation retiré');
}

function certificate_numbering(string $type): array
{
    if (!isset(certificate_types()[$type])) {
        throw new InvalidArgumentException('Type d’attestation inconnu.');
    }
    certificate_config_ensure();
    $prefix = trim(institution_setting('attestation_prefix_' . $type));
    if ($prefix === '') {
        $prefix = match ($type) {
            'presence' => 'ATT-PRE-',
            'reussite' => 'ATT-REU-',
            default => 'ATT-INS-',
        };
    }
    $next = max(1, (int) institution_setting('attestation_next_' . $type));
    return ['prefix' => $prefix, 'next' => $next];
}

function certificate_format_number(string $prefix, int $number): string
{
    $prefix = trim($prefix);
    if ($prefix === '') {
        $prefix = 'ATT-';
    }
    return 'N° ' . $prefix . str_pad((string) max(1, $number), 5, '0', STR_PAD_LEFT);
}

function certificate_save_numbering(string $type, string $prefix, int $next): void
{
    if (!isset(certificate_types()[$type])) {
        throw new InvalidArgumentException('Type d’attestation inconnu.');
    }
    $prefix = trim(preg_replace('/\s+/u', '', $prefix) ?? '');
    if ($prefix === '' || mb_strlen($prefix) > 24 || !preg_match('/^[A-Za-z0-9_\-\/]+$/u', $prefix)) {
        throw new InvalidArgumentException('Préfixe invalide (lettres, chiffres, - _ / , 24 caractères max).');
    }
    if ($next < 1 || $next > 999999) {
        throw new InvalidArgumentException('Le prochain numéro doit être entre 1 et 999999.');
    }
    certificate_config_ensure();
    institution_setting_put('attestation_prefix_' . $type, $prefix);
    institution_setting_put('attestation_next_' . $type, (string) $next);
    academic_policy_note('attestation_numero', $type . ' · ' . $prefix . ' prochain=' . $next);
}

/**
 * @return string Display label e.g. N° ATT-INS-00041
 */
function certificate_allocate_number(string $type, bool $commit): string
{
    $config = certificate_numbering($type);
    $label = certificate_format_number($config['prefix'], $config['next']);
    if ($commit) {
        institution_setting_put('attestation_next_' . $type, (string) ($config['next'] + 1));
    }
    return $label;
}

function certificate_docx_apply_extras(string $docxPath, ?string $numeroLabel, ?string $cachetPath): void
{
    $archive = new ZipArchive();
    if ($archive->open($docxPath) !== true) {
        throw new RuntimeException('Impossible d’ouvrir le document pour le cachet / numéro.');
    }
    try {
        if (is_string($numeroLabel) && trim($numeroLabel) !== '') {
            certificate_docx_inject_number($archive, $numeroLabel);
        }
        if (is_string($cachetPath) && $cachetPath !== '' && is_file($cachetPath)) {
            certificate_docx_inject_cachet($archive, $cachetPath);
        }
    } finally {
        if (!$archive->close()) {
            throw new RuntimeException('Impossible d’enregistrer le cachet / numéro.');
        }
    }
}

function certificate_docx_inject_number(ZipArchive $archive, string $numeroLabel): void
{
    $xml = $archive->getFromName('word/document.xml');
    if (!is_string($xml)) {
        throw new RuntimeException('document.xml introuvable.');
    }
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->preserveWhiteSpace = true;
    if (!$document->loadXML($xml, LIBXML_NONET)) {
        throw new RuntimeException('document.xml invalide.');
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $body = $xpath->query('//w:body')->item(0);
    if (!$body instanceof DOMElement) {
        throw new RuntimeException('Corps Word introuvable.');
    }

    // Soft-replace template markers if present (${num_att} preferred).
    $replaced = false;
    $markers = [
        '${num_att}',
        '{num_att}',
        '${numero_attestation}',
        '{numero_attestation}',
    ];
    foreach ($xpath->query('//w:t') as $textNode) {
        $value = (string) ($textNode->nodeValue ?? '');
        foreach ($markers as $marker) {
            if (str_contains($value, $marker)) {
                $textNode->nodeValue = str_replace($marker, $numeroLabel, $value);
                $value = (string) $textNode->nodeValue;
                $replaced = true;
            }
        }
    }

    if (!$replaced) {
        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $paragraph = $document->createElementNS($wNs, 'w:p');
        $pPr = $document->createElementNS($wNs, 'w:pPr');
        $jc = $document->createElementNS($wNs, 'w:jc');
        $jc->setAttribute('w:val', 'right');
        $pPr->appendChild($jc);
        $paragraph->appendChild($pPr);
        $run = $document->createElementNS($wNs, 'w:r');
        $rPr = $document->createElementNS($wNs, 'w:rPr');
        $bold = $document->createElementNS($wNs, 'w:b');
        $sz = $document->createElementNS($wNs, 'w:sz');
        $sz->setAttribute('w:val', '20');
        $rPr->appendChild($bold);
        $rPr->appendChild($sz);
        $run->appendChild($rPr);
        $text = $document->createElementNS($wNs, 'w:t');
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $text->nodeValue = $numeroLabel;
        $run->appendChild($text);
        $paragraph->appendChild($run);
        $first = $body->firstChild;
        if ($first) {
            $body->insertBefore($paragraph, $first);
        } else {
            $body->appendChild($paragraph);
        }
    }

    $updated = $document->saveXML();
    if (!is_string($updated) || !$archive->addFromString('word/document.xml', $updated)) {
        throw new RuntimeException('Impossible d’écrire le numéro d’attestation.');
    }
}

function certificate_docx_inject_cachet(ZipArchive $archive, string $cachetPath): void
{
    $imageInfo = getimagesize($cachetPath);
    if ($imageInfo === false || empty($imageInfo['mime'])) {
        throw new RuntimeException('Le cachet n’est pas une image valide.');
    }
    $formats = [
        'image/jpeg' => ['jpg', 'image/jpeg'],
        'image/png' => ['png', 'image/png'],
        'image/gif' => ['gif', 'image/gif'],
    ];
    $logoTemporaryPath = null;
    $mime = (string) $imageInfo['mime'];
    $path = $cachetPath;
    if ($mime === 'image/webp') {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagepng')) {
            throw new RuntimeException('Conversion WebP du cachet impossible.');
        }
        $image = imagecreatefromwebp($cachetPath);
        if ($image === false) {
            throw new RuntimeException('Impossible de lire le cachet WebP.');
        }
        $logoTemporaryPath = tempnam(sys_get_temp_dir(), 'cachet-');
        if ($logoTemporaryPath === false || !imagepng($image, $logoTemporaryPath)) {
            imagedestroy($image);
            throw new RuntimeException('Impossible de convertir le cachet WebP.');
        }
        imagedestroy($image);
        $path = $logoTemporaryPath;
        $imageInfo = getimagesize($path);
        $mime = 'image/png';
    }
    if (!isset($formats[$mime])) {
        throw new RuntimeException('Format de cachet incompatible avec Word.');
    }
    try {
        $imageContent = file_get_contents($path);
        if ($imageContent === false || $imageContent === '') {
            throw new RuntimeException('Cachet vide.');
        }
        $extension = $formats[$mime][0];
        $mediaName = 'institution-cachet.' . $extension;
        $relationshipId = 'rIdInstitutionCachet1';

        $xml = $archive->getFromName('word/document.xml');
        if (!is_string($xml)) {
            throw new RuntimeException('document.xml introuvable.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('document.xml invalide.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $xpath->query('//w:body')->item(0);
        if (!$body instanceof DOMElement) {
            throw new RuntimeException('Corps Word introuvable.');
        }

        // Soft replace ${cachet} markers if present (same approach as logo).
        $markerFound = false;
        foreach ($xpath->query('//w:p') as $paragraph) {
            $textNodes = $xpath->query('.//w:t', $paragraph);
            $combined = '';
            foreach ($textNodes as $node) {
                $combined .= $node->nodeValue ?? '';
            }
            if (preg_match('/\$?\{cachet\}/i', $combined)) {
                $markerFound = true;
                break;
            }
        }

        if ($markerFound) {
            foreach ($xpath->query('//w:t') as $textNode) {
                $value = (string) ($textNode->nodeValue ?? '');
                if (str_contains(strtolower($value), '{cachet}')) {
                    $textNode->nodeValue = preg_replace('/\$?\{cachet\}/i', '', $value) ?? '';
                }
            }
        }

        $drawing = institution_docx_logo_drawing($document, $relationshipId, $extension, $imageInfo, 9001);
        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $paragraph = $document->createElementNS($wNs, 'w:p');
        $pPr = $document->createElementNS($wNs, 'w:pPr');
        $jc = $document->createElementNS($wNs, 'w:jc');
        $jc->setAttribute('w:val', 'right');
        $pPr->appendChild($jc);
        $paragraph->appendChild($pPr);
        $paragraph->appendChild($drawing);

        $sectPr = $xpath->query('./w:sectPr', $body)->item(0);
        if ($sectPr) {
            $body->insertBefore($paragraph, $sectPr);
        } else {
            $body->appendChild($paragraph);
        }

        $updated = $document->saveXML();
        if (!is_string($updated) || !$archive->addFromString('word/document.xml', $updated)) {
            throw new RuntimeException('Impossible d’écrire le cachet.');
        }
        if (!$archive->addFromString('word/media/' . $mediaName, $imageContent)) {
            throw new RuntimeException('Impossible d’ajouter le fichier cachet.');
        }
        // Relationship targeting cachet media (not logo filename helper).
        $relationshipsName = 'word/_rels/document.xml.rels';
        $relationshipsXml = $archive->getFromName($relationshipsName);
        $relationships = new DOMDocument('1.0', 'UTF-8');
        if (!is_string($relationshipsXml) || !$relationships->loadXML($relationshipsXml, LIBXML_NONET)) {
            throw new RuntimeException('Relations Word invalides.');
        }
        $root = $relationships->documentElement;
        $relXpath = new DOMXPath($relationships);
        $relXpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        if ($relXpath->query('//rel:Relationship[@Id="' . $relationshipId . '"]')->length === 0) {
            $relationship = $relationships->createElementNS(
                'http://schemas.openxmlformats.org/package/2006/relationships',
                'Relationship'
            );
            $relationship->setAttribute('Id', $relationshipId);
            $relationship->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
            $relationship->setAttribute('Target', 'media/' . $mediaName);
            $root->appendChild($relationship);
            $relXml = $relationships->saveXML();
            if (!is_string($relXml) || !$archive->addFromString($relationshipsName, $relXml)) {
                throw new RuntimeException('Impossible d’enregistrer la relation du cachet.');
            }
        }
        institution_docx_add_logo_content_type($archive, $extension, $formats[$mime][1]);
    } finally {
        if (is_string($logoTemporaryPath) && is_file($logoTemporaryPath)) {
            unlink($logoTemporaryPath);
        }
    }
}

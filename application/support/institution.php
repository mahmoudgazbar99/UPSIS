<?php
declare(strict_types=1);

const INSTITUTION_NAME_DEFAULT = "Université privée des sciences infirmières et de santé\nde Gabès";
const INSTITUTION_SECRETARY_DEFAULT = 'Said ALJANE';
const INSTITUTION_SECRETARY_AR_DEFAULT = 'سعيد علجان';
const FORMULA_MOYENNE_DEFAULT = 'has_rattrapage ? rattrapage : ((!has_cc || !has_examen) ? note : (has_tp ? cc * 0.25 + examen * 0.5 + tp * 0.25 : cc * 0.3 + examen * 0.7))';
const FORMULA_GENERALE_DEFAULT = 'somme_coeff > 0 ? somme_ponderee / somme_coeff : 0';
const FORMULA_CREDIT_DEFAULT = 'moyenne >= 10 ? credit : 0';

function institution_ensure(): void
{
    static $ready = false;
    if ($ready || app_schema_is_ready('institution')) {
        $ready = true;
        return;
    }
    $ready = true;
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return;
    }
    if (!db_table_exists($pdo, 'system_settings')) {
        $pdo->exec(
            "CREATE TABLE system_settings (
              id_setting INT UNSIGNED NOT NULL AUTO_INCREMENT,
              setting_key VARCHAR(80) NOT NULL,
              setting_value TEXT NOT NULL,
              PRIMARY KEY (id_setting),
              UNIQUE KEY uq_system_settings_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } else {
        $column = $pdo->query("SHOW COLUMNS FROM system_settings LIKE 'setting_value'")->fetch();
        $type = strtolower((string) ($column['Type'] ?? ''));
        if ($type !== '' && !str_contains($type, 'text')) {
            $pdo->exec('ALTER TABLE system_settings MODIFY setting_value TEXT NOT NULL');
        }
    }
    if (!db_table_exists($pdo, 'institution_logo')) {
        $pdo->exec(
            "CREATE TABLE institution_logo (
              asset_key VARCHAR(40) NOT NULL,
              mime VARCHAR(80) NOT NULL,
              content LONGBLOB NOT NULL,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (asset_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
    if (!db_table_exists($pdo, 'unite')) {
        $pdo->exec(
            "CREATE TABLE unite (
              id_unite INT UNSIGNED NOT NULL AUTO_INCREMENT,
              code_ue VARCHAR(30) NOT NULL,
              description_ue VARCHAR(255) NOT NULL,
              semestre ENUM('S1','S2','S3','S4','S5','S6') NOT NULL DEFAULT 'S1',
              coefficient_ue DECIMAL(4,2) NOT NULL DEFAULT 1.00,
              PRIMARY KEY (id_unite),
              UNIQUE KEY uq_unite_code (code_ue)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
    $insert = $pdo->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        'sec_generale' => INSTITUTION_SECRETARY_DEFAULT,
        'sec_generale_ar' => INSTITUTION_SECRETARY_AR_DEFAULT,
        'directeur' => '',
        'directeur_ar' => '',
        'university_name' => INSTITUTION_NAME_DEFAULT,
        'university_logo_custom' => '0',
        'formula_moyenne' => '',
        'formula_generale' => '',
        'formula_credit' => '',
    ] as $key => $value) {
        $insert->execute([$key, $value]);
    }
    $legacyName = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $legacyName->execute(['university_name']);
    $insert->execute(['establishment_fr', (string) ($legacyName->fetchColumn() ?: INSTITUTION_NAME_DEFAULT)]);
    $insert->execute(['establishment_ar', '']);
    app_schema_mark_ready('institution');
}

function institution_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($refresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    $defaults = [
        'sec_generale' => INSTITUTION_SECRETARY_DEFAULT,
        'sec_generale_ar' => INSTITUTION_SECRETARY_AR_DEFAULT,
        'directeur' => '',
        'directeur_ar' => '',
        'university_name' => INSTITUTION_NAME_DEFAULT,
        'establishment_fr' => INSTITUTION_NAME_DEFAULT,
        'establishment_ar' => '',
        'university_logo_custom' => '0',
        'formula_moyenne' => '',
        'formula_generale' => '',
        'formula_credit' => '',
    ];
    try {
        institution_ensure();
        $rows = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
        foreach ($rows as $row) {
            $defaults[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
    } catch (Throwable $exception) {
        /* Keep the built-in identity if the database is unavailable. */
    }
    $cache = $defaults;
    return $cache;
}

function institution_setting(string $key): string
{
    $settings = institution_settings();
    return (string) ($settings[$key] ?? '');
}

function institution_setting_put(string $key, string $value): void
{
    institution_ensure();
    $statement = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute([$key, $value]);
    institution_settings(true);
}

function institution_name_lines(): array
{
    $name = trim(institution_setting('university_name'));
    if ($name === '') {
        $name = INSTITUTION_NAME_DEFAULT;
    }
    $lines = preg_split('/\R/u', $name) ?: [$name];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    return $lines !== [] ? $lines : ['Université'];
}

function institution_name_inline(): string
{
    return implode(' ', institution_name_lines());
}

function institution_establishment_name_fr(): string
{
    return trim(institution_setting('establishment_fr')) ?: institution_name_inline();
}

function institution_establishment_name_ar(): string
{
    return trim(institution_setting('establishment_ar'));
}

function institution_logo_custom(): bool
{
    return institution_setting('university_logo_custom') === '1';
}

function institution_logo_src(): string
{
    // Root-relative, extensionless: .php image URLs get 308-canonicalized on Render
    // and browsers often show a broken <img> instead of following that redirect.
    if (!institution_logo_custom()) {
        return '/images/no_bg_logo.png?v=2';
    }
    try {
        $stamp = (int) db()->query("SELECT UNIX_TIMESTAMP(updated_at) FROM institution_logo WHERE asset_key = 'logo'")->fetchColumn();
    } catch (Throwable $exception) {
        $stamp = time();
    }
    return '/brand_logo?v=' . max(1, $stamp);
}

function institution_logo_file(): array
{
    $fallback = ['path' => public_path('images/no_bg_logo.png'), 'temporary' => false];
    if (!institution_logo_custom()) {
        return $fallback;
    }
    try {
        $row = db()->query("SELECT mime, content FROM institution_logo WHERE asset_key = 'logo'")->fetch();
    } catch (Throwable $exception) {
        return $fallback;
    }
    $binary = $row ? pg_blob($row['content']) : '';
    if (!$row || $binary === '') {
        return $fallback;
    }
    $extension = match ((string) $row['mime']) {
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg',
    };
    $path = tempnam(sys_get_temp_dir(), 'brand-logo');
    if ($path === false) {
        return $fallback;
    }
    $file = $path . '.' . $extension;
    file_put_contents($file, $binary);
    @unlink($path);
    return ['path' => $file, 'temporary' => true];
}

function institution_brand(): array
{
    return [
        'name' => trim(institution_setting('university_name')) ?: INSTITUTION_NAME_DEFAULT,
        'name_inline' => institution_name_inline(),
        'lines' => institution_name_lines(),
        'establishment_fr' => institution_establishment_name_fr(),
        'establishment_ar' => institution_establishment_name_ar(),
        'sec_generale' => trim(institution_setting('sec_generale')) ?: INSTITUTION_SECRETARY_DEFAULT,
        'sec_generale_ar' => trim(institution_setting('sec_generale_ar')) ?: INSTITUTION_SECRETARY_AR_DEFAULT,
        'directeur' => trim(institution_setting('directeur')),
        'directeur_ar' => trim(institution_setting('directeur_ar')),
        'logo' => institution_logo_src(),
    ];
}

function institution_ltr_text(string $value): string
{
    return $value === '' ? '' : "\u{2066}{$value}\u{2069}";
}

function institution_rtl_text(string $value): string
{
    return $value === '' ? '' : "\u{2067}{$value}\u{2069}";
}

function institution_copy_docx_template(string $sourcePath): string
{
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Le modèle Word est introuvable.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'docx-template-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Impossible de créer une copie temporaire du modèle Word.');
    }

    $copyPath = $temporaryPath . '.docx';
    if (!rename($temporaryPath, $copyPath)) {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
        throw new RuntimeException('Impossible de créer une copie temporaire du modèle Word.');
    }
    if (!copy($sourcePath, $copyPath)) {
        unlink($copyPath);
        throw new RuntimeException('Impossible de copier le modèle Word.');
    }

    return $copyPath;
}

function institution_separate_establishment_names(DOMXPath $xpath): void
{
    $paragraphs = $xpath->query('//w:p');
    if ($paragraphs === false) {
        return;
    }
    $nodes = [];
    foreach ($paragraphs as $paragraph) {
        if ($paragraph instanceof DOMElement) {
            $nodes[] = $paragraph;
        }
    }
    $normalize = static function (DOMElement $paragraph) use ($xpath): string {
        $text = '';
        $textNodes = $xpath->query('.//w:t', $paragraph);
        if ($textNodes !== false) {
            foreach ($textNodes as $textNode) {
                $text .= $textNode->nodeValue ?? '';
            }
        }
        return preg_replace('/\s+/u', '', $text) ?? '';
    };
    $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    for ($index = 0, $count = count($nodes); $index < $count - 1; $index++) {
        $current = $normalize($nodes[$index]);
        $next = $normalize($nodes[$index + 1]);
        $separatesNames = ($current === '${establishment_fr}' && str_starts_with($next, '${establishment_ar}'))
            || ($current === '${establishment_ar}' && str_starts_with($next, '${establishment_fr}'));
        if (!$separatesNames) {
            continue;
        }
        $properties = $xpath->query('./w:pPr', $nodes[$index])->item(0);
        if (!$properties instanceof DOMElement) {
            $properties = $nodes[$index]->ownerDocument->createElementNS($namespace, 'w:pPr');
            $nodes[$index]->insertBefore($properties, $nodes[$index]->firstChild);
        }
        if ($xpath->query('./w:pBdr', $properties)->length > 0) {
            continue;
        }
        $border = $nodes[$index]->ownerDocument->createElementNS($namespace, 'w:pBdr');
        $bottom = $nodes[$index]->ownerDocument->createElementNS($namespace, 'w:bottom');
        $bottom->setAttributeNS($namespace, 'w:val', 'single');
        $bottom->setAttributeNS($namespace, 'w:sz', '12');
        $bottom->setAttributeNS($namespace, 'w:space', '1');
        $bottom->setAttributeNS($namespace, 'w:color', '000000');
        $border->appendChild($bottom);
        $properties->appendChild($border);
    }
}

function institution_replace_docx_placeholders(string $copyPath, array $values): void
{
    $archive = new ZipArchive();
    if ($archive->open($copyPath) !== true) {
        throw new RuntimeException('Impossible d’ouvrir la copie du modèle Word.');
    }

    $logoPath = null;
    if (array_key_exists('logo', $values)) {
        $logoPath = (string) $values['logo'];
        unset($values['logo']);
        if ($logoPath === '' || !is_file($logoPath)) {
            $archive->close();
            throw new RuntimeException('Le fichier du logo à intégrer au modèle Word est introuvable.');
        }
    }

    $replacements = [];
    $counts = [];
    foreach ($values as $key => $value) {
        if (!is_string($key) || preg_match('/^[\p{L}\p{N}_]+$/u', $key) !== 1) {
            $archive->close();
            throw new InvalidArgumentException('Nom de placeholder Word invalide.');
        }
        foreach (['${' . $key . '}', '{' . $key . '}'] as $placeholder) {
            $replacements[$placeholder] = (string) $value;
            $counts[$placeholder] = 0;
        }
    }

    try {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entryName = $archive->getNameIndex($index);
            if (!is_string($entryName) || !preg_match('~^word/(?:document|header\d*|footer\d*|footnotes|endnotes)\.xml$~', $entryName)) {
                continue;
            }

            $xml = $archive->getFromName($entryName);
            if (!is_string($xml)) {
                throw new RuntimeException('Impossible de lire la partie Word ' . $entryName . '.');
            }

            $document = new DOMDocument('1.0', 'UTF-8');
            $document->preserveWhiteSpace = true;
            $previousErrorMode = libxml_use_internal_errors(true);
            try {
                $loaded = $document->loadXML($xml, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrorMode);
            }
            if (!$loaded) {
                throw new RuntimeException('Le XML de la partie Word ' . $entryName . ' est invalide.');
            }

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            institution_separate_establishment_names($xpath);
            $paragraphs = $xpath->query('//w:p');
            if ($paragraphs === false) {
                throw new RuntimeException('Impossible de lire les paragraphes de ' . $entryName . '.');
            }

            foreach ($paragraphs as $paragraph) {
                $textNodes = $xpath->query('.//w:t', $paragraph);
                if ($textNodes === false || $textNodes->length === 0) {
                    continue;
                }

                foreach ($replacements as $placeholder => $replacement) {
                    while (true) {
                        $parts = [];
                        $combinedText = '';
                        foreach ($textNodes as $textNode) {
                            $parts[] = ['node' => $textNode, 'text' => $textNode->nodeValue ?? ''];
                            $combinedText .= $textNode->nodeValue ?? '';
                        }

                        $position = strpos($combinedText, $placeholder);
                        if ($position === false) {
                            break;
                        }

                        $offset = 0;
                        $startIndex = null;
                        $startOffset = 0;
                        $endIndex = null;
                        $endOffset = 0;
                        foreach ($parts as $partIndex => $part) {
                            $length = strlen($part['text']);
                            if ($startIndex === null && $position < $offset + $length) {
                                $startIndex = $partIndex;
                                $startOffset = $position - $offset;
                            }
                            if ($position + strlen($placeholder) <= $offset + $length) {
                                $endIndex = $partIndex;
                                $endOffset = $position + strlen($placeholder) - $offset;
                                break;
                            }
                            $offset += $length;
                        }
                        if ($startIndex === null || $endIndex === null) {
                            throw new RuntimeException('Impossible de localiser le placeholder ' . $placeholder . '.');
                        }

                        $startText = $parts[$startIndex]['text'];
                        $endText = $parts[$endIndex]['text'];
                        $parts[$startIndex]['node']->nodeValue = substr($startText, 0, $startOffset)
                            . $replacement
                            . ($startIndex === $endIndex ? substr($startText, $endOffset) : substr($endText, $endOffset));
                        if ($startIndex !== $endIndex) {
                            for ($partIndex = $startIndex + 1; $partIndex < $endIndex; $partIndex++) {
                                $parts[$partIndex]['node']->nodeValue = '';
                            }
                            $parts[$endIndex]['node']->nodeValue = '';
                        }
                        $counts[$placeholder]++;
                    }
                }
            }

            $updatedXml = $document->saveXML();
            if (!is_string($updatedXml) || !$archive->addFromString($entryName, $updatedXml)) {
                throw new RuntimeException('Impossible d’enregistrer la partie Word ' . $entryName . '.');
            }
        }

        if ($logoPath !== null) {
            if (!$archive->close()) {
                throw new RuntimeException('Impossible d’enregistrer les remplacements textuels du document Word.');
            }
            if ($archive->open($copyPath) !== true) {
                throw new RuntimeException('Impossible de rouvrir le document Word pour intégrer le logo.');
            }
            institution_docx_embed_logo($archive, $logoPath);
        }

        foreach ($values as $key => $_value) {
            $dollarPlaceholder = '${' . $key . '}';
            $plainPlaceholder = '{' . $key . '}';
            if (($counts[$dollarPlaceholder] + $counts[$plainPlaceholder]) === 0) {
                throw new RuntimeException('Le modèle ne contient pas le placeholder ' . $dollarPlaceholder . ' ou ' . $plainPlaceholder . '.');
            }
        }

    } catch (Throwable $exception) {
        $archive->close();
        throw $exception;
    }

    if (!$archive->close()) {
        throw new RuntimeException('Impossible d’enregistrer la copie du document Word.');
    }
}

function institution_docx_embed_logo(ZipArchive $archive, string $logoPath): void
{
    $imageInfo = getimagesize($logoPath);
    if ($imageInfo === false || empty($imageInfo['mime'])) {
        throw new RuntimeException('Le fichier du logo n’est pas une image valide.');
    }

    $formats = [
        'image/jpeg' => ['jpg', 'image/jpeg'],
        'image/png' => ['png', 'image/png'],
        'image/gif' => ['gif', 'image/gif'],
    ];
    $logoTemporaryPath = null;
    $mime = (string) $imageInfo['mime'];
    if ($mime === 'image/webp') {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagepng')) {
            throw new RuntimeException('La conversion du logo WebP nécessite GD avec le support WebP.');
        }
        $image = imagecreatefromwebp($logoPath);
        if ($image === false) {
            throw new RuntimeException('Impossible de lire le logo WebP.');
        }
        $logoTemporaryPath = tempnam(sys_get_temp_dir(), 'docx-logo-');
        if ($logoTemporaryPath === false) {
            imagedestroy($image);
            throw new RuntimeException('Impossible de convertir le logo WebP pour Word.');
        }
        if (!imagepng($image, $logoTemporaryPath)) {
            imagedestroy($image);
            unlink($logoTemporaryPath);
            throw new RuntimeException('Impossible de convertir le logo WebP pour Word.');
        }
        imagedestroy($image);
        $logoPath = $logoTemporaryPath;
        $imageInfo = getimagesize($logoPath);
        $mime = 'image/png';
    }
    if (!isset($formats[$mime])) {
        throw new RuntimeException('Le format du logo n’est pas compatible avec le document Word.');
    }

    try {
        $imageContent = file_get_contents($logoPath);
        if ($imageContent === false || $imageContent === '') {
            throw new RuntimeException('Impossible de lire le contenu du logo.');
        }

        $relationshipId = 'rIdInstitutionLogo1';
        $logoCount = 0;
        $wordParts = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entryName = $archive->getNameIndex($index);
            if (is_string($entryName) && preg_match('~^word/(?:document|header\d*|footer\d*|footnotes|endnotes)\.xml$~', $entryName)) {
                $wordParts[] = $entryName;
            }
        }
        foreach ($wordParts as $entryName) {
            $partXml = $archive->getFromName($entryName);
            if (!is_string($partXml)) {
                throw new RuntimeException('Impossible de lire la partie Word ' . $entryName . '.');
            }
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->preserveWhiteSpace = true;
            if (!$document->loadXML($partXml, LIBXML_NONET)) {
                throw new RuntimeException('Le XML de la partie Word ' . $entryName . ' est invalide.');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $partLogoCount = 0;
        foreach ($xpath->query('//w:p') as $paragraph) {
            while (true) {
                $textNodes = $xpath->query('.//w:t', $paragraph);
                $combinedText = '';
                foreach ($textNodes as $textNode) {
                    $combinedText .= $textNode->nodeValue ?? '';
                }
                if (!preg_match('/\$?\{logo\}/i', $combinedText, $logoMatch, PREG_OFFSET_CAPTURE)) {
                    break;
                }
                $marker = $logoMatch[0][0];
                $position = $logoMatch[0][1];

                $offset = 0;
                $startNode = null;
                $endNode = null;
                $startOffset = 0;
                $endOffset = 0;
                foreach ($textNodes as $textNode) {
                    $length = strlen($textNode->nodeValue ?? '');
                    if ($startNode === null && $position < $offset + $length) {
                        $startNode = $textNode;
                        $startOffset = $position - $offset;
                    }
                    if ($position + strlen($marker) <= $offset + $length) {
                        $endNode = $textNode;
                        $endOffset = $position + strlen($marker) - $offset;
                        break;
                    }
                    $offset += $length;
                }
                if (!$startNode instanceof DOMElement || !$endNode instanceof DOMElement) {
                    throw new RuntimeException('Impossible de localiser le placeholder du logo.');
                }
                $run = $startNode->parentNode;
                if (!$run instanceof DOMElement || $run->localName !== 'r') {
                    throw new RuntimeException('Le placeholder du logo ne se trouve pas dans un segment Word valide.');
                }

                $startText = $startNode->nodeValue ?? '';
                $endText = $endNode->nodeValue ?? '';
                $startNode->nodeValue = substr($startText, 0, $startOffset);
                if ($startNode === $endNode) {
                    $suffix = substr($startText, $endOffset);
                    $endNode->nodeValue = $startNode->nodeValue;
                    $suffixNode = $suffix === '' ? null : $document->createElementNS(
                        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                        'w:t'
                    );
                    if ($suffixNode instanceof DOMElement) {
                        $suffixNode->nodeValue = $suffix;
                    }
                } else {
                    $endNode->nodeValue = substr($endText, $endOffset);
                    $suffixNode = null;
                    $passedStart = false;
                    foreach ($textNodes as $textNode) {
                        if ($textNode === $startNode) {
                            $passedStart = true;
                            continue;
                        }
                        if ($textNode === $endNode) {
                            break;
                        }
                        if ($passedStart) {
                            $textNode->nodeValue = '';
                        }
                    }
                }

                $drawing = institution_docx_logo_drawing($document, $relationshipId, $formats[$mime][0], $imageInfo, ++$logoCount);
                $partLogoCount++;
                $run->parentNode?->insertBefore($drawing, $run->nextSibling);
                if ($suffixNode instanceof DOMElement) {
                    $startNode->parentNode?->insertBefore($suffixNode, $startNode->nextSibling);
                }
            }
        }
            if ($partLogoCount === 0) {
                continue;
            }
            $updatedXml = $document->saveXML();
            if (!is_string($updatedXml) || !$archive->addFromString($entryName, $updatedXml)) {
                throw new RuntimeException('Impossible d’enregistrer le logo dans ' . $entryName . '.');
            }
            institution_docx_add_logo_relationship(
                $archive,
                $relationshipId,
                $formats[$mime][0],
                'word/_rels/' . basename($entryName) . '.rels'
            );
        }
        if ($logoCount === 0) {
            throw new RuntimeException('Le modèle ne contient pas le placeholder ${logo} ou {logo}.');
        }
        if (!$archive->addFromString('word/media/' . institution_docx_logo_filename($formats[$mime][0]), $imageContent)) {
            throw new RuntimeException('Impossible d’ajouter le fichier image du logo au document Word.');
        }
        institution_docx_add_logo_content_type($archive, $formats[$mime][0], $formats[$mime][1]);
    } finally {
        if (is_string($logoTemporaryPath) && is_file($logoTemporaryPath)) {
            unlink($logoTemporaryPath);
        }
    }
}

function institution_docx_logo_filename(string $extension): string
{
    return 'institution-logo.' . $extension;
}

function institution_docx_logo_relationship(ZipArchive $archive): string
{
    $relationshipsXml = $archive->getFromName('word/_rels/document.xml.rels');
    if (!is_string($relationshipsXml)) {
        throw new RuntimeException('Le modèle ne contient pas les relations du document Word.');
    }
    $relationships = new DOMDocument('1.0', 'UTF-8');
    if (!$relationships->loadXML($relationshipsXml, LIBXML_NONET)) {
        throw new RuntimeException('Les relations du document Word sont invalides.');
    }
    $xpath = new DOMXPath($relationships);
    $xpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
    for ($index = 1; ; $index++) {
        $candidate = 'rIdInstitutionLogo' . $index;
        if ($xpath->query('//rel:Relationship[@Id="' . $candidate . '"]')->length === 0) {
            return $candidate;
        }
    }
}

function institution_docx_add_logo_relationship(ZipArchive $archive, string $relationshipId, string $extension, string $relationshipsName = 'word/_rels/document.xml.rels'): void
{
    $relationshipsXml = $archive->getFromName($relationshipsName);
    $relationships = new DOMDocument('1.0', 'UTF-8');
    if (!is_string($relationshipsXml)) {
        $relationships->loadXML(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
            LIBXML_NONET
        );
    } elseif (!$relationships->loadXML($relationshipsXml, LIBXML_NONET)) {
        throw new RuntimeException('Les relations du document Word sont invalides.');
    }
    $root = $relationships->documentElement;
    if (!$root instanceof DOMElement) {
        throw new RuntimeException('Les relations du document Word sont invalides.');
    }
    $xpath = new DOMXPath($relationships);
    $xpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
    if ($xpath->query('//rel:Relationship[@Id="' . $relationshipId . '"]')->length > 0) {
        return;
    }
    $relationship = $relationships->createElementNS(
        'http://schemas.openxmlformats.org/package/2006/relationships',
        'Relationship'
    );
    $relationship->setAttribute('Id', $relationshipId);
    $relationship->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
    $relationship->setAttribute('Target', 'media/' . institution_docx_logo_filename($extension));
    $root->appendChild($relationship);
    $updatedXml = $relationships->saveXML();
    if (!is_string($updatedXml) || !$archive->addFromString($relationshipsName, $updatedXml)) {
        throw new RuntimeException('Impossible d’enregistrer la relation du logo Word.');
    }
}

function institution_docx_add_logo_content_type(ZipArchive $archive, string $extension, string $mime): void
{
    $contentTypesXml = $archive->getFromName('[Content_Types].xml');
    if (!is_string($contentTypesXml)) {
        throw new RuntimeException('Le modèle ne contient pas les types de contenu Word.');
    }
    $contentTypes = new DOMDocument('1.0', 'UTF-8');
    if (!$contentTypes->loadXML($contentTypesXml, LIBXML_NONET)) {
        throw new RuntimeException('Les types de contenu du document Word sont invalides.');
    }
    $xpath = new DOMXPath($contentTypes);
    $xpath->registerNamespace('ct', 'http://schemas.openxmlformats.org/package/2006/content-types');
    if ($xpath->query('//ct:Default[@Extension="' . $extension . '"]')->length > 0) {
        return;
    }
    $default = $contentTypes->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Default');
    $default->setAttribute('Extension', $extension);
    $default->setAttribute('ContentType', $mime);
    $root = $contentTypes->documentElement;
    if (!$root instanceof DOMElement) {
        throw new RuntimeException('Les types de contenu du document Word sont invalides.');
    }
    $firstOverride = $xpath->query('./ct:Override', $root)->item(0);
    $root->insertBefore($default, $firstOverride);
    $updatedXml = $contentTypes->saveXML();
    if (!is_string($updatedXml) || !$archive->addFromString('[Content_Types].xml', $updatedXml)) {
        throw new RuntimeException('Impossible d’enregistrer le type de contenu du logo Word.');
    }
}

function institution_docx_logo_drawing(DOMDocument $document, string $relationshipId, string $extension, array $imageInfo, int $drawingId): DOMElement
{
    $wordNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $wpNs = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    $aNs = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $picNs = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
    $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $maxWidth = 1_371_600;
    $maxHeight = 1_371_600;
    $ratio = min($maxWidth / max(1, (int) $imageInfo[0]), $maxHeight / max(1, (int) $imageInfo[1]));
    $width = max(1, (int) round((int) $imageInfo[0] * $ratio));
    $height = max(1, (int) round((int) $imageInfo[1] * $ratio));

    $run = $document->createElementNS($wordNs, 'w:r');
    $drawing = $document->createElementNS($wordNs, 'w:drawing');
    $inline = $document->createElementNS($wpNs, 'wp:inline');
    foreach (['distT', 'distB', 'distL', 'distR'] as $distance) {
        $inline->setAttribute($distance, '0');
    }
    $extent = $document->createElementNS($wpNs, 'wp:extent');
    $extent->setAttribute('cx', (string) $width);
    $extent->setAttribute('cy', (string) $height);
    $inline->appendChild($extent);
    $docProperties = $document->createElementNS($wpNs, 'wp:docPr');
    $docProperties->setAttribute('id', (string) $drawingId);
    $docProperties->setAttribute('name', 'Institution logo ' . $drawingId);
    $inline->appendChild($docProperties);
    $frameProperties = $document->createElementNS($wpNs, 'wp:cNvGraphicFramePr');
    $locks = $document->createElementNS($aNs, 'a:graphicFrameLocks');
    $locks->setAttribute('noChangeAspect', '1');
    $frameProperties->appendChild($locks);
    $inline->appendChild($frameProperties);

    $graphic = $document->createElementNS($aNs, 'a:graphic');
    $graphicData = $document->createElementNS($aNs, 'a:graphicData');
    $graphicData->setAttribute('uri', $picNs);
    $picture = $document->createElementNS($picNs, 'pic:pic');
    $nonVisual = $document->createElementNS($picNs, 'pic:nvPicPr');
    $properties = $document->createElementNS($picNs, 'pic:cNvPr');
    $properties->setAttribute('id', (string) $drawingId);
    $properties->setAttribute('name', institution_docx_logo_filename($extension));
    $nonVisual->appendChild($properties);
    $nonVisual->appendChild($document->createElementNS($picNs, 'pic:cNvPicPr'));
    $picture->appendChild($nonVisual);
    $fill = $document->createElementNS($picNs, 'pic:blipFill');
    $blip = $document->createElementNS($aNs, 'a:blip');
    $blip->setAttributeNS($relNs, 'r:embed', $relationshipId);
    $fill->appendChild($blip);
    $stretch = $document->createElementNS($aNs, 'a:stretch');
    $stretch->appendChild($document->createElementNS($aNs, 'a:fillRect'));
    $fill->appendChild($stretch);
    $picture->appendChild($fill);
    $shapeProperties = $document->createElementNS($picNs, 'pic:spPr');
    $transform = $document->createElementNS($aNs, 'a:xfrm');
    $offset = $document->createElementNS($aNs, 'a:off');
    $offset->setAttribute('x', '0');
    $offset->setAttribute('y', '0');
    $transform->appendChild($offset);
    $shapeExtent = $document->createElementNS($aNs, 'a:ext');
    $shapeExtent->setAttribute('cx', (string) $width);
    $shapeExtent->setAttribute('cy', (string) $height);
    $transform->appendChild($shapeExtent);
    $shapeProperties->appendChild($transform);
    $geometry = $document->createElementNS($aNs, 'a:prstGeom');
    $geometry->setAttribute('prst', 'rect');
    $geometry->appendChild($document->createElementNS($aNs, 'a:avLst'));
    $shapeProperties->appendChild($geometry);
    $picture->appendChild($shapeProperties);
    $graphicData->appendChild($picture);
    $graphic->appendChild($graphicData);
    $inline->appendChild($graphic);
    $drawing->appendChild($inline);
    $run->appendChild($drawing);
    return $run;
}

function institution_person_name(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '' || mb_strlen($value) > 120 || preg_match('/[\x00-\x1F]/u', $value)) {
        throw new InvalidArgumentException('Saisissez un nom de 1 à 120 caractères.');
    }
    return $value;
}

function institution_arabic_name(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '' || mb_strlen($value) > 120 || preg_match('/[\x00-\x1F]/u', $value)) {
        throw new InvalidArgumentException('Saisissez le nom en arabe (1 à 120 caractères).');
    }
    if (!preg_match('/\p{Arabic}/u', $value)) {
        throw new InvalidArgumentException('Le nom en arabe doit contenir des lettres arabes.');
    }
    return $value;
}

function institution_save_university_name(string $value): void
{
    $value = trim($value);
    $value = preg_replace("/[ \t]+/u", ' ', $value) ?? $value;
    $lines = preg_split('/\R/u', $value) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    if ($lines === [] || count($lines) > 3) {
        throw new InvalidArgumentException('Saisissez le nom de l’université sur une à trois lignes.');
    }
    $stored = implode("\n", $lines);
    if (mb_strlen($stored) > 180) {
        throw new InvalidArgumentException('Le nom de l’université est trop long.');
    }
    institution_setting_put('university_name', $stored);
}

function institution_save_establishment_names(string $nameFr, string $nameAr): void
{
    $nameFr = trim(preg_replace('/\s+/u', ' ', $nameFr) ?? $nameFr);
    $nameAr = trim(preg_replace('/\s+/u', ' ', $nameAr) ?? $nameAr);
    if ($nameFr === '' || mb_strlen($nameFr) > 180 || preg_match('/[\x00-\x1F]/u', $nameFr)) {
        throw new InvalidArgumentException('Saisissez le nom français de l’établissement (1 à 180 caractères).');
    }
    if ($nameAr === '' || mb_strlen($nameAr) > 180 || preg_match('/[\x00-\x1F]/u', $nameAr)) {
        throw new InvalidArgumentException('Saisissez le nom arabe de l’établissement (1 à 180 caractères).');
    }
    if (!preg_match('/\p{Arabic}/u', $nameAr)) {
        throw new InvalidArgumentException('Le nom arabe doit contenir des lettres arabes.');
    }

    institution_ensure();
    $pdo = db();
    $statement = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $pdo->beginTransaction();
    try {
        foreach ([
            'establishment_fr' => $nameFr,
            'establishment_ar' => $nameAr,
            'university_name' => $nameFr,
        ] as $key => $value) {
            $statement->execute([$key, $value]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    institution_settings(true);
}

function institution_save_logo(array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Le fichier du logo n’a pas pu être reçu.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > 2_000_000) {
        throw new InvalidArgumentException('Le logo doit peser moins de 2 Mo.');
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
        throw new InvalidArgumentException('Le logo doit être une image PNG, JPG, GIF ou WebP.');
    }
    $content = file_get_contents($tmp);
    if ($content === false || $content === '') {
        throw new InvalidArgumentException('Le logo est vide.');
    }
    institution_ensure();
    $statement = db()->prepare(
        'INSERT INTO institution_logo (asset_key, mime, content) VALUES ("logo", ?, ?)
         ON DUPLICATE KEY UPDATE mime = VALUES(mime), content = VALUES(content)'
    );
    $statement->bindValue(1, $types[$type]);
    $statement->bindValue(2, $content, PDO::PARAM_LOB);
    $statement->execute();
    institution_setting_put('university_logo_custom', '1');
}

function institution_reset_logo(): void
{
    institution_ensure();
    db()->exec("DELETE FROM institution_logo WHERE asset_key = 'logo'");
    institution_setting_put('university_logo_custom', '0');
}

function institution_builtin_note(array $mark): ?float
{
    if ($mark['note_rattrapage'] !== null && $mark['note_rattrapage'] !== '') {
        return round((float) $mark['note_rattrapage'], 2);
    }
    if ($mark['note_cours'] === null || $mark['note_cours'] === '' || $mark['note_td'] === null || $mark['note_td'] === '') {
        return ($mark['note'] === null || $mark['note'] === '') ? null : round((float) $mark['note'], 2);
    }
    $cc = (float) $mark['note_cours'];
    $exam = (float) $mark['note_td'];
    if ((float) ($mark['volume_tp'] ?? 0) > 0 && $mark['note_tp'] !== null && $mark['note_tp'] !== '') {
        return round($cc * 0.25 + $exam * 0.5 + (float) $mark['note_tp'] * 0.25, 2);
    }
    return round($cc * 0.3 + $exam * 0.7, 2);
}

function institution_mark_variables(array $mark): array
{
    $present = static function (mixed $value): bool {
        return $value !== null && $value !== '';
    };
    $number = static function (mixed $value) use ($present): float {
        return $present($value) ? (float) $value : 0.0;
    };
    $hasTp = (float) ($mark['volume_tp'] ?? 0) > 0 && $present($mark['note_tp'] ?? null);
    return [
        'cc' => $number($mark['note_cours'] ?? null),
        'examen' => $number($mark['note_td'] ?? null),
        'tp' => $number($mark['note_tp'] ?? null),
        'rattrapage' => $number($mark['note_rattrapage'] ?? null),
        'note' => $number($mark['note'] ?? null),
        'coeff' => $number($mark['coeff'] ?? 0),
        'credit' => $number($mark['credit'] ?? 0),
        'volume_tp' => $number($mark['volume_tp'] ?? 0),
        'has_cc' => $present($mark['note_cours'] ?? null) ? 1.0 : 0.0,
        'has_examen' => $present($mark['note_td'] ?? null) ? 1.0 : 0.0,
        'has_tp' => $hasTp ? 1.0 : 0.0,
        'has_rattrapage' => $present($mark['note_rattrapage'] ?? null) ? 1.0 : 0.0,
        'has_note' => $present($mark['note'] ?? null) ? 1.0 : 0.0,
    ];
}

function institution_active_formula(string $key, string $fallback): string
{
    $formula = trim(institution_setting($key));
    return $formula === '' ? $fallback : $formula;
}

function institution_subject_average(array $mark): ?float
{
    $formula = trim(institution_setting('formula_moyenne'));
    if ($formula === '' || $formula === FORMULA_MOYENNE_DEFAULT) {
        return institution_builtin_note($mark);
    }
    try {
        return round(formula_evaluate($formula, institution_mark_variables($mark)), 2);
    } catch (Throwable $exception) {
        return institution_builtin_note($mark);
    }
}

function institution_general_average(float $weighted, float $coefficient, float $credits, int $count, float $sum): ?float
{
    if ($count < 1) {
        return null;
    }
    $formula = institution_active_formula('formula_generale', FORMULA_GENERALE_DEFAULT);
    try {
        $value = formula_evaluate($formula, [
            'somme_ponderee' => $weighted,
            'somme_coeff' => $coefficient,
            'somme_credits' => $credits,
            'somme_moyennes' => $sum,
            'nombre' => (float) $count,
        ]);
    } catch (Throwable $exception) {
        return $coefficient > 0 ? round($weighted / $coefficient, 2) : null;
    }
    return round($value, 2);
}

function institution_acquired_credit(float $average, float $credit): float
{
    $formula = institution_active_formula('formula_credit', FORMULA_CREDIT_DEFAULT);
    try {
        return round(formula_evaluate($formula, [
            'moyenne' => $average,
            'credit' => $credit,
            'coeff' => 0.0,
        ]), 2);
    } catch (Throwable $exception) {
        return $average >= 10 ? round($credit, 2) : 0.0;
    }
}

function formula_evaluate(string $formula, array $variables): float
{
    $formula = trim($formula);
    if ($formula === '' || strlen($formula) > 400 || !preg_match('/^[0-9A-Za-z_+\-*\/()<>=!?:.&|\s]+$/', $formula)) {
        throw new InvalidArgumentException('La formule contient un caractère non autorisé.');
    }
    $parser = new FormulaParser($formula, $variables);
    $value = $parser->parse();
    if (!is_finite($value)) {
        throw new InvalidArgumentException('La formule ne produit pas un nombre.');
    }
    return $value;
}

final class FormulaParser
{
    private int $index = 0;
    private int $length;
    private int $depth = 0;

    public function __construct(private string $source, private array $variables)
    {
        $this->length = strlen($source);
    }

    public function parse(): float
    {
        $value = $this->ternary();
        $this->skip();
        if ($this->index < $this->length) {
            throw new InvalidArgumentException('La fin de la formule est invalide.');
        }
        return $value;
    }

    private function ternary(): float
    {
        $condition = $this->logicOr();
        $this->skip();
        if (!$this->eat('?')) {
            return $condition;
        }
        $yes = $this->ternary();
        $this->skip();
        if (!$this->eat(':')) {
            throw new InvalidArgumentException('Il manque « : » dans la condition.');
        }
        $no = $this->ternary();
        return $condition != 0.0 ? $yes : $no;
    }

    private function logicOr(): float
    {
        $value = $this->logicAnd();
        while ($this->eat('||')) {
            $right = $this->logicAnd();
            $value = ($value != 0.0 || $right != 0.0) ? 1.0 : 0.0;
        }
        return $value;
    }

    private function logicAnd(): float
    {
        $value = $this->comparison();
        while ($this->eat('&&')) {
            $right = $this->comparison();
            $value = ($value != 0.0 && $right != 0.0) ? 1.0 : 0.0;
        }
        return $value;
    }

    private function comparison(): float
    {
        $value = $this->addition();
        $this->skip();
        foreach (['>=', '<=', '==', '!=', '>', '<'] as $operator) {
            if ($this->eat($operator)) {
                $right = $this->addition();
                $ok = match ($operator) {
                    '>=' => $value >= $right,
                    '<=' => $value <= $right,
                    '==' => $value == $right,
                    '!=' => $value != $right,
                    '>' => $value > $right,
                    default => $value < $right,
                };
                return $ok ? 1.0 : 0.0;
            }
        }
        return $value;
    }

    private function addition(): float
    {
        $value = $this->product();
        while (true) {
            $this->skip();
            if ($this->eat('+')) {
                $value += $this->product();
            } elseif ($this->eat('-')) {
                $value -= $this->product();
            } else {
                return $value;
            }
        }
    }

    private function product(): float
    {
        $value = $this->unary();
        while (true) {
            $this->skip();
            if ($this->eat('*')) {
                $value *= $this->unary();
            } elseif ($this->eat('/')) {
                $right = $this->unary();
                if ($right == 0.0) {
                    throw new InvalidArgumentException('Division par zéro dans la formule.');
                }
                $value /= $right;
            } else {
                return $value;
            }
        }
    }

    private function unary(): float
    {
        $this->skip();
        if ($this->eat('+')) {
            return $this->unary();
        }
        if ($this->eat('-')) {
            return -$this->unary();
        }
        if ($this->eat('!')) {
            return $this->unary() == 0.0 ? 1.0 : 0.0;
        }
        return $this->primary();
    }

    private function primary(): float
    {
        $this->enter();
        $this->skip();
        if ($this->eat('(')) {
            $value = $this->ternary();
            $this->skip();
            if (!$this->eat(')')) {
                throw new InvalidArgumentException('Parenthèse fermante manquante.');
            }
            $this->leave();
            return $value;
        }
        if ($this->peekNumber()) {
            $value = $this->number();
            $this->leave();
            return $value;
        }
        $name = $this->identifier();
        $this->skip();
        if ($this->eat('(')) {
            $args = [];
            $this->skip();
            if (!$this->eat(')')) {
                do {
                    $args[] = $this->ternary();
                    $this->skip();
                } while ($this->eat(','));
                if (!$this->eat(')')) {
                    throw new InvalidArgumentException('Parenthèse de fonction manquante.');
                }
            }
            $value = $this->call($name, $args);
            $this->leave();
            return $value;
        }
        if (!array_key_exists($name, $this->variables)) {
            throw new InvalidArgumentException('Variable inconnue : ' . $name);
        }
        $this->leave();
        return (float) $this->variables[$name];
    }

    private function call(string $name, array $args): float
    {
        return match ($name) {
            'round' => round($this->arg($args, 0), isset($args[1]) ? (int) $args[1] : 0),
            'min' => min($this->arg($args, 0), $this->arg($args, 1)),
            'max' => max($this->arg($args, 0), $this->arg($args, 1)),
            'if' => $this->arg($args, 0) != 0.0 ? $this->arg($args, 1) : $this->arg($args, 2),
            default => throw new InvalidArgumentException('Fonction non autorisée : ' . $name),
        };
    }

    private function arg(array $args, int $index): float
    {
        if (!isset($args[$index])) {
            throw new InvalidArgumentException('Argument de fonction manquant.');
        }
        return (float) $args[$index];
    }

    private function identifier(): string
    {
        $this->skip();
        if ($this->index >= $this->length || !preg_match('/[A-Za-z_]/', $this->source[$this->index])) {
            throw new InvalidArgumentException('Formule incomplète.');
        }
        $start = $this->index;
        while ($this->index < $this->length && preg_match('/[A-Za-z0-9_]/', $this->source[$this->index])) {
            $this->index++;
        }
        return substr($this->source, $start, $this->index - $start);
    }

    private function number(): float
    {
        $this->skip();
        $start = $this->index;
        while ($this->index < $this->length && preg_match('/[0-9.]/', $this->source[$this->index])) {
            $this->index++;
        }
        $raw = substr($this->source, $start, $this->index - $start);
        if (!is_numeric($raw)) {
            throw new InvalidArgumentException('Nombre invalide.');
        }
        return (float) $raw;
    }

    private function peekNumber(): bool
    {
        $this->skip();
        return $this->index < $this->length && preg_match('/[0-9.]/', $this->source[$this->index]) === 1;
    }

    private function eat(string $token): bool
    {
        $this->skip();
        if (substr($this->source, $this->index, strlen($token)) !== $token) {
            return false;
        }
        $this->index += strlen($token);
        return true;
    }

    private function skip(): void
    {
        while ($this->index < $this->length && ctype_space($this->source[$this->index])) {
            $this->index++;
        }
    }

    private function enter(): void
    {
        $this->depth++;
        if ($this->depth > 80) {
            throw new InvalidArgumentException('La formule est trop imbriquée.');
        }
    }

    private function leave(): void
    {
        $this->depth--;
    }
}

function academic_unities(): array
{
    institution_ensure();
    $rows = db()->query(
        'SELECT code_ue, description_ue, semestre, coefficient_ue FROM (
            SELECT code_ue, description_ue, semestre, coefficient_ue, 1 AS source_order
            FROM unite
            UNION ALL
            SELECT code_ue, description_ue, semestre, coefficient_ue, 2 AS source_order
            FROM matiere
            WHERE code_ue <> ""
         ) unities
         ORDER BY source_order, code_ue'
    )->fetchAll();
    $unique = [];
    foreach ($rows as $row) {
        $code = (string) $row['code_ue'];
        if ($code === '' || isset($unique[$code])) {
            continue;
        }
        $unique[$code] = $row;
    }
    uasort($unique, static fn(array $a, array $b): int => strcmp((string) $a['code_ue'], (string) $b['code_ue']));
    return array_values($unique);
}

function academic_find_unity(string $code): ?array
{
    foreach (academic_unities() as $unity) {
        if (strcasecmp((string) $unity['code_ue'], $code) === 0) {
            return $unity;
        }
    }
    return null;
}

function academic_add_unity(string $code, string $name, string $semester, float $coefficient): void
{
    institution_ensure();
    $code = strtoupper(trim($code));
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if (!preg_match('/^[A-Z0-9._-]{2,30}$/', $code)) {
        throw new InvalidArgumentException('Le code d’unité doit contenir 2 à 30 lettres, chiffres, points ou tirets.');
    }
    if ($name === '' || mb_strlen($name) > 255) {
        throw new InvalidArgumentException('Saisissez le nom de l’unité.');
    }
    if (!academic_period_valid($semester)) {
        throw new InvalidArgumentException('Choisissez un semestre.');
    }
    if ($coefficient <= 0 || $coefficient > 99) {
        throw new InvalidArgumentException('Le coefficient d’unité doit être supérieur à 0.');
    }
    if (academic_find_unity($code) !== null) {
        throw new InvalidArgumentException('Cette unité existe déjà.');
    }
    $duplicateName = db()->prepare(
        'SELECT 1 FROM (
            SELECT description_ue FROM unite
            UNION ALL
            SELECT description_ue FROM matiere WHERE code_ue <> ""
         ) names WHERE LOWER(description_ue) = LOWER(?) LIMIT 1'
    );
    $duplicateName->execute([$name]);
    if ($duplicateName->fetchColumn()) {
        throw new InvalidArgumentException('Une unité porte déjà ce nom.');
    }
    db()->prepare(
        'INSERT INTO unite (code_ue, description_ue, semestre, coefficient_ue) VALUES (?, ?, ?, ?)'
    )->execute([$code, $name, $semester, $coefficient]);
}

function academic_add_subject(array $input): void
{
    institution_ensure();
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')) ?? '');
    $coefficient = (float) str_replace(',', '.', (string) ($input['coefficient'] ?? ''));
    $credit = (float) str_replace(',', '.', (string) ($input['credit'] ?? '0'));
    $regime = (string) ($input['regime'] ?? 'Mixte');
    $withoutUnity = !empty($input['sans_unite']);
    $unityCode = strtoupper(trim((string) ($input['code_ue'] ?? '')));
    $semester = (string) ($input['semestre'] ?? '');

    if (!preg_match('/^[A-Z0-9._-]{2,30}$/', $code)) {
        throw new InvalidArgumentException('Le code de la matière doit contenir 2 à 30 lettres, chiffres, points ou tirets.');
    }
    if ($name === '' || mb_strlen($name) > 255) {
        throw new InvalidArgumentException('Saisissez le nom de la matière.');
    }
    if ($coefficient <= 0 || $coefficient > 99) {
        throw new InvalidArgumentException('Le coefficient doit être supérieur à 0.');
    }
    if ($credit < 0 || $credit > 99) {
        throw new InvalidArgumentException('Le crédit est invalide.');
    }
    if (!in_array($regime, ['Mixte', 'C.C'], true)) {
        throw new InvalidArgumentException('Choisissez le régime Mixte ou C.C.');
    }
    if ($withoutUnity === ($unityCode !== '')) {
        throw new InvalidArgumentException('Choisissez une unité existante, ou cochez que cette matière n’a pas une unité.');
    }

    $duplicate = db()->prepare(
        'SELECT 1 FROM matiere WHERE LOWER(code_ecue) = LOWER(?) OR LOWER(description_ecue) = LOWER(?) LIMIT 1'
    );
    $duplicate->execute([$code, $name]);
    if ($duplicate->fetchColumn()) {
        throw new InvalidArgumentException('Cette matière existe déjà (même code ou même nom).');
    }

    if ($withoutUnity) {
        if (!academic_period_valid($semester)) {
            throw new InvalidArgumentException('Choisissez le semestre de la matière.');
        }
        $unity = [
            'code_ue' => '',
            'description_ue' => '',
            'semestre' => $semester,
            'coefficient_ue' => 0,
        ];
    } else {
        $unity = academic_find_unity($unityCode);
        if ($unity === null) {
            throw new InvalidArgumentException('L’unité choisie n’existe pas.');
        }
    }

    db()->prepare(
        'INSERT INTO matiere (
            code_ue, description_ue, code_ecue, description_ecue, semestre, coeff, credit, regime,
            volume_cours, volume_td, volume_tp, volume_autres, coefficient_ecue, coefficient_ue
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?)'
    )->execute([
        $unity['code_ue'],
        $unity['description_ue'],
        $code,
        $name,
        $unity['semestre'],
        $coefficient,
        $credit,
        $regime,
        $coefficient,
        $unity['coefficient_ue'],
    ]);
}

<?php
declare(strict_types=1);

/** @return array<string, string> */
function student_sheet_columns(): array
{
    return [
        'num_inscription' => 'Inscription',
        'nom' => 'Nom',
        'prenom' => 'Prénom',
        'nom_ar' => 'Nom arabe',
        'prenom_ar' => 'Prénom arabe',
        'date_naissance' => 'Date de naissance',
        'lieu_naissance' => 'Lieu de naissance',
        'lieu_naissance_ar' => 'Lieu de naissance arabe',
        'sexe' => 'Sexe',
        'nationalite' => 'Nationalité',
        'etat_civil' => 'État civil',
        'cin_passeport' => 'CIN ou passeport',
        'num_tel' => 'Téléphone',
        'adresse' => 'Adresse',
        'email' => 'E-mail',
        'date_inscrit' => 'Date d\'inscription',
        'specialite' => 'Spécialité',
        'niveau' => 'Niveau',
        'annee_bac' => 'Année du bac',
        'specialite_bac' => 'Spécialité du bac',
    ];
}

function student_sheet_header_key(string $header): string
{
    $value = mb_strtolower(trim($header));
    $value = strtr($value, [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'à' => 'a', 'â' => 'a', 'ù' => 'u', 'û' => 'u',
        'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'ç' => 'c',
    ]);
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    $map = [
        'inscription' => 'num_inscription', 'numinscription' => 'num_inscription', 'numerodinscription' => 'num_inscription',
        'nom' => 'nom', 'nomdefamille' => 'nom',
        'prenom' => 'prenom',
        'nomarabe' => 'nom_ar', 'nomar' => 'nom_ar',
        'prenomarabe' => 'prenom_ar', 'prenomar' => 'prenom_ar',
        'datenaissance' => 'date_naissance', 'datedenaissance' => 'date_naissance', 'naissance' => 'date_naissance',
        'lieunaissance' => 'lieu_naissance', 'lieudenaissance' => 'lieu_naissance',
        'lieunaissancearabe' => 'lieu_naissance_ar', 'lieudenaissancearabe' => 'lieu_naissance_ar',
        'sexe' => 'sexe', 'genre' => 'sexe',
        'nationalite' => 'nationalite',
        'etatcivil' => 'etat_civil',
        'cinoupasseport' => 'cin_passeport', 'cinpasseport' => 'cin_passeport', 'cin' => 'cin_passeport', 'passeport' => 'cin_passeport', 'identifiant' => 'cin_passeport',
        'telephone' => 'num_tel', 'tel' => 'num_tel', 'numtel' => 'num_tel',
        'adresse' => 'adresse',
        'email' => 'email', 'courriel' => 'email', 'mail' => 'email',
        'dateinscription' => 'date_inscrit', 'datedinscription' => 'date_inscrit', 'dateinscrit' => 'date_inscrit',
        'specialite' => 'specialite', 'filiere' => 'specialite',
        'niveau' => 'niveau',
        'anneebac' => 'annee_bac', 'anneedubac' => 'annee_bac',
        'specialitebac' => 'specialite_bac', 'specialitedubac' => 'specialite_bac',
    ];
    return $map[$value] ?? '';
}

function student_sheet_xml_local(string $xml): SimpleXMLElement
{
    $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml) ?? $xml;
    $parsed = simplexml_load_string($xml);
    if ($parsed === false) {
        throw new InvalidArgumentException('Le fichier Excel est illisible.');
    }
    return $parsed;
}

function student_sheet_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function student_sheet_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

/** @param list<list<string>> $rows */
function student_sheet_xlsx(array $rows): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Le fichier Excel ne peut pas être préparé sur ce serveur.');
    }
    $cells = '';
    foreach ($rows as $rowIndex => $row) {
        $line = $rowIndex + 1;
        $cells .= '<row r="' . $line . '">';
        foreach (array_values($row) as $columnIndex => $value) {
            $ref = student_sheet_column_name($columnIndex + 1) . $line;
            $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . student_sheet_xml((string) $value) . '</t></is></c>';
        }
        $cells .= '</row>';
    }
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . $cells . '</sheetData></worksheet>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Etudiants" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    $path = tempnam(sys_get_temp_dir(), 'students');
    if ($path === false) {
        throw new RuntimeException('Le fichier Excel ne peut pas être préparé.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Le fichier Excel ne peut pas être préparé.');
    }
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $binary = (string) file_get_contents($path);
    @unlink($path);
    return $binary;
}

function student_sheet_send(string $filename, string $binary): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) strlen($binary));
    echo $binary;
}

/** @return list<list<string>> */
function student_sheet_export_rows(): array
{
    academic_policy_ensure();
    $columns = student_sheet_columns();
    $rows = [array_values($columns)];
    $query = db()->query(
        'SELECT e.num_inscription, e.nom, e.prenom, e.nom_ar, e.prenom_ar, e.date_naissance, e.lieu_naissance, e.lieu_naissance_ar,
                e.sexe, e.nationalite, e.etat_civil, e.cin_passeport, e.num_tel, e.adresse, e.email, e.date_inscrit,
                e.niveau, e.annee_bac, e.specialite_bac, s.nom_spec
         FROM etudiant e
         LEFT JOIN specialite s ON s.id_spec = e.id_spec
         ORDER BY e.nom, e.prenom'
    );
    foreach ($query as $student) {
        $line = [];
        foreach (array_keys($columns) as $key) {
            if ($key === 'specialite') {
                $line[] = (string) ($student['nom_spec'] ?? '');
            } else {
                $line[] = (string) ($student[$key] ?? '');
            }
        }
        $rows[] = $line;
    }
    return $rows;
}

/** @return list<array<string, string>> */
function student_sheet_read(string $path, string $extension): array
{
    $extension = strtolower($extension);
    if ($extension === 'csv' || $extension === 'txt') {
        return student_sheet_read_csv($path);
    }
    if ($extension === 'xlsx') {
        return student_sheet_read_xlsx($path);
    }
    throw new InvalidArgumentException('Utilisez un fichier .xlsx ou .csv.');
}

/** @return list<array<string, string>> */
function student_sheet_read_csv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new InvalidArgumentException('Le fichier est illisible.');
    }
    $first = (string) fgets($handle);
    if (str_starts_with($first, "\xEF\xBB\xBF")) {
        $first = substr($first, 3);
    }
    $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    if (substr_count($first, "\t") > substr_count($first, $delimiter)) {
        $delimiter = "\t";
    }
    rewind($handle);
    $table = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $table[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
    }
    fclose($handle);
    return student_sheet_associate($table);
}

/** @return list<array<string, string>> */
function student_sheet_read_xlsx(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('La lecture Excel n’est pas disponible sur ce serveur.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new InvalidArgumentException('Le fichier Excel est illisible.');
    }
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml) && $sharedXml !== '') {
        $xml = student_sheet_xml_local($sharedXml);
        foreach ($xml->si as $item) {
            $text = '';
            if (isset($item->t)) {
                $text = (string) $item->t;
            }
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $shared[] = $text;
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!is_string($sheetXml) || $sheetXml === '') {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_starts_with($name, 'xl/worksheets/sheet')) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();
    if (!is_string($sheetXml) || $sheetXml === '') {
        throw new InvalidArgumentException('La première feuille du fichier est vide.');
    }
    $sheet = student_sheet_xml_local($sheetXml);
    $table = [];
    foreach ($sheet->sheetData->row as $row) {
        $line = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $column = preg_replace('/\d+/', '', $ref) ?? 'A';
            $index = 0;
            foreach (str_split($column) as $letter) {
                $index = ($index * 26) + (ord($letter) - 64);
            }
            $type = (string) $cell['t'];
            $value = '';
            if ($type === 's') {
                $value = $shared[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) $cell->is->t;
            } else {
                $value = (string) $cell->v;
            }
            $line[$index - 1] = trim($value);
        }
        if ($line === []) {
            continue;
        }
        $max = max(array_keys($line));
        $filled = array_fill(0, $max + 1, '');
        foreach ($line as $position => $value) {
            $filled[$position] = $value;
        }
        $table[] = $filled;
    }
    return student_sheet_associate($table);
}

/** @param list<list<string>> $table
 *  @return list<array<string, string>>
 */
function student_sheet_associate(array $table): array
{
    if ($table === []) {
        throw new InvalidArgumentException('Le fichier ne contient aucune ligne.');
    }
    $headers = array_shift($table);
    $keys = [];
    foreach ($headers as $index => $header) {
        $key = student_sheet_header_key((string) $header);
        if ($key !== '') {
            $keys[$index] = $key;
        }
    }
    if (!in_array('nom', $keys, true) && !in_array('cin_passeport', $keys, true)) {
        throw new InvalidArgumentException('La première ligne doit contenir les titres de colonnes, par exemple Nom, Prénom, CIN ou passeport.');
    }
    $rows = [];
    foreach ($table as $line) {
        $assoc = [];
        $empty = true;
        foreach ($keys as $index => $key) {
            $value = trim((string) ($line[$index] ?? ''));
            $assoc[$key] = $value;
            if ($value !== '') {
                $empty = false;
            }
        }
        if (!$empty) {
            $rows[] = $assoc;
        }
    }
    if (count($rows) > 5000) {
        throw new InvalidArgumentException('Le fichier dépasse 5000 étudiants. Divisez-le.');
    }
    return $rows;
}

function student_sheet_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $match) === 1) {
        if (!checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
    }
    if (preg_match('/^\d{5}$/', $value) === 1) {
        $serial = (int) $value;
        if ($serial < 20000 || $serial > 80000) {
            return null;
        }
        $timestamp = ($serial - 25569) * 86400;
        return gmdate('Y-m-d', $timestamp);
    }
    return null;
}

function student_sheet_sexe(string $value): ?string
{
    $value = mb_strtolower(trim($value));
    return match ($value) {
        'femme', 'f', 'féminin', 'feminin' => 'Femme',
        'homme', 'h', 'm', 'masculin' => 'Homme',
        default => null,
    };
}

function student_sheet_bac(string $value): ?string
{
    $value = mb_strtolower(trim($value));
    $value = strtr($value, ['é' => 'e', 'è' => 'e']);
    return match ($value) {
        'science', 'sciences', 'sciences experimentales' => 'Science',
        'math', 'maths', 'mathematiques' => 'Math',
        'lettre', 'lettres' => 'Lettre',
        'sport', 'sportif' => 'Sport',
        default => null,
    };
}

/**
 * Students absent from the file are left untouched.
 * An empty cell never erases a value already stored.
 * A new student missing a required value is not created.
 *
 * @param list<array<string, string>> $rows
 * @return array{created:int, updated:int, skipped:list<array{line:int, reason:string}>, warnings:list<string>}
 */
function student_sheet_import(array $rows): array
{
    academic_policy_ensure();
    $result = ['created' => 0, 'updated' => 0, 'skipped' => [], 'warnings' => []];
    $specialties = db()->query('SELECT id_spec, nom_spec FROM specialite WHERE active = 1')->fetchAll();
    $byName = [];
    foreach ($specialties as $specialty) {
        $byName[mb_strtolower(trim((string) $specialty['nom_spec']))] = (int) $specialty['id_spec'];
    }
    $levels = [];
    foreach (study_levels() as $level) {
        $levels[mb_strtolower((string) $level['code'])] = (string) $level['code'];
        $levels[mb_strtolower((string) $level['label'])] = (string) $level['code'];
    }
    $seenIdentity = [];
    $catalog = inscription_field_catalog();
    $requiredNew = [];
    foreach (inscription_required_field_keys() as $field) {
        if ($field === 'id_spec') {
            $requiredNew['specialite'] = $catalog['id_spec']['label'] ?? 'Spécialité';
            continue;
        }
        $requiredNew[$field] = $catalog[$field]['label'] ?? $field;
    }
    // Import always needs a specialty column mapping target.
    if (!isset($requiredNew['specialite'])) {
        $requiredNew['specialite'] = 'Spécialité';
    }
    foreach ($rows as $offset => $row) {
        $line = $offset + 2;
        $present = array_keys($row);
        $identity = trim((string) ($row['cin_passeport'] ?? ''));
        $registration = trim((string) ($row['num_inscription'] ?? ''));
        $marker = $identity !== '' ? mb_strtolower($identity) : mb_strtolower($registration);
        if ($marker !== '' && isset($seenIdentity[$marker])) {
            $result['skipped'][] = ['line' => $line, 'reason' => 'Doublon dans le fichier.'];
            continue;
        }
        if ($marker !== '') {
            $seenIdentity[$marker] = true;
        }
        $existing = null;
        if ($identity !== '') {
            $query = db()->prepare('SELECT * FROM etudiant WHERE cin_passeport = ? LIMIT 1');
            $query->execute([$identity]);
            $existing = $query->fetch() ?: null;
        }
        if ($existing === null && $registration !== '') {
            $query = db()->prepare('SELECT * FROM etudiant WHERE num_inscription = ? LIMIT 1');
            $query->execute([$registration]);
            $existing = $query->fetch() ?: null;
        }
        if ($existing) {
            $changes = [];
            $textFields = ['nom', 'prenom', 'nom_ar', 'prenom_ar', 'lieu_naissance', 'lieu_naissance_ar', 'nationalite', 'etat_civil', 'num_tel', 'adresse', 'email'];
            foreach ($textFields as $field) {
                if (in_array($field, $present, true) && trim((string) ($row[$field] ?? '')) !== '') {
                    $changes[$field] = trim((string) $row[$field]);
                }
            }
            if (in_array('date_naissance', $present, true) && trim((string) ($row['date_naissance'] ?? '')) !== '') {
                $date = student_sheet_date((string) $row['date_naissance']);
                if ($date === null) {
                    $result['skipped'][] = ['line' => $line, 'reason' => 'Date de naissance illisible. La fiche existante n’a pas été modifiée.'];
                    continue;
                }
                $changes['date_naissance'] = $date;
            }
            if (in_array('date_inscrit', $present, true) && trim((string) ($row['date_inscrit'] ?? '')) !== '') {
                $date = student_sheet_date((string) $row['date_inscrit']);
                if ($date === null) {
                    $result['skipped'][] = ['line' => $line, 'reason' => 'Date d’inscription illisible. La fiche existante n’a pas été modifiée.'];
                    continue;
                }
                $changes['date_inscrit'] = $date;
            }
            if (in_array('sexe', $present, true) && trim((string) ($row['sexe'] ?? '')) !== '') {
                $sexe = student_sheet_sexe((string) $row['sexe']);
                if ($sexe === null) {
                    $result['skipped'][] = ['line' => $line, 'reason' => 'Sexe non reconnu. Indiquez Femme ou Homme.'];
                    continue;
                }
                $changes['sexe'] = $sexe;
            }
            if (in_array('specialite', $present, true) && trim((string) ($row['specialite'] ?? '')) !== '') {
                $specId = $byName[mb_strtolower(trim((string) $row['specialite']))] ?? 0;
                if ($specId === 0) {
                    $result['warnings'][] = 'Ligne ' . $line . ' : spécialité inconnue, la spécialité actuelle est conservée.';
                } else {
                    $changes['id_spec'] = $specId;
                }
            }
            if (in_array('niveau', $present, true) && trim((string) ($row['niveau'] ?? '')) !== '') {
                $level = $levels[mb_strtolower(trim((string) $row['niveau']))] ?? '';
                if ($level === '') {
                    $result['warnings'][] = 'Ligne ' . $line . ' : niveau inconnu, le niveau actuel est conservé.';
                } else {
                    $changes['niveau'] = $level;
                }
            }
            if (in_array('annee_bac', $present, true) && trim((string) ($row['annee_bac'] ?? '')) !== '') {
                $year = (int) $row['annee_bac'];
                if ($year < 1950 || $year > 2100) {
                    $result['warnings'][] = 'Ligne ' . $line . ' : année du bac ignorée.';
                } else {
                    $changes['annee_bac'] = $year;
                }
            }
            if (in_array('specialite_bac', $present, true) && trim((string) ($row['specialite_bac'] ?? '')) !== '') {
                $bac = student_sheet_bac((string) $row['specialite_bac']);
                if ($bac === null) {
                    $result['warnings'][] = 'Ligne ' . $line . ' : spécialité du bac non reconnue, la valeur actuelle est conservée.';
                } else {
                    $changes['specialite_bac'] = $bac;
                }
            }
            if ($changes === []) {
                $result['warnings'][] = 'Ligne ' . $line . ' : étudiant déjà présent, aucune nouvelle information à enregistrer.';
                continue;
            }
            $set = [];
            foreach (array_keys($changes) as $field) {
                $set[] = $field . ' = ?';
            }
            $changes['id_etudiant'] = (int) $existing['id_etudiant'];
            db()->prepare('UPDATE etudiant SET ' . implode(', ', $set) . ' WHERE id_etudiant = ?')->execute(array_values($changes));
            $result['updated']++;
            continue;
        }
        $missing = [];
        foreach ($requiredNew as $field => $label) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $result['skipped'][] = ['line' => $line, 'reason' => 'Étudiant nouveau incomplet : ' . implode(', ', $missing) . '.'];
            continue;
        }
        $birth = student_sheet_date((string) $row['date_naissance']);
        $enrolled = student_sheet_date((string) $row['date_inscrit']);
        $sexe = student_sheet_sexe((string) $row['sexe']);
        $bac = student_sheet_bac((string) $row['specialite_bac']);
        $specId = $byName[mb_strtolower(trim((string) $row['specialite']))] ?? 0;
        $level = $levels[mb_strtolower(trim((string) $row['niveau']))] ?? '';
        $bacYear = (int) $row['annee_bac'];
        $email = trim((string) $row['email']);
        $identityValue = trim((string) $row['cin_passeport']);
        $identityKind = identity_matches('cin', $identityValue) ? 'cin' : (identity_matches('passeport', $identityValue) ? 'passeport' : '');
        if ($birth === null || $enrolled === null || $sexe === null || $bac === null || $specId === 0 || $level === '' || $bacYear < 1950 || $bacYear > 2100 || $identityKind === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $result['skipped'][] = ['line' => $line, 'reason' => 'Une valeur obligatoire est illisible ou ne correspond à aucune spécialité ou à aucun niveau déjà enregistré.'];
            continue;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $number = trim((string) ($row['num_inscription'] ?? ''));
            if ($number === '') {
                $currentYear = date('Y');
                $lastNumber = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING(num_inscription, 4), '/', 1) AS UNSIGNED)), 0) FROM etudiant WHERE num_inscription LIKE 'INS%/$currentYear'")->fetchColumn();
                $number = 'INS' . str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT) . '/' . $currentYear;
            }
            $insert = [
                'nom' => trim((string) $row['nom']),
                'prenom' => trim((string) $row['prenom']),
                'nom_ar' => trim((string) ($row['nom_ar'] ?? '')),
                'prenom_ar' => trim((string) ($row['prenom_ar'] ?? '')),
                'date_naissance' => $birth,
                'lieu_naissance' => trim((string) ($row['lieu_naissance'] ?? '')),
                'lieu_naissance_ar' => trim((string) ($row['lieu_naissance_ar'] ?? '')),
                'sexe' => $sexe,
                'nationalite' => trim((string) ($row['nationalite'] ?? '')),
                'etat_civil' => trim((string) ($row['etat_civil'] ?? '')),
                'cin_passeport' => $identityValue,
                'num_tel' => trim((string) ($row['num_tel'] ?? '')),
                'adresse' => trim((string) ($row['adresse'] ?? '')),
                'email' => $email,
                'num_inscription' => $number,
                'date_inscrit' => $enrolled,
                'id_spec' => $specId,
                'niveau' => $level,
                'annee_bac' => $bacYear,
                'specialite_bac' => $bac,
            ];
            $pdo->prepare(
                'INSERT INTO etudiant (nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance, lieu_naissance_ar, sexe, nationalite, etat_civil, cin_passeport, num_tel, adresse, email, num_inscription, date_inscrit, id_spec, niveau, annee_bac, specialite_bac)
                 VALUES (:nom, :prenom, :nom_ar, :prenom_ar, :date_naissance, :lieu_naissance, :lieu_naissance_ar, :sexe, :nationalite, :etat_civil, :cin_passeport, :num_tel, :adresse, :email, :num_inscription, :date_inscrit, :id_spec, :niveau, :annee_bac, :specialite_bac)'
            )->execute($insert);
            $studentId = (int) $pdo->lastInsertId();
            $specName = '';
            foreach ($specialties as $specialty) {
                if ((int) $specialty['id_spec'] === $specId) {
                    $specName = (string) $specialty['nom_spec'];
                }
            }
            try {
                if (stripos($specName, 'commun') !== false || stripos($specName, 'tronc') !== false) {
                    assign_tronc_commun_groups($pdo, $studentId);
                } else {
                    assign_student_to_academic_groups($pdo, $studentId, $level);
                }
            } catch (Throwable $exception) {
                $result['warnings'][] = 'Ligne ' . $line . ' : étudiant enregistré, groupes non affectés.';
            }
            $pdo->commit();
            $result['created']++;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('student import: ' . $exception->getMessage());
            $result['skipped'][] = ['line' => $line, 'reason' => 'Enregistrement refusé : CIN, e-mail ou numéro d’inscription déjà utilisé.'];
        }
    }
    return $result;
}

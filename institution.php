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
    if ($ready) {
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

function institution_logo_custom(): bool
{
    return institution_setting('university_logo_custom') === '1';
}

function institution_logo_src(): string
{
    if (!institution_logo_custom()) {
        return 'images/no_bg_logo.png?v=2';
    }
    try {
        $stamp = (int) db()->query("SELECT UNIX_TIMESTAMP(updated_at) FROM institution_logo WHERE asset_key = 'logo'")->fetchColumn();
    } catch (Throwable $exception) {
        $stamp = time();
    }
    return 'brand_logo.php?v=' . max(1, $stamp);
}

function institution_logo_file(): array
{
    $fallback = ['path' => __DIR__ . '/images/no_bg_logo.png', 'temporary' => false];
    if (!institution_logo_custom()) {
        return $fallback;
    }
    try {
        $row = db()->query("SELECT mime, content FROM institution_logo WHERE asset_key = 'logo'")->fetch();
    } catch (Throwable $exception) {
        return $fallback;
    }
    if (!$row || $row['content'] === null || $row['content'] === '') {
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
    file_put_contents($file, $row['content']);
    @unlink($path);
    return ['path' => $file, 'temporary' => true];
}

function institution_brand(): array
{
    return [
        'name' => trim(institution_setting('university_name')) ?: INSTITUTION_NAME_DEFAULT,
        'name_inline' => institution_name_inline(),
        'lines' => institution_name_lines(),
        'sec_generale' => trim(institution_setting('sec_generale')) ?: INSTITUTION_SECRETARY_DEFAULT,
        'sec_generale_ar' => trim(institution_setting('sec_generale_ar')) ?: INSTITUTION_SECRETARY_AR_DEFAULT,
        'directeur' => trim(institution_setting('directeur')),
        'directeur_ar' => trim(institution_setting('directeur_ar')),
        'logo' => institution_logo_src(),
    ];
}

function institution_apply_signatories(object $processor): void
{
    $brand = institution_brand();
    $processor->setValue('sec_generale_ar', $brand['sec_generale_ar']);
    $processor->setValue('directeur_ar', $brand['directeur_ar']);
    $processor->setValue('sec_generale', $brand['sec_generale']);
    $processor->setValue('directeur', $brand['directeur']);
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

function institution_presence_signature_xml(string $xml): string
{
    $xml = str_replace(['سعيد علجان', 'Said ALJANE'], '${sec_generale_ar}', $xml);
    $xml = str_replace('>sec_generale<', '>sec_generale_ar<', $xml);
    $updated = preg_replace_callback(
        '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*sec_generale_ar(?:(?!<\/w:p>).)*<\/w:p>/s',
        static function (array $match): string {
            $paragraph = $match[0];
            if (!str_contains($paragraph, '<w:rtl')) {
                $paragraph = str_replace('<w:rPr>', '<w:rPr><w:rtl/>', $paragraph);
            }
            return $paragraph;
        },
        $xml
    );
    return is_string($updated) ? $updated : $xml;
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
    if (!in_array($semester, ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'], true)) {
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
        if (!in_array($semester, ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'], true)) {
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

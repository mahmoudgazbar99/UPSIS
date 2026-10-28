<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$mysqlHost = getenv('MYSQL_HOST') ?: 'mysql';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$pgHost = getenv('DB_HOST') ?: 'db';
$pgPort = getenv('DB_PORT') ?: '5432';
$name = getenv('DB_NAME') ?: 'ecole_sante_gabes';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS');
if ($user === '' || $pass === false || $pass === '') {
    fwrite(STDERR, "DB_USER and DB_PASS are required. Run this inside the web container.\n");
    exit(1);
}

$mysql = new PDO(
    'mysql:host=' . $mysqlHost . ';port=' . $mysqlPort . ';dbname=' . $name . ';charset=utf8mb4',
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pg = new PDO(
    'pgsql:host=' . $pgHost . ';port=' . $pgPort . ';dbname=' . $name,
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$mysql->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$pg->exec("SET client_encoding TO 'UTF8'");
$pg->exec("SET TIME ZONE 'UTC'");

function qident(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function mysql_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function literal(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function field(array $row, string $name): mixed
{
    foreach ([$name, strtoupper($name), strtolower($name)] as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }
    return null;
}

function pg_column_type(array $column): string
{
    $data = strtolower((string) $column['DATA_TYPE']);
    if ($data === 'enum' || $data === 'set') {
        return 'varchar(64)';
    }
    if (str_contains($data, 'blob') || $data === 'binary' || $data === 'varbinary') {
        return 'bytea';
    }
    if ($data === 'tinyint' || $data === 'smallint' || $data === 'mediumint') {
        return $data === 'mediumint' ? 'integer' : 'smallint';
    }
    if ($data === 'int' || $data === 'integer') {
        return 'integer';
    }
    if ($data === 'bigint') {
        return 'bigint';
    }
    if ($data === 'decimal' || $data === 'numeric') {
        return 'numeric(' . (int) $column['NUMERIC_PRECISION'] . ',' . (int) $column['NUMERIC_SCALE'] . ')';
    }
    if ($data === 'float' || $data === 'double') {
        return 'double precision';
    }
    if ($data === 'varchar') {
        return 'varchar(' . (int) $column['CHARACTER_MAXIMUM_LENGTH'] . ')';
    }
    if ($data === 'char') {
        return 'char(' . (int) $column['CHARACTER_MAXIMUM_LENGTH'] . ')';
    }
    if ($data === 'json') {
        return 'jsonb';
    }
    if (in_array($data, ['text', 'tinytext', 'mediumtext', 'longtext'], true)) {
        return 'text';
    }
    if ($data === 'date') {
        return 'date';
    }
    if ($data === 'time') {
        return 'time';
    }
    if ($data === 'datetime' || $data === 'timestamp') {
        return 'timestamp';
    }
    if ($data === 'year') {
        return 'integer';
    }
    throw new RuntimeException('Type MySQL non géré : ' . $column['COLUMN_TYPE'] . ' (' . $column['TABLE_NAME'] . '.' . $column['COLUMN_NAME'] . ')');
}

function pg_default(array $column): string
{
    if (str_contains(strtolower((string) $column['EXTRA']), 'auto_increment')) {
        return '';
    }
    if ($column['COLUMN_DEFAULT'] === null) {
        return '';
    }
    $default = (string) $column['COLUMN_DEFAULT'];
    if (preg_match('/^current_timestamp(?:\(\d*\))?$/i', $default)) {
        return ' DEFAULT CURRENT_TIMESTAMP';
    }
    if (is_numeric($default)) {
        return ' DEFAULT ' . $default;
    }
    return ' DEFAULT ' . literal($default);
}

try {
    $pg->exec('DROP SCHEMA IF EXISTS public CASCADE');
    $pg->exec('CREATE SCHEMA public');
    $pg->exec('GRANT ALL ON SCHEMA public TO ' . qident($user));
    $pg->exec('GRANT ALL ON SCHEMA public TO PUBLIC');

    $tables = $mysql->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = " . $mysql->quote($name) . " AND table_type = 'BASE TABLE'
         ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $columnQuery = $mysql->prepare(
        'SELECT * FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ?
         ORDER BY ordinal_position'
    );
    $keyQuery = $mysql->prepare(
        'SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list
         FROM information_schema.statistics
         WHERE table_schema = ? AND table_name = ?
         GROUP BY index_name, non_unique
         ORDER BY index_name'
    );
    $identities = [];
    $blobColumns = [];

    foreach ($tables as $table) {
        $columnQuery->execute([$name, $table]);
        $columns = $columnQuery->fetchAll();
        if ($columns === []) {
            throw new RuntimeException('Table sans colonnes : ' . $table);
        }
        $keyQuery->execute([$name, $table]);
        $primary = [];
        foreach ($keyQuery->fetchAll() as $index) {
            if (strtoupper((string) field($index, 'index_name')) === 'PRIMARY') {
                $primary = explode(',', (string) field($index, 'columns_list'));
            }
        }
        $lines = [];
        foreach ($columns as $column) {
            $columnName = (string) $column['COLUMN_NAME'];
            $type = pg_column_type($column);
            if ($type === 'bytea') {
                $blobColumns[$table][$columnName] = true;
            }
            $identity = str_contains(strtolower((string) $column['EXTRA']), 'auto_increment');
            if ($identity) {
                $identities[] = [$table, $columnName];
            }
            $line = qident($columnName) . ' ' . $type;
            if ($identity) {
                $line .= ' GENERATED BY DEFAULT AS IDENTITY';
            }
            $line .= ((string) $column['IS_NULLABLE'] === 'YES') ? ' NULL' : ' NOT NULL';
            $line .= pg_default($column);
            $lines[] = $line;
        }
        if ($primary !== []) {
            $lines[] = 'PRIMARY KEY (' . implode(', ', array_map('qident', $primary)) . ')';
        }
        $pg->exec('CREATE TABLE ' . qident($table) . " (\n  " . implode(",\n  ", $lines) . "\n)");
    }

    $pg->beginTransaction();
    $sourceCounts = [];
    foreach ($tables as $table) {
        $columnQuery->execute([$name, $table]);
        $columns = $columnQuery->fetchAll();
        $names = array_map(static fn (array $column): string => (string) $column['COLUMN_NAME'], $columns);
        $sourceCounts[$table] = (int) $mysql->query('SELECT COUNT(*) FROM ' . mysql_ident($table))->fetchColumn();
        if ($sourceCounts[$table] === 0) {
            continue;
        }
        $placeholders = [];
        foreach ($names as $columnName) {
            $placeholders[] = isset($blobColumns[$table][$columnName]) ? "decode(?, 'hex')" : '?';
        }
        $insert = $pg->prepare(
            'INSERT INTO ' . qident($table)
            . ' (' . implode(', ', array_map('qident', $names)) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $select = $mysql->query('SELECT * FROM ' . mysql_ident($table));
        $copied = 0;
        while ($row = $select->fetch()) {
            $values = [];
            foreach ($names as $columnName) {
                $value = $row[$columnName];
                if ($value === null) {
                    $values[] = null;
                    continue;
                }
                if (isset($blobColumns[$table][$columnName])) {
                    $values[] = bin2hex((string) $value);
                    continue;
                }
                if (is_string($value) && preg_match('/^0000-00-00/', $value) === 1) {
                    $values[] = null;
                    continue;
                }
                $values[] = $value;
            }
            try {
                $insert->execute($values);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Import arrêté sur ' . $table . ' après ' . $copied . ' ligne(s) : ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
            $copied++;
        }
        if ($copied !== $sourceCounts[$table]) {
            throw new RuntimeException('Comptage incohérent pour ' . $table . ' : ' . $copied . ' / ' . $sourceCounts[$table]);
        }
    }

    foreach ($tables as $table) {
        $keyQuery->execute([$name, $table]);
        foreach ($keyQuery->fetchAll() as $index) {
            if (strtoupper((string) field($index, 'index_name')) === 'PRIMARY') {
                continue;
            }
            $indexName = substr($table . '_' . field($index, 'index_name'), 0, 63);
            $columns = implode(', ', array_map('qident', explode(',', (string) field($index, 'columns_list'))));
            $unique = (int) field($index, 'non_unique') === 0 ? 'UNIQUE ' : '';
            $pg->exec('CREATE ' . $unique . 'INDEX ' . qident($indexName) . ' ON ' . qident($table) . ' (' . $columns . ')');
        }
    }

    $foreignKeys = $mysql->query(
        "SELECT k.constraint_name, k.table_name, k.column_name, k.referenced_table_name, k.referenced_column_name,
                k.ordinal_position, r.update_rule, r.delete_rule
         FROM information_schema.key_column_usage k
         JOIN information_schema.referential_constraints r
           ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
         WHERE k.table_schema = " . $mysql->quote($name) . " AND k.referenced_table_name IS NOT NULL
         ORDER BY k.table_name, k.constraint_name, k.ordinal_position"
    )->fetchAll();
    $grouped = [];
    foreach ($foreignKeys as $key) {
        $grouped[field($key, 'table_name') . '.' . field($key, 'constraint_name')][] = $key;
    }
    foreach ($grouped as $items) {
        $first = $items[0];
        $constraint = substr((string) field($first, 'constraint_name'), 0, 63);
        $columns = implode(', ', array_map(static fn (array $item): string => qident((string) field($item, 'column_name')), $items));
        $references = implode(', ', array_map(static fn (array $item): string => qident((string) field($item, 'referenced_column_name')), $items));
        $pg->exec(
            'ALTER TABLE ' . qident((string) field($first, 'table_name'))
            . ' ADD CONSTRAINT ' . qident($constraint)
            . ' FOREIGN KEY (' . $columns . ') REFERENCES ' . qident((string) field($first, 'referenced_table_name'))
            . ' (' . $references . ')'
            . ' ON UPDATE ' . field($first, 'update_rule')
            . ' ON DELETE ' . field($first, 'delete_rule')
        );
    }

    $pg->exec(
        'CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $body$
         BEGIN
           NEW.updated_at = CURRENT_TIMESTAMP;
           RETURN NEW;
         END;
         $body$ LANGUAGE plpgsql'
    );
    $touch = $mysql->query(
        "SELECT table_name FROM information_schema.columns
         WHERE table_schema = " . $mysql->quote($name) . "
           AND column_name = 'updated_at' AND extra LIKE '%on update%'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($touch as $table) {
        $pg->exec(
            'CREATE TRIGGER ' . qident(substr('touch_' . $table, 0, 63))
            . ' BEFORE UPDATE ON ' . qident((string) $table)
            . ' FOR EACH ROW EXECUTE FUNCTION set_updated_at()'
        );
    }

    $pg->exec(
        'CREATE OR REPLACE FUNCTION json_text_is_valid(value text) RETURNS boolean AS $body$
         BEGIN
           IF value IS NULL OR btrim(value) = \'\' THEN
             RETURN true;
           END IF;
           PERFORM value::jsonb;
           RETURN true;
         EXCEPTION WHEN others THEN
           RETURN false;
         END;
         $body$ LANGUAGE plpgsql IMMUTABLE'
    );
    $checks = $mysql->query(
        "SELECT tc.table_name, cc.constraint_name, cc.check_clause
         FROM information_schema.check_constraints cc
         JOIN information_schema.table_constraints tc
           ON tc.constraint_schema = cc.constraint_schema AND tc.constraint_name = cc.constraint_name
         WHERE cc.constraint_schema = " . $mysql->quote($name)
    )->fetchAll();
    foreach ($checks as $check) {
        if (preg_match('/json_valid\\(`([A-Za-z0-9_]+)`\\)/', (string) field($check, 'check_clause'), $match) !== 1) {
            throw new RuntimeException('Contrainte non migrée : ' . field($check, 'constraint_name') . ' ' . field($check, 'check_clause'));
        }
        $pg->exec(
            'ALTER TABLE ' . qident((string) field($check, 'table_name'))
            . ' ADD CONSTRAINT ' . qident(substr((string) field($check, 'constraint_name'), 0, 63))
            . ' CHECK (json_text_is_valid(' . qident($match[1]) . '))'
        );
    }

    foreach ($identities as [$table, $column]) {
        $sequence = $pg->query('SELECT pg_get_serial_sequence(' . $pg->quote($table) . ', ' . $pg->quote($column) . ')')->fetchColumn();
        if (!$sequence) {
            throw new RuntimeException('Séquence absente pour ' . $table . '.' . $column);
        }
        $max = (int) $pg->query('SELECT COALESCE(MAX(' . qident($column) . '), 0) FROM ' . qident($table))->fetchColumn();
        if ($max > 0) {
            $pg->query('SELECT setval(' . $pg->quote((string) $sequence) . ', ' . $max . ', true)');
        } else {
            $pg->query('SELECT setval(' . $pg->quote((string) $sequence) . ', 1, false)');
        }
    }

    $mismatches = [];
    foreach ($sourceCounts as $table => $count) {
        $destination = (int) $pg->query('SELECT COUNT(*) FROM ' . qident($table))->fetchColumn();
        if ($destination !== $count) {
            $mismatches[] = $table . ' source=' . $count . ' destination=' . $destination;
        }
        echo str_pad($table, 36) . str_pad((string) $count, 8) . $destination . PHP_EOL;
    }
    if ($mismatches !== []) {
        throw new RuntimeException('Comptages différents : ' . implode('; ', $mismatches));
    }

    $settings = $mysql->query(
        "SELECT setting_key, setting_value FROM system_settings WHERE setting_value REGEXP '[^ -~]'"
    )->fetchAll();
    $settingLookup = $pg->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    foreach ($settings as $setting) {
        $settingLookup->execute([$setting['setting_key']]);
        if ((string) $settingLookup->fetchColumn() !== (string) $setting['setting_value']) {
            throw new RuntimeException('Texte non conservé pour ' . $setting['setting_key']);
        }
    }
    $money = [
        'SELECT COALESCE(SUM(total_paye), 0) FROM finance_solde',
        'SELECT COALESCE(SUM(montant_paye), 0) FROM finance',
    ];
    foreach ($money as $sum) {
        $left = $mysql->query($sum)->fetchColumn();
        $right = $pg->query($sum)->fetchColumn();
        if ((string) $left !== (string) $right) {
            throw new RuntimeException('Somme différente pour ' . $sum . ' : ' . $left . ' / ' . $right);
        }
        echo $sum . ' = ' . $right . PHP_EOL;
    }
    $logo = $mysql->query('SELECT asset_key, OCTET_LENGTH(content) AS bytes FROM institution_logo')->fetchAll();
    $logoLookup = $pg->prepare('SELECT octet_length(content) FROM institution_logo WHERE asset_key = ?');
    foreach ($logo as $asset) {
        $logoLookup->execute([$asset['asset_key']]);
        if ((string) $logoLookup->fetchColumn() !== (string) $asset['bytes']) {
            throw new RuntimeException('Binaire différent pour institution_logo.' . $asset['asset_key']);
        }
        echo 'institution_logo.' . $asset['asset_key'] . ' ' . $asset['bytes'] . ' octets' . PHP_EOL;
    }

    $pg->commit();
    echo "MIGRATION OK " . count($tables) . " tables\n";
} catch (Throwable $exception) {
    if ($pg->inTransaction()) {
        $pg->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

<?php
declare(strict_types=1);

/**
 * Database connection defaults.
 * The live PDO factory remains db() in application/config.php and still honors
 * DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS environment variables.
 */
return [
    'driver' => 'pgsql',
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '5433',
    'database' => getenv('DB_NAME') ?: 'ecole_sante_gabes',
    'username' => getenv('DB_USER') ?: 'myapp_user',
    'password' => getenv('DB_PASS') ?: 'myapp_password',
    'charset' => 'UTF8',
];

<?php
declare(strict_types=1);

/**
 * High-level application settings.
 * Runtime bootstrap still lives in application/bootstrap.php and application/config.php
 * so existing pages keep their authentication and database behavior.
 */
return [
    'name' => 'Université privée des sciences infirmières et de santé de Gabès',
    'locale' => 'fr',
    'public_url' => getenv('APP_URL') ?: '',
    'timezone' => 'UTC',
];

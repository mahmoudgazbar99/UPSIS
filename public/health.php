<?php
declare(strict_types=1);

// Render health probe — no bootstrap, no Neon. Keep this file dependency-free.
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
http_response_code(200);
echo 'ok';

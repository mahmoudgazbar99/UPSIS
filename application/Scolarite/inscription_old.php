<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_permission('students.edit');
header('Location: inscription.php');
exit;

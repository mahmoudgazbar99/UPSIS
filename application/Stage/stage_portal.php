<?php
declare(strict_types=1);

require_once app_path('layout.php');
require_permission('stage.view');
$embed = app_is_embedded() ? '&embed=1' : '';
header('Location: stage_bureau.php?section=tableau' . $embed);
exit;

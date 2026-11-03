<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/student_sheet.php');
require_permission('students.export');
student_sheet_send('etudiants.xlsx', student_sheet_xlsx(student_sheet_export_rows()));

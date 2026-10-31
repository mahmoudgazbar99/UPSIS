<?php
declare(strict_types=1);
require_once app_path('bootstrap.php');
require_admin();

$pdo = db();
$pdo->exec("SET SESSION character_set_connection = 'utf8mb4'");
$pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");

// Tables and columns to fix
$fixes = [
    'etudiant' => ['nom_ar', 'prenom_ar'],
    'enseignant' => ['nom_ar', 'prenom_ar'],
    'specialite' => ['nom_spec_ar'],
    'groupe' => ['matiere_ar'],
    'niveau_etudiant' => ['nom_ar', 'prenom_ar'],
];

foreach ($fixes as $table => $columns) {
    // Check if table exists
    $checkTable = $pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
    if (!$checkTable) continue;
    
    foreach ($columns as $col) {
        // Check if column exists
        $checkCol = $pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchColumn();
        if (!$checkCol) continue;
        
        echo "Fixing $table.$col...\n";
        
        // Convert double-encoded UTF-8 back to proper UTF-8
        $sql = "UPDATE $table SET $col = CAST(CONVERT(CAST($col AS BINARY) USING utf8mb4) AS CHAR CHARACTER SET utf8mb4) WHERE $col IS NOT NULL AND LENGTH($col) > 0";
        try {
            $pdo->exec($sql);
            echo "✓ Fixed $table.$col\n";
        } catch (Exception $e) {
            error_log('utf8 repair failed for ' . $table . '.' . $col . ': ' . $e->getMessage());
            echo "Error fixing $table.$col\n";
        }
    }
}

echo "\nUtf-8 fix completed!\n";


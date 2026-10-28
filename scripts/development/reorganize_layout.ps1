#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Set-Location $Root

function Ensure-Dir([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Move-Tracked([string]$From, [string]$To) {
    $src = Join-Path $Root $From
    $dst = Join-Path $Root $To
    if (-not (Test-Path -LiteralPath $src)) {
        Write-Host "SKIP missing $From"
        return
    }
    Ensure-Dir (Split-Path -Parent $dst)
    if (Test-Path -LiteralPath $dst) {
        throw "Target already exists: $To"
    }
    Move-Item -LiteralPath $src -Destination $dst
    Write-Host "MOVE $From -> $To"
}

# --- directories ---
$dirs = @(
    'public',
    'public/assets',
    'public/images',
    'public/exports',
    'app',
    'app/support',
    'app/pages/Auth',
    'app/pages/Accounts',
    'app/pages/Dashboard',
    'app/pages/Students',
    'app/pages/Teachers',
    'app/pages/Academics',
    'app/pages/Grades',
    'app/pages/Attendance',
    'app/pages/Internships',
    'app/pages/Finance',
    'app/pages/Documents',
    'app/pages/Admin',
    'app/pages/Landing',
    'config',
    'database/schema',
    'database/seeds',
    'database/migrations',
    'database/backups',
    'storage/logs',
    'storage/cache',
    'storage/exports',
    'storage/uploads',
    'storage/generated',
    'storage/tmp',
    'resources/templates',
    'docs/architecture',
    'docs/deployment',
    'docs/developer-guide',
    'scripts/maintenance',
    'scripts/deployment',
    'scripts/development',
    'tests/Unit',
    'tests/Integration',
    'tests/Feature',
    'tests/Fixtures',
    'docker/php'
)
foreach ($d in $dirs) { Ensure-Dir (Join-Path $Root $d) }

# --- page map: filename -> app/pages/... ---
$pageMap = @{
    'login.php' = 'app/pages/Auth/login.php'
    'logout.php' = 'app/pages/Auth/logout.php'
    'connexion_etudiant.php' = 'app/pages/Auth/connexion_etudiant.php'
    'changer_mot_de_passe.php' = 'app/pages/Auth/changer_mot_de_passe.php'
    'creer_espace_etudiant.php' = 'app/pages/Auth/creer_espace_etudiant.php'

    'creer_comptes.php' = 'app/pages/Accounts/creer_comptes.php'
    'modifier_comptes.php' = 'app/pages/Accounts/modifier_comptes.php'
    'activer_comptes.php' = 'app/pages/Accounts/activer_comptes.php'
    'ajouter_compte_stage.php' = 'app/pages/Accounts/ajouter_compte_stage.php'
    'finance_users.php' = 'app/pages/Accounts/finance_users.php'

    'index.php' = 'app/pages/Dashboard/index.php'
    'dashboard.php' = 'app/pages/Dashboard/dashboard.php'

    'inscription.php' = 'app/pages/Students/inscription.php'
    'inscription_old.php' = 'app/pages/Students/inscription_old.php'
    'identite.php' = 'app/pages/Students/identite.php'
    'etablissement.php' = 'app/pages/Students/etablissement.php'
    'etudiant_inbox.php' = 'app/pages/Students/etudiant_inbox.php'

    'enseignants.php' = 'app/pages/Teachers/enseignants.php'
    'affecter_enseignant.php' = 'app/pages/Teachers/affecter_enseignant.php'
    'responsables.php' = 'app/pages/Teachers/responsables.php'

    'tronc_commun.php' = 'app/pages/Academics/tronc_commun.php'
    'structure_academique.php' = 'app/pages/Academics/structure_academique.php'
    'specialites.php' = 'app/pages/Academics/specialites.php'
    'ajouter_matiere.php' = 'app/pages/Academics/ajouter_matiere.php'
    'ajouter_unite.php' = 'app/pages/Academics/ajouter_unite.php'
    'groupes.php' = 'app/pages/Academics/groupes.php'
    'formule_calcul.php' = 'app/pages/Academics/formule_calcul.php'
    'politique_promotion.php' = 'app/pages/Academics/politique_promotion.php'
    'politique_presence.php' = 'app/pages/Academics/politique_presence.php'
    'promotion.php' = 'app/pages/Academics/promotion.php'
    'emploi_temps.php' = 'app/pages/Academics/emploi_temps.php'

    'gestion_notes.php' = 'app/pages/Grades/gestion_notes.php'
    'grille_notes.php' = 'app/pages/Grades/grille_notes.php'
    'releve_notes.php' = 'app/pages/Grades/releve_notes.php'

    'presence_etudiants.php' = 'app/pages/Attendance/presence_etudiants.php'
    'feuille_presence.php' = 'app/pages/Attendance/feuille_presence.php'
    'generate_feuille_presence.php' = 'app/pages/Attendance/generate_feuille_presence.php'
    'generate_presence.php' = 'app/pages/Attendance/generate_presence.php'
    'alertes_elimination.php' = 'app/pages/Attendance/alertes_elimination.php'
    'demandes_presence.php' = 'app/pages/Attendance/demandes_presence.php'

    'stage_allocation.php' = 'app/pages/Internships/stage_allocation.php'
    'stage_archives.php' = 'app/pages/Internships/stage_archives.php'
    'stage_campagne.php' = 'app/pages/Internships/stage_campagne.php'
    'stage_docs.php' = 'app/pages/Internships/stage_docs.php'
    'stage_document.php' = 'app/pages/Internships/stage_document.php'
    'stage_doc_types.php' = 'app/pages/Internships/stage_doc_types.php'
    'stage_dossier.php' = 'app/pages/Internships/stage_dossier.php'
    'stage_organismes.php' = 'app/pages/Internships/stage_organismes.php'
    'stage_placements.php' = 'app/pages/Internships/stage_placements.php'
    'stage_portal.php' = 'app/pages/Internships/stage_portal.php'
    'stage_suivi.php' = 'app/pages/Internships/stage_suivi.php'

    'finance.php' = 'app/pages/Finance/finance.php'
    'frais_scolarite.php' = 'app/pages/Finance/frais_scolarite.php'
    'recu_paiement.php' = 'app/pages/Finance/recu_paiement.php'

    'attestation_inscription.php' = 'app/pages/Documents/attestation_inscription.php'
    'attestation_live.php' = 'app/pages/Documents/attestation_live.php'
    'attestation_presence.php' = 'app/pages/Documents/attestation_presence.php'
    'attestation_reussite.php' = 'app/pages/Documents/attestation_reussite.php'
    'generate_word.php' = 'app/pages/Documents/generate_word.php'
    'generate_reussite.php' = 'app/pages/Documents/generate_reussite.php'
    'download_all_inscriptions.php' = 'app/pages/Documents/download_all_inscriptions.php'
    'archive_export.php' = 'app/pages/Documents/archive_export.php'
    'etudiants_export.php' = 'app/pages/Documents/etudiants_export.php'

    'journal_audit.php' = 'app/pages/Admin/journal_audit.php'
    'fix_utf8.php' = 'app/pages/Admin/fix_utf8.php'

    'accueil.php' = 'app/pages/Landing/accueil.php'
}

$supportMap = @{
    'config.php' = 'app/config.php'
    'pgsql.php' = 'app/pgsql.php'
    'layout.php' = 'app/layout.php'
    'student_theme.php' = 'app/support/student_theme.php'
    'institution.php' = 'app/support/institution.php'
    'landing_i18n.php' = 'app/support/landing_i18n.php'
    'certificate.php' = 'app/support/certificate.php'
    'certificate_export.php' = 'app/support/certificate_export.php'
    'brand_logo.php' = 'app/support/brand_logo.php'
    'access_roles.php' = 'app/support/access_roles.php'
    'academic_policy.php' = 'app/support/academic_policy.php'
}

$sqlMigrations = @(
    'academic_migration.sql',
    'academic_policy_migration.sql',
    'admin_migration.sql',
    'attestation_presence_migration.sql',
    'audit_migration.sql',
    'emploi_temps_migration.sql',
    'etudiant_compte_migration.sql',
    'finance_account_migration.sql',
    'finance_history_migration.sql',
    'finance_migration.sql',
    'presence_migration.sql',
    'promotion_archive_migration.sql',
    'stage_accounts_migration.sql',
    'td_groups_migration.sql',
    'teacher_migration.sql'
)
$sqlSeeds = @('seed_tronc_commun.sql', 's1_academic_seed.sql', 'db.sql', 'db_new.sql')
$sqlBackups = @('ecole_sante_gabes_full.sql', 'ecole_sante_gabes_export_2026-09-18.sql')

foreach ($k in $supportMap.Keys) { Move-Tracked $k $supportMap[$k] }
foreach ($k in $pageMap.Keys) { Move-Tracked $k $pageMap[$k] }

foreach ($f in $sqlMigrations) { Move-Tracked $f ("database/migrations/$f") }
foreach ($f in $sqlSeeds) { Move-Tracked $f ("database/seeds/$f") }
foreach ($f in $sqlBackups) { Move-Tracked $f ("database/backups/$f") }

# Public static trees
if (Test-Path (Join-Path $Root 'assets')) {
    Get-ChildItem (Join-Path $Root 'assets') | ForEach-Object {
        $target = Join-Path (Join-Path $Root 'public\assets') $_.Name
        if (-not (Test-Path $target)) { Move-Item $_.FullName $target }
    }
    if ((Get-ChildItem (Join-Path $Root 'assets') -Force | Measure-Object).Count -eq 0) {
        Remove-Item (Join-Path $Root 'assets') -Force
    }
}
if (Test-Path (Join-Path $Root 'images')) {
    Get-ChildItem (Join-Path $Root 'images') | ForEach-Object {
        $target = Join-Path (Join-Path $Root 'public\images') $_.Name
        if (-not (Test-Path $target)) { Move-Item $_.FullName $target }
    }
    if ((Get-ChildItem (Join-Path $Root 'images') -Force | Measure-Object).Count -eq 0) {
        Remove-Item (Join-Path $Root 'images') -Force
    }
}
if (Test-Path (Join-Path $Root 'exports')) {
    Get-ChildItem (Join-Path $Root 'exports') | ForEach-Object {
        $target = Join-Path (Join-Path $Root 'public\exports') $_.Name
        if (-not (Test-Path $target)) { Move-Item $_.FullName $target }
    }
    if ((Get-ChildItem (Join-Path $Root 'exports') -Force | Measure-Object).Count -eq 0) {
        Remove-Item (Join-Path $Root 'exports') -Force
    }
}
if (Test-Path (Join-Path $Root 'logo.png')) {
    Move-Tracked 'logo.png' 'public/logo.png'
}
if (Test-Path (Join-Path $Root 'tmp')) {
    Get-ChildItem (Join-Path $Root 'tmp') -Force | ForEach-Object {
        Move-Item $_.FullName (Join-Path (Join-Path $Root 'storage\tmp') $_.Name) -Force
    }
    Remove-Item (Join-Path $Root 'tmp') -Force -ErrorAction SilentlyContinue
}
foreach ($tmp in @('tmpuaafm.php', 'tmpuiweh.php')) {
    if (Test-Path (Join-Path $Root $tmp)) {
        Move-Tracked $tmp ("storage/tmp/$tmp")
    }
}

# Docs consolidation (preserve, do not delete)
$docMoves = @{
    'CONFIGURATION_ADMIN.md' = 'docs/developer-guide/CONFIGURATION_ADMIN.md'
    'SCOLARITE_GUIDE.md' = 'docs/developer-guide/SCOLARITE_GUIDE.md'
    'STAGE_GUIDE.md' = 'docs/developer-guide/STAGE_GUIDE.md'
    'PROJECT_EXHAUSTIVE_DOCUMENTATION.txt' = 'docs/architecture/PROJECT_EXHAUSTIVE_DOCUMENTATION.txt'
    'eval_main_1.md' = 'docs/architecture/eval_main_1.md'
    'eval_main_2.md' = 'docs/architecture/eval_main_2.md'
}
foreach ($k in $docMoves.Keys) {
    if (Test-Path (Join-Path $Root $k)) { Move-Tracked $k $docMoves[$k] }
}

# Create public URL stubs for every moved page
$stubTemplate = @'
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require app_path('{REL}');
'@

foreach ($k in $pageMap.Keys) {
    $rel = ($pageMap[$k] -replace '^app/', '' -replace '\\', '/')
    $stubPath = Join-Path $Root ("public/$k")
    $content = $stubTemplate.Replace('{REL}', $rel)
    Set-Content -LiteralPath $stubPath -Value $content -Encoding UTF8
    Write-Host "STUB public/$k"
}

# Root compatibility stubs (for tools / accidental root hits while DocumentRoot migrates)
$rootStub = @'
<?php
declare(strict_types=1);
require __DIR__ . '/public/{FILE}';
'@
foreach ($k in $pageMap.Keys) {
    $path = Join-Path $Root $k
    if (-not (Test-Path $path)) {
        Set-Content -LiteralPath $path -Value ($rootStub.Replace('{FILE}', $k)) -Encoding UTF8
        Write-Host "ROOT-STUB $k"
    }
}

Write-Host 'DONE reorganize_layout'

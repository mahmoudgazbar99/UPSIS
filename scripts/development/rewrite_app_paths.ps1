#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$appRoot = Join-Path $Root 'app'

function Rewrite-File([string]$Path) {
    $original = [IO.File]::ReadAllText($Path)
    $text = $original

    $rel = $Path.Substring($appRoot.Length).TrimStart('\', '/').Replace('\', '/')

    if ($rel -eq 'layout.php') {
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/config\.php['\`"]", "require_once __DIR__ . '/bootstrap.php'"
    } elseif ($rel -eq 'config.php') {
        if ($text -notmatch "APP_CONFIG_LOADED") {
            $text = $text -replace '^<\?php\s*', "<?php`r`ndeclare(strict_types=1);`r`nif (defined('APP_CONFIG_LOADED')) {`r`n    return;`r`n}`r`ndefine('APP_CONFIG_LOADED', true);`r`n`r`n"
            $text = $text -replace "declare\(strict_types=1\);\r?\ndeclare\(strict_types=1\);", "declare(strict_types=1);"
        }
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/pgsql\.php['\`"]", "require_once __DIR__ . '/pgsql.php'"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/access_roles\.php['\`"]", "require_once __DIR__ . '/support/access_roles.php'"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/institution\.php['\`"]", "require_once __DIR__ . '/support/institution.php'"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/academic_policy\.php['\`"]", "require_once __DIR__ . '/support/academic_policy.php'"
    } elseif ($rel -eq 'support/access_roles.php') {
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/stage_allocation\.php['\`"]", "require_once dirname(__DIR__) . '/pages/Internships/stage_allocation.php'"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/stage_docs\.php['\`"]", "require_once dirname(__DIR__) . '/pages/Internships/stage_docs.php'"
    } else {
        # Pages and other support files: bootstrap already provides path helpers.
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/layout\.php['\`"]", "require_once app_path('layout.php')"
        $text = $text -replace "require\s+__DIR__\s*\.\s*['\`"]/layout\.php['\`"]", "require app_path('layout.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/config\.php['\`"]", "require_once app_path('bootstrap.php')"
        $text = $text -replace "require\s+__DIR__\s*\.\s*['\`"]/config\.php['\`"]", "require app_path('bootstrap.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/student_theme\.php['\`"]", "require_once app_path('support/student_theme.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/institution\.php['\`"]", "require_once app_path('support/institution.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/landing_i18n\.php['\`"]", "require_once app_path('support/landing_i18n.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/certificate\.php['\`"]", "require_once app_path('support/certificate.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/certificate_export\.php['\`"]", "require_once app_path('support/certificate_export.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/brand_logo\.php['\`"]", "require_once app_path('support/brand_logo.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/access_roles\.php['\`"]", "require_once app_path('support/access_roles.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/academic_policy\.php['\`"]", "require_once app_path('support/academic_policy.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/tronc_commun\.php['\`"]", "require_once app_path('pages/Academics/tronc_commun.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/stage_allocation\.php['\`"]", "require_once app_path('pages/Internships/stage_allocation.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/stage_docs\.php['\`"]", "require_once app_path('pages/Internships/stage_docs.php')"
        $text = $text -replace "require_once\s+__DIR__\s*\.\s*['\`"]/vendor/autoload\.php['\`"]", "require_once root_path('vendor/autoload.php')"
    }

    # Private templates stay at project templates/
    $text = $text -replace "__DIR__\s*\.\s*['\`"]/templates/", "root_path('templates/') . '"
    # Fix accidental double quotes from replace like root_path('templates/') . '/file' when original was __DIR__ . '/templates/file'
    # Pattern was: __DIR__ . '/templates/foo' -> root_path('templates/') . 'foo'  but we replaced prefix only leaving '/templates/foo'
    # Redo more carefully below for templates/images if needed.

    if ($text -ne $original) {
        [IO.File]::WriteAllText($Path, $text)
        Write-Host "REWRITE $Path"
    }
}

Get-ChildItem -Path $appRoot -Recurse -Filter *.php | ForEach-Object { Rewrite-File $_.FullName }

# Targeted filesystem path fixes for templates and images
$special = Get-ChildItem -Path $appRoot -Recurse -Filter *.php
foreach ($file in $special) {
    $original = [IO.File]::ReadAllText($file.FullName)
    $text = $original
    $text = [regex]::Replace($text, "__DIR__\s*\.\s*'(/templates/[^']+)'", { param($m) "root_path('" + $m.Groups[1].Value.TrimStart('/') + "')" })
    $text = [regex]::Replace($text, '__DIR__\s*\.\s*"/templates/([^"]+)"', { param($m) "root_path('templates/$($m.Groups[1].Value)')" })
    $text = [regex]::Replace($text, "__DIR__\s*\.\s*'/images/([^']+)'", { param($m) "public_path('images/$($m.Groups[1].Value)')" })
    $text = [regex]::Replace($text, '__DIR__\s*\.\s*"/images/([^"]+)"', { param($m) "public_path('images/$($m.Groups[1].Value)')" })
    # Broken intermediate form from first templates pass
    $text = $text -replace "root_path\('templates/'\) \. '/templates/", "root_path('templates/"
    if ($text -ne $original) {
        [IO.File]::WriteAllText($file.FullName, $text)
        Write-Host "PATHFIX $($file.FullName)"
    }
}

Write-Host 'DONE rewrite_app_paths'

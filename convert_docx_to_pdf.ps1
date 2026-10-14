param(
    [Parameter(Mandatory = $true)]
    [string] $Docx,
    [Parameter(Mandatory = $true)]
    [string] $Pdf
)

$ErrorActionPreference = 'Stop'

$docxPath = [System.IO.Path]::GetFullPath($Docx)
$pdfPath = [System.IO.Path]::GetFullPath($Pdf)
$pdfDir = [System.IO.Path]::GetDirectoryName($pdfPath)
if (-not (Test-Path -LiteralPath $docxPath)) {
    throw "Word document not found: $docxPath"
}
if ($pdfDir -and -not (Test-Path -LiteralPath $pdfDir)) {
    New-Item -ItemType Directory -Path $pdfDir | Out-Null
}

$word = $null
$document = $null
try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $document = $word.Documents.Open($docxPath, $false, $true)
    $wdFormatPDF = 17
    $document.ExportAsFixedFormat($pdfPath, $wdFormatPDF)
} finally {
    if ($document -ne $null) {
        try { $document.Close($false) } catch {}
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($document)
    }
    if ($word -ne $null) {
        try { $word.Quit() } catch {}
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}

if (-not (Test-Path -LiteralPath $pdfPath) -or ((Get-Item -LiteralPath $pdfPath).Length -le 0)) {
    throw "PDF export failed for $docxPath"
}

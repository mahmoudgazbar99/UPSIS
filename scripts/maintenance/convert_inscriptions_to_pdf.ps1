param(
    [Parameter(Mandatory = $true)]
    [string] $WorkDir,
    [Parameter(Mandatory = $true)]
    [string] $OutputPdf
)

$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0

try {
    $master = $word.Documents.Add()
    try {
        $master.Sections.Item(1).PageSetup.Orientation = 1
        $master.Sections.Item(1).PageSetup.PageWidth = 842
        $master.Sections.Item(1).PageSetup.PageHeight = 595
        $documents = @(Get-ChildItem -LiteralPath $WorkDir -Filter '*.docx' | Sort-Object Name)
        for ($index = 0; $index -lt $documents.Count; $index++) {
            if ($index -gt 0) {
                $insertRange = $master.Range($master.Content.End - 1, $master.Content.End - 1)
                $insertRange.InsertBreak(7)
            }
            $insertRange = $master.Range($master.Content.End - 1, $master.Content.End - 1)
            $insertRange.InsertFile($documents[$index].FullName)
        }
        $master.ExportAsFixedFormat($OutputPdf, 17)
    } finally {
        try { $master.Close($false) } catch {}
    }
} finally {
    $word.Quit()
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)
}

# Puts a "MindCollector Dev" shortcut (named "MindCollector Logs" until 2026-10-10; old shortcuts are removed) on the desktop and in the Start menu, with the app's icon.
# Run once:  powershell -ExecutionPolicy Bypass -File tools\log-manager\Install-Shortcuts.ps1
# Safe to re-run; it overwrites its own shortcuts and nothing else.

Add-Type -AssemblyName System.Drawing
$here = $PSScriptRoot
$ico = Join-Path $here 'mindcollector.ico'

# The same gold "M" the window and tray use, drawn at 256px and stored as a PNG inside an .ico
# (the format Windows has read since Vista), so it stays sharp at every size.
$size = 256
$bmp = New-Object Drawing.Bitmap $size, $size
$g = [Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = 'AntiAlias'
$g.TextRenderingHint = 'AntiAliasGridFit'
$g.FillEllipse((New-Object Drawing.SolidBrush ([Drawing.Color]::FromArgb(200, 149, 44))), 8, 8, 240, 240)
$font = New-Object Drawing.Font('Segoe UI', 120, [Drawing.FontStyle]::Bold, [Drawing.GraphicsUnit]::Pixel)
$fmt = New-Object Drawing.StringFormat
$fmt.Alignment = 'Center'; $fmt.LineAlignment = 'Center'
$g.DrawString('M', $font, (New-Object Drawing.SolidBrush ([Drawing.Color]::FromArgb(9, 9, 13))), (New-Object Drawing.RectangleF(0, 6, $size, $size)), $fmt)
$g.Dispose()
$png = New-Object IO.MemoryStream
$bmp.Save($png, [Drawing.Imaging.ImageFormat]::Png)
$bytes = $png.ToArray()

$out = New-Object IO.MemoryStream
$w = New-Object IO.BinaryWriter $out
$w.Write([uint16]0); $w.Write([uint16]1); $w.Write([uint16]1)          # header: icon, 1 image
$w.Write([byte]0); $w.Write([byte]0); $w.Write([byte]0); $w.Write([byte]0)  # 256x256, no palette
$w.Write([uint16]1); $w.Write([uint16]32)                                  # planes, bpp
$w.Write([uint32]$bytes.Length); $w.Write([uint32]22)                      # size, offset
$w.Write($bytes)
[IO.File]::WriteAllBytes($ico, $out.ToArray())

$shell = New-Object -ComObject WScript.Shell
$targets = @(
    (Join-Path ([Environment]::GetFolderPath('Desktop')) 'MindCollector Dev.lnk'),
    (Join-Path ([Environment]::GetFolderPath('Programs')) 'MindCollector Dev.lnk')
)
# The old name's shortcuts, so the desktop does not show two.
foreach ($folder in @('Desktop', 'Programs')) {
    $old = Join-Path ([Environment]::GetFolderPath($folder)) 'MindCollector Logs.lnk'
    if (Test-Path $old) { Remove-Item $old; "Removed $old" }
}
foreach ($path in $targets) {
    $lnk = $shell.CreateShortcut($path)
    $lnk.TargetPath = "$env:WINDIR\System32\wscript.exe"
    $lnk.Arguments = "`"$(Join-Path $here 'MindCollector Logs.vbs')`""
    $lnk.WorkingDirectory = $here
    $lnk.IconLocation = "$ico,0"
    $lnk.Description = 'Read arena games from WoW into MindCollector and browse them'
    $lnk.Save()
    "Created $path"
}

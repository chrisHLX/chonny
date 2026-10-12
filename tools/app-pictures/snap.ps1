param([string]$File, [string]$Name, [string]$Tab = '', [int]$W = 980, [int]$H = 1400)
# Screenshot one anonymised app page (storage/app/app-pictures/anon) at 2x with headless Edge, into
# storage/app/app-pictures/out. -Tab opens that tab of a game card. See README.md.
$here = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\storage\app\app-pictures'))
$html = Get-Content -Raw -Encoding utf8 (Join-Path $here "anon\$File")
if ($Tab) { $html = $html -replace "onload=""showTab\('summary'\)""", "onload=""showTab('$Tab')""" }
$tmp = Join-Path $here "anon\_snap-$Name.html"
Set-Content -Encoding utf8 -Path $tmp -Value $html
New-Item -ItemType Directory -Force (Join-Path $here 'out') | Out-Null
$out = Join-Path $here "out\$Name.png"
$edge = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
& $edge --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=2 --allow-file-access-from-files "--user-data-dir=$(Join-Path $here 'edge-profile')" "--window-size=$W,$H" "--screenshot=$out" --virtual-time-budget=3000 ("file:///" + ($tmp -replace '\\','/')) 2>$null | Out-Null
Start-Sleep -Milliseconds 300
if (Test-Path $out) { $out } else { "no screenshot for $File" }

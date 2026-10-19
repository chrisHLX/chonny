# Builds the Microsoft Store package: store\out\MindCollector-<version>.msix, to upload in
# Partner Center (MindCollector > Packages). Unsigned on purpose: the Store signs what it publishes.
#
#   powershell -ExecutionPolicy Bypass -File tools\desktop-app\store\build-store.ps1 [-Tools <dir>]
#
# Needs the .NET 8 SDK and makeappx.exe / makepri.exe: the Windows SDK, or Microsoft's
# Microsoft.Windows.SDK.BuildTools NuGet package unpacked anywhere (pass its bin\<ver>\x64 as -Tools).
# The version is the csproj's <Version> plus ".0"; the Store needs each upload's to be higher.
param([string]$Tools = '')

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$project = Split-Path -Parent $here
$layout = Join-Path $here 'layout'
$out = Join-Path $here 'out'

if (-not $Tools) {
    $candidates = @(Get-ChildItem "$env:USERPROFILE\.nuget\packages\microsoft.windows.sdk.buildtools\*\bin\*\x64\makeappx.exe" -ErrorAction SilentlyContinue) +
                  @(Get-ChildItem "${env:ProgramFiles(x86)}\Windows Kits\10\bin\*\x64\makeappx.exe" -ErrorAction SilentlyContinue)
    if ($candidates.Count -eq 0) { throw 'makeappx.exe not found: pass -Tools <folder holding makeappx.exe and makepri.exe>' }
    $Tools = ($candidates | Sort-Object FullName | Select-Object -Last 1).DirectoryName
}
$makeappx = Join-Path $Tools 'makeappx.exe'
$makepri = Join-Path $Tools 'makepri.exe'

[xml]$csproj = Get-Content (Join-Path $project 'MindCollector.Desktop.csproj')
$version = (($csproj.Project.PropertyGroup | Where-Object { $_.Version }) | Select-Object -First 1).Version + '.0'
Write-Host "MindCollector $version for the Store"

Remove-Item -Recurse -Force $layout -ErrorAction SilentlyContinue
& dotnet publish (Join-Path $project 'MindCollector.Desktop.csproj') -c Release -p:Store=true -o $layout
if ($LASTEXITCODE -ne 0) { throw 'dotnet publish failed' }

Copy-Item -Recurse (Join-Path $here 'Assets') (Join-Path $layout 'Assets')
(Get-Content (Join-Path $here 'AppxManifest.xml') -Raw).Replace('{VERSION}', $version) |
    Set-Content (Join-Path $layout 'AppxManifest.xml') -Encoding UTF8

# The resource index the Store expects beside the manifest (logos, language).
$priconfig = Join-Path $env:TEMP 'mindcollector-priconfig.xml'
& $makepri createconfig /cf $priconfig /dq en-US /o | Out-Null
& $makepri new /pr $layout /cf $priconfig /mn (Join-Path $layout 'AppxManifest.xml') /of (Join-Path $layout 'resources.pri') /o | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'makepri failed' }

New-Item -ItemType Directory -Force $out | Out-Null
$msix = Join-Path $out "MindCollector-$version.msix"
& $makeappx pack /d $layout /p $msix /o
if ($LASTEXITCODE -ne 0) { throw 'makeappx failed' }

Write-Host ("Built {0} ({1:N0} MB)" -f $msix, ((Get-Item $msix).Length / 1MB))

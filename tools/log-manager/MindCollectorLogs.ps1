# ---------------------------------------------------------------------------
#  MindCollector Logs
#
#  A small window (and tray icon) over the arena-log workflow:
#    1. Watches WoW's Logs folder for WoWCombatLog-*.txt.
#    2. When a game has finished, runs `php artisan wow:ingest-combatlog` on that
#       file, then `php artisan wow:sync --skip-ingest` to build the reviews.
#    3. Once a log is archived and WoW has let go of it, moves the log out of
#       WoW's folder into the "move logs to" folder (default D:\MindCollector\wow-logs).
#    4. Lists your archived games and lets you set the folders without editing .env.
#
#  Start it with "MindCollector Logs.vbs" (no console window). See README.md.
#
#  WHEN IS A GAME FINISHED. The addon turns logging off one second after the arena
#  ends. A Solo Shuffle writes one ARENA_MATCH_START per round and a single
#  ARENA_MATCH_END for the lobby, so "the last arena marker in the file is an END"
#  is true only between lobbies - never mid-shuffle. Syncing mid-lobby would store
#  a three-round review that a later sync never rebuilds, which is why this waits.
#
#  WHEN IS A LOG SAFE TO MOVE. WoW writes one file per client session and holds it
#  open. A file is moved only when (a) every byte of it has been ingested
#  successfully, (b) it is not the file of a running WoW session, and (c) it can be
#  opened exclusively. Anything else waits for the next pass.
#
#  Plain ASCII on purpose: Windows PowerShell 5.1 reads a BOM-less script as ANSI.
# ---------------------------------------------------------------------------

param([switch]$Minimized)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms, System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$mutexCreated = $false
$script:Mutex = New-Object System.Threading.Mutex($true, 'Local\MindCollectorLogs', [ref]$mutexCreated)
if (-not $mutexCreated) {
    [System.Windows.Forms.MessageBox]::Show('MindCollector Logs is already running - look for it in the tray.', 'MindCollector Logs') | Out-Null
    exit
}

# --- Paths -----------------------------------------------------------------

$script:Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$script:EnvFile = Join-Path $script:Repo '.env'
$script:StateDir = Join-Path $env:APPDATA 'MindCollector'
$script:SettingsFile = Join-Path $script:StateDir 'logs-app.json'
$script:ActivityFile = Join-Path $script:StateDir 'logs-app.log'
$script:ServerPort = 8321
New-Item -ItemType Directory -Force -Path $script:StateDir | Out-Null

# Herd's `php` is a .bat shim. Run the real php.exe so a started server can be stopped again.
function Resolve-PhpExe {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if (-not $cmd) { return $null }
    if ($cmd.Source -like '*.bat') {
        $m = [regex]::Match((Get-Content $cmd.Source -Raw), '"([^"]+php\.exe)"')
        if ($m.Success) { return $m.Groups[1].Value }
    }
    return $cmd.Source
}
$script:Php = Resolve-PhpExe

# Blizzard spec ids, read from this project's `specializations` table (external_spec_id).
$script:SpecNames = @{
    '250'='Blood Death Knight'; '251'='Frost Death Knight'; '252'='Unholy Death Knight'
    '577'='Havoc Demon Hunter'; '581'='Vengeance Demon Hunter'; '1480'='Devourer Demon Hunter'
    '102'='Balance Druid'; '103'='Feral Druid'; '104'='Guardian Druid'; '105'='Restoration Druid'
    '1467'='Devastation Evoker'; '1468'='Preservation Evoker'; '1473'='Augmentation Evoker'
    '253'='Beast Mastery Hunter'; '254'='Marksmanship Hunter'; '255'='Survival Hunter'
    '62'='Arcane Mage'; '63'='Fire Mage'; '64'='Frost Mage'
    '268'='Brewmaster Monk'; '269'='Windwalker Monk'; '270'='Mistweaver Monk'
    '65'='Holy Paladin'; '66'='Protection Paladin'; '70'='Retribution Paladin'
    '256'='Discipline Priest'; '257'='Holy Priest'; '258'='Shadow Priest'
    '259'='Assassination Rogue'; '260'='Outlaw Rogue'; '261'='Subtlety Rogue'
    '262'='Elemental Shaman'; '263'='Enhancement Shaman'; '264'='Restoration Shaman'
    '265'='Affliction Warlock'; '266'='Demonology Warlock'; '267'='Destruction Warlock'
    '71'='Arms Warrior'; '72'='Fury Warrior'; '73'='Protection Warrior'
}

# --- .env and settings -----------------------------------------------------

function Get-EnvValue([string]$key) {
    if (-not (Test-Path $script:EnvFile)) { return $null }
    foreach ($line in [IO.File]::ReadAllLines($script:EnvFile)) {
        $m = [regex]::Match($line, "^\s*$key\s*=\s*(.*)$")
        if ($m.Success) { return $m.Groups[1].Value.Trim().Trim('"').Trim("'") }
    }
    return $null
}

# Writes one key back into .env, keeping every other line as it was. UTF-8 without a BOM:
# a BOM would become part of the first key's name.
function Set-EnvValue([string]$key, [string]$value) {
    $value = $value -replace '\\', '/'
    $lines = [System.Collections.Generic.List[string]]::new()
    if (Test-Path $script:EnvFile) { $lines.AddRange([IO.File]::ReadAllLines($script:EnvFile)) }
    $new = "$key=`"$value`""
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^\s*$key\s*=") { $lines[$i] = $new; $found = $true }
    }
    if (-not $found) { $lines.Add($new) }
    [IO.File]::WriteAllText($script:EnvFile, ($lines -join "`r`n") + "`r`n", (New-Object System.Text.UTF8Encoding($false)))
}

function Load-Settings {
    $s = @{ MoveTo = $null; AutoSync = $true; MoveAfterArchive = $true; Ingested = @{} }
    if (Test-Path $script:SettingsFile) {
        try {
            $j = Get-Content $script:SettingsFile -Raw -Encoding UTF8 | ConvertFrom-Json
            if ($j.MoveTo) { $s.MoveTo = $j.MoveTo }
            if ($null -ne $j.AutoSync) { $s.AutoSync = [bool]$j.AutoSync }
            if ($null -ne $j.MoveAfterArchive) { $s.MoveAfterArchive = [bool]$j.MoveAfterArchive }
            if ($j.Ingested) { foreach ($p in $j.Ingested.PSObject.Properties) { $s.Ingested[$p.Name] = $p.Value } }
        } catch { }
    }
    return $s
}

function Save-Settings {
    $script:Settings | ConvertTo-Json -Depth 4 | Set-Content -Path $script:SettingsFile -Encoding UTF8
}

function Get-WowLogsDir {
    $p = Get-EnvValue 'WOW_COMBATLOG_PATH'
    if (-not $p) { return $null }
    if ($p -match '\.(txt|log)$') { $p = Split-Path $p -Parent }
    return ($p -replace '/', '\')
}

function Get-ArchiveDir {
    $p = Get-EnvValue 'ARENA_LOG_ARCHIVE_PATH'
    if (-not $p) { $p = Join-Path $script:Repo 'data\arena-logs' }
    return ($p -replace '/', '\')
}

$script:Settings = Load-Settings
if (-not $script:Settings.MoveTo) {
    $script:Settings.MoveTo = Join-Path (Split-Path (Get-ArchiveDir) -Parent) 'wow-logs'
}

# --- Activity log ----------------------------------------------------------

function Write-Activity([string]$text) {
    $line = '[{0:dd MMM HH:mm:ss}] {1}' -f (Get-Date), $text
    try { Add-Content -Path $script:ActivityFile -Value $line -Encoding UTF8 } catch { }
    if ($script:ActivityBox) { $script:ActivityBox.AppendText($line + "`r`n") }
}

function Set-Status([string]$text) {
    if ($script:StatusLabel) { $script:StatusLabel.Text = $text }
}

# --- Log files -------------------------------------------------------------

function Get-CombatLogs {
    $dir = Get-WowLogsDir
    if (-not $dir -or -not (Test-Path $dir)) { return @() }
    return @(Get-ChildItem -Path $dir -Filter 'WoWCombatLog*.txt' -File | Sort-Object LastWriteTime -Descending)
}

function Get-FileSig($f) { return "$($f.Length)|$($f.LastWriteTimeUtc.Ticks)" }

function Test-WowRunning {
    return [bool](Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Name -in @('Wow', 'WowT', 'WowB', 'WowClassic') })
}

# True when the last arena marker in the file's tail is ARENA_MATCH_END: the lobby is over.
# A tail with no marker at all means a round is in full swing (or there is no arena in it).
function Test-TailReady([string]$path) {
    $fs = [IO.File]::Open($path, 'Open', 'Read', 'ReadWrite, Delete')
    try {
        $len = [Math]::Min($fs.Length, 524288)
        $fs.Seek(-$len, 'End') | Out-Null
        $buf = New-Object byte[] $len
        $read = $fs.Read($buf, 0, $len)
        $text = [Text.Encoding]::ASCII.GetString($buf, 0, $read)
    } finally { $fs.Dispose() }
    $end = $text.LastIndexOf('ARENA_MATCH_END')
    return ($end -ge 0 -and $end -gt $text.LastIndexOf('ARENA_MATCH_START'))
}

function Test-FileFree([string]$path) {
    try { $fs = [IO.File]::Open($path, 'Open', 'ReadWrite', 'None'); $fs.Dispose(); return $true }
    catch { return $false }
}

# Copy, check the size, then delete the original - a cross-drive move is a copy anyway, and
# this way an interrupted move never leaves the only copy half-written.
function Move-CombatLog($f) {
    $dest = $script:Settings.MoveTo
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    $target = Join-Path $dest $f.Name
    $n = 2
    while (Test-Path $target) { $target = Join-Path $dest ("{0}-{1}{2}" -f $f.BaseName, $n++, $f.Extension) }
    $partial = "$target.partial"
    try {
        [IO.File]::Copy($f.FullName, $partial, $true)
        if ((Get-Item $partial).Length -ne $f.Length) { throw 'size mismatch after copy' }
        [IO.File]::Delete($f.FullName)
        [IO.File]::Move($partial, $target)
        $script:Settings.Ingested.Remove($f.Name)
        Save-Settings
        Write-Activity ("Moved {0} ({1:N0} MB) to {2}" -f $f.Name, ($f.Length / 1MB), $dest)
    } catch {
        if (Test-Path $partial) { Remove-Item $partial -Force -ErrorAction SilentlyContinue }
        Write-Activity "Could not move $($f.Name): $($_.Exception.Message) - will try again later."
    }
}

# --- Running artisan without freezing the window ----------------------------
# One step at a time; the UI timer polls the running process.

$script:Steps = New-Object System.Collections.Queue
$script:Running = $null
$script:ImportedThisRun = 0
$script:RetryAfter = [DateTime]::MinValue

# $data rides along on the step and comes back to $onDone, so the callbacks need no closures.
# (GetNewClosure() moves a scriptblock into its own module, where this script's functions and
# $script: variables are out of sight.)
function Add-Step([string]$label, [string]$arguments, [scriptblock]$onDone, $data = $null) {
    $script:Steps.Enqueue(@{ Label = $label; Args = $arguments; OnDone = $onDone; Data = $data })
}

# A hidden process with no console window. cmd does the redirection into a file, so there is
# no pipe to drain and nothing runs on a background thread.
function Start-Hidden([string]$exe, [string]$arguments, [string]$outFile = $null) {
    $psi = New-Object Diagnostics.ProcessStartInfo
    $psi.WorkingDirectory = $script:Repo
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    if ($outFile) {
        $psi.FileName = "$env:WINDIR\System32\cmd.exe"
        $psi.Arguments = "/d /c `"`"$exe`" $arguments > `"$outFile`" 2>&1`""
    } else {
        $psi.FileName = $exe
        $psi.Arguments = $arguments
    }
    return [Diagnostics.Process]::Start($psi)
}

function Start-NextStep {
    if ($script:Running -or $script:Steps.Count -eq 0) { return }
    $step = $script:Steps.Dequeue()
    $out = [IO.Path]::GetTempFileName()
    Write-Activity $step.Label
    Set-Status $step.Label
    $proc = Start-Hidden $script:Php ('artisan ' + $step.Args) $out
    $script:Running = @{ Proc = $proc; Out = $out; Step = $step }
}

function Poll-Step {
    if (-not $script:Running -or -not $script:Running.Proc.HasExited) { return }
    $r = $script:Running
    $script:Running = $null
    $r.Proc.WaitForExit()
    $output = Get-Content $r.Out -Raw -Encoding UTF8
    Remove-Item $r.Out -Force -ErrorAction SilentlyContinue
    $output = if ($output) { $output.Trim() } else { '' }
    foreach ($l in ($output -split "`r?`n")) { if ($l.Trim()) { Write-Activity ('    ' + $l.TrimEnd()) } }
    & $r.Step.OnDone ($r.Proc.ExitCode -eq 0) $output $r.Step.Data
    if ($script:Steps.Count -eq 0 -and -not $script:Running) { Set-Status (Get-IdleStatus) }
    Start-NextStep
}

function Get-IdleStatus {
    $dir = Get-WowLogsDir
    if (-not $script:Php) { return 'PHP not found on PATH - is Herd installed?' }
    if (-not $dir -or -not (Test-Path $dir)) { return 'WoW Logs folder not found - set it under Settings.' }
    $mode = if ($script:Settings.AutoSync) { 'Watching' } else { 'Auto-sync is off. Watching' }
    return "$mode $dir"
}

# --- The sync pass -----------------------------------------------------------

# $manual: the user pressed Sync now, so ignore the quiet period and re-read even unchanged
# files. Still never ingests a lobby that is in progress.
function Invoke-SyncPass([bool]$manual) {
    if ($script:Running -or $script:Steps.Count -gt 0) { return }
    if (-not $script:Php) { Set-Status (Get-IdleStatus); return }
    if (-not $manual -and (Get-Date) -lt $script:RetryAfter) { return }

    $files = Get-CombatLogs
    $wow = Test-WowRunning
    $newest = if ($files.Count) { $files[0].FullName } else { $null }
    $queued = 0

    foreach ($f in $files) {
        $sig = Get-FileSig $f
        $live = $wow -and $f.FullName -eq $newest
        if (-not $manual -and $script:Settings.Ingested[$f.Name] -eq $sig) { continue }
        if (-not $manual -and ((Get-Date) - $f.LastWriteTime).TotalSeconds -lt 20) { continue }
        if ($live -and -not (Test-TailReady $f.FullName)) {
            if ($manual) { Write-Activity "$($f.Name): a game is in progress - it will be read when it ends." }
            continue
        }

        # The signature is taken BEFORE reading: if the file grows while it is being read, it no
        # longer matches afterwards and is read again (already-archived games are skipped).
        Add-Step "Reading $($f.Name)" ("wow:ingest-combatlog `"" + $f.FullName + "`"") {
            param($ok, $output, $data)
            if ($ok) {
                $script:Settings.Ingested[$data.Name] = $data.Sig
                Save-Settings
                $m = [regex]::Match($output, 'Imported (\d+) match')
                if ($m.Success) { $script:ImportedThisRun += [int]$m.Groups[1].Value }
            } else {
                $script:RetryAfter = (Get-Date).AddMinutes(2)
                Write-Activity 'Reading the log failed. The usual cause is MySQL not running - start Herd. Trying again in 2 minutes.'
            }
        } @{ Name = $f.Name; Sig = $sig }
        $queued++
    }

    if ($queued -gt 0) {
        $script:ImportedThisRun = 0
        Add-Step 'Building reviews for new games' 'wow:sync --skip-ingest' {
            param($ok, $output, $data)
            # ImportedThisRun counts archive entries, which is rounds for a shuffle; the sync's own
            # "N new game(s)" line counts games.
            if ($script:ImportedThisRun -gt 0) {
                Load-Matches
                $m = [regex]::Match($output, '(\d+) new game')
                $what = if ($m.Success) { "$($m.Groups[1].Value) new game(s) reviewed" } else { 'New games archived' }
                Show-Balloon $what 'Open MindCollector Logs to see them.'
            }
            if (-not $ok) { Write-Activity 'Building reviews failed - the games are archived; reviews will build on the next sync.' }
            Invoke-MovePass
        }
        Start-NextStep
    } else {
        if ($manual) { Write-Activity 'Nothing new to read.' }
        Invoke-MovePass
    }
}

function Invoke-MovePass {
    if (-not $script:Settings.MoveAfterArchive) { return }
    $wow = Test-WowRunning
    $files = Get-CombatLogs
    $newest = if ($files.Count) { $files[0].FullName } else { $null }
    foreach ($f in $files) {
        if ($script:Settings.Ingested[$f.Name] -ne (Get-FileSig $f)) { continue }
        if ($wow -and $f.FullName -eq $newest) { continue }
        if (-not (Test-FileFree $f.FullName)) { continue }
        Move-CombatLog $f
    }
}

# --- Matches ---------------------------------------------------------------

function Format-Duration([int]$sec) { return '{0}:{1:D2}' -f [int][Math]::Floor($sec / 60), ($sec % 60) }

function Get-You($m) {
    return $m.units | Where-Object { $_.id -like 'Player-*' -and $_.affiliation -eq 1 } | Select-Object -First 1
}

function Short-Name([string]$n) { return ($n -split '-')[0] }

function Spec-Name([string]$id) {
    if ($script:SpecNames.ContainsKey($id)) { return $script:SpecNames[$id] }
    return "spec $id"
}

function Load-Matches {
    $script:MatchList.BeginUpdate()
    $script:MatchList.Items.Clear()
    $script:Games = @{}
    $dir = Join-Path (Get-ArchiveDir) 'metadata'
    if (-not (Test-Path $dir)) {
        $script:MatchList.EndUpdate()
        $script:MatchCount.Text = "No archive at $dir"
        return
    }

    # Only games read from your own combat log; the archive also holds 16 other people's
    # matches from the old wowarenalogs feed.
    $all = foreach ($file in Get-ChildItem -Path $dir -Filter '*.json' -File) {
        try {
            $m = Get-Content $file.FullName -Raw -Encoding UTF8 | ConvertFrom-Json
            if ($m.source -eq 'local-combatlog') { $m }
        } catch { }
    }

    $groups = $all | Group-Object { if ($_.lobbyId) { $_.lobbyId } else { $_.id } }
    $rows = foreach ($g in $groups) {
        $rounds = @($g.Group | Sort-Object { [int64]$_.startTime })
        [pscustomobject]@{ Key = $g.Name; Start = [int64]$rounds[0].startTime; Rounds = $rounds }
    }

    foreach ($row in ($rows | Sort-Object Start -Descending)) {
        $first = $row.Rounds[0]
        $you = Get-You $first
        $won = @($row.Rounds | Where-Object { $_.result -eq 3 }).Count
        $lost = @($row.Rounds | Where-Object { $_.result -eq 2 }).Count
        $result = if ($row.Rounds.Count -gt 1 -or $first.lobbyId) { "$won-$lost" }
                  elseif ($first.result -eq 3) { 'Won' } elseif ($first.result -eq 2) { 'Lost' } else { '?' }
        $played = [DateTimeOffset]::FromUnixTimeMilliseconds($row.Start).UtcDateTime
        $secs = ($row.Rounds | Measure-Object -Property durationInSeconds -Sum).Sum

        $item = New-Object System.Windows.Forms.ListViewItem($played.ToString('ddd dd MMM  HH:mm'))
        [void]$item.SubItems.Add(($first.startInfo.bracket -replace '^Rated ', ''))
        [void]$item.SubItems.Add($result)
        [void]$item.SubItems.Add($(if ($you) { "$(Spec-Name $you.spec) ($(Short-Name $you.name))" } else { '' }))
        [void]$item.SubItems.Add((Format-Duration $secs))
        [void]$item.SubItems.Add($(if ($first.playerTeamRating) { [string]$first.playerTeamRating } else { '' }))
        $item.Tag = $row.Key
        if ($result -eq 'Won' -or ($won -gt $lost)) { $item.ForeColor = [Drawing.Color]::FromArgb(134, 239, 172) }
        elseif ($result -eq 'Lost' -or ($lost -gt $won)) { $item.ForeColor = [Drawing.Color]::FromArgb(252, 165, 165) }
        [void]$script:MatchList.Items.Add($item)
        $script:Games[$row.Key] = $row.Rounds
    }
    $script:MatchList.EndUpdate()
    $script:MatchCount.Text = "$($rows.Count) game(s) in $(Get-ArchiveDir)"
}

function Show-MatchDetail([string]$key) {
    $rounds = $script:Games[$key]
    if (-not $rounds) { return }
    $sb = New-Object System.Text.StringBuilder
    $first = $rounds[0]
    $played = [DateTimeOffset]::FromUnixTimeMilliseconds([int64]$first.startTime).UtcDateTime
    [void]$sb.AppendLine("$($first.startInfo.bracket)   $($played.ToString('dddd dd MMMM yyyy, HH:mm'))")
    [void]$sb.AppendLine('')

    # Every player who appeared in any round, once.
    $players = @{}
    foreach ($r in $rounds) {
        foreach ($u in $r.units) {
            if ($u.id -like 'Player-*' -and $u.spec -ne '0' -and -not $players.ContainsKey($u.id)) { $players[$u.id] = $u }
        }
    }
    [void]$sb.AppendLine('Players')
    foreach ($u in ($players.Values | Sort-Object { if ($_.affiliation -eq 1) { 0 } else { 1 } }, name)) {
        $mark = if ($u.affiliation -eq 1) { '  (you)' } else { '' }
        [void]$sb.AppendLine(('  {0,-34} {1}{2}' -f $u.name, (Spec-Name $u.spec), $mark))
    }
    [void]$sb.AppendLine('')

    [void]$sb.AppendLine($(if ($rounds.Count -gt 1) { 'Rounds' } else { 'Result' }))
    $i = 0
    foreach ($r in $rounds) {
        $i++
        $res = if ($r.result -eq 3) { 'Won ' } elseif ($r.result -eq 2) { 'Lost' } else { '?   ' }
        $label = if ($r.sequenceNumber) { "Round $($r.sequenceNumber)" } else { "Game" }
        [void]$sb.AppendLine(('  {0,-9} {1}  {2}' -f $label, $res, (Format-Duration $r.durationInSeconds)))
    }
    [void]$sb.AppendLine('')
    [void]$sb.AppendLine("Archive id: $($first.id)$(if ($first.lobbyId) { "   lobby: $($first.lobbyId)" })")
    $script:DetailBox.Text = $sb.ToString()
}

# --- Local site, for the full review ---------------------------------------

function Test-PortOpen([int]$port) {
    $c = New-Object Net.Sockets.TcpClient
    try { $ar = $c.BeginConnect('127.0.0.1', $port, $null, $null); return ($ar.AsyncWaitHandle.WaitOne(300) -and $c.Connected) }
    catch { return $false } finally { $c.Close() }
}

function Open-Review {
    if (-not (Test-PortOpen $script:ServerPort)) {
        if (-not $script:Php) { Write-Activity 'PHP not found - cannot start the site.'; return }
        $script:Server = Start-Hidden $script:Php "-S 127.0.0.1:$($script:ServerPort) -t public"
        Write-Activity "Started the site on http://127.0.0.1:$($script:ServerPort)"
        Start-Sleep -Milliseconds 1200
    }
    Start-Process "http://127.0.0.1:$($script:ServerPort)/wow/game-review"
}

# --- Start with Windows ------------------------------------------------------

$script:StartupLink = Join-Path ([Environment]::GetFolderPath('Startup')) 'MindCollector Logs.lnk'

function Set-StartWithWindows([bool]$on) {
    if ($on) {
        $sh = New-Object -ComObject WScript.Shell
        $lnk = $sh.CreateShortcut($script:StartupLink)
        $lnk.TargetPath = "$env:WINDIR\System32\wscript.exe"
        $lnk.Arguments = "`"$(Join-Path $PSScriptRoot 'MindCollector Logs.vbs')`" -Minimized"
        $lnk.WorkingDirectory = $PSScriptRoot
        $lnk.Save()
    } elseif (Test-Path $script:StartupLink) {
        Remove-Item $script:StartupLink -Force
    }
}

# --- Window ----------------------------------------------------------------

$C = @{
    Bg     = [Drawing.Color]::FromArgb(9, 9, 13)
    Panel  = [Drawing.Color]::FromArgb(17, 17, 22)
    Raised = [Drawing.Color]::FromArgb(24, 24, 30)
    Line   = [Drawing.Color]::FromArgb(44, 44, 56)
    Ink    = [Drawing.Color]::FromArgb(240, 240, 242)
    Muted  = [Drawing.Color]::FromArgb(138, 138, 154)
    Gold   = [Drawing.Color]::FromArgb(200, 149, 44)
}
$fontUi = New-Object Drawing.Font('Segoe UI', 9.5)
$fontMono = New-Object Drawing.Font('Consolas', 9.5)

function New-Button([string]$text, [bool]$primary = $false) {
    $b = New-Object Windows.Forms.Button
    $b.Text = $text
    $b.FlatStyle = 'Flat'
    $b.AutoSize = $true
    $b.Padding = New-Object Windows.Forms.Padding(8, 2, 8, 2)
    $b.FlatAppearance.BorderColor = $(if ($primary) { $C.Gold } else { $C.Line })
    $b.BackColor = $(if ($primary) { $C.Gold } else { $C.Raised })
    $b.ForeColor = $(if ($primary) { $C.Bg } else { $C.Ink })
    $b.Cursor = 'Hand'
    return $b
}

# A gold "M" for the window and the tray.
$bmp = New-Object Drawing.Bitmap 32, 32
$g = [Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = 'AntiAlias'
$g.TextRenderingHint = 'AntiAlias'
$g.FillEllipse((New-Object Drawing.SolidBrush $C.Gold), 1, 1, 30, 30)
$g.DrawString('M', (New-Object Drawing.Font('Segoe UI', 14, [Drawing.FontStyle]::Bold)), (New-Object Drawing.SolidBrush $C.Bg), 3, 3)
$g.Dispose()
$appIcon = [Drawing.Icon]::FromHandle($bmp.GetHicon())

$form = New-Object Windows.Forms.Form
$form.Text = 'MindCollector Logs'
$form.Icon = $appIcon
$form.Size = New-Object Drawing.Size(980, 640)
$form.MinimumSize = New-Object Drawing.Size(720, 460)
$form.StartPosition = 'CenterScreen'
$form.BackColor = $C.Bg
$form.ForeColor = $C.Ink
$form.Font = $fontUi

# Header
$header = New-Object Windows.Forms.Panel
$header.Dock = 'Top'; $header.Height = 70; $header.BackColor = $C.Panel; $header.Padding = New-Object Windows.Forms.Padding(16, 10, 16, 8)
$title = New-Object Windows.Forms.Label
$title.Text = 'MindCollector Logs'; $title.ForeColor = $C.Gold; $title.AutoSize = $true
$title.Font = New-Object Drawing.Font('Segoe UI Semibold', 14); $title.Location = New-Object Drawing.Point(14, 8)
$script:StatusLabel = New-Object Windows.Forms.Label
$script:StatusLabel.ForeColor = $C.Muted; $script:StatusLabel.AutoSize = $true; $script:StatusLabel.Location = New-Object Drawing.Point(16, 42)
$headerButtons = New-Object Windows.Forms.FlowLayoutPanel
$headerButtons.Dock = 'Right'; $headerButtons.AutoSize = $true; $headerButtons.WrapContents = $false; $headerButtons.Padding = New-Object Windows.Forms.Padding(0, 8, 0, 0)
$btnSync = New-Button 'Sync now' $true
$btnOpen = New-Button 'Open Match Review'
$headerButtons.Controls.AddRange(@($btnOpen, $btnSync))
$header.Controls.AddRange(@($headerButtons, $title, $script:StatusLabel))

# Nav
$nav = New-Object Windows.Forms.FlowLayoutPanel
$nav.Dock = 'Top'; $nav.Height = 40; $nav.BackColor = $C.Bg; $nav.Padding = New-Object Windows.Forms.Padding(12, 6, 12, 0)
$body = New-Object Windows.Forms.Panel
$body.Dock = 'Fill'; $body.Padding = New-Object Windows.Forms.Padding(16, 4, 16, 16)

# Matches page
$pageMatches = New-Object Windows.Forms.Panel
$pageMatches.Dock = 'Fill'
$split = New-Object Windows.Forms.SplitContainer
$split.Dock = 'Fill'; $split.Orientation = 'Vertical'; $split.BackColor = $C.Line; $split.SplitterWidth = 4
$script:MatchList = New-Object Windows.Forms.ListView
$script:MatchList.Dock = 'Fill'; $script:MatchList.View = 'Details'; $script:MatchList.FullRowSelect = $true
$script:MatchList.MultiSelect = $false; $script:MatchList.HideSelection = $false; $script:MatchList.BorderStyle = 'None'
$script:MatchList.BackColor = $C.Panel; $script:MatchList.ForeColor = $C.Ink
foreach ($col in @(@('Played', 125), @('Bracket', 90), @('Result', 52), @('You', 190), @('Length', 52), @('Rating', 52))) {
    [void]$script:MatchList.Columns.Add($col[0], $col[1])
}
$script:DetailBox = New-Object Windows.Forms.TextBox
$script:DetailBox.Dock = 'Fill'; $script:DetailBox.Multiline = $true; $script:DetailBox.ReadOnly = $true; $script:DetailBox.ScrollBars = 'Vertical'
$script:DetailBox.BackColor = $C.Raised; $script:DetailBox.ForeColor = $C.Ink; $script:DetailBox.Font = $fontMono; $script:DetailBox.BorderStyle = 'None'
$script:DetailBox.Text = 'Pick a game to see who was in it and how each round went.'
$split.Panel1.Controls.Add($script:MatchList)
$split.Panel2.Controls.Add($script:DetailBox)
$script:MatchCount = New-Object Windows.Forms.Label
$script:MatchCount.Dock = 'Bottom'; $script:MatchCount.Height = 24; $script:MatchCount.ForeColor = $C.Muted; $script:MatchCount.TextAlign = 'MiddleLeft'
$pageMatches.Controls.AddRange(@($split, $script:MatchCount))

# Activity page
$pageActivity = New-Object Windows.Forms.Panel
$pageActivity.Dock = 'Fill'
$script:ActivityBox = New-Object Windows.Forms.TextBox
$script:ActivityBox.Dock = 'Fill'; $script:ActivityBox.Multiline = $true; $script:ActivityBox.ReadOnly = $true; $script:ActivityBox.ScrollBars = 'Both'; $script:ActivityBox.WordWrap = $false
$script:ActivityBox.BackColor = $C.Panel; $script:ActivityBox.ForeColor = $C.Ink; $script:ActivityBox.Font = $fontMono; $script:ActivityBox.BorderStyle = 'None'
$pageActivity.Controls.Add($script:ActivityBox)

# Settings page
$pageSettings = New-Object Windows.Forms.Panel
$pageSettings.Dock = 'Fill'; $pageSettings.AutoScroll = $true
$grid = New-Object Windows.Forms.TableLayoutPanel
$grid.Dock = 'Top'; $grid.AutoSize = $true; $grid.ColumnCount = 3; $grid.Padding = New-Object Windows.Forms.Padding(0, 8, 0, 0)
[void]$grid.ColumnStyles.Add((New-Object Windows.Forms.ColumnStyle('Absolute', 190)))
[void]$grid.ColumnStyles.Add((New-Object Windows.Forms.ColumnStyle('Percent', 100)))
[void]$grid.ColumnStyles.Add((New-Object Windows.Forms.ColumnStyle('AutoSize')))

function Add-FolderRow([string]$label, [string]$hint, [string]$value) {
    $l = New-Object Windows.Forms.Label
    $l.Text = $label; $l.AutoSize = $true; $l.Margin = New-Object Windows.Forms.Padding(0, 10, 8, 0)
    $t = New-Object Windows.Forms.TextBox
    $t.Text = $value; $t.Dock = 'Fill'; $t.BackColor = $C.Raised; $t.ForeColor = $C.Ink; $t.BorderStyle = 'FixedSingle'; $t.Margin = New-Object Windows.Forms.Padding(0, 6, 8, 0)
    $b = New-Button 'Browse...'
    $b.Margin = New-Object Windows.Forms.Padding(0, 4, 0, 0)
    $b.Tag = $t
    $b.Add_Click({
        $box = $this.Tag
        $d = New-Object Windows.Forms.FolderBrowserDialog
        if ($box.Text -and (Test-Path $box.Text)) { $d.SelectedPath = $box.Text }
        if ($d.ShowDialog() -eq 'OK') { $box.Text = $d.SelectedPath }
    })
    $h = New-Object Windows.Forms.Label
    $h.Text = $hint; $h.AutoSize = $true; $h.ForeColor = $C.Muted; $h.Margin = New-Object Windows.Forms.Padding(0, 2, 0, 6)
    $grid.Controls.Add($l); $grid.Controls.Add($t); $grid.Controls.Add($b)
    $grid.Controls.Add((New-Object Windows.Forms.Label)); $grid.Controls.Add($h); $grid.Controls.Add((New-Object Windows.Forms.Label))
    return $t
}

$txtWow = Add-FolderRow "WoW's Logs folder" 'Where WoW writes WoWCombatLog-*.txt. Saved to .env as WOW_COMBATLOG_PATH.' ((Get-WowLogsDir) -as [string])
$txtArchive = Add-FolderRow 'Game archive' 'Where each game is stored once read (raw/ + metadata/). Saved to .env as ARENA_LOG_ARCHIVE_PATH. Changing it does not move games already there.' (Get-ArchiveDir)
$txtMove = Add-FolderRow 'Move WoW logs to' "Where a combat log goes once all of it is archived, so WoW's folder stays empty." $script:Settings.MoveTo

function New-Check([string]$text, [bool]$checked) {
    $c = New-Object Windows.Forms.CheckBox
    $c.Text = $text; $c.Checked = $checked; $c.AutoSize = $true; $c.Margin = New-Object Windows.Forms.Padding(0, 8, 0, 0)
    $grid.Controls.Add((New-Object Windows.Forms.Label)); $grid.Controls.Add($c); $grid.Controls.Add((New-Object Windows.Forms.Label))
    return $c
}
$chkAuto = New-Check 'Read new games automatically when an arena ends' $script:Settings.AutoSync
$chkMove = New-Check "Move each combat log out of WoW's folder once it is archived (after WoW closes)" $script:Settings.MoveAfterArchive
$chkStartup = New-Check 'Start MindCollector Logs when Windows starts (in the tray)' (Test-Path $script:StartupLink)

$btnSave = New-Button 'Save settings' $true
$btnSave.Margin = New-Object Windows.Forms.Padding(0, 16, 0, 0)
$grid.Controls.Add((New-Object Windows.Forms.Label)); $grid.Controls.Add($btnSave); $grid.Controls.Add((New-Object Windows.Forms.Label))
$pageSettings.Controls.Add($grid)

$btnSave.Add_Click({
    try {
        if ($txtWow.Text -and -not (Test-Path $txtWow.Text)) { throw "WoW's Logs folder does not exist: $($txtWow.Text)" }
        $archiveChanged = (Get-ArchiveDir) -ne $txtArchive.Text
        if ($txtWow.Text -ne (Get-WowLogsDir)) { Set-EnvValue 'WOW_COMBATLOG_PATH' $txtWow.Text }
        if ($archiveChanged) {
            New-Item -ItemType Directory -Force -Path $txtArchive.Text | Out-Null
            Set-EnvValue 'ARENA_LOG_ARCHIVE_PATH' $txtArchive.Text
        }
        $script:Settings.MoveTo = $txtMove.Text
        $script:Settings.AutoSync = $chkAuto.Checked
        $script:Settings.MoveAfterArchive = $chkMove.Checked
        Save-Settings
        Set-StartWithWindows $chkStartup.Checked
        Write-Activity 'Settings saved.'
        Set-Status (Get-IdleStatus)
        if ($archiveChanged) { Load-Matches }
    } catch {
        [Windows.Forms.MessageBox]::Show($_.Exception.Message, 'MindCollector Logs') | Out-Null
    }
})

# Nav buttons switch pages
$pages = [ordered]@{ 'Matches' = $pageMatches; 'Activity' = $pageActivity; 'Settings' = $pageSettings }
$navButtons = @{}
function Show-Page([string]$name) {
    foreach ($k in $pages.Keys) {
        $pages[$k].Visible = ($k -eq $name)
        $navButtons[$k].ForeColor = $(if ($k -eq $name) { $C.Gold } else { $C.Muted })
        $navButtons[$k].FlatAppearance.BorderColor = $(if ($k -eq $name) { $C.Gold } else { $C.Bg })
    }
}
foreach ($k in $pages.Keys) {
    $b = New-Object Windows.Forms.Button
    $b.Text = $k; $b.FlatStyle = 'Flat'; $b.AutoSize = $true; $b.BackColor = $C.Bg; $b.Cursor = 'Hand'
    $b.FlatAppearance.BorderSize = 1
    $b.Tag = $k
    $b.Add_Click({ Show-Page $this.Tag })
    $navButtons[$k] = $b
    $nav.Controls.Add($b)
    $body.Controls.Add($pages[$k])
}

$form.Controls.AddRange(@($body, $nav, $header))

# Tray
$script:Tray = New-Object Windows.Forms.NotifyIcon
$script:Tray.Icon = $appIcon
$script:Tray.Text = 'MindCollector Logs'
$script:Tray.Visible = $true
$menu = New-Object Windows.Forms.ContextMenuStrip
[void]$menu.Items.Add('Open', $null, { $form.Show(); $form.WindowState = 'Normal'; $form.Activate() })
[void]$menu.Items.Add('Sync now', $null, { Invoke-SyncPass $true })
[void]$menu.Items.Add('Open Match Review', $null, { Open-Review })
[void]$menu.Items.Add('-')
[void]$menu.Items.Add('Exit', $null, { $script:Exiting = $true; [Windows.Forms.Application]::Exit() })
$script:Tray.ContextMenuStrip = $menu
$script:Tray.Add_DoubleClick({ $form.Show(); $form.WindowState = 'Normal'; $form.Activate() })

function Show-Balloon([string]$title, [string]$text) {
    $script:Tray.BalloonTipTitle = $title
    $script:Tray.BalloonTipText = $text
    $script:Tray.ShowBalloonTip(5000)
}

# Closing the window keeps it watching in the tray; Exit in the tray menu quits.
$script:Exiting = $false
$form.Add_FormClosing({
    param($s, $e)
    if (-not $script:Exiting -and $e.CloseReason -eq 'UserClosing') {
        $e.Cancel = $true
        $form.Hide()
        if (-not $script:ToldAboutTray) {
            Show-Balloon 'Still watching' 'MindCollector Logs keeps running in the tray. Right-click it to exit.'
            $script:ToldAboutTray = $true
        }
    }
})

$btnSync.Add_Click({ Invoke-SyncPass $true })
$btnOpen.Add_Click({ Open-Review })
$script:MatchList.Add_SelectedIndexChanged({
    if ($script:MatchList.SelectedItems.Count) { Show-MatchDetail $script:MatchList.SelectedItems[0].Tag }
})

# One timer: poll a running step every second, look at the Logs folder every 10.
$script:Tick = 0
$timer = New-Object Windows.Forms.Timer
$timer.Interval = 1000
$timer.Add_Tick({
    try {
        Poll-Step
        $script:Tick++
        if ($script:Tick % 10 -eq 0 -and $script:Settings.AutoSync) { Invoke-SyncPass $false }
    } catch {
        $script:Running = $null
        Write-Activity "Error: $($_.Exception.Message)"
    }
})

$form.Add_Shown({
    if (-not $script:Laidout) { $split.SplitterDistance = [int]($split.Width * 0.64); $script:Laidout = $true }
})

# The message loop runs without owning the form, so the window can come and go while the
# tray icon keeps watching. Started with -Minimized (Windows startup), the window stays hidden.
Show-Page 'Matches'
Set-Status (Get-IdleStatus)
Write-Activity "Started. Repo: $($script:Repo)"
Load-Matches
$timer.Start()
if (-not $Minimized) { $form.Show() }

try {
    [Windows.Forms.Application]::Run()
} finally {
    $timer.Stop()
    $script:Tray.Visible = $false
    $script:Tray.Dispose()
    if ($script:Server -and -not $script:Server.HasExited) { Stop-Process -Id $script:Server.Id -Force -ErrorAction SilentlyContinue }
    $script:Mutex.ReleaseMutex()
}

# ---------------------------------------------------------------------------
#  MindCollector Dev
#
#  A small window (and tray icon) over the arena-log workflow:
#    1. Watches WoW's Logs folder for WoWCombatLog-*.txt.
#    2. When a game has finished, runs `php artisan wow:ingest-combatlog` on that
#       file, then `php artisan wow:sync --skip-ingest` to build the reviews.
#    3. Once a log is archived and WoW has let go of it, moves the log out of
#       WoW's folder into the "move logs to" folder (default D:\MindCollector\wow-logs).
#    4. Lists your archived games, each with a card (wow:game-cards) shown as a page in
#       Windows' built-in browser control, and lets you set the folders without editing .env.
#    5. While you play, a small always-on-top panel shows the game you are in and takes
#       notes; Ctrl+Shift+M marks a moment from inside WoW. See "Live" below.
#    6. A character picker (your characters, found from the games) filters the list, and the
#       Improve page shows what that character has to work on (wow:game-cards writes it).
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
    [System.Windows.Forms.MessageBox]::Show('MindCollector Dev is already running - look for it in the tray.', 'MindCollector Dev') | Out-Null
    exit
}

# --- Paths -----------------------------------------------------------------

$script:Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$script:EnvFile = Join-Path $script:Repo '.env'
$script:StateDir = Join-Path $env:APPDATA 'MindCollector'
$script:SettingsFile = Join-Path $script:StateDir 'logs-app.json'
$script:CardsDir = Join-Path $script:StateDir 'cards'
$script:LegacyCardsFile = Join-Path $script:StateDir 'game-cards.json'
$script:NotesFile = Join-Path $script:StateDir 'notes.json'
$script:Cards = @{}
$script:Experience = @{}
$script:ActivityFile = Join-Path $script:StateDir 'logs-app.log'
$script:ServerPort = 8321
New-Item -ItemType Directory -Force -Path $script:StateDir | Out-Null
# One previous activity log is kept as .old once the file passes 1 MB.
if ((Test-Path $script:ActivityFile) -and (Get-Item $script:ActivityFile).Length -gt 1MB) {
    Move-Item $script:ActivityFile "$($script:ActivityFile).old" -Force -ErrorAction SilentlyContinue
}

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
    # Character: the full name (Name-Realm-Region) the Matches list and the Improve page show; '' is all.
    # BackupTo: a second copy of the archive and notes ('' is off); see Invoke-BackupPass.
    $s = @{ MoveTo = $null; BackupTo = ''; AutoSync = $true; MoveAfterArchive = $true; LivePanel = $true; Character = ''; Ingested = @{} }
    if (Test-Path $script:SettingsFile) {
        try {
            $j = Get-Content $script:SettingsFile -Raw -Encoding UTF8 | ConvertFrom-Json
            if ($j.MoveTo) { $s.MoveTo = $j.MoveTo }
            if ($j.BackupTo) { $s.BackupTo = [string]$j.BackupTo }
            if ($null -ne $j.AutoSync) { $s.AutoSync = [bool]$j.AutoSync }
            if ($null -ne $j.MoveAfterArchive) { $s.MoveAfterArchive = [bool]$j.MoveAfterArchive }
            if ($null -ne $j.LivePanel) { $s.LivePanel = [bool]$j.LivePanel }
            if ($null -ne $j.Character) { $s.Character = [string]$j.Character }
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
    if ($script:ActivityBox) {
        # Keep the box to its last ~200 KB; the full history is in the file.
        if ($script:ActivityBox.TextLength -gt 400000) { $script:ActivityBox.Text = $script:ActivityBox.Text.Substring(200000) }
        $script:ActivityBox.AppendText($line + "`r`n")
    }
}

# An error the script does not catch stops the app, and the .vbs launcher has no console to show
# it, so it used to vanish without a word (2026-10-03). Say why in the activity log first.
trap {
    Write-Activity "Stopped by an error: $($_.Exception.Message) (line $($_.InvocationInfo.ScriptLineNumber))"
    break
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
    return [bool](Get-Process -Name 'Wow', 'WowT', 'WowB', 'WowClassic' -ErrorAction SilentlyContinue)
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
$script:Warned = @{}

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
    $script:Running = @{ Proc = $proc; Out = $out; Step = $step; Started = Get-Date }
}

# Longest a job may run. Reading a session's log is the slow one (a 292 MB log is a few minutes);
# anything past this is hung, and one hung job would otherwise block every job after it.
$script:StepLimitMinutes = 15

function Poll-Step {
    if (-not $script:Running) { return }
    if (-not $script:Running.Proc.HasExited) {
        if (((Get-Date) - $script:Running.Started).TotalMinutes -lt $script:StepLimitMinutes) { return }
        # cmd runs php under it: end the whole tree, then carry on as a failed step.
        $kill = Start-Hidden "$env:WINDIR\System32\taskkill.exe" "/T /F /PID $($script:Running.Proc.Id)"
        $kill.WaitForExit(5000) | Out-Null
        Write-Activity "Stopped '$($script:Running.Step.Label)' after $($script:StepLimitMinutes) minutes without finishing."
        $script:Running.Proc.WaitForExit(5000) | Out-Null
    }
    $r = $script:Running
    $script:Running = $null
    $output = $null
    try { $output = Get-Content $r.Out -Raw -Encoding UTF8 } catch { }
    Remove-Item $r.Out -Force -ErrorAction SilentlyContinue
    $output = if ($output) { $output.Trim() } else { '' }
    foreach ($l in ($output -split "`r?`n")) { if ($l.Trim()) { Write-Activity ('    ' + $l.TrimEnd()) } }
    $ok = $r.Proc.HasExited -and $r.Proc.ExitCode -eq 0
    & $r.Step.OnDone $ok $output $r.Step.Data
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
                # A new log format or patch (CombatLogIngestService::headerWarning): once a run.
                foreach ($w in [regex]::Matches($output, 'WARNING: ([^\r\n]+)')) {
                    $text = $w.Groups[1].Value
                    if (-not $script:Warned.ContainsKey($text)) {
                        $script:Warned[$text] = $true
                        Show-Balloon 'Check before trusting new games' $text
                    }
                }
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
                Show-Balloon $what 'Open MindCollector Dev to see them.'
            }
            if (-not $ok) { Write-Activity 'Building reviews failed - the games are archived; reviews will build on the next sync.' }
            Invoke-MovePass
            Invoke-BackupPass
        }
        Add-CardsStep
        Add-PushStep
        Start-NextStep
    } else {
        if ($manual) {
            Write-Activity 'Nothing new to read.'
            # Sync now still sends what the website does not have yet (a first upload goes 150 at a time).
            Add-PushStep
            Start-NextStep
        }
        Invoke-MovePass
    }
}

# --- Game cards --------------------------------------------------------------
# `wow:game-cards` writes one card per game (who you played and their experience, how each
# death happened, goes, defensives, interrupts, and for a loss what the rules flag). The sync has
# already measured everything; this only formats it, so it is quick.

# Each card is its own file in cards\ beside a small index.json, and only cards whose game changed
# are drawn again (see BuildGameCards). The app reads the index and opens a card's file on click.
# Your games to the website (/wow/coach), once there is a key (MINDCOLLECTOR_KEY, from Settings).
# The server measures and draws them itself; this only sends what it has not had yet.
function Add-PushStep {
    if (-not (Get-EnvValue 'MINDCOLLECTOR_KEY')) { return }
    Add-Step 'Sending games to the website' 'wow:push-rounds' {
        param($ok, $output, $data)
        if (-not $ok) { Write-Activity 'Sending games to the website failed - they will go with the next sync.' }
    }
}

function Add-CardsStep {
    Add-Step 'Updating game cards' ("wow:game-cards --dir=`"" + $script:CardsDir + "`" --notes=`"" + $script:NotesFile + "`"") {
        param($ok, $output, $data)
        if ($ok) {
            Load-Cards; Show-SelectedMatch
            if ($script:PageImprove -and $script:PageImprove.Visible) { Show-Improve }
            if ($script:PageComps -and $script:PageComps.Visible) { Update-CompList; Show-SelectedComp }
            if ($script:PageClasses -and $script:PageClasses.Visible) { Update-ClassList; Show-SelectedClass }
        }
    }
}

function Load-Cards {
    $script:Cards = @{}
    # The Improve page of each character you have played, by full name (ImprovementService).
    $script:ImproveFiles = @{}
    $index = Join-Path $script:CardsDir 'index.json'
    $script:CardsStale = -not (Test-Path $index)
    if ($script:CardsStale) { return }
    try {
        $j = Get-Content $index -Raw -Encoding UTF8 | ConvertFrom-Json
        foreach ($p in $j.games.PSObject.Properties) { $script:Cards[$p.Name] = Join-Path $script:CardsDir "$($p.Name).html" }
        if ($j.characters) {
            foreach ($p in $j.characters.PSObject.Properties) {
                $script:ImproveFiles[$p.Name] = [pscustomobject]@{ File = (Join-Path $script:CardsDir $p.Value.file); Games = [int]$p.Value.games }
            }
        }
        # The comp library: one page per enemy comp (CompLibraryService), with which of your
        # characters met it and how often.
        $script:Comps = New-Object System.Collections.ArrayList
        if ($j.comps) {
            foreach ($p in $j.comps.PSObject.Properties) {
                $chars = @{}
                if ($p.Value.characters) { foreach ($q in $p.Value.characters.PSObject.Properties) { $chars[$q.Name] = [pscustomobject]@{ Games = [int]$q.Value.games; Won = [int]$q.Value.won; Lost = [int]$q.Value.lost } } }
                [void]$script:Comps.Add([pscustomobject]@{
                    Key = $p.Name; Nick = [string]$p.Value.nick; Name = [string]$p.Value.name
                    Games = [int]$p.Value.games; Won = [int]$p.Value.won; Lost = [int]$p.Value.lost; Last = [string]$p.Value.last
                    Characters = $chars; File = (Join-Path $script:CardsDir $p.Value.file)
                })
            }
        }
        # The Classes page: for each spec met, its highest-rated and most experienced player
        # (ClassLibraryService), one page each.
        $script:Classes = New-Object System.Collections.ArrayList
        if ($j.classes) {
            foreach ($p in $j.classes.PSObject.Properties) {
                [void]$script:Classes.Add([pscustomobject]@{
                    Key = $p.Name; Name = [string]$p.Value.name; Spec = [string]$p.Value.spec; Class = [string]$p.Value.class
                    Color = [string]$p.Value.color; Why = [string]$p.Value.why
                    Games = [int]$p.Value.games; Won = [int]$p.Value.won; Lost = [int]$p.Value.lost
                    File = (Join-Path $script:CardsDir $p.Value.file)
                })
            }
        }
        foreach ($p in $j.experience.PSObject.Properties) { $script:Experience[$p.Name] = $p.Value }
        # The one-file-for-every-card version this replaced (15 MB by 2026-10-02).
        if (Test-Path $script:LegacyCardsFile) { Remove-Item $script:LegacyCardsFile -Force -ErrorAction SilentlyContinue }
    } catch {
        Write-Activity "Could not read the game cards: $($_.Exception.Message)"
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

# --- Backup ------------------------------------------------------------------
# A second copy of the game archive and notes.json, in a folder the user picks (another drive, or
# a cloud-synced folder). Once a combat log is gone the archive cannot be rebuilt, and until
# 4 Oct 2026 it lived on one drive only. Robocopy copies only new and changed files and never
# deletes from the copy, so a game lost here survives there. It runs hidden, in the background,
# and Poll-Backup reports how it went.
$script:BackupProc = $null

function Invoke-BackupPass {
    $to = $script:Settings.BackupTo
    if (-not $to -or $script:BackupProc) { return }
    $to = $to.TrimEnd('\')
    $from = (Get-ArchiveDir).TrimEnd('\')
    $dest = Join-Path $to 'arena-logs'
    try {
        New-Item -ItemType Directory -Force -Path $dest | Out-Null
        if (Test-Path $script:NotesFile) { Copy-Item $script:NotesFile (Join-Path $to 'notes.json') -Force }
    } catch {
        Write-Activity "Backup to $to failed: $($_.Exception.Message)"
        return
    }
    $script:BackupProc = Start-Hidden "$env:WINDIR\System32\robocopy.exe" ("`"$from`" `"$dest`" /E /XO /R:1 /W:1 /NP /NFL /NDL /NJH /NJS")
}

function Poll-Backup {
    $p = $script:BackupProc
    if (-not $p -or -not $p.HasExited) { return }
    $script:BackupProc = $null
    # Robocopy's exit code: 0 nothing new, 1 copied, 2-7 extra or mismatched files (still a copy), 8+ failed.
    if ($p.ExitCode -ge 8) { Write-Activity "Backup to $($script:Settings.BackupTo) failed (robocopy exit code $($p.ExitCode))." }
    elseif ($p.ExitCode -band 1) { Write-Activity "Backed up new games to $($script:Settings.BackupTo)." }
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
    $dir = Join-Path (Get-ArchiveDir) 'metadata'
    if (-not (Test-Path $dir)) {
        $script:MatchList.Items.Clear()
        $script:Games = @{}
        $script:MatchCount.Text = "No archive at $dir"
        return
    }

    # Only games read from your own combat log; the archive also holds 16 other people's
    # matches from the old wowarenalogs feed. An archive file is written once, so each is parsed
    # once and kept (by name and write time): re-reading all of them froze the window for 1.3s
    # after every sync at 679 rounds (2026-10-02), and that grew with every game.
    if (-not $script:MetaCache) { $script:MetaCache = @{} }
    $files = [IO.Directory]::GetFiles($dir, '*.json')
    $seen = @{}
    $all = New-Object System.Collections.ArrayList
    foreach ($path in $files) {
        $k = "$([IO.Path]::GetFileName($path))|$([IO.File]::GetLastWriteTimeUtc($path).Ticks)"
        $seen[$k] = $true
        if (-not $script:MetaCache.ContainsKey($k)) {
            $m = $null
            try { $m = [IO.File]::ReadAllText($path, [Text.Encoding]::UTF8) | ConvertFrom-Json } catch { }
            $script:MetaCache[$k] = $(if ($m -and $m.source -eq 'local-combatlog') { $m } else { $false })
        }
        if ($script:MetaCache[$k]) { [void]$all.Add($script:MetaCache[$k]) }
    }
    # Forget files that have gone (wow:forget-games) or been rewritten.
    foreach ($k in @($script:MetaCache.Keys)) { if (-not $seen.ContainsKey($k)) { $script:MetaCache.Remove($k) } }

    # Nothing new on disk, the same character picked, and the list already drawn: leave it, and the
    # selection, as they are.
    $listKey = (($seen.Keys | Sort-Object) -join ';') + '|' + $script:Settings.Character
    if ($script:MatchList.Items.Count -and $listKey -eq $script:ListKey) { return }
    $script:ListKey = $listKey

    # Rounds grouped into games by lobby (a shuffle) or by match, with plain loops: Group-Object,
    # Sort-Object and Measure-Object took most of a second over 343 games.
    $byGame = @{}
    foreach ($m in $all) {
        $key = if ($m.lobbyId) { [string]$m.lobbyId } else { [string]$m.id }
        if (-not $byGame.ContainsKey($key)) { $byGame[$key] = New-Object System.Collections.ArrayList }
        [void]$byGame[$key].Add($m)
    }
    # Sort-Object, not [Array]::Sort: PowerShell converts the arrays to call that and it sorts the
    # copies, leaving the list in the order the files came off disk (2026-10-02).
    $rows = foreach ($key in $byGame.Keys) {
        $rounds = @($byGame[$key] | Sort-Object { [int64]$_.startTime })
        [pscustomobject]@{ Key = $key; Start = [int64]$rounds[0].startTime; Rounds = $rounds; You = (Get-You $rounds[0]) }
    }
    $allRows = @($rows | Sort-Object Start -Descending)

    # Your characters: the logging character of each game (affiliation 1), so nothing has to be set
    # up. Most-played first.
    $chars = @{}
    foreach ($row in $allRows) {
        if (-not $row.You) { continue }
        $n = [string]$row.You.name
        if (-not $chars.ContainsKey($n)) { $chars[$n] = [pscustomobject]@{ Name = $n; Games = 0; Spec = (Spec-Name $row.You.spec) } }
        $chars[$n].Games++
    }
    $script:MyChars = @($chars.Values | Sort-Object Games -Descending)
    if ($script:Settings.Character -and -not $chars.ContainsKey($script:Settings.Character)) { $script:Settings.Character = '' }
    Update-CharacterPicker

    $pick = $script:Settings.Character
    $sorted = if ($pick) { @($allRows | Where-Object { $_.You -and $_.You.name -eq $pick }) } else { $allRows }

    $selected = if ($script:MatchList.SelectedItems.Count) { [string]$script:MatchList.SelectedItems[0].Tag } else { $null }
    $won = [Drawing.Color]::FromArgb(134, 239, 172)
    $lostColor = [Drawing.Color]::FromArgb(252, 165, 165)
    $items = New-Object System.Collections.ArrayList
    $script:Games = @{}
    foreach ($row in $sorted) {
        $first = $row.Rounds[0]
        $you = $row.You
        $w = 0; $l = 0; $secs = 0
        foreach ($r in $row.Rounds) {
            if ($r.result -eq 3) { $w++ } elseif ($r.result -eq 2) { $l++ }
            $secs += [int]$r.durationInSeconds
        }
        $result = if ($row.Rounds.Count -gt 1 -or $first.lobbyId) { "$w-$l" }
                  elseif ($first.result -eq 3) { 'Won' } elseif ($first.result -eq 2) { 'Lost' } else { '?' }
        $played = [DateTimeOffset]::FromUnixTimeMilliseconds($row.Start).UtcDateTime

        $item = New-Object System.Windows.Forms.ListViewItem($played.ToString('ddd dd MMM  HH:mm'))
        [void]$item.SubItems.Add(($first.startInfo.bracket -replace '^Rated ', ''))
        [void]$item.SubItems.Add($result)
        [void]$item.SubItems.Add($(if ($you) { "$(Spec-Name $you.spec) ($(Short-Name $you.name))" } else { '' }))
        [void]$item.SubItems.Add((Format-Duration $secs))
        [void]$item.SubItems.Add($(if ($first.playerTeamRating) { [string]$first.playerTeamRating } else { '' }))
        $item.Tag = $row.Key
        if ($result -eq 'Won' -or ($w -gt $l)) { $item.ForeColor = $won }
        elseif ($result -eq 'Lost' -or ($l -gt $w)) { $item.ForeColor = $lostColor }
        [void]$items.Add($item)
        $script:Games[$row.Key] = $row.Rounds
    }

    $script:MatchList.BeginUpdate()
    $script:MatchList.Items.Clear()
    $script:MatchList.Items.AddRange([Windows.Forms.ListViewItem[]]$items.ToArray())
    # Keep the game you were reading selected across a refresh.
    if ($selected) { foreach ($it in $script:MatchList.Items) { if ($it.Tag -eq $selected) { $it.Selected = $true; break } } }
    $script:MatchList.EndUpdate()
    $script:MatchCount.Text = if ($pick) { "$($sorted.Count) of $($allRows.Count) game(s), $(Short-Name $pick) only, in $(Get-ArchiveDir)" } else { "$($sorted.Count) game(s) in $(Get-ArchiveDir)" }
    # Archive times are WoW's wall clock stored as UTC, the same clock notes are stamped with. The
    # newest game of any character: a "for next game" note waits for whichever you play next.
    $script:NewestGame = if ($allRows.Count) { [DateTimeOffset]::FromUnixTimeMilliseconds($allRows[0].Start).UtcDateTime } else { $null }
    Update-NoteBar
}

# The character picker in the nav bar: "All characters", then each character you have played, most
# games first, the one showing in gold. Filled from the archive by Load-Matches; the choice is kept
# in Settings.Character. No game count: the list counts archived games and the Improve page counts
# measured ones, and the two differ (games before a wow:forget-games cutoff are archived, never
# measured).
function Update-CharacterPicker {
    if (-not $script:CharMenu) { return }
    $current = [string]$script:Settings.Character
    $script:CharMenu.Items.Clear()
    $entries = @([pscustomobject]@{ Key = ''; Text = 'All characters' })
    # Not $c: PowerShell names ignore case, and $c would hide the colour table $C.
    foreach ($ch in $script:MyChars) { $entries += [pscustomobject]@{ Key = $ch.Name; Text = ('{0}  {1}  {2}' -f (Short-Name $ch.Name), $script:Dot, $ch.Spec) } }
    foreach ($e in $entries) {
        $item = New-Object Windows.Forms.ToolStripMenuItem($e.Text)
        $item.Tag = $e.Key
        $item.ForeColor = $(if ($e.Key -eq $current) { $C.Gold } else { $C.Ink })
        $item.Padding = New-Object Windows.Forms.Padding(4, 3, 4, 3)
        $item.Add_Click({ Select-Character ([string]$this.Tag) })
        [void]$script:CharMenu.Items.Add($item)
        if ($e.Key -eq $current) { $script:CharButton.Text = $e.Text + '  ' + $script:Arrow }
    }
}

function Select-Character([string]$key) {
    if ($key -eq [string]$script:Settings.Character) { return }
    $script:Settings.Character = $key
    Save-Settings
    Load-Matches
    if ($script:PageImprove.Visible) { Show-Improve }
    if ($script:PageComps.Visible) { Update-CompList }
}

function Show-SelectedMatch {
    if ($script:MatchList -and $script:MatchList.SelectedItems.Count) { Show-MatchDetail $script:MatchList.SelectedItems[0].Tag }
}

# The card view is Windows' built-in browser control (IE11). Each card is written to one of two
# files in turn: navigating to the file that is already showing does not reload it.
$script:CardFlip = 0

function Show-Html([string]$html) {
    # The card keeps its open tab in the page title ("tab:damage"), read from the live page. The next
    # card is written opening the same tab, so switching games keeps you on it.
    try { $title = [string]$script:CardView.Document.Title } catch { $title = '' }
    if ($title -match '^tab:([a-z]+)$') { $script:CardTab = $Matches[1] }
    if ($script:CardTab) { $html = $html.Replace('onload="showTab(''summary'')"', "onload=`"showTab('$($script:CardTab)')`"") }
    $script:CardFlip = 1 - $script:CardFlip
    $path = Join-Path $script:StateDir ("card-{0}.html" -f $script:CardFlip)
    [IO.File]::WriteAllText($path, $html, (New-Object System.Text.UTF8Encoding($false)))
    $script:CardView.Navigate($path)
}

# The Improve page: what to work on for the picked character, or your most-played one when the
# picker says "All characters". wow:game-cards writes one page per character (ImprovementService).
function Show-Improve {
    if (-not $script:ImproveView) { return }
    $full = [string]$script:Settings.Character
    if (-not $full -or -not $script:ImproveFiles -or -not $script:ImproveFiles.ContainsKey($full)) {
        $full = $null; $most = -1
        if ($script:ImproveFiles) {
            foreach ($k in $script:ImproveFiles.Keys) { if ($script:ImproveFiles[$k].Games -gt $most) { $most = $script:ImproveFiles[$k].Games; $full = $k } }
        }
    }
    if (-not $full -or -not (Test-Path $script:ImproveFiles[$full].File)) {
        $path = Join-Path $script:StateDir 'improve-empty.html'
        [IO.File]::WriteAllText($path, (Get-PlainPage "<h2>What to work on</h2><p class='muted'>This page is built with the game cards after a sync. Play a game, or press Sync now, and it appears here.</p>"), (New-Object System.Text.UTF8Encoding($false)))
    } else {
        $path = $script:ImproveFiles[$full].File
    }
    # Navigating to the page already showing does not reload it.
    if ($script:ImproveView.Url -and $script:ImproveView.Url.IsFile -and $script:ImproveView.Url.LocalPath -eq $path) { $script:ImproveView.Refresh() }
    else { $script:ImproveView.Navigate($path) }
}

# The Comps page: every enemy comp you have met (the picked character's only, unless "All
# characters"), most games first; the selected one's page on the right.
# The Comps tab lists 3v3 comps and the Shuffle tab Solo Shuffle ones: the same page, one bracket
# each (CompLibraryService keys a shuffle comp 'shuffle:...' and a 2v2 one '2v2:...').
$script:CompBracket = '3v3'

function Update-CompList {
    if (-not $script:CompList) { return }
    $pick = [string]$script:Settings.Character
    $selected = if ($script:CompList.SelectedItems.Count) { [string]$script:CompList.SelectedItems[0].Tag } else { $null }
    $inBracket = if ($script:CompBracket -eq 'shuffle') { { $_.Key -like 'shuffle:*' } } else { { $_.Key -notlike '*:*' } }
    $rows = @($script:Comps | Where-Object $inBracket | Where-Object { -not $pick -or $_.Characters.ContainsKey($pick) } | Sort-Object @{ Expression = { if ($pick) { $_.Characters[$pick].Games } else { $_.Games } }; Descending = $true }, @{ Expression = 'Last'; Descending = $true })
    $script:CompList.BeginUpdate()
    $script:CompList.Items.Clear()
    # Not $c: PowerShell names ignore case, and $c would hide the colour table $C.
    foreach ($cp in $rows) {
        # The tab already says the bracket, so the name drops its 'Solo Shuffle: ' prefix.
        $name = if ($cp.Name -match '^[^:]+:\s*(.+)$') { $Matches[1] } else { $cp.Name }
        $item = New-Object Windows.Forms.ListViewItem($(if ($cp.Nick) { "$($cp.Nick)  $($script:Dot)  $name" } else { $name }))
        # The picked character's own games and record; every character's with "All characters".
        $mine = if ($pick) { $cp.Characters[$pick] } else { $cp }
        [void]$item.SubItems.Add([string]$mine.Games)
        [void]$item.SubItems.Add("$($mine.Won)-$($mine.Lost)")
        [void]$item.SubItems.Add($(if ($cp.Last.Length -ge 10) { ([datetime]$cp.Last).ToString('ddd dd MMM') } else { '' }))
        $item.Tag = $cp.File
        [void]$script:CompList.Items.Add($item)
    }
    if ($selected) { foreach ($it in $script:CompList.Items) { if ($it.Tag -eq $selected) { $it.Selected = $true; break } } }
    $script:CompList.EndUpdate()
    $bracketText = if ($script:CompBracket -eq 'shuffle') { 'Solo Shuffle' } else { '3v3' }
    $script:CompCount.Text = "$($rows.Count) $bracketText comp(s), grouped by their two DPS specs with any healer. Each comp's page reads every game you played against it, on any character."
    if (-not $script:CompList.SelectedItems.Count) {
        $path = Join-Path $script:StateDir 'comp-empty.html'
        $msg = if ($rows.Count) { 'Pick a comp to see how it played against you: its goes, its crowd control on you, who died, and how defensives were traded.' } else { 'The comp library is built with the game cards after a sync.' }
        [IO.File]::WriteAllText($path, (Get-PlainPage "<p class='muted'>$msg</p>"), (New-Object System.Text.UTF8Encoding($false)))
        $script:CompView.Navigate($path)
    }
}

function Show-SelectedComp {
    if (-not $script:CompList.SelectedItems.Count) { return }
    $path = [string]$script:CompList.SelectedItems[0].Tag
    if (-not (Test-Path $path)) { return }
    if ($script:CompView.Url -and $script:CompView.Url.IsFile -and $script:CompView.Url.LocalPath -eq $path) { $script:CompView.Refresh() }
    else { $script:CompView.Navigate($path) }
}

# The Classes page: for each spec you have played against, the highest-rated player of it you met
# (their team's MMR) and the most experienced (Gladiator seasons), grouped by class. Every
# character's games: the picker does not filter it. The player's page on the right.
function Update-ClassList {
    if (-not $script:ClassList) { return }
    $selected = if ($script:ClassList.SelectedItems.Count) { [string]$script:ClassList.SelectedItems[0].Tag } else { $null }
    $script:ClassList.BeginUpdate()
    $script:ClassList.Items.Clear()
    $script:ClassList.Groups.Clear()
    $groups = @{}
    foreach ($pl in @($script:Classes | Sort-Object Class, Spec, Name)) {
        if (-not $groups.ContainsKey($pl.Class)) {
            $groups[$pl.Class] = New-Object Windows.Forms.ListViewGroup($pl.Class)
            [void]$script:ClassList.Groups.Add($groups[$pl.Class])
        }
        $item = New-Object Windows.Forms.ListViewItem($pl.Name, $groups[$pl.Class])
        try { $item.ForeColor = [Drawing.ColorTranslator]::FromHtml($pl.Color) } catch { }
        [void]$item.SubItems.Add($pl.Spec)
        [void]$item.SubItems.Add($pl.Why)
        [void]$item.SubItems.Add("$($pl.Games) ($($pl.Won)-$($pl.Lost))")
        $item.Tag = $pl.File
        [void]$script:ClassList.Items.Add($item)
    }
    if ($selected) { foreach ($it in $script:ClassList.Items) { if ($it.Tag -eq $selected) { $it.Selected = $true; break } } }
    $script:ClassList.EndUpdate()
    $script:ClassCount.Text = "$(@($script:Classes).Count) player(s): for each spec you have played against, the one you met at the highest team MMR and the one with the most Gladiator seasons. Rounds: how many you played against them, and your record."
    if (-not $script:ClassList.SelectedItems.Count) {
        $path = Join-Path $script:StateDir 'class-empty.html'
        $msg = if (@($script:Classes).Count) { 'Pick a player to see what they pressed against you, beside the median player of their spec and beside you when you play it.' } else { 'The Classes page is built with the game cards after a sync.' }
        [IO.File]::WriteAllText($path, (Get-PlainPage "<p class='muted'>$msg</p>"), (New-Object System.Text.UTF8Encoding($false)))
        $script:ClassView.Navigate($path)
    }
}

function Show-SelectedClass {
    if (-not $script:ClassList.SelectedItems.Count) { return }
    $path = [string]$script:ClassList.SelectedItems[0].Tag
    if (-not (Test-Path $path)) { return }
    if ($script:ClassView.Url -and $script:ClassView.Url.IsFile -and $script:ClassView.Url.LocalPath -eq $path) { $script:ClassView.Refresh() }
    else { $script:ClassView.Navigate($path) }
}

function Get-PlainPage([string]$bodyHtml) {
    return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="X-UA-Compatible" content="IE=edge"><style>' +
        'html,body{margin:0;background:#111116;color:#F0F0F2}body{font-family:Segoe UI,Arial;font-size:13px;padding:16px;line-height:1.5}' +
        '.muted{color:#8A8A9A}table{border-collapse:collapse}td{padding:3px 14px 3px 0}h2{font-size:16px;margin:0 0 10px}' +
        '</style></head><body>' + $bodyHtml + '</body></html>'
}

function Show-MatchDetail([string]$key) {
    if ($script:Cards.ContainsKey($key) -and (Test-Path $script:Cards[$key])) {
        Show-Html ([IO.File]::ReadAllText($script:Cards[$key], [Text.Encoding]::UTF8))
        return
    }

    if (-not $script:Games -or -not $script:Games.ContainsKey($key)) { return }
    $rounds = $script:Games[$key]
    $enc = { param($t) [System.Net.WebUtility]::HtmlEncode([string]$t) }
    $first = $rounds[0]
    $played = [DateTimeOffset]::FromUnixTimeMilliseconds([int64]$first.startTime).UtcDateTime
    $sb = New-Object System.Text.StringBuilder
    [void]$sb.Append("<h2>$(& $enc $first.startInfo.bracket) <span class='muted'>$($played.ToString('ddd d MMM, HH:mm'))</span></h2>")
    [void]$sb.Append("<p class='muted'>No analysis for this game yet. It appears after the next sync; until then, the basics from the archive.</p><table>")

    # Every player who appeared in any round, once.
    $players = @{}
    foreach ($r in $rounds) {
        foreach ($u in $r.units) {
            if ($u.id -like 'Player-*' -and $u.spec -ne '0' -and -not $players.ContainsKey($u.id)) { $players[$u.id] = $u }
        }
    }
    foreach ($u in ($players.Values | Sort-Object { if ($_.affiliation -eq 1) { 0 } else { 1 } }, name)) {
        $you = if ($u.affiliation -eq 1) { " <span style='color:#C8952C'>YOU</span>" } else { '' }
        [void]$sb.Append("<tr><td><b>$(& $enc (Short-Name $u.name))</b>$you</td><td class='muted'>$(& $enc (Spec-Name $u.spec))</td></tr>")
    }
    [void]$sb.Append('</table><p>')
    $i = 0
    foreach ($r in $rounds) {
        $i++
        $res = if ($r.result -eq 3) { "<b style='color:#86efac'>Won</b>" } elseif ($r.result -eq 2) { "<b style='color:#fca5a5'>Lost</b>" } else { '?' }
        $label = if ($rounds.Count -gt 1) { "Round $i" } else { 'Game' }
        [void]$sb.Append("$label &nbsp;$res&nbsp; <span class='muted'>$(Format-Duration $r.durationInSeconds)</span><br>")
    }
    [void]$sb.Append('</p>')
    Show-Html (Get-PlainPage $sb.ToString())
}

# --- Notes -------------------------------------------------------------------
# notes.json holds every note and mark: when it was written (the machine's clock, which is the
# clock WoW writes into the log), the round it was written in when the panel knew it
# (`roundStart`, the ARENA_MATCH_START time), and the text. wow:game-cards puts each on its game.

function Load-Notes {
    $script:Notes = New-Object System.Collections.ArrayList
    if (-not (Test-Path $script:NotesFile)) { return }
    try {
        $j = Get-Content $script:NotesFile -Raw -Encoding UTF8 | ConvertFrom-Json
        foreach ($n in @($j.notes)) { if ($n) { [void]$script:Notes.Add($n) } }
    } catch {
        Write-Activity "Could not read your notes file: $($_.Exception.Message)"
    }
}

function Save-Notes {
    $json = @{ notes = @($script:Notes) } | ConvertTo-Json -Depth 4
    [IO.File]::WriteAllText($script:NotesFile, $json, (New-Object System.Text.UTF8Encoding($false)))
    $script:NotesDirty = $true
}

function Add-Note([string]$kind, [string]$text, [datetime]$at) {
    $n = [pscustomobject]@{
        id = [guid]::NewGuid().ToString('N')
        at = $at.ToString('yyyy-MM-dd HH:mm:ss')
        roundStart = $script:Live.RoundStart
        visit = $script:Live.Visit
        zone = $script:Live.Zone
        bracket = $script:Live.Bracket
        round = $script:Live.Round
        kind = $kind
        text = $text
    }
    [void]$script:Notes.Add($n)
    Save-Notes
    Update-LivePanel
    return $n
}

# Notes written on the Matches page, away from a game. `game` puts a note on the selected game (its
# lobby id, the same key the cards use); `kind = next` puts it on the first game that starts after
# it was written, so it is waiting there when the next sync builds that game's card.
function Add-PageNote([bool]$forNext) {
    $text = $script:NoteBox.Text.Trim()
    if (-not $text) { return }
    $key = $null
    if (-not $forNext) {
        if (-not $script:MatchList.SelectedItems.Count) { Set-Status 'Pick a game first, or use For next game.'; return }
        $key = [string]$script:MatchList.SelectedItems[0].Tag
    }
    $n = [pscustomobject]@{
        id = [guid]::NewGuid().ToString('N')
        at = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        kind = $(if ($forNext) { 'next' } else { 'note' })
        game = $key
        text = $text
    }
    [void]$script:Notes.Add($n)
    Save-Notes
    $script:NoteBox.Text = ''
    Write-Activity $(if ($forNext) { "Note for your next game: $text" } else { "Note added to the game: $text" })
    Update-NoteBar
    # Rebuild the cards now when nothing else is running; otherwise the timer does it when idle.
    if (-not $script:Running -and $script:Steps.Count -eq 0 -and $script:Php -and $script:Live.State -ne 'live') {
        $script:NotesDirty = $false
        Add-CardsStep; Start-NextStep
    }
}

# How many "next game" notes are still waiting: written after the newest game in the archive started.
function Update-NoteBar {
    if (-not $script:NoteWaiting) { return }
    $newest = $script:NewestGame
    $waiting = @($script:Notes | Where-Object {
        $_.kind -eq 'next' -and (-not $newest -or [datetime]::ParseExact([string]$_.at, 'yyyy-MM-dd HH:mm:ss', $null) -gt $newest)
    })
    $script:NoteWaiting.Text = if ($waiting.Count) { "$($waiting.Count) waiting for your next game: " + (($waiting | ForEach-Object { $_.text }) -join ' | ') } else { '' }
}

# --- Live: the game you are in ------------------------------------------------
# While WoW runs, the newest combat log is followed a second at a time. What it holds, measured on
# real games (2026-10-01): logging starts as you zone in (ZONE_CHANGE, about a minute of prep, and
# only your own team appears in it); ARENA_MATCH_START and every player's COMBATANT_INFO (team at
# field 2, spec at field 25) are written as the gates open; every player had a name in some event
# within five seconds of that in the last 12 games; a shuffle writes a START per round and one
# ARENA_MATCH_END for the lobby. So the enemy cannot be shown in the prep room, only at the gates.

$script:Live = @{ File = $null; Offset = 0; Carry = (New-Object byte[] 0); State = 'idle'; Zone = ''; Bracket = ''
    Round = 0; RoundStart = $null; RoundStartAt = $null; Visit = $null; Players = [ordered]@{}; Unnamed = 0; Logger = $null
    Changed = $false; Asked = @{}; LastGrowth = $null; EndedAt = $null }

function Reset-LiveVisit([string]$zone, [datetime]$at) {
    $script:LiveOpenedByHand = $false
    $script:Live.State = 'prep'; $script:Live.Zone = $zone; $script:Live.Bracket = ''; $script:Live.Round = 0
    $script:Live.RoundStart = $null; $script:Live.RoundStartAt = $null; $script:Live.Visit = $at.ToString('yyyy-MM-dd HH:mm:ss')
    $script:Live.Players = [ordered]@{}; $script:Live.Unnamed = 0; $script:Live.Changed = $true
}

function ConvertFrom-LogTime([string]$line) {
    $sep = $line.IndexOf('  ')
    if ($sep -lt 10) { return $null }
    $stamp = $line.Substring(0, $sep)
    $dot = $stamp.IndexOf('.')
    if ($dot -gt 0) { $stamp = $stamp.Substring(0, $dot) }
    $t = [datetime]::MinValue
    if ([datetime]::TryParseExact($stamp, 'M/d/yyyy H:mm:ss', [Globalization.CultureInfo]::InvariantCulture, 'None', [ref]$t)) { return $t }
    return $null
}

# New bytes since last time, cut at the last whole line; the rest waits for the next read.
function Read-LiveText {
    $files = Get-CombatLogs
    if (-not $files.Count) { return $null }
    $f = $files[0]
    if ($script:Live.File -ne $f.FullName) {
        # A new session's file, or the app just started: catch up on the last megabyte, which
        # holds a game already under way.
        $script:Live.File = $f.FullName
        $script:Live.Offset = [Math]::Max(0, $f.Length - 1MB)
        $script:Live.Carry = New-Object byte[] 0
    }
    if ($f.Length -lt $script:Live.Offset) { $script:Live.Offset = 0 }
    if ($f.Length -eq $script:Live.Offset) { return $null }

    $fs = [IO.File]::Open($f.FullName, 'Open', 'Read', 'ReadWrite, Delete')
    try {
        $fs.Seek($script:Live.Offset, 'Begin') | Out-Null
        $want = [int][Math]::Min($fs.Length - $script:Live.Offset, 8MB)
        $buf = New-Object byte[] ($script:Live.Carry.Length + $want)
        [Array]::Copy($script:Live.Carry, $buf, $script:Live.Carry.Length)
        $read = $fs.Read($buf, $script:Live.Carry.Length, $want)
        $script:Live.Offset += $read
        # When WoW last wrote, not when this read: catching up after a restart is not a live game.
        if ($read -gt 0) { $script:Live.LastGrowth = $f.LastWriteTime }
    } finally { $fs.Dispose() }

    $total = $script:Live.Carry.Length + $read
    $cut = [Array]::LastIndexOf($buf, [byte]10, $total - 1)
    if ($cut -lt 0) {
        $script:Live.Carry = New-Object byte[] $total
        [Array]::Copy($buf, $script:Live.Carry, $total)
        return $null
    }
    $rest = $total - $cut - 1
    $script:Live.Carry = New-Object byte[] $rest
    if ($rest -gt 0) { [Array]::Copy($buf, $cut + 1, $script:Live.Carry, 0, $rest) }
    return [Text.Encoding]::UTF8.GetString($buf, 0, $cut)
}

function Read-LiveLine([string]$line) {
    $sep = $line.IndexOf('  ')
    if ($sep -lt 0) { return }
    $body = $line.Substring($sep + 2)
    $L = $script:Live

    if ($body.StartsWith('ZONE_CHANGE,')) {
        $q1 = $body.IndexOf('"'); $q2 = $body.LastIndexOf('"')
        $at = ConvertFrom-LogTime $line
        if ($q2 -gt $q1 -and $at) { Reset-LiveVisit $body.Substring($q1 + 1, $q2 - $q1 - 1) $at }
        return
    }
    if ($body.StartsWith('ARENA_MATCH_START,')) {
        $f = $body.Split(',')
        $at = ConvertFrom-LogTime $line
        if (-not $at) { return }
        if (-not $L.Visit) { Reset-LiveVisit '' $at }
        $L.State = 'live'; $L.Bracket = ($f[3] -replace '^Rated ', ''); $L.Round++
        $L.RoundStartAt = $at; $L.RoundStart = $at.ToString('yyyy-MM-dd HH:mm:ss')
        $L.Players = [ordered]@{}; $L.Unnamed = 0; $L.Changed = $true
        # Notes written in the prep room belong to the round that follows.
        $moved = $false
        foreach ($n in $script:Notes) {
            if ($n.visit -eq $L.Visit -and -not $n.roundStart) { $n.roundStart = $L.RoundStart; $n.round = $L.Round; $n.bracket = $L.Bracket; $moved = $true }
        }
        if ($moved) { Save-Notes }
        return
    }
    if ($body.StartsWith('COMBATANT_INFO,')) {
        $f = $body.Split(',')
        if ($f.Count -gt 25 -and -not $L.Players.Contains($f[1])) { $L.Players[$f[1]] = @{ Team = $f[2]; Spec = $f[25]; Name = $null }; $L.Unnamed++; $L.Changed = $true }
        return
    }
    if ($body.StartsWith('ARENA_MATCH_END,')) { $L.State = 'ended'; $L.EndedAt = Get-Date; $L.Changed = $true; return }

    # Ordinary events carry source and target as GUID, "name", flags: the players' names, and
    # which player is you (affiliation "mine" is the lowest flag bit).
    # Nothing left to learn from ordinary events once everyone is named: most lines stop here.
    if ($L.Logger -and $L.Unnamed -le 0) { return }
    $f = $body.Split(',')
    if ($f.Count -lt 8) { return }
    foreach ($ix in @(1, 5)) {
        $guid = $f[$ix]
        if (-not $guid.StartsWith('Player-')) { continue }
        if ($L.Players.Contains($guid) -and -not $L.Players[$guid].Name) {
            # WoW sometimes writes a player's name without the realm ("Dragonz", 2026-10-01). Only
            # Name-Realm-Region can be looked up, so show the short one until the full one appears.
            $n = $f[$ix + 1].Trim('"')
            if ($n.Contains('-')) { $L.Players[$guid].Name = $n; $L.Unnamed--; $L.Changed = $true }
            elseif (-not $L.Players[$guid].Short) { $L.Players[$guid].Short = $n; $L.Changed = $true }
        }
        if (-not $L.Logger -and $f[$ix + 2].StartsWith('0x')) {
            try { if (([Convert]::ToInt32($f[$ix + 2].Substring(2), 16) -band 0xF) -eq 1) { $L.Logger = $guid; $L.Changed = $true } } catch { }
        }
    }
}

# How long the panel stays up once a game is over, and how long a silent log means it is over.
$script:LiveLingerSeconds = 15
$script:LiveSilenceSeconds = 90

# The column is in use while something is half-written in the note box.
function Test-LivePanelInUse {
    return [bool]$script:NoteBox.Text.Trim()
}

# The panel goes once the game is over, unless you are writing in it. Over means: the lobby's
# ARENA_MATCH_END was read, or the log has said nothing for LiveSilenceSeconds. The second is
# needed because WoW holds back the last lines of a game (the END among them) and writes them only
# later, often as the next arena starts (seen 2026-10-01: a game's lines stopped at 15:31:16 and
# no END had been written three minutes on). A game in progress writes every second.
function Close-LivePanelIfOver([bool]$wowRunning) {
    if (-not $script:LiveShown -or $script:LiveOpenedByHand -or (Test-LivePanelInUse)) { return }
    $L = $script:Live
    $now = Get-Date
    $over = (-not $wowRunning) -or
        ($L.State -eq 'ended' -and $L.EndedAt -and ($now - $L.EndedAt).TotalSeconds -ge $script:LiveLingerSeconds) -or
        ($L.State -in @('prep', 'live') -and $L.LastGrowth -and ($now - $L.LastGrowth).TotalSeconds -ge $script:LiveSilenceSeconds)
    if ($over) {
        # Not again for this visit; the next arena opens it as usual, and the tray menu brings it back.
        $script:LiveDismissed = $L.Visit
        Hide-LivePanel
    }
}

function Update-Live {
    if (-not $script:Settings.LivePanel) { return }
    $wow = Test-WowRunning
    Close-LivePanelIfOver $wow
    if (-not $wow) { return }
    $text = Read-LiveText
    if ($text) { foreach ($line in $text.Split("`n")) { Read-LiveLine $line.TrimEnd("`r") } }

    if ($script:Live.Changed) {
        $script:Live.Changed = $false
        $fresh = $script:Live.LastGrowth -and ((Get-Date) - $script:Live.LastGrowth).TotalSeconds -lt $script:LiveSilenceSeconds
        if ($fresh -and $script:Live.State -in @('prep', 'live') -and $script:LiveDismissed -ne $script:Live.Visit) { Show-LivePanel $false }
        Update-LivePanel
        Request-LiveExperience
    }
    if ($script:LiveShown) { Update-LiveClock }
}

# Anyone in the game whose experience is not on file, looked up once each (about a second each).
function Request-LiveExperience {
    $names = @($script:Live.Players.Values | Where-Object { $_.Name -and -not $script:Experience.ContainsKey($_.Name) -and -not $script:Live.Asked.ContainsKey($_.Name) } | ForEach-Object { $_.Name })
    if (-not $names.Count -or -not $script:Php) { return }
    foreach ($n in $names) { $script:Live.Asked[$n] = $true }
    Add-Step "Looking up $($names.Count) player(s) in this game" ('wow:experience ' + (($names | ForEach-Object { '"' + $_ + '"' }) -join ' ')) {
        param($ok, $output, $data)
        if (-not $ok) { return }
        try {
            $line = $output -split "`r?`n" | Where-Object { $_.StartsWith('{') } | Select-Object -Last 1
            $j = $line | ConvertFrom-Json
            foreach ($p in $j.PSObject.Properties) { $script:Experience[$p.Name] = $p.Value }
            Update-LivePanel
        } catch { }
    }
    Start-NextStep
}

# --- The hotkey ----------------------------------------------------------------
# Ctrl+Shift+M from anywhere, WoW included, marks the moment. A hidden window owns the hotkey and
# queues the time of each press; the timer turns them into marks.
Add-Type -ReferencedAssemblies System.Windows.Forms -TypeDefinition @"
using System;
using System.Collections.Concurrent;
using System.Runtime.InteropServices;
using System.Windows.Forms;
public class MindCollectorHotkey : NativeWindow, IDisposable {
    [DllImport("user32.dll")] static extern bool RegisterHotKey(IntPtr h, int id, uint mods, uint vk);
    [DllImport("user32.dll")] static extern bool UnregisterHotKey(IntPtr h, int id);
    public ConcurrentQueue<DateTime> Presses = new ConcurrentQueue<DateTime>();
    public bool Registered;
    public MindCollectorHotkey(uint mods, uint vk) {
        CreateHandle(new CreateParams());
        Registered = RegisterHotKey(Handle, 1, mods | 0x4000, vk);
    }
    protected override void WndProc(ref Message m) {
        if (m.Msg == 0x0312) { Presses.Enqueue(DateTime.Now); }
        base.WndProc(ref m);
    }
    public void Dispose() { UnregisterHotKey(Handle, 1); DestroyHandle(); }
}
public static class MindCollectorWin {
    [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr h, int cmd);
    [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
}
"@

function Read-Hotkey {
    if (-not $script:Hotkey) { return }
    $t = [datetime]::MinValue
    while ($script:Hotkey.Presses.TryDequeue([ref]$t)) {
        [void](Add-Note 'mark' '' $t)
        [System.Media.SystemSounds]::Asterisk.Play()
        Write-Activity ("Marked a moment at {0:HH:mm:ss}" -f $t)
    }
}

# --- Local site, for the full review ---------------------------------------

function Test-PortOpen([int]$port) {
    $tcp = New-Object Net.Sockets.TcpClient
    try { $ar = $tcp.BeginConnect('127.0.0.1', $port, $null, $null); return ($ar.AsyncWaitHandle.WaitOne(300) -and $tcp.Connected) }
    catch { return $false } finally { $tcp.Close() }
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

$script:StartupLink = Join-Path ([Environment]::GetFolderPath('Startup')) 'MindCollector Dev.lnk'
# Named 'MindCollector Logs' until 2026-10-10, when it became 'MindCollector Dev' beside the downloadable
# .NET app ('MindCollector'). A start-with-Windows shortcut of the old name is carried over.
$oldStartup = Join-Path ([Environment]::GetFolderPath('Startup')) 'MindCollector Logs.lnk'
if ((Test-Path $oldStartup) -and -not (Test-Path $script:StartupLink)) { Move-Item $oldStartup $script:StartupLink -ErrorAction SilentlyContinue }

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
$form.Text = 'MindCollector Dev'
$form.Icon = $appIcon
$form.Size = New-Object Drawing.Size(1500, 920)
$form.MinimumSize = New-Object Drawing.Size(720, 460)
$form.StartPosition = 'CenterScreen'
$form.BackColor = $C.Bg
$form.ForeColor = $C.Ink
$form.Font = $fontUi

# Header
$header = New-Object Windows.Forms.Panel
$header.Dock = 'Top'; $header.Height = 70; $header.BackColor = $C.Panel; $header.Padding = New-Object Windows.Forms.Padding(16, 10, 16, 8)
$title = New-Object Windows.Forms.Label
$title.Text = 'MindCollector Dev'; $title.ForeColor = $C.Gold; $title.AutoSize = $true
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
# List on the left, card on the right at full height, so a card is read without scrolling.
$split.Dock = 'Fill'; $split.Orientation = 'Vertical'; $split.BackColor = $C.Line; $split.SplitterWidth = 4; $split.FixedPanel = 'Panel1'
$script:MatchList = New-Object Windows.Forms.ListView
$script:MatchList.Dock = 'Fill'; $script:MatchList.View = 'Details'; $script:MatchList.FullRowSelect = $true
$script:MatchList.MultiSelect = $false; $script:MatchList.HideSelection = $false; $script:MatchList.BorderStyle = 'None'
$script:MatchList.BackColor = $C.Panel; $script:MatchList.ForeColor = $C.Ink
foreach ($col in @(@('Played', 125), @('Bracket', 90), @('Result', 52), @('You', 190), @('Length', 52), @('Rating', 52))) {
    [void]$script:MatchList.Columns.Add($col[0], $col[1])
}
$script:CardView = New-Object Windows.Forms.WebBrowser
$script:CardView.Dock = 'Fill'
$script:CardView.ScriptErrorsSuppressed = $true
$script:CardView.IsWebBrowserContextMenuEnabled = $false
$script:CardView.AllowWebBrowserDrop = $false
$script:CardView.WebBrowserShortcutsEnabled = $false
$script:CardTab = $null
$split.Panel1.Controls.Add($script:MatchList)

# Note bar under the card: write a note on the selected game, or one for the next game you play.
$noteBar = New-Object Windows.Forms.Panel
$noteBar.Dock = 'Bottom'; $noteBar.Height = 58; $noteBar.BackColor = $C.Panel; $noteBar.Padding = New-Object Windows.Forms.Padding(8, 6, 8, 4)
$script:NoteBox = New-Object Windows.Forms.TextBox
$script:NoteBox.Dock = 'Fill'; $script:NoteBox.BackColor = $C.Raised; $script:NoteBox.ForeColor = $C.Ink; $script:NoteBox.BorderStyle = 'FixedSingle'
$noteButtons = New-Object Windows.Forms.FlowLayoutPanel
$noteButtons.Dock = 'Right'; $noteButtons.AutoSize = $true; $noteButtons.WrapContents = $false; $noteButtons.Padding = New-Object Windows.Forms.Padding(6, 0, 0, 0)
$btnNoteGame = New-Button 'Add to selected game' $true
$btnNoteNext = New-Button 'For next game'
$btnNoteGame.Margin = New-Object Windows.Forms.Padding(0, 0, 6, 0); $btnNoteNext.Margin = New-Object Windows.Forms.Padding(0)
$noteButtons.Controls.AddRange(@($btnNoteGame, $btnNoteNext))
$script:NoteWaiting = New-Object Windows.Forms.Label
$script:NoteWaiting.Dock = 'Bottom'; $script:NoteWaiting.Height = 20; $script:NoteWaiting.ForeColor = $C.Gold; $script:NoteWaiting.TextAlign = 'MiddleLeft'; $script:NoteWaiting.AutoEllipsis = $true
$noteRow = New-Object Windows.Forms.Panel
$noteRow.Dock = 'Top'; $noteRow.Height = 28
$noteRow.Controls.AddRange(@($script:NoteBox, $noteButtons))
$noteBar.Controls.AddRange(@($noteRow, $script:NoteWaiting))

$split.Panel2.Controls.AddRange(@($script:CardView, $noteBar))
$script:MatchCount = New-Object Windows.Forms.Label
$script:MatchCount.Dock = 'Bottom'; $script:MatchCount.Height = 24; $script:MatchCount.ForeColor = $C.Muted; $script:MatchCount.TextAlign = 'MiddleLeft'
$pageMatches.Controls.AddRange(@($split, $script:MatchCount))

# Improve page: what to work on, for the character picked in the nav bar.
$script:PageImprove = New-Object Windows.Forms.Panel
$script:PageImprove.Dock = 'Fill'
$script:ImproveView = New-Object Windows.Forms.WebBrowser
$script:ImproveView.Dock = 'Fill'
$script:ImproveView.ScriptErrorsSuppressed = $true
$script:ImproveView.IsWebBrowserContextMenuEnabled = $false
$script:ImproveView.AllowWebBrowserDrop = $false
$script:ImproveView.WebBrowserShortcutsEnabled = $false
$script:PageImprove.Controls.Add($script:ImproveView)

# Comps page: the comp library, list on the left and the comp's page on the right, like Matches.
$script:PageComps = New-Object Windows.Forms.Panel
$script:PageComps.Dock = 'Fill'
$compSplit = New-Object Windows.Forms.SplitContainer
$compSplit.Dock = 'Fill'; $compSplit.Orientation = 'Vertical'; $compSplit.BackColor = $C.Line; $compSplit.SplitterWidth = 4; $compSplit.FixedPanel = 'Panel1'
$script:CompList = New-Object Windows.Forms.ListView
$script:CompList.Dock = 'Fill'; $script:CompList.View = 'Details'; $script:CompList.FullRowSelect = $true
$script:CompList.MultiSelect = $false; $script:CompList.HideSelection = $false; $script:CompList.BorderStyle = 'None'
$script:CompList.BackColor = $C.Panel; $script:CompList.ForeColor = $C.Ink
foreach ($col in @(@('Comp', 330), @('Games', 52), @('Record', 56), @('Last met', 90))) { [void]$script:CompList.Columns.Add($col[0], $col[1]) }
$script:CompView = New-Object Windows.Forms.WebBrowser
$script:CompView.Dock = 'Fill'
$script:CompView.ScriptErrorsSuppressed = $true
$script:CompView.IsWebBrowserContextMenuEnabled = $false
$script:CompView.AllowWebBrowserDrop = $false
$script:CompView.WebBrowserShortcutsEnabled = $false
$compSplit.Panel1.Controls.Add($script:CompList)
$compSplit.Panel2.Controls.Add($script:CompView)
$script:CompCount = New-Object Windows.Forms.Label
$script:CompCount.Dock = 'Bottom'; $script:CompCount.Height = 24; $script:CompCount.ForeColor = $C.Muted; $script:CompCount.TextAlign = 'MiddleLeft'
$script:PageComps.Controls.AddRange(@($compSplit, $script:CompCount))
$script:CompList.Add_SelectedIndexChanged({ Show-SelectedComp })
$script:CompSplit = $compSplit

# Classes page: the strongest player of each spec you met, grouped by class; their page on the right.
$script:PageClasses = New-Object Windows.Forms.Panel
$script:PageClasses.Dock = 'Fill'
$classSplit = New-Object Windows.Forms.SplitContainer
$classSplit.Dock = 'Fill'; $classSplit.Orientation = 'Vertical'; $classSplit.BackColor = $C.Line; $classSplit.SplitterWidth = 4; $classSplit.FixedPanel = 'Panel1'
$script:ClassList = New-Object Windows.Forms.ListView
$script:ClassList.Dock = 'Fill'; $script:ClassList.View = 'Details'; $script:ClassList.FullRowSelect = $true
$script:ClassList.MultiSelect = $false; $script:ClassList.HideSelection = $false; $script:ClassList.BorderStyle = 'None'
$script:ClassList.BackColor = $C.Panel; $script:ClassList.ForeColor = $C.Ink
foreach ($col in @(@('Player', 120), @('Spec', 150), @('Why', 230), @('Rounds', 70))) { [void]$script:ClassList.Columns.Add($col[0], $col[1]) }
$script:ClassView = New-Object Windows.Forms.WebBrowser
$script:ClassView.Dock = 'Fill'
$script:ClassView.ScriptErrorsSuppressed = $true
$script:ClassView.IsWebBrowserContextMenuEnabled = $false
$script:ClassView.AllowWebBrowserDrop = $false
$script:ClassView.WebBrowserShortcutsEnabled = $false
$classSplit.Panel1.Controls.Add($script:ClassList)
$classSplit.Panel2.Controls.Add($script:ClassView)
$script:ClassCount = New-Object Windows.Forms.Label
$script:ClassCount.Dock = 'Bottom'; $script:ClassCount.Height = 24; $script:ClassCount.ForeColor = $C.Muted; $script:ClassCount.TextAlign = 'MiddleLeft'
$script:PageClasses.Controls.AddRange(@($classSplit, $script:ClassCount))
$script:ClassList.Add_SelectedIndexChanged({ Show-SelectedClass })
$script:ClassSplit = $classSplit

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
# A text row like a folder row, without the Browse button.
function Add-TextRow([string]$label, [string]$hint, [string]$value) {
    $l = New-Object Windows.Forms.Label
    $l.Text = $label; $l.AutoSize = $true; $l.Margin = New-Object Windows.Forms.Padding(0, 10, 8, 0)
    $t = New-Object Windows.Forms.TextBox
    $t.Text = $value; $t.Dock = 'Fill'; $t.BackColor = $C.Raised; $t.ForeColor = $C.Ink; $t.BorderStyle = 'FixedSingle'; $t.Margin = New-Object Windows.Forms.Padding(0, 6, 8, 0)
    $h = New-Object Windows.Forms.Label
    $h.Text = $hint; $h.AutoSize = $true; $h.ForeColor = $C.Muted; $h.Margin = New-Object Windows.Forms.Padding(0, 2, 0, 6)
    $grid.Controls.Add($l); $grid.Controls.Add($t); $grid.Controls.Add((New-Object Windows.Forms.Label))
    $grid.Controls.Add((New-Object Windows.Forms.Label)); $grid.Controls.Add($h); $grid.Controls.Add((New-Object Windows.Forms.Label))
    return $t
}
$txtKey = Add-TextRow 'Website key' 'Sends your games to mindcollector.com/wow/coach after each sync, to review them away from the PC. Make the key on that page. Saved to .env as MINDCOLLECTOR_KEY. Leave empty to keep them on this PC.' ((Get-EnvValue 'MINDCOLLECTOR_KEY') -as [string])
$txtBackup = Add-FolderRow 'Back up games to' 'A second copy of the game archive and your notes, refreshed after each sync: another drive or a cloud-synced folder. Leave empty for none.' $script:Settings.BackupTo

function New-Check([string]$text, [bool]$checked) {
    $cb = New-Object Windows.Forms.CheckBox
    $cb.Text = $text; $cb.Checked = $checked; $cb.AutoSize = $true; $cb.Margin = New-Object Windows.Forms.Padding(0, 8, 0, 0)
    $grid.Controls.Add((New-Object Windows.Forms.Label)); $grid.Controls.Add($cb); $grid.Controls.Add((New-Object Windows.Forms.Label))
    return $cb
}
$chkAuto = New-Check 'Read new games automatically when an arena ends' $script:Settings.AutoSync
$chkMove = New-Check "Move each combat log out of WoW's folder once it is archived (after WoW closes)" $script:Settings.MoveAfterArchive
$chkLive = New-Check 'Show the game you are in beside the matches, to take notes (Ctrl+Shift+M marks a moment)' $script:Settings.LivePanel
$chkStartup = New-Check 'Start MindCollector Dev when Windows starts (in the tray)' (Test-Path $script:StartupLink)

$btnSave = New-Button 'Save settings' $true
$btnSave.Margin = New-Object Windows.Forms.Padding(0, 16, 0, 0)
$grid.Controls.Add((New-Object Windows.Forms.Label)); $grid.Controls.Add($btnSave); $grid.Controls.Add((New-Object Windows.Forms.Label))
$pageSettings.Controls.Add($grid)

$btnSave.Add_Click({
    try {
        if ($txtWow.Text -and -not (Test-Path $txtWow.Text)) { throw "WoW's Logs folder does not exist: $($txtWow.Text)" }
        $backup = $txtBackup.Text.Trim().TrimEnd('\')
        if ($backup) {
            if (-not (Test-Path ([IO.Path]::GetPathRoot($backup)))) { throw "The backup drive does not exist: $backup" }
            # With a trailing separator, so arena-logs-backup beside arena-logs is allowed.
            $a = $txtArchive.Text.TrimEnd('\') + '\'
            $b = $backup + '\'
            if ($b.StartsWith($a, [StringComparison]::OrdinalIgnoreCase) -or $a.StartsWith($b, [StringComparison]::OrdinalIgnoreCase)) {
                throw 'The backup folder cannot be inside the game archive, or hold it.'
            }
        }
        $backupChanged = $backup -ne $script:Settings.BackupTo
        $key = $txtKey.Text.Trim()
        if ($key -and $key -notmatch '^mc_[A-Za-z0-9]{40}$') { throw 'That is not a website key: it starts mc_ and is 43 characters. Make one on mindcollector.com/wow/coach.' }
        $keyChanged = $key -ne [string](Get-EnvValue 'MINDCOLLECTOR_KEY')
        if ($keyChanged) { Set-EnvValue 'MINDCOLLECTOR_KEY' $key }
        $archiveChanged = (Get-ArchiveDir) -ne $txtArchive.Text
        if ($txtWow.Text -ne (Get-WowLogsDir)) { Set-EnvValue 'WOW_COMBATLOG_PATH' $txtWow.Text }
        if ($archiveChanged) {
            New-Item -ItemType Directory -Force -Path $txtArchive.Text | Out-Null
            Set-EnvValue 'ARENA_LOG_ARCHIVE_PATH' $txtArchive.Text
        }
        $script:Settings.MoveTo = $txtMove.Text
        $script:Settings.BackupTo = $backup
        $script:Settings.AutoSync = $chkAuto.Checked
        $script:Settings.MoveAfterArchive = $chkMove.Checked
        $script:Settings.LivePanel = $chkLive.Checked
        if (-not $chkLive.Checked) { Hide-LivePanel }
        Save-Settings
        Set-StartWithWindows $chkStartup.Checked
        Write-Activity 'Settings saved.'
        Set-Status (Get-IdleStatus)
        if ($archiveChanged) { Load-Matches }
        # The first copy of everything, straight away; later ones follow each sync.
        if ($backup -and $backupChanged) { Write-Activity "Backing up the game archive to $backup..."; Invoke-BackupPass }
        # A new key: send what the website does not have yet, straight away.
        if ($key -and $keyChanged) { Add-PushStep; Start-NextStep }
    } catch {
        [Windows.Forms.MessageBox]::Show($_.Exception.Message, 'MindCollector Dev') | Out-Null
    }
})

# Nav buttons switch pages
# Comps and Shuffle share one panel; CompBracket decides which comps it lists.
$pages = [ordered]@{ 'Matches' = $pageMatches; 'Improve' = $script:PageImprove; 'Comps' = $script:PageComps; 'Shuffle' = $script:PageComps; 'Classes' = $script:PageClasses; 'Activity' = $pageActivity; 'Settings' = $pageSettings }
$navButtons = @{}
function Show-Page([string]$name) {
    # Hide every panel first, then show the one picked: two tabs share a panel.
    foreach ($k in $pages.Keys) { $pages[$k].Visible = $false }
    $pages[$name].Visible = $true
    foreach ($k in $pages.Keys) {
        $navButtons[$k].ForeColor = $(if ($k -eq $name) { $C.Gold } else { $C.Muted })
        $navButtons[$k].FlatAppearance.BorderColor = $(if ($k -eq $name) { $C.Gold } else { $C.Bg })
    }
    if ($name -eq 'Improve') { Show-Improve }
    if ($name -eq 'Comps' -or $name -eq 'Shuffle') {
        $bracket = if ($name -eq 'Shuffle') { 'shuffle' } else { '3v3' }
        if ($script:CompBracket -ne $bracket) {
            $script:CompBracket = $bracket
            # A comp picked on the other tab is not in this list.
            $script:CompList.SelectedItems.Clear()
        }
        # Room for the comp names; the page takes the rest.
        if (-not $script:CompSplitLaidOut -and $script:CompSplit.Width -gt 0) { $script:CompSplit.SplitterDistance = [Math]::Min(560, [int]($script:CompSplit.Width * 0.4)); $script:CompSplitLaidOut = $true }
        Update-CompList
    }
    if ($name -eq 'Classes') {
        if (-not $script:ClassSplitLaidOut -and $script:ClassSplit.Width -gt 0) { $script:ClassSplit.SplitterDistance = [Math]::Min(600, [int]($script:ClassSplit.Width * 0.42)); $script:ClassSplitLaidOut = $true }
        Update-ClassList
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

# Which character: filters the Matches list and picks the Improve page. Your characters are found
# from the games themselves, so there is nothing to set up. A button and a dark menu rather than a
# ComboBox: a WinForms ComboBox keeps Windows' blue selection and a white arrow box whatever its
# colours are set to (2026-10-03).
Add-Type -ReferencedAssemblies System.Windows.Forms, System.Drawing -TypeDefinition @"
using System.Drawing;
using System.Windows.Forms;
public class MindCollectorMenuColors : ProfessionalColorTable {
    static readonly Color Bg = Color.FromArgb(24, 24, 30), Hover = Color.FromArgb(44, 44, 56);
    public override Color ToolStripDropDownBackground { get { return Bg; } }
    public override Color ImageMarginGradientBegin { get { return Bg; } }
    public override Color ImageMarginGradientMiddle { get { return Bg; } }
    public override Color ImageMarginGradientEnd { get { return Bg; } }
    public override Color MenuBorder { get { return Hover; } }
    public override Color MenuItemBorder { get { return Hover; } }
    public override Color MenuItemSelected { get { return Hover; } }
    public override Color MenuItemSelectedGradientBegin { get { return Hover; } }
    public override Color MenuItemSelectedGradientEnd { get { return Hover; } }
    public override Color SeparatorDark { get { return Hover; } }
    public override Color SeparatorLight { get { return Hover; } }
}
"@
# Built from char codes: this script stays plain ASCII.
$script:Arrow = [string][char]0x25BE
$script:Dot = [string][char]0x00B7

$charLabel = New-Object Windows.Forms.Label
$charLabel.Text = 'Character'; $charLabel.AutoSize = $true; $charLabel.ForeColor = $C.Muted
$charLabel.Margin = New-Object Windows.Forms.Padding(28, 8, 6, 0)
$script:CharButton = New-Button ('All characters  ' + $script:Arrow)
$script:CharButton.Margin = New-Object Windows.Forms.Padding(0, 2, 0, 0)
$script:CharMenu = New-Object Windows.Forms.ContextMenuStrip
$script:CharMenu.Renderer = New-Object Windows.Forms.ToolStripProfessionalRenderer((New-Object MindCollectorMenuColors))
$script:CharMenu.BackColor = $C.Raised; $script:CharMenu.ForeColor = $C.Ink; $script:CharMenu.ShowImageMargin = $false; $script:CharMenu.Font = $fontUi
$script:CharButton.Add_Click({ $script:CharMenu.Show($script:CharButton, 0, $script:CharButton.Height) })
$nav.Controls.AddRange(@($charLabel, $script:CharButton))

$form.Controls.AddRange(@($body, $nav, $header))

# Tray
$script:Tray = New-Object Windows.Forms.NotifyIcon
$script:Tray.Icon = $appIcon
$script:Tray.Text = 'MindCollector Dev'
$script:Tray.Visible = $true
$menu = New-Object Windows.Forms.ContextMenuStrip
[void]$menu.Items.Add('Open', $null, { $form.Show(); $form.WindowState = 'Normal'; $form.Activate() })
[void]$menu.Items.Add('Sync now', $null, { Invoke-SyncPass $true })
[void]$menu.Items.Add('Open Match Review', $null, { Open-Review })
[void]$menu.Items.Add('Show this game', $null, { $script:LiveDismissed = $null; $script:LiveOpenedByHand = $true; Update-LivePanel; Show-LivePanel $true })
[void]$menu.Items.Add('-')
[void]$menu.Items.Add('Exit', $null, { $script:Exiting = $true; [Windows.Forms.Application]::Exit() })
$script:Tray.ContextMenuStrip = $menu
$script:Tray.Add_DoubleClick({ $form.Show(); $form.WindowState = 'Normal'; $form.Activate() })

function Show-Balloon([string]$title, [string]$text) {
    $script:Tray.BalloonTipTitle = $title
    $script:Tray.BalloonTipText = $text
    $script:Tray.ShowBalloonTip(5000)
}

# --- The live panel ------------------------------------------------------------
# A small window that stays on top of WoW (in Windowed or Windowed Fullscreen mode; nothing can sit
# over exclusive fullscreen). It appears WITHOUT taking focus, so it never pulls you out of the
# game: click it when you want to type, click back into WoW to play.

# Class colours, from config/wow_classes.php.
$script:ClassColors = @{
    'Warrior' = '#C79C6E'; 'Paladin' = '#F58CBA'; 'Hunter' = '#ABD473'; 'Rogue' = '#FFF569'; 'Priest' = '#FFFFFF'
    'Death Knight' = '#C41F3B'; 'Shaman' = '#0070DE'; 'Mage' = '#69CCF0'; 'Warlock' = '#9482C9'; 'Monk' = '#00FF96'
    'Druid' = '#FF7D0A'; 'Demon Hunter' = '#A330C9'; 'Evoker' = '#33937F'
}

function Get-SpecColor([string]$specId) {
    $name = Spec-Name $specId
    foreach ($k in $script:ClassColors.Keys) { if ($name.EndsWith($k)) { return [Drawing.ColorTranslator]::FromHtml($script:ClassColors[$k]) } }
    return $C.Muted
}

# "This game" is a column on the Matches page, not a window of its own: it used to be a separate
# always-on-top panel that opened over WoW at every arena, which got in the way (2026-10-02). The
# main window can sit on another screen; this column shows while you are in an arena and never
# takes focus. Notes are typed in the Matches page's one note box.
$script:LivePane = New-Object Windows.Forms.Panel
$script:LivePane.Dock = 'Right'; $script:LivePane.Width = 380; $script:LivePane.Visible = $false
$script:LivePane.BackColor = $C.Panel; $script:LivePane.ForeColor = $C.Ink
$script:LivePane.Padding = New-Object Windows.Forms.Padding(12, 10, 12, 12)

$script:LiveTitle = New-Object Windows.Forms.Label
$script:LiveTitle.Dock = 'Top'; $script:LiveTitle.Height = 26; $script:LiveTitle.ForeColor = $C.Gold
$script:LiveTitle.Font = New-Object Drawing.Font('Segoe UI Semibold', 12)
$script:LiveClock = New-Object Windows.Forms.Label
$script:LiveClock.Dock = 'Top'; $script:LiveClock.Height = 22; $script:LiveClock.ForeColor = $C.Muted

$script:LivePlayers = New-Object Windows.Forms.ListView
$script:LivePlayers.Dock = 'Top'; $script:LivePlayers.Height = 232; $script:LivePlayers.View = 'Details'
$script:LivePlayers.HeaderStyle = 'Nonclickable'; $script:LivePlayers.FullRowSelect = $true; $script:LivePlayers.BorderStyle = 'None'
$script:LivePlayers.BackColor = $C.Raised; $script:LivePlayers.ForeColor = $C.Ink
foreach ($col in @(@('Player', 112), @('Spec', 150), @('Glad', 42), @('Best', 52))) { [void]$script:LivePlayers.Columns.Add($col[0], $col[1]) }
$script:GroupUs = New-Object Windows.Forms.ListViewGroup('Your team')
$script:GroupThem = New-Object Windows.Forms.ListViewGroup('Them')
[void]$script:LivePlayers.Groups.Add($script:GroupUs)
[void]$script:LivePlayers.Groups.Add($script:GroupThem)

$notesLabel = New-Object Windows.Forms.Label
$notesLabel.Text = 'Notes'; $notesLabel.Dock = 'Top'; $notesLabel.Height = 26; $notesLabel.ForeColor = $C.Muted
$notesLabel.Padding = New-Object Windows.Forms.Padding(0, 8, 0, 0)

$script:LiveNotes = New-Object Windows.Forms.ListBox
$script:LiveNotes.Dock = 'Fill'; $script:LiveNotes.BorderStyle = 'None'; $script:LiveNotes.IntegralHeight = $false
$script:LiveNotes.BackColor = $C.Raised; $script:LiveNotes.ForeColor = $C.Ink; $script:LiveNotes.HorizontalScrollbar = $true

# The note box under the card is this column's input too (Submit-LiveNote reads $script:LiveInput).
$script:LiveInput = $script:NoteBox
$hint = New-Object Windows.Forms.Label
$hint.Dock = 'Bottom'; $hint.Height = 40; $hint.Padding = New-Object Windows.Forms.Padding(0, 6, 0, 0); $hint.ForeColor = $C.Muted; $hint.Font = New-Object Drawing.Font('Segoe UI', 8.5)
$hint.Text = 'Type in the note box and press Enter. Ctrl+Shift+M marks a moment from in game; pick a mark here to write about it.'
$liveClose = New-Object Windows.Forms.LinkLabel
$liveClose.Text = 'Hide'; $liveClose.Dock = 'Top'; $liveClose.Height = 18; $liveClose.TextAlign = 'MiddleRight'
$liveClose.LinkColor = $C.Muted; $liveClose.ActiveLinkColor = $C.Gold

$script:LivePane.Controls.AddRange(@($script:LiveNotes, $notesLabel, $script:LivePlayers, $script:LiveClock, $script:LiveTitle, $liveClose, $hint))
$pageMatches.Controls.Add($script:LivePane)

# Shown and hidden in place; it never takes focus, so the window can sit on another screen.
$script:LiveShown = $false
function Show-LivePanel([bool]$activate) {
    $script:LivePane.Visible = $true
    $script:LiveShown = $true
    Update-NoteTarget
    if ($activate) { $form.Show(); $form.WindowState = 'Normal'; Show-Page 'Matches'; $form.Activate(); $script:NoteBox.Focus() }
}

function Hide-LivePanel {
    $script:LivePane.Visible = $false
    $script:LiveShown = $false
    Update-NoteTarget
}

# Where the note box writes: the round you are in while this game's column shows, otherwise the
# selected game. The button says which.
function Test-NotesGoLive { return $script:LiveShown -and $script:Live.Visit -and $script:Live.State -in @('prep', 'live', 'ended') }
function Update-NoteTarget {
    if (-not $btnNoteGame) { return }
    $sel = Get-SelectedLiveNote
    $btnNoteGame.Text = if (Test-NotesGoLive) {
        if ($sel -and $sel.kind -eq 'mark') { 'Write on mark' } elseif ($script:Live.Round -gt 1 -or $script:Live.Bracket -like '*Shuffle*') { "Add to round $($script:Live.Round)" } else { 'Add to this game' }
    } else { 'Add to selected game' }
}

function Submit-Note {
    if (Test-NotesGoLive) { Submit-LiveNote } else { Add-PageNote $false }
}

function Format-NoteClock($n) {
    if (-not $n.roundStart) { return 'prep' }
    $secs = ([datetime]::ParseExact($n.at, 'yyyy-MM-dd HH:mm:ss', $null) - [datetime]::ParseExact($n.roundStart, 'yyyy-MM-dd HH:mm:ss', $null)).TotalSeconds
    if ($secs -lt 0) { return 'prep' }
    return Format-Duration ([int]$secs)
}

function Update-LiveClock {
    $L = $script:Live
    $script:LiveClock.Text = switch ($L.State) {
        'prep' { 'In the prep room. The other team shows when the gates open.' }
        'live' { if ($L.RoundStartAt) { 'Gates opened ' + (Format-Duration ([int]((Get-Date) - $L.RoundStartAt).TotalSeconds)) + ' ago' } else { '' } }
        'ended' { 'Game over. Add anything you want to remember.' }
        default { 'Waiting for an arena game.' }
    }
}

function Update-LivePanel {
    if (-not $script:LivePane) { return }
    $L = $script:Live
    $bits = @($L.Zone, $L.Bracket) | Where-Object { $_ }
    if ($L.Round -gt 1 -or $L.Bracket -like '*Shuffle*') { $bits += "Round $($L.Round)" }
    $script:LiveTitle.Text = $(if ($bits) { $bits -join '  -  ' } else { 'This game' })
    Update-LiveClock

    # Players: your team (whoever shares your arena team id) first, each in their class colour.
    $script:LivePlayers.BeginUpdate()
    $script:LivePlayers.Items.Clear()
    $myTeam = if ($L.Logger -and $L.Players.Contains($L.Logger)) { $L.Players[$L.Logger].Team } else { $null }
    foreach ($guid in $L.Players.Keys) {
        $p = $L.Players[$guid]
        $shown = if ($p.Name) { Short-Name $p.Name } elseif ($p.Short) { $p.Short } else { $null }
        $name = if ($shown) { $shown + $(if ($guid -eq $L.Logger) { ' (you)' } else { '' }) } else { '...' }
        $x = if ($p.Name) { $script:Experience[$p.Name] } else { $null }
        $glad = ''; $best = ''
        if ($x -and $x.found) { $glad = [string]$x.gladSeasons; $best = $(if ($x.exp3v3) { [string]$x.exp3v3 } else { '' }) }
        elseif ($x) { $best = 'none' }
        elseif ($p.Name) { $best = '...' }
        $item = New-Object Windows.Forms.ListViewItem($name)
        [void]$item.SubItems.Add((Spec-Name $p.Spec))
        [void]$item.SubItems.Add($glad)
        [void]$item.SubItems.Add($best)
        $item.ForeColor = Get-SpecColor $p.Spec
        $item.Group = $(if ($myTeam -ne $null -and $p.Team -eq $myTeam) { $script:GroupUs } else { $script:GroupThem })
        [void]$script:LivePlayers.Items.Add($item)
    }
    $script:LivePlayers.EndUpdate()

    # This visit's notes, newest last.
    # The list shows text; $script:LiveNoteRows holds the note behind each line, by index.
    $keep = $(if ($script:LiveNotes.SelectedIndex -ge 0) { $script:LiveNoteRows[$script:LiveNotes.SelectedIndex].id } else { $null })
    $script:Rebuilding = $true
    $script:LiveNotes.BeginUpdate()
    $script:LiveNotes.Items.Clear()
    $script:LiveNoteRows = New-Object System.Collections.ArrayList
    foreach ($n in $script:Notes) {
        if (-not $L.Visit -or $n.visit -ne $L.Visit) { continue }
        $label = if ($n.kind -eq 'mark') { '[mark] ' + $(if ($n.text) { $n.text } else { '(write about it)' }) } else { $n.text }
        $round = if ($n.round -and ($L.Round -gt 1 -or $L.Bracket -like '*Shuffle*')) { "R$($n.round) " } else { '' }
        $ix = $script:LiveNotes.Items.Add(('{0}{1,-5} {2}' -f $round, (Format-NoteClock $n), $label))
        [void]$script:LiveNoteRows.Add($n)
        if ($keep -and $n.id -eq $keep) { $script:LiveNotes.SelectedIndex = $ix }
    }
    $script:LiveNotes.EndUpdate()
    $script:Rebuilding = $false
    Update-NoteTarget
}

# Enter or the button: write onto the selected mark if one is picked, otherwise a new note.
function Submit-LiveNote {
    $text = $script:LiveInput.Text.Trim()
    if (-not $text) { return }
    $sel = Get-SelectedLiveNote
    if ($sel -and $sel.kind -eq 'mark') {
        $sel.text = $text
        Save-Notes
    } else {
        [void](Add-Note 'note' $text (Get-Date))
    }
    $script:LiveInput.Text = ''
    $script:LiveNotes.ClearSelected()
    Update-LivePanel
}

function Get-SelectedLiveNote {
    $i = $script:LiveNotes.SelectedIndex
    if ($i -lt 0 -or -not $script:LiveNoteRows -or $i -ge $script:LiveNoteRows.Count) { return $null }
    return $script:LiveNoteRows[$i]
}

$script:LiveNotes.Add_SelectedIndexChanged({
    if ($script:Rebuilding) { return }
    $sel = Get-SelectedLiveNote
    Update-NoteTarget
    if ($sel -and $sel.kind -eq 'mark') { $script:LiveInput.Text = $sel.text; $script:LiveInput.Focus() }
})
# Hide: not again for this arena visit; the next arena shows it as usual, the tray menu brings it back.
$liveClose.Add_LinkClicked({
    $script:LiveDismissed = $script:Live.Visit
    $script:LiveOpenedByHand = $false
    Hide-LivePanel
})

# Closing the window keeps it watching in the tray; Exit in the tray menu quits.
$script:Exiting = $false
$form.Add_FormClosing({
    param($s, $e)
    if (-not $script:Exiting -and $e.CloseReason -eq 'UserClosing') {
        $e.Cancel = $true
        $form.Hide()
        if (-not $script:ToldAboutTray) {
            Show-Balloon 'Still watching' 'MindCollector Dev keeps running in the tray. Right-click it to exit.'
            $script:ToldAboutTray = $true
        }
    }
})

$btnSync.Add_Click({ Invoke-SyncPass $true })
$btnOpen.Add_Click({ Open-Review })
$btnNoteGame.Add_Click({ Submit-Note })
$btnNoteNext.Add_Click({ Add-PageNote $true })
# Enter writes where the main button says: the round you are in, a picked mark, or the selected game.
$script:NoteBox.Add_KeyDown({
    param($s, $e)
    if ($e.KeyCode -eq 'Enter') { $e.SuppressKeyPress = $true; Submit-Note }
})
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
        Poll-Backup
        Read-Hotkey
        Update-Live
        $script:Tick++
        if ($script:Tick % 10 -eq 0 -and $script:Settings.AutoSync) { Invoke-SyncPass $false }
        # A note added after its game was synced: rebuild the cards once things are quiet.
        if ($script:Tick % 10 -eq 5 -and $script:NotesDirty -and $script:Live.State -ne 'live' -and -not $script:Running -and $script:Steps.Count -eq 0) {
            $script:NotesDirty = $false
            Add-CardsStep; Start-NextStep
        }
    } catch {
        $script:Running = $null
        Write-Activity "Error: $($_.Exception.Message)"
    }
})

$form.Add_Shown({
    if (-not $script:Laidout) {
        # Wide enough for the list's columns; the card takes the rest.
        $split.SplitterDistance = [Math]::Min(580, [int]($split.Width * 0.42))
        $script:Laidout = $true
        Show-Html (Get-PlainPage "<p class='muted'>Pick a game to see who you played, how each death happened, and what to look at.</p>")
    }
})

# The message loop runs without owning the form, so the window can come and go while the
# tray icon keeps watching. Started with -Minimized (Windows startup), the window stays hidden.
Show-Page 'Matches'
Set-Status (Get-IdleStatus)
Write-Activity "Started. Repo: $($script:Repo)"
Load-Notes
$script:NotesDirty = $false
Load-Matches
Load-Cards
$script:Hotkey = New-Object MindCollectorHotkey(6, 0x4D)   # Ctrl (2) + Shift (4) + M
if (-not $script:Hotkey.Registered) { Write-Activity 'Ctrl+Shift+M is taken by another program, so marking a moment from in game is off.' }
# First run, or the cards file was cleared: build it once from what is already synced.
if ($script:CardsStale -and $script:Php) { Add-CardsStep; Start-NextStep }
$timer.Start()
if (-not $Minimized) { $form.Show() }

try {
    [Windows.Forms.Application]::Run()
} finally {
    $timer.Stop()
    if ($script:Hotkey) { $script:Hotkey.Dispose() }
    $script:Tray.Visible = $false
    $script:Tray.Dispose()
    if ($script:Server -and -not $script:Server.HasExited) { Stop-Process -Id $script:Server.Id -Force -ErrorAction SilentlyContinue }
    $script:Mutex.ReleaseMutex()
}

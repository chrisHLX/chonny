# Speaks a text file with one of Windows' own voices, and shows a small window while it does:
# Pause / Resume, Stop, and Mute replies (makes the "off" file, so later replies stay quiet).
# Started by speak_reply.py; killing this process (your next message does) stops it all.
param([string]$TextFile, [string]$Voice = "George", [double]$Rate = 1.0)

$text = [System.IO.File]::ReadAllText($TextFile, [System.Text.Encoding]::UTF8)
$wav = Join-Path $env:TEMP ("claude-reply-" + [guid]::NewGuid().ToString("N").Substring(0, 8) + ".wav")

# 1. The speech, into a wave file: the newer OneCore voices (George, Susan...), else the older ones.
try {
    Add-Type -AssemblyName System.Runtime.WindowsRuntime
    $asTaskGeneric = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
        $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1' })[0]
    [Windows.Media.SpeechSynthesis.SpeechSynthesizer, Windows.Media.SpeechSynthesis, ContentType = WindowsRuntime] | Out-Null
    $synth = New-Object Windows.Media.SpeechSynthesis.SpeechSynthesizer
    $v = [Windows.Media.SpeechSynthesis.SpeechSynthesizer]::AllVoices | Where-Object { $_.DisplayName -like "*$Voice*" } | Select-Object -First 1
    if ($v) { $synth.Voice = $v }
    $synth.Options.SpeakingRate = $Rate
    $task = $asTaskGeneric.MakeGenericMethod([Windows.Media.SpeechSynthesis.SpeechSynthesisStream]).Invoke($null, @($synth.SynthesizeTextToStreamAsync($text)))
    $task.Wait(-1) | Out-Null
    $src = [System.IO.WindowsRuntimeStreamExtensions]::AsStreamForRead($task.Result)
    $fs = [System.IO.File]::Create($wav); $src.CopyTo($fs); $fs.Close()
}
catch {
    "$(Get-Date -Format s) newer voice failed: $($_.Exception.Message)" | Out-File -Append -Encoding utf8 (Join-Path $PSScriptRoot "speak.log")
    Add-Type -AssemblyName System.Speech
    $s = New-Object System.Speech.Synthesis.SpeechSynthesizer
    $s.Rate = [int](($Rate - 1) * 10)
    $s.SetOutputToWaveFile($wav); $s.Speak($text); $s.Dispose()
}

# 2. Play it, with the little window.
Add-Type -AssemblyName PresentationFramework, PresentationCore, WindowsBase
$player = New-Object System.Windows.Media.MediaPlayer
$player.Open([Uri]$wav)

$gold = [System.Windows.Media.BrushConverter]::new().ConvertFromString("#D4AF37")
$ink = [System.Windows.Media.BrushConverter]::new().ConvertFromString("#E6E6E6")
$bg = [System.Windows.Media.BrushConverter]::new().ConvertFromString("#121212")
$raised = [System.Windows.Media.BrushConverter]::new().ConvertFromString("#2A2A2A")

$win = New-Object System.Windows.Window
$win.Title = "Claude is speaking"
$win.Width = 330; $win.SizeToContent = "Height"
$win.WindowStyle = "None"; $win.ResizeMode = "NoResize"; $win.AllowsTransparency = $false
$win.Topmost = $true; $win.ShowInTaskbar = $false; $win.ShowActivated = $false
$win.Background = $bg; $win.BorderBrush = $gold; $win.BorderThickness = 1
$area = [System.Windows.SystemParameters]::WorkArea
$win.Left = $area.Right - 330 - 16; $win.Top = $area.Bottom - 120 - 16

$panel = New-Object System.Windows.Controls.StackPanel
$panel.Margin = "12,10,12,10"
$title = New-Object System.Windows.Controls.TextBlock
$title.Text = "Claude is reading its reply"; $title.Foreground = $gold; $title.FontWeight = "Bold"; $title.FontSize = 13
$preview = New-Object System.Windows.Controls.TextBlock
$first = ($text -split "`n")[0]; if ($first.Length -gt 90) { $first = $first.Substring(0, 90) + "..." }
$preview.Text = $first; $preview.Foreground = $ink; $preview.FontSize = 12; $preview.TextWrapping = "Wrap"; $preview.Margin = "0,4,0,8"
$row = New-Object System.Windows.Controls.StackPanel
$row.Orientation = "Horizontal"

function Btn($label) {
    $b = New-Object System.Windows.Controls.Button
    $b.Content = $label; $b.Margin = "0,0,8,0"; $b.Padding = "10,3,10,3"
    $b.Background = $raised; $b.Foreground = $ink; $b.BorderBrush = $raised; $b.Focusable = $false
    $b
}
$pause = Btn "Pause"
$stop = Btn "Stop"
$mute = Btn "Mute replies"
$script:paused = $false
$pause.Add_Click({
    if ($script:paused) { $player.Play(); $pause.Content = "Pause"; $script:paused = $false }
    else { $player.Pause(); $pause.Content = "Resume"; $script:paused = $true }
})
$stop.Add_Click({ $player.Stop(); $win.Close() })
$mute.Add_Click({
    New-Item -ItemType File -Force (Join-Path $PSScriptRoot "off") | Out-Null
    $player.Stop(); $win.Close()
})
$row.Children.Add($pause) | Out-Null; $row.Children.Add($stop) | Out-Null; $row.Children.Add($mute) | Out-Null
$panel.Children.Add($title) | Out-Null; $panel.Children.Add($preview) | Out-Null; $panel.Children.Add($row) | Out-Null
$win.Content = $panel

$player.Add_MediaEnded({ $win.Close() })
$player.Add_MediaFailed({ $win.Close() })
$win.Add_Loaded({ $player.Play() })
$win.Add_MouseLeftButtonDown({ try { $win.DragMove() } catch {} })   # drag it out of the way
$win.ShowDialog() | Out-Null

$player.Close()
Remove-Item $wav -ErrorAction SilentlyContinue

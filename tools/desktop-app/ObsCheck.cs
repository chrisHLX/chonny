using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text.Json.Nodes;

namespace MindCollector.Desktop;

/// <summary>
/// Setting OBS up so recording simply works, and proving it before saying so (2026-11-12, after Cbags
/// hit "OBS's replay buffer was off ... close OBS and open it again"). Chriso: OBS stays the recorder
/// (the app's own, NativeRecorder, measured 3.5% of WoW's frame rate against OBS's none), so the work
/// is making OBS's setup and recording smooth.
///
/// <see cref="SetUpAndTestAsync"/> fixes what it can itself instead of telling the player to: installs
/// OBS, turns its remote control on, sets the recording format to MP4, turns the replay buffer on, and
/// restarts OBS when a setting only takes effect as OBS starts. It never restarts an OBS that is
/// recording or streaming something the player started. Then it records a short test and checks the
/// file: it plays for the right length, it has a picture and sound, and the picture isn't black when
/// WoW is open. Only then does it say ready.
/// </summary>
public static class ObsCheck
{
    public sealed record Outcome(bool Ready, IReadOnlyList<string> Lines);

    /// <summary>
    /// Sets OBS up and tests it. <paramref name="ask"/> puts a yes/no question to the player (a restart);
    /// <paramref name="progress"/> says what is happening. <paramref name="forever"/>: also test the
    /// replay buffer WoW Forever's fights are saved from.
    /// </summary>
    public static async Task<Outcome> SetUpAndTestAsync(AppSettings settings, bool forever, Func<string, bool> ask, Action<string> progress, CancellationToken ct)
    {
        var lines = new List<string>();
        void Say(string line) { lines.Add(line); progress(line); }

        // 1. remote control: an open OBS with it off can only be fixed by closing it
        if (ObsSetup.Running() && !ObsSetup.ReadConfig().Enabled)
        {
            if (!ask("OBS's remote control is off, and the app needs it to record. OBS has to close and open again to turn it on.\n\nIf OBS is recording or streaming something, that will stop. Restart OBS now?"))
                return new(false, [.. lines, "OBS's remote control is off. Click Set up OBS again when OBS can restart."]);
            progress("Restarting OBS to turn on its remote control…");
            var (ok, why) = await RestartAsync(() => ObsSetup.EnableServer(out _), ct);
            if (!ok) return new(false, [.. lines, why]);
            Say("Turned on OBS's remote control (restarted OBS).");
        }

        progress("Connecting to OBS…");
        var (obs, message) = await ObsSetup.ConnectAsync(openIfClosed: true, ct);
        if (obs == null) return new(false, [.. lines, message]);
        string? encoderWas = null;
        try
        {
            Say(message);
            var added = await ObsSetup.EnsureWowCaptureAsync(obs, ct);
            if (added != null) Say(added);
            var format = await EnsureMp4Async(obs, ct);
            if (format != null) Say(format);

            // 2. what OBS reads only as it starts, gathered so one restart (asked once) does it all
            var fixes = new List<string>();
            var edits = new List<Func<List<string>, bool>>();
            var bufferWasRunning = false;
            if (forever)
            {
                var status = await obs.TryRequestAsync("GetReplayBufferStatus", null, ct);
                bufferWasRunning = status is { } st && st.TryGetProperty("outputActive", out var a) && a.GetBoolean();
                if (status == null)
                {
                    fixes.Add("turn on its replay buffer, which WoW Forever's fights are saved from");
                    edits.Add(lines => { ObsSetup.SetReplayBufferInProfile(true, settings.ForeverBufferSeconds, out _); return false; });
                }
            }
            var encoder = await EncoderFixAsync(obs, ct);
            if (encoder != null)
            {
                fixes.Add(encoder.Value.Why);
                edits.Add(encoder.Value.Edit);
            }
            if (fixes.Count > 0)
            {
                var needed = forever && fixes[0].Contains("replay buffer");
                if (await BusyAsync(obs, ct))
                {
                    if (needed) return new(false, [.. lines, "OBS's replay buffer is off, and OBS is recording or streaming right now. When that's done, click Set up OBS again and the app will restart OBS to turn it on."]);
                    Say("OBS is recording or streaming, so its encoder was left as it is.");
                }
                else if (!ask($"OBS has to close and open again to {string.Join(", and to ", fixes)}. It isn't recording or streaming now.\n\nRestart OBS now?"))
                {
                    if (needed) return new(false, [.. lines, "OBS's replay buffer is off, so Forever fights won't be saved. Click Set up OBS again when OBS can restart."]);
                    Say("Left OBS's encoder as it is.");
                }
                else
                {
                    progress("Restarting OBS…");
                    await obs.DisposeAsync();
                    obs = null;
                    var (ok, why) = await RestartAsync(() =>
                    {
                        foreach (var edit in edits) ObsSetup.EditProfile(edit, out _, out _);
                    }, ct);
                    if (!ok) return new(false, [.. lines, why]);
                    (obs, message) = await ObsSetup.ConnectAsync(openIfClosed: true, ct);
                    if (obs == null) return new(false, [.. lines, message]);
                    if (encoder != null) encoderWas = encoder.Value.Was;
                    Say($"Restarted OBS to {string.Join(", and to ", fixes)}.");
                }
            }

            // 3a. the Forever test. A buffer already running proves itself, and saving it would write
            // the whole ten minutes at once (a freeze in WoW on 12 Nov); one the app has just started
            // is saved after a few seconds, a small clip.
            if (forever)
            {
                var (ours, problem, needsRestart) = await ObsSetup.StartReplayBufferAsync(obs, settings.ForeverBufferSeconds, ct);
                if (problem != null) return new(false, [.. lines, problem]);
                if (bufferWasRunning && !ours) Say("Forever fights: OBS's replay buffer is running.");
                else
                {
                    progress("Testing a Forever fight save (5 seconds)…");
                    await Task.Delay(TimeSpan.FromSeconds(5), ct);
                    var saved = await SaveReplayAsync(obs, ct);
                    if (ours && !ForeverOpen()) await obs.TryRequestAsync("StopReplayBuffer", null, ct);
                    if (saved == null) return new(false, [.. lines, "OBS didn't save a clip from its replay buffer. Check OBS's Settings > Output > Recording path exists."]);
                    var check = CheckVideo(saved, TimeSpan.FromSeconds(2), WowOpen());
                    TryDelete(saved);
                    if (check.Problem != null) return new(false, [.. lines, $"Forever test: {check.Problem}"]);
                    Say($"Forever fights: OBS saved a test clip ({check.Summary}).");
                }
            }

            // 3b. the arena test (retail and Forever alike): a short recording the way a game gets one
            await obs.DisposeAsync();
            obs = null;
            var arena = await TestRecordingAsync(settings, progress, ct);
            if (arena.Problem != null && encoderWas != null)
            {
                // the graphics card's encoder didn't work here: put back what OBS had and try again
                Say($"The test failed with the graphics card's encoder ({arena.Problem}), so OBS's encoder was put back.");
                var was = encoderWas;
                encoderWas = null;
                var (ok, why) = await RestartAsync(() => ObsSetup.EditProfile(l => RestoreEncoder(l, was), out _, out _), ct);
                if (!ok) return new(false, [.. lines, why]);
                arena = await TestRecordingAsync(settings, progress, ct);
            }
            if (arena.Problem != null) return new(false, [.. lines, $"Test recording: {arena.Problem}"]);
            Say($"Arena games: the test recording worked ({arena.Summary}).");
            if (!WowOpen()) Say("WoW isn't open, so the picture couldn't be checked: OBS records it once the game is running.");
            return new(true, lines);
        }
        catch (Exception e) when (e is not OperationCanceledException)
        {
            return new(false, [.. lines, $"OBS setup stopped: {e.Message}"]);
        }
        finally
        {
            if (obs != null) await obs.DisposeAsync();
        }
    }

    private static async Task<VideoCheck> TestRecordingAsync(AppSettings settings, Action<string> progress, CancellationToken ct)
    {
        progress("Testing a recording (6 seconds)…");
        var since = DateTime.Now.AddSeconds(-2);
        var log = new List<string>();
        var recorder = new Recorder(settings, log.Add);
        await recorder.RecordForAsync(TimeSpan.FromSeconds(6));
        var dir = Recorder.VideosDir(settings);
        var video = Directory.Exists(dir)
            ? new DirectoryInfo(dir).GetFiles("*.mp4").Where(f => f.CreationTime >= since).OrderByDescending(f => f.CreationTime).FirstOrDefault()
            : null;
        if (video == null) return new("it didn't save a video. " + (log.LastOrDefault() ?? ""), "");
        // OBS takes a moment to start writing (about 2s here, longer cold): proof is that it recorded
        var check = CheckVideo(video.FullName, TimeSpan.FromSeconds(1), WowOpen());
        TryDelete(video.FullName);
        TryDelete(video.FullName[..^4] + ".mindcollector.json");
        return check;
    }

    // ---------------------------------------------------------------- the encoder

    /// <summary>
    /// Recordings on the graphics card's encoder at OBS's "High Quality, Medium File Size" (Simple mode's
    /// RecQuality=Small, as Chriso's OBS records), when OBS is in Simple mode and would record on the
    /// processor (x264) or "same as stream" (the stream's bitrate, low for a recording: 2500-6000 kbps).
    /// Advanced mode is the player's own setup and is left alone. Null when nothing needs changing, or
    /// the PC has no graphics card OBS knows an encoder for. Was: what to put back if the test fails.
    /// </summary>
    private static async Task<(string Why, Func<List<string>, bool> Edit, string Was)?> EncoderFixAsync(ObsClient obs, CancellationToken ct)
    {
        var mode = await ObsSetup.ProfileParameterAsync(obs, "Output", "Mode", ct);
        if (string.Equals(mode, "Advanced", StringComparison.OrdinalIgnoreCase)) return null;
        var quality = await ObsSetup.ProfileParameterAsync(obs, "SimpleOutput", "RecQuality", ct) ?? "Stream";
        var encoder = await ObsSetup.ProfileParameterAsync(obs, "SimpleOutput", "RecEncoder", ct) ?? "x264";
        var stream = await ObsSetup.ProfileParameterAsync(obs, "SimpleOutput", "StreamEncoder", ct) ?? "x264";
        var used = quality == "Stream" ? stream : encoder;
        var software = used.StartsWith("x264", StringComparison.OrdinalIgnoreCase);
        if (!software && quality != "Stream") return null;
        var hardware = ObsSetup.HardwareEncoder();
        var target = software ? hardware ?? encoder : encoder;
        if (quality != "Stream" && target == encoder) return null;
        var why = software && hardware != null
            ? "record on your graphics card instead of the processor (smoother games, sharper video)"
            : "record at high quality instead of the stream's low bitrate";
        return (why, lines =>
        {
            var changed = ObsSetup.SetIni(lines, "SimpleOutput", "RecQuality", "Small", v => v == "Small");
            changed |= ObsSetup.SetIni(lines, "SimpleOutput", "RecEncoder", target, v => v == target);
            return changed;
        }, $"{quality}|{encoder}");
    }

    private static bool RestoreEncoder(List<string> lines, string was)
    {
        var parts = was.Split('|');
        var changed = ObsSetup.SetIni(lines, "SimpleOutput", "RecQuality", parts[0], v => v == parts[0]);
        changed |= ObsSetup.SetIni(lines, "SimpleOutput", "RecEncoder", parts[1], v => v == parts[1]);
        return changed;
    }

    // ---------------------------------------------------------------- restarting OBS

    /// <summary>Whether OBS is recording, streaming or running its virtual camera: never restarted then.</summary>
    public static async Task<bool> BusyAsync(ObsClient obs, CancellationToken ct)
    {
        foreach (var request in new[] { "GetRecordStatus", "GetStreamStatus", "GetVirtualCamStatus" })
        {
            var r = await obs.TryRequestAsync(request, null, ct);
            if (r is { } v && v.TryGetProperty("outputActive", out var a) && a.GetBoolean()) return true;
        }
        return false;
    }

    /// <summary>
    /// Closes OBS the way its own close button does (so it saves its settings and leaves no crash
    /// marker), runs <paramref name="whileClosed"/> (a setting OBS only reads as it starts), and opens
    /// it again in the tray. Only after <see cref="BusyAsync"/> said no, or the player said yes. A
    /// running replay buffer is stopped first, since OBS asks before closing with one going.
    /// </summary>
    public static async Task<(bool Ok, string Message)> RestartAsync(Action? whileClosed, CancellationToken ct)
    {
        try
        {
            var (obs, _) = await ObsSetup.ConnectAsync(openIfClosed: false, ct);
            if (obs != null)
            {
                await using (obs)
                {
                    if (await BusyAsync(obs, ct)) return (false, "OBS is recording or streaming, so it was left open. When that's done, try again.");
                    // Stopping takes a moment; closing before it has asks "outputs active, exit anyway?"
                    // (12 Nov on Chriso's PC), so wait until OBS says it has stopped.
                    await obs.TryRequestAsync("StopReplayBuffer", null, ct);
                    for (var i = 0; i < 40; i++)
                    {
                        var st = await obs.TryRequestAsync("GetReplayBufferStatus", null, ct);
                        if (st is not { } s || !s.TryGetProperty("outputActive", out var a) || !a.GetBoolean()) break;
                        await Task.Delay(250, ct);
                    }
                }
            }
        }
        catch (Exception e) when (e is not OperationCanceledException) { }

        var processes = Process.GetProcessesByName("obs64").Concat(Process.GetProcessesByName("obs32")).ToList();
        foreach (var p in processes)
            foreach (var hwnd in WindowsOf(p.Id, "OBS ")) PostMessage(hwnd, 0x0010 /* WM_CLOSE */, IntPtr.Zero, IntPtr.Zero);
        var until = DateTime.UtcNow.AddSeconds(20);
        while (ObsSetup.Running() && DateTime.UtcNow < until)
        {
            await Task.Delay(250, ct);
            // OBS asking "outputs are active, exit anyway?": answer no and leave OBS as it was, so no
            // question is left on the player's screen
            var asking = processes.SelectMany(p => WindowsOf(p.Id, "Active Outputs")).ToList();
            if (asking.Count > 0)
            {
                foreach (var hwnd in asking) PostMessage(hwnd, 0x0010, IntPtr.Zero, IntPtr.Zero);
                return (false, "OBS has something running it wouldn't close with (a recording, stream or replay buffer), so it was left open. Stop it in OBS, then try again.");
            }
        }
        if (ObsSetup.Running()) return (false, "OBS didn't close (it may be asking something on screen). Close it yourself, then click Set up OBS again.");

        whileClosed?.Invoke();
        return ObsSetup.Launch(out var message) ? (true, message) : (false, message);
    }

    [DllImport("user32.dll")] private static extern bool EnumWindows(EnumWindowsProc callback, IntPtr param);
    [DllImport("user32.dll")] private static extern uint GetWindowThreadProcessId(IntPtr hwnd, out int processId);
    [DllImport("user32.dll")] private static extern bool PostMessage(IntPtr hwnd, uint message, IntPtr wParam, IntPtr lParam);
    [DllImport("user32.dll")] private static extern bool IsWindowVisible(IntPtr hwnd);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern int GetWindowText(IntPtr hwnd, System.Text.StringBuilder text, int max);
    private delegate bool EnumWindowsProc(IntPtr hwnd, IntPtr param);

    /// <summary>
    /// A process's top-level windows whose title starts with <paramref name="title"/>: OBS's main window
    /// ("OBS 33.0.1 - Profile: ..."), there even while it sits in the tray, or its "Active Outputs" question.
    /// </summary>
    private static List<IntPtr> WindowsOf(int processId, string title)
    {
        var found = new List<IntPtr>();
        EnumWindows((hwnd, _) =>
        {
            GetWindowThreadProcessId(hwnd, out var pid);
            if (pid != processId) return true;
            var text = new System.Text.StringBuilder(256);
            GetWindowText(hwnd, text, 256);
            if (text.ToString().StartsWith(title, StringComparison.Ordinal) && IsWindowVisible(hwnd) || (title == "OBS " && text.ToString().StartsWith(title, StringComparison.Ordinal))) found.Add(hwnd);
            return true;
        }, IntPtr.Zero);
        return found;
    }

    // ---------------------------------------------------------------- the settings it fixes

    /// <summary>
    /// MP4 (or Hybrid MP4) recordings, which the app's player opens; MKV, OBS's old default, it can't.
    /// Changed through OBS's remote control, read by OBS as each recording starts. A line when it changed.
    /// </summary>
    private static async Task<string?> EnsureMp4Async(ObsClient obs, CancellationToken ct)
    {
        var format = await ObsSetup.RecordFormatAsync(obs, ct);
        if (ObsSetup.Playable(format)) return null;
        var mode = await ObsSetup.ProfileParameterAsync(obs, "Output", "Mode", ct);
        var section = string.Equals(mode, "Advanced", StringComparison.OrdinalIgnoreCase) ? "AdvOut" : "SimpleOutput";
        foreach (var key in new[] { "RecFormat2", "RecFormat" })
            await obs.TryRequestAsync("SetProfileParameter", new JsonObject { ["parameterCategory"] = section, ["parameterName"] = key, ["parameterValue"] = "mp4" }, ct);
        return $"Set OBS to record MP4 (it was {format}), which the app can play.";
    }

    // ---------------------------------------------------------------- the test

    private static async Task<string?> SaveReplayAsync(ObsClient obs, CancellationToken ct)
    {
        var asked = DateTime.Now;
        await obs.RequestAsync("SaveReplayBuffer", null, ct);
        for (var i = 0; i < 40; i++)
        {
            await Task.Delay(250, ct);
            var last = await obs.TryRequestAsync("GetLastReplayBufferReplay", null, ct);
            if (last is { } l && l.TryGetProperty("savedReplayPath", out var p) && p.GetString() is { Length: > 0 } saidPath
                && Path.GetFullPath(saidPath) is var path
                && File.Exists(path) && File.GetLastWriteTime(path) >= asked.AddSeconds(-1))
            {
                // OBS may still be writing it for a moment
                var size = -1L;
                for (var j = 0; j < 20 && size != new FileInfo(path).Length; j++) { size = new FileInfo(path).Length; await Task.Delay(250, ct); }
                return path;
            }
        }
        return null;
    }

    public sealed record VideoCheck(string? Problem, string Summary);

    /// <summary>
    /// A test video as Windows reads it: at least <paramref name="atLeast"/> long, a picture, sound, and,
    /// when WoW is open, a picture that isn't black (a capture that found no game records black).
    /// </summary>
    public static VideoCheck CheckVideo(string path, TimeSpan atLeast, bool wantPicture)
    {
        try
        {
            // OBS gives its paths with forward slashes (D:/Replay ...), which Windows' reader refuses
            path = Path.GetFullPath(path);
            var file = Windows.Storage.StorageFile.GetFileFromPathAsync(path).AsTask().GetAwaiter().GetResult();
            var clip = Windows.Media.Editing.MediaClip.CreateFromFileAsync(file).AsTask().GetAwaiter().GetResult();
            var length = clip.OriginalDuration;
            var v = clip.GetVideoEncodingProperties();
            var fps = v.FrameRate.Numerator / (double)Math.Max(v.FrameRate.Denominator, 1);
            var summary = $"{length.TotalSeconds:F1}s, {v.Width}x{v.Height} at {fps:F0} fps, {(clip.EmbeddedAudioTracks.Count > 0 ? "with sound" : "no sound")}";
            if (length < atLeast) return new($"the video is only {length.TotalSeconds:F1}s long", summary);
            if (v.Width == 0) return new("the video has no picture", summary);
            if (clip.EmbeddedAudioTracks.Count == 0) return new("the video has no sound: check OBS's Desktop Audio isn't muted or disabled", summary);
            if (wantPicture && IsBlack(clip)) return new("the picture is black: OBS isn't capturing WoW. In OBS, check the scene shows the game", summary);
            return new(null, summary);
        }
        catch (Exception e) { return new($"Windows couldn't read the video ({e.Message})", ""); }
    }

    /// <summary>A frame from the middle with nothing brighter than near-black anywhere on it.</summary>
    private static bool IsBlack(Windows.Media.Editing.MediaClip clip)
    {
        var comp = new Windows.Media.Editing.MediaComposition();
        comp.Clips.Add(clip);
        using var still = comp.GetThumbnailAsync(clip.OriginalDuration / 2, 320, 180, Windows.Media.Editing.VideoFramePrecision.NearestFrame).AsTask().GetAwaiter().GetResult();
        using var stream = still.AsStreamForRead();
        using var bitmap = new System.Drawing.Bitmap(stream);
        var brightest = 0;
        for (var y = 0; y < bitmap.Height; y += 4)
            for (var x = 0; x < bitmap.Width; x += 4)
            {
                var c = bitmap.GetPixel(x, y);
                brightest = Math.Max(brightest, (c.R * 299 + c.G * 587 + c.B * 114) / 1000);
            }
        return brightest < 24;
    }

    private static bool WowOpen() => Process.GetProcessesByName("Wow").Length > 0 || Process.GetProcessesByName("WowClassic").Length > 0;
    private static bool ForeverOpen() => Process.GetProcessesByName("Wow").Any(p => { try { return p.MainModule?.FileName.Contains("_forever_", StringComparison.OrdinalIgnoreCase) == true; } catch { return false; } });

    private static void TryDelete(string path) { try { if (File.Exists(path)) File.Delete(path); } catch { } }
}

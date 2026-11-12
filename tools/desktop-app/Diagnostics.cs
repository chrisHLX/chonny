using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace MindCollector.Desktop;

/// <summary>
/// Switches that run without the window and write what they found to a text file in the app's
/// data folder (MINDCOLLECTOR_DATA, or %APPDATA%\MindCollector Desktop):
///
///   MindCollector.exe --check-log "path\to\WoWCombatLog-....txt"
///       Lists every arena round the app would send from that log: when it starts, its bracket and
///       its size. Sends nothing. What a tester runs when "my games did not show up".
///
///   MindCollector.exe --install-addon --logs FOLDER [--replace] [--forever]
///       Installs or updates the arena-log addon for that WoW Logs folder, as the app does at start;
///       with --forever, WoW Forever's addon (FOLDER is then ...\_forever_\Logs).
///
///   MindCollector.exe --sync-once --server URL --key KEY --logs FOLDER [--move FOLDER]
///       One sync pass with these settings, as the window's timer would run it. For checking a
///       build end to end against a test server.
///
///   MindCollector.exe --check-recorder LOG [LOG...]
///       Replays logs through the recorder's rules and lists where each video would start and stop
///       and where each round would fall in it. Records nothing.
///
///   MindCollector.exe --obs-test [SECONDS]
///       Finds OBS, opens it, connects, and says what it records. With SECONDS, records that long
///       into the videos folder exactly as a game would be (timing file included).
///
///   MindCollector.exe --check-forever LOG
///       Replays a WoW Forever log and lists every PvP fight the app would save. Records nothing.
///
///   MindCollector.exe --replay-test [SECONDS]
///       Starts OBS's replay buffer, waits, saves one clip as a Forever fight would be, and stops.
/// </summary>
internal static class Diagnostics
{
    public static int CheckLog(string path)
    {
        var report = new StringBuilder();
        try
        {
            var scan = RoundScanner.Scan(path, 0, closeOpenRound: true);
            report.AppendLine($"{Path.GetFileName(path)}: {scan.Rounds.Count} round(s)");
            foreach (var r in scan.Rounds)
            {
                var first = r.Text.AsSpan(0, r.Text.IndexOf('\n') is var i && i > 0 ? i : r.Text.Length).ToString();
                var stamp = first.Split("  ")[0];
                var bracket = first.Split(',').ElementAtOrDefault(3) ?? "?";
                var lines = r.Text.Count(c => c == '\n') + 1;
                var ended = r.Text.Contains("ARENA_MATCH_END,") ? "" : "  (closed by the next round's start)";
                report.AppendLine($"  {stamp,-26} {bracket,-20} {lines,7} lines  {Encoding.UTF8.GetByteCount(r.Text) / 1024,6} KB{ended}");
            }
            Write("check-log.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("check-log.txt", report + $"Error: {e.Message}");
            return 1;
        }
    }

    public static int SyncOnce(string[] args)
    {
        string? Arg(string name) => Array.IndexOf(args, name) is var i && i >= 0 && i + 1 < args.Length ? args[i + 1] : null;
        var log = new StringBuilder();

        var settings = AppSettings.Load();
        settings.ServerUrl = Arg("--server") ?? settings.ServerUrl;
        if (Arg("--key") is { } key) settings.Key = key;
        settings.WowLogsDir = Arg("--logs") ?? settings.WowLogsDir;
        if (Arg("--move") is { } move) settings.MoveTo = move;
        settings.Save();

        try
        {
            var syncer = new Syncer(settings, line => log.AppendLine(line));
            syncer.Progress += p => log.AppendLine($"  progress: log {p.LogsDone} of {p.LogsTotal}, {p.Sent} sent{(p.PagesAsked ? ", pages asked for" : "")}");
            var result = syncer.RunAsync(manual: true, CancellationToken.None).GetAwaiter().GetResult();
            log.AppendLine($"Result: sent {result.Sent}, skipped {result.Skipped}, failed {result.Failed}, done called: {result.CalledDone}");
            Write("sync-once.txt", log.ToString());
            return result.Failed > 0 ? 2 : 0;
        }
        catch (Exception e)
        {
            Write("sync-once.txt", log + $"Error: {e.GetType().Name}: {e.Message}");
            return 1;
        }
    }

    /// <summary>`--install-addon --logs FOLDER [--replace]`: what the app does at start, reported to install-addon.txt.</summary>
    public static int InstallAddon(string logsDir, bool replace, bool forever = false, bool observer = false)
    {
        try
        {
            var outcome = AddonInstaller.Ensure(observer ? AddonInstaller.Observer : forever ? AddonInstaller.Forever : AddonInstaller.Arena, logsDir, replace);
            Write("install-addon.txt", $"{outcome.State}: {outcome.Message}{Environment.NewLine}{outcome.Folder}");
            return outcome.State is AddonInstaller.State.NoWow or AddonInstaller.State.DifferentCopy ? 2 : 0;
        }
        catch (Exception e)
        {
            Write("install-addon.txt", $"Error: {e.Message}");
            return 1;
        }
    }

    /// <summary>
    /// `--check-recorder LOG [LOG...]`: replays logs through GameTrigger on their own clock, as if
    /// each had appeared while the app ran, and reports when each video would start and stop and
    /// where each round would fall in it. Records nothing. Logs are taken in the order given.
    /// </summary>
    public static int CheckRecorder(IEnumerable<string> paths)
    {
        var report = new StringBuilder();
        var trigger = new GameTrigger();
        DateTime cursor = DateTime.MinValue, videoStart = DateTime.MinValue;
        var videos = 0;

        void TickUntil(DateTime target)
        {
            while (trigger.Recording && cursor < target)
            {
                cursor = cursor.AddSeconds(1);
                var (act, why) = trigger.OnTick(cursor, wowRunning: true);
                if (act == GameTrigger.Act.None) continue;
                var length = cursor - videoStart;
                report.AppendLine(act == GameTrigger.Act.Discard
                    ? $"  {cursor:HH:mm:ss}  thrown away ({why}) after {length:mm\\:ss}"
                    : $"  {cursor:HH:mm:ss}  stop: {why}. Video {length:h\\:mm\\:ss} long, {trigger.Rounds.Count} round(s), {trigger.Bracket}");
                if (act == GameTrigger.Act.Stop) videos++;
                trigger.Reset();
            }
            if (cursor < target) cursor = target;
        }

        try
        {
            foreach (var path in paths)
            {
                using var reader = new StreamReader(new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete));
                var first = true;
                string? line;
                while ((line = reader.ReadLine()) != null)
                {
                    if (GameTrigger.LineTime(line) is not { } t) continue;
                    TickUntil(t);
                    if (first)
                    {
                        first = false;
                        report.AppendLine($"{Path.GetFileName(path)} (first line {t:dd MMM HH:mm:ss})");
                        if (trigger.OnArriving(t) == GameTrigger.Act.Start)
                        {
                            videoStart = t;
                            report.AppendLine($"  {t:HH:mm:ss}  start: a new log appeared");
                        }
                    }
                    trigger.OnActivity(t);
                    var kind = GameTrigger.Classify(line);
                    if (kind == GameTrigger.LineKind.Arriving && trigger.OnArriving(t) == GameTrigger.Act.Start)
                    {
                        videoStart = t;
                        report.AppendLine($"  {t:HH:mm:ss}  start: zoning into arena {GameTrigger.ZoneOf(line)}");
                    }
                    else if (kind == GameTrigger.LineKind.Start)
                    {
                        if (trigger.EndsUnfinishedGame(GameTrigger.BracketOf(line)))
                        {
                            report.AppendLine($"  {t:HH:mm:ss}  stop: the next game started (no END). Video {t - videoStart:h\\:mm\\:ss} long, {trigger.Rounds.Count} round(s), {trigger.Bracket}");
                            videos++;
                            trigger.Reset();
                        }
                        var wasRecording = trigger.Recording;
                        trigger.OnMatchStart(t, t, GameTrigger.BracketOf(line));
                        if (!wasRecording)
                        {
                            videoStart = t;
                            report.AppendLine($"  {t:HH:mm:ss}  start: ARENA_MATCH_START");
                        }
                        report.AppendLine($"  {t:HH:mm:ss.fff}  round {trigger.Rounds.Count} ({GameTrigger.BracketOf(line)}) at {(t - videoStart).TotalSeconds:F1}s into the video");
                    }
                    else if (kind == GameTrigger.LineKind.End)
                    {
                        trigger.OnMatchEnd(t);
                        report.AppendLine($"  {t:HH:mm:ss}  ARENA_MATCH_END");
                    }
                }
            }
            TickUntil(cursor.AddMinutes(10));   // after the last log: nothing more is written
            report.AppendLine($"{videos} video(s).");
            Write("check-recorder.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("check-recorder.txt", report + $"Error: {e.Message}");
            return 1;
        }
    }

    /// <summary>
    /// `--obs-test [SECONDS]`: finds OBS, turns its remote control on if it is off and OBS is closed,
    /// opens it, connects, and reports what it records. With SECONDS, records that long through the
    /// same start and stop a game gets (Recorder.RecordForAsync), into the videos folder.
    /// </summary>
    public static int ObsTest(string[] args)
    {
        var report = new StringBuilder();
        var seconds = args.Length > 1 && int.TryParse(args[1], out var n) ? n : 0;
        try
        {
            var config = ObsSetup.ReadConfig();
            report.AppendLine($"OBS: {ObsSetup.ExePath() ?? "not found"}; open: {ObsSetup.Running()}");
            report.AppendLine($"WebSocket settings: found {config.Exists}, enabled {config.Enabled}, port {config.Port}, password {(config.Password != null ? "set" : "none")}");

            var (client, message) = ObsSetup.ConnectAsync(openIfClosed: true, CancellationToken.None).GetAwaiter().GetResult();
            report.AppendLine(message);
            if (client == null) { Write("obs-test.txt", report.ToString()); return 2; }
            using (var ct = new CancellationTokenSource(TimeSpan.FromSeconds(20)))
            {
                report.AppendLine($"obs-websocket {client.WebSocketVersion}");
                report.AppendLine($"Records as: {ObsSetup.RecordFormatAsync(client, ct.Token).GetAwaiter().GetResult() ?? "(not said)"}");
                var dir = client.TryRequestAsync("GetRecordDirectory", null, ct.Token).GetAwaiter().GetResult();
                report.AppendLine($"OBS's own folder: {(dir is { } d && d.TryGetProperty("recordDirectory", out var rd) ? rd.GetString() : "(not said)")}");
            }
            client.DisposeAsync().AsTask().GetAwaiter().GetResult();

            if (seconds > 0)
            {
                var settings = AppSettings.Load();
                report.AppendLine($"Recording {seconds}s into {Recorder.VideosDir(settings)}:");
                var recorder = new Recorder(settings, line => report.AppendLine("  " + line));
                recorder.RecordForAsync(TimeSpan.FromSeconds(seconds)).GetAwaiter().GetResult();
                using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(20));
                var (after, _) = ObsSetup.ConnectAsync(openIfClosed: false, ct.Token).GetAwaiter().GetResult();
                if (after != null)
                {
                    var dir = after.TryRequestAsync("GetRecordDirectory", null, ct.Token).GetAwaiter().GetResult();
                    report.AppendLine($"OBS's own folder afterwards: {(dir is { } d && d.TryGetProperty("recordDirectory", out var rd) ? rd.GetString() : "(not said)")}");
                    after.DisposeAsync().AsTask().GetAwaiter().GetResult();
                }
            }
            Write("obs-test.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("obs-test.txt", report + $"Error: {e.GetType().Name}: {e.Message}");
            return 1;
        }
    }

    /// <summary>
    /// `--check-forever LOG`: replays a WoW Forever log through FightFinder on its own clock and lists
    /// every PvP fight it would save (when, how long, who, deaths) and the hostile players it saw in
    /// fights too small to save. Records nothing.
    /// </summary>
    public static int CheckForever(string path)
    {
        var report = new StringBuilder();
        try
        {
            var finder = new FightFinder();
            var fights = new List<FightFinder.Fight>();
            DateTime? lastTime = null;
            using var reader = new StreamReader(new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete));
            string? line;
            var lines = 0;
            while ((line = reader.ReadLine()) != null)
            {
                lines++;
                if (GameTrigger.LineTime(line) is { } t)
                {
                    // On the log's own clock, a fight is due Settle after its last line, as the app would see it.
                    if (finder.Due(t) is { } due) fights.Add(due);
                    lastTime = t;
                }
                if (finder.Feed(line) is { } f) fights.Add(f);
            }
            if (lastTime is { } end && finder.Due(end + TimeSpan.FromMinutes(1)) is { } tail) fights.Add(tail);

            report.AppendLine($"{Path.GetFileName(path)}: {lines} lines, {fights.Count} PvP fight(s) to save");
            foreach (var f in fights)
                report.AppendLine($"  {f.First:HH:mm:ss.fff} to {f.Last:HH:mm:ss.fff} ({(f.Last - f.First).TotalSeconds,5:F1}s, {f.Lines,4} lines)  {f.Title()}{(f.Zone != null ? $"  [{f.Zone}]" : "")}");
            Write("check-forever.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("check-forever.txt", report + $"Error: {e.Message}");
            return 1;
        }
    }

    /// <summary>
    /// `--replay-test [SECONDS]`: starts OBS's replay buffer as WoW Forever would (turning it on in
    /// OBS's profile if OBS is closed), waits SECONDS (20 by default), saves one clip into the videos
    /// folder as a fight would be (timing file included), and stops the buffer.
    /// </summary>
    public static int ReplayTest(string[] args)
    {
        var report = new StringBuilder();
        var seconds = args.Length > 1 && int.TryParse(args[1], out var n) ? n : 20;
        try
        {
            var settings = AppSettings.Load();
            report.AppendLine($"OBS: {ObsSetup.ExePath() ?? "not found"}; open: {ObsSetup.Running()}; profile: {ObsSetup.ProfileIni() ?? "not found"}");
            report.AppendLine($"Buffer {settings.ForeverBufferSeconds}s, waiting {seconds}s, saving into {Recorder.VideosDir(settings)}:");
            var recorder = new ForeverRecorder(settings, line => report.AppendLine("  " + line));
            recorder.TestAsync(TimeSpan.FromSeconds(seconds)).GetAwaiter().GetResult();
            if (recorder.Problem != null) report.AppendLine($"Problem: {recorder.Problem}");
            var newest = new DirectoryInfo(Recorder.VideosDir(settings)).GetFiles("Forever *.*").Where(f => !f.Name.EndsWith(Recorder.SidecarSuffix)).OrderByDescending(f => f.LastWriteTimeUtc).FirstOrDefault();
            if (newest != null)
            {
                report.AppendLine($"Newest clip: {newest.Name}, {newest.Length / 1048576.0:N1} MB, length read from the file: {Mp4.Duration(newest.FullName)?.TotalSeconds.ToString("F3") ?? "(could not read)"}s");
                var sidecar = newest.FullName + Recorder.SidecarSuffix;
                if (File.Exists(sidecar)) report.AppendLine(File.ReadAllText(sidecar));
            }
            Write("replay-test.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("replay-test.txt", report + $"Error: {e.GetType().Name}: {e.Message}");
            return 1;
        }
    }

    /// <summary>--cut SOURCE TARGET FROM_START_SECONDS FROM_END_SECONDS: ClipCutter on its own, the full error into cut.txt.</summary>
    public static int Cut(string[] args)
    {
        var report = new StringBuilder();
        try
        {
            var length = Mp4.Duration(args[1]) ?? TimeSpan.Zero;
            report.AppendLine($"{args[1]}: {length.TotalSeconds:F2}s, {new FileInfo(args[1]).Length / 1048576.0:N0} MB");
            var file = Windows.Storage.StorageFile.GetFileFromPathAsync(Path.GetFullPath(args[1])).AsTask().GetAwaiter().GetResult();
            var p = Windows.Media.MediaProperties.MediaEncodingProfile.CreateFromFileAsync(file).AsTask().GetAwaiter().GetResult();
            report.AppendLine($"container {p.Container?.Subtype ?? "none"}");
            report.AppendLine(p.Video is { } v ? $"video {v.Subtype} {v.Width}x{v.Height} {v.FrameRate.Numerator}/{v.FrameRate.Denominator} fps, {v.Bitrate} b/s, profile {v.ProfileId}" : "video none");
            report.AppendLine(p.Audio is { } a ? $"audio {a.Subtype} {a.ChannelCount}ch {a.SampleRate} Hz {a.BitsPerSample} bit, {a.Bitrate} b/s" : "audio none");
            var started = DateTime.Now;
            var why = ClipCutter.CutAsync(args[1], args[2], TimeSpan.FromSeconds(double.Parse(args[3], System.Globalization.CultureInfo.InvariantCulture)),
                TimeSpan.FromSeconds(double.Parse(args[4], System.Globalization.CultureInfo.InvariantCulture)), length).GetAwaiter().GetResult();
            report.AppendLine(why ?? $"Cut in {(DateTime.Now - started).TotalSeconds:N1}s: {args[2]} is {Mp4.Duration(args[2])?.TotalSeconds:F2}s, {new FileInfo(args[2]).Length / 1048576.0:N0} MB");
        }
        catch (Exception e)
        {
            report.AppendLine(e.ToString());
        }
        File.WriteAllText(Path.Combine(AppContext.BaseDirectory, "cut.txt"), report.ToString());
        Console.WriteLine(report);
        return 0;
    }

    /// <summary>
    /// `--obs-scene-test [SECONDS]`: what a recording would switch OBS to (ObsSetup.UseWowSceneAsync),
    /// held for SECONDS (default 10), then put back as a recording's end does (obs-scene-test.txt).
    /// </summary>
    public static int ObsSceneTest(string[] args)
    {
        var lines = new List<string>();
        try
        {
            var hold = args.Length >= 2 && int.TryParse(args[1], out var s) ? s : 10;
            using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(60 + hold));
            var (client, message) = ObsSetup.ConnectAsync(openIfClosed: false, ct.Token).GetAwaiter().GetResult();
            lines.Add(message);
            if (client == null) { Write("obs-scene-test.txt", string.Join(Environment.NewLine, lines)); return 2; }
            string? Scene() => client.TryRequestAsync("GetCurrentProgramScene", null, ct.Token).GetAwaiter().GetResult() is { } c
                && c.TryGetProperty("currentProgramSceneName", out var n) ? n.GetString() : null;
            lines.Add($"Before: \"{Scene()}\"");
            var (previous, note) = ObsSetup.UseWowSceneAsync(client, null, ct.Token).GetAwaiter().GetResult();
            lines.Add($"Switched: {(previous == null ? "no" : $"yes, from \"{previous}\"")}; now \"{Scene()}\"");
            if (note != null) lines.Add(note);
            Thread.Sleep(TimeSpan.FromSeconds(hold));
            ObsSetup.RestoreSceneAsync(client, previous, null, ct.Token).GetAwaiter().GetResult();
            lines.Add($"After: \"{Scene()}\"");
            client.DisposeAsync().AsTask().GetAwaiter().GetResult();
            Write("obs-scene-test.txt", string.Join(Environment.NewLine, lines));
            return 0;
        }
        catch (Exception e)
        {
            lines.Add($"Error: {e.Message}");
            Write("obs-scene-test.txt", string.Join(Environment.NewLine, lines));
            return 1;
        }
    }

    /// <summary>`--replay-stop`: stops OBS's replay buffer, for one an app that was killed left running (replay-stop.txt).</summary>
    /// <summary>
    /// --settings-shot PATH: draws the Settings window, with this PC's settings, to a PNG and leaves
    /// without showing it or saving anything. For checking the layout after a change.
    /// </summary>
    public static int SettingsShot(string path)
    {
        ApplicationConfiguration.Initialize();
        using var form = new SettingsForm(AppSettings.Load());
        form.StartPosition = FormStartPosition.Manual;
        form.Location = new Point(-10000, -10000);
        form.ShowInTaskbar = false;
        form.Show();
        Application.DoEvents();
        using var bmp = new Bitmap(form.Width, form.Height);
        form.DrawToBitmap(bmp, new Rectangle(Point.Empty, form.Size));
        bmp.Save(path, System.Drawing.Imaging.ImageFormat.Png);
        return 0;
    }

    public static int ReplayStop()
    {
        try
        {
            using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            var (client, message) = ObsSetup.ConnectAsync(openIfClosed: false, ct.Token).GetAwaiter().GetResult();
            if (client == null) { Write("replay-stop.txt", message); return 2; }
            var stopped = client.TryRequestAsync("StopReplayBuffer", null, ct.Token).GetAwaiter().GetResult() != null;
            client.DisposeAsync().AsTask().GetAwaiter().GetResult();
            Write("replay-stop.txt", stopped ? "Stopped OBS's replay buffer." : "OBS's replay buffer was not running.");
            return 0;
        }
        catch (Exception e)
        {
            Write("replay-stop.txt", $"Error: {e.Message}");
            return 1;
        }
    }

    /// <summary>
    /// `--check-live LOG [FROM-LINE]`: replays a log through LiveGame, as "This game" reads it, and
    /// lists each round's players (team, spec, name, you) as they stood when the next round began or
    /// the game ended. For checking the reading against a game whose teams are known.
    /// </summary>
    public static int CheckLive(string path, int fromLine)
    {
        var report = new StringBuilder();
        var live = new LiveGame();
        var empty = new Dictionary<string, System.Text.Json.JsonElement>();
        void Dump(string why)
        {
            var s = live.Snapshot(empty);
            if ((int)(s["round"] ?? 0) == 0) return;
            report.AppendLine($"{s["zone"]} · {s["bracket"]} · round {s["round"]} ({why})");
            foreach (var p in s["players"]!.AsArray())
                report.AppendLine($"    {p!["side"],-5} {p["name"],-16} {p["spec"],-24} {((bool)p["you"]! ? "you" : "")}");
        }
        try
        {
            using var reader = new StreamReader(new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete));
            string? line;
            var n = 0;
            while ((line = reader.ReadLine()) != null)
            {
                if (++n < fromLine) continue;
                if (line.Contains("ARENA_MATCH_START,", StringComparison.Ordinal)) Dump("before the next round");
                live.OnLine(line);
                if (line.Contains("ARENA_MATCH_END,", StringComparison.Ordinal)) { Dump("at the end"); report.AppendLine(); }
            }
            Write("check-live.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("check-live.txt", report + $"Error: {e.Message}");
            return 1;
        }
    }

    /// <summary>
    /// `--check-notes --server URL --key KEY [PLAYER...]`: the notes path end to end, with this data
    /// folder's settings. Brings the developer app's notes over (once), adds a note, a mark written
    /// about afterwards and a next-game note, sends them, and asks the website about PLAYERs.
    /// </summary>
    public static int CheckNotes(string[] args)
    {
        string? Arg(string name) => Array.IndexOf(args, name) is var i && i >= 0 && i + 1 < args.Length ? args[i + 1] : null;
        var report = new StringBuilder();
        try
        {
            var settings = AppSettings.Load();
            settings.ServerUrl = Arg("--server") ?? settings.ServerUrl;
            if (Arg("--key") is { } key) settings.Key = key;
            var notes = new NoteBook(settings, line => report.AppendLine("  log: " + line));
            notes.ImportDevNotes();
            notes.Add("note", "check-notes: a note with no game");
            var mark = notes.Add("mark", "");
            notes.Write(mark.Id, "check-notes: a mark written about afterwards");
            notes.Add("next", "check-notes: for the next game");

            using var api = new ApiClient(settings.ServerUrl, settings.Key!);
            notes.FlushAsync(api, CancellationToken.None).GetAwaiter().GetResult();
            foreach (var n in settings.Notes)
                report.AppendLine($"{n.Kind,-5} {n.At}  sent={n.Sent} refused={n.Refused ?? "-"}  {n.Text}");

            var players = args.SkipWhile(a => a != "--players").Skip(1).ToList();
            if (players.Count > 0)
            {
                var (known, pending) = api.PlayersAsync(players, CancellationToken.None).GetAwaiter().GetResult();
                foreach (var (name, x) in known) report.AppendLine($"known   {name}: {x.GetRawText()}");
                foreach (var name in pending) report.AppendLine($"pending {name}");
            }
            Write("check-notes.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("check-notes.txt", report + $"Error: {e.GetType().Name}: {e.Message}");
            return 1;
        }
    }

    /// <summary>
    /// `--local-sync-once LOG --dir PROJECT [--user N]`: developer mode's local sync on one log, as if
    /// all of it had just been sent: `wow:ingest-combatlog`, `wow:sync --skip-ingest`, and the Watch
    /// pages with --user. Both commands skip what the archive already has, so it is safe to repeat.
    /// </summary>
    public static int LocalSyncOnce(string[] args)
    {
        string? Arg(string name) => Array.IndexOf(args, name) is var i && i >= 0 && i + 1 < args.Length ? args[i + 1] : null;
        var report = new StringBuilder();
        try
        {
            var log = new FileInfo(args[1]);
            var settings = AppSettings.Load();
            settings.LocalSyncDir = Arg("--dir");
            settings.LocalSyncUser = int.TryParse(Arg("--user"), out var u) ? u : null;
            settings.LocalSyncSince = null;
            settings.Files[log.FullName] = new FileProgress { Offset = log.Length, SeenLength = log.Length, LocalOffset = 0 };
            var syncer = new Syncer(settings, line => report.AppendLine(line));
            syncer.Warning += w => report.AppendLine("balloon: " + w);
            var started = DateTime.Now;
            syncer.LocalSyncAsync([log], CancellationToken.None).GetAwaiter().GetResult();
            report.AppendLine($"Local offset now {settings.Files[log.FullName].LocalOffset} of {log.Length}; {(DateTime.Now - started).TotalSeconds:N0}s.");
            Write("local-sync-once.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("local-sync-once.txt", report + $"Error: {e.GetType().Name}: {e.Message}");
            return 1;
        }
    }

    /// <summary>
    /// `--check-record-light`: what the Recording light would say now (record-light.txt), and its four
    /// states drawn off-screen (record-light.png), so it can be checked without the window open.
    /// </summary>
    public static int CheckRecordLight()
    {
        var problem = ObsSetup.Problem();
        Write("record-light.txt", $"OBS open: {ObsSetup.Running()}; Safe Mode: {ObsSetup.InSafeMode()}\n"
            + $"With recording on, the light would say: {(problem == null ? "Ready to record" : "Not recording: " + problem)}\n");

        using var bar = new Panel { BackColor = Theme.Frame, Size = new Size(760, 60) };
        var x = 12;
        foreach (var (state, why) in new[] { (RecordLight.State.Recording, (string?)null), (RecordLight.State.Ready, null), (RecordLight.State.Problem, problem ?? ObsSetup.SafeModeMessage), (RecordLight.State.Off, null) })
        {
            var light = new RecordLight();
            light.Set(state, why);
            light.Location = new Point(x, 12);
            bar.Controls.Add(light);
            x += light.Width + 12;
        }
        var activity = Theme.Button("Activity");
        activity.Location = new Point(x, 12);
        bar.Controls.Add(activity);
        // And the right of the bar at the window's 900px minimum (58% of it, less the bar's padding):
        // Settings, Website, Activity and the widest light must fit.
        var widest = new RecordLight();
        widest.Set(RecordLight.State.Ready, null);
        var need = new[] { "Settings", "Website", "Activity" }.Sum(t => Theme.Button(t).Width + 8) + widest.Width + 8;
        File.AppendAllText(Path.Combine(AppSettings.Dir, "record-light.txt"), $"At 900px the right of the bar has {(int)((900 - 16 - 32) * 0.58)}px; it needs {need}px.\n");

        using var bmp = new Bitmap(bar.Width, bar.Height);
        bar.CreateControl();
        bar.DrawToBitmap(bmp, new Rectangle(Point.Empty, bar.Size));
        Directory.CreateDirectory(AppSettings.Dir);
        bmp.Save(Path.Combine(AppSettings.Dir, "record-light.png"), System.Drawing.Imaging.ImageFormat.Png);
        return 0;
    }

    /// <summary>
    /// --check-observer [FILE]: reads the Observer addon's saved data (Forever's, found as the app finds
    /// it, or FILE) and lists what the Looked at and Ask pages would show (check-observer.txt).
    /// </summary>
    public static int CheckObserver(string? file)
    {
        var report = new StringBuilder();
        try
        {
            var settings = AppSettings.Load();
            settings.ForeverLogsDir ??= AppSettings.DetectForeverLogs(settings.WowLogsDir);
            file ??= ObserverData.FindFile(settings.ForeverLogsDir);
            report.AppendLine($"File: {file ?? "none found"}");
            var data = ObserverData.Load(file);
            if (data == null) { Write("check-observer.txt", report.ToString()); return 2; }
            report.AppendLine($"Saved {data.Saved:yyyy-MM-dd HH:mm:ss}; {data.Subjects.Count} things looked at; {data.Prices.Count} price histories; {data.Asks.Count} questions");
            foreach (var g in data.Subjects.GroupBy(x => x.Kind).OrderByDescending(g => g.Count())) report.AppendLine($"  {g.Key}: {g.Count()}");
            report.AppendLine(data.LastScan is { } sc ? $"Last scan {sc.At:yyyy-MM-dd HH:mm}: {sc.Auctions} auctions, {sc.Prices.Count} items" : "No full scan yet");
            report.AppendLine();
            foreach (var s in data.Subjects.Where(x => x.Kind is "item" or "talent").OrderByDescending(x => x.Last).Take(25))
            {
                var first = ObserverData.FirstPricedLook(s);
                var now = s.ItemId is { } id ? data.Latest(id) : null;
                var price = first?.Price is { } was && now != null ? $"{ObserverPages.Money(was)} -> {ObserverPages.Money(now.Min)}" : now != null ? ObserverPages.Money(now.Min) : "";
                report.AppendLine($"{s.Last:dd MMM HH:mm}  {s.Kind,-6} {s.Name,-32} x{s.Looks,-3} {price,-20} {s.LastLook?.Why ?? s.LastLook?.Guess}");
            }
            foreach (var a in data.Asks) report.AppendLine($"ask {a.At:dd MMM HH:mm} [{a.Status}] {a.Question}{(a.Answer != null ? " => " + a.Answer : "")}");
            // the pages themselves, so they can be looked at in a browser
            File.WriteAllText(Path.Combine(AppSettings.Dir, "check-observer.html"), ObserverPages.LookedOverview(data, settings));
            var item = data.Subjects.Where(x => x.Kind == "item").OrderByDescending(x => x.Looks).FirstOrDefault();
            if (item != null) File.WriteAllText(Path.Combine(AppSettings.Dir, "check-observer-item.html"), ObserverPages.Subject(data, item));
            Write("check-observer.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("check-observer.txt", report + $"Error: {e}");
            return 1;
        }
    }

    /// <summary>
    /// --share-market FILE SERVER KEY OUT.lua [REGION REALM FACTION]: the Observer's last scan in FILE as
    /// MarketShare sends it, sent to SERVER with KEY, then that market's pooled prices written to OUT.lua
    /// as the addon would get them (share-market.txt). The market words stand in for a scan saved before
    /// the addon recorded where it was. For checking against a local site; nothing in settings changes.
    /// </summary>
    public static int ShareMarket(string[] args)
    {
        var report = new StringBuilder();
        try
        {
            var (file, server, key, outLua) = (args[1], args[2], args[3], args[4]);
            if (SavedVariables.Read(file).GetValueOrDefault("MindCollectorObserverDB") is not Dictionary<object, object?> db
                || db.GetValueOrDefault("lastScan") is not Dictionary<object, object?> scan)
            {
                Write("share-market.txt", "No lastScan in " + file);
                return 2;
            }
            var region = scan.GetValueOrDefault("region") as string ?? (args.Length > 5 ? args[5] : "US");
            var realm = scan.GetValueOrDefault("realm") as string ?? (args.Length > 6 ? args[6] : "Unknown");
            var faction = scan.GetValueOrDefault("faction") as string ?? (args.Length > 7 ? args[7] : "Horde");
            var t = scan.GetValueOrDefault("t") switch { long l => l, double d => (long)d, _ => 0L };
            var (json, items) = MarketShare.ScanJson(scan, region, realm, faction, t);
            report.AppendLine($"Scan {DateTimeOffset.FromUnixTimeSeconds(t).LocalDateTime:yyyy-MM-dd HH:mm} of {region}/{realm}/{faction}: {items} items, {json.Length:N0} bytes of JSON");
            using var doc0 = JsonDocument.Parse(json);
            var withBands = doc0.RootElement.GetProperty("items").EnumerateArray().Count(r => r.GetArrayLength() == 7);
            report.AppendLine($"  {withBands} items carry time-left bands");
            foreach (var r in doc0.RootElement.GetProperty("items").EnumerateArray().Take(3)) report.AppendLine("  " + r.GetRawText());

            using var api = new ApiClient(server, key);
            var (status, reason) = api.ShareScanAsync(json, CancellationToken.None).GetAwaiter().GetResult();
            report.AppendLine($"Sent: {status}{(reason != null ? " (" + reason + ")" : "")}");
            using var pooled = api.MarketAsync(region, realm, faction, CancellationToken.None).GetAwaiter().GetResult();
            if (pooled == null) { report.AppendLine("No pooled prices came back."); Write("share-market.txt", report.ToString()); return 3; }
            var lua = MarketShare.MarketLua(pooled.RootElement);
            File.WriteAllText(outLua, lua, new UTF8Encoding(false));
            report.AppendLine($"Pooled: {pooled.RootElement.GetProperty("items").EnumerateObject().Count()} items, {pooled.RootElement.GetProperty("players")} players; {lua.Length:N0} chars of Lua in {outLua}");
            Write("share-market.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("share-market.txt", report + $"Error: {e}");
            return 1;
        }
    }

    /// <summary>
    /// --rec-test [SECONDS] [FPS] [wow|everything|none] [MBPS]: the app's own recorder (NativeRecorder) on
    /// the running WoW window for SECONDS (10), into rec-test.mp4 in the data folder; what it captured,
    /// encoded, repeated and dropped, its sound, and this process's CPU time while it ran (rec-test.txt).
    /// </summary>
    public static int RecTest(string[] args)
    {
        var report = new StringBuilder();
        try
        {
            var seconds = args.Length > 1 && int.TryParse(args[1], out var s) ? s : 10;
            var fps = args.Length > 2 && int.TryParse(args[2], out var f) ? f : 60;
            var sound = args.Length > 3 ? args[3] : "wow";
            var mbps = args.Length > 4 && int.TryParse(args[4], out var m) ? m : 25;
            var fine = args.Length > 5 && args[5] == "fine";
            var hwnd = NativeRecorder.FindWow();
            if (hwnd == IntPtr.Zero) { Write("rec-test.txt", "No WoW window found."); return 2; }
            var path = Path.Combine(AppSettings.Dir, "rec-test.mp4");
            var cpu = System.Diagnostics.Process.GetCurrentProcess().TotalProcessorTime;
            var r = NativeRecorder.RecordAsync(hwnd, path, TimeSpan.FromSeconds(seconds), new NativeRecorder.Options(fps, (uint)mbps * 1_000_000, sound, fine)).GetAwaiter().GetResult();
            cpu = System.Diagnostics.Process.GetCurrentProcess().TotalProcessorTime - cpu;
            report.AppendLine($"{r.Width}x{r.Height}, asked {seconds}s at {fps} fps, {mbps} Mbps, sound: {sound}; took {r.Took.TotalSeconds:F1}s");
            report.AppendLine($"WoW drew {r.Captured} frames ({r.Captured / Math.Max(r.Length.TotalSeconds, 0.001):F1} a second); encoded {r.Encoded} at a steady {fps}, {r.Repeated} of them repeats; dropped {r.Dropped}; clock late {r.Late} times");
            report.AppendLine($"video {r.Length.TotalSeconds:F2}s, {r.Encoded / Math.Max(r.Length.TotalSeconds, 0.001):F1} fps encoded, {r.Bytes / 1048576.0:F1} MB ({r.Bytes * 8 / Math.Max(r.Length.TotalSeconds, 0.001) / 1e6:F1} Mbps)");
            report.AppendLine($"this process's CPU time: {cpu.TotalSeconds:F2}s over {r.Took.TotalSeconds:F1}s ({cpu.TotalSeconds / r.Took.TotalSeconds / Environment.ProcessorCount * 100:F1}% of the whole CPU)");
            if (r.Audio != null) report.AppendLine(r.Audio);
            if (r.Error != null) report.AppendLine($"error: {r.Error}");
            // a still from the middle, to see it is the game and not a black or torn frame
            try
            {
                var file = Windows.Storage.StorageFile.GetFileFromPathAsync(path).AsTask().GetAwaiter().GetResult();
                var clip = Windows.Media.Editing.MediaClip.CreateFromFileAsync(file).AsTask().GetAwaiter().GetResult();
                var comp = new Windows.Media.Editing.MediaComposition();
                comp.Clips.Add(clip);
                using var still = comp.GetThumbnailAsync(clip.OriginalDuration / 2, 960, 540, Windows.Media.Editing.VideoFramePrecision.NearestFrame).AsTask().GetAwaiter().GetResult();
                using var input = still.AsStreamForRead();
                using var output = File.Create(Path.Combine(AppSettings.Dir, "rec-test.png"));
                input.CopyTo(output);
                report.AppendLine($"Windows reads it as {clip.OriginalDuration.TotalSeconds:F2}s; still at the middle: rec-test.png");
                foreach (var t in clip.EmbeddedAudioTracks)
                {
                    var p = t.GetAudioEncodingProperties();
                    report.AppendLine($"sound track in the file: {p.Subtype} {p.SampleRate} Hz x{p.ChannelCount}, {p.Bitrate / 1000} kbps");
                }
                if (clip.EmbeddedAudioTracks.Count == 0) report.AppendLine("no sound track in the file");
            }
            catch (Exception e) { report.AppendLine($"still: {e.Message}"); }
            Write("rec-test.txt", report.ToString());
            return r.Error == null ? 0 : 1;
        }
        catch (Exception e)
        {
            Write("rec-test.txt", report + $"Error: {e}");
            return 1;
        }
    }

    /// <summary>
    /// --obs-check [forever] [restart]: Settings' Set up OBS without the window (ObsCheck): sets OBS up
    /// and records a test, a Forever replay save too with "forever". Restarts of OBS are answered no
    /// unless "restart" is given (obs-check.txt).
    /// </summary>
    public static int ObsCheckRun(bool forever, bool restart)
    {
        var report = new StringBuilder();
        try
        {
            var settings = AppSettings.Load();
            var outcome = ObsCheck.SetUpAndTestAsync(settings, forever, q => { report.AppendLine($"asked: {q.Split(Environment.NewLine[^1])[0]} -> {(restart ? "yes" : "no")}"); return restart; },
                line => report.AppendLine("  " + line), CancellationToken.None).GetAwaiter().GetResult();
            report.AppendLine(outcome.Ready ? "READY" : "NOT READY");
            foreach (var l in outcome.Lines) report.AppendLine("= " + l);
            Write("obs-check.txt", report.ToString());
            return outcome.Ready ? 0 : 2;
        }
        catch (Exception e)
        {
            Write("obs-check.txt", report + $"Error: {e}");
            return 1;
        }
    }

    /// <summary>
    /// --obs-audio: read-only, every OBS input that can carry sound, its kind, whether it's muted, its
    /// volume, and a game capture's capture_audio; the special inputs and the current scene's items
    /// (obs-audio.txt). For an echo: the same sound recorded twice, by Game Capture and desktop audio.
    /// </summary>
    public static int ObsAudio()
    {
        var report = new StringBuilder();
        try
        {
            var (obs, message) = ObsSetup.ConnectAsync(false, CancellationToken.None).GetAwaiter().GetResult();
            report.AppendLine(message);
            if (obs == null) { Write("obs-audio.txt", report.ToString()); return 1; }
            using var _ = new DisposeAsyncGuard(obs);
            var ct = CancellationToken.None;
            var special = obs.TryRequestAsync("GetSpecialInputs", null, ct).GetAwaiter().GetResult();
            report.AppendLine($"special: {special}");
            var scene = obs.TryRequestAsync("GetCurrentProgramScene", null, ct).GetAwaiter().GetResult();
            var sceneName = scene?.GetProperty("currentProgramSceneName").GetString();
            report.AppendLine($"scene: {sceneName}");
            if (sceneName != null && obs.TryRequestAsync("GetSceneItemList", new JsonObject { ["sceneName"] = sceneName }, ct).GetAwaiter().GetResult() is { } items)
                foreach (var i in items.GetProperty("sceneItems").EnumerateArray())
                    report.AppendLine($"  item: {i.GetProperty("sourceName")} enabled={i.GetProperty("sceneItemEnabled")}");
            var list = obs.RequestAsync("GetInputList", null, ct).GetAwaiter().GetResult();
            foreach (var input in list.GetProperty("inputs").EnumerateArray())
            {
                var name = input.GetProperty("inputName").GetString()!;
                var kind = input.GetProperty("inputKind").GetString();
                var q = new JsonObject { ["inputName"] = name };
                var mute = obs.TryRequestAsync("GetInputMute", q, ct).GetAwaiter().GetResult();
                var vol = obs.TryRequestAsync("GetInputVolume", new JsonObject { ["inputName"] = name }, ct).GetAwaiter().GetResult();
                var set = obs.TryRequestAsync("GetInputSettings", new JsonObject { ["inputName"] = name }, ct).GetAwaiter().GetResult();
                var tracks = obs.TryRequestAsync("GetInputAudioTracks", new JsonObject { ["inputName"] = name }, ct).GetAwaiter().GetResult();
                var monitor = obs.TryRequestAsync("GetInputAudioMonitorType", new JsonObject { ["inputName"] = name }, ct).GetAwaiter().GetResult();
                report.AppendLine($"{name} [{kind}] muted={mute?.GetProperty("inputMuted")} db={vol?.GetProperty("inputVolumeDb")} tracks={tracks?.GetProperty("inputAudioTracks")} monitor={monitor?.GetProperty("monitorType")}");
                report.AppendLine($"    settings: {set?.GetProperty("inputSettings")}");
            }
            foreach (var key in new[] { "RecTracks", "RecEncoder", "RecFormat2", "RecQuality" })
                report.AppendLine($"profile {key}: {ObsSetup.ProfileParameterAsync(obs, "SimpleOutput", key, ct).GetAwaiter().GetResult()}");
            Write("obs-audio.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("obs-audio.txt", report + $"Error: {e}");
            return 1;
        }
    }

    /// <summary>--file-forever DIR: moves DIR's loose Forever clips into a folder per game (as the app does at start), then lists DIR (file-forever.txt). For a test copy.</summary>
    public static int FileForever(string dir)
    {
        var report = new StringBuilder();
        var moved = ForeverRecorder.FileIntoFolders(dir, l => report.AppendLine("log: " + l));
        report.AppendLine($"moved {moved}");
        foreach (var d in Directory.EnumerateDirectories(dir).OrderBy(x => x))
            report.AppendLine($"{Path.GetFileName(d)}: {Directory.EnumerateFiles(d, "*.mp4").Count()} clips, {Directory.EnumerateFiles(d, "*.json").Count()} timing files");
        report.AppendLine($"loose: {Directory.EnumerateFiles(dir).Count()} files");
        foreach (var v in VideoLibrary.Load(dir).Where(v => v.IsForever).Take(2))
            report.AppendLine($"name {v.Name} -> url {VideoLibrary.Url(v.Name)} -> path {VideoLibrary.FullPath(dir, v.Name)}");
        report.AppendLine($"outside: {VideoLibrary.FullPath(dir, Path.Combine("..", "x.mp4")) ?? "refused"}");
        Write("file-forever.txt", report.ToString());
        return 0;
    }

    /// <summary>--fight-groups DIR: how the Fights tab groups the Forever clips in DIR (fight-groups.txt).</summary>
    public static int FightGroups(string dir)
    {
        var report = new StringBuilder();
        foreach (var g in ForeverPages.Groups(VideoLibrary.Load(dir).Where(v => v.IsForever)))
        {
            var (k, d) = ForeverPages.Tally(g);
            report.AppendLine($"{g[0].Fight!.Zone} {ForeverPages.FoughtAt(g[0]):ddd d MMM HH:mm} to {g[^1].Fight!.Last:HH:mm}: {g.Count} fights, {k}-{d}");
        }
        Write("fight-groups.txt", report.ToString());
        return 0;
    }

    private sealed class DisposeAsyncGuard(ObsClient obs) : IDisposable
    {
        public void Dispose() => obs.DisposeAsync().AsTask().GetAwaiter().GetResult();
    }

    /// <summary>
    /// --loudness FILE: the sound track decoded to 16-bit PCM (Windows' own transcoder into a WAV beside
    /// the data folder) and measured: overall loudness (RMS, dBFS) and the peak, per second too (loudness.txt).
    /// </summary>
    public static int Loudness(string path)
    {
        var report = new StringBuilder();
        try
        {
            var src = Windows.Storage.StorageFile.GetFileFromPathAsync(Path.GetFullPath(path)).AsTask().GetAwaiter().GetResult();
            var folder = Windows.Storage.StorageFolder.GetFolderFromPathAsync(AppSettings.Dir).AsTask().GetAwaiter().GetResult();
            var dst = folder.CreateFileAsync("loudness.wav", Windows.Storage.CreationCollisionOption.ReplaceExisting).AsTask().GetAwaiter().GetResult();
            var profile = Windows.Media.MediaProperties.MediaEncodingProfile.CreateWav(Windows.Media.MediaProperties.AudioEncodingQuality.High);
            profile.Audio = Windows.Media.MediaProperties.AudioEncodingProperties.CreatePcm(48000, 2, 16);
            var prepared = new Windows.Media.Transcoding.MediaTranscoder().PrepareFileTranscodeAsync(src, dst, profile).AsTask().GetAwaiter().GetResult();
            if (!prepared.CanTranscode) { Write("loudness.txt", $"cannot decode: {prepared.FailureReason}"); return 1; }
            prepared.TranscodeAsync().AsTask().GetAwaiter().GetResult();
            var bytes = File.ReadAllBytes(dst.Path);
            var data = 44;
            for (var i = 12; i + 8 < bytes.Length; i += 8 + BitConverter.ToInt32(bytes, i + 4))
                if (bytes[i] == 'd' && bytes[i + 1] == 'a' && bytes[i + 2] == 't' && bytes[i + 3] == 'a') { data = i + 8; break; }
            var samples = (bytes.Length - data) / 2;
            double sum = 0, peak = 0, secSum = 0;
            var perSecond = new List<string>();
            for (var i = 0; i < samples; i++)
            {
                var v = BitConverter.ToInt16(bytes, data + i * 2) / 32768.0;
                sum += v * v; secSum += v * v;
                peak = Math.Max(peak, Math.Abs(v));
                if ((i + 1) % 96000 == 0) { perSecond.Add($"{10 * Math.Log10(secSum / 96000 + 1e-12):F1}"); secSum = 0; }
            }
            report.AppendLine($"{Path.GetFileName(path)}: {samples / 96000.0:F1}s of sound, loudness {10 * Math.Log10(sum / Math.Max(samples, 1) + 1e-12):F1} dBFS, peak {20 * Math.Log10(peak + 1e-12):F1} dBFS");
            report.AppendLine("each second (dBFS): " + string.Join(" ", perSecond));
            Write("loudness.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("loudness.txt", report + $"Error: {e.Message}");
            return 1;
        }
    }

    /// <summary>--probe FILE: a video's size, frame rate, bitrate and sound as Windows reads them, and a still from its middle (probe.txt, probe.png).</summary>
    public static int Probe(string path, bool full = false)
    {
        var report = new StringBuilder();
        try
        {
            var file = Windows.Storage.StorageFile.GetFileFromPathAsync(path).AsTask().GetAwaiter().GetResult();
            var clip = Windows.Media.Editing.MediaClip.CreateFromFileAsync(file).AsTask().GetAwaiter().GetResult();
            var v = clip.GetVideoEncodingProperties();
            var bytes = new FileInfo(path).Length;
            report.AppendLine($"{Path.GetFileName(path)}: {clip.OriginalDuration.TotalSeconds:F2}s, {bytes / 1048576.0:F1} MB ({bytes * 8 / clip.OriginalDuration.TotalSeconds / 1e6:F1} Mbps overall)");
            report.AppendLine($"video: {v.Subtype} {v.Width}x{v.Height}, {v.FrameRate.Numerator / (double)Math.Max(v.FrameRate.Denominator, 1):F2} fps, profile {v.ProfileId}, {v.Bitrate / 1e6:F1} Mbps");
            foreach (var t in clip.EmbeddedAudioTracks)
            {
                var a = t.GetAudioEncodingProperties();
                report.AppendLine($"sound: {a.Subtype} {a.SampleRate} Hz x{a.ChannelCount}, {a.Bitrate / 1000} kbps");
            }
            var comp = new Windows.Media.Editing.MediaComposition();
            comp.Clips.Add(clip);
            using var still = comp.GetThumbnailAsync(clip.OriginalDuration / 2, full ? (int)v.Width : 960, full ? (int)v.Height : 540, Windows.Media.Editing.VideoFramePrecision.NearestFrame).AsTask().GetAwaiter().GetResult();
            using var input = still.AsStreamForRead();
            using var output = File.Create(Path.Combine(AppSettings.Dir, "probe.png"));
            input.CopyTo(output);
            Write("probe.txt", report.ToString());
            return 0;
        }
        catch (Exception e)
        {
            Write("probe.txt", report + $"Error: {e.Message}");
            return 1;
        }
    }

    /// <summary>--ask "QUESTION": one question through AskBridge as the Ask page sends it (ask.txt, ask.html). Uses the player's Claude account.</summary>
    public static int Ask(string question)
    {
        var settings = AppSettings.Load();
        settings.ForeverLogsDir ??= AppSettings.DetectForeverLogs(settings.WowLogsDir);
        settings.AskClaude = true;   // this run only; nothing is saved
        var log = new StringBuilder();
        using var bridge = new AskBridge(settings, line => log.AppendLine(line));
        var done = new ManualResetEventSlim();
        AskBridge.Answer? answer = null;
        bridge.Answered += a => { answer = a; done.Set(); };
        log.AppendLine($"Claude: {AskBridge.FindClaude(settings.ClaudePath) ?? "not found"}; ready: {bridge.CheckReady() ?? "yes"}");
        bridge.AskFromApp(question);
        if (!done.Wait(TimeSpan.FromMinutes(9))) log.AppendLine("No answer in 9 minutes.");
        if (answer != null)
        {
            log.AppendLine($"Answer: {answer.Text}");
            foreach (var p in answer.Points.Take(10)) log.AppendLine($"  {p.Zone} {p.X}, {p.Y} {p.Label}");
            if (answer.Points.Count > 10) log.AppendLine($"  ... {answer.Points.Count} points in all");
            File.WriteAllText(Path.Combine(AppSettings.Dir, "ask.html"), ObserverPages.Answer(answer));
        }
        Write("ask.txt", log.ToString());
        return answer != null ? 0 : 1;
    }

    private static void Write(string name, string text)
    {
        Directory.CreateDirectory(AppSettings.Dir);
        File.WriteAllText(Path.Combine(AppSettings.Dir, name), text);
    }
}

using System.Text;

namespace MindCollector.Desktop;

/// <summary>
/// Switches that run without the window and write what they found to a text file in the app's
/// data folder (MINDCOLLECTOR_DATA, or %APPDATA%\MindCollector Desktop):
///
///   MindCollector.exe --check-log "path\to\WoWCombatLog-....txt"
///       Lists every arena round the app would send from that log: when it starts, its bracket and
///       its size. Sends nothing. What a tester runs when "my games did not show up".
///
///   MindCollector.exe --install-addon --logs FOLDER [--replace]
///       Installs or updates the arena-log addon for that WoW Logs folder, as the app does at start.
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
    public static int InstallAddon(string logsDir, bool replace)
    {
        try
        {
            var outcome = AddonInstaller.Ensure(logsDir, replace);
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

    private static void Write(string name, string text)
    {
        Directory.CreateDirectory(AppSettings.Dir);
        File.WriteAllText(Path.Combine(AppSettings.Dir, name), text);
    }
}

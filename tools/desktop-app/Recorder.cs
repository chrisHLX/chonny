using System.Globalization;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace MindCollector.Desktop;

/// <summary>
/// When to record (2026-10-25). Decisions only, with no OBS and no files, so a whole combat log can
/// be replayed through it (--check-recorder) to see where each video would have started and stopped.
///
/// - **Zoning into an arena: start.** ARENA_MATCH_START is written as the gates open, about a minute
///   after you arrive (25 Oct shuffle: zone-in 11:04:41, START 11:05:40), so starting there would
///   lose the prep. Two signs come earlier: a new log appearing (the arena-log addon turns logging
///   on as you zone in, and WoW opens a new WoWCombatLog-*.txt each time), and, for a log that stays
///   on all session (19 Oct: eighteen 3v3s in one file), a ZONE_CHANGE naming an arena's instance
///   id, which is the id ARENA_MATCH_START carries too.
/// - **ARENA_MATCH_START: start if not already, and note the round.** For a player who logs all the
///   time (no new file per arena), and the anchor every round's video position is computed from:
///   RoundAnalysisService's clock is zero at this line.
/// - **ARENA_MATCH_END: stop a few seconds later**, for the scoreboard. A Solo Shuffle writes one
///   END for the whole lobby, so one shuffle is one video, which is what the Watch page expects.
/// - **Safety stops:** WoW closed, 90 minutes, or a game that never wrote its END: the next game's
///   START ends it (unless both are rounds of a shuffle), and failing that 20 minutes with nothing
///   written. A video with no ARENA_MATCH_START in four minutes is thrown away: the new log was
///   something else (a /combatlog typed in a raid).
///
/// **WoW holds the log in memory and writes it in bursts** (measured 25 Oct, the first game the app
/// recorded). Round 6 ran 3.5 minutes with nothing reaching the file, so a "quiet for three minutes"
/// rule stopped the video 35s before the END line arrived. The disk goes quiet mid-game, so quiet
/// is a sign only before a game starts. The same buffering held that game's zone-in ZONE_CHANGE
/// back until round 1's START, so the prep is caught only when the arena opens a new log (the first
/// arena of a WoW session); later arenas in the same session are recorded from their gates.
/// </summary>
public sealed class GameTrigger
{
    public static readonly TimeSpan EndTail = TimeSpan.FromSeconds(8);
    public static readonly TimeSpan Quiet = TimeSpan.FromMinutes(3);
    public static readonly TimeSpan QuietInGame = TimeSpan.FromMinutes(20);
    public static readonly TimeSpan NoGame = TimeSpan.FromMinutes(4);
    public static readonly TimeSpan Longest = TimeSpan.FromMinutes(90);

    public enum Act { None, Start, Stop, Discard }

    public bool Recording { get; private set; }
    public bool HasGame => Rounds.Count > 0;
    public DateTime StartedAt { get; private set; }
    public DateTime? StopAt { get; private set; }
    public DateTime LastActivity { get; private set; }
    public string? Bracket { get; private set; }

    /// <summary>Each round's ARENA_MATCH_START, in the log's own time (this PC's local clock).</summary>
    public List<DateTime> Rounds { get; } = [];

    /// <summary>
    /// Every arena instance id in the ARENA_MATCH_START lines of the logs kept in D:\MindCollector\wow-logs (25 Oct 2026: 15 maps, 620
    /// rounds), so a ZONE_CHANGE can be recognised before any START. A map not here yet is learned
    /// from its first START (LearnArena) and caught at the zone-in from then on.
    /// </summary>
    private static readonly HashSet<int> Arenas = [572, 617, 980, 1134, 1504, 1505, 1552, 1825, 1911, 2167, 2373, 2509, 2547, 2563, 2759];

    public static bool IsArena(int zone) { lock (Arenas) return Arenas.Contains(zone); }

    public static void LearnArena(int zone) { lock (Arenas) Arenas.Add(zone); }

    /// <summary>A new log, or a ZONE_CHANGE into an arena: you are arriving. Recorded from here, unconfirmed until a START.</summary>
    public Act OnArriving(DateTime now)
    {
        LastActivity = now;
        if (Recording) return Act.None;
        Begin(now);
        return Act.Start;
    }

    public Act OnMatchStart(DateTime now, DateTime logTime, string bracket)
    {
        LastActivity = now;
        var act = Act.None;
        if (!Recording)
        {
            Begin(now);
            act = Act.Start;
        }
        Rounds.Add(logTime);
        Bracket ??= bracket;
        StopAt = null;   // the next round of a lobby: keep going
        return act;
    }

    /// <summary>
    /// A START that cannot belong to the game being recorded: that game never wrote its END. Only a
    /// shuffle plays several rounds under one END (CombatLogIngestService::isRoundBased()).
    /// </summary>
    public bool EndsUnfinishedGame(string bracket) =>
        Recording && HasGame && StopAt == null && !(RoundBased(Bracket) && RoundBased(bracket));

    private static bool RoundBased(string? bracket) => bracket?.Contains("Solo Shuffle", StringComparison.Ordinal) == true;

    public void OnMatchEnd(DateTime now)
    {
        LastActivity = now;
        if (Recording && HasGame) StopAt = now + EndTail;
    }

    public void OnActivity(DateTime now) => LastActivity = now;

    public (Act Act, string Reason) OnTick(DateTime now, bool wowRunning)
    {
        if (!Recording) return (Act.None, "");
        if (StopAt is { } at && now >= at) return (Act.Stop, "the game ended");
        if (!HasGame && now - StartedAt > NoGame) return (Act.Discard, "no arena game started");
        if (!wowRunning) return (HasGame ? Act.Stop : Act.Discard, "WoW closed");
        if (!HasGame && now - LastActivity > Quiet) return (Act.Discard, "the combat log went quiet");
        if (HasGame && now - LastActivity > QuietInGame) return (Act.Stop, "nothing was written to the combat log for 20 minutes");
        if (now - StartedAt > Longest) return (Act.Stop, "it ran for 90 minutes");
        return (Act.None, "");
    }

    public void Reset()
    {
        Recording = false;
        StopAt = null;
        Bracket = null;
        Rounds.Clear();
    }

    private void Begin(DateTime now)
    {
        Reset();
        Recording = true;
        StartedAt = now;
        LastActivity = now;
    }

    // ---------------------------------------------------------------- reading the log's lines

    private static readonly Regex Stamp = new(@"^(\d{1,2})/(\d{1,2})/(\d{4}) (\d{1,2}):(\d{2}):(\d{2})\.(\d+)", RegexOptions.Compiled);

    /// <summary>A line's own time, as CombatLogIngestService::timestampMs() reads it: local, to the millisecond.</summary>
    public static DateTime? LineTime(string line)
    {
        var m = Stamp.Match(line);
        if (!m.Success) return null;
        int I(int g) => int.Parse(m.Groups[g].Value, CultureInfo.InvariantCulture);
        try
        {
            var ms = (int)Math.Round(double.Parse("0." + m.Groups[7].Value, CultureInfo.InvariantCulture) * 1000);
            return new DateTime(I(3), I(1), I(2), I(4), I(5), I(6), DateTimeKind.Local).AddMilliseconds(ms);
        }
        catch { return null; }
    }

    /// <summary>ARENA_MATCH_START,zoneId,unknown,bracket,isRanked: the bracket.</summary>
    public static string BracketOf(string line) => Field(line, 3)?.Trim() ?? "Arena";

    /// <summary>The instance id of ARENA_MATCH_START,zoneId,... and of ZONE_CHANGE,instanceId,"name",difficulty.</summary>
    public static int? ZoneOf(string line) => int.TryParse(Field(line, 1), NumberStyles.Integer, CultureInfo.InvariantCulture, out var z) ? z : null;

    private static string? Field(string line, int index)
    {
        var body = line.Contains("  ") ? line[(line.IndexOf("  ", StringComparison.Ordinal) + 2)..] : line;
        return body.Split(',').ElementAtOrDefault(index);
    }

    /// <summary>What a line means to the recorder: a START (learning its arena), an END, a zone-in to an arena, or nothing.</summary>
    public static LineKind Classify(string line)
    {
        if (line.Contains("ARENA_MATCH_START,", StringComparison.Ordinal))
        {
            if (ZoneOf(line) is { } z) LearnArena(z);
            return LineKind.Start;
        }
        if (line.Contains("ARENA_MATCH_END,", StringComparison.Ordinal)) return LineKind.End;
        if (line.Contains("ZONE_CHANGE,", StringComparison.Ordinal) && ZoneOf(line) is { } zone && IsArena(zone)) return LineKind.Arriving;
        return LineKind.Other;
    }

    public enum LineKind { Other, Start, End, Arriving }
}

/// <summary>
/// Records each arena game with OBS (2026-10-25): watches the newest combat log once a second, asks
/// GameTrigger what to do, and drives OBS through its WebSocket server (ObsClient, ObsSetup).
///
/// Each video goes to the videos folder with a small file beside it, "&lt;video&gt;.mindcollector.json",
/// that says when its first frame was recorded and when each round began, both on this PC's clock,
/// which is also the combat log's clock. That is what lines the video up with the game exactly:
/// round n starts (its ARENA_MATCH_START minus the recording's start) seconds into the file, with no
/// lining up by hand (`wow:watch-page` reads it).
///
/// OBS is the player's, so it is left as found: its folder is changed only for the moment a
/// recording starts and put back straight after, a recording the player started is never touched,
/// and a video is deleted only by the size limit, and only one this app recorded.
/// </summary>
public sealed class Recorder : IDisposable
{
    public const string SidecarSuffix = ".mindcollector.json";

    private readonly AppSettings _settings;
    private readonly Action<string> _log;
    private readonly LiveGame? _live;
    private readonly GameTrigger _trigger = new();
    private readonly DateTime _sinceUtc = DateTime.UtcNow;
    private readonly System.Threading.Timer _timer;

    private string? _file;
    private long _offset;
    private readonly List<byte> _carry = [];
    private int _busy;
    private bool _disposed;

    private ObsClient? _obs;
    private Session? _session;
    private DateTime _retryAfter = DateTime.MinValue;
    private TaskCompletionSource<DateTime>? _started;
    private TaskCompletionSource<string?>? _stopped;

    private sealed class Session
    {
        public DateTime StartedAt;
        public bool External;          // OBS was already recording: the player's, not ours
        public bool FolderSet;         // OBS wrote straight into the videos folder
        public string? RestoreFolder;  // the player's own OBS folder, still to be put back
        public string? Log;
    }

    /// <summary>True while a recording this app started is running.</summary>
    public event Action<bool>? RecordingChanged;

    /// <param name="live">Fed every line read, for "This game" (LiveGame). The log is read whether or not recording is on.</param>
    public Recorder(AppSettings settings, Action<string> log, LiveGame? live = null)
    {
        _settings = settings;
        _log = log;
        _live = live;
        _timer = new System.Threading.Timer(_ => _ = TickAsync(), null, Timeout.Infinite, Timeout.Infinite);
    }

    public void Start() => _timer.Change(1000, 1000);

    public static string VideosDir(AppSettings s) =>
        string.IsNullOrWhiteSpace(s.VideosDir) ? AppSettings.DefaultVideosDir(s) : s.VideosDir;

    private async Task TickAsync()
    {
        if (_disposed || Interlocked.Exchange(ref _busy, 1) == 1) return;
        try
        {
            var now = DateTime.Now;
            var events = ReadLog();
            if (!_settings.RecordGames)
            {
                if (_trigger.Recording) await FinishAsync("recording was turned off in Settings", discard: !_trigger.HasGame);
                return;
            }

            foreach (var e in events)
            {
                var act = GameTrigger.Act.None;
                switch (e.Kind)
                {
                    case Kind.Arriving: act = _trigger.OnArriving(now); break;
                    case Kind.Start:
                        if (_trigger.EndsUnfinishedGame(e.Bracket ?? "Arena")) await FinishAsync("the next game started", discard: false);
                        act = _trigger.OnMatchStart(now, e.Time ?? now, e.Bracket ?? "Arena");
                        break;
                    case Kind.End: _trigger.OnMatchEnd(now); break;
                    case Kind.Grew: _trigger.OnActivity(now); break;
                }
                if (act == GameTrigger.Act.Start) await BeginAsync();
            }

            var (tick, reason) = _trigger.OnTick(now, Syncer.WowRunning());
            if (tick is GameTrigger.Act.Stop or GameTrigger.Act.Discard)
                await FinishAsync(reason, discard: tick == GameTrigger.Act.Discard);
        }
        catch (Exception e)
        {
            _log($"Recording: {e.Message}");
            // A start that failed part-way: nothing is recording, so the next round may try again.
            if (_session == null && _trigger.Recording)
            {
                _trigger.Reset();
                _retryAfter = DateTime.Now.AddMinutes(2);
            }
        }
        finally
        {
            Interlocked.Exchange(ref _busy, 0);
        }
    }

    // ---------------------------------------------------------------- the log

    private enum Kind { Arriving, Grew, Start, End }

    private readonly record struct LogEvent(Kind Kind, DateTime? Time = null, string? Bracket = null);

    /// <summary>What was added to the newest log since the last look: a new file, growth, and the arena markers.</summary>
    private List<LogEvent> ReadLog()
    {
        var events = new List<LogEvent>();
        var dir = _settings.WowLogsDir;
        if (string.IsNullOrEmpty(dir) || !Directory.Exists(dir)) return events;

        var newest = new DirectoryInfo(dir).GetFiles("WoWCombatLog*.txt").OrderByDescending(f => f.LastWriteTimeUtc).FirstOrDefault();
        if (newest == null) return events;

        var history = false;
        if (!string.Equals(newest.FullName, _file, StringComparison.OrdinalIgnoreCase))
        {
            // The rest of the old one first: a 3v3's END can be its last line.
            if (_file != null && File.Exists(_file)) events.AddRange(ReadFrom(_file, history: false));
            // A log opened while the app runs is a game starting. One already there is caught up on
            // from its last megabyte, which holds a game already under way, for "This game" only: a
            // game half over is not recorded, and an old START must not start a recording.
            var fresh = newest.CreationTimeUtc >= _sinceUtc;
            _file = newest.FullName;
            _offset = fresh ? 0 : Math.Max(0, newest.Length - 1024 * 1024);
            _carry.Clear();
            history = !fresh;
            if (fresh) events.Add(new(Kind.Arriving));
            else SkipToLineStart(newest.FullName);
        }
        events.AddRange(ReadFrom(newest.FullName, history));
        return events;
    }

    /// <summary>A catch-up read starts mid-file: move on to the start of the next whole line.</summary>
    private void SkipToLineStart(string path)
    {
        if (_offset == 0) return;
        using var fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        fs.Seek(_offset, SeekOrigin.Begin);
        int b;
        while ((b = fs.ReadByte()) >= 0)
        {
            _offset++;
            if (b == '\n') break;
        }
    }

    /// <param name="history">A catch-up: lines go to "This game" only, never to the recording.</param>
    private List<LogEvent> ReadFrom(string path, bool history)
    {
        var events = new List<LogEvent>();
        using var fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        if (fs.Length < _offset) { _offset = 0; _carry.Clear(); }
        if (fs.Length == _offset) return events;

        fs.Seek(_offset, SeekOrigin.Begin);
        var bytes = new byte[Math.Min(fs.Length - _offset, 64L * 1024 * 1024)];
        var read = fs.Read(bytes, 0, bytes.Length);
        _offset += read;
        if (!history)
        {
            events.Add(new(Kind.Grew));
            _live?.OnGrew();
        }

        // Whole lines only; a partial last line waits for the rest.
        var start = 0;
        for (var i = 0; i < read; i++)
        {
            if (bytes[i] != (byte)'\n') continue;
            string line;
            if (_carry.Count > 0)
            {
                _carry.AddRange(new ArraySegment<byte>(bytes, start, i - start));
                line = Encoding.UTF8.GetString(_carry.ToArray());
                _carry.Clear();
            }
            else line = Encoding.UTF8.GetString(bytes, start, i - start);
            start = i + 1;

            _live?.OnLine(line.TrimEnd('\r'));
            if (history) continue;
            switch (GameTrigger.Classify(line))
            {
                case GameTrigger.LineKind.Start: events.Add(new(Kind.Start, GameTrigger.LineTime(line), GameTrigger.BracketOf(line))); break;
                case GameTrigger.LineKind.End: events.Add(new(Kind.End)); break;
                case GameTrigger.LineKind.Arriving: events.Add(new(Kind.Arriving)); break;
            }
        }
        if (start < read) _carry.AddRange(new ArraySegment<byte>(bytes, start, read - start));
        return events;
    }

    // ---------------------------------------------------------------- OBS

    private async Task BeginAsync()
    {
        if (DateTime.Now < _retryAfter) { _trigger.Reset(); return; }
        using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(45));

        if (_obs is not { Connected: true })
        {
            if (_obs != null) await _obs.DisposeAsync();
            var (client, message) = await ObsSetup.ConnectAsync(openIfClosed: true, ct.Token);
            if (client == null)
            {
                _log($"Could not record this game: {message}");
                _retryAfter = DateTime.Now.AddMinutes(2);
                _trigger.Reset();
                return;
            }
            _obs = client;
            _obs.RecordStateChanged += OnRecordState;
        }

        var status = await _obs.RequestAsync("GetRecordStatus", null, ct.Token);
        if (status.TryGetProperty("outputActive", out var active) && active.GetBoolean())
        {
            _session = new Session { StartedAt = DateTime.Now, External = true };
            _log("OBS is already recording, so the app left that recording alone for this game.");
            return;
        }

        var dir = VideosDir(_settings);
        Directory.CreateDirectory(dir);
        var previous = (await _obs.TryRequestAsync("GetRecordDirectory", null, ct.Token)) is { } g
            && g.TryGetProperty("recordDirectory", out var rd) ? rd.GetString() : null;
        var folderSet = previous != null
            && await _obs.TryRequestAsync("SetRecordDirectory", new JsonObject { ["recordDirectory"] = dir }, ct.Token) != null;

        _started = new TaskCompletionSource<DateTime>(TaskCreationOptions.RunContinuationsAsynchronously);
        var asked = DateTime.Now;
        await _obs.RequestAsync("StartRecord", null, ct.Token);
        DateTime startedAt;
        try { startedAt = await _started.Task.WaitAsync(TimeSpan.FromSeconds(10)); }
        catch (TimeoutException) { startedAt = asked; }

        // Put the player's own folder back now; if OBS will not change it mid-recording, after it stops.
        var restore = folderSet
            && await _obs.TryRequestAsync("SetRecordDirectory", new JsonObject { ["recordDirectory"] = previous }, ct.Token) == null;

        _session = new Session { StartedAt = startedAt, FolderSet = folderSet, RestoreFolder = restore ? previous : null, Log = _file };
        _log($"Recording this game with OBS (started {startedAt:HH:mm:ss.fff}).");
        RecordingChanged?.Invoke(true);
    }

    private void OnRecordState(string state, string? path)
    {
        if (state == "OBS_WEBSOCKET_OUTPUT_STARTED") _started?.TrySetResult(DateTime.Now);
        else if (state == "OBS_WEBSOCKET_OUTPUT_STOPPED") _stopped?.TrySetResult(path);
    }

    private async Task FinishAsync(string reason, bool discard)
    {
        var session = _session;
        var rounds = _trigger.Rounds.ToList();
        var bracket = _trigger.Bracket;
        _trigger.Reset();
        _session = null;
        if (session == null) return;
        if (session.External)
        {
            _log("That game is over; OBS is still recording as you set it.");
            return;
        }
        RecordingChanged?.Invoke(false);

        string? path = null;
        using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(30));
        if (_obs is { Connected: true } obs)
        {
            _stopped = new TaskCompletionSource<string?>(TaskCreationOptions.RunContinuationsAsynchronously);
            var r = await obs.TryRequestAsync("StopRecord", null, ct.Token);
            path = r is { } v && v.TryGetProperty("outputPath", out var op) ? op.GetString() : null;
            // The file is finished only once OBS says it has stopped.
            try { path = await _stopped.Task.WaitAsync(TimeSpan.FromSeconds(20)) ?? path; } catch (TimeoutException) { }
            if (session.RestoreFolder != null)
                await obs.TryRequestAsync("SetRecordDirectory", new JsonObject { ["recordDirectory"] = session.RestoreFolder }, ct.Token);
        }
        var stoppedAt = DateTime.Now;

        if (path == null || !File.Exists(path))
        {
            _log($"OBS closed before this game's recording could be stopped ({reason}). Anything it saved is in OBS's own folder.");
            return;
        }
        if (discard)
        {
            try { File.Delete(path); } catch { }
            _log($"Deleted a recording with no game in it ({reason}).");
            return;
        }

        var dir = VideosDir(_settings);
        if (!session.FolderSet)
        {
            // An OBS that would not take the folder: move the file in afterwards.
            var target = Path.Combine(dir, Path.GetFileName(path));
            try { await Task.Run(() => File.Move(path, target)); path = target; }
            catch (Exception e) { _log($"Could not move the video into {dir}: {e.Message}"); }
        }

        WriteSidecar(path, session, stoppedAt, rounds, bracket, reason);
        var mb = new FileInfo(path).Length / 1048576.0;
        _log($"Saved this game's video: {Path.GetFileName(path)} ({mb:N0} MB, {rounds.Count} round(s); stopped because {reason}).");
        KeepWithinLimit(dir, keep: path);
    }

    private static string Clock(DateTime t) => t.ToString("yyyy-MM-dd HH:mm:ss.fff", CultureInfo.InvariantCulture);

    private void WriteSidecar(string video, Session session, DateTime stoppedAt, List<DateTime> rounds, string? bracket, string reason)
    {
        var json = new JsonObject
        {
            ["format"] = 1,
            ["app"] = $"MindCollector {Application.ProductVersion.Split('+')[0]}",
            ["video"] = Path.GetFileName(video),
            ["clock"] = "local time on this PC, the same clock as the combat log's own timestamps",
            ["recordingStarted"] = Clock(session.StartedAt),
            ["recordingStopped"] = Clock(stoppedAt),
            ["bracket"] = bracket,
            ["log"] = session.Log != null ? Path.GetFileName(session.Log) : null,
            ["rounds"] = new JsonArray(rounds.Select(r => (JsonNode)Clock(r)).ToArray()),
            ["stoppedBecause"] = reason,
        };
        try { File.WriteAllText(video + SidecarSuffix, json.ToJsonString(new JsonSerializerOptions { WriteIndented = true })); }
        catch (Exception e) { _log($"Could not write the video's timing file: {e.Message}"); }
    }

    /// <summary>The size limit: the oldest videos this app recorded go first. Nothing else in the folder is touched.</summary>
    private void KeepWithinLimit(string dir, string keep)
    {
        if (_settings.VideoLimitGb <= 0) return;
        var limit = _settings.VideoLimitGb * 1024L * 1024 * 1024;
        var ours = new DirectoryInfo(dir).GetFiles("*" + SidecarSuffix)
            .Select(s => (Sidecar: s, Video: new FileInfo(s.FullName[..^SidecarSuffix.Length])))
            .Where(x => x.Video.Exists)
            .OrderBy(x => x.Video.CreationTimeUtc)
            .ToList();
        var total = ours.Sum(x => x.Video.Length);
        foreach (var (sidecar, video) in ours)
        {
            if (total <= limit) break;
            if (string.Equals(video.FullName, keep, StringComparison.OrdinalIgnoreCase)) continue;
            try
            {
                var size = video.Length;
                video.Delete();
                sidecar.Delete();
                total -= size;
                _log($"Deleted the oldest video, {video.Name}, to stay under {_settings.VideoLimitGb} GB.");
            }
            catch { }
        }
    }

    /// <summary>
    /// --obs-test: one short recording through the same start and stop a game gets (folder, timing
    /// file, the player's OBS folder put back), with the round placed at the moment it was asked for.
    /// </summary>
    public async Task RecordForAsync(TimeSpan length)
    {
        var now = DateTime.Now;
        _trigger.OnMatchStart(now, now, "Test");
        await BeginAsync();
        if (_session == null) return;
        await Task.Delay(length);
        await FinishAsync("the test ended", discard: false);
        if (_obs != null) await _obs.DisposeAsync();
        _obs = null;
    }

    /// <summary>On exit: a recording this app started is stopped and kept, so it is not left running.</summary>
    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        _timer.Dispose();
        try
        {
            Task.Run(async () =>
            {
                var until = DateTime.UtcNow.AddSeconds(5);
                while (Volatile.Read(ref _busy) == 1 && DateTime.UtcNow < until) await Task.Delay(50);
                if (_trigger.Recording) await FinishAsync("the app was closed", discard: !_trigger.HasGame);
                if (_obs != null) await _obs.DisposeAsync();
            }).Wait(TimeSpan.FromSeconds(30));
        }
        catch { }
    }
}

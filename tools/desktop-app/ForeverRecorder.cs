using System.Globalization;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace MindCollector.Desktop;

/// <summary>
/// Finds PvP fights in a WoW Forever combat log (2026-11-06). Decisions only, no OBS and no files,
/// so a whole log can be replayed through it (--check-forever).
///
/// **A PvP line** is one where you (or your pet) act on a hostile player-controlled unit, or one acts
/// on you: the unit flags say so. AFFILIATION_MINE (0x1) on one side, REACTION_HOSTILE (0x40) with
/// CONTROL_PLAYER (0x100) on the other. Your own lines carry 0x511 in Forever as in retail (measured
/// 2026-11-06: Crypto-Shot 0x511, friendly players 0x518). Mobs are CONTROL_NPC, so questing never
/// makes a fight; a hostile player's pet does, since it is their attack.
///
/// **A fight** is PvP lines no more than <see cref="Gap"/> apart, and it is due to be saved Gap after
/// its last PvP line, by this PC's clock, which is the log's clock too. One number for both on
/// purpose: WoW writes a fight to the file only when combat ends (0.3-0.5s after, measured
/// 2026-11-06), so the app sees a whole fight at once, and only a single rule makes a replay of the
/// log (--check-forever) split fights where the app does. The clip so ends about Gap after the fight.
/// </summary>
public sealed class FightFinder
{
    public static readonly TimeSpan Gap = TimeSpan.FromSeconds(15);

    /// <summary>A stray hit (an area spell tagging someone passing) is one line; a fight is at least this many, or a death.</summary>
    public const int MinLines = 3;

    private const uint Mine = 0x1, Hostile = 0x40, PlayerControlled = 0x100, PlayerType = 0x400;

    public sealed class Fight
    {
        public DateTime First;
        public DateTime Last;
        public int Lines;
        public string? Zone;
        /// <summary>When the log last said you entered this zone (ZONE_CHANGE, also written when logging
        /// starts): fights with the same zone and time are one battleground game (0.6.16, grouping).</summary>
        public DateTime? Entered;
        public bool YouDied;
        public readonly List<string> Opponents = [];
        public readonly List<string> Killed = [];

        public bool Counts => Lines >= MinLines || YouDied || Killed.Count > 0;

        public string Title()
        {
            var who = Opponents.Count == 0 ? "a player" : string.Join(", ", Opponents.Take(3).Select(Short)) + (Opponents.Count > 3 ? $" +{Opponents.Count - 3}" : "");
            var how = YouDied ? " (you died)" : Killed.Count > 0 ? $" (killed {string.Join(", ", Killed.Take(3).Select(Short))})" : "";
            return $"vs {who}{how}";
        }

        /// <summary>"Rick-Shotz-PvP-US" to "Rick-Shotz": Forever names are Name-Surname, then the realm.</summary>
        public static string Short(string name)
        {
            var parts = name.Split('-');
            return parts.Length >= 3 ? string.Join('-', parts.Take(parts.Length - 2)) : name;
        }
    }

    private Fight? _current;
    private string? _zone;
    private DateTime? _entered;

    public Fight? Current => _current;

    /// <summary>Reads one line. Returns a fight that this line showed to be over (a PvP line past the gap), else null.</summary>
    public Fight? Feed(string line)
    {
        var sep = line.IndexOf("  ", StringComparison.Ordinal);
        if (sep < 0 || GameTrigger.LineTime(line) is not { } t) return null;
        var body = line.AsSpan(sep + 2);

        if (body.StartsWith("ZONE_CHANGE,"))
        {
            var f = line[(sep + 2)..].Split(',');
            var zone = f.Length > 2 ? f[2].Trim('"') : null;
            // Logging switched off and on again in town writes the zone again: the same zone keeps its time.
            if (zone != _zone) _entered = t;
            _zone = zone;
            return null;
        }

        // EVENT,srcGUID,"srcName",srcFlags,srcRaidFlags,dstGUID,"dstName",dstFlags,dstRaidFlags,...
        var fields = line[(sep + 2)..].Split(',', 10);
        if (fields.Length < 9) return null;
        if (!TryFlags(fields[3], out var src) || !TryFlags(fields[7], out var dst)) return null;

        if (fields[0] == "UNIT_DIED" || fields[0] == "UNIT_DESTROYED")
        {
            if (_current == null || t - _current.Last > Gap) return null;
            if ((dst & Mine) != 0 && (dst & PlayerType) != 0) { _current.YouDied = true; _current.Last = t; }
            else if ((dst & Hostile) != 0 && (dst & PlayerType) != 0 && _current.Opponents.Contains(Name(fields[6]))) { _current.Killed.Add(Name(fields[6])); _current.Last = t; }
            return null;
        }

        string? opponent;
        if ((src & Mine) != 0 && IsHostilePlayer(dst)) opponent = (dst & PlayerType) != 0 ? Name(fields[6]) : null;
        else if (IsHostilePlayer(src) && (dst & Mine) != 0) opponent = (src & PlayerType) != 0 ? Name(fields[2]) : null;
        else return null;

        Fight? finished = null;
        if (_current != null && t - _current.Last > Gap)
        {
            finished = _current.Counts ? _current : null;
            _current = null;
        }
        _current ??= new Fight { First = t, Zone = _zone, Entered = _entered };
        _current.Last = t;
        _current.Lines++;
        if (opponent != null && !_current.Opponents.Contains(opponent)) _current.Opponents.Add(opponent);
        return finished;
    }

    /// <summary>The fight whose last PvP line is more than Gap before <paramref name="now"/>: over, to be saved. Taken once.</summary>
    public Fight? Due(DateTime now)
    {
        if (_current == null || now - _current.Last <= Gap) return null;
        var f = _current;
        _current = null;
        return f.Counts ? f : null;
    }

    private static bool IsHostilePlayer(uint flags) => (flags & Hostile) != 0 && (flags & PlayerControlled) != 0;

    private static bool TryFlags(string s, out uint flags) =>
        uint.TryParse(s.StartsWith("0x", StringComparison.OrdinalIgnoreCase) ? s[2..] : "", NumberStyles.HexNumber, CultureInfo.InvariantCulture, out flags);

    private static string Name(string quoted) => quoted.Trim('"');
}

/// <summary>
/// Saves each PvP fight in WoW Forever with OBS (2026-11-06).
///
/// **Why a replay buffer.** WoW holds a fight's lines in memory and writes them only when combat
/// ends, so nothing on disk says a fight has started (measured with a probe addon: seven writes, each
/// 0.3-0.5s after a fight ended, none during one; turning logging off and on reuses the same file).
/// So the app cannot start OBS at the first hit. It runs OBS's replay buffer instead, which keeps the
/// last <see cref="AppSettings.ForeverBufferSeconds"/> (ten minutes) of video in memory and writes
/// nothing until asked, and asks for it FightFinder.Gap after the fight's last hit.
///
/// **Cut to the fight** (the same day, Chriso: "only hold 1 min before then continue till the fight is
/// over"). The saved clip is then cut (ClipCutter) to <see cref="AppSettings.ForeverLeadSeconds"/>
/// before the first hit to <see cref="AppSettings.ForeverTailSeconds"/> after the last, so a 20s duel
/// is about a minute and a half and a six-minute brawl is whole. The full clip and its timing file
/// are written first and replaced only once the cut has worked, so a failed cut leaves the full clip.
/// A fight longer than the buffer less Gap loses its start, and the timing file says so.
///
/// **Lining up.** The full clip ends when OBS was asked to save it (this PC's clock, the log's clock
/// too) and its length is read from the MP4 itself, so it starts at (asked - length). The cut starts
/// exactly its trim later. The fight's first line minus the start is where it begins in the video, as
/// rounds are for arena videos.
///
/// The replay buffer runs only while Forever is open, and is stopped when it closes if the app
/// started it. A replay buffer the player started is used and left running.
/// </summary>
public sealed class ForeverRecorder : IDisposable
{
    private readonly AppSettings _settings;
    private readonly Action<string> _log;
    private readonly System.Threading.Timer _timer;
    private readonly FightFinder _finder = new();

    private string? _file;
    private long _offset;
    private readonly List<byte> _carry = [];
    private int _busy;
    private int _ticks;
    private bool _disposed;

    private ObsClient? _obs;
    private bool _bufferOn;
    private bool _ours;
    private string? _restoreScene; // the player's own OBS scene, while the app's WoW scene feeds the buffer
    private DateTime _retryAfter = DateTime.MinValue;
    private TaskCompletionSource<string>? _saved;

    /// <summary>A fight was saved into the videos folder.</summary>
    public event Action? Saved;

    /// <summary>Forever's Logs folder was found while the app ran (its addon is still to be put in).</summary>
    public event Action? LogsFound;

    /// <summary>Why the replay buffer is not running, when Forever is open and it should be; null otherwise.</summary>
    public string? Problem { get; private set; }

    public ForeverRecorder(AppSettings settings, Action<string> log)
    {
        _settings = settings;
        _log = log;
        _timer = new System.Threading.Timer(_ => _ = TickAsync(), null, Timeout.Infinite, Timeout.Infinite);
    }

    public void Start()
    {
        // A cut the app was closed in the middle of leaves its half-written file: none runs yet, so any there is left over.
        try
        {
            var dir = Recorder.VideosDir(_settings);
            if (Directory.Exists(dir))
                foreach (var f in Directory.EnumerateFiles(dir, "Forever *.cutting")) File.Delete(f);
        }
        catch { }
        _timer.Change(1000, 1000);
    }

    /// <summary>The next fight will be saved: OBS's replay buffer runs.</summary>
    public bool Ready => _bufferOn;

    /// <summary>How much video is held, in seconds.</summary>
    public int HeldSeconds => _settings.ForeverBufferSeconds;

    public static bool ForeverRunning() => WowRegistry.RunningForever().Any();

    private async Task TickAsync()
    {
        if (_disposed || Interlocked.Exchange(ref _busy, 1) == 1) return;
        try
        {
            if (!_settings.RecordForever)
            {
                _file = null;
                if (_bufferOn) await StopBufferAsync("recording Forever fights was turned off");
                return;
            }

            // Not found yet: the running game every tick, the whole search once a minute.
            if (_settings.ForeverLogsDir == null && (ForeverRunning() || ++_ticks % 60 == 1))
            {
                _settings.ForeverLogsDir = AppSettings.DetectForeverLogs(_settings.WowLogsDir);
                // Found after the app started (Forever installed or first opened later): its addon,
                // which turns the log on, goes in now, or no fight would ever be written down.
                if (_settings.ForeverLogsDir != null) LogsFound?.Invoke();
            }
            var running = ForeverRunning();
            var fights = ReadLog();
            if (_finder.Due(DateTime.Now) is { } due) fights.Add(due);

            if (running && !_bufferOn && DateTime.Now >= _retryAfter) await StartBufferAsync();
            foreach (var fight in fights) await SaveAsync(fight);

            if (!running && _bufferOn && _finder.Current == null) await StopBufferAsync("WoW Forever closed");
        }
        catch (Exception e)
        {
            _log($"Forever recording: {e.Message}");
        }
        finally
        {
            Interlocked.Exchange(ref _busy, 0);
        }
    }

    // ---------------------------------------------------------------- the log

    /// <summary>The fights the new lines showed to be over. A log already there when the app starts is read from its end.</summary>
    private List<FightFinder.Fight> ReadLog()
    {
        var fights = new List<FightFinder.Fight>();
        var dir = _settings.ForeverLogsDir;
        if (string.IsNullOrEmpty(dir) || !Directory.Exists(dir)) return fights;
        var newest = new DirectoryInfo(dir).GetFiles("WoWCombatLog*.txt").OrderByDescending(f => f.LastWriteTimeUtc).FirstOrDefault();
        if (newest == null) return fights;

        if (!string.Equals(newest.FullName, _file, StringComparison.OrdinalIgnoreCase))
        {
            // First look: start at the end, so old fights are never saved. A new file: from its start.
            _offset = _file == null ? newest.Length : 0;
            _file = newest.FullName;
            _carry.Clear();
        }

        using var fs = new FileStream(newest.FullName, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        if (fs.Length < _offset) { _offset = 0; _carry.Clear(); }
        if (fs.Length == _offset) return fights;
        fs.Seek(_offset, SeekOrigin.Begin);
        var bytes = new byte[Math.Min(fs.Length - _offset, 64L * 1024 * 1024)];
        var read = fs.Read(bytes, 0, bytes.Length);
        _offset += read;

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
            if (_finder.Feed(line.TrimEnd('\r')) is { } f) fights.Add(f);
        }
        if (start < read) _carry.AddRange(new ArraySegment<byte>(bytes, start, read - start));
        return fights;
    }

    // ---------------------------------------------------------------- OBS

    private async Task<bool> ConnectAsync(CancellationToken ct)
    {
        if (_obs is { Connected: true }) return true;
        if (_obs != null) { await _obs.DisposeAsync(); _obs = null; }
        // A closed OBS builds its replay buffer only if its profile says so as it starts.
        if (!ObsSetup.Running() && ObsSetup.EnableReplayBufferInProfile(_settings.ForeverBufferSeconds, out var set) && !set.Contains("already"))
            _log(set);
        var (client, message) = await ObsSetup.ConnectAsync(openIfClosed: true, ct);
        if (client == null)
        {
            Problem = message;
            return false;
        }
        _obs = client;
        _obs.ReplayBufferSaved += path => _saved?.TrySetResult(path);
        return true;
    }

    private async Task StartBufferAsync()
    {
        // long enough for a restart of OBS below (closing ~5s, opening and answering up to 30s)
        using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(120));
        if (!await ConnectAsync(ct.Token))
        {
            _log($"Could not start OBS's replay buffer for WoW Forever: {Problem}");
            _retryAfter = DateTime.Now.AddMinutes(2);
            return;
        }
        if (_settings.ObsGameCapture && _restoreScene == null)
        {
            var (prior, note) = await ObsSetup.UseWowSceneAsync(_obs!, _settings, ct.Token);
            _restoreScene = prior;
            if (note != null) _log(note);
        }
        var (ours, problem, needsRestart) = await ObsSetup.StartReplayBufferAsync(_obs!, _settings.ForeverBufferSeconds, ct.Token);
        // OBS builds its replay buffer only as it starts (Cbags, 12 Nov: "close OBS and open it again").
        // The app does that itself now, unless OBS is recording or streaming something.
        if (needsRestart && !await ObsCheck.BusyAsync(_obs!, ct.Token))
        {
            _log("OBS's replay buffer was off: restarting OBS to turn it on (it wasn't recording or streaming).");
            await _obs!.DisposeAsync();
            _obs = null;
            var (restarted, why) = await ObsCheck.RestartAsync(() => ObsSetup.EnableReplayBufferInProfile(_settings.ForeverBufferSeconds, out _), ct.Token);
            if (restarted && await ConnectAsync(ct.Token))
            {
                if (_settings.ObsGameCapture)
                {
                    var (prior, note) = await ObsSetup.UseWowSceneAsync(_obs!, _settings, ct.Token);
                    _restoreScene ??= prior;
                    if (note != null) _log(note);
                }
                (ours, problem, needsRestart) = await ObsSetup.StartReplayBufferAsync(_obs!, _settings.ForeverBufferSeconds, ct.Token);
            }
            else problem = restarted ? Problem : why;
        }
        if (problem != null)
        {
            Problem = problem;
            _log(problem);
            _retryAfter = DateTime.Now.AddMinutes(2);
            return;
        }
        _bufferOn = true;
        _ours = ours;
        Problem = null;
        _log(ours
            ? $"WoW Forever is open: OBS's replay buffer is running ({_settings.ForeverBufferSeconds}s). Each PvP fight is saved when it ends."
            : "WoW Forever is open: using the replay buffer already running in OBS. Each PvP fight is saved when it ends.");
    }

    private async Task StopBufferAsync(string reason)
    {
        _bufferOn = false;
        if (_ours && _obs is { Connected: true } obs)
        {
            using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(15));
            await obs.TryRequestAsync("StopReplayBuffer", null, ct.Token);
            _log($"Stopped OBS's replay buffer ({reason}).");
        }
        if (_obs is { Connected: true } still)
        {
            using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(15));
            await ObsSetup.RestoreSceneAsync(still, _restoreScene, _settings, ct.Token);
        }
        _restoreScene = null;
        _ours = false;
        if (_obs != null) { await _obs.DisposeAsync(); _obs = null; }
    }

    private async Task SaveAsync(FightFinder.Fight fight)
    {
        var what = $"PvP fight at {fight.First:HH:mm:ss} {fight.Title()}";
        var (path, asked) = await SaveWithObsAsync(what);
        if (path == null) return;

        var dir = Recorder.VideosDir(_settings);
        Directory.CreateDirectory(dir);
        var target = Path.Combine(dir, $"Forever {fight.First:yyyy-MM-dd HH-mm-ss}{Path.GetExtension(path)}");
        try { await Task.Run(() => File.Move(path, target, overwrite: false)); path = target; }
        catch (Exception e) { _log($"Could not move the clip into {dir}: {e.Message}"); }

        var length = Mp4.Duration(path);
        var clipStart = asked - (length ?? TimeSpan.FromSeconds(HeldSeconds));
        WriteSidecar(path, fight, clipStart, asked, exact: length != null);
        var mb = new FileInfo(path).Length / 1048576.0;
        var lost = fight.First < clipStart ? $" Its first {(clipStart - fight.First).TotalSeconds:N0}s were before the replay: hold more minutes (ForeverBufferSeconds) to keep longer fights whole." : "";
        _log($"Saved the {what}: {Path.GetFileName(path)} ({mb:N0} MB, {(length ?? TimeSpan.Zero).TotalSeconds:N0}s).{lost}");
        Saved?.Invoke();

        // The cut runs on its own, so the next fight is never kept waiting; one cut at a time.
        if (length is { } full)
        {
            var video = path;
            _lastCut = Task.Run(() => CutAsync(video, fight, clipStart, asked, full, what));
        }
        else Recorder.KeepWithinLimit(_settings, dir, path, _log);
    }

    /// <summary>OBS saves its buffer and says where: the file, and when the save was asked (the clip's last frame).</summary>
    private async Task<(string? Path, DateTime Asked)> SaveWithObsAsync(string what)
    {
        var asked = DateTime.Now;
        if (!_bufferOn || _obs is not { Connected: true } obs)
        {
            _log($"Did not save the {what}: OBS's replay buffer is not running{(Problem != null ? $" ({Problem})" : "")}.");
            return (null, asked);
        }

        using var ct = new CancellationTokenSource(TimeSpan.FromSeconds(60));
        _saved = new TaskCompletionSource<string>(TaskCreationOptions.RunContinuationsAsynchronously);
        asked = DateTime.Now;
        try { await obs.RequestAsync("SaveReplayBuffer", null, ct.Token); }
        catch (ObsClient.ObsException e)
        {
            _log($"Did not save the {what}: {e.Message}");
            if (!obs.Connected) _bufferOn = false;
            return (null, asked);
        }

        string? path;
        try { path = await _saved.Task.WaitAsync(TimeSpan.FromSeconds(30)); }
        catch (TimeoutException)
        {
            var last = await obs.TryRequestAsync("GetLastReplayBufferReplay", null, ct.Token);
            path = last is { } l && l.TryGetProperty("savedReplayPath", out var p) ? p.GetString() : null;
        }
        if (path == null || !File.Exists(path))
        {
            _log($"OBS did not say where it saved the {what}.");
            return (null, asked);
        }
        return (path, asked);
    }

    private readonly SemaphoreSlim _cutting = new(1, 1);
    private Task _lastCut = Task.CompletedTask;

    /// <summary>
    /// The saved clip cut to the lead before the first hit and the tail after the last. The full clip
    /// and its timing file stay until the cut is written, then the cut takes their name.
    /// </summary>
    private async Task CutAsync(string video, FightFinder.Fight fight, DateTime clipStart, DateTime clipEnd, TimeSpan full, string what)
    {
        var fromStart = fight.First - TimeSpan.FromSeconds(_settings.ForeverLeadSeconds) - clipStart;
        var fromEnd = clipEnd - (fight.Last + TimeSpan.FromSeconds(_settings.ForeverTailSeconds));
        if (fromStart < TimeSpan.Zero) fromStart = TimeSpan.Zero;
        if (fromEnd < TimeSpan.Zero) fromEnd = TimeSpan.Zero;
        if (fromStart + fromEnd < TimeSpan.FromSeconds(10)) { Recorder.KeepWithinLimit(_settings, Path.GetDirectoryName(video)!, video, _log); return; }

        await _cutting.WaitAsync();
        try
        {
            var temp = video + ".cutting";
            var started = DateTime.Now;
            var why = await ClipCutter.CutAsync(video, temp, fromStart, fromEnd, full);
            if (why != null || !File.Exists(temp))
            {
                _log($"Kept the whole clip of the {what}: {why ?? "the cut was not written"}.");
                return;
            }
            var cutLength = Mp4.Duration(temp);
            var expected = full - fromStart - fromEnd;
            var before = new FileInfo(video).Length;
            File.Move(temp, video, overwrite: true);
            var start = clipStart + fromStart;
            WriteSidecar(video, fight, start, start + (cutLength ?? expected), exact: cutLength != null);
            _log($"Cut the {what} to {(cutLength ?? expected).TotalSeconds:N0}s ({before / 1048576.0:N0} MB to {new FileInfo(video).Length / 1048576.0:N0} MB, "
                + $"{(DateTime.Now - started).TotalSeconds:N0}s to cut; expected {expected.TotalSeconds:F2}s, the file says {cutLength?.TotalSeconds.ToString("F2", CultureInfo.InvariantCulture) ?? "?"}s).");
            Saved?.Invoke();
        }
        catch (Exception e)
        {
            _log($"Kept the whole clip of the {what}: the cut failed ({e.Message}).");
            try { File.Delete(video + ".cutting"); } catch { }
        }
        finally
        {
            _cutting.Release();
            Recorder.KeepWithinLimit(_settings, Path.GetDirectoryName(video)!, video, _log);
        }
    }

    private static string Clock(DateTime t) => t.ToString("yyyy-MM-dd HH:mm:ss.fff", CultureInfo.InvariantCulture);

    private void WriteSidecar(string video, FightFinder.Fight fight, DateTime clipStart, DateTime clipEnd, bool exact)
    {
        var json = new JsonObject
        {
            ["format"] = 1,
            ["app"] = $"MindCollector {Application.ProductVersion.Split('+')[0]}",
            ["game"] = "forever",
            ["video"] = Path.GetFileName(video),
            ["clock"] = "local time on this PC, the same clock as the combat log's own timestamps",
            // The clip's first frame: when OBS was asked to save, less the clip's length (from the MP4, or the buffer's setting when it could not be read).
            ["recordingStarted"] = Clock(clipStart),
            ["recordingStopped"] = Clock(clipEnd),
            ["timing"] = exact ? "clip length read from the file" : "clip length assumed from the buffer setting",
            ["bracket"] = $"Forever: {fight.Title()}" + (fight.Zone != null ? $" · {fight.Zone}" : ""),
            ["log"] = _file != null ? Path.GetFileName(_file) : null,
            // The fight's first PvP line, so the Videos tab has a button to jump to it.
            ["rounds"] = new JsonArray(Clock(fight.First)),
            ["fight"] = new JsonObject
            {
                ["first"] = Clock(fight.First),
                ["last"] = Clock(fight.Last),
                ["lines"] = fight.Lines,
                ["zone"] = fight.Zone,
                ["entered"] = fight.Entered is { } entered ? Clock(entered) : null,
                ["opponents"] = new JsonArray(fight.Opponents.Select(o => (JsonNode)o).ToArray()),
                ["killed"] = new JsonArray(fight.Killed.Select(o => (JsonNode)o).ToArray()),
                ["youDied"] = fight.YouDied,
                ["startCutOff"] = fight.First < clipStart,
            },
        };
        try { File.WriteAllText(video + Recorder.SidecarSuffix, json.ToJsonString(new JsonSerializerOptions { WriteIndented = true })); }
        catch (Exception e) { _log($"Could not write the clip's timing file: {e.Message}"); }
    }

    /// <summary>For --replay-test: start the buffer, wait, save one clip as a fight would be, and stop.</summary>
    public async Task TestAsync(TimeSpan wait)
    {
        await StartBufferAsync();
        if (!_bufferOn) return;
        await Task.Delay(wait);
        var now = DateTime.Now;
        var fight = new FightFinder.Fight { First = now - TimeSpan.FromSeconds(Math.Min(10, wait.TotalSeconds / 2)), Last = now, Lines = 1, Zone = "Test" };
        fight.Opponents.Add("Test-Clip-Test-US");
        await SaveAsync(fight);
        await StopBufferAsync("the test ended");
        await _lastCut;
    }

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
                if (_bufferOn) await StopBufferAsync("the app was closed");
            }).Wait(TimeSpan.FromSeconds(20));
        }
        catch { }
    }
}

/// <summary>An MP4's length, from its movie header (moov > mvhd), with no library. Null when it is not an MP4 or has no header yet.</summary>
internal static class Mp4
{
    public static TimeSpan? Duration(string path)
    {
        try
        {
            using var fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
            using var r = new BinaryReader(fs);
            return Find(r, 0, fs.Length, "moov") is { } moov && Find(r, moov.Start, moov.End, "mvhd") is { } mvhd ? ReadMvhd(r, mvhd.Start) : null;
        }
        catch { return null; }
    }

    private static (long Start, long End)? Find(BinaryReader r, long from, long to, string type)
    {
        var pos = from;
        while (pos + 8 <= to)
        {
            r.BaseStream.Seek(pos, SeekOrigin.Begin);
            var size = (long)BigEndian(r.ReadBytes(4));
            var name = Encoding.ASCII.GetString(r.ReadBytes(4));
            var header = 8L;
            if (size == 1) { size = (long)BigEndian(r.ReadBytes(8)); header = 16; }
            else if (size == 0) size = to - pos;
            if (size < header) return null;
            if (name == type) return (pos + header, pos + size);
            pos += size;
        }
        return null;
    }

    private static TimeSpan? ReadMvhd(BinaryReader r, long start)
    {
        r.BaseStream.Seek(start, SeekOrigin.Begin);
        var version = r.ReadByte();
        r.ReadBytes(3);
        ulong timescale, duration;
        if (version == 1) { r.ReadBytes(16); timescale = BigEndian(r.ReadBytes(4)); duration = BigEndian(r.ReadBytes(8)); }
        else { r.ReadBytes(8); timescale = BigEndian(r.ReadBytes(4)); duration = BigEndian(r.ReadBytes(4)); }
        return timescale == 0 ? null : TimeSpan.FromSeconds(duration / (double)timescale);
    }

    private static ulong BigEndian(byte[] b)
    {
        ulong v = 0;
        foreach (var x in b) v = (v << 8) | x;
        return v;
    }
}

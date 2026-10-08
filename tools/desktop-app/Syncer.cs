using System.Diagnostics;

namespace MindCollector.Desktop;

/// <summary>
/// One pass over the player's logs: send every finished round not sent yet, tell the server the batch
/// is done, and move logs that are fully sent out of WoW's folder. The rules are the PowerShell app's
/// (tools/log-manager), which ran for weeks on real games:
///
/// - A log that changed in the last 20 seconds is being written; wait.
/// - The newest log while WoW runs is read only up to the last finished round, and the batch is only
///   finished ("done", which assembles lobbies) when its last arena marker is an END: a Solo Shuffle
///   writes a START per round and one END per lobby, so that is true only between lobbies.
/// - A log is moved only when all of it is sent, WoW is not writing it, and it opens exclusively.
///   It is copied, its size checked, then the original deleted.
/// </summary>
public sealed class Syncer
{
    private static readonly TimeSpan Quiet = TimeSpan.FromSeconds(20);

    private readonly AppSettings _settings;
    private readonly Action<string> _log;

    public Syncer(AppSettings settings, Action<string> log)
    {
        _settings = settings;
        _log = log;
    }

    public sealed record PassResult(int Sent, int Skipped, int Failed, bool CalledDone);

    public static bool WowRunning() =>
        Process.GetProcessesByName("Wow").Length > 0 || Process.GetProcessesByName("WowT").Length > 0 || Process.GetProcessesByName("WowB").Length > 0;

    public async Task<PassResult> RunAsync(bool manual, CancellationToken ct)
    {
        var key = _settings.Key;
        if (string.IsNullOrEmpty(key)) { _log("No website key yet: add it in Settings."); return new(0, 0, 0, false); }
        if (string.IsNullOrEmpty(_settings.WowLogsDir) || !Directory.Exists(_settings.WowLogsDir))
        {
            _log("WoW's Logs folder was not found: set it in Settings.");
            return new(0, 0, 0, false);
        }

        using var api = new ApiClient(_settings.ServerUrl, key);

        // A measure change on the server: send everything again, from every log still kept.
        var version = await api.VersionAsync(ct);
        if (version > _settings.ServerVersion)
        {
            if (_settings.ServerVersion > 0)
            {
                _log($"The website now measures at version {version}: sending your kept logs again so every game is re-measured.");
                _settings.Files.Clear();
            }
            _settings.ServerVersion = version;
            _settings.Save();
        }

        var logs = LogFiles();
        var wow = WowRunning();
        var newest = logs.FirstOrDefault()?.FullName;
        int sent = 0, skipped = 0, failed = 0;
        bool lobbyFinished = false, anySent = false;

        foreach (var f in logs)
        {
            ct.ThrowIfCancellationRequested();
            f.Refresh();
            var progress = _settings.Files.TryGetValue(f.FullName, out var p) ? p : new FileProgress();
            var unchanged = progress.SeenLength == f.Length && progress.SeenWriteTicks == f.LastWriteTimeUtc.Ticks;
            if (unchanged && progress.Offset >= f.Length) continue;
            if (!manual && unchanged) continue;
            if (!manual && DateTime.Now - f.LastWriteTime < Quiet) continue;

            // The log can still grow only if it is the newest one and WoW is running.
            var live = wow && f.FullName == newest;
            var scan = RoundScanner.Scan(f.FullName, progress.Offset, closeOpenRound: !live);
            var failedHere = 0;

            foreach (var round in scan.Rounds)
            {
                var result = await api.SendRoundAsync(round.Text, ct);
                switch (result.Status)
                {
                    case "stored": sent++; anySent = true; break;
                    case "skipped": skipped++; break;
                    default:
                        failed++;
                        failedHere++;
                        _log($"  A round was not taken: {result.Reason ?? result.Status}");
                        break;
                }
            }

            // Only move past what was dealt with; a failed round is tried again next pass.
            if (failedHere == 0) progress.Offset = scan.NextOffset;
            progress.SeenLength = f.Length;
            progress.SeenWriteTicks = f.LastWriteTimeUtc.Ticks;
            _settings.Files[f.FullName] = progress;
            _settings.Save();

            if (scan.Rounds.Count > 0)
                _log($"{f.Name}: {scan.Rounds.Count} round(s) read, {sent} sent so far{(scan.OpenRound ? "; a game is still in progress" : "")}.");
            if (!live || scan.LastMarkerIsEnd) lobbyFinished = true;
        }

        var calledDone = false;
        if (anySent && lobbyFinished)
        {
            var assembled = await api.DoneAsync(ct);
            calledDone = true;
            _log($"Sent {sent} round(s){(skipped > 0 ? $", {skipped} skipped (not a rated arena, or no combatant info)" : "")}. The website is building your pages{(assembled > 0 ? $" ({assembled} shuffle lobby(s) put together)" : "")}.");
        }
        else if (manual && sent == 0 && failed == 0)
        {
            _log("Nothing new to send.");
        }

        if (_settings.MoveLogs) MoveFinishedLogs(wow, newest);
        return new(sent, skipped, failed, calledDone);
    }

    private List<FileInfo> LogFiles()
    {
        var files = new List<FileInfo>();
        foreach (var dir in new[] { _settings.WowLogsDir, _settings.MoveTo })
        {
            if (!string.IsNullOrEmpty(dir) && Directory.Exists(dir))
                files.AddRange(new DirectoryInfo(dir).GetFiles("WoWCombatLog*.txt"));
        }
        return files.OrderByDescending(f => f.LastWriteTimeUtc).ToList();
    }

    private void MoveFinishedLogs(bool wow, string? newest)
    {
        if (string.IsNullOrEmpty(_settings.MoveTo) || string.IsNullOrEmpty(_settings.WowLogsDir)) return;

        foreach (var f in new DirectoryInfo(_settings.WowLogsDir).GetFiles("WoWCombatLog*.txt"))
        {
            if (wow && f.FullName == newest) continue;
            if (!_settings.Files.TryGetValue(f.FullName, out var p) || p.Offset < f.Length) continue;
            if (!OpensExclusively(f.FullName)) continue;

            Directory.CreateDirectory(_settings.MoveTo);
            var target = Path.Combine(_settings.MoveTo, f.Name);
            for (int n = 2; File.Exists(target); n++)
                target = Path.Combine(_settings.MoveTo, $"{Path.GetFileNameWithoutExtension(f.Name)}-{n}{f.Extension}");
            var partial = target + ".partial";

            try
            {
                File.Copy(f.FullName, partial, overwrite: true);
                if (new FileInfo(partial).Length != f.Length) throw new IOException("size mismatch after copy");
                File.Delete(f.FullName);
                File.Move(partial, target);

                var moved = new FileInfo(target);
                _settings.Files.Remove(f.FullName);
                _settings.Files[target] = new FileProgress { Offset = moved.Length, SeenLength = moved.Length, SeenWriteTicks = moved.LastWriteTimeUtc.Ticks };
                _settings.Save();
                _log($"Moved {f.Name} ({f.Length / 1048576:N0} MB) to {_settings.MoveTo}.");
            }
            catch (Exception e)
            {
                try { if (File.Exists(partial)) File.Delete(partial); } catch { }
                _log($"Could not move {f.Name}: {e.Message}. Will try again later.");
            }
        }
    }

    private static bool OpensExclusively(string path)
    {
        try { using var fs = new FileStream(path, FileMode.Open, FileAccess.ReadWrite, FileShare.None); return true; }
        catch { return false; }
    }
}

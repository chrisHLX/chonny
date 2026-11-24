using System.Text;

namespace MindCollector.Desktop;

/// <summary>
/// WoW Forever's combat logs off WoW's drive (0.6.35, 2026-11-24, Chriso: "the logs are growing on my C
/// drive anyway. And we will need them later anyway so yeah at least move them to the D drive and if
/// possible break them up into folders with each recording").
///
/// Run only while Forever is closed (at start, and when it closes), since a log Forever still has open is
/// its session's. For each log no longer written:
/// 1. **A piece in each game's folder.** A game folder (ForeverRecorder.GameFolder) whose clips' timing
///    files name this log gets the log's lines for that game, under the log's own name, so a clip and the
///    lines it came from sit together and go together when the folder is deleted. The piece runs from
///    entering the zone (a battleground from its start) but no more than <see cref="Before"/> before the
///    first fight, to leaving the zone but no more than <see cref="After"/> after the last.
/// 2. **The whole log moved** to <see cref="ArchiveDir"/>, beside the retail logs the sync moves (copy,
///    check the size, delete; as Syncer.MoveFinishedLogs), oldest first, stopping at the first that
///    fails, so the newest log left in WoW's folder is always the newest there was (ForeverRecorder
///    follows the newest, and an older one taken for a new one would be read from its start).
/// Questing is in the whole logs and not in the pieces; nothing is deleted except a log once its copy is whole.
/// </summary>
internal static class ForeverLogKeeper
{
    public static readonly TimeSpan Before = TimeSpan.FromMinutes(15);
    public static readonly TimeSpan After = TimeSpan.FromMinutes(5);

    /// <summary>"forever-logs" beside the retail logs' folder (D:\MindCollector\wow-logs, so D:\MindCollector\forever-logs).</summary>
    public static string ArchiveDir(AppSettings s) =>
        Path.Combine(Path.GetDirectoryName((s.MoveTo ?? "").TrimEnd('\\', '/')) is { Length: > 0 } parent
            ? parent
            : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "MindCollector"), "forever-logs");

    public static (int Pieces, int Moved) Run(AppSettings s, Action<string> log) =>
        Run(s.ForeverLogsDir, Recorder.VideosDir(s), ArchiveDir(s), log);

    /// <summary>The work, on any three folders (--keep-forever-logs runs it on a test copy).</summary>
    public static (int Pieces, int Moved) Run(string? logsDir, string videosDir, string archiveDir, Action<string> log)
    {
        if (string.IsNullOrEmpty(logsDir) || !Directory.Exists(logsDir)) return (0, 0);
        var logs = new DirectoryInfo(logsDir).GetFiles("WoWCombatLog*.txt").OrderBy(f => f.LastWriteTimeUtc).ToList();

        // Pieces first, from WoW's folder or the archive, wherever the log is now.
        var pieces = 0;
        var games = VideoLibrary.Load(videosDir)
            .Where(v => v.Fight is { Log: { Length: > 0 } } && Path.GetDirectoryName(v.Name) is { Length: > 0 })
            .GroupBy(v => (Folder: Path.Combine(videosDir, Path.GetDirectoryName(v.Name)!), Log: v.Fight!.Log!))
            .Where(g => !File.Exists(Path.Combine(g.Key.Folder, g.Key.Log)))
            .GroupBy(g => g.Key.Log, StringComparer.OrdinalIgnoreCase);
        foreach (var byLog in games)
        {
            var source = logs.FirstOrDefault(f => f.Name.Equals(byLog.Key, StringComparison.OrdinalIgnoreCase))?.FullName
                ?? (File.Exists(Path.Combine(archiveDir, byLog.Key)) ? Path.Combine(archiveDir, byLog.Key) : null);
            if (source == null || !OpensExclusively(source)) continue;
            try
            {
                var written = Slice(source, byLog.Select(g => (g.Key.Folder, (IReadOnlyList<ForeverFight>)g.Select(v => v.Fight!).ToList())).ToList());
                pieces += written;
            }
            catch (Exception e) { log($"Could not write the log's lines for {byLog.Key} into its games' folders: {e.Message}"); }
        }

        var moved = 0;
        foreach (var f in logs)
        {
            if (!OpensExclusively(f.FullName)) break;
            Directory.CreateDirectory(archiveDir);
            var target = Path.Combine(archiveDir, f.Name);
            for (var n = 2; File.Exists(target); n++)
                target = Path.Combine(archiveDir, $"{Path.GetFileNameWithoutExtension(f.Name)}-{n}{f.Extension}");
            var partial = target + ".partial";
            try
            {
                File.Copy(f.FullName, partial, overwrite: true);
                if (new FileInfo(partial).Length != f.Length) throw new IOException("size mismatch after copy");
                File.Delete(f.FullName);
                File.Move(partial, target);
                moved++;
            }
            catch (Exception e)
            {
                try { if (File.Exists(partial)) File.Delete(partial); } catch { }
                log($"Could not move the Forever log {f.Name}: {e.Message}. Will try again when Forever next closes.");
                break;
            }
        }
        if (pieces > 0 || moved > 0)
            log($"Forever's logs: {(pieces > 0 ? $"{pieces} game folder{(pieces == 1 ? "" : "s")} given {(pieces == 1 ? "its" : "their")} log lines" : "")}{(pieces > 0 && moved > 0 ? ", " : "")}{(moved > 0 ? $"{moved} log{(moved == 1 ? "" : "s")} moved to {archiveDir}" : "")}.");
        return (pieces, moved);
    }

    /// <summary>One pass over the log, writing each game's lines into its folder. Returns how many pieces were written.</summary>
    private static int Slice(string source, List<(string Folder, IReadOnlyList<ForeverFight> Fights)> games)
    {
        var name = Path.GetFileName(source);
        var outs = games.Select(g =>
        {
            var first = g.Fights.Min(f => f.First);
            var last = g.Fights.Max(f => f.Last);
            var entered = g.Fights.Where(f => f.Entered is { } e && e <= first).Select(f => f.Entered!.Value).DefaultIfEmpty(first - Before).Min();
            return new Piece(Path.Combine(g.Folder, name), g.Fights[0].Zone, entered > first - Before ? entered : first - Before, last, last + After);
        }).ToList();

        string? header = null;
        string? zone = null;
        try
        {
            using (var reader = new StreamReader(new FileStream(source, FileMode.Open, FileAccess.Read, FileShare.Read), new UTF8Encoding(false)))
            {
                string? line;
                while ((line = reader.ReadLine()) != null)
                {
                    var sep = line.IndexOf("  ", StringComparison.Ordinal);
                    if (sep < 0 || GameTrigger.LineTime(line) is not { } t) continue;
                    var body = line.AsSpan(sep + 2);
                    if (header == null && body.StartsWith("COMBAT_LOG_VERSION,")) header = line;
                    if (body.StartsWith("ZONE_CHANGE,"))
                    {
                        var f = line[(sep + 2)..].Split(',');
                        zone = f.Length > 2 ? f[2].Trim('"') : null;
                    }
                    foreach (var p in outs)
                    {
                        if (p.Done || t < p.From) continue;
                        // After the last fight, leaving the zone ends the game (a battleground's end).
                        if (t > p.Stop || (t > p.Last && zone != p.Zone)) { p.Done = true; continue; }
                        if (p.Writer == null)
                        {
                            p.Writer = new StreamWriter(p.Path + ".partial", false, new UTF8Encoding(false)) { NewLine = "\r\n" };
                            if (header != null && !ReferenceEquals(header, line)) p.Writer.WriteLine(header);
                        }
                        p.Writer.WriteLine(line);
                    }
                    if (outs.All(p => p.Done)) break;
                }
            }
        }
        catch
        {
            foreach (var p in outs.Where(p => p.Writer != null))
            {
                p.Writer!.Dispose();
                try { File.Delete(p.Path + ".partial"); } catch { }
            }
            throw;
        }

        var written = 0;
        foreach (var p in outs)
        {
            if (p.Writer == null) continue;
            p.Writer.Dispose();
            File.Move(p.Path + ".partial", p.Path, overwrite: true);
            written++;
        }
        return written;
    }

    private sealed class Piece(string path, string? zone, DateTime from, DateTime last, DateTime stop)
    {
        public string Path = path;
        public string? Zone = zone;
        public DateTime From = from, Last = last, Stop = stop;
        public StreamWriter? Writer;
        public bool Done;
    }

    /// <summary>Nothing else has it open. A few tries: ForeverRecorder opens the newest log for a moment every second.</summary>
    private static bool OpensExclusively(string path)
    {
        for (var tries = 0; ; tries++)
        {
            try { using var fs = new FileStream(path, FileMode.Open, FileAccess.ReadWrite, FileShare.None); return true; }
            catch when (tries < 3) { Thread.Sleep(250); }
            catch { return false; }
        }
    }
}

using System.Text;

namespace MindCollector.Desktop;

/// <summary>
/// Two switches that run without the window and write what they found to a text file in the app's
/// data folder (MINDCOLLECTOR_DATA, or %APPDATA%\MindCollector Desktop):
///
///   MindCollector.exe --check-log "path\to\WoWCombatLog-....txt"
///       Lists every arena round the app would send from that log: when it starts, its bracket and
///       its size. Sends nothing. What a tester runs when "my games did not show up".
///
///   MindCollector.exe --sync-once --server URL --key KEY --logs FOLDER [--move FOLDER]
///       One sync pass with these settings, as the window's timer would run it. For checking a
///       build end to end against a test server.
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

    private static void Write(string name, string text)
    {
        Directory.CreateDirectory(AppSettings.Dir);
        File.WriteAllText(Path.Combine(AppSettings.Dir, name), text);
    }
}

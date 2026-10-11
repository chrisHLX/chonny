using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace MindCollector.Desktop;

/// <summary>
/// Everything the app remembers, in %APPDATA%\MindCollector Desktop\settings.json. Kept apart from
/// the developer's PowerShell app (%APPDATA%\MindCollector) so the two never share state on one PC.
/// The website key is stored encrypted for this Windows user (DPAPI), never as plain text.
/// </summary>
public sealed class AppSettings
{
    /// <summary>MINDCOLLECTOR_DATA moves it elsewhere, so a test run never touches a real install.</summary>
    public static readonly string Dir = Environment.GetEnvironmentVariable("MINDCOLLECTOR_DATA") is { Length: > 0 } custom
        ? custom
        : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "MindCollector Desktop");

    private static readonly string FilePath = Path.Combine(Dir, "settings.json");

    public string ServerUrl { get; set; } = "https://mindcollector.com";
    public string? KeyProtected { get; set; }
    public string? WowLogsDir { get; set; }
    public string? MoveTo { get; set; }
    public bool MoveLogs { get; set; } = true;

    /// <summary>
    /// Logs written before this are never sent (2026-10-12): set to two weeks before the first sync,
    /// so a new player's first connect sends their recent games, not months of them at once to a
    /// one-core server. Kept, so a later re-send (a new measure version) skips the same old logs.
    /// </summary>
    public DateTime? SendSince { get; set; }

    /// <summary>The measure version the server reported last; a higher one re-sends every kept log.</summary>
    public int ServerVersion { get; set; }

    /// <summary>Per log file (full path): how far it has been read and sent.</summary>
    public Dictionary<string, FileProgress> Files { get; set; } = new(StringComparer.OrdinalIgnoreCase);

    [JsonIgnore]
    public string? Key
    {
        get
        {
            if (string.IsNullOrEmpty(KeyProtected)) return null;
            try
            {
                var bytes = ProtectedData.Unprotect(Convert.FromBase64String(KeyProtected), null, DataProtectionScope.CurrentUser);
                return Encoding.UTF8.GetString(bytes);
            }
            catch { return null; }
        }
        set
        {
            KeyProtected = string.IsNullOrWhiteSpace(value)
                ? null
                : Convert.ToBase64String(ProtectedData.Protect(Encoding.UTF8.GetBytes(value.Trim()), null, DataProtectionScope.CurrentUser));
        }
    }

    public static AppSettings Load()
    {
        AppSettings s;
        try
        {
            s = File.Exists(FilePath)
                ? JsonSerializer.Deserialize<AppSettings>(File.ReadAllText(FilePath)) ?? new AppSettings()
                : new AppSettings();
        }
        catch { s = new AppSettings(); }

        s.Files = new Dictionary<string, FileProgress>(s.Files, StringComparer.OrdinalIgnoreCase);
        s.WowLogsDir ??= DetectWowLogs();
        s.MoveTo ??= Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "MindCollector", "wow-logs");
        return s;
    }

    public void Save()
    {
        Directory.CreateDirectory(Dir);
        var tmp = FilePath + ".tmp";
        File.WriteAllText(tmp, JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
        File.Move(tmp, FilePath, overwrite: true);
    }

    /// <summary>WoW's usual install places; the first that exists. The player can change it in Settings.</summary>
    private static string? DetectWowLogs()
    {
        // Where Battle.net says WoW is (2026-10-11): finds a game on any drive, not only the usual
        // folders below. The Logs folder appears the first time WoW writes a log, so it is made here
        // when missing; WoW writes into it as it would have.
        foreach (var retail in WowRegistry.RetailFolders())
        {
            if (!Directory.Exists(retail)) continue;
            var logs = Path.Combine(retail, "Logs");
            try { Directory.CreateDirectory(logs); } catch { continue; }
            return logs;
        }

        string[] roots =
        [
            @"C:\Program Files (x86)\World of Warcraft",
            @"C:\Program Files\World of Warcraft",
            @"C:\World of Warcraft",
            @"D:\World of Warcraft",
            @"D:\Games\World of Warcraft",
            @"E:\World of Warcraft",
        ];
        foreach (var root in roots)
        {
            var logs = Path.Combine(root, "_retail_", "Logs");
            if (Directory.Exists(logs)) return logs;
        }
        return null;
    }
}

/// <summary>WoW's _retail_ folder as Battle.net records it in the registry.</summary>
internal static class WowRegistry
{
    public static IEnumerable<string> RetailFolders()
    {
        var values = new List<string?>();
        try
        {
            using var lm = Microsoft.Win32.RegistryKey.OpenBaseKey(Microsoft.Win32.RegistryHive.LocalMachine, Microsoft.Win32.RegistryView.Registry32);
            values.Add(lm.OpenSubKey(@"SOFTWARE\Blizzard Entertainment\World of Warcraft")?.GetValue("InstallPath") as string);
            values.Add(lm.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\World of Warcraft")?.GetValue("InstallLocation") as string);
        }
        catch { }
        foreach (var v in values)
        {
            if (string.IsNullOrWhiteSpace(v)) continue;
            var path = v.Trim().TrimEnd('\\', '/');
            yield return Path.GetFileName(path).Equals("_retail_", StringComparison.OrdinalIgnoreCase) ? path : Path.Combine(path, "_retail_");
        }
    }
}

public sealed class FileProgress
{
    /// <summary>Bytes read and dealt with: everything before it is sent, or was outside any round.</summary>
    public long Offset { get; set; }

    /// <summary>Length and write time when last read, to tell whether the file has changed since.</summary>
    public long SeenLength { get; set; }
    public long SeenWriteTicks { get; set; }
}

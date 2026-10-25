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

    /// <summary>Record each arena game with OBS (Recorder). Off until the player turns it on.</summary>
    public bool RecordGames { get; set; }

    /// <summary>Where the videos go; empty means beside the moved logs (DefaultVideosDir).</summary>
    public string? VideosDir { get; set; }

    /// <summary>The most the app's own videos may take, in GB; past it the oldest go. 0 keeps them all.</summary>
    public int VideoLimitGb { get; set; } = 100;

    /// <summary>Ctrl+Shift+M, from anywhere (WoW included), marks the moment in the game being played.</summary>
    public bool MarkHotkey { get; set; } = true;

    /// <summary>The character the Games list shows; empty shows every character's games.</summary>
    public string? Character { get; set; }

    /// <summary>Notes and marks written in the app, newest last: waiting to be sent, and the recent ones shown on "This game".</summary>
    public List<AppNote> Notes { get; set; } = [];

    /// <summary>Whether the developer app's notes.json has been brought over (once).</summary>
    public bool DevNotesImported { get; set; }

    /// <summary>
    /// Developer mode (settings.json only, 2026-10-25): a copy of this project, whose local archive and
    /// database are kept up to date after each game, the job the developer app used to do. Null for
    /// every player. See README, "Developer mode".
    /// </summary>
    public string? LocalSyncDir { get; set; }

    /// <summary>Developer mode: the local user whose Watch pages are rebuilt after a game (`wow:watch-page --user=`).</summary>
    public int? LocalSyncUser { get; set; }

    /// <summary>Developer mode: logs last written before this are left alone (they were synced by the developer app).</summary>
    public DateTime? LocalSyncSince { get; set; }

    /// <summary>Held while the settings are written or the notes changed: the sync thread and the window both do both.</summary>
    [JsonIgnore]
    public readonly object Gate = new();

    /// <summary>A "videos" folder next to the moved logs, so a player's games and their videos sit together.</summary>
    public static string DefaultVideosDir(AppSettings s) =>
        Path.Combine(Path.GetDirectoryName((s.MoveTo ?? "").TrimEnd('\\', '/')) is { Length: > 0 } parent
            ? parent
            : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "MindCollector"), "videos");

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

    /// <summary>Whether this Windows user has run the app before (the Store build's first run goes straight to Connect).</summary>
    public static bool Exists() => File.Exists(FilePath);

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
        lock (Gate)
        {
            Directory.CreateDirectory(Dir);
            var tmp = FilePath + ".tmp";
            File.WriteAllText(tmp, JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
            File.Move(tmp, FilePath, overwrite: true);
        }
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

    /// <summary>Developer mode: how far the local archive has read this log (`wow:ingest-combatlog`).</summary>
    public long LocalOffset { get; set; }
}

/// <summary>
/// A note or a mark written in the app. Times are this PC's wall clock as "yyyy-MM-dd HH:mm:ss",
/// the combat log's clock, which is what the website files a note by when it has no game named.
/// </summary>
public sealed class AppNote
{
    public string Id { get; set; } = Guid.NewGuid().ToString("N");

    /// <summary>note, mark (a moment, perhaps with no text yet) or next (for the next game).</summary>
    public string Kind { get; set; } = "note";
    public string Text { get; set; } = "";
    public string At { get; set; } = "";

    /// <summary>The round it was written in (its ARENA_MATCH_START), when the app knew it.</summary>
    public string? RoundStart { get; set; }
    public int Round { get; set; }

    /// <summary>A past game it was written on (the website's lobby id), when it was.</summary>
    public string? Lobby { get; set; }

    /// <summary>The arena visit it was written in (local only): a prep-room note joins the round that follows.</summary>
    public string? Visit { get; set; }

    public bool Sent { get; set; }

    /// <summary>The website turned it down for good (a game it does not know); kept, never retried.</summary>
    public string? Refused { get; set; }
}

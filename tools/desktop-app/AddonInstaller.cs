using System.Reflection;
using System.Security.Cryptography;

namespace MindCollector.Desktop;

/// <summary>
/// Puts the MindCollector Arena Log addon (tools/wow-addon/MindCollectorArenaLog, built into this
/// app) into WoW's AddOns folder, which sits beside the Logs folder the app already knows:
/// ...\World of Warcraft\_retail_\Logs  →  ...\_retail_\Interface\AddOns\MindCollectorArenaLog.
///
/// The addon is what makes the log worth reading: it turns combat logging on in arenas and off after,
/// and turns on Advanced Combat Logging, without which a log has no specs. Shipping it inside the app
/// means a fix to it (a new `## Interface` line after a patch, whose absence makes WoW skip the addon
/// in silence) reaches every player with the next app version.
///
/// Never overwrites a copy it did not put there: a different copy with no marker file is reported and
/// left alone, unless the player asks for it to be replaced (Settings).
/// </summary>
public static class AddonInstaller
{
    public const string FolderName = "MindCollectorArenaLog";
    private const string Marker = ".installed-by-mindcollector";
    private static readonly string[] Files = ["MindCollectorArenaLog.toc", "main.lua"];

    public enum State { NoWow, Installed, Updated, UpToDate, DifferentCopy }

    public sealed record Outcome(State State, string Message, string? Folder);

    /// <summary>The AddOns folder for a Logs folder, or null when that is not a WoW install.</summary>
    public static string? AddOnsDir(string? logsDir)
    {
        if (string.IsNullOrEmpty(logsDir)) return null;
        var retail = Directory.GetParent(logsDir.TrimEnd('\\', '/'))?.FullName;
        if (retail == null || !Directory.Exists(retail)) return null;
        // _retail_ holds Wow.exe; anything else is not a WoW folder and nothing is written to it.
        if (!File.Exists(Path.Combine(retail, "Wow.exe")) && !Directory.Exists(Path.Combine(retail, "Interface"))) return null;
        return Path.Combine(retail, "Interface", "AddOns");
    }

    public static Outcome Ensure(string? logsDir, bool replaceDifferent = false)
    {
        var addons = AddOnsDir(logsDir);
        if (addons == null)
            return new(State.NoWow, "WoW's folder was not found next to the Logs folder, so the addon was not installed. Check the Logs folder in Settings.", null);

        var target = Path.Combine(addons, FolderName);
        var bundled = Files.ToDictionary(f => f, Read);
        var exists = Directory.Exists(target);
        var same = exists && Files.All(f => File.Exists(Path.Combine(target, f)) && File.ReadAllBytes(Path.Combine(target, f)).AsSpan().SequenceEqual(bundled[f]));
        var ours = exists && File.Exists(Path.Combine(target, Marker));

        if (same)
        {
            if (!ours) File.WriteAllText(Path.Combine(target, Marker), Hash(bundled));
            return new(State.UpToDate, "The MindCollector addon is installed and up to date.", target);
        }

        if (exists && !ours && !replaceDifferent)
            return new(State.DifferentCopy, "A different copy of the MindCollector addon is installed, so it was left alone. Use Settings > Install the addon to replace it.", target);

        Directory.CreateDirectory(target);
        foreach (var (name, bytes) in bundled)
        {
            var path = Path.Combine(target, name);
            File.WriteAllBytes(path + ".tmp", bytes);
            File.Move(path + ".tmp", path, overwrite: true);
        }
        File.WriteAllText(Path.Combine(target, Marker), Hash(bundled));

        var reload = Syncer.WowRunning() ? " WoW is running: type /reload in game to load it." : " It loads the next time WoW starts.";
        return exists
            ? new(State.Updated, "The MindCollector addon was updated." + reload, target)
            : new(State.Installed, "The MindCollector addon was installed." + reload, target);
    }

    private static byte[] Read(string name)
    {
        using var s = Assembly.GetExecutingAssembly().GetManifestResourceStream($"addon/{name}")
            ?? throw new InvalidOperationException($"The addon file {name} is missing from this build.");
        using var m = new MemoryStream();
        s.CopyTo(m);
        return m.ToArray();
    }

    private static string Hash(Dictionary<string, byte[]> files) =>
        Convert.ToHexString(SHA256.HashData(files.OrderBy(f => f.Key).SelectMany(f => f.Value).ToArray())).ToLowerInvariant();
}

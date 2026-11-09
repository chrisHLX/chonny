using System.Reflection;
using System.Security.Cryptography;

namespace MindCollector.Desktop;

/// <summary>
/// Puts the app's WoW addons, built into it from tools/wow-addon, into WoW's AddOns folder, which sits
/// beside the Logs folder the app already knows:
/// ...\World of Warcraft\_retail_\Logs  →  ...\_retail_\Interface\AddOns\MindCollectorArenaLog, and
/// ...\World of Warcraft\_forever_\Logs →  ...\_forever_\Interface\AddOns\MindCollectorForeverLog.
///
/// The arena addon is what makes the retail log worth reading: it turns combat logging on in arenas
/// and off after, and turns on Advanced Combat Logging, without which a log has no specs. The Forever
/// addon (2026-11-06) keeps logging on all session, so ForeverRecorder sees each PvP fight. Shipping
/// them inside the app means a fix (a new `## Interface` line after a patch, whose absence makes WoW
/// skip the addon in silence) reaches every player with the next app version.
///
/// Never overwrites a copy it did not put there: a different copy with no marker file is reported and
/// left alone, unless the player asks for it to be replaced (Settings).
/// </summary>
public static class AddonInstaller
{
    /// <summary>Files are kept identical to the build; Seeds are written only when missing (the app or the addon writes them later).</summary>
    public sealed record Addon(string FolderName, string Resource, string[] Files, string Title, string[]? Seeds = null);

    public static readonly Addon Arena = new("MindCollectorArenaLog", "addon", ["MindCollectorArenaLog.toc", "main.lua"], "MindCollector addon");
    public static readonly Addon Forever = new("MindCollectorForeverLog", "forever-addon", ["MindCollectorForeverLog.toc", "main.lua"], "MindCollector Forever addon");
    /// <summary>
    /// The Observer (2026-11-09, tools/wow-addon/MindCollectorObserver): remembers what the player looks
    /// at in Forever and carries /ask. Answers.lua is a seed: AskBridge writes it, so it is never compared.
    /// </summary>
    public static readonly Addon Observer = new("MindCollectorObserver", "observer-addon",
        ["MindCollectorObserver.toc", "Data.lua", "main.lua", "ui.lua", "ask.lua"], "MindCollector Observer addon", ["Answers.lua"]);

    private const string Marker = ".installed-by-mindcollector";

    public enum State { NoWow, Installed, Updated, UpToDate, DifferentCopy }

    public sealed record Outcome(State State, string Message, string? Folder);

    /// <summary>The AddOns folder for a Logs folder, or null when that is not a WoW install.</summary>
    public static string? AddOnsDir(string? logsDir)
    {
        if (string.IsNullOrEmpty(logsDir)) return null;
        var game = Directory.GetParent(logsDir.TrimEnd('\\', '/'))?.FullName;
        if (game == null || !Directory.Exists(game)) return null;
        // _retail_ (or _forever_) holds Wow.exe; anything else is not a WoW folder and nothing is written to it.
        if (!File.Exists(Path.Combine(game, "Wow.exe")) && !Directory.Exists(Path.Combine(game, "Interface"))) return null;
        return Path.Combine(game, "Interface", "AddOns");
    }

    public static Outcome Ensure(string? logsDir, bool replaceDifferent = false) => Ensure(Arena, logsDir, replaceDifferent);

    public static Outcome Ensure(Addon addon, string? logsDir, bool replaceDifferent = false)
    {
        var addons = AddOnsDir(logsDir);
        if (addons == null)
            return new(State.NoWow, $"WoW's folder was not found next to the Logs folder, so the {addon.Title} was not installed. Check the Logs folder in Settings.", null);

        var target = Path.Combine(addons, addon.FolderName);
        var bundled = addon.Files.ToDictionary(f => f, f => Read(addon, f));
        var exists = Directory.Exists(target);
        var same = exists && addon.Files.All(f => File.Exists(Path.Combine(target, f)) && File.ReadAllBytes(Path.Combine(target, f)).AsSpan().SequenceEqual(bundled[f]));
        var ours = exists && File.Exists(Path.Combine(target, Marker));

        if (same)
        {
            WriteSeeds(addon, target);
            if (!ours) File.WriteAllText(Path.Combine(target, Marker), Hash(bundled));
            return new(State.UpToDate, $"The {addon.Title} is installed and up to date.", target);
        }

        if (exists && !ours && !replaceDifferent)
            return new(State.DifferentCopy, $"A different copy of the {addon.Title} is installed, so it was left alone. Use Settings > Install the addon to replace it.", target);

        // WoW lists an addon's files only when it starts: a new addon, or a new file in one, needs a restart.
        var newFiles = !exists || addon.Files.Concat(addon.Seeds ?? []).Any(f => !File.Exists(Path.Combine(target, f)));
        Directory.CreateDirectory(target);
        foreach (var (name, bytes) in bundled)
        {
            var path = Path.Combine(target, name);
            File.WriteAllBytes(path + ".tmp", bytes);
            File.Move(path + ".tmp", path, overwrite: true);
        }
        WriteSeeds(addon, target);
        File.WriteAllText(Path.Combine(target, Marker), Hash(bundled));

        var reload = !Syncer.WowRunning() ? " It loads the next time WoW starts."
            : newFiles ? " WoW is running: restart it to load it (a /reload doesn't pick up an addon's new files)."
            : " WoW is running: type /reload in game to load it.";
        return exists
            ? new(State.Updated, $"The {addon.Title} was updated." + reload, target)
            : new(State.Installed, $"The {addon.Title} was installed." + reload, target);
    }

    private static void WriteSeeds(Addon addon, string target)
    {
        foreach (var seed in addon.Seeds ?? [])
        {
            var path = Path.Combine(target, seed);
            if (!File.Exists(path)) File.WriteAllBytes(path, Read(addon, seed));
        }
    }

    private static byte[] Read(Addon addon, string name)
    {
        using var s = Assembly.GetExecutingAssembly().GetManifestResourceStream($"{addon.Resource}/{name}")
            ?? throw new InvalidOperationException($"The addon file {name} is missing from this build.");
        using var m = new MemoryStream();
        s.CopyTo(m);
        return m.ToArray();
    }

    private static string Hash(Dictionary<string, byte[]> files) =>
        Convert.ToHexString(SHA256.HashData(files.OrderBy(f => f.Key).SelectMany(f => f.Value).ToArray())).ToLowerInvariant();
}

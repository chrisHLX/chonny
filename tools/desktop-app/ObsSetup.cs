using System.Diagnostics;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace MindCollector.Desktop;

/// <summary>
/// Finding OBS on this PC, reading its remote-control settings, and opening it.
///
/// The port and password are read from OBS's own settings file, never typed into this app or kept
/// in its settings: both programs run as the same Windows user, so the app can read what OBS wrote,
/// and a password changed in OBS is picked up on the next connect with nothing to update here.
///
/// OBS rewrites that file when it closes, so the app only turns the server on while OBS is closed;
/// with OBS open the player turns it on in OBS (Tools > WebSocket Server Settings).
/// </summary>
public static class ObsSetup
{
    public static readonly string ConfigPath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "obs-studio", "plugin_config", "obs-websocket", "config.json");

    public sealed record WebSocketConfig(bool Exists, bool Enabled, int Port, string? Password);

    public static WebSocketConfig ReadConfig()
    {
        try
        {
            if (!File.Exists(ConfigPath)) return new(false, false, 4455, null);
            using var doc = JsonDocument.Parse(File.ReadAllText(ConfigPath));
            var r = doc.RootElement;
            var enabled = r.TryGetProperty("server_enabled", out var e) && e.ValueKind == JsonValueKind.True;
            var port = r.TryGetProperty("server_port", out var p) && p.TryGetInt32(out var n) ? n : 4455;
            var auth = !r.TryGetProperty("auth_required", out var a) || a.ValueKind != JsonValueKind.False;
            var password = auth && r.TryGetProperty("server_password", out var pw) ? pw.GetString() : null;
            return new(true, enabled, port, password);
        }
        catch { return new(false, false, 4455, null); }
    }

    public static bool Running() => Process.GetProcessesByName("obs64").Length > 0 || Process.GetProcessesByName("obs32").Length > 0;

    /// <summary>obs64.exe: where OBS's installer says it is, then the usual folder.</summary>
    public static string? ExePath()
    {
        var dirs = new List<string?>();
        try
        {
            using var lm = Microsoft.Win32.RegistryKey.OpenBaseKey(Microsoft.Win32.RegistryHive.LocalMachine, Microsoft.Win32.RegistryView.Registry64);
            dirs.Add(lm.OpenSubKey(@"SOFTWARE\OBS Studio")?.GetValue("") as string);
        }
        catch { }
        dirs.Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "obs-studio"));
        foreach (var dir in dirs)
        {
            if (string.IsNullOrWhiteSpace(dir)) continue;
            var exe = Path.Combine(dir, "bin", "64bit", "obs64.exe");
            if (File.Exists(exe)) return exe;
        }
        return null;
    }

    /// <summary>
    /// Turns OBS's remote control on, keeping its port and password; makes a password when it has
    /// none. Only while OBS is closed: an open OBS writes its own copy back when it exits.
    /// </summary>
    public static bool EnableServer(out string message)
    {
        if (Running())
        {
            message = "OBS is open, so turn it on there: Tools > WebSocket Server Settings > Enable WebSocket server, then Apply.";
            return false;
        }
        try
        {
            JsonObject config = File.Exists(ConfigPath) ? JsonNode.Parse(File.ReadAllText(ConfigPath)) as JsonObject ?? new() : new();
            config["server_enabled"] = true;
            config["server_port"] ??= 4455;
            config["alerts_enabled"] ??= false;
            // first_load true makes OBS make its own password on start, replacing this one.
            config["first_load"] = false;
            if (config["auth_required"]?.GetValue<bool>() != false && string.IsNullOrEmpty(config["server_password"]?.GetValue<string>()))
            {
                config["auth_required"] = true;
                config["server_password"] = Convert.ToHexString(System.Security.Cryptography.RandomNumberGenerator.GetBytes(12)).ToLowerInvariant();
            }
            Directory.CreateDirectory(Path.GetDirectoryName(ConfigPath)!);
            File.WriteAllText(ConfigPath, config.ToJsonString(new JsonSerializerOptions { WriteIndented = true }));
            message = "Turned on OBS's WebSocket server (its remote control).";
            return true;
        }
        catch (Exception e)
        {
            message = $"Could not change OBS's settings: {e.Message}";
            return false;
        }
    }

    /// <summary>
    /// Opens OBS in the tray. It must start in its own folder, or it cannot find its files. The
    /// shutdown check is skipped so a crash last time cannot leave a "start in safe mode?" box
    /// waiting on a screen nobody is looking at while a game goes unrecorded.
    ///
    /// OBS 32 asks anyway (2026-11-03): it finds the crash from a run_ file a session leaves in
    /// .sentinel and deletes on a clean exit. A session that ended at 13:37 without exiting cleanly
    /// left one; the OBS the app opened at 16:59 asked, Safe Mode was chosen, Safe Mode leaves the
    /// WebSocket server out, and a whole evening went unrecorded. OBS is closed here, so every run_
    /// file is from a session already gone: they are cleared before it starts.
    /// </summary>
    public static bool Launch(out string message)
    {
        var exe = ExePath();
        if (exe == null)
        {
            message = "OBS is not installed. Settings > Set up OBS installs it for you (free), then sets it up.";
            return false;
        }
        if (!Running()) ClearCrashMarkers();
        try
        {
            Process.Start(new ProcessStartInfo(exe, "--minimize-to-tray --disable-shutdown-check")
            {
                WorkingDirectory = Path.GetDirectoryName(exe)!,
                UseShellExecute = false,
            });
            message = "Opened OBS in the tray.";
            return true;
        }
        catch (Exception e)
        {
            message = $"Could not open OBS: {e.Message}";
            return false;
        }
    }

    private static readonly string ObsDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "obs-studio");

    /// <summary>OBS's run_ files from sessions that did not exit cleanly. Only call with OBS closed.</summary>
    private static void ClearCrashMarkers()
    {
        try
        {
            var dir = Path.Combine(ObsDir, ".sentinel");
            if (!Directory.Exists(dir)) return;
            foreach (var f in Directory.GetFiles(dir, "run_*"))
            {
                try { File.Delete(f); } catch { }
            }
        }
        catch { }
    }

    /// <summary>
    /// Whether the OBS that is open started in Safe Mode, which loads no third-party plugins and so
    /// no WebSocket server: its newest log says "Safe Mode enabled".
    /// </summary>
    public static bool InSafeMode()
    {
        try
        {
            var log = new DirectoryInfo(Path.Combine(ObsDir, "logs")).GetFiles("*.txt").OrderByDescending(f => f.LastWriteTimeUtc).FirstOrDefault();
            if (log == null) return false;
            using var reader = new StreamReader(new FileStream(log.FullName, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete));
            for (var i = 0; i < 400 && reader.ReadLine() is { } line; i++)
            {
                if (line.Contains("Safe Mode enabled", StringComparison.Ordinal)) return true;
            }
            return false;
        }
        catch { return false; }
    }

    /// <summary>
    /// What would stop the next game being recorded, read without connecting to OBS (2026-11-03):
    /// whether it is installed, its server switched on, and, when it is open, whether anything is
    /// listening on its port. Null when nothing stands in the way; a closed OBS is fine, the app
    /// opens it when a game starts.
    /// </summary>
    public static string? Problem()
    {
        if (ExePath() == null) return "OBS is not installed. Settings > Set up OBS installs it for you (free), then sets it up.";
        var config = ReadConfig();
        var running = Running();
        if (!config.Enabled && running) return "OBS's WebSocket server is off. In OBS: Tools > WebSocket Server Settings > Enable WebSocket server.";
        if (!running) return null;
        try
        {
            var listening = System.Net.NetworkInformation.IPGlobalProperties.GetIPGlobalProperties()
                .GetActiveTcpListeners().Any(l => l.Port == config.Port);
            if (listening) return null;
        }
        catch { return null; }
        return InSafeMode() ? SafeModeMessage : "OBS is open but not answering on its WebSocket port. Close OBS and open it again.";
    }

    public const string SafeModeMessage = "OBS opened in Safe Mode after it last closed badly, and Safe Mode turns its remote control off. Close OBS and open it again, choosing Run Normally.";

    /// <summary>
    /// A connection to OBS, opening OBS first when it is closed: waits up to 30 seconds for it to
    /// start answering. Null, with the reason in <paramref name="message"/>, when it cannot.
    /// </summary>
    public static async Task<(ObsClient? Client, string Message)> ConnectAsync(bool openIfClosed, CancellationToken ct)
    {
        var config = ReadConfig();
        if (!config.Enabled && !Running())
        {
            // Off and closed: turning it on is safe (OBS reads the file when it starts).
            if (!EnableServer(out var why)) return (null, why);
            config = ReadConfig();
        }
        if (!config.Enabled)
            return (null, "OBS's WebSocket server is off. In OBS: Tools > WebSocket Server Settings > Enable WebSocket server.");

        if (!Running())
        {
            if (!openIfClosed) return (null, "OBS is not open.");
            if (!Launch(out var why)) return (null, why);
        }

        var until = DateTime.UtcNow.AddSeconds(30);
        string last = "OBS is not answering.";
        while (DateTime.UtcNow < until)
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                var client = await ObsClient.ConnectAsync(config.Port, config.Password, ct);
                return (client, $"Connected to OBS {client.ObsVersion}.");
            }
            catch (ObsClient.ObsException e)
            {
                last = e.Message;
                if (last.Contains("password", StringComparison.OrdinalIgnoreCase)) return (null, last);
            }
            catch (OperationCanceledException) when (!ct.IsCancellationRequested) { }
            await Task.Delay(1000, ct);
        }
        // Open and silent with the server switched on: Safe Mode, said as itself rather than as
        // "check the WebSocket setting", which is on.
        return (null, Running() && InSafeMode() ? SafeModeMessage : last);
    }

    /// <summary>
    /// A fresh OBS has an empty scene and records black (2026-10-26). When the scene OBS is showing
    /// has nothing in it, this adds a Game Capture of WoW: window mode, matched by Wow.exe when the
    /// title or class differ (priority 2), so it finds the game however its window is named. A scene
    /// that has anything in it is the player's own setup and is left alone. Null when nothing changed.
    /// </summary>
    public static async Task<string?> EnsureWowCaptureAsync(ObsClient obs, CancellationToken ct)
    {
        var current = await obs.TryRequestAsync("GetCurrentProgramScene", null, ct);
        var scene = current is { } c && c.TryGetProperty("currentProgramSceneName", out var n) ? n.GetString()
            : current is { } c2 && c2.TryGetProperty("sceneName", out var n2) ? n2.GetString() : null;
        if (string.IsNullOrEmpty(scene)) return null;

        var items = await obs.TryRequestAsync("GetSceneItemList", new JsonObject { ["sceneName"] = scene }, ct);
        if (items is not { } list || !list.TryGetProperty("sceneItems", out var arr) || arr.GetArrayLength() > 0) return null;

        var made = await obs.RequestAsync("CreateInput", new JsonObject
        {
            ["sceneName"] = scene,
            ["inputName"] = OwnCaptureName,
            ["inputKind"] = "game_capture",
            ["inputSettings"] = WowGameCaptureSettings(),
            ["sceneItemEnabled"] = true,
        }, ct);
        if (made.TryGetProperty("sceneItemId", out var id)) await FitToCanvasAsync(obs, scene, id.GetInt32(), ct);
        return "Added a Game Capture of WoW to OBS, since its scene was empty.";
    }

    /// <summary>The name of the capture EnsureWowCaptureAsync adds to an empty scene.</summary>
    private const string OwnCaptureName = "World of Warcraft";

    /// <summary>
    /// A Game Capture of WoW: window mode, matched by Wow.exe when the title or class differ (priority
    /// 2), which finds retail and WoW Forever alike (both are Wow.exe with the same window class).
    /// Limited to OBS's own frame rate, so a game running faster than the video is copied only as often
    /// as OBS uses it. No cursor, as Chriso's own Display Capture had it.
    /// </summary>
    private static JsonObject WowGameCaptureSettings() => new()
    {
        ["capture_mode"] = "window",
        ["window"] = "World of Warcraft:waApplication Window:Wow.exe",
        ["priority"] = 2,
        ["limit_framerate"] = true,
        ["capture_cursor"] = false,
    };

    // ---------------------------------------------------------------- the app's own WoW scene

    /// <summary>The scene the app records WoW with when the player's own is only a screen capture.</summary>
    public const string WowSceneName = "MindCollector: WoW";

    private const string WowSceneInput = "MindCollector: World of Warcraft";

    /// <summary>
    /// What a recording shows: a Game Capture of WoW rather than a capture of the whole screen
    /// (2026-11-08). Measured that day on Chriso's PC, WoW Forever (D3D12) at 60 fps on a 165 Hz
    /// screen, OBS recording at 60, twice each:
    ///   Display Capture of the game's screen: Windows composited every one of WoW's frames (303 DWM
    ///   presents in 5 s for WoW's 303), OBS's GPU use 2.5-4.3%.
    ///   Game Capture of WoW: WoW's frames went to the screen without DWM (0 presents, as with nothing
    ///   captured at all), OBS's GPU use 1.9%.
    /// WoW's own frame pacing was the same in all three (16.67 ms, sd 0.3), so neither capture slows
    /// the game; what Display Capture adds is Windows putting every frame through the compositor, which
    /// costs a refresh of delay, and it records whatever else is on that screen.
    ///
    /// The player's scene is only replaced when it is nothing but screen or window captures (or
    /// empty): a scene with a webcam, an overlay, its own audio or a capture of a game is theirs and is
    /// recorded as it is. Never while OBS is streaming, so a live picture never changes. Returns the
    /// scene to go back to when the recording stops and a line for the log, both null when nothing
    /// was switched. With <paramref name="remember"/>, the scene to go back to is also kept in the
    /// settings file, so if OBS is already on the app's scene when this runs (the app was closed
    /// before it could switch back) the player's scene is still the one returned.
    ///
    /// Not a fix for a choppy WoW in the background: WoW drops itself to 30 fps when another window
    /// has focus (measured the same day at the login screen, 60 focused), which is the game's own "Max
    /// Background FPS" setting, not the capture.
    /// </summary>
    public static async Task<(string? Previous, string? Note)> UseWowSceneAsync(ObsClient obs, AppSettings? remember, CancellationToken ct)
    {
        var scene = await ProgramSceneAsync(obs, ct);
        if (string.IsNullOrEmpty(scene)) return (null, null);
        if (scene == WowSceneName)
        {
            // fitted again in case OBS's picture size changed since the scene was made
            if (await obs.TryRequestAsync("GetSceneItemId", new JsonObject { ["sceneName"] = WowSceneName, ["sourceName"] = WowSceneInput }, ct) is { } own
                && own.TryGetProperty("sceneItemId", out var ownId))
                await FitToCanvasAsync(obs, WowSceneName, ownId.GetInt32(), ct);
            return (remember?.ObsSceneToRestore, await ApplySoundAsync(obs, remember, ct));
        }
        if (await obs.TryRequestAsync("GetStreamStatus", null, ct) is { } stream
            && stream.TryGetProperty("outputActive", out var live) && live.GetBoolean()) return (null, null);

        if (await obs.TryRequestAsync("GetSceneItemList", new JsonObject { ["sceneName"] = scene }, ct) is not { } items
            || !items.TryGetProperty("sceneItems", out var list)) return (null, null);
        await FitOwnCaptureAsync(obs, scene, list, ct);
        foreach (var item in list.EnumerateArray())
        {
            if (item.TryGetProperty("sceneItemEnabled", out var on) && !on.GetBoolean()) continue;
            var kind = item.TryGetProperty("inputKind", out var k) && k.ValueKind == JsonValueKind.String ? k.GetString() : null;
            if (kind is not ("monitor_capture" or "window_capture")) return (null, null);
        }

        var created = await EnsureWowSceneAsync(obs, ct);
        if (await obs.TryRequestAsync("SetCurrentProgramScene", new JsonObject { ["sceneName"] = WowSceneName }, ct) == null)
            return (null, null);
        if (remember != null) { remember.ObsSceneToRestore = scene; remember.Save(); }
        var sound = await ApplySoundAsync(obs, remember, ct);
        return (scene, (created
            ? $"Recording WoW with a Game Capture of the game (a new OBS scene, \"{WowSceneName}\"), not your screen capture; OBS goes back to \"{scene}\" when the app stops recording."
            : $"Recording WoW with the app's Game Capture scene; OBS goes back to \"{scene}\" when the app stops recording.") + (sound != null ? " " + sound : ""));
    }

    /// <summary>
    /// Puts the player's scene back, if OBS is still showing the app's, and forgets it in
    /// <paramref name="remember"/> once nothing more is owed (put back, or the player moved OBS on).
    /// </summary>
    public static async Task RestoreSceneAsync(ObsClient obs, string? previous, AppSettings? remember, CancellationToken ct)
    {
        await RestoreSoundAsync(obs, remember, ct);
        if (string.IsNullOrEmpty(previous) || !obs.Connected) return;
        var now = await ProgramSceneAsync(obs, ct);
        if (now == null) return;
        if (now == WowSceneName
            && await obs.TryRequestAsync("SetCurrentProgramScene", new JsonObject { ["sceneName"] = previous }, ct) == null) return;
        if (remember != null && remember.ObsSceneToRestore != null) { remember.ObsSceneToRestore = null; remember.Save(); }
    }

    /// <summary>
    /// WoW's own sound only (Settings, <see cref="AppSettings.RecordSound"/> "wow", 2026-11-12): the app's
    /// Game Capture captures the game's audio (OBS 30+ "Capture Audio", setting capture_audio, found in
    /// OBS 33's win-capture.dll), and OBS's Desktop Audio is muted while the app's scene records, so voice
    /// chat and music stay out and the game isn't heard twice (OBS's own tooltip warns of that). What was
    /// muted is kept in settings and unmuted afterwards. "everything": no game audio capture, Desktop
    /// Audio as the player has it. The microphone is muted with it (0.6.16: Chriso's first battleground
    /// clips had his own voice and an echo; WoW's sound only means only WoW's).
    /// </summary>
    private static async Task<string?> ApplySoundAsync(ObsClient obs, AppSettings? remember, CancellationToken ct)
    {
        var wowOnly = remember?.RecordSound != "everything";
        await obs.TryRequestAsync("SetInputSettings", new JsonObject
        {
            ["inputName"] = WowSceneInput,
            ["inputSettings"] = new JsonObject { ["capture_audio"] = wowOnly },
            ["overlay"] = true,
        }, ct);
        if (!wowOnly || remember == null) return null;
        var special = await obs.TryRequestAsync("GetSpecialInputs", null, ct);
        var muted = new List<string>();
        foreach (var key in new[] { "desktop1", "desktop2", "mic1", "mic2", "mic3", "mic4" })
        {
            if (special is not { } sp || !sp.TryGetProperty(key, out var n) || n.ValueKind != JsonValueKind.String || n.GetString() is not { Length: > 0 } name) continue;
            var state = await obs.TryRequestAsync("GetInputMute", new JsonObject { ["inputName"] = name }, ct);
            if (state is { } st && st.TryGetProperty("inputMuted", out var already) && already.GetBoolean()) continue;
            if (await obs.TryRequestAsync("SetInputMute", new JsonObject { ["inputName"] = name, ["inputMuted"] = true }, ct) != null) muted.Add(name);
        }
        if (muted.Count == 0) return null;
        remember.ObsMutedForWow = [.. (remember.ObsMutedForWow ?? []).Union(muted)];
        remember.Save();
        return "Recording WoW's own sound only: OBS's Desktop Audio and microphone are muted while the app records.";
    }

    /// <summary>Unmutes what <see cref="ApplySoundAsync"/> muted.</summary>
    private static async Task RestoreSoundAsync(ObsClient obs, AppSettings? remember, CancellationToken ct)
    {
        if (remember?.ObsMutedForWow is not { Count: > 0 } muted || !obs.Connected) return;
        foreach (var name in muted)
            await obs.TryRequestAsync("SetInputMute", new JsonObject { ["inputName"] = name, ["inputMuted"] = false }, ct);
        remember.ObsMutedForWow = null;
        remember.Save();
    }

    /// <summary>The app's scene with its Game Capture, fitted to the canvas; true when it was made now.</summary>
    private static async Task<bool> EnsureWowSceneAsync(ObsClient obs, CancellationToken ct)
    {
        var scenes = await obs.TryRequestAsync("GetSceneList", null, ct);
        var exists = scenes is { } s && s.TryGetProperty("scenes", out var arr)
            && arr.EnumerateArray().Any(x => x.TryGetProperty("sceneName", out var n) && n.GetString() == WowSceneName);
        if (exists) return false;

        await obs.RequestAsync("CreateScene", new JsonObject { ["sceneName"] = WowSceneName }, ct);
        JsonElement made;
        if (await obs.TryRequestAsync("GetInputSettings", new JsonObject { ["inputName"] = WowSceneInput }, ct) != null)
            made = await obs.RequestAsync("CreateSceneItem", new JsonObject { ["sceneName"] = WowSceneName, ["sourceName"] = WowSceneInput }, ct);
        else
            made = await obs.RequestAsync("CreateInput", new JsonObject
            {
                ["sceneName"] = WowSceneName,
                ["inputName"] = WowSceneInput,
                ["inputKind"] = "game_capture",
                ["inputSettings"] = WowGameCaptureSettings(),
                ["sceneItemEnabled"] = true,
            }, ct);

        if (made.TryGetProperty("sceneItemId", out var id)) await FitToCanvasAsync(obs, WowSceneName, id.GetInt32(), ct);
        return true;
    }

    /// <summary>
    /// Fills OBS's picture with one capture, whatever size WoW runs at: a windowed game is smaller than
    /// the screen, and a 1440p or ultrawide game is bigger than a 1080p picture. Unfitted, a bigger game
    /// is recorded at full size from its corner: a zoomed-in look with the action bars and minimap cut
    /// off (Cbags, 13 Nov). Idempotent, so it is safe before every recording.
    /// </summary>
    private static async Task FitToCanvasAsync(ObsClient obs, string scene, int itemId, CancellationToken ct)
    {
        if (await obs.TryRequestAsync("GetVideoSettings", null, ct) is not { } video
            || !video.TryGetProperty("baseWidth", out var w) || !video.TryGetProperty("baseHeight", out var h)) return;
        await obs.TryRequestAsync("SetSceneItemTransform", new JsonObject
        {
            ["sceneName"] = scene,
            ["sceneItemId"] = itemId,
            ["sceneItemTransform"] = new JsonObject
            {
                ["positionX"] = 0,
                ["positionY"] = 0,
                ["alignment"] = 5,
                ["boundsType"] = "OBS_BOUNDS_SCALE_INNER",
                ["boundsAlignment"] = 0,
                ["boundsWidth"] = w.GetInt32(),
                ["boundsHeight"] = h.GetInt32(),
            },
        }, ct);
    }

    /// <summary>
    /// The Game Capture the app put in an empty scene (EnsureWowCaptureAsync), fitted to the picture.
    /// Only when the scene holds that capture alone: a scene with anything else is the player's layout,
    /// left as it is. Before 0.6.23 that capture was added unfitted, so installs set up then are fixed
    /// here, at their next recording.
    /// </summary>
    private static async Task FitOwnCaptureAsync(ObsClient obs, string scene, JsonElement items, CancellationToken ct)
    {
        var all = items.EnumerateArray().ToList();
        if (all.Count != 1) return;
        var item = all[0];
        if (item.TryGetProperty("sourceName", out var name) && name.GetString() == OwnCaptureName
            && item.TryGetProperty("inputKind", out var kind) && kind.GetString() == "game_capture"
            && item.TryGetProperty("sceneItemId", out var id))
            await FitToCanvasAsync(obs, scene, id.GetInt32(), ct);
    }

    private static async Task<string?> ProgramSceneAsync(ObsClient obs, CancellationToken ct)
    {
        var current = await obs.TryRequestAsync("GetCurrentProgramScene", null, ct);
        return current is { } c && c.TryGetProperty("currentProgramSceneName", out var n) ? n.GetString()
            : current is { } c2 && c2.TryGetProperty("sceneName", out var n2) ? n2.GetString() : null;
    }

    /// <summary>The container OBS records into (mp4, hybrid_mp4, mkv, ...), or null when it would not say.</summary>
    public static async Task<string?> RecordFormatAsync(ObsClient obs, CancellationToken ct)
    {
        var mode = await ProfileParameterAsync(obs, "Output", "Mode", ct);
        var section = string.Equals(mode, "Advanced", StringComparison.OrdinalIgnoreCase) ? "AdvOut" : "SimpleOutput";
        return await ProfileParameterAsync(obs, section, "RecFormat2", ct)
            ?? await ProfileParameterAsync(obs, section, "RecFormat", ct);
    }

    // ---------------------------------------------------------------- the replay buffer (WoW Forever)

    /// <summary>The most memory the replay buffer may hold, in MB: 600s at the 32 Mbps measured on Chriso's PC is ~2.4 GB.</summary>
    public const int ReplayBufferMb = 4096;

    /// <summary>
    /// The current profile's basic.ini (%APPDATA%\obs-studio\basic\profiles\NAME\basic.ini). OBS 31+
    /// names the profile in user.ini, older ones in global.ini.
    /// </summary>
    public static string? ProfileIni()
    {
        foreach (var name in new[] { "user.ini", "global.ini" })
        {
            try
            {
                var path = Path.Combine(ObsDir, name);
                if (!File.Exists(path)) continue;
                var dir = File.ReadAllLines(path).Select(l => l.Trim()).FirstOrDefault(l => l.StartsWith("ProfileDir=", StringComparison.Ordinal))?["ProfileDir=".Length..];
                if (string.IsNullOrEmpty(dir)) continue;
                var ini = Path.Combine(ObsDir, "basic", "profiles", dir, "basic.ini");
                if (File.Exists(ini)) return ini;
            }
            catch { }
        }
        return null;
    }

    /// <summary>
    /// Turns OBS's replay buffer on in the profile's file, for both output modes, holding at least
    /// <paramref name="seconds"/> and <see cref="ReplayBufferMb"/>. Only while OBS is closed: an open
    /// OBS writes its own copy back, and builds its replay buffer only as it starts. The file's BOM
    /// and line endings are kept as OBS wrote them.
    /// </summary>
    public static bool EnableReplayBufferInProfile(int seconds, out string message) => SetReplayBufferInProfile(true, seconds, out message);

    /// <summary>The replay buffer on or off in the profile's file (off only for --obs-buffer-off, to reproduce Cbags' OBS).</summary>
    internal static bool SetReplayBufferInProfile(bool on, int seconds, out string message)
    {
        var ok = EditProfile(lines =>
        {
            var changed = false;
            foreach (var section in new[] { "SimpleOutput", "AdvOut" })
            {
                changed |= SetIni(lines, section, "RecRB", on ? "true" : "false", v => v == (on ? "true" : "false"));
                if (!on) continue;
                changed |= SetIni(lines, section, "RecRBTime", seconds.ToString(), v => int.TryParse(v, out var n) && n >= seconds);
                changed |= SetIni(lines, section, "RecRBSize", ReplayBufferMb.ToString(), v => int.TryParse(v, out var n) && n >= ReplayBufferMb);
            }
            return changed;
        }, out var changed, out message);
        if (ok) message = !changed ? $"OBS's replay buffer was already {(on ? "on" : "off")}." : on ? $"Turned on OBS's replay buffer ({seconds}s)." : "Turned off OBS's replay buffer.";
        return ok;
    }

    /// <summary>
    /// Edits the current profile's basic.ini while OBS is closed (an open OBS writes its own copy back,
    /// and reads most output settings only as it starts). The file's BOM and line endings are kept as
    /// OBS wrote them. <paramref name="changed"/>: whether the edit changed anything.
    /// </summary>
    internal static bool EditProfile(Func<List<string>, bool> edit, out bool changed, out string message)
    {
        changed = false;
        if (Running()) { message = "OBS is open."; return false; }
        var ini = ProfileIni();
        if (ini == null) { message = "OBS's profile was not found. Open OBS once, then try again."; return false; }
        try
        {
            var bytes = File.ReadAllBytes(ini);
            var bom = bytes.Length >= 3 && bytes[0] == 0xEF && bytes[1] == 0xBB && bytes[2] == 0xBF;
            var text = System.Text.Encoding.UTF8.GetString(bytes, bom ? 3 : 0, bytes.Length - (bom ? 3 : 0));
            var newline = text.Contains("\r\n") ? "\r\n" : "\n";
            var lines = text.Split(newline).ToList();
            changed = edit(lines);
            message = "";
            if (!changed) return true;
            var output = System.Text.Encoding.UTF8.GetBytes(string.Join(newline, lines));
            File.WriteAllBytes(ini + ".tmp", bom ? [0xEF, 0xBB, 0xBF, .. output] : output);
            File.Move(ini + ".tmp", ini, overwrite: true);
            return true;
        }
        catch (Exception e)
        {
            message = $"Could not change OBS's settings: {e.Message}";
            return false;
        }
    }

    /// <summary>
    /// OBS's Simple-mode name for the graphics card's own encoder on this PC: NVIDIA "nvenc", AMD "amd",
    /// Intel "qsv" (as OBS 33 writes them in basic.ini; Chriso's: RecEncoder=nvenc), from the display
    /// adapters Windows has drivers for, discrete cards first. Null when none is known.
    /// </summary>
    public static string? HardwareEncoder()
    {
        var names = new List<string>();
        try
        {
            using var adapters = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\Class\{4d36e968-e325-11ce-bfc1-08002be10318}");
            foreach (var sub in adapters?.GetSubKeyNames() ?? [])
            {
                try { if (adapters!.OpenSubKey(sub)?.GetValue("DriverDesc") is string d) names.Add(d); } catch { }
            }
        }
        catch { }
        if (names.Any(n => n.Contains("NVIDIA", StringComparison.OrdinalIgnoreCase))) return "nvenc";
        if (names.Any(n => n.Contains("Radeon", StringComparison.OrdinalIgnoreCase) || n.Contains("AMD", StringComparison.OrdinalIgnoreCase))) return "amd";
        if (names.Any(n => n.Contains("Intel", StringComparison.OrdinalIgnoreCase))) return "qsv";
        return null;
    }

    /// <summary>Sets key=value in [section] unless <paramref name="good"/> already holds for its value; true when it changed.</summary>
    internal static bool SetIni(List<string> lines, string section, string key, string value, Func<string, bool> good)
    {
        var start = lines.FindIndex(l => l.Trim() == $"[{section}]");
        if (start < 0)
        {
            if (lines.Count > 0 && lines[^1].Length == 0) lines.Insert(lines.Count - 1, $"[{section}]");
            else lines.Add($"[{section}]");
            start = lines.FindIndex(l => l.Trim() == $"[{section}]");
        }
        var end = lines.FindIndex(start + 1, l => l.TrimStart().StartsWith('['));
        if (end < 0) end = lines.Count;
        for (var i = start + 1; i < end; i++)
        {
            if (!lines[i].StartsWith(key + "=", StringComparison.Ordinal)) continue;
            if (good(lines[i][(key.Length + 1)..].Trim())) return false;
            lines[i] = $"{key}={value}";
            return true;
        }
        // A section's last line may be the blank before the next section: insert above it.
        var at = end;
        while (at > start + 1 && lines[at - 1].Trim().Length == 0) at--;
        lines.Insert(at, $"{key}={value}");
        return true;
    }

    /// <summary>
    /// The replay buffer, with OBS open (2026-11-06). Its length and size are read each time it starts,
    /// so they are set here first. Whether it exists at all is fixed when OBS starts: when it is off,
    /// it is turned on in the profile and the problem says to restart OBS.
    /// Ours: the app started it (and so stops it). Problem: null when the buffer is running.
    /// NeedsRestart: OBS has no replay buffer until it starts again (ObsCheck.RestartAsync does that).
    /// </summary>
    public static async Task<(bool Ours, string? Problem, bool NeedsRestart)> StartReplayBufferAsync(ObsClient obs, int seconds, CancellationToken ct)
    {
        var mode = await ProfileParameterAsync(obs, "Output", "Mode", ct);
        var section = string.Equals(mode, "Advanced", StringComparison.OrdinalIgnoreCase) ? "AdvOut" : "SimpleOutput";
        if (!int.TryParse(await ProfileParameterAsync(obs, section, "RecRBTime", ct), out var time) || time < seconds)
            await obs.TryRequestAsync("SetProfileParameter", new JsonObject { ["parameterCategory"] = section, ["parameterName"] = "RecRBTime", ["parameterValue"] = seconds.ToString() }, ct);
        if (!int.TryParse(await ProfileParameterAsync(obs, section, "RecRBSize", ct), out var size) || size < ReplayBufferMb)
            await obs.TryRequestAsync("SetProfileParameter", new JsonObject { ["parameterCategory"] = section, ["parameterName"] = "RecRBSize", ["parameterValue"] = ReplayBufferMb.ToString() }, ct);

        var status = await obs.TryRequestAsync("GetReplayBufferStatus", null, ct);
        if (status is not { } st)
        {
            // OBS built no replay buffer when it started. Turn it on for next time.
            await obs.TryRequestAsync("SetProfileParameter", new JsonObject { ["parameterCategory"] = section, ["parameterName"] = "RecRB", ["parameterValue"] = "true" }, ct);
            return (false, "OBS's replay buffer was off. It is on now, but OBS only picks that up when it starts: close OBS and open it again (or let the app open it).", true);
        }
        if (st.TryGetProperty("outputActive", out var active) && active.GetBoolean()) return (false, null, false);
        await obs.RequestAsync("StartReplayBuffer", null, ct);
        return (true, null, false);
    }

    /// <summary>Containers the app's player (WebView2, Edge's engine) opens.</summary>
    public static bool Playable(string? format) =>
        format is null || format.Contains("mp4", StringComparison.OrdinalIgnoreCase) || format.Equals("mov", StringComparison.OrdinalIgnoreCase);

    internal static async Task<string?> ProfileParameterAsync(ObsClient obs, string category, string name, CancellationToken ct)
    {
        var r = await obs.TryRequestAsync("GetProfileParameter", new JsonObject { ["parameterCategory"] = category, ["parameterName"] = name }, ct);
        if (r is not { } v) return null;
        var value = v.TryGetProperty("parameterValue", out var p) && p.ValueKind == JsonValueKind.String ? p.GetString() : null;
        if (string.IsNullOrEmpty(value) && v.TryGetProperty("defaultParameterValue", out var dp) && dp.ValueKind == JsonValueKind.String) value = dp.GetString();
        return string.IsNullOrEmpty(value) ? null : value;
    }
}

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
    /// </summary>
    public static bool Launch(out string message)
    {
        var exe = ExePath();
        if (exe == null)
        {
            message = "OBS is not installed. Get it from obsproject.com, then open Settings > Set up OBS.";
            return false;
        }
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
        return (null, last);
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

        await obs.RequestAsync("CreateInput", new JsonObject
        {
            ["sceneName"] = scene,
            ["inputName"] = "World of Warcraft",
            ["inputKind"] = "game_capture",
            ["inputSettings"] = new JsonObject
            {
                ["capture_mode"] = "window",
                ["window"] = "World of Warcraft:waApplication Window:Wow.exe",
                ["priority"] = 2,
            },
            ["sceneItemEnabled"] = true,
        }, ct);
        return "Added a Game Capture of WoW to OBS, since its scene was empty.";
    }

    /// <summary>The container OBS records into (mp4, hybrid_mp4, mkv, ...), or null when it would not say.</summary>
    public static async Task<string?> RecordFormatAsync(ObsClient obs, CancellationToken ct)
    {
        var mode = await ProfileParameterAsync(obs, "Output", "Mode", ct);
        var section = string.Equals(mode, "Advanced", StringComparison.OrdinalIgnoreCase) ? "AdvOut" : "SimpleOutput";
        return await ProfileParameterAsync(obs, section, "RecFormat2", ct)
            ?? await ProfileParameterAsync(obs, section, "RecFormat", ct);
    }

    /// <summary>Containers the app's player (WebView2, Edge's engine) opens.</summary>
    public static bool Playable(string? format) =>
        format is null || format.Contains("mp4", StringComparison.OrdinalIgnoreCase) || format.Equals("mov", StringComparison.OrdinalIgnoreCase);

    private static async Task<string?> ProfileParameterAsync(ObsClient obs, string category, string name, CancellationToken ct)
    {
        var r = await obs.TryRequestAsync("GetProfileParameter", new JsonObject { ["parameterCategory"] = category, ["parameterName"] = name }, ct);
        if (r is not { } v) return null;
        var value = v.TryGetProperty("parameterValue", out var p) && p.ValueKind == JsonValueKind.String ? p.GetString() : null;
        if (string.IsNullOrEmpty(value) && v.TryGetProperty("defaultParameterValue", out var dp) && dp.ValueKind == JsonValueKind.String) value = dp.GetString();
        return string.IsNullOrEmpty(value) ? null : value;
    }
}

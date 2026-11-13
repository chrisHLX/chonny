using System.Diagnostics;
using System.Net.Http.Headers;
using System.Text.Json;

namespace MindCollector.Desktop;

/// <summary>
/// Keeps the app itself current (2026-10-11), so a player never downloads it twice. The pages it
/// shows are the website's and change on every deploy; this is for the window around them.
///
/// The website says which version it serves (GET /api/coach/app-version, from version.json beside the
/// exe). When that is newer than this copy and WoW is not running, the new exe is downloaded to the
/// temp folder and started with --update: it is then a copy outside the install folder, so
/// Installer replaces the installed one (closing this one) and starts it again in the tray. A file
/// fetched by the app itself carries no download mark, so Windows does not warn a second time.
/// </summary>
internal static class Updater
{
    public static Version Current => typeof(Updater).Assembly.GetName().Version ?? new Version(0, 0);

    /// <summary>The newer version the website serves, or null when this one is current (or it cannot tell).</summary>
    public static async Task<Version?> NewerAsync(string serverUrl, CancellationToken ct)
    {
        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
        http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        using var res = await http.GetAsync($"{serverUrl.TrimEnd('/')}/api/coach/app-version", ct);
        if (!res.IsSuccessStatusCode) return null;
        using var doc = JsonDocument.Parse(await res.Content.ReadAsStringAsync(ct));
        if (!doc.RootElement.TryGetProperty("version", out var v) || v.ValueKind != JsonValueKind.String) return null;
        if (!Version.TryParse(v.GetString(), out var served)) return null;

        var current = new Version(Current.Major, Current.Minor, Math.Max(0, Current.Build));
        return served > current ? served : null;
    }

    private static async Task<string?> ExpectedHashAsync(string serverUrl, CancellationToken ct)
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
            using var res = await http.GetAsync($"{serverUrl.TrimEnd('/')}/api/coach/app-version", ct);
            if (!res.IsSuccessStatusCode) return null;
            using var doc = JsonDocument.Parse(await res.Content.ReadAsStringAsync(ct));
            return doc.RootElement.TryGetProperty("sha256", out var h) && h.ValueKind == JsonValueKind.String ? h.GetString() : null;
        }
        catch (Exception e) when (e is HttpRequestException or JsonException or TaskCanceledException) { return null; }
    }

    /// <summary>Downloads the new version and starts it; true when the caller should now exit.</summary>
    public static async Task<bool> InstallAsync(string serverUrl, Version version, Action<string> log, CancellationToken ct) =>
        await DownloadAsync(serverUrl, version, log, ct) is { } path && Launch(path, version, log);

    /// <summary>
    /// The new version in the temp folder, checked (megabytes, starts "MZ", the website's hash): its
    /// path, or null when it did not come down properly. A good copy already there is used as it is,
    /// so the download made while WoW was open (0.6.24) is not made twice.
    /// </summary>
    public static async Task<string?> DownloadAsync(string serverUrl, Version version, Action<string> log, CancellationToken ct)
    {
        var target = Path.Combine(Path.GetTempPath(), $"MindCollector-{version}.exe");
        // The website's hash for this version, when it gives one: a download cut short or altered on the
        // way is not started. It proves nothing if the website itself were taken over; only signing would.
        var expected = await ExpectedHashAsync(serverUrl, ct);
        if (expected != null && File.Exists(target) && (await HashAsync(target, ct)).Equals(expected, StringComparison.OrdinalIgnoreCase))
            return target;

        using var http = new HttpClient { Timeout = TimeSpan.FromMinutes(10) };
        using (var res = await http.GetAsync($"{serverUrl.TrimEnd('/')}/wow/coach/app", HttpCompletionOption.ResponseHeadersRead, ct))
        {
            res.EnsureSuccessStatusCode();
            await using var file = File.Create(target);
            await res.Content.CopyToAsync(file, ct);
        }

        // A real Windows program starts "MZ" and is megabytes, not an error page.
        var head = new byte[2];
        await using (var f = File.OpenRead(target)) { _ = await f.ReadAsync(head, ct); }
        if (new FileInfo(target).Length < 1_000_000 || head[0] != 'M' || head[1] != 'Z')
        {
            log($"The update to {version} did not download properly; will try again later.");
            File.Delete(target);
            return null;
        }
        if (expected != null && !(await HashAsync(target, ct)).Equals(expected, StringComparison.OrdinalIgnoreCase))
        {
            log($"The update to {version} did not match the website's hash; will try again later.");
            File.Delete(target);
            return null;
        }
        return target;
    }

    /// <summary>Starts a downloaded version, which replaces this one (Installer); true, so the caller exits.</summary>
    public static bool Launch(string path, Version version, Action<string> log)
    {
        log($"Updating MindCollector from {Current.ToString(3)} to {version}.");
        Process.Start(new ProcessStartInfo(path, "--update") { UseShellExecute = true });
        return true;
    }

    private static async Task<string> HashAsync(string path, CancellationToken ct)
    {
        await using var f = File.OpenRead(path);
        return Convert.ToHexString(await System.Security.Cryptography.SHA256.HashDataAsync(f, ct));
    }
}

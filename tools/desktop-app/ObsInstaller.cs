using System.Diagnostics;

namespace MindCollector.Desktop;

/// <summary>
/// Installs OBS Studio for a player who doesn't have it (2026-11-12, Chriso: "it installs obs or gives
/// an option to"), only when they click Set up OBS and say yes. It uses Windows' own package manager,
/// winget, with OBS's official package (OBSProject.OBSStudio, publisher OBS Project; checked with
/// `winget show` on 2026-11-12, 33.0.0). Windows asks for permission as the installer starts. Where
/// winget isn't there (some older Windows 10), OBS's download page opens instead.
/// </summary>
public static class ObsInstaller
{
    public const string DownloadPage = "https://obsproject.com/download";
    private static readonly TimeSpan Limit = TimeSpan.FromMinutes(10);

    public static string? WingetPath()
    {
        var local = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Microsoft", "WindowsApps", "winget.exe");
        if (File.Exists(local)) return local;
        foreach (var dir in (Environment.GetEnvironmentVariable("PATH") ?? "").Split(Path.PathSeparator))
        {
            try { var p = Path.Combine(dir.Trim(), "winget.exe"); if (dir.Length > 0 && File.Exists(p)) return p; } catch { }
        }
        return null;
    }

    /// <summary>Installs OBS and says how it went; true once OBS is on this PC.</summary>
    public static async Task<(bool Ok, string Message)> InstallAsync(CancellationToken ct)
    {
        if (ObsSetup.ExePath() != null) return (true, "OBS is already installed.");
        var winget = WingetPath();
        if (winget == null) return OpenDownloadPage("Windows' installer (winget) isn't on this PC");

        var psi = new ProcessStartInfo(winget,
            "install --id OBSProject.OBSStudio --exact --silent --accept-source-agreements --accept-package-agreements")
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        try
        {
            using var p = Process.Start(psi);
            if (p == null) return OpenDownloadPage("the installer didn't start");
            var output = p.StandardOutput.ReadToEndAsync(ct);
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(Limit);
            try { await p.WaitForExitAsync(timeout.Token); }
            catch (OperationCanceledException) { try { p.Kill(true); } catch { } return OpenDownloadPage("the install took more than 10 minutes"); }
            if (ObsSetup.ExePath() != null) return (true, "OBS is installed.");
            var said = (await output).Split('\n').Select(l => l.Trim()).LastOrDefault(l => l.Length > 0);
            return OpenDownloadPage($"the install didn't finish (winget said: {said ?? "exit " + p.ExitCode})");
        }
        catch (Exception e) when (e is not OperationCanceledException)
        {
            return OpenDownloadPage($"the installer couldn't run ({e.Message})");
        }
    }

    private static (bool, string) OpenDownloadPage(string why)
    {
        try { Process.Start(new ProcessStartInfo(DownloadPage) { UseShellExecute = true }); } catch { }
        return (false, $"Couldn't install OBS here: {why}. Its download page is open: install it, then click Set up OBS again.");
    }
}

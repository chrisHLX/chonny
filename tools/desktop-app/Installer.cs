using System.Diagnostics;
using Microsoft.Win32;

namespace MindCollector.Desktop;

/// <summary>
/// Installs the app the first time it is opened from wherever the browser saved it (2026-10-11), so a
/// new player only ever double-clicks one file: it copies itself to %LOCALAPPDATA%\Programs\MindCollector,
/// adds Start menu and desktop shortcuts, starts with Windows (in the tray, as Spotify does; Settings
/// turns it off), and opens the installed copy. Opening a newer download later replaces the installed
/// copy the same way, closing the running one first, which is how an update is installed.
/// </summary>
internal static class Installer
{
    public const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
    public const string RunName = "MindCollector";

    public static string Dir => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Programs", "MindCollector");
    public static string InstalledExe => Path.Combine(Dir, "MindCollector.exe");

    /// <summary>True when this process installed the app and started the installed copy, and should exit.</summary>
    public static bool InstallIfNeeded(bool fromUpdate = false)
    {
        var self = Environment.ProcessPath;
        if (self == null || string.Equals(Path.GetFullPath(self), Path.GetFullPath(InstalledExe), StringComparison.OrdinalIgnoreCase)) return false;

        try
        {
            var firstInstall = !File.Exists(InstalledExe);
            CloseRunningCopies();
            Directory.CreateDirectory(Dir);
            File.Copy(self, InstalledExe, overwrite: true);
            Shortcut(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Programs), "MindCollector.lnk"));
            Shortcut(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), "MindCollector.lnk"));
            if (firstInstall) StartWithWindows(true);
            else if (Registry.CurrentUser.OpenSubKey(RunKey)?.GetValue(RunName) != null) StartWithWindows(true);   // point it at the new copy

            // The first time, the installed copy goes straight to connecting: the browser opens on the
            // "Connect the app on this PC?" page, so a new player clicks nothing in the app at all.
            Process.Start(new ProcessStartInfo(InstalledExe) { UseShellExecute = true, WorkingDirectory = Dir, Arguments = firstInstall ? "--first-run" : fromUpdate ? "--minimized" : "" });
            return true;
        }
        catch (Exception e)
        {
            // Never stop the player playing: run from where it is, and say why in the activity log.
            try { File.AppendAllText(Path.Combine(AppSettings.Dir, "activity.log"), $"[{DateTime.Now:dd MMM HH:mm:ss}] Could not install to {Dir}: {e.Message}{Environment.NewLine}"); } catch { }
            return false;
        }
    }

    public static void StartWithWindows(bool on)
    {
        using var run = Registry.CurrentUser.OpenSubKey(RunKey, writable: true);
        if (on) run?.SetValue(RunName, $"\"{InstalledExeOrSelf()}\" --minimized");
        else run?.DeleteValue(RunName, throwOnMissingValue: false);
    }

    private static string InstalledExeOrSelf() => File.Exists(InstalledExe) ? InstalledExe : Application.ExecutablePath;

    /// <summary>An older copy still running holds the file: close it, so the new one can take its place.</summary>
    private static void CloseRunningCopies()
    {
        foreach (var p in Process.GetProcessesByName("MindCollector"))
        {
            try
            {
                if (p.Id == Environment.ProcessId) continue;
                if (!string.Equals(p.MainModule?.FileName, InstalledExe, StringComparison.OrdinalIgnoreCase)) continue;
                p.Kill();
                p.WaitForExit(5000);
            }
            catch { }
            finally { p.Dispose(); }
        }
    }

    private static void Shortcut(string path)
    {
        var type = Type.GetTypeFromProgID("WScript.Shell");
        if (type == null) return;
        dynamic shell = Activator.CreateInstance(type)!;
        var link = shell.CreateShortcut(path);
        link.TargetPath = InstalledExe;
        link.WorkingDirectory = Dir;
        link.IconLocation = InstalledExe + ",0";
        link.Description = "MindCollector: your arena games, reviewed";
        link.Save();
    }
}

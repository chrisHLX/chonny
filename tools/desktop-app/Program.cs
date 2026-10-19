namespace MindCollector.Desktop;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        // Diagnostics, for a tester to run and send back, and for checking a build without the window.
        if (args.Length >= 2 && args[0] == "--check-log") return Diagnostics.CheckLog(args[1]);
        if (args.Length >= 1 && args[0] == "--sync-once") return Diagnostics.SyncOnce(args);
        if (args.Length >= 3 && args[0] == "--install-addon") return Diagnostics.InstallAddon(args[2], replace: args.Contains("--replace"));

        // Opened from Downloads: install, open the installed copy, and leave (Installer). A development
        // build (under bin) and a test run (MINDCOLLECTOR_DATA) never install themselves over the real one.
        var testData = Environment.GetEnvironmentVariable("MINDCOLLECTOR_DATA");
        var fromBuild = Environment.ProcessPath?.Contains(Path.DirectorySeparatorChar + "bin" + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase) == true;
#if STORE
        // The Store installs and updates it (StoreStartup): nothing to copy anywhere.
        var startHidden = StoreStartup.LaunchedAtSignIn();
        var firstRun = !fromBuild && testData == null && !AppSettings.Exists();
#else
        if (!fromBuild && testData == null && Installer.InstallIfNeeded(fromUpdate: args.Contains("--update"))) return 0;
        var startHidden = args.Contains("--minimized");
        var firstRun = args.Contains("--first-run");
#endif

        // One app per Windows user; a test run with its own data folder is its own app.
        var name = @"Local\MindCollectorDesktop" + (testData == null ? "" : "-" + Convert.ToHexString(System.Security.Cryptography.SHA1.HashData(System.Text.Encoding.UTF8.GetBytes(testData)))[..12]);
        using var mutex = new Mutex(true, name, out var first);
        using var show = new EventWaitHandle(false, EventResetMode.AutoReset, name + "-show");
        if (!first)
        {
            // Already running (usually in the tray): bring its window up, as Spotify does, not a message.
            show.Set();
            return 0;
        }

        ApplicationConfiguration.Initialize();
        var form = new MainForm(startHidden: startHidden, firstRun: firstRun);
        var watcher = new Thread(() =>
        {
            while (show.WaitOne())
            {
                try { form.BeginInvoke(form.ShowWindow); } catch { }
            }
        }) { IsBackground = true, Name = "show-window" };
        watcher.Start();
        Application.Run(form);
        return 0;
    }
}

namespace MindCollector.Desktop;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        // Diagnostics, for a tester to run and send back, and for checking a build without the window.
        if (args.Length >= 2 && args[0] == "--check-log") return Diagnostics.CheckLog(args[1]);
        if (args.Length >= 1 && args[0] == "--sync-once") return Diagnostics.SyncOnce(args);
        if (args.Length >= 3 && args[0] == "--install-addon") return Diagnostics.InstallAddon(args[2], replace: args.Contains("--replace"), forever: args.Contains("--forever"), observer: args.Contains("--observer"));
        if (args.Length >= 1 && args[0] == "--check-observer") return Diagnostics.CheckObserver(args.Length > 1 ? args[1] : null);
        if (args.Length >= 2 && args[0] == "--ask") return Diagnostics.Ask(args[1]);
        if (args.Length >= 5 && args[0] == "--share-market") return Diagnostics.ShareMarket(args);
        if (args.Length >= 2 && args[0] == "--check-recorder") return Diagnostics.CheckRecorder(args.Skip(1));
        if (args.Length >= 1 && args[0] == "--obs-test") return Diagnostics.ObsTest(args);
        if (args.Length >= 2 && args[0] == "--check-forever") return Diagnostics.CheckForever(args[1]);
        if (args.Length >= 1 && args[0] == "--replay-test") return Diagnostics.ReplayTest(args);
        if (args.Length >= 1 && args[0] == "--replay-stop") return Diagnostics.ReplayStop();
        if (args.Length >= 2 && args[0] == "--settings-shot") return Diagnostics.SettingsShot(args[1]);
        if (args.Length >= 1 && args[0] == "--obs-scene-test") return Diagnostics.ObsSceneTest(args);
        if (args.Length >= 5 && args[0] == "--cut") return Diagnostics.Cut(args);
        if (args.Length >= 1 && args[0] == "--check-record-light") return Diagnostics.CheckRecordLight();
        if (args.Length >= 1 && args[0] == "--rec-test") return Diagnostics.RecTest(args);
        // --snap PATH: one frame of the running WoW window as a PNG (pictures of the addon for the website)
        if (args.Length >= 2 && args[0] == "--snap") { var w = NativeRecorder.FindWow(); if (w == IntPtr.Zero) return 2; NativeRecorder.SnapAsync(w, args[1]).GetAwaiter().GetResult(); return 0; }
        if (args.Length >= 1 && args[0] == "--obs-buffer-off") { var (ok, m) = ObsCheck.RestartAsync(() => ObsSetup.SetReplayBufferInProfile(false, 0, out _), CancellationToken.None).GetAwaiter().GetResult(); Console.WriteLine(m); return ok ? 0 : 1; }
        if (args.Length >= 1 && args[0] == "--obs-check") return Diagnostics.ObsCheckRun(args.Contains("forever"), args.Contains("restart"));
        if (args.Length >= 2 && args[0] == "--loudness") return Diagnostics.Loudness(args[1]);
        if (args.Length >= 1 && args[0] == "--obs-audio") return Diagnostics.ObsAudio();
        if (args.Length >= 2 && args[0] == "--fight-groups") return Diagnostics.FightGroups(args[1]);
        // --obs-set-encoder ENCODER QUALITY [STREAMENCODER]: restarts OBS with Simple mode set so (a processor encoder, to test the setup switching it back)
        if (args.Length >= 3 && args[0] == "--obs-set-encoder") { var (enc, q) = (args[1], args[2]); var (ok, m) = ObsCheck.RestartAsync(() => ObsSetup.EditProfile(l => ObsSetup.SetIni(l, "SimpleOutput", "RecEncoder", enc, v => v == enc) | ObsSetup.SetIni(l, "SimpleOutput", "RecQuality", q, v => v == q) | (args.Length > 3 && ObsSetup.SetIni(l, "SimpleOutput", "StreamEncoder", args[3], v => v == args[3])), out _, out _), CancellationToken.None).GetAwaiter().GetResult(); Console.WriteLine(m); return ok ? 0 : 1; }
        if (args.Length >= 2 && args[0] == "--probe") return Diagnostics.Probe(args[1], args.Contains("full"));
        if (args.Length >= 1 && args[0] == "--check-notes") return Diagnostics.CheckNotes(args);
        if (args.Length >= 2 && args[0] == "--local-sync-once") return Diagnostics.LocalSyncOnce(args);
        if (args.Length >= 2 && args[0] == "--check-live") return Diagnostics.CheckLive(args[1], args.Length > 2 && int.TryParse(args[2], out var from) ? from : 0);

        // Opened from Downloads: install, open the installed copy, and leave (Installer). A development
        // build (under bin) and a test run (MINDCOLLECTOR_DATA) never install themselves over the real one.
        var testData = Environment.GetEnvironmentVariable("MINDCOLLECTOR_DATA");
        var fromBuild = Environment.ProcessPath?.Contains(Path.DirectorySeparatorChar + "bin" + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase) == true;
#if STORE
        // The Store installs and updates it (StoreStartup): nothing to copy anywhere.
        var startMinimized = StoreStartup.LaunchedAtSignIn();
        var firstRun = !fromBuild && testData == null && !AppSettings.Exists();
#else
        if (!fromBuild && testData == null && Installer.InstallIfNeeded(fromUpdate: args.Contains("--update"))) return 0;
        var startMinimized = args.Contains("--minimized");
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
        var form = new MainForm(startMinimized: startMinimized, firstRun: firstRun);
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

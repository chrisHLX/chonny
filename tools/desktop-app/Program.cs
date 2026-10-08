namespace MindCollector.Desktop;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        // Diagnostics, for a tester to run and send back, and for checking a build without the window.
        if (args.Length >= 2 && args[0] == "--check-log") return Diagnostics.CheckLog(args[1]);
        if (args.Length >= 1 && args[0] == "--sync-once") return Diagnostics.SyncOnce(args);

        using var mutex = new Mutex(true, @"Local\MindCollectorDesktop", out var first);
        if (!first)
        {
            MessageBox.Show("MindCollector is already running. Look for it in the tray, by the clock.", "MindCollector");
            return 0;
        }

        ApplicationConfiguration.Initialize();
        Application.Run(new MainForm(startHidden: args.Contains("--minimized")));
        return 0;
    }
}

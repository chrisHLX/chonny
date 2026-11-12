using System.Text.Json;

namespace ClaudeReader;

internal static class Program
{
    /// <summary>
    /// ClaudeReader.exe [FILE]: one copy per Windows user, sitting in the tray. A second start (opening a
    /// file with it, or the hook starting it) hands its file to the running copy through the inbox.
    /// --extract FILE OUT: a file's text as the reader would speak it, written to OUT (for checking PDFs).
    /// </summary>
    [STAThread]
    private static int Main(string[] args)
    {
        if (args.Length >= 3 && args[0] == "--extract")
        {
            try
            {
                var text = args[1].EndsWith(".pdf", StringComparison.OrdinalIgnoreCase)
                    ? TextSources.FromPdfAsync(args[1], s => Console.WriteLine(s), CancellationToken.None).GetAwaiter().GetResult()
                    : TextSources.FromTextFile(args[1]);
                var sentences = TextSources.Sentences(text);
                File.WriteAllText(args[2], string.Join("\n", sentences));
                return 0;
            }
            catch (Exception e) { File.WriteAllText(args[2], "Error: " + e); return 1; }
        }

        using var mutex = new Mutex(true, @"Local\ClaudeReader", out var first);
        var file = args.FirstOrDefault(a => !a.StartsWith("--") && File.Exists(a));
        if (!first)
        {
            Directory.CreateDirectory(ReaderForm.Inbox);
            var msg = file != null ? new { kind = "open", path = Path.GetFullPath(file) } : (object)new { kind = "show" };
            File.WriteAllText(Path.Combine(ReaderForm.Inbox, $"{DateTime.UtcNow.Ticks}-start.json"), JsonSerializer.Serialize(msg));
            return 0;
        }

        ApplicationConfiguration.Initialize();
        var form = new ReaderForm();
        _ = form.Handle;   // the hotkeys and the inbox work before the window is ever shown
        if (file != null) form.Shown += async (_, _) => await form.OpenFileAsync(file);
        if (args.Contains("--show") || file != null) form.Show();
        Application.Run();
        return 0;
    }
}

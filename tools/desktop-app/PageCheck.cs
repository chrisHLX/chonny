using System.Text;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace MindCollector.Desktop;

/// <summary>
/// `--check-pages FILE...` (2026-11-22): loads saved pages (a Watch page from /api/coach/watch, say) in a
/// real WebView2 two ways, the old NavigateToString and PageHost, and reports for each whether it loaded,
/// how many ability buttons the timeline drew, the local video's and the YouTube player's state, with a
/// picture of each (check-pages-N.png). Writes check-pages.txt. Opens a small window while it runs.
/// </summary>
internal static class PageCheck
{
    private const string Probe = """
        (function () {
          var v = document.getElementById('vid'), p = null;
          try { p = window.YT && YT.get ? YT.get('yt') : null; } catch (e) {}
          return JSON.stringify({
            address: location.href,
            abilities: document.querySelectorAll('.ab').length,
            rails: document.querySelectorAll('.rail').length,
            video: v && v.tagName === 'VIDEO' && v.style.display !== 'none' ? { src: v.currentSrc, ready: v.readyState, seconds: Math.round(v.duration || 0) } : null,
            videoErrors: window.__mcVideoErrors || [],
            youtube: document.getElementById('yt') ? (p && p.getDuration ? { state: p.getPlayerState(), seconds: Math.round(p.getDuration()) } : 'player not ready') : null
          });
        })()
        """;

    public static int Run(IReadOnlyList<string> files)
    {
        var log = new StringBuilder();
        var ok = false;
        Application.EnableVisualStyles();
        using var form = new Form { Text = "MindCollector page check", ClientSize = new Size(1400, 900), StartPosition = FormStartPosition.Manual, Location = new Point(40, 40), ShowInTaskbar = false };
        var view = new WebView2 { Dock = DockStyle.Fill };
        form.Controls.Add(view);
        form.Shown += async (_, _) =>
        {
            try { ok = await RunAsync(view, files, log); }
            catch (Exception e) { log.AppendLine("Failed: " + e); }
            form.Close();
        };
        Application.Run(form);
        Directory.CreateDirectory(AppSettings.Dir);
        File.WriteAllText(Path.Combine(AppSettings.Dir, "check-pages.txt"), log.ToString());
        return ok ? 0 : 1;
    }

    private static async Task<bool> RunAsync(WebView2 view, IReadOnlyList<string> files, StringBuilder log)
    {
        var settings = AppSettings.Load();
        var env = await CoreWebView2Environment.CreateAsync(null, Path.Combine(Path.GetTempPath(), "MindCollector-page-check"));
        await view.EnsureCoreWebView2Async(env);
        var web = view.CoreWebView2;
        web.SetVirtualHostNameToFolderMapping(VideoLibrary.Host, Recorder.VideosDir(settings), CoreWebView2HostResourceAccessKind.Allow);
        var host = new PageHost(web);
        // A video's error, kept before the page's own handler clears it.
        await web.AddScriptToExecuteOnDocumentCreatedAsync("window.__mcVideoErrors = []; addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'VIDEO') { __mcVideoErrors.push((t.error ? t.error.code + ' ' + t.error.message : '?') + ' @ ' + t.currentSrc); } }, true);");
        var allOk = true;

        for (var i = 0; i < files.Count; i++)
        {
            // As the app shows a website page: with the site as its base.
            var html = File.ReadAllText(files[i]).Replace("<head>", $"<head><base href=\"{settings.ServerUrl}/\">", StringComparison.OrdinalIgnoreCase);
            log.AppendLine($"{Path.GetFileName(files[i])} ({Encoding.UTF8.GetByteCount(html) / 1048576.0:F2} MB)");

            string before;
            try { before = await Loaded(web, () => web.NavigateToString(html)) ? "loaded " + await Look(web, $"check-pages-{i + 1}-before.png") : "navigation failed"; }
            catch (ArgumentException e) { before = "refused: " + e.Message; }
            log.AppendLine($"  NavigateToString: {before}");

            var loaded = await Loaded(web, () => host.Show(html));
            log.AppendLine($"  PageHost: {(loaded ? "loaded " + await Look(web, $"check-pages-{i + 1}.png") : "navigation failed")}");
            allOk &= loaded;
        }
        return allOk;
    }

    /// <summary>
    /// `--swap-test DIR A B` (2026-11-25): what the window's player does when a video's file is replaced
    /// at the same address, as a trim does. Plays A as DIR\swap.mp4, puts B in its place, loads the page
    /// again at the same address and then with a query on it. Writes swap-test.txt. Its window is off-screen.
    /// </summary>
    public static int SwapTest(string dir, string a, string b)
    {
        var log = new StringBuilder();
        Application.EnableVisualStyles();
        using var form = new Form { Text = "MindCollector swap test", ClientSize = new Size(900, 600), StartPosition = FormStartPosition.Manual, Location = new Point(-4000, -4000), ShowInTaskbar = false };
        var view = new WebView2 { Dock = DockStyle.Fill };
        form.Controls.Add(view);
        form.Shown += async (_, _) =>
        {
            try
            {
                var env = await CoreWebView2Environment.CreateAsync(null, Path.Combine(Path.GetTempPath(), "MindCollector-swap-test-" + Environment.TickCount64));
                await view.EnsureCoreWebView2Async(env);
                var web = view.CoreWebView2;
                web.SetVirtualHostNameToFolderMapping(VideoLibrary.Host, dir, CoreWebView2HostResourceAccessKind.Allow);
                var host = new PageHost(web);
                var target = Path.Combine(dir, "swap.mp4");
                async Task Try(string label, string query)
                {
                    var html = $$"""
                        <!DOCTYPE html><html><body><video id="v" preload="metadata" src="https://{{VideoLibrary.Host}}/swap.mp4{{query}}"></video>
                        <script>window.__r=null;var v=document.getElementById('v');v.addEventListener('loadedmetadata',function(){__r='ok '+v.duration.toFixed(2)+'s';v.currentTime=v.duration-2;});v.addEventListener('error',function(){__r='error '+v.error.code+' '+v.error.message;});</script></body></html>
                        """;
                    await Loaded(web, () => host.Show(html));
                    await Task.Delay(6000);
                    log.AppendLine($"{label}: {System.Text.Json.JsonSerializer.Deserialize<string>(await web.ExecuteScriptAsync("String(window.__r)"))}");
                }
                File.Copy(a, target, true);
                log.AppendLine($"A {new FileInfo(a).Length} bytes, B {new FileInfo(b).Length} bytes");
                await Try("A, first load", "");
                await Try("A, again", "");
                await Loaded(web, () => host.Show("<!DOCTYPE html><html><body>blank</body></html>"));
                File.Copy(b, target, true);
                await Try("B at the same address", "");
                await Try("B with ?v=2", "?v=2");
            }
            catch (Exception e) { log.AppendLine("Failed: " + e); }
            form.Close();
        };
        Application.Run(form);
        Directory.CreateDirectory(AppSettings.Dir);
        File.WriteAllText(Path.Combine(AppSettings.Dir, "swap-test.txt"), log.ToString());
        return 0;
    }

    /// <summary>Gives the video, or YouTube's player, time to get going, then what the page shows and a picture of it.</summary>
    private static async Task<string> Look(CoreWebView2 web, string picture)
    {
        await Task.Delay(12000);
        var state = System.Text.Json.JsonSerializer.Deserialize<string>(await web.ExecuteScriptAsync(Probe));
        // And two minutes in: a round's players are drawn from a minute before it starts, so a recording
        // with a long prep shows none at 0:00.
        var later = "";
        if (await web.ExecuteScriptAsync("(function () { var v = document.getElementById('vid'); if (!v || !v.duration || v.style.display === 'none') return false; v.currentTime = 120; return true; })()") == "true")
        {
            await Task.Delay(3000);
            later = "\n    at 2:00: " + System.Text.Json.JsonSerializer.Deserialize<string>(await web.ExecuteScriptAsync(Probe));
        }
        var png = Path.Combine(AppSettings.Dir, picture);
        using (var stream = File.Create(png)) await web.CapturePreviewAsync(CoreWebView2CapturePreviewImageFormat.Png, stream);
        return $"{state}{later}\n    picture: {png}";
    }

    /// <summary>Runs a navigation and waits (up to 30s) for it to finish; true when it succeeded.</summary>
    private static async Task<bool> Loaded(CoreWebView2 web, Action navigate)
    {
        var done = new TaskCompletionSource<bool>();
        void Completed(object? s, CoreWebView2NavigationCompletedEventArgs e) => done.TrySetResult(e.IsSuccess);
        web.NavigationCompleted += Completed;
        try
        {
            navigate();
            return await Task.WhenAny(done.Task, Task.Delay(30000)) == done.Task && done.Task.Result;
        }
        finally { web.NavigationCompleted -= Completed; }
    }
}

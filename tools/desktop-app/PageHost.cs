using System.Text;
using Microsoft.Web.WebView2.Core;

namespace MindCollector.Desktop;

/// <summary>
/// Draws the app's pages from a made-up https address, answered from memory (0.6.33, 2026-11-22).
///
/// Before, every page went in with NavigateToString, which has two faults that both hit the Watch page:
/// - **A size limit.** WebView2 writes the string out as a URL-encoded document and refuses one over
///   2 MB ("Value does not fall within the expected range"). Every quote, bracket and space becomes three
///   bytes, so a 1.2 MB shuffle timeline is about 2.3 MB encoded. Six of the nine recorded shuffles of
///   Oct-Nov were refused, and the app fell back to the plain player with no timeline.
/// - **No address.** A string has no origin, so the YouTube player inside it sent no Referer, and
///   YouTube answers that with error 153: an uploaded game's timeline never played.
/// Served here, a page has no limit and its address is the Referer.
/// </summary>
internal sealed class PageHost
{
    public const string Host = "app.mindcollector.invalid";
    private const int Keep = 8;   // recent pages, so the browser's Back (Alt+Left) still finds one

    private readonly CoreWebView2 _web;
    private readonly Dictionary<int, byte[]> _pages = [];
    private int _n;

    public PageHost(CoreWebView2 web)
    {
        _web = web;
        web.AddWebResourceRequestedFilter($"https://{Host}/*", CoreWebView2WebResourceContext.All);
        web.WebResourceRequested += OnRequest;
    }

    public static bool Owns(Uri uri) => uri.Host.Equals(Host, StringComparison.OrdinalIgnoreCase);

    public void Show(string html)
    {
        _pages[++_n] = Encoding.UTF8.GetBytes(html);
        _pages.Remove(_n - Keep);
        _web.Navigate($"https://{Host}/page/{_n}");
    }

    private void OnRequest(object? sender, CoreWebView2WebResourceRequestedEventArgs e)
    {
        if (!Uri.TryCreate(e.Request.Uri, UriKind.Absolute, out var uri) || !Owns(uri)) return;
        var path = uri.AbsolutePath;
        e.Response = path.StartsWith("/page/", StringComparison.Ordinal) && int.TryParse(path[6..], out var n) && _pages.TryGetValue(n, out var page)
            ? _web.Environment.CreateWebResourceResponse(new MemoryStream(page), 200, "OK", "Content-Type: text/html; charset=utf-8\r\nCache-Control: no-store")
            : _web.Environment.CreateWebResourceResponse(null, 404, "Not Found", "");
    }
}

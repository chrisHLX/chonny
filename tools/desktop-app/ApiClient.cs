using System.IO.Compression;
using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace MindCollector.Desktop;

/// <summary>
/// The website's app API, with the player's key from /wow/coach as a Bearer token:
///   POST /api/coach/round    one round's log text, gzipped, in 768 KB parts when larger
///   POST /api/coach/done     after a batch: assemble lobbies, build the player's pages
///   GET  /api/coach/version  the measure version the server stores
///   GET  /api/coach/index    the player's games, characters, comps and classes
///   GET  /api/coach/page/x   one page, with an ETag so an unchanged page is not fetched twice
///   POST /api/coach/note     a note or a Ctrl+Shift+M mark (CoachAppController)
///   POST /api/coach/players  the experience of the players in the game being played
/// The server measures every round itself (CoachUploadController); the app never sends a
/// measurement, only the log text of a finished round.
/// </summary>
public sealed class ApiClient : IDisposable
{
    /// <summary>Nginx accepts 1 MB a request on the site; parts stay well under it.</summary>
    private const int PartSize = 768 * 1024;

    private readonly HttpClient _http;
    private readonly string _base;

    public ApiClient(string serverUrl, string key)
    {
        _base = serverUrl.TrimEnd('/');
        _http = new HttpClient { Timeout = TimeSpan.FromMinutes(3) };
        _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", key);
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.UserAgent.ParseAdd($"MindCollector-Desktop/{typeof(ApiClient).Assembly.GetName().Version?.ToString(2) ?? "0"}");
    }

    public sealed class KeyRejectedException() : Exception("The website did not accept this key. Make a new one on mindcollector.com/wow/coach.");

    public sealed record SendResult(string Status, string? Reason, int Bytes);

    /// <summary>Sends one finished round. Waits and tries again when the server says it is busy.</summary>
    public async Task<SendResult> SendRoundAsync(string roundText, CancellationToken ct)
    {
        var bytes = Gzip(roundText);
        var match = Md5Hex(roundText);
        var parts = Math.Max(1, (int)Math.Ceiling(bytes.Length / (double)PartSize));
        JsonElement last = default;

        for (int part = 1; part <= parts; part++)
        {
            var offset = (part - 1) * PartSize;
            var length = Math.Min(PartSize, bytes.Length - offset);

            for (int attempt = 0; ; attempt++)
            {
                using var content = new ByteArrayContent(bytes, offset, length);
                content.Headers.ContentType = new MediaTypeHeaderValue("application/gzip");
                using var req = new HttpRequestMessage(HttpMethod.Post, $"{_base}/api/coach/round") { Content = content };
                req.Headers.Add("X-Match", match);
                req.Headers.Add("X-Part", part.ToString());
                req.Headers.Add("X-Parts", parts.ToString());

                using var res = await _http.SendAsync(req, ct);
                if (res.StatusCode == HttpStatusCode.Unauthorized) throw new KeyRejectedException();
                if (res.StatusCode == HttpStatusCode.TooManyRequests && attempt < 10)
                {
                    await Task.Delay(TimeSpan.FromSeconds(RetryAfter(res, await res.Content.ReadAsStringAsync(ct))), ct);
                    continue;
                }
                if (res.StatusCode == HttpStatusCode.RequestEntityTooLarge)
                    return new SendResult("failed", "Too large for the server to accept (its upload limit).", bytes.Length);
                if ((int)res.StatusCode >= 500 && attempt < 3)
                {
                    await Task.Delay(TimeSpan.FromSeconds(10 * (attempt + 1)), ct);
                    continue;
                }

                var body = await res.Content.ReadAsStringAsync(ct);
                if (!res.IsSuccessStatusCode) return new SendResult("failed", $"Server said {(int)res.StatusCode}.", bytes.Length);
                last = JsonDocument.Parse(body).RootElement.Clone();
                break;
            }
        }

        var status = last.TryGetProperty("status", out var s) ? s.GetString() ?? "?" : "?";
        var reason = last.TryGetProperty("reason", out var r) ? r.GetString() : null;
        return new SendResult(status, reason, bytes.Length);
    }

    public async Task<int> DoneAsync(CancellationToken ct)
    {
        using var res = await _http.PostAsync($"{_base}/api/coach/done", null, ct);
        if (res.StatusCode == HttpStatusCode.Unauthorized) throw new KeyRejectedException();
        res.EnsureSuccessStatusCode();
        using var doc = JsonDocument.Parse(await res.Content.ReadAsStringAsync(ct));
        return doc.RootElement.TryGetProperty("assembled", out var a) ? a.GetInt32() : 0;
    }

    /// <summary>
    /// The key the player approved in the browser for this code (AppDownloadController), or null while
    /// they have not yet. Needs no key itself: this is how the app gets one.
    /// </summary>
    public static async Task<string?> PickUpKeyAsync(string serverUrl, string code, CancellationToken ct)
    {
        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
        http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        using var res = await http.GetAsync($"{serverUrl.TrimEnd('/')}/api/coach/connect/{code}", ct);
        if (res.StatusCode != HttpStatusCode.OK) return null;
        using var doc = JsonDocument.Parse(await res.Content.ReadAsStringAsync(ct));
        return doc.RootElement.TryGetProperty("key", out var key) ? key.GetString() : null;
    }

    public async Task<int> VersionAsync(CancellationToken ct)
    {
        using var res = await _http.GetAsync($"{_base}/api/coach/version", ct);
        if (res.StatusCode == HttpStatusCode.Unauthorized) throw new KeyRejectedException();
        res.EnsureSuccessStatusCode();
        using var doc = JsonDocument.Parse(await res.Content.ReadAsStringAsync(ct));
        return doc.RootElement.GetProperty("version").GetInt32();
    }

    public async Task<JsonDocument> IndexAsync(CancellationToken ct)
    {
        using var res = await _http.GetAsync($"{_base}/api/coach/index", ct);
        if (res.StatusCode == HttpStatusCode.Unauthorized) throw new KeyRejectedException();
        res.EnsureSuccessStatusCode();
        return JsonDocument.Parse(await res.Content.ReadAsStringAsync(ct));
    }

    public enum NoteOutcome { Stored, Refused, Later }

    /// <summary>
    /// POST /api/coach/note (CoachAppController). Refused is final (the website does not know the
    /// game, or the note is not one); Later is anything else, including a website not yet deployed
    /// with the endpoint, so the note waits rather than being lost.
    /// </summary>
    public async Task<(NoteOutcome Outcome, string? Reason)> NoteAsync(AppNote note, CancellationToken ct)
    {
        var body = JsonSerializer.Serialize(new Dictionary<string, string?>
        {
            ["id"] = note.Id, ["kind"] = note.Kind, ["text"] = note.Text, ["at"] = note.At,
            ["roundStart"] = note.RoundStart, ["lobby"] = note.Lobby,
        });
        using var content = new StringContent(body, Encoding.UTF8, "application/json");
        using var res = await _http.PostAsync($"{_base}/api/coach/note", content, ct);
        if (res.StatusCode == HttpStatusCode.Unauthorized) throw new KeyRejectedException();
        var text = await res.Content.ReadAsStringAsync(ct);
        string? status = null, reason = null;
        try
        {
            using var doc = JsonDocument.Parse(text);
            status = doc.RootElement.TryGetProperty("status", out var s) ? s.GetString() : null;
            reason = doc.RootElement.TryGetProperty("reason", out var r) ? r.GetString() : null;
        }
        catch { }
        // Only the website saying "stored" counts: a PHP error page also arrives as 200 OK, and a
        // note taken as sent on one of those would be lost.
        return status switch
        {
            "stored" when res.IsSuccessStatusCode => (NoteOutcome.Stored, null),
            "unknown-game" => (NoteOutcome.Refused, "the website does not have that game"),
            "rejected" => (NoteOutcome.Refused, reason ?? "the website would not take it"),
            _ => (NoteOutcome.Later, $"the website said {(int)res.StatusCode}"),
        };
    }

    /// <summary>
    /// POST /api/coach/players: the experience of the players in the game being played. Known ones
    /// come back now; the rest are looked up on the website and come back on a later ask.
    /// </summary>
    public async Task<(Dictionary<string, JsonElement> Known, List<string> Pending)> PlayersAsync(IEnumerable<string> names, CancellationToken ct)
    {
        using var content = new StringContent(JsonSerializer.Serialize(new { players = names }), Encoding.UTF8, "application/json");
        using var res = await _http.PostAsync($"{_base}/api/coach/players", content, ct);
        if (res.StatusCode == HttpStatusCode.Unauthorized) throw new KeyRejectedException();
        res.EnsureSuccessStatusCode();
        using var doc = JsonDocument.Parse(await res.Content.ReadAsStringAsync(ct));
        var known = new Dictionary<string, JsonElement>();
        if (doc.RootElement.TryGetProperty("players", out var p) && p.ValueKind == JsonValueKind.Object)
            foreach (var x in p.EnumerateObject()) known[x.Name] = x.Value.Clone();
        var pending = doc.RootElement.TryGetProperty("pending", out var q) && q.ValueKind == JsonValueKind.Array
            ? q.EnumerateArray().Select(e => e.GetString() ?? "").Where(s => s != "").ToList()
            : [];
        return (known, pending);
    }

    /// <summary>A page, from the local copy when the server says it has not changed.</summary>
    public async Task<string?> PageAsync(string file, CancellationToken ct)
    {
        var cacheDir = Path.Combine(AppSettings.Dir, "pages");
        Directory.CreateDirectory(cacheDir);
        var cached = Path.Combine(cacheDir, file);
        var etagFile = cached + ".etag";

        using var req = new HttpRequestMessage(HttpMethod.Get, $"{_base}/api/coach/page/{file}");
        if (File.Exists(cached) && File.Exists(etagFile))
            req.Headers.TryAddWithoutValidation("If-None-Match", await File.ReadAllTextAsync(etagFile, ct));

        try
        {
            using var res = await _http.SendAsync(req, ct);
            if (res.StatusCode == HttpStatusCode.Unauthorized) throw new KeyRejectedException();
            if (res.StatusCode == HttpStatusCode.NotModified && File.Exists(cached)) return await File.ReadAllTextAsync(cached, ct);
            if (res.StatusCode == HttpStatusCode.NotFound) return null;
            res.EnsureSuccessStatusCode();
            var html = await res.Content.ReadAsStringAsync(ct);
            await File.WriteAllTextAsync(cached, html, ct);
            if (res.Headers.ETag != null) await File.WriteAllTextAsync(etagFile, res.Headers.ETag.ToString(), ct);
            return html;
        }
        catch (HttpRequestException) when (File.Exists(cached))
        {
            // Offline: the last copy is better than nothing.
            return await File.ReadAllTextAsync(cached, ct);
        }
    }

    /// <summary>
    /// A game's Watch page (CoachPagesController::watch): its goes, deaths and defensives under the
    /// video, lined up from what the video's timing file says. Null when the website has no such page
    /// (an older website, or a game it does not hold), so the plain player shows instead.
    /// </summary>
    public async Task<string?> WatchPageAsync(string lobby, VideoEntry video, CancellationToken ct)
    {
        static string Clock(DateTime t) => t.ToString("yyyy-MM-dd HH:mm:ss.fff", System.Globalization.CultureInfo.InvariantCulture);
        var query = new List<string> { "video=" + Uri.EscapeDataString(video.Name), "started=" + Uri.EscapeDataString(Clock(video.Started)) };
        query.AddRange(video.Rounds.Select(r => "rounds[]=" + Uri.EscapeDataString(Clock(r))));
        using var res = await _http.GetAsync($"{_base}/api/coach/watch/{lobby}?{string.Join("&", query)}", ct);
        if (res.StatusCode == HttpStatusCode.Unauthorized) throw new KeyRejectedException();
        if (!res.IsSuccessStatusCode) return null;
        return await res.Content.ReadAsStringAsync(ct);
    }

    private static double RetryAfter(HttpResponseMessage res, string body)
    {
        if (res.Headers.RetryAfter?.Delta is { } d) return Math.Max(1, d.TotalSeconds);
        try
        {
            using var doc = JsonDocument.Parse(body);
            if (doc.RootElement.TryGetProperty("retryAfter", out var r)) return Math.Max(1, r.GetDouble());
        }
        catch { }
        return 15;
    }

    private static byte[] Gzip(string text)
    {
        using var output = new MemoryStream();
        using (var gz = new GZipStream(output, CompressionLevel.Optimal, leaveOpen: true))
        {
            var bytes = Encoding.UTF8.GetBytes(text);
            gz.Write(bytes, 0, bytes.Length);
        }
        return output.ToArray();
    }

    private static string Md5Hex(string text) =>
        Convert.ToHexString(MD5.HashData(Encoding.UTF8.GetBytes(text))).ToLowerInvariant();

    public void Dispose() => _http.Dispose();
}

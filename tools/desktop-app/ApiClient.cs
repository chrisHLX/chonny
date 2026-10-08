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
        _http.DefaultRequestHeaders.UserAgent.ParseAdd("MindCollector-Desktop/0.2");
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

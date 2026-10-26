using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace MindCollector.Desktop;

/// <summary>
/// One video to the player's own YouTube channel (2026-10-26), straight from this PC: YouTube's
/// resumable upload (videos.insert, uploadType=resumable), with no library, the way ObsClient talks
/// to OBS. The website gives the short-lived access token and the title, description and chapters
/// (ApiClient.PrepareYouTubeAsync); nothing passes through it.
///
/// Sent in 8 MB pieces (a multiple of the 256 KB YouTube requires). After a dropped connection it
/// asks YouTube how much arrived and carries on from there; an expired token (an hour) is replaced
/// by asking the website again.
/// </summary>
public sealed class YouTubeUploader
{
    private const string Insert = "https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status";
    private const int Piece = 32 * 256 * 1024;
    private const int Tries = 6;

    public sealed class UploadException(string message) : Exception(message);

    /// <summary>Uploads one file and returns the new video's id.</summary>
    public static async Task<string> UploadAsync(string path, string title, string description, IReadOnlyList<string> tags, string privacy,
        Func<Task<string>> token, IProgress<double> progress, CancellationToken ct)
    {
        // YouTube's "carry on" is a 308 with no Location: never treat it as a redirect.
        using var http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false }) { Timeout = TimeSpan.FromMinutes(5) };
        var length = new FileInfo(path).Length;
        var access = await token();

        // 1. Ask for an upload address, with the video's details.
        var meta = new JsonObject
        {
            ["snippet"] = new JsonObject
            {
                ["title"] = title,
                ["description"] = description,
                ["tags"] = new JsonArray(tags.Select(t => (JsonNode)t).ToArray()),
                ["categoryId"] = "20",   // Gaming
            },
            ["status"] = new JsonObject { ["privacyStatus"] = privacy, ["selfDeclaredMadeForKids"] = false },
        };
        string? address = null;
        for (var attempt = 1; address == null; attempt++)
        {
            using var start = new HttpRequestMessage(HttpMethod.Post, Insert)
            {
                Content = new StringContent(meta.ToJsonString(), Encoding.UTF8, "application/json"),
            };
            start.Headers.Authorization = new AuthenticationHeaderValue("Bearer", access);
            start.Headers.Add("X-Upload-Content-Length", length.ToString());
            start.Headers.Add("X-Upload-Content-Type", ContentType(path));
            using var res = await http.SendAsync(start, ct);
            if (res.StatusCode == HttpStatusCode.Unauthorized && attempt < Tries) { access = await token(); continue; }
            if (!res.IsSuccessStatusCode)
                throw new UploadException(Reason(await res.Content.ReadAsStringAsync(ct), res.StatusCode));
            address = res.Headers.Location?.ToString() ?? throw new UploadException("YouTube gave no upload address.");
        }

        // 2. Send the file in pieces, resuming from what YouTube says it has.
        await using var file = File.OpenRead(path);
        long sent = 0;
        var failures = 0;
        var buffer = new byte[Piece];
        while (true)
        {
            ct.ThrowIfCancellationRequested();
            file.Position = sent;
            var count = await file.ReadAsync(buffer.AsMemory(0, (int)Math.Min(Piece, length - sent)), ct);
            using var put = new HttpRequestMessage(HttpMethod.Put, address) { Content = new ByteArrayContent(buffer, 0, count) };
            put.Content.Headers.ContentRange = new ContentRangeHeaderValue(sent, sent + count - 1, length);
            put.Headers.Authorization = new AuthenticationHeaderValue("Bearer", access);
            try
            {
                using var res = await http.SendAsync(put, ct);
                if ((int)res.StatusCode == 308)   // more to come: YouTube says how much it has
                {
                    sent = res.Headers.TryGetValues("Range", out var r) && r.FirstOrDefault() is { } range
                        ? long.Parse(range[(range.IndexOf('-') + 1)..]) + 1
                        : 0;
                    failures = 0;
                    progress.Report((double)sent / length);
                    continue;
                }
                if (res.IsSuccessStatusCode)
                {
                    progress.Report(1);
                    using var doc = JsonDocument.Parse(await res.Content.ReadAsStringAsync(ct));
                    return doc.RootElement.GetProperty("id").GetString() ?? throw new UploadException("YouTube gave no video id.");
                }
                if (res.StatusCode == HttpStatusCode.Unauthorized && ++failures < Tries) { access = await token(); sent = await ReceivedAsync(http, address, access, length, ct); continue; }
                if ((int)res.StatusCode >= 500 && ++failures < Tries) { await Task.Delay(TimeSpan.FromSeconds(2 << failures), ct); sent = await ReceivedAsync(http, address, access, length, ct); continue; }
                throw new UploadException(Reason(await res.Content.ReadAsStringAsync(ct), res.StatusCode));
            }
            catch (HttpRequestException) when (++failures < Tries)
            {
                await Task.Delay(TimeSpan.FromSeconds(2 << failures), ct);
                sent = await ReceivedAsync(http, address, access, length, ct);
            }
            catch (TaskCanceledException) when (!ct.IsCancellationRequested && ++failures < Tries)
            {
                sent = await ReceivedAsync(http, address, access, length, ct);
            }
        }
    }

    /// <summary>How much of the file YouTube has, after an interruption ("bytes */total").</summary>
    private static async Task<long> ReceivedAsync(HttpClient http, string address, string access, long length, CancellationToken ct)
    {
        try
        {
            using var ask = new HttpRequestMessage(HttpMethod.Put, address) { Content = new ByteArrayContent([]) };
            ask.Content.Headers.Add("Content-Range", $"bytes */{length}");
            ask.Headers.Authorization = new AuthenticationHeaderValue("Bearer", access);
            using var res = await http.SendAsync(ask, ct);
            return (int)res.StatusCode == 308 && res.Headers.TryGetValues("Range", out var r) && r.FirstOrDefault() is { } range
                ? long.Parse(range[(range.IndexOf('-') + 1)..]) + 1
                : 0;
        }
        catch { return 0; }
    }

    private static string ContentType(string path) => Path.GetExtension(path).ToLowerInvariant() switch
    {
        ".mp4" => "video/mp4",
        ".mkv" => "video/x-matroska",
        ".mov" => "video/quicktime",
        ".webm" => "video/webm",
        _ => "video/*",
    };

    private static string Reason(string body, HttpStatusCode code)
    {
        try
        {
            using var doc = JsonDocument.Parse(body);
            var e = doc.RootElement.GetProperty("error");
            var reason = e.TryGetProperty("errors", out var list) && list.GetArrayLength() > 0 && list[0].TryGetProperty("reason", out var rs) ? rs.GetString() : null;
            return reason switch
            {
                "quotaExceeded" or "uploadLimitExceeded" => "YouTube's upload limit for today is used up. Try again tomorrow.",
                "youtubeSignupRequired" => "This Google account has no YouTube channel yet. Make one on youtube.com, then try again.",
                _ => $"YouTube said no ({(int)code}): {e.GetProperty("message").GetString()}",
            };
        }
        catch { return $"YouTube said no ({(int)code})."; }
    }
}

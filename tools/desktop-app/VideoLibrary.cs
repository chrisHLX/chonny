using System.Globalization;
using System.Net;
using System.Text.Json;

namespace MindCollector.Desktop;

/// <summary>One video in the videos folder, with what its timing file says (Recorder.WriteSidecar), if it has one.</summary>
internal sealed record VideoEntry(string Name, DateTime Started, DateTime? Stopped, string? Bracket, IReadOnlyList<DateTime> Rounds, long Bytes)
{
    public TimeSpan? Length => Stopped is { } s && s > Started ? s - Started : null;
}

/// <summary>
/// The Videos tab (2026-10-25): the recordings in the videos folder, played in the window. WebView2
/// serves the folder under a made-up host (SetVirtualHostNameToFolderMapping), so the page plays a
/// video from disk without a file:/// address and without copying it. A video the app recorded
/// carries its round starts, so the page has a button per round, and the video is matched to its
/// game on the website by round 1's start (the index's playedAt is that START cut to the second).
/// </summary>
internal static class VideoLibrary
{
    public const string Host = "videos.mindcollector.invalid";

    private static readonly string[] Kinds = [".mp4", ".mkv", ".mov", ".webm"];

    public static List<VideoEntry> Load(string dir)
    {
        var list = new List<VideoEntry>();
        if (!Directory.Exists(dir)) return list;
        foreach (var path in Directory.EnumerateFiles(dir))
        {
            if (!Kinds.Contains(Path.GetExtension(path), StringComparer.OrdinalIgnoreCase)) continue;
            try
            {
                var info = new FileInfo(path);
                DateTime started = info.CreationTime;
                DateTime? stopped = null;
                string? bracket = null;
                var rounds = new List<DateTime>();
                var sidecar = path + Recorder.SidecarSuffix;
                if (File.Exists(sidecar))
                {
                    using var doc = JsonDocument.Parse(File.ReadAllText(sidecar));
                    var r = doc.RootElement;
                    if (Clock(r, "recordingStarted") is { } s) started = s;
                    stopped = Clock(r, "recordingStopped");
                    bracket = r.TryGetProperty("bracket", out var b) ? b.GetString() : null;
                    if (r.TryGetProperty("rounds", out var rs) && rs.ValueKind == JsonValueKind.Array)
                        foreach (var x in rs.EnumerateArray())
                            if (DateTime.TryParse(x.GetString(), CultureInfo.InvariantCulture, DateTimeStyles.None, out var t)) rounds.Add(t);
                }
                list.Add(new VideoEntry(info.Name, started, stopped, bracket, rounds, info.Length));
            }
            catch { /* a video still being written, or a broken timing file: leave it out this time */ }
        }
        return list.OrderByDescending(v => v.Started).ToList();
    }

    /// <summary>The video whose round 1 started at playedAt (the START line, cut to the second).</summary>
    public static VideoEntry? ForGame(IEnumerable<VideoEntry> videos, string playedAt)
    {
        if (!DateTime.TryParse(playedAt, CultureInfo.InvariantCulture, DateTimeStyles.None, out var at)) return null;
        return videos.FirstOrDefault(v => v.Rounds.Count > 0 && (v.Rounds[0] - at).TotalSeconds is >= -1 and < 2);
    }

    private static DateTime? Clock(JsonElement e, string name) =>
        e.TryGetProperty(name, out var p) && DateTime.TryParse(p.GetString(), CultureInfo.InvariantCulture, DateTimeStyles.None, out var t) ? t : null;

    public static string Duration(TimeSpan t) => t.TotalHours >= 1 ? t.ToString(@"h\:mm\:ss") : t.ToString(@"m\:ss");

    /// <summary>The player: the video, a button per round, and the way to its game and its file.</summary>
    public static string Page(VideoEntry v, string title, string? lobby)
    {
        var src = $"https://{Host}/{Uri.EscapeDataString(v.Name)}";
        var buttons = string.Join("", v.Rounds.Select((r, i) =>
        {
            var at = Math.Max(0, (r - v.Started).TotalSeconds);
            var label = v.Rounds.Count > 1 ? $"Round {i + 1}" : "Gates open";
            return $"<button data-t=\"{at.ToString("0.##", CultureInfo.InvariantCulture)}\">{label} <span>{Duration(TimeSpan.FromSeconds(at))}</span></button>";
        }));
        var facts = new List<string> { v.Started.ToString("ddd d MMM, HH:mm", CultureInfo.InvariantCulture) };
        if (v.Length is { } len) facts.Add(Duration(len));
        facts.Add($"{v.Bytes / (1024.0 * 1024):0} MB");
        var game = lobby == null ? "" : $"<a class=\"btn gold\" href=\"mc-app://game/{lobby}\">Open this game</a>";

        return $$$"""
            <!doctype html><html><head><meta charset="utf-8"><style>
            html,body{margin:0;background:#121212;color:#fff;font-family:'Segoe UI',Arial,sans-serif}
            .wrap{padding:24px 28px;max-width:1180px}
            .eyebrow{color:#C8952C;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
            h1{font-size:26px;font-weight:800;letter-spacing:-.02em;margin:6px 0 2px}
            .muted{color:#B3B3B3;font-size:13.5px;margin-bottom:16px}
            video{display:block;width:100%;max-height:68vh;background:#000;border-radius:8px}
            .bar{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;align-items:center}
            button,.btn{border:0;border-radius:999px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;background:#2A2A2A;color:#fff;text-decoration:none;font-family:inherit}
            button:hover,.btn:hover{background:#3E3E3E}
            button span{color:#B3B3B3;font-weight:400;margin-left:4px;font-variant-numeric:tabular-nums}
            .btn.gold{background:#C8952C;color:#000}.btn.gold:hover{background:#E8B84B}
            .gap{flex:1}
            .err{display:none;margin-top:14px;padding:12px 14px;background:#181818;border-radius:8px;color:#B3B3B3;font-size:13.5px}
            </style></head><body><div class="wrap">
            <div class="eyebrow">Video</div>
            <h1>{{{WebUtility.HtmlEncode(title)}}}</h1>
            <div class="muted">{{{WebUtility.HtmlEncode(string.Join(" · ", facts))}}}</div>
            <video id="v" controls preload="metadata" src="{{{src}}}"></video>
            <div class="err" id="err">This video would not play here. The window plays MP4; Settings &gt; Set up OBS checks that OBS records MP4. Show it in its folder to open it in another player.</div>
            <div class="bar">{{{buttons}}}<span class="gap"></span>{{{game}}}<a class="btn" href="mc-app://folder/{{{Uri.EscapeDataString(v.Name)}}}">Show in folder</a></div>
            </div><script>
            (function(){
              var v=document.getElementById('v');
              v.addEventListener('error',function(){document.getElementById('err').style.display='block';});
              var bs=document.querySelectorAll('button[data-t]');
              for(var i=0;i<bs.length;i++){bs[i].onclick=function(){v.currentTime=parseFloat(this.getAttribute('data-t'));v.play().catch(function(){});};}
            })();
            </script></body></html>
            """;
    }

    /// <summary>The button on a game's card that opens its video, in the note bar at the bottom (ThisGamePage.NoteBar), clear of the card.</summary>
    public static string WatchBar(string name) => $$$"""
        <a id="mc-watch" href="mc-app://video/{{{Uri.EscapeDataString(name)}}}" style="margin-left:auto;background:#C8952C;color:#000;border-radius:999px;padding:8px 16px;font:700 13px 'Segoe UI',Arial,sans-serif;text-decoration:none;white-space:nowrap">&#9654; Watch the video</a>
        <script>(function(){var a=document.getElementById('mc-watch'),bar=document.getElementById('mc-notebar');if(a&&bar)bar.appendChild(a);})();</script>
        """;
}

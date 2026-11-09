using System.Globalization;
using System.Net;

namespace MindCollector.Desktop;

/// <summary>
/// The WoW Forever side of the app (2026-11-06): its own pages, beside retail's, picked with the
/// switch at the top of the menu. Everything here comes from the fight clips in the videos folder and
/// their timing files (ForeverRecorder); nothing is sent to the website yet.
/// </summary>
internal static class ForeverPages
{
    /// <summary>What the Forever side is watching, and how many fights it holds: shown when no fight is open.</summary>
    public static string Overview(AppSettings settings, ForeverRecorder recorder, IReadOnlyList<VideoEntry> fights)
    {
        var rows = new List<(string Label, string Value, bool Good)>
        {
            ("Saving fights", settings.RecordForever ? "On" : "Off: turn it on in Settings > Recording", settings.RecordForever),
            ("WoW Forever", ForeverRecorder.ForeverRunning() ? "Open" : "Not running", ForeverRecorder.ForeverRunning()),
        };
        if (settings.RecordForever && ForeverRecorder.ForeverRunning())
            rows.Add(("OBS", recorder.Ready
                ? $"Holding the last {recorder.HeldSeconds / 60} minutes"
                : recorder.Problem ?? "Starting…", recorder.Ready));
        rows.Add(("Logs", settings.ForeverLogsDir ?? "Not found yet", settings.ForeverLogsDir != null));

        var table = string.Join("", rows.Select(r =>
            $"<tr><td>{H(r.Label)}</td><td class=\"{(r.Good ? "ok" : "no")}\">{H(r.Value)}</td></tr>"));
        var last = fights.FirstOrDefault();
        var summary = fights.Count == 0
            ? "No fights saved yet."
            : $"{fights.Count} fight{(fights.Count == 1 ? "" : "s")} saved, the last {FoughtAt(last!).ToString("ddd d MMM, HH:mm", CultureInfo.InvariantCulture)}. Pick one on the left.";
        var button = settings.RecordForever ? "" : "<p><a class=\"btn gold\" href=\"mc-app://settings\">Open Settings</a></p>";

        return Wrap("WoW Forever", "Your PvP fights", $"""
            <p class="muted">{H(summary)}</p>
            <table>{table}</table>
            {button}
            <h2>How it works</h2>
            <p>While Forever is open, OBS keeps the last few minutes of video. When a fight against another player ends, the app keeps a minute before your first hit through to a few seconds after the last one, and saves it here. Duels and world PvP both count; fights with mobs don't.</p>
            <p>WoW only writes a fight to its log once you leave combat, so a clip appears about half a minute after the fight ends.</p>
            """);
    }

    /// <summary>One player you've fought: every fight with them, newest first, each opening its clip.</summary>
    public static string Opponent(string name, IReadOnlyList<VideoEntry> fights)
    {
        var kills = fights.Count(v => v.Fight!.Killed.Contains(name));
        var deaths = fights.Count(v => v.Fight!.YouDied);
        var list = string.Join("", fights.Select(v =>
        {
            var f = v.Fight!;
            var facts = new List<string> { FoughtAt(v).ToString("ddd d MMM, HH:mm", CultureInfo.InvariantCulture) };
            if (v.Length is { } l) facts.Add(VideoLibrary.Duration(l));
            if (!string.IsNullOrEmpty(f.Zone)) facts.Add(f.Zone);
            var badge = f.Outcome is { } o ? $"<span class=\"badge {(o.Tone > 0 ? "won" : o.Tone < 0 ? "lost" : "")}\">{H(o.Text)}</span>" : "";
            return $"<a class=\"row\" href=\"mc-app://video/{Uri.EscapeDataString(v.Name)}\"><span><b>{H(f.Title)}</b><br><span class=\"muted\">{H(string.Join(" · ", facts))}</span></span>{badge}</a>";
        }));
        var tally = $"{fights.Count} fight{(fights.Count == 1 ? "" : "s")} · you killed them {kills} time{(kills == 1 ? "" : "s")} · you died {deaths} time{(deaths == 1 ? "" : "s")}";
        return Wrap("WoW Forever · player", FightFinder.Fight.Short(name), $"""
            <p class="muted">{H(tally)}</p>
            <div class="list">{list}</div>
            """);
    }

    /// <summary>When the fight began: its first hit, not the clip's start a minute before it.</summary>
    public static DateTime FoughtAt(VideoEntry v) => v.Fight?.First ?? v.Started;

    internal static string H(string s) => WebUtility.HtmlEncode(s);

    internal static string Wrap(string eyebrow, string title, string body) => $$$"""
        <!doctype html><html><head><meta charset="utf-8"><style>
        html,body{margin:0;background:#121212;color:#fff;font-family:'Segoe UI',Arial,sans-serif}
        .wrap{padding:24px 28px;max-width:860px}
        .eyebrow{color:#C8952C;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        h1{font-size:26px;font-weight:800;letter-spacing:-.02em;margin:6px 0 4px}
        h2{font-size:16px;font-weight:700;margin:28px 0 6px}
        p{font-size:14px;line-height:1.55;color:#E6E6E6;margin:8px 0}
        .muted{color:#B3B3B3;font-size:13.5px}
        table{border-collapse:collapse;margin-top:12px;font-size:14px}
        td{padding:7px 18px 7px 0;vertical-align:top}
        td:first-child{color:#B3B3B3;white-space:nowrap}
        td.ok{color:#86EFAC}td.no{color:#FCA5A5}
        .btn{display:inline-block;border-radius:999px;padding:8px 14px;font-weight:700;font-size:13px;background:#2A2A2A;color:#fff;text-decoration:none}
        .btn.gold{background:#C8952C;color:#000}.btn.gold:hover{background:#E8B84B}
        .list{margin-top:14px;display:flex;flex-direction:column;gap:6px}
        .row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 14px;background:#181818;border-radius:8px;color:#fff;text-decoration:none;font-size:14px;line-height:1.45}
        .row:hover{background:#242424}
        .badge{border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;background:#2A2A2A;color:#B3B3B3;white-space:nowrap}
        .badge.won{background:#163420;color:#86EFAC}.badge.lost{background:#3E181A;color:#FCA5A5}
        </style></head><body><div class="wrap">
        <div class="eyebrow">{{{H(eyebrow)}}}</div>
        <h1>{{{H(title)}}}</h1>
        {{{body}}}
        </div></body></html>
        """;
}

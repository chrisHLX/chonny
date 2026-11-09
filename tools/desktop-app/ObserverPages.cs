using System.Globalization;
using System.Text;
using System.Text.Json;

namespace MindCollector.Desktop;

/// <summary>
/// The Forever side's Looked at and Ask pages (2026-11-09), from the Observer addon's saved data
/// (ObserverData) and the app's own answers (AskBridge).
/// </summary>
internal static class ObserverPages
{
    private static string H(string s) => ForeverPages.H(s);
    private static readonly CultureInfo Inv = CultureInfo.InvariantCulture;

    /// <summary>Copper as WoW shows it: 12g 34s, 5s 20c, 95c.</summary>
    public static string Money(long c)
    {
        var g = c / 10000; var s = c / 100 % 100; var cp = c % 100;
        return g > 0 ? $"{g:N0}g {s}s" : s > 0 ? $"{s}s {cp}c" : $"{cp}c";
    }

    public static string When(DateTime t)
    {
        var d = DateTime.Now - t;
        if (d.TotalMinutes < 2) return "just now";
        if (d.TotalHours < 1) return $"{(int)d.TotalMinutes} min ago";
        if (t.Date == DateTime.Today) return "today " + t.ToString("HH:mm", Inv);
        if (t.Date == DateTime.Today.AddDays(-1)) return "yesterday " + t.ToString("HH:mm", Inv);
        if (d.TotalDays < 7) return $"{Math.Round(d.TotalDays)} days ago";
        return t.ToString("d MMM", Inv);
    }

    public static (string Text, int Tone)? Change(long was, long now)
    {
        if (was <= 0) return null;
        var p = (now - was) / (double)was;
        if (Math.Abs(p) < 0.005) return ("no change", 0);
        return ($"{(p > 0 ? "+" : "−")}{Math.Abs(p) * 100:F0}%", Math.Sign(p));
    }

    private static string Kind(string k) => k switch { "item" => "Item", "talent" => "Talent", "spell" => "Spell", "player" => "Player", _ => "Other" };

    // ------------------------------------------------------------------ Looked at

    public static string LookedOverview(ObserverData? data, AppSettings settings)
    {
        if (data == null)
            return ForeverPages.Wrap("WoW Forever", "What you looked at", $"""
                <p class="muted">{(settings.ForeverObserver ? "Nothing yet. The Observer addon is installed with the app; in game, hold your mouse over things for a few seconds, then /reload so WoW writes what it saw." : "The Observer addon is off. Turn it on in Settings > WoW Forever.")}</p>
                {HowItWorks}
                """);

        var items = data.Subjects.Where(s => s.Kind == "item").ToList();
        var moved = items
            .Select(s => (S: s, First: ObserverData.FirstPricedLook(s), Now: s.ItemId is { } id ? data.Latest(id) : null))
            .Where(x => x.First?.Price is > 0 && x.Now != null && x.Now.At > x.First!.At)
            .Select(x => (x.S, Was: x.First!.Price!.Value, Now: x.Now!.Min, P: (x.Now.Min - x.First.Price!.Value) / (double)x.First.Price.Value, x.First.At))
            .Where(x => Math.Abs(x.P) >= 0.10)
            .OrderByDescending(x => Math.Abs(x.P)).Take(10).ToList();

        var sb = new StringBuilder(Css);
        var counts = data.Subjects.GroupBy(s => s.Kind).ToDictionary(g => g.Key, g => g.Count());
        sb.Append($"<p class=\"muted\">As of your last /reload in game ({H(When(data.Saved))}): {counts.GetValueOrDefault("item")} items, {counts.GetValueOrDefault("talent")} talents, {counts.GetValueOrDefault("spell")} spells and {counts.GetValueOrDefault("player")} players looked at.</p>");

        sb.Append("<h2>Prices that moved since you looked</h2>");
        if (moved.Count == 0) sb.Append("<p class=\"muted\">None by 10% or more yet. It needs a newer price than the one you saw: open the item again in the auction house, or scan the whole auction house from the addon's window.</p>");
        else
        {
            sb.Append("<div class=\"list\">");
            foreach (var m in moved)
                sb.Append(Row("look/" + Uri.EscapeDataString(m.S.Key), m.S.Name,
                    $"{Money(m.Was)} on {m.At.ToString("d MMM", Inv)}, {Money(m.Now)} now · probably {m.S.LastLook?.Why ?? m.S.LastLook?.Guess ?? "looking"}",
                    Change(m.Was, m.Now)));
            sb.Append("</div>");
        }

        if (data.LastScan is { } a && data.PrevScan is { } b)
        {
            var movers = a.Prices.Where(p => p.Value.Listed >= 5 && b.Prices.TryGetValue(p.Key, out var o) && o.Min > 0)
                .Select(p => (Id: p.Key, Now: p.Value, Was: b.Prices[p.Key]))
                .Select(x => (x.Id, x.Now, x.Was, P: (x.Now.Min - x.Was.Min) / (double)x.Was.Min))
                .OrderByDescending(x => Math.Abs(x.P)).Take(15).ToList();
            sb.Append($"<h2>The whole auction house, {a.At.ToString("d MMM", Inv)} against {b.At.ToString("d MMM", Inv)}</h2>");
            sb.Append($"<p class=\"muted\">The biggest moves between your last two full scans, among items with at least 5 listed. {a.Auctions:N0} auctions in the latest.</p><div class=\"list\">");
            foreach (var m in movers)
            {
                var name = m.Now.Name ?? m.Was.Name ?? data.Subjects.FirstOrDefault(s => s.ItemId == m.Id)?.Name ?? $"Item {m.Id}";
                var looked = data.Subjects.Any(s => s.ItemId == m.Id) ? " · you looked at this" : "";
                sb.Append(Row(null, name, $"{Money(m.Was.Min)} to {Money(m.Now.Min)} · {m.Now.Listed} listed{looked}", Change(m.Was.Min, m.Now.Min)));
            }
            sb.Append("</div>");
        }
        else sb.Append("<h2>The whole auction house</h2><p class=\"muted\">Scan it twice, on different days, from the addon's window (Scan auction house, or /mco scan), and the biggest moves show here.</p>");

        sb.Append(HowItWorks);
        return ForeverPages.Wrap("WoW Forever", "What you looked at", sb.ToString());
    }

    private const string HowItWorks = """
        <h2>How it works</h2>
        <p>The Observer addon counts a look when you hold your mouse over something for a few seconds, or open an item in the auction house. It keeps where you were, the price it saw and how many you had, and guesses why you were looking from your professions and Forever's recipes. The guess is only a guess: in game, the addon's window (/mco) lets you say the real reason, and it keeps both.</p>
        <p>WoW only writes an addon's data at a /reload or when you log out, so these pages are as fresh as your last one.</p>
        """;

    public static string Subject(ObserverData data, ObserverData.Subject s)
    {
        var sb = new StringBuilder();
        var facts = new List<string> { Kind(s.Kind), $"{s.Looks} look{(s.Looks == 1 ? "" : "s")}, {Math.Round(s.Seconds)}s in all", $"first {s.First.ToString("d MMM HH:mm", Inv)}", $"last {When(s.Last)}" };
        if (s.Kind == "player" && s.Level is { } lv) facts.Insert(1, $"level {lv} {s.Class?.ToLowerInvariant()}");
        sb.Append($"<p class=\"muted\">{H(string.Join(" · ", facts))}</p>");

        var last = s.LastLook;
        if (last != null)
            sb.Append(last.Why != null
                ? $"<p><b>You said:</b> {H(last.Why)} <span class=\"muted\">(the guess was: {H(last.Guess ?? "none")})</span></p>"
                : $"<p><b>Probably:</b> {H(last.Guess ?? "looking")}</p>");

        if (s.ItemId is { } id)
        {
            var first = ObserverData.FirstPricedLook(s);
            var now = data.Latest(id);
            var rows = new List<(string, string, bool)>();
            if (first?.Price is { } p0) rows.Add(("When you first looked", $"{Money(p0)} on {first.At.ToString("d MMM", Inv)}", true));
            if (now != null) rows.Add(("Latest price", $"{Money(now.Min)}{(now.Listed is { } q ? $", {q:N0} listed" : "")} ({When(now.At)})", true));
            if (first?.Price is { } a && now != null && Change(a, now.Min) is { } c) rows.Add(("Change", c.Text, c.Tone >= 0));
            if (rows.Count > 0)
                sb.Append("<table>" + string.Join("", rows.Select(r => $"<tr><td>{H(r.Item1)}</td><td>{H(r.Item2)}</td></tr>")) + "</table>");
            else sb.Append("<p class=\"muted\">No auction price seen yet: open it in the auction house in game.</p>");

            var history = data.Prices.TryGetValue(id, out var h) ? h.ToList() : [];
            if (now != null && (history.Count == 0 || now.At > history[^1].At)) history.Add(now);
            sb.Append(PriceChart(history, s.Events.Where(e => e.Price is > 0).ToList()));
        }
        if (s.Kind == "talent")
            sb.Append($"<p>{(s.TakenAt is { } ta ? $"Taken {ta.ToString("d MMM", Inv)}, {Math.Max(0, (int)(ta - s.First).TotalDays)} days after you first read it." : s.HadIt ? "You had it when you looked." : "Not taken.")}</p>");

        if (s.Notes.Count > 0)
        {
            sb.Append("<h2>What changed since</h2><div class=\"list\">");
            foreach (var n in s.Notes.AsEnumerable().Reverse()) sb.Append($"<div class=\"row\"><span>{H(n.Text)}</span><span class=\"muted\">{H(n.At.ToString("d MMM", Inv))}</span></div>");
            sb.Append("</div>");
        }

        sb.Append("<h2>Each look</h2><table class=\"looks\"><tr><th>When</th><th>Where</th><th>For</th><th>Price</th><th>Had</th><th>Why</th></tr>");
        foreach (var e in s.Events.AsEnumerable().Reverse())
            sb.Append($"<tr><td>{H(e.At.ToString("d MMM HH:mm", Inv))}</td><td>{H(e.Place)}</td><td>{(e.Seconds > 0 ? $"{e.Seconds}s" : "opened")}</td>" +
                      $"<td>{(e.Price is > 0 ? H(Money(e.Price.Value)) : "")}</td><td>{(e.Had is > 0 ? e.Had.ToString() : "")}</td>" +
                      $"<td>{(e.Why != null ? "<b>you:</b> " + H(e.Why) : "<span class=\"muted\">" + H(e.Guess ?? "") + "</span>")}</td></tr>");
        sb.Append("</table>");
        return ForeverPages.Wrap("WoW Forever · " + Kind(s.Kind).ToLowerInvariant(), s.Name, Css + sb);
    }

    /// <summary>
    /// The item's auction price over time: one gold line, a dot on each look that saw a price, a
    /// crosshair with the day's price on hover, and the same numbers in a table below. With fewer
    /// than two prices there is no line to draw, so it says so instead.
    /// </summary>
    private static string PriceChart(List<ObserverData.PricePoint> prices, List<ObserverData.Look> looks)
    {
        if (prices.Count < 2)
            return "<p class=\"muted\">One price so far. The chart starts once the addon has seen it on two days.</p>";
        const int W = 680, Hh = 200, L = 64, R = 16, T = 14, B = 28;
        var t0 = prices[0].At; var t1 = prices[^1].At;
        var span = Math.Max(1, (t1 - t0).TotalSeconds);
        var max = prices.Max(p => p.Min) * 1.08; var min = Math.Max(0, prices.Min(p => p.Min) * 0.9);
        if (max - min < 1) max = min + 1;
        double X(DateTime t) => L + (t - t0).TotalSeconds / span * (W - L - R);
        double Y(double v) => T + (1 - (v - min) / (max - min)) * (Hh - T - B);
        string F(double v) => v.ToString("F1", Inv);

        var line = string.Join(" ", prices.Select(p => $"{F(X(p.At))},{F(Y(p.Min))}"));
        var area = $"{F(X(t0))},{F(Y(min))} {line} {F(X(t1))},{F(Y(min))}";
        var grid = new StringBuilder();
        for (var i = 0; i <= 2; i++)
        {
            var v = min + (max - min) * i / 2;
            grid.Append($"<line x1=\"{L}\" x2=\"{W - R}\" y1=\"{F(Y(v))}\" y2=\"{F(Y(v))}\" class=\"grid\"/><text x=\"{L - 8}\" y=\"{F(Y(v) + 4)}\" class=\"axis\" text-anchor=\"end\">{H(Money((long)v))}</text>");
        }
        grid.Append($"<text x=\"{L}\" y=\"{Hh - 8}\" class=\"axis\">{H(t0.ToString("d MMM", Inv))}</text><text x=\"{W - R}\" y=\"{Hh - 8}\" class=\"axis\" text-anchor=\"end\">{H(t1.ToString("d MMM", Inv))}</text>");
        var dots = string.Join("", looks.Where(e => e.At >= t0 && e.At <= t1).Select(e =>
            $"<circle cx=\"{F(X(e.At))}\" cy=\"{F(Y(e.Price!.Value))}\" r=\"5\" class=\"look\"/>"));
        var json = JsonSerializer.Serialize(prices.Select(p => new { x = Math.Round(X(p.At), 1), y = Math.Round(Y(p.Min), 1), d = p.At.ToString("d MMM HH:mm", Inv), v = Money(p.Min), q = p.Listed }));
        var table = string.Join("", prices.AsEnumerable().Reverse().Select(p => $"<tr><td>{H(p.At.ToString("d MMM HH:mm", Inv))}</td><td>{H(Money(p.Min))}</td><td>{(p.Listed is { } q ? q.ToString("N0", Inv) : "")}</td></tr>"));
        return $$"""
            <h2>Price since you started looking</h2>
            <p class="muted">The cheapest listing each day the addon saw it. Gold dots are your looks.</p>
            <div class="chart"><svg id="pc" viewBox="0 0 {{W}} {{Hh}}" width="100%">
              {{grid}}
              <polygon points="{{area}}" class="wash"/>
              <polyline points="{{line}}" class="pl"/>
              {{dots}}
              <line id="cx" y1="{{T}}" y2="{{Hh - B}}" class="cross" visibility="hidden"/>
              <rect x="{{L}}" y="0" width="{{W - L - R}}" height="{{Hh}}" fill="transparent" id="hit"/>
            </svg><div id="tip" class="tip"></div></div>
            <details><summary>Every price seen</summary><table class="looks"><tr><th>When</th><th>Cheapest</th><th>Listed</th></tr>{{table}}</table></details>
            <script>
            (function(){
              var pts={{json}}, svg=document.getElementById('pc'), cx=document.getElementById('cx'), tip=document.getElementById('tip');
              document.getElementById('hit').addEventListener('pointermove', function(ev){
                var r=svg.getBoundingClientRect(), x=(ev.clientX-r.left)/r.width*{{W}}, best=pts[0];
                pts.forEach(function(p){ if(Math.abs(p.x-x)<Math.abs(best.x-x)) best=p; });
                cx.setAttribute('x1',best.x); cx.setAttribute('x2',best.x); cx.setAttribute('visibility','visible');
                tip.textContent=''; var b=document.createElement('b'); b.textContent=best.v; tip.appendChild(b);
                tip.appendChild(document.createTextNode('  '+best.d+(best.q?' · '+best.q+' listed':'')));
                tip.style.left=(best.x/{{W}}*r.width)+'px'; tip.style.display='block';
              });
              document.getElementById('hit').addEventListener('pointerleave', function(){ cx.setAttribute('visibility','hidden'); tip.style.display='none'; });
            })();
            </script>
            """;
    }

    private const string Css = """
        <style>
        .chart{position:relative;margin-top:8px;max-width:680px}
        svg .grid{stroke:#2A2A2A;stroke-width:1}
        svg .axis{fill:#8A8A8A;font-size:11px;font-family:'Segoe UI',Arial,sans-serif}
        svg .pl{fill:none;stroke:#C8952C;stroke-width:2;stroke-linejoin:round;stroke-linecap:round}
        svg .wash{fill:#C8952C;opacity:.10}
        svg .look{fill:#C8952C;stroke:#121212;stroke-width:2}
        svg .cross{stroke:#6A6A6A;stroke-width:1}
        .tip{display:none;position:absolute;top:-6px;transform:translateX(-50%);background:#242424;color:#E6E6E6;padding:5px 9px;border-radius:6px;font-size:12.5px;white-space:nowrap;pointer-events:none}
        .tip b{color:#fff}
        table.looks{width:100%;max-width:820px}
        table.looks th{text-align:left;color:#8A8A8A;font-weight:600;font-size:12.5px;padding:6px 14px 6px 0}
        table.looks td{font-size:13.5px;padding:6px 14px 6px 0;border-top:1px solid #222}
        table.looks td:first-child{color:#E6E6E6}
        details{margin-top:10px;font-size:13.5px;color:#B3B3B3}
        summary{cursor:pointer}
        .badge.up{background:#163420;color:#86EFAC}.badge.down{background:#3E181A;color:#FCA5A5}
        input.ask{width:100%;max-width:640px;box-sizing:border-box;background:#1E1E1E;border:1px solid #333;color:#fff;border-radius:8px;padding:10px 12px;font-size:14px;font-family:inherit}
        button.btn{border:0;cursor:pointer;font-family:inherit;margin-top:10px}
        </style>
        """;

    private static string Row(string? link, string title, string sub, (string Text, int Tone)? badge)
    {
        var b = badge is { } x ? $"<span class=\"badge {(x.Tone > 0 ? "up" : x.Tone < 0 ? "down" : "")}\">{H(x.Text)}</span>" : "";
        var inner = $"<span><b>{H(title)}</b><br><span class=\"muted\">{H(sub)}</span></span>{b}";
        return link != null ? $"<a class=\"row\" href=\"mc-app://{link}\">{inner}</a>" : $"<div class=\"row\">{inner}</div>";
    }

    // ------------------------------------------------------------------ Ask

    public static string AskOverview(AskBridge bridge, ObserverData? data)
    {
        var problem = bridge.CheckReady();
        var sb = new StringBuilder(Css);
        sb.Append(problem != null
            ? $"<p class=\"muted\">{H(problem)}</p><p><a class=\"btn gold\" href=\"mc-app://settings\">Open Settings</a></p>"
            : bridge.Working > 0 ? "<p class=\"muted\">Working on a question now. Answers usually take a minute or two.</p>"
            : "<p class=\"muted\">Ready. Ask here, or with /ask in game.</p>");
        if (problem == null)
            sb.Append("""
                <input class="ask" id="q" maxlength="200" placeholder="where are the bears in Ashenvale? mark them on the map" autofocus>
                <br><button class="btn gold" id="go">Ask</button> <span class="muted" id="sent"></span>
                <script>
                (function(){
                  var q=document.getElementById('q');
                  function send(){ var t=q.value.trim(); if(!t) return; window.chrome.webview.postMessage({type:'ask', text:t}); q.value=''; document.getElementById('sent').textContent='Sent. The answer shows on the left in a minute or two.'; }
                  document.getElementById('go').addEventListener('click', send);
                  q.addEventListener('keydown', function(e){ if(e.key==='Enter') send(); });
                })();
                </script>
                """);
        var waiting = data?.Asks.Where(a => a.Status != "answered" && DateTime.Now - a.At < TimeSpan.FromHours(2) && bridge.Answers().All(x => x.Id != a.Id)).ToList() ?? [];
        if (waiting.Count > 0)
            sb.Append("<h2>Asked in game, not answered yet</h2><div class=\"list\">" + string.Join("", waiting.Select(a => Row(null, a.Question, $"asked {When(a.At)}", null))) + "</div>");
        sb.Append("""
            <h2>How it works</h2>
            <p>Claude looks it up on the web and on Wowhead's Forever pages, in your Chrome, and answers in a sentence or three. When it's about where something is, it reads every spawn point from Wowhead's map and sends them to the game: the next /reload in WoW puts them on your world map, with a waypoint on the nearest.</p>
            <p>Asking in game with /ask reloads the UI to send the question, since an addon can't reach anything outside the game while it runs. Each question uses your Claude account.</p>
            """);
        return ForeverPages.Wrap("WoW Forever", "Ask Claude", sb.ToString());
    }

    public static string Answer(AskBridge.Answer a)
    {
        var asked = DateTimeOffset.FromUnixTimeSeconds(a.Asked).LocalDateTime;
        var sb = new StringBuilder(Css);
        var facts = new List<string> { $"asked {When(asked)}", a.FromApp ? "from the app" : "in game" + (a.Zone != null ? $", in {a.Zone}" : "") };
        if (a.Cost is { } c) facts.Add($"${c:F2}");
        sb.Append($"<p class=\"muted\">{H(string.Join(" · ", facts))}</p><p style=\"font-size:15px\">{H(a.Text)}</p>");
        if (a.Points.Count > 0)
        {
            var zones = a.Points.GroupBy(p => p.Zone).Select(g => $"{g.Count()} in {g.Key}");
            sb.Append($"<h2>{a.Points.Count} point{(a.Points.Count == 1 ? "" : "s")} for the map</h2><p class=\"muted\">{H(string.Join(", ", zones))}. Type /reload in WoW to see them on your world map, with a waypoint on the nearest; /mco clear takes them off.</p>");
            sb.Append("<details><summary>Every point</summary><table class=\"looks\"><tr><th>Zone</th><th>Where</th><th>What</th></tr>" +
                      string.Join("", a.Points.Select(p => $"<tr><td>{H(p.Zone)}</td><td>{p.X.ToString("F1", Inv)}, {p.Y.ToString("F1", Inv)}</td><td>{H(p.Label)}</td></tr>")) + "</table></details>");
        }
        return ForeverPages.Wrap("WoW Forever · Ask", a.Question, sb.ToString());
    }
}

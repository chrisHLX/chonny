using System.Globalization;
using System.Text;
using System.Text.Json;

namespace MindCollector.Desktop;

/// <summary>
/// Shares the player's WoW Forever auction house scans and brings everyone's prices back (2026-11-12,
/// the website's ForeverMarketService).
///
/// The Observer addon keeps its last two full scans in its saved data, which WoW writes at a /reload
/// or logout. When that file changes, each scan newer than the last one sent goes to
/// POST /api/forever/scan: per item the cheapest unit price, how many are listed and how many sit in
/// each time-left band, with the realm, region and faction it was scanned on. Nothing else in the file
/// is read for this: not what the player looked at, their ledger, their questions or their position.
/// Then GET /api/forever/market gives the pooled view of that market, written into the addon as
/// Market.lua for the next /reload. Prices come back whether or not sharing is on (Settings). From 0.6.40
/// Market.lua also carries day by day prices for the items the player tracks or has looked at
/// (/api/forever/history), and `itemsSent`, so the addon can let its copy of a sent sweep go. The scan
/// before last and the goblin house's may be packed into one string (SavedVariables.ScanPrices).
///
/// Also, from 0.6.39 (2026-11-27), what the addon read about weapons, armour and vendors for the gear
/// page (Gear.lua, ForeverGearService): a finished /mco items sweep, once per sweep, in parts of 4,000
/// items (nginx takes 1 MB; a whole sweep is about 500 KB gzipped); the suffix versions it has the
/// stats of; and each vendor list newer than the last sent. A scan carries its suffix versions' prices.
/// Sent only when sharing is on.
///
/// Runs on its own timer and never on the window's thread or the recorder's; every failure is logged
/// and tried again on a later tick.
/// </summary>
public sealed class MarketShare : IDisposable
{
    private static readonly TimeSpan FetchEvery = TimeSpan.FromMinutes(30);
    private static readonly string StateFile = Path.Combine(AppSettings.Dir, "market.json");

    private readonly AppSettings _settings;
    private readonly Action<string> _log;
    private System.Threading.Timer? _timer;
    private int _busy;
    private DateTime _seenFile;
    /// <summary>The items the player tracks or has prices kept for, whose day by day history Market.lua carries.</summary>
    private List<long> _historyIds = [];

    /// <summary>What has been sent and fetched, in its own file so it never races the settings.</summary>
    private sealed class State
    {
        public long LastSent { get; set; }
        public string? Region { get; set; }
        public string? Realm { get; set; }
        public string? Faction { get; set; }
        public DateTime Fetched { get; set; }
        /// <summary>The last fetch tried, worked or not, so a failing one waits FetchEvery (2026-11-26: it
        /// was retried every 30-second tick, about 1,500 refused requests a day).</summary>
        public DateTime Tried { get; set; }
        /// <summary>The item sweep last sent, as "build:when it finished".</summary>
        public string? ItemsSent { get; set; }
        /// <summary>How many suffix versions were sent; more in the file means some are new.</summary>
        public int VariantsSent { get; set; }
        /// <summary>The newest vendor list sent.</summary>
        public long VendorsSent { get; set; }
    }

    public MarketShare(AppSettings settings, Action<string> log)
    {
        _settings = settings;
        _log = log;
    }

    public void Start() => _timer = new System.Threading.Timer(_ => Tick(), null, TimeSpan.FromSeconds(20), TimeSpan.FromSeconds(30));

    public void Dispose() => _timer?.Dispose();

    private void Tick()
    {
        if (Interlocked.Exchange(ref _busy, 1) == 1) return;
        _ = Task.Run(async () =>
        {
            try { await RunAsync(CancellationToken.None); }
            catch (ApiClient.KeyRejectedException) { }
            catch (Exception e) { _log($"Shared prices: {e.Message}"); }
            finally { Interlocked.Exchange(ref _busy, 0); }
        });
    }

    private async Task RunAsync(CancellationToken ct)
    {
        if (string.IsNullOrEmpty(_settings.Key)) return;
        var logs = _settings.ForeverLogsDir ?? AppSettings.DetectForeverLogs(_settings.WowLogsDir);
        var state = LoadState();
        var sent = false;

        var file = ObserverData.FindFile(logs);
        if (file != null)
        {
            var at = File.GetLastWriteTimeUtc(file);
            if (at != _seenFile && DateTime.UtcNow - at > TimeSpan.FromSeconds(1)
                && SavedVariables.Read(file).GetValueOrDefault("MindCollectorObserverDB") is Dictionary<object, object?> db)
            {
                _seenFile = at;
                _historyIds = HistoryIds(db);
                sent = await SendNewScansAsync(db, state, ct);
                // a sweep just sent: fetch now, so Market.lua tells the addon it can let its copy go
                if (_settings.ShareScans && await SendGearAsync(db, state, ct)) sent = true;
            }
        }

        // A market is region and faction; the realm is sent but the website ignores it (2026-11-26).
        if (!string.IsNullOrEmpty(state.Region) && !string.IsNullOrEmpty(state.Faction) && (sent || DateTime.UtcNow - state.Tried > FetchEvery))
            await FetchAsync(logs, state, ct);
        SaveState(state);
    }

    private async Task<bool> SendNewScansAsync(Dictionary<object, object?> db, State state, CancellationToken ct)
    {
        var sent = false;
        using var api = new ApiClient(_settings.ServerUrl, _settings.Key!);
        // Your faction's last two scans, and the latest of any other house (the neutral goblin one in
        // Booty Bay, Gadgetzan or Everlook), which the addon keeps apart and which is its own market.
        var scans = new List<Dictionary<object, object?>>();
        foreach (var key in new[] { "prevScan", "lastScan" })
            if (db.GetValueOrDefault(key) is Dictionary<object, object?> s) scans.Add(s);
        if (db.GetValueOrDefault("otherScans") is Dictionary<object, object?> others)
            scans.AddRange(others.Values.OfType<Dictionary<object, object?>>());

        // oldest first, so a failure part way leaves the newer ones for next time
        foreach (var scan in scans.OrderBy(s => AsLong(s.GetValueOrDefault("t")) ?? 0))
        {
            var t = AsLong(scan.GetValueOrDefault("t")) ?? 0;
            // scans from before the addon recorded where they were can't be placed in a market. The realm
            // isn't needed: Forever has one auction house per region and faction, and from 22 Nov the game
            // reports no realm (2026-11-26).
            if (t <= state.LastSent || scan.GetValueOrDefault("region") is not string { Length: > 0 } region
                || scan.GetValueOrDefault("faction") is not string { Length: > 0 } faction) continue;
            var realm = scan.GetValueOrDefault("realm") as string ?? "";
            // the prices to bring back are always your own faction's house, whichever house this was
            state.Region = region;
            if (realm != "") state.Realm = realm;
            state.Faction = scan.GetValueOrDefault("side") as string ?? state.Faction ?? faction;
            if (!_settings.ShareScans) { state.LastSent = t; continue; }

            var (json, items) = ScanJson(scan, region, realm, faction, t);
            var (status, reason) = await api.ShareScanAsync(json, ct);
            if (status is "stored" or "duplicate")
            {
                state.LastSent = t;
                sent = true;
                if (status == "stored") _log($"Shared your scan of the {faction} auction house ({items:N0} items).");
            }
            else if (status == "rejected")
            {
                state.LastSent = t;
                _log($"The website didn't take your scan of the {faction} auction house: {reason}");
            }
            else
            {
                _log($"Couldn't share your auction house scan yet ({reason ?? status}); trying again later.");
                break;
            }
        }
        return sent;
    }

    /// <summary>
    /// One scan as the website takes it: {region, realm, faction, t, n, items: [[id, min, listed, e0, e1, e2, e3]],
    /// variants: [[id, bonus, min, listed]]}, the e's how many of `listed` sit in each time-left band, shortest first,
    /// and the variants a weapon or armour piece's suffix versions, by the bonus numbers on their links (0.6.39). The addon keeps a band by
    /// whatever value the game gave it, and its `bands` table says where each starts; sorting by that
    /// puts them in order whichever way the game counts.
    /// </summary>
    internal static (string Json, int Items) ScanJson(Dictionary<object, object?> scan, string region, string realm, string faction, long t)
    {
        var order = new Dictionary<object, int>();
        if (scan.GetValueOrDefault("bands") is Dictionary<object, object?> bands)
        {
            var sorted = bands
                .Select(b => (Key: b.Key, Start: SavedVariables.List(b.Value) is { Count: > 0 } l ? AsLong(l[0]) ?? 0 : 0))
                .OrderBy(b => b.Start).ToList();
            for (var i = 0; i < sorted.Count && i < 4; i++) order[sorted[i].Key] = i;
        }

        var items = new List<long[]>();
        var variants = new List<object[]>();
        if (SavedVariables.ScanPrices(scan) is { } prices)
            foreach (var (k, v) in prices)
            {
                var arr = SavedVariables.List(v);
                if (AsLong(k) is not { } id || arr.Count < 2 || AsDouble(arr[0]) is not { } min || min <= 0 || AsLong(arr[1]) is not { } listed || listed <= 0) continue;
                if (v is Dictionary<object, object?> withV && withV.GetValueOrDefault("v") is Dictionary<object, object?> vs)
                    foreach (var (bonus, pair) in vs)
                    {
                        var p = SavedVariables.List(pair);
                        if (bonus is string b && p.Count >= 2 && AsDouble(p[0]) is { } vmin && vmin > 0 && AsLong(p[1]) is { } vq && vq > 0)
                            variants.Add([id, b, (long)Math.Round(vmin), vq]);
                    }
                var row = new List<long> { id, (long)Math.Round(min), listed };
                if (order.Count == 4 && v is Dictionary<object, object?> d && d.GetValueOrDefault("tl") is Dictionary<object, object?> tl)
                {
                    var e = new long[4];
                    foreach (var (band, q) in tl)
                        if (order.TryGetValue(band, out var i) && AsLong(q) is { } n) e[i] += n;
                    row.AddRange(e);
                }
                items.Add([.. row]);
            }

        var json = JsonSerializer.Serialize(new
        {
            region, realm, faction, t,
            n = AsLong(scan.GetValueOrDefault("n")) ?? 0,
            items,
            variants,
        });
        return (json, items.Count);
    }

    /// <summary>
    /// The item sweep, suffix versions and vendor lists the addon kept (Gear.lua), each sent once. True when
    /// the sweep itself went: Market.lua then says so (`itemsSent`), and the addon keeps only its counts.
    /// </summary>
    private async Task<bool> SendGearAsync(Dictionary<object, object?> db, State state, CancellationToken ct)
    {
        using var api = new ApiClient(_settings.ServerUrl, _settings.Key!);
        var sweepSent = false;

        var sweep = db.GetValueOrDefault("items") as Dictionary<object, object?>;
        var build = sweep?.GetValueOrDefault("build") as string;
        var done = AsLong(sweep?.GetValueOrDefault("done"));
        // no `got` once the addon has let its copy go (0.6.40): it was sent
        if (sweep != null && build != null && done != null && sweep.GetValueOrDefault("got") is Dictionary<object, object?> && state.ItemsSent != $"{build}:{done}")
        {
            var parts = ItemsJson(sweep, build);
            var ok = true;
            foreach (var part in parts)
            {
                var (status, reason) = await api.ShareGearAsync("items", part, ct);
                if (status != "stored")
                {
                    _log($"Couldn't send the game's weapons and armour to the website yet ({reason ?? status}); trying again later.");
                    ok = false;
                    break;
                }
            }
            if (ok)
            {
                state.ItemsSent = $"{build}:{done}";
                sweepSent = true;
                _log($"Sent the game's weapons and armour to the website ({(sweep.GetValueOrDefault("got") as Dictionary<object, object?>)?.Count ?? 0:N0} items).");
            }
        }

        if (db.GetValueOrDefault("variants") is Dictionary<object, object?> variants && variants.Count != state.VariantsSent && build != null)
        {
            var json = JsonSerializer.Serialize(new
            {
                build,
                items = new Dictionary<string, object?>(),
                variants = variants.Where(v => v.Key is string).ToDictionary(v => (string)v.Key, v =>
                {
                    var p = SavedVariables.List(v.Value);
                    return (object?)new object?[] { p.Count > 0 ? p[0] : null, p.Count > 1 ? Plain(p[1]) : null };
                }),
            });
            var (status, _) = await api.ShareGearAsync("items", json, ct);
            if (status == "stored") state.VariantsSent = variants.Count;
        }

        if (db.GetValueOrDefault("vendorLists") is Dictionary<object, object?> lists)
        {
            var fresh = lists
                .Select(l => (Npc: AsLong(l.Key), List: l.Value as Dictionary<object, object?>))
                .Where(l => l.Npc != null && l.List != null && (AsLong(l.List.GetValueOrDefault("t")) ?? 0) > state.VendorsSent)
                .ToList();
            if (fresh.Count > 0)
            {
                var json = JsonSerializer.Serialize(new
                {
                    vendors = fresh.Select(l => new Dictionary<string, object?>
                    {
                        ["npc"] = l.Npc, ["name"] = l.List!.GetValueOrDefault("name"), ["zone"] = l.List.GetValueOrDefault("zone"),
                        ["sub"] = l.List.GetValueOrDefault("sub"), ["mapID"] = l.List.GetValueOrDefault("mapID"),
                        ["x"] = l.List.GetValueOrDefault("x"), ["y"] = l.List.GetValueOrDefault("y"), ["t"] = l.List.GetValueOrDefault("t"),
                        ["items"] = SavedVariables.List(l.List.GetValueOrDefault("items")).Select(i => Positions(i, 6)).ToList(),
                    }).ToList(),
                });
                var (status, reason) = await api.ShareGearAsync("vendors", json, ct);
                if (status == "stored")
                {
                    state.VendorsSent = fresh.Max(l => AsLong(l.List!.GetValueOrDefault("t")) ?? 0);
                    _log($"Sent {fresh.Count} vendor list{(fresh.Count == 1 ? "" : "s")} to the website.");
                }
                else _log($"Couldn't send vendor lists to the website yet ({reason ?? status}); trying again later.");
            }
        }
        return sweepSent;
    }

    /// <summary>
    /// The items whose day by day prices to bring back: the ones tracked, then the ones the addon keeps
    /// prices for (looked at), at most ForeverMarketService::HISTORY_IDS (400).
    /// </summary>
    internal static List<long> HistoryIds(Dictionary<object, object?> db)
    {
        var ids = new List<long>();
        foreach (var key in new[] { "tracked", "prices" })
            if (db.GetValueOrDefault(key) is Dictionary<object, object?> d)
                foreach (var k in d.Keys)
                    if (AsLong(k) is { } id && id > 0 && !ids.Contains(id)) ids.Add(id);
        return ids.Take(400).ToList();
    }

    /// <summary>
    /// A sweep as the website takes it, in parts of 4,000 items: {build, items: {id: [name, quality, item level,
    /// required level, slot, class, subclass, bind, sell price, stats, lines, damage]}}, the refusals (`gone`) in the last.
    /// </summary>
    internal static List<string> ItemsJson(Dictionary<object, object?> sweep, string build)
    {
        var got = (sweep.GetValueOrDefault("got") as Dictionary<object, object?> ?? [])
            .Where(i => AsLong(i.Key) != null)
            .Select(i => (Id: AsLong(i.Key)!.Value.ToString(CultureInfo.InvariantCulture), Row: Positions(i.Value, 12)))
            .ToList();
        var gone = (sweep.GetValueOrDefault("gone") as Dictionary<object, object?> ?? [])
            .Select(g => AsLong(g.Key)).Where(g => g != null).ToList();
        var parts = new List<string>();
        for (var at = 0; at < got.Count || parts.Count == 0; at += 4000)
        {
            var chunk = got.Skip(at).Take(4000).ToDictionary(i => i.Id, i => (object?)i.Row);
            var last = at + 4000 >= got.Count;
            parts.Add(JsonSerializer.Serialize(new Dictionary<string, object?>
            {
                ["build"] = build, ["items"] = chunk, ["gone"] = last ? gone : new List<long?>(),
            }));
        }
        return parts;
    }

    /// <summary>A Lua array with holes (nil stats, say) as a JSON array of a fixed length, nulls in the holes.</summary>
    private static object?[] Positions(object? v, int n)
    {
        var d = v as Dictionary<object, object?>;
        var row = new object?[n];
        for (var k = 0; k < n; k++) row[k] = d != null && d.TryGetValue((long)(k + 1), out var x) ? Plain(x) : null;
        return row;
    }

    /// <summary>A Lua value as plain JSON: a table with keys 1..n becomes a list, any other table an object.</summary>
    private static object? Plain(object? v)
    {
        if (v is not Dictionary<object, object?> d) return v;
        if (d.Count > 0 && d.Keys.All(k => k is long)) return Enumerable.Range(1, d.Count).Select(i => d.TryGetValue((long)i, out var x) ? Plain(x) : null).ToList();
        return d.ToDictionary(e => Convert.ToString(e.Key, CultureInfo.InvariantCulture)!, e => Plain(e.Value));
    }

    private async Task FetchAsync(string? logs, State state, CancellationToken ct)
    {
        var addon = AddonFolder(logs);
        if (addon == null) return;
        state.Tried = DateTime.UtcNow;
        using var api = new ApiClient(_settings.ServerUrl, _settings.Key!);
        using var doc = await api.MarketAsync(state.Region!, state.Realm ?? "", state.Faction!, ct);
        if (doc == null) return;
        var root = doc.RootElement;
        if (!root.TryGetProperty("items", out var items) || items.ValueKind != JsonValueKind.Object) return;

        // the day by day history; the prices are written without it if it can't be had
        JsonDocument? history = null;
        if (_historyIds.Count > 0)
            try { history = await api.HistoryAsync(state.Region!, state.Faction!, _historyIds, ct); }
            catch (ApiClient.KeyRejectedException) { throw; }
            catch (Exception e) { _log($"Couldn't fetch price history yet ({e.Message})."); }
        using var historyDoc = history;
        var lua = MarketLua(root, history?.RootElement, state.ItemsSent);
        var path = Path.Combine(addon, "Market.lua");
        File.WriteAllText(path + ".tmp", lua, new UTF8Encoding(false));
        File.Move(path + ".tmp", path, overwrite: true);
        var first = state.Fetched == default;
        state.Fetched = DateTime.UtcNow;
        var count = items.EnumerateObject().Count();
        var players = root.TryGetProperty("players", out var p) && p.ValueKind == JsonValueKind.Number ? p.GetInt32() : 0;
        if (first || count > 0)
            _log($"Everyone's {state.Faction} auction prices: {count:N0} items from {players} player{(players == 1 ? "" : "s")}. /reload in Forever to load them.");
    }

    /// <summary>The pooled view as the addon reads it (tools/wow-addon/MindCollectorObserver/Market.lua).</summary>
    internal static string MarketLua(JsonElement root, JsonElement? history = null, string? itemsSent = null)
    {
        static string Num(JsonElement e) => e.ValueKind == JsonValueKind.Number ? e.GetRawText() : "nil";
        static string Str(string s) => "\"" + s.Replace("\\", "\\\\").Replace("\"", "\\\"").Replace("\n", " ") + "\"";

        var sb = new StringBuilder();
        sb.Append("-- Written by the MindCollector app ").Append(DateTime.Now.ToString("yyyy-MM-dd HH:mm", CultureInfo.InvariantCulture))
          .Append(": auction house prices pooled from every player who shares their scans.\n");
        sb.Append("-- Each item: { t, min, listed, prev t, prev min, prev listed, prev listed by time left (4), lowest in 7 days, scans, usual (median over 7 days), readings }\n");
        sb.Append("MindCollectorObserverMarket = {\n");
        sb.Append("  market = ").Append(Str(root.TryGetProperty("market", out var m) ? m.GetString() ?? "" : "")).Append(",\n");
        sb.Append("  at = ").Append(DateTimeOffset.UtcNow.ToUnixTimeSeconds()).Append(",\n");
        sb.Append("  t = ").Append(root.TryGetProperty("t", out var t) ? Num(t) : "nil").Append(",\n");
        sb.Append("  players = ").Append(root.TryGetProperty("players", out var p) ? Num(p) : "0").Append(",\n");
        // the item sweep the website has, as "build:when it finished": the addon then keeps only its counts
        if (!string.IsNullOrEmpty(itemsSent)) sb.Append("  itemsSent = ").Append(Str(itemsSent)).Append(",\n");
        // day by day prices kept for good (ForeverMarketService::history), one string per item, oldest first:
        // ";yyyymmdd:cheapest:usual:listed:readings;..." (a string costs the addon its length, a table about five times)
        if (history is { } h && h.TryGetProperty("items", out var hi) && hi.ValueKind == JsonValueKind.Object)
        {
            sb.Append("  history = {\n");
            foreach (var item in hi.EnumerateObject())
            {
                if (!long.TryParse(item.Name, out var hid) || item.Value.ValueKind != JsonValueKind.Array) continue;
                var days = item.Value.EnumerateArray()
                    .Where(d => d.ValueKind == JsonValueKind.Array && d.GetArrayLength() == 5 && d.EnumerateArray().All(x => x.ValueKind == JsonValueKind.Number))
                    .Select(d => string.Join(":", d.EnumerateArray().Select(x => x.GetRawText())))
                    .ToList();
                if (days.Count > 0) sb.Append("    [").Append(hid).Append("] = \";").Append(string.Join(";", days)).Append(";\",\n");
            }
            sb.Append("  },\n");
        }
        sb.Append("  items = {\n");
        var items = root.TryGetProperty("items", out var it) && it.ValueKind == JsonValueKind.Object
            ? it.EnumerateObject().ToList() : [];
        foreach (var item in items)
        {
            if (!long.TryParse(item.Name, out var id) || item.Value.ValueKind != JsonValueKind.Array) continue;
            sb.Append("    [").Append(id).Append("] = {").Append(string.Join(", ", item.Value.EnumerateArray().Select(Num))).Append("},\n");
        }
        sb.Append("  },\n}\n");
        return sb.ToString();
    }

    /// <summary>The Observer addon's folder in this Forever install, or null when it isn't installed.</summary>
    private static string? AddonFolder(string? logs)
    {
        var game = logs == null ? null : Directory.GetParent(logs.TrimEnd('\\', '/'))?.FullName;
        if (game == null) return null;
        var dir = Path.Combine(game, "Interface", "AddOns", "MindCollectorObserver");
        return Directory.Exists(dir) ? dir : null;
    }

    private static State LoadState()
    {
        try { return File.Exists(StateFile) ? JsonSerializer.Deserialize<State>(File.ReadAllText(StateFile)) ?? new State() : new State(); }
        catch { return new State(); }
    }

    private static void SaveState(State state)
    {
        Directory.CreateDirectory(AppSettings.Dir);
        File.WriteAllText(StateFile + ".tmp", JsonSerializer.Serialize(state));
        File.Move(StateFile + ".tmp", StateFile, overwrite: true);
    }

    private static long? AsLong(object? v) => v switch { long l => l, double d => (long)d, string s when long.TryParse(s, out var i) => i, _ => null };
    private static double? AsDouble(object? v) => v switch { long l => l, double d => d, _ => null };
}

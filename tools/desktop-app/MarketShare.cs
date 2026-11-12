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
/// Market.lua for the next /reload. Prices come back whether or not sharing is on (Settings).
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

    /// <summary>What has been sent and fetched, in its own file so it never races the settings.</summary>
    private sealed class State
    {
        public long LastSent { get; set; }
        public string? Region { get; set; }
        public string? Realm { get; set; }
        public string? Faction { get; set; }
        public DateTime Fetched { get; set; }
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
            if (at != _seenFile && DateTime.UtcNow - at > TimeSpan.FromSeconds(1))
            {
                _seenFile = at;
                sent = await SendNewScansAsync(file, state, ct);
            }
        }

        if (state.Realm != null && state.Region != null && state.Faction != null && (sent || DateTime.UtcNow - state.Fetched > FetchEvery))
            await FetchAsync(logs, state, ct);
        SaveState(state);
    }

    private async Task<bool> SendNewScansAsync(string file, State state, CancellationToken ct)
    {
        if (SavedVariables.Read(file).GetValueOrDefault("MindCollectorObserverDB") is not Dictionary<object, object?> db) return false;
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
            // scans from before the addon recorded where they were can't be placed in a market
            if (t <= state.LastSent || scan.GetValueOrDefault("realm") is not string realm
                || scan.GetValueOrDefault("region") is not string region || scan.GetValueOrDefault("faction") is not string faction) continue;
            // the prices to bring back are always your own faction's house, whichever house this was
            state.Region = region;
            state.Realm = realm;
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
    /// One scan as the website takes it: {region, realm, faction, t, n, items: [[id, min, listed, e0, e1, e2, e3]]},
    /// the e's how many of `listed` sit in each time-left band, shortest first. The addon keeps a band by
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
        if (scan.GetValueOrDefault("prices") is Dictionary<object, object?> prices)
            foreach (var (k, v) in prices)
            {
                var arr = SavedVariables.List(v);
                if (AsLong(k) is not { } id || arr.Count < 2 || AsDouble(arr[0]) is not { } min || min <= 0 || AsLong(arr[1]) is not { } listed || listed <= 0) continue;
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
        });
        return (json, items.Count);
    }

    private async Task FetchAsync(string? logs, State state, CancellationToken ct)
    {
        var addon = AddonFolder(logs);
        if (addon == null) return;
        using var api = new ApiClient(_settings.ServerUrl, _settings.Key!);
        using var doc = await api.MarketAsync(state.Region!, state.Realm!, state.Faction!, ct);
        if (doc == null) return;
        var root = doc.RootElement;
        if (!root.TryGetProperty("items", out var items) || items.ValueKind != JsonValueKind.Object) return;

        var lua = MarketLua(root);
        var path = Path.Combine(addon, "Market.lua");
        File.WriteAllText(path + ".tmp", lua, new UTF8Encoding(false));
        File.Move(path + ".tmp", path, overwrite: true);
        var first = state.Fetched == default;
        state.Fetched = DateTime.UtcNow;
        var count = items.EnumerateObject().Count();
        var players = root.TryGetProperty("players", out var p) && p.ValueKind == JsonValueKind.Number ? p.GetInt32() : 0;
        if (first || count > 0)
            _log($"Everyone's auction prices for {state.Realm}: {count:N0} items from {players} player{(players == 1 ? "" : "s")}. /reload in Forever to load them.");
    }

    /// <summary>The pooled view as the addon reads it (tools/wow-addon/MindCollectorObserver/Market.lua).</summary>
    internal static string MarketLua(JsonElement root)
    {
        static string Num(JsonElement e) => e.ValueKind == JsonValueKind.Number ? e.GetRawText() : "nil";
        static string Str(string s) => "\"" + s.Replace("\\", "\\\\").Replace("\"", "\\\"").Replace("\n", " ") + "\"";

        var sb = new StringBuilder();
        sb.Append("-- Written by the MindCollector app ").Append(DateTime.Now.ToString("yyyy-MM-dd HH:mm", CultureInfo.InvariantCulture))
          .Append(": auction house prices pooled from every player who shares their scans.\n");
        sb.Append("-- Each item: { t, min, listed, prev t, prev min, prev listed, prev listed by time left (4), lowest in 7 days, scans }\n");
        sb.Append("MindCollectorObserverMarket = {\n");
        sb.Append("  market = ").Append(Str(root.TryGetProperty("market", out var m) ? m.GetString() ?? "" : "")).Append(",\n");
        sb.Append("  at = ").Append(DateTimeOffset.UtcNow.ToUnixTimeSeconds()).Append(",\n");
        sb.Append("  t = ").Append(root.TryGetProperty("t", out var t) ? Num(t) : "nil").Append(",\n");
        sb.Append("  players = ").Append(root.TryGetProperty("players", out var p) ? Num(p) : "0").Append(",\n");
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

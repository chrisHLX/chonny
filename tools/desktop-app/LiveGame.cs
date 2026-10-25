using System.Globalization;
using System.Text.Json.Nodes;

namespace MindCollector.Desktop;

/// <summary>
/// The game being played, read from the combat log as it is written (2026-10-25): the developer
/// app's "This game" column (tools/log-manager, Read-LiveLine), brought into this app. Fed every
/// line the Recorder reads, a second at a time.
///
/// What the log holds, measured on real games (2026-10-01, and the 25 Oct recording):
/// - ZONE_CHANGE as you arrive: about a minute of prep, with only your own team in the log. WoW
///   holds the log in memory and writes it in bursts, so the prep often reaches the file only when
///   the gates open.
/// - ARENA_MATCH_START and every player's COMBATANT_INFO as the gates open: the team at field 2,
///   the spec id at field 25 (counting the event name as 0). A shuffle writes a START per round
///   and re-deals the teams each time, so the players are read again every round.
/// - Every player is named by some ordinary event within seconds: GUID, "Name-Realm-Region",
///   flags, at fields 1-3 (source) and 5-7 (target). The lowest flag nibble 1 is you.
/// - ARENA_MATCH_END: one per 3v3, one per whole shuffle lobby.
/// </summary>
public sealed class LiveGame
{
    public sealed class Player
    {
        public string Guid = "";
        public string Team = "";
        public string Spec = "";
        public string? Name;   // Name-Realm-Region, the only form that can be looked up
        public string? Short; // a name written without its realm, shown until the full one appears
    }

    private readonly object _gate = new();
    private readonly List<Player> _players = [];
    private int _unnamed;

    public string State { get; private set; } = "idle";   // idle, prep, live, ended
    public string Zone { get; private set; } = "";
    public string Bracket { get; private set; } = "";
    public int Round { get; private set; }
    public DateTime? RoundStart { get; private set; }
    public DateTime? Visit { get; private set; }
    public string? Logger { get; private set; }
    public DateTime LastGrowthUtc { get; private set; }
    public DateTime? EndedAtUtc { get; private set; }

    /// <summary>Goes up whenever anything shown changes, so the page is sent only what is new.</summary>
    public int Version { get; private set; }

    /// <summary>A round began: the visit, and its ARENA_MATCH_START (prep-room notes join it).</summary>
    public event Action<DateTime?, DateTime>? RoundStarted;

    /// <summary>
    /// Worth showing: arrived or playing, with the log written in the last 20 minutes (not 90s, as
    /// the developer app had it: WoW can write nothing for minutes mid-round), or ended a minute ago.
    /// </summary>
    public bool Active
    {
        get
        {
            lock (_gate)
            {
                var now = DateTime.UtcNow;
                if (State is "prep" or "live") return now - LastGrowthUtc < TimeSpan.FromMinutes(20) && Syncer.WowRunning();
                return State == "ended" && EndedAtUtc is { } e && now - e < TimeSpan.FromMinutes(1);
            }
        }
    }

    public void OnGrew()
    {
        lock (_gate) LastGrowthUtc = DateTime.UtcNow;
    }

    public void OnLine(string line)
    {
        var sep = line.IndexOf("  ", StringComparison.Ordinal);
        if (sep < 0) return;
        var body = line[(sep + 2)..];
        DateTime? started = null;
        DateTime? visit = null;

        lock (_gate)
        {
            if (body.StartsWith("ZONE_CHANGE,", StringComparison.Ordinal))
            {
                var at = GameTrigger.LineTime(line);
                var zone = GameTrigger.ZoneOf(line);
                var q1 = body.IndexOf('"');
                var q2 = body.LastIndexOf('"');
                var name = q2 > q1 ? body.Substring(q1 + 1, q2 - q1 - 1) : "";
                if (zone is { } z && GameTrigger.IsArena(z) && at is { } t)
                {
                    // Arriving writes two lines a second apart (the zone you left, then the arena).
                    if (State == "prep" && Visit is { } v && t - v < TimeSpan.FromSeconds(10)) Zone = name;
                    else NewVisit(name, t);
                }
                else if (State is "prep" or "live")
                {
                    State = "ended";
                    EndedAtUtc = DateTime.UtcNow;
                }
                Version++;
                return;
            }
            if (body.StartsWith("ARENA_MATCH_START,", StringComparison.Ordinal))
            {
                if (GameTrigger.LineTime(line) is not { } at) return;
                // No arrival seen (the app started mid-game, or the zone-in was not written): this START opens the visit.
                if (Visit == null || State is "idle" or "ended") NewVisit(Zone, at);
                State = "live";
                Bracket = GameTrigger.BracketOf(line).Replace("Rated ", "");
                Round++;
                RoundStart = at;
                _players.Clear();
                _unnamed = 0;
                Version++;
                started = at;
                visit = Visit;
            }
            else if (body.StartsWith("COMBATANT_INFO,", StringComparison.Ordinal))
            {
                var f = body.Split(',');
                if (f.Length > 25 && !_players.Any(p => p.Guid == f[1]))
                {
                    _players.Add(new Player { Guid = f[1], Team = f[2], Spec = f[25] });
                    _unnamed++;
                    Version++;
                }
                return;
            }
            else if (body.StartsWith("ARENA_MATCH_END,", StringComparison.Ordinal))
            {
                State = "ended";
                EndedAtUtc = DateTime.UtcNow;
                Version++;
                return;
            }
            else
            {
                // Nothing left to learn from ordinary events once everyone is named: most lines stop here.
                if (Logger != null && _unnamed <= 0) return;
                var f = body.Split(',', 9);
                if (f.Length < 8) return;
                foreach (var ix in new[] { 1, 5 })
                {
                    var guid = f[ix];
                    if (!guid.StartsWith("Player-", StringComparison.Ordinal)) continue;
                    var p = _players.FirstOrDefault(x => x.Guid == guid);
                    if (p is { Name: null })
                    {
                        // WoW sometimes writes a name without its realm; only Name-Realm-Region can be looked up.
                        var n = f[ix + 1].Trim('"');
                        if (n.Contains('-')) { p.Name = n; _unnamed--; Version++; }
                        else if (p.Short == null) { p.Short = n; Version++; }
                    }
                    if (Logger == null && f[ix + 2].StartsWith("0x", StringComparison.Ordinal)
                        && int.TryParse(f[ix + 2].AsSpan(2), NumberStyles.HexNumber, CultureInfo.InvariantCulture, out var flags) && (flags & 0xF) == 1)
                    {
                        Logger = guid;
                        Version++;
                    }
                }
                return;
            }
        }
        if (started is { } s) RoundStarted?.Invoke(visit, s);
    }

    private void NewVisit(string zone, DateTime at)
    {
        State = "prep";
        Zone = zone;
        Bracket = "";
        Round = 0;
        RoundStart = null;
        Visit = at;
        _players.Clear();
        _unnamed = 0;
        EndedAtUtc = null;
    }

    /// <summary>The full names of everyone named so far, to look their experience up.</summary>
    public List<string> Names()
    {
        lock (_gate) return _players.Where(p => p.Name != null).Select(p => p.Name!).ToList();
    }

    /// <summary>What "This game" draws: the arena, the round, a clock from the gates, and both teams.</summary>
    public JsonObject Snapshot(IReadOnlyDictionary<string, System.Text.Json.JsonElement> experience)
    {
        lock (_gate)
        {
            var mine = Logger != null ? _players.FirstOrDefault(p => p.Guid == Logger)?.Team : null;
            var players = new JsonArray();
            foreach (var p in _players.OrderBy(p => mine != null && p.Team == mine ? 0 : 1))
            {
                var spec = SpecName(p.Spec);
                var x = new JsonObject
                {
                    ["name"] = p.Name != null ? p.Name.Split('-')[0] : p.Short ?? "…",
                    ["full"] = p.Name,
                    ["spec"] = spec,
                    ["color"] = ClassColor(spec),
                    ["side"] = mine == null ? "" : p.Team == mine ? "us" : "them",
                    ["you"] = p.Guid == Logger,
                };
                if (p.Name != null && experience.TryGetValue(p.Name, out var e))
                {
                    x["found"] = e.TryGetProperty("found", out var fd) && fd.ValueKind == System.Text.Json.JsonValueKind.True;
                    if (e.TryGetProperty("gladSeasons", out var gs) && gs.ValueKind == System.Text.Json.JsonValueKind.Number) x["glad"] = gs.GetInt32();
                    if (e.TryGetProperty("exp3v3", out var ex) && ex.ValueKind == System.Text.Json.JsonValueKind.Number) x["best"] = ex.GetInt32();
                }
                players.Add(x);
            }
            return new JsonObject
            {
                ["state"] = State,
                ["zone"] = Zone,
                ["bracket"] = Bracket,
                ["round"] = Round,
                ["shuffle"] = Bracket.Contains("Shuffle"),
                ["sinceGates"] = RoundStart is { } r ? Math.Max(0, (DateTime.Now - r).TotalSeconds) : null,
                ["players"] = players,
            };
        }
    }

    // ---------------------------------------------------------------- specs and colours

    /// <summary>Blizzard spec ids, from this project's `specializations` table (as the developer app had them).</summary>
    private static readonly Dictionary<string, string> Specs = new()
    {
        ["250"] = "Blood Death Knight", ["251"] = "Frost Death Knight", ["252"] = "Unholy Death Knight",
        ["577"] = "Havoc Demon Hunter", ["581"] = "Vengeance Demon Hunter", ["1480"] = "Devourer Demon Hunter",
        ["102"] = "Balance Druid", ["103"] = "Feral Druid", ["104"] = "Guardian Druid", ["105"] = "Restoration Druid",
        ["1467"] = "Devastation Evoker", ["1468"] = "Preservation Evoker", ["1473"] = "Augmentation Evoker",
        ["253"] = "Beast Mastery Hunter", ["254"] = "Marksmanship Hunter", ["255"] = "Survival Hunter",
        ["62"] = "Arcane Mage", ["63"] = "Fire Mage", ["64"] = "Frost Mage",
        ["268"] = "Brewmaster Monk", ["269"] = "Windwalker Monk", ["270"] = "Mistweaver Monk",
        ["65"] = "Holy Paladin", ["66"] = "Protection Paladin", ["70"] = "Retribution Paladin",
        ["256"] = "Discipline Priest", ["257"] = "Holy Priest", ["258"] = "Shadow Priest",
        ["259"] = "Assassination Rogue", ["260"] = "Outlaw Rogue", ["261"] = "Subtlety Rogue",
        ["262"] = "Elemental Shaman", ["263"] = "Enhancement Shaman", ["264"] = "Restoration Shaman",
        ["265"] = "Affliction Warlock", ["266"] = "Demonology Warlock", ["267"] = "Destruction Warlock",
        ["71"] = "Arms Warrior", ["72"] = "Fury Warrior", ["73"] = "Protection Warrior",
    };

    /// <summary>Blizzard's class colours (config/wow_classes.php).</summary>
    private static readonly (string Class, string Color)[] Colors =
    [
        ("Death Knight", "#C41F3B"), ("Demon Hunter", "#A330C9"), ("Warrior", "#C79C6E"), ("Paladin", "#F58CBA"),
        ("Hunter", "#ABD473"), ("Rogue", "#FFF569"), ("Priest", "#FFFFFF"), ("Shaman", "#0070DE"), ("Mage", "#69CCF0"),
        ("Warlock", "#9482C9"), ("Monk", "#00FF96"), ("Druid", "#FF7D0A"), ("Evoker", "#33937F"),
    ];

    public static string SpecName(string id) => Specs.TryGetValue(id, out var n) ? n : $"Spec {id}";

    private static string ClassColor(string spec) =>
        Colors.FirstOrDefault(c => spec.EndsWith(c.Class, StringComparison.Ordinal)).Color ?? "#B3B3B3";
}

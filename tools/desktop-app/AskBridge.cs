using System.Diagnostics;
using System.Globalization;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace MindCollector.Desktop;

/// <summary>
/// Answers /ask questions from WoW Forever with Claude (2026-11-09; first built as
/// tools/forever-ask/ask_bridge.py, whose README has the reasoning).
///
/// An addon can't reach anything outside the game while WoW runs, so the question comes out through
/// the Observer addon's saved data (written when its /ask reloads the UI), and the answer goes back
/// through Answers.lua in the addon's folder, which the game reads at the next /reload. Claude runs
/// headless (`claude -p`) with web search, Wowhead and the player's Chrome, never Bash or file edits,
/// and must return its points as read from Wowhead's map data, never estimated.
///
/// Questions can also be typed in the app's Ask page: the answer shows at once, and the next
/// /reload in game puts its points on the map.
/// </summary>
public sealed class AskBridge : IDisposable
{
    public sealed class Point
    {
        public string Zone { get; set; } = "";
        public double X { get; set; }
        public double Y { get; set; }
        public string Label { get; set; } = "";
    }

    public sealed class Answer
    {
        public string Id { get; set; } = "";
        public string Question { get; set; } = "";
        public long Asked { get; set; }
        public long Answered { get; set; }
        public string Text { get; set; } = "";
        public List<Point> Points { get; set; } = [];
        public double? Cost { get; set; }
        public bool FromApp { get; set; }
        public string? Zone { get; set; }
    }

    private const string Addon = "MindCollectorObserver";
    private static readonly TimeSpan MaxAge = TimeSpan.FromHours(2);
    private static readonly TimeSpan RunLimit = TimeSpan.FromMinutes(8);
    private static readonly string StateDir = Path.Combine(AppSettings.Dir, "ask");
    private static readonly string StateFile = Path.Combine(StateDir, "answers.json");

    private readonly AppSettings _settings;
    private readonly Action<string> _log;
    private readonly object _lock = new();
    private readonly HashSet<string> _busy = [];
    private readonly List<Answer> _answers;
    private System.Threading.Timer? _timer;
    private DateTime _seen;

    /// <summary>A question went to Claude (its text).</summary>
    public event Action<string>? Asking;
    /// <summary>Claude answered (or couldn't).</summary>
    public event Action<Answer>? Answered;

    /// <summary>Why it can't answer, when it can't (no Claude Code, no Forever): shown on the Ask page.</summary>
    public string? Problem { get; private set; }
    public int Working { get { lock (_lock) return _busy.Count; } }

    public AskBridge(AppSettings settings, Action<string> log)
    {
        _settings = settings;
        _log = log;
        _answers = LoadState();
    }

    public IReadOnlyList<Answer> Answers() { lock (_lock) return _answers.OrderByDescending(a => a.Answered).ToList(); }

    public void Start() => _timer = new System.Threading.Timer(_ => Poll(), null, TimeSpan.FromSeconds(5), TimeSpan.FromSeconds(3));

    public void Dispose() => _timer?.Dispose();

    // ------------------------------------------------------------------ questions in

    private void Poll()
    {
        if (!_settings.AskClaude) return;
        try
        {
            var file = ObserverData.FindFile(_settings.ForeverLogsDir);
            if (file == null) return;
            var at = File.GetLastWriteTimeUtc(file);
            if (at == _seen || DateTime.UtcNow - at < TimeSpan.FromSeconds(1)) return;   // unchanged, or still being written
            _seen = at;
            if (SavedVariables.Read(file).GetValueOrDefault("MindCollectorObserverDB") is not Dictionary<object, object?> db) return;
            foreach (var a in SavedVariables.List(db.GetValueOrDefault("asks")).OfType<Dictionary<object, object?>>())
            {
                if (a.GetValueOrDefault("id") is not string id || a.GetValueOrDefault("status") as string == "answered") continue;
                var asked = a.GetValueOrDefault("t") is long t ? t : 0;
                if (DateTimeOffset.UtcNow.ToUnixTimeSeconds() - asked > MaxAge.TotalSeconds) continue;
                lock (_lock)
                {
                    if (_busy.Contains(id) || _answers.Any(x => x.Id == id)) continue;
                    _busy.Add(id);
                }
                _ = Task.Run(() => Work(id, a, fromApp: false));
            }
        }
        catch (Exception e) { _log($"Ask: couldn't read Forever's saved data: {e.Message}"); }
    }

    /// <summary>A question typed in the app's Ask page; the answer reaches the game at the next /reload.</summary>
    public void AskFromApp(string question)
    {
        var id = $"{DateTimeOffset.UtcNow.ToUnixTimeSeconds()}-app{Random.Shared.Next(1000, 9999)}";
        var ask = new Dictionary<object, object?> { ["q"] = question, ["t"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds() };
        lock (_lock) _busy.Add(id);
        _ = Task.Run(() => Work(id, ask, fromApp: true));
    }

    private void Work(string id, Dictionary<object, object?> ask, bool fromApp)
    {
        var q = ask.GetValueOrDefault("q") as string ?? "";
        var answer = new Answer
        {
            Id = id, Question = q, FromApp = fromApp, Zone = ask.GetValueOrDefault("zone") as string,
            Asked = ask.GetValueOrDefault("t") is long t ? t : DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
        };
        try
        {
            _log($"Ask: {q}");
            Asking?.Invoke(q);
            var (text, points, cost) = AskClaude(ask);
            answer.Text = text.Length > 0 ? text : "No answer came back.";
            answer.Points = points;
            answer.Cost = cost;
        }
        catch (TimeoutException) { answer.Text = "Claude took too long on this one. Try asking it more narrowly."; }
        catch (Exception e) { answer.Text = $"Couldn't answer: {e.Message}"; }
        answer.Answered = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        lock (_lock)
        {
            _answers.RemoveAll(a => a.Id == id);
            _answers.Add(answer);
            _busy.Remove(id);
            SaveState();
            WriteAnswersLua();
        }
        _log($"Ask answered ({answer.Points.Count} points{(answer.Cost is { } c ? $", ${c:F2}" : "")}): {answer.Text}");
        Answered?.Invoke(answer);
    }

    // ------------------------------------------------------------------ Claude

    private const string SystemPrompt = """
        You answer a question typed inside World of Warcraft: Forever by a player who is in the game right now.
        Forever is Blizzard's level-60 remake of the original world (launched 2026-11-04), with new zones, recipes and items.
        Your answer is shown in the game, and the points you return are drawn on the in-game world map.

        Rules:
        - Facts about Forever come from Wowhead's Forever pages (https://www.wowhead.com/forever/...), not Classic or retail pages.
          Find the page with WebSearch (for example: site:wowhead.com/forever ashenvale bear) or WebFetch.
        - For where an NPC, object or herb is: open its Wowhead Forever page in Chrome and read window.g_mapperData with JavaScript.
          It is keyed by Wowhead zone id; each value is a list of {count, coords: [[x, y], ...]}, with x and y in percent (0-100)
          of that zone's map. The page's location text ("This NPC can be found in ...") names the zones; match ids to names by count.
          Return those coordinates exactly. Never estimate or invent coordinates: if you cannot read them, return no points and say so.
        - Zone names exactly as the game shows them (for example "Ashenvale", "Stonetalon Mountains").
        - At most 150 points. If there are more, keep the ones in the player's zone, spread across the whole area.
        - answer: at most three short sentences in plain words, no markdown. Say what you marked and anything useful
          (level, where they cluster, which is nearest the player). If you are unsure of something, say so.
        - When the prompt gives "From the Forever game files" facts about an item, they are exact (recipes, professions,
          skill, level required): use them over any website. A low level requirement doesn't mean nobody uses it at 60.
        - "What my addon has seen" is the player's own live auction house readings (cheapest listing, how many are listed,
          by day, their own posts and sales). Use those numbers. Nobody can see auction house sales directly: say what the
          numbers suggest (few listed can mean it sells fast or that few want it; listings falling over days while the price
          holds suggests it sells) and how the player can find out (track it for a few days, post a handful and see how
          many sell). Say who uses the item and when (raids, PvP, levelling), from Wowhead and the game files.
        - Close any browser tabs you opened.
        """;

    private const string Schema = """
        {"type":"object","properties":{"answer":{"type":"string"},"points":{"type":"array","items":{"type":"object",
        "properties":{"zone":{"type":"string"},"x":{"type":"number"},"y":{"type":"number"},"label":{"type":"string"}},
        "required":["zone","x","y"]}}},"required":["answer","points"]}
        """;

    private (string Text, List<Point> Points, double? Cost) AskClaude(Dictionary<object, object?> ask)
    {
        var claude = FindClaude(_settings.ClaudePath) ?? throw new InvalidOperationException("Claude Code isn't installed on this PC (Settings has where to point it).");
        string S(string k) => Convert.ToString(ask.GetValueOrDefault(k), CultureInfo.InvariantCulture) ?? "";
        var prompt = new StringBuilder($"Question: {S("q")}\n\n");
        if (S("zone") != "")
        {
            var where = S("subzone") != "" ? $"{S("zone")} ({S("subzone")})" : S("zone");
            var pos = S("x") != "" ? $" at {S("x")}, {S("y")}" : "";
            var faction = S("faction") != "" ? $" ({S("faction")}: only suggest what {S("faction")} can use)" : "";
            prompt.Append($"Player: {S("ch")}, level {S("level")} {S("class").ToLowerInvariant()}{faction}, in {where}{pos} (map id {S("mapID")}).");
            if (S("profs") != "") prompt.Append($" Professions: {S("profs")}.");
            // what the addon has seen of the subject (live prices, listings, costs, own sales), from Research
            if (S("ctx") != "") prompt.Append($"\n\n{S("ctx")}");
        }
        else prompt.Append("Asked from the MindCollector app, not from inside the game, so the player's position isn't known.");
        var facts = ItemFacts.For(S("q"));
        if (facts.Count > 0) prompt.Append("\n\nFrom the Forever game files (recipe, skill, level required, count):\n" + string.Join("\n", facts));

        Directory.CreateDirectory(StateDir);
        var psi = new ProcessStartInfo(claude)
        {
            WorkingDirectory = StateDir,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
            StandardOutputEncoding = Encoding.UTF8,
        };
        foreach (var a in new[] { "-p", prompt.ToString(), "--output-format", "json", "--json-schema", Schema.Replace("\n", ""),
                     "--append-system-prompt", SystemPrompt, "--chrome", "--model", _settings.AskModel,
                     "--allowedTools", "WebSearch", "WebFetch", "mcp__claude-in-chrome",
                     "--disallowedTools", "Bash", "Edit", "Write", "NotebookEdit", "PowerShell" })
            psi.ArgumentList.Add(a);
        using var p = Process.Start(psi) ?? throw new InvalidOperationException("Claude didn't start.");
        var stdout = p.StandardOutput.ReadToEndAsync();
        var stderr = p.StandardError.ReadToEndAsync();
        if (!p.WaitForExit(RunLimit))
        {
            try { p.Kill(entireProcessTree: true); } catch { }
            throw new TimeoutException();
        }
        var raw = stdout.Result;
        JsonElement result;
        try { result = JsonDocument.Parse(raw).RootElement; }
        catch (JsonException) { throw new InvalidOperationException(Short(stderr.Result.Length > 0 ? stderr.Result : raw)); }

        if (!result.TryGetProperty("structured_output", out var output) || output.ValueKind != JsonValueKind.Object)
        {
            var text = result.TryGetProperty("result", out var r) ? r.GetString() ?? "" : "";
            var start = text.IndexOf('{'); var end = text.LastIndexOf('}');
            if (start < 0 || end < start) throw new InvalidOperationException(Short(text.Length > 0 ? text : "no answer in Claude's output"));
            output = JsonDocument.Parse(text[start..(end + 1)]).RootElement;
        }
        var points = new List<Point>();
        if (output.TryGetProperty("points", out var pts) && pts.ValueKind == JsonValueKind.Array)
            foreach (var pt in pts.EnumerateArray())
            {
                if (!pt.TryGetProperty("zone", out var z) || z.GetString() is not { Length: > 0 } zone) continue;
                if (!pt.TryGetProperty("x", out var x) || !pt.TryGetProperty("y", out var y) || x.ValueKind != JsonValueKind.Number || y.ValueKind != JsonValueKind.Number) continue;
                var (px, py) = (x.GetDouble(), y.GetDouble());
                if (px is < 0 or > 100 || py is < 0 or > 100) continue;
                points.Add(new Point { Zone = zone, X = Math.Round(px, 2), Y = Math.Round(py, 2), Label = pt.TryGetProperty("label", out var l) ? l.GetString() ?? "" : "" });
            }
        var answer = output.TryGetProperty("answer", out var ans) ? ans.GetString() ?? "" : "";
        double? cost = result.TryGetProperty("total_cost_usd", out var cu) && cu.ValueKind == JsonValueKind.Number ? cu.GetDouble() : null;
        return (answer.Trim(), points.Take(150).ToList(), cost);
    }

    private static string Short(string s) => s.Length > 300 ? s[..300] : s.Trim();

    /// <summary>claude.exe: Settings, the PATH, the native installer's ~/.local/bin, then VS Code's Claude extension.</summary>
    public static string? FindClaude(string? configured)
    {
        if (!string.IsNullOrEmpty(configured) && File.Exists(configured)) return configured;
        foreach (var dir in (Environment.GetEnvironmentVariable("PATH") ?? "").Split(Path.PathSeparator))
            try { if (dir.Length > 0 && File.Exists(Path.Combine(dir, "claude.exe"))) return Path.Combine(dir, "claude.exe"); } catch { }
        var home = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
        var local = Path.Combine(home, ".local", "bin", "claude.exe");
        if (File.Exists(local)) return local;
        var ext = Path.Combine(home, ".vscode", "extensions");
        if (Directory.Exists(ext))
            return Directory.EnumerateDirectories(ext, "anthropic.claude-code-*")
                .Select(d => Path.Combine(d, "resources", "native-binary", "claude.exe"))
                .Where(File.Exists)
                .OrderByDescending(File.GetLastWriteTimeUtc)
                .FirstOrDefault();
        return null;
    }

    /// <summary>Whether it can answer, in words for the Ask page: null when it can.</summary>
    public string? CheckReady()
    {
        Problem = !_settings.AskClaude ? "Answering questions is off. Turn it on in Settings > WoW Forever."
            : FindClaude(_settings.ClaudePath) == null ? "Claude Code isn't installed on this PC. Install it (claude.com/claude-code), or set where it is in Settings."
            : _settings.ForeverLogsDir == null ? "WoW Forever hasn't been found yet."
            : null;
        return Problem;
    }

    // ------------------------------------------------------------------ answers out

    private void WriteAnswersLua()
    {
        var logs = _settings.ForeverLogsDir;
        var addons = AddonInstaller.AddOnsDir(logs);
        if (addons == null || !Directory.Exists(Path.Combine(addons, Addon))) return;
        var sb = new StringBuilder();
        sb.Append("-- Written by the MindCollector app (AskBridge); the game loads it when the UI loads (/reload).\n");
        sb.Append("MindCollectorObserverAnswers = {\n");
        foreach (var a in _answers.OrderBy(a => a.Answered).TakeLast(25))
        {
            var pins = string.Join(", ", a.Points.Select(p =>
                $"{{zone = {Lua(p.Zone)}, x = {p.X.ToString(CultureInfo.InvariantCulture)}, y = {p.Y.ToString(CultureInfo.InvariantCulture)}, label = {Lua(p.Label)}}}"));
            sb.Append($"  [{Lua(a.Id)}] = {{t = {a.Answered}, asked = {a.Asked}, q = {Lua(a.Question)}, answer = {Lua(a.Text)}, app = {(a.FromApp ? "true" : "false")}, pins = {{{pins}}}}},\n");
        }
        sb.Append("}\n");
        var path = Path.Combine(addons, Addon, "Answers.lua");
        File.WriteAllText(path + ".tmp", sb.ToString(), new UTF8Encoding(false));
        File.Move(path + ".tmp", path, overwrite: true);
    }

    private static string Lua(string s) => "\"" + s.Replace("\\", "\\\\").Replace("\"", "\\\"").Replace("\r", "").Replace("\n", "\\n") + "\"";

    private static List<Answer> LoadState()
    {
        try { return File.Exists(StateFile) ? JsonSerializer.Deserialize<List<Answer>>(File.ReadAllText(StateFile)) ?? [] : []; }
        catch { return []; }
    }

    private void SaveState()
    {
        Directory.CreateDirectory(StateDir);
        File.WriteAllText(StateFile, JsonSerializer.Serialize(_answers.OrderBy(a => a.Answered).TakeLast(100).ToList(), new JsonSerializerOptions { WriteIndented = true }));
    }

    // ------------------------------------------------------------------ facts from the game files

    /// <summary>
    /// Forever's recipe facts for items a question names, built from the client data
    /// (tools/forever-ask/build_item_facts.py) and carried in the app, so "what is X used for"
    /// is answered from the game, not from a guide site.
    /// </summary>
    private static class ItemFacts
    {
        private static Dictionary<string, JsonElement>? _facts;
        private static List<string>? _names;

        public static List<string> For(string question)
        {
            Load();
            var q = question.ToLowerInvariant();
            var found = new List<JsonElement>();
            var taken = new List<(int Start, int End)>();
            foreach (var name in _names!)
            {
                if (name.Length < 4) continue;
                foreach (Match m in Regex.Matches(q, @"(?<![a-z])" + Regex.Escape(name) + @"(?![a-z])"))
                {
                    if (taken.Any(t => m.Index >= t.Start && m.Index < t.End)) continue;
                    taken.Add((m.Index, m.Index + m.Length));
                    found.Add(_facts![name]);
                    break;
                }
                if (found.Count >= 4) break;
            }
            return found.Select(Line).ToList();
        }

        private static string Line(JsonElement f)
        {
            var sb = new StringBuilder($"- {f.GetProperty("name").GetString()} (item level {f.GetProperty("item_level").GetInt32()})");
            var uses = f.GetProperty("used_in").EnumerateArray().Select(u =>
            {
                var lvl = u[3].ValueKind == JsonValueKind.Number ? $", needs level {u[3].GetInt32()}" : "";
                return $"{u[0].GetString()} ({u[1].GetString()} {u[2].GetInt32()}{lvl}, x{u[4].GetInt32()})";
            }).ToList();
            if (uses.Count > 0) sb.Append(". Used in: ").Append(string.Join("; ", uses));
            var made = f.GetProperty("made_by").EnumerateArray().Select(m =>
                $"{m[0].GetString()} ({m[1].GetString()} {m[2].GetInt32()}: {string.Join(", ", m[3].EnumerateArray().Select(r => $"{r[0].GetString()} x{r[1].GetInt32()}"))})").ToList();
            if (made.Count > 0) sb.Append(". Made by: ").Append(string.Join("; ", made));
            return sb.ToString();
        }

        private static void Load()
        {
            if (_facts != null) return;
            using var s = Assembly.GetExecutingAssembly().GetManifestResourceStream("ask/item_facts.json");
            var doc = s == null ? null : JsonDocument.Parse(s);
            _facts = doc == null ? [] : doc.RootElement.EnumerateObject().ToDictionary(p => p.Name, p => p.Value);
            _names = _facts.Keys.OrderByDescending(k => k.Length).ToList();
        }
    }
}

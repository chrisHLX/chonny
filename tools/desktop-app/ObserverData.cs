using System.Globalization;
using System.Text;
using System.Text.RegularExpressions;

namespace MindCollector.Desktop;

/// <summary>
/// What the Observer addon (tools/wow-addon/MindCollectorObserver) saved in WoW Forever: everything
/// the player looked at, the prices it saw, the last two full auction house scans, and their /ask
/// questions. Read from WTF\Account\*\SavedVariables\MindCollectorObserver.lua, which WoW writes at a
/// /reload or logout, so it is as fresh as the player's last reload.
/// </summary>
public sealed class ObserverData
{
    public sealed record Look(DateTime At, int Seconds, string Place, long? Price, int? Listed, int? Had, string? Guess, string? Why, string? Character);
    public sealed record Note(DateTime At, string Text);
    public sealed record Subject(string Key, string Kind, string Id, string Name, DateTime First, DateTime Last, int Looks, double Seconds,
        IReadOnlyList<Look> Events, IReadOnlyList<Note> Notes, DateTime? TakenAt, bool HadIt, string? Class, int? Level)
    {
        public int? ItemId => Kind == "item" && int.TryParse(Id, out var i) ? i : null;
        public Look? LastLook => Events.Count > 0 ? Events[^1] : null;
    }
    public sealed record PricePoint(DateTime At, long Min, int? Listed, string? Source);
    public sealed record Scan(DateTime At, int Auctions, IReadOnlyDictionary<int, (long Min, int Listed, string? Name)> Prices);
    public sealed record Ask(string Id, DateTime At, string Question, string Status, string? Answer, int Points, string? Zone);

    public required string Path { get; init; }
    public required DateTime Saved { get; init; }
    public required IReadOnlyList<Subject> Subjects { get; init; }
    public required IReadOnlyDictionary<int, IReadOnlyList<PricePoint>> Prices { get; init; }
    public Scan? LastScan { get; init; }
    public Scan? PrevScan { get; init; }
    public required IReadOnlyList<Ask> Asks { get; init; }
    public double MinDwell { get; init; } = 2.5;

    /// <summary>The newest saved-data file under a Forever install (the folder holding Wow.exe), or null.</summary>
    public static string? FindFile(string? foreverLogsDir)
    {
        var game = foreverLogsDir == null ? null : Directory.GetParent(foreverLogsDir.TrimEnd('\\', '/'))?.FullName;
        if (game == null) return null;
        var account = System.IO.Path.Combine(game, "WTF", "Account");
        if (!Directory.Exists(account)) return null;
        return Directory.EnumerateDirectories(account)
            .Select(d => System.IO.Path.Combine(d, "SavedVariables", "MindCollectorObserver.lua"))
            .Where(File.Exists)
            .OrderByDescending(File.GetLastWriteTimeUtc)
            .FirstOrDefault();
    }

    public static ObserverData? Load(string? path)
    {
        if (path == null || !File.Exists(path)) return null;
        var vars = SavedVariables.Read(path);
        if (vars.GetValueOrDefault("MindCollectorObserverDB") is not Dictionary<object, object?> db) return null;

        var subjects = new List<Subject>();
        if (db.GetValueOrDefault("subjects") is Dictionary<object, object?> subs)
            foreach (var (k, v) in subs)
                if (v is Dictionary<object, object?> s && k is string key)
                    subjects.Add(ReadSubject(key, s));

        var prices = new Dictionary<int, IReadOnlyList<PricePoint>>();
        if (db.GetValueOrDefault("prices") is Dictionary<object, object?> ps)
            foreach (var (k, v) in ps)
                if (AsInt(k) is { } id)
                    prices[id] = SavedVariables.List(v).OfType<Dictionary<object, object?>>()
                        .Select(p => new PricePoint(Time(p.GetValueOrDefault("t")), (long)(AsDouble(p.GetValueOrDefault("min")) ?? 0), AsInt(p.GetValueOrDefault("q")), p.GetValueOrDefault("src") as string))
                        .Where(p => p.Min > 0).OrderBy(p => p.At).ToList();

        var asks = SavedVariables.List(db.GetValueOrDefault("asks")).OfType<Dictionary<object, object?>>()
            .Select(a => new Ask(a.GetValueOrDefault("id") as string ?? "", Time(a.GetValueOrDefault("t")), a.GetValueOrDefault("q") as string ?? "",
                a.GetValueOrDefault("status") as string ?? "sent", a.GetValueOrDefault("answer") as string,
                SavedVariables.List(a.GetValueOrDefault("pins")).Count, a.GetValueOrDefault("zone") as string))
            .Where(a => a.Id != "").ToList();

        return new ObserverData
        {
            Path = path,
            Saved = File.GetLastWriteTime(path),
            Subjects = subjects,
            Prices = prices,
            LastScan = ReadScan(db.GetValueOrDefault("lastScan")),
            PrevScan = ReadScan(db.GetValueOrDefault("prevScan")),
            Asks = asks,
            MinDwell = AsDouble(db.GetValueOrDefault("minDwell")) ?? 2.5,
        };
    }

    /// <summary>The newest price for an item: its own history or the last full scan, whichever is newer.</summary>
    public PricePoint? Latest(int itemId)
    {
        var own = Prices.TryGetValue(itemId, out var h) && h.Count > 0 ? h[^1] : null;
        if (LastScan is { } s && s.Prices.TryGetValue(itemId, out var p) && (own == null || s.At > own.At))
            return new PricePoint(s.At, p.Min, p.Listed, "scan");
        return own;
    }

    /// <summary>The first look that saw a price: what "when you first looked" compares against.</summary>
    public static Look? FirstPricedLook(Subject s) => s.Events.FirstOrDefault(e => e.Price is > 0);

    private static Subject ReadSubject(string key, Dictionary<object, object?> s)
    {
        var events = SavedVariables.List(s.GetValueOrDefault("events")).OfType<Dictionary<object, object?>>().Select(e => new Look(
            Time(e.GetValueOrDefault("t")), AsInt(e.GetValueOrDefault("s")) ?? 0, e.GetValueOrDefault("ctx") as string ?? "?",
            (long?)AsDouble(e.GetValueOrDefault("p")), AsInt(e.GetValueOrDefault("q")), AsInt(e.GetValueOrDefault("n")),
            e.GetValueOrDefault("g") as string, e.GetValueOrDefault("why") as string, e.GetValueOrDefault("ch") as string)).ToList();
        var notes = SavedVariables.List(s.GetValueOrDefault("notes")).OfType<Dictionary<object, object?>>()
            .Select(n => new Note(Time(n.GetValueOrDefault("t")), StripColors(n.GetValueOrDefault("text") as string ?? ""))).ToList();
        var kind = s.GetValueOrDefault("kind") as string ?? key.Split(':')[0];
        var name = s.GetValueOrDefault("name") as string ?? key;
        if (kind == "player") name = Regex.Replace(name, @" \(.*\)$", "");
        return new Subject(key, kind, Convert.ToString(s.GetValueOrDefault("id"), CultureInfo.InvariantCulture) ?? "", name,
            Time(s.GetValueOrDefault("first")), Time(s.GetValueOrDefault("last")), AsInt(s.GetValueOrDefault("looks")) ?? 0,
            AsDouble(s.GetValueOrDefault("secs")) ?? 0, events, notes,
            s.GetValueOrDefault("takenAt") is { } ta ? Time(ta) : null, s.GetValueOrDefault("hadIt") is true,
            s.GetValueOrDefault("class") as string, AsInt(s.GetValueOrDefault("level")));
    }

    private static Scan? ReadScan(object? v)
    {
        if (v is not Dictionary<object, object?> d || d.GetValueOrDefault("prices") is not Dictionary<object, object?> ps) return null;
        var prices = new Dictionary<int, (long, int, string?)>();
        foreach (var (k, p) in ps)
        {
            var arr = SavedVariables.List(p);
            if (AsInt(k) is { } id && arr.Count >= 2 && AsDouble(arr[0]) is { } min && min > 0)
                prices[id] = ((long)Math.Round(min), AsInt(arr[1]) ?? 0, arr.Count > 2 ? arr[2] as string : null);
        }
        return new Scan(Time(d.GetValueOrDefault("t")), AsInt(d.GetValueOrDefault("n")) ?? 0, prices);
    }

    /// <summary>WoW's colour codes (|cffRRGGBB ... |r) out of text the addon wrote for its own window.</summary>
    public static string StripColors(string s) => Regex.Replace(s, @"\|c[0-9a-fA-F]{8}|\|r", "");

    private static DateTime Time(object? v) => AsDouble(v) is { } t ? DateTimeOffset.FromUnixTimeSeconds((long)t).LocalDateTime : DateTime.MinValue;
    private static int? AsInt(object? v) => v switch { long l => (int)l, double d => (int)d, string s when int.TryParse(s, out var i) => i, _ => null };
    private static double? AsDouble(object? v) => v switch { long l => l, double d => d, _ => null };
}

/// <summary>
/// Just enough Lua to read a WoW SavedVariables file: tables, strings, numbers, booleans and nil.
/// Tables come back as Dictionary&lt;object, object?&gt; (keys long or string, array entries 1-based
/// longs), so List() turns an array-like table into a list.
/// </summary>
public static class SavedVariables
{
    public static Dictionary<string, object?> Read(string path)
    {
        // Latin-1 keeps every byte as one char, so the UTF-8 the game wrote can be rebuilt string by string.
        var text = File.ReadAllText(path, Encoding.Latin1);
        var vars = new Dictionary<string, object?>();
        foreach (Match m in Regex.Matches(text, @"^([A-Za-z_]\w*)\s*=\s*", RegexOptions.Multiline))
        {
            var r = new Reader(text, m.Index + m.Length);
            try { vars[m.Groups[1].Value] = r.Value(); } catch (FormatException) { }
        }
        return vars;
    }

    public static List<object?> List(object? v)
    {
        if (v is not Dictionary<object, object?> d) return [];
        var list = new List<object?>();
        for (long i = 1; d.TryGetValue(i, out var x); i++) list.Add(x);
        return list;
    }

    private sealed class Reader(string s, int i)
    {
        private void Ws()
        {
            while (i < s.Length)
            {
                if (s[i] is ' ' or '\t' or '\r' or '\n' or ',' or ';') i++;
                else if (i + 1 < s.Length && s[i] == '-' && s[i + 1] == '-') { var n = s.IndexOf('\n', i); i = n < 0 ? s.Length : n + 1; }
                else break;
            }
        }

        public object? Value()
        {
            Ws();
            if (i >= s.Length) throw new FormatException("end of file");
            var c = s[i];
            if (c == '{') return Table();
            if (c == '"') return Str();
            if (string.CompareOrdinal(s, i, "true", 0, 4) == 0) { i += 4; return true; }
            if (string.CompareOrdinal(s, i, "false", 0, 5) == 0) { i += 5; return false; }
            if (string.CompareOrdinal(s, i, "nil", 0, 3) == 0) { i += 3; return null; }
            var m = Num.Match(s, i);
            if (!m.Success || m.Index != i) throw new FormatException($"unexpected {s.Substring(i, Math.Min(20, s.Length - i))}");
            i += m.Length;
            var t = m.Value;
            // (object) on one side: without it the ?: is typed double, so a whole number came back a double too, and
            // AskBridge's `t is long` never matched: every question read as asked in 1970, too old, skipped (13 Nov).
            return t.IndexOfAny(['.', 'e', 'E']) >= 0 ? (object)double.Parse(t, CultureInfo.InvariantCulture) : long.Parse(t, CultureInfo.InvariantCulture);
        }

        // \G: a match only where the reader is. Without it, Match(s, i) searched on through the rest of
        // the file whenever the next thing wasn't a number or a name, for every value: reading the
        // addon's 1 MB file took 57 seconds on the window's thread, and the Looked at tab froze (13 Nov).
        private static readonly Regex Num = new(@"\G-?\d+(\.\d+)?([eE][-+]?\d+)?", RegexOptions.Compiled);
        private static readonly Regex Name = new(@"\G[A-Za-z_]\w*\s*=", RegexOptions.Compiled);

        private string Str()
        {
            i++;
            // No escapes, the common case (a scan in the price history is ~100 KB of digits): one slice,
            // not a byte at a time through a list.
            var close = s.IndexOf('"', i);
            if (close < 0) throw new FormatException("unterminated string");
            if (s.IndexOf('\\', i, close - i) < 0)
            {
                var plain = Encoding.UTF8.GetString(Encoding.Latin1.GetBytes(s.Substring(i, close - i)));
                i = close + 1;
                return plain;
            }
            var bytes = new List<byte>();
            while (s[i] != '"')
            {
                if (s[i] == '\\')
                {
                    var n = s[i + 1];
                    if (char.IsDigit(n))
                    {
                        var j = i + 1; var v = 0;
                        while (j < s.Length && j < i + 4 && char.IsDigit(s[j])) v = v * 10 + (s[j++] - '0');
                        bytes.Add((byte)(v & 0xFF)); i = j; continue;
                    }
                    bytes.Add((byte)(n switch { 'n' => '\n', 't' => '\t', 'r' => '\r', _ => n }));
                    i += 2;
                }
                else bytes.Add((byte)s[i++]);
            }
            i++;
            return Encoding.UTF8.GetString(bytes.ToArray());
        }

        private Dictionary<object, object?> Table()
        {
            i++;
            var d = new Dictionary<object, object?>();
            long next = 1;
            while (true)
            {
                Ws();
                if (s[i] == '}') { i++; return d; }
                if (s[i] == '[')
                {
                    i++;
                    var k = Value();
                    Ws(); i++;   // ]
                    Ws(); i++;   // =
                    var v = Value();
                    if (k != null) d[k is double dk && dk == Math.Floor(dk) ? (long)dk : k] = v;
                }
                else if (Name.Match(s, i) is { Success: true } m && m.Index == i)
                {
                    i += m.Length;
                    d[m.Value.TrimEnd('=', ' ', '\t')] = Value();
                }
                else d[next++] = Value();
            }
        }
    }
}

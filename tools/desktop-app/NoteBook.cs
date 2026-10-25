using System.Globalization;
using System.Text.Json;

namespace MindCollector.Desktop;

/// <summary>
/// Notes and Ctrl+Shift+M marks written in the app (2026-10-25), the developer app's notes brought
/// over. Kept in the settings until the website has them (CoachAppController), so a note written
/// offline, or before the website could take notes from the app, is sent later and never lost.
///
/// A note during a game knows only its time and round: the round is not on the website yet. The
/// website files it by time when it builds the cards (GameCardService::attachNotes), the same rule
/// the developer app's notes.json used: a note written in the prep room joins the round that follows
/// (OnRoundStarted), and one written up to five minutes after a game is that game's.
/// </summary>
public sealed class NoteBook
{
    private const int Keep = 300;

    private readonly AppSettings _s;
    private readonly Action<string> _log;
    private bool _toldLater;

    public event Action? Changed;

    public NoteBook(AppSettings settings, Action<string> log)
    {
        _s = settings;
        _log = log;
    }

    public static string Clock(DateTime t) => t.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture);

    /// <summary>A new note: on a past game (lobby), on the game being played (live), or for the next game.</summary>
    public AppNote Add(string kind, string text, string? lobby = null, LiveGame? live = null)
    {
        var note = new AppNote { Kind = kind, Text = text.Trim(), At = Clock(DateTime.Now), Lobby = lobby };
        if (live is { Visit: { } visit })
        {
            note.Visit = Clock(visit);
            note.RoundStart = live.RoundStart is { } r ? Clock(r) : null;
            note.Round = live.Round;
        }
        lock (_s.Gate)
        {
            _s.Notes.Add(note);
            var drop = _s.Notes.Count - Keep;
            if (drop > 0) _s.Notes.RemoveAll(n => n.Sent && drop-- > 0);
        }
        _s.Save();
        Changed?.Invoke();
        return note;
    }

    /// <summary>Writes about a mark (or changes a note): sent again, and the website updates it in place.</summary>
    public void Write(string id, string text)
    {
        lock (_s.Gate)
        {
            var note = _s.Notes.FirstOrDefault(n => n.Id == id);
            if (note == null) return;
            note.Text = text.Trim();
            note.Sent = false;
        }
        _s.Save();
        Changed?.Invoke();
    }

    /// <summary>A round began: notes written in this visit's prep room belong to it.</summary>
    public void OnRoundStarted(DateTime? visit, DateTime start)
    {
        if (visit is not { } v) return;
        var key = Clock(v);
        var moved = false;
        lock (_s.Gate)
        {
            foreach (var n in _s.Notes.Where(n => n.Visit == key && n.RoundStart == null && n.Kind != "next"))
            {
                n.RoundStart = Clock(start);
                n.Sent = false;
                moved = true;
            }
        }
        if (!moved) return;
        _s.Save();
        Changed?.Invoke();
    }

    /// <summary>This visit's notes and marks, oldest first.</summary>
    public List<AppNote> ForVisit(DateTime? visit)
    {
        if (visit is not { } v) return [];
        var key = Clock(v);
        lock (_s.Gate) return _s.Notes.Where(n => n.Visit == key).ToList();
    }

    /// <summary>Notes for the next game written since the last game began: still waiting for it.</summary>
    public List<AppNote> Waiting(DateTime? lastGame)
    {
        var since = lastGame is { } g ? Clock(g) : "";
        lock (_s.Gate) return _s.Notes.Where(n => n.Kind == "next" && string.CompareOrdinal(n.At, since) > 0).ToList();
    }

    /// <summary>Sends what the website does not have yet. Stops at the first that has to wait, and tries again next time.</summary>
    public async Task FlushAsync(ApiClient api, CancellationToken ct)
    {
        List<AppNote> todo;
        lock (_s.Gate) todo = _s.Notes.Where(n => !n.Sent && n.Refused == null).ToList();
        if (todo.Count == 0) return;

        foreach (var note in todo)
        {
            var (outcome, reason) = await api.NoteAsync(note, ct);
            if (outcome == ApiClient.NoteOutcome.Later)
            {
                if (!_toldLater) _log($"Notes are kept on this PC until the website takes them ({reason}).");
                _toldLater = true;
                break;
            }
            lock (_s.Gate)
            {
                if (outcome == ApiClient.NoteOutcome.Stored) note.Sent = true;
                else note.Refused = reason;
            }
            if (outcome == ApiClient.NoteOutcome.Refused) _log($"A note was not taken: {reason}. It stays on this PC.");
            _toldLater = false;
        }
        _s.Save();
        Changed?.Invoke();
    }

    /// <summary>
    /// The developer app's notes (%APPDATA%\MindCollector\notes.json), brought over once with their
    /// own ids and times. A note it filed against its own game id goes by its time instead: the
    /// website's game ids are its own.
    /// </summary>
    public void ImportDevNotes()
    {
        if (_s.DevNotesImported) return;
        var path = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "MindCollector", "notes.json");
        var added = 0;
        try
        {
            if (File.Exists(path))
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(path));
                var list = doc.RootElement.ValueKind == JsonValueKind.Array ? doc.RootElement
                    : doc.RootElement.TryGetProperty("notes", out var n) ? n : default;
                if (list.ValueKind == JsonValueKind.Array)
                {
                    lock (_s.Gate)
                    {
                        foreach (var e in list.EnumerateArray())
                        {
                            string? S(string k) => e.TryGetProperty(k, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : null;
                            var id = S("id") ?? Guid.NewGuid().ToString("N");
                            if (S("at") is not { } at || _s.Notes.Any(x => x.Id == id)) continue;
                            _s.Notes.Add(new AppNote
                            {
                                Id = id, Kind = S("kind") ?? "note", Text = S("text") ?? "", At = at,
                                RoundStart = S("roundStart"), Visit = S("visit"),
                                Round = e.TryGetProperty("round", out var r) && r.ValueKind == JsonValueKind.Number ? r.GetInt32() : 0,
                            });
                            added++;
                        }
                    }
                }
            }
        }
        catch (Exception e)
        {
            _log($"Could not read the developer app's notes: {e.Message}");
            return;
        }
        _s.DevNotesImported = true;
        _s.Save();
        if (added > 0) _log($"Brought over {added} note(s) from the developer app; they go to the website with the next sync.");
    }
}

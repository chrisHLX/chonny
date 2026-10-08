using System.Text;

namespace MindCollector.Desktop;

/// <summary>
/// Finds finished arena rounds in a combat log, from where the last read stopped.
///
/// The same rule as the website's browser upload (game-review-upload.blade.php): a round starts at
/// ARENA_MATCH_START and ends at ARENA_MATCH_END or at the next ARENA_MATCH_START. A Solo Shuffle
/// lobby writes a START per round and one END for the lobby, so a new START closes the round before
/// it. Nothing outside a round is kept. Every piece of interpretation (teams, specs, who won) stays
/// on the server, because all of it has been wrong at least once and the server can be fixed
/// without shipping a new app.
///
/// A round still being written (no END, no later START) is left alone, and the next read starts at
/// its first byte, so it is sent once it is finished and never in part.
/// </summary>
public static class RoundScanner
{
    private const string Start = "ARENA_MATCH_START,";
    private const string End = "ARENA_MATCH_END,";
    private const int ReadSize = 4 * 1024 * 1024;

    public sealed record Round(string Text, long StartOffset);

    public sealed record Result(List<Round> Rounds, long NextOffset, bool OpenRound, bool LastMarkerIsEnd);

    /// <param name="closeOpenRound">
    /// Treat a round with no end as finished (the log will not grow again: WoW is closed, or a newer
    /// session's file exists). The server decides whether it is usable.
    /// </param>
    public static Result Scan(string path, long fromOffset, bool closeOpenRound)
    {
        var rounds = new List<Round>();
        StringBuilder? current = null;
        long currentStart = 0;
        bool lastMarkerIsEnd = false;

        using var fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        if (fromOffset > fs.Length) fromOffset = 0;   // a new file under the same name
        fs.Seek(fromOffset, SeekOrigin.Begin);

        var buffer = new byte[ReadSize];
        var carry = new List<byte>();
        long position = fromOffset;     // file offset of the first byte in `carry` + buffer
        long lineStart = fromOffset;
        long lastFullLineEnd = fromOffset;

        int read;
        while ((read = fs.Read(buffer, 0, buffer.Length)) > 0)
        {
            int segmentStart = 0;
            for (int i = 0; i < read; i++)
            {
                if (buffer[i] != (byte)'\n') continue;

                // One whole line: whatever was carried over plus this segment, without the '\n'.
                string line;
                if (carry.Count > 0)
                {
                    carry.AddRange(new ArraySegment<byte>(buffer, segmentStart, i - segmentStart));
                    line = Encoding.UTF8.GetString(carry.ToArray());
                    carry.Clear();
                }
                else
                {
                    line = Encoding.UTF8.GetString(buffer, segmentStart, i - segmentStart);
                }

                long thisLineStart = lineStart;
                lineStart = position + i + 1;
                lastFullLineEnd = lineStart;
                segmentStart = i + 1;

                if (line.Contains(Start, StringComparison.Ordinal))
                {
                    if (current != null) rounds.Add(new Round(current.ToString(), currentStart));
                    current = new StringBuilder(line);
                    currentStart = thisLineStart;
                    lastMarkerIsEnd = false;
                    continue;
                }

                if (current == null) continue;
                current.Append('\n').Append(line);

                if (line.Contains(End, StringComparison.Ordinal))
                {
                    rounds.Add(new Round(current.ToString(), currentStart));
                    current = null;
                    lastMarkerIsEnd = true;
                }
            }

            if (segmentStart < read) carry.AddRange(new ArraySegment<byte>(buffer, segmentStart, read - segmentStart));
            position += read;
        }

        if (current != null && closeOpenRound)
        {
            // Keep a trailing partial line too: the log will not be written again.
            if (carry.Count > 0) current.Append('\n').Append(Encoding.UTF8.GetString(carry.ToArray()));
            rounds.Add(new Round(current.ToString(), currentStart));
            return new Result(rounds, position, false, lastMarkerIsEnd);
        }

        if (current != null)
        {
            // Unfinished: read it again from its first byte next time.
            return new Result(rounds, currentStart, true, false);
        }

        // Everything up to the last whole line is dealt with; a partial last line is read again.
        return new Result(rounds, lastFullLineEnd, false, lastMarkerIsEnd);
    }
}

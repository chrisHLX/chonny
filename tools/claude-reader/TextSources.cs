using System.Text;
using System.Text.RegularExpressions;
using Windows.Data.Pdf;
using Windows.Graphics.Imaging;
using Windows.Media.Ocr;
using Windows.Storage;
using Windows.Storage.Streams;

namespace ClaudeReader;

/// <summary>
/// Turning things into speakable text: Claude's markdown, text files, PDFs; and splitting it into the
/// sentences the reader steps through.
/// </summary>
public static class TextSources
{
    /// <summary>
    /// Markdown into something worth listening to (the same rules as speak_reply.py's for_speech): code
    /// blocks become a mention, tables read row by row, headings and list marks go, links read their text,
    /// and the symbols Claude writes become words.
    /// </summary>
    public static string ForSpeech(string md)
    {
        var s = Regex.Replace(md, @"```.*?```", " There's code on screen. ", RegexOptions.Singleline);
        var lines = new List<string>();
        foreach (var raw in s.Split('\n'))
        {
            var l = raw.Trim();
            if (Regex.IsMatch(l, @"^\|?\s*:?-{2,}.*$")) continue;   // a table's separator row
            if (l.StartsWith('|')) l = string.Join(", ", l.Trim('|').Split('|').Select(c => c.Trim()).Where(c => c.Length > 0));
            l = Regex.Replace(l, @"^#{1,6}\s*", "");
            l = Regex.Replace(l, @"^([-*+]|\d+\.)\s+", "");
            l = Regex.Replace(l, @"(\*\*|__)(.+?)\1", "$2").Trim();
            if (l.Length > 0 && !Regex.IsMatch(l, @"[.!?:;,]$")) l += ".";
            lines.Add(l);
        }
        s = string.Join("\n", lines);
        s = Regex.Replace(s, @"\[([^\]]+)\]\([^)]+\)", "$1");
        s = Regex.Replace(s, @"https?://\S+", "a link");
        s = Regex.Replace(s, @"`([^`]*)`", "$1");
        s = Regex.Replace(s, @"(\*\*|__|\*|_)(?=\S)(.+?)(?<=\S)\1", "$2");
        foreach (var (a, b) in new[] { ("→", " to "), ("->", " to "), ("×", " times "), ("≥", " at least "), ("≤", " at most "),
                                       ("~", "about "), ("&", " and "), ("·", ", "), ("—", ", "), ("–", " to ") })
            s = s.Replace(a, b);
        s = Regex.Replace(s, @"[ \t]+", " ");
        s = Regex.Replace(s, @"\n{2,}", "\n");
        return s.Trim();
    }

    /// <summary>
    /// The text to read, in sentences: split after . ! ? and at line breaks, very short pieces joined to
    /// the next so it doesn't stop and start on "e.g." or a lone number.
    /// </summary>
    public static List<string> Sentences(string text)
    {
        var pieces = Regex.Split(text.Replace("\r", ""), @"(?<=[.!?])\s+(?=[A-Z0-9""'(\[])|\n+")
            .Select(p => p.Trim()).Where(p => p.Length > 0).ToList();
        var outList = new List<string>();
        var carry = "";
        foreach (var p in pieces)
        {
            var joined = carry.Length > 0 ? carry + " " + p : p;
            if (joined.Length < 25) { carry = joined; continue; }
            outList.Add(joined);
            carry = "";
        }
        if (carry.Length > 0)
        {
            if (outList.Count > 0) outList[^1] += " " + carry; else outList.Add(carry);
        }
        return outList;
    }

    /// <summary>A text or markdown file, read as UTF-8 (or what its byte-order mark says).</summary>
    public static string FromTextFile(string path)
    {
        var text = File.ReadAllText(path, Encoding.UTF8);
        return path.EndsWith(".md", StringComparison.OrdinalIgnoreCase) || path.EndsWith(".markdown", StringComparison.OrdinalIgnoreCase)
            ? ForSpeech(text) : Tidy(text);
    }

    /// <summary>
    /// A PDF's text, page by page: each page drawn by Windows' own PDF reader and read by Windows' own text
    /// recognition (OCR), so scanned PDFs work as well as typed ones and nothing else is needed.
    /// <paramref name="progress"/> gets "page 3 of 12".
    /// </summary>
    public static async Task<string> FromPdfAsync(string path, Action<string>? progress, CancellationToken ct)
    {
        var file = await StorageFile.GetFileFromPathAsync(Path.GetFullPath(path));
        var pdf = await PdfDocument.LoadFromFileAsync(file);
        var ocr = OcrEngine.TryCreateFromUserProfileLanguages()
            ?? throw new InvalidOperationException("Windows has no text recognition language installed (Settings > Time & language > Language).");
        var text = new StringBuilder();
        for (uint i = 0; i < pdf.PageCount; i++)
        {
            ct.ThrowIfCancellationRequested();
            progress?.Invoke($"Reading the PDF: page {i + 1} of {pdf.PageCount}…");
            using var page = pdf.GetPage(i);
            using var stream = new InMemoryRandomAccessStream();
            // wide enough for small print to be recognised, within what OCR accepts
            var width = (uint)Math.Min(OcrEngine.MaxImageDimension, Math.Max(1600, page.Size.Width * 2));
            await page.RenderToStreamAsync(stream, new PdfPageRenderOptions { DestinationWidth = width });
            var decoder = await BitmapDecoder.CreateAsync(stream);
            using var bitmap = await decoder.GetSoftwareBitmapAsync(BitmapPixelFormat.Bgra8, BitmapAlphaMode.Premultiplied);
            var result = await ocr.RecognizeAsync(bitmap);
            foreach (var line in result.Lines) text.AppendLine(line.Text);
            text.AppendLine();
        }
        return Tidy(JoinWrappedLines(text.ToString()));
    }

    /// <summary>
    /// A page's lines joined back into paragraphs: a line that doesn't end a sentence runs on into the
    /// next, so a PDF's line wrapping doesn't put a pause in the middle of every sentence.
    /// </summary>
    private static string JoinWrappedLines(string text)
    {
        var sb = new StringBuilder();
        var glue = false;   // the last line ended mid-word ("hyphen-"): the next carries straight on
        foreach (var raw in text.Replace("\r", "").Split('\n'))
        {
            var l = raw.Trim();
            if (l.Length == 0) { sb.Append("\n"); glue = false; continue; }
            if (sb.Length > 0 && sb[^1] != '\n' && !glue) sb.Append(' ');
            glue = false;
            if (l.EndsWith('-') && l.Length > 1 && char.IsLetter(l[^2])) { sb.Append(l[..^1]); glue = true; continue; }   // a hyphenated word split across lines
            sb.Append(l);
            if (Regex.IsMatch(l, @"[.!?:]$")) sb.Append('\n');
        }
        return sb.ToString();
    }

    /// <summary>Plain text made pleasant to hear: links named rather than spelled out, runs of space and blank lines closed up.</summary>
    public static string Tidy(string text)
    {
        var s = Regex.Replace(text, @"https?://\S+", "a link");
        s = Regex.Replace(s, @"[ \t]+", " ");
        s = Regex.Replace(s, @"\n\s*\n+", "\n");
        return s.Trim();
    }

    /// <summary>A short title for the list: the first words.</summary>
    public static string TitleOf(string text)
    {
        var first = Regex.Replace(text.Trim(), @"\s+", " ");
        return first.Length <= 70 ? first : first[..70].TrimEnd() + "…";
    }
}

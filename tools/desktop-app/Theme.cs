namespace MindCollector.Desktop;

/// <summary>The site's colours (tailwind.config.js), so the app and the pages it shows look like one thing.</summary>
internal static class Theme
{
    public static readonly Color Bg = Color.FromArgb(9, 9, 13);
    public static readonly Color Panel = Color.FromArgb(17, 17, 22);
    public static readonly Color Raised = Color.FromArgb(24, 24, 30);
    public static readonly Color Line = Color.FromArgb(44, 44, 56);
    public static readonly Color Ink = Color.FromArgb(240, 240, 242);
    public static readonly Color Muted = Color.FromArgb(138, 138, 154);
    public static readonly Color Gold = Color.FromArgb(200, 149, 44);
    public static readonly Color Won = Color.FromArgb(134, 239, 172);
    public static readonly Color Lost = Color.FromArgb(252, 165, 165);

    public static readonly Font Ui = new("Segoe UI", 9.5f);
    public static readonly Font Title = new("Segoe UI Semibold", 14f);

    public static Button Button(string text, bool primary = false)
    {
        var b = new Button
        {
            Text = text,
            FlatStyle = FlatStyle.Flat,
            AutoSize = true,
            Padding = new Padding(8, 2, 8, 2),
            BackColor = primary ? Gold : Raised,
            ForeColor = primary ? Bg : Ink,
            Cursor = Cursors.Hand,
            Margin = new Padding(6, 0, 0, 0),
        };
        b.FlatAppearance.BorderColor = primary ? Gold : Line;
        return b;
    }

    public static Icon AppIcon()
    {
        var path = Path.Combine(AppContext.BaseDirectory, "mindcollector.ico");
        return File.Exists(path) ? new Icon(path) : SystemIcons.Application;
    }
}

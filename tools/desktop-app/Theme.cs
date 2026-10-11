using System.Drawing.Drawing2D;
using System.Runtime.InteropServices;

namespace MindCollector.Desktop;

/// <summary>
/// The site's colours (tailwind.config.js) and Spotify's desktop layout (2026-10-11): black frame,
/// #121212 panels with rounded corners, white text, grey secondary text, gold for the one thing to
/// press. The pages the app shows use the same values (resources/views/desktop/partials/styles.blade.php).
/// </summary>
internal static class Theme
{
    public static readonly Color Frame = Color.FromArgb(0, 0, 0);
    public static readonly Color Bg = Frame;
    public static readonly Color Panel = Color.FromArgb(18, 18, 18);
    public static readonly Color Raised = Color.FromArgb(35, 35, 35);
    public static readonly Color Selected = Color.FromArgb(42, 42, 42);
    public static readonly Color Line = Color.FromArgb(40, 40, 40);
    public static readonly Color Ink = Color.FromArgb(255, 255, 255);
    public static readonly Color Muted = Color.FromArgb(179, 179, 179);
    public static readonly Color Subtle = Color.FromArgb(106, 106, 106);
    public static readonly Color Gold = Color.FromArgb(200, 149, 44);
    public static readonly Color GoldLight = Color.FromArgb(232, 184, 75);
    public static readonly Color Won = Color.FromArgb(134, 239, 172);
    public static readonly Color Lost = Color.FromArgb(252, 165, 165);
    public static readonly Color WonBg = Color.FromArgb(22, 52, 32);
    public static readonly Color LostBg = Color.FromArgb(62, 24, 26);

    public static readonly Font Ui = new("Segoe UI", 9.5f);
    public static readonly Font Bold = new("Segoe UI Semibold", 10.5f);
    public static readonly Font Small = new("Segoe UI", 9f);
    public static readonly Font Title = new("Segoe UI", 15f, FontStyle.Bold);
    public static readonly Font Heading = new("Segoe UI", 11.5f, FontStyle.Bold);

    /// <summary>Windows 11's icon font, with Windows 10's as the fallback.</summary>
    public static readonly Font Icons = new(FontFamily.Families.Any(f => f.Name == "Segoe Fluent Icons") ? "Segoe Fluent Icons" : "Segoe MDL2 Assets", 12f);

    public static Button Button(string text, bool primary = false) => new PillButton(text, primary);

    public static Icon AppIcon()
    {
        // A downloaded exe has no .ico beside it, but the icon is built into the exe itself.
        var path = Path.Combine(AppContext.BaseDirectory, "mindcollector.ico");
        if (File.Exists(path)) return new Icon(path);
        try { if (Environment.ProcessPath is { } exe && Icon.ExtractAssociatedIcon(exe) is { } own) return own; } catch { }
        return SystemIcons.Application;
    }

    public static GraphicsPath Rounded(Rectangle r, int radius)
    {
        var d = radius * 2;
        var p = new GraphicsPath();
        if (radius <= 0) { p.AddRectangle(r); return p; }
        p.AddArc(r.X, r.Y, d, d, 180, 90);
        p.AddArc(r.Right - d - 1, r.Y, d, d, 270, 90);
        p.AddArc(r.Right - d - 1, r.Bottom - d - 1, d, d, 0, 90);
        p.AddArc(r.X, r.Bottom - d - 1, d, d, 90, 90);
        p.CloseFigure();
        return p;
    }

    [DllImport("dwmapi.dll")]
    private static extern int DwmSetWindowAttribute(IntPtr hwnd, int attr, ref int value, int size);

    /// <summary>A dark title bar (Windows 10 20H1+), black on Windows 11, so the window has no white strip.</summary>
    public static void DarkTitleBar(Form form)
    {
        form.HandleCreated += (_, _) =>
        {
            try
            {
                var on = 1;
                DwmSetWindowAttribute(form.Handle, 20, ref on, sizeof(int));        // DWMWA_USE_IMMERSIVE_DARK_MODE
                var black = 0x00000000;
                DwmSetWindowAttribute(form.Handle, 35, ref black, sizeof(int));     // DWMWA_CAPTION_COLOR (Windows 11)
            }
            catch { /* older Windows: the default title bar */ }
        };
    }
}

/// <summary>A pill: gold with black text for the main action, an outline otherwise. Still a Button,
/// so Click, AcceptButton and DialogResult work as before.</summary>
internal sealed class PillButton : Button
{
    private readonly bool _primary;
    private bool _hover;

    public PillButton(string text, bool primary)
    {
        _primary = primary;
        Text = text;
        Font = new Font("Segoe UI Semibold", 9.75f);
        FlatStyle = FlatStyle.Flat;
        FlatAppearance.BorderSize = 0;
        Cursor = Cursors.Hand;
        Margin = new Padding(8, 0, 0, 0);
        AutoSize = false;
        Height = 36;
        Width = TextRenderer.MeasureText(text, Font).Width + 40;
        SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.UserPaint | ControlStyles.SupportsTransparentBackColor, true);
        BackColor = Color.Transparent;
        MouseEnter += (_, _) => { _hover = true; Invalidate(); };
        MouseLeave += (_, _) => { _hover = false; Invalidate(); };
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        g.SmoothingMode = SmoothingMode.AntiAlias;
        g.Clear(Parent?.BackColor ?? Theme.Panel);
        var r = new Rectangle(0, 0, Width - 1, Height - 1);
        using var path = Theme.Rounded(r, Height / 2);
        if (_primary)
        {
            using var fill = new SolidBrush(_hover ? Theme.GoldLight : Theme.Gold);
            g.FillPath(fill, path);
        }
        else
        {
            using var pen = new Pen(_hover ? Theme.Ink : Theme.Subtle, 1f);
            if (_hover) { using var f = new SolidBrush(Color.FromArgb(18, 255, 255, 255)); g.FillPath(f, path); }
            g.DrawPath(pen, path);
        }
        TextRenderer.DrawText(g, Text, Font, r, _primary ? Color.Black : Theme.Ink,
            TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.NoPadding);
    }
}

/// <summary>A panel with rounded corners on the black frame, as Spotify's.</summary>
internal sealed class RoundPanel : Panel
{
    public int Radius { get; set; } = 8;
    public Color Fill { get; set; } = Theme.Panel;

    public RoundPanel()
    {
        SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw | ControlStyles.UserPaint, true);
        BackColor = Theme.Frame;
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
        e.Graphics.Clear(Theme.Frame);
        using var path = Theme.Rounded(new Rectangle(0, 0, Width - 1, Height - 1), Radius);
        using var b = new SolidBrush(Fill);
        e.Graphics.FillPath(b, path);
    }
}

/// <summary>One row of the navigation: an icon and a name; white when it is the open page.</summary>
internal sealed class NavItem : Control
{
    private bool _hover;
    public bool Active { get; set; }
    public string Glyph { get; }

    public NavItem(string glyph, string text)
    {
        Glyph = glyph;
        Text = text;
        Height = 40;
        Cursor = Cursors.Hand;
        Font = new Font("Segoe UI Semibold", 11f);
        BackColor = Theme.Panel;
        SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.UserPaint | ControlStyles.ResizeRedraw, true);
        MouseEnter += (_, _) => { _hover = true; Invalidate(); };
        MouseLeave += (_, _) => { _hover = false; Invalidate(); };
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        g.SmoothingMode = SmoothingMode.AntiAlias;
        g.Clear(Theme.Panel);
        if (Active)
        {
            using var path = Theme.Rounded(new Rectangle(0, 2, Width - 1, Height - 5), 6);
            using var fill = new SolidBrush(Theme.Raised);
            g.FillPath(fill, path);
            using var bar = new SolidBrush(Theme.Gold);
            g.FillRectangle(bar, 0, 10, 3, Height - 20);
        }
        var color = Active || _hover ? Theme.Ink : Theme.Muted;
        TextRenderer.DrawText(g, Glyph, Theme.Icons, new Rectangle(14, 0, 28, Height), color, TextFormatFlags.VerticalCenter | TextFormatFlags.HorizontalCenter);
        TextRenderer.DrawText(g, Text, Font, new Rectangle(52, 0, Width - 56, Height), color, TextFormatFlags.VerticalCenter | TextFormatFlags.EndEllipsis);
    }
}

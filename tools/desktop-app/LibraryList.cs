using System.Drawing.Drawing2D;

namespace MindCollector.Desktop;

/// <summary>One row of the library: a title, a line under it, and a result pill on the right.</summary>
internal sealed record LibraryRow(string Title, string Sub, string? Badge = null, int Tone = 0, string? File = null);

/// <summary>
/// The library on the left of the window, drawn the way Spotify draws "Your Library" (2026-10-11):
/// two-line rows, a lighter row under the mouse, the open one lighter still with a gold mark.
/// </summary>
internal sealed class LibraryList : ListBox
{
    private int _hover = -1;

    public LibraryList()
    {
        DrawMode = DrawMode.OwnerDrawFixed;
        ItemHeight = 58;
        BorderStyle = BorderStyle.None;
        BackColor = Theme.Panel;
        ForeColor = Theme.Ink;
        IntegralHeight = false;
        SetStyle(ControlStyles.OptimizedDoubleBuffer, true);
        MouseMove += (_, e) =>
        {
            var i = IndexFromPoint(e.Location);
            if (i == _hover) return;
            var old = _hover;
            _hover = i;
            if (old >= 0 && old < Items.Count) Invalidate(GetItemRectangle(old));
            if (i >= 0) Invalidate(GetItemRectangle(i));
        };
        MouseLeave += (_, _) =>
        {
            var old = _hover;
            _hover = -1;
            if (old >= 0 && old < Items.Count) Invalidate(GetItemRectangle(old));
        };
    }

    public string? SelectedFile => SelectedItem is LibraryRow r ? r.File : null;

    [System.Runtime.InteropServices.DllImport("uxtheme.dll", CharSet = System.Runtime.InteropServices.CharSet.Unicode)]
    private static extern int SetWindowTheme(IntPtr hwnd, string? app, string? idList);

    /// <summary>Windows' dark scrollbar (Windows 10 1809+), not the white default.</summary>
    protected override void OnHandleCreated(EventArgs e)
    {
        base.OnHandleCreated(e);
        try { SetWindowTheme(Handle, "DarkMode_Explorer", null); } catch { }
    }

    /// <summary>
    /// The list is a native control, so double buffering it does nothing: Windows wipes it, then each
    /// row paints its background, its hover fill and its text straight onto the screen, and moving the
    /// mouse between rows flickered (2026-10-25). Each row is drawn off-screen and copied in one go,
    /// and the wipe covers only the empty space under the last row.
    /// </summary>
    protected override void WndProc(ref Message m)
    {
        const int WM_ERASEBKGND = 0x0014;
        if (m.Msg == WM_ERASEBKGND)
        {
            var bottom = Items.Count == 0 ? 0 : GetItemRectangle(Items.Count - 1).Bottom;
            if (bottom < ClientSize.Height)
            {
                using var g = Graphics.FromHdc(m.WParam);
                using var bg = new SolidBrush(Theme.Panel);
                g.FillRectangle(bg, 0, Math.Max(0, bottom), ClientSize.Width, ClientSize.Height - Math.Max(0, bottom));
            }
            m.Result = 1;
            return;
        }
        base.WndProc(ref m);
    }

    protected override void OnDrawItem(DrawItemEventArgs e)
    {
        if (e.Bounds.Width <= 0 || e.Bounds.Height <= 0) return;
        using var buffer = BufferedGraphicsManager.Current.Allocate(e.Graphics, e.Bounds);
        DrawRow(buffer.Graphics, e.Bounds, e.Index, e.State);
        buffer.Render(e.Graphics);
    }

    /// <summary>The buffer draws a row through a translation, which TextRenderer ignores unless told (rows after the first lost their text).</summary>
    private const TextFormatFlags Buffered = TextFormatFlags.PreserveGraphicsTranslateTransform | TextFormatFlags.PreserveGraphicsClipping;

    private void DrawRow(Graphics g, Rectangle bounds, int index, DrawItemState state)
    {
        g.SmoothingMode = SmoothingMode.AntiAlias;
        using (var bg = new SolidBrush(Theme.Panel)) g.FillRectangle(bg, bounds);
        if (index < 0 || index >= Items.Count || Items[index] is not LibraryRow row) return;

        var selected = (state & DrawItemState.Selected) != 0;
        var box = new Rectangle(bounds.X, bounds.Y + 1, bounds.Width - 1, bounds.Height - 3);
        if (selected || index == _hover)
        {
            using var path = Theme.Rounded(box, 6);
            using var fill = new SolidBrush(selected ? Theme.Selected : Theme.Raised);
            g.FillPath(fill, path);
        }
        if (selected)
        {
            using var bar = new SolidBrush(Theme.Gold);
            g.FillRectangle(bar, box.X, box.Y + 12, 3, box.Height - 24);
        }

        var badgeWidth = 0;
        if (!string.IsNullOrEmpty(row.Badge))
        {
            var size = TextRenderer.MeasureText(row.Badge, Theme.Small);
            badgeWidth = size.Width + 14;
            var pill = new Rectangle(box.Right - badgeWidth - 12, box.Y + (box.Height - 22) / 2, badgeWidth, 22);
            using var path = Theme.Rounded(pill, 11);
            using var fill = new SolidBrush(row.Tone > 0 ? Theme.WonBg : row.Tone < 0 ? Theme.LostBg : Theme.Selected);
            g.FillPath(fill, path);
            TextRenderer.DrawText(g, row.Badge, Theme.Small, pill, row.Tone > 0 ? Theme.Won : row.Tone < 0 ? Theme.Lost : Theme.Muted,
                TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.NoPadding | Buffered);
        }

        var textWidth = box.Width - 24 - (badgeWidth > 0 ? badgeWidth + 16 : 0);
        TextRenderer.DrawText(g, row.Title, Theme.Bold, new Rectangle(box.X + 14, box.Y + 9, textWidth, 22), Theme.Ink,
            TextFormatFlags.EndEllipsis | TextFormatFlags.NoPrefix | Buffered);
        TextRenderer.DrawText(g, row.Sub, Theme.Small, new Rectangle(box.X + 14, box.Y + 31, textWidth, 20), Theme.Muted,
            TextFormatFlags.EndEllipsis | TextFormatFlags.NoPrefix | Buffered);
    }
}

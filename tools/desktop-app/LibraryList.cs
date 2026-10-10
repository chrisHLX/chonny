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

    protected override void OnDrawItem(DrawItemEventArgs e)
    {
        var g = e.Graphics;
        g.SmoothingMode = SmoothingMode.AntiAlias;
        using (var bg = new SolidBrush(Theme.Panel)) g.FillRectangle(bg, e.Bounds);
        if (e.Index < 0 || e.Index >= Items.Count || Items[e.Index] is not LibraryRow row) return;

        var selected = (e.State & DrawItemState.Selected) != 0;
        var box = new Rectangle(e.Bounds.X, e.Bounds.Y + 1, e.Bounds.Width - 1, e.Bounds.Height - 3);
        if (selected || e.Index == _hover)
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
                TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.NoPadding);
        }

        var textWidth = box.Width - 24 - (badgeWidth > 0 ? badgeWidth + 16 : 0);
        TextRenderer.DrawText(g, row.Title, Theme.Bold, new Rectangle(box.X + 14, box.Y + 9, textWidth, 22), Theme.Ink,
            TextFormatFlags.EndEllipsis | TextFormatFlags.NoPrefix);
        TextRenderer.DrawText(g, row.Sub, Theme.Small, new Rectangle(box.X + 14, box.Y + 31, textWidth, 20), Theme.Muted,
            TextFormatFlags.EndEllipsis | TextFormatFlags.NoPrefix);
    }
}

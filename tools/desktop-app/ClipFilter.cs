using System.Drawing.Drawing2D;

namespace MindCollector.Desktop;

/// <summary>
/// Above the Videos and Fights lists (0.6.36): a search box (a name you gave a clip, a player, a zone, a
/// bracket, a date) and a Starred toggle. Changed fires a moment after typing stops, and at once for the
/// toggle. Esc in the box clears it; Ctrl+F from the list puts the cursor in it (MainForm).
/// </summary>
internal sealed class ClipFilter : Panel
{
    private readonly SearchField _field = new();
    private readonly StarToggle _star = new();
    private readonly System.Windows.Forms.Timer _wait = new() { Interval = 220 };

    public event Action? Changed;

    public string Query => _field.Box.Text.Trim();
    public bool StarredOnly => _star.On;
    public bool Active => Query.Length > 0 || StarredOnly;

    public ClipFilter()
    {
        Dock = DockStyle.Top;
        Height = 46;
        BackColor = Theme.Panel;
        Padding = new Padding(2, 4, 2, 8);
        _star.Dock = DockStyle.Right;
        _field.Dock = DockStyle.Fill;
        Controls.Add(_field);
        Controls.Add(new Panel { Dock = DockStyle.Right, Width = 8, BackColor = Theme.Panel });
        Controls.Add(_star);

        _wait.Tick += (_, _) => { _wait.Stop(); Changed?.Invoke(); };
        _field.Box.TextChanged += (_, _) => { _field.Invalidate(); _wait.Stop(); _wait.Start(); };
        _field.Box.KeyDown += (_, e) =>
        {
            if (e.KeyCode == Keys.Escape && _field.Box.TextLength > 0) { _field.Box.Clear(); e.SuppressKeyPress = true; }
            if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; _wait.Stop(); Changed?.Invoke(); }
        };
        _field.Cleared += () => { _wait.Stop(); Changed?.Invoke(); };
        _star.Click += (_, _) => { _star.On = !_star.On; _star.Invalidate(); Changed?.Invoke(); };
    }

    public void FocusSearch()
    {
        _field.Box.Focus();
        _field.Box.SelectAll();
    }

    /// <summary>The rounded box with a magnifier on the left and, with something typed, an × on the right.</summary>
    private sealed class SearchField : Panel
    {
        public readonly TextBox Box = new()
        {
            BorderStyle = BorderStyle.None,
            BackColor = Theme.Raised,
            ForeColor = Theme.Ink,
            Font = new Font("Segoe UI", 10f),
            PlaceholderText = "Search name, player or zone",
            Dock = DockStyle.Fill,
        };

        public event Action? Cleared;
        private bool _overClear;

        public SearchField()
        {
            SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.UserPaint | ControlStyles.ResizeRedraw, true);
            BackColor = Theme.Panel;
            Padding = new Padding(34, 8, 30, 0);
            Controls.Add(Box);
            Cursor = Cursors.IBeam;
            MouseDown += (_, e) =>
            {
                if (OnClear(e.Location) && Box.TextLength > 0) { Box.Clear(); Cleared?.Invoke(); }
                Box.Focus();
            };
            MouseMove += (_, e) =>
            {
                var over = OnClear(e.Location) && Box.TextLength > 0;
                Cursor = over ? Cursors.Hand : Cursors.IBeam;
                if (over != _overClear) { _overClear = over; Invalidate(); }
            };
            MouseLeave += (_, _) => { if (_overClear) { _overClear = false; Invalidate(); } };
            Box.GotFocus += (_, _) => Invalidate();
            Box.LostFocus += (_, _) => Invalidate();
        }

        private bool OnClear(Point p) => p.X >= Width - 30;

        protected override void OnPaint(PaintEventArgs e)
        {
            var g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.Clear(Theme.Panel);
            var r = new Rectangle(0, 0, Width - 1, Height - 1);
            using (var path = Theme.Rounded(r, 8))
            {
                using var fill = new SolidBrush(Theme.Raised);
                g.FillPath(fill, path);
                using var pen = new Pen(Box.Focused ? Theme.Gold : Theme.Line, 1f);
                g.DrawPath(pen, path);
            }
            const TextFormatFlags centre = TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.NoPadding;
            TextRenderer.DrawText(g, "", Theme.SmallIcons, new Rectangle(4, 0, 30, Height), Theme.Muted, centre);
            if (Box.TextLength > 0)
                TextRenderer.DrawText(g, "", Theme.SmallIcons, new Rectangle(Width - 30, 0, 26, Height), _overClear ? Theme.Ink : Theme.Muted, centre);
        }
    }

    /// <summary>"★ Starred": an outline pill, gold when on.</summary>
    private sealed class StarToggle : Control
    {
        public bool On;
        private bool _hover;

        public StarToggle()
        {
            SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.UserPaint | ControlStyles.ResizeRedraw, true);
            Width = 102;
            Cursor = Cursors.Hand;
            BackColor = Theme.Panel;
            MouseEnter += (_, _) => { _hover = true; Invalidate(); };
            MouseLeave += (_, _) => { _hover = false; Invalidate(); };
            AccessibleRole = AccessibleRole.CheckButton;
            AccessibleName = "Starred only";
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            var g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.Clear(Theme.Panel);
            var r = new Rectangle(0, 0, Width - 1, Height - 1);
            using var path = Theme.Rounded(r, 8);
            if (On)
            {
                using var fill = new SolidBrush(_hover ? Theme.GoldLight : Theme.Gold);
                g.FillPath(fill, path);
            }
            else
            {
                using var fill = new SolidBrush(_hover ? Theme.Selected : Theme.Raised);
                g.FillPath(fill, path);
            }
            var ink = On ? Color.Black : Theme.Ink;
            var label = "Starred";
            var star = On ? "★" : "☆";
            using var starFont = new Font("Segoe UI Symbol", 10f);
            var lw = TextRenderer.MeasureText(g, label, Theme.Ui, Size.Empty, TextFormatFlags.NoPadding).Width;
            var sw = TextRenderer.MeasureText(g, star, starFont, Size.Empty, TextFormatFlags.NoPadding).Width;
            var x = (Width - lw - sw - 6) / 2;
            const TextFormatFlags flags = TextFormatFlags.NoPadding | TextFormatFlags.VerticalCenter | TextFormatFlags.Left;
            TextRenderer.DrawText(g, star, starFont, new Rectangle(x, 0, sw + 2, Height), On ? Color.Black : Theme.Gold, flags);
            TextRenderer.DrawText(g, label, Theme.Ui, new Rectangle(x + sw + 6, 0, lw + 2, Height), ink, flags);
        }
    }
}

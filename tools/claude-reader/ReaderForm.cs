using System.Runtime.InteropServices;
using System.Text.Json;

namespace ClaudeReader;

/// <summary>
/// The reader's window and tray icon. Claude's replies arrive in the inbox folder (written by the
/// Claude Code hook, speak_reply.py); files and pasted text come from the buttons or by dropping a file
/// on the window. Every reply is kept in the list, so the last one can be replayed (button or
/// Ctrl+Alt+R) and earlier ones picked.
///
/// Game-safe (2026-11-12, after WoW froze as the old always-on-top speech window appeared over it): the
/// window is never always-on-top, never takes focus when it pops up, and doesn't pop up at all while a
/// fullscreen window (a game) is in front. Ctrl+Alt+P pauses, Ctrl+Alt+S stops, from anywhere.
/// </summary>
public sealed class ReaderForm : Form
{
    public static readonly string Dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ClaudeReader");
    public static readonly string Inbox = Path.Combine(Dir, "inbox");
    private static readonly string HistoryFile = Path.Combine(Dir, "history.json");
    private static readonly string SettingsFile = Path.Combine(Dir, "settings.json");

    public sealed class Item
    {
        public string Kind { get; set; } = "reply";   // reply, file, pasted
        public string Title { get; set; } = "";
        public string Text { get; set; } = "";
        public DateTime At { get; set; } = DateTime.Now;
    }

    public sealed class Settings
    {
        public string Voice { get; set; } = "George";
        public double Rate { get; set; } = 1.1;
        public bool ReadReplies { get; set; } = true;
        public bool PopUp { get; set; } = true;
    }

    private static readonly Color Back = Color.FromArgb(18, 18, 18);
    private static readonly Color Panel = Color.FromArgb(30, 30, 30);
    private static readonly Color Gold = Color.FromArgb(200, 149, 44);
    private static readonly Color Muted = Color.FromArgb(150, 150, 150);

    private readonly Speaker _speaker = new();
    private readonly List<Item> _history = [];
    private Settings _settings = new();
    private readonly NotifyIcon _tray = new();
    private readonly ListBox _list = new();
    private readonly RichTextBox _view = new();
    private readonly Label _status = new();
    private readonly Button _pause = new();
    private readonly TrackBar _rate = new();
    private readonly Label _rateLabel = new();
    private readonly ComboBox _voice = new();
    private readonly CheckBox _readReplies = new();
    private readonly CheckBox _popUp = new();
    private readonly System.Windows.Forms.Timer _inboxTimer = new() { Interval = 300 };
    private Item? _current;
    private List<string> _sentences = [];
    private List<int> _starts = [];
    private bool _exiting;

    public ReaderForm()
    {
        Directory.CreateDirectory(Inbox);
        Text = "Claude Reader";
        Icon = MakeIcon();
        ClientSize = new Size(820, 520);
        MinimumSize = new Size(640, 380);
        BackColor = Back;
        ForeColor = Color.White;
        Font = new Font("Segoe UI", 9.5f);
        StartPosition = FormStartPosition.Manual;
        var area = Screen.PrimaryScreen!.WorkingArea;
        Location = new Point(area.Right - Width - 24, area.Bottom - Height - 24);
        AllowDrop = true;
        ShowInTaskbar = true;

        LoadSettings();
        LoadHistory();
        BuildLayout();
        BuildTray();

        _speaker.SetVoice(_settings.Voice);
        _speaker.SetRate(_settings.Rate);
        _speaker.SentenceStarted += i => BeginInvoke(() => Highlight(i));
        _speaker.Finished += () => BeginInvoke(() => { Highlight(-1); UpdateButtons(); SetStatus("Finished."); });
        _speaker.Failed += m => BeginInvoke(() => SetStatus("Couldn't speak: " + m));

        DragEnter += (_, e) => e.Effect = e.Data?.GetDataPresent(DataFormats.FileDrop) == true ? DragDropEffects.Copy : DragDropEffects.None;
        DragDrop += async (_, e) => { if (e.Data?.GetData(DataFormats.FileDrop) is string[] { Length: > 0 } files) await OpenFileAsync(files[0]); };
        FormClosing += (_, e) => { if (!_exiting && e.CloseReason == CloseReason.UserClosing) { e.Cancel = true; Hide(); } };
        _inboxTimer.Tick += async (_, _) => await ReadInboxAsync();
        _inboxTimer.Start();
    }

    /// <summary>Opening from the tray or by a reply never steals focus from what you're doing.</summary>
    protected override bool ShowWithoutActivation => true;

    protected override void OnHandleCreated(EventArgs e)
    {
        base.OnHandleCreated(e);
        RegisterHotKey(Handle, 1, ModControl | ModAlt, 'P');
        RegisterHotKey(Handle, 2, ModControl | ModAlt, 'S');
        RegisterHotKey(Handle, 3, ModControl | ModAlt, 'R');
    }

    protected override void WndProc(ref Message m)
    {
        if (m.Msg == 0x0312)   // WM_HOTKEY
        {
            switch ((int)m.WParam)
            {
                case 1: PauseResume(); break;
                case 2: StopReading(); break;
                case 3: ReplayLast(); break;
            }
        }
        base.WndProc(ref m);
    }

    // ---------------------------------------------------------------- layout

    private void BuildLayout()
    {
        var top = new FlowLayoutPanel { Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(10, 10, 10, 4), BackColor = Back, WrapContents = true };
        top.Controls.Add(MakeButton("Replay last", (_, _) => ReplayLast(), primary: true));
        top.Controls.Add(MakeButton("Open file…", async (_, _) => await PickFileAsync()));
        top.Controls.Add(MakeButton("Read copied text", (_, _) => ReadClipboard()));
        top.Controls.Add(Spacer());
        top.Controls.Add(MakeButton("Back", (_, _) => _speaker.Skip(-1)));
        StyleButton(_pause, "Pause");
        _pause.Click += (_, _) => PauseResume();
        top.Controls.Add(_pause);
        top.Controls.Add(MakeButton("Next", (_, _) => _speaker.Skip(1)));
        top.Controls.Add(MakeButton("Stop", (_, _) => StopReading()));

        var options = new FlowLayoutPanel { Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(10, 0, 10, 6), BackColor = Back, WrapContents = true };
        options.Controls.Add(new Label { Text = "Speed", AutoSize = true, ForeColor = Muted, Margin = new Padding(0, 8, 4, 0) });
        _rate.Minimum = 5; _rate.Maximum = 25; _rate.TickFrequency = 5; _rate.SmallChange = 1; _rate.Width = 140; _rate.BackColor = Back;
        _rate.Value = (int)Math.Round(Math.Clamp(_settings.Rate, 0.5, 2.5) * 10);
        _rate.ValueChanged += (_, _) => { _settings.Rate = _rate.Value / 10.0; _speaker.SetRate(_settings.Rate); _rateLabel.Text = $"{_settings.Rate:0.0}x"; SaveSettings(); };
        options.Controls.Add(_rate);
        _rateLabel.Text = $"{_settings.Rate:0.0}x"; _rateLabel.AutoSize = true; _rateLabel.ForeColor = Muted; _rateLabel.Margin = new Padding(0, 8, 14, 0);
        options.Controls.Add(_rateLabel);
        options.Controls.Add(new Label { Text = "Voice", AutoSize = true, ForeColor = Muted, Margin = new Padding(0, 8, 4, 0) });
        _voice.DropDownStyle = ComboBoxStyle.DropDownList; _voice.Width = 200; _voice.BackColor = Panel; _voice.ForeColor = Color.White; _voice.FlatStyle = FlatStyle.Flat;
        foreach (var v in Speaker.Voices) _voice.Items.Add(v);
        var chosen = Speaker.Voices.FirstOrDefault(v => v.Contains(_settings.Voice, StringComparison.OrdinalIgnoreCase));
        if (chosen != null) _voice.SelectedItem = chosen;
        _voice.SelectedIndexChanged += (_, _) => { _settings.Voice = _voice.SelectedItem as string ?? _settings.Voice; _speaker.SetVoice(_settings.Voice); SaveSettings(); };
        options.Controls.Add(_voice);
        _readReplies.Text = "Read Claude's replies"; _readReplies.AutoSize = true; _readReplies.Checked = _settings.ReadReplies; _readReplies.Margin = new Padding(14, 6, 0, 0);
        _readReplies.CheckedChanged += (_, _) => { _settings.ReadReplies = _readReplies.Checked; SaveSettings(); };
        options.Controls.Add(_readReplies);
        _popUp.Text = "Show this window when reading (never over a fullscreen game)"; _popUp.AutoSize = true; _popUp.Checked = _settings.PopUp; _popUp.Margin = new Padding(14, 6, 0, 0);
        _popUp.CheckedChanged += (_, _) => { _settings.PopUp = _popUp.Checked; SaveSettings(); };
        options.Controls.Add(_popUp);

        var split = new SplitContainer { Dock = DockStyle.Fill, BackColor = Back, SplitterWidth = 6 };
        _list.Dock = DockStyle.Fill; _list.BackColor = Panel; _list.ForeColor = Color.White; _list.BorderStyle = BorderStyle.None;
        _list.DrawMode = DrawMode.OwnerDrawFixed; _list.ItemHeight = 40;
        _list.DrawItem += DrawListItem;
        _list.DoubleClick += (_, _) => { if (_list.SelectedIndex >= 0) Read(_history[_list.SelectedIndex]); };
        _list.KeyDown += (_, e) => { if (e.KeyCode == Keys.Enter && _list.SelectedIndex >= 0) Read(_history[_list.SelectedIndex]); };
        split.Panel1.Controls.Add(_list);
        split.Panel1.Controls.Add(new Label { Text = "Recent (double-click to read)", Dock = DockStyle.Top, ForeColor = Muted, Height = 22, Padding = new Padding(4, 3, 0, 0) });
        _view.Dock = DockStyle.Fill; _view.ReadOnly = true; _view.BackColor = Panel; _view.ForeColor = Color.Gainsboro; _view.BorderStyle = BorderStyle.None;
        _view.Font = new Font("Segoe UI", 11f);
        _view.DoubleClick += (_, _) => JumpToCaret();
        split.Panel2.Controls.Add(_view);
        split.Panel2.Controls.Add(new Label { Text = "Double-click a sentence to read from there", Dock = DockStyle.Top, ForeColor = Muted, Height = 22, Padding = new Padding(4, 3, 0, 0) });

        _status.Dock = DockStyle.Bottom; _status.Height = 26; _status.ForeColor = Muted; _status.Padding = new Padding(10, 5, 0, 0);
        _status.Text = "Ctrl+Alt+R replays the last reply, Ctrl+Alt+P pauses, Ctrl+Alt+S stops. Drop a .txt, .md or .pdf here to hear it.";

        Controls.Add(split);
        Controls.Add(_status);
        Controls.Add(options);
        Controls.Add(top);
        Shown += (_, _) => split.SplitterDistance = 260;
        RefreshList();
        UpdateButtons();
    }

    private void DrawListItem(object? sender, DrawItemEventArgs e)
    {
        if (e.Index < 0 || e.Index >= _history.Count) return;
        var item = _history[e.Index];
        var selected = (e.State & DrawItemState.Selected) != 0;
        using var bg = new SolidBrush(selected ? Color.FromArgb(55, 45, 20) : Panel);
        e.Graphics.FillRectangle(bg, e.Bounds);
        var kind = item.Kind switch { "reply" => "Claude", "file" => "File", _ => "Pasted" };
        TextRenderer.DrawText(e.Graphics, $"{kind} · {When(item.At)}", new Font("Segoe UI", 8f), new Point(e.Bounds.X + 6, e.Bounds.Y + 3), item == _current ? Gold : Muted);
        TextRenderer.DrawText(e.Graphics, item.Title, Font, new Rectangle(e.Bounds.X + 6, e.Bounds.Y + 18, e.Bounds.Width - 10, 20), Color.White, TextFormatFlags.EndEllipsis);
    }

    private static string When(DateTime t) => t.Date == DateTime.Today ? t.ToString("HH:mm") : t.ToString("d MMM HH:mm");

    private Button MakeButton(string text, EventHandler click, bool primary = false)
    {
        var b = new Button();
        StyleButton(b, text, primary);
        b.Click += click;
        return b;
    }

    private static void StyleButton(Button b, string text, bool primary = false)
    {
        b.Text = text; b.AutoSize = true; b.FlatStyle = FlatStyle.Flat; b.Padding = new Padding(8, 2, 8, 2); b.Margin = new Padding(0, 0, 6, 0);
        b.BackColor = primary ? Gold : Panel; b.ForeColor = primary ? Color.Black : Color.White;
        b.FlatAppearance.BorderColor = primary ? Gold : Color.FromArgb(70, 70, 70);
    }

    private static Control Spacer() => new Label { Width = 14, Height = 1 };

    private void BuildTray()
    {
        _tray.Icon = Icon;
        _tray.Text = "Claude Reader";
        _tray.Visible = true;
        var menu = new ContextMenuStrip();
        menu.Items.Add("Open", null, (_, _) => ShowReader(activate: true));
        menu.Items.Add("Replay last reply   Ctrl+Alt+R", null, (_, _) => ReplayLast());
        menu.Items.Add("Pause / resume   Ctrl+Alt+P", null, (_, _) => PauseResume());
        menu.Items.Add("Stop   Ctrl+Alt+S", null, (_, _) => StopReading());
        menu.Items.Add("Read copied text", null, (_, _) => ReadClipboard());
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Exit", null, (_, _) => { _exiting = true; _speaker.Dispose(); _tray.Visible = false; Application.Exit(); });
        _tray.ContextMenuStrip = menu;
        _tray.MouseClick += (_, e) => { if (e.Button == MouseButtons.Left) ShowReader(activate: true); };
    }

    // ---------------------------------------------------------------- reading

    private void Read(Item item, int from = 0)
    {
        _current = item;
        _sentences = TextSources.Sentences(item.Text);
        _view.Text = string.Join("\n", _sentences);
        _starts = [];
        var at = 0;
        foreach (var s in _sentences) { _starts.Add(at); at += s.Length + 1; }
        _speaker.Play(_sentences, from);
        SetStatus($"Reading: {item.Title}");
        _list.Invalidate();
        UpdateButtons();
    }

    private void ReplayLast()
    {
        var last = _history.FirstOrDefault(h => h.Kind == "reply");
        if (last == null) { SetStatus("No reply from Claude yet."); return; }
        Read(last);
        PopUpIfFree();
    }

    private void PauseResume()
    {
        _speaker.PauseResume();
        UpdateButtons();
        SetStatus(_speaker.Paused ? "Paused." : _speaker.Playing ? $"Reading: {_current?.Title}" : _status.Text);
    }

    private void StopReading()
    {
        _speaker.Stop();
        Highlight(-1);
        UpdateButtons();
        SetStatus("Stopped.");
    }

    private void JumpToCaret()
    {
        if (_current == null || _starts.Count == 0) return;
        var pos = _view.SelectionStart;
        var i = _starts.FindLastIndex(s => s <= pos);
        if (i < 0) i = 0;
        if (_speaker.Playing) _speaker.JumpTo(i); else Read(_current, i);
        UpdateButtons();
    }

    private void Highlight(int i)
    {
        _view.SuspendLayout();
        _view.SelectAll();
        _view.SelectionBackColor = Panel;
        _view.SelectionColor = Color.Gainsboro;
        if (i >= 0 && i < _starts.Count)
        {
            _view.Select(_starts[i], _sentences[i].Length);
            _view.SelectionBackColor = Color.FromArgb(70, 55, 20);
            _view.SelectionColor = Color.White;
            _view.ScrollToCaret();
            _view.Select(_starts[i], 0);
        }
        _view.ResumeLayout();
        UpdateButtons();
    }

    private void UpdateButtons() => _pause.Text = _speaker.Paused ? "Resume" : "Pause";

    private void SetStatus(string text) => _status.Text = text;

    private async Task PickFileAsync()
    {
        using var dialog = new OpenFileDialog { Filter = "Text and PDFs|*.txt;*.md;*.markdown;*.pdf|All files|*.*", Title = "Read a file aloud" };
        if (dialog.ShowDialog(this) == DialogResult.OK) await OpenFileAsync(dialog.FileName);
    }

    public async Task OpenFileAsync(string path)
    {
        try
        {
            SetStatus($"Opening {Path.GetFileName(path)}…");
            var text = path.EndsWith(".pdf", StringComparison.OrdinalIgnoreCase)
                ? await TextSources.FromPdfAsync(path, s => BeginInvoke(() => SetStatus(s)), CancellationToken.None)
                : TextSources.FromTextFile(path);
            if (string.IsNullOrWhiteSpace(text)) { SetStatus($"{Path.GetFileName(path)} has no text to read."); return; }
            var item = Add(new Item { Kind = "file", Title = Path.GetFileName(path), Text = text });
            ShowReader(activate: false);
            Read(item);
        }
        catch (Exception e) { SetStatus($"Couldn't read {Path.GetFileName(path)}: {e.Message}"); }
    }

    private void ReadClipboard()
    {
        if (!Clipboard.ContainsText()) { SetStatus("Nothing copied: copy some text first."); return; }
        var text = TextSources.Tidy(Clipboard.GetText());
        if (text.Length == 0) return;
        Read(Add(new Item { Kind = "pasted", Title = TextSources.TitleOf(text), Text = text }));
        ShowReader(activate: false);
    }

    private Item Add(Item item)
    {
        _history.Insert(0, item);
        while (_history.Count > 40) _history.RemoveAt(_history.Count - 1);
        SaveHistory();
        RefreshList();
        return item;
    }

    private void RefreshList()
    {
        _list.BeginUpdate();
        _list.Items.Clear();
        foreach (var h in _history) _list.Items.Add(h.Title);
        _list.EndUpdate();
    }

    // ---------------------------------------------------------------- the inbox (the Claude Code hook, a second copy of the app)

    private bool _reading;

    private async Task ReadInboxAsync()
    {
        if (_reading) return;
        _reading = true;
        try
        {
            foreach (var file in Directory.GetFiles(Inbox, "*.json").OrderBy(f => f))
            {
                JsonElement msg;
                try { msg = JsonDocument.Parse(await File.ReadAllTextAsync(file)).RootElement.Clone(); }
                catch (IOException) { continue; }   // still being written: next tick
                catch (JsonException) { msg = default; }
                try { File.Delete(file); } catch { }
                if (msg.ValueKind != JsonValueKind.Object) continue;
                var kind = msg.TryGetProperty("kind", out var k) ? k.GetString() : null;
                switch (kind)
                {
                    case "reply":
                        var text = msg.TryGetProperty("text", out var t) ? t.GetString() ?? "" : "";
                        if (text.Length == 0) break;
                        var item = Add(new Item { Kind = "reply", Title = TextSources.TitleOf(text), Text = text });
                        // "silent": kept for replay but not read (the old "off" file beside speak_reply.py)
                        var silent = msg.TryGetProperty("silent", out var q) && q.ValueKind == JsonValueKind.True;
                        if (_settings.ReadReplies && !silent) { Read(item); PopUpIfFree(); }
                        break;
                    case "stop":
                        // a new message to Claude stops a reply being read, not a file you're listening to
                        if (_speaker.Playing && _current?.Kind == "reply") StopReading();
                        break;
                    case "open":
                        if (msg.TryGetProperty("path", out var p) && p.GetString() is { Length: > 0 } path) await OpenFileAsync(path);
                        break;
                    case "show":
                        ShowReader(activate: true);
                        break;
                }
            }
        }
        catch { }
        finally { _reading = false; }
    }

    // ---------------------------------------------------------------- showing without getting in the way

    private void PopUpIfFree()
    {
        if (_settings.PopUp && !Visible && !FullscreenInFront()) ShowReader(activate: false);
    }

    private void ShowReader(bool activate)
    {
        if (!Visible) Show();
        if (WindowState == FormWindowState.Minimized) WindowState = FormWindowState.Normal;
        if (activate) Activate();
    }

    /// <summary>Whether the window in front fills its whole screen (a game, a video): then nothing pops up.</summary>
    private static bool FullscreenInFront()
    {
        var hwnd = GetForegroundWindow();
        if (hwnd == IntPtr.Zero) return false;
        var cls = new System.Text.StringBuilder(64);
        GetClassName(hwnd, cls, 64);
        if (cls.ToString() is "Progman" or "WorkerW") return false;   // the desktop
        if (!GetWindowRect(hwnd, out var r)) return false;
        var screen = Screen.FromHandle(hwnd).Bounds;
        return r.Left <= screen.Left && r.Top <= screen.Top && r.Right >= screen.Right && r.Bottom >= screen.Bottom;
    }

    // ---------------------------------------------------------------- saved state

    private void LoadSettings()
    {
        try { if (File.Exists(SettingsFile)) _settings = JsonSerializer.Deserialize<Settings>(File.ReadAllText(SettingsFile)) ?? new(); }
        catch { _settings = new(); }
    }

    private void SaveSettings()
    {
        try { File.WriteAllText(SettingsFile, JsonSerializer.Serialize(_settings, new JsonSerializerOptions { WriteIndented = true })); } catch { }
    }

    private void LoadHistory()
    {
        try { if (File.Exists(HistoryFile)) _history.AddRange(JsonSerializer.Deserialize<List<Item>>(File.ReadAllText(HistoryFile)) ?? []); }
        catch { }
    }

    private void SaveHistory()
    {
        try { File.WriteAllText(HistoryFile, JsonSerializer.Serialize(_history)); } catch { }
    }

    private static Icon MakeIcon()
    {
        using var bmp = new Bitmap(32, 32);
        using (var g = Graphics.FromImage(bmp))
        {
            g.SmoothingMode = System.Drawing.Drawing2D.SmoothingMode.AntiAlias;
            g.Clear(Color.Transparent);
            using var gold = new SolidBrush(Gold);
            g.FillEllipse(gold, 1, 1, 30, 30);
            using var black = new SolidBrush(Color.Black);
            // a speaker: a box and a cone
            g.FillRectangle(black, 8, 12, 5, 8);
            g.FillPolygon(black, new Point[] { new(13, 12), new(19, 7), new(19, 25), new(13, 20) });
            using var pen = new Pen(Color.Black, 2);
            g.DrawArc(pen, 17, 10, 8, 12, -60, 120);
        }
        return Icon.FromHandle(bmp.GetHicon());
    }

    private const uint ModAlt = 0x1, ModControl = 0x2;
    [DllImport("user32.dll")] private static extern bool RegisterHotKey(IntPtr hwnd, int id, uint modifiers, uint key);
    [DllImport("user32.dll")] private static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] private static extern bool GetWindowRect(IntPtr hwnd, out RECT rect);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern int GetClassName(IntPtr hwnd, System.Text.StringBuilder name, int max);
    [StructLayout(LayoutKind.Sequential)] private struct RECT { public int Left, Top, Right, Bottom; }
}

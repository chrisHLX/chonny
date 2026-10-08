using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace MindCollector.Desktop;

/// <summary>
/// The window: a list of the player's games (or Improve, Comps, Classes pages) on the left, the page
/// on the right, and a tray icon that keeps the app watching the log when the window is closed.
///
/// The pages are the website's own (BuildCoachPages), fetched with the player's key and shown in
/// WebView2, Edge's engine: it draws off the window's thread, so a heavy page never freezes the app
/// the way the PowerShell app's IE11 control did with 340-icon cards (2026-10-08).
/// </summary>
internal sealed class MainForm : Form
{
    private readonly AppSettings _settings = AppSettings.Load();
    private readonly Syncer _syncer;
    private readonly ListView _list = new();
    private readonly WebView2 _view = new();
    private readonly Label _status = new() { AutoSize = true, ForeColor = Theme.Muted };
    private readonly NotifyIcon _tray = new();
    private readonly System.Windows.Forms.Timer _timer = new() { Interval = 10_000 };
    private readonly Dictionary<string, Button> _tabs = new();
    private readonly List<string> _activity = [];
    private readonly string _activityFile = Path.Combine(AppSettings.Dir, "activity.log");
    private readonly bool _startHidden;

    private JsonDocument? _index;
    private string _tab = "Games";
    private bool _busy;
    private bool _exiting;
    private int _refreshesLeft;

    public MainForm(bool startHidden)
    {
        _startHidden = startHidden;
        _syncer = new Syncer(_settings, Log);

        Text = "MindCollector";
        Icon = Theme.AppIcon();
        Size = new Size(1400, 880);
        MinimumSize = new Size(900, 560);
        StartPosition = FormStartPosition.CenterScreen;
        BackColor = Theme.Bg;
        ForeColor = Theme.Ink;
        Font = Theme.Ui;

        BuildLayout();
        BuildTray();

        _timer.Tick += async (_, _) => await TickAsync();
        Shown += async (_, _) => await StartAsync();
        FormClosing += (_, e) =>
        {
            if (_exiting || e.CloseReason != CloseReason.UserClosing) return;
            e.Cancel = true;
            Hide();
            _tray.ShowBalloonTip(4000, "Still watching", "MindCollector keeps sending your games from the tray. Right-click it to exit.", ToolTipIcon.Info);
        };
    }

    protected override void SetVisibleCore(bool value)
    {
        // Started with Windows: run in the tray without flashing the window.
        if (_startHidden && !IsHandleCreated) { CreateHandle(); value = false; _ = StartAsync(); }
        base.SetVisibleCore(value);
    }

    // ------------------------------------------------------------------ layout

    private void BuildLayout()
    {
        var header = new Panel { Dock = DockStyle.Top, Height = 64, BackColor = Theme.Panel, Padding = new Padding(16, 10, 16, 8) };
        var title = new Label { Text = "MindCollector", ForeColor = Theme.Gold, Font = Theme.Title, AutoSize = true, Location = new Point(14, 6) };
        _status.Location = new Point(16, 38);
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Right, AutoSize = true, WrapContents = false, Padding = new Padding(0, 8, 0, 0) };
        var sync = Theme.Button("Sync now", primary: true);
        var settings = Theme.Button("Settings");
        var site = Theme.Button("Open the website");
        var activity = Theme.Button("Activity");
        sync.Click += async (_, _) => await SyncAsync(manual: true);
        settings.Click += (_, _) => OpenSettings();
        site.Click += (_, _) => OpenUrl($"{_settings.ServerUrl}/wow/coach");
        activity.Click += (_, _) => ShowActivity();
        buttons.Controls.AddRange([activity, site, settings, sync]);
        header.Controls.AddRange([buttons, title, _status]);

        var nav = new FlowLayoutPanel { Dock = DockStyle.Top, Height = 38, BackColor = Theme.Bg, Padding = new Padding(12, 6, 12, 0) };
        foreach (var name in new[] { "Games", "Improve", "Comps", "Classes" })
        {
            var b = new Button { Text = name, FlatStyle = FlatStyle.Flat, AutoSize = true, BackColor = Theme.Bg, Cursor = Cursors.Hand, Tag = name };
            b.FlatAppearance.BorderSize = 1;
            b.Click += (_, _) => { _tab = name; FillList(); };
            _tabs[name] = b;
            nav.Controls.Add(b);
        }

        _list.Dock = DockStyle.Fill;
        _list.View = View.Details;
        _list.FullRowSelect = true;
        _list.MultiSelect = false;
        _list.HideSelection = false;
        _list.BorderStyle = BorderStyle.None;
        _list.BackColor = Theme.Panel;
        _list.ForeColor = Theme.Ink;
        _list.SelectedIndexChanged += async (_, _) =>
        {
            if (_list.SelectedItems.Count == 1 && _list.SelectedItems[0].Tag is string file) await ShowPageAsync(file);
        };

        _view.Dock = DockStyle.Fill;
        _view.DefaultBackgroundColor = Theme.Panel;

        var split = new SplitContainer
        {
            Dock = DockStyle.Fill,
            Orientation = Orientation.Vertical,
            BackColor = Theme.Line,
            SplitterWidth = 4,
            FixedPanel = FixedPanel.Panel1,
        };
        split.Panel1.Controls.Add(_list);
        split.Panel2.Controls.Add(_view);
        Load += (_, _) => split.SplitterDistance = 560;

        var body = new Panel { Dock = DockStyle.Fill, Padding = new Padding(16, 4, 16, 16) };
        body.Controls.Add(split);
        Controls.AddRange([body, nav, header]);
        MarkTab();
    }

    private void BuildTray()
    {
        _tray.Icon = Theme.AppIcon();
        _tray.Text = "MindCollector";
        _tray.Visible = true;
        var menu = new ContextMenuStrip();
        menu.Items.Add("Open", null, (_, _) => ShowWindow());
        menu.Items.Add("Sync now", null, async (_, _) => await SyncAsync(manual: true));
        menu.Items.Add("-");
        menu.Items.Add("Exit", null, (_, _) => { _exiting = true; _tray.Visible = false; Application.Exit(); });
        _tray.ContextMenuStrip = menu;
        _tray.DoubleClick += (_, _) => ShowWindow();
    }

    private void ShowWindow()
    {
        Show();
        WindowState = FormWindowState.Normal;
        Activate();
    }

    // ------------------------------------------------------------------ running

    private bool _started;

    private async Task StartAsync()
    {
        if (_started) return;
        _started = true;

        var env = await CoreWebView2Environment.CreateAsync(null, Path.Combine(AppSettings.Dir, "WebView2"));
        await _view.EnsureCoreWebView2Async(env);
        _view.CoreWebView2.Settings.AreDefaultContextMenusEnabled = false;
        _view.CoreWebView2.Settings.AreDevToolsEnabled = false;
        _view.CoreWebView2.NavigationStarting += OnNavigationStarting;
        _view.CoreWebView2.NewWindowRequested += (_, e) => { e.Handled = true; OpenUrl(e.Uri); };
        Blank("Pick a game to see who you played, how each death happened, and what to look at.");

        CheckAddon();

        if (string.IsNullOrEmpty(_settings.Key))
        {
            SetStatus("Add your website key in Settings to start.");
            OpenSettings();
        }
        else
        {
            SetStatus($"Watching {_settings.WowLogsDir ?? "(no WoW Logs folder set)"}");
            await RefreshIndexAsync();
        }

        _timer.Start();
    }

    private async Task TickAsync()
    {
        if (_refreshesLeft > 0 && !_busy)
        {
            _refreshesLeft--;
            await RefreshIndexAsync();
        }
        await SyncAsync(manual: false);
    }

    private async Task SyncAsync(bool manual)
    {
        if (_busy) return;
        _busy = true;
        try
        {
            if (manual) SetStatus("Looking for new games…");
            // Off the window's thread: a long log is read and sent without freezing the app.
            var result = await Task.Run(() => _syncer.RunAsync(manual, CancellationToken.None));
            if (result.Sent > 0)
            {
                _tray.ShowBalloonTip(4000, $"{result.Sent} round(s) sent", "Your pages are being built on the website; they appear here in a minute or so.", ToolTipIcon.Info);
                _refreshesLeft = 6;   // the server builds in the background; look again for the next minute
            }
            SetStatus(result.Failed > 0
                ? $"{result.Failed} round(s) could not be sent; will try again. See Activity."
                : $"Watching {_settings.WowLogsDir}");
        }
        catch (ApiClient.KeyRejectedException e)
        {
            SetStatus(e.Message);
            Log(e.Message);
        }
        catch (Exception e)
        {
            SetStatus("Could not reach the website; will try again.");
            Log($"Error: {e.Message}");
        }
        finally
        {
            _busy = false;
        }
    }

    private async Task RefreshIndexAsync()
    {
        if (string.IsNullOrEmpty(_settings.Key)) return;
        try
        {
            using var api = new ApiClient(_settings.ServerUrl, _settings.Key);
            var fresh = await api.IndexAsync(CancellationToken.None);
            _index?.Dispose();
            _index = fresh;
            FillList();
        }
        catch (ApiClient.KeyRejectedException e) { SetStatus(e.Message); }
        catch (Exception e) { Log($"Could not load your games: {e.Message}"); }
    }

    // ------------------------------------------------------------------ the list

    private void FillList()
    {
        MarkTab();
        var selected = _list.SelectedItems.Count == 1 ? _list.SelectedItems[0].Tag as string : null;
        _list.BeginUpdate();
        _list.Items.Clear();
        _list.Columns.Clear();

        var root = _index?.RootElement;
        if (root is not { } r || !r.TryGetProperty("built", out var built) || !built.GetBoolean())
        {
            _list.Columns.Add("", 500);
            _list.Items.Add(new ListViewItem("No games on the website yet. Play an arena game; it is sent when it ends."));
            _list.EndUpdate();
            return;
        }

        switch (_tab)
        {
            case "Games": FillGames(r); break;
            case "Improve": FillSimple(r, "characters", ("Character", 160, "name"), ("Spec", 180, "spec"), ("Games", 70, "games")); break;
            case "Comps": FillSimple(r, "comps", ("Comp", 320, "name"), ("Games", 70, "games"), ("Won", 60, "won"), ("Lost", 60, "lost")); break;
            case "Classes": FillSimple(r, "classes", ("Player", 140, "name"), ("Spec", 180, "spec"), ("Why", 260, "why")); break;
        }

        if (selected != null)
            foreach (ListViewItem it in _list.Items)
                if (it.Tag as string == selected) { it.Selected = true; break; }
        _list.EndUpdate();
    }

    private void FillGames(JsonElement r)
    {
        foreach (var (name, width) in new[] { ("Played", 135), ("Bracket", 95), ("Result", 55), ("You", 110), ("Against", 300) })
            _list.Columns.Add(name, width);
        if (!r.TryGetProperty("games", out var games)) return;

        var rows = games.EnumerateObject()
            .Select(g => (Lobby: g.Name, Game: g.Value, At: Str(g.Value, "playedAt")))
            .OrderByDescending(x => x.At, StringComparer.Ordinal);

        foreach (var (lobby, g, at) in rows)
        {
            var won = 0; var lost = 0;
            if (g.TryGetProperty("record", out var rec) && rec.GetArrayLength() == 2) { won = rec[0].GetInt32(); lost = rec[1].GetInt32(); }
            var bracket = Str(g, "bracket").Replace("Rated ", "");
            var result = won + lost == 1 ? (won == 1 ? "Won" : "Lost") : $"{won}-{lost}";
            var against = g.TryGetProperty("against", out var a) ? string.Join(", ", a.EnumerateArray().Select(x => x.GetString())) : "";
            var played = DateTime.TryParse(at, out var d) ? d.ToString("ddd dd MMM  HH:mm") : at;

            var item = new ListViewItem([played, bracket, result, Str(g, "you"), against]) { Tag = lobby + ".html" };
            item.ForeColor = won > lost ? Theme.Won : lost > won ? Theme.Lost : Theme.Ink;
            _list.Items.Add(item);
        }
    }

    private void FillSimple(JsonElement r, string key, params (string Title, int Width, string Field)[] columns)
    {
        foreach (var (title, width, _) in columns) _list.Columns.Add(title, width);
        if (!r.TryGetProperty(key, out var set) || set.ValueKind != JsonValueKind.Object) return;

        foreach (var entry in set.EnumerateObject().OrderByDescending(e => e.Value.TryGetProperty("games", out var n) && n.ValueKind == JsonValueKind.Number ? n.GetInt32() : 0))
        {
            var cells = columns.Select(c => Str(entry.Value, c.Field)).ToArray();
            _list.Items.Add(new ListViewItem(cells) { Tag = Str(entry.Value, "file") });
        }
    }

    private void MarkTab()
    {
        foreach (var (name, b) in _tabs)
        {
            b.ForeColor = name == _tab ? Theme.Gold : Theme.Muted;
            b.FlatAppearance.BorderColor = name == _tab ? Theme.Gold : Theme.Bg;
        }
    }

    // ------------------------------------------------------------------ pages

    private async Task ShowPageAsync(string file)
    {
        if (string.IsNullOrEmpty(file) || string.IsNullOrEmpty(_settings.Key) || _view.CoreWebView2 == null) return;
        try
        {
            using var api = new ApiClient(_settings.ServerUrl, _settings.Key);
            var html = await api.PageAsync(file, CancellationToken.None);
            if (html == null) { Blank("This page has not been built on the website yet. Try again in a minute."); return; }

            // The pages use site-relative image paths (/storage/...): give them the site as their base.
            var withBase = html.Replace("<head>", $"<head><base href=\"{_settings.ServerUrl}/\">", StringComparison.OrdinalIgnoreCase);
            _view.CoreWebView2.NavigateToString(withBase);
        }
        catch (Exception e)
        {
            Blank($"Could not load this page: {e.Message}");
        }
    }

    /// <summary>A link inside a page to another page opens it here; anything else opens in the browser.</summary>
    private async void OnNavigationStarting(object? sender, CoreWebView2NavigationStartingEventArgs e)
    {
        if (!Uri.TryCreate(e.Uri, UriKind.Absolute, out var uri) || uri.Scheme is "data" or "about") return;

        e.Cancel = true;
        var site = new Uri(_settings.ServerUrl);
        if (uri.Host == site.Host && uri.AbsolutePath.EndsWith(".html", StringComparison.OrdinalIgnoreCase) && !uri.AbsolutePath.StartsWith("/storage/"))
            await ShowPageAsync(Path.GetFileName(uri.AbsolutePath));
        else
            OpenUrl(e.Uri);
    }

    private void Blank(string message) =>
        _view.CoreWebView2?.NavigateToString(
            "<!doctype html><html><body style=\"margin:0;background:#111116;color:#8A8A9A;font-family:Segoe UI,Arial;font-size:13px;padding:18px\">"
            + System.Net.WebUtility.HtmlEncode(message) + "</body></html>");

    // ------------------------------------------------------------------ bits

    private void OpenSettings()
    {
        using var f = new SettingsForm(_settings);
        if (f.ShowDialog(this) == DialogResult.OK)
        {
            SetStatus($"Watching {_settings.WowLogsDir}");
            CheckAddon();
            _ = RefreshIndexAsync();
        }
    }

    /// <summary>Installs or updates the arena-log addon, and says so when anything changed or is wrong.</summary>
    private void CheckAddon()
    {
        try
        {
            var outcome = AddonInstaller.Ensure(_settings.WowLogsDir);
            Log(outcome.Message);
            if (outcome.State is AddonInstaller.State.Installed or AddonInstaller.State.Updated)
                _tray.ShowBalloonTip(6000, "Addon ready", outcome.Message, ToolTipIcon.Info);
            else if (outcome.State != AddonInstaller.State.UpToDate)
                SetStatus(outcome.Message);
        }
        catch (Exception e)
        {
            Log($"Could not install the addon: {e.Message}");
            SetStatus($"Could not install the addon: {e.Message}");
        }
    }

    private void Log(string text)
    {
        var line = $"[{DateTime.Now:dd MMM HH:mm:ss}] {text}";
        try
        {
            Directory.CreateDirectory(AppSettings.Dir);
            if (File.Exists(_activityFile) && new FileInfo(_activityFile).Length > 1_000_000)
                File.Move(_activityFile, _activityFile + ".old", overwrite: true);
            File.AppendAllText(_activityFile, line + Environment.NewLine);
        }
        catch { }
        lock (_activity)
        {
            _activity.Add(line);
            if (_activity.Count > 500) _activity.RemoveRange(0, 100);
        }
    }

    private void ShowActivity()
    {
        string text;
        lock (_activity) text = string.Join(Environment.NewLine, _activity);
        var box = new TextBox
        {
            Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Both, WordWrap = false, Dock = DockStyle.Fill,
            BackColor = Theme.Panel, ForeColor = Theme.Ink, Font = new Font("Consolas", 9.5f), BorderStyle = BorderStyle.None,
            Text = text.Length > 0 ? text : "Nothing yet.",
        };
        var f = new Form { Text = "MindCollector activity", Icon = Theme.AppIcon(), Size = new Size(900, 520), StartPosition = FormStartPosition.CenterParent, BackColor = Theme.Panel };
        f.Controls.Add(box);
        f.Shown += (_, _) => { box.SelectionStart = box.TextLength; box.ScrollToCaret(); };
        f.Show(this);
    }

    private void SetStatus(string text)
    {
        if (InvokeRequired) { BeginInvoke(() => SetStatus(text)); return; }
        _status.Text = text;
    }

    private static string Str(JsonElement e, string name) =>
        e.TryGetProperty(name, out var v) ? v.ValueKind switch
        {
            JsonValueKind.String => v.GetString() ?? "",
            JsonValueKind.Number => v.ToString(),
            JsonValueKind.Null => "",
            _ => v.ToString(),
        } : "";

    private static void OpenUrl(string url)
    {
        try { System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(url) { UseShellExecute = true }); } catch { }
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing) { _timer.Dispose(); _tray.Dispose(); _index?.Dispose(); }
        base.Dispose(disposing);
    }
}

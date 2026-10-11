using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace MindCollector.Desktop;

/// <summary>
/// The window, laid out as Spotify's desktop app (2026-10-11): the navigation and the library (the
/// player's games, or Improve, Comps, Classes pages) in rounded panels on the left, the page on the
/// right, and a bar along the bottom with the status and the Sync button. A tray icon keeps the app
/// watching the log when the window is closed.
///
/// The pages are the website's own (BuildCoachPages), fetched with the player's key and shown in
/// WebView2, Edge's engine: it draws off the window's thread, so a heavy page never freezes the app
/// the way the PowerShell app's IE11 control did with 340-icon cards (2026-10-08).
/// </summary>
internal sealed class MainForm : Form
{
    private readonly AppSettings _settings = AppSettings.Load();
    private readonly Syncer _syncer;
    private readonly LibraryList _list = new();
    private readonly Label _libTitle = new() { Dock = DockStyle.Top, Height = 44, Font = Theme.Heading, ForeColor = Theme.Ink, BackColor = Theme.Panel, TextAlign = ContentAlignment.MiddleLeft, Padding = new Padding(10, 0, 0, 0) };
    private readonly WebView2 _view = new();
    private readonly Label _status = new() { AutoSize = true, ForeColor = Theme.Muted };
    private readonly NotifyIcon _tray = new();
    private readonly System.Windows.Forms.Timer _timer = new() { Interval = 10_000 };
    private readonly Dictionary<string, NavItem> _tabs = new();
    private readonly List<string> _activity = [];
    private readonly string _activityFile = Path.Combine(AppSettings.Dir, "activity.log");
    private readonly bool _startHidden;
    private readonly bool _firstRun;

    private JsonDocument? _index;
    private string _tab = "Games";
    private bool _busy;
    private bool _exiting;
    private int _refreshesLeft;

    public MainForm(bool startHidden, bool firstRun = false)
    {
        _startHidden = startHidden;
        _firstRun = firstRun;
        _syncer = new Syncer(_settings, Log);

        Text = "MindCollector";
        Icon = Theme.AppIcon();
        Size = new Size(1400, 880);
        MinimumSize = new Size(900, 560);
        StartPosition = FormStartPosition.CenterScreen;
        BackColor = Theme.Frame;
        ForeColor = Theme.Ink;
        Font = Theme.Ui;
        Theme.DarkTitleBar(this);

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

    /// <summary>What each library is called, and the icon (Segoe Fluent / MDL2) beside it.</summary>
    private static readonly (string Name, string Glyph, string Title)[] Pages =
    [
        ("Games", "", "Your games"),
        ("Improve", "", "What to work on"),
        ("Comps", "", "Comps you've played"),
        ("Classes", "", "Players you've met"),
    ];

    private void BuildLayout()
    {
        // Left: the brand and the navigation, then the library, each in a rounded panel.
        var nav = new RoundPanel { Dock = DockStyle.Top, Height = 64 + Pages.Length * 42 + 10, Padding = new Padding(8, 0, 8, 8) };
        // The wordmark as on the site: "Mind" white, "Collector" gold, drawn as one word.
        var brand = new Panel { Dock = DockStyle.Top, Height = 60, BackColor = Theme.Panel };
        brand.Paint += (_, e) =>
        {
            const TextFormatFlags flags = TextFormatFlags.NoPadding | TextFormatFlags.Left | TextFormatFlags.VerticalCenter;
            var mind = TextRenderer.MeasureText(e.Graphics, "Mind", Theme.Title, Size.Empty, flags);
            TextRenderer.DrawText(e.Graphics, "Mind", Theme.Title, new Rectangle(14, 6, mind.Width, 54), Theme.Ink, flags);
            TextRenderer.DrawText(e.Graphics, "Collector", Theme.Title, new Rectangle(14 + mind.Width, 6, 200, 54), Theme.Gold, flags);
        };
        foreach (var (name, glyph, _) in Pages.Reverse())
        {
            var item = new NavItem(glyph, name) { Dock = DockStyle.Top };
            item.Click += (_, _) => { _tab = name; FillList(); };
            _tabs[name] = item;
            nav.Controls.Add(item);
        }
        nav.Controls.Add(brand);

        _list.Dock = DockStyle.Fill;
        _list.SelectedIndexChanged += async (_, _) =>
        {
            if (_list.SelectedFile is { } file) await ShowPageAsync(file);
        };
        var library = new RoundPanel { Dock = DockStyle.Fill, Padding = new Padding(8, 6, 8, 8) };
        library.Controls.Add(_list);
        library.Controls.Add(_libTitle);

        var left = new Panel { Dock = DockStyle.Left, Width = 380, BackColor = Theme.Frame, Padding = new Padding(8, 8, 0, 0) };
        left.Controls.Add(library);
        left.Controls.Add(new Panel { Dock = DockStyle.Top, Height = 8, BackColor = Theme.Frame });
        left.Controls.Add(nav);

        // Right: the page. Padding above and below keeps the panel's rounded corners clear of the
        // web view, which is a square window of its own.
        _view.Dock = DockStyle.Fill;
        _view.DefaultBackgroundColor = Theme.Panel;
        var page = new RoundPanel { Dock = DockStyle.Fill, Padding = new Padding(0, 8, 0, 8) };
        page.Controls.Add(_view);
        var right = new Panel { Dock = DockStyle.Fill, BackColor = Theme.Frame, Padding = new Padding(8, 8, 8, 0) };
        right.Controls.Add(page);

        // Bottom: the status on the left, Sync in the middle, the rest on the right (Spotify's player bar).
        var bar = new TableLayoutPanel { Dock = DockStyle.Bottom, Height = 72, BackColor = Theme.Frame, ColumnCount = 3, RowCount = 1, Padding = new Padding(16, 0, 16, 0) };
        bar.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 40));
        bar.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 20));
        bar.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 40));
        bar.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        _status.AutoSize = false;
        _status.Dock = DockStyle.Fill;
        _status.TextAlign = ContentAlignment.MiddleLeft;
        _status.AutoEllipsis = true;
        var sync = Theme.Button("Sync now", primary: true);
        sync.Anchor = AnchorStyles.None;
        sync.Click += async (_, _) => await SyncAsync(manual: true);
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.RightToLeft, WrapContents = false, BackColor = Theme.Frame, Padding = new Padding(0, 18, 0, 0) };
        var settings = Theme.Button("Settings");
        var site = Theme.Button("Website");
        var activity = Theme.Button("Activity");
        settings.Click += (_, _) => OpenSettings();
        site.Click += (_, _) => OpenUrl($"{_settings.ServerUrl}/wow/coach");
        activity.Click += (_, _) => ShowActivity();
        buttons.Controls.AddRange([settings, site, activity]);
        bar.Controls.Add(_status, 0, 0);
        bar.Controls.Add(sync, 1, 0);
        bar.Controls.Add(buttons, 2, 0);

        // Docked last-added first: the bar spans the width, then the left column, then the page.
        Controls.AddRange([right, left, bar]);
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

    public void ShowWindow()
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
        CheckAddon();

        if (string.IsNullOrEmpty(_settings.Key))
        {
            // A new player: one button, and the browser does the rest (ConnectAsync).
            FillList();
            ShowWelcome();
            SetStatus("Not connected yet. Click Connect to start.");
            if (_firstRun) _ = ConnectAsync();   // just installed: straight to the browser
        }
        else
        {
            Blank("Pick a game to see who you played, how each death happened, and what to look at.");
            SetStatus(WatchingText());
            await RefreshIndexAsync();
        }

        _timer.Start();
    }

    private string WatchingText() => _settings.WowLogsDir == null
        ? "Couldn't find World of Warcraft. Set its folder in Settings."
        : "Watching your WoW logs for arena games";

    // ------------------------------------------------------------------ connecting (2026-10-11)

    private CancellationTokenSource? _connecting;
    private string? _connectUrl;

    /// <summary>
    /// Connects the app to the player's account without a key to copy: opens /wow/app/connect with a
    /// fresh random code, where they sign in (or sign up) and click Connect, and picks the key up from
    /// the website as soon as they do (AppDownloadController). Gives up after ten minutes.
    /// </summary>
    private async Task ConnectAsync()
    {
        if (_connecting != null)
        {
            if (_connectUrl != null) OpenUrl(_connectUrl);
            return;
        }
        var code = Convert.ToHexString(System.Security.Cryptography.RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        _connectUrl = $"{_settings.ServerUrl}/wow/app/connect?code={code}";
        _connecting = new CancellationTokenSource(TimeSpan.FromMinutes(10));
        var ct = _connecting.Token;
        // A test run (its own data folder) can leave the browser shut and approve from the log.
        if (Environment.GetEnvironmentVariable("MINDCOLLECTOR_DATA") != null && Environment.GetEnvironmentVariable("MINDCOLLECTOR_NO_BROWSER") == "1")
            Log($"Connect at {_connectUrl}");
        else
            OpenUrl(_connectUrl);
        ShowWelcome();
        SetStatus("Waiting for you to click Connect in your browser…");
        try
        {
            while (true)
            {
                await Task.Delay(TimeSpan.FromSeconds(2), ct);
                string? key = null;
                try { key = await ApiClient.PickUpKeyAsync(_settings.ServerUrl, code, ct); }
                catch (Exception e) when (e is not OperationCanceledException) { /* offline for a moment: keep waiting */ }
                if (key == null) continue;

                _settings.Key = key;
                _settings.Save();
                Log("Connected to your account.");
                _tray.ShowBalloonTip(4000, "Connected", "MindCollector will send your arena games as you play.", ToolTipIcon.Info);
                SetStatus(WatchingText());
                _connecting = null;
                ShowWelcome();
                await RefreshIndexAsync();
                ShowWindow();
                return;
            }
        }
        catch (OperationCanceledException)
        {
            SetStatus("Connecting took too long. Click Connect to try again.");
        }
        finally
        {
            _connecting?.Dispose();
            _connecting = null;
        }
        ShowWelcome();
    }

    /// <summary>The page shown before there are games: connect, then what to do next.</summary>
    private void ShowWelcome()
    {
        string title, body, button = "";
        if (string.IsNullOrEmpty(_settings.Key) && _connecting != null)
        {
            title = "Finish in your browser";
            body = "Sign in (or make an account) on the page that opened, then click Connect. This updates by itself.";
            button = "<a class=\"link\" href=\"mc-app://open\">The page didn't open? Open it again</a>";
        }
        else if (string.IsNullOrEmpty(_settings.Key))
        {
            title = "Welcome to MindCollector";
            body = "Connect the app to your account, and every arena game you play shows up here with a review, a minute after it ends.";
            button = "<a class=\"btn\" href=\"mc-app://connect\">Connect your account</a>";
        }
        else if (_settings.WowLogsDir == null)
        {
            title = "Where is World of Warcraft?";
            body = "The app couldn't find WoW on this PC. Pick its Logs folder in Settings (World of Warcraft\\_retail_\\Logs).";
            button = "<a class=\"btn\" href=\"mc-app://settings\">Open Settings</a>";
        }
        else
        {
            title = "You're all set";
            body = Syncer.WowRunning()
                ? "WoW is open, so type /reload once to load the MindCollector addon. Then play arena: each game shows up here about a minute after it ends."
                : "Play arena: each game shows up here about a minute after it ends. Keep the app running in the tray while you play.";
        }
        _view.CoreWebView2?.NavigateToString($$"""
            <!doctype html><html><head><meta charset="utf-8"><style>
            html,body{margin:0;height:100%;background:#121212;color:#fff;font-family:'Segoe UI',Arial,sans-serif}
            .wrap{height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(180deg,#3a2d12 0,#1e1a12 240px,#121212 520px)}
            .card{max-width:520px;text-align:center;padding:24px}
            h1{font-size:34px;font-weight:800;letter-spacing:-.02em;margin:0 0 14px}
            p{font-size:15px;line-height:1.55;color:#b3b3b3;margin:0 0 28px}
            .btn{display:inline-block;background:#C8952C;color:#000;font-weight:700;font-size:15px;padding:14px 34px;border-radius:999px;text-decoration:none}
            .btn:hover{background:#E8B84B;transform:scale(1.03)}
            .link{color:#b3b3b3;font-size:14px}
            </style></head><body><div class="wrap"><div class="card">
            <h1>{{System.Net.WebUtility.HtmlEncode(title)}}</h1><p>{{System.Net.WebUtility.HtmlEncode(body)}}</p>{{button}}
            </div></div></body></html>
            """);
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
        if (string.IsNullOrEmpty(_settings.Key))
        {
            if (manual) await ConnectAsync();   // "Sync now" before connecting: connect first
            return;
        }
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
                : WatchingText());
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
            // No games yet: say what to do next. The first time there are games, open the newest.
            var hasGames = _list.Items.OfType<LibraryRow>().Any(r => r.File != null);
            if (!hasGames && _list.SelectedFile == null) ShowWelcome();
            else if (hasGames && !_pageShown && _tab == "Games") _list.SelectedIndex = 0;
        }
        catch (ApiClient.KeyRejectedException e) { SetStatus(e.Message); }
        catch (Exception e) { Log($"Could not load your games: {e.Message}"); }
    }

    // ------------------------------------------------------------------ the list

    private void FillList()
    {
        MarkTab();
        var selected = _list.SelectedFile;
        var rows = new List<LibraryRow>();

        var root = _index?.RootElement;
        if (root is not { } r || !r.TryGetProperty("built", out var built) || !built.GetBoolean())
        {
            rows.Add(new LibraryRow("Nothing here yet", "Play an arena game. It's sent to the website when it ends."));
        }
        else
        {
            switch (_tab)
            {
                case "Games": rows.AddRange(GameRows(r)); break;
                case "Improve": rows.AddRange(SimpleRows(r, "characters", e => (Str(e, "name"), $"{Str(e, "spec")} · {Str(e, "games")} games", null, 0))); break;
                case "Comps": rows.AddRange(SimpleRows(r, "comps", e => (Str(e, "name"), $"{Str(e, "games")} games", $"{Str(e, "won")}-{Str(e, "lost")}", Math.Sign(Num(e, "won") - Num(e, "lost"))))); break;
                case "Classes": rows.AddRange(SimpleRows(r, "classes", e => (Str(e, "name"), $"{Str(e, "spec")} · {Str(e, "why")}", null, 0))); break;
            }
        }

        var title = Pages.First(p => p.Name == _tab).Title;
        var count = rows.Count(x => x.File != null);
        _libTitle.Text = count > 0 ? $"{title}   {count}" : title;

        _list.BeginUpdate();
        _list.Items.Clear();
        foreach (var row in rows) _list.Items.Add(row);
        if (selected != null)
            for (var i = 0; i < _list.Items.Count; i++)
                if (_list.Items[i] is LibraryRow lr && lr.File == selected) { _list.SelectedIndex = i; break; }
        _list.EndUpdate();
    }

    private static IEnumerable<LibraryRow> GameRows(JsonElement r)
    {
        if (!r.TryGetProperty("games", out var games)) yield break;
        var rows = games.EnumerateObject()
            .Select(g => (Lobby: g.Name, Game: g.Value, At: Str(g.Value, "playedAt")))
            .OrderByDescending(x => x.At, StringComparer.Ordinal);

        foreach (var (lobby, g, at) in rows)
        {
            var won = 0; var lost = 0;
            if (g.TryGetProperty("record", out var rec) && rec.GetArrayLength() == 2) { won = rec[0].GetInt32(); lost = rec[1].GetInt32(); }
            var bracket = Str(g, "bracket").Replace("Rated ", "");
            var badge = won + lost == 1 ? (won == 1 ? "Won" : "Lost") : $"{won}-{lost}";
            var against = g.TryGetProperty("against", out var a) ? string.Join(", ", a.EnumerateArray().Select(x => x.GetString())) : "";
            var played = DateTime.TryParse(at, out var d) ? d.ToString("ddd d MMM, HH:mm") : at;
            var you = Str(g, "you");
            var title = string.IsNullOrEmpty(you) ? bracket : $"{you} · {bracket}";
            var sub = string.IsNullOrEmpty(against) ? played : $"{played} · vs {against}";
            yield return new LibraryRow(title, sub, badge, Math.Sign(won - lost), lobby + ".html");
        }
    }

    private static IEnumerable<LibraryRow> SimpleRows(JsonElement r, string key, Func<JsonElement, (string Title, string Sub, string? Badge, int Tone)> shape)
    {
        if (!r.TryGetProperty(key, out var set) || set.ValueKind != JsonValueKind.Object) yield break;
        foreach (var entry in set.EnumerateObject().OrderByDescending(e => Num(e.Value, "games")))
        {
            var (title, sub, badge, tone) = shape(entry.Value);
            yield return new LibraryRow(title, sub, badge, tone, Str(entry.Value, "file"));
        }
    }

    private static int Num(JsonElement e, string name) =>
        e.TryGetProperty(name, out var n) && n.ValueKind == JsonValueKind.Number ? n.GetInt32() : 0;

    private void MarkTab()
    {
        foreach (var (name, item) in _tabs)
        {
            item.Active = name == _tab;
            item.Invalidate();
        }
    }

    // ------------------------------------------------------------------ pages

    private bool _pageShown;

    private async Task ShowPageAsync(string file)
    {
        _pageShown = true;
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

        // The welcome page's buttons (ShowWelcome).
        if (uri.Scheme == "mc-app")
        {
            e.Cancel = true;
            switch (uri.Host)
            {
                case "connect": await ConnectAsync(); break;
                case "open": if (_connectUrl != null) OpenUrl(_connectUrl); break;
                case "settings": OpenSettings(); break;
            }
            return;
        }

        e.Cancel = true;
        var site = new Uri(_settings.ServerUrl);
        if (uri.Host == site.Host && uri.AbsolutePath.EndsWith(".html", StringComparison.OrdinalIgnoreCase) && !uri.AbsolutePath.StartsWith("/storage/"))
            await ShowPageAsync(Path.GetFileName(uri.AbsolutePath));
        else
            OpenUrl(e.Uri);
    }

    private void Blank(string message) =>
        _view.CoreWebView2?.NavigateToString(
            "<!doctype html><html><body style=\"margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:#121212;color:#B3B3B3;font-family:Segoe UI,Arial;font-size:15px\"><p style=\"max-width:420px;text-align:center;line-height:1.5\">"
            + System.Net.WebUtility.HtmlEncode(message) + "</p></body></html>");

    // ------------------------------------------------------------------ bits

    private void OpenSettings()
    {
        using var f = new SettingsForm(_settings);
        var result = f.ShowDialog(this);
        if (result == DialogResult.Retry)   // Settings' Connect button
        {
            _ = ConnectAsync();
            return;
        }
        if (result == DialogResult.OK)
        {
            SetStatus(WatchingText());
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
        Theme.DarkTitleBar(f);
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

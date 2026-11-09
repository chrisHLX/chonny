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
    private readonly Recorder _recorder;
    private readonly ForeverRecorder _forever;
    private readonly AskBridge _ask;
    private readonly LiveGame _live = new();
    private readonly NoteBook _notes;
    private readonly System.Windows.Forms.Timer _liveTimer = new() { Interval = 1000 };
    private readonly ComboBox _character = new() { Dock = DockStyle.Top, DropDownStyle = ComboBoxStyle.DropDownList, FlatStyle = FlatStyle.Flat, BackColor = Theme.Raised, ForeColor = Theme.Ink, Visible = false };
    private readonly Dictionary<string, System.Text.Json.JsonElement> _experience = new();
    private readonly Dictionary<string, int> _asked = new();
    private DateTime _nextPlayersAsk;
    private int _sentVersion = -1;
    private bool _notesChanged;
    private readonly HashSet<string> _warned = [];
    private bool _recording;
    private readonly RecordLight _recLight = new();
    private int _lightTick;
    private readonly LibraryList _list = new();
    private readonly Label _libTitle = new() { Dock = DockStyle.Top, Height = 44, Font = Theme.Heading, ForeColor = Theme.Ink, BackColor = Theme.Panel, TextAlign = ContentAlignment.MiddleLeft, Padding = new Padding(10, 0, 0, 0) };
    private readonly WebView2 _view = new();
    private readonly Label _status = new() { AutoSize = true, ForeColor = Theme.Muted };
    private readonly NotifyIcon _tray = new();
    private readonly System.Windows.Forms.Timer _timer = new() { Interval = 10_000 };
    private readonly Dictionary<string, NavItem> _tabs = new();
    private readonly List<string> _activity = [];
    private readonly string _activityFile = Path.Combine(AppSettings.Dir, "activity.log");
    private readonly bool _firstRun;

    private JsonDocument? _index;
    private string _tab = "Games";
    private bool _busy;
    private bool _exiting;
    private int _refreshesLeft;

    public MainForm(bool startMinimized, bool firstRun = false)
    {
        // Started with Windows: on the taskbar, one click away, taking no focus from a game. It used
        // to start hidden, and Windows tucks a new tray icon out of sight, so a player had no way of
        // knowing it was there or where their games were (2026-11-02).
        if (startMinimized) WindowState = FormWindowState.Minimized;
        _firstRun = firstRun;
        _syncer = new Syncer(_settings, Log);
        _syncer.Progress += p => BeginInvoke(() =>
        {
            if (p.LogsTotal > 1)
            {
                _syncNote = $"Sending your games from the last two weeks: {p.Sent} sent so far, log {Math.Min(p.LogsDone + 1, p.LogsTotal)} of {p.LogsTotal}. Keep the app open; your games show up here as they're done.";
                SetStatus(_syncNote);
                if (!_list.Items.OfType<LibraryRow>().Any(r => r.File != null && !r.File.StartsWith(SamplePrefix, StringComparison.Ordinal))) FillList();
            }
            if (p.PagesAsked) _refreshesLeft = 6;
        });
        _syncer.Warning += text => BeginInvoke(() =>
        {
            if (_warned.Add(text)) _tray.ShowBalloonTip(8000, "Check before trusting new games", text.Replace("WARNING: ", ""), ToolTipIcon.Warning);
        });
        _notes = new NoteBook(_settings, Log);
        _notes.Changed += () => _notesChanged = true;
        _live.RoundStarted += (visit, start) => _notes.OnRoundStarted(visit, start);
        _recorder = new Recorder(_settings, Log, _live);
        _recorder.RecordingChanged += on => BeginInvoke(() =>
        {
            _recording = on;
            UpdateRecordLight();
            _tray.Text = on ? "MindCollector: recording this game" : "MindCollector";
            SetStatus(on ? "Recording this game with OBS" : WatchingText());
            if (!on && _tab is "Videos" or "Games") FillList();
        });
        _forever = new ForeverRecorder(_settings, Log);
        _forever.Saved += () => BeginInvoke(() => { if (InForever) FillList(); });
        _forever.LogsFound += () => BeginInvoke(() => { _settings.Save(); CheckForeverAddon(); });
        _ask = new AskBridge(_settings, Log);
        _ask.Asking += _ => BeginInvoke(() => { if (_tab == "Ask") { FillList(); RedrawObserverPage(); } });
        _ask.Answered += a => BeginInvoke(() => OnAnswered(a));
        // The side open last time (the switch at the top of the menu).
        if (_settings.Section == "forever") _tab = "Fights";

        Text = "MindCollector";
        Icon = Theme.AppIcon();
        Size = new Size(1400, 880);
        MinimumSize = new Size(900, 560);
        StartPosition = FormStartPosition.CenterScreen;
        BackColor = Theme.Frame;
        ForeColor = Theme.Ink;
        Font = Theme.Ui;
        Theme.DarkTitleBar(this);
        DoubleBuffered = true;

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

    // ------------------------------------------------------------------ layout

    /// <summary>What each library is called, and the icon (Segoe Fluent / MDL2) beside it.</summary>
    private static readonly (string Name, string Glyph, string Title)[] RetailPages =
    [
        ("This game", "", "The game you're in"),
        ("Games", "", "Your games"),
        ("Videos", "\uE714", "Your videos"),
        ("Improve", "", "What to work on"),
        ("Comps", "", "Comps you've faced"),
        ("Teammates", "\uE716", "Who you play with"),
        ("Classes", "", "Players you've met"),
    ];

    /// <summary>The WoW Forever side (2026-11-06, ForeverPages): its fight clips, and the players in them.</summary>
    private static readonly (string Name, string Glyph, string Title)[] ForeverTabs =
    [
        ("Fights", "", "Your PvP fights"),
        ("Opponents", "", "Players you've fought"),
        ("Looked at", "", "What you looked at"),
        ("Ask", "", "Ask Claude"),
    ];

    private static readonly (string Name, string Glyph, string Title)[] Pages = [.. RetailPages, .. ForeverTabs];

    /// <summary>The Forever side is open: the side follows the open page, so it can never disagree with it.</summary>
    private bool InForever => ForeverTabs.Any(p => p.Name == _tab);

    private readonly SectionSwitch _section = new() { Dock = DockStyle.Top };
    private RoundPanel? _nav;
    private Button? _syncButton;
    private string _lastRetailTab = "Games";
    private string _lastForeverTab = "Fights";

    private void BuildLayout()
    {
        // Left: the brand, the Retail / Forever switch and the navigation, then the library, each in a rounded panel.
        var nav = _nav = new RoundPanel { Dock = DockStyle.Top, Padding = new Padding(8, 0, 8, 8) };
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
            item.Click += (_, _) =>
            {
                _tab = name;
                FillList();
                if (name == "This game") ShowThisGame();
                else if (name == "Looked at") ShowLookedOverview();
                else if (name == "Ask") ShowAskOverview();
            };
            _tabs[name] = item;
            nav.Controls.Add(item);
        }
        _section.Picked += side =>
        {
            _tab = side == 1 ? _lastForeverTab : _lastRetailTab;
            _filling = true;
            _list.ClearSelected();
            _filling = false;
            FillList();
            ShowSectionStart();
        };
        nav.Controls.Add(_section);
        nav.Controls.Add(brand);

        _list.Dock = DockStyle.Fill;
        _list.SelectedIndexChanged += async (_, _) =>
        {
            if (_filling || _list.SelectedFile is not { } file) return;
            if (file == YouTubeAction) { OpenYouTubeQueue(); return; }
            if (file == YouTubeInviteAction)
            {
                // How to be added as a tester: the website's YouTube box on Your games.
                try { System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo($"{_settings.ServerUrl.TrimEnd('/')}/wow/coach#youtube") { UseShellExecute = true }); } catch { }
                _filling = true;
                _list.ClearSelected();
                _filling = false;
                return;
            }
            if (file.StartsWith(SamplePrefix, StringComparison.Ordinal)) await ShowSampleAsync(file[SamplePrefix.Length..]);
            else if (file.StartsWith(VideoPrefix, StringComparison.Ordinal)) ShowVideo(file[VideoPrefix.Length..]);
            else if (file.StartsWith(OpponentPrefix, StringComparison.Ordinal)) ShowOpponent(file[OpponentPrefix.Length..]);
            else if (file.StartsWith(LookPrefix, StringComparison.Ordinal)) ShowLook(file[LookPrefix.Length..]);
            else if (file.StartsWith(AskPrefix, StringComparison.Ordinal)) ShowAnswer(file[AskPrefix.Length..]);
            else await ShowPageAsync(file);
        };
        var library = new RoundPanel { Dock = DockStyle.Fill, Padding = new Padding(8, 6, 8, 8) };
        library.Controls.Add(_list);
        library.Controls.Add(_character);
        library.Controls.Add(_libTitle);
        // The character picker (the developer app's): the Games list shows one character's games.
        _character.SelectedIndexChanged += (_, _) =>
        {
            if (_filling) return;
            _settings.Character = _character.SelectedIndex <= 0 ? null : _character.SelectedItem as string;
            _settings.Save();
            FillList();
        };

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
        // The right takes more since the Recording light joined Activity (2026-11-03): all four fit at
        // the window's 900px minimum, and the status on the left shortens with an ellipsis instead.
        bar.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 22));
        bar.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 20));
        bar.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 58));
        bar.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        _status.AutoSize = false;
        _status.Dock = DockStyle.Fill;
        _status.TextAlign = ContentAlignment.MiddleLeft;
        _status.AutoEllipsis = true;
        var sync = _syncButton = Theme.Button("Sync now", primary: true);
        sync.Anchor = AnchorStyles.None;
        sync.Click += async (_, _) => await SyncAsync(manual: true);
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.RightToLeft, WrapContents = false, BackColor = Theme.Frame, Padding = new Padding(0, 18, 0, 0) };
        var settings = Theme.Button("Settings");
        var site = Theme.Button("Website");
        var activity = Theme.Button("Activity");
        settings.Click += (_, _) => OpenSettings();
        site.Click += (_, _) => OpenUrl($"{_settings.ServerUrl}/wow/coach");
        activity.Click += (_, _) => ShowActivity();
        _recLight.Click += (_, _) => OnRecordLightClick();
        buttons.Controls.AddRange([settings, site, activity, _recLight]);
        UpdateRecordLight();
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
        menu.Items.Add("Show this game", null, (_, _) => { ShowWindow(); _tab = "This game"; FillList(); ShowThisGame(); });
        menu.Items.Add("Mark this moment (Ctrl+Shift+M)", null, (_, _) => AddMark());
        menu.Items.Add("Sync now", null, async (_, _) => await SyncAsync(manual: true));
        menu.Items.Add("-");
        menu.Items.Add("Exit", null, (_, _) => { _exiting = true; _tray.Visible = false; _recorder.Dispose(); _forever.Dispose(); _ask.Dispose(); Application.Exit(); });
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
        _view.CoreWebView2.WebMessageReceived += OnPageMessage;
        MapVideos();
        _view.CoreWebView2.NavigationCompleted += (_, _) => { if (_tab == "This game") { _sentVersion = -1; SendLiveState(); } };
        CheckAddon();
        _notes.ImportDevNotes();
        _liveTimer.Tick += async (_, _) => await LiveTickAsync();
        _liveTimer.Start();

        if (InForever)
        {
            // Opened on the Forever side: its fights, and retail's games load behind it for the switch.
            FillList();
            ShowSectionStart();
            await RefreshIndexAsync();
        }
        else if (string.IsNullOrEmpty(_settings.Key))
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
        _recorder.Start();
        _forever.Start();
        _ask.Start();
        CleanUpOldUpdates();
        await CheckForUpdateAsync();
    }

    /// <summary>The downloaded update files left in the temp folder once one has been installed.</summary>
    private static void CleanUpOldUpdates()
    {
        try
        {
            foreach (var f in Directory.GetFiles(Path.GetTempPath(), "MindCollector-*.exe"))
                try { File.Delete(f); } catch { /* still running: next time */ }
        }
        catch { }
    }

    private string WatchingText() => InForever ? ForeverText()
        : _settings.WowLogsDir == null
        ? "Couldn't find World of Warcraft. Set its folder in Settings."
        : "Watching your WoW logs for arena games";

    private string ForeverText() =>
        !_settings.RecordForever ? "Saving Forever fights is off (Settings > Recording)"
        : !ForeverRecorder.ForeverRunning() ? "Waiting for WoW Forever to open"
        : _forever.Ready ? "Watching WoW Forever: each PvP fight is saved with OBS when it ends"
        : _forever.Problem ?? "Starting OBS for WoW Forever…";

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
                : "Play arena: each game shows up here about a minute after it ends. Keep the app running in the tray while you play. Meanwhile, open a sample on the left to see what you'll get.";
        }
        Show($$"""
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

    private int _ticks;

    /// <summary>
    /// A newer app on the website, installed by itself (Updater): at start and every three hours,
    /// never while WoW is running or a sync is under way, so a game is never interrupted.
    /// </summary>
    private async Task CheckForUpdateAsync()
    {
#if STORE
        await Task.CompletedTask;   // the Store keeps it up to date
        return;
#endif
        if (_busy || Syncer.WowRunning() || Environment.GetEnvironmentVariable("MINDCOLLECTOR_DATA") != null) return;
        try
        {
            var newer = await Updater.NewerAsync(_settings.ServerUrl, CancellationToken.None);
            if (newer == null) return;
            if (await Updater.InstallAsync(_settings.ServerUrl, newer, Log, CancellationToken.None))
            {
                _exiting = true;
                _tray.Visible = false;
                Application.Exit();
            }
        }
        catch (Exception e) { Log($"Could not check for an update: {e.Message}"); }
    }

    private async Task TickAsync()
    {
        if (++_ticks % 1080 == 0) await CheckForUpdateAsync();   // every three hours at ten seconds a tick
        // WoW not found yet (0.6.1): look again, so starting the game afterwards fixes it by itself.
        // The running game is checked every tick; the whole search, drives included, every minute.
        if (_settings.WowLogsDir == null && (WowRegistry.RunningRetail().Any() || _ticks % 6 == 0) && AppSettings.DetectWowLogs() is { } found)
        {
            _settings.WowLogsDir = found;
            _settings.Save();
            Log($"Found World of Warcraft's logs at {found}.");
            CheckAddon();
            SetStatus(WatchingText());
            if (_list.SelectedFile == null) ShowWelcome();
        }
        if (_refreshesLeft > 0)
        {
            _refreshesLeft--;
            await RefreshIndexAsync();
        }
        await SyncAsync(manual: false);
        await FlushNotesAsync();
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
            _syncNote = null;
            if (result.Sent > 0)
            {
                _tray.ShowBalloonTip(4000, $"{result.Sent} round(s) sent", "Your pages are being built on the website; they appear here in a minute or so.", ToolTipIcon.Info);
                _refreshesLeft = 6;   // the server builds in the background; look again for the next minute
            }
            SetStatus(result.Failed > 0
                ? $"{result.Failed} round(s) could not be sent; will try again. See Activity."
                : _recording ? "Recording this game with OBS" : WatchingText());
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

    private string? _indexRaw;
    private bool _filling;

    private async Task RefreshIndexAsync()
    {
        if (string.IsNullOrEmpty(_settings.Key)) return;
        try
        {
            using var api = new ApiClient(_settings.ServerUrl, _settings.Key);
            var fresh = await api.IndexAsync(CancellationToken.None);
            // Unchanged (most of the minute of checks after a game): leave the list and the page be.
            // Rebuilding them every ten seconds made the open page flash and jump to the top.
            var raw = fresh.RootElement.GetRawText();
            if (raw == _indexRaw && _index != null) { fresh.Dispose(); return; }
            _indexRaw = raw;
            _index?.Dispose();
            _index = fresh;
            FillList();
            if (InForever) return;   // retail's games refreshed behind the Forever side; its page stays
            // No games yet: say what to do next. The first time there are games, open the newest.
            var hasGames = _list.Items.OfType<LibraryRow>().Any(r => r.File != null && !r.File.StartsWith(SamplePrefix, StringComparison.Ordinal));
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
        if (InForever) rows.AddRange(ForeverRows());
        else if (root is not { } r || !r.TryGetProperty("built", out var built) || !built.GetBoolean())
        {
            rows.Add(new LibraryRow("Nothing here yet", "Play an arena game. It's sent to the website when it ends."));
        }
        else
        {
            switch (_tab)
            {
                case "This game": break;
                case "Games": LoadVideos(); rows.AddRange(GameRows(r, VideoFor).Where(g => _settings.Character == null || g.Title.StartsWith(_settings.Character + " ·", StringComparison.Ordinal))); break;
                case "Improve": rows.AddRange(SimpleRows(r, "characters", e => (Str(e, "name"), $"{Str(e, "spec")} · {Str(e, "games")} games", null, 0))); break;
                case "Comps": rows.AddRange(SimpleRows(r, "comps", e => (Str(e, "name"), $"{Str(e, "games")} games", $"{Str(e, "won")}-{Str(e, "lost")}", Math.Sign(Num(e, "won") - Num(e, "lost"))))); break;
                case "Teammates": rows.AddRange(SimpleRows(r, "teammates", e => (Str(e, "name"), $"{Str(e, "games")} games", $"{Str(e, "won")}-{Str(e, "lost")}", Math.Sign(Num(e, "won") - Num(e, "lost"))), "order")); break;
                case "Classes": rows.AddRange(SimpleRows(r, "classes", e => (Str(e, "name"), $"{Str(e, "spec")} · {Str(e, "why")}", null, 0))); break;
            }
        }

        // No games yet (0.6.0): what to expect, and the website's samples to look at meanwhile.
        if (_tab == "Games" && !rows.Any(x => x.File != null))
        {
            rows.Clear();
            rows.Add(_syncNote != null
                ? new LibraryRow("Sending your games", _syncNote)
                : new LibraryRow("Your games arrive here", "About a minute after each arena game ends"));
            rows.Add(new LibraryRow("Sample · a 3v3 game", "A real game, names hidden", null, 0, SamplePrefix + "game"));
            rows.Add(new LibraryRow("Sample · a Solo Shuffle", "Round by round", null, 0, SamplePrefix + "shuffle"));
            rows.Add(new LibraryRow("Sample · watch a game", "Play it back with everyone's cooldowns", null, 0, SamplePrefix + "watch"));
        }

        if (_tab == "Videos")
        {
            rows.Clear();
            rows.AddRange(VideoRows());
            // Only once the website has YouTube set up (its index says so): until then it could only say "not yet".
            if (rows.Count > 0 && _index?.RootElement is { } ix && ix.TryGetProperty("youtube", out var yt) && yt.ValueKind == System.Text.Json.JsonValueKind.True)
                rows.Insert(0, new LibraryRow("YouTube: upload and share…", "Send games to your channel, and see who can watch the ones there, their views and their pages", null, 0, YouTubeAction));
            // Set up, but testers only while Google reviews it, and this player is not one yet (2026-10-29).
            else if (rows.Count > 0 && _index?.RootElement is { } iv && iv.TryGetProperty("youtubeInvite", out var inv) && inv.ValueKind == System.Text.Json.JsonValueKind.True)
                rows.Insert(0, new LibraryRow("YouTube uploads: testers for now", "Google is still reviewing it. Click to see how to be added", null, 0, YouTubeInviteAction));
            if (rows.Count == 0)
                rows.Add(_settings.RecordGames
                    ? new LibraryRow("No videos yet", "Each arena game you play is recorded with OBS")
                    : new LibraryRow("Recording is off", "Settings > Recording records each arena game with OBS"));
        }

        if (_tab == "This game")
        {
            rows.Clear();
            rows.Add(_live.Active
                ? new LibraryRow(string.Join(" · ", new[] { _live.Zone, _live.Bracket }.Where(s => s != "")) is { Length: > 0 } t ? t : "In an arena", _live.Round > 0 ? $"Round {_live.Round}" : "In the prep room")
                : new LibraryRow("Not in an arena", "Ctrl+Shift+M marks a moment from inside WoW"));
        }

        var title = Pages.First(p => p.Name == _tab).Title;
        var count = rows.Count(x => x.File != null && !x.File.StartsWith(SamplePrefix, StringComparison.Ordinal));
        _libTitle.Text = count > 0 ? $"{title}   {count}" : title;

        _filling = true;
        FillCharacters(root);
        _list.BeginUpdate();
        _list.Items.Clear();
        foreach (var row in rows) _list.Items.Add(row);
        if (selected != null)
            for (var i = 0; i < _list.Items.Count; i++)
                if (_list.Items[i] is LibraryRow lr && lr.File == selected) { _list.SelectedIndex = i; break; }
        _list.EndUpdate();
        _filling = false;
    }

    private static IEnumerable<LibraryRow> GameRows(JsonElement r, Func<string, VideoEntry?> video)
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
            if (video(at) != null) sub = Recorded + sub;
            yield return new LibraryRow(title, sub, badge, Math.Sign(won - lost), lobby + ".html");
        }
    }

    private static IEnumerable<LibraryRow> SimpleRows(JsonElement r, string key, Func<JsonElement, (string Title, string Sub, string? Badge, int Tone)> shape, string? orderBy = null)
    {
        if (!r.TryGetProperty(key, out var set) || set.ValueKind != JsonValueKind.Object) yield break;
        var entries = orderBy != null ? set.EnumerateObject().OrderBy(e => Num(e.Value, orderBy)) : set.EnumerateObject().OrderByDescending(e => Num(e.Value, "games"));
        foreach (var entry in entries)
        {
            var (title, sub, badge, tone) = shape(entry.Value);
            yield return new LibraryRow(title, sub, badge, tone, Str(entry.Value, "file"));
        }
    }

    private static int Num(JsonElement e, string name) =>
        e.TryGetProperty(name, out var n) && n.ValueKind == JsonValueKind.Number ? n.GetInt32() : 0;

    private void MarkTab()
    {
        var forever = InForever;
        if (forever) _lastForeverTab = _tab; else _lastRetailTab = _tab;
        var side = forever ? ForeverTabs : RetailPages;
        foreach (var (name, item) in _tabs)
        {
            item.Visible = side.Any(p => p.Name == name);
            item.Active = name == _tab;
            item.Invalidate();
        }
        _section.SetSide(forever ? 1 : 0);
        // The brand, the switch, and the side's own rows.
        if (_nav != null) _nav.Height = 60 + _section.Height + side.Length * 42 + 10;
        // Forever sends nothing to the website (yet), so it has nothing to sync.
        if (_syncButton != null) _syncButton.Visible = !forever;
        var section = forever ? "forever" : null;
        if (_settings.Section != section)
        {
            _settings.Section = section;
            _settings.Save();
        }
    }

    /// <summary>What the page shows on opening a side: its newest fight or its overview for Forever, the usual start for retail.</summary>
    private void ShowSectionStart()
    {
        if (InForever)
        {
            SetStatus(WatchingText());
            if (_tab == "Looked at") ShowLookedOverview();
            else if (_tab == "Ask") ShowAskOverview();
            else ShowForeverOverview();
            return;
        }
        SetStatus(_recording ? "Recording this game with OBS" : WatchingText());
        if (string.IsNullOrEmpty(_settings.Key)) ShowWelcome();
        else Blank("Pick a game to see who you played, how each death happened, and what to look at.");
    }

    // ------------------------------------------------------------------ WoW Forever (2026-11-06, ForeverPages)

    private const string OpponentPrefix = "opponent:";

    private List<VideoEntry> ForeverFights() => LoadVideos().Where(v => v.IsForever).ToList();

    private void ShowForeverOverview()
    {
        if (_view.CoreWebView2 == null) return;
        _shownFile = null;
        Show(ForeverPages.Overview(_settings, _forever, ForeverFights()));
    }

    private IEnumerable<LibraryRow> ForeverRows()
    {
        if (_tab == "Looked at") { foreach (var r in LookedRows()) yield return r; yield break; }
        if (_tab == "Ask") { foreach (var r in AskRows()) yield return r; yield break; }
        var fights = ForeverFights();
        if (fights.Count == 0)
        {
            yield return _settings.RecordForever
                ? new LibraryRow("No fights yet", "Fight a player in Forever: the clip arrives here about half a minute after")
                : new LibraryRow("Saving fights is off", "Settings > Recording saves each PvP fight in Forever");
            yield break;
        }

        if (_tab == "Fights")
        {
            foreach (var v in fights)
            {
                var facts = new List<string> { ForeverPages.FoughtAt(v).ToString("ddd d MMM, HH:mm") };
                if (v.Length is { } l) facts.Add(VideoLibrary.Duration(l));
                if (v.Fight?.Zone is { Length: > 0 } zone) facts.Add(zone);
                var outcome = v.Fight?.Outcome;
                yield return new LibraryRow(v.Fight?.Title ?? (v.Bracket ?? "Fight").Replace("Forever: ", ""), string.Join(" · ", facts), outcome?.Text, outcome?.Tone ?? 0, VideoPrefix + v.Name);
            }
            yield break;
        }

        // Opponents: everyone in a fight, most fought first; the badge is your kills of them less your deaths.
        var players = fights
            .Where(v => v.Fight != null)
            .SelectMany(v => v.Fight!.Opponents.Distinct().Select(name => (Name: name, Video: v)))
            .GroupBy(x => x.Name)
            .Select(g => (Name: g.Key, Fights: g.Select(x => x.Video).ToList()))
            .OrderByDescending(p => p.Fights.Count).ThenByDescending(p => p.Fights[0].Started);
        foreach (var (name, list) in players)
        {
            var kills = list.Count(v => v.Fight!.Killed.Contains(name));
            var deaths = list.Count(v => v.Fight!.YouDied);
            var sub = $"{list.Count} fight{(list.Count == 1 ? "" : "s")} · last {ForeverPages.FoughtAt(list[0]):ddd d MMM, HH:mm}";
            yield return new LibraryRow(FightFinder.Fight.Short(name), sub, kills + deaths > 0 ? $"{kills}-{deaths}" : null, Math.Sign(kills - deaths), OpponentPrefix + name);
        }
    }

    private void ShowOpponent(string name)
    {
        if (_view.CoreWebView2 == null) return;
        var fights = ForeverFights().Where(v => v.Fight?.Opponents.Contains(name) == true).ToList();
        if (fights.Count == 0) { ShowForeverOverview(); return; }
        _shownFile = null;
        Show(ForeverPages.Opponent(name, fights));
    }

    // ------------------------------------------------------------------ Looked at and Ask (2026-11-09, ObserverPages)

    private const string LookPrefix = "look:";
    private const string AskPrefix = "ask:";
    private ObserverData? _observer;
    private DateTime _observerAt;
    private string? _observerPage;   // what the page shows: "looked", "ask", "look:<key>" or "ask:<id>"

    /// <summary>The Observer addon's saved data, read again only when WoW has written it since.</summary>
    private ObserverData? Observer()
    {
        _settings.ForeverLogsDir ??= AppSettings.DetectForeverLogs(_settings.WowLogsDir);
        var file = ObserverData.FindFile(_settings.ForeverLogsDir);
        if (file == null) return _observer = null;
        var at = File.GetLastWriteTimeUtc(file);
        if (_observer != null && at == _observerAt && _observer.Path == file) return _observer;
        try
        {
            _observer = ObserverData.Load(file);
            _observerAt = at;
        }
        catch (Exception e) { Log($"Could not read the Observer addon's data: {e.Message}"); }
        return _observer;
    }

    private bool ObserverChanged()
    {
        var file = ObserverData.FindFile(_settings.ForeverLogsDir);
        return file != null && File.GetLastWriteTimeUtc(file) != _observerAt;
    }

    private IEnumerable<LibraryRow> LookedRows()
    {
        var data = Observer();
        var subjects = data?.Subjects.Where(s => s.Kind is "item" or "talent").OrderByDescending(s => s.Last).Take(300).ToList() ?? [];
        if (subjects.Count == 0)
        {
            yield return _settings.ForeverObserver
                ? new LibraryRow("Nothing yet", "In Forever, hold your mouse over an item or a talent for a few seconds, then /reload")
                : new LibraryRow("The Observer addon is off", "Settings > WoW Forever turns it on");
            yield break;
        }
        foreach (var s in subjects)
        {
            var last = s.LastLook;
            var sub = $"{(last?.Why != null ? "you said: " + last.Why : "probably " + (last?.Guess ?? "looking"))} · {s.Looks} look{(s.Looks == 1 ? "" : "s")} · {ObserverPages.When(s.Last)}";
            (string Text, int Tone)? badge = null;
            if (s.ItemId is { } id && ObserverData.FirstPricedLook(s)?.Price is { } was && data!.Latest(id) is { } now && now.At > s.First)
                badge = ObserverPages.Change(was, now.Min);
            else if (s.Kind == "talent") badge = s.TakenAt != null ? ("Taken", 1) : s.HadIt ? ("Had it", 0) : null;
            yield return new LibraryRow(s.Name, sub, badge?.Text, badge?.Tone ?? 0, LookPrefix + s.Key);
        }
    }

    private IEnumerable<LibraryRow> AskRows()
    {
        var answers = _ask.Answers();
        if (_ask.Working > 0) yield return new LibraryRow("Working on it…", "Claude is looking it up; this usually takes a minute or two");
        var data = Observer();
        foreach (var a in data?.Asks.Where(a => a.Status != "answered" && DateTime.Now - a.At < TimeSpan.FromHours(2) && answers.All(x => x.Id != a.Id)) ?? [])
            yield return new LibraryRow(a.Question, $"asked in game {ObserverPages.When(a.At)}; not answered yet");
        if (answers.Count == 0 && _ask.Working == 0)
        {
            yield return new LibraryRow("No questions yet", _settings.AskClaude ? "Ask on the right, or with /ask in game" : "Settings > WoW Forever turns answering on");
            yield break;
        }
        foreach (var a in answers)
        {
            var text = a.Text.Length > 90 ? a.Text[..90].TrimEnd() + "…" : a.Text;
            yield return new LibraryRow(a.Question, text, a.Points.Count > 0 ? $"{a.Points.Count} on map" : null, 0, AskPrefix + a.Id);
        }
    }

    private void ShowLookedOverview()
    {
        if (_view.CoreWebView2 == null) return;
        _shownFile = null;
        _observerPage = "looked";
        Show(ObserverPages.LookedOverview(Observer(), _settings));
    }

    private void ShowLook(string key)
    {
        if (_view.CoreWebView2 == null) return;
        var data = Observer();
        var s = data?.Subjects.FirstOrDefault(x => x.Key == key);
        if (data == null || s == null) { ShowLookedOverview(); return; }
        _shownFile = null;
        _observerPage = LookPrefix + key;
        Show(ObserverPages.Subject(data, s));
    }

    private void ShowAskOverview()
    {
        if (_view.CoreWebView2 == null) return;
        _shownFile = null;
        _observerPage = "ask";
        Show(ObserverPages.AskOverview(_ask, Observer()));
    }

    private void ShowAnswer(string id)
    {
        if (_view.CoreWebView2 == null) return;
        var a = _ask.Answers().FirstOrDefault(x => x.Id == id);
        if (a == null) { ShowAskOverview(); return; }
        _shownFile = null;
        _observerPage = AskPrefix + id;
        Show(ObserverPages.Answer(a));
    }

    /// <summary>The Looked at or Ask page again, with what changed (Show skips it when nothing did).</summary>
    private void RedrawObserverPage()
    {
        switch (_observerPage)
        {
            case "looked" when _tab == "Looked at": ShowLookedOverview(); break;
            case "ask" when _tab == "Ask": ShowAskOverview(); break;
            case { } p when p.StartsWith(LookPrefix, StringComparison.Ordinal) && _tab == "Looked at": ShowLook(p[LookPrefix.Length..]); break;
            case { } p when p.StartsWith(AskPrefix, StringComparison.Ordinal) && _tab == "Ask": ShowAnswer(p[AskPrefix.Length..]); break;
        }
    }

    private void OnAnswered(AskBridge.Answer a)
    {
        var text = a.Points.Count > 0 ? $"{a.Text} /reload in WoW to see the {a.Points.Count} points on your map." : a.Text;
        _tray.ShowBalloonTip(10000, "Claude: " + (a.Question.Length > 50 ? a.Question[..50] + "…" : a.Question), text.Length > 250 ? text[..250] + "…" : text, ToolTipIcon.Info);
        if (_tab == "Ask")
        {
            FillList();
            if (_observerPage == "ask") ShowAnswer(a.Id);
            else RedrawObserverPage();
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
            // A game's card (its file is the website's lobby id): the note box underneath.
            if (System.Text.RegularExpressions.Regex.Match(file, "^([0-9a-f]{32})\\.html$") is { Success: true } game)
            {
                var at = withBase.LastIndexOf("</body>", StringComparison.OrdinalIgnoreCase);
                var bar = ThisGamePage.NoteBar(game.Groups[1].Value);
                if (GamePlayedAt(game.Groups[1].Value) is { } played && VideoFor(played) is { } video) bar += VideoLibrary.WatchBar(video.Name);
                withBase = at >= 0 ? withBase.Insert(at, bar) : withBase + bar;
            }
            _shownFile = file;
            Show(withBase);
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
                case "video": ShowVideo(Uri.UnescapeDataString(uri.AbsolutePath.Trim('/'))); break;
                case "game": OpenGame(uri.AbsolutePath.Trim('/')); break;
                case "folder": ShowInFolder(Uri.UnescapeDataString(uri.AbsolutePath.Trim('/'))); break;
                case "look": ShowLook(Uri.UnescapeDataString(uri.AbsolutePath.Trim('/'))); break;
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

    private string? _shown;
    private string? _shownFile;
    private DateTime? _reloadAt;

    /// <summary>Draws a page, unless it is the one already showing (no flash, no jump to the top).</summary>
    private void Show(string html)
    {
        if (_view.CoreWebView2 == null || html == _shown) return;
        _shown = html;
        _view.CoreWebView2.NavigateToString(html);
    }

    // ------------------------------------------------------------------ samples (0.6.0)

    private const string SamplePrefix = "sample:";
    private string? _syncNote;

    /// <summary>The website's sample pages (/wow/sample): real games with every name hidden.</summary>
    private async Task ShowSampleAsync(string tab)
    {
        if (_view.CoreWebView2 == null) return;
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
            http.DefaultRequestHeaders.UserAgent.ParseAdd($"MindCollector-Desktop/{Application.ProductVersion}");
            var html = await http.GetStringAsync($"{_settings.ServerUrl}/wow/sample/page/{Uri.EscapeDataString(tab)}");
            _shownFile = null;
            Show(html.Replace("<head>", $"<head><base href=\"{_settings.ServerUrl}/\">", StringComparison.OrdinalIgnoreCase));
        }
        catch (Exception e)
        {
            Blank($"Could not load the sample: {e.Message}");
        }
    }

    // ------------------------------------------------------------------ videos (2026-10-25, VideoLibrary)

    private const string VideoPrefix = "video:";
    /// <summary>Starts a game row's second line when the game has a video.</summary>
    private const string Recorded = "▶ ";
    private List<VideoEntry> _videos = [];
    private string? _mappedVideos;

    /// <summary>Serves the videos folder to the pages under VideoLibrary.Host; again whenever Settings may have moved it.</summary>
    private void MapVideos()
    {
        if (_view.CoreWebView2 == null) return;
        var dir = Recorder.VideosDir(_settings);
        if (dir == _mappedVideos) return;
        try
        {
            Directory.CreateDirectory(dir);
            if (_mappedVideos != null) _view.CoreWebView2.ClearVirtualHostNameToFolderMapping(VideoLibrary.Host);
            _view.CoreWebView2.SetVirtualHostNameToFolderMapping(VideoLibrary.Host, dir, CoreWebView2HostResourceAccessKind.Allow);
            _mappedVideos = dir;
        }
        catch (Exception e) { Log($"Could not open the videos folder {dir}: {e.Message}"); }
    }

    private List<VideoEntry> LoadVideos() => _videos = VideoLibrary.Load(Recorder.VideosDir(_settings));

    private VideoEntry? VideoFor(string playedAt) => VideoLibrary.ForGame(_videos, playedAt);

    private string? GamePlayedAt(string lobby) =>
        _index?.RootElement is { } r && r.TryGetProperty("games", out var games) && games.ValueKind == JsonValueKind.Object
            && games.TryGetProperty(lobby, out var g) ? Str(g, "playedAt") : null;

    /// <summary>The game a video is of, from the index: its lobby, and its row as the Games tab draws it.</summary>
    private (string Lobby, LibraryRow Row)? GameOf(VideoEntry v)
    {
        if (v.Rounds.Count == 0 || _index?.RootElement is not { } r) return null;
        foreach (var row in GameRows(r, at => VideoLibrary.ForGame([v], at)))
            if (row.Sub.StartsWith(Recorded, StringComparison.Ordinal) && row.File is { } f)
                return (f[..^".html".Length], row);
        return null;
    }

    private IEnumerable<LibraryRow> VideoRows()
    {
        // Forever's fight clips are on the Forever side (Fights), not here.
        foreach (var v in LoadVideos().Where(v => !v.IsForever))
        {
            var line = v.Started.ToString("ddd d MMM, HH:mm") + (v.Length is { } l ? $" · {VideoLibrary.Duration(l)}" : "") + (v.YouTubeId != null ? " · on YouTube" : "");
            yield return GameOf(v) is { } g
                ? new LibraryRow(g.Row.Title, line, g.Row.Badge, g.Row.Tone, VideoPrefix + v.Name)
                : new LibraryRow((v.Bracket ?? "Video").Replace("Rated ", ""), line, null, 0, VideoPrefix + v.Name);
        }
    }

    /// <summary>
    /// A video: on its game's Watch page from the website when it is of a game the website holds (the
    /// timeline of goes, deaths and defensives under it), otherwise the plain player.
    /// </summary>
    private const string YouTubeAction = "action:youtube";
    private const string YouTubeInviteAction = "action:youtube-invite";

    /// <summary>The upload picker (YouTubeQueueForm): recordings matched to a game, those on YouTube already among them, marked.</summary>
    private void OpenYouTubeQueue()
    {
        var items = LoadVideos()
            .Select(v => (Video: v, Game: GameOf(v)))
            .Where(x => x.Game != null)
            .Select(x =>
            {
                // The row's title is "Character · 3v3", or only the bracket when the game has no "you".
                var title = x.Game!.Value.Row.Title;
                var at = title.IndexOf(" · ", StringComparison.Ordinal);
                return new YouTubeQueueForm.Item(x.Video, x.Game.Value.Lobby, $"{title} {x.Game.Value.Row.Badge}".Trim(), at > 0 ? title[..at] : null);
            })
            .ToList();
        using var form = new YouTubeQueueForm(_settings, items.OrderByDescending(i => i.Video.Started).ToList(), Log);
        form.ShowDialog(this);
        _filling = true;
        _list.ClearSelected();
        _filling = false;
        FillList();
    }

    private async void ShowVideo(string name)
    {
        if (_view.CoreWebView2 == null) return;
        MapVideos();
        var v = _videos.FirstOrDefault(x => x.Name == name) ?? LoadVideos().FirstOrDefault(x => x.Name == name);
        if (v == null) { Blank("That video is no longer in the videos folder."); return; }
        var game = v.IsForever ? null : GameOf(v);
        _shownFile = null;
        if (v.Fight is { } fight) { Show(VideoLibrary.Page(v, fight.Title, null)); return; }
        if (game is { } g && !string.IsNullOrEmpty(_settings.Key))
        {
            try
            {
                using var api = new ApiClient(_settings.ServerUrl, _settings.Key);
                if (await api.WatchPageAsync(g.Lobby, v, CancellationToken.None) is { } html)
                {
                    Show(html.Replace("<head>", $"<head><base href=\"{_settings.ServerUrl}/\">", StringComparison.OrdinalIgnoreCase));
                    return;
                }
            }
            catch (Exception e) { Log($"Could not load this game's timeline: {e.Message}"); }
        }
        Show(VideoLibrary.Page(v, game?.Row.Title ?? (v.Bracket ?? "Video").Replace("Rated ", ""), game?.Lobby));
    }

    private async void OpenGame(string lobby)
    {
        if (!System.Text.RegularExpressions.Regex.IsMatch(lobby, "^[0-9a-f]{32}$")) return;
        _tab = "Games";
        FillList();
        await ShowPageAsync(lobby + ".html");
    }

    private void ShowInFolder(string name)
    {
        var path = Path.Combine(Recorder.VideosDir(_settings), Path.GetFileName(name));
        try { System.Diagnostics.Process.Start("explorer.exe", File.Exists(path) ? $"/select,\"{path}\"" : $"\"{Path.GetDirectoryName(path)}\""); }
        catch (Exception e) { Log($"Could not open the folder: {e.Message}"); }
    }

    // ------------------------------------------------------------------ this game, notes and marks (2026-10-25)

    private void ShowThisGame()
    {
        _shownFile = null;
        _sentVersion = -1;
        Show(ThisGamePage.Html());
        SendLiveState();
    }

    /// <summary>Once a second: the nav's dot, the status line, the players' experience, and "This game" when it changed.</summary>
    private async Task LiveTickAsync()
    {
        if (++_lightTick % 5 == 0) UpdateRecordLight();
        // Forever's state (open, OBS's buffer) has no event of its own: look every five seconds.
        if (InForever && _lightTick % 5 == 0 && !_recording) SetStatus(WatchingText());
        // The Observer addon's data changes at each /reload in game: redraw its list and page then.
        if (InForever && _tab is "Looked at" or "Ask" && _lightTick % 5 == 0 && ObserverChanged()) { FillList(); RedrawObserverPage(); }
        var active = _live.Active;
        if (_tabs.TryGetValue("This game", out var nav) && nav.Dot != active)
        {
            nav.Dot = active;
            nav.Invalidate();
            _sentVersion = -1;   // in or out of a game is a change of time, not of the log
            if (_tab == "This game") FillList();
            if (!_recording) SetStatus(active ? "In an arena" : WatchingText());
        }
        if (_tab == "This game") SendLiveState();
        if (_reloadAt is { } due && DateTime.Now >= due)
        {
            _reloadAt = null;
            if (_shownFile != null) await ShowPageAsync(_shownFile);
        }
        if (active && DateTime.Now >= _nextPlayersAsk) await AskPlayersAsync();
    }

    /// <summary>The state to "This game", only when something on it changed (the clock runs in the page).</summary>
    private void SendLiveState()
    {
        if (_view.CoreWebView2 == null || _tab != "This game") return;
        if (_live.Version == _sentVersion && !_notesChanged) return;
        _sentVersion = _live.Version;
        _notesChanged = false;

        var state = _live.Snapshot(_experience);
        if (!_live.Active) state["state"] = "idle";
        var start = _live.RoundStart;
        var notes = new System.Text.Json.Nodes.JsonArray();
        foreach (var n in _notes.ForVisit(_live.Visit))
        {
            var when = n.Kind == "next" ? "next game" : n.RoundStart is { } rs && DateTime.TryParse(rs, out var r) && DateTime.TryParse(n.At, out var a)
                ? (a >= r ? $"{(n.Round > 1 ? $"R{n.Round} " : "")}{(int)(a - r).TotalMinutes}:{(a - r).Seconds:00}" : "prep")
                : "prep";
            notes.Add(new System.Text.Json.Nodes.JsonObject { ["id"] = n.Id, ["kind"] = n.Kind, ["text"] = n.Text, ["when"] = when, ["sent"] = n.Sent });
        }
        state["notes"] = notes;
        state["waiting"] = new System.Text.Json.Nodes.JsonArray(_notes.Waiting(start).Select(n => (System.Text.Json.Nodes.JsonNode)n.Text).ToArray());
        state["type"] = "state";
        try { _view.CoreWebView2.PostWebMessageAsJson(state.ToJsonString()); } catch { }
    }

    /// <summary>
    /// The experience of everyone named in the game (Gladiator seasons, best 3v3), from the website:
    /// what it already knows now, the rest a little later once it has looked them up.
    /// </summary>
    private async Task AskPlayersAsync()
    {
        _nextPlayersAsk = DateTime.Now.AddSeconds(15);
        if (string.IsNullOrEmpty(_settings.Key)) return;
        var names = _live.Names().Where(n => !_experience.ContainsKey(n) && _asked.GetValueOrDefault(n) < 6).ToList();
        if (names.Count == 0) return;
        foreach (var n in names) _asked[n] = _asked.GetValueOrDefault(n) + 1;
        try
        {
            using var api = new ApiClient(_settings.ServerUrl, _settings.Key);
            var (known, _) = await api.PlayersAsync(names, CancellationToken.None);
            foreach (var (name, x) in known) _experience[name] = x;
            if (known.Count > 0) _sentVersion = -1;
        }
        catch { /* the website without the endpoint yet, or offline: the names still show */ }
    }

    /// <summary>Messages from the app's own pages (ThisGamePage): a note, or a mark.</summary>
    private void OnPageMessage(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        try
        {
            using var doc = JsonDocument.Parse(e.WebMessageAsJson);
            var m = doc.RootElement;
            var type = Str(m, "type");
            if (type == "mark") { AddMark(); return; }
            if (type == "ask")
            {
                var question = Str(m, "text").Trim();
                if (question.Length > 0 && _ask.CheckReady() == null) { _ask.AskFromApp(question); FillList(); }
                return;
            }
            if (type != "note") return;

            var text = Str(m, "text").Trim();
            if (text.Length == 0) return;
            var target = Str(m, "target");
            var markId = Str(m, "mark");
            if (target == "live" && markId != "") _notes.Write(markId, text);
            else if (target == "next") _notes.Add("next", text);
            else if (target == "game" && Str(m, "lobby") is { Length: 32 } lobby)
            {
                _notes.Add("note", text, lobby: lobby);
                _reloadAt = DateTime.Now.AddSeconds(20);   // the card redraws on the website with the note
            }
            else _notes.Add("note", text, live: _live.Active || _live.State == "ended" ? _live : null);

            _view.CoreWebView2.PostWebMessageAsJson("{\"type\":\"saved\"}");
            _ = FlushNotesAsync();
        }
        catch (Exception ex) { Log($"Could not save the note: {ex.Message}"); }
    }

    /// <summary>Ctrl+Shift+M (or the button): the moment, marked, with a sound and nothing else.</summary>
    private void AddMark()
    {
        var note = _notes.Add("mark", "", live: _live.Active || _live.State == "ended" ? _live : null);
        System.Media.SystemSounds.Asterisk.Play();
        Log(_live.Round > 0 && note.RoundStart != null ? $"Marked a moment in round {_live.Round}." : "Marked a moment.");
        _ = FlushNotesAsync();
    }

    private bool _flushing;

    private async Task FlushNotesAsync()
    {
        if (_flushing || string.IsNullOrEmpty(_settings.Key)) return;
        _flushing = true;
        try
        {
            using var api = new ApiClient(_settings.ServerUrl, _settings.Key);
            await _notes.FlushAsync(api, CancellationToken.None);
        }
        catch (Exception e) when (e is HttpRequestException or TaskCanceledException) { /* offline: next tick */ }
        catch (ApiClient.KeyRejectedException) { }
        finally { _flushing = false; }
    }

    /// <summary>The character picker over the Games list: every character in the games, once there are two.</summary>
    private void FillCharacters(JsonElement? root)
    {
        var names = new List<string>();
        if (root is { } r && r.TryGetProperty("games", out var games) && games.ValueKind == JsonValueKind.Object)
            names = games.EnumerateObject().Select(g => Str(g.Value, "you")).Where(s => s != "").Distinct().OrderBy(s => s).ToList();
        _character.Visible = _tab == "Games" && names.Count > 1;
        var items = new List<string> { "All characters" };
        items.AddRange(names);
        if (!_character.Items.Cast<string>().SequenceEqual(items))
        {
            _character.Items.Clear();
            foreach (var i in items) _character.Items.Add(i);
        }
        var at = _settings.Character != null ? items.IndexOf(_settings.Character) : 0;
        _character.SelectedIndex = Math.Max(0, at);
    }

    // ------------------------------------------------------------------ the hotkey

    [System.Runtime.InteropServices.DllImport("user32.dll")]
    private static extern bool RegisterHotKey(IntPtr hWnd, int id, uint modifiers, uint vk);

    [System.Runtime.InteropServices.DllImport("user32.dll")]
    private static extern bool UnregisterHotKey(IntPtr hWnd, int id);

    private const int HotkeyId = 0x4D43;   // "MC"

    /// <summary>Ctrl+Shift+M from anywhere, WoW included (the developer app's hotkey).</summary>
    protected override void OnHandleCreated(EventArgs e)
    {
        base.OnHandleCreated(e);
        if (!_settings.MarkHotkey) return;
        const uint ctrl = 0x2, shift = 0x4, noRepeat = 0x4000;
        if (!RegisterHotKey(Handle, HotkeyId, ctrl | shift | noRepeat, (uint)Keys.M))
            Log("Ctrl+Shift+M is taken by another program, so it cannot mark moments. The tray menu's \"Mark this moment\" still does.");
    }

    protected override void OnHandleDestroyed(EventArgs e)
    {
        UnregisterHotKey(Handle, HotkeyId);
        base.OnHandleDestroyed(e);
    }

    protected override void WndProc(ref Message m)
    {
        if (m.Msg == 0x0312 && m.WParam.ToInt32() == HotkeyId) AddMark();
        base.WndProc(ref m);
    }

    private void Blank(string message) =>
        Show(
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
            MapVideos();
            if (_tab == "Videos") FillList();
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
        CheckForeverAddon();
    }

    /// <summary>
    /// WoW Forever's addons, once Forever is found: the log addon (keeps logging on) when fights are
    /// recorded, and the Observer addon (Looked at, /ask) unless turned off.
    /// </summary>
    private void CheckForeverAddon()
    {
        if (!_settings.RecordForever && !_settings.ForeverObserver) return;
        _settings.ForeverLogsDir ??= AppSettings.DetectForeverLogs(_settings.WowLogsDir);
        if (_settings.ForeverLogsDir == null) return;
        var addons = new List<AddonInstaller.Addon>();
        if (_settings.RecordForever) addons.Add(AddonInstaller.Forever);
        if (_settings.ForeverObserver) addons.Add(AddonInstaller.Observer);
        foreach (var addon in addons)
            try
            {
                var outcome = AddonInstaller.Ensure(addon, _settings.ForeverLogsDir);
                Log(outcome.Message);
                if (outcome.State is AddonInstaller.State.Installed or AddonInstaller.State.Updated)
                    _tray.ShowBalloonTip(6000, $"{addon.Title} ready", outcome.Message, ToolTipIcon.Info);
            }
            catch (Exception e) { Log($"Could not install the {addon.Title}: {e.Message}"); }
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

    /// <summary>
    /// The Recording light (2026-11-03), every 5 seconds and when a recording starts or stops. On
    /// 3 Nov an OBS in Safe Mode cost a whole evening's videos, said only in Activity, one line a game.
    /// </summary>
    private void UpdateRecordLight()
    {
        if (!_settings.RecordGames) { _recLight.Set(RecordLight.State.Off, null); return; }
        if (_recording) { _recLight.Set(RecordLight.State.Recording, null); return; }
        var problem = ObsSetup.Problem();
        // A closed OBS looks fine from here; if the app could not open it for the last game, say so.
        if (problem == null && !ObsSetup.Running() && _recorder.LastFailure is { } f && DateTime.Now - f.At < TimeSpan.FromMinutes(15))
            problem = $"The last game could not be recorded: {f.Why}";
        _recLight.Set(problem == null ? RecordLight.State.Ready : RecordLight.State.Problem, problem);
    }

    private void OnRecordLightClick()
    {
        switch (_recLight.Current)
        {
            case RecordLight.State.Off: OpenSettings(); break;
            case RecordLight.State.Problem:
                MessageBox.Show(this, _recLight.Why, "Games are not being recorded", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                break;
            default: ShowActivity(); break;
        }
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

    /// <summary>Opens a web link in the browser. Nothing else: the shell would also run a file path, or any protocol a page named.</summary>
    internal static void OpenUrl(string url)
    {
        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) || uri.Scheme is not ("http" or "https")) return;
        try { System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(url) { UseShellExecute = true }); } catch { }
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing) { _timer.Dispose(); _liveTimer.Dispose(); _recorder.Dispose(); _forever.Dispose(); _tray.Dispose(); _index?.Dispose(); }
        base.Dispose(disposing);
    }
}

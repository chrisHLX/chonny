namespace MindCollector.Desktop;

/// <summary>
/// Picking games to upload to the player's YouTube channel (2026-10-26): the recordings matched to a
/// game and not uploaded yet, the last day's ticked, a privacy setting (unlisted unless they choose),
/// then one at a time: the website's title and chapters, the file straight to YouTube
/// (YouTubeUploader), the video id back to the website, and a note beside the file. Nothing is
/// uploaded unless it is ticked here.
///
/// Since 2026-10-28 it also lists what is already on the channel, as the website last heard from
/// YouTube (ApiClient.YouTubeVideosAsync): who can watch each video, its views, and its page on the
/// website to send to people. Each privacy choice says what it means for that page.
///
/// Since 2026-10-29 the games already on YouTube stay in the list, marked and never ticked, so the
/// list is every recorded game; a character picker narrows it, and Tick all / Untick all act on what
/// it shows. Only what is shown can be ticked, so the Upload button's count is what goes.
/// </summary>
internal sealed class YouTubeQueueForm : Form
{
    public sealed record Item(VideoEntry Video, string Lobby, string Title, string? Character);

    private readonly AppSettings _settings;
    private readonly Action<string> _log;
    private readonly List<Item> _items;
    private readonly CheckedListBox _list = new() { Dock = DockStyle.Fill, BorderStyle = BorderStyle.None, BackColor = Theme.Raised, ForeColor = Theme.Ink, CheckOnClick = true };
    private readonly ComboBox _character = new() { DropDownStyle = ComboBoxStyle.DropDownList, Width = 180, FlatStyle = FlatStyle.Flat, BackColor = Theme.Raised, ForeColor = Theme.Ink };
    private readonly Button _all = Theme.Button("Tick all");
    private readonly Button _none = Theme.Button("Untick all");
    private readonly List<Item> _shown = [];
    private readonly HashSet<Item> _ticked = [];
    private readonly HashSet<Item> _uploadedHere = [];
    private bool _filling;
    private readonly ComboBox _privacy = new() { DropDownStyle = ComboBoxStyle.DropDownList, Width = 140, FlatStyle = FlatStyle.Flat, BackColor = Theme.Raised, ForeColor = Theme.Ink };
    private readonly Label _privacyMeans = new() { Dock = DockStyle.Fill, ForeColor = Theme.Muted, Font = Theme.Small, Padding = new Padding(0, 4, 0, 0) };
    private readonly ProgressBar _bar = new() { Dock = DockStyle.Fill, Height = 8, Maximum = 1000, Visible = false };
    private readonly Label _status = new() { Dock = DockStyle.Fill, ForeColor = Theme.Muted };
    private readonly Button _upload = Theme.Button("Upload", primary: true);
    private readonly Button _close = Theme.Button("Close");

    private readonly ListBox _channel = new() { Dock = DockStyle.Fill, BorderStyle = BorderStyle.None, BackColor = Theme.Raised, ForeColor = Theme.Ink, IntegralHeight = false };
    private readonly Label _channelLine = new() { Dock = DockStyle.Fill, ForeColor = Theme.Muted, Font = Theme.Small };
    private readonly Button _open = Theme.Button("Open its page");
    private readonly Button _copy = Theme.Button("Copy link");
    private readonly Button _manage = Theme.Button("Manage on the website");
    private List<ApiClient.YouTubeVideoInfo> _videos = [];
    private string? _manageUrl;
    private CancellationTokenSource? _running;

    public bool Uploaded { get; private set; }

    public YouTubeQueueForm(AppSettings settings, List<Item> items, Action<string> log)
    {
        _settings = settings;
        _items = items;
        _log = log;
        Text = "Upload to YouTube";
        Icon = Theme.AppIcon();
        BackColor = Theme.Frame;
        ForeColor = Theme.Ink;
        Font = Theme.Ui;
        StartPosition = FormStartPosition.CenterParent;
        ClientSize = new Size(720, 700);
        MinimumSize = new Size(560, 560);
        Padding = new Padding(16);
        Theme.DarkTitleBar(this);

        var cutoff = DateTime.Now.AddDays(-1);
        foreach (var it in items)
            if (it.Video.Started >= cutoff && !OnYouTube(it)) _ticked.Add(it);

        // The character picker, once the games hold two; it starts on the one the Games tab shows.
        var characters = items.Select(i => i.Character).OfType<string>().Distinct().OrderBy(c => c).ToList();
        _character.Items.Add("All characters");
        foreach (var c in characters) _character.Items.Add(c);
        _character.SelectedIndex = Math.Max(0, settings.Character is { } mine ? _character.Items.IndexOf(mine) : 0);
        _character.Visible = characters.Count > 1;
        _ticked.RemoveWhere(i => !Matches(i));
        _character.SelectedIndexChanged += (_, _) =>
        {
            _ticked.RemoveWhere(i => !Matches(i));   // what is ticked is always what is shown
            Fill();
        };
        _list.ItemCheck += (_, e) =>
        {
            if (_filling || e.Index < 0 || e.Index >= _shown.Count) return;
            var it = _shown[e.Index];
            if (e.NewValue == CheckState.Checked && OnYouTube(it))
            {
                e.NewValue = CheckState.Unchecked;
                _status.ForeColor = Theme.Muted;
                _status.Text = "That game is on your channel already: it is listed below, under On your channel.";
                return;
            }
            if (e.NewValue == CheckState.Checked) _ticked.Add(it); else _ticked.Remove(it);
            Count();
        };
        _all.Click += (_, _) => { foreach (var it in _shown) if (!OnYouTube(it)) _ticked.Add(it); Fill(); };
        _none.Click += (_, _) => { foreach (var it in _shown) _ticked.Remove(it); Fill(); };

        _privacy.Items.AddRange(["Unlisted", "Public", "Private"]);
        _privacy.SelectedIndexChanged += (_, _) => _privacyMeans.Text = Means(_privacy.SelectedItem?.ToString());
        _privacy.SelectedIndex = 0;
        var privacyRow = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.LeftToRight, BackColor = Theme.Frame, Margin = Padding.Empty };
        privacyRow.Controls.AddRange([new Label { Text = "Who can see them:", AutoSize = true, ForeColor = Theme.Muted, Margin = new Padding(0, 8, 8, 0) }, _privacy]);
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.RightToLeft, BackColor = Theme.Frame, Margin = Padding.Empty };
        buttons.Controls.AddRange([_close, _upload]);
        var channelButtons = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.LeftToRight, BackColor = Theme.Frame, Margin = Padding.Empty };
        channelButtons.Controls.AddRange([_open, _copy, _manage]);

        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, BackColor = Theme.Frame };
        void Add(Control c, SizeType type, float size)
        {
            grid.RowStyles.Add(new RowStyle(type, size));
            c.Margin = new Padding(0, 2, 0, 2);
            grid.Controls.Add(c);
        }
        var pickRow = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.LeftToRight, BackColor = Theme.Frame, Margin = Padding.Empty };
        _character.Margin = new Padding(0, 6, 12, 0);
        pickRow.Controls.AddRange([_character, _all, _none]);

        Add(Heading("To upload"), SizeType.Absolute, 28);
        Add(new Label { Dock = DockStyle.Fill, ForeColor = Theme.Muted, Text = "Tick the games to send to your YouTube channel. Each gets a title and chapters written from the game, "
            + "and goes straight from this PC to YouTube. Games from the last day are ticked; ones already on YouTube say so." }, SizeType.Absolute, 40);
        Add(pickRow, SizeType.Absolute, 42);
        Add(_list, SizeType.Percent, 50);
        Add(privacyRow, SizeType.Absolute, 40);
        Add(_privacyMeans, SizeType.Absolute, 40);
        Add(_bar, SizeType.Absolute, 10);
        Add(_status, SizeType.Absolute, 40);
        Add(buttons, SizeType.Absolute, 46);
        Add(Heading("On your channel"), SizeType.Absolute, 32);
        Add(_channel, SizeType.Percent, 50);
        Add(_channelLine, SizeType.Absolute, 40);
        Add(channelButtons, SizeType.Absolute, 46);
        Controls.Add(grid);

        if (items.Count == 0)
        {
            _status.Text = "No recorded games yet: a video shows here once its game is on the website.";
            _upload.Enabled = false;
        }
        else if (items.All(OnYouTube))
        {
            _status.Text = "Every recorded game is on YouTube already.";
        }
        _upload.Click += async (_, _) => await RunAsync();
        _close.Click += (_, _) => Close();
        _channel.SelectedIndexChanged += (_, _) => ShowSelected();
        _channel.DoubleClick += (_, _) => { if (Selected() is { } v) Open(v.Page); };
        _open.Click += (_, _) => { if (Selected() is { } v) Open(v.Page); };
        _copy.Click += (_, _) =>
        {
            if (Selected() is not { } v) return;
            try { Clipboard.SetText(v.Page); _channelLine.Text = $"Copied {v.Page}. Anyone you send it to sees the game with its timeline."; } catch { }
        };
        _manage.Click += (_, _) => Open(_manageUrl ?? $"{_settings.ServerUrl.TrimEnd('/')}/wow/coach#videos");
        Shown += async (_, _) => await LoadChannelAsync();
        FormClosing += (_, _) => _running?.Cancel();
        ShowSelected();
        Fill();
    }

    private bool OnYouTube(Item it) => it.Video.YouTubeId != null || _uploadedHere.Contains(it);

    private bool Matches(Item it) => _character.SelectedIndex <= 0 || it.Character == _character.SelectedItem as string;

    /// <summary>The list as the picker shows it, newest first, the ticks kept.</summary>
    private void Fill()
    {
        _filling = true;
        _list.BeginUpdate();
        _list.Items.Clear();
        _shown.Clear();
        foreach (var it in _items.Where(Matches))
        {
            _shown.Add(it);
            var length = it.Video.Length is { } l ? $"   ·   {VideoLibrary.Duration(l)}" : "";
            var mark = OnYouTube(it) ? "   ·   on YouTube" : "";
            _list.Items.Add($"{it.Title}   ·   {it.Video.Started:ddd d MMM, HH:mm}{length}{mark}", _ticked.Contains(it));
        }
        _list.EndUpdate();
        _filling = false;
        Count();
    }

    /// <summary>The Upload button says how many will go; Tick all and Untick all only when they would change something.</summary>
    private void Count()
    {
        var n = _ticked.Count;
        _upload.Text = n == 0 ? "Upload" : $"Upload {n} game{(n == 1 ? "" : "s")}";
        _upload.Width = TextRenderer.MeasureText(_upload.Text, _upload.Font).Width + 40;
        _all.Enabled = _running == null && _shown.Any(i => !OnYouTube(i) && !_ticked.Contains(i));
        _none.Enabled = _running == null && _shown.Any(_ticked.Contains);
    }

    private static Label Heading(string text) => new() { Dock = DockStyle.Fill, Text = text, Font = Theme.Heading, ForeColor = Theme.Ink };

    /// <summary>What each privacy choice means, for YouTube and for the game's page on the website.</summary>
    private static string Means(string? choice) => choice switch
    {
        "Public" => "Anyone can find it on YouTube, and it goes in MindCollector's video library with its timeline.",
        "Private" => "Only you can watch it. Anyone you send it to gets an error, on YouTube and on its MindCollector page.",
        _ => "Anyone with the link can watch it, on YouTube or on its MindCollector page with the timeline, but nobody can find it by searching.",
    };

    private ApiClient.YouTubeVideoInfo? Selected() => _channel.SelectedIndex >= 0 && _channel.SelectedIndex < _videos.Count ? _videos[_channel.SelectedIndex] : null;

    private void ShowSelected()
    {
        var v = Selected();
        _open.Enabled = v != null;
        _copy.Enabled = v is { Watchable: true };
        _channelLine.Text = v?.Line ?? (_videos.Count > 0 ? "Pick a video to see who can watch it and to share its page." : _channelLine.Text);
    }

    /// <summary>The videos already on the channel, from the website (which asks YouTube).</summary>
    private async Task LoadChannelAsync()
    {
        if (string.IsNullOrEmpty(_settings.Key)) return;
        _channelLine.Text = "Asking YouTube about your videos…";
        try
        {
            using var api = new ApiClient(_settings.ServerUrl, _settings.Key!);
            (_videos, _manageUrl) = await api.YouTubeVideosAsync(CancellationToken.None);
        }
        catch (Exception e)
        {
            _channelLine.Text = $"Could not load your videos: {e.Message}";
            return;
        }
        if (IsDisposed) return;
        _channel.Items.Clear();
        foreach (var v in _videos) _channel.Items.Add(Row(v));
        _channelLine.Text = _videos.Count == 0 ? "Nothing uploaded yet." : "";
        ShowSelected();
    }

    private static string Row(ApiClient.YouTubeVideoInfo v)
    {
        var who = v.Privacy switch { "public" => "Public", "unlisted" => "Unlisted", "private" => "Private", "removed" => "Gone from YouTube", _ => v.Privacy };
        if (v.Processing) who += ", still processing";
        if (v.InLibrary) who += ", in the library";
        var views = v.Views is { } n ? $"   ·   {n:N0} view{(n == 1 ? "" : "s")}" : "";
        return $"{v.Title ?? v.VideoId}   ·   {who}{views}";
    }

    private static void Open(string url)
    {
        try { System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(url) { UseShellExecute = true }); } catch { }
    }

    private async Task RunAsync()
    {
        var picked = _shown.Where(_ticked.Contains).ToList();
        if (picked.Count == 0) { _status.Text = "Tick at least one game."; return; }
        if (string.IsNullOrEmpty(_settings.Key)) { _status.Text = "Connect the app to your account first."; return; }

        var privacy = _privacy.SelectedItem?.ToString()?.ToLowerInvariant() ?? "unlisted";
        _upload.Enabled = false;
        _list.Enabled = false;
        _character.Enabled = false;
        _bar.Visible = true;
        _running = new CancellationTokenSource();
        Count();
        var ct = _running.Token;
        var done = 0;
        try
        {
            using var api = new ApiClient(_settings.ServerUrl, _settings.Key!);
            foreach (var it in picked)
            {
                _status.ForeColor = Theme.Muted;
                _status.Text = $"Uploading {done + 1} of {picked.Count}: {it.Title}";
                _bar.Value = 0;
                var prep = await api.PrepareYouTubeAsync(it.Lobby, it.Video, ct);
                if (prep.Status == "not-linked")
                {
                    _status.ForeColor = Theme.Lost;
                    _status.Text = "Link your YouTube channel first: on the website, Your games > YouTube > Connect YouTube. Opening it now.";
                    if (prep.Connect is { } url) Open(url);
                    return;
                }
                if (prep.Status != "ok" || prep.Token == null || prep.Title == null)
                {
                    _status.ForeColor = Theme.Lost;
                    _status.Text = prep.Reason ?? $"The website could not prepare this upload ({prep.Status}).";
                    return;
                }

                var first = true;
                async Task<string> Token()
                {
                    if (first) { first = false; return prep.Token!; }
                    var again = await api.PrepareYouTubeAsync(it.Lobby, it.Video, ct);   // a fresh token after an hour
                    return again.Token ?? throw new YouTubeUploader.UploadException("The website could not give a new YouTube token.");
                }
                var progress = new Progress<double>(p => { _bar.Value = (int)Math.Clamp(p * 1000, 0, 1000); });
                var path = Path.Combine(Recorder.VideosDir(_settings), it.Video.Name);
                var id = await Task.Run(() => YouTubeUploader.UploadAsync(path, prep.Title!, prep.Description ?? "", prep.Tags, privacy, Token, progress, ct), ct);

                await api.UploadedYouTubeAsync(it.Lobby, id, privacy, it.Video, ct);
                VideoLibrary.MarkUploaded(Recorder.VideosDir(_settings), it.Video.Name, id, privacy);
                _log($"Uploaded to YouTube ({privacy}): {prep.Title} https://youtu.be/{id}");
                done++;
                Uploaded = true;
                _uploadedHere.Add(it);
                _ticked.Remove(it);
                Fill();
            }
            _status.ForeColor = Theme.Won;
            _status.Text = privacy == "private"
                ? $"Done: {done} game(s) on your channel, private. Only you can play them."
                : $"Done: {done} game(s) on your channel. Each has a page with its timeline to send to people: pick it below and click Copy link.";
        }
        catch (OperationCanceledException)
        {
            _status.Text = $"Stopped. {done} uploaded before that.";
        }
        catch (Exception e)
        {
            _status.ForeColor = Theme.Lost;
            _status.Text = $"Stopped after {done}: {e.Message}";
            _log($"YouTube upload stopped: {e.Message}");
        }
        finally
        {
            _running = null;
            _upload.Enabled = true;
            _list.Enabled = true;
            _character.Enabled = true;
            _bar.Visible = false;
            if (!IsDisposed) Count();
        }
        if (done > 0 && !IsDisposed) await LoadChannelAsync();
    }
}

namespace MindCollector.Desktop;

/// <summary>
/// Picking games to upload to the player's YouTube channel (2026-10-26): the recordings matched to a
/// game and not uploaded yet, the last day's ticked, a privacy setting (unlisted unless they choose),
/// then one at a time: the website's title and chapters, the file straight to YouTube
/// (YouTubeUploader), the video id back to the website, and a note beside the file. Nothing is
/// uploaded unless it is ticked here.
/// </summary>
internal sealed class YouTubeQueueForm : Form
{
    public sealed record Item(VideoEntry Video, string Lobby, string Title);

    private readonly AppSettings _settings;
    private readonly Action<string> _log;
    private readonly List<Item> _items;
    private readonly CheckedListBox _list = new() { Dock = DockStyle.Fill, BorderStyle = BorderStyle.None, BackColor = Theme.Raised, ForeColor = Theme.Ink, CheckOnClick = true };
    private readonly ComboBox _privacy = new() { DropDownStyle = ComboBoxStyle.DropDownList, Width = 140, FlatStyle = FlatStyle.Flat, BackColor = Theme.Raised, ForeColor = Theme.Ink };
    private readonly ProgressBar _bar = new() { Dock = DockStyle.Top, Height = 8, Maximum = 1000, Visible = false };
    private readonly Label _status = new() { Dock = DockStyle.Top, Height = 44, ForeColor = Theme.Muted };
    private readonly Button _upload = Theme.Button("Upload", primary: true);
    private readonly Button _close = Theme.Button("Close");
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
        ClientSize = new Size(640, 520);
        MinimumSize = new Size(520, 400);
        Padding = new Padding(16);
        Theme.DarkTitleBar(this);

        var head = new Label
        {
            Dock = DockStyle.Top, Height = 52, ForeColor = Theme.Muted,
            Text = "Tick the games to send to your YouTube channel. Each gets a title and chapters written from the game, "
                + "and goes straight from this PC to YouTube. Games from the last day are ticked.",
        };
        var cutoff = DateTime.Now.AddDays(-1);
        foreach (var it in items)
            _list.Items.Add($"{it.Title}   ·   {it.Video.Started:ddd d MMM, HH:mm}{(it.Video.Length is { } l ? $"   ·   {VideoLibrary.Duration(l)}" : "")}", it.Video.Started >= cutoff);

        _privacy.Items.AddRange(["Unlisted", "Public", "Private"]);
        _privacy.SelectedIndex = 0;
        var row = new FlowLayoutPanel { Dock = DockStyle.Bottom, Height = 52, FlowDirection = FlowDirection.LeftToRight, BackColor = Theme.Frame, Padding = new Padding(0, 10, 0, 0) };
        row.Controls.AddRange([new Label { Text = "Who can see them:", AutoSize = true, ForeColor = Theme.Muted, Margin = new Padding(0, 8, 8, 0) }, _privacy]);
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Bottom, Height = 54, FlowDirection = FlowDirection.RightToLeft, BackColor = Theme.Frame, Padding = new Padding(0, 8, 0, 0) };
        buttons.Controls.AddRange([_close, _upload]);

        Controls.Add(_list);
        Controls.Add(_bar);
        Controls.Add(_status);
        Controls.Add(head);
        Controls.Add(row);
        Controls.Add(buttons);

        if (items.Count == 0)
        {
            _status.Text = "No recorded games to upload yet: a video shows here once its game is on the website and it isn't on YouTube already.";
            _upload.Enabled = false;
        }
        _upload.Click += async (_, _) => await RunAsync();
        _close.Click += (_, _) => Close();
        FormClosing += (_, _) => _running?.Cancel();
    }

    private async Task RunAsync()
    {
        var picked = _list.CheckedIndices.Cast<int>().Select(i => _items[i]).ToList();
        if (picked.Count == 0) { _status.Text = "Tick at least one game."; return; }
        if (string.IsNullOrEmpty(_settings.Key)) { _status.Text = "Connect the app to your account first."; return; }

        var privacy = _privacy.SelectedItem?.ToString()?.ToLowerInvariant() ?? "unlisted";
        _upload.Enabled = false;
        _list.Enabled = false;
        _bar.Visible = true;
        _running = new CancellationTokenSource();
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
                    if (prep.Connect is { } url) try { System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(url) { UseShellExecute = true }); } catch { }
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
                _list.SetItemChecked(_items.IndexOf(it), false);
            }
            _status.ForeColor = Theme.Won;
            _status.Text = $"Done: {done} game(s) on your channel. Their pages on the website play them now.";
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
            _bar.Visible = false;
        }
    }
}

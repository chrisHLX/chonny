namespace MindCollector.Desktop;

/// <summary>The key from mindcollector.com/wow/coach, the folders, and whether to start with Windows.</summary>
internal sealed class SettingsForm : Form
{
    private readonly AppSettings _settings;
    private readonly TextBox _server = Box();
    private readonly TextBox _key = Box();
    private readonly TextBox _wow = Box();
    private readonly TextBox _move = Box();
    private readonly CheckBox _moveLogs = Check("Move each combat log out of WoW's folder once all of it is sent (after WoW closes)");
    private readonly CheckBox _startup = Check("Start MindCollector when Windows starts (minimised to the taskbar)");
    private readonly CheckBox _record = Check("Record each arena game with OBS: it starts as you zone in and stops when the game ends");
    private readonly CheckBox _forever = Check("Save each PvP fight in WoW Forever, cut to the fight (how much is set below)");
    private readonly CheckBox _gameCapture = Check("Record only the game (an OBS Game Capture) when your OBS scene is a screen capture");
    private readonly CheckBox _observer = Check("Install the Observer addon: it remembers what you look at in Forever, for Looked at");
    private readonly CheckBox _shareScans = Check("Share my auction house scans and get everyone's prices back (prices and how many are listed only, never what you look at or sell)");
    private readonly CheckBox _askClaude = Check("Answer /ask questions from Forever with Claude (each question uses your Claude account)");
    private readonly TrackBar _lead = Slider(0, 120, 5);
    private readonly TrackBar _tail = Slider(0, 60, 1);
    private readonly TextBox _videos = Box();
    private readonly TextBox _limit = Box();
    private readonly Label _status = new() { AutoSize = true, ForeColor = Theme.Muted, Margin = new Padding(0, 10, 0, 0) };

    public SettingsForm(AppSettings settings)
    {
        _settings = settings;
        Text = "MindCollector settings";
        Icon = Theme.AppIcon();
        Theme.DarkTitleBar(this);
        BackColor = Theme.Panel;
        ForeColor = Theme.Ink;
        Font = Theme.Ui;
        StartPosition = FormStartPosition.CenterParent;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = MinimizeBox = false;
        ClientSize = new Size(760, 962);
        Padding = new Padding(16);

        _server.Text = settings.ServerUrl;
        _key.Text = settings.Key ?? "";
        _key.UseSystemPasswordChar = true;
        _wow.Text = settings.WowLogsDir ?? "";
        _move.Text = settings.MoveTo ?? "";
        _moveLogs.Checked = settings.MoveLogs;
        _startup.Checked = Installer.StartsWithWindows();
        _record.Checked = settings.RecordGames;
        _forever.Checked = settings.RecordForever;
        _gameCapture.Checked = settings.ObsGameCapture;
        _observer.Checked = settings.ForeverObserver;
        _askClaude.Checked = settings.AskClaude;
        _shareScans.Checked = settings.ShareScans;
        _lead.Value = Math.Clamp(settings.ForeverLeadSeconds, _lead.Minimum, _lead.Maximum);
        _tail.Value = Math.Clamp(settings.ForeverTailSeconds, _tail.Minimum, _tail.Maximum);
        _videos.Text = Recorder.VideosDir(settings);
        _limit.Text = settings.VideoLimitGb.ToString();
#if STORE
        if (StoreStartup.State() == Windows.ApplicationModel.StartupTaskState.DisabledByUser)
        {
            _startup.Enabled = false;
            _startup.Text = "Start MindCollector when Windows starts (turned off in Task Manager > Startup apps; turn it on there)";
        }
#endif

        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 3, AutoSize = true };
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 150));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

        // Connect is the way in (2026-10-11): the browser does the rest, and the key below fills itself.
        var connect = Theme.Button(string.IsNullOrEmpty(settings.Key) ? "Connect your account" : "Connect again", primary: string.IsNullOrEmpty(settings.Key));
        connect.Margin = new Padding(0, 4, 0, 10);
        connect.Click += (_, _) => { DialogResult = DialogResult.Retry; Close(); };
        grid.Controls.Add(new Label { Text = "Account", AutoSize = true, Margin = new Padding(0, 14, 8, 0) });
        grid.Controls.Add(connect);
        grid.Controls.Add(new Label());
        Row(grid, "Website key", _key, null, "Filled in by Connect. A key from Your games > Upload on the website works too.");
        Row(grid, "WoW's Logs folder", _wow, Browse(_wow), "Where WoW writes WoWCombatLog-*.txt, usually World of Warcraft\\_retail_\\Logs.");
        Row(grid, "Move logs to", _move, Browse(_move), "Logs go here once every round in them is sent, so WoW's folder stays small.");
        CheckRow(grid, null, _moveLogs);
        CheckRow(grid, null, _startup);
        CheckRow(grid, "Recording", _record);
        CheckRow(grid, null, _forever);
        SliderRow(grid, "Before the fight", _lead, "Seconds kept before the first hit, you or them. Lower it if the clips start too early.");
        SliderRow(grid, "After the fight", _tail, "Seconds kept after the last hit.");
        CheckRow(grid, null, _gameCapture);
        grid.Controls.Add(new Label());
        grid.Controls.Add(new Label { Text = "Needs OBS Studio (free, obsproject.com). Install it, then click Set up OBS below: it does the rest.", AutoSize = true, ForeColor = Theme.Muted, Margin = new Padding(0, 0, 0, 6) });
        grid.Controls.Add(new Label());
        Row(grid, "Save videos to", _videos, Browse(_videos), "Each game's video, with a small file beside it that lines the video up with the game.");
        Row(grid, "Keep up to (GB)", _limit, null, "Past this the oldest videos the app recorded are deleted. 0 keeps them all.");
        CheckRow(grid, "WoW Forever", _observer);
        CheckRow(grid, null, _shareScans);
        CheckRow(grid, null, _askClaude);
        var claude = AskBridge.FindClaude(settings.ClaudePath);
        grid.Controls.Add(new Label());
        grid.Controls.Add(new Label
        {
            Text = claude != null ? "Uses Claude Code on this PC, with your web browser for Wowhead's maps." : "Needs Claude Code on this PC (claude.com/claude-code), signed in to your Claude account.",
            AutoSize = true, ForeColor = claude != null ? Theme.Muted : Theme.Lost, Margin = new Padding(0, 0, 0, 6),
        });
        grid.Controls.Add(new Label());
        Row(grid, "Website", _server, null, "Leave as it is unless told otherwise.");

        var install = Theme.Button("Install the addon");
        install.Click += (_, _) =>
        {
            try
            {
                var outcome = AddonInstaller.Ensure(_wow.Text.Trim(), replaceDifferent: true);
                _status.Text = outcome.Message;
                _status.ForeColor = outcome.State == AddonInstaller.State.NoWow ? Theme.Lost : Theme.Won;
            }
            catch (Exception e)
            {
                _status.Text = $"Could not install the addon: {e.Message}";
                _status.ForeColor = Theme.Lost;
            }
        };

        var obs = Theme.Button("Set up OBS");
        obs.Click += async (_, _) => await SetUpObsAsync(obs);

        var save = Theme.Button("Save", primary: true);
        var cancel = Theme.Button("Cancel");
        save.Click += async (_, _) => await SaveAsync();
        cancel.Click += (_, _) => Close();
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Bottom, FlowDirection = FlowDirection.RightToLeft, AutoSize = true };
        buttons.Controls.AddRange([cancel, save, install, obs]);
        grid.Controls.Add(new Label()); grid.Controls.Add(_status); grid.Controls.Add(new Label());

        Controls.Add(grid);
        Controls.Add(buttons);
        AcceptButton = save;
        CancelButton = cancel;
    }

    private async Task SaveAsync()
    {
        if (!string.IsNullOrWhiteSpace(_wow.Text) && !Directory.Exists(_wow.Text))
        {
            _status.Text = "That WoW Logs folder does not exist.";
            _status.ForeColor = Theme.Lost;
            return;
        }

        // Check the key against the website before keeping it, so a typo shows here and not later.
        if (!string.IsNullOrWhiteSpace(_key.Text))
        {
            _status.Text = "Checking the key with the website…";
            _status.ForeColor = Theme.Muted;
            try
            {
                using var api = new ApiClient(_server.Text.Trim(), _key.Text.Trim());
                await api.VersionAsync(CancellationToken.None);
            }
            catch (ApiClient.KeyRejectedException e)
            {
                _status.Text = e.Message;
                _status.ForeColor = Theme.Lost;
                return;
            }
            catch (Exception e)
            {
                _status.Text = $"Could not reach the website: {e.Message}";
                _status.ForeColor = Theme.Lost;
                return;
            }
        }

        _settings.ServerUrl = _server.Text.Trim().TrimEnd('/');
        _settings.Key = _key.Text;
        _settings.WowLogsDir = string.IsNullOrWhiteSpace(_wow.Text) ? null : _wow.Text.Trim();
        _settings.MoveTo = _move.Text.Trim();
        _settings.MoveLogs = _moveLogs.Checked;
        _settings.RecordGames = _record.Checked;
        _settings.RecordForever = _forever.Checked;
        _settings.ObsGameCapture = _gameCapture.Checked;
        _settings.ForeverObserver = _observer.Checked;
        _settings.AskClaude = _askClaude.Checked;
        _settings.ShareScans = _shareScans.Checked;
        _settings.ForeverLeadSeconds = _lead.Value;
        _settings.ForeverTailSeconds = _tail.Value;
        _settings.VideosDir = string.IsNullOrWhiteSpace(_videos.Text) ? null : _videos.Text.Trim();
        _settings.VideoLimitGb = int.TryParse(_limit.Text.Trim(), out var gb) && gb >= 0 ? gb : _settings.VideoLimitGb;
        _settings.Save();

        Installer.StartWithWindows(_startup.Checked);

        DialogResult = DialogResult.OK;
        Close();
    }

    /// <summary>
    /// Turns on OBS's remote control (or says how), opens OBS, connects, and checks it records into
    /// a file the app can play. Ticks "Record each arena game" when it all works.
    /// </summary>
    private async Task SetUpObsAsync(Button button)
    {
        button.Enabled = false;
        _status.Text = "Setting up OBS…";
        _status.ForeColor = Theme.Muted;
        try
        {
            var (client, message) = await ObsSetup.ConnectAsync(openIfClosed: true, CancellationToken.None);
            if (client == null)
            {
                _status.Text = message;
                _status.ForeColor = Theme.Lost;
                return;
            }
            await using (client)
            {
                var added = await ObsSetup.EnsureWowCaptureAsync(client, CancellationToken.None);
                if (added != null) message = $"{message} {added}";
                var format = await ObsSetup.RecordFormatAsync(client, CancellationToken.None);
                _record.Checked = true;
                if (ObsSetup.Playable(format))
                {
                    _status.Text = $"{message} Each arena game will be recorded{(format != null ? $" as {format}" : "")}. Click Save.";
                    _status.ForeColor = Theme.Won;
                }
                else
                {
                    _status.Text = $"{message} It records as {format}, which the app cannot play: in OBS, set Settings > Output > Recording Format to MP4 (or Hybrid MP4). Then click Save.";
                    _status.ForeColor = Theme.Gold;
                }
            }
        }
        catch (Exception e)
        {
            _status.Text = $"Could not set up OBS: {e.Message}";
            _status.ForeColor = Theme.Lost;
        }
        finally
        {
            button.Enabled = true;
        }
    }

    private static void Row(TableLayoutPanel grid, string label, TextBox box, Button? browse, string hint)
    {
        grid.Controls.Add(new Label { Text = label, AutoSize = true, Margin = new Padding(0, 10, 8, 0) });
        grid.Controls.Add(box);
        grid.Controls.Add(browse ?? (Control)new Label());
        grid.Controls.Add(new Label());
        grid.Controls.Add(new Label { Text = hint, AutoSize = true, ForeColor = Theme.Muted, Margin = new Padding(0, 0, 0, 6) });
        grid.Controls.Add(new Label());
    }

    /// <summary>A tick box across the box and button columns, so a long line is not cut off.</summary>
    private static void CheckRow(TableLayoutPanel grid, string? label, CheckBox box)
    {
        grid.Controls.Add(label == null ? new Label() : new Label { Text = label, AutoSize = true, Margin = new Padding(0, 10, 8, 0) });
        grid.Controls.Add(box);
        grid.SetColumnSpan(box, 2);
    }

    /// <summary>A slider with its value in seconds beside it, and a hint under it, as Row lays out a box.</summary>
    private static void SliderRow(TableLayoutPanel grid, string label, TrackBar slider, string hint)
    {
        var value = new Label { AutoSize = true, Margin = new Padding(0, 12, 0, 0), Text = $"{slider.Value} s" };
        slider.ValueChanged += (_, _) => value.Text = $"{slider.Value} s";
        grid.Controls.Add(new Label { Text = label, AutoSize = true, Margin = new Padding(0, 12, 8, 0) });
        grid.Controls.Add(slider);
        grid.Controls.Add(value);
        grid.Controls.Add(new Label());
        grid.Controls.Add(new Label { Text = hint, AutoSize = true, ForeColor = Theme.Muted, Margin = new Padding(0, 0, 0, 6) });
        grid.Controls.Add(new Label());
    }

    private static TrackBar Slider(int min, int max, int step) => new()
    {
        Minimum = min,
        Maximum = max,
        SmallChange = step,
        LargeChange = step * 2,
        TickFrequency = max / 12,
        TickStyle = TickStyle.BottomRight,
        Dock = DockStyle.Fill,
        BackColor = Theme.Panel,
        Margin = new Padding(0, 4, 8, 0),
        AutoSize = false,
        Height = 34,
    };

    private static Button Browse(TextBox target)
    {
        var b = Theme.Button("Browse…");
        b.Click += (_, _) =>
        {
            using var d = new FolderBrowserDialog();
            if (Directory.Exists(target.Text)) d.SelectedPath = target.Text;
            if (d.ShowDialog() == DialogResult.OK) target.Text = d.SelectedPath;
        };
        return b;
    }

    private static TextBox Box() => new()
    {
        Dock = DockStyle.Fill,
        BackColor = Theme.Raised,
        ForeColor = Theme.Ink,
        BorderStyle = BorderStyle.FixedSingle,
        Margin = new Padding(0, 6, 8, 0),
    };

    private static CheckBox Check(string text) => new() { Text = text, AutoSize = true, Margin = new Padding(0, 8, 0, 0) };
}

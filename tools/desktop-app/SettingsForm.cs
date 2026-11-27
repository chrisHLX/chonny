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
    private readonly CheckBox _wowSound = Check("Record WoW's sound only, not voice chat or music");
    private readonly CheckBox _gameCapture = Check("Record only the game (an OBS Game Capture) when your OBS scene is a screen capture");
    private readonly CheckBox _observer = Check("Install the Auction House addon in Forever: prices, professions, and what you look at");
    private readonly CheckBox _shareScans = Check("Share my auction house scans and get everyone's prices back (prices only)");
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
        // Sizeable, and the settings scroll above buttons that stay put (0.6.42, 2026-11-27): at 1022
        // high a fixed dialog ran off a laptop screen at 125% scaling, Save and Cancel with it.
        FormBorderStyle = FormBorderStyle.Sizable;
        MaximizeBox = MinimizeBox = false;
        ClientSize = new Size(760, 1022);
        MinimumSize = new Size(560, 360);
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
        _wowSound.Checked = settings.RecordSound != "everything";
        _observer.Checked = settings.ForeverObserver;
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

        var grid = new TableLayoutPanel { Dock = DockStyle.Top, ColumnCount = 3, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink };
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
        CheckRow(grid, null, _wowSound);
        grid.Controls.Add(new Label());
        grid.Controls.Add(new Label { Text = "Needs OBS Studio (free). Click Set up OBS below: it installs OBS if it's missing, then does the rest.", AutoSize = true, ForeColor = Theme.Muted, Margin = new Padding(0, 0, 0, 6) });
        grid.Controls.Add(new Label());
        Row(grid, "Save videos to", _videos, Browse(_videos), "Each game's video, with a small file beside it that lines the video up with the game.");
        Row(grid, "Keep up to (GB)", _limit, null, "Past this the oldest videos the app recorded are deleted. 0 keeps them all.");
        CheckRow(grid, "WoW Forever", _observer);
        CheckRow(grid, null, _shareScans);
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

        var scroll = new ScrollPanel { Dock = DockStyle.Fill, AutoScroll = true };
        scroll.Controls.Add(grid);
        Controls.Add(scroll);
        Controls.Add(buttons);
        AcceptButton = save;
        CancelButton = cancel;
    }

    /// <summary>No taller than the screen it opens on, and wholly on it, centred over the app.</summary>
    protected override void OnLoad(EventArgs e)
    {
        base.OnLoad(e);
        var area = Screen.FromControl(Owner ?? (Control)this).WorkingArea;
        Height = Math.Min(Height, area.Height);
        if (StartPosition == FormStartPosition.Manual) return;   // --settings-shot places it off screen
        var x = Owner == null ? area.Left + (area.Width - Width) / 2 : Owner.Left + (Owner.Width - Width) / 2;
        var y = Owner == null ? area.Top + (area.Height - Height) / 2 : Owner.Top + (Owner.Height - Height) / 2;
        StartPosition = FormStartPosition.Manual;
        Location = new Point(Math.Clamp(x, area.Left, Math.Max(area.Left, area.Right - Width)),
            Math.Clamp(y, area.Top, Math.Max(area.Top, area.Bottom - Height)));
    }

    /// <summary>Windows' dark scrollbar (Windows 10 1809+), not the white default, as in LibraryList.</summary>
    private sealed class ScrollPanel : Panel
    {
        [System.Runtime.InteropServices.DllImport("uxtheme.dll", CharSet = System.Runtime.InteropServices.CharSet.Unicode)]
        private static extern int SetWindowTheme(IntPtr hwnd, string? app, string? idList);

        protected override void OnHandleCreated(EventArgs e)
        {
            base.OnHandleCreated(e);
            try { SetWindowTheme(Handle, "DarkMode_Explorer", null); } catch { }
        }
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
        _settings.RecordSound = _wowSound.Checked ? "wow" : "everything";
        _settings.ForeverObserver = _observer.Checked;
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
    /// Installs OBS if it's missing, then ObsCheck: sets it up, restarts it when a setting needs that
    /// (asking first), and tests a short recording (and a Forever replay save when that's ticked)
    /// before it says ready. Ticks "Record each arena game" when it all works.
    /// </summary>
    private async Task SetUpObsAsync(Button button)
    {
        button.Enabled = false;
        _status.Text = "Setting up OBS…";
        _status.ForeColor = Theme.Muted;
        try
        {
            // No OBS yet: offer to install it, then carry on with the setup (2026-11-12).
            if (ObsSetup.ExePath() == null)
            {
                var answer = MessageBox.Show(this,
                    "OBS Studio isn't installed, and the app records your games with it.\n\n" +
                    "Install it now? It's free (from OBS Project, about 150 MB), installed by Windows' own installer. " +
                    "Windows will ask for permission. It takes a minute or two.",
                    "Install OBS Studio?", MessageBoxButtons.YesNo, MessageBoxIcon.Question);
                if (answer != DialogResult.Yes)
                {
                    _status.Text = "OBS isn't installed, so games won't be recorded. Click Set up OBS any time to install it.";
                    _status.ForeColor = Theme.Gold;
                    return;
                }
                _status.Text = "Installing OBS… (a minute or two; say yes when Windows asks)";
                var (ok, said) = await ObsInstaller.InstallAsync(CancellationToken.None);
                if (!ok)
                {
                    _status.Text = said;
                    _status.ForeColor = Theme.Lost;
                    return;
                }
                _status.Text = "OBS is installed. Setting it up…";
            }

            // Set up and prove it (ObsCheck, 2026-11-12): fixes what it can, restarts OBS when a setting
            // needs it (asking first), then a short test recording, checked, before it says ready.
            // Off the window's thread; questions and progress come back to it.
            bool Ask(string question) => (bool)Invoke(() => MessageBox.Show(this, question, "MindCollector: OBS",
                MessageBoxButtons.YesNo, MessageBoxIcon.Question) == DialogResult.Yes);
            void Progress(string line) => BeginInvoke(() => { _status.Text = line; _status.ForeColor = Theme.Muted; });
            var forever = _forever.Checked;
            var outcome = await Task.Run(() => ObsCheck.SetUpAndTestAsync(_settings, forever, Ask, Progress, CancellationToken.None));
            if (outcome.Ready)
            {
                _record.Checked = true;
                _status.Text = "Ready: " + string.Join(" ", outcome.Lines.Where(l => l.StartsWith("Arena") || l.StartsWith("Forever") || l.StartsWith("WoW isn't"))) + " Click Save.";
                _status.ForeColor = Theme.Won;
            }
            else
            {
                _status.Text = outcome.Lines.LastOrDefault() ?? "OBS isn't ready.";
                _status.ForeColor = Theme.Lost;
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

    private static CheckBox Check(string text) => new WrapCheck { Text = text, AutoSize = true, Margin = new Padding(0, 8, 0, 0) };

    /// <summary>
    /// A tick box whose text wraps to the width it is given (0.6.42). A CheckBox asks for its text on one
    /// line whatever the column, so a long one ran past the window's edge and was cut off.
    /// </summary>
    private sealed class WrapCheck : CheckBox
    {
        public WrapCheck()
        {
            CheckAlign = ContentAlignment.TopLeft;
            TextAlign = ContentAlignment.TopLeft;
        }

        public override Size GetPreferredSize(Size proposedSize)
        {
            var one = base.GetPreferredSize(Size.Empty);
            if (proposedSize.Width <= 0 || proposedSize.Width >= one.Width) return one;
            var line = TextRenderer.MeasureText(Text, Font);
            var glyph = one.Width - line.Width;   // the box, the gap and the padding
            var text = TextRenderer.MeasureText(Text, Font, new Size(Math.Max(1, proposedSize.Width - glyph), 0), TextFormatFlags.WordBreak);
            return new Size(proposedSize.Width, one.Height - line.Height + text.Height);
        }
    }
}

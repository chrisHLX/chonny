using Microsoft.Win32;

namespace MindCollector.Desktop;

/// <summary>The key from mindcollector.com/wow/coach, the folders, and whether to start with Windows.</summary>
internal sealed class SettingsForm : Form
{
    private const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string RunName = "MindCollector";

    private readonly AppSettings _settings;
    private readonly TextBox _server = Box();
    private readonly TextBox _key = Box();
    private readonly TextBox _wow = Box();
    private readonly TextBox _move = Box();
    private readonly CheckBox _moveLogs = Check("Move each combat log out of WoW's folder once all of it is sent (after WoW closes)");
    private readonly CheckBox _startup = Check("Start MindCollector when Windows starts (in the tray)");
    private readonly Label _status = new() { AutoSize = true, ForeColor = Theme.Muted, Margin = new Padding(0, 10, 0, 0) };

    public SettingsForm(AppSettings settings)
    {
        _settings = settings;
        Text = "MindCollector settings";
        Icon = Theme.AppIcon();
        BackColor = Theme.Panel;
        ForeColor = Theme.Ink;
        Font = Theme.Ui;
        StartPosition = FormStartPosition.CenterParent;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = MinimizeBox = false;
        ClientSize = new Size(720, 420);
        Padding = new Padding(16);

        _server.Text = settings.ServerUrl;
        _key.Text = settings.Key ?? "";
        _key.UseSystemPasswordChar = true;
        _wow.Text = settings.WowLogsDir ?? "";
        _move.Text = settings.MoveTo ?? "";
        _moveLogs.Checked = settings.MoveLogs;
        using (var run = Registry.CurrentUser.OpenSubKey(RunKey)) _startup.Checked = run?.GetValue(RunName) != null;

        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 3, AutoSize = true };
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 150));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

        Row(grid, "Website key", _key, null, "Make one on mindcollector.com, under Your games > Upload. Paste it here.");
        Row(grid, "WoW's Logs folder", _wow, Browse(_wow), "Where WoW writes WoWCombatLog-*.txt, usually World of Warcraft\\_retail_\\Logs.");
        Row(grid, "Move logs to", _move, Browse(_move), "Logs go here once every round in them is sent, so WoW's folder stays small.");
        grid.Controls.Add(new Label()); grid.Controls.Add(_moveLogs); grid.Controls.Add(new Label());
        grid.Controls.Add(new Label()); grid.Controls.Add(_startup); grid.Controls.Add(new Label());
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

        var save = Theme.Button("Save", primary: true);
        var cancel = Theme.Button("Cancel");
        save.Click += async (_, _) => await SaveAsync();
        cancel.Click += (_, _) => Close();
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Bottom, FlowDirection = FlowDirection.RightToLeft, AutoSize = true };
        buttons.Controls.AddRange([cancel, save, install]);
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
        _settings.Save();

        using (var run = Registry.CurrentUser.OpenSubKey(RunKey, writable: true))
        {
            if (_startup.Checked) run?.SetValue(RunName, $"\"{Application.ExecutablePath}\" --minimized");
            else run?.DeleteValue(RunName, throwOnMissingValue: false);
        }

        DialogResult = DialogResult.OK;
        Close();
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
